<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'JevzGames Infra',
        'base_url' => '',
        'environment' => 'development',
        'server' => 'apache',
        'installed_at' => null,
    ],
    'database' => [
        'host' => '',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'cdn' => [
        'enabled' => false,
        'url' => '',
    ],
    'session' => [
        'name' => 'JEVZGAMES_SESSION',
        'lifetime' => 7200,
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'api' => [
        'expose_errors' => false,
    ],
    'integrations' => [],
];
