<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;

require_installed();

Page::header('Juegos');
?>
<section class="panel">
    <h1>Juegos</h1>
    <p class="muted">Base preparada para listar juegos registrados, sus versiones, configuracion y acceso desde APIs.</p>
    <p>La gestion completa de juegos queda para una fase posterior. La tabla y la estructura ya estan creadas.</p>
</section>
<?php
Page::footer();
