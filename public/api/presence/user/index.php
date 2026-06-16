<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\Presence;
use App\Models\PublicProfile;

require_installed();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$userId = (int) ($_GET['user_id'] ?? 0);
$username = ltrim(trim((string) ($_GET['username'] ?? '')), '@');

try {
    if ($userId <= 0 && $username !== '') {
        $profile = PublicProfile::findByUsername($username);
        if (!$profile || ($profile['status'] ?? '') !== 'active') {
            api_response(false, 'Usuario no encontrado.', [], 404);
        }
        $userId = (int) $profile['id'];
    }

    if ($userId <= 0) {
        api_response(false, 'Usuario requerido.', [], 400);
    }

    $presence = Presence::forUser($userId);
    $presence['label'] = Presence::label($presence);

    api_response(true, 'OK', ['presence' => $presence]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
