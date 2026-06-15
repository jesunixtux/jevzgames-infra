<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ExternalAuth;

require_installed();

$provider = (string) ($_GET['provider'] ?? '');

try {
    header('Location: ' . ExternalAuth::startUrl($provider));
    exit;
} catch (Throwable $exception) {
    flash('error', $exception->getMessage());
    redirect_to('/login/');
}
