<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class GameBuild
{
    private const CHANNELS = ['development', 'playtest', 'beta', 'stable', 'archived'];

    public static function ensureTables(): void
    {
        self::addColumnIfMissing('game_builds', 'size_bytes', 'BIGINT UNSIGNED NULL AFTER checksum');
        self::addColumnIfMissing('game_builds', 'executable_path', 'VARCHAR(255) NULL AFTER size_bytes');
    }

    public static function channels(): array
    {
        return self::CHANNELS;
    }

    public static function list(?int $gameId = null): array
    {
        self::ensureTables();
        $params = [];
        $where = '';
        if ($gameId !== null && $gameId > 0) {
            $where = 'WHERE b.game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT b.*, g.name AS game_name, g.slug AS game_slug
             FROM game_builds b
             INNER JOIN games g ON g.id = b.game_id
             ' . $where . '
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT 250'
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => self::payload($row), $stmt->fetchAll());
    }

    public static function latestForGame(int $gameId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT b.*, g.name AS game_name, g.slug AS game_slug
             FROM game_builds b
             INNER JOIN games g ON g.id = b.game_id
             WHERE b.game_id = :game_id
               AND b.channel IN ("stable", "beta", "playtest", "development")
             ORDER BY FIELD(b.channel, "stable", "beta", "playtest", "development"), b.published_at DESC, b.created_at DESC, b.id DESC
             LIMIT 1'
        );
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return is_array($row) ? self::payload($row) : null;
    }

    public static function latestForGames(array $gameIds): array
    {
        $builds = [];
        foreach (array_unique(array_map('intval', $gameIds)) as $gameId) {
            if ($gameId <= 0) {
                continue;
            }
            $build = self::latestForGame($gameId);
            if ($build !== null) {
                $builds[$gameId] = $build;
            }
        }

        return $builds;
    }

    public static function saveUpload(array $input, array $file): int
    {
        self::ensureTables();
        $data = self::validateInput($input);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Debes subir un archivo .zip valido.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload invalido.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('El .zip esta vacio.');
        }

        $original = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($original), '.zip')) {
            throw new RuntimeException('Solo se permiten builds .zip.');
        }

        $game = self::gameById($data['game_id']);
        if (!$game) {
            throw new RuntimeException('Juego invalido.');
        }

        $directory = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . (string) $game['slug'];
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta de builds.');
        }

        $filename = (string) $game['slug'] . '-' . self::safeFilename($data['version']) . '-' . $data['channel'] . '.zip';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('No se pudo guardar el .zip.');
        }

        $data['file_path'] = '/uploads/builds/' . (string) $game['slug'] . '/' . $filename;
        $data['checksum'] = hash_file('sha256', $target) ?: null;
        $data['size_bytes'] = filesize($target) ?: $size;

        return self::upsert($data);
    }

    public static function saveRemote(array $input): int
    {
        self::ensureTables();
        $data = self::validateInput($input);
        $filePath = trim((string) ($input['file_path'] ?? ''));
        if ($filePath === '' || (!filter_var($filePath, FILTER_VALIDATE_URL) && !str_starts_with($filePath, '/'))) {
            throw new RuntimeException('La URL o ruta del .zip no es valida.');
        }
        if (!str_ends_with(strtolower(parse_url($filePath, PHP_URL_PATH) ?: $filePath), '.zip')) {
            throw new RuntimeException('La build debe apuntar a un .zip.');
        }

        $data['file_path'] = $filePath;
        $data['checksum'] = trim((string) ($input['checksum'] ?? '')) ?: null;
        $data['size_bytes'] = (int) ($input['size_bytes'] ?? 0) > 0 ? (int) $input['size_bytes'] : null;

        return self::upsert($data);
    }

    public static function delete(int $buildId): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare('DELETE FROM game_builds WHERE id = :id');
        $stmt->execute(['id' => $buildId]);
    }

    private static function upsert(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_builds
                (game_id, version, channel, file_path, checksum, size_bytes, executable_path, notes, published_at, created_at)
             VALUES
                (:game_id, :version, :channel, :file_path, :checksum, :size_bytes, :executable_path, :notes, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                file_path = VALUES(file_path),
                checksum = VALUES(checksum),
                size_bytes = VALUES(size_bytes),
                executable_path = VALUES(executable_path),
                notes = VALUES(notes),
                published_at = VALUES(published_at)'
        );
        try {
            $stmt->execute($data);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe una build para ese juego, version y canal.');
            }
            throw $exception;
        }

        $id = (int) Database::pdo()->lastInsertId();
        if ($id > 0) {
            return $id;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id FROM game_builds WHERE game_id = :game_id AND version = :version AND channel = :channel LIMIT 1'
        );
        $stmt->execute([
            'game_id' => $data['game_id'],
            'version' => $data['version'],
            'channel' => $data['channel'],
        ]);

        return (int) $stmt->fetchColumn();
    }

    private static function validateInput(array $input): array
    {
        $gameId = (int) ($input['game_id'] ?? 0);
        $version = trim((string) ($input['version'] ?? ''));
        $channel = (string) ($input['channel'] ?? 'development');
        $executablePath = trim(str_replace('\\', '/', (string) ($input['executable_path'] ?? '')));
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($gameId <= 0) {
            throw new RuntimeException('Juego invalido.');
        }
        if ($version === '' || strlen($version) > 60) {
            throw new RuntimeException('Version invalida.');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new RuntimeException('Canal invalido.');
        }
        if ($executablePath === '' || str_starts_with($executablePath, '/') || str_contains($executablePath, '..')) {
            throw new RuntimeException('Debes indicar el ejecutable relativo dentro del .zip, por ejemplo Game.exe o Windows/Game.exe.');
        }
        if (!preg_match('#^[A-Za-z0-9._/\- ]+\.(exe|bat|cmd)$#i', $executablePath)) {
            throw new RuntimeException('El ejecutable debe terminar en .exe, .bat o .cmd.');
        }

        return [
            'game_id' => $gameId,
            'version' => $version,
            'channel' => $channel,
            'file_path' => null,
            'checksum' => null,
            'size_bytes' => null,
            'executable_path' => $executablePath,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    private static function payload(array $row): array
    {
        $filePath = (string) ($row['file_path'] ?? '');
        $downloadUrl = $filePath !== ''
            ? (filter_var($filePath, FILTER_VALIDATE_URL) ? $filePath : \url($filePath))
            : null;

        return [
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'game_name' => (string) ($row['game_name'] ?? ''),
            'game_slug' => (string) ($row['game_slug'] ?? ''),
            'version' => (string) $row['version'],
            'channel' => (string) $row['channel'],
            'file_path' => $filePath,
            'download_url' => $downloadUrl,
            'checksum' => $row['checksum'] ?? null,
            'size_bytes' => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
            'executable_path' => $row['executable_path'] ?? null,
            'notes' => $row['notes'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private static function gameById(int $gameId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM games WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();

        return is_array($game) ? $game : null;
    }

    private static function safeFilename(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? 'build';
        return trim($value, '-') ?: 'build';
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        if ((int) $stmt->fetchColumn() === 0) {
            Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
