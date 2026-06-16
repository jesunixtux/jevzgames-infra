<?php
declare(strict_types=1);

require dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\DeveloperApi;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$accessToken = bearer_token();
if ($accessToken === null || $accessToken === '') {
    api_response(false, 'Bearer token requerido.', [], 401);
}

try {
    $context = DeveloperApi::context($accessToken);
    api_response(true, 'OK', ['games' => DeveloperApi::games($context)]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], DeveloperApi::statusForException($exception));
}
