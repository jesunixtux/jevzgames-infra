<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class SteamAuth
{
    private const PROVIDER = 'steam';
    private const OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';

    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS external_integrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                provider VARCHAR(80) NOT NULL,
                client_id VARCHAR(190) NULL,
                client_secret_hash VARCHAR(255) NULL,
                status ENUM("active", "inactive") NOT NULL DEFAULT "inactive",
                config_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_external_integrations_provider (provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS external_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                provider VARCHAR(80) NOT NULL,
                external_user_id VARCHAR(190) NOT NULL,
                external_username VARCHAR(190) NULL,
                extra_json LONGTEXT NULL,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_external_accounts_provider_user (provider, external_user_id),
                INDEX idx_external_accounts_user (user_id),
                CONSTRAINT fk_external_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function connectEnabled(): bool
    {
        $integration = self::integration();

        return $integration !== null && !empty($integration['config']['connect_enabled']);
    }

    public static function integration(): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, provider, config_json
             FROM external_integrations
             WHERE provider = :provider AND status = "active"
             LIMIT 1'
        );
        $stmt->execute(['provider' => self::PROVIDER]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $row['config'] = Game::decodeJson($row['config_json'] ?? null);

        return $row;
    }

    public static function steamAccountForUser(int $userId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM external_accounts
             WHERE user_id = :user_id AND provider = :provider
             ORDER BY linked_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'provider' => self::PROVIDER,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $row['extra'] = Game::decodeJson($row['extra_json'] ?? null);

        return $row;
    }

    public static function startConnectUrl(int $userId): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario invalido.');
        }

        if (!self::connectEnabled()) {
            throw new RuntimeException('Steam Connect no esta habilitado.');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['steam_openid_state'][$state] = [
            'user_id' => $userId,
            'mode' => 'connect',
            'created_at' => time(),
        ];

        $returnTo = \url('/auth/steam/callback/?mode=connect&state=' . rawurlencode($state));
        $realm = rtrim(\url('/'), '/');
        $params = [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $returnTo,
            'openid.realm' => $realm,
            'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        return self::OPENID_ENDPOINT . '?' . http_build_query($params);
    }

    public static function completeConnect(int $userId, array $query): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario invalido.');
        }

        if (!self::connectEnabled()) {
            throw new RuntimeException('Steam Connect no esta habilitado.');
        }

        $state = (string) ($query['state'] ?? '');
        $stored = $_SESSION['steam_openid_state'][$state] ?? null;
        unset($_SESSION['steam_openid_state'][$state]);
        if (!is_array($stored) || (int) ($stored['user_id'] ?? 0) !== $userId || time() - (int) ($stored['created_at'] ?? 0) > 900) {
            throw new RuntimeException('Estado Steam invalido o expirado.');
        }

        $openid = self::normalizedOpenIdParams($query);
        if (($openid['openid.mode'] ?? '') !== 'id_res') {
            throw new RuntimeException('Steam no devolvio una respuesta valida.');
        }

        if (!self::verifyOpenId($openid)) {
            throw new RuntimeException('No se pudo verificar la identidad con Steam.');
        }

        $claimedId = (string) ($openid['openid.claimed_id'] ?? $openid['openid.identity'] ?? '');
        if (!preg_match('#^https?://steamcommunity\.com/openid/id/([0-9]{17,20})$#', $claimedId, $matches)) {
            throw new RuntimeException('Steam no devolvio un SteamID valido.');
        }

        $steamId = $matches[1];
        $integration = self::integration();
        $profile = $integration ? self::fetchSteamProfile($steamId, $integration['config'] ?? []) : [];
        $username = (string) ($profile['personaname'] ?? ('steam_' . $steamId));

        self::linkSteamAccount($userId, $steamId, $username, [
            'steamid' => $steamId,
            'profile' => $profile,
            'connected_at' => date(DATE_ATOM),
        ]);

        return self::steamAccountForUser($userId) ?? [
            'provider' => self::PROVIDER,
            'external_user_id' => $steamId,
            'external_username' => $username,
        ];
    }

    public static function disconnect(int $userId): void
    {
        self::ensureTables();
        Database::pdo()->prepare(
            'DELETE FROM external_accounts
             WHERE user_id = :user_id AND provider = :provider'
        )->execute([
            'user_id' => $userId,
            'provider' => self::PROVIDER,
        ]);
    }

    private static function linkSteamAccount(int $userId, string $steamId, string $username, array $extra): void
    {
        self::ensureTables();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT user_id
                 FROM external_accounts
                 WHERE provider = :provider AND external_user_id = :external_user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'provider' => self::PROVIDER,
                'external_user_id' => $steamId,
            ]);
            $existingUserId = $stmt->fetchColumn();
            if ($existingUserId !== false && (int) $existingUserId !== $userId) {
                throw new RuntimeException('Esta cuenta Steam ya esta conectada a otro usuario.');
            }

            $pdo->prepare(
                'DELETE FROM external_accounts
                 WHERE user_id = :user_id AND provider = :provider AND external_user_id <> :external_user_id'
            )->execute([
                'user_id' => $userId,
                'provider' => self::PROVIDER,
                'external_user_id' => $steamId,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO external_accounts (user_id, provider, external_user_id, external_username, extra_json, linked_at)
                 VALUES (:user_id, :provider, :external_user_id, :external_username, :extra_json, NOW())
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    external_username = VALUES(external_username),
                    extra_json = VALUES(extra_json),
                    linked_at = NOW()'
            );
            $stmt->execute([
                'user_id' => $userId,
                'provider' => self::PROVIDER,
                'external_user_id' => $steamId,
                'external_username' => $username !== '' ? substr($username, 0, 190) : null,
                'extra_json' => json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function normalizedOpenIdParams(array $query): array
    {
        $params = [];
        foreach ($query as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'openid_')) {
                $key = 'openid.' . substr($key, strlen('openid_'));
            }
            if (str_starts_with($key, 'openid.')) {
                $params[$key] = is_array($value) ? reset($value) : (string) $value;
            }
        }

        return $params;
    }

    private static function verifyOpenId(array $openid): bool
    {
        $payload = $openid;
        $payload['openid.mode'] = 'check_authentication';
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: text/plain\r\n",
                'content' => http_build_query($payload),
                'timeout' => 15,
            ],
        ]);

        $body = file_get_contents(self::OPENID_ENDPOINT, false, $context);
        if ($body === false) {
            return false;
        }

        return preg_match('/(^|\n)is_valid\s*:\s*true(\n|$)/i', $body) === 1;
    }

    private static function fetchSteamProfile(string $steamId, array $config): array
    {
        $apiKey = trim((string) ($config['steam_api_key'] ?? $config['api_key'] ?? ''));
        if ($apiKey === '') {
            return [];
        }

        $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?' . http_build_query([
            'key' => $apiKey,
            'steamids' => $steamId,
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $players = $decoded['response']['players'] ?? [];
        return isset($players[0]) && is_array($players[0]) ? $players[0] : [];
    }
}
