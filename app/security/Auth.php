<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\Page;
use App\Models\PlatformSettings;
use App\Models\Presence;
use App\Models\User;
use App\Services\ActivityLogger;
use RuntimeException;

final class Auth
{
    private const REMEMBER_COOKIE = 'JEVZGAMES_REMEMBER';
    private const REMEMBER_DAYS = 30;

    private static ?array $cachedUser = null;

    public static function check(): bool
    {
        if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
            self::restoreRememberedUser();
        }

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

        if (self::$cachedUser === null || (self::$cachedUser['status'] ?? '') === 'blocked') {
            if (self::$cachedUser !== null && (self::$cachedUser['status'] ?? '') === 'blocked') {
                $_SESSION['suspended_account_notice'] = true;
                self::revokeRememberCookie();
            }
            unset($_SESSION['user_id']);
            self::$cachedUser = null;
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

    public static function attempt(string $identity, string $password, bool $remember = false): bool
    {
        $user = User::findByEmailOrUsername($identity);
        if (!$user) {
            return false;
        }

        if (($user['status'] ?? '') === 'blocked') {
            $_SESSION['suspended_account_notice'] = true;
            throw new RuntimeException('Tu cuenta se encuentra suspendida.');
        }

        if (($user['status'] ?? '') !== 'active') {
            return false;
        }

        if (PlatformSettings::emailVerificationRequired() && !User::isEmailVerified($user)) {
            throw new RuntimeException('Debes verificar tu correo antes de iniciar sesion.');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$cachedUser = User::findByIdWithRoles((int) $user['id']);
        User::touchLastLogin((int) $user['id']);
        try {
            Presence::set((int) $user['id'], 'online', null, 'web');
        } catch (\Throwable) {
        }
        if ($remember) {
            self::rememberUser((int) $user['id']);
        } else {
            self::revokeRememberCookie();
        }
        ActivityLogger::info('login_success', ['user_id' => (int) $user['id']]);

        return true;
    }

    public static function loginUserId(int $userId, bool $remember = false): void
    {
        $user = User::findByIdWithRoles($userId);
        if (!$user) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        if (($user['status'] ?? '') === 'blocked') {
            $_SESSION['suspended_account_notice'] = true;
            throw new RuntimeException('Tu cuenta se encuentra suspendida.');
        }

        if (($user['status'] ?? '') !== 'active') {
            throw new RuntimeException('La cuenta no esta activa.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$cachedUser = $user;
        if ($remember) {
            self::rememberUser($userId);
        }
        User::touchLastLogin($userId);
        try {
            Presence::set($userId, 'online', null, 'oauth');
        } catch (\Throwable) {
        }
        ActivityLogger::info('login_success', ['user_id' => $userId, 'provider' => 'external_oauth']);
    }

    public static function logout(): void
    {
        if (self::check()) {
            try {
                Presence::offline((int) $_SESSION['user_id']);
            } catch (\Throwable) {
            }
            ActivityLogger::info('logout', ['user_id' => (int) $_SESSION['user_id']]);
        }

        self::revokeRememberCookie();
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
            self::rememberRequestedRoute();
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

    private static function rememberRequestedRoute(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return;
        }

        $base = \public_base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $target = '/' . ltrim($path, '/');
        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $target .= '?' . $query;
        }

        if ($target !== '/login/' && $target !== '/login' && !str_starts_with($target, '//')) {
            $_SESSION['after_login_redirect'] = $target;
        }
    }

    public static function ensureRememberTable(): void
    {
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS auth_remember_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                selector VARCHAR(32) NOT NULL UNIQUE,
                token_hash VARCHAR(128) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                INDEX idx_auth_remember_tokens_user (user_id),
                INDEX idx_auth_remember_tokens_expires (expires_at),
                CONSTRAINT fk_auth_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function restoreRememberedUser(): void
    {
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return;
        }

        [$selector, $token] = explode(':', $cookie, 2);
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            self::clearRememberCookie();
            return;
        }

        try {
            self::ensureRememberTable();
            Database::pdo()->prepare('DELETE FROM auth_remember_tokens WHERE expires_at < NOW()')->execute();
            $stmt = Database::pdo()->prepare(
                'SELECT rt.*, u.status, u.email_verified_at
                 FROM auth_remember_tokens rt
                 INNER JOIN users u ON u.id = rt.user_id
                 WHERE rt.selector = :selector
                 LIMIT 1'
            );
            $stmt->execute(['selector' => $selector]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            return;
        }

        if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            self::clearRememberCookie();
            return;
        }

        if (($row['status'] ?? '') === 'blocked') {
            $_SESSION['suspended_account_notice'] = true;
            self::revokeRememberCookie();
            return;
        }

        if (($row['status'] ?? '') !== 'active') {
            self::revokeRememberCookie();
            return;
        }

        if (PlatformSettings::emailVerificationRequired() && empty($row['email_verified_at'])) {
            self::revokeRememberCookie();
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        self::$cachedUser = User::findByIdWithRoles((int) $row['user_id']);
        Database::pdo()->prepare('UPDATE auth_remember_tokens SET last_used_at = NOW() WHERE id = :id')->execute(['id' => (int) $row['id']]);
    }

    private static function rememberUser(int $userId): void
    {
        self::ensureRememberTable();
        self::revokeRememberCookie();

        $selector = bin2hex(random_bytes(12));
        $token = bin2hex(random_bytes(32));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at, created_at)
             VALUES (:user_id, :selector, :token_hash, DATE_ADD(NOW(), INTERVAL ' . self::REMEMBER_DAYS . ' DAY), NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
        ]);

        self::setRememberCookie($selector . ':' . $token, time() + self::REMEMBER_DAYS * 86400);
    }

    private static function revokeRememberCookie(): void
    {
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
                try {
                    self::ensureRememberTable();
                    Database::pdo()->prepare('DELETE FROM auth_remember_tokens WHERE selector = :selector')->execute(['selector' => $selector]);
                } catch (\Throwable) {
                }
            }
        }

        self::clearRememberCookie();
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => \public_base_path() !== '' ? \public_base_path() . '/' : '/',
            'secure' => (bool) \app_config('session.secure', false),
            'httponly' => true,
            'samesite' => (string) \app_config('session.samesite', 'Lax'),
        ]);
    }

    private static function clearRememberCookie(): void
    {
        self::setRememberCookie('', time() - 42000);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }
}
