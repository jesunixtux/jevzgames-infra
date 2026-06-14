<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class CloudSave
{
    private const STATUSES = ['active', 'disabled'];

    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS game_cloud_save_configs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                config_key VARCHAR(100) NOT NULL,
                name VARCHAR(160) NOT NULL,
                max_slots INT UNSIGNED NOT NULL DEFAULT 3,
                max_bytes INT UNSIGNED NOT NULL DEFAULT 65536,
                auto_sync TINYINT(1) NOT NULL DEFAULT 1,
                status ENUM("active", "disabled") NOT NULL DEFAULT "active",
                metadata_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_game_cloud_save_configs_game_key (game_id, config_key),
                INDEX idx_game_cloud_save_configs_game (game_id),
                CONSTRAINT fk_game_cloud_save_configs_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_cloud_saves (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                config_id BIGINT UNSIGNED NOT NULL,
                slot INT UNSIGNED NOT NULL DEFAULT 1,
                save_json LONGTEXT NOT NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                metadata_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_cloud_saves_scope (game_id, user_id, config_id, slot),
                INDEX idx_user_cloud_saves_user (user_id),
                CONSTRAINT fk_user_cloud_saves_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_cloud_saves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_cloud_saves_config FOREIGN KEY (config_id) REFERENCES game_cloud_save_configs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function configs(?int $gameId = null): array
    {
        self::ensureTables();
        $params = [];
        $where = '';
        if ($gameId !== null && $gameId > 0) {
            $where = 'WHERE c.game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT c.*, g.name AS game_name, g.slug AS game_slug,
                    saves.save_count, saves.player_count
             FROM game_cloud_save_configs c
             INNER JOIN games g ON g.id = c.game_id
             LEFT JOIN (
                 SELECT config_id, COUNT(*) AS save_count, COUNT(DISTINCT user_id) AS player_count
                 FROM user_cloud_saves
                 GROUP BY config_id
             ) saves ON saves.config_id = c.id
             ' . $where . '
             ORDER BY g.name ASC, c.name ASC'
        );
        $stmt->execute($params);

        return array_map(static function (array $row): array {
            $row['metadata'] = Game::decodeJson($row['metadata_json'] ?? null);
            unset($row['metadata_json']);
            return $row;
        }, $stmt->fetchAll());
    }

    public static function findConfig(int $configId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare('SELECT * FROM game_cloud_save_configs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $configId]);
        $config = $stmt->fetch();

        return is_array($config) ? $config : null;
    }

    public static function saveConfig(array $input): int
    {
        self::ensureTables();
        $data = self::validatedConfig($input);
        $pdo = Database::pdo();

        if ($data['id'] > 0) {
            $stmt = $pdo->prepare(
                'UPDATE game_cloud_save_configs
                 SET game_id = :game_id,
                     config_key = :config_key,
                     name = :name,
                     max_slots = :max_slots,
                     max_bytes = :max_bytes,
                     auto_sync = :auto_sync,
                     status = :status,
                     metadata_json = :metadata_json,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            try {
                $stmt->execute($data);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Ya existe una configuracion cloud con esa key para este juego.');
                }
                throw $exception;
            }

            return $data['id'];
        }

        $insertData = $data;
        unset($insertData['id']);
        $stmt = $pdo->prepare(
            'INSERT INTO game_cloud_save_configs
                (game_id, config_key, name, max_slots, max_bytes, auto_sync, status, metadata_json, created_at, updated_at)
             VALUES
                (:game_id, :config_key, :name, :max_slots, :max_bytes, :auto_sync, :status, :metadata_json, NOW(), NOW())'
        );
        try {
            $stmt->execute($insertData);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe una configuracion cloud con esa key para este juego.');
            }
            throw $exception;
        }

        return (int) $pdo->lastInsertId();
    }

    public static function updateConfigStatus(int $configId, string $status): void
    {
        self::ensureTables();
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Estado cloud invalido.');
        }

        $stmt = Database::pdo()->prepare('UPDATE game_cloud_save_configs SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $configId,
            'status' => $status,
        ]);
    }

    public static function configsForGame(int $gameId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM game_cloud_save_configs
             WHERE game_id = :game_id AND status = "active"
             ORDER BY name ASC'
        );
        $stmt->execute(['game_id' => $gameId]);

        return array_map(static fn (array $row): array => self::configPayload($row), $stmt->fetchAll());
    }

    public static function listForUser(int $userId, ?int $gameId = null): array
    {
        self::ensureTables();
        $params = ['user_id' => $userId];
        $where = 'WHERE s.user_id = :user_id';
        if ($gameId !== null && $gameId > 0) {
            $where .= ' AND s.game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.game_id, s.slot, s.size_bytes, s.metadata_json, s.created_at, s.updated_at,
                    c.config_key, c.name AS config_name, c.max_slots, c.max_bytes,
                    g.name AS game_name, g.slug AS game_slug
             FROM user_cloud_saves s
             INNER JOIN game_cloud_save_configs c ON c.id = s.config_id
             INNER JOIN games g ON g.id = s.game_id
             ' . $where . '
             ORDER BY s.updated_at DESC, s.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function saveForUser(int $gameId, int $userId, string $configKey, int $slot, array $saveData, array $metadata = []): array
    {
        self::ensureTables();
        $config = self::activeConfigByKey($gameId, $configKey);
        if (!$config) {
            throw new RuntimeException('Configuracion de cloud save no encontrada.');
        }

        if ($slot < 1 || $slot > (int) $config['max_slots']) {
            throw new RuntimeException('Slot fuera del rango permitido.');
        }

        $saveJson = (string) json_encode($saveData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sizeBytes = strlen($saveJson);
        if ($sizeBytes > (int) $config['max_bytes']) {
            throw new RuntimeException('La partida supera el tamano maximo configurado.');
        }

        $metadataJson = $metadata !== []
            ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_cloud_saves
                (game_id, user_id, config_id, slot, save_json, size_bytes, metadata_json, created_at, updated_at)
             VALUES
                (:game_id, :user_id, :config_id, :slot, :save_json, :size_bytes, :metadata_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                save_json = VALUES(save_json),
                size_bytes = VALUES(size_bytes),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
            'config_id' => (int) $config['id'],
            'slot' => $slot,
            'save_json' => $saveJson,
            'size_bytes' => $sizeBytes,
            'metadata_json' => $metadataJson,
        ]);

        return self::loadForUser($gameId, $userId, $configKey, $slot);
    }

    public static function loadForUser(int $gameId, int $userId, string $configKey, int $slot): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, c.config_key, c.name AS config_name, g.name AS game_name, g.slug AS game_slug
             FROM user_cloud_saves s
             INNER JOIN game_cloud_save_configs c ON c.id = s.config_id
             INNER JOIN games g ON g.id = s.game_id
             WHERE s.game_id = :game_id
               AND s.user_id = :user_id
               AND c.config_key = :config_key
               AND s.slot = :slot
             LIMIT 1'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
            'config_key' => self::normalizeKey($configKey),
            'slot' => $slot,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return [
                'found' => false,
                'config_key' => self::normalizeKey($configKey),
                'slot' => $slot,
                'save' => null,
            ];
        }

        return self::savePayload($row);
    }

    private static function validatedConfig(array $input): array
    {
        $id = (int) ($input['cloud_config_id'] ?? 0);
        $gameId = (int) ($input['game_id'] ?? 0);
        $configKey = self::normalizeKey((string) ($input['config_key'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $maxSlots = (int) ($input['max_slots'] ?? 3);
        $maxBytes = (int) ($input['max_bytes'] ?? 65536);
        $autoSync = isset($input['auto_sync']) ? 1 : 0;
        $status = (string) ($input['status'] ?? 'active');
        $metadataJson = self::cleanJson((string) ($input['metadata_json'] ?? ''));

        if ($gameId <= 0 || !self::gameExists($gameId)) {
            throw new RuntimeException('El juego asociado no existe.');
        }

        if ($name === '' || strlen($name) > 160) {
            throw new RuntimeException('El nombre cloud debe tener entre 1 y 160 caracteres.');
        }

        if ($maxSlots < 1 || $maxSlots > 20) {
            throw new RuntimeException('Los slots deben estar entre 1 y 20.');
        }

        if ($maxBytes < 1024 || $maxBytes > 5 * 1024 * 1024) {
            throw new RuntimeException('El tamano maximo debe estar entre 1 KB y 5 MB.');
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Estado cloud invalido.');
        }

        return [
            'id' => $id,
            'game_id' => $gameId,
            'config_key' => $configKey,
            'name' => $name,
            'max_slots' => $maxSlots,
            'max_bytes' => $maxBytes,
            'auto_sync' => $autoSync,
            'status' => $status,
            'metadata_json' => $metadataJson,
        ];
    }

    private static function activeConfigByKey(int $gameId, string $configKey): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM game_cloud_save_configs
             WHERE game_id = :game_id AND config_key = :config_key AND status = "active"
             LIMIT 1'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'config_key' => self::normalizeKey($configKey),
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private static function configPayload(array $row): array
    {
        return [
            'config_key' => (string) $row['config_key'],
            'name' => (string) $row['name'],
            'max_slots' => (int) $row['max_slots'],
            'max_bytes' => (int) $row['max_bytes'],
            'auto_sync' => (bool) $row['auto_sync'],
            'metadata' => Game::decodeJson($row['metadata_json'] ?? null),
        ];
    }

    private static function savePayload(array $row): array
    {
        $decoded = json_decode((string) $row['save_json'], true);

        return [
            'found' => true,
            'id' => (int) $row['id'],
            'game' => [
                'id' => (int) $row['game_id'],
                'name' => (string) ($row['game_name'] ?? ''),
                'slug' => (string) ($row['game_slug'] ?? ''),
            ],
            'config_key' => (string) $row['config_key'],
            'config_name' => (string) $row['config_name'],
            'slot' => (int) $row['slot'],
            'size_bytes' => (int) $row['size_bytes'],
            'save' => is_array($decoded) ? $decoded : null,
            'metadata' => Game::decodeJson($row['metadata_json'] ?? null),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9_.:-]{2,100}$/', $key)) {
            throw new RuntimeException('Cloud key invalida.');
        }

        return $key;
    }

    private static function cleanJson(string $json): ?string
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('El JSON de metadata cloud no es valido.');
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function gameExists(int $gameId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
