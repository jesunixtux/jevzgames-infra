<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PlatformSettings;
use App\Models\UserAgreement;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();

function eula_redirect_target(): string
{
    $redirect = $_SESSION['after_eula_redirect'] ?? '/profile/';
    unset($_SESSION['after_eula_redirect']);

    if (!is_string($redirect) || $redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
        return '/profile/';
    }

    return $redirect;
}

$settings = PlatformSettings::eulaSettings();
$user = Auth::user();
$needsAcceptance = $user ? UserAgreement::needsAcceptance((int) $user['id']) : false;

if (request_is_post()) {
    if (!$user) {
        $_SESSION['after_login_redirect'] = '/eula/';
        redirect_to('/login/');
    }

    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/eula/');
    }

    if (!isset($_POST['accept_eula'])) {
        flash('error', 'Debes aceptar el EULA para continuar.');
        redirect_to('/eula/');
    }

    UserAgreement::acceptCurrent((int) $user['id']);
    flash('message', 'EULA aceptado.');
    redirect_to(eula_redirect_target());
}

Page::header('EULA');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e($settings['title']) ?></h1>
            <p class="muted">Version <?= e($settings['version']) ?></p>
        </div>
        <span class="status-pill <?= $settings['enabled'] ? 'status-pill--published' : 'status-pill--archived' ?>">
            <?= $settings['enabled'] ? 'Activo' : 'Deshabilitado' ?>
        </span>
    </div>
</section>

<section class="panel">
    <?php if (!$settings['enabled']): ?>
        <p class="muted">No hay EULA activo.</p>
    <?php else: ?>
        <div class="legal-text">
            <?= nl2br(e($settings['body'])) ?>
        </div>
        <?php if ($user): ?>
            <?php if ($needsAcceptance): ?>
                <form class="form" method="post">
                    <?= Csrf::field() ?>
                    <label class="checkbox-field">
                        <input type="checkbox" name="accept_eula" value="1" required>
                        Acepto esta version del EULA
                    </label>
                    <div class="actions">
                        <button type="submit">Aceptar y continuar</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="muted">Tu cuenta ya acepto la version vigente o no es obligatorio aceptarla.</p>
            <?php endif; ?>
        <?php elseif ($settings['required']): ?>
            <div class="actions">
                <a class="button" href="<?= e(url('/login/')) ?>">Iniciar sesion para aceptar</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
Page::footer();
