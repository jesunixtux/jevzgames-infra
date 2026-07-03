<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Game;
use App\Models\GameBuild;
use App\Models\PlatformSettings;
use App\Security\Auth;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$links = array_values(array_filter(Game::userLinks($userId), static function (array $game): bool {
    return in_array((string) $game['status'], Game::visibleStatuses(), true);
}));
$builds = GameBuild::latestForGames(array_map(static fn (array $game): int => (int) $game['game_id'], $links));
$contentSettings = PlatformSettings::contentSettings();
$clientEnabled = PlatformSettings::enabled('client');

Page::header('Biblioteca');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Biblioteca</h1>
            <p class="muted"><?= e($contentSettings['library_intro']) ?></p>
        </div>
        <a class="button button--secondary" href="<?= e(url('/games/')) ?>">Ver catalogo</a>
    </div>
</section>

<section class="grid">
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($links)) ?></span>
        <h2>Juegos</h2>
        <p class="muted">Vinculados a tu cuenta.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e(count(array_filter($builds))) ?></span>
        <h2>Instalables</h2>
        <p class="muted">Con build ZIP configurada.</p>
    </article>
</section>

<section class="panel">
    <h2>Mis juegos</h2>
    <?php if ($links === []): ?>
        <div class="empty-state">
            <h3>No tienes juegos vinculados</h3>
            <p class="muted">Cuando inicies sesion desde una app compatible u obtengas un juego, aparecera aqui automaticamente.</p>
            <a class="button button--secondary" href="<?= e(url('/games/')) ?>">Abrir catalogo</a>
        </div>
    <?php else: ?>
        <div class="game-grid">
            <?php foreach ($links as $game): ?>
                <?php
                $build = $builds[(int) $game['game_id']] ?? null;
                $buildIsZip = $build && (($build['delivery_type'] ?? 'zip') === 'zip') && !empty($build['download_url']);
                $buildIsExternal = $build && (($build['delivery_type'] ?? 'zip') === 'external_platform') && !empty($build['launch_url']);
                $canDownloadFromWeb = !$clientEnabled && $buildIsZip;
                ?>
                <article class="game-card">
                    <div class="game-card__header">
                        <h3><?= e($game['name']) ?></h3>
                        <span class="status-pill status-pill--<?= e((string) $game['status']) ?>">
                            <?= e(Game::statusLabel((string) $game['status'])) ?>
                        </span>
                    </div>
                    <dl class="meta">
                        <div><dt>Slug</dt><dd><code><?= e($game['slug']) ?></code></dd></div>
                        <div><dt>Version publica</dt><dd><?= e($game['current_version'] ?? 'Sin version') ?></dd></div>
                        <div><dt>Vinculado</dt><dd><?= e($game['linked_at']) ?></dd></div>
                        <div><dt>Licencia</dt><dd><?= !empty($game['license_id']) ? e(($game['license_source'] ?? 'manual') . ' / activa') : 'Sin licencia' ?></dd></div>
                        <div>
                            <dt>Build</dt>
                            <dd>
                                <?php if ($build): ?>
                                    <?= e($build['version']) ?> / <?= e($build['channel']) ?>
                                    <?php if ($buildIsExternal): ?>
                                        <br><span class="muted"><?= e((string) ($build['platform'] ?? 'plataforma externa')) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Sin build instalable
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                    <div class="actions">
                        <a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $game['slug']))) ?>">Ver juego</a>
                        <a class="button button--secondary" href="<?= e(url('/achievements/?game=' . (int) $game['game_id'])) ?>">Logros</a>
                        <?php if ($canDownloadFromWeb): ?>
                            <a class="button" href="<?= e($build['download_url']) ?>">Descargar build</a>
                        <?php elseif ($clientEnabled && $buildIsZip): ?>
                            <span class="muted">Instalable desde el cliente.</span>
                        <?php elseif ($buildIsExternal): ?>
                            <span class="muted">Se abre desde <?= e((string) ($build['platform'] ?? 'plataforma externa')) ?> usando el cliente.</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
Page::footer();
