<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class Achievement
{
    private const STATUSES = ['active', 'hidden', 'disabled'];
    private const MODES = ['set', 'add', 'unlock'];

    public static function ensureTables(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS game_achievements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                code VARCHAR(100) NOT NULL,
                title VARCHAR(160) NOT NULL,
                description TEXT NULL,
                image_path VARCHAR(255) NULL,
                locked_image_path VARCHAR(255) NULL,
                points INT UNSIGNED NOT NULL DEFAULT 0,
                goal_value DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM("active", "hidden", "disabled") NOT NULL DEFAULT "active",
                reward_json LONGTEXT NULL,
                config_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_game_achievements_game_code (game_id, code),
                INDEX idx_game_achievements_game (game_id),
                INDEX idx_game_achievements_status (status),
                CONSTRAINT fk_game_achievements_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::addColumnIfMissing('game_achievements', 'image_path', 'VARCHAR(255) NULL AFTER description');
        self::addColumnIfMissing('game_achievements', 'locked_image_path', 'VARCHAR(255) NULL AFTER image_path');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_achievements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                game_id INT UNSIGNED NOT NULL,
                achievement_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                progress_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                progress_json LONGTEXT NULL,
                unlocked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_achievements_user_achievement (user_id, achievement_id),
                INDEX idx_user_achievements_game_user (game_id, user_id),
                INDEX idx_user_achievements_unlocked (unlocked_at),
                CONSTRAINT fk_user_achievements_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES game_achievements(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function list(?int $gameId = null): array
    {
        self::ensureTables();
        $params = [];
        $where = '';
        if ($gameId !== null && $gameId > 0) {
            $where = 'WHERE a.game_id = :game_id';
            $params['game_id'] = $gameId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT a.*, g.name AS game_name, g.slug AS game_slug,
                    progress.unlocked_count, progress.player_count
             FROM game_achievements a
             INNER JOIN games g ON g.id = a.game_id
             LEFT JOIN (
                 SELECT achievement_id,
                        SUM(CASE WHEN unlocked_at IS NULL THEN 0 ELSE 1 END) AS unlocked_count,
                        COUNT(*) AS player_count
                 FROM user_achievements
                 GROUP BY achievement_id
             ) progress ON progress.achievement_id = a.id
             ' . $where . '
             ORDER BY g.name ASC, a.status ASC, a.title ASC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function find(int $achievementId): ?array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare('SELECT * FROM game_achievements WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $achievementId]);
        $achievement = $stmt->fetch();

        return is_array($achievement) ? $achievement : null;
    }

    public static function save(array $input): int
    {
        self::ensureTables();
        $data = self::validatedInput($input);
        $pdo = Database::pdo();

        if ($data['id'] > 0) {
            $stmt = $pdo->prepare(
                'UPDATE game_achievements
                 SET game_id = :game_id,
                     code = :code,
                     title = :title,
                     description = :description,
                     image_path = :image_path,
                     locked_image_path = :locked_image_path,
                     points = :points,
                     goal_value = :goal_value,
                     is_secret = :is_secret,
                     status = :status,
                     reward_json = :reward_json,
                     config_json = :config_json,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            try {
                $stmt->execute($data);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Ya existe un logro con ese codigo para este juego.');
                }
                throw $exception;
            }

            return $data['id'];
        }

        $insertData = $data;
        unset($insertData['id']);
        $stmt = $pdo->prepare(
            'INSERT INTO game_achievements
                (game_id, code, title, description, image_path, locked_image_path, points, goal_value, is_secret, status, reward_json, config_json, created_at, updated_at)
             VALUES
                (:game_id, :code, :title, :description, :image_path, :locked_image_path, :points, :goal_value, :is_secret, :status, :reward_json, :config_json, NOW(), NOW())'
        );
        try {
            $stmt->execute($insertData);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe un logro con ese codigo para este juego.');
            }
            throw $exception;
        }

        return (int) $pdo->lastInsertId();
    }

    public static function updateStatus(int $achievementId, string $status): void
    {
        self::ensureTables();
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Estado de logro invalido.');
        }

        $stmt = Database::pdo()->prepare('UPDATE game_achievements SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $achievementId,
            'status' => $status,
        ]);
    }

    public static function listForPlayer(int $gameId, int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.code, a.title, a.description, a.points, a.goal_value, a.is_secret, a.status,
                    a.reward_json, a.config_json,
                    ua.progress_value, ua.progress_json, ua.unlocked_at, ua.updated_at AS progress_updated_at
             FROM game_achievements a
             LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = :user_id
             WHERE a.game_id = :game_id
               AND a.status IN ("active", "hidden")
             ORDER BY a.title ASC'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
        ]);

        return array_map(static function (array $row): array {
            return self::publicPayload($row);
        }, $stmt->fetchAll());
    }

    public static function unlockedForUser(int $userId): array
    {
        self::ensureTables();
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.code, a.title, a.description, a.points, a.goal_value, a.is_secret, a.status,
                    a.reward_json, a.config_json,
                    ua.progress_value, ua.progress_json, ua.unlocked_at, ua.updated_at AS progress_updated_at,
                    g.id AS game_id, g.name AS game_name, g.slug AS game_slug
             FROM user_achievements ua
             INNER JOIN game_achievements a ON a.id = ua.achievement_id
             INNER JOIN games g ON g.id = ua.game_id
             WHERE ua.user_id = :user_id
               AND ua.unlocked_at IS NOT NULL
               AND a.status IN ("active", "hidden")
               AND g.status IN ("development", "playtest", "beta", "published")
             ORDER BY ua.unlocked_at DESC, ua.id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static function (array $row): array {
            $payload = self::publicPayload($row);
            $payload['game'] = [
                'id' => (int) $row['game_id'],
                'name' => (string) $row['game_name'],
                'slug' => (string) $row['game_slug'],
            ];
            return $payload;
        }, $stmt->fetchAll());
    }

    public static function recordProgress(int $gameId, int $userId, string $code, float $value, string $mode = 'set', array $progress = []): array
    {
        self::ensureTables();
        $code = self::normalizeCode($code);
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('Modo de progreso invalido.');
        }

        $achievement = self::findActiveByCode($gameId, $code);
        if (!$achievement) {
            throw new RuntimeException('Logro no encontrado o desactivado.');
        }

        $existing = self::playerProgress((int) $achievement['id'], $userId);
        $current = $existing ? (float) $existing['progress_value'] : 0.0;
        $goal = max(1.0, (float) $achievement['goal_value']);
        $next = match ($mode) {
            'add' => $current + max(0.0, $value),
            'unlock' => $goal,
            default => max(0.0, $value),
        };
        $next = min($next, $goal);
        $unlockedAt = $existing['unlocked_at'] ?? null;
        $justUnlocked = false;

        if ($unlockedAt === null && $next >= $goal) {
            $unlockedAt = date('Y-m-d H:i:s');
            $justUnlocked = true;
        }

        $progressJson = $progress !== []
            ? json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : ($existing['progress_json'] ?? null);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_achievements
                (game_id, achievement_id, user_id, progress_value, progress_json, unlocked_at, created_at, updated_at)
             VALUES
                (:game_id, :achievement_id, :user_id, :progress_value, :progress_json, :unlocked_at, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                progress_value = VALUES(progress_value),
                progress_json = VALUES(progress_json),
                unlocked_at = COALESCE(user_achievements.unlocked_at, VALUES(unlocked_at)),
                updated_at = NOW()'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'achievement_id' => (int) $achievement['id'],
            'user_id' => $userId,
            'progress_value' => $next,
            'progress_json' => $progressJson,
            'unlocked_at' => $unlockedAt,
        ]);

        $fresh = self::playerRow((int) $achievement['id'], $userId);
        return [
            'achievement' => self::publicPayload(array_merge($achievement, $fresh ?? [])),
            'just_unlocked' => $justUnlocked,
        ];
    }

    private static function validatedInput(array $input): array
    {
        $id = (int) ($input['achievement_id'] ?? 0);
        $gameId = (int) ($input['game_id'] ?? 0);
        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $points = (int) ($input['points'] ?? 0);
        $imagePath = self::cleanAssetPath((string) ($input['image_path'] ?? ''));
        $lockedImagePath = self::cleanAssetPath((string) ($input['locked_image_path'] ?? ''));
        $goal = (float) ($input['goal_value'] ?? 1);
        $isSecret = isset($input['is_secret']) ? 1 : 0;
        $status = trim((string) ($input['status'] ?? 'active'));
        $rewardJson = self::cleanJson((string) ($input['reward_json'] ?? ''));
        $configJson = self::cleanJson((string) ($input['config_json'] ?? ''));

        if ($gameId <= 0 || !self::gameExists($gameId)) {
            throw new RuntimeException('El juego asociado no existe.');
        }

        if (!preg_match('/^[a-z0-9_.:-]{2,100}$/', $code)) {
            throw new RuntimeException('El codigo del logro debe usar minusculas, numeros, guion, punto, dos puntos o guion bajo.');
        }

        if ($title === '' || strlen($title) > 160) {
            throw new RuntimeException('El titulo del logro debe tener entre 1 y 160 caracteres.');
        }

        if ($description !== '' && strlen($description) > 5000) {
            throw new RuntimeException('La descripcion del logro es demasiado larga.');
        }

        if ($points < 0 || $points > 1000000) {
            throw new RuntimeException('Los puntos deben estar entre 0 y 1000000.');
        }

        if ($goal <= 0 || $goal > 1000000000) {
            throw new RuntimeException('La meta debe ser mayor que 0.');
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Estado de logro invalido.');
        }

        return [
            'id' => $id,
            'game_id' => $gameId,
            'code' => $code,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'image_path' => $imagePath,
            'locked_image_path' => $lockedImagePath,
            'points' => $points,
            'goal_value' => $goal,
            'is_secret' => $isSecret,
            'status' => $status,
            'reward_json' => $rewardJson,
            'config_json' => $configJson,
        ];
    }

    private static function findActiveByCode(int $gameId, string $code): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM game_achievements
             WHERE game_id = :game_id AND code = :code AND status IN ("active", "hidden")
             LIMIT 1'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'code' => $code,
        ]);
        $achievement = $stmt->fetch();

        return is_array($achievement) ? $achievement : null;
    }

    private static function playerProgress(int $achievementId, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM user_achievements
             WHERE achievement_id = :achievement_id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'achievement_id' => $achievementId,
            'user_id' => $userId,
        ]);
        $progress = $stmt->fetch();

        return is_array($progress) ? $progress : null;
    }

    private static function playerRow(int $achievementId, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT progress_value, progress_json, unlocked_at, updated_at AS progress_updated_at
             FROM user_achievements
             WHERE achievement_id = :achievement_id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'achievement_id' => $achievementId,
            'user_id' => $userId,
        ]);
        $progress = $stmt->fetch();

        return is_array($progress) ? $progress : null;
    }

    private static function publicPayload(array $row): array
    {
        $goal = max(1.0, (float) ($row['goal_value'] ?? 1));
        $progress = min($goal, max(0.0, (float) ($row['progress_value'] ?? 0)));

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'description' => $row['description'] ?? null,
            'image_path' => $row['image_path'] ?? null,
            'locked_image_path' => $row['locked_image_path'] ?? null,
            'points' => (int) $row['points'],
            'goal_value' => $goal,
            'progress_value' => $progress,
            'progress_percent' => round(($progress / $goal) * 100, 2),
            'is_secret' => (bool) $row['is_secret'],
            'status' => (string) $row['status'],
            'unlocked' => !empty($row['unlocked_at']),
            'unlocked_at' => $row['unlocked_at'] ?? null,
            'reward' => Game::decodeJson($row['reward_json'] ?? null),
            'config' => Game::decodeJson($row['config_json'] ?? null),
            'progress' => Game::decodeJson($row['progress_json'] ?? null),
        ];
    }

    private static function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    private static function cleanJson(string $json): ?string
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('Uno de los campos JSON del logro no es valido.');
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function cleanAssetPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return strlen($path) <= 255 ? $path : throw new RuntimeException('La URL de imagen del logro es demasiado larga.');
        }

        if (!preg_match('#^/?[A-Za-z0-9._/\-]+$#', $path) || strlen($path) > 255) {
            throw new RuntimeException('La ruta de imagen del logro no es valida.');
        }

        return $path;
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

    private static function gameExists(int $gameId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
