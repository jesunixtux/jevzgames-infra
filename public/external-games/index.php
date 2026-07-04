<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\ExternalGames;
use App\Models\Game;
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
                    <?php if (!empty($editingGame['slug'])): ?>
                        <a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $editingGame['slug']))) ?>"><?= e(i18n_text('Ver juego', 'View game')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
    </section>

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
