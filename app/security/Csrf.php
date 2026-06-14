<?php
declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

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
}
