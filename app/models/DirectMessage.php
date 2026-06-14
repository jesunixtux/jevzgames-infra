<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class DirectMessage
{
    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS direct_message_threads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_a_id INT UNSIGNED NOT NULL,
                user_b_id INT UNSIGNED NOT NULL,
                last_message_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_direct_message_threads_pair (user_a_id, user_b_id),
                INDEX idx_direct_message_threads_a (user_a_id),
                INDEX idx_direct_message_threads_b (user_b_id),
                CONSTRAINT fk_direct_message_threads_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_direct_message_threads_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS direct_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                thread_id BIGINT UNSIGNED NOT NULL,
                sender_user_id INT UNSIGNED NOT NULL,
                recipient_user_id INT UNSIGNED NOT NULL,
                message TEXT NOT NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_direct_messages_thread (thread_id, created_at),
                INDEX idx_direct_messages_recipient_read (recipient_user_id, read_at),
                CONSTRAINT fk_direct_messages_thread FOREIGN KEY (thread_id) REFERENCES direct_message_threads(id) ON DELETE CASCADE,
                CONSTRAINT fk_direct_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_direct_messages_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function inbox(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT t.*,
                    other_user.id AS other_user_id,
                    other_user.username AS other_username,
                    COALESCE(pp.display_name, other_user.display_name, other_user.username) AS other_display_name,
                    pp.avatar_path AS other_avatar_path,
                    last_message.message AS last_message,
                    last_message.sender_user_id AS last_sender_user_id,
                    unread.unread_count
             FROM direct_message_threads t
             INNER JOIN users other_user ON other_user.id = CASE WHEN t.user_a_id = :user_case THEN t.user_b_id ELSE t.user_a_id END
             LEFT JOIN public_profiles pp ON pp.user_id = other_user.id
             LEFT JOIN (
                SELECT m1.*
                FROM direct_messages m1
                INNER JOIN (
                    SELECT thread_id, MAX(id) AS max_id
                    FROM direct_messages
                    GROUP BY thread_id
                ) latest ON latest.max_id = m1.id
             ) last_message ON last_message.thread_id = t.id
             LEFT JOIN (
                SELECT thread_id, COUNT(*) AS unread_count
                FROM direct_messages
                WHERE recipient_user_id = :user_unread AND read_at IS NULL
                GROUP BY thread_id
             ) unread ON unread.thread_id = t.id
             WHERE t.user_a_id = :user_a OR t.user_b_id = :user_b
             ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC'
        );
        $stmt->execute([
            'user_case' => $userId,
            'user_unread' => $userId,
            'user_a' => $userId,
            'user_b' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public static function findThreadForUser(int $threadId, int $userId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT t.*,
                    other_user.id AS other_user_id,
                    other_user.username AS other_username,
                    COALESCE(pp.display_name, other_user.display_name, other_user.username) AS other_display_name
             FROM direct_message_threads t
             INNER JOIN users other_user ON other_user.id = CASE WHEN t.user_a_id = :user_case THEN t.user_b_id ELSE t.user_a_id END
             LEFT JOIN public_profiles pp ON pp.user_id = other_user.id
             WHERE t.id = :id
               AND (t.user_a_id = :user_a OR t.user_b_id = :user_b)
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $threadId,
            'user_case' => $userId,
            'user_a' => $userId,
            'user_b' => $userId,
        ]);
        $thread = $stmt->fetch();

        return is_array($thread) ? $thread : null;
    }

    public static function threadBetween(int $userId, int $otherUserId): array
    {
        self::ensureTables();
        [$userA, $userB] = self::pair($userId, $otherUserId);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO direct_message_threads (user_a_id, user_b_id, created_at, updated_at)
             VALUES (:user_a, :user_b, NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated_at = updated_at'
        );
        $stmt->execute([
            'user_a' => $userA,
            'user_b' => $userB,
        ]);

        $stmt = $pdo->prepare(
            'SELECT *
             FROM direct_message_threads
             WHERE user_a_id = :user_a AND user_b_id = :user_b
             LIMIT 1'
        );
        $stmt->execute([
            'user_a' => $userA,
            'user_b' => $userB,
        ]);
        $thread = $stmt->fetch();

        if (!is_array($thread)) {
            throw new RuntimeException('No se pudo crear la conversacion.');
        }

        return $thread;
    }

    public static function messages(int $threadId, int $userId, bool $markRead = true): array
    {
        self::ensureTables();
        $thread = self::findThreadForUser($threadId, $userId);
        if (!$thread) {
            throw new RuntimeException('Conversacion no encontrada.');
        }

        if ($markRead) {
            self::markThreadRead($threadId, $userId);
            Notification::markUrlRead($userId, '/messages/?thread=' . $threadId);
        }

        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.username AS sender_username, COALESCE(pp.display_name, u.display_name, u.username) AS sender_display_name
             FROM direct_messages m
             INNER JOIN users u ON u.id = m.sender_user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE m.thread_id = :thread_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute(['thread_id' => $threadId]);

        return $stmt->fetchAll();
    }

    public static function send(int $senderUserId, int $recipientUserId, string $message): int
    {
        self::ensureTables();
        if ($senderUserId <= 0 || $recipientUserId <= 0 || $senderUserId === $recipientUserId) {
            throw new RuntimeException('Destinatario invalido.');
        }

        if (!SocialSettings::canReceiveMessage($recipientUserId, $senderUserId)) {
            throw new RuntimeException('Este usuario no recibe mensajes tuyos.');
        }

        $recipient = User::findByIdWithRoles($recipientUserId);
        if (!$recipient || ($recipient['status'] ?? '') !== 'active') {
            throw new RuntimeException('El destinatario no existe o no esta activo.');
        }

        $message = trim($message);
        if ($message === '' || strlen($message) > 5000) {
            throw new RuntimeException('El mensaje debe tener entre 1 y 5000 caracteres.');
        }

        $thread = self::threadBetween($senderUserId, $recipientUserId);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO direct_messages (thread_id, sender_user_id, recipient_user_id, message, created_at)
             VALUES (:thread_id, :sender_user_id, :recipient_user_id, :message, NOW())'
        );
        $stmt->execute([
            'thread_id' => (int) $thread['id'],
            'sender_user_id' => $senderUserId,
            'recipient_user_id' => $recipientUserId,
            'message' => $message,
        ]);

        $messageId = (int) Database::pdo()->lastInsertId();
        Database::pdo()->prepare(
            'UPDATE direct_message_threads
             SET last_message_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute(['id' => (int) $thread['id']]);

        Notification::create(
            $recipientUserId,
            'direct.message',
            'Nuevo mensaje',
            'Tienes un mensaje privado nuevo.',
            '/messages/?thread=' . (int) $thread['id'],
            $senderUserId,
            ['thread_id' => (int) $thread['id'], 'message_id' => $messageId]
        );

        return (int) $thread['id'];
    }

    public static function userByUsername(string $username): ?array
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, username, display_name, status
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    public static function unreadCount(int $userId): int
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM direct_messages
             WHERE recipient_user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public static function messagesAfter(int $threadId, int $userId, int $afterId): array
    {
        self::ensureTables();
        $thread = self::findThreadForUser($threadId, $userId);
        if (!$thread) {
            throw new RuntimeException('Conversacion no encontrada.');
        }

        self::markThreadRead($threadId, $userId);

        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.username AS sender_username, COALESCE(pp.display_name, u.display_name, u.username) AS sender_display_name
             FROM direct_messages m
             INNER JOIN users u ON u.id = m.sender_user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE m.thread_id = :thread_id
               AND m.id > :after_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->bindValue('thread_id', $threadId, \PDO::PARAM_INT);
        $stmt->bindValue('after_id', max(0, $afterId), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function markThreadRead(int $threadId, int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE direct_messages
             SET read_at = COALESCE(read_at, NOW())
             WHERE thread_id = :thread_id
               AND recipient_user_id = :user_id
               AND read_at IS NULL'
        );
        $stmt->execute([
            'thread_id' => $threadId,
            'user_id' => $userId,
        ]);
    }

    public static function markAllRead(int $userId): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'UPDATE direct_messages
             SET read_at = COALESCE(read_at, NOW())
             WHERE recipient_user_id = :user_id
               AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    private static function pair(int $userId, int $otherUserId): array
    {
        if ($userId === $otherUserId) {
            throw new RuntimeException('No puedes iniciar una conversacion contigo mismo.');
        }

        return $userId < $otherUserId
            ? [$userId, $otherUserId]
            : [$otherUserId, $userId];
    }
}
