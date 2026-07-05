<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Playtime
{
    public static function ensureColumns(): void
    {
        self::addColumnIfMissing('user_games', 'last_played_at', 'DATETIME NULL AFTER linked_at');
        self::addColumnIfMissing('user_games', 'playtime_only', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER last_played_at');
        self::addColumnIfMissing('user_games', 'total_play_seconds', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_played_at');
        self::addColumnIfMissing('user_games', 'last_play_started_at', 'DATETIME NULL AFTER total_play_seconds');
    }

    public static function start(int $userId, int $gameId): void
    {
        if ($userId <= 0 || $gameId <= 0) {
            return;
        }

        self::ensureColumns();
        Database::pdo()->prepare(
            'INSERT INTO user_games (user_id, game_id, linked_at, playtime_only)
             VALUES (:user_id, :game_id, NOW(), 1)
             ON DUPLICATE KEY UPDATE linked_at = linked_at'
        )->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
        Database::pdo()->prepare(
            'UPDATE user_games
             SET last_play_started_at = COALESCE(last_play_started_at, NOW()),
                 last_played_at = NOW()
             WHERE user_id = :user_id AND game_id = :game_id'
        )->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
    }

    public static function stop(int $userId, ?int $gameId = null): void
    {
        if ($userId <= 0) {
            return;
        }

        self::ensureColumns();
        $params = ['user_id' => $userId];
        $where = 'user_id = :user_id AND last_play_started_at IS NOT NULL';
        if ($gameId !== null && $gameId > 0) {
            $where .= ' AND game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        Database::pdo()->prepare(
            'UPDATE user_games
             SET total_play_seconds = total_play_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, last_play_started_at, NOW())),
                 last_play_started_at = NULL,
                 last_played_at = NOW()
             WHERE ' . $where
        )->execute($params);
    }

    public static function rowsForUser(int $userId): array
    {
        self::ensureColumns();
        $stmt = Database::pdo()->prepare(
            'SELECT ug.game_id, ug.total_play_seconds, ug.last_played_at, g.name, g.slug
             FROM user_games ug
             INNER JOIN games g ON g.id = ug.game_id
             WHERE ug.user_id = :user_id
             ORDER BY ug.total_play_seconds DESC, ug.last_played_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        if ((int) $stmt->fetchColumn() === 0) {
            Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
