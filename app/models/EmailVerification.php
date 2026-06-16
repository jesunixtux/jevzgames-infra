<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\ActivityLogger;
use App\Services\Mailer;
use RuntimeException;

final class EmailVerification
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS email_verification_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash VARCHAR(128) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_verification_tokens_user (user_id),
                INDEX idx_email_verification_tokens_expires (expires_at),
                CONSTRAINT fk_email_verification_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function sendForUser(int $userId): ?string
    {
        $settings = PlatformSettings::emailVerificationSettings();
        if (!$settings['enabled']) {
            return null;
        }

        $user = User::findByIdWithRoles($userId);
        if (!$user) {
            throw new RuntimeException('Usuario no encontrado para verificacion.');
        }

        if (User::isEmailVerified($user)) {
            return null;
        }

        self::ensureTables();
        Database::pdo()->prepare(
            'UPDATE email_verification_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id AND used_at IS NULL'
        )->execute(['user_id' => $userId]);

        $token = 'jvg_ev_' . bin2hex(random_bytes(32));
        $ttlHours = max(1, (int) $settings['ttl_hours']);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL ' . $ttlHours . ' HOUR), NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => self::hashToken($token),
        ]);

        $url = \url('/verify-email/?token=' . rawurlencode($token));
        self::deliver($user, $url, $settings);

        return $url;
    }

    public static function verifyToken(string $token): array
    {
        self::ensureTables();
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Token de verificacion requerido.');
        }

        Database::pdo()->prepare('DELETE FROM email_verification_tokens WHERE expires_at < NOW() AND used_at IS NULL')->execute();
        $stmt = Database::pdo()->prepare(
            'SELECT evt.*, u.email, u.username
             FROM email_verification_tokens evt
             INNER JOIN users u ON u.id = evt.user_id
             WHERE evt.token_hash = :token_hash
               AND evt.used_at IS NULL
               AND evt.expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken($token)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El enlace de verificacion no existe o expiro.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            User::markEmailVerified((int) $row['user_id']);
            $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => (int) $row['id']]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        ActivityLogger::info('email_verified', ['user_id' => (int) $row['user_id']]);

        return [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'username' => (string) $row['username'],
        ];
    }

    public static function resendByEmail(string $email): ?string
    {
        $settings = PlatformSettings::emailVerificationSettings();
        if (!$settings['enabled']) {
            return null;
        }

        $user = User::findByEmail($email);
        if (!$user || User::isEmailVerified($user)) {
            return null;
        }

        return self::sendForUser((int) $user['id']);
    }

    private static function deliver(array $user, string $url, array $settings): void
    {
        $subject = (string) $settings['subject'];
        $body = "Hola " . (string) $user['username'] . ",\n\n";
        $body .= "Verifica tu correo abriendo este enlace:\n" . $url . "\n\n";
        $body .= "Si no creaste esta cuenta, ignora este mensaje.\n";
        $htmlBody = '<p>Hola ' . htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Verifica tu correo abriendo este enlace:</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Verificar correo</a></p>'
            . '<p>Si no creaste esta cuenta, ignora este mensaje.</p>';

        $sent = false;
        if ($settings['delivery'] === 'smtp') {
            Mailer::send($settings, (string) $user['email'], (string) $user['username'], $subject, $body, $htmlBody);
            $sent = true;
        }

        $context = [
            'user_id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'delivery' => (string) $settings['delivery'],
            'mail_sent' => $sent,
        ];
        if ($settings['delivery'] === 'log') {
            $context['url'] = $url;
        }

        ActivityLogger::info('email_verification_link', $context);
    }

    private static function hashToken(string $token): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', trim($token), $pepper);
    }
}
