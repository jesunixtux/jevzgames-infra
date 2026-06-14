<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();

if (Auth::check()) {
    redirect_to('/profile/');
}

$error = null;

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $error = 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.';
    } else {
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            if (Auth::attempt($identity, $password)) {
                redirect_to('/profile/');
            }
            $error = 'Credenciales invalidas o cuenta bloqueada.';
        } catch (Throwable) {
            $error = 'No se pudo iniciar sesion en este momento.';
        }
    }
}

Page::header('Login');
?>
<?php if ($error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel panel--narrow">
    <h1>Login</h1>
    <form class="form" method="post">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="identity">Email o usuario</label>
            <input id="identity" name="identity" value="<?= e($_POST['identity'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" required>
        </div>
        <div class="actions">
            <button type="submit">Entrar</button>
            <a class="button button--secondary" href="<?= e(url('/register/')) ?>">Crear cuenta</a>
        </div>
    </form>
</section>
<?php
Page::footer();
