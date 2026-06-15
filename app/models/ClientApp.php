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

        return [
            'client_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_DAYS * 86400,
            'user' => self::userPayload(User::findByIdWithRoles((int) $user['id']) ?? $user),
        ];
    }

    public static function authenticate(string $token): ?array
    {
        self::ensureEnabled();
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT cs.*, u.username, u.email, u.display_name, u.status
             FROM client_sessions cs
             INNER JOIN users u ON u.id = cs.user_id
             WHERE cs.token_hash = :token_hash
               AND cs.status = "active"
               AND cs.expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken(trim($token))]);
        $session = $stmt->fetch();
        if (!is_array($session) || $session['status'] !== 'active') {
            return null;
        }

        if (($session['status'] ?? '') === 'blocked') {
            return null;
        }

        Database::pdo()->prepare('UPDATE client_sessions SET last_used_at = NOW() WHERE id = :id')->execute(['id' => (int) $session['id']]);

        return $session;
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

        return [
            'linked_games' => array_map(static function (array $game) use ($builds): array {
                $gameId = (int) $game['game_id'];
                $game['install_build'] = $builds[$gameId] ?? null;
                return $game;
            }, $linkedGames),
            'catalog' => array_map(static function (array $game) use ($builds): array {
                $gameId = (int) $game['id'];
                $game['install_build'] = $builds[$gameId] ?? null;
                return $game;
            }, $catalog),
        ];
    }

    public static function revoke(string $token): void
    {
        self::ensureTables();
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

    private static function hashToken(string $token): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', $token, $pepper);
    }
}
