<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PasswordReset;
use App\Security\Csrf;

require_installed();

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/forgot-password/');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', i18n_text('Ingresa un correo valido.', 'Enter a valid email address.'));
        redirect_to('/forgot-password/');
    }

    try {
        PasswordReset::requestForEmail($email);
        flash('message', i18n_text(
            'Si existe una cuenta con ese correo, se envio un enlace para restablecer la contrasena.',
            'If an account exists for that email, a password reset link was sent.'
        ));
        redirect_to('/forgot-password/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/forgot-password/');
    }
}

Page::header(i18n_text('Restablecer contrasena', 'Reset password'));
?>
<section class="panel panel--narrow">
    <h1><?= e(i18n_text('Restablecer contrasena', 'Reset password')) ?></h1>
    <p class="muted">
        <?= e(i18n_text(
            'Escribe el correo de tu cuenta.',
            'Enter your account email.'
        )) ?>
    </p>
    <form class="form" method="post">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="email"><?= e(i18n_text('Correo', 'Email')) ?></label>
            <input id="email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="actions">
            <button type="submit"><?= e(i18n_text('Enviar enlace', 'Send link')) ?></button>
            <a class="button button--secondary" href="<?= e(url('/login/')) ?>"><?= e(i18n_text('Volver al login', 'Back to login')) ?></a>
        </div>
    </form>
</section>
<?php
Page::footer();
