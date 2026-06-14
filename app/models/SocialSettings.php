<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class SocialSettings
{
    private const FRIEND_POLICIES = ['anyone', 'mutual_friends', 'none'];
    private const MESSAGE_POLICIES = ['anyone', 'friends', 'mutual_friends', 'none'];

    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_social_settings (
                user_id INT UNSIGNED PRIMARY KEY,
                friend_request_policy ENUM("anyone", "mutual_friends", "none") NOT NULL DEFAULT "anyone",
                message_policy ENUM("anyone", "friends", "mutual_friends", "none") NOT NULL DEFAULT "anyone",
                private_show_bio TINYINT(1) NOT NULL DEFAULT 1,
                private_show_games TINYINT(1) NOT NULL DEFAULT 1,
                private_show_achievements TINYINT(1) NOT NULL DEFAULT 1,
                private_show_friends TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_user_social_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_relationship_controls (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                target_user_id INT UNSIGNED NOT NULL,
                control ENUM("blocked", "muted") NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_relationship_controls_scope (user_id, target_user_id, control),
                INDEX idx_user_relationship_controls_user (user_id, control),
                INDEX idx_user_relationship_controls_target (target_user_id, control),
                CONSTRAINT fk_user_relationship_controls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_relationship_controls_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function settingsForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare('SELECT * FROM user_social_settings WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : self::defaults($userId);
    }

    public static function save(int $userId, array $input): void
    {
        self::ensureTables();
        $friendPolicy = (string) ($input['friend_request_policy'] ?? 'anyone');
        $messagePolicy = (string) ($input['message_policy'] ?? 'anyone');

        if (!in_array($friendPolicy, self::FRIEND_POLICIES, true)) {
            throw new RuntimeException('Politica de solicitudes invalida.');
        }

        if (!in_array($messagePolicy, self::MESSAGE_POLICIES, true)) {
            throw new RuntimeException('Politica de mensajes invalida.');
        }

        $data = [
            'user_id' => $userId,
            'friend_request_policy' => $friendPolicy,
            'message_policy' => $messagePolicy,
            'private_show_bio' => isset($input['private_show_bio']) ? 1 : 0,
            'private_show_games' => isset($input['private_show_games']) ? 1 : 0,
            'private_show_achievements' => isset($input['private_show_achievements']) ? 1 : 0,
            'private_show_friends' => isset($input['private_show_friends']) ? 1 : 0,
        ];

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_social_settings
                (user_id, friend_request_policy, message_policy, private_show_bio, private_show_games, private_show_achievements, private_show_friends, updated_at)
             VALUES
                (:user_id, :friend_request_policy, :message_policy, :private_show_bio, :private_show_games, :private_show_achievements, :private_show_friends, NOW())
             ON DUPLICATE KEY UPDATE
                friend_request_policy = VALUES(friend_request_policy),
                message_policy = VALUES(message_policy),
                private_show_bio = VALUES(private_show_bio),
                private_show_games = VALUES(private_show_games),
                private_show_achievements = VALUES(private_show_achievements),
                private_show_friends = VALUES(private_show_friends),
                updated_at = NOW()'
        );
        $stmt->execute($data);
    }

    public static function controlsForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, u.username, COALESCE(p.display_name, u.display_name, u.username) AS display_name
             FROM user_relationship_controls c
             INNER JOIN users u ON u.id = c.target_user_id
             LEFT JOIN public_profiles p ON p.user_id = u.id
             WHERE c.user_id = :user_id
             ORDER BY c.control ASC, display_name ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function setControl(int $userId, int $targetUserId, string $control): void
    {
        self::ensureTables();
        self::assertControl($userId, $targetUserId, $control);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_relationship_controls (user_id, target_user_id, control, created_at, updated_at)
             VALUES (:user_id, :target_user_id, :control, NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'control' => $control,
        ]);

        if ($control === 'blocked') {
            Friend::remove($userId, $targetUserId);
        }
    }

    public static function removeControl(int $userId, int $targetUserId, string $control): void
    {
        self::ensureTables();
        self::assertControl($userId, $targetUserId, $control);
        $stmt = Database::pdo()->prepare(
            'DELETE FROM user_relationship_controls
             WHERE user_id = :user_id AND target_user_id = :target_user_id AND control = :control'
        );
        $stmt->execute([
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'control' => $control,
        ]);
    }

    public static function isBlocked(int $userId, int $targetUserId): bool
    {
        return self::hasControl($userId, $targetUserId, 'blocked');
    }

    public static function isBlockedBetween(int $userId, int $targetUserId): bool
    {
        return self::isBlocked($userId, $targetUserId) || self::isBlocked($targetUserId, $userId);
    }

    public static function isMuted(int $userId, int $targetUserId): bool
    {
        return self::hasControl($userId, $targetUserId, 'muted') || self::isBlocked($userId, $targetUserId);
    }

    public static function areFriends(int $userId, int $targetUserId): bool
    {
        if ($userId <= 0 || $targetUserId <= 0 || $userId === $targetUserId) {
            return false;
        }

        Friend::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM user_friends
             WHERE status = "accepted"
               AND ((requester_user_id = :a1 AND addressee_user_id = :b1)
                OR (requester_user_id = :b2 AND addressee_user_id = :a2))'
        );
        $stmt->execute([
            'a1' => $userId,
            'b1' => $targetUserId,
            'b2' => $targetUserId,
            'a2' => $userId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function hasMutualFriend(int $userId, int $targetUserId): bool
    {
        if ($userId <= 0 || $targetUserId <= 0 || $userId === $targetUserId) {
            return false;
        }

        Friend::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM (
                SELECT CASE WHEN requester_user_id = :user_a THEN addressee_user_id ELSE requester_user_id END AS friend_id
                FROM user_friends
                WHERE status = "accepted" AND (requester_user_id = :user_b OR addressee_user_id = :user_c)
             ) mine
             INNER JOIN (
                SELECT CASE WHEN requester_user_id = :target_a THEN addressee_user_id ELSE requester_user_id END AS friend_id
                FROM user_friends
                WHERE status = "accepted" AND (requester_user_id = :target_b OR addressee_user_id = :target_c)
             ) theirs ON theirs.friend_id = mine.friend_id'
        );
        $stmt->execute([
            'user_a' => $userId,
            'user_b' => $userId,
            'user_c' => $userId,
            'target_a' => $targetUserId,
            'target_b' => $targetUserId,
            'target_c' => $targetUserId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function canReceiveFriendRequest(int $targetUserId, int $viewerId): bool
    {
        if ($targetUserId <= 0 || $viewerId <= 0 || $targetUserId === $viewerId || self::isBlockedBetween($targetUserId, $viewerId)) {
            return false;
        }

        $settings = self::settingsForUser($targetUserId);
        return match ($settings['friend_request_policy']) {
            'anyone' => true,
            'mutual_friends' => self::hasMutualFriend($targetUserId, $viewerId),
            default => false,
        };
    }

    public static function canReceiveMessage(int $targetUserId, int $viewerId): bool
    {
        if ($targetUserId <= 0 || $viewerId <= 0 || $targetUserId === $viewerId || self::isBlockedBetween($targetUserId, $viewerId)) {
            return false;
        }

        $settings = self::settingsForUser($targetUserId);
        return match ($settings['message_policy']) {
            'anyone' => true,
            'friends' => self::areFriends($targetUserId, $viewerId),
            'mutual_friends' => self::areFriends($targetUserId, $viewerId) || self::hasMutualFriend($targetUserId, $viewerId),
            default => false,
        };
    }

    public static function canSeePrivateSection(int $targetUserId, int $viewerId, string $section): bool
    {
        if ($targetUserId === $viewerId) {
            return true;
        }

        if (!self::areFriends($targetUserId, $viewerId)) {
            return false;
        }

        $settings = self::settingsForUser($targetUserId);
        $key = 'private_show_' . $section;
        return !empty($settings[$key]);
    }

    private static function hasControl(int $userId, int $targetUserId, string $control): bool
    {
        self::ensureTables();
        if ($userId <= 0 || $targetUserId <= 0 || $userId === $targetUserId) {
            return false;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM user_relationship_controls
             WHERE user_id = :user_id AND target_user_id = :target_user_id AND control = :control'
        );
        $stmt->execute([
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'control' => $control,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function assertControl(int $userId, int $targetUserId, string $control): void
    {
        if ($userId <= 0 || $targetUserId <= 0 || $userId === $targetUserId) {
            throw new RuntimeException('Usuario objetivo invalido.');
        }

        if (!in_array($control, ['blocked', 'muted'], true)) {
            throw new RuntimeException('Control de usuario invalido.');
        }
    }

    private static function defaults(int $userId): array
    {
        return [
            'user_id' => $userId,
            'friend_request_policy' => 'anyone',
            'message_policy' => 'anyone',
            'private_show_bio' => true,
            'private_show_games' => true,
            'private_show_achievements' => true,
            'private_show_friends' => false,
        ];
    }

    private static function hydrate(array $row): array
    {
        $row['private_show_bio'] = (bool) $row['private_show_bio'];
        $row['private_show_games'] = (bool) $row['private_show_games'];
        $row['private_show_achievements'] = (bool) $row['private_show_achievements'];
        $row['private_show_friends'] = (bool) $row['private_show_friends'];

        return $row;
    }
}
