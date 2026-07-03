<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Game;
use App\Models\GameBuild;
use App\Models\PlatformSettings;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();

$user = Auth::user();
$userId = $user ? (int) $user['id'] : null;
$canManuallyLinkGames = Auth::hasRole(['admin', 'superroot']);
<<<<<<< Updated upstream
$canViewPrivateGames = Auth::hasRole(['admin', 'superroot']);
=======
>>>>>>> Stashed changes
$clientEnabled = PlatformSettings::enabled('client');
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = array_merge(['all'], Game::visibleStatuses());
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$selectedSlug = (string) ($_GET['game'] ?? '');
<<<<<<< Updated upstream
$selectedGame = $selectedSlug !== '' ? Game::findPublicBySlug($selectedSlug, $userId, $canViewPrivateGames) : null;
=======
$selectedGame = $selectedSlug !== '' ? Game::findPublicBySlug($selectedSlug, $userId, Auth::hasRole(['admin', 'superroot'])) : null;
>>>>>>> Stashed changes

if (request_is_post()) {
    if (!Auth::check()) {
        flash('error', 'Debes iniciar sesion para vincular juegos.');
        redirect_to('/login/');
    }

    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect();
    }

    $gameId = (int) ($_POST['game_id'] ?? 0);
    $slug = (string) ($_POST['slug'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($gameId <= 0) {
            throw new RuntimeException('Juego invalido.');
        }

        if ($action === 'link') {
            if (!$canManuallyLinkGames) {
                throw new RuntimeException('El vinculo manual solo esta disponible para Admin o Superroot. Los jugadores se vinculan al iniciar sesion desde una app o cliente compatible.');
            }
            Game::linkUser((int) $userId, $gameId);
            ActivityLogger::info('game_linked', ['user_id' => $userId, 'game_id' => $gameId]);
            flash('message', 'Juego vinculado a tu cuenta.');
        } elseif ($action === 'obtain') {
            $build = GameBuild::latestForGame($gameId);
            if ($build === null) {
                throw new RuntimeException('Este juego aun no tiene build instalable.');
            }
            Game::grantLicense((int) $userId, $gameId, 'web');
            ActivityLogger::info('game_obtained', ['user_id' => $userId, 'game_id' => $gameId]);
            flash('message', 'Juego agregado a tu biblioteca.');
        } elseif ($action === 'unlink') {
            Game::unlinkUser((int) $userId, $gameId, true);
            ActivityLogger::info('game_unlinked', ['user_id' => $userId, 'game_id' => $gameId]);
            flash('message', 'Juego desvinculado. Se borraron tus datos de ese juego en la plataforma.');
        } else {
            throw new RuntimeException('Accion no valida.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }

    redirect_to($slug !== '' ? '/games/?game=' . rawurlencode($slug) : '/games/');
}

$games = Game::publicGames($userId, $statusFilter, false, $canViewPrivateGames);
$links = $userId !== null ? Game::userLinks($userId) : [];
$builds = GameBuild::latestForGames(array_map(static fn (array $game): int => (int) $game['id'], $games));
$selectedBuild = $selectedGame ? GameBuild::latestForGame((int) $selectedGame['id']) : null;
$contentSettings = is_installed() ? PlatformSettings::contentSettings() : [];
$gamesIntro = trim((string) ($contentSettings['games_intro'] ?? ''));
if (
    $gamesIntro === ''
    || stripos($gamesIntro, 'infraestructura') !== false
    || stripos($gamesIntro, 'infrastructure') !== false
    || stripos($gamesIntro, 'API') !== false
    || stripos($gamesIntro, 'panel') !== false
) {
    $gamesIntro = i18n_text('Descubre juegos y builds disponibles.', 'Discover available games and builds.');
}

Page::header(t('nav.games'));
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e(t('nav.games')) ?></h1>
            <p class="muted"><?= e($gamesIntro) ?></p>
        </div>
        <?php if (Auth::hasRole(['admin', 'superroot'])): ?>
            <a class="button button--secondary" href="<?= e(url('/admin/?section=games')) ?>">Gestionar juegos</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($selectedSlug !== '' && !$selectedGame): ?>
    <div class="alert alert--error">El juego solicitado no existe o no esta visible.</div>
<?php endif; ?>

<?php if ($selectedGame): ?>
    <?php
    $config = Game::decodeJson($selectedGame['config_json'] ?? null);
    $endpoints = Game::decodeJson($selectedGame['endpoints_json'] ?? null);
    $cdn = Game::decodeJson($selectedGame['cdn_json'] ?? null);
<<<<<<< Updated upstream
    $selectedBuildIsZip = $selectedBuild && (($selectedBuild['delivery_type'] ?? 'zip') === 'zip') && !empty($selectedBuild['download_url']);
    $selectedBuildIsExternal = $selectedBuild && (($selectedBuild['delivery_type'] ?? 'zip') === 'external_platform') && !empty($selectedBuild['launch_url']);
    $selectedCanDownloadFromWeb = !$clientEnabled && $selectedBuildIsZip;
=======
    $selectedBuildCanDownloadOnWeb = $selectedBuild && !empty($selectedBuild['download_url']) && !$clientEnabled;
>>>>>>> Stashed changes
    ?>
    <section class="game-detail">
        <article class="panel">
            <div class="thread-header">
                <div>
                    <h2><?= e($selectedGame['name']) ?></h2>
                    <p class="muted">
                        <code><?= e($selectedGame['slug']) ?></code>
                        · <?= e(Game::statusLabel((string) $selectedGame['status'])) ?>
                        <?php if (!empty($selectedGame['current_version'])): ?>
                            · Version <?= e($selectedGame['current_version']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="status-pill status-pill--<?= e((string) $selectedGame['status']) ?>">
                    <?= e(Game::statusLabel((string) $selectedGame['status'])) ?>
                </span>
            </div>

            <p><?= nl2br(e($selectedGame['description'] ?? 'Sin descripcion publica.')) ?></p>

            <dl class="meta">
                <div><dt>Builds</dt><dd><?= e($selectedGame['build_count'] ?? 0) ?></dd></div>
                <div><dt>Visibilidad</dt><dd><?= e(Game::visibilityLabel((string) ($selectedGame['visibility'] ?? 'public'))) ?></dd></div>
                <div><dt>Ultima build</dt><dd><?= e($selectedGame['latest_build_at'] ?? 'Sin builds') ?></dd></div>
                <div><dt>Visibilidad</dt><dd><?= e(Game::visibilityLabel((string) ($selectedGame['visibility'] ?? 'public'))) ?></dd></div>
                <div><dt>Creado</dt><dd><?= e($selectedGame['created_at']) ?></dd></div>
            </dl>

            <div class="actions">
                <?php if (!$user): ?>
                    <a class="button" href="<?= e(url('/login/')) ?>">Iniciar sesion</a>
                <?php elseif ((int) $selectedGame['is_linked'] === 1): ?>
<<<<<<< Updated upstream
                    <?php if ($selectedCanDownloadFromWeb): ?>
                        <a class="button" href="<?= e($selectedBuild['download_url']) ?>">Descargar build</a>
                    <?php elseif ($clientEnabled && $selectedBuildIsZip): ?>
                        <span class="muted">Disponible desde el cliente.</span>
                    <?php elseif ($selectedBuildIsExternal): ?>
                        <span class="muted">Se abre desde <?= e((string) ($selectedBuild['platform'] ?? 'plataforma externa')) ?> usando el cliente.</span>
=======
                    <?php if ($selectedBuildCanDownloadOnWeb): ?>
                        <a class="button" href="<?= e($selectedBuild['download_url']) ?>">Descargar build</a>
                    <?php elseif ($selectedBuild && !empty($selectedBuild['download_url']) && $clientEnabled): ?>
                        <a class="button" href="<?= e(url('/client/')) ?>"><?= e(i18n_text('Instalar desde cliente', 'Install from client')) ?></a>
>>>>>>> Stashed changes
                    <?php endif; ?>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="unlink">
                        <input type="hidden" name="game_id" value="<?= e($selectedGame['id']) ?>">
                        <input type="hidden" name="slug" value="<?= e($selectedGame['slug']) ?>">
                        <button type="submit" class="button button--secondary">Desvincular y borrar datos</button>
                    </form>
                <?php elseif ($canManuallyLinkGames): ?>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="link">
                        <input type="hidden" name="game_id" value="<?= e($selectedGame['id']) ?>">
                        <input type="hidden" name="slug" value="<?= e($selectedGame['slug']) ?>">
                        <button type="submit">Vincular a mi cuenta</button>
                    </form>
                <?php elseif ($selectedBuild): ?>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="obtain">
                        <input type="hidden" name="game_id" value="<?= e($selectedGame['id']) ?>">
                        <input type="hidden" name="slug" value="<?= e($selectedGame['slug']) ?>">
                        <button type="submit">Obtener juego</button>
                    </form>
                <?php else: ?>
                    <span class="muted">El juego se vincula automaticamente cuando inicias sesion desde una app o cliente compatible.</span>
                <?php endif; ?>
                <?php if (!$selectedBuild): ?>
                    <span class="muted">Sin build instalable.</span>
                <?php endif; ?>
                <a class="button button--secondary" href="<?= e(url('/games/')) ?>">Volver al catalogo</a>
            </div>
        </article>

        <aside class="panel">
            <h3>Configuracion publica</h3>
            <?php if ($config === [] && $endpoints === [] && $cdn === []): ?>
                <p class="muted">Este juego aun no tiene configuracion publica registrada.</p>
            <?php else: ?>
                <?php if ($config !== []): ?>
                    <h4>Config</h4>
                    <pre class="code-view"><?= e(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if ($endpoints !== []): ?>
                    <h4>Endpoints</h4>
                    <pre class="code-view"><?= e(json_encode($endpoints, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if ($cdn !== []): ?>
                    <h4>CDN</h4>
                    <pre class="code-view"><?= e(json_encode($cdn, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        </aside>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="section-heading">
        <div>
            <h2>Catalogo</h2>
            <p class="muted">Los juegos archivados no se muestran publicamente.</p>
        </div>
        <form class="filter-bar filter-bar--inline" method="get">
            <label for="status">Estado</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= e($status === 'all' ? 'Todos' : Game::statusLabel($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($games === []): ?>
        <div class="empty-state">
            <h3>No hay juegos visibles</h3>
            <p class="muted">Crea un juego desde Admin y dejalo en desarrollo, playtest, beta o publicado.</p>
        </div>
    <?php else: ?>
        <div class="game-grid">
            <?php foreach ($games as $game): ?>
                <?php
                $build = $builds[(int) $game['id']] ?? null;
<<<<<<< Updated upstream
                $buildIsZip = $build && (($build['delivery_type'] ?? 'zip') === 'zip') && !empty($build['download_url']);
                $buildIsExternal = $build && (($build['delivery_type'] ?? 'zip') === 'external_platform') && !empty($build['launch_url']);
                $canDownloadFromWeb = !$clientEnabled && $buildIsZip;
=======
                $buildCanDownloadOnWeb = $build && !empty($build['download_url']) && !$clientEnabled;
>>>>>>> Stashed changes
                $description = (string) ($game['description'] ?? '');
                $shortDescription = $description !== ''
                    ? (strlen($description) > 160 ? substr($description, 0, 157) . '...' : $description)
                    : 'Sin descripcion publica.';
                ?>
                <article class="game-card">
                    <div class="game-card__header">
                        <h3><?= e($game['name']) ?></h3>
                        <span class="status-pill status-pill--<?= e((string) $game['status']) ?>">
                            <?= e(Game::statusLabel((string) $game['status'])) ?>
                        </span>
                    </div>
                    <p class="muted"><?= e($shortDescription) ?></p>
                    <dl class="meta">
                        <div><dt>Slug</dt><dd><code><?= e($game['slug']) ?></code></dd></div>
                        <div><dt>Version</dt><dd><?= e($game['current_version'] ?? 'Sin version') ?></dd></div>
                        <div><dt>Build</dt><dd><?= $build ? e($build['version'] . ' / ' . $build['channel']) : 'Sin build' ?></dd></div>
                        <div><dt>Visibilidad</dt><dd><?= e(Game::visibilityLabel((string) ($game['visibility'] ?? 'public'))) ?></dd></div>
                        <div><dt>Vinculado</dt><dd><?= ((int) $game['is_linked'] === 1) ? 'Si' : 'No' ?></dd></div>
                    </dl>
                    <div class="actions">
                        <a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $game['slug']))) ?>">Ver juego</a>
<<<<<<< Updated upstream
                        <?php if ($user && (int) $game['is_linked'] === 1 && $canDownloadFromWeb): ?>
                            <a class="button" href="<?= e($build['download_url']) ?>">Descargar</a>
                        <?php elseif ($user && (int) $game['is_linked'] === 1 && $clientEnabled && $buildIsZip): ?>
                            <span class="muted">Disponible en cliente</span>
                        <?php elseif ($user && (int) $game['is_linked'] === 1 && $buildIsExternal): ?>
                            <span class="muted">Abre <?= e((string) ($build['platform'] ?? 'plataforma')) ?> desde el cliente</span>
                        <?php elseif ($user && $build): ?>
=======
                        <?php if ($user && (int) $game['is_linked'] === 1 && $buildCanDownloadOnWeb): ?>
                            <a class="button" href="<?= e($build['download_url']) ?>">Descargar</a>
                        <?php elseif ($user && (int) $game['is_linked'] === 1 && $build && !empty($build['download_url']) && $clientEnabled): ?>
                            <a class="button" href="<?= e(url('/client/')) ?>"><?= e(i18n_text('Instalar desde cliente', 'Install from client')) ?></a>
                        <?php elseif ($user && $build && !empty($build['download_url'])): ?>
>>>>>>> Stashed changes
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="obtain">
                                <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
                                <input type="hidden" name="slug" value="<?= e($game['slug']) ?>">
                                <button type="submit">Obtener juego</button>
                            </form>
                        <?php elseif (!$build): ?>
                            <span class="muted">Sin build</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($user): ?>
    <section class="panel">
        <h2>Mis juegos vinculados</h2>
        <?php if ($links === []): ?>
            <p class="muted">Todavia no tienes juegos vinculados a tu cuenta.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Juego</th>
                            <th>Estado</th>
                            <th>Version</th>
                            <th>Vinculado</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($links as $link): ?>
                            <tr>
                                <td><?= e($link['name']) ?></td>
                                <td><?= e(Game::statusLabel((string) $link['status'])) ?></td>
                                <td><?= e($link['current_version'] ?? '') ?></td>
                                <td><?= e($link['linked_at']) ?></td>
                                <td><a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $link['slug']))) ?>">Abrir</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
Page::footer();
