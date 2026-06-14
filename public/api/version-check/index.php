<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\OAuth;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$input = json_input();
$publicKey = trim((string) ($input['public_key'] ?? ''));
$clientVersion = trim((string) ($input['version'] ?? ''));

if ($publicKey === '') {
    api_response(false, 'public_key es requerido.', [], 422);
}

try {
    $game = OAuth::gameByPublicKey($publicKey);
    if (!$game) {
        api_response(false, 'API key de juego invalida o revocada.', [], 401);
    }

    $currentVersion = (string) ($game['current_version'] ?? '');
    $updateRequired = false;
    if ($clientVersion !== '' && $currentVersion !== '') {
        $updateRequired = version_compare($clientVersion, $currentVersion, '<');
        if (!$updateRequired && $clientVersion !== $currentVersion && version_compare($clientVersion, $currentVersion, '==')) {
            $updateRequired = false;
        }
    }

    api_response(true, 'OK', [
        'game' => OAuth::publicGamePayload($game),
        'client_version' => $clientVersion,
        'current_version' => $currentVersion !== '' ? $currentVersion : null,
        'update_required' => $updateRequired,
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
