<?php
declare(strict_types=1);

require dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;
use App\Models\Presence;

require_installed();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$token = bearer_token();
if ($token === null) {
    api_response(false, 'Bearer token requerido.', [], 401);
}

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? json_input() : $_GET;

try {
    $session = ClientApp::authenticate($token);
    if (!$session) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    $userId = (int) ($input['user_id'] ?? $session['user_id']);
    if ($userId <= 0) {
        $userId = (int) $session['user_id'];
    }

    api_response(true, 'OK', [
        'presence' => ClientApp::presencePayload(Presence::forUser($userId)),
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
