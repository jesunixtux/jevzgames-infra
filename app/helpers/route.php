<?php
declare(strict_types=1);

function public_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($script === '') {
        return '';
    }

    $publicMarker = '/public/';
    $publicPos = strpos($script, $publicMarker);
    if ($publicPos !== false) {
        return rtrim(substr($script, 0, $publicPos + strlen('/public')), '/');
    }

    if (str_ends_with($script, '/public/index.php')) {
        return rtrim(substr($script, 0, -strlen('/index.php')), '/');
    }

    if (preg_match('#^(.*?)/(install|login|register|logout|games|oauth|profile|user|community|messages|notifications|support|api|admin|supporter|superroot)(?:/|$)#', $script, $matches)) {
        return rtrim($matches[1], '/');
    }

    $dir = dirname($script);
    return $dir === '/' || $dir === '\\' ? '' : rtrim($dir, '/');
}

function url(string $path = ''): string
{
    $baseUrl = trim((string) app_config('app.base_url', ''));
    if ($baseUrl === '') {
        $baseUrl = public_base_path();
    }

    $baseUrl = rtrim($baseUrl, '/');
    $path = '/' . ltrim($path, '/');

    if ($path === '/') {
        return $baseUrl === '' ? '/' : $baseUrl . '/';
    }

    return $baseUrl . $path;
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function current_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    return is_string($path) ? $path : '/';
}
