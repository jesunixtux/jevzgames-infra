<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;
use App\Models\Presence;

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

    $status = (string) ($input['status'] ?? 'online');
    $gameSlug = trim((string) ($input['game_slug'] ?? ''));
    $gameId = (int) ($input['game_id'] ?? 0);

    $presence = $gameSlug !== ''
        ? Presence::setBySlug((int) $session['user_id'], $status, $gameSlug, 'client')
        : Presence::set((int) $session['user_id'], $status, $gameId > 0 ? $gameId : null, 'client');

    api_response(true, 'OK', ['presence' => $presence]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
