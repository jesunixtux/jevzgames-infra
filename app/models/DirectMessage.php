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
                    last_message.id AS last_message_id,
                    last_message.sender_user_id AS last_sender_user_id,
                    last_message.recipient_user_id AS last_recipient_user_id,
                    last_message.read_at AS last_read_at,
                    last_message.created_at AS last_message_created_at,
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

    public static function clientConversations(int $userId): array
    {
        return array_map(
            static fn (array $row): array => self::clientConversationPayload($row, $userId),
            self::inbox($userId)
        );
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

    public static function findThreadBetween(int $userId, int $otherUserId): ?array
    {
        self::ensureTables();
        [$userA, $userB] = self::pair($userId, $otherUserId);
        $stmt = Database::pdo()->prepare(
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

        return is_array($thread) ? $thread : null;
    }

    public static function clientThread(int $userId, int $otherUserId, int $limit = 50, int $beforeId = 0, int $afterId = 0): array
    {
        self::ensureTables();
        $otherUser = self::clientUserById($otherUserId);
        if (!$otherUser) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        $thread = self::threadBetween($userId, $otherUserId);
        self::markThreadRead((int) $thread['id'], $userId);
        Notification::markUrlRead($userId, '/messages/?thread=' . (int) $thread['id']);

        $limit = max(1, min(100, $limit));
        $where = ['m.thread_id = :thread_id'];
        $params = ['thread_id' => (int) $thread['id']];
        $order = 'DESC';
        $reverseRows = true;

        if ($afterId > 0) {
            $where[] = 'm.id > :after_id';
            $params['after_id'] = $afterId;
            $order = 'ASC';
            $reverseRows = false;
        } elseif ($beforeId > 0) {
            $where[] = 'm.id < :before_id';
            $params['before_id'] = $beforeId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.username AS sender_username, COALESCE(pp.display_name, u.display_name, u.username) AS sender_display_name
             FROM direct_messages m
             INNER JOIN users u ON u.id = m.sender_user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY m.id ' . $order . '
             LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if ($reverseRows) {
            $rows = array_reverse($rows);
        }

        return [
            'thread' => [
                'id' => (int) $thread['id'],
                'conversation_user_id' => $otherUserId,
                'created_at' => $thread['created_at'] ?? null,
                'updated_at' => $thread['updated_at'] ?? null,
            ],
            'conversation_user' => self::clientUserPayload($otherUser),
            'messages' => array_map(
                static fn (array $row): array => self::clientMessagePayload($row, $userId),
                $rows
            ),
            'pagination' => [
                'limit' => $limit,
                'before_id' => $beforeId > 0 ? $beforeId : null,
                'after_id' => $afterId > 0 ? $afterId : null,
                'count' => count($rows),
            ],
        ];
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

    public static function clientSend(int $senderUserId, int $recipientUserId, string $message): array
    {
        $message = trim($message);
        if ($message === '' || strlen($message) > 2000) {
            throw new RuntimeException('El mensaje debe tener entre 1 y 2000 caracteres.');
        }

        $threadId = self::send($senderUserId, $recipientUserId, $message);
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.username AS sender_username, COALESCE(pp.display_name, u.display_name, u.username) AS sender_display_name
             FROM direct_messages m
             INNER JOIN users u ON u.id = m.sender_user_id
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE m.thread_id = :thread_id
               AND m.sender_user_id = :sender_user_id
             ORDER BY m.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'thread_id' => $threadId,
            'sender_user_id' => $senderUserId,
        ]);
        $row = $stmt->fetch();

        return [
            'thread_id' => $threadId,
            'message' => is_array($row) ? self::clientMessagePayload($row, $senderUserId) : null,
        ];
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

    public static function clientMarkRead(int $userId, int $otherUserId): array
    {
        $thread = self::findThreadBetween($userId, $otherUserId);
        if ($thread) {
            self::markThreadRead((int) $thread['id'], $userId);
            Notification::markUrlRead($userId, '/messages/?thread=' . (int) $thread['id']);
        }

        return [
            'conversation_user_id' => $otherUserId,
            'thread_id' => $thread ? (int) $thread['id'] : null,
            'unread_count' => self::unreadCount($userId),
        ];
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

    private static function clientConversationPayload(array $row, int $userId): array
    {
        $otherUserId = (int) $row['other_user_id'];
        $lastMessage = null;
        if (!empty($row['last_message_id'])) {
            $lastMessage = [
                'id' => (int) $row['last_message_id'],
                'sender_user_id' => (int) $row['last_sender_user_id'],
                'recipient_user_id' => (int) $row['last_recipient_user_id'],
                'message' => (string) ($row['last_message'] ?? ''),
                'message_html' => self::messageHtml((string) ($row['last_message'] ?? '')),
                'is_outgoing' => (int) $row['last_sender_user_id'] === $userId,
                'is_read' => !empty($row['last_read_at']) || (int) $row['last_sender_user_id'] === $userId,
                'created_at' => $row['last_message_created_at'] ?? null,
                'read_at' => $row['last_read_at'] ?? null,
            ];
        }

        return [
            'thread_id' => (int) $row['id'],
            'conversation_user' => [
                'id' => $otherUserId,
                'username' => (string) $row['other_username'],
                'display_name' => (string) $row['other_display_name'],
                'avatar_path' => $row['other_avatar_path'] ?? null,
                'presence' => ClientApp::presencePayload(Presence::forUser($otherUserId)),
            ],
            'last_message' => $lastMessage,
            'unread_count' => (int) ($row['unread_count'] ?? 0),
            'last_message_at' => $row['last_message_created_at'] ?? $row['last_message_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function clientMessagePayload(array $row, int $viewerUserId): array
    {
        $message = (string) ($row['message'] ?? '');

        return [
            'id' => (int) $row['id'],
            'thread_id' => (int) $row['thread_id'],
            'sender_user_id' => (int) $row['sender_user_id'],
            'recipient_user_id' => (int) $row['recipient_user_id'],
            'sender_username' => (string) ($row['sender_username'] ?? ''),
            'sender_display_name' => (string) ($row['sender_display_name'] ?? $row['sender_username'] ?? ''),
            'message' => $message,
            'message_html' => self::messageHtml($message),
            'is_outgoing' => (int) $row['sender_user_id'] === $viewerUserId,
            'is_read' => !empty($row['read_at']) || (int) $row['sender_user_id'] === $viewerUserId,
            'created_at' => $row['created_at'] ?? null,
            'read_at' => $row['read_at'] ?? null,
        ];
    }

    private static function clientUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.username, u.display_name, u.status, pp.avatar_path
             FROM users u
             LEFT JOIN public_profiles pp ON pp.user_id = u.id
             WHERE u.id = :id
               AND u.status = "active"
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    private static function clientUserPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) ($user['display_name'] ?? $user['username']),
            'avatar_path' => $user['avatar_path'] ?? null,
            'presence' => ClientApp::presencePayload(Presence::forUser((int) $user['id'])),
        ];
    }

    private static function messageHtml(string $message): string
    {
        return nl2br(\htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false);
    }
}
