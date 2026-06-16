<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PlatformSettings;
use App\Security\Auth;

$user = Auth::user();
$contentSettings = is_installed() ? PlatformSettings::contentSettings() : [];
$isInternalUser = $user && Auth::hasRole(['developer', 'admin', 'superroot']);
$canOpenAdmin = $user && Auth::hasRole(['admin', 'superroot']);
$canOpenSupport = $user && Auth::hasRole(['supporter', 'admin', 'superroot']);
$canOpenSuperroot = $user && Auth::hasRole('superroot');
$homeTitle = trim((string) ($contentSettings['home_title'] ?? ''));
$homeIntro = trim((string) ($contentSettings['home_intro'] ?? ''));

if ($homeTitle === '' || stripos($homeTitle, 'infra') !== false) {
    $homeTitle = (string) app_config('app.name', 'JevzGames');
}

if (
    $homeIntro === ''
    || stripos($homeIntro, 'infraestructura') !== false
    || stripos($homeIntro, 'infrastructure') !== false
    || stripos($homeIntro, 'API') !== false
    || stripos($homeIntro, 'panel') !== false
) {
    $homeIntro = i18n_text(
        'Tu biblioteca, amigos, logros e inventario en un solo lugar.',
        'Your library, friends, achievements and inventory in one place.'
    );
}

Page::header(i18n_text('Inicio', 'Home'));
?>
<section class="panel">
    <h1><?= e($homeTitle) ?></h1>
    <p class="muted"><?= e($homeIntro) ?></p>
    <?php if (!is_installed()): ?>
        <p><?= e(i18n_text('El sistema todavia no esta instalado. Ejecuta el instalador inicial para crear la configuracion privada y el usuario superroot.', 'The system is not installed yet. Run the initial installer to create the private configuration and superroot user.')) ?></p>
        <div class="actions">
            <a class="button" href="<?= e(url('/install/')) ?>"><?= e(i18n_text('Abrir instalador', 'Open installer')) ?></a>
        </div>
    <?php elseif ($user): ?>
        <p><?= e(i18n_text('Sesion activa como', 'Signed in as')) ?> <strong><?= e($user['username']) ?></strong>.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('/library/')) ?>"><?= e(t('nav.library')) ?></a>
            <a class="button button--secondary" href="<?= e(url('/games/')) ?>"><?= e(t('nav.games')) ?></a>
            <a class="button button--secondary" href="<?= e(url('/profile/')) ?>"><?= e(t('nav.profile')) ?></a>
        </div>
    <?php else: ?>
        <p><?= e(i18n_text('Inicia sesion para ver tus juegos, logros, amigos y mensajes.', 'Sign in to see your games, achievements, friends and messages.')) ?></p>
        <div class="actions">
            <a class="button" href="<?= e(url('/login/')) ?>"><?= e(t('nav.login')) ?></a>
            <a class="button button--secondary" href="<?= e(url('/register/')) ?>"><?= e(t('nav.register')) ?></a>
        </div>
    <?php endif; ?>
</section>

<?php if (is_installed()): ?>
<section class="grid" aria-label="<?= e(i18n_text('Accesos principales', 'Main shortcuts')) ?>">
    <article class="tile">
        <h2><?= e(t('nav.library')) ?></h2>
        <p class="muted"><?= e(i18n_text('Juegos vinculados o con licencia en tu cuenta.', 'Games linked or licensed to your account.')) ?></p>
        <?php if ($user): ?>
            <p><a href="<?= e(url('/library/')) ?>"><?= e(i18n_text('Abrir biblioteca', 'Open library')) ?></a></p>
        <?php endif; ?>
    </article>
    <article class="tile">
        <h2><?= e(t('nav.achievements')) ?></h2>
        <p class="muted"><?= e(i18n_text('Revisa los logros desbloqueados y tus puntos.', 'Review unlocked achievements and your points.')) ?></p>
        <?php if ($user): ?>
            <p><a href="<?= e(url('/achievements/')) ?>"><?= e(i18n_text('Ver logros', 'View achievements')) ?></a></p>
        <?php endif; ?>
    </article>
    <article class="tile">
        <h2><?= e(t('nav.community')) ?></h2>
        <p class="muted"><?= e(i18n_text('Encuentra juegos, amigos y conversaciones.', 'Find games, friends and conversations.')) ?></p>
        <p><a href="<?= e(url('/community/')) ?>"><?= e(i18n_text('Abrir comunidad', 'Open community')) ?></a></p>
    </article>
</section>
<?php endif; ?>

<?php if ($isInternalUser || $canOpenSupport): ?>
<section class="panel">
    <h2><?= e(i18n_text('Herramientas', 'Tools')) ?></h2>
    <p class="muted"><?= e(i18n_text('Accesos internos disponibles para tu rol.', 'Internal shortcuts available for your role.')) ?></p>
    <div class="actions">
        <?php if ($isInternalUser): ?>
            <a class="button button--secondary" href="<?= e(url('/tutorials/')) ?>"><?= e(t('nav.tutorials')) ?></a>
            <a class="button button--secondary" href="<?= e(url('/api/status/')) ?>"><?= e(i18n_text('Estado API', 'API status')) ?></a>
        <?php endif; ?>
        <?php if ($canOpenAdmin): ?>
            <a class="button button--secondary" href="<?= e(url('/admin/')) ?>"><?= e(t('nav.admin')) ?></a>
        <?php endif; ?>
        <?php if ($canOpenSupport): ?>
            <a class="button button--secondary" href="<?= e(url('/supporter/')) ?>"><?= e(t('nav.support_panel')) ?></a>
        <?php endif; ?>
        <?php if ($canOpenSuperroot): ?>
            <a class="button button--secondary" href="<?= e(url('/superroot/')) ?>"><?= e(t('nav.superroot')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php
Page::footer();
