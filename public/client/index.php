<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PlatformSettings;

require_installed();

$config = PlatformSettings::clientConfigPayload();

Page::header('Cliente');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e($config['name']) ?></h1>
            <p class="muted">Configuracion publica para montar un launcher tipo Steam.</p>
        </div>
        <span class="status-pill <?= !empty($config['enabled']) ? 'status-pill--published' : 'status-pill--archived' ?>">
            <?= !empty($config['enabled']) ? 'Activo' : 'Deshabilitado' ?>
        </span>
    </div>
</section>

<section class="panel">
    <h2>Config</h2>
    <dl class="meta">
        <div><dt>Version minima</dt><dd><?= e($config['min_version']) ?></dd></div>
        <div><dt>Descarga</dt><dd><?= $config['download_url'] !== '' ? '<a href="' . e($config['download_url']) . '">' . e($config['download_url']) . '</a>' : 'Sin URL' ?></dd></div>
    </dl>
    <h3>Endpoints</h3>
    <pre class="code-view"><?= e(json_encode($config['endpoints'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</section>
<?php
Page::footer();
