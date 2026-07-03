<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class ClientApp
{
    private const TOKEN_DAYS = 30;

    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS client_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash VARCHAR(128) NOT NULL UNIQUE,
                client_name VARCHAR(120) NULL,
                status ENUM("active", "revoked") NOT NULL DEFAULT "active",
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                INDEX idx_client_sessions_user (user_id),
                INDEX idx_client_sessions_status (status),
                CONSTRAINT fk_client_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function login(string $identity, string $password, string $clientName = ''): array
    {
        self::ensureEnabled();
        self::ensureTables();
        $user = User::findByEmailOrUsername($identity);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new RuntimeException('Credenciales invalidas.');
        }

        if (($user['status'] ?? '') === 'blocked') {
            throw new RuntimeException('Tu cuenta se encuentra suspendida.');
        }

        if (($user['status'] ?? '') !== 'active') {
            throw new RuntimeException('La cuenta no esta activa.');
        }

        if (PlatformSettings::emailVerificationRequired() && !User::isEmailVerified($user)) {
            throw new RuntimeException('Debes verificar tu correo antes de iniciar sesion.');
        }

        $token = 'jvg_ct_' . bin2hex(random_bytes(32));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO client_sessions (user_id, token_hash, client_name, status, expires_at, created_at)
             VALUES (:user_id, :token_hash, :client_name, "active", DATE_ADD(NOW(), INTERVAL ' . self::TOKEN_DAYS . ' DAY), NOW())'
        );
        $stmt->execute([
            'user_id' => (int) $user['id'],
            'token_hash' => self::hashToken($token),
            'client_name' => trim($clientName) !== '' ? substr(trim($clientName), 0, 120) : null,
        ]);
        User::touchLastLogin((int) $user['id']);
        try {
            Presence::set((int) $user['id'], 'online', null, 'client');
        } catch (\Throwable) {
        }

        return [
            'client_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_DAYS * 86400,
            'user' => self::userPayload(User::findByIdWithRoles((int) $user['id']) ?? $user),
        ];
    }

    public static function authenticate(string $token, bool $requireEnabled = true): ?array
    {
        if ($requireEnabled) {
            self::ensureEnabled();
        }
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT cs.*,
                    cs.status AS client_session_status,
                    u.username,
                    u.email,
                    u.display_name,
                    u.status AS user_status
             FROM client_sessions cs
             INNER JOIN users u ON u.id = cs.user_id
             WHERE cs.token_hash = :token_hash
               AND cs.status = "active"
               AND cs.expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken(trim($token))]);
        $session = $stmt->fetch();
        if (!is_array($session) || ($session['client_session_status'] ?? '') !== 'active') {
            return null;
        }

        if (($session['user_status'] ?? '') !== 'active') {
            return null;
        }

        Database::pdo()->prepare('UPDATE client_sessions SET last_used_at = NOW() WHERE id = :id')->execute(['id' => (int) $session['id']]);
        try {
            Presence::touch((int) $session['user_id'], 'client');
        } catch (\Throwable) {
        }

        return $session;
    }

    public static function me(array $session): array
    {
        $userId = (int) $session['user_id'];

        return [
            'user' => self::sessionUserPayload($session),
            'presence' => self::presencePayload(Presence::forUser($userId)),
        ];
    }

    public static function library(int $userId): array
    {
        self::ensureEnabled();
        $linkedGames = Game::userLinks($userId);
        $catalog = Game::publicGames($userId, 'all');
        $gameIds = array_merge(
            array_map(static fn (array $game): int => (int) $game['game_id'], $linkedGames),
            array_map(static fn (array $game): int => (int) $game['id'], $catalog)
        );
        $builds = GameBuild::latestForGames($gameIds);
        $generatedAt = date('c');
        $ownedGames = array_map(
            static fn (array $game): array => self::ownedGamePayload($userId, $game, $builds[(int) $game['game_id']] ?? null, $generatedAt),
            $linkedGames
        );

        return [
            'owned_games' => $ownedGames,
            'linked_games' => array_map(static function (array $game) use ($builds, $userId, $generatedAt): array {
                $gameId = (int) $game['game_id'];
                $game['install_build'] = $builds[$gameId] ?? null;
                $game['license'] = Game::licenseForUserGame((int) $game['user_id'], $gameId);
                $normalized = self::ownedGamePayload($userId, $game, $game['install_build'], $generatedAt);
                $game['has_license'] = $normalized['has_license'] ? 1 : 0;
                $game['is_linked'] = $normalized['is_linked'] ? 1 : 0;
                $game['offline_allowed'] = $normalized['offline_allowed'];
                $game['offline_available'] = $normalized['offline_available'];
                $game['offline_entitlement'] = $normalized['offline_entitlement'];
                $game['last_played_at'] = $normalized['last_played_at'];
                unset($game['config_json']);
                return $game;
            }, $linkedGames),
            'catalog' => array_map(static function (array $game) use ($builds): array {
                $gameId = (int) $game['id'];
                return self::catalogGamePayload($game, $builds[$gameId] ?? null);
            }, $catalog),
            'offline_cache' => self::offlineCachePolicy($generatedAt),
        ];
    }

    public static function obtainGame(int $userId, int $gameId): array
    {
        self::ensureEnabled();
        Game::ensureVisibilityColumn();
        if ($userId <= 0 || $gameId <= 0) {
            throw new RuntimeException('Juego invalido.');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, slug, status, visibility, current_version, config_json
             FROM games
             WHERE id = :id
               AND status IN ("development", "playtest", "beta", "published")
               AND visibility IN ("public", "unlisted")
             LIMIT 1'
        );
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();
        if (!is_array($game)) {
            throw new RuntimeException('El juego no existe o no esta disponible.');
        }

        $build = GameBuild::latestForGame($gameId);
        if ($build === null) {
            throw new RuntimeException('Este juego aun no tiene build instalable.');
        }

        $license = Game::grantLicense($userId, $gameId, 'client');
        $offlineAvailable = self::offlineAllowed($game) && self::buildSupportsLocalInstall($build);

        return [
            'game' => [
                'game_id' => $gameId,
                'name' => (string) $game['name'],
                'slug' => (string) $game['slug'],
                'status' => (string) $game['status'],
                'visibility' => (string) ($game['visibility'] ?? 'public'),
                'current_version' => $game['current_version'] ?? null,
                'is_linked' => 1,
                'has_license' => 1,
                'install_build' => $build,
                'offline_allowed' => self::offlineAllowed($game),
                'offline_available' => $offlineAvailable,
                'offline_entitlement' => self::offlineEntitlement($userId, [
                    'game_id' => $gameId,
                    'current_version' => $game['current_version'] ?? null,
                    'license_id' => $license['id'] ?? null,
                    'license_key_preview' => $license['license_key_preview'] ?? null,
                    'licensed_at' => $license['granted_at'] ?? null,
                ], self::offlineAllowed($game), $offlineAvailable, date('c')),
                'license' => $license,
            ],
            'license' => $license,
            'install_build' => $build,
        ];
    }

    public static function setPresence(int $userId, string $status, string $gameSlug = '', int $gameId = 0): array
    {
        self::ensureEnabled();
        $status = strtolower(trim($status));
        $status = in_array($status, ['online', 'in_game', 'offline'], true) ? $status : 'online';
        if ($status === 'in_game') {
            $resolvedGameId = $gameSlug !== '' ? (Game::gameIdBySlug($gameSlug) ?? 0) : $gameId;
            if ($resolvedGameId <= 0 || !self::userCanPlayGame($userId, $resolvedGameId)) {
                throw new RuntimeException('El juego no esta en tu biblioteca.');
            }
        }

        $presence = $gameSlug !== ''
            ? Presence::setBySlug($userId, $status, $gameSlug, 'client')
            : Presence::set($userId, $status, $gameId > 0 ? $gameId : null, 'client');

        if (($presence['status'] ?? '') === 'in_game' && !empty($presence['game_id'])) {
            Game::recordLastPlayed($userId, (int) $presence['game_id']);
        }

        return self::presencePayload($presence);
    }

    public static function revoke(string $token): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT user_id
             FROM client_sessions
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken(trim($token))]);
        $userId = $stmt->fetchColumn();
        if ($userId !== false) {
            try {
                Presence::offline((int) $userId);
            } catch (\Throwable) {
            }
        }

        Database::pdo()->prepare(
            'UPDATE client_sessions SET status = "revoked", revoked_at = NOW() WHERE token_hash = :token_hash'
        )->execute(['token_hash' => self::hashToken(trim($token))]);
    }

    public static function ensureEnabled(): void
    {
        if (!PlatformSettings::enabled('client')) {
            throw new RuntimeException('El cliente esta deshabilitado.');
        }
    }

    public static function userPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'email' => (string) $user['email'],
            'display_name' => (string) ($user['display_name'] ?? $user['username']),
            'status' => (string) ($user['status'] ?? 'active'),
        ];
    }

    public static function sessionUserPayload(array $session): array
    {
        return [
            'id' => (int) $session['user_id'],
            'username' => (string) $session['username'],
            'email' => (string) $session['email'],
            'display_name' => (string) ($session['display_name'] ?? $session['username']),
            'status' => (string) ($session['user_status'] ?? 'active'),
        ];
    }

    public static function presencePayload(array $presence): array
    {
        return [
            'status' => (string) ($presence['status'] ?? 'offline'),
            'connected' => !empty($presence['connected']),
            'game_id' => $presence['game_id'] ?? null,
            'game_slug' => $presence['game_slug'] ?? null,
            'game_name' => $presence['game_name'] ?? null,
            'last_seen_at' => $presence['last_seen_at'] ?? null,
            'source' => (string) ($presence['source'] ?? ''),
        ];
    }

    private static function ownedGamePayload(int $userId, array $game, ?array $build, string $generatedAt): array
    {
        $gameId = (int) ($game['game_id'] ?? $game['id'] ?? 0);
        $hasLicense = !empty($game['license_id']) || !empty($game['license']) || (int) ($game['has_license'] ?? 0) === 1;
        $isLinked = !empty($game['linked_at']) || (int) ($game['is_linked'] ?? 0) === 1;
        $offlineAllowed = self::offlineAllowed($game);
        $offlineAvailable = $offlineAllowed && $hasLicense && self::buildSupportsLocalInstall($build);

        return [
            'id' => $gameId,
            'game_id' => $gameId,
            'name' => (string) ($game['name'] ?? ''),
            'slug' => (string) ($game['slug'] ?? ''),
            'status' => (string) ($game['status'] ?? ''),
            'visibility' => (string) ($game['visibility'] ?? 'public'),
            'current_version' => $game['current_version'] ?? null,
            'has_license' => $hasLicense,
            'is_linked' => $isLinked,
            'install_build' => $build,
            'offline_allowed' => $offlineAllowed,
            'offline_available' => $offlineAvailable,
            'offline_entitlement' => self::offlineEntitlement($userId, $game, $offlineAllowed, $offlineAvailable, $generatedAt),
            'last_played_at' => $game['last_played_at'] ?? null,
            'linked_at' => $game['linked_at'] ?? null,
        ];
    }

    private static function catalogGamePayload(array $game, ?array $build): array
    {
        $hasLicense = (int) ($game['has_license'] ?? 0) === 1;
        $isLinked = (int) ($game['is_linked'] ?? 0) === 1;

        return [
            'id' => (int) $game['id'],
            'name' => (string) $game['name'],
            'slug' => (string) $game['slug'],
            'description' => $game['description'] ?? null,
            'status' => (string) $game['status'],
            'visibility' => (string) ($game['visibility'] ?? 'public'),
            'current_version' => $game['current_version'] ?? null,
            'banner_path' => $game['banner_path'] ?? null,
            'has_license' => $hasLicense,
            'is_linked' => $isLinked,
            'owned' => $hasLicense || $isLinked,
            'install_build' => $build,
            'offline_allowed' => self::offlineAllowed($game),
            'build_count' => isset($game['build_count']) ? (int) $game['build_count'] : 0,
            'latest_build_at' => $game['latest_build_at'] ?? null,
        ];
    }

    private static function offlineEntitlement(int $userId, array $game, bool $offlineAllowed, bool $offlineAvailable, string $generatedAt): array
    {
        $licenseId = !empty($game['license_id']) ? (int) $game['license_id'] : null;
        $gameId = (int) ($game['game_id'] ?? $game['id'] ?? 0);
        $reason = 'ok';
        if (!$offlineAllowed) {
            $reason = 'offline_disabled_for_game';
        } elseif ($licenseId === null) {
            $reason = 'no_active_license';
        } elseif (!$offlineAvailable) {
            $reason = 'no_installable_build';
        }

        return [
            'available' => $offlineAvailable,
            'reason' => $reason,
            'cache_version' => 1,
            'user_id' => $userId,
            'game_id' => $gameId,
            'license_id' => $licenseId,
            'license_key_preview' => $game['license_key_preview'] ?? null,
            'licensed_at' => $game['licensed_at'] ?? null,
            'generated_at' => $generatedAt,
            'cache_key' => hash('sha256', implode('|', [
                $userId,
                $gameId,
                $licenseId ?? 0,
                (string) ($game['licensed_at'] ?? ''),
                (string) ($game['current_version'] ?? ''),
            ])),
        ];
    }

    private static function offlineCachePolicy(string $generatedAt): array
    {
        return [
            'schema_version' => 1,
            'generated_at' => $generatedAt,
            'local_files' => [
                'session' => 'session.json',
                'library' => 'library-cache.json',
                'installed_game' => 'games/<slug>/installed.json',
            ],
            'rules' => [
                'store_passwords' => false,
                'store_token' => true,
                'launch_installed_owned_games_offline' => true,
                'download_new_games_offline' => false,
                'obtain_new_licenses_offline' => false,
                'offline_requires_prior_license' => true,
            ],
        ];
    }

    private static function buildSupportsLocalInstall(?array $build): bool
    {
        if ($build === null) {
            return false;
        }

        return (string) ($build['delivery_type'] ?? 'zip') === 'zip' && !empty($build['download_url']);
    }

    private static function offlineAllowed(array $game): bool
    {
        $config = Game::decodeJson(isset($game['config_json']) ? (string) $game['config_json'] : null);
        $value = self::configValue($config, ['client.offline_allowed', 'offline_allowed', 'drm.offline_allowed']);
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function configValue(array $config, array $paths): mixed
    {
        foreach ($paths as $path) {
            $cursor = $config;
            foreach (explode('.', $path) as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$part];
            }
            if ($cursor !== null) {
                return $cursor;
            }
        }

        return null;
    }

    private static function userCanPlayGame(int $userId, int $gameId): bool
    {
        if ($userId <= 0 || $gameId <= 0) {
            return false;
        }

        Game::ensureLicenseTables();
        Game::ensureUserGameMetadataColumns();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM games g
             LEFT JOIN user_games ug ON ug.game_id = g.id AND ug.user_id = :linked_user_id
             LEFT JOIN user_game_licenses ugl ON ugl.game_id = g.id AND ugl.user_id = :licensed_user_id AND ugl.status = "active"
             WHERE g.id = :game_id
               AND g.status IN ("development", "playtest", "beta", "published")
               AND (ug.user_id IS NOT NULL OR ugl.id IS NOT NULL)'
        );
        $stmt->execute([
            'linked_user_id' => $userId,
            'licensed_user_id' => $userId,
            'game_id' => $gameId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function hashToken(string $token): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', $token, $pepper);
    }
}
