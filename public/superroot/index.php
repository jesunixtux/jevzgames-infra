<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;

require_installed();
Auth::requireRole('superroot');

Page::header('Superroot');
?>
<section class="panel">
    <h1>Panel Superroot</h1>
    <p class="muted">Base protegida para configuracion sensible de la infraestructura.</p>
    <ul class="list">
        <li>Configuracion global: pendiente de fase posterior.</li>
        <li>Configuracion CDN: pendiente de fase posterior.</li>
        <li>Integraciones externas: pendiente de fase posterior.</li>
        <li>Mantenimiento avanzado: pendiente de fase posterior.</li>
    </ul>
</section>
<?php
Page::footer();
