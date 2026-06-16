<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\ExternalAuth;
use App\Models\UserAgreement;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();

function login_redirect_target(): string
{
    $redirect = $_SESSION['after_login_redirect'] ?? '/profile/';
    unset($_SESSION['after_login_redirect']);

    if (!is_string($redirect) || $redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
        return '/profile/';
    }

    return $redirect;
}

if (Auth::check()) {
    $currentUser = Auth::user();
    if ($currentUser && UserAgreement::needsAcceptance((int) $currentUser['id'])) {
        redirect_to('/eula/');
    }
    redirect_to(login_redirect_target());
}

$error = null;
$externalProviders = is_installed() ? ExternalAuth::loginProviders() : [];

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/login/');
    } else {
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = isset($_POST['remember_me']) && (string) $_POST['remember_me'] === '1';

        try {
            if (Auth::attempt($identity, $password, $remember)) {
                $target = login_redirect_target();
                $currentUser = Auth::user();
                if ($currentUser && UserAgreement::needsAcceptance((int) $currentUser['id'])) {
                    $_SESSION['after_eula_redirect'] = $target;
                    redirect_to('/eula/');
                }
                redirect_to($target);
            }
            $error = 'Credenciales invalidas o cuenta bloqueada.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable) {
            $error = 'No se pudo iniciar sesion en este momento.';
        }
    }
}

Page::header('Login');
?>
<?php if ($error): ?>
    <div class="alert alert--error">
        <?= e($error) ?>
        <?php if (str_contains($error, 'verificar tu correo')): ?>
            <div class="actions">
                <a class="button button--secondary" href="<?= e(url('/verify-email/')) ?>">Reenviar verificacion</a>
            </div>
        <?php endif; ?>
    </div>
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
        <label class="checkbox-field">
            <input type="checkbox" name="remember_me" value="1" <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
            Mantener sesion iniciada
        </label>
        <div class="actions">
            <button type="submit">Entrar</button>
            <a class="button button--secondary" href="<?= e(url('/register/')) ?>">Crear cuenta</a>
            <a class="button button--secondary" href="<?= e(url('/forgot-password/')) ?>"><?= e(i18n_text('Olvide mi contrasena', 'Forgot password')) ?></a>
        </div>
    </form>

    <?php if ($externalProviders !== []): ?>
        <div class="auth-providers">
            <h2>OAuth</h2>
            <div class="actions">
                <?php foreach ($externalProviders as $provider): ?>
                    <a class="button button--secondary" href="<?= e(url('/auth/oauth/start/?provider=' . rawurlencode((string) $provider['provider']))) ?>">
                        Entrar con <?= e($provider['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php
Page::footer();
