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

    $conversationUserId = (int) ($input['conversation_user_id'] ?? 0);
    if ($conversationUserId <= 0) {
        api_response(false, 'conversation_user_id requerido.', [], 422);
    }

    api_response(true, 'OK', DirectMessage::clientMarkRead((int) $session['user_id'], $conversationUserId));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
