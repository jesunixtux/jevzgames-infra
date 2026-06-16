<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PasswordReset;
use App\Security\Csrf;

require_installed();

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenInfo = null;
$tokenError = null;

if ($token !== '') {
    try {
        $tokenInfo = PasswordReset::tokenInfo($token);
    } catch (Throwable $exception) {
        $tokenError = $exception->getMessage();
    }
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect($token !== '' ? '/reset-password/?token=' . rawurlencode($token) : '/reset-password/');
    }

    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($token === '' || $tokenError !== null) {
        flash('error', i18n_text('El enlace de recuperacion no es valido.', 'The reset link is not valid.'));
        redirect_to('/forgot-password/');
    }

    if (strlen($password) < 8) {
        flash('error', i18n_text('La contrasena debe tener al menos 8 caracteres.', 'Password must be at least 8 characters.'));
        redirect_to('/reset-password/?token=' . rawurlencode($token));
    }

    if ($password !== $passwordConfirm) {
        flash('error', i18n_text('Las contrasenas no coinciden.', 'Passwords do not match.'));
        redirect_to('/reset-password/?token=' . rawurlencode($token));
    }

    try {
        PasswordReset::resetWithToken($token, $password);
        flash('message', i18n_text('Contrasena actualizada. Ya puedes iniciar sesion.', 'Password updated. You can now sign in.'));
        redirect_to('/login/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/forgot-password/');
    }
}

Page::header(i18n_text('Nueva contrasena', 'New password'));
?>
<section class="panel panel--narrow">
    <h1><?= e(i18n_text('Nueva contrasena', 'New password')) ?></h1>
    <?php if ($token === '' || $tokenError !== null): ?>
        <div class="alert alert--error">
            <?= e($tokenError ?? i18n_text('El enlace de recuperacion es requerido.', 'A reset link is required.')) ?>
        </div>
        <div class="actions">
            <a class="button" href="<?= e(url('/forgot-password/')) ?>"><?= e(i18n_text('Solicitar otro enlace', 'Request another link')) ?></a>
            <a class="button button--secondary" href="<?= e(url('/login/')) ?>"><?= e(i18n_text('Volver al login', 'Back to login')) ?></a>
        </div>
    <?php else: ?>
        <p class="muted">
            <?= e(i18n_text('Crea una contrasena nueva para', 'Create a new password for')) ?>
            <strong><?= e((string) ($tokenInfo['email'] ?? '')) ?></strong>.
        </p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="field">
                <label for="password"><?= e(i18n_text('Nueva contrasena', 'New password')) ?></label>
                <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="password_confirm"><?= e(i18n_text('Confirmar contrasena', 'Confirm password')) ?></label>
                <input id="password_confirm" name="password_confirm" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="actions">
                <button type="submit"><?= e(i18n_text('Actualizar contrasena', 'Update password')) ?></button>
                <a class="button button--secondary" href="<?= e(url('/login/')) ?>"><?= e(i18n_text('Cancelar', 'Cancel')) ?></a>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php
Page::footer();
