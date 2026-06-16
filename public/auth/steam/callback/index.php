<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\SteamAuth;
use App\Security\Auth;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

try {
    SteamAuth::completeConnect($userId, $_GET);
    flash('message', t('connections.steam_connected'));
    redirect_to('/profile/');
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
    redirect_to('/profile/');
}
