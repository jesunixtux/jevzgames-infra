<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;

require_installed();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$token = bearer_token();
if ($token === null) {
    api_response(false, 'Bearer token requerido.', [], 401);
}

try {
    $session = ClientApp::authenticate($token);
    if (!$session) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    api_response(true, 'OK', ClientApp::me($session));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
