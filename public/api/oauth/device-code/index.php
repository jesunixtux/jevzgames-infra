<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\OAuth;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$input = json_input();
$publicKey = trim((string) ($input['public_key'] ?? ''));

if ($publicKey === '') {
    api_response(false, 'public_key es requerido.', [], 422);
}

try {
    api_response(true, 'OK', OAuth::createDeviceCode($publicKey));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
