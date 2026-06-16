<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;
use App\Models\Game;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$token = bearer_token();
if ($token === null) {
    api_response(false, 'Bearer token requerido.', [], 401);
}

$input = json_input();

try {
    $session = ClientApp::authenticate($token);
    if (!$session) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    $gameId = (int) ($input['game_id'] ?? 0);
    if ($gameId <= 0 && !empty($input['slug'])) {
        $gameId = Game::gameIdBySlug((string) $input['slug']) ?? 0;
    }

    api_response(true, 'OK', ClientApp::obtainGame((int) $session['user_id'], $gameId));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
