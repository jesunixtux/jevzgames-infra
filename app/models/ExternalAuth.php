<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class ExternalAuth
{
    public static function loginProviders(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id, name, provider, client_id, config_json
             FROM external_integrations
             WHERE status = "active"
             ORDER BY name ASC'
        );

        $providers = [];
        foreach ($stmt->fetchAll() as $row) {
            if ((string) $row['provider'] === 'steam') {
                continue;
            }

            $config = Game::decodeJson($row['config_json'] ?? null);
            if (empty($config['login_enabled'])) {
                continue;
            }

            $providers[] = [
                'name' => (string) $row['name'],
                'provider' => (string) $row['provider'],
                'client_id' => (string) ($row['client_id'] ?? ''),
                'config' => $config,
            ];
        }

        return $providers;
    }

    public static function provider(string $provider): ?array
    {
        if (strtolower(trim($provider)) === 'steam') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, provider, client_id, config_json
             FROM external_integrations
             WHERE provider = :provider AND status = "active"
             LIMIT 1'
        );
        $stmt->execute(['provider' => strtolower(trim($provider))]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $row['config'] = Game::decodeJson($row['config_json'] ?? null);
        return !empty($row['config']['login_enabled']) ? $row : null;
    }

    public static function startUrl(string $provider): string
    {
        $integration = self::provider($provider);
        if (!$integration) {
            throw new RuntimeException('Proveedor OAuth no disponible.');
        }

        $config = $integration['config'];
        $authUrl = trim((string) ($config['auth_url'] ?? ''));
        if ($authUrl === '') {
            throw new RuntimeException('auth_url no esta configurado para este proveedor.');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['external_oauth_state'][$integration['provider']] = $state;
        $redirectUri = self::redirectUri((string) $integration['provider'], $config);
        $params = [
            'response_type' => 'code',
            'client_id' => (string) ($integration['client_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'scope' => (string) ($config['scope'] ?? ''),
            'state' => $state,
        ];

        return $authUrl . (str_contains($authUrl, '?') ? '&' : '?') . http_build_query(array_filter($params, static fn (string $value): bool => $value !== ''));
    }

    public static function complete(string $provider, string $code, string $state): int
    {
        $integration = self::provider($provider);
        if (!$integration) {
            throw new RuntimeException('Proveedor OAuth no disponible.');
        }

        $expectedState = $_SESSION['external_oauth_state'][$integration['provider']] ?? '';
        unset($_SESSION['external_oauth_state'][$integration['provider']]);
        if (!is_string($expectedState) || !hash_equals($expectedState, $state)) {
            throw new RuntimeException('Estado OAuth invalido.');
        }

        $config = $integration['config'];
        $tokenUrl = trim((string) ($config['token_url'] ?? ''));
        $userinfoUrl = trim((string) ($config['userinfo_url'] ?? ''));
        if ($tokenUrl === '' || $userinfoUrl === '') {
            throw new RuntimeException('token_url y userinfo_url son requeridos.');
        }

        $tokenPayload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri((string) $integration['provider'], $config),
            'client_id' => (string) ($integration['client_id'] ?? ''),
        ];
        if (!empty($config['client_secret'])) {
            $tokenPayload['client_secret'] = (string) $config['client_secret'];
        }

        $token = self::postForm($tokenUrl, $tokenPayload);
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('El proveedor no devolvio access_token.');
        }

        $profile = self::getJson($userinfoUrl, $accessToken);
        $externalId = (string) self::valueByPath($profile, (string) ($config['id_field'] ?? 'id'));
        $email = (string) self::valueByPath($profile, (string) ($config['email_field'] ?? 'email'));
        $username = (string) self::valueByPath($profile, (string) ($config['username_field'] ?? 'username'));
        if ($username === '') {
            $username = (string) self::valueByPath($profile, 'name');
        }
        if ($externalId === '') {
            throw new RuntimeException('El perfil OAuth no incluye identificador externo.');
        }

        return self::linkOrCreateUser((string) $integration['provider'], $externalId, $email, $username, $profile);
    }

    private static function linkOrCreateUser(string $provider, string $externalId, string $email, string $username, array $profile): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM external_accounts WHERE provider = :provider AND external_user_id = :external_user_id LIMIT 1');
        $stmt->execute([
            'provider' => $provider,
            'external_user_id' => $externalId,
        ]);
        $account = $stmt->fetch();
        if (is_array($account)) {
            return (int) $account['user_id'];
        }

        $userId = null;
        if ($email !== '') {
            $existing = User::findByEmailOrUsername($email);
            if ($existing) {
                $userId = (int) $existing['id'];
            }
        }

        if ($userId === null) {
            $baseUsername = self::safeUsername($username !== '' ? $username : $provider . '_' . substr(hash('sha1', $externalId), 0, 10));
            $emailToUse = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $baseUsername . '@oauth.local';
            $userId = User::create(self::uniqueUsername($baseUsername), $emailToUse, bin2hex(random_bytes(24)), 'user');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO external_accounts (user_id, provider, external_user_id, external_username, extra_json, linked_at)
             VALUES (:user_id, :provider, :external_user_id, :external_username, :extra_json, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'external_user_id' => $externalId,
            'external_username' => $username !== '' ? $username : null,
            'extra_json' => json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return $userId;
    }

    private static function redirectUri(string $provider, array $config): string
    {
        $configured = trim((string) ($config['redirect_uri'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return \url('/auth/oauth/callback/?provider=' . rawurlencode($provider));
    }

    private static function postForm(string $url, array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => http_build_query($payload),
                'timeout' => 15,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('No se pudo contactar el token_url OAuth.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta token OAuth invalida.');
        }

        return $decoded;
    }

    private static function getJson(string $url, string $accessToken): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer " . $accessToken . "\r\nAccept: application/json\r\n",
                'timeout' => 15,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('No se pudo consultar userinfo_url OAuth.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta userinfo OAuth invalida.');
        }

        return $decoded;
    }

    private static function valueByPath(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return '';
            }
            $current = $current[$part];
        }

        return is_scalar($current) ? $current : '';
    }

    private static function safeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        $username = preg_replace('/[^a-z0-9_]/', '_', $username) ?? '';
        $username = trim($username, '_');

        return substr($username !== '' ? $username : 'oauth_user', 0, 40);
    }

    private static function uniqueUsername(string $base): string
    {
        $candidate = $base;
        $i = 1;
        while (User::findByEmailOrUsername($candidate) !== null) {
            $suffix = '_' . $i++;
            $candidate = substr($base, 0, 60 - strlen($suffix)) . $suffix;
        }

        return $candidate;
    }
}
