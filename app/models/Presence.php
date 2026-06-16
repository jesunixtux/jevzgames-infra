<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Presence
{
    private const ONLINE_WINDOW_SECONDS = 180;

    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_presence (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                status ENUM("online", "in_game", "offline") NOT NULL DEFAULT "online",
                game_id INT UNSIGNED NULL,
                game_name VARCHAR(140) NULL,
                game_slug VARCHAR(160) NULL,
                source VARCHAR(80) NULL,
                last_seen_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_presence_status (status),
                INDEX idx_user_presence_last_seen (last_seen_at),
                INDEX idx_user_presence_game (game_id),
                CONSTRAINT fk_user_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_presence_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function set(int $userId, string $status = 'online', ?int $gameId = null, string $source = 'web'): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new RuntimeException('Usuario invalido.');
        }

        $status = self::cleanStatus($status);
        $game = null;
        if ($status === 'in_game') {
            $game = self::gameById($gameId ?? 0);
            if (!$game) {
                throw new RuntimeException('Juego invalido para presencia.');
            }
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_presence (user_id, status, game_id, game_name, game_slug, source, last_seen_at, updated_at)
             VALUES (:user_id, :status, :game_id, :game_name, :game_slug, :source, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                game_id = VALUES(game_id),
                game_name = VALUES(game_name),
                game_slug = VALUES(game_slug),
                source = VALUES(source),
                last_seen_at = NOW(),
                updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'status' => $status,
            'game_id' => $game ? (int) $game['id'] : null,
            'game_name' => $game ? (string) $game['name'] : null,
            'game_slug' => $game ? (string) $game['slug'] : null,
            'source' => substr(trim($source), 0, 80),
        ]);

        return self::forUser($userId);
    }

    public static function setBySlug(int $userId, string $status, string $slug, string $source = 'client'): array
    {
        $gameId = null;
        if (self::cleanStatus($status) === 'in_game') {
            $gameId = Game::gameIdBySlug($slug);
            if ($gameId === null) {
                throw new RuntimeException('Juego invalido para presencia.');
            }
        }

        return self::set($userId, $status, $gameId, $source);
    }

    public static function touch(int $userId, string $source = 'web'): array
    {
        self::ensureTables();
        if ($userId <= 0) {
            throw new RuntimeException('Usuario invalido.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_presence (user_id, status, source, last_seen_at, updated_at)
             VALUES (:user_id, "online", :source, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = CASE WHEN status = "in_game" THEN status ELSE "online" END,
                source = VALUES(source),
                last_seen_at = NOW(),
                updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source' => substr(trim($source), 0, 80),
        ]);

        return self::forUser($userId);
    }

    public static function offline(int $userId): void
    {
        self::ensureTables();
        if ($userId <= 0) {
            return;
        }

        Database::pdo()->prepare(
            'INSERT INTO user_presence (user_id, status, source, last_seen_at, updated_at)
             VALUES (:user_id, "offline", "logout", NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = "offline",
                game_id = NULL,
                game_name = NULL,
                game_slug = NULL,
                source = "logout",
                last_seen_at = NOW(),
                updated_at = NOW()'
        )->execute(['user_id' => $userId]);
    }

    public static function forUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS seconds_since_seen
             FROM user_presence p
             WHERE p.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return self::offlinePayload($userId);
        }

        $secondsSinceSeen = max(0, (int) ($row['seconds_since_seen'] ?? self::ONLINE_WINDOW_SECONDS + 1));
        $status = (string) ($row['status'] ?? 'offline');
        $connected = $status !== 'offline' && $secondsSinceSeen <= self::ONLINE_WINDOW_SECONDS;
        if (!$connected) {
            $status = 'offline';
        }

        return [
            'user_id' => $userId,
            'connected' => $connected,
            'status' => $status,
            'game_id' => $status === 'in_game' ? (int) ($row['game_id'] ?? 0) : null,
            'game_name' => $status === 'in_game' ? (string) ($row['game_name'] ?? '') : null,
            'game_slug' => $status === 'in_game' ? (string) ($row['game_slug'] ?? '') : null,
            'source' => (string) ($row['source'] ?? ''),
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'seconds_since_seen' => $secondsSinceSeen,
        ];
    }

    public static function label(array $presence): string
    {
        if (($presence['status'] ?? '') === 'in_game' && !empty($presence['game_name'])) {
            return str_replace('{game}', (string) $presence['game_name'], \t('presence.playing'));
        }

        return !empty($presence['connected']) ? \t('presence.online') : \t('presence.offline');
    }

    private static function cleanStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['online', 'in_game', 'offline'], true) ? $status : 'online';
    }

    private static function gameById(int $gameId): ?array
    {
        if ($gameId <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, slug
             FROM games
             WHERE id = :id
               AND status IN ("development", "playtest", "beta", "published")
             LIMIT 1'
        );
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();

        return is_array($game) ? $game : null;
    }

    private static function offlinePayload(int $userId): array
    {
        return [
            'user_id' => $userId,
            'connected' => false,
            'status' => 'offline',
            'game_id' => null,
            'game_name' => null,
            'game_slug' => null,
            'source' => '',
            'last_seen_at' => null,
            'seconds_since_seen' => null,
        ];
    }
}
