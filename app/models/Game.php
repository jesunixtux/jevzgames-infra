<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Game
{
    private const VISIBLE_STATUSES = ['development', 'playtest', 'beta', 'published'];

    public static function ensureLicenseTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_game_licenses (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                game_id INT UNSIGNED NOT NULL,
                source VARCHAR(80) NOT NULL DEFAULT "manual",
                license_key_hash VARCHAR(128) NOT NULL UNIQUE,
                license_key_preview VARCHAR(32) NOT NULL,
                status ENUM("active", "revoked") NOT NULL DEFAULT "active",
                granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_game_licenses_scope (user_id, game_id),
                INDEX idx_user_game_licenses_user (user_id),
                INDEX idx_user_game_licenses_game (game_id),
                INDEX idx_user_game_licenses_status (status),
                CONSTRAINT fk_user_game_licenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_game_licenses_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function publicGames(?int $userId = null, string $status = 'all'): array
    {
        $params = [];
        $where = ['g.status IN ("development", "playtest", "beta", "published")'];

        if ($status !== 'all' && in_array($status, self::VISIBLE_STATUSES, true)) {
            $where[] = 'g.status = :status';
            $params['status'] = $status;
        }

        $linkedSelect = '0 AS is_linked, 0 AS has_license';
        $linkedJoin = '';
        if ($userId !== null) {
            self::ensureLicenseTables();
            $linkedSelect = 'CASE WHEN ug.user_id IS NULL THEN 0 ELSE 1 END AS is_linked,
                             CASE WHEN ugl.id IS NULL THEN 0 ELSE 1 END AS has_license';
            $linkedJoin = 'LEFT JOIN user_games ug ON ug.game_id = g.id AND ug.user_id = :linked_user_id
                           LEFT JOIN user_game_licenses ugl ON ugl.game_id = g.id AND ugl.user_id = :licensed_user_id AND ugl.status = "active"';
            $params['linked_user_id'] = $userId;
            $params['licensed_user_id'] = $userId;
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
        $linkedSelect = '0 AS is_linked, 0 AS has_license';
        $linkedJoin = '';
        if ($userId !== null) {
            self::ensureLicenseTables();
            $linkedSelect = 'CASE WHEN ug.user_id IS NULL THEN 0 ELSE 1 END AS is_linked,
                             CASE WHEN ugl.id IS NULL THEN 0 ELSE 1 END AS has_license';
            $linkedJoin = 'LEFT JOIN user_games ug ON ug.game_id = g.id AND ug.user_id = :linked_user_id
                           LEFT JOIN user_game_licenses ugl ON ugl.game_id = g.id AND ugl.user_id = :licensed_user_id AND ugl.status = "active"';
            $params['linked_user_id'] = $userId;
            $params['licensed_user_id'] = $userId;
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
        self::grantLicense($userId, $gameId, 'manual');
    }

    public static function ensureUserGameMetadataColumns(): void
    {
        self::addColumnIfMissing('user_games', 'last_played_at', 'DATETIME NULL AFTER linked_at');
    }

    public static function grantLicense(int $userId, int $gameId, string $source = 'manual'): array
    {
        if ($userId <= 0 || $gameId <= 0) {
            throw new RuntimeException('Licencia de juego invalida.');
        }

        if (!Database::pdo()->inTransaction()) {
            self::ensureLicenseTables();
        }

        self::upsertUserLink($userId, $gameId);

        $source = self::cleanLicenseSource($source);
        $licenseKey = 'jvg_lic_' . bin2hex(random_bytes(24));
        $licenseHash = self::hashLicenseKey($licenseKey);
        $preview = substr($licenseKey, 0, 12) . '...' . substr($licenseKey, -6);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_game_licenses (user_id, game_id, source, license_key_hash, license_key_preview, status, granted_at)
             VALUES (:user_id, :game_id, :source, :license_key_hash, :license_key_preview, "active", NOW())
             ON DUPLICATE KEY UPDATE
                source = VALUES(source),
                status = "active",
                revoked_at = NULL,
                updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
            'source' => $source,
            'license_key_hash' => $licenseHash,
            'license_key_preview' => $preview,
        ]);

        return self::licenseForUserGame($userId, $gameId) ?? [
            'licensed' => true,
            'game_id' => $gameId,
            'source' => $source,
            'status' => 'active',
        ];
    }

    public static function licenseForUserGame(int $userId, int $gameId): ?array
    {
        if (!Database::pdo()->inTransaction()) {
            self::ensureLicenseTables();
        }

        $stmt = Database::pdo()->prepare(
            'SELECT l.*, g.name AS game_name, g.slug AS game_slug
             FROM user_game_licenses l
             INNER JOIN games g ON g.id = l.game_id
             WHERE l.user_id = :user_id
               AND l.game_id = :game_id
               AND l.status = "active"
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
        $license = $stmt->fetch();

        return is_array($license) ? self::licensePayload($license) : null;
    }

    public static function gameIdBySlug(string $slug): ?int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM games WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => strtolower(trim($slug))]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private static function upsertUserLink(int $userId, int $gameId): void
    {
        self::ensureUserGameMetadataColumns();
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

    public static function unlinkUser(int $userId, int $gameId, bool $deleteGameData = true): void
    {
        if ($deleteGameData) {
            self::purgeUserGameData($userId, $gameId);
        }

        $stmt = Database::pdo()->prepare('DELETE FROM user_games WHERE user_id = :user_id AND game_id = :game_id');
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
    }

    public static function userLinks(int $userId): array
    {
        self::ensureLicenseTables();
        self::ensureUserGameMetadataColumns();
        $stmt = Database::pdo()->prepare(
            'SELECT ug.*, g.name, g.slug, g.status, g.current_version, g.config_json,
                    l.id AS license_id, l.source AS license_source, l.license_key_preview, l.status AS license_status, l.granted_at AS licensed_at
             FROM user_games ug
             INNER JOIN games g ON g.id = ug.game_id
             LEFT JOIN user_game_licenses l ON l.user_id = ug.user_id AND l.game_id = ug.game_id AND l.status = "active"
             WHERE ug.user_id = :user_id
             ORDER BY ug.linked_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function recordLastPlayed(int $userId, int $gameId): void
    {
        if ($userId <= 0 || $gameId <= 0) {
            return;
        }

        self::ensureUserGameMetadataColumns();
        $stmt = Database::pdo()->prepare(
            'UPDATE user_games
             SET last_played_at = NOW()
             WHERE user_id = :user_id AND game_id = :game_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
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

    public static function purgeUserGameData(int $userId, int $gameId): void
    {
        $pdo = Database::pdo();
        $tables = [
            'user_cloud_saves' => 'DELETE FROM user_cloud_saves WHERE user_id = :user_id AND game_id = :game_id',
            'game_player_data' => 'DELETE FROM game_player_data WHERE user_id = :user_id AND game_id = :game_id',
            'user_achievements' => 'DELETE FROM user_achievements WHERE user_id = :user_id AND game_id = :game_id',
            'game_oauth_tokens' => 'DELETE FROM game_oauth_tokens WHERE user_id = :user_id AND game_id = :game_id',
            'user_inventory' => 'DELETE FROM user_inventory WHERE user_id = :user_id AND game_id = :game_id',
            'code_redemptions' => 'DELETE FROM code_redemptions WHERE user_id = :user_id AND game_id = :game_id',
            'user_game_licenses' => 'DELETE FROM user_game_licenses WHERE user_id = :user_id AND game_id = :game_id',
        ];

        foreach ($tables as $table => $sql) {
            if (!self::tableExists($table)) {
                continue;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'game_id' => $gameId,
            ]);
        }
    }

    private static function tableExists(string $table): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute(['table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
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

    private static function cleanLicenseSource(string $source): string
    {
        $source = strtolower(trim($source));
        return preg_match('/^[a-z0-9_.:-]{2,80}$/', $source) ? $source : 'manual';
    }

    private static function hashLicenseKey(string $licenseKey): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', $licenseKey, $pepper);
    }

    private static function licensePayload(array $row): array
    {
        return [
            'licensed' => true,
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'game' => [
                'id' => (int) $row['game_id'],
                'name' => (string) ($row['game_name'] ?? ''),
                'slug' => (string) ($row['game_slug'] ?? ''),
            ],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'license_key_preview' => (string) $row['license_key_preview'],
            'granted_at' => $row['granted_at'] ?? null,
        ];
    }
}
