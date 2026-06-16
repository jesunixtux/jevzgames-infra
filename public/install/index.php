<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Installers\Installer;
use App\Security\Csrf;

$error = null;
$requirements = Installer::requirements();
$canInstall = Installer::canInstall();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$defaultBaseUrl = rtrim($scheme . '://' . $host . public_base_path(), '/');

if (installer_is_locked()) {
    Page::header('Instalador bloqueado');
    ?>
    <section class="panel">
        <h1>Instalador bloqueado</h1>
        <p>Ya existe <code>app/config/installed.lock</code>. Por seguridad el instalador no puede ejecutarse otra vez.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('/')) ?>">Volver al inicio</a>
        </div>
    </section>
    <?php
    Page::footer();
    exit;
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/install/');
    } else {
        try {
            Installer::install($_POST);
            flash('message', 'Instalacion completada. Ahora puedes iniciar sesion con el superroot.');
            redirect_to('/login/');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$value = static function (string $key, string $default = ''): string {
    return e((string) ($_POST[$key] ?? $default));
};

Page::header('Instalador');
?>
<?php if ($error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel">
    <h1>Instalacion inicial</h1>
    <p class="muted">Configura la base principal, la URL base, el CDN inicial y el usuario superroot. Al terminar se crea <code>installed.lock</code>.</p>

    <div class="requirements" aria-label="Requisitos">
        <?php foreach ($requirements as $requirement): ?>
            <div class="requirement">
                <div>
                    <strong><?= e($requirement['label']) ?></strong><br>
                    <span class="muted"><?= e($requirement['detail']) ?></span>
                </div>
                <span class="status <?= $requirement['ok'] ? 'status--ok' : 'status--fail' ?>">
                    <?= $requirement['ok'] ? 'OK' : 'Falta' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel">
    <h2>Datos de instalacion</h2>
    <form class="form" method="post">
        <?= Csrf::field() ?>

        <div class="form-grid">
            <div class="field">
                <label for="app_name">Nombre de la plataforma</label>
                <input id="app_name" name="app_name" value="<?= $value('app_name', 'JevzGames Infra') ?>" required maxlength="120">
            </div>
            <div class="field">
                <label for="base_url">URL base</label>
                <input id="base_url" name="base_url" value="<?= $value('base_url', $defaultBaseUrl) ?>" placeholder="http://localhost">
            </div>
            <div class="field">
                <label for="environment">Entorno</label>
                <select id="environment" name="environment">
                    <option value="development" <?= (($_POST['environment'] ?? 'development') === 'development') ? 'selected' : '' ?>>development</option>
                    <option value="production" <?= (($_POST['environment'] ?? '') === 'production') ? 'selected' : '' ?>>production</option>
                </select>
            </div>
            <div class="field">
                <label for="server">Servidor</label>
                <select id="server" name="server">
                    <option value="apache" <?= (($_POST['server'] ?? 'apache') === 'apache') ? 'selected' : '' ?>>Apache</option>
                    <option value="nginx" <?= (($_POST['server'] ?? '') === 'nginx') ? 'selected' : '' ?>>Nginx</option>
                </select>
            </div>
        </div>

        <h3>Base de datos principal</h3>
        <div class="form-grid">
            <div class="field">
                <label for="db_host">Host</label>
                <input id="db_host" name="db_host" value="<?= $value('db_host', '127.0.0.1') ?>" required>
            </div>
            <div class="field">
                <label for="db_port">Puerto</label>
                <input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= $value('db_port', '3306') ?>" required>
            </div>
            <div class="field">
                <label for="db_name">Nombre</label>
                <input id="db_name" name="db_name" value="<?= $value('db_name', 'jevzgames_main') ?>" required pattern="[A-Za-z0-9_]+">
            </div>
            <div class="field">
                <label for="db_user">Usuario</label>
                <input id="db_user" name="db_user" value="<?= $value('db_user', 'root') ?>" required>
            </div>
            <div class="field">
                <label for="db_password">Contrasena</label>
                <input id="db_password" name="db_password" type="password" value="<?= $value('db_password') ?>">
            </div>
        </div>

        <h3>CDN</h3>
        <div class="form-grid">
            <label class="checkbox-field">
                <input type="checkbox" name="cdn_enabled" value="1" <?= isset($_POST['cdn_enabled']) ? 'checked' : '' ?>>
                Usar CDN externa
            </label>
            <div class="field">
                <label for="cdn_url">URL CDN externa</label>
                <input id="cdn_url" name="cdn_url" value="<?= $value('cdn_url') ?>" placeholder="https://cdn.ejemplo.com">
            </div>
        </div>

        <h3>Usuario superroot</h3>
        <div class="form-grid">
            <div class="field">
                <label for="superroot_username">Usuario</label>
                <input id="superroot_username" name="superroot_username" value="<?= $value('superroot_username', 'superroot') ?>" required pattern="[A-Za-z0-9_]{3,30}">
            </div>
            <div class="field">
                <label for="superroot_email">Email</label>
                <input id="superroot_email" name="superroot_email" type="email" value="<?= $value('superroot_email') ?>" required>
            </div>
            <div class="field">
                <label for="superroot_password">Contrasena</label>
                <input id="superroot_password" name="superroot_password" type="password" minlength="10" required>
            </div>
            <div class="field">
                <label for="superroot_password_confirm">Confirmar contrasena</label>
                <input id="superroot_password_confirm" name="superroot_password_confirm" type="password" minlength="10" required>
            </div>
        </div>

        <div class="actions">
            <button type="submit" <?= $canInstall ? '' : 'disabled' ?>>Instalar</button>
        </div>
    </form>
</section>
<?php
Page::footer();
