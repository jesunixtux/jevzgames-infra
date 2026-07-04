<?php
declare(strict_types=1);

require dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\ClientApp;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$token = bearer_token();
if ($token === null) {
    api_response(false, 'Bearer token requerido.', [], 401);
}

$input = json_input();
$progress = $input['progress_data'] ?? [];
if (!is_array($progress)) {
    $progress = [];
}

try {
    $session = ClientApp::authenticate($token);
    if (!$session) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    api_response(true, 'OK', ClientApp::unlockAchievement(
        (int) $session['user_id'],
        trim((string) ($input['game_slug'] ?? '')),
        trim((string) ($input['achievement_code'] ?? $input['code'] ?? '')),
        $progress
    ));
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
