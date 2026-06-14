<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;

$user = Auth::user();

Page::header('Inicio');
?>
<section class="panel">
    <h1><?= e((string) app_config('app.name', 'JevzGames Infra')) ?></h1>
    <p class="muted">Infraestructura monolitica modular para usuarios, juegos, APIs y paneles internos.</p>
    <?php if (!is_installed()): ?>
        <p>El sistema todavia no esta instalado. Ejecuta el instalador inicial para crear la configuracion privada y el usuario superroot.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('/install/')) ?>">Abrir instalador</a>
        </div>
    <?php elseif ($user): ?>
        <p>Sesion activa como <strong><?= e($user['username']) ?></strong>.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('/profile/')) ?>">Ver perfil</a>
            <a class="button button--secondary" href="<?= e(url('/api/status/')) ?>">API status</a>
        </div>
    <?php else: ?>
        <p>La plataforma esta instalada. Puedes iniciar sesion o crear una cuenta normal.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('/login/')) ?>">Iniciar sesion</a>
            <a class="button button--secondary" href="<?= e(url('/register/')) ?>">Crear cuenta</a>
        </div>
    <?php endif; ?>
</section>

<section class="grid" aria-label="Modulos base">
    <article class="tile">
        <h2>Usuarios</h2>
        <p class="muted">Registro, login, logout, roles basicos y proteccion de sesion.</p>
    </article>
    <article class="tile">
        <h2>Juegos</h2>
        <p class="muted">Estructura lista para juegos con configuracion propia y APIs HTTP/JSON.</p>
    </article>
    <article class="tile">
        <h2>API</h2>
        <p class="muted">Respuesta JSON estandar y endpoint inicial <code>/api/status/</code>.</p>
    </article>
</section>
<?php
Page::footer();
