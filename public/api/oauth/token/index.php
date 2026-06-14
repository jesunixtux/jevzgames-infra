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
$deviceCode = trim((string) ($input['device_code'] ?? ''));

if ($publicKey === '' || $deviceCode === '') {
    api_response(false, 'public_key y device_code son requeridos.', [], 422);
}

try {
    $result = OAuth::pollToken($deviceCode, $publicKey);
    if (($result['ready'] ?? false) === true) {
        api_response(true, 'authorized', $result);
    }

    api_response(false, (string) ($result['status'] ?? 'authorization_pending'), $result);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
