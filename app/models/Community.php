<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Community
{
    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_posts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                body TEXT NOT NULL,
                status ENUM("active", "hidden", "deleted") NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_community_posts_user (user_id),
                INDEX idx_community_posts_status_created (status, created_at),
                CONSTRAINT fk_community_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_comments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                status ENUM("active", "hidden", "deleted") NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_community_comments_post (post_id, status, created_at),
                INDEX idx_community_comments_user (user_id),
                CONSTRAINT fk_community_comments_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function createPost(int $userId, array $input): int
    {
        self::ensureTables();
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if ($userId <= 0) {
            throw new RuntimeException('Usuario invalido.');
        }

        if ($title === '' || strlen($title) > 180) {
            throw new RuntimeException('El titulo debe tener entre 1 y 180 caracteres.');
        }

        if ($body === '' || strlen($body) > 8000) {
            throw new RuntimeException('La publicacion debe tener entre 1 y 8000 caracteres.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO community_posts (user_id, title, body, status, created_at, updated_at)
             VALUES (:user_id, :title, :body, "active", NOW(), NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function listPosts(int $limit = 40): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, u.username, COALESCE(pp.display_name, u.display_name, u.username) AS display_name,
                    pp.avatar_path,
                    stats.comment_count,
                    stats.latest_comment_at
             FROM community_posts p
             INNER JOIN users u ON u.id = p.user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             LEFT JOIN (
                SELECT post_id, COUNT(*) AS comment_count, MAX(created_at) AS latest_comment_at
                FROM community_comments
                WHERE status = "active"
                GROUP BY post_id
             ) stats ON stats.post_id = p.id
             WHERE p.status = "active"
             ORDER BY COALESCE(stats.latest_comment_at, p.created_at) DESC, p.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function findPost(int $postId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, u.username, COALESCE(pp.display_name, u.display_name, u.username) AS display_name,
                    pp.avatar_path
             FROM community_posts p
             INNER JOIN users u ON u.id = p.user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE p.id = :id AND p.status = "active"
             LIMIT 1'
        );
        $stmt->execute(['id' => $postId]);
        $post = $stmt->fetch();

        return is_array($post) ? $post : null;
    }

    public static function comments(int $postId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, u.username, COALESCE(pp.display_name, u.display_name, u.username) AS display_name,
                    pp.avatar_path
             FROM community_comments c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE c.post_id = :post_id
               AND c.status = "active"
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute(['post_id' => $postId]);

        return $stmt->fetchAll();
    }

    public static function addComment(int $postId, int $userId, string $body): int
    {
        self::ensureTables();
        $post = self::findPost($postId);
        if (!$post) {
            throw new RuntimeException('Publicacion no encontrada.');
        }

        if (SocialSettings::isBlockedBetween((int) $post['user_id'], $userId)) {
            throw new RuntimeException('No puedes comentar esta publicacion.');
        }

        $body = trim($body);
        if ($body === '' || strlen($body) > 3000) {
            throw new RuntimeException('El comentario debe tener entre 1 y 3000 caracteres.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO community_comments (post_id, user_id, body, status, created_at, updated_at)
             VALUES (:post_id, :user_id, :body, "active", NOW(), NOW())'
        );
        $stmt->execute([
            'post_id' => $postId,
            'user_id' => $userId,
            'body' => $body,
        ]);

        $commentId = (int) Database::pdo()->lastInsertId();
        Notification::create(
            (int) $post['user_id'],
            'community.comment',
            'Nuevo comentario',
            'Alguien comento tu publicacion: ' . (string) $post['title'],
            '/community/?post=' . $postId . '#comments',
            $userId,
            ['post_id' => $postId, 'comment_id' => $commentId]
        );

        return $commentId;
    }
}
