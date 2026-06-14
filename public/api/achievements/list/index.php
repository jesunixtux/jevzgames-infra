<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\Achievement;
use App\Models\OAuth;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$accessToken = bearer_token();
if ($accessToken === null || $accessToken === '') {
    api_response(false, 'Bearer token requerido.', [], 401);
}

try {
    $token = OAuth::authenticateToken($accessToken);
    if (!$token) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    api_response(true, 'OK', [
        'achievements' => Achievement::listForPlayer((int) $token['game_id'], (int) $token['user_id']),
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
