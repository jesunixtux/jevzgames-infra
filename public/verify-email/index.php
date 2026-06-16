<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\EmailVerification;
use App\Models\PlatformSettings;
use App\Security\Csrf;

require_installed();

$settings = PlatformSettings::emailVerificationSettings();
$token = (string) ($_GET['token'] ?? '');

if ($token !== '') {
    try {
        EmailVerification::verifyToken($token);
        flash('message', 'Correo verificado. Ya puedes iniciar sesion.');
        redirect_to('/login/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/verify-email/');
    }
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/verify-email/');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Ingresa un correo valido.');
        redirect_to('/verify-email/');
    }

    try {
        EmailVerification::resendByEmail($email);
        flash('message', 'Si existe una cuenta pendiente para ese correo, se genero un nuevo enlace de verificacion.');
        redirect_to('/verify-email/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/verify-email/');
    }
}

Page::header('Verificar correo');
?>
<section class="panel panel--narrow">
    <h1>Verificar correo</h1>
    <?php if (!$settings['enabled']): ?>
        <p class="muted">La verificacion por correo esta deshabilitada.</p>
    <?php else: ?>
        <p class="muted">
            Solicita un nuevo enlace. En modo local <code>log</code>, el enlace queda en <code>storage/logs/app.log</code>.
        </p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="email">Correo</label>
                <input id="email" name="email" type="email" required>
            </div>
            <div class="actions">
                <button type="submit">Reenviar verificacion</button>
                <a class="button button--secondary" href="<?= e(url('/login/')) ?>">Volver al login</a>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php
Page::footer();
