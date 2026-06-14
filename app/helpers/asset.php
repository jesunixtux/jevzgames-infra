<?php
declare(strict_types=1);

function asset_url(string $path): string
{
    $path = ltrim($path, '/');

    if ((bool) app_config('cdn.enabled', false)) {
        $cdnUrl = trim((string) app_config('cdn.url', ''));
        if ($cdnUrl !== '') {
            return rtrim($cdnUrl, '/') . '/' . $path;
        }
    }

    return url('/assets/' . $path);
}
