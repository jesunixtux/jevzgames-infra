<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Achievement;
use App\Models\Admin;
use App\Models\CloudSave;
use App\Models\GameDatabase;
use App\Models\PublishRequest;
use App\Models\Support;
use App\Models\Workshop;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();
Auth::requireRole(['admin', 'superroot']);

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$sections = ['overview', 'users', 'games', 'publish', 'achievements', 'cloud', 'codes', 'workshop', 'support', 'logs'];
$section = (string) ($_GET['section'] ?? 'overview');
if (!in_array($section, $sections, true)) {
    $section = 'overview';
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/admin/');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_user_status') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            Admin::updateUserStatus($targetUserId, (string) ($_POST['status'] ?? 'active'));
            ActivityLogger::info('admin_user_status_updated', ['user_id' => $userId, 'target_user_id' => $targetUserId]);
            flash('message', 'Estado de usuario actualizado.');
            redirect_to('/admin/?section=users');
        }

        if ($action === 'save_game') {
            $gameId = Admin::saveGame($_POST);
            ActivityLogger::info('admin_game_saved', ['user_id' => $userId, 'game_id' => $gameId]);
            flash('message', 'Juego guardado.');
            redirect_to('/admin/?section=games');
        }

        if ($action === 'update_game_status') {
            $gameId = (int) ($_POST['game_id'] ?? 0);
            Admin::updateGameStatus($gameId, (string) ($_POST['status'] ?? 'development'));
            ActivityLogger::info('admin_game_status_updated', ['user_id' => $userId, 'game_id' => $gameId]);
            flash('message', 'Estado de juego actualizado.');
            redirect_to('/admin/?section=games');
        }

        if ($action === 'approve_publish_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $gameId = PublishRequest::approve($requestId, $userId);
            ActivityLogger::info('publish_request_approved', ['user_id' => $userId, 'request_id' => $requestId, 'game_id' => $gameId]);
            flash('message', 'Solicitud aprobada y juego creado.');
            redirect_to('/admin/?section=publish');
        }

        if ($action === 'reject_publish_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            PublishRequest::reject($requestId, $userId, (string) ($_POST['review_note'] ?? ''));
            ActivityLogger::info('publish_request_rejected', ['user_id' => $userId, 'request_id' => $requestId]);
            flash('message', 'Solicitud rechazada.');
            redirect_to('/admin/?section=publish');
        }

        if ($action === 'create_game_api_key') {
            $gameId = (int) ($_POST['game_id'] ?? 0);
            $created = Admin::createGameApiKey($gameId);
            ActivityLogger::info('admin_game_api_key_created', ['user_id' => $userId, 'game_id' => $gameId, 'api_key_id' => $created['id']]);
            flash('message', 'API key creada. Public key: ' . $created['public_key'] . ' | Secret key (copiar ahora): ' . $created['secret_key']);
            redirect_to('/admin/?section=games');
        }

        if ($action === 'revoke_game_api_key') {
            $apiKeyId = (int) ($_POST['api_key_id'] ?? 0);
            Admin::revokeGameApiKey($apiKeyId);
            ActivityLogger::info('admin_game_api_key_revoked', ['user_id' => $userId, 'api_key_id' => $apiKeyId]);
            flash('message', 'API key revocada.');
            redirect_to('/admin/?section=games');
        }

        if ($action === 'test_game_database') {
            $gameId = (int) ($_POST['game_id'] ?? 0);
            $result = Admin::testGameDatabase($gameId);
            ActivityLogger::info('admin_game_database_tested', ['user_id' => $userId, 'game_id' => $gameId, 'connected' => (bool) ($result['connected'] ?? false)]);
            $type = (bool) ($result['connected'] ?? false) ? 'message' : 'error';
            flash($type, 'BD dedicada: ' . ($result['message'] ?? 'Sin respuesta.'));
            redirect_to('/admin/?section=games');
        }

        if ($action === 'save_achievement') {
            $achievementId = Achievement::save($_POST);
            ActivityLogger::info('admin_achievement_saved', ['user_id' => $userId, 'achievement_id' => $achievementId]);
            flash('message', 'Logro guardado.');
            redirect_to('/admin/?section=achievements');
        }

        if ($action === 'update_achievement_status') {
            $achievementId = (int) ($_POST['achievement_id'] ?? 0);
            Achievement::updateStatus($achievementId, (string) ($_POST['status'] ?? 'disabled'));
            ActivityLogger::info('admin_achievement_status_updated', ['user_id' => $userId, 'achievement_id' => $achievementId]);
            flash('message', 'Estado de logro actualizado.');
            redirect_to('/admin/?section=achievements');
        }

        if ($action === 'save_cloud_config') {
            $cloudConfigId = CloudSave::saveConfig($_POST);
            ActivityLogger::info('admin_cloud_config_saved', ['user_id' => $userId, 'cloud_config_id' => $cloudConfigId]);
            flash('message', 'Configuracion cloud guardada.');
            redirect_to('/admin/?section=cloud');
        }

        if ($action === 'update_cloud_config_status') {
            $cloudConfigId = (int) ($_POST['cloud_config_id'] ?? 0);
            CloudSave::updateConfigStatus($cloudConfigId, (string) ($_POST['status'] ?? 'disabled'));
            ActivityLogger::info('admin_cloud_config_status_updated', ['user_id' => $userId, 'cloud_config_id' => $cloudConfigId]);
            flash('message', 'Estado cloud actualizado.');
            redirect_to('/admin/?section=cloud');
        }

        if ($action === 'create_code') {
            $created = Admin::createCode($_POST, $userId);
            ActivityLogger::info('admin_code_created', ['user_id' => $userId, 'code_id' => $created['id']]);
            flash('message', 'Codigo creado. Copialo ahora, no se volvera a mostrar: ' . $created['code']);
            redirect_to('/admin/?section=codes');
        }

        if ($action === 'update_code_status') {
            $codeId = (int) ($_POST['code_id'] ?? 0);
            Admin::updateCodeStatus($codeId, (string) ($_POST['status'] ?? 'inactive'));
            ActivityLogger::info('admin_code_status_updated', ['user_id' => $userId, 'code_id' => $codeId]);
            flash('message', 'Estado de codigo actualizado.');
            redirect_to('/admin/?section=codes');
        }

        if ($action === 'save_workshop_config') {
            Workshop::saveConfig($_POST);
            ActivityLogger::info('admin_workshop_config_saved', ['user_id' => $userId, 'game_id' => (int) ($_POST['game_id'] ?? 0)]);
            flash('message', 'Workshop actualizado.');
            redirect_to('/admin/?section=workshop');
        }

        if ($action === 'update_workshop_item_status') {
            Workshop::updateItemStatus((int) ($_POST['item_id'] ?? 0), (string) ($_POST['status'] ?? 'pending'));
            ActivityLogger::info('admin_workshop_item_status_updated', ['user_id' => $userId, 'item_id' => (int) ($_POST['item_id'] ?? 0)]);
            flash('message', 'Item workshop actualizado.');
            redirect_to('/admin/?section=workshop');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/admin/?section=' . rawurlencode($section));
    }
}

$stats = Admin::dashboardStats();
$users = Admin::users();
$games = Admin::games();
$publishStatus = (string) ($_GET['publish_status'] ?? 'pending');
$publishRequests = PublishRequest::all($publishStatus);
$apiKeys = Admin::apiKeys();
$achievements = Achievement::list();
$cloudConfigs = CloudSave::configs();
$codes = Admin::codes();
$workshopConfigs = Workshop::configs();
$workshopItems = Workshop::items(null, true);
$supportFilter = (string) ($_GET['support_status'] ?? 'open');
$supportFilters = ['open', 'closed', 'solved', 'unsolved', 'all'];
if (!in_array($supportFilter, $supportFilters, true)) {
    $supportFilter = 'open';
}
$tickets = Admin::supportTickets($supportFilter);
$activityLogs = Admin::activityLogs(80);
$appLogs = Admin::appLogLines(40);

$editGameId = (int) ($_GET['edit_game'] ?? 0);
$editingGame = $editGameId > 0 ? Admin::findGame($editGameId) : null;
$editAchievementId = (int) ($_GET['edit_achievement'] ?? 0);
$editingAchievement = $editAchievementId > 0 ? Achievement::find($editAchievementId) : null;
$editCloudConfigId = (int) ($_GET['edit_cloud_config'] ?? 0);
$editingCloudConfig = $editCloudConfigId > 0 ? CloudSave::findConfig($editCloudConfigId) : null;
$sectionLabels = [
    'overview' => 'Resumen',
    'users' => 'Usuarios',
    'games' => 'Juegos',
    'publish' => 'Publicaciones',
    'achievements' => 'Logros',
    'cloud' => 'Cloud saves',
    'codes' => 'Codigos',
    'workshop' => 'Workshop',
    'support' => 'Soporte',
    'logs' => 'Logs',
];

Page::header('Admin');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Panel Admin</h1>
            <p class="muted">Administracion operativa de usuarios, juegos, codigos, soporte y logs.</p>
        </div>
        <a class="button button--secondary" href="<?= e(url('/supporter/')) ?>">Panel soporte</a>
    </div>
</section>

<nav class="tab-nav" aria-label="Secciones admin">
    <?php foreach ($sectionLabels as $key => $label): ?>
        <a class="<?= $section === $key ? 'tab-nav__item tab-nav__item--active' : 'tab-nav__item' ?>" href="<?= e(url('/admin/?section=' . $key)) ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($section === 'overview'): ?>
    <section class="grid">
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['users']) ?></span>
            <h2>Usuarios</h2>
            <p class="muted"><?= e($stats['blocked_users']) ?> bloqueados.</p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['games']) ?></span>
            <h2>Juegos</h2>
            <p class="muted"><?= e($stats['published_games']) ?> publicados.</p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['open_tickets']) ?></span>
            <h2>Tickets abiertos</h2>
            <p class="muted">Atencion pendiente en soporte.</p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['active_codes']) ?></span>
            <h2>Codigos activos</h2>
            <p class="muted">Canjes disponibles.</p>
        </article>
    </section>

    <section class="panel">
        <h2>Accesos rapidos</h2>
        <div class="actions">
            <a class="button button--secondary" href="<?= e(url('/admin/?section=users')) ?>">Gestionar usuarios</a>
            <a class="button button--secondary" href="<?= e(url('/admin/?section=games')) ?>">Gestionar juegos</a>
            <a class="button button--secondary" href="<?= e(url('/admin/?section=cloud')) ?>">Cloud saves</a>
            <a class="button button--secondary" href="<?= e(url('/admin/?section=codes')) ?>">Crear codigos</a>
            <a class="button button--secondary" href="<?= e(url('/admin/?section=support')) ?>">Revisar soporte</a>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'users'): ?>
    <section class="panel">
        <h2>Usuarios</h2>
        <p class="muted">Admin puede bloquear, desbloquear o marcar recuperacion de usuarios normales. Admin y superroot se gestionan desde Superroot.</p>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Estado</th>
                        <th>Ultimo login</th>
                        <th>Guardar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $managedUser): ?>
                        <tr>
                            <td>
                                <strong><?= e($managedUser['username']) ?></strong><br>
                                <span class="muted">#<?= e($managedUser['id']) ?></span>
                            </td>
                            <td><?= e($managedUser['email']) ?></td>
                            <td><?= e(implode(', ', $managedUser['roles'])) ?></td>
                            <td>
                                <?php if ($managedUser['protected']): ?>
                                    <?= e($managedUser['status']) ?>
                                <?php else: ?>
                                    <form class="row-form" method="post" id="user-status-<?= e($managedUser['id']) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="update_user_status">
                                        <input type="hidden" name="target_user_id" value="<?= e($managedUser['id']) ?>">
                                        <select name="status">
                                            <?php foreach (['active' => 'Activa', 'blocked' => 'Bloqueada', 'pending_recovery' => 'Recuperacion'] as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $managedUser['status'] === $value ? 'selected' : '' ?>>
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><?= e($managedUser['last_login_at'] ?? 'Nunca') ?></td>
                            <td>
                                <?php if ($managedUser['protected']): ?>
                                    <span class="muted">Protegido</span>
                                <?php else: ?>
                                    <button type="submit" form="user-status-<?= e($managedUser['id']) ?>">Guardar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'games'): ?>
    <section class="panel">
        <h2><?= $editingGame ? 'Editar juego' : 'Nuevo juego' ?></h2>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_game">
            <input type="hidden" name="game_id" value="<?= e($editingGame['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Nombre</label>
                    <input id="name" name="name" value="<?= e($editingGame['name'] ?? '') ?>" maxlength="140" required>
                </div>
                <div class="field">
                    <label for="slug">Slug</label>
                    <input id="slug" name="slug" value="<?= e($editingGame['slug'] ?? '') ?>" maxlength="160" pattern="[a-z0-9-]{2,160}" required>
                </div>
                <div class="field">
                    <label for="status">Estado</label>
                    <select id="status" name="status">
                        <?php foreach (Admin::gameStatuses() as $status): ?>
                            <option value="<?= e($status) ?>" <?= (($editingGame['status'] ?? 'development') === $status) ? 'selected' : '' ?>>
                                <?= e($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="current_version">Version actual</label>
                    <input id="current_version" name="current_version" value="<?= e($editingGame['current_version'] ?? '') ?>" maxlength="60">
                </div>
            </div>

            <div class="field">
                <label for="description">Descripcion</label>
                <textarea id="description" name="description" rows="4" maxlength="5000"><?= e($editingGame['description'] ?? '') ?></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="config_json">Config JSON</label>
                    <textarea id="config_json" name="config_json" rows="4" placeholder='{"features":[]}'><?= e($editingGame['config_json'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="endpoints_json">Endpoints JSON</label>
                    <textarea id="endpoints_json" name="endpoints_json" rows="4" placeholder='{"status":"/api/status/"}'><?= e($editingGame['endpoints_json'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="external_database_json">BD dedicada JSON</label>
                    <textarea id="external_database_json" name="external_database_json" rows="4" placeholder='{"enabled":true,"host":"127.0.0.1","port":3306,"database":"game_db","user":"root","password":"","charset":"utf8mb4"}'><?= e($editingGame['external_database_json'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="cdn_json">CDN JSON</label>
                    <textarea id="cdn_json" name="cdn_json" rows="4" placeholder='{"base_url":""}'><?= e($editingGame['cdn_json'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit"><?= $editingGame ? 'Guardar juego' : 'Crear juego' ?></button>
                <?php if ($editingGame): ?>
                    <a class="button button--secondary" href="<?= e(url('/admin/?section=games')) ?>">Cancelar edicion</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Juegos registrados</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Juego</th>
                        <th>Slug</th>
                        <th>Version</th>
                        <th>Estado</th>
                        <th>BD dedicada</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($games === []): ?>
                        <tr><td colspan="6">No hay juegos registrados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($games as $game): ?>
                        <?php $databaseStatus = GameDatabase::publicStatusFromGame($game); ?>
                        <tr>
                            <td><strong><?= e($game['name']) ?></strong></td>
                            <td><code><?= e($game['slug']) ?></code></td>
                            <td><?= e($game['current_version'] ?? '') ?></td>
                            <td><?= e($game['status']) ?></td>
                            <td>
                                <?= $databaseStatus['enabled'] ? 'Activa' : 'Inactiva' ?><br>
                                <span class="muted"><?= $databaseStatus['configured'] ? 'Configurada' : 'Sin configurar' ?></span>
                            </td>
                            <td class="table-actions">
                                <a class="button button--secondary" href="<?= e(url('/admin/?section=games&edit_game=' . (int) $game['id'])) ?>">Editar</a>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="test_game_database">
                                    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
                                    <button type="submit" class="button button--secondary">Probar BD</button>
                                </form>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update_game_status">
                                    <input type="hidden" name="game_id" value="<?= e($game['id']) ?>">
                                    <select name="status">
                                        <?php foreach (Admin::gameStatuses() as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $game['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button button--secondary">Cambiar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>API keys de juegos</h2>
                <p class="muted">Unity usa la public key para pedir un device code. La secret key se muestra solo al crearla.</p>
            </div>
        </div>

        <form class="filter-bar filter-bar--inline" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_game_api_key">
            <label for="api_game_id">Juego</label>
            <select id="api_game_id" name="game_id" required>
                <?php foreach ($games as $game): ?>
                    <option value="<?= e($game['id']) ?>"><?= e($game['name']) ?> (<?= e($game['slug']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Generar API key</button>
        </form>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Juego</th>
                        <th>Public key</th>
                        <th>Estado</th>
                        <th>Creada</th>
                        <th>Ultimo uso</th>
                        <th>Revocada</th>
                        <th>Accion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($apiKeys === []): ?>
                        <tr><td colspan="7">No hay API keys creadas.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($apiKeys as $apiKey): ?>
                        <tr>
                            <td>
                                <strong><?= e($apiKey['game_name']) ?></strong><br>
                                <code><?= e($apiKey['game_slug']) ?></code>
                            </td>
                            <td><code><?= e($apiKey['public_key']) ?></code></td>
                            <td><?= e($apiKey['status']) ?></td>
                            <td><?= e($apiKey['created_at']) ?></td>
                            <td><?= e($apiKey['last_used_at'] ?? '') ?></td>
                            <td><?= e($apiKey['revoked_at'] ?? '') ?></td>
                            <td>
                                <?php if ($apiKey['status'] === 'active'): ?>
                                    <form method="post" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="revoke_game_api_key">
                                        <input type="hidden" name="api_key_id" value="<?= e($apiKey['id']) ?>">
                                        <button type="submit" class="button button--secondary">Revocar</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Sin acciones</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'publish'): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Solicitudes de publicacion</h2>
                <p class="muted">Aprobar crea un juego en estado <code>development</code> con el usuario como owner.</p>
            </div>
            <form class="filter-bar filter-bar--inline" method="get">
                <input type="hidden" name="section" value="publish">
                <label for="publish_status">Estado</label>
                <select id="publish_status" name="publish_status" onchange="this.form.submit()">
                    <?php foreach (['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'all' => 'Todas'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $publishStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Juego</th>
                        <th>Usuario</th>
                        <th>Contacto</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($publishRequests === []): ?>
                        <tr><td colspan="6">No hay solicitudes para este filtro.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($publishRequests as $request): ?>
                        <tr>
                            <td>
                                <strong><?= e($request['name']) ?></strong><br>
                                <code><?= e($request['slug']) ?></code>
                                <?php if (!empty($request['description'])): ?>
                                    <p class="muted"><?= e(substr((string) $request['description'], 0, 180)) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>@<?= e($request['username']) ?></td>
                            <td>
                                <?= e($request['contact_email'] ?? '') ?><br>
                                <?php if (!empty($request['website_url'])): ?>
                                    <a href="<?= e($request['website_url']) ?>" target="_blank" rel="noreferrer">Sitio</a>
                                <?php endif; ?>
                                <?php if (!empty($request['build_url'])): ?>
                                    <a href="<?= e($request['build_url']) ?>" target="_blank" rel="noreferrer">Build</a>
                                <?php endif; ?>
                            </td>
                            <td><?= e($request['status']) ?></td>
                            <td><?= e($request['created_at']) ?></td>
                            <td class="table-actions">
                                <?php if ($request['status'] === 'pending'): ?>
                                    <form method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="approve_publish_request">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <button type="submit">Aprobar</button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reject_publish_request">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <input name="review_note" placeholder="Nota" maxlength="255">
                                        <button type="submit" class="button button--secondary">Rechazar</button>
                                    </form>
                                <?php elseif (!empty($request['approved_game_slug'])): ?>
                                    <a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $request['approved_game_slug']))) ?>">Ver juego</a>
                                <?php else: ?>
                                    <span class="muted"><?= e($request['review_note'] ?? 'Revisada') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'achievements'): ?>
    <section class="panel">
        <h2><?= $editingAchievement ? 'Editar logro' : 'Nuevo logro' ?></h2>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_achievement">
            <input type="hidden" name="achievement_id" value="<?= e($editingAchievement['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="achievement_game_id">Juego</label>
                    <select id="achievement_game_id" name="game_id" required>
                        <?php foreach ($games as $game): ?>
                            <option value="<?= e($game['id']) ?>" <?= ((int) ($editingAchievement['game_id'] ?? 0) === (int) $game['id']) ? 'selected' : '' ?>>
                                <?= e($game['name']) ?> (<?= e($game['slug']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="achievement_code">Codigo</label>
                    <input id="achievement_code" name="code" value="<?= e($editingAchievement['code'] ?? '') ?>" maxlength="100" placeholder="first_win" required>
                </div>
                <div class="field">
                    <label for="achievement_title">Titulo</label>
                    <input id="achievement_title" name="title" value="<?= e($editingAchievement['title'] ?? '') ?>" maxlength="160" required>
                </div>
                <div class="field">
                    <label for="achievement_status">Estado</label>
                    <select id="achievement_status" name="status">
                        <?php foreach (Achievement::statuses() as $achievementStatus): ?>
                            <option value="<?= e($achievementStatus) ?>" <?= (($editingAchievement['status'] ?? 'active') === $achievementStatus) ? 'selected' : '' ?>>
                                <?= e($achievementStatus) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="achievement_goal">Meta</label>
                    <input id="achievement_goal" name="goal_value" type="number" min="0.01" step="0.01" value="<?= e($editingAchievement['goal_value'] ?? '1') ?>" required>
                </div>
                <div class="field">
                    <label for="achievement_points">Puntos</label>
                    <input id="achievement_points" name="points" type="number" min="0" max="1000000" value="<?= e($editingAchievement['points'] ?? 0) ?>" required>
                </div>
                <div class="field">
                    <label for="achievement_image_path">Imagen desbloqueada</label>
                    <input id="achievement_image_path" name="image_path" value="<?= e($editingAchievement['image_path'] ?? '') ?>" maxlength="255" placeholder="/uploads/achievements/first_win.png">
                </div>
                <div class="field">
                    <label for="achievement_locked_image_path">Imagen bloqueada</label>
                    <input id="achievement_locked_image_path" name="locked_image_path" value="<?= e($editingAchievement['locked_image_path'] ?? '') ?>" maxlength="255" placeholder="/uploads/achievements/locked.png">
                </div>
                <label class="checkbox-field">
                    <input type="checkbox" name="is_secret" value="1" <?= !empty($editingAchievement['is_secret']) ? 'checked' : '' ?>>
                    Logro secreto
                </label>
            </div>

            <div class="field">
                <label for="achievement_description">Descripcion</label>
                <textarea id="achievement_description" name="description" rows="3" maxlength="5000"><?= e($editingAchievement['description'] ?? '') ?></textarea>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="achievement_reward_json">Recompensa JSON</label>
                    <textarea id="achievement_reward_json" name="reward_json" rows="4" placeholder='{"coins":100}'><?= e($editingAchievement['reward_json'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="achievement_config_json">Config JSON</label>
                    <textarea id="achievement_config_json" name="config_json" rows="4" placeholder='{"trigger":"match.win"}'><?= e($editingAchievement['config_json'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit"><?= $editingAchievement ? 'Guardar logro' : 'Crear logro' ?></button>
                <?php if ($editingAchievement): ?>
                    <a class="button button--secondary" href="<?= e(url('/admin/?section=achievements')) ?>">Cancelar edicion</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Logros configurados</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Logro</th>
                        <th>Juego</th>
                        <th>Meta</th>
                        <th>Puntos</th>
                        <th>Estado</th>
                        <th>Desbloqueos</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($achievements === []): ?>
                        <tr><td colspan="7">No hay logros configurados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($achievements as $achievement): ?>
                        <tr>
                            <td>
                                <div class="compact-row">
                                    <?php if (!empty($achievement['image_path'])): ?>
                                        <img class="achievement-thumb" src="<?= e($achievement['image_path']) ?>" alt="">
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($achievement['title']) ?></strong><br>
                                        <code><?= e($achievement['code']) ?></code>
                                        <?php if (!empty($achievement['is_secret'])): ?>
                                            <span class="muted">Secreto</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?= e($achievement['game_name']) ?><br>
                                <code><?= e($achievement['game_slug']) ?></code>
                            </td>
                            <td><?= e($achievement['goal_value']) ?></td>
                            <td><?= e($achievement['points']) ?></td>
                            <td><?= e($achievement['status']) ?></td>
                            <td><?= e($achievement['unlocked_count'] ?? 0) ?> / <?= e($achievement['player_count'] ?? 0) ?></td>
                            <td class="table-actions">
                                <a class="button button--secondary" href="<?= e(url('/admin/?section=achievements&edit_achievement=' . (int) $achievement['id'])) ?>">Editar</a>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update_achievement_status">
                                    <input type="hidden" name="achievement_id" value="<?= e($achievement['id']) ?>">
                                    <select name="status">
                                        <?php foreach (Achievement::statuses() as $achievementStatus): ?>
                                            <option value="<?= e($achievementStatus) ?>" <?= $achievement['status'] === $achievementStatus ? 'selected' : '' ?>>
                                                <?= e($achievementStatus) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button button--secondary">Cambiar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'cloud'): ?>
    <section class="panel">
        <h2><?= $editingCloudConfig ? 'Editar cloud save' : 'Nueva config cloud' ?></h2>
        <p class="muted">Cada juego puede tener una o varias keys de guardado. Unity usa <code>config_key</code> y <code>slot</code>.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_cloud_config">
            <input type="hidden" name="cloud_config_id" value="<?= e($editingCloudConfig['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="cloud_game_id">Juego</label>
                    <select id="cloud_game_id" name="game_id" required>
                        <?php foreach ($games as $game): ?>
                            <option value="<?= e($game['id']) ?>" <?= ((int) ($editingCloudConfig['game_id'] ?? 0) === (int) $game['id']) ? 'selected' : '' ?>>
                                <?= e($game['name']) ?> (<?= e($game['slug']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="cloud_config_key">Config key</label>
                    <input id="cloud_config_key" name="config_key" value="<?= e($editingCloudConfig['config_key'] ?? 'default') ?>" maxlength="100" required>
                </div>
                <div class="field">
                    <label for="cloud_name">Nombre</label>
                    <input id="cloud_name" name="name" value="<?= e($editingCloudConfig['name'] ?? 'Partida principal') ?>" maxlength="160" required>
                </div>
                <div class="field">
                    <label for="cloud_status">Estado</label>
                    <select id="cloud_status" name="status">
                        <?php foreach (CloudSave::statuses() as $cloudStatus): ?>
                            <option value="<?= e($cloudStatus) ?>" <?= (($editingCloudConfig['status'] ?? 'active') === $cloudStatus) ? 'selected' : '' ?>>
                                <?= e($cloudStatus) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="cloud_max_slots">Slots maximos</label>
                    <input id="cloud_max_slots" name="max_slots" type="number" min="1" max="20" value="<?= e($editingCloudConfig['max_slots'] ?? 3) ?>" required>
                </div>
                <div class="field">
                    <label for="cloud_max_bytes">Tamano maximo bytes</label>
                    <input id="cloud_max_bytes" name="max_bytes" type="number" min="1024" max="<?= e(5 * 1024 * 1024) ?>" value="<?= e($editingCloudConfig['max_bytes'] ?? 65536) ?>" required>
                </div>
                <label class="checkbox-field">
                    <input type="checkbox" name="auto_sync" value="1" <?= !empty($editingCloudConfig) ? (!empty($editingCloudConfig['auto_sync']) ? 'checked' : '') : 'checked' ?>>
                    Auto sync
                </label>
            </div>

            <div class="field">
                <label for="cloud_metadata_json">Metadata JSON</label>
                <textarea id="cloud_metadata_json" name="metadata_json" rows="4" placeholder='{"save_type":"profile"}'><?= e($editingCloudConfig['metadata_json'] ?? '') ?></textarea>
            </div>

            <div class="actions">
                <button type="submit"><?= $editingCloudConfig ? 'Guardar config' : 'Crear config' ?></button>
                <?php if ($editingCloudConfig): ?>
                    <a class="button button--secondary" href="<?= e(url('/admin/?section=cloud')) ?>">Cancelar edicion</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Configuraciones cloud</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Config</th>
                        <th>Juego</th>
                        <th>Slots</th>
                        <th>Tamano</th>
                        <th>Estado</th>
                        <th>Uso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($cloudConfigs === []): ?>
                        <tr><td colspan="7">No hay configs cloud creadas.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cloudConfigs as $cloudConfig): ?>
                        <tr>
                            <td>
                                <strong><?= e($cloudConfig['name']) ?></strong><br>
                                <code><?= e($cloudConfig['config_key']) ?></code>
                            </td>
                            <td>
                                <?= e($cloudConfig['game_name']) ?><br>
                                <code><?= e($cloudConfig['game_slug']) ?></code>
                            </td>
                            <td><?= e($cloudConfig['max_slots']) ?></td>
                            <td><?= e($cloudConfig['max_bytes']) ?> bytes</td>
                            <td><?= e($cloudConfig['status']) ?></td>
                            <td><?= e($cloudConfig['save_count'] ?? 0) ?> saves / <?= e($cloudConfig['player_count'] ?? 0) ?> jugadores</td>
                            <td class="table-actions">
                                <a class="button button--secondary" href="<?= e(url('/admin/?section=cloud&edit_cloud_config=' . (int) $cloudConfig['id'])) ?>">Editar</a>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update_cloud_config_status">
                                    <input type="hidden" name="cloud_config_id" value="<?= e($cloudConfig['id']) ?>">
                                    <select name="status">
                                        <?php foreach (CloudSave::statuses() as $cloudStatus): ?>
                                            <option value="<?= e($cloudStatus) ?>" <?= $cloudConfig['status'] === $cloudStatus ? 'selected' : '' ?>>
                                                <?= e($cloudStatus) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button button--secondary">Cambiar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'codes'): ?>
    <section class="panel">
        <h2>Crear codigo canjeable</h2>
        <p class="muted">El codigo se muestra una sola vez al crearlo. En base de datos se guarda hash y preview.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_code">
            <div class="form-grid">
                <div class="field">
                    <label for="code">Codigo opcional</label>
                    <input id="code" name="code" placeholder="Vacio genera uno automaticamente" maxlength="80">
                </div>
                <div class="field">
                    <label for="game_id">Juego</label>
                    <select id="game_id" name="game_id">
                        <option value="0">Global</option>
                        <?php foreach ($games as $game): ?>
                            <option value="<?= e($game['id']) ?>"><?= e($game['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="reward_type">Tipo de recompensa</label>
                    <input id="reward_type" name="reward_type" value="item" maxlength="80" required>
                </div>
                <div class="field">
                    <label for="max_uses">Usos maximos</label>
                    <input id="max_uses" name="max_uses" type="number" min="1" max="1000000" value="1" required>
                </div>
                <div class="field">
                    <label for="expires_at">Expira</label>
                    <input id="expires_at" name="expires_at" type="date">
                </div>
                <div class="field">
                    <label for="code_status">Estado</label>
                    <select id="code_status" name="status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="reward_json">Recompensa JSON</label>
                <textarea id="reward_json" name="reward_json" rows="4" placeholder='{"item":"skin_blue"}'></textarea>
            </div>
            <div class="actions">
                <button type="submit">Crear codigo</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Codigos</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Juego</th>
                        <th>Recompensa</th>
                        <th>Usos</th>
                        <th>Expira</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($codes === []): ?>
                        <tr><td colspan="7">No hay codigos creados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($codes as $code): ?>
                        <tr>
                            <td><code><?= e($code['code_preview']) ?></code></td>
                            <td><?= e($code['game_name'] ?? 'Global') ?></td>
                            <td><?= e($code['reward_type']) ?></td>
                            <td><?= e($code['current_uses']) ?> / <?= e($code['max_uses']) ?></td>
                            <td><?= e($code['expires_at'] ?? '') ?></td>
                            <td><?= e($code['status']) ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update_code_status">
                                    <input type="hidden" name="code_id" value="<?= e($code['id']) ?>">
                                    <input type="hidden" name="status" value="<?= $code['status'] === 'active' ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="button button--secondary">
                                        <?= $code['status'] === 'active' ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'workshop'): ?>
    <section class="panel">
        <h2>Configurar workshop por juego</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Juego</th>
                        <th>Estado</th>
                        <th>Uploads</th>
                        <th>Moderacion</th>
                        <th>Tamano max</th>
                        <th>Guardar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workshopConfigs as $configRow): ?>
                        <tr>
                            <td><?= e($configRow['game_name']) ?><br><code><?= e($configRow['game_slug']) ?></code></td>
                            <td>
                                <form class="row-form" method="post" id="workshop-config-<?= e($configRow['game_id']) ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="save_workshop_config">
                                    <input type="hidden" name="game_id" value="<?= e($configRow['game_id']) ?>">
                                    <select name="status">
                                        <option value="disabled" <?= (($configRow['status'] ?? 'disabled') === 'disabled') ? 'selected' : '' ?>>disabled</option>
                                        <option value="enabled" <?= (($configRow['status'] ?? 'disabled') === 'enabled') ? 'selected' : '' ?>>enabled</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <label class="checkbox-field checkbox-field--compact">
                                    <input form="workshop-config-<?= e($configRow['game_id']) ?>" type="checkbox" name="allow_user_uploads" value="1" <?= !empty($configRow['allow_user_uploads']) ? 'checked' : '' ?>>
                                    Usuarios
                                </label>
                            </td>
                            <td>
                                <select form="workshop-config-<?= e($configRow['game_id']) ?>" name="moderation_mode">
                                    <option value="pre" <?= (($configRow['moderation_mode'] ?? 'pre') === 'pre') ? 'selected' : '' ?>>pre</option>
                                    <option value="post" <?= (($configRow['moderation_mode'] ?? 'pre') === 'post') ? 'selected' : '' ?>>post</option>
                                </select>
                            </td>
                            <td>
                                <input form="workshop-config-<?= e($configRow['game_id']) ?>" name="max_file_bytes" type="number" min="1024" max="<?= e(200 * 1024 * 1024) ?>" value="<?= e($configRow['max_file_bytes'] ?? 10485760) ?>">
                                <textarea form="workshop-config-<?= e($configRow['game_id']) ?>" name="allowed_types_json" rows="2" placeholder='["zip","png"]'><?= e($configRow['allowed_types_json'] ?? '') ?></textarea>
                            </td>
                            <td><button form="workshop-config-<?= e($configRow['game_id']) ?>" type="submit">Guardar</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Items workshop</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Juego</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th>Accion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($workshopItems === []): ?>
                        <tr><td colspan="5">No hay items de workshop.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($workshopItems as $item): ?>
                        <tr>
                            <td><?= e($item['title']) ?><br><code><?= e($item['slug']) ?></code></td>
                            <td><?= e($item['game_name']) ?></td>
                            <td>@<?= e($item['username']) ?></td>
                            <td><?= e($item['status']) ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update_workshop_item_status">
                                    <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
                                    <select name="status">
                                        <?php foreach (['pending', 'published', 'rejected', 'hidden'] as $workshopStatus): ?>
                                            <option value="<?= e($workshopStatus) ?>" <?= $item['status'] === $workshopStatus ? 'selected' : '' ?>><?= e($workshopStatus) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button button--secondary">Cambiar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'support'): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Soporte</h2>
                <p class="muted">Vista administrativa de tickets. Para responder usa el panel soporte.</p>
            </div>
            <a class="button button--secondary" href="<?= e(url('/supporter/')) ?>">Responder tickets</a>
        </div>
        <form class="filter-bar filter-bar--inline" method="get">
            <input type="hidden" name="section" value="support">
            <label for="support_status">Estado</label>
            <select id="support_status" name="support_status" onchange="this.form.submit()">
                <?php foreach ($supportFilters as $filter): ?>
                    <option value="<?= e($filter) ?>" <?= $supportFilter === $filter ? 'selected' : '' ?>>
                        <?= e($filter === 'all' ? 'Todos' : Support::statusLabel($filter)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Usuario</th>
                        <th>Asignado</th>
                        <th>Estado</th>
                        <th>Mensajes</th>
                        <th>Accion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tickets === []): ?>
                        <tr><td colspan="6">No hay tickets para este filtro.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td>#<?= e($ticket['id']) ?> <?= e($ticket['subject']) ?></td>
                            <td><?= e($ticket['requester_username'] ?? 'Usuario eliminado') ?></td>
                            <td><?= e($ticket['assigned_username'] ?? 'Sin asignar') ?></td>
                            <td><?= e(Support::statusLabel((string) $ticket['status'])) ?></td>
                            <td><?= e($ticket['message_count'] ?? 0) ?></td>
                            <td><a class="button button--secondary" href="<?= e(url('/supporter/?ticket=' . (int) $ticket['id'] . '&status=all')) ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'logs'): ?>
    <section class="panel">
        <h2>Logs de actividad</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Evento</th>
                        <th>Nivel</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activityLogs === []): ?>
                        <tr><td colspan="5">No hay logs de actividad en base de datos.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <tr>
                            <td><?= e($log['id']) ?></td>
                            <td><?= e($log['username'] ?? '') ?></td>
                            <td><?= e($log['event']) ?></td>
                            <td><?= e($log['level']) ?></td>
                            <td><?= e($log['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Archivo app.log</h2>
        <?php if ($appLogs === []): ?>
            <p class="muted">Todavia no hay logs de aplicacion.</p>
        <?php else: ?>
            <pre class="log-view"><?= e(implode(PHP_EOL, $appLogs)) ?></pre>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
Page::footer();
