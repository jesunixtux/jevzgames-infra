<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;
use RuntimeException;

final class ExternalGames
{
    private const SETTINGS = [
        'external_games.enabled' => ['0', 'boolean'],
        'external_games.allow_publish' => ['0', 'boolean'],
        'external_games.db_host' => ['', 'string'],
        'external_games.db_port' => ['3306', 'integer'],
        'external_games.db_name' => ['', 'string'],
        'external_games.db_user' => ['', 'string'],
        'external_games.db_password' => ['', 'string', 1],
    ];

    private const STATUSES = ['development', 'playtest', 'beta', 'published', 'archived'];
    private const VISIBILITIES = ['public', 'unlisted', 'private'];

    private static ?PDO $externalPdo = null;

    public static function settings(): array
    {
        self::ensureDefaults();
        $values = self::values();
        $configured = $values['db_host'] !== '' && $values['db_name'] !== '' && $values['db_user'] !== '';

        return [
            'enabled' => (bool) $values['enabled'],
            'allow_publish' => (bool) $values['allow_publish'],
            'configured' => $configured,
            'db_host' => $values['db_host'],
            'db_port' => (int) $values['db_port'],
            'db_name' => $values['db_name'],
            'db_user' => $values['db_user'],
            'db_password_configured' => $values['db_password'] !== '',
        ];
    }

    public static function saveSettings(array $input): void
    {
        self::ensureDefaults();
        $enabled = isset($input['external_games_enabled']) ? '1' : '0';
        $allowPublish = isset($input['external_games_allow_publish']) ? '1' : '0';
        $host = trim((string) ($input['external_games_db_host'] ?? ''));
        $port = (int) ($input['external_games_db_port'] ?? 3306);
        $database = trim((string) ($input['external_games_db_name'] ?? ''));
        $user = trim((string) ($input['external_games_db_user'] ?? ''));
        $password = (string) ($input['external_games_db_password'] ?? '');

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('El puerto de la base externa no es valido.');
        }

        if ($enabled === '1') {
            foreach ([
                'host' => $host,
                'database' => $database,
                'user' => $user,
            ] as $label => $value) {
                if ($value === '') {
                    throw new RuntimeException('Para activar juegos externos debes configurar host, base de datos y usuario.');
                }
            }
        }

        $currentPassword = self::raw('external_games.db_password');
        $storedPassword = $password !== '' ? self::encryptSecret($password) : $currentPassword;

        self::upsert([
            'external_games.enabled' => [$enabled, 'boolean'],
            'external_games.allow_publish' => [$allowPublish, 'boolean'],
            'external_games.db_host' => [$host, 'string'],
            'external_games.db_port' => [(string) $port, 'integer'],
            'external_games.db_name' => [$database, 'string'],
            'external_games.db_user' => [$user, 'string'],
            'external_games.db_password' => [$storedPassword, 'string', 1],
        ]);

        self::$externalPdo = null;

        if ($enabled === '1') {
            self::ensureExternalTables();
        }
    }

    public static function ensureSystemRole(): void
    {
        $pdo = Database::pdo();
        $pdo->exec(
            "INSERT INTO roles (slug, name, description, is_system)
             VALUES ('developer-extern', 'Desarrollador externo', 'Publica y configura juegos de terceros.', 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system)"
        );

        $pdo->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             INNER JOIN permissions p ON p.slug IN ('profile.view', 'codes.redeem', 'games.manage', 'api.keys.manage')
             WHERE r.slug = 'developer-extern'"
        );
    }

    public static function ensureExternalTables(): void
    {
        $pdo = self::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS external_games (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_user_id INT UNSIGNED NOT NULL,
                main_game_id INT UNSIGNED NULL,
                name VARCHAR(140) NOT NULL,
                developer_name VARCHAR(140) NULL,
                publisher_name VARCHAR(140) NULL,
                slug VARCHAR(160) NOT NULL UNIQUE,
                description TEXT NULL,
                status ENUM("development", "playtest", "beta", "published", "archived") NOT NULL DEFAULT "development",
                visibility ENUM("public", "unlisted", "private") NOT NULL DEFAULT "private",
                current_version VARCHAR(60) NULL,
                config_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_external_games_owner (owner_user_id),
                INDEX idx_external_games_main (main_game_id),
                INDEX idx_external_games_status (status),
                INDEX idx_external_games_visibility (visibility)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS external_game_players (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                external_game_id BIGINT UNSIGNED NOT NULL,
                platform_user_id INT UNSIGNED NULL,
                username VARCHAR(120) NULL,
                display_name VARCHAR(160) NULL,
                last_seen_at DATETIME NULL,
                metadata_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_external_game_players_game (external_game_id),
                INDEX idx_external_game_players_user (platform_user_id),
                CONSTRAINT fk_external_game_players_game FOREIGN KEY (external_game_id) REFERENCES external_games(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function stats(): array
    {
        $settings = self::settings();
        $base = [
            'connected' => false,
            'message' => $settings['configured'] ? 'Sin probar.' : 'Sin configurar.',
            'external_games' => 0,
            'external_players' => 0,
        ];

        if (!$settings['enabled'] || !$settings['configured']) {
            return $base;
        }

        try {
            self::ensureExternalTables();
            $pdo = self::pdo();
            $base['connected'] = true;
            $base['message'] = 'Conexion correcta.';
            $base['external_games'] = (int) $pdo->query('SELECT COUNT(*) FROM external_games')->fetchColumn();
            $base['external_players'] = (int) $pdo->query('SELECT COUNT(*) FROM external_game_players')->fetchColumn();
        } catch (\Throwable $exception) {
            $base['message'] = $exception->getMessage();
        }

        return $base;
    }

    public static function gamesForUser(int $userId, bool $canManageAll = false): array
    {
        self::assertUsable();
        self::ensureExternalTables();
        $params = [];
        $where = '';
        if (!$canManageAll) {
            $where = 'WHERE eg.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = $userId;
        }

        $stmt = self::pdo()->prepare(
            'SELECT eg.*,
                    players.player_count,
                    players.last_player_seen_at
             FROM external_games eg
             LEFT JOIN (
                SELECT external_game_id, COUNT(*) AS player_count, MAX(last_seen_at) AS last_player_seen_at
                FROM external_game_players
                GROUP BY external_game_id
             ) players ON players.external_game_id = eg.id
             ' . $where . '
             ORDER BY eg.created_at DESC, eg.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function findGame(int $externalGameId, int $userId, bool $canManageAll = false): ?array
    {
        if ($externalGameId <= 0) {
            return null;
        }

        self::assertUsable();
        self::ensureExternalTables();
        $stmt = self::pdo()->prepare('SELECT * FROM external_games WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $externalGameId]);
        $game = $stmt->fetch();
        if (!is_array($game)) {
            return null;
        }

        if (!$canManageAll && (int) $game['owner_user_id'] !== $userId) {
            throw new RuntimeException('No tienes acceso a este juego externo.');
        }

        return $game;
    }

    public static function saveGame(int $userId, array $input, bool $canManageAll = false): int
    {
        self::assertUsable();
        self::ensureExternalTables();
        Game::ensureVisibilityColumn();
        $data = self::validatedGameInput($input);
        $externalPdo = self::pdo();
        $mainPdo = Database::pdo();

        if ($data['id'] > 0) {
            $existing = self::findGame($data['id'], $userId, $canManageAll);
            if (!$existing) {
                throw new RuntimeException('Juego externo no encontrado.');
            }

            $ownerId = (int) $existing['owner_user_id'];
            $externalPdo->beginTransaction();
            $mainPdo->beginTransaction();
            try {
                $externalStmt = $externalPdo->prepare(
                    'UPDATE external_games
                     SET name = :name,
                         developer_name = :developer_name,
                         publisher_name = :publisher_name,
                         description = :description,
                         status = :status,
                         visibility = :visibility,
                         current_version = :current_version,
                         config_json = :config_json,
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $externalStmt->execute([
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'developer_name' => $data['developer_name'],
                    'publisher_name' => $data['publisher_name'],
                    'description' => $data['description'],
                    'status' => $data['status'],
                    'visibility' => $data['visibility'],
                    'current_version' => $data['current_version'],
                    'config_json' => $data['config_json'],
                ]);

                self::syncMainGame([
                    ...$data,
                    'id' => $data['id'],
                    'owner_user_id' => $ownerId,
                    'slug' => (string) $existing['slug'],
                    'main_game_id' => isset($existing['main_game_id']) ? (int) $existing['main_game_id'] : null,
                ]);

                $externalPdo->commit();
                $mainPdo->commit();
            } catch (\Throwable $exception) {
                $externalPdo->rollBack();
                $mainPdo->rollBack();
                throw $exception;
            }

            return $data['id'];
        }

        $slug = self::randomSlug();
        $externalPdo->beginTransaction();
        $mainPdo->beginTransaction();
        try {
            $stmt = $externalPdo->prepare(
                'INSERT INTO external_games
                    (owner_user_id, name, developer_name, publisher_name, slug, description, status, visibility, current_version, config_json, created_at, updated_at)
                 VALUES
                    (:owner_user_id, :name, :developer_name, :publisher_name, :slug, :description, :status, :visibility, :current_version, :config_json, NOW(), NOW())'
            );
            $stmt->execute([
                'owner_user_id' => $userId,
                'name' => $data['name'],
                'developer_name' => $data['developer_name'],
                'publisher_name' => $data['publisher_name'],
                'slug' => $slug,
                'description' => $data['description'],
                'status' => $data['status'],
                'visibility' => $data['visibility'],
                'current_version' => $data['current_version'],
                'config_json' => $data['config_json'],
            ]);

            $externalGameId = (int) $externalPdo->lastInsertId();
            $mainGameId = self::syncMainGame([
                ...$data,
                'id' => $externalGameId,
                'owner_user_id' => $userId,
                'slug' => $slug,
                'main_game_id' => null,
            ]);

            $update = $externalPdo->prepare('UPDATE external_games SET main_game_id = :main_game_id WHERE id = :id');
            $update->execute([
                'id' => $externalGameId,
                'main_game_id' => $mainGameId,
            ]);

            $externalPdo->commit();
            $mainPdo->commit();

            return $externalGameId;
        } catch (PDOException $exception) {
            $externalPdo->rollBack();
            $mainPdo->rollBack();
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('No se pudo generar un slug externo unico. Intenta de nuevo.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $externalPdo->rollBack();
            $mainPdo->rollBack();
            throw $exception;
        }
    }

    public static function playerRows(int $externalGameId, int $userId, bool $canManageAll = false): array
    {
        self::findGame($externalGameId, $userId, $canManageAll);
        $stmt = self::pdo()->prepare(
            'SELECT *
             FROM external_game_players
             WHERE external_game_id = :external_game_id
             ORDER BY COALESCE(last_seen_at, created_at) DESC, id DESC
             LIMIT 100'
        );
        $stmt->execute(['external_game_id' => $externalGameId]);

        return $stmt->fetchAll();
    }

    public static function managedMainGame(int $externalGameId, int $userId, bool $canManageAll = false): array
    {
        $externalGame = self::findGame($externalGameId, $userId, $canManageAll);
        if ($externalGame === null) {
            throw new RuntimeException('Juego externo no encontrado.');
        }

        $mainGameId = (int) ($externalGame['main_game_id'] ?? 0);
        if ($mainGameId <= 0) {
            $mainGameId = self::syncMainGame([
                ...$externalGame,
                'id' => (int) $externalGame['id'],
                'owner_user_id' => (int) $externalGame['owner_user_id'],
                'main_game_id' => null,
            ]);

            $update = self::pdo()->prepare('UPDATE external_games SET main_game_id = :main_game_id WHERE id = :id');
            $update->execute([
                'id' => (int) $externalGame['id'],
                'main_game_id' => $mainGameId,
            ]);
            $externalGame['main_game_id'] = $mainGameId;
        }

        Game::ensureVisibilityColumn();
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM games
             WHERE id = :id
               AND source_type = "external"
               AND external_game_id = :external_game_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $mainGameId,
            'external_game_id' => (int) $externalGame['id'],
        ]);
        $mainGame = $stmt->fetch();
        if (!is_array($mainGame)) {
            throw new RuntimeException('No se encontro el registro principal de este juego externo.');
        }

        return [
            'external_game' => $externalGame,
            'main_game' => $mainGame,
        ];
    }

    public static function managedMainGameByBuild(int $buildId, int $userId, bool $canManageAll = false): array
    {
        if ($buildId <= 0) {
            throw new RuntimeException('Build invalida.');
        }

        GameBuild::ensureTables();
        Game::ensureVisibilityColumn();
        $stmt = Database::pdo()->prepare(
            'SELECT b.*, g.external_game_id
             FROM game_builds b
             INNER JOIN games g ON g.id = b.game_id
             WHERE b.id = :build_id
               AND g.source_type = "external"
             LIMIT 1'
        );
        $stmt->execute(['build_id' => $buildId]);
        $build = $stmt->fetch();
        if (!is_array($build) || (int) ($build['external_game_id'] ?? 0) <= 0) {
            throw new RuntimeException('Build externa no encontrada.');
        }

        $managed = self::managedMainGame((int) $build['external_game_id'], $userId, $canManageAll);
        $managed['build'] = $build;

        return $managed;
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function visibilities(): array
    {
        return self::VISIBILITIES;
    }

    private static function assertUsable(): void
    {
        $settings = self::settings();
        if (!$settings['enabled'] || !$settings['allow_publish']) {
            throw new RuntimeException('Los juegos externos estan deshabilitados por Superroot.');
        }
        if (!$settings['configured']) {
            throw new RuntimeException('La base de datos externa no esta configurada.');
        }
    }

    private static function pdo(): PDO
    {
        if (self::$externalPdo instanceof PDO) {
            return self::$externalPdo;
        }

        $values = self::values();
        if ($values['db_host'] === '' || $values['db_name'] === '' || $values['db_user'] === '') {
            throw new RuntimeException('La base de datos externa no esta configurada.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $values['db_host'],
            (int) $values['db_port'],
            $values['db_name']
        );

        self::$externalPdo = new PDO($dsn, $values['db_user'], self::decryptSecret($values['db_password']), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$externalPdo;
    }

    private static function syncMainGame(array $data): int
    {
        $mainGameId = isset($data['main_game_id']) && (int) $data['main_game_id'] > 0 ? (int) $data['main_game_id'] : 0;
        $payload = [
            'owner_user_id' => (int) $data['owner_user_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'],
            'developer_name' => $data['developer_name'],
            'publisher_name' => $data['publisher_name'],
            'status' => $data['status'],
            'visibility' => $data['visibility'],
            'source_type' => 'external',
            'external_game_id' => (int) $data['id'],
            'current_version' => $data['current_version'],
            'config_json' => $data['config_json'],
        ];

        if ($mainGameId > 0) {
            $stmt = Database::pdo()->prepare(
                'UPDATE games
                 SET owner_user_id = :owner_user_id,
                     name = :name,
                     slug = :slug,
                     description = :description,
                     developer_name = :developer_name,
                     publisher_name = :publisher_name,
                     status = :status,
                     visibility = :visibility,
                     source_type = :source_type,
                     external_game_id = :external_game_id,
                     current_version = :current_version,
                     config_json = :config_json,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $mainGameId, ...$payload]);

            return $mainGameId;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO games
                (owner_user_id, name, slug, description, developer_name, publisher_name, status, visibility, source_type, external_game_id, current_version, config_json, created_at, updated_at)
             VALUES
                (:owner_user_id, :name, :slug, :description, :developer_name, :publisher_name, :status, :visibility, :source_type, :external_game_id, :current_version, :config_json, NOW(), NOW())'
        );
        $stmt->execute($payload);

        return (int) Database::pdo()->lastInsertId();
    }

    private static function validatedGameInput(array $input): array
    {
        $id = (int) ($input['external_game_id'] ?? $input['game_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $developerName = trim((string) ($input['developer_name'] ?? ''));
        $publisherName = trim((string) ($input['publisher_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $status = trim((string) ($input['status'] ?? 'development'));
        $visibility = trim((string) ($input['visibility'] ?? 'private'));
        $currentVersion = trim((string) ($input['current_version'] ?? ''));
        $configJson = trim((string) ($input['config_json'] ?? ''));

        if ($name === '' || strlen($name) > 140) {
            throw new RuntimeException('El nombre del juego debe tener entre 1 y 140 caracteres.');
        }
        if ($developerName !== '' && strlen($developerName) > 140) {
            throw new RuntimeException('La desarrolladora no puede superar 140 caracteres.');
        }
        if ($publisherName !== '' && strlen($publisherName) > 140) {
            throw new RuntimeException('El publisher no puede superar 140 caracteres.');
        }
        if ($description !== '' && strlen($description) > 5000) {
            throw new RuntimeException('La descripcion del juego es demasiado larga.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Estado de juego invalido.');
        }
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new RuntimeException('Visibilidad de juego invalida.');
        }
        if ($currentVersion !== '' && strlen($currentVersion) > 60) {
            throw new RuntimeException('La version actual no puede superar 60 caracteres.');
        }
        if ($configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new RuntimeException('El JSON de configuracion no es valido.');
            }
            $configJson = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return [
            'id' => $id,
            'name' => $name,
            'developer_name' => $developerName !== '' ? $developerName : null,
            'publisher_name' => $publisherName !== '' ? $publisherName : null,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
            'visibility' => $visibility,
            'current_version' => $currentVersion !== '' ? $currentVersion : null,
            'config_json' => $configJson !== '' ? $configJson : null,
        ];
    }

    private static function randomSlug(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $slug = 'ext-' . bin2hex(random_bytes(8));
            $externalExists = self::pdo()->prepare('SELECT COUNT(*) FROM external_games WHERE slug = :slug');
            $externalExists->execute(['slug' => $slug]);
            if ((int) $externalExists->fetchColumn() > 0) {
                continue;
            }

            if (Game::gameIdBySlug($slug) !== null) {
                continue;
            }

            return $slug;
        }

        throw new RuntimeException('No se pudo generar un slug externo unico.');
    }

    private static function values(): array
    {
        self::ensureDefaults();
        $stmt = Database::pdo()->query(
            'SELECT setting_key, setting_value, value_type
             FROM system_settings
             WHERE setting_key IN ("' . implode('","', array_keys(self::SETTINGS)) . '")'
        );

        $values = [];
        foreach (self::SETTINGS as $key => [$default, $type]) {
            $shortKey = substr($key, strlen('external_games.'));
            $values[$shortKey] = self::cast((string) $default, (string) $type);
        }

        foreach ($stmt->fetchAll() as $row) {
            $shortKey = substr((string) $row['setting_key'], strlen('external_games.'));
            $values[$shortKey] = self::cast((string) ($row['setting_value'] ?? ''), (string) ($row['value_type'] ?? 'string'));
        }

        return $values;
    }

    private static function ensureDefaults(): void
    {
        self::upsert(self::SETTINGS, false);
    }

    private static function upsert(array $settings, bool $overwrite = true): void
    {
        $sql = 'INSERT INTO system_settings (setting_key, setting_value, value_type, is_private, created_at, updated_at)
                VALUES (:setting_key, :setting_value, :value_type, :is_private, NOW(), NOW())';
        if ($overwrite) {
            $sql .= ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), is_private = VALUES(is_private), updated_at = NOW()';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE setting_key = setting_key';
        }

        $stmt = Database::pdo()->prepare($sql);
        foreach ($settings as $key => $definition) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => (string) ($definition[0] ?? ''),
                'value_type' => (string) ($definition[1] ?? 'string'),
                'is_private' => (int) ($definition[2] ?? 0),
            ]);
        }
    }

    private static function raw(string $key): string
    {
        $stmt = Database::pdo()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? '' : (string) $value;
    }

    private static function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'integer' => (int) $value,
            default => $value,
        };
    }

    private static function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('La extension OpenSSL es requerida para guardar secretos.');
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el secreto.');
        }

        return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($cipher);
    }

    private static function decryptSecret(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, 'enc:v1:')) {
            return $stored;
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $parts = explode(':', $stored, 4);
        if (count($parts) !== 4) {
            return '';
        }

        $iv = base64_decode($parts[2], true);
        $cipher = base64_decode($parts[3], true);
        if ($iv === false || $cipher === false) {
            return '';
        }

        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    private static function secretKey(): string
    {
        $pepper = (string) \app_config('app.installed_at', '');
        if ($pepper === '') {
            $pepper = (string) \app_config('database.name', 'jevzgames-infra');
        }

        return hash('sha256', $pepper, true);
    }
}
