<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class Superroot
{
    private const MANAGED_ROLES = ['user', 'developer', 'admin', 'supporter'];

    public static function dashboardStats(): array
    {
        $pdo = Database::pdo();

        return [
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'games' => (int) $pdo->query('SELECT COUNT(*) FROM games')->fetchColumn(),
            'open_tickets' => (int) $pdo->query('SELECT COUNT(*) FROM support_tickets WHERE status = "open"')->fetchColumn(),
            'active_integrations' => (int) $pdo->query('SELECT COUNT(*) FROM external_integrations WHERE status = "active"')->fetchColumn(),
            'admins' => (int) $pdo->query(
                'SELECT COUNT(DISTINCT ur.user_id)
                 FROM user_roles ur
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE r.slug IN ("admin", "superroot")'
            )->fetchColumn(),
        ];
    }

    public static function updateCoreConfig(array $input): void
    {
        $data = self::validatedConfigInput($input);
        $current = \app_config();

        if (!is_array($current)) {
            throw new RuntimeException('La configuracion actual no es valida.');
        }

        $updated = array_replace_recursive($current, [
            'app' => [
                'name' => $data['app_name'],
                'base_url' => $data['base_url'],
                'environment' => $data['environment'],
                'server' => $data['server'],
            ],
            'cdn' => [
                'enabled' => $data['cdn_enabled'],
                'url' => $data['cdn_url'],
            ],
            'session' => [
                'lifetime' => $data['session_lifetime'],
                'secure' => $data['session_secure'],
            ],
            'api' => [
                'expose_errors' => $data['api_expose_errors'],
            ],
        ]);

        self::writePrivateConfig($updated);
        $GLOBALS['app_config'] = $updated;
        self::persistSettings($data);
        self::syncCdnSettings($data['cdn_enabled'], $data['cdn_url']);
    }

    public static function integrations(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id, name, provider, client_id, status, config_json,
                    CASE WHEN client_secret_hash IS NULL OR client_secret_hash = "" THEN 0 ELSE 1 END AS has_secret,
                    created_at, updated_at
             FROM external_integrations
             ORDER BY FIELD(status, "active", "inactive"), provider ASC'
        );

        return $stmt->fetchAll();
    }

    public static function saveIntegration(array $input): int
    {
        $data = self::validatedIntegrationInput($input);
        $pdo = Database::pdo();

        if ($data['id'] > 0) {
            $existing = self::integrationById($data['id']);
            if (!$existing) {
                throw new RuntimeException('La integracion indicada no existe.');
            }

            $secretHash = $existing['client_secret_hash'];
            if ($data['client_secret'] !== '') {
                $secretHash = password_hash($data['client_secret'], PASSWORD_DEFAULT);
            }

            $stmt = $pdo->prepare(
                'UPDATE external_integrations
                 SET name = :name,
                     provider = :provider,
                     client_id = :client_id,
                     client_secret_hash = :client_secret_hash,
                     status = :status,
                     config_json = :config_json,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            try {
                $stmt->execute([
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'provider' => $data['provider'],
                    'client_id' => $data['client_id'],
                    'client_secret_hash' => $secretHash,
                    'status' => $data['status'],
                    'config_json' => $data['config_json'],
                ]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Ya existe una integracion con ese proveedor.');
                }
                throw $exception;
            }

            return $data['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO external_integrations (name, provider, client_id, client_secret_hash, status, config_json, created_at, updated_at)
             VALUES (:name, :provider, :client_id, :client_secret_hash, :status, :config_json, NOW(), NOW())'
        );
        try {
            $stmt->execute([
                'name' => $data['name'],
                'provider' => $data['provider'],
                'client_id' => $data['client_id'],
                'client_secret_hash' => $data['client_secret'] !== '' ? password_hash($data['client_secret'], PASSWORD_DEFAULT) : null,
                'status' => $data['status'],
                'config_json' => $data['config_json'],
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe una integracion con ese proveedor.');
            }
            throw $exception;
        }

        return (int) $pdo->lastInsertId();
    }

    public static function toggleIntegration(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Estado de integracion invalido.');
        }

        $stmt = Database::pdo()->prepare('UPDATE external_integrations SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'status' => $status,
        ]);
    }

    public static function users(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT u.id, u.username, u.email, u.status, u.display_name, u.created_at, u.last_login_at,
                    GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ",") AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id, u.username, u.email, u.status, u.display_name, u.created_at, u.last_login_at
             ORDER BY u.id ASC
             LIMIT 250'
        );

        return array_map(static function (array $row): array {
            $roles = $row['roles'] !== null && $row['roles'] !== ''
                ? explode(',', (string) $row['roles'])
                : [];
            $row['roles'] = $roles;
            return $row;
        }, $stmt->fetchAll());
    }

    public static function updateUserAccess(int $targetUserId, string $status, array $roles, int $actorUserId): void
    {
        $target = User::findByIdWithRoles($targetUserId);
        if (!$target) {
            throw new RuntimeException('El usuario indicado no existe.');
        }

        if (in_array('superroot', $target['roles'] ?? [], true)) {
            throw new RuntimeException('Las cuentas superroot no se modifican desde esta tabla.');
        }

        if (!in_array($status, ['active', 'blocked', 'pending_recovery'], true)) {
            throw new RuntimeException('Estado de usuario invalido.');
        }

        $roles = array_map(static fn (mixed $role): string => (string) $role, $roles);
        $roles = array_values(array_unique(array_intersect($roles, self::MANAGED_ROLES)));
        if ($roles === []) {
            $roles = ['user'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'id' => $targetUserId,
                'status' => $status,
            ]);

            $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $targetUserId]);

            $roleIds = self::roleIdsForSlugs($roles);
            $insert = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, created_at) VALUES (:user_id, :role_id, NOW())');
            foreach ($roleIds as $roleId) {
                $insert->execute([
                    'user_id' => $targetUserId,
                    'role_id' => $roleId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function maintenanceInfo(): array
    {
        $pdo = Database::pdo();

        return [
            'php_version' => PHP_VERSION,
            'db_version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
            'installed' => \is_installed(),
            'config_writable' => is_writable(CONFIG_PATH),
            'logs_writable' => is_writable(STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs'),
            'sessions_writable' => is_writable(STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions'),
            'config_path' => \private_config_path(),
            'lock_path' => \installed_lock_path(),
            'log_path' => STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log',
            'session_path' => session_save_path(),
        ];
    }

    public static function recentLogLines(int $limit = 30): array
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

    private static function validatedConfigInput(array $input): array
    {
        $appName = trim((string) ($input['app_name'] ?? ''));
        $baseUrl = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $environment = trim((string) ($input['environment'] ?? 'development'));
        $server = trim((string) ($input['server'] ?? 'apache'));
        $cdnEnabled = isset($input['cdn_enabled']) && (string) $input['cdn_enabled'] === '1';
        $cdnUrl = rtrim(trim((string) ($input['cdn_url'] ?? '')), '/');
        $sessionLifetime = (int) ($input['session_lifetime'] ?? 7200);
        $sessionSecure = isset($input['session_secure']) && (string) $input['session_secure'] === '1';
        $apiExposeErrors = isset($input['api_expose_errors']) && (string) $input['api_expose_errors'] === '1';

        if ($appName === '' || strlen($appName) > 120) {
            throw new RuntimeException('El nombre de plataforma debe tener entre 1 y 120 caracteres.');
        }

        if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('La URL base debe ser una URL valida o quedar vacia.');
        }

        if (!in_array($environment, ['development', 'production'], true)) {
            throw new RuntimeException('El entorno no es valido.');
        }

        if (!in_array($server, ['apache', 'nginx'], true)) {
            throw new RuntimeException('El modo de servidor no es valido.');
        }

        if ($cdnEnabled && ($cdnUrl === '' || !filter_var($cdnUrl, FILTER_VALIDATE_URL))) {
            throw new RuntimeException('Si activas CDN externa, debes indicar una URL valida.');
        }

        if ($sessionLifetime < 300 || $sessionLifetime > 86400) {
            throw new RuntimeException('La duracion de sesion debe estar entre 300 y 86400 segundos.');
        }

        if ($environment === 'production' && $apiExposeErrors) {
            throw new RuntimeException('No expongas errores internos en produccion.');
        }

        return [
            'app_name' => $appName,
            'base_url' => $baseUrl,
            'environment' => $environment,
            'server' => $server,
            'cdn_enabled' => $cdnEnabled,
            'cdn_url' => $cdnUrl,
            'session_lifetime' => $sessionLifetime,
            'session_secure' => $sessionSecure,
            'api_expose_errors' => $apiExposeErrors,
        ];
    }

    private static function validatedIntegrationInput(array $input): array
    {
        $id = (int) ($input['integration_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        $clientId = trim((string) ($input['client_id'] ?? ''));
        $clientSecret = (string) ($input['client_secret'] ?? '');
        $status = trim((string) ($input['status'] ?? 'inactive'));
        $configJson = trim((string) ($input['config_json'] ?? ''));

        if ($name === '' || strlen($name) > 120) {
            throw new RuntimeException('El nombre de integracion debe tener entre 1 y 120 caracteres.');
        }

        if (!preg_match('/^[a-z0-9_.:-]{2,80}$/', $provider)) {
            throw new RuntimeException('El proveedor solo puede usar letras minusculas, numeros, guion bajo, punto, dos puntos o guion.');
        }

        if ($clientId !== '' && strlen($clientId) > 190) {
            throw new RuntimeException('El client_id no puede superar 190 caracteres.');
        }

        if ($clientSecret !== '' && strlen($clientSecret) > 500) {
            throw new RuntimeException('El client_secret es demasiado largo.');
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Estado de integracion invalido.');
        }

        if ($configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new RuntimeException('La configuracion JSON de la integracion no es valida.');
            }
            $configJson = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $configJson = null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'provider' => $provider,
            'client_id' => $clientId !== '' ? $clientId : null,
            'client_secret' => $clientSecret,
            'status' => $status,
            'config_json' => $configJson,
        ];
    }

    private static function writePrivateConfig(array $config): void
    {
        $content = "<?php\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= 'return ' . var_export($config, true) . ";\n";

        if (file_put_contents(\private_config_path(), $content, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir app/config/config.php.');
        }
    }

    private static function persistSettings(array $data): void
    {
        $settings = [
            'app.name' => [$data['app_name'], 'string'],
            'app.base_url' => [$data['base_url'], 'string'],
            'app.environment' => [$data['environment'], 'string'],
            'app.server' => [$data['server'], 'string'],
            'cdn.enabled' => [$data['cdn_enabled'] ? '1' : '0', 'boolean'],
            'cdn.url' => [$data['cdn_url'], 'string'],
            'session.lifetime' => [(string) $data['session_lifetime'], 'integer'],
            'session.secure' => [$data['session_secure'] ? '1' : '0', 'boolean'],
            'api.expose_errors' => [$data['api_expose_errors'] ? '1' : '0', 'boolean'],
        ];

        $stmt = Database::pdo()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, is_private, created_at, updated_at)
             VALUES (:setting_key, :setting_value, :value_type, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()'
        );

        foreach ($settings as $key => [$value, $type]) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'value_type' => $type,
            ]);
        }
    }

    private static function syncCdnSettings(bool $enabled, string $url): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO cdn_settings (name, mode, base_url, is_active, config_json, created_at, updated_at)
             VALUES (:name, :mode, :base_url, :is_active, NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), base_url = VALUES(base_url), is_active = VALUES(is_active), updated_at = NOW()'
        );

        $stmt->execute([
            'name' => 'Local',
            'mode' => 'local',
            'base_url' => null,
            'is_active' => $enabled ? 0 : 1,
        ]);

        $stmt->execute([
            'name' => 'External',
            'mode' => 'external',
            'base_url' => $url !== '' ? $url : null,
            'is_active' => $enabled ? 1 : 0,
        ]);
    }

    private static function integrationById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM external_integrations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $integration = $stmt->fetch();

        return is_array($integration) ? $integration : null;
    }

    private static function roleIdsForSlugs(array $slugs): array
    {
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = Database::pdo()->prepare('SELECT id, slug FROM roles WHERE slug IN (' . $placeholders . ')');
        $stmt->execute($slugs);

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[(string) $row['slug']] = (int) $row['id'];
        }

        $missing = array_diff($slugs, array_keys($ids));
        if ($missing !== []) {
            throw new RuntimeException('Faltan roles base en la base de datos: ' . implode(', ', $missing));
        }

        return array_values($ids);
    }
}
