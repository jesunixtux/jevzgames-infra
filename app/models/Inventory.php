<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Inventory
{
    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS game_inventory_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NULL,
                item_key VARCHAR(120) NOT NULL,
                name VARCHAR(160) NOT NULL,
                description TEXT NULL,
                item_type VARCHAR(80) NOT NULL DEFAULT "item",
                image_path VARCHAR(255) NULL,
                metadata_json LONGTEXT NULL,
                status ENUM("active", "disabled") NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_game_inventory_items_scope (game_id, item_key),
                INDEX idx_game_inventory_items_game (game_id),
                INDEX idx_game_inventory_items_status (status),
                CONSTRAINT fk_game_inventory_items_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_inventory (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                game_id INT UNSIGNED NULL,
                item_id BIGINT UNSIGNED NULL,
                item_key VARCHAR(120) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                source VARCHAR(80) NULL,
                metadata_json LONGTEXT NULL,
                acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_inventory_scope (user_id, game_id, item_key),
                INDEX idx_user_inventory_user (user_id),
                INDEX idx_user_inventory_game (game_id),
                CONSTRAINT fk_user_inventory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_inventory_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_inventory_item FOREIGN KEY (item_id) REFERENCES game_inventory_items(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function listForUser(int $userId, ?int $gameId = null): array
    {
        if (!Database::pdo()->inTransaction()) {
            self::ensureTables();
        }
        $params = ['user_id' => $userId];
        $where = 'WHERE ui.user_id = :user_id';
        if ($gameId !== null && $gameId > 0) {
            $where .= ' AND ui.game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT ui.*, i.name, i.description, i.item_type, i.image_path, i.status AS item_status,
                    g.name AS game_name, g.slug AS game_slug
             FROM user_inventory ui
             LEFT JOIN game_inventory_items i ON i.id = ui.item_id
             LEFT JOIN games g ON g.id = ui.game_id
             ' . $where . '
             ORDER BY ui.updated_at DESC, ui.acquired_at DESC, ui.id DESC'
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => self::inventoryPayload($row), $stmt->fetchAll());
    }

    public static function grantItem(int $userId, ?int $gameId, string $itemKey, int $quantity = 1, array $metadata = [], string $source = 'manual', ?string $name = null, string $itemType = 'item', ?string $imagePath = null): array
    {
        if (!Database::pdo()->inTransaction()) {
            self::ensureTables();
        }
        $itemKey = self::normalizeItemKey($itemKey);
        $quantity = max(1, min(1000000, $quantity));
        $item = self::ensureItem($gameId, $itemKey, $name ?? $itemKey, $itemType, $metadata, $imagePath);
        $metadataJson = $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        if ($gameId === null) {
            $existing = Database::pdo()->prepare(
                'SELECT id FROM user_inventory WHERE user_id = :user_id AND game_id IS NULL AND item_key = :item_key LIMIT 1'
            );
            $existing->execute([
                'user_id' => $userId,
                'item_key' => $itemKey,
            ]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                $stmt = Database::pdo()->prepare(
                    'UPDATE user_inventory
                     SET quantity = quantity + :quantity,
                         item_id = :item_id,
                         source = :source,
                         metadata_json = COALESCE(:metadata_json, metadata_json),
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => (int) $existingId,
                    'quantity' => $quantity,
                    'item_id' => (int) $item['id'],
                    'source' => $source,
                    'metadata_json' => $metadataJson,
                ]);
            } else {
                $stmt = Database::pdo()->prepare(
                    'INSERT INTO user_inventory (user_id, game_id, item_id, item_key, quantity, source, metadata_json, acquired_at, updated_at)
                     VALUES (:user_id, NULL, :item_id, :item_key, :quantity, :source, :metadata_json, NOW(), NOW())'
                );
                $stmt->execute([
                    'user_id' => $userId,
                    'item_id' => (int) $item['id'],
                    'item_key' => $itemKey,
                    'quantity' => $quantity,
                    'source' => $source,
                    'metadata_json' => $metadataJson,
                ]);
            }
        } else {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO user_inventory (user_id, game_id, item_id, item_key, quantity, source, metadata_json, acquired_at, updated_at)
                 VALUES (:user_id, :game_id, :item_id, :item_key, :quantity, :source, :metadata_json, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                    item_id = VALUES(item_id),
                    source = VALUES(source),
                    metadata_json = COALESCE(VALUES(metadata_json), metadata_json),
                    updated_at = NOW()'
            );
            $stmt->execute([
                'user_id' => $userId,
                'game_id' => $gameId,
                'item_id' => (int) $item['id'],
                'item_key' => $itemKey,
                'quantity' => $quantity,
                'source' => $source,
                'metadata_json' => $metadataJson,
            ]);
        }

        $items = self::listForUser($userId, $gameId);
        foreach ($items as $inventoryItem) {
            if ($inventoryItem['item_key'] === $itemKey) {
                return $inventoryItem;
            }
        }

        return [];
    }

    public static function redeemCode(int $userId, string $code): array
    {
        self::ensureTables();
        Game::ensureLicenseTables();
        $code = self::normalizeCode($code);
        if ($code === '') {
            throw new RuntimeException('Codigo requerido.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT *
                 FROM redeemable_codes
                 WHERE code_hash = :code_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['code_hash' => self::codeHash($code)]);
            $redeemable = $stmt->fetch();

            if (!is_array($redeemable)) {
                throw new RuntimeException('Codigo invalido.');
            }

            if ($redeemable['status'] !== 'active') {
                throw new RuntimeException('Este codigo no esta activo.');
            }

            if (!empty($redeemable['expires_at']) && strtotime((string) $redeemable['expires_at']) < time()) {
                throw new RuntimeException('Este codigo expiro.');
            }

            if ((int) $redeemable['current_uses'] >= (int) $redeemable['max_uses']) {
                throw new RuntimeException('Este codigo ya no tiene usos disponibles.');
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM code_redemptions WHERE code_id = :code_id AND user_id = :user_id');
            $stmt->execute([
                'code_id' => (int) $redeemable['id'],
                'user_id' => $userId,
            ]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya canjeaste este codigo.');
            }

            $reward = Game::decodeJson($redeemable['reward_json'] ?? null);
            $granted = self::grantReward($userId, $redeemable, $reward);
            $snapshot = [
                'reward_type' => (string) $redeemable['reward_type'],
                'reward' => $reward,
                'granted' => $granted,
            ];

            $stmt = $pdo->prepare(
                'INSERT INTO code_redemptions (code_id, user_id, game_id, redeemed_at, reward_snapshot_json)
                 VALUES (:code_id, :user_id, :game_id, NOW(), :reward_snapshot_json)'
            );
            $stmt->execute([
                'code_id' => (int) $redeemable['id'],
                'user_id' => $userId,
                'game_id' => $redeemable['game_id'] !== null ? (int) $redeemable['game_id'] : null,
                'reward_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $stmt = $pdo->prepare('UPDATE redeemable_codes SET current_uses = current_uses + 1 WHERE id = :id');
            $stmt->execute(['id' => (int) $redeemable['id']]);
            $pdo->commit();

            return [
                'code_preview' => (string) $redeemable['code_preview'],
                'game_id' => $redeemable['game_id'] !== null ? (int) $redeemable['game_id'] : null,
                'reward_type' => (string) $redeemable['reward_type'],
                'granted' => $granted,
            ];
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private static function grantReward(int $userId, array $redeemable, array $reward): array
    {
        $gameId = $redeemable['game_id'] !== null ? (int) $redeemable['game_id'] : null;
        $rewardType = (string) $redeemable['reward_type'];
        $items = [];
        $licenses = [];

        foreach (self::rewardGameIds($gameId, $rewardType, $reward) as $licenseGameId) {
            $licenses[] = Game::grantLicense($userId, $licenseGameId, 'code');
        }

        if ($licenses !== [] && !self::rewardContainsItems($reward)) {
            return [
                'items' => [],
                'licenses' => $licenses,
            ];
        }

        if (isset($reward['items']) && is_array($reward['items'])) {
            foreach ($reward['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'item_key' => (string) ($item['item_key'] ?? $item['item'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'name' => isset($item['name']) ? (string) $item['name'] : null,
                    'type' => isset($item['type']) ? (string) $item['type'] : $rewardType,
                    'image_path' => isset($item['image_path']) || isset($item['image']) ? (string) ($item['image_path'] ?? $item['image']) : null,
                    'metadata' => isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [],
                ];
            }
        } elseif (isset($reward['item']) || isset($reward['item_key'])) {
            $items[] = [
                'item_key' => (string) ($reward['item_key'] ?? $reward['item']),
                'quantity' => (int) ($reward['quantity'] ?? 1),
                'name' => isset($reward['name']) ? (string) $reward['name'] : null,
                'type' => isset($reward['type']) ? (string) $reward['type'] : $rewardType,
                'image_path' => isset($reward['image_path']) || isset($reward['image']) ? (string) ($reward['image_path'] ?? $reward['image']) : null,
                'metadata' => isset($reward['metadata']) && is_array($reward['metadata']) ? $reward['metadata'] : [],
            ];
        } else {
            $items[] = [
                'item_key' => $rewardType,
                'quantity' => (int) ($reward['quantity'] ?? 1),
                'name' => ucfirst(str_replace(['_', '-'], ' ', $rewardType)),
                'type' => $rewardType,
                'image_path' => isset($reward['image_path']) || isset($reward['image']) ? (string) ($reward['image_path'] ?? $reward['image']) : null,
                'metadata' => $reward,
            ];
        }

        $grantedItems = [];
        foreach ($items as $item) {
            if ($item['item_key'] === '') {
                continue;
            }
            $grantedItems[] = self::grantItem(
                $userId,
                $gameId,
                $item['item_key'],
                max(1, (int) $item['quantity']),
                $item['metadata'],
                'code',
                $item['name'],
                $item['type'],
                $item['image_path']
            );
        }

        return [
            'items' => $grantedItems,
            'licenses' => $licenses,
        ];
    }

    private static function ensureItem(?int $gameId, string $itemKey, string $name, string $itemType, array $metadata, ?string $imagePath): array
    {
        $existing = Database::pdo()->prepare(
            'SELECT *
             FROM game_inventory_items
             WHERE item_key = :item_key
               AND ((game_id IS NULL AND :game_id_is_null = 1) OR game_id = :game_id)
             LIMIT 1'
        );
        $existing->execute([
            'item_key' => $itemKey,
            'game_id' => $gameId,
            'game_id_is_null' => $gameId === null ? 1 : 0,
        ]);
        $item = $existing->fetch();
        if (is_array($item)) {
            $cleanImagePath = self::cleanAssetPath((string) ($imagePath ?? ''));
            if ($cleanImagePath !== null && $cleanImagePath !== ($item['image_path'] ?? null)) {
                $stmt = Database::pdo()->prepare(
                    'UPDATE game_inventory_items SET image_path = :image_path, updated_at = NOW() WHERE id = :id'
                );
                $stmt->execute([
                    'id' => (int) $item['id'],
                    'image_path' => $cleanImagePath,
                ]);
                $item['image_path'] = $cleanImagePath;
            }
            return $item;
        }

        $metadataJson = $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $cleanImagePath = self::cleanAssetPath((string) ($imagePath ?? ''));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_inventory_items (game_id, item_key, name, item_type, image_path, metadata_json, status, created_at, updated_at)
             VALUES (:game_id, :item_key, :name, :item_type, :image_path, :metadata_json, "active", NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                item_type = VALUES(item_type),
                image_path = COALESCE(VALUES(image_path), image_path),
                metadata_json = COALESCE(VALUES(metadata_json), metadata_json),
                updated_at = NOW()'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'item_key' => $itemKey,
            'name' => trim($name) !== '' ? trim($name) : $itemKey,
            'item_type' => self::normalizeType($itemType),
            'image_path' => $cleanImagePath,
            'metadata_json' => $metadataJson,
        ]);

        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM game_inventory_items
             WHERE item_key = :item_key
               AND ((game_id IS NULL AND :game_id_is_null = 1) OR game_id = :game_id)
             LIMIT 1'
        );
        $stmt->execute([
            'item_key' => $itemKey,
            'game_id' => $gameId,
            'game_id_is_null' => $gameId === null ? 1 : 0,
        ]);
        $item = $stmt->fetch();

        if (!is_array($item)) {
            throw new RuntimeException('No se pudo preparar el item de inventario.');
        }

        return $item;
    }

    private static function inventoryPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'game' => $row['game_id'] !== null ? [
                'id' => (int) $row['game_id'],
                'name' => (string) ($row['game_name'] ?? ''),
                'slug' => (string) ($row['game_slug'] ?? ''),
            ] : null,
            'item_key' => (string) $row['item_key'],
            'name' => (string) ($row['name'] ?? $row['item_key']),
            'description' => $row['description'] ?? null,
            'item_type' => (string) ($row['item_type'] ?? 'item'),
            'image_path' => $row['image_path'] ?? null,
            'quantity' => (int) $row['quantity'],
            'source' => $row['source'] ?? null,
            'metadata' => Game::decodeJson($row['metadata_json'] ?? null),
            'acquired_at' => $row['acquired_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function normalizeItemKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9_.:-]{2,120}$/', $key)) {
            throw new RuntimeException('Item key invalida.');
        }

        return $key;
    }

    private static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return preg_match('/^[a-z0-9_.:-]{2,80}$/', $type) ? $type : 'item';
    }

    private static function rewardContainsItems(array $reward): bool
    {
        return isset($reward['items']) || isset($reward['item']) || isset($reward['item_key']);
    }

    private static function rewardGameIds(?int $defaultGameId, string $rewardType, array $reward): array
    {
        $ids = [];
        $isGameReward = in_array($rewardType, ['game', 'game_license', 'license'], true);

        if (isset($reward['games']) && is_array($reward['games'])) {
            foreach ($reward['games'] as $game) {
                $id = self::gameIdFromReward($game);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        $singleId = self::gameIdFromReward($reward);
        if ($singleId !== null) {
            $ids[] = $singleId;
        }

        if ($ids === [] && $isGameReward && $defaultGameId !== null) {
            $ids[] = $defaultGameId;
        }

        if ($ids === [] && $isGameReward && !self::rewardContainsItems($reward)) {
            throw new RuntimeException('El codigo de juego debe indicar game_id, game_slug o estar asociado a un juego.');
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private static function gameIdFromReward(mixed $value): ?int
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['game_id']) && (int) $value['game_id'] > 0) {
            return (int) $value['game_id'];
        }

        $slug = (string) ($value['game_slug'] ?? $value['slug'] ?? $value['game'] ?? '');
        return $slug !== '' ? Game::gameIdBySlug($slug) : null;
    }

    private static function cleanAssetPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return strlen($path) <= 255 ? $path : throw new RuntimeException('La URL de imagen del item es demasiado larga.');
        }

        if (!preg_match('#^/?[A-Za-z0-9._/\-]+$#', $path) || strlen($path) > 255) {
            throw new RuntimeException('La ruta de imagen del item no es valida.');
        }

        return $path;
    }

    private static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private static function codeHash(string $code): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', self::normalizeCode($code), $pepper);
    }
}
