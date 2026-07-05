<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class GameGroup
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_groups (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_user_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL UNIQUE,
                description TEXT NULL,
                visibility ENUM("public", "private") NOT NULL DEFAULT "public",
                status ENUM("active", "archived") NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_groups_owner (owner_user_id),
                INDEX idx_user_groups_visibility (visibility),
                CONSTRAINT fk_user_groups_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_group_members (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                group_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                role ENUM("owner", "member") NOT NULL DEFAULT "member",
                status ENUM("active", "left", "banned") NOT NULL DEFAULT "active",
                joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_group_members_scope (group_id, user_id),
                INDEX idx_user_group_members_user (user_id),
                CONSTRAINT fk_user_group_members_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_group_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function create(int $ownerUserId, array $input): int
    {
        self::ensureTables();
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? 'public');
        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('El nombre del grupo debe tener entre 1 y 120 caracteres.');
        }
        if (!in_array($visibility, ['public', 'private'], true)) {
            $visibility = 'public';
        }

        $slug = self::uniqueSlug($name);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO user_groups (owner_user_id, name, slug, description, visibility, status, created_at, updated_at)
                 VALUES (:owner_user_id, :name, :slug, :description, :visibility, "active", NOW(), NOW())'
            );
            $stmt->execute([
                'owner_user_id' => $ownerUserId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'visibility' => $visibility,
            ]);
            $groupId = (int) $pdo->lastInsertId();
            $memberStmt = $pdo->prepare(
                'INSERT INTO user_group_members (group_id, user_id, role, status, joined_at)
                 VALUES (:group_id, :user_id, "owner", "active", NOW())'
            );
            $memberStmt->execute([
                'group_id' => $groupId,
                'user_id' => $ownerUserId,
            ]);
            $pdo->commit();
            return $groupId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function join(int $groupId, int $userId, string $role = 'member'): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_group_members (group_id, user_id, role, status, joined_at)
             VALUES (:group_id, :user_id, :role, "active", NOW())
             ON DUPLICATE KEY UPDATE status = "active", role = IF(role = "owner", role, VALUES(role))'
        );
        $stmt->execute([
            'group_id' => $groupId,
            'user_id' => $userId,
            'role' => $role === 'owner' ? 'owner' : 'member',
        ]);
    }

    public static function leave(int $groupId, int $userId): void
    {
        self::ensureTables();
        Database::pdo()->prepare(
            'UPDATE user_group_members
             SET status = "left"
             WHERE group_id = :group_id AND user_id = :user_id AND role <> "owner"'
        )->execute([
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    public static function listForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT g.*, m.role, m.status AS member_status, counts.member_count
             FROM user_groups g
             INNER JOIN user_group_members m ON m.group_id = g.id AND m.user_id = :user_id AND m.status = "active"
             LEFT JOIN (
                SELECT group_id, COUNT(*) AS member_count
                FROM user_group_members
                WHERE status = "active"
                GROUP BY group_id
             ) counts ON counts.group_id = g.id
             WHERE g.status = "active"
             ORDER BY g.name ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function publicGroups(): array
    {
        self::ensureTables();
        return Database::pdo()->query(
            'SELECT g.*, counts.member_count
             FROM user_groups g
             LEFT JOIN (
                SELECT group_id, COUNT(*) AS member_count
                FROM user_group_members
                WHERE status = "active"
                GROUP BY group_id
             ) counts ON counts.group_id = g.id
             WHERE g.status = "active" AND g.visibility = "public"
             ORDER BY g.created_at DESC
             LIMIT 100'
        )->fetchAll();
    }

    private static function uniqueSlug(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'group', '-')) ?: 'group';
        for ($i = 0; $i < 50; $i++) {
            $slug = $i === 0 ? $base : $base . '-' . bin2hex(random_bytes(2));
            $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM user_groups WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
        }

        throw new RuntimeException('No se pudo generar slug de grupo.');
    }
}
