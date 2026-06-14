<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Page;
use App\Models\User;
use App\Services\ActivityLogger;

final class Auth
{
    private static ?array $cachedUser = null;

    public static function check(): bool
    {
        return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            self::$cachedUser = null;
            return null;
        }

        $userId = (int) $_SESSION['user_id'];
        if (self::$cachedUser !== null && (int) self::$cachedUser['id'] === $userId) {
            return self::$cachedUser;
        }

        try {
            self::$cachedUser = User::findByIdWithRoles($userId);
        } catch (\Throwable) {
            self::$cachedUser = null;
        }

        if (self::$cachedUser === null) {
            unset($_SESSION['user_id']);
        }

        return self::$cachedUser;
    }

    public static function hasRole(string|array $roles): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }

        $roles = is_array($roles) ? $roles : [$roles];
        $userRoles = $user['roles'] ?? [];

        return count(array_intersect($roles, $userRoles)) > 0;
    }

    public static function attempt(string $identity, string $password): bool
    {
        $user = User::findByEmailOrUsername($identity);
        if (!$user || ($user['status'] ?? '') === 'blocked') {
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$cachedUser = User::findByIdWithRoles((int) $user['id']);
        User::touchLastLogin((int) $user['id']);
        ActivityLogger::info('login_success', ['user_id' => (int) $user['id']]);

        return true;
    }

    public static function logout(): void
    {
        if (self::check()) {
            ActivityLogger::info('logout', ['user_id' => (int) $_SESSION['user_id']]);
        }

        self::$cachedUser = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            \flash('error', 'Debes iniciar sesion para continuar.');
            \redirect_to('/login/');
        }
    }

    public static function requireRole(string|array $roles): void
    {
        self::requireLogin();

        if (!self::hasRole($roles)) {
            http_response_code(403);
            Page::header('Acceso restringido');
            echo '<section class="panel"><h1>Acceso restringido</h1><p>No tienes permisos para entrar a esta seccion.</p></section>';
            Page::footer();
            exit;
        }
    }
}
