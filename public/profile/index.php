<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;

require_installed();
Auth::requireLogin();
$user = Auth::user();

Page::header('Perfil');
?>
<section class="panel">
    <h1>Perfil</h1>
    <p class="muted">Datos basicos de la cuenta conectada a la infraestructura.</p>
    <dl class="meta">
        <div>
            <dt>Usuario</dt>
            <dd><?= e($user['username'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Email</dt>
            <dd><?= e($user['email'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Estado</dt>
            <dd><?= e($user['status'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Roles</dt>
            <dd><?= e(implode(', ', $user['roles'] ?? [])) ?></dd>
        </div>
    </dl>
</section>
<?php
Page::footer();
