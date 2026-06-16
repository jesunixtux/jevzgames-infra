<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\Game;
use App\Models\GameBuild;
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

    $gameId = (int) $token['game_id'];
    $userId = (int) $token['user_id'];
    $license = Game::licenseForUserGame($userId, $gameId);

    api_response(true, 'OK', [
        'licensed' => $license !== null,
        'license' => $license,
        'user' => [
            'id' => $userId,
            'username' => (string) $token['username'],
        ],
        'game' => [
            'id' => $gameId,
            'name' => (string) $token['game_name'],
            'slug' => (string) $token['game_slug'],
            'status' => (string) $token['game_status'],
            'current_version' => $token['current_version'] ?? null,
        ],
        'install_build' => GameBuild::latestForGame($gameId),
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
