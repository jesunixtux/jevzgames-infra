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
    header('Location: ' . SteamAuth::startConnectUrl($userId));
    exit;
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
    redirect_to('/profile/');
}
