<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Models\OAuth;
use App\Models\PublicProfile;
use App\Models\Game;

require_installed();

if (!request_is_post()) {
    api_response(false, 'Metodo no permitido.', [], 405);
}

$accessToken = bearer_token();
if ($accessToken === null || $accessToken === '') {
    api_response(false, 'Bearer token requerido.', [], 401);
}

try {
    $token = OAuth::authenticateToken($accessToken);
    if (!$token) {
        api_response(false, 'Token invalido o expirado.', [], 401);
    }

    $profile = PublicProfile::findByUserId((int) $token['user_id']);

    api_response(true, 'OK', [
        'user' => [
            'id' => (int) $token['user_id'],
            'username' => (string) $token['username'],
            'email' => (string) $token['email'],
            'display_name' => (string) ($profile['display_name'] ?? $token['username']),
            'avatar_url' => PublicProfile::avatarUrl($profile['avatar_path'] ?? ''),
            'profile_url' => url('/user/@' . rawurlencode((string) $token['username'])),
        ],
        'game' => [
            'id' => (int) $token['game_id'],
            'name' => (string) $token['game_name'],
            'slug' => (string) $token['game_slug'],
            'status' => (string) $token['game_status'],
            'current_version' => $token['current_version'] ?? null,
        ],
        'license' => Game::licenseForUserGame((int) $token['user_id'], (int) $token['game_id']),
        'linked' => true,
    ]);
} catch (Throwable $exception) {
    api_response(false, $exception->getMessage(), [], 400);
}
