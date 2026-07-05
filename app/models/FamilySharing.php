<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class FamilySharing
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_family_members (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_user_id INT UNSIGNED NOT NULL,
                member_user_id INT UNSIGNED NOT NULL,
                status ENUM("pending", "active", "revoked") NOT NULL DEFAULT "pending",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_at DATETIME NULL,
                revoked_at DATETIME NULL,
                UNIQUE KEY uq_user_family_members_pair (owner_user_id, member_user_id),
                INDEX idx_user_family_members_owner (owner_user_id),
                INDEX idx_user_family_members_member (member_user_id),
                INDEX idx_user_family_members_status (status),
                CONSTRAINT fk_user_family_members_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_family_members_member FOREIGN KEY (member_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function invite(int $ownerUserId, string $memberIdentity): int
    {
        self::ensureTables();
        $member = User::findByEmailOrUsername($memberIdentity);
        if (!$member) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        $memberUserId = (int) $member['id'];
        if ($memberUserId === $ownerUserId) {
            throw new RuntimeException('No puedes compartir familia contigo mismo.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_family_members (owner_user_id, member_user_id, status, created_at)
             VALUES (:owner_user_id, :member_user_id, "pending", NOW())
             ON DUPLICATE KEY UPDATE status = "pending", revoked_at = NULL'
        );
        $stmt->execute([
            'owner_user_id' => $ownerUserId,
            'member_user_id' => $memberUserId,
        ]);

        Notification::create($memberUserId, 'family.invite', 'Invitacion de Family Sharing', 'Te invitaron a una biblioteca familiar.', '/family/', $ownerUserId);

        return $memberUserId;
    }

    public static function accept(int $memberUserId, int $ownerUserId): void
    {
        self::ensureTables();
        Database::pdo()->prepare(
            'UPDATE user_family_members
             SET status = "active", accepted_at = NOW(), revoked_at = NULL
             WHERE owner_user_id = :owner_user_id AND member_user_id = :member_user_id AND status = "pending"'
        )->execute([
            'owner_user_id' => $ownerUserId,
            'member_user_id' => $memberUserId,
        ]);
    }

    public static function revoke(int $ownerUserId, int $memberUserId): void
    {
        self::ensureTables();
        Database::pdo()->prepare(
            'UPDATE user_family_members
             SET status = "revoked", revoked_at = NOW()
             WHERE owner_user_id = :owner_user_id AND member_user_id = :member_user_id'
        )->execute([
            'owner_user_id' => $ownerUserId,
            'member_user_id' => $memberUserId,
        ]);
    }

    public static function rowsForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT f.*,
                    owner.username AS owner_username,
                    member.username AS member_username
             FROM user_family_members f
             INNER JOIN users owner ON owner.id = f.owner_user_id
             INNER JOIN users member ON member.id = f.member_user_id
             WHERE f.owner_user_id = :owner_id OR f.member_user_id = :member_id
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([
            'owner_id' => $userId,
            'member_id' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public static function canUseSharedGame(int $memberUserId, int $gameId): bool
    {
        self::ensureTables();
        Game::ensureLicenseTables();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM user_family_members f
             INNER JOIN user_game_licenses l ON l.user_id = f.owner_user_id AND l.status = "active"
             WHERE f.member_user_id = :member_user_id
               AND f.status = "active"
               AND l.game_id = :game_id'
        );
        $stmt->execute([
            'member_user_id' => $memberUserId,
            'game_id' => $gameId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function sharedGameRows(int $memberUserId): array
    {
        self::ensureTables();
        Game::ensureLicenseTables();
        Playtime::ensureColumns();
        $stmt = Database::pdo()->prepare(
            'SELECT l.user_id AS family_owner_user_id, owner.username AS family_owner_username,
                    l.id AS license_id, l.license_key_preview, l.granted_at AS licensed_at,
                    g.id AS game_id, g.owner_user_id, g.name, g.slug, g.developer_name, g.publisher_name,
                    g.status, g.visibility, g.source_type, g.external_game_id, g.current_version, g.config_json,
                    ug.linked_at, ug.last_played_at, ug.total_play_seconds
             FROM user_family_members f
             INNER JOIN user_game_licenses l ON l.user_id = f.owner_user_id AND l.status = "active"
             INNER JOIN games g ON g.id = l.game_id
             INNER JOIN users owner ON owner.id = f.owner_user_id
             LEFT JOIN user_games ug ON ug.user_id = :member_user_id_link AND ug.game_id = g.id
             WHERE f.member_user_id = :member_user_id
               AND f.status = "active"
               AND g.status IN ("development", "playtest", "beta", "published")
             ORDER BY owner.username ASC, g.name ASC'
        );
        $stmt->execute([
            'member_user_id' => $memberUserId,
            'member_user_id_link' => $memberUserId,
        ]);

        return $stmt->fetchAll();
    }
}
