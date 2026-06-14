<?php
declare(strict_types=1);

namespace App\Core;

use App\Security\Auth;

final class Page
{
    public static function header(string $title = ''): void
    {
        $appName = (string) \app_config('app.name', 'JevzGames Infra');
        $fullTitle = $title !== '' ? $title . ' | ' . $appName : $appName;
        $user = Auth::user();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \e($fullTitle) ?></title>
    <link rel="stylesheet" href="<?= \e(\asset_url('css/main.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="<?= \e(\url('/')) ?>"><?= \e($appName) ?></a>
        <nav class="nav" aria-label="Navegacion principal">
            <a href="<?= \e(\url('/games/')) ?>">Juegos</a>
            <?php if (!\is_installed()): ?>
                <a href="<?= \e(\url('/install/')) ?>">Instalar</a>
            <?php elseif ($user): ?>
                <a href="<?= \e(\url('/profile/')) ?>">Perfil</a>
                <?php if (Auth::hasRole(['admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/admin/')) ?>">Admin</a>
                <?php endif; ?>
                <?php if (Auth::hasRole(['supporter', 'admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/supporter/')) ?>">Soporte</a>
                <?php endif; ?>
                <?php if (Auth::hasRole('superroot')): ?>
                    <a href="<?= \e(\url('/superroot/')) ?>">Superroot</a>
                <?php endif; ?>
                <a href="<?= \e(\url('/logout/')) ?>">Salir</a>
            <?php else: ?>
                <a href="<?= \e(\url('/login/')) ?>">Login</a>
                <a href="<?= \e(\url('/register/')) ?>">Registro</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="main">
        <?php
        self::flash();
    }

    public static function footer(): void
    {
        ?>
</main>
<footer class="site-footer">
    <span>JevzGames Infraestructura modular en PHP puro.</span>
</footer>
</body>
</html>
        <?php
    }

    public static function flash(): void
    {
        $message = \flash('message');
        $error = \flash('error');

        if ($message !== null) {
            echo '<div class="alert alert--success">' . \e((string) $message) . '</div>';
        }

        if ($error !== null) {
            echo '<div class="alert alert--error">' . \e((string) $error) . '</div>';
        }
    }
}
