<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$input = json_input();

try {
    api_response(true, 'OK', ClientApp::login(
        (string) ($input['identity'] ?? ''),
        (string) ($input['password'] ?? ''),
        (string) ($input['client_name'] ?? '')
    ));
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    $status = (str_contains($message, 'suspendida') || str_contains($message, 'verificar tu correo')) ? 403 : 400;
    api_response(false, $message, [], $status);
}
