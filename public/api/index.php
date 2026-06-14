<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

api_response(true, 'API JevzGames Infra', [
    'endpoints' => [
        url('/api/status/'),
    ],
]);
