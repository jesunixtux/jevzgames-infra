<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Notification;
use App\Models\PlatformSettings;
use App\Models\Presence;
use App\Security\Auth;

final class Page
{
    public static function header(string $title = ''): void
    {
        $appName = trim((string) \app_config('app.name', 'JevzGames'));
        if ($appName === '' || stripos($appName, 'infra') !== false) {
            $appName = 'JevzGames';
        }
        $fullTitle = $title !== '' ? $title . ' | ' . $appName : $appName;
        $user = Auth::user();
        $locale = \current_locale();
        if ($locale !== 'es' && empty($GLOBALS['i18n_output_translation_started'])) {
            $GLOBALS['i18n_output_translation_started'] = true;
            \ob_start('\i18n_translate_rendered_html');
        }
        $unreadNotifications = 0;
        if ($user && \is_installed()) {
            try {
                Presence::touch((int) ($user['id'] ?? 0), 'web');
                $unreadNotifications = Notification::unreadCount((int) ($user['id'] ?? 0));
            } catch (\Throwable) {
                $unreadNotifications = 0;
            }
        }
        ?>
<!doctype html>
<html lang="<?= \e($locale) ?>">
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
            <a href="<?= \e(\url('/games/')) ?>"><?= \e(\t('nav.games')) ?></a>
            <?php if (\is_installed()): ?>
                <a href="<?= \e(\url('/community/')) ?>"><?= \e(\t('nav.community')) ?></a>
                <?php if (PlatformSettings::enabled('publish_on_games')): ?>
                    <a href="<?= \e(\url('/publish-on-games/')) ?>"><?= \e(\t('nav.publish')) ?></a>
                <?php endif; ?>
                <?php if (PlatformSettings::enabled('workshop')): ?>
                    <a href="<?= \e(\url('/workshop/')) ?>"><?= \e(\t('nav.workshop')) ?></a>
                <?php endif; ?>
                <?php if (PlatformSettings::enabled('client')): ?>
                    <a href="<?= \e(\url('/client/')) ?>"><?= \e(\t('nav.client')) ?></a>
                <?php endif; ?>
                <button
                    type="button"
                    class="theme-toggle"
                    data-theme-toggle
                    data-dark-label="<?= \e(\t('nav.dark_mode')) ?>"
                    data-light-label="<?= \e(\t('nav.light_mode')) ?>"
                    aria-label="<?= \e(\t('nav.dark_mode')) ?>"
                ><?= \e(\t('nav.dark_mode')) ?></button>
                <span class="locale-switcher" aria-label="Language">
                    <?php foreach (\available_locales() as $availableLocale => $label): ?>
                        <a class="<?= $locale === $availableLocale ? 'locale-switcher__item locale-switcher__item--active' : 'locale-switcher__item' ?>" href="<?= \e(\locale_url((string) $availableLocale)) ?>">
                            <?= \e(strtoupper((string) $availableLocale)) ?>
                        </a>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
            <?php if (!\is_installed()): ?>
                <a href="<?= \e(\url('/install/')) ?>"><?= \e(\t('nav.install')) ?></a>
            <?php elseif ($user): ?>
                <a href="<?= \e(\url('/profile/')) ?>"><?= \e(\t('nav.profile')) ?></a>
                <a href="<?= \e(\url('/library/')) ?>"><?= \e(\t('nav.library')) ?></a>
                <a href="<?= \e(\url('/achievements/')) ?>"><?= \e(\t('nav.achievements')) ?></a>
                <a href="<?= \e(\url('/inventory/')) ?>"><?= \e(\t('nav.inventory')) ?></a>
                <a href="<?= \e(\url('/friends/')) ?>"><?= \e(\t('nav.friends')) ?></a>
                <a href="<?= \e(\url('/messages/')) ?>"><?= \e(\t('nav.messages')) ?></a>
                <a
                    class="nav__notifications <?= $unreadNotifications > 0 ? 'nav__alert' : '' ?>"
                    href="<?= \e(\url('/notifications/')) ?>"
                    data-notifications-link
                    data-poll-url="<?= \e(\url('/notifications/poll/')) ?>"
                >
                    <?= \e(\t('nav.notifications')) ?>
                    <span class="nav__badge" data-notifications-badge <?= $unreadNotifications > 0 ? '' : 'hidden' ?>>
                        <?= \e((string) min($unreadNotifications, 99)) ?>
                    </span>
                </a>
                <a href="<?= \e(\url('/support/')) ?>"><?= \e(\t('nav.support')) ?></a>
                <?php if (Auth::hasRole(['developer', 'developer-extern', 'admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/tutorials/')) ?>"><?= \e(\t('nav.tutorials')) ?></a>
                <?php endif; ?>
                <?php if (Auth::hasRole(['developer-extern', 'admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/external-games/')) ?>"><?= \e(\t('nav.external_games')) ?></a>
                <?php endif; ?>
                <?php if (Auth::hasRole(['admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/admin/')) ?>"><?= \e(\t('nav.admin')) ?></a>
                <?php endif; ?>
                <?php if (Auth::hasRole(['supporter', 'admin', 'superroot'])): ?>
                    <a href="<?= \e(\url('/supporter/')) ?>"><?= \e(\t('nav.support_panel')) ?></a>
                <?php endif; ?>
                <?php if (Auth::hasRole('superroot')): ?>
                    <a href="<?= \e(\url('/superroot/')) ?>"><?= \e(\t('nav.superroot')) ?></a>
                <?php endif; ?>
                <a href="<?= \e(\url('/logout/')) ?>"><?= \e(\t('nav.logout')) ?></a>
            <?php else: ?>
                <a href="<?= \e(\url('/login/')) ?>"><?= \e(\t('nav.login')) ?></a>
                <a href="<?= \e(\url('/register/')) ?>"><?= \e(\t('nav.register')) ?></a>
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
        $footerText = \i18n_text('JevzGames, juegos y comunidad.', 'JevzGames, games and community.');
        if (\is_installed()) {
            try {
                $configuredFooter = trim((string) (PlatformSettings::contentSettings()['footer_text'] ?? ''));
                if (
                    $configuredFooter !== ''
                    && stripos($configuredFooter, 'infraestructura') === false
                    && stripos($configuredFooter, 'infrastructure') === false
                ) {
                    $footerText = $configuredFooter;
                }
            } catch (\Throwable) {
            }
        }
        ?>
</main>
<footer class="site-footer">
    <span><?= \e($footerText) ?></span>
    <?php if (\is_installed()): ?>
        <span> &middot; <a href="<?= \e(\url('/eula/')) ?>"><?= \e(\t('nav.eula')) ?></a></span>
    <?php endif; ?>
</footer>
<script>
(function () {
    var toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
        var darkLabel = toggle.getAttribute('data-dark-label') || 'Dark mode';
        var lightLabel = toggle.getAttribute('data-light-label') || 'Light mode';
        var applyThemeLabel = function () {
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            toggle.textContent = isDark ? lightLabel : darkLabel;
            toggle.setAttribute('aria-label', isDark ? lightLabel : darkLabel);
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

(function () {
    var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-presence-user-id][data-presence-poll-url]'));
    if (nodes.length === 0) {
        return;
    }

    function applyPresence(node, presence) {
        if (!presence || !presence.status) {
            return;
        }

        node.classList.remove('presence-pill--online', 'presence-pill--offline', 'presence-pill--in_game');
        node.classList.add('presence-pill--' + presence.status);
        node.textContent = presence.label || '';
    }

    function pollPresence() {
        var seen = {};
        nodes.forEach(function (node) {
            var userId = node.getAttribute('data-presence-user-id');
            var pollUrl = node.getAttribute('data-presence-poll-url');
            if (!userId || !pollUrl) {
                return;
            }

            var key = pollUrl + ':' + userId;
            if (seen[key]) {
                return;
            }
            seen[key] = true;

            fetch(pollUrl + '?user_id=' + encodeURIComponent(userId), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (payload) {
                    if (!payload || !payload.success || !payload.data || !payload.data.presence) {
                        return;
                    }

                    nodes.forEach(function (candidate) {
                        if (
                            candidate.getAttribute('data-presence-user-id') === userId &&
                            candidate.getAttribute('data-presence-poll-url') === pollUrl
                        ) {
                            applyPresence(candidate, payload.data.presence);
                        }
                    });
                })
                .catch(function () {});
        });
    }

    window.setTimeout(pollPresence, 2000);
    window.setInterval(pollPresence, 10000);
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
