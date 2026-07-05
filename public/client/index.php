<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\LauncherRepository;
use App\Models\PlatformSettings;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();

$config = PlatformSettings::clientConfigPayload();
$isSuperroot = Auth::hasRole('superroot');

if (request_is_post()) {
    if (!$isSuperroot) {
        flash('error', 'Solo Superroot puede modificar el repositorio del launcher.');
        redirect_to('/client/');
    }
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/client/');
    }

    try {
        LauncherRepository::save($_POST, (int) (Auth::user()['id'] ?? 0));
        flash('message', 'Release del launcher guardado.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect_to('/client/');
}

$latestWindows = LauncherRepository::latest('windows');
$releases = $isSuperroot ? LauncherRepository::all() : [];
$clientName = trim((string) ($config['name'] ?? 'JevzGames Client'));
$downloadUrl = $latestWindows['download_url'] ?? (string) ($config['download_url'] ?? '');

Page::header('Cliente');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e($config['name']) ?></h1>
            <p class="muted"><?= e(i18n_text('Cliente de escritorio para instalar juegos, sincronizar partidas, canjear codigos y ver mensajes.', 'Desktop client for installing games, syncing saves, redeeming codes and viewing messages.')) ?></p>
        </div>
        <span class="status-pill <?= !empty($config['enabled']) ? 'status-pill--published' : 'status-pill--archived' ?>">
            <?= !empty($config['enabled']) ? 'Activo' : 'Deshabilitado' ?>
        </span>
    </div>
</section>

<section class="panel">
    <h2><?= e($clientName) ?></h2>
    <dl class="meta">
        <div><dt><?= e(i18n_text('Sistema operativo', 'Operating system')) ?></dt><dd>Windows</dd></div>
        <div><dt><?= e(i18n_text('Version minima', 'Minimum version')) ?></dt><dd><?= e($config['min_version']) ?></dd></div>
        <?php if ($latestWindows): ?>
            <div><dt><?= e(i18n_text('Ultima version', 'Latest version')) ?></dt><dd><?= e($latestWindows['version']) ?></dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($downloadUrl !== ''): ?>
        <a class="button" href="<?= e($downloadUrl) ?>"><?= e(i18n_text('Descargar cliente', 'Download client')) ?></a>
    <?php else: ?>
        <p class="muted"><?= e(i18n_text('No hay descarga publicada todavia.', 'No download has been published yet.')) ?></p>
    <?php endif; ?>
</section>

<?php if ($isSuperroot): ?>
    <section class="panel">
        <h2>Endpoints</h2>
        <pre class="code-view"><?= e(json_encode($config['endpoints'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </section>

    <section class="panel">
        <h2><?= e(i18n_text('Repositorio del launcher', 'Launcher repository')) ?></h2>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="launcher_version">Version</label>
                    <input id="launcher_version" name="launcher_version" placeholder="0.1.11-beta" maxlength="60" required>
                </div>
                <div class="field">
                    <label for="launcher_os">OS</label>
                    <input id="launcher_os" name="launcher_os" value="windows" maxlength="60" required>
                </div>
                <div class="field">
                    <label for="launcher_download_url"><?= e(i18n_text('URL de descarga', 'Download URL')) ?></label>
                    <input id="launcher_download_url" name="launcher_download_url" placeholder="https://.../RacLauncher.zip" required>
                </div>
                <div class="field">
                    <label for="launcher_checksum_sha256">SHA-256</label>
                    <input id="launcher_checksum_sha256" name="launcher_checksum_sha256" maxlength="64">
                </div>
                <div class="field">
                    <label for="launcher_status"><?= e(i18n_text('Estado', 'Status')) ?></label>
                    <select id="launcher_status" name="launcher_status">
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="launcher_notes"><?= e(i18n_text('Notas', 'Notes')) ?></label>
                <textarea id="launcher_notes" name="launcher_notes" rows="3"></textarea>
            </div>
            <button type="submit"><?= e(i18n_text('Publicar release', 'Publish release')) ?></button>
        </form>
    </section>

    <section class="panel">
        <h2><?= e(i18n_text('Releases publicados', 'Published releases')) ?></h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Version</th><th>OS</th><th>URL</th><th>Estado</th></tr></thead>
                <tbody>
                    <?php if ($releases === []): ?>
                        <tr><td colspan="4"><?= e(i18n_text('No hay releases.', 'No releases.')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($releases as $release): ?>
                        <tr>
                            <td><?= e($release['version']) ?></td>
                            <td><?= e($release['os']) ?></td>
                            <td><a href="<?= e($release['download_url']) ?>"><?= e($release['download_url']) ?></a></td>
                            <td><?= e($release['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php
Page::footer();
