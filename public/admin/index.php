<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;

require_installed();
Auth::requireRole(['admin', 'superroot']);

Page::header('Admin');
?>
<section class="panel">
    <h1>Panel Admin</h1>
    <p class="muted">Base protegida para administracion de usuarios, juegos, logs, codigos y moderacion.</p>
    <ul class="list">
        <li>Gestion de usuarios: pendiente de fase posterior.</li>
        <li>Gestion de juegos: pendiente de fase posterior.</li>
        <li>Revision de logs: pendiente de fase posterior.</li>
    </ul>
</section>
<?php
Page::footer();
