<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class PlayerData
{
    public static function ensureMainTable(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS game_player_data (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                data_key VARCHAR(120) NOT NULL,
                data_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_game_player_data_scope (game_id, user_id, data_key),
                INDEX idx_game_player_data_user (user_id),
                CONSTRAINT fk_game_player_data_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_player_data_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function save(int $gameId, int $userId, string $key, array $data): array
    {
        self::ensureMainTable();
        $key = self::normalizeKey($key);
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $dedicated = self::dedicatedConnection($gameId);
        if ($dedicated instanceof PDO) {
            self::ensureDedicatedTable($dedicated);
            $stmt = $dedicated->prepare(
                'INSERT INTO jevzgames_player_data
                    (platform_game_id, platform_user_id, data_key, data_json, created_at, updated_at)
                 VALUES
                    (:game_id, :user_id, :data_key, :data_json, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = NOW()'
            );
            $stmt->execute([
                'game_id' => $gameId,
                'user_id' => $userId,
                'data_key' => $key,
                'data_json' => $json,
            ]);

            return [
                'storage' => 'dedicated',
                'key' => $key,
                'data_json' => $json,
                'data' => $data,
            ];
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_player_data
                (game_id, user_id, data_key, data_json, created_at, updated_at)
             VALUES
                (:game_id, :user_id, :data_key, :data_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = NOW()'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
            'data_key' => $key,
            'data_json' => $json,
        ]);

        return [
            'storage' => 'main',
            'key' => $key,
            'data_json' => $json,
            'data' => $data,
        ];
    }

    public static function load(int $gameId, int $userId, string $key): array
    {
        self::ensureMainTable();
        $key = self::normalizeKey($key);

        $dedicated = self::dedicatedConnection($gameId);
        if ($dedicated instanceof PDO) {
            self::ensureDedicatedTable($dedicated);
            $stmt = $dedicated->prepare(
                'SELECT data_json, updated_at
                 FROM jevzgames_player_data
                 WHERE platform_game_id = :game_id AND platform_user_id = :user_id AND data_key = :data_key
                 LIMIT 1'
            );
            $stmt->execute([
                'game_id' => $gameId,
                'user_id' => $userId,
                'data_key' => $key,
            ]);
            $row = $stmt->fetch();

            return self::loadPayload($row, 'dedicated', $key);
        }

        $stmt = Database::pdo()->prepare(
            'SELECT data_json, updated_at
             FROM game_player_data
             WHERE game_id = :game_id AND user_id = :user_id AND data_key = :data_key
             LIMIT 1'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
            'data_key' => $key,
        ]);
        $row = $stmt->fetch();

        return self::loadPayload($row, 'main', $key);
    }

    private static function dedicatedConnection(int $gameId): ?PDO
    {
        try {
            return GameDatabase::dedicatedPdoForGameId($gameId);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ensureDedicatedTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jevzgames_player_data (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                platform_game_id INT UNSIGNED NOT NULL,
                platform_user_id INT UNSIGNED NOT NULL,
                data_key VARCHAR(120) NOT NULL,
                data_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_jevzgames_player_data_scope (platform_game_id, platform_user_id, data_key),
                INDEX idx_jevzgames_player_data_user (platform_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function loadPayload(mixed $row, string $storage, string $key): array
    {
        if (!is_array($row)) {
            return [
                'found' => false,
                'storage' => $storage,
                'key' => $key,
                'data_json' => null,
                'data' => null,
                'updated_at' => null,
            ];
        }

        $json = (string) $row['data_json'];
        $decoded = json_decode($json, true);

        return [
            'found' => true,
            'storage' => $storage,
            'key' => $key,
            'data_json' => $json,
            'data' => is_array($decoded) ? $decoded : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9_.:-]{2,120}$/', $key)) {
            throw new RuntimeException('data key invalida.');
        }

        return $key;
    }
}
