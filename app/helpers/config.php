<?php
declare(strict_types=1);

function app_config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['app_config'] ?? [];

    if ($key === null || $key === '') {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}
