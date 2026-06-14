<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Game
{
    private const VISIBLE_STATUSES = ['development', 'playtest', 'beta', 'published'];

    public static function publicGames(?int $userId = null, string $status = 'all'): array
    {
        $params = [];
        $where = ['g.status IN ("development", "playtest", "beta", "published")'];

        if ($status !== 'all' && in_array($status, self::VISIBLE_STATUSES, true)) {
            $where[] = 'g.status = :status';
            $params['status'] = $status;
        }

        $linkedSelect = '0 AS is_linked';
        $linkedJoin = '';
        if ($userId !== null) {
            $linkedSelect = 'CASE WHEN ug.user_id IS NULL THEN 0 ELSE 1 END AS is_linked';
            $linkedJoin = 'LEFT JOIN user_games ug ON ug.game_id = g.id AND ug.user_id = :linked_user_id';
            $params['linked_user_id'] = $userId;
        }

        $sql = 'SELECT g.id, g.name, g.slug, g.description, g.status, g.current_version, g.banner_path,
                       g.config_json, g.endpoints_json, g.external_database_json, g.cdn_json, g.created_at, g.updated_at,
                       ' . $linkedSelect . ',
                       builds.latest_build_at,
                       builds.build_count
                FROM games g
                ' . $linkedJoin . '
                LEFT JOIN (
                    SELECT game_id, MAX(created_at) AS latest_build_at, COUNT(*) AS build_count
                    FROM game_builds
                    GROUP BY game_id
                ) builds ON builds.game_id = g.id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY FIELD(g.status, "published", "beta", "playtest", "development"), g.name ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function findPublicBySlug(string $slug, ?int $userId = null): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $params = ['slug' => $slug];
        $linkedSelect = '0 AS is_linked';
        $linkedJoin = '';
        if ($userId !== null) {
            $linkedSelect = 'CASE WHEN ug.user_id IS NULL THEN 0 ELSE 1 END AS is_linked';
            $linkedJoin = 'LEFT JOIN user_games ug ON ug.game_id = g.id AND ug.user_id = :linked_user_id';
            $params['linked_user_id'] = $userId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT g.*, ' . $linkedSelect . ',
                    builds.latest_build_at,
                    builds.build_count
             FROM games g
             ' . $linkedJoin . '
             LEFT JOIN (
                 SELECT game_id, MAX(created_at) AS latest_build_at, COUNT(*) AS build_count
                 FROM game_builds
                 GROUP BY game_id
             ) builds ON builds.game_id = g.id
             WHERE g.slug = :slug
               AND g.status IN ("development", "playtest", "beta", "published")
             LIMIT 1'
        );
        $stmt->execute($params);
        $game = $stmt->fetch();

        return is_array($game) ? $game : null;
    }

    public static function linkUser(int $userId, int $gameId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_games (user_id, game_id, linked_at)
             VALUES (:user_id, :game_id, NOW())
             ON DUPLICATE KEY UPDATE linked_at = linked_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
    }

    public static function unlinkUser(int $userId, int $gameId): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_games WHERE user_id = :user_id AND game_id = :game_id');
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
    }

    public static function userLinks(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ug.*, g.name, g.slug, g.status, g.current_version
             FROM user_games ug
             INNER JOIN games g ON g.id = ug.game_id
             WHERE ug.user_id = :user_id
             ORDER BY ug.linked_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'development' => 'Desarrollo',
            'playtest' => 'Playtest',
            'beta' => 'Beta',
            'published' => 'Publicado',
            'archived' => 'Archivado',
            default => $status,
        };
    }

    public static function visibleStatuses(): array
    {
        return self::VISIBLE_STATUSES;
    }

    public static function decodeJson(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
