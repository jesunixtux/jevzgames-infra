<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Notification;
use App\Models\PlatformSettings;
use App\Security\Auth;

final class Page
{
    public static function header(string $title = ''): void
    {
        $appName = (string) \app_config('app.name', 'JevzGames Infra');
        $fullTitle = $title !== '' ? $title . ' | ' . $appName : $appName;
        $user = Auth::user();
        $unreadNotifications = 0;
        if ($user && \is_installed()) {
            try {
                $unreadNotifications = Notification::unreadCount((int) ($user['id'] ?? 0));
            } catch (\Throwable) {
                $unreadNotifications = 0;
            }
        }
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \e($fullTitle) ?></title>
    <script>
    (function () {
        try {
            var theme = localStorage.getItem('jevzgames_theme');
            if (theme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (exception) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= \e(\asset_url('css/main.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="<?= \e(\url('/')) ?>"><?= \e($appName) ?></a>
        <nav class="nav" aria-label="Navegacion principal">
            <a href="<?= \e(\url('/games/')) ?>">Juegos</a>
            <?php if (\is_installed()): ?>
                <a href="<?= \e(\url('/community/')) ?>">Comunidad</a>
                <?php if (PlatformSettings::enabled('publish_on_games')): ?>
                    <a href="<?= \e(\url('/publish-on-games/')) ?>">Publicar</a>
                <?php endif; ?>
                <?php if (PlatformSettings::enabled('workshop')): ?>
                    <a href="<?= \e(\url('/workshop/')) ?>">Workshop</a>
                <?php endif; ?>
                <?php if (PlatformSettings::enabled('client')): ?>
                    <a href="<?= \e(\url('/client/')) ?>">Cliente</a>
                <?php endif; ?>
                <button type="button" class="theme-toggle" data-theme-toggle aria-label="Cambiar modo oscuro">Modo oscuro</button>
            <?php endif; ?>
            <?php if (!\is_installed()): ?>
                <a href="<?= \e(\url('/install/')) ?>">Instalar</a>
            <?php elseif ($user): ?>
                <a href="<?= \e(\url('/profile/')) ?>">Perfil</a>
                <a href="<?= \e(\url('/library/')) ?>">Biblioteca</a>
                <a href="<?= \e(\url('/achievements/')) ?>">Logros</a>
                <a href="<?= \e(\url('/inventory/')) ?>">Inventario</a>
                <a href="<?= \e(\url('/messages/')) ?>">Mensajes</a>
                <a
                    class="nav__notifications <?= $unreadNotifications > 0 ? 'nav__alert' : '' ?>"
                    href="<?= \e(\url('/notifications/')) ?>"
                    data-notifications-link
                    data-poll-url="<?= \e(\url('/notifications/poll/')) ?>"
                >
                    Notificaciones
                    <span class="nav__badge" data-notifications-badge <?= $unreadNotifications > 0 ? '' : 'hidden' ?>>
                        <?= \e((string) min($unreadNotifications, 99)) ?>
                    </span>
                </a>
                <a href="<?= \e(\url('/support/')) ?>">Soporte</a>
                <?php if (Auth::hasRole(['admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/admin/')) ?>">Admin</a>
                <?php endif; ?>
                <?php if (Auth::hasRole(['supporter', 'admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/supporter/')) ?>">Panel soporte</a>
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
        $footerText = 'JevzGames Infraestructura modular en PHP puro.';
        if (\is_installed()) {
            try {
                $footerText = PlatformSettings::contentSettings()['footer_text'] ?: $footerText;
            } catch (\Throwable) {
            }
        }
        ?>
</main>
<footer class="site-footer">
    <span><?= \e($footerText) ?></span>
    <?php if (\is_installed()): ?>
        <span> &middot; <a href="<?= \e(\url('/eula/')) ?>">EULA</a></span>
    <?php endif; ?>
</footer>
<script>
(function () {
    var toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
        var applyThemeLabel = function () {
            toggle.textContent = document.documentElement.getAttribute('data-theme') === 'dark' ? 'Modo claro' : 'Modo oscuro';
        };
        applyThemeLabel();
        toggle.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            if (next === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
            try {
                localStorage.setItem('jevzgames_theme', next === 'dark' ? 'dark' : 'light');
            } catch (exception) {}
            applyThemeLabel();
        });
    }

    document.querySelectorAll('[data-dismiss-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = button.closest('[data-dismissible-modal]');
            if (modal) {
                modal.remove();
            }
        });
    });
})();

(function () {
    var link = document.querySelector('[data-notifications-link]');
    if (!link) {
        return;
    }

    var badge = link.querySelector('[data-notifications-badge]');
    var pollUrl = link.getAttribute('data-poll-url');
    if (!badge || !pollUrl) {
        return;
    }

    function applyCount(count) {
        count = Number(count || 0);
        if (count > 0) {
            link.classList.add('nav__alert');
            badge.hidden = false;
            badge.textContent = String(Math.min(count, 99));
            return;
        }

        link.classList.remove('nav__alert');
        badge.hidden = true;
        badge.textContent = '0';
    }

    function poll() {
        fetch(pollUrl, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (payload) {
                if (payload && payload.success && payload.data) {
                    applyCount(payload.data.unread_count);
                }
            })
            .catch(function () {});
    }

    window.setTimeout(poll, 1000);
    window.setInterval(poll, 5000);
})();
</script>
</body>
</html>
        <?php
    }

    public static function flash(): void
    {
        $message = \flash('message');
        $error = \flash('error');
        $suspended = !empty($_SESSION['suspended_account_notice']);
        unset($_SESSION['suspended_account_notice']);

        if ($message !== null) {
            echo '<div class="alert alert--success">' . \e((string) $message) . '</div>';
        }

        if ($error !== null) {
            echo '<div class="alert alert--error">' . \e((string) $error) . '</div>';
        }

        if ($suspended) {
            echo '<div class="modal-backdrop" data-dismissible-modal>';
            echo '<section class="modal panel" role="alertdialog" aria-modal="true" aria-labelledby="suspended-title">';
            echo '<h2 id="suspended-title">Cuenta suspendida</h2>';
            echo '<p>Tu cuenta se encuentra suspendida. Contacta soporte si crees que esto es un error.</p>';
            echo '<div class="actions"><a class="button" href="' . \e(\url('/support/')) . '">Contactar soporte</a>';
            echo '<button type="button" class="button button--secondary" data-dismiss-modal>Cerrar</button></div>';
            echo '</section></div>';
        }
    }
}
