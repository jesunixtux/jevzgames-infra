<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\EmailVerification;
use App\Models\PlatformSettings;
use App\Models\User;
use App\Models\UserAgreement;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();

if (Auth::check()) {
    redirect_to('/profile/');
}

$errors = [];
$emailSettings = PlatformSettings::emailVerificationSettings();
$eulaSettings = PlatformSettings::eulaSettings();

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $errors[] = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            $errors[] = 'El usuario debe tener 3 a 30 caracteres: letras, numeros o guion bajo.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El email no es valido.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'La contrasena debe tener al menos 8 caracteres.';
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'Las contrasenas no coinciden.';
        }

        if ($eulaSettings['enabled'] && $eulaSettings['required'] && !isset($_POST['accept_eula'])) {
            $errors[] = 'Debes aceptar el EULA vigente para crear la cuenta.';
        }

        if ($errors === []) {
            try {
                $userId = User::create($username, $email, $password, 'user');
                if ($eulaSettings['enabled'] && $eulaSettings['required']) {
                    UserAgreement::acceptCurrent($userId);
                }
                if ($emailSettings['enabled']) {
                    EmailVerification::sendForUser($userId);
                }
                ActivityLogger::info('user_registered', ['user_id' => $userId]);
                $message = $emailSettings['enabled']
                    ? 'Cuenta creada. Revisa tu correo para verificarla. En XAMPP con modo log, el enlace queda en storage/logs/app.log.'
                    : 'Cuenta creada. Ahora puedes iniciar sesion.';
                flash('message', $message);
                redirect_to('/login/');
            } catch (Throwable) {
                $errors[] = 'No se pudo crear la cuenta. Revisa si el usuario o email ya existen.';
            }
        }
    }
}

Page::header('Registro');
?>
<?php if ($errors !== []): ?>
    <div class="alert alert--error">
        <ul class="list">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="panel panel--narrow">
    <h1>Registro</h1>
    <form class="form" method="post">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="username">Usuario</label>
            <input id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" required pattern="[A-Za-z0-9_]{3,30}">
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" minlength="8" required>
        </div>
        <div class="field">
            <label for="password_confirm">Confirmar contrasena</label>
            <input id="password_confirm" name="password_confirm" type="password" minlength="8" required>
        </div>
        <?php if ($eulaSettings['enabled']): ?>
            <label class="checkbox-field">
                <input type="checkbox" name="accept_eula" value="1" <?= !empty($_POST['accept_eula']) ? 'checked' : '' ?> <?= $eulaSettings['required'] ? 'required' : '' ?>>
                Acepto el <a href="<?= e(url('/eula/')) ?>" target="_blank" rel="noopener">EULA version <?= e($eulaSettings['version']) ?></a>
            </label>
        <?php endif; ?>
        <div class="actions">
            <button type="submit">Crear cuenta</button>
            <a class="button button--secondary" href="<?= e(url('/login/')) ?>">Ya tengo cuenta</a>
        </div>
    </form>
</section>
<?php
Page::footer();
