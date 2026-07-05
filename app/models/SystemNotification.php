<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SystemNotification
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS system_notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(100) NOT NULL,
                title VARCHAR(180) NOT NULL,
                body TEXT NULL,
                target_url VARCHAR(500) NULL,
                actor_user_id INT UNSIGNED NULL,
                data_json LONGTEXT NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_system_notifications_user_read (user_id, read_at),
                INDEX idx_system_notifications_type (type),
                CONSTRAINT fk_system_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_system_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function create(int $userId, string $type, string $title, string $body = '', string $targetUrl = '', ?int $actorUserId = null, array $data = []): int
    {
        self::ensureTables();
        if ($userId <= 0) {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO system_notifications
                (user_id, type, title, body, target_url, actor_user_id, data_json, created_at)
             VALUES
                (:user_id, :type, :title, :body, :target_url, :actor_user_id, :data_json, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type' => substr(trim($type), 0, 100) ?: 'system',
            'title' => substr(trim($title), 0, 180) ?: 'Notification',
            'body' => trim($body) !== '' ? trim($body) : null,
            'target_url' => trim($targetUrl) !== '' ? substr(trim($targetUrl), 0, 500) : null,
            'actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            'data_json' => $data !== [] ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function unreadCount(int $userId): int
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM system_notifications WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public static function listForUser(int $userId, int $limit = 100): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT n.*, u.username AS actor_username, COALESCE(p.display_name, u.display_name, u.username) AS actor_display_name
             FROM system_notifications n
             LEFT JOIN users u ON u.id = n.actor_user_id
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE n.user_id = :user_id
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            return [
                'id' => 'sys_' . (int) $row['id'],
                'type' => (string) $row['type'],
                'title' => (string) $row['title'],
                'body' => (string) ($row['body'] ?? ''),
                'target_url' => (string) ($row['target_url'] ?? ''),
                'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
                'actor_username' => $row['actor_username'] ?? null,
                'actor_display_name' => $row['actor_display_name'] ?? null,
                'created_at' => $row['created_at'],
                'read_at' => $row['read_at'] ?? null,
                'data' => Game::decodeJson($row['data_json'] ?? null),
            ];
        }, $stmt->fetchAll());
    }

    public static function markRead(int $userId, int $notificationId): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'UPDATE system_notifications
             SET read_at = COALESCE(read_at, NOW())
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'id' => $notificationId,
            'user_id' => $userId,
        ]);
    }

    public static function markAllRead(int $userId): void
    {
        self::ensureTables();
        Database::pdo()->prepare(
            'UPDATE system_notifications
             SET read_at = COALESCE(read_at, NOW())
             WHERE user_id = :user_id AND read_at IS NULL'
        )->execute(['user_id' => $userId]);
    }
}
