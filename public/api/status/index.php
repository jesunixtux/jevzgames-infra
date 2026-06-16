<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Database;
use App\Security\Auth;

$databaseConnected = false;
$isInternalUser = is_installed() && Auth::hasRole(['developer', 'admin', 'superroot']);

if (!$isInternalUser) {
    api_response(true, 'OK', [
        'status' => 'ok',
    ]);
}

if (is_installed()) {
    try {
        Database::pdo()->query('SELECT 1');
        $databaseConnected = true;
    } catch (Throwable) {
        $databaseConnected = false;
    }
}

api_response(true, 'OK', [
    'app' => [
        'name' => app_config('app.name', 'JevzGames'),
        'environment' => app_config('app.environment', 'development'),
        'base_url' => app_config('app.base_url', ''),
    ],
    'installed' => is_installed(),
    'database' => [
        'configured' => is_installed(),
        'connected' => $databaseConnected,
    ],
    'php' => [
        'version' => PHP_VERSION,
    ],
    'time' => date('c'),
]);
