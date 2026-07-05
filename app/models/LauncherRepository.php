<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class LauncherRepository
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS launcher_releases (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(60) NOT NULL,
                os VARCHAR(60) NOT NULL DEFAULT "windows",
                download_url VARCHAR(500) NOT NULL,
                checksum_sha256 VARCHAR(128) NULL,
                notes TEXT NULL,
                status ENUM("active", "inactive") NOT NULL DEFAULT "active",
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_launcher_releases_version_os (version, os),
                INDEX idx_launcher_releases_status (status),
                CONSTRAINT fk_launcher_releases_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function save(array $input, int $userId): int
    {
        self::ensureTables();
        $version = trim((string) ($input['launcher_version'] ?? ''));
        $os = strtolower(trim((string) ($input['launcher_os'] ?? 'windows')));
        $downloadUrl = trim((string) ($input['launcher_download_url'] ?? ''));
        $checksum = strtolower(trim((string) ($input['launcher_checksum_sha256'] ?? '')));
        $notes = trim((string) ($input['launcher_notes'] ?? ''));
        $status = (string) ($input['launcher_status'] ?? 'active');

        if ($version === '' || strlen($version) > 60) {
            throw new RuntimeException('Version del launcher invalida.');
        }
        if (!preg_match('/^[a-z0-9_.-]{2,60}$/', $os)) {
            throw new RuntimeException('Sistema operativo invalido.');
        }
        if (!filter_var($downloadUrl, FILTER_VALIDATE_URL) && !str_starts_with($downloadUrl, '/')) {
            throw new RuntimeException('URL de descarga del launcher invalida.');
        }
        if ($checksum !== '' && !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('SHA-256 invalido.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO launcher_releases (version, os, download_url, checksum_sha256, notes, status, created_by, created_at)
             VALUES (:version, :os, :download_url, :checksum_sha256, :notes, :status, :created_by, NOW())
             ON DUPLICATE KEY UPDATE
                download_url = VALUES(download_url),
                checksum_sha256 = VALUES(checksum_sha256),
                notes = VALUES(notes),
                status = VALUES(status),
                created_by = VALUES(created_by)'
        );
        $stmt->execute([
            'version' => $version,
            'os' => $os,
            'download_url' => $downloadUrl,
            'checksum_sha256' => $checksum !== '' ? $checksum : null,
            'notes' => $notes !== '' ? $notes : null,
            'status' => $status,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        if ($id > 0) {
            return $id;
        }

        $stmt = Database::pdo()->prepare('SELECT id FROM launcher_releases WHERE version = :version AND os = :os LIMIT 1');
        $stmt->execute(['version' => $version, 'os' => $os]);

        return (int) $stmt->fetchColumn();
    }

    public static function latest(string $os = 'windows'): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM launcher_releases
             WHERE os = :os AND status = "active"
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['os' => strtolower(trim($os)) ?: 'windows']);
        $row = $stmt->fetch();

        return is_array($row) ? self::payload($row) : null;
    }

    public static function all(): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->query('SELECT * FROM launcher_releases ORDER BY created_at DESC, id DESC LIMIT 100');

        return array_map(static fn (array $row): array => self::payload($row), $stmt->fetchAll());
    }

    private static function payload(array $row): array
    {
        $downloadUrl = (string) $row['download_url'];
        return [
            'id' => (int) $row['id'],
            'version' => (string) $row['version'],
            'os' => (string) $row['os'],
            'download_url' => filter_var($downloadUrl, FILTER_VALIDATE_URL) ? $downloadUrl : \url($downloadUrl),
            'checksum_sha256' => $row['checksum_sha256'] ?? null,
            'notes' => $row['notes'] ?? null,
            'status' => (string) $row['status'],
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
