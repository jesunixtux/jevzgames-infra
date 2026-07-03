<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class Admin
{
    private const GAME_STATUSES = ['development', 'playtest', 'beta', 'published', 'archived'];
    private const GAME_VISIBILITIES = ['public', 'unlisted', 'private'];
    private const USER_STATUSES = ['active', 'blocked', 'pending_recovery'];

    public static function dashboardStats(): array
    {
        $pdo = Database::pdo();

        return [
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'blocked_users' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "blocked"')->fetchColumn(),
            'games' => (int) $pdo->query('SELECT COUNT(*) FROM games')->fetchColumn(),
            'published_games' => (int) $pdo->query('SELECT COUNT(*) FROM games WHERE status = "published"')->fetchColumn(),
            'open_tickets' => (int) $pdo->query('SELECT COUNT(*) FROM support_tickets WHERE status = "open"')->fetchColumn(),
            'active_codes' => (int) $pdo->query('SELECT COUNT(*) FROM redeemable_codes WHERE status = "active"')->fetchColumn(),
        ];
    }

    public static function users(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT u.id, u.username, u.email, u.status, u.created_at, u.last_login_at,
                    GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ",") AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id, u.username, u.email, u.status, u.created_at, u.last_login_at
             ORDER BY u.id ASC
             LIMIT 250'
        );

        return array_map(static function (array $row): array {
            $row['roles'] = $row['roles'] !== null && $row['roles'] !== ''
                ? explode(',', (string) $row['roles'])
                : [];
            $row['protected'] = count(array_intersect($row['roles'], ['admin', 'superroot'])) > 0;
            return $row;
        }, $stmt->fetchAll());
    }

    public static function updateUserStatus(int $targetUserId, string $status): void
    {
        if (!in_array($status, self::USER_STATUSES, true)) {
            throw new RuntimeException('Estado de usuario invalido.');
        }

        $target = User::findByIdWithRoles($targetUserId);
        if (!$target) {
            throw new RuntimeException('El usuario indicado no existe.');
        }

        if (count(array_intersect($target['roles'] ?? [], ['admin', 'superroot'])) > 0) {
            throw new RuntimeException('Las cuentas admin y superroot solo se gestionan desde Superroot.');
        }

        $stmt = Database::pdo()->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'id' => $targetUserId,
        ]);
    }

    public static function games(): array
    {
        Game::ensureVisibilityColumn();
        $stmt = Database::pdo()->query(
            'SELECT g.*, u.username AS owner_username
             FROM games g
             LEFT JOIN users u ON u.id = g.owner_user_id
             ORDER BY FIELD(g.visibility, "public", "unlisted", "private"),
                      FIELD(g.status, "published", "beta", "playtest", "development", "archived"),
                      g.name ASC'
        );

        return $stmt->fetchAll();
    }

    public static function apiKeys(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT k.id, k.game_id, k.public_key, k.status, k.last_used_at, k.created_at, k.revoked_at,
                    g.name AS game_name, g.slug AS game_slug
             FROM game_api_keys k
             INNER JOIN games g ON g.id = k.game_id
             ORDER BY k.created_at DESC, k.id DESC
             LIMIT 250'
        );

        return $stmt->fetchAll();
    }

    public static function findGame(int $gameId): ?array
    {
        Game::ensureVisibilityColumn();
        $stmt = Database::pdo()->prepare('SELECT * FROM games WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $gameId]);
        $game = $stmt->fetch();

        return is_array($game) ? $game : null;
    }

    public static function saveGame(array $input): int
    {
        $data = self::validatedGameInput($input);
        $pdo = Database::pdo();
        Game::ensureVisibilityColumn();

        if ($data['id'] > 0) {
            $stmt = $pdo->prepare(
                'UPDATE games
                 SET name = :name,
                     slug = :slug,
                     description = :description,
                     status = :status,
                     visibility = :visibility,
                     current_version = :current_version,
                     config_json = :config_json,
                     endpoints_json = :endpoints_json,
                     external_database_json = :external_database_json,
                     cdn_json = :cdn_json,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            try {
                $stmt->execute($data);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Ya existe un juego con ese slug.');
                }
                throw $exception;
            }

            return $data['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO games (name, slug, description, status, visibility, current_version, config_json, endpoints_json, external_database_json, cdn_json, created_at, updated_at)
             VALUES (:name, :slug, :description, :status, :visibility, :current_version, :config_json, :endpoints_json, :external_database_json, :cdn_json, NOW(), NOW())'
        );

        $insertData = $data;
        unset($insertData['id']);
        try {
            $stmt->execute($insertData);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe un juego con ese slug.');
            }
            throw $exception;
        }

        return (int) $pdo->lastInsertId();
    }

    public static function updateGameStatus(int $gameId, string $status): void
    {
        if (!in_array($status, self::GAME_STATUSES, true)) {
            throw new RuntimeException('Estado de juego invalido.');
        }

        $stmt = Database::pdo()->prepare('UPDATE games SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'id' => $gameId,
        ]);
    }

    public static function updateGameVisibility(int $gameId, string $visibility): void
    {
        Game::ensureVisibilityColumn();
        if (!in_array($visibility, self::GAME_VISIBILITIES, true)) {
            throw new RuntimeException('Visibilidad de juego invalida.');
        }

        $stmt = Database::pdo()->prepare('UPDATE games SET visibility = :visibility, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'visibility' => $visibility,
            'id' => $gameId,
        ]);
    }

    public static function createGameApiKey(int $gameId): array
    {
        if ($gameId <= 0 || !self::gameExists($gameId)) {
            throw new RuntimeException('El juego asociado no existe.');
        }

        $publicKey = 'jvg_pk_' . bin2hex(random_bytes(12));
        $secretKey = 'jvg_sk_' . bin2hex(random_bytes(24));

        $stmt = Database::pdo()->prepare(
            'INSERT INTO game_api_keys (game_id, public_key, secret_key_hash, status, created_at)
             VALUES (:game_id, :public_key, :secret_key_hash, "active", NOW())'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'public_key' => $publicKey,
            'secret_key_hash' => password_hash($secretKey, PASSWORD_DEFAULT),
        ]);

        return [
            'id' => (int) Database::pdo()->lastInsertId(),
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
        ];
    }

    public static function revokeGameApiKey(int $apiKeyId): void
    {
        if ($apiKeyId <= 0) {
            throw new RuntimeException('API key invalida.');
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE game_api_keys
             SET status = "revoked", revoked_at = NOW()
             WHERE id = :id AND status = "active"'
        );
        $stmt->execute(['id' => $apiKeyId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('La API key no existe o ya estaba revocada.');
        }
    }

    public static function testGameDatabase(int $gameId): array
    {
        return GameDatabase::testForGameId($gameId);
    }

    public static function codes(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT c.*, g.name AS game_name, u.username AS creator_username
             FROM redeemable_codes c
             LEFT JOIN games g ON g.id = c.game_id
             LEFT JOIN users u ON u.id = c.created_by
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 250'
        );

        return $stmt->fetchAll();
    }

    public static function createCode(array $input, int $creatorId): array
    {
        $data = self::validatedCodeInput($input);
        $code = self::normalizeCode($data['code'] !== '' ? $data['code'] : self::generateCode());
        $hash = self::codeHash($code);
        $preview = self::codePreview($code);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO redeemable_codes (game_id, code_hash, code_preview, reward_type, reward_json, max_uses, current_uses, expires_at, status, created_by, created_at)
             VALUES (:game_id, :code_hash, :code_preview, :reward_type, :reward_json, :max_uses, 0, :expires_at, :status, :created_by, NOW())'
        );

        try {
            $stmt->execute([
                'game_id' => $data['game_id'],
                'code_hash' => $hash,
                'code_preview' => $preview,
                'reward_type' => $data['reward_type'],
                'reward_json' => $data['reward_json'],
                'max_uses' => $data['max_uses'],
                'expires_at' => $data['expires_at'],
                'status' => $data['status'],
                'created_by' => $creatorId,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ese codigo ya existe.');
            }
            throw $exception;
        }

        return [
            'id' => (int) Database::pdo()->lastInsertId(),
            'code' => $code,
        ];
    }

    public static function updateCodeStatus(int $codeId, string $status): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Estado de codigo invalido.');
        }

        $stmt = Database::pdo()->prepare('UPDATE redeemable_codes SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'id' => $codeId,
        ]);
    }

    public static function supportTickets(string $status = 'open'): array
    {
        return Support::listTickets($status);
    }

    public static function activityLogs(int $limit = 80): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.*, u.username
             FROM activity_logs l
             LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function appLogLines(int $limit = 40): array
    {
        $path = STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_slice($lines, -max(1, min(200, $limit)));
    }

    public static function gameStatuses(): array
    {
        return self::GAME_STATUSES;
    }

    public static function gameVisibilities(): array
    {
        return self::GAME_VISIBILITIES;
    }

    private static function validatedGameInput(array $input): array
    {
        Game::ensureVisibilityColumn();
        $id = (int) ($input['game_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $description = trim((string) ($input['description'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'development'));
        $visibility = trim((string) ($input['visibility'] ?? 'public'));
        $currentVersion = trim((string) ($input['current_version'] ?? ''));
        $configJson = self::cleanJson((string) ($input['config_json'] ?? ''));
        $endpointsJson = self::cleanJson((string) ($input['endpoints_json'] ?? ''));
        $externalDatabaseJson = self::cleanJson((string) ($input['external_database_json'] ?? ''));
        $cdnJson = self::cleanJson((string) ($input['cdn_json'] ?? ''));

        if ($name === '' || strlen($name) > 140) {
            throw new RuntimeException('El nombre del juego debe tener entre 1 y 140 caracteres.');
        }

        if (!preg_match('/^[a-z0-9-]{2,160}$/', $slug)) {
            throw new RuntimeException('El slug debe usar minusculas, numeros y guiones.');
        }

        if ($description !== '' && strlen($description) > 5000) {
            throw new RuntimeException('La descripcion del juego es demasiado larga.');
        }

        if (!in_array($status, self::GAME_STATUSES, true)) {
            throw new RuntimeException('Estado de juego invalido.');
        }

<<<<<<< Updated upstream
        if (!in_array($visibility, Game::visibilityOptions(), true)) {
=======
        if (!in_array($visibility, self::GAME_VISIBILITIES, true)) {
>>>>>>> Stashed changes
            throw new RuntimeException('Visibilidad de juego invalida.');
        }

        if ($currentVersion !== '' && strlen($currentVersion) > 60) {
            throw new RuntimeException('La version actual no puede superar 60 caracteres.');
        }

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
            'visibility' => $visibility,
            'current_version' => $currentVersion !== '' ? $currentVersion : null,
            'config_json' => $configJson,
            'endpoints_json' => $endpointsJson,
            'external_database_json' => $externalDatabaseJson,
            'cdn_json' => $cdnJson,
        ];
    }

    private static function validatedCodeInput(array $input): array
    {
        $gameId = (int) ($input['game_id'] ?? 0);
        $code = trim((string) ($input['code'] ?? ''));
        $rewardType = strtolower(trim((string) ($input['reward_type'] ?? '')));
        $rewardJson = self::cleanJson((string) ($input['reward_json'] ?? ''));
        $maxUses = (int) ($input['max_uses'] ?? 1);
        $expiresAt = trim((string) ($input['expires_at'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'active'));

        if ($gameId <= 0) {
            $gameId = null;
        } elseif (!self::gameExists($gameId)) {
            throw new RuntimeException('El juego asociado no existe.');
        }

        if ($code !== '' && !preg_match('/^[A-Za-z0-9_-]{4,80}$/', $code)) {
            throw new RuntimeException('El codigo solo puede usar letras, numeros, guion y guion bajo.');
        }

        if (!preg_match('/^[a-z0-9_.:-]{2,80}$/', $rewardType)) {
            throw new RuntimeException('El tipo de recompensa no es valido.');
        }

        if ($maxUses < 1 || $maxUses > 1000000) {
            throw new RuntimeException('Los usos maximos deben estar entre 1 y 1000000.');
        }

        if ($expiresAt !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
                throw new RuntimeException('La fecha de expiracion debe tener formato YYYY-MM-DD.');
            }
            $expiresAt .= ' 23:59:59';
        } else {
            $expiresAt = null;
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Estado de codigo invalido.');
        }

        return [
            'game_id' => $gameId,
            'code' => $code,
            'reward_type' => $rewardType,
            'reward_json' => $rewardJson,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'status' => $status,
        ];
    }

    private static function cleanJson(string $json): ?string
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('Uno de los campos JSON no es valido.');
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function gameExists(int $gameId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM games WHERE id = :id');
        $stmt->execute(['id' => $gameId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private static function codeHash(string $code): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash_hmac('sha256', self::normalizeCode($code), $pepper);
    }

    private static function codePreview(string $code): string
    {
        $code = self::normalizeCode($code);
        if (strlen($code) <= 8) {
            return $code;
        }

        return substr($code, 0, 4) . '...' . substr($code, -4);
    }

    private static function generateCode(): string
    {
        return 'JVG-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
