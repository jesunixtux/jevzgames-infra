<?php
declare(strict_types=1);

require dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;
use App\Models\DirectMessage;

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

    $toUserId = (int) ($input['to_user_id'] ?? 0);
    $message = (string) ($input['message'] ?? '');
    if ($toUserId <= 0) {
        api_response(false, 'to_user_id requerido.', [], 422);
    }

    api_response(true, 'OK', DirectMessage::clientSend((int) $session['user_id'], $toUserId, $message));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
