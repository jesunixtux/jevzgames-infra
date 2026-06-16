<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'app');
define('PUBLIC_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'public');
define('STORAGE_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'storage');
define('CONFIG_PATH', APP_PATH . DIRECTORY_SEPARATOR . 'config');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = APP_PATH . DIRECTORY_SEPARATOR;

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $parts = explode('\\', $relativeClass);
    $directoryMap = [
        'Core' => 'core',
        'Models' => 'models',
        'Services' => 'services',
        'Security' => 'security',
        'Installers' => 'installers',
    ];

    if (isset($parts[0], $directoryMap[$parts[0]])) {
        $parts[0] = $directoryMap[$parts[0]];
    }

    $relativePath = implode(DIRECTORY_SEPARATOR, $parts) . '.php';
    $file = $baseDir . $relativePath;

    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'config.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'escape.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'route.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'i18n.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'asset.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'response.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'request.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'session.php';
require APP_PATH . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'installation.php';

$defaultConfig = require CONFIG_PATH . DIRECTORY_SEPARATOR . 'default.php';
$privateConfigPath = CONFIG_PATH . DIRECTORY_SEPARATOR . 'config.php';
$privateConfig = is_file($privateConfigPath) ? require $privateConfigPath : [];
$GLOBALS['app_config'] = array_replace_recursive($defaultConfig, is_array($privateConfig) ? $privateConfig : []);

$environment = app_config('app.environment', 'development');
if ($environment === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

$sessionPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions';
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

if (session_status() === PHP_SESSION_NONE) {
    session_name((string) app_config('session.name', 'JEVZGAMES_SESSION'));
    session_set_cookie_params([
        'lifetime' => (int) app_config('session.lifetime', 7200),
        'path' => public_base_path() !== '' ? public_base_path() . '/' : '/',
        'secure' => (bool) app_config('session.secure', false),
        'httponly' => (bool) app_config('session.httponly', true),
        'samesite' => (string) app_config('session.samesite', 'Lax'),
    ]);
    session_start();
}

if (isset($_GET['lang'])) {
    current_locale();
}

if (PHP_SAPI !== 'cli' && is_installed()) {
    try {
        $maintenance = \App\Models\PlatformSettings::maintenanceSettings();
        if (!empty($maintenance['enabled']) && !\App\Security\Auth::hasRole(['admin', 'superroot', 'developer'])) {
            $path = current_path();
            $base = public_base_path();
            $route = $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
            $route = '/' . ltrim($route, '/');
            $allowedPrefixes = ['/login/', '/logout/', '/assets/', '/install/'];
            $allowed = in_array($route, ['/login', '/logout', '/install'], true);
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                if (str_starts_with($route, '/api/')) {
                    api_response(false, (string) $maintenance['message'], [
                        'maintenance' => true,
                    ], 503);
                }

                http_response_code(503);
                $message = e((string) $maintenance['message']);
                $loginUrl = e(url('/login/'));
                echo '<!doctype html><html lang="' . e(current_locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
                echo '<title>' . e(i18n_text('Mantenimiento', 'Maintenance')) . '</title><link rel="stylesheet" href="' . e(asset_url('css/main.css')) . '"></head><body>';
                echo '<main class="main"><section class="panel panel--narrow"><h1>' . e(i18n_text('Mantenimiento', 'Maintenance')) . '</h1><p class="muted">' . $message . '</p>';
                echo '<div class="actions"><a class="button" href="' . $loginUrl . '">' . e(i18n_text('Login interno', 'Internal login')) . '</a></div></section></main></body></html>';
                exit;
            }
        }
    } catch (\Throwable) {
    }
}
