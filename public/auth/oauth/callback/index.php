<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ExternalAuth;
use App\Security\Auth;

require_installed();

$provider = (string) ($_GET['provider'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$error = (string) ($_GET['error'] ?? '');

if ($error !== '') {
    flash('error', 'OAuth cancelado: ' . $error);
    redirect_to('/login/');
}

try {
    $userId = ExternalAuth::complete($provider, $code, $state);
    Auth::loginUserId($userId, true);
    flash('message', 'Sesion iniciada con OAuth.');
    redirect_to('/profile/');
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
    redirect_to('/login/');
}
