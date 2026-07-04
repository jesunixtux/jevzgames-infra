<?php
declare(strict_types=1);

require dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;

require_installed();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'POST', ['GET', 'POST'], true)) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$token = bearer_token();
if ($token === null) {
    api_response(false, 'Bearer token requerido.', [], 401);
}

$input = request_is_post() ? json_input() : $_GET;

try {
    $session = ClientApp::authenticate($token);
    if (!$session) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    api_response(true, 'OK', ClientApp::achievements(
        (int) $session['user_id'],
        trim((string) ($input['game_slug'] ?? '')),
        (int) ($input['game_id'] ?? 0)
    ));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
