<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\ActivityLogger;
use App\Services\Mailer;
use RuntimeException;

final class PasswordReset
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash VARCHAR(128) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                request_ip VARCHAR(45) NULL,
                INDEX idx_password_reset_tokens_user (user_id),
                INDEX idx_password_reset_tokens_expires (expires_at),
                CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function requestForEmail(string $email): ?string
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = User::findByEmail($email);
        if (!$user || ($user['status'] ?? '') === 'blocked') {
            ActivityLogger::info('password_reset_requested', [
                'email' => $email,
                'matched_user' => false,
            ]);
            return null;
        }

        self::ensureTables();
        Database::pdo()->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id AND used_at IS NULL'
        )->execute(['user_id' => (int) $user['id']]);

        $settings = PlatformSettings::emailVerificationSettings();
        $ttlHours = min(24, max(1, (int) ($settings['ttl_hours'] ?? 2)));
        $token = 'jvg_pr_' . bin2hex(random_bytes(32));

        $stmt = Database::pdo()->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at, request_ip)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL ' . $ttlHours . ' HOUR), NOW(), :request_ip)'
        );
        $stmt->execute([
            'user_id' => (int) $user['id'],
            'token_hash' => self::hashToken($token),
            'request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $url = \url('/reset-password/?token=' . rawurlencode($token));
        try {
            self::deliver($user, $url, $settings, $ttlHours);
        } catch (\Throwable $exception) {
            ActivityLogger::error('password_reset_delivery_failed', [
                'user_id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'delivery' => (string) ($settings['delivery'] ?? 'log'),
                'error' => $exception->getMessage(),
            ]);
        }

        return $url;
    }

    public static function tokenInfo(string $token): array
    {
        $row = self::validTokenRow($token);

        return [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'username' => (string) $row['username'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    public static function resetWithToken(string $token, string $password): array
    {
        if (strlen($password) < 8) {
            throw new RuntimeException('La contrasena debe tener al menos 8 caracteres.');
        }

        $row = self::validTokenRow($token);
        if (($row['status'] ?? '') === 'blocked') {
            throw new RuntimeException('Esta cuenta esta suspendida.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            User::updatePassword((int) $row['user_id'], $password);
            User::markEmailVerified((int) $row['user_id']);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $row['id']]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        ActivityLogger::info('password_reset_completed', [
            'user_id' => (int) $row['user_id'],
        ]);

        return [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'username' => (string) $row['username'],
        ];
    }

    private static function validTokenRow(string $token): array
    {
        self::ensureTables();
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('El enlace de recuperacion es requerido.');
        }

        Database::pdo()->prepare('DELETE FROM password_reset_tokens WHERE expires_at < NOW() AND used_at IS NULL')->execute();
        $stmt = Database::pdo()->prepare(
            'SELECT prt.*, u.email, u.username, u.status
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = :token_hash
               AND prt.used_at IS NULL
               AND prt.expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken($token)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El enlace de recuperacion no existe o expiro.');
        }

        return $row;
    }

    private static function deliver(array $user, string $url, array $settings, int $ttlHours): void
    {
        $subject = \i18n_text('Restablece tu contrasena en JevzGames', 'Reset your JevzGames password');
        $body = "Hola " . (string) $user['username'] . ",\n\n";
        $body .= "Recibimos una solicitud para restablecer tu contrasena.\n";
        $body .= "Abre este enlace para crear una contrasena nueva:\n" . $url . "\n\n";
        $body .= "Este enlace expira en " . $ttlHours . " hora(s).\n";
        $body .= "Si no solicitaste este cambio, ignora este mensaje.\n";
        $htmlBody = '<p>Hola ' . htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Recibimos una solicitud para restablecer tu contrasena.</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Crear contrasena nueva</a></p>'
            . '<p>Este enlace expira en ' . $ttlHours . ' hora(s).</p>'
            . '<p>Si no solicitaste este cambio, ignora este mensaje.</p>';

        $sent = false;
        if (($settings['delivery'] ?? 'log') === 'smtp') {
            Mailer::send($settings, (string) $user['email'], (string) $user['username'], $subject, $body, $htmlBody);
            $sent = true;
        }

        $context = [
            'user_id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'delivery' => (string) ($settings['delivery'] ?? 'log'),
            'mail_sent' => $sent,
        ];
        if (($settings['delivery'] ?? 'log') === 'log') {
            $context['url'] = $url;
        }

        ActivityLogger::info('password_reset_link', $context);
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
