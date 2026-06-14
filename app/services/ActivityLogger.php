<?php
declare(strict_types=1);

namespace App\Services;

final class ActivityLogger
{
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    private static function write(string $level, string $event, array $context): void
    {
        $logDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir) || !is_writable($logDir)) {
            return;
        }

        $line = json_encode([
            'time' => date('c'),
            'level' => $level,
            'event' => $event,
            'context' => $context,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line !== false) {
            file_put_contents($logDir . DIRECTORY_SEPARATOR . 'app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
