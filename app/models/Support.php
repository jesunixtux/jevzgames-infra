<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class Support
{
    public const CHAT_LIFETIME_MINUTES = 3;

    public static function listTickets(?string $status = null, ?int $userId = null): array
    {
        $params = [];
        $where = [];

        if ($status !== null && $status !== 'all') {
            self::assertStatus($status);
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }

        if ($userId !== null) {
            $where[] = 't.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql = self::ticketSelectSql();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY FIELD(t.status, "open", "unsolved", "solved", "closed"), COALESCE(last_message.last_message_at, t.created_at) DESC, t.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function findTicket(int $ticketId, ?int $userId = null): ?array
    {
        $sql = self::ticketSelectSql() . ' WHERE t.id = :ticket_id';
        $params = ['ticket_id' => $ticketId];

        if ($userId !== null) {
            $sql .= ' AND t.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $ticket = $stmt->fetch();

        return is_array($ticket) ? $ticket : null;
    }

    public static function messages(int $ticketId, int $afterId = 0): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.id, m.ticket_id, m.sender_user_id, m.message, m.created_at, u.username AS sender_username
             FROM support_messages m
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE m.ticket_id = :ticket_id AND m.id > :after_id
             ORDER BY m.id ASC'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'after_id' => max(0, $afterId),
        ]);

        return $stmt->fetchAll();
    }

    public static function createTicket(int $userId, string $subject, string $message): int
    {
        $subject = self::cleanSubject($subject);
        $message = self::cleanMessage($message);

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO support_tickets (user_id, subject, status, expires_at, created_at, updated_at)
                 VALUES (:user_id, :subject, "open", DATE_ADD(NOW(), INTERVAL ' . self::CHAT_LIFETIME_MINUTES . ' MINUTE), NOW(), NOW())'
            );
            $stmt->execute([
                'user_id' => $userId,
                'subject' => $subject,
            ]);

            $ticketId = (int) $pdo->lastInsertId();
            self::insertMessage($ticketId, $userId, $message);

            $pdo->commit();
            return $ticketId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function addMessage(int $ticketId, int $senderUserId, string $message): void
    {
        $ticket = self::findTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException('El ticket no existe.');
        }

        if (!self::canReply($ticket)) {
            throw new RuntimeException('El chat esta cerrado o vencido. Un supporter debe extenderlo si corresponde.');
        }

        self::insertMessage($ticketId, $senderUserId, self::cleanMessage($message));
        self::touchTicket($ticketId);
    }

    public static function assignTicket(int $ticketId, int $supporterId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE support_tickets
             SET assigned_user_id = :supporter_id, updated_at = NOW()
             WHERE id = :ticket_id AND status = "open"'
        );
        $stmt->execute([
            'supporter_id' => $supporterId,
            'ticket_id' => $ticketId,
        ]);
    }

    public static function extendTicket(int $ticketId, int $minutes = 3): void
    {
        $minutes = max(1, min(120, $minutes));
        $stmt = Database::pdo()->prepare(
            'UPDATE support_tickets
             SET expires_at = DATE_ADD(GREATEST(COALESCE(expires_at, NOW()), NOW()), INTERVAL :minutes MINUTE),
                 updated_at = NOW()
             WHERE id = :ticket_id AND status = "open"'
        );
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->bindValue('ticket_id', $ticketId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function closeTicket(int $ticketId, int $supporterId, string $status): void
    {
        if (!in_array($status, ['closed', 'solved', 'unsolved'], true)) {
            throw new RuntimeException('Estado de cierre invalido.');
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE support_tickets
             SET status = :status,
                 assigned_user_id = COALESCE(assigned_user_id, :supporter_id),
                 closed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :ticket_id'
        );
        $stmt->execute([
            'status' => $status,
            'supporter_id' => $supporterId,
            'ticket_id' => $ticketId,
        ]);
    }

    public static function canReply(array $ticket): bool
    {
        if (array_key_exists('can_reply', $ticket)) {
            return (int) $ticket['can_reply'] === 1;
        }

        if (($ticket['status'] ?? '') !== 'open') {
            return false;
        }

        if (empty($ticket['expires_at'])) {
            return true;
        }

        return strtotime((string) $ticket['expires_at']) >= time();
    }

    public static function timeLeftSeconds(array $ticket): int
    {
        if (array_key_exists('time_left_seconds', $ticket)) {
            return max(0, (int) $ticket['time_left_seconds']);
        }

        if (empty($ticket['expires_at'])) {
            return 0;
        }

        return max(0, strtotime((string) $ticket['expires_at']) - time());
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Abierto',
            'closed' => 'Cerrado',
            'solved' => 'Solucionado',
            'unsolved' => 'No solucionado',
            default => $status,
        };
    }

    private static function ticketSelectSql(): string
    {
        return 'SELECT t.*,
                       CASE
                           WHEN t.status = "open" AND (t.expires_at IS NULL OR t.expires_at >= NOW()) THEN 1
                           ELSE 0
                       END AS can_reply,
                       CASE
                           WHEN t.expires_at IS NULL THEN 0
                           ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), t.expires_at))
                       END AS time_left_seconds,
                       requester.username AS requester_username,
                       requester.email AS requester_email,
                       assigned.username AS assigned_username,
                       last_message.last_message_at,
                       last_message.message_count
                FROM support_tickets t
                LEFT JOIN users requester ON requester.id = t.user_id
                LEFT JOIN users assigned ON assigned.id = t.assigned_user_id
                LEFT JOIN (
                    SELECT ticket_id, MAX(created_at) AS last_message_at, COUNT(*) AS message_count
                    FROM support_messages
                    GROUP BY ticket_id
                ) last_message ON last_message.ticket_id = t.id';
    }

    private static function insertMessage(int $ticketId, int $senderUserId, string $message): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO support_messages (ticket_id, sender_user_id, message, created_at)
             VALUES (:ticket_id, :sender_user_id, :message, NOW())'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'sender_user_id' => $senderUserId,
            'message' => $message,
        ]);
    }

    private static function touchTicket(int $ticketId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = :ticket_id');
        $stmt->execute(['ticket_id' => $ticketId]);
    }

    private static function cleanSubject(string $subject): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
        if ($subject === '' || strlen($subject) > 180) {
            throw new RuntimeException('El asunto debe tener entre 1 y 180 caracteres.');
        }

        return $subject;
    }

    private static function cleanMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '' || strlen($message) > 5000) {
            throw new RuntimeException('El mensaje debe tener entre 1 y 5000 caracteres.');
        }

        return $message;
    }

    private static function assertStatus(string $status): void
    {
        if (!in_array($status, ['open', 'closed', 'solved', 'unsolved'], true)) {
            throw new RuntimeException('Estado de ticket invalido.');
        }
    }
}
