<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) \app_config('database.host', '');
        $database = (string) \app_config('database.name', '');
        $user = (string) \app_config('database.user', '');
        $password = (string) \app_config('database.password', '');
        $port = (int) \app_config('database.port', 3306);
        $charset = (string) \app_config('database.charset', 'utf8mb4');

        if ($host === '' || $database === '' || $user === '') {
            throw new RuntimeException('La base de datos no esta configurada.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        self::$pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
