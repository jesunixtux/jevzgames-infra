<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class OAuth
{
    private const DEVICE_EXPIRES_MINUTES = 10;
    private const ACCESS_TOKEN_EXPIRES_DAYS = 30;
    private const POLL_INTERVAL_SECONDS = 3;

    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS game_oauth_device_codes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                public_key VARCHAR(120) NOT NULL,
                device_code_hash VARCHAR(128) NOT NULL UNIQUE,
                user_code_hash VARCHAR(128) NOT NULL UNIQUE,
                user_code_preview VARCHAR(20) NOT NULL,
                status ENUM("pending", "authorized", "denied", "expired") NOT NULL DEFAULT "pending",
                approved_user_id INT UNSIGNED NULL,
                expires_at DATETIME NOT NULL,
                last_polled_at DATETIME NULL,
                authorized_at DATETIME NULL,
                token_issued_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_game_oauth_device_codes_game (game_id),
                INDEX idx_game_oauth_device_codes_status (status),
                INDEX idx_game_oauth_device_codes_expires (expires_at),
                CONSTRAINT fk_game_oauth_device_codes_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_oauth_device_codes_user FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS game_oauth_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                device_code_id BIGINT UNSIGNED NULL,
                access_token_hash VARCHAR(128) NOT NULL UNIQUE,
                status ENUM("active", "revoked") NOT NULL DEFAULT "active",
                expires_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                INDEX idx_game_oauth_tokens_game (game_id),
                INDEX idx_game_oauth_tokens_user (user_id),
                INDEX idx_game_oauth_tokens_status (status),
                CONSTRAINT fk_game_oauth_tokens_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_oauth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_oauth_tokens_device FOREIGN KEY (device_code_id) REFERENCES game_oauth_device_codes(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function createDeviceCode(string $publicKey): array
    {
        self::ensureTables();
        $publicKey = trim($publicKey);
        $game = self::gameByPublicKey($publicKey);
        if (!$game) {
            throw new RuntimeException('API key de juego invalida o revocada.');
        }

        $deviceCode = 'jvg_dc_' . bin2hex(random_bytes(32));
        $userCode = self::generateUserCode();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_oauth_device_codes
                (game_id, public_key, device_code_hash, user_code_hash, user_code_preview, status, expires_at, created_at)
             VALUES
                (:game_id, :public_key, :device_code_hash, :user_code_hash, :user_code_preview, "pending", DATE_ADD(NOW(), INTERVAL ' . self::DEVICE_EXPIRES_MINUTES . ' MINUTE), NOW())'
        );
        $stmt->execute([
            'game_id' => (int) $game['id'],
            'public_key' => $publicKey,
            'device_code_hash' => self::hashValue($deviceCode),
            'user_code_hash' => self::hashValue($userCode),
            'user_code_preview' => $userCode,
        ]);

        $verificationUri = \url('/oauth/authorize/');
        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => $verificationUri,
            'verification_uri_complete' => $verificationUri . '?user_code=' . rawurlencode($userCode),
            'expires_in' => self::DEVICE_EXPIRES_MINUTES * 60,
            'interval' => self::POLL_INTERVAL_SECONDS,
            'game' => self::publicGamePayload($game),
        ];
    }

    public static function findDeviceByUserCode(string $userCode): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT dc.*, g.name AS game_name, g.slug AS game_slug, g.status AS game_status, g.current_version
             FROM game_oauth_device_codes dc
             INNER JOIN games g ON g.id = dc.game_id
             WHERE dc.user_code_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['hash' => self::hashValue(self::normalizeUserCode($userCode))]);
        $device = $stmt->fetch();

        if (!is_array($device)) {
            return null;
        }

        self::expireIfNeeded($device);
        $device = self::deviceById((int) $device['id']) ?? $device;

        return $device;
    }

    public static function approveDevice(int $deviceId, int $userId): void
    {
        self::ensureTables();
        $device = self::deviceById($deviceId);
        if (!$device) {
            throw new RuntimeException('Solicitud OAuth no encontrada.');
        }

        $device = self::assertPending($device);
        Game::grantLicense($userId, (int) $device['game_id'], 'oauth');

        $stmt = Database::pdo()->prepare(
            'UPDATE game_oauth_device_codes
             SET status = "authorized", approved_user_id = :user_id, authorized_at = NOW()
             WHERE id = :id AND status = "pending"'
        );
        $stmt->execute([
            'id' => $deviceId,
            'user_id' => $userId,
        ]);
    }

    public static function denyDevice(int $deviceId, int $userId): void
    {
        self::ensureTables();
        $device = self::deviceById($deviceId);
        if (!$device) {
            throw new RuntimeException('Solicitud OAuth no encontrada.');
        }

        $device = self::assertPending($device);
        $stmt = Database::pdo()->prepare(
            'UPDATE game_oauth_device_codes
             SET status = "denied", approved_user_id = :user_id, authorized_at = NOW()
             WHERE id = :id AND status = "pending"'
        );
        $stmt->execute([
            'id' => $deviceId,
            'user_id' => $userId,
        ]);
    }

    public static function pollToken(string $deviceCode, string $publicKey): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT dc.*, g.name AS game_name, g.slug AS game_slug, g.status AS game_status, g.current_version,
                    u.username, u.email
             FROM game_oauth_device_codes dc
             INNER JOIN games g ON g.id = dc.game_id
             LEFT JOIN users u ON u.id = dc.approved_user_id
             WHERE dc.device_code_hash = :device_hash AND dc.public_key = :public_key
             LIMIT 1'
        );
        $stmt->execute([
            'device_hash' => self::hashValue(trim($deviceCode)),
            'public_key' => trim($publicKey),
        ]);
        $device = $stmt->fetch();

        if (!is_array($device)) {
            throw new RuntimeException('device_code invalido.');
        }

        self::expireIfNeeded($device);
        $device = self::deviceById((int) $device['id']) ?? $device;
        self::touchPoll((int) $device['id']);

        if ($device['status'] === 'pending') {
            return [
                'ready' => false,
                'status' => 'authorization_pending',
                'interval' => self::POLL_INTERVAL_SECONDS,
            ];
        }

        if ($device['status'] === 'expired') {
            return [
                'ready' => false,
                'status' => 'expired_token',
            ];
        }

        if ($device['status'] === 'denied') {
            return [
                'ready' => false,
                'status' => 'access_denied',
            ];
        }

        if ($device['status'] !== 'authorized') {
            return [
                'ready' => false,
                'status' => 'invalid_status',
            ];
        }

        if (!empty($device['token_issued_at'])) {
            return [
                'ready' => false,
                'status' => 'token_already_issued',
            ];
        }

        $accessToken = 'jvg_at_' . bin2hex(random_bytes(32));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO game_oauth_tokens
                    (game_id, user_id, device_code_id, access_token_hash, status, expires_at, created_at)
                 VALUES
                    (:game_id, :user_id, :device_code_id, :access_token_hash, "active", DATE_ADD(NOW(), INTERVAL ' . self::ACCESS_TOKEN_EXPIRES_DAYS . ' DAY), NOW())'
            );
            $stmt->execute([
                'game_id' => (int) $device['game_id'],
                'user_id' => (int) $device['approved_user_id'],
                'device_code_id' => (int) $device['id'],
                'access_token_hash' => self::hashValue($accessToken),
            ]);

            $stmt = $pdo->prepare('UPDATE game_oauth_device_codes SET token_issued_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) $device['id']]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $freshDevice = self::deviceWithUser((int) $device['id']);
        return [
            'ready' => true,
            'status' => 'authorized',
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_EXPIRES_DAYS * 86400,
            'user' => [
                'id' => (int) ($freshDevice['approved_user_id'] ?? 0),
                'username' => (string) ($freshDevice['username'] ?? ''),
                'email' => (string) ($freshDevice['email'] ?? ''),
            ],
            'game' => [
                'id' => (int) $device['game_id'],
                'name' => (string) ($device['game_name'] ?? ''),
                'slug' => (string) ($device['game_slug'] ?? ''),
                'current_version' => $device['current_version'] ?? null,
            ],
            'license' => Game::licenseForUserGame((int) $device['approved_user_id'], (int) $device['game_id']),
        ];
    }

    public static function authenticateToken(string $accessToken): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, u.username, u.email, u.status AS user_status,
                    g.name AS game_name, g.slug AS game_slug, g.status AS game_status, g.current_version
             FROM game_oauth_tokens t
             INNER JOIN users u ON u.id = t.user_id
             INNER JOIN games g ON g.id = t.game_id
             WHERE t.access_token_hash = :hash
               AND t.status = "active"
               AND t.expires_at >= NOW()
               AND g.status IN ("development", "playtest", "beta", "published")
             LIMIT 1'
        );
        $stmt->execute(['hash' => self::hashValue(trim($accessToken))]);
        $token = $stmt->fetch();

        if (!is_array($token) || $token['user_status'] === 'blocked') {
            return null;
        }

        $stmt = Database::pdo()->prepare('UPDATE game_oauth_tokens SET last_used_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => (int) $token['id']]);

        return $token;
    }

    public static function gameByPublicKey(string $publicKey): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT g.*, k.public_key
             FROM game_api_keys k
             INNER JOIN games g ON g.id = k.game_id
             WHERE k.public_key = :public_key
               AND k.status = "active"
               AND g.status IN ("development", "playtest", "beta", "published")
             LIMIT 1'
        );
        $stmt->execute(['public_key' => trim($publicKey)]);
        $game = $stmt->fetch();

        if (is_array($game)) {
            $stmt = Database::pdo()->prepare('UPDATE game_api_keys SET last_used_at = NOW() WHERE public_key = :public_key');
            $stmt->execute(['public_key' => trim($publicKey)]);
        }

        return is_array($game) ? $game : null;
    }

    public static function publicGamePayload(array $game): array
    {
        return [
            'id' => (int) $game['id'],
            'name' => (string) $game['name'],
            'slug' => (string) $game['slug'],
            'status' => (string) $game['status'],
            'current_version' => $game['current_version'] ?? null,
            'config' => Game::decodeJson($game['config_json'] ?? null),
            'endpoints' => Game::decodeJson($game['endpoints_json'] ?? null),
            'dedicated_database' => GameDatabase::publicStatusFromGame($game),
            'cdn' => Game::decodeJson($game['cdn_json'] ?? null),
        ];
    }

    private static function assertPending(array $device): array
    {
        self::expireIfNeeded($device);
        $freshDevice = self::deviceById((int) $device['id']);
        if (!$freshDevice) {
            throw new RuntimeException('Solicitud OAuth no encontrada.');
        }

        if ($freshDevice['status'] !== 'pending') {
            throw new RuntimeException('Esta solicitud ya fue procesada o expiro.');
        }

        return $freshDevice;
    }

    private static function deviceById(int $deviceId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT dc.*, g.name AS game_name, g.slug AS game_slug, g.status AS game_status, g.current_version,
                    u.username, u.email
             FROM game_oauth_device_codes dc
             INNER JOIN games g ON g.id = dc.game_id
             LEFT JOIN users u ON u.id = dc.approved_user_id
             WHERE dc.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $deviceId]);
        $device = $stmt->fetch();

        return is_array($device) ? $device : null;
    }

    private static function deviceWithUser(int $deviceId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT dc.*, u.username, u.email
             FROM game_oauth_device_codes dc
             LEFT JOIN users u ON u.id = dc.approved_user_id
             WHERE dc.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $deviceId]);
        $device = $stmt->fetch();

        return is_array($device) ? $device : null;
    }

    private static function expireIfNeeded(array $device): void
    {
        if ($device['status'] !== 'pending') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE game_oauth_device_codes
             SET status = "expired"
             WHERE id = :id AND status = "pending" AND expires_at < NOW()'
        );
        $stmt->execute(['id' => (int) $device['id']]);
    }

    private static function touchPoll(int $deviceId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE game_oauth_device_codes SET last_polled_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $deviceId]);
    }

    private static function generateUserCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    private static function normalizeUserCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
        if (strlen($code) === 8) {
            return substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }

        return $code;
    }

    private static function hashValue(string $value): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', $value, $pepper);
    }
}
