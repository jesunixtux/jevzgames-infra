<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\ExternalGames;
use App\Models\PlatformSettings;
use App\Models\Superroot;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;
use App\Services\Mailer;

require_installed();
Auth::requireRole('superroot');

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$sections = ['overview', 'config', 'features', 'content', 'access', 'integrations', 'extern-games-config', 'users', 'maintenance'];
$section = (string) ($_GET['section'] ?? 'overview');
if (!in_array($section, $sections, true)) {
    $section = 'overview';
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/superroot/');
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

        if ($action === 'save_features') {
            PlatformSettings::save($_POST);
            ActivityLogger::info('superroot_features_saved', ['user_id' => $userId]);
            flash('message', 'Funciones de plataforma actualizadas.');
            redirect_to('/superroot/?section=features');
        }

        if ($action === 'save_access_legal') {
            PlatformSettings::saveAccessLegal($_POST);
            ActivityLogger::info('superroot_access_legal_saved', ['user_id' => $userId]);
            flash('message', 'Acceso, correo y EULA actualizados.');
            redirect_to('/superroot/?section=access');
        }

        if ($action === 'save_content') {
            PlatformSettings::saveContent($_POST);
            ActivityLogger::info('superroot_content_saved', ['user_id' => $userId]);
            flash('message', 'Contenido publico actualizado.');
            redirect_to('/superroot/?section=content');
        }

        if ($action === 'save_maintenance') {
            PlatformSettings::saveMaintenance($_POST);
            ActivityLogger::info('superroot_maintenance_saved', ['user_id' => $userId]);
            flash('message', 'Modo mantenimiento actualizado.');
            redirect_to('/superroot/?section=maintenance');
        }

        if ($action === 'save_external_games_config') {
            ExternalGames::saveSettings($_POST);
            ActivityLogger::info('superroot_external_games_config_saved', ['user_id' => $userId]);
            flash('message', 'Configuracion de juegos externos actualizada.');
            redirect_to('/superroot/?section=extern-games-config');
        }

        if ($action === 'panic_reinstall') {
            $result = Superroot::panicReinstall($userId, (string) ($_POST['superroot_password'] ?? ''));
            ActivityLogger::info('superroot_panic_reinstall', ['user_id' => $userId, 'statements' => (int) $result['statements']]);
            flash('message', 'Panic reinstall completado. ' . $result['message']);
            redirect_to('/superroot/?section=maintenance');
        }

        if ($action === 'send_email_test') {
            $settings = PlatformSettings::emailVerificationSettings();
            if ($settings['delivery'] !== 'smtp') {
                throw new RuntimeException('Selecciona y guarda SMTP antes de enviar una prueba.');
            }
            Mailer::send(
                $settings,
                (string) ($user['email'] ?? ''),
                (string) ($user['username'] ?? 'superroot'),
                'Prueba SMTP JevzGames',
                "Este es un correo de prueba de JevzGames.\n\nSi lo recibiste, PHPMailer SMTP esta funcionando.",
                '<p>Este es un correo de prueba de JevzGames.</p><p>Si lo recibiste, PHPMailer SMTP esta funcionando.</p>'
            );
            ActivityLogger::info('superroot_email_test_sent', ['user_id' => $userId]);
            flash('message', 'Correo de prueba enviado a tu cuenta.');
            redirect_to('/superroot/?section=access');
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
$platformSettings = PlatformSettings::values();
$contentSettings = PlatformSettings::contentSettings();
$contentTranslations = PlatformSettings::contentTranslations();
$languageSettings = PlatformSettings::languageSettings();
$supportedLocalesText = implode(PHP_EOL, array_map(
    static fn (string $locale, string $label): string => $locale . '=' . $label,
    array_keys($languageSettings['supported_locales']),
    array_values($languageSettings['supported_locales'])
));
$maintenanceSettings = PlatformSettings::maintenanceSettings();
$externalGamesSettings = ExternalGames::settings();
$externalGamesStats = ExternalGames::stats();
$emailSettings = PlatformSettings::emailVerificationSettings();
$eulaSettings = PlatformSettings::eulaSettings();
$eulaTranslations = PlatformSettings::eulaTranslations();
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
    'features' => 'Funciones',
    'content' => 'Contenido',
    'access' => 'Acceso legal',
    'integrations' => 'Integraciones',
    'extern-games-config' => 'Extern games',
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
                    <input id="app_name" name="app_name" value="<?= e(app_config('app.name', 'JevzGames')) ?>" required maxlength="120">
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

<?php if ($section === 'features'): ?>
    <section class="panel">
        <h2>Funciones de plataforma</h2>
        <p class="muted">Estas opciones activan superficies publicas sin tocar codigo. Por defecto quedan apagadas.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_features">

            <div class="form-grid">
                <label class="checkbox-field">
                    <input type="checkbox" name="publish_on_games_enabled" value="1" <?= !empty($platformSettings['features.publish_on_games_enabled']) ? 'checked' : '' ?>>
                    Activar /publish-on-games/
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="workshop_enabled" value="1" <?= !empty($platformSettings['features.workshop_enabled']) ? 'checked' : '' ?>>
                    Activar Workshop
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="client_enabled" value="1" <?= !empty($platformSettings['features.client_enabled']) ? 'checked' : '' ?>>
                    Activar cliente tipo Steam
                </label>
            </div>

            <h3>Cliente</h3>
            <div class="form-grid">
                <div class="field">
                    <label for="client_name">Nombre del cliente</label>
                    <input id="client_name" name="client_name" value="<?= e($platformSettings['client.name'] ?? 'JevzGames Client') ?>" maxlength="120">
                </div>
                <div class="field">
                    <label for="client_download_url">URL de descarga</label>
                    <input id="client_download_url" name="client_download_url" value="<?= e($platformSettings['client.download_url'] ?? '') ?>" placeholder="https://example.com/client.zip">
                </div>
                <div class="field">
                    <label for="client_min_version">Version minima</label>
                    <input id="client_min_version" name="client_min_version" value="<?= e($platformSettings['client.min_version'] ?? '0.1.0') ?>" maxlength="40">
                </div>
            </div>

            <div class="field">
                <label for="client_config_json">Config JSON del cliente</label>
                <textarea id="client_config_json" name="client_config_json" rows="5" placeholder='{"theme":"default","news_url":""}'><?= e(is_array($platformSettings['client.config_json'] ?? null) ? json_encode($platformSettings['client.config_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '') ?></textarea>
            </div>

            <div class="actions">
                <button type="submit">Guardar funciones</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if ($section === 'content'): ?>
    <section class="panel">
        <h2>Contenido editable</h2>
        <p class="muted">Textos principales e idiomas que Superroot puede cambiar sin editar archivos.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_content">
            <div class="field">
                <label for="supported_locales_text">Idiomas disponibles</label>
                <textarea id="supported_locales_text" name="supported_locales_text" rows="4" placeholder="en=English&#10;es=Español"><?= e($supportedLocalesText) ?></textarea>
                <p class="muted">Formato por linea: <code>codigo=Nombre</code>. Despues de guardar aparecen campos para cada idioma.</p>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="default_locale">Idioma por defecto</label>
                    <select id="default_locale" name="default_locale">
                        <?php foreach ($languageSettings['supported_locales'] as $locale => $label): ?>
                            <option value="<?= e($locale) ?>" <?= $languageSettings['default_locale'] === $locale ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Idiomas activos</label>
                    <div class="role-checks">
                        <?php foreach ($languageSettings['supported_locales'] as $locale => $label): ?>
                            <label class="checkbox-field checkbox-field--compact">
                                <input type="checkbox" name="enabled_locales[]" value="<?= e($locale) ?>" <?= in_array($locale, $languageSettings['enabled_locales'], true) ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php foreach ($languageSettings['supported_locales'] as $locale => $label): ?>
                <?php $translation = $contentTranslations[$locale] ?? $contentSettings; ?>
                <h3><?= e($label) ?> <span class="muted">(<?= e($locale) ?>)</span></h3>
                <div class="form-grid">
                    <div class="field">
                        <label for="content_<?= e($locale) ?>_home_title">Titulo de inicio</label>
                        <input id="content_<?= e($locale) ?>_home_title" name="content[<?= e($locale) ?>][home_title]" value="<?= e($translation['home_title'] ?? '') ?>" maxlength="160">
                    </div>
                    <div class="field">
                        <label for="content_<?= e($locale) ?>_footer_text">Footer</label>
                        <input id="content_<?= e($locale) ?>_footer_text" name="content[<?= e($locale) ?>][footer_text]" value="<?= e($translation['footer_text'] ?? '') ?>" maxlength="240">
                    </div>
                </div>
                <div class="field">
                    <label for="content_<?= e($locale) ?>_home_intro">Texto de inicio</label>
                    <textarea id="content_<?= e($locale) ?>_home_intro" name="content[<?= e($locale) ?>][home_intro]" rows="4" maxlength="1000"><?= e($translation['home_intro'] ?? '') ?></textarea>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="content_<?= e($locale) ?>_games_intro">Texto del catalogo</label>
                        <textarea id="content_<?= e($locale) ?>_games_intro" name="content[<?= e($locale) ?>][games_intro]" rows="4" maxlength="500"><?= e($translation['games_intro'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label for="content_<?= e($locale) ?>_library_intro">Texto de biblioteca</label>
                        <textarea id="content_<?= e($locale) ?>_library_intro" name="content[<?= e($locale) ?>][library_intro]" rows="4" maxlength="500"><?= e($translation['library_intro'] ?? '') ?></textarea>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="actions">
                <button type="submit">Guardar contenido</button>
                <a class="button button--secondary" href="<?= e(url('/')) ?>">Ver inicio</a>
                <a class="button button--secondary" href="<?= e(url('/games/')) ?>">Ver catalogo</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if ($section === 'access'): ?>
    <section class="panel">
        <h2>Correo y EULA</h2>
        <p class="muted">Controla verificacion de correo, recuperacion de contrasena y el EULA vigente sin editar codigo.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_access_legal">

            <h3>Verificacion por correo</h3>
            <div class="form-grid">
                <label class="checkbox-field">
                    <input type="checkbox" name="email_verification_enabled" value="1" <?= $emailSettings['enabled'] ? 'checked' : '' ?>>
                    Activar verificacion por correo
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="email_verification_required" value="1" <?= $emailSettings['required'] ? 'checked' : '' ?>>
                    Requerir correo verificado para iniciar sesion
                </label>
                <div class="field">
                    <label for="email_verification_delivery">Envio</label>
                    <select id="email_verification_delivery" name="email_verification_delivery">
                        <option value="log" <?= $emailSettings['delivery'] === 'log' ? 'selected' : '' ?>>Log local</option>
                        <option value="smtp" <?= $emailSettings['delivery'] === 'smtp' ? 'selected' : '' ?>>SMTP con PHPMailer</option>
                    </select>
                </div>
                <div class="field">
                    <label for="email_verification_ttl_hours">Expira en horas</label>
                    <input id="email_verification_ttl_hours" name="email_verification_ttl_hours" type="number" min="1" max="720" value="<?= e($emailSettings['ttl_hours']) ?>">
                </div>
                <div class="field">
                    <label for="email_verification_from">Remitente</label>
                    <input id="email_verification_from" name="email_verification_from" type="email" value="<?= e($emailSettings['from']) ?>">
                </div>
                <div class="field">
                    <label for="email_verification_from_name">Nombre remitente</label>
                    <input id="email_verification_from_name" name="email_verification_from_name" value="<?= e($emailSettings['from_name']) ?>" maxlength="120">
                </div>
                <div class="field">
                    <label for="email_verification_subject">Asunto</label>
                    <input id="email_verification_subject" name="email_verification_subject" value="<?= e($emailSettings['subject']) ?>" maxlength="180">
                </div>
            </div>
            <p class="muted">En XAMPP puedes usar <code>Log local</code>. Para envio real usa SMTP con PHPMailer. Esta misma configuracion se usa para restablecer contrasenas.</p>

            <h3>SMTP PHPMailer</h3>
            <div class="form-grid">
                <div class="field">
                    <label for="smtp_host">Host SMTP</label>
                    <input id="smtp_host" name="smtp_host" value="<?= e($emailSettings['smtp']['host'] ?? '') ?>" placeholder="smtp.gmail.com">
                </div>
                <div class="field">
                    <label for="smtp_port">Puerto</label>
                    <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= e($emailSettings['smtp']['port'] ?? 587) ?>">
                </div>
                <div class="field">
                    <label for="smtp_encryption">Seguridad</label>
                    <select id="smtp_encryption" name="smtp_encryption">
                        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'Sin cifrado'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (($emailSettings['smtp']['encryption'] ?? 'tls') === $value) ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="smtp_timeout">Timeout segundos</label>
                    <input id="smtp_timeout" name="smtp_timeout" type="number" min="1" max="120" value="<?= e($emailSettings['smtp']['timeout'] ?? 15) ?>">
                </div>
                <label class="checkbox-field">
                    <input type="checkbox" name="smtp_auth" value="1" <?= !empty($emailSettings['smtp']['auth']) ? 'checked' : '' ?>>
                    Usar autenticacion SMTP
                </label>
                <div class="field">
                    <label for="smtp_username">Usuario SMTP</label>
                    <input id="smtp_username" name="smtp_username" value="<?= e($emailSettings['smtp']['username'] ?? '') ?>" autocomplete="off">
                </div>
                <div class="field">
                    <label for="smtp_password">Password SMTP</label>
                    <input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password" placeholder="<?= !empty($emailSettings['smtp']['password_configured']) ? 'Guardado - deja vacio para conservarlo' : '' ?>">
                </div>
            </div>

            <h3>EULA</h3>
            <div class="form-grid">
                <label class="checkbox-field">
                    <input type="checkbox" name="eula_enabled" value="1" <?= $eulaSettings['enabled'] ? 'checked' : '' ?>>
                    Activar EULA publico
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="eula_required" value="1" <?= $eulaSettings['required'] ? 'checked' : '' ?>>
                    Requerir aceptar EULA vigente
                </label>
            </div>

            <?php foreach ($languageSettings['supported_locales'] as $locale => $label): ?>
                <?php $eulaTranslation = $eulaTranslations[$locale] ?? $eulaSettings; ?>
                <h4><?= e($label) ?> <span class="muted">(<?= e($locale) ?>)</span></h4>
                <div class="form-grid">
                    <div class="field">
                        <label for="eula_<?= e($locale) ?>_version">Version</label>
                        <input id="eula_<?= e($locale) ?>_version" name="eula[<?= e($locale) ?>][version]" value="<?= e($eulaTranslation['version'] ?? '1.0') ?>" maxlength="40">
                    </div>
                    <div class="field">
                        <label for="eula_<?= e($locale) ?>_title">Titulo</label>
                        <input id="eula_<?= e($locale) ?>_title" name="eula[<?= e($locale) ?>][title]" value="<?= e($eulaTranslation['title'] ?? '') ?>" maxlength="180">
                    </div>
                </div>
                <div class="field">
                    <label for="eula_<?= e($locale) ?>_body">Texto del EULA</label>
                    <textarea id="eula_<?= e($locale) ?>_body" name="eula[<?= e($locale) ?>][body]" rows="10"><?= e($eulaTranslation['body'] ?? '') ?></textarea>
                </div>
            <?php endforeach; ?>

            <div class="actions">
                <button type="submit">Guardar acceso legal</button>
                <a class="button button--secondary" href="<?= e(url('/eula/')) ?>">Ver EULA publico</a>
                <a class="button button--secondary" href="<?= e(url('/verify-email/')) ?>">Verificar correo</a>
            </div>
        </form>

        <form class="inline-form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="send_email_test">
            <button type="submit" class="button button--secondary">Enviar prueba SMTP a mi correo</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($section === 'integrations'): ?>
    <section class="panel">
        <h2><?= $editingIntegration ? 'Editar integracion' : 'Nueva integracion' ?></h2>
        <p class="muted">Steam, Epic, GOG u otros proveedores quedan configurados en base de datos. Para mostrar boton de login usa <code>login_enabled</code> en el JSON.</p>
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
                <textarea id="config_json" name="config_json" rows="5" placeholder='{"login_enabled":true,"auth_url":"https://provider/oauth/authorize","token_url":"https://provider/oauth/token","userinfo_url":"https://provider/oauth/userinfo","scope":"openid profile email","client_secret":"opcional"}'><?= e($editingIntegration['config_json'] ?? '') ?></textarea>
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

<?php if ($section === 'extern-games-config'): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Extern games config</h2>
                <p class="muted">Base dedicada para juegos de terceros. Todo queda apagado por defecto y solo el rol <code>developer-extern</code> puede publicar desde su apartado.</p>
            </div>
            <span class="status-pill <?= ($externalGamesSettings['enabled'] && $externalGamesSettings['allow_publish'] && $externalGamesSettings['configured']) ? 'status-pill--published' : 'status-pill--archived' ?>">
                <?= ($externalGamesSettings['enabled'] && $externalGamesSettings['allow_publish'] && $externalGamesSettings['configured']) ? 'Activo' : 'Deshabilitado' ?>
            </span>
        </div>

        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_external_games_config">
            <div class="form-grid">
                <label class="checkbox-field">
                    <input type="checkbox" name="external_games_enabled" value="1" <?= $externalGamesSettings['enabled'] ? 'checked' : '' ?>>
                    Activar juegos externos
                </label>
                <label class="checkbox-field">
                    <input type="checkbox" name="external_games_allow_publish" value="1" <?= $externalGamesSettings['allow_publish'] ? 'checked' : '' ?>>
                    Permitir publicar/configurar
                </label>
                <div class="field">
                    <label for="external_games_db_host">Host BD externa</label>
                    <input id="external_games_db_host" name="external_games_db_host" value="<?= e($externalGamesSettings['db_host']) ?>" placeholder="127.0.0.1">
                </div>
                <div class="field">
                    <label for="external_games_db_port">Puerto</label>
                    <input id="external_games_db_port" name="external_games_db_port" type="number" min="1" max="65535" value="<?= e($externalGamesSettings['db_port']) ?>">
                </div>
                <div class="field">
                    <label for="external_games_db_name">Base de datos</label>
                    <input id="external_games_db_name" name="external_games_db_name" value="<?= e($externalGamesSettings['db_name']) ?>" placeholder="jevzgames_external">
                </div>
                <div class="field">
                    <label for="external_games_db_user">Usuario BD</label>
                    <input id="external_games_db_user" name="external_games_db_user" value="<?= e($externalGamesSettings['db_user']) ?>" autocomplete="off">
                </div>
                <div class="field">
                    <label for="external_games_db_password">Password BD</label>
                    <input id="external_games_db_password" name="external_games_db_password" type="password" autocomplete="new-password" placeholder="<?= $externalGamesSettings['db_password_configured'] ? 'Guardado - deja vacio para conservarlo' : '' ?>">
                </div>
            </div>
            <div class="actions">
                <button type="submit">Guardar juegos externos</button>
                <a class="button button--secondary" href="<?= e(url('/external-games/')) ?>">Abrir apartado externo</a>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Estado de base externa</h2>
        <dl class="meta">
            <div><dt>Configurada</dt><dd><?= e($boolText((bool) $externalGamesSettings['configured'])) ?></dd></div>
            <div><dt>Conexion</dt><dd><?= e($externalGamesStats['message']) ?></dd></div>
            <div><dt>Juegos externos</dt><dd><?= e($externalGamesStats['external_games']) ?></dd></div>
            <div><dt>Jugadores externos</dt><dd><?= e($externalGamesStats['external_players']) ?></dd></div>
        </dl>
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
                                        <?php foreach (['user' => 'User', 'developer' => 'Developer', 'developer-extern' => 'Developer extern', 'admin' => 'Admin', 'supporter' => 'Supporter'] as $role => $label): ?>
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
        <h2>Modo mantenimiento</h2>
        <p class="muted">Cuando esta activo, solo Admin, Superroot y Developer pueden navegar la plataforma.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_maintenance">
            <label class="checkbox-field">
                <input type="checkbox" name="maintenance_enabled" value="1" <?= $maintenanceSettings['enabled'] ? 'checked' : '' ?>>
                Activar modo mantenimiento
            </label>
            <div class="field">
                <label for="maintenance_message">Mensaje publico</label>
                <textarea id="maintenance_message" name="maintenance_message" rows="4" maxlength="1000"><?= e($maintenanceSettings['message']) ?></textarea>
            </div>
            <div class="actions">
                <button type="submit">Guardar mantenimiento</button>
            </div>
        </form>
    </section>

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
        <h2>Panic reinstall</h2>
        <p class="muted">Reaplica <code>database/schema.sql</code>, <code>database/seeds.sql</code> y las migraciones runtime sin borrar datos existentes. No ejecuta DROP ni TRUNCATE.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="panic_reinstall">
            <div class="field">
                <label for="superroot_password">Password Superroot</label>
                <input id="superroot_password" name="superroot_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="actions">
                <button type="submit" class="button button--danger">Ejecutar panic reinstall</button>
            </div>
        </form>
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
