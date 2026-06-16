<?php
declare(strict_types=1);

namespace App\Installers;

use App\Core\Database;
use App\Models\User;
use PDO;
use RuntimeException;

final class Installer
{
    public static function requirements(): array
    {
        return [
            [
                'label' => 'PHP 8.1 o superior',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'detail' => PHP_VERSION,
            ],
            [
                'label' => 'Extension PDO',
                'ok' => extension_loaded('pdo'),
                'detail' => extension_loaded('pdo') ? 'Disponible' : 'No disponible',
            ],
            [
                'label' => 'Extension pdo_mysql',
                'ok' => extension_loaded('pdo_mysql'),
                'detail' => extension_loaded('pdo_mysql') ? 'Disponible' : 'No disponible',
            ],
            [
                'label' => 'Extension JSON',
                'ok' => extension_loaded('json'),
                'detail' => extension_loaded('json') ? 'Disponible' : 'No disponible',
            ],
            [
                'label' => 'Sesiones PHP',
                'ok' => function_exists('session_start'),
                'detail' => function_exists('session_start') ? 'Disponible' : 'No disponible',
            ],
            [
                'label' => 'app/config escribible',
                'ok' => is_writable(CONFIG_PATH),
                'detail' => CONFIG_PATH,
            ],
            [
                'label' => 'storage/logs escribible',
                'ok' => is_writable(STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs'),
                'detail' => STORAGE_PATH . DIRECTORY_SEPARATOR . 'logs',
            ],
            [
                'label' => 'storage/sessions escribible',
                'ok' => is_writable(STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions'),
                'detail' => STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions',
            ],
        ];
    }

    public static function canInstall(): bool
    {
        foreach (self::requirements() as $requirement) {
            if (!$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    public static function install(array $input): void
    {
        if (\installer_is_locked()) {
            throw new RuntimeException('El instalador ya esta bloqueado.');
        }

        if (!self::canInstall()) {
            throw new RuntimeException('El entorno no cumple los requisitos minimos.');
        }

        $data = self::validatedInput($input);
        $serverPdo = self::serverConnection($data);
        self::createDatabase($serverPdo, $data['db_name']);

        $databasePdo = self::databaseConnection($data);
        self::runSqlFile($databasePdo, ROOT_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql');
        self::runSqlFile($databasePdo, ROOT_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds.sql');

        $config = self::buildConfig($data);
        self::writeConfig($config);

        $defaultConfig = require CONFIG_PATH . DIRECTORY_SEPARATOR . 'default.php';
        $GLOBALS['app_config'] = array_replace_recursive($defaultConfig, $config);
        Database::reset();

        User::create($data['superroot_username'], $data['superroot_email'], $data['superroot_password'], 'superroot');
        self::storeInitialSettings($data);
        self::writeLock();
    }

    private static function validatedInput(array $input): array
    {
        $dbHost = trim((string) ($input['db_host'] ?? ''));
        $dbPort = (int) ($input['db_port'] ?? 3306);
        $dbName = trim((string) ($input['db_name'] ?? ''));
        $dbUser = trim((string) ($input['db_user'] ?? ''));
        $dbPassword = (string) ($input['db_password'] ?? '');
        $appName = trim((string) ($input['app_name'] ?? 'JevzGames'));
        $baseUrl = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $environment = trim((string) ($input['environment'] ?? 'development'));
        $server = trim((string) ($input['server'] ?? 'apache'));
        $cdnEnabled = isset($input['cdn_enabled']) && (string) $input['cdn_enabled'] === '1';
        $cdnUrl = rtrim(trim((string) ($input['cdn_url'] ?? '')), '/');
        $superrootUsername = trim((string) ($input['superroot_username'] ?? ''));
        $superrootEmail = trim((string) ($input['superroot_email'] ?? ''));
        $superrootPassword = (string) ($input['superroot_password'] ?? '');
        $superrootPasswordConfirm = (string) ($input['superroot_password_confirm'] ?? '');

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('Completa host, nombre y usuario de base de datos.');
        }

        if ($dbPort < 1 || $dbPort > 65535) {
            throw new RuntimeException('El puerto de base de datos no es valido.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('El nombre de base de datos solo puede usar letras, numeros y guion bajo.');
        }

        if ($appName === '' || strlen($appName) > 120) {
            throw new RuntimeException('El nombre de la plataforma no es valido.');
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

        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $superrootUsername)) {
            throw new RuntimeException('El usuario superroot debe tener 3 a 30 caracteres: letras, numeros o guion bajo.');
        }

        if (!filter_var($superrootEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El email superroot no es valido.');
        }

        if (strlen($superrootPassword) < 10) {
            throw new RuntimeException('La contrasena superroot debe tener al menos 10 caracteres.');
        }

        if ($superrootPassword !== $superrootPasswordConfirm) {
            throw new RuntimeException('Las contrasenas superroot no coinciden.');
        }

        return [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $dbPassword,
            'app_name' => $appName,
            'base_url' => $baseUrl,
            'environment' => $environment,
            'server' => $server,
            'cdn_enabled' => $cdnEnabled,
            'cdn_url' => $cdnUrl,
            'superroot_username' => $superrootUsername,
            'superroot_email' => $superrootEmail,
            'superroot_password' => $superrootPassword,
        ];
    }

    private static function serverConnection(array $data): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $data['db_host'],
            $data['db_port']
        );

        return new PDO($dsn, $data['db_user'], $data['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function databaseConnection(array $data): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $data['db_host'],
            $data['db_port'],
            $data['db_name']
        );

        return new PDO($dsn, $data['db_user'], $data['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function createDatabase(PDO $pdo, string $database): void
    {
        $quoted = '`' . str_replace('`', '``', $database) . '`';
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private static function runSqlFile(PDO $pdo, string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('No existe el archivo SQL: ' . $path);
        }

        $sql = (string) file_get_contents($path);
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private static function buildConfig(array $data): array
    {
        return [
            'app' => [
                'name' => $data['app_name'],
                'base_url' => $data['base_url'],
                'environment' => $data['environment'],
                'server' => $data['server'],
                'installed_at' => date('c'),
            ],
            'database' => [
                'host' => $data['db_host'],
                'port' => $data['db_port'],
                'name' => $data['db_name'],
                'user' => $data['db_user'],
                'password' => $data['db_password'],
                'charset' => 'utf8mb4',
            ],
            'cdn' => [
                'enabled' => $data['cdn_enabled'],
                'url' => $data['cdn_url'],
            ],
            'session' => [
                'name' => 'JEVZGAMES_SESSION',
                'lifetime' => 7200,
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
            'api' => [
                'expose_errors' => false,
            ],
            'integrations' => [],
        ];
    }

    private static function writeConfig(array $config): void
    {
        $content = "<?php\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= 'return ' . var_export($config, true) . ";\n";

        if (file_put_contents(\private_config_path(), $content, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir app/config/config.php.');
        }
    }

    private static function storeInitialSettings(array $data): void
    {
        $pdo = Database::pdo();
        $settings = [
            'app.name' => $data['app_name'],
            'app.base_url' => $data['base_url'],
            'app.environment' => $data['environment'],
            'app.server' => $data['server'],
            'cdn.enabled' => $data['cdn_enabled'] ? '1' : '0',
            'cdn.url' => $data['cdn_url'],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, is_private, created_at, updated_at)
             VALUES (:setting_key, :setting_value, :value_type, :is_private, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()'
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'value_type' => 'string',
                'is_private' => str_contains($key, 'database') ? 1 : 0,
            ]);
        }
    }

    private static function writeLock(): void
    {
        $content = 'installed_at=' . date('c') . PHP_EOL;
        if (file_put_contents(\installed_lock_path(), $content, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir installed.lock.');
        }
    }
}
