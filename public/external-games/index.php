<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\ExternalGames;
use App\Models\Game;
use App\Models\GameBuild;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();
Auth::requireRole(['developer-extern', 'admin', 'superroot']);

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canManageAll = Auth::hasRole(['admin', 'superroot']);
$settings = ExternalGames::settings();
$stats = ExternalGames::stats();
$externalGames = [];
$editingGame = null;
$editingMainGame = null;
$gameBuilds = [];
$players = [];

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/external-games/');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_external_game') {
            $externalGameId = ExternalGames::saveGame($userId, $_POST, $canManageAll);
            ActivityLogger::info('external_game_saved', ['user_id' => $userId, 'external_game_id' => $externalGameId]);
            flash('message', 'Juego externo guardado.');
            redirect_to('/external-games/?edit_game=' . $externalGameId);
        }

        if ($action === 'save_external_game_build_upload') {
            $managed = ExternalGames::managedMainGame((int) ($_POST['external_game_id'] ?? 0), $userId, $canManageAll);
            $input = $_POST;
            $input['game_id'] = (int) $managed['main_game']['id'];
            $buildId = GameBuild::saveUpload($input, $_FILES['build_zip'] ?? []);
            ActivityLogger::info('external_game_build_saved', ['user_id' => $userId, 'build_id' => $buildId, 'external_game_id' => (int) ($_POST['external_game_id'] ?? 0)]);
            flash('message', 'Build ZIP guardada.');
            redirect_to('/external-games/?edit_game=' . (int) ($_POST['external_game_id'] ?? 0));
        }

        if ($action === 'save_external_game_build_remote') {
            $managed = ExternalGames::managedMainGame((int) ($_POST['external_game_id'] ?? 0), $userId, $canManageAll);
            $input = $_POST;
            $input['game_id'] = (int) $managed['main_game']['id'];
            $buildId = GameBuild::saveRemote($input);
            ActivityLogger::info('external_remote_game_build_saved', ['user_id' => $userId, 'build_id' => $buildId, 'external_game_id' => (int) ($_POST['external_game_id'] ?? 0)]);
            flash('message', 'Build remota guardada.');
            redirect_to('/external-games/?edit_game=' . (int) ($_POST['external_game_id'] ?? 0));
        }

        if ($action === 'save_external_game_platform_build') {
            $managed = ExternalGames::managedMainGame((int) ($_POST['external_game_id'] ?? 0), $userId, $canManageAll);
            $input = $_POST;
            $input['game_id'] = (int) $managed['main_game']['id'];
            $buildId = GameBuild::saveExternalPlatform($input);
            ActivityLogger::info('external_platform_game_build_saved', ['user_id' => $userId, 'build_id' => $buildId, 'external_game_id' => (int) ($_POST['external_game_id'] ?? 0)]);
            flash('message', 'Version de plataforma externa guardada.');
            redirect_to('/external-games/?edit_game=' . (int) ($_POST['external_game_id'] ?? 0));
        }

        if ($action === 'delete_external_game_build') {
            $managed = ExternalGames::managedMainGameByBuild((int) ($_POST['build_id'] ?? 0), $userId, $canManageAll);
            GameBuild::delete((int) ($_POST['build_id'] ?? 0));
            ActivityLogger::info('external_game_build_deleted', ['user_id' => $userId, 'build_id' => (int) ($_POST['build_id'] ?? 0)]);
            flash('message', 'Build eliminada.');
            redirect_to('/external-games/?edit_game=' . (int) $managed['external_game']['id']);
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/external-games/');
    }
}

if ($settings['enabled'] && $settings['allow_publish'] && $settings['configured']) {
    try {
        $externalGames = ExternalGames::gamesForUser($userId, $canManageAll);
        $editGameId = (int) ($_GET['edit_game'] ?? 0);
        if ($editGameId > 0) {
            $editingGame = ExternalGames::findGame($editGameId, $userId, $canManageAll);
            if ($editingGame !== null) {
                $managed = ExternalGames::managedMainGame($editGameId, $userId, $canManageAll);
                $editingMainGame = $managed['main_game'];
                $gameBuilds = GameBuild::list((int) $managed['main_game']['id']);
                $players = ExternalGames::playerRows($editGameId, $userId, $canManageAll);
            }
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
}

Page::header(i18n_text('Juegos externos', 'External games'));
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e(i18n_text('Juegos externos', 'External games')) ?></h1>
            <p class="muted"><?= e(i18n_text('Publica y configura juegos de terceros. El slug se genera automaticamente.', 'Publish and configure third-party games. The slug is generated automatically.')) ?></p>
        </div>
        <span class="status-pill <?= ($settings['enabled'] && $settings['allow_publish'] && $settings['configured']) ? 'status-pill--published' : 'status-pill--archived' ?>">
            <?= ($settings['enabled'] && $settings['allow_publish'] && $settings['configured']) ? e(i18n_text('Activo', 'Active')) : e(i18n_text('Deshabilitado', 'Disabled')) ?>
        </span>
    </div>
</section>

<?php if (!$settings['enabled'] || !$settings['allow_publish'] || !$settings['configured']): ?>
    <section class="panel">
        <h2><?= e(i18n_text('Configuracion requerida', 'Configuration required')) ?></h2>
        <p class="muted"><?= e(i18n_text('Superroot debe activar juegos externos, permitir publicacion y conectar una base de datos externa antes de usar este apartado.', 'Superroot must enable external games, allow publishing and connect an external database before this section can be used.')) ?></p>
        <?php if (Auth::hasRole('superroot')): ?>
            <div class="actions">
                <a class="button button--secondary" href="<?= e(url('/superroot/?section=extern-games-config')) ?>"><?= e(i18n_text('Abrir configuracion', 'Open configuration')) ?></a>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="grid">
        <article class="tile metric-tile">
            <span class="metric"><?= e(count($externalGames)) ?></span>
            <h2><?= e(i18n_text('Tus juegos', 'Your games')) ?></h2>
            <p class="muted"><?= e($canManageAll ? i18n_text('Vista global.', 'Global view.') : i18n_text('Solo los que te pertenecen.', 'Only games you own.')) ?></p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['external_players']) ?></span>
            <h2><?= e(i18n_text('Jugadores externos', 'External players')) ?></h2>
            <p class="muted"><?= e($stats['message']) ?></p>
        </article>
    </section>

    <section class="panel">
        <h2><?= e($editingGame ? i18n_text('Editar juego externo', 'Edit external game') : i18n_text('Nuevo juego externo', 'New external game')) ?></h2>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_external_game">
            <input type="hidden" name="external_game_id" value="<?= e($editingGame['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="name"><?= e(i18n_text('Nombre', 'Name')) ?></label>
                    <input id="name" name="name" value="<?= e($editingGame['name'] ?? '') ?>" maxlength="140" required>
                </div>
                <div class="field">
                    <label><?= e(i18n_text('Slug aleatorio', 'Random slug')) ?></label>
                    <input value="<?= e($editingGame['slug'] ?? i18n_text('Se genera al crear.', 'Generated on create.')) ?>" disabled>
                </div>
                <div class="field">
                    <label for="status"><?= e(i18n_text('Estado', 'Status')) ?></label>
                    <select id="status" name="status">
                        <?php foreach (ExternalGames::statuses() as $status): ?>
                            <option value="<?= e($status) ?>" <?= (($editingGame['status'] ?? 'development') === $status) ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="visibility"><?= e(i18n_text('Visibilidad', 'Visibility')) ?></label>
                    <select id="visibility" name="visibility">
                        <?php foreach (ExternalGames::visibilities() as $visibility): ?>
                            <option value="<?= e($visibility) ?>" <?= (($editingGame['visibility'] ?? 'private') === $visibility) ? 'selected' : '' ?>><?= e(Game::visibilityLabel($visibility)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="current_version"><?= e(i18n_text('Version actual', 'Current version')) ?></label>
                    <input id="current_version" name="current_version" value="<?= e($editingGame['current_version'] ?? '') ?>" maxlength="60">
                </div>
                <div class="field">
                    <label for="developer_name"><?= e(i18n_text('Desarrolladora', 'Developer')) ?></label>
                    <input id="developer_name" name="developer_name" value="<?= e($editingGame['developer_name'] ?? '') ?>" maxlength="140">
                </div>
                <div class="field">
                    <label for="publisher_name">Publisher</label>
                    <input id="publisher_name" name="publisher_name" value="<?= e($editingGame['publisher_name'] ?? '') ?>" maxlength="140">
                </div>
            </div>
            <div class="field">
                <label for="description"><?= e(i18n_text('Descripcion', 'Description')) ?></label>
                <textarea id="description" name="description" rows="4" maxlength="5000"><?= e($editingGame['description'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label for="config_json"><?= e(i18n_text('Config JSON', 'Config JSON')) ?></label>
                <textarea id="config_json" name="config_json" rows="5" placeholder='{"client":{"offline_allowed":true}}'><?= e($editingGame['config_json'] ?? '') ?></textarea>
            </div>
            <div class="actions">
                <button type="submit"><?= e($editingGame ? i18n_text('Guardar cambios', 'Save changes') : i18n_text('Crear juego', 'Create game')) ?></button>
                <?php if ($editingGame): ?>
                    <a class="button button--secondary" href="<?= e(url('/external-games/')) ?>"><?= e(i18n_text('Nuevo juego', 'New game')) ?></a>
                    <?php if ($editingMainGame): ?>
                        <a class="button button--secondary" href="<?= e(url('/games-code/?game_id=' . (int) $editingMainGame['id'])) ?>"><?= e(i18n_text('Codigos', 'Codes')) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($editingGame['slug'])): ?>
                        <a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $editingGame['slug']))) ?>"><?= e(i18n_text('Ver juego', 'View game')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if ($editingGame): ?>
        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2><?= e(i18n_text('Builds del juego', 'Game builds')) ?></h2>
                    <p class="muted"><?= e(i18n_text('Sube un ZIP, registra una URL o apunta a Steam/otra plataforma para que el launcher pueda instalar o abrir el juego.', 'Upload a ZIP, register a URL or point to Steam/another platform so the launcher can install or open the game.')) ?></p>
                </div>
                <span class="status-pill"><?= e($editingGame['slug'] ?? '') ?></span>
            </div>

            <form class="form" method="post" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_external_game_build_upload">
                <input type="hidden" name="external_game_id" value="<?= e($editingGame['id']) ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="external_build_version"><?= e(i18n_text('Version', 'Version')) ?></label>
                        <input id="external_build_version" name="version" value="<?= e($editingGame['current_version'] ?? '0.1.0') ?>" maxlength="60" required>
                    </div>
                    <div class="field">
                        <label for="external_build_channel"><?= e(i18n_text('Canal', 'Channel')) ?></label>
                        <select id="external_build_channel" name="channel">
                            <?php foreach (GameBuild::channels() as $channel): ?>
                                <option value="<?= e($channel) ?>"><?= e($channel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="external_build_executable_path"><?= e(i18n_text('Ejecutable dentro del ZIP', 'Executable inside ZIP')) ?></label>
                        <input id="external_build_executable_path" name="executable_path" placeholder="Game.exe" maxlength="255" required>
                    </div>
                    <div class="field">
                        <label for="external_build_zip"><?= e(i18n_text('Build .zip', 'Build .zip')) ?></label>
                        <input id="external_build_zip" name="build_zip" type="file" accept=".zip,application/zip" required>
                    </div>
                </div>
                <div class="field">
                    <label for="external_build_notes"><?= e(i18n_text('Notas', 'Notes')) ?></label>
                    <textarea id="external_build_notes" name="notes" rows="3"></textarea>
                </div>
                <div class="actions">
                    <button type="submit"><?= e(i18n_text('Subir build ZIP', 'Upload ZIP build')) ?></button>
                </div>
            </form>

            <details class="panel-lite">
                <summary><?= e(i18n_text('Registrar build por URL', 'Register build by URL')) ?></summary>
                <form class="form" method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="save_external_game_build_remote">
                    <input type="hidden" name="external_game_id" value="<?= e($editingGame['id']) ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="external_remote_version"><?= e(i18n_text('Version', 'Version')) ?></label>
                            <input id="external_remote_version" name="version" maxlength="60" required>
                        </div>
                        <div class="field">
                            <label for="external_remote_channel"><?= e(i18n_text('Canal', 'Channel')) ?></label>
                            <select id="external_remote_channel" name="channel">
                                <?php foreach (GameBuild::channels() as $channel): ?>
                                    <option value="<?= e($channel) ?>"><?= e($channel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="external_remote_executable_path"><?= e(i18n_text('Ejecutable dentro del ZIP', 'Executable inside ZIP')) ?></label>
                            <input id="external_remote_executable_path" name="executable_path" placeholder="Game.exe" maxlength="255" required>
                        </div>
                        <div class="field">
                            <label for="external_remote_file_path"><?= e(i18n_text('URL o ruta .zip', 'URL or .zip path')) ?></label>
                            <input id="external_remote_file_path" name="file_path" placeholder="https://.../game.zip o /uploads/builds/game.zip" required>
                        </div>
                        <div class="field">
                            <label for="external_remote_checksum">SHA-256</label>
                            <input id="external_remote_checksum" name="checksum" maxlength="128">
                        </div>
                    </div>
                    <div class="actions">
                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Guardar build remota', 'Save remote build')) ?></button>
                    </div>
                </form>
            </details>

            <details class="panel-lite">
                <summary><?= e(i18n_text('Registrar plataforma externa', 'Register external platform')) ?></summary>
                <form class="form" method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="save_external_game_platform_build">
                    <input type="hidden" name="external_game_id" value="<?= e($editingGame['id']) ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="external_platform_version"><?= e(i18n_text('Version', 'Version')) ?></label>
                            <input id="external_platform_version" name="version" maxlength="60" required>
                        </div>
                        <div class="field">
                            <label for="external_platform_channel"><?= e(i18n_text('Canal', 'Channel')) ?></label>
                            <select id="external_platform_channel" name="channel">
                                <?php foreach (GameBuild::channels() as $channel): ?>
                                    <option value="<?= e($channel) ?>"><?= e($channel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="external_platform_name"><?= e(i18n_text('Plataforma', 'Platform')) ?></label>
                            <input id="external_platform_name" name="platform" value="steam" maxlength="60" required>
                        </div>
                        <div class="field">
                            <label for="external_platform_app_id"><?= e(i18n_text('App ID externo', 'External App ID')) ?></label>
                            <input id="external_platform_app_id" name="platform_app_id" placeholder="Steam AppID" maxlength="120">
                        </div>
                        <div class="field">
                            <label for="external_platform_url"><?= e(i18n_text('URL de lanzamiento', 'Launch URL')) ?></label>
                            <input id="external_platform_url" name="platform_url" placeholder="steam://run/480">
                        </div>
                    </div>
                    <div class="field">
                        <label for="external_platform_notes"><?= e(i18n_text('Notas', 'Notes')) ?></label>
                        <textarea id="external_platform_notes" name="notes" rows="3"></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Guardar plataforma', 'Save platform')) ?></button>
                    </div>
                </form>
            </details>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= e(i18n_text('Version', 'Version')) ?></th>
                            <th><?= e(i18n_text('Canal', 'Channel')) ?></th>
                            <th><?= e(i18n_text('Tipo', 'Type')) ?></th>
                            <th><?= e(i18n_text('Destino', 'Target')) ?></th>
                            <th><?= e(i18n_text('Ejecutable', 'Executable')) ?></th>
                            <th><?= e(i18n_text('Accion', 'Action')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($gameBuilds === []): ?>
                            <tr><td colspan="6"><?= e(i18n_text('No hay builds para este juego.', 'There are no builds for this game.')) ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($gameBuilds as $build): ?>
                            <tr>
                                <td><?= e($build['version']) ?></td>
                                <td><?= e($build['channel']) ?></td>
                                <td>
                                    <?= e((string) ($build['delivery_type'] ?? 'zip')) ?>
                                    <?php if (!empty($build['platform'])): ?>
                                        <br><span class="muted"><?= e((string) $build['platform']) ?> <?= e((string) ($build['platform_app_id'] ?? '')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($build['download_url'])): ?>
                                        <a href="<?= e($build['download_url']) ?>" target="_blank" rel="noreferrer"><?= e(i18n_text('Descargar', 'Download')) ?></a>
                                    <?php elseif (!empty($build['launch_url'])): ?>
                                        <a href="<?= e($build['launch_url']) ?>" target="_blank" rel="noreferrer"><?= e(i18n_text('Abrir plataforma', 'Open platform')) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= e($build['executable_path'] ?? '') ?></code></td>
                                <td>
                                    <form method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete_external_game_build">
                                        <input type="hidden" name="build_id" value="<?= e($build['id']) ?>">
                                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Eliminar', 'Delete')) ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2><?= e(i18n_text('Lista de juegos externos', 'External game list')) ?></h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= e(i18n_text('Juego', 'Game')) ?></th>
                        <th><?= e(i18n_text('Slug', 'Slug')) ?></th>
                        <th><?= e(i18n_text('Equipo', 'Team')) ?></th>
                        <th><?= e(i18n_text('Estado', 'Status')) ?></th>
                        <th><?= e(i18n_text('Visibilidad', 'Visibility')) ?></th>
                        <th><?= e(i18n_text('Jugadores', 'Players')) ?></th>
                        <th><?= e(i18n_text('Accion', 'Action')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($externalGames === []): ?>
                        <tr><td colspan="7"><?= e(i18n_text('No hay juegos externos creados.', 'No external games created.')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($externalGames as $externalGame): ?>
                        <tr>
                            <td><strong><?= e($externalGame['name']) ?></strong></td>
                            <td><code><?= e($externalGame['slug']) ?></code></td>
                            <td>
                                <?= e($externalGame['developer_name'] ?? '') ?><br>
                                <span class="muted"><?= e($externalGame['publisher_name'] ?? '') ?></span>
                            </td>
                            <td><?= e($externalGame['status']) ?></td>
                            <td><?= e(Game::visibilityLabel((string) $externalGame['visibility'])) ?></td>
                            <td><?= e($externalGame['player_count'] ?? 0) ?></td>
                            <td>
                                <a class="button button--secondary" href="<?= e(url('/external-games/?edit_game=' . (int) $externalGame['id'])) ?>"><?= e(i18n_text('Editar', 'Edit')) ?></a>
                                <?php if (!empty($externalGame['main_game_id'])): ?>
                                    <a class="button button--secondary" href="<?= e(url('/games-code/?game_id=' . (int) $externalGame['main_game_id'])) ?>"><?= e(i18n_text('Codigos', 'Codes')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($editingGame): ?>
        <section class="panel">
            <h2><?= e(i18n_text('Jugadores externos', 'External players')) ?></h2>
            <?php if ($players === []): ?>
                <p class="muted"><?= e(i18n_text('Todavia no hay jugadores externos registrados para este juego.', 'There are no external players registered for this game yet.')) ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?= e(i18n_text('Usuario', 'User')) ?></th>
                                <th><?= e(i18n_text('Ultima vez', 'Last seen')) ?></th>
                                <th>Metadata</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $player): ?>
                                <tr>
                                    <td><?= e($player['id']) ?></td>
                                    <td><?= e($player['display_name'] ?? $player['username'] ?? '') ?></td>
                                    <td><?= e($player['last_seen_at'] ?? '') ?></td>
                                    <td><code><?= e($player['metadata_json'] ?? '') ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php
Page::footer();
