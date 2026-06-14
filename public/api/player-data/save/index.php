<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\OAuth;
use App\Models\PlayerData;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$accessToken = bearer_token();
if ($accessToken === null || $accessToken === '') {
    api_response(false, 'Bearer token requerido.', [], 401);
}

$input = json_input();
$key = trim((string) ($input['key'] ?? ''));
$data = $input['data'] ?? null;

if ($key === '' || !is_array($data)) {
    api_response(false, 'key y data son requeridos.', [], 422);
}

try {
    $token = OAuth::authenticateToken($accessToken);
    if (!$token) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    api_response(true, 'OK', [
        'player_data' => PlayerData::save((int) $token['game_id'], (int) $token['user_id'], $key, $data),
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
