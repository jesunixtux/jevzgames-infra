<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Workshop
{
    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS game_workshop_configs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL UNIQUE,
                status ENUM("enabled", "disabled") NOT NULL DEFAULT "disabled",
                allow_user_uploads TINYINT(1) NOT NULL DEFAULT 0,
                moderation_mode ENUM("pre", "post") NOT NULL DEFAULT "pre",
                max_file_bytes INT UNSIGNED NOT NULL DEFAULT 10485760,
                allowed_types_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_game_workshop_configs_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workshop_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(160) NOT NULL,
                slug VARCHAR(180) NOT NULL,
                description TEXT NULL,
                file_url VARCHAR(255) NULL,
                image_url VARCHAR(255) NULL,
                metadata_json LONGTEXT NULL,
                status ENUM("pending", "published", "rejected", "hidden") NOT NULL DEFAULT "pending",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_workshop_items_game_slug (game_id, slug),
                INDEX idx_workshop_items_game_status (game_id, status),
                INDEX idx_workshop_items_user (user_id),
                CONSTRAINT fk_workshop_items_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_workshop_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function configs(): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->query(
            'SELECT g.id AS game_id, g.name AS game_name, g.slug AS game_slug,
                    c.status, c.allow_user_uploads, c.moderation_mode, c.max_file_bytes, c.allowed_types_json
             FROM games g
             LEFT JOIN game_workshop_configs c ON c.game_id = g.id
             ORDER BY g.name ASC'
        );

        return $stmt->fetchAll();
    }

    public static function saveConfig(array $input): void
    {
        self::ensureTables();
        $gameId = (int) ($input['game_id'] ?? 0);
        $status = (string) ($input['status'] ?? 'disabled');
        $allowUserUploads = isset($input['allow_user_uploads']) ? 1 : 0;
        $moderationMode = (string) ($input['moderation_mode'] ?? 'pre');
        $maxFileBytes = (int) ($input['max_file_bytes'] ?? 10485760);
        $allowedTypesJson = trim((string) ($input['allowed_types_json'] ?? ''));

        if ($gameId <= 0) {
            throw new RuntimeException('Juego invalido.');
        }
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new RuntimeException('Estado workshop invalido.');
        }
        if (!in_array($moderationMode, ['pre', 'post'], true)) {
            throw new RuntimeException('Modo de moderacion invalido.');
        }
        if ($maxFileBytes < 1024 || $maxFileBytes > 200 * 1024 * 1024) {
            throw new RuntimeException('El tamano maximo debe estar entre 1 KB y 200 MB.');
        }
        if ($allowedTypesJson !== '') {
            $decoded = json_decode($allowedTypesJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new RuntimeException('Tipos permitidos debe ser JSON valido.');
            }
            $allowedTypesJson = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $allowedTypesJson = null;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_workshop_configs
                (game_id, status, allow_user_uploads, moderation_mode, max_file_bytes, allowed_types_json, created_at, updated_at)
             VALUES
                (:game_id, :status, :allow_user_uploads, :moderation_mode, :max_file_bytes, :allowed_types_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                allow_user_uploads = VALUES(allow_user_uploads),
                moderation_mode = VALUES(moderation_mode),
                max_file_bytes = VALUES(max_file_bytes),
                allowed_types_json = VALUES(allowed_types_json),
                updated_at = NOW()'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'status' => $status,
            'allow_user_uploads' => $allowUserUploads,
            'moderation_mode' => $moderationMode,
            'max_file_bytes' => $maxFileBytes,
            'allowed_types_json' => $allowedTypesJson,
        ]);
    }

    public static function enabledGames(): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->query(
            'SELECT c.*, g.name AS game_name, g.slug AS game_slug
             FROM game_workshop_configs c
             INNER JOIN games g ON g.id = c.game_id
             WHERE c.status = "enabled"
               AND g.status IN ("development", "playtest", "beta", "published")
             ORDER BY g.name ASC'
        );

        return $stmt->fetchAll();
    }

    public static function items(?int $gameId = null, bool $includePending = false): array
    {
        self::ensureTables();
        $params = [];
        $where = $includePending ? 'WHERE wi.status IN ("pending", "published")' : 'WHERE wi.status = "published"';
        if ($gameId !== null && $gameId > 0) {
            $where .= ' AND wi.game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT wi.*, g.name AS game_name, g.slug AS game_slug, u.username
             FROM workshop_items wi
             INNER JOIN games g ON g.id = wi.game_id
             INNER JOIN users u ON u.id = wi.user_id
             ' . $where . '
             ORDER BY wi.created_at DESC, wi.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function submitItem(int $userId, array $input): int
    {
        self::ensureTables();
        $gameId = (int) ($input['game_id'] ?? 0);
        $config = self::configForGame($gameId);
        if (!$config || $config['status'] !== 'enabled' || (int) $config['allow_user_uploads'] !== 1) {
            throw new RuntimeException('Este juego no acepta publicaciones de workshop.');
        }

        $data = self::validateItem($input);
        $status = $config['moderation_mode'] === 'post' ? 'published' : 'pending';
        $stmt = Database::pdo()->prepare(
            'INSERT INTO workshop_items
                (game_id, user_id, title, slug, description, file_url, image_url, metadata_json, status, created_at, updated_at)
             VALUES
                (:game_id, :user_id, :title, :slug, :description, :file_url, :image_url, :metadata_json, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
            'status' => $status,
            ...$data,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function updateItemStatus(int $itemId, string $status): void
    {
        self::ensureTables();
        if (!in_array($status, ['pending', 'published', 'rejected', 'hidden'], true)) {
            throw new RuntimeException('Estado workshop invalido.');
        }
        $stmt = Database::pdo()->prepare('UPDATE workshop_items SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $itemId, 'status' => $status]);
    }

    private static function configForGame(int $gameId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM game_workshop_configs WHERE game_id = :game_id LIMIT 1');
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private static function validateItem(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $description = trim((string) ($input['description'] ?? ''));
        $fileUrl = trim((string) ($input['file_url'] ?? ''));
        $imageUrl = trim((string) ($input['image_url'] ?? ''));
        $metadataJson = trim((string) ($input['metadata_json'] ?? ''));

        if ($title === '' || strlen($title) > 160) {
            throw new RuntimeException('El titulo debe tener entre 1 y 160 caracteres.');
        }
        if (!preg_match('/^[a-z0-9-]{2,180}$/', $slug)) {
            throw new RuntimeException('El slug debe usar minusculas, numeros y guiones.');
        }
        foreach (['file_url' => $fileUrl, 'image_url' => $imageUrl] as $label => $url) {
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException($label . ' no es una URL valida.');
            }
        }
        if ($metadataJson !== '') {
            $decoded = json_decode($metadataJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new RuntimeException('Metadata debe ser JSON valido.');
            }
            $metadataJson = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $metadataJson = null;
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'file_url' => $fileUrl !== '' ? $fileUrl : null,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'metadata_json' => $metadataJson,
        ];
    }
}
