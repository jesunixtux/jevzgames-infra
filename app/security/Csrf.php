<?php
declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            self::regenerate();
        }

        return (string) $_SESSION['_csrf_token'];
    }

    public static function regenerate(): string
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        return (string) $_SESSION['_csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . \e(self::token()) . '">';
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf_token'])
            && hash_equals((string) $_SESSION['_csrf_token'], $token);
    }

    public static function failRedirect(?string $path = null): never
    {
        self::regenerate();
        \flash('error', \function_exists('t') ? \t('csrf.changed') : 'Your session changed. Please try again.');
        \redirect_to(self::redirectTarget($path));
    }

    private static function redirectTarget(?string $path): string
    {
        $target = $path !== null && $path !== '' ? $path : self::currentRelativeUrl();
        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = '/';
        }

        if (isset($_GET['lang']) && !str_contains($target, 'lang=')) {
            $separator = str_contains($target, '?') ? '&' : '?';
            $target .= $separator . 'lang=' . rawurlencode((string) $_GET['lang']);
        }

        return $target;
    }

    private static function currentRelativeUrl(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $query = parse_url($uri, PHP_URL_QUERY);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $base = \function_exists('public_base_path') ? \public_base_path() : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
            $path = $path === '' ? '/' : $path;
        }

        $target = '/' . ltrim($path, '/');
        if (is_string($query) && $query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }
}
