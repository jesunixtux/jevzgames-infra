<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;

require_installed();
Auth::requireRole(['supporter', 'admin', 'superroot']);

Page::header('Soporte');
?>
<section class="panel">
    <h1>Panel Supporter</h1>
    <p class="muted">Base protegida para solicitudes y conversaciones de soporte.</p>
    <ul class="list">
        <li>Listado de tickets: pendiente de fase posterior.</li>
        <li>Chat con polling/AJAX: pendiente de fase posterior.</li>
        <li>Cierre y solucion: pendiente de fase posterior.</li>
    </ul>
</section>
<?php
Page::footer();
