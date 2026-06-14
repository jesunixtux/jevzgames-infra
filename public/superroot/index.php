<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Superroot;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();
Auth::requireRole('superroot');

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$sections = ['overview', 'config', 'integrations', 'users', 'maintenance'];
$section = (string) ($_GET['section'] ?? 'overview');
if (!in_array($section, $sections, true)) {
    $section = 'overview';
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/superroot/');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_config') {
            Superroot::updateCoreConfig($_POST);
            ActivityLogger::info('superroot_config_updated', ['user_id' => $userId]);
            flash('message', 'Configuracion actualizada.');
            redirect_to('/superroot/?section=config');
        }

        if ($action === 'save_integration') {
            $integrationId = Superroot::saveIntegration($_POST);
            ActivityLogger::info('superroot_integration_saved', ['user_id' => $userId, 'integration_id' => $integrationId]);
            flash('message', 'Integracion guardada.');
            redirect_to('/superroot/?section=integrations');
        }

        if ($action === 'toggle_integration') {
            $integrationId = (int) ($_POST['integration_id'] ?? 0);
            Superroot::toggleIntegration($integrationId, (string) ($_POST['status'] ?? 'inactive'));
            ActivityLogger::info('superroot_integration_toggled', ['user_id' => $userId, 'integration_id' => $integrationId]);
            flash('message', 'Estado de integracion actualizado.');
            redirect_to('/superroot/?section=integrations');
        }

        if ($action === 'update_user') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            $roles = isset($_POST['roles']) && is_array($_POST['roles']) ? $_POST['roles'] : [];
            Superroot::updateUserAccess($targetUserId, (string) ($_POST['status'] ?? 'active'), $roles, $userId);
            ActivityLogger::info('superroot_user_access_updated', ['user_id' => $userId, 'target_user_id' => $targetUserId]);
            flash('message', 'Usuario actualizado.');
            redirect_to('/superroot/?section=users');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/superroot/?section=' . rawurlencode($section));
    }
}

$stats = Superroot::dashboardStats();
$integrations = Superroot::integrations();
$users = Superroot::users();
$maintenance = Superroot::maintenanceInfo();
$logLines = Superroot::recentLogLines(30);
$config = app_config();
$editIntegrationId = (int) ($_GET['edit_integration'] ?? 0);
$editingIntegration = null;
foreach ($integrations as $integration) {
    if ((int) $integration['id'] === $editIntegrationId) {
        $editingIntegration = $integration;
        break;
    }
}

$sectionLabels = [
    'overview' => 'Resumen',
    'config' => 'Configuracion',
    'integrations' => 'Integraciones',
    'users' => 'Usuarios',
    'maintenance' => 'Mantenimiento',
];

$boolText = static fn (bool $value): string => $value ? 'Si' : 'No';

Page::header('Superroot');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Panel Superroot</h1>
            <p class="muted">Configuracion sensible, integraciones, roles y mantenimiento de la infraestructura.</p>
        </div>
        <span class="status-pill status-pill--solved">Solo superroot</span>
    </div>
</section>

<nav class="tab-nav" aria-label="Secciones superroot">
    <?php foreach ($sectionLabels as $key => $label): ?>
        <a class="<?= $section === $key ? 'tab-nav__item tab-nav__item--active' : 'tab-nav__item' ?>" href="<?= e(url('/superroot/?section=' . $key)) ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($section === 'overview'): ?>
    <section class="grid">
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['users']) ?></span>
            <h2>Usuarios</h2>
            <p class="muted">Cuentas registradas en la base principal.</p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['games']) ?></span>
            <h2>Juegos</h2>
            <p class="muted">Juegos registrados o preparados.</p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['open_tickets']) ?></span>
            <h2>Tickets abiertos</h2>
            <p class="muted">Solicitudes de soporte activas.</p>
        </article>
        <article class="tile metric-tile">
            <span class="metric"><?= e($stats['active_integrations']) ?></span>
            <h2>Integraciones activas</h2>
            <p class="muted">Proveedores externos habilitados.</p>
        </article>
    </section>

    <section class="panel">
        <h2>Estado actual</h2>
        <dl class="meta">
            <div><dt>Plataforma</dt><dd><?= e(app_config('app.name', '')) ?></dd></div>
            <div><dt>URL base</dt><dd><?= e(app_config('app.base_url', '')) ?></dd></div>
            <div><dt>Entorno</dt><dd><?= e(app_config('app.environment', '')) ?></dd></div>
            <div><dt>Servidor</dt><dd><?= e(app_config('app.server', '')) ?></dd></div>
            <div><dt>CDN externa</dt><dd><?= e($boolText((bool) app_config('cdn.enabled', false))) ?></dd></div>
            <div><dt>Admins</dt><dd><?= e($stats['admins']) ?></dd></div>
        </dl>
    </section>
<?php endif; ?>

<?php if ($section === 'config'): ?>
    <section class="panel">
        <h2>Configuracion global</h2>
        <p class="muted">Estos cambios escriben <code>app/config/config.php</code> y sincronizan <code>system_settings</code>.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update_config">

            <div class="form-grid">
                <div class="field">
                    <label for="app_name">Nombre de plataforma</label>
                    <input id="app_name" name="app_name" value="<?= e(app_config('app.name', 'JevzGames Infra')) ?>" required maxlength="120">
                </div>
                <div class="field">
                    <label for="base_url">URL base</label>
                    <input id="base_url" name="base_url" value="<?= e(app_config('app.base_url', '')) ?>" placeholder="http://jevzgames.local">
                </div>
                <div class="field">
                    <label for="environment">Entorno</label>
                    <select id="environment" name="environment">
                        <?php foreach (['development', 'production'] as $environment): ?>
                            <option value="<?= e($environment) ?>" <?= app_config('app.environment', 'development') === $environment ? 'selected' : '' ?>>
                                <?= e($environment) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="server">Servidor</label>
                    <select id="server" name="server">
                        <?php foreach (['apache', 'nginx'] as $server): ?>
                            <option value="<?= e($server) ?>" <?= app_config('app.server', 'apache') === $server ? 'selected' : '' ?>>
                                <?= e($server) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h3>CDN</h3>
            <div class="form-grid">
                <label class="checkbox-field">
                    <input type="checkbox" name="cdn_enabled" value="1" <?= (bool) app_config('cdn.enabled', false) ? 'checked' : '' ?>>
                    Usar CDN externa
                </label>
                <div class="field">
                    <label for="cdn_url">URL CDN externa</label>
                    <input id="cdn_url" name="cdn_url" value="<?= e(app_config('cdn.url', '')) ?>" placeholder="https://cdn.ejemplo.com">
                </div>
            </div>

            <h3>Seguridad y API</h3>
            <div class="form-grid">
                <div class="field">
                    <label for="session_lifetime">Duracion de sesion en segundos</label>
                    <input id="session_lifetime" name="session_lifetime" type="number" min="300" max="86400" value="<?= e(app_config('session.lifetime', 7200)) ?>" required>
                </div>
                <label class="checkbox-field">
                    <input type="checkbox" name="session_secure" value="1" <?= (bool) app_config('session.secure', false) ? 'checked' : '' ?>>
                    Cookies solo HTTPS
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="api_expose_errors" value="1" <?= (bool) app_config('api.expose_errors', false) ? 'checked' : '' ?>>
                    Exponer errores API en desarrollo
                </label>
            </div>

            <div class="actions">
                <button type="submit">Guardar configuracion</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if ($section === 'integrations'): ?>
    <section class="panel">
        <h2><?= $editingIntegration ? 'Editar integracion' : 'Nueva integracion' ?></h2>
        <p class="muted">Steam, Epic, GOG u otros proveedores quedan configurados en base de datos, no hardcodeados.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_integration">
            <input type="hidden" name="integration_id" value="<?= e($editingIntegration['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Nombre</label>
                    <input id="name" name="name" value="<?= e($editingIntegration['name'] ?? '') ?>" required maxlength="120" placeholder="Steam">
                </div>
                <div class="field">
                    <label for="provider">Proveedor</label>
                    <input id="provider" name="provider" value="<?= e($editingIntegration['provider'] ?? '') ?>" required maxlength="80" placeholder="steam">
                </div>
                <div class="field">
                    <label for="client_id">Client ID</label>
                    <input id="client_id" name="client_id" value="<?= e($editingIntegration['client_id'] ?? '') ?>" maxlength="190">
                </div>
                <div class="field">
                    <label for="status">Estado</label>
                    <select id="status" name="status">
                        <?php foreach (['inactive' => 'Inactiva', 'active' => 'Activa'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (($editingIntegration['status'] ?? 'inactive') === $value) ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="client_secret">Client Secret</label>
                <input id="client_secret" name="client_secret" type="password" placeholder="<?= $editingIntegration ? 'Dejar vacio para conservar el secreto actual' : '' ?>">
            </div>

            <div class="field">
                <label for="config_json">Configuracion JSON</label>
                <textarea id="config_json" name="config_json" rows="5" placeholder='{"redirect_uri":"http://jevzgames.local/auth/callback"}'><?= e($editingIntegration['config_json'] ?? '') ?></textarea>
            </div>

            <div class="actions">
                <button type="submit"><?= $editingIntegration ? 'Guardar cambios' : 'Crear integracion' ?></button>
                <?php if ($editingIntegration): ?>
                    <a class="button button--secondary" href="<?= e(url('/superroot/?section=integrations')) ?>">Cancelar edicion</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Integraciones configuradas</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>Nombre</th>
                        <th>Client ID</th>
                        <th>Secreto</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($integrations === []): ?>
                        <tr><td colspan="6">No hay integraciones configuradas.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($integrations as $integration): ?>
                        <tr>
                            <td><code><?= e($integration['provider']) ?></code></td>
                            <td><?= e($integration['name']) ?></td>
                            <td><?= e($integration['client_id'] ?? '') ?></td>
                            <td><?= ((int) $integration['has_secret'] === 1) ? 'Guardado como hash' : 'Sin secreto' ?></td>
                            <td><?= e($integration['status']) ?></td>
                            <td class="table-actions">
                                <a class="button button--secondary" href="<?= e(url('/superroot/?section=integrations&edit_integration=' . (int) $integration['id'])) ?>">Editar</a>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="toggle_integration">
                                    <input type="hidden" name="integration_id" value="<?= e($integration['id']) ?>">
                                    <input type="hidden" name="status" value="<?= $integration['status'] === 'active' ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="button button--secondary">
                                        <?= $integration['status'] === 'active' ? 'Desactivar' : 'Activar' ?>
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

<?php if ($section === 'users'): ?>
    <section class="panel">
        <h2>Usuarios y roles</h2>
        <p class="muted">El rol <code>superroot</code> no se asigna ni se quita desde esta tabla.</p>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Estado</th>
                        <th>Roles</th>
                        <th>Ultimo login</th>
                        <th>Guardar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $managedUser): ?>
                        <?php $isSuperroot = in_array('superroot', $managedUser['roles'], true); ?>
                        <tr>
                            <td>
                                <strong><?= e($managedUser['username']) ?></strong><br>
                                <span class="muted">#<?= e($managedUser['id']) ?></span>
                            </td>
                            <td><?= e($managedUser['email']) ?></td>
                            <td>
                                <?php if ($isSuperroot): ?>
                                    <?= e($managedUser['status']) ?>
                                <?php else: ?>
                                    <form class="row-form" method="post" id="user-form-<?= e($managedUser['id']) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="update_user">
                                        <input type="hidden" name="target_user_id" value="<?= e($managedUser['id']) ?>">
                                        <select name="status">
                                            <?php foreach (['active' => 'Activa', 'blocked' => 'Bloqueada', 'pending_recovery' => 'Recuperacion'] as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $managedUser['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSuperroot): ?>
                                    <span class="status-pill status-pill--solved">superroot</span>
                                <?php else: ?>
                                    <div class="role-checks" form="user-form-<?= e($managedUser['id']) ?>">
                                        <?php foreach (['user' => 'User', 'developer' => 'Developer', 'admin' => 'Admin', 'supporter' => 'Supporter'] as $role => $label): ?>
                                            <label class="checkbox-field checkbox-field--compact">
                                                <input form="user-form-<?= e($managedUser['id']) ?>" type="checkbox" name="roles[]" value="<?= e($role) ?>" <?= in_array($role, $managedUser['roles'], true) ? 'checked' : '' ?>>
                                                <?= e($label) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($managedUser['last_login_at'] ?? 'Nunca') ?></td>
                            <td>
                                <?php if ($isSuperroot): ?>
                                    <span class="muted">Protegido</span>
                                <?php else: ?>
                                    <button form="user-form-<?= e($managedUser['id']) ?>" type="submit">Guardar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'maintenance'): ?>
    <section class="panel">
        <h2>Mantenimiento</h2>
        <dl class="meta">
            <div><dt>PHP</dt><dd><?= e($maintenance['php_version']) ?></dd></div>
            <div><dt>Base de datos</dt><dd><?= e($maintenance['db_version']) ?></dd></div>
            <div><dt>Instalado</dt><dd><?= e($boolText((bool) $maintenance['installed'])) ?></dd></div>
            <div><dt>Config escribible</dt><dd><?= e($boolText((bool) $maintenance['config_writable'])) ?></dd></div>
            <div><dt>Logs escribibles</dt><dd><?= e($boolText((bool) $maintenance['logs_writable'])) ?></dd></div>
            <div><dt>Sesiones escribibles</dt><dd><?= e($boolText((bool) $maintenance['sessions_writable'])) ?></dd></div>
            <div><dt>Config</dt><dd><code><?= e($maintenance['config_path']) ?></code></dd></div>
            <div><dt>Lock</dt><dd><code><?= e($maintenance['lock_path']) ?></code></dd></div>
            <div><dt>Log</dt><dd><code><?= e($maintenance['log_path']) ?></code></dd></div>
        </dl>
    </section>

    <section class="panel">
        <h2>Ultimos logs</h2>
        <?php if ($logLines === []): ?>
            <p class="muted">Todavia no hay logs de aplicacion.</p>
        <?php else: ?>
            <pre class="log-view"><?= e(implode(PHP_EOL, $logLines)) ?></pre>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
Page::footer();
