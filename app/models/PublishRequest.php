<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class PublishRequest
{
    public static function ensureTables(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS game_publish_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                game_id INT UNSIGNED NULL,
                name VARCHAR(140) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                description TEXT NULL,
                website_url VARCHAR(255) NULL,
                contact_email VARCHAR(190) NULL,
                build_url VARCHAR(255) NULL,
                status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
                reviewer_user_id INT UNSIGNED NULL,
                review_note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                INDEX idx_game_publish_requests_user (user_id),
                INDEX idx_game_publish_requests_status (status),
                CONSTRAINT fk_game_publish_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_game_publish_requests_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL,
                CONSTRAINT fk_game_publish_requests_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function submit(int $userId, array $input): int
    {
        self::ensureTables();
        $data = self::validate($input);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_publish_requests
                (user_id, name, slug, description, website_url, contact_email, build_url, status, created_at)
             VALUES
                (:user_id, :name, :slug, :description, :website_url, :contact_email, :build_url, "pending", NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            ...$data,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function mine(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, g.name AS approved_game_name, g.slug AS approved_game_slug
             FROM game_publish_requests r
             LEFT JOIN games g ON g.id = r.game_id
             WHERE r.user_id = :user_id
             ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public static function all(string $status = 'pending'): array
    {
        self::ensureTables();
        $params = [];
        $where = '';
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where = 'WHERE r.status = :status';
            $params['status'] = $status;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT r.*, u.username, g.slug AS approved_game_slug
             FROM game_publish_requests r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN games g ON g.id = r.game_id
             ' . $where . '
             ORDER BY FIELD(r.status, "pending", "approved", "rejected"), r.created_at DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function approve(int $requestId, int $reviewerUserId): int
    {
        self::ensureTables();
        Game::ensureVisibilityColumn();
        $request = self::find($requestId);
        if (!$request || $request['status'] !== 'pending') {
            throw new RuntimeException('Solicitud no encontrada o ya revisada.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO games (owner_user_id, name, slug, description, status, visibility, source_type, current_version, config_json, created_at, updated_at)
                 VALUES (:owner_user_id, :name, :slug, :description, "development", "private", "external", "0.1.0", :config_json, NOW(), NOW())'
            );
            $config = [
                'submitted_from' => 'publish-on-games',
                'website_url' => $request['website_url'] ?? null,
                'build_url' => $request['build_url'] ?? null,
            ];
            try {
                $stmt->execute([
                    'owner_user_id' => (int) $request['user_id'],
                    'name' => (string) $request['name'],
                    'slug' => (string) $request['slug'],
                    'description' => $request['description'] ?? null,
                    'config_json' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Ya existe un juego con ese slug.');
                }
                throw $exception;
            }

            $gameId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare(
                'UPDATE game_publish_requests
                 SET status = "approved", game_id = :game_id, reviewer_user_id = :reviewer_user_id, reviewed_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $requestId,
                'game_id' => $gameId,
                'reviewer_user_id' => $reviewerUserId,
            ]);
            $pdo->commit();

            return $gameId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function reject(int $requestId, int $reviewerUserId, string $note): void
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'UPDATE game_publish_requests
             SET status = "rejected", reviewer_user_id = :reviewer_user_id, review_note = :review_note, reviewed_at = NOW()
             WHERE id = :id AND status = "pending"'
        );
        $stmt->execute([
            'id' => $requestId,
            'reviewer_user_id' => $reviewerUserId,
            'review_note' => trim($note) !== '' ? trim($note) : null,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Solicitud no encontrada o ya revisada.');
        }
    }

    private static function find(int $requestId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM game_publish_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private static function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $description = trim((string) ($input['description'] ?? ''));
        $websiteUrl = trim((string) ($input['website_url'] ?? ''));
        $contactEmail = trim((string) ($input['contact_email'] ?? ''));
        $buildUrl = trim((string) ($input['build_url'] ?? ''));

        if ($name === '' || strlen($name) > 140) {
            throw new RuntimeException('El nombre del juego debe tener entre 1 y 140 caracteres.');
        }

        if ($slug === '') {
            $slug = 'ext-' . bin2hex(random_bytes(8));
        }

        if (!preg_match('/^[a-z0-9-]{2,160}$/', $slug)) {
            throw new RuntimeException('El slug debe usar minusculas, numeros y guiones.');
        }

        if ($description !== '' && strlen($description) > 5000) {
            throw new RuntimeException('La descripcion es demasiado larga.');
        }

        foreach (['website_url' => $websiteUrl, 'build_url' => $buildUrl] as $label => $url) {
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException($label . ' no es una URL valida.');
            }
        }

        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El email de contacto no es valido.');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'website_url' => $websiteUrl !== '' ? $websiteUrl : null,
            'contact_email' => $contactEmail !== '' ? $contactEmail : null,
            'build_url' => $buildUrl !== '' ? $buildUrl : null,
        ];
    }
}
