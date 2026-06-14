<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Friend
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_friends (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                requester_user_id INT UNSIGNED NOT NULL,
                addressee_user_id INT UNSIGNED NOT NULL,
                status ENUM("pending", "accepted", "rejected", "blocked") NOT NULL DEFAULT "pending",
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at DATETIME NULL,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_friends_pair (requester_user_id, addressee_user_id),
                INDEX idx_user_friends_requester (requester_user_id, status),
                INDEX idx_user_friends_addressee (addressee_user_id, status),
                CONSTRAINT fk_user_friends_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_friends_addressee FOREIGN KEY (addressee_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function relationship(int $viewerId, int $targetId): ?array
    {
        self::ensureTables();
        if ($viewerId <= 0 || $targetId <= 0 || $viewerId === $targetId) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM user_friends
             WHERE (requester_user_id = :viewer_a AND addressee_user_id = :target_a)
                OR (requester_user_id = :target_b AND addressee_user_id = :viewer_b)
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'viewer_a' => $viewerId,
            'target_a' => $targetId,
            'target_b' => $targetId,
            'viewer_b' => $viewerId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public static function request(int $viewerId, int $targetId): void
    {
        self::ensureTables();
        if ($viewerId === $targetId) {
            throw new RuntimeException('No puedes enviarte solicitud a ti mismo.');
        }

        if (!SocialSettings::canReceiveFriendRequest($targetId, $viewerId)) {
            throw new RuntimeException('Este usuario no recibe solicitudes tuyas.');
        }

        $existing = self::relationship($viewerId, $targetId);
        if ($existing && $existing['status'] === 'accepted') {
            throw new RuntimeException('Ya son amigos.');
        }

        if ($existing && $existing['status'] === 'pending') {
            throw new RuntimeException('Ya hay una solicitud pendiente.');
        }

        Database::pdo()->prepare(
            'DELETE FROM user_friends
             WHERE (requester_user_id = :viewer_a AND addressee_user_id = :target_a)
                OR (requester_user_id = :target_b AND addressee_user_id = :viewer_b)'
        )->execute([
            'viewer_a' => $viewerId,
            'target_a' => $targetId,
            'target_b' => $targetId,
            'viewer_b' => $viewerId,
        ]);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_friends (requester_user_id, addressee_user_id, status, requested_at)
             VALUES (:requester, :addressee, "pending", NOW())'
        );
        $stmt->execute([
            'requester' => $viewerId,
            'addressee' => $targetId,
        ]);

        Notification::create(
            $targetId,
            'friend.request',
            'Solicitud de amistad',
            'Tienes una nueva solicitud de amistad.',
            '/profile/',
            $viewerId,
            ['requester_user_id' => $viewerId]
        );
    }

    public static function accept(int $viewerId, int $requestId): void
    {
        self::ensureTables();
        $request = self::requestById($requestId);
        $stmt = Database::pdo()->prepare(
            'UPDATE user_friends
             SET status = "accepted", responded_at = NOW(), updated_at = NOW()
             WHERE id = :id AND addressee_user_id = :viewer AND status = "pending"'
        );
        $stmt->execute([
            'id' => $requestId,
            'viewer' => $viewerId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Solicitud no encontrada.');
        }

        if ($request && (int) $request['addressee_user_id'] === $viewerId) {
            Notification::create(
                (int) $request['requester_user_id'],
                'friend.accepted',
                'Solicitud aceptada',
                'Tu solicitud de amistad fue aceptada.',
                '/user/@' . rawurlencode((string) (self::usernameForUser($viewerId) ?? '')),
                $viewerId,
                ['friend_user_id' => $viewerId]
            );
        }
    }

    public static function reject(int $viewerId, int $requestId): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'UPDATE user_friends
             SET status = "rejected", responded_at = NOW(), updated_at = NOW()
             WHERE id = :id AND addressee_user_id = :viewer AND status = "pending"'
        );
        $stmt->execute([
            'id' => $requestId,
            'viewer' => $viewerId,
        ]);
    }

    public static function remove(int $viewerId, int $targetId): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'DELETE FROM user_friends
             WHERE ((requester_user_id = :viewer_a AND addressee_user_id = :target_a)
                OR (requester_user_id = :target_b AND addressee_user_id = :viewer_b))
               AND status IN ("accepted", "pending", "rejected")'
        );
        $stmt->execute([
            'viewer_a' => $viewerId,
            'target_a' => $targetId,
            'target_b' => $targetId,
            'viewer_b' => $viewerId,
        ]);
    }

    public static function friendsForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT f.*, u.id AS friend_id, u.username, COALESCE(p.display_name, u.display_name, u.username) AS display_name,
                    p.avatar_path
             FROM user_friends f
             INNER JOIN users u ON u.id = CASE WHEN f.requester_user_id = :user_id_case THEN f.addressee_user_id ELSE f.requester_user_id END
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE (f.requester_user_id = :user_id_a OR f.addressee_user_id = :user_id_b)
               AND f.status = "accepted"
             ORDER BY display_name ASC'
        );
        $stmt->execute([
            'user_id_case' => $userId,
            'user_id_a' => $userId,
            'user_id_b' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public static function pendingForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT f.*, u.username, COALESCE(p.display_name, u.display_name, u.username) AS display_name,
                    p.avatar_path
             FROM user_friends f
             INNER JOIN users u ON u.id = f.requester_user_id
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE f.addressee_user_id = :user_id
               AND f.status = "pending"
             ORDER BY f.requested_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    private static function requestById(int $requestId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM user_friends WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private static function usernameForUser(int $userId): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $username = $stmt->fetchColumn();

        return is_string($username) && $username !== '' ? $username : null;
    }
}
