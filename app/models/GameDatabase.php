<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class GameDatabase
{
    public static function gameById(int $gameId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM games WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();

        return is_array($game) ? $game : null;
    }

    public static function configFromGame(array $game): array
    {
        $config = Game::decodeJson($game['external_database_json'] ?? null);

        return [
            'enabled' => filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'driver' => 'mysql',
            'host' => trim((string) ($config['host'] ?? '')),
            'port' => (int) ($config['port'] ?? 3306),
            'database' => trim((string) ($config['database'] ?? '')),
            'user' => trim((string) ($config['user'] ?? '')),
            'password' => (string) ($config['password'] ?? ''),
            'charset' => trim((string) ($config['charset'] ?? 'utf8mb4')),
        ];
    }

    public static function publicStatusFromGame(array $game): array
    {
        $config = self::configFromGame($game);

        return [
            'enabled' => (bool) $config['enabled'],
            'configured' => self::hasMinimumConfig($config),
            'driver' => 'mysql',
        ];
    }

    public static function testForGameId(int $gameId): array
    {
        $game = self::gameById($gameId);
        if (!$game) {
            throw new RuntimeException('El juego indicado no existe.');
        }

        $config = self::configFromGame($game);
        $public = self::publicStatusFromGame($game);

        if (!$config['enabled']) {
            return $public + [
                'connected' => false,
                'message' => 'La base dedicada esta desactivada para este juego.',
            ];
        }

        if (!self::hasMinimumConfig($config)) {
            return $public + [
                'connected' => false,
                'message' => 'Faltan host, database o user en external_database_json.',
            ];
        }

        try {
            $pdo = self::pdoFromConfig($config);
            $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            return $public + [
                'connected' => true,
                'database' => $config['database'],
                'server_version' => $serverVersion,
                'message' => 'Conexion OK.',
            ];
        } catch (\Throwable $exception) {
            return $public + [
                'connected' => false,
                'database' => $config['database'],
                'message' => $exception->getMessage(),
            ];
        }
    }

    public static function dedicatedPdoForGame(array $game): ?PDO
    {
        $config = self::configFromGame($game);
        if (!$config['enabled'] || !self::hasMinimumConfig($config)) {
            return null;
        }

        return self::pdoFromConfig($config);
    }

    public static function dedicatedPdoForGameId(int $gameId): ?PDO
    {
        $game = self::gameById($gameId);
        if (!$game) {
            return null;
        }

        return self::dedicatedPdoForGame($game);
    }

    private static function hasMinimumConfig(array $config): bool
    {
        return $config['host'] !== '' && $config['database'] !== '' && $config['user'] !== '';
    }

    private static function pdoFromConfig(array $config): PDO
    {
        $charset = $config['charset'] !== '' ? $config['charset'] : 'utf8mb4';
        $port = max(1, (int) $config['port']);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $port,
            $config['database'],
            $charset
        );

        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
