<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class UserAgreement
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_eula_acceptances (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                locale VARCHAR(12) NOT NULL DEFAULT "es",
                version VARCHAR(40) NOT NULL,
                accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                UNIQUE KEY uq_user_eula_acceptances_user_locale_version (user_id, locale, version),
                INDEX idx_user_eula_acceptances_user (user_id),
                CONSTRAINT fk_user_eula_acceptances_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('locale')) {
            Database::pdo()->exec('ALTER TABLE user_eula_acceptances ADD COLUMN locale VARCHAR(12) NOT NULL DEFAULT "es" AFTER user_id');
        }

        if (self::indexExists('uq_user_eula_acceptances_user_version')) {
            Database::pdo()->exec('ALTER TABLE user_eula_acceptances DROP INDEX uq_user_eula_acceptances_user_version');
        }

        if (!self::indexExists('uq_user_eula_acceptances_user_locale_version')) {
            Database::pdo()->exec('ALTER TABLE user_eula_acceptances ADD UNIQUE KEY uq_user_eula_acceptances_user_locale_version (user_id, locale, version)');
        }
    }

    public static function acceptedCurrent(int $userId): bool
    {
        $settings = PlatformSettings::eulaSettings();
        if (!$settings['enabled'] || !$settings['required']) {
            return true;
        }

        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM user_eula_acceptances
             WHERE user_id = :user_id AND locale = :locale AND version = :version'
        );
        $stmt->execute([
            'user_id' => $userId,
            'locale' => $settings['locale'],
            'version' => $settings['version'],
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function needsAcceptance(int $userId): bool
    {
        return !self::acceptedCurrent($userId);
    }

    public static function acceptCurrent(int $userId): void
    {
        $settings = PlatformSettings::eulaSettings();
        if (!$settings['enabled']) {
            return;
        }

        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_eula_acceptances (user_id, locale, version, accepted_at, ip_address, user_agent)
             VALUES (:user_id, :locale, :version, NOW(), :ip_address, :user_agent)
             ON DUPLICATE KEY UPDATE accepted_at = VALUES(accepted_at), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'locale' => $settings['locale'],
            'version' => $settings['version'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    public static function latestForUser(int $userId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM user_eula_acceptances
             WHERE user_id = :user_id
             ORDER BY accepted_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private static function columnExists(string $column): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = "user_eula_acceptances"
               AND column_name = :column_name'
        );
        $stmt->execute(['column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function indexExists(string $index): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = "user_eula_acceptances"
               AND index_name = :index_name'
        );
        $stmt->execute(['index_name' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
