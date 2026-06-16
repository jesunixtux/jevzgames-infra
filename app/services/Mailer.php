<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class Mailer
{
    public static function send(array $settings, string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        self::loadPhpMailer();

        $smtp = $settings['smtp'] ?? [];
        $host = trim((string) ($smtp['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('SMTP no esta configurado.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = max(1, (int) ($smtp['port'] ?? 587));
            $mail->SMTPAuth = !empty($smtp['auth']);
            $mail->Timeout = max(1, (int) ($smtp['timeout'] ?? 15));

            $encryption = (string) ($smtp['encryption'] ?? 'tls');
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            if ($mail->SMTPAuth) {
                $mail->Username = (string) ($smtp['username'] ?? '');
                $mail->Password = (string) ($smtp['password'] ?? '');
            }

            $mail->setFrom((string) $settings['from'], (string) ($settings['from_name'] ?? 'JevzGames'));
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;

            if ($htmlBody !== null && $htmlBody !== '') {
                $mail->isHTML(true);
                $mail->Body = $htmlBody;
                $mail->AltBody = $textBody;
            } else {
                $mail->Body = $textBody;
            }

            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new RuntimeException('No se pudo enviar el correo por SMTP: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private static function loadPhpMailer(): void
    {
        if (class_exists(PHPMailer::class)) {
            return;
        }

        $base = ROOT_PATH . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'src';
        $required = [
            $base . DIRECTORY_SEPARATOR . 'Exception.php',
            $base . DIRECTORY_SEPARATOR . 'PHPMailer.php',
            $base . DIRECTORY_SEPARATOR . 'SMTP.php',
        ];

        foreach ($required as $file) {
            if (!is_file($file)) {
                throw new RuntimeException('PHPMailer no esta instalado en /phpmailer.');
            }
            require_once $file;
        }
    }
}
