<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\GameDatabase;
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

    $result = GameDatabase::testForGameId((int) $token['game_id']);
    unset($result['database'], $result['server_version']);
    api_response(true, 'OK', [
        'database' => $result,
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
