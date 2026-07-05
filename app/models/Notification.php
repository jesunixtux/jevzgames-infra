<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Notification
{
    public static function ensureTables(): void
    {
        DirectMessage::ensureTables();
        Friend::ensureTables();
        Community::ensureTables();
        SocialSettings::ensureTables();
        SystemNotification::ensureTables();
    }

    public static function create(int $userId, string $type, string $title, string $body = '', string $targetUrl = '', ?int $actorUserId = null, array $data = []): int
    {
        return SystemNotification::create($userId, $type, $title, $body, $targetUrl, $actorUserId, $data);
    }

    public static function unreadCount(int $userId): int
    {
        return count(self::dynamicItems($userId)) + SystemNotification::unreadCount($userId);
    }

    public static function listForUser(int $userId, int $limit = 100): array
    {
        self::ensureTables();
        $items = array_merge(
            SystemNotification::listForUser($userId, $limit),
            self::dynamicItems($userId)
        );

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return array_slice($items, 0, max(1, min(200, $limit)));
    }

    private static function dynamicItems(int $userId): array
    {
        return array_merge(
            self::messageItems($userId),
            self::friendRequestItems($userId),
            self::friendAcceptedItems($userId),
            self::commentItems($userId)
        );
    }

    public static function findForUser(int $userId, string|int $notificationId): ?array
    {
        $notificationId = (string) $notificationId;
        foreach (self::listForUser($userId, 200) as $item) {
            if ((string) $item['id'] === $notificationId) {
                return $item;
            }
        }

        return null;
    }

    public static function markRead(int $userId, string|int $notificationId): void
    {
        $notificationId = (string) $notificationId;
        if (str_starts_with($notificationId, 'dm_')) {
            DirectMessage::markThreadRead((int) substr($notificationId, 3), $userId);
            return;
        }
        if (str_starts_with($notificationId, 'sys_')) {
            SystemNotification::markRead($userId, (int) substr($notificationId, 4));
            return;
        }

        self::hide($userId, $notificationId);
    }

    public static function markAllRead(int $userId): void
    {
        DirectMessage::markAllRead($userId);
        SystemNotification::markAllRead($userId);
        $_SESSION['_session_notifications_comment_seen_at'][$userId] = date('Y-m-d H:i:s');

        foreach (self::friendRequestItems($userId, false) as $item) {
            self::hide($userId, (string) $item['id']);
        }
        foreach (self::friendAcceptedItems($userId, false) as $item) {
            self::hide($userId, (string) $item['id']);
        }
        foreach (self::commentItems($userId, false) as $item) {
            self::hide($userId, (string) $item['id']);
        }
    }

    public static function markUrlRead(int $userId, string $targetUrl): void
    {
        if (preg_match('#^/messages/\?thread=(\d+)#', $targetUrl, $matches)) {
            DirectMessage::markThreadRead((int) $matches[1], $userId);
            return;
        }

        foreach (self::listForUser($userId, 200) as $item) {
            if (($item['target_url'] ?? '') === $targetUrl) {
                self::hide($userId, (string) $item['id']);
            }
        }
    }

    private static function messageItems(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT unread.thread_id, unread.unread_count,
                    latest.id AS message_id, latest.sender_user_id AS actor_user_id, latest.message, latest.created_at,
                    u.username AS actor_username, COALESCE(p.display_name, u.display_name, u.username) AS actor_display_name
             FROM (
                SELECT thread_id, MAX(id) AS latest_id, COUNT(*) AS unread_count
                FROM direct_messages
                WHERE recipient_user_id = :user_id AND read_at IS NULL
                GROUP BY thread_id
             ) unread
             INNER JOIN direct_messages latest ON latest.id = unread.latest_id
             INNER JOIN users u ON u.id = latest.sender_user_id
             LEFT JOIN public_profiles p ON p.user_id = u.id
             ORDER BY latest.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $actorId = (int) $row['actor_user_id'];
            if (SocialSettings::isMuted($userId, $actorId)) {
                continue;
            }

            $items[] = [
                'id' => 'dm_' . (int) $row['thread_id'],
                'type' => 'direct.message',
                'title' => 'Nuevo mensaje',
                'body' => ((int) $row['unread_count'] > 1 ? (int) $row['unread_count'] . ' mensajes nuevos. ' : '') . substr((string) $row['message'], 0, 180),
                'target_url' => '/messages/?thread=' . (int) $row['thread_id'],
                'actor_user_id' => $actorId,
                'actor_username' => $row['actor_username'] ?? null,
                'actor_display_name' => $row['actor_display_name'] ?? null,
                'created_at' => $row['created_at'],
                'read_at' => null,
                'data' => [
                    'thread_id' => (int) $row['thread_id'],
                    'message_id' => (int) $row['message_id'],
                    'unread_count' => (int) $row['unread_count'],
                ],
            ];
        }

        return $items;
    }

    private static function friendRequestItems(int $userId, bool $respectHidden = true): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT f.id, f.requester_user_id AS actor_user_id, f.requested_at AS created_at,
                    u.username AS actor_username, COALESCE(p.display_name, u.display_name, u.username) AS actor_display_name
             FROM user_friends f
             INNER JOIN users u ON u.id = f.requester_user_id
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE f.addressee_user_id = :user_id
               AND f.status = "pending"
             ORDER BY f.requested_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = 'fr_' . (int) $row['id'];
            $actorId = (int) $row['actor_user_id'];
            if (($respectHidden && self::isHidden($userId, $id)) || SocialSettings::isMuted($userId, $actorId)) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'type' => 'friend.request',
                'title' => 'Solicitud de amistad',
                'body' => '@' . (string) $row['actor_username'] . ' quiere agregarte.',
                'target_url' => '/profile/',
                'actor_user_id' => $actorId,
                'actor_username' => $row['actor_username'] ?? null,
                'actor_display_name' => $row['actor_display_name'] ?? null,
                'created_at' => $row['created_at'],
                'read_at' => null,
                'data' => ['friend_request_id' => (int) $row['id']],
            ];
        }

        return $items;
    }

    private static function commentItems(int $userId, bool $respectHidden = true): array
    {
        $seenAt = $_SESSION['_session_notifications_comment_seen_at'][$userId] ?? null;
        $sql = 'SELECT c.id, c.post_id, c.user_id AS actor_user_id, c.body, c.created_at,
                       p.title AS post_title,
                       u.username AS actor_username, COALESCE(pp.display_name, u.display_name, u.username) AS actor_display_name
                FROM community_comments c
                INNER JOIN community_posts p ON p.id = c.post_id
                INNER JOIN users u ON u.id = c.user_id
                LEFT JOIN public_profiles pp ON pp.user_id = u.id
                WHERE p.user_id = :user_id
                  AND c.user_id <> :user_id_actor
                  AND p.status = "active"
                  AND c.status = "active"';
        $params = [
            'user_id' => $userId,
            'user_id_actor' => $userId,
        ];
        if (is_string($seenAt) && $seenAt !== '') {
            $sql .= ' AND c.created_at > :seen_at';
            $params['seen_at'] = $seenAt;
        }
        $sql .= ' ORDER BY c.created_at DESC LIMIT 100';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = 'cm_' . (int) $row['id'];
            $actorId = (int) $row['actor_user_id'];
            if (($respectHidden && self::isHidden($userId, $id)) || SocialSettings::isMuted($userId, $actorId)) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'type' => 'community.comment',
                'title' => 'Nuevo comentario',
                'body' => '@' . (string) $row['actor_username'] . ' comento: ' . substr((string) $row['body'], 0, 160),
                'target_url' => '/community/?post=' . (int) $row['post_id'] . '#comments',
                'actor_user_id' => $actorId,
                'actor_username' => $row['actor_username'] ?? null,
                'actor_display_name' => $row['actor_display_name'] ?? null,
                'created_at' => $row['created_at'],
                'read_at' => null,
                'data' => [
                    'post_id' => (int) $row['post_id'],
                    'comment_id' => (int) $row['id'],
                ],
            ];
        }

        return $items;
    }

    private static function friendAcceptedItems(int $userId, bool $respectHidden = true): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT f.id, f.addressee_user_id AS actor_user_id, f.responded_at AS created_at,
                    u.username AS actor_username, COALESCE(p.display_name, u.display_name, u.username) AS actor_display_name
             FROM user_friends f
             INNER JOIN users u ON u.id = f.addressee_user_id
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE f.requester_user_id = :user_id
               AND f.status = "accepted"
               AND f.responded_at IS NOT NULL
             ORDER BY f.responded_at DESC
             LIMIT 100'
        );
        $stmt->execute(['user_id' => $userId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = 'fa_' . (int) $row['id'];
            $actorId = (int) $row['actor_user_id'];
            if (($respectHidden && self::isHidden($userId, $id)) || SocialSettings::isMuted($userId, $actorId)) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'type' => 'friend.accepted',
                'title' => 'Solicitud aceptada',
                'body' => '@' . (string) $row['actor_username'] . ' acepto tu solicitud.',
                'target_url' => '/user/@' . rawurlencode((string) $row['actor_username']),
                'actor_user_id' => $actorId,
                'actor_username' => $row['actor_username'] ?? null,
                'actor_display_name' => $row['actor_display_name'] ?? null,
                'created_at' => $row['created_at'],
                'read_at' => null,
                'data' => ['friend_request_id' => (int) $row['id']],
            ];
        }

        return $items;
    }

    private static function hide(int $userId, string $notificationId): void
    {
        $_SESSION['_session_notifications_hidden'][$userId][$notificationId] = true;
    }

    private static function isHidden(int $userId, string $notificationId): bool
    {
        return !empty($_SESSION['_session_notifications_hidden'][$userId][$notificationId]);
    }
}
