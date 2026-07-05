<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Security\Auth;

$data = [
    'status' => 'available',
];

if (Auth::hasRole(['developer', 'developer-extern', 'admin', 'superroot'])) {
    $data['endpoints'] = [
        url('/api/status/'),
        url('/api/game-info/'),
        url('/api/version-check/'),
        url('/api/oauth/device-code/'),
        url('/api/oauth/token/'),
        url('/api/user-profile/'),
        url('/api/game-database/status/'),
        url('/api/player-data/save/'),
        url('/api/player-data/get/'),
        url('/api/achievements/list/'),
        url('/api/achievements/progress/'),
        url('/api/achievements/unlock/'),
        url('/api/game-license/check/'),
        url('/api/inventory/list/'),
        url('/api/redeem/'),
        url('/api/cloud-saves/config/'),
        url('/api/cloud-saves/list/'),
        url('/api/cloud-saves/save/'),
        url('/api/cloud-saves/get/'),
        url('/api/presence/user/'),
        url('/api/client/config/'),
        url('/api/client/login/'),
        url('/api/client/me/'),
        url('/api/client/library/'),
        url('/api/client/obtain-game/'),
        url('/api/client/inventory/'),
        url('/api/client/redeem/'),
        url('/api/client/presence/'),
        url('/api/client/presence/status/'),
        url('/api/client/achievements/list/'),
        url('/api/client/achievements/unlock/'),
        url('/api/client/cloud/configs/'),
        url('/api/client/cloud/push/'),
        url('/api/client/cloud/pull/'),
        url('/api/client/groups/'),
        url('/api/client/family/'),
        url('/api/client/launcher/update-check/'),
        url('/api/client/messages/conversations/'),
        url('/api/client/messages/thread/'),
        url('/api/client/messages/send/'),
        url('/api/client/messages/mark-read/'),
        url('/api/client/logout/'),
        url('/api/developer/games/list/'),
        url('/api/developer/games/detail/'),
        url('/api/developer/api-keys/create/'),
        url('/api/developer/api-keys/revoke/'),
        url('/api/developer/games/test/'),
    ];
}

api_response(true, 'JevzGames API', $data);
