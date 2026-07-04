<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\DeveloperApi;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();
Auth::requireRole(['developer', 'developer-extern', 'admin', 'superroot']);

$canSeeAdminTutorials = Auth::hasRole(['admin', 'superroot']);
$testResult = null;
$testError = null;

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/tutorials/');
    }

    try {
        $clientToken = trim((string) ($_POST['client_token'] ?? ''));
        if ($clientToken === '') {
            throw new RuntimeException(i18n_text('Ingresa un client_token.', 'Enter a client_token.'));
        }

        $context = DeveloperApi::context($clientToken);
        $input = [
            'game_id' => (int) ($_POST['game_id'] ?? 0),
            'slug' => (string) ($_POST['slug'] ?? ''),
            'test' => (string) ($_POST['test'] ?? 'game_info'),
            'version' => (string) ($_POST['version'] ?? '0.0.0'),
            'public_key' => (string) ($_POST['public_key'] ?? ''),
        ];
        $testResult = DeveloperApi::runTest($context, $input);
    } catch (Throwable $exception) {
        $testError = $exception->getMessage();
    }
}

Page::header(i18n_text('Tutoriales', 'Tutorials'));
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e(i18n_text('Tutoriales', 'Tutorials')) ?></h1>
            <p class="muted"><?= e(i18n_text('Guias bilingues para montar la plataforma, conectar cuentas y probar las APIs.', 'Bilingual guides for setting up the platform, connecting accounts and testing APIs.')) ?></p>
        </div>
        <span class="status-pill"><?= e($canSeeAdminTutorials ? i18n_text('Acceso completo', 'Full access') : i18n_text('APIs de juegos', 'Game APIs')) ?></span>
    </div>
</section>

<?php if ($canSeeAdminTutorials): ?>
<section class="grid profile-grid">
    <article class="panel">
        <h2><?= e(i18n_text('Instalacion local XAMPP', 'Local XAMPP setup')) ?></h2>
        <ol class="list">
            <li><?= e(i18n_text('Copia el proyecto dentro de C:\\xampp y configura Apache para apuntar al directorio public.', 'Copy the project inside C:\\xampp and configure Apache to point at the public directory.')) ?></li>
            <li><?= e(i18n_text('Crea la base principal y ejecuta el instalador inicial.', 'Create the main database and run the initial installer.')) ?></li>
            <li><?= e(i18n_text('Verifica /api/status/ antes de conectar clientes o juegos.', 'Verify /api/status/ before connecting clients or games.')) ?></li>
        </ol>
    </article>

    <article class="panel">
        <h2><?= e(i18n_text('Superroot y funciones', 'Superroot and features')) ?></h2>
        <p class="muted"><?= e(i18n_text('Desde Superroot puedes activar cliente, workshop, publicacion abierta, correo, EULA, idiomas, mantenimiento e integraciones externas.', 'From Superroot you can enable client, workshop, open publishing, email, EULA, languages, maintenance and external integrations.')) ?></p>
        <p><a class="button button--secondary" href="<?= e(url('/superroot/')) ?>"><?= e(i18n_text('Abrir Superroot', 'Open Superroot')) ?></a></p>
    </article>

    <article class="panel">
        <h2><?= e(i18n_text('Steam Connect', 'Steam Connect')) ?></h2>
        <p class="muted"><?= e(i18n_text('Crea una integracion activa con provider steam y config_json con connect_enabled true. steam_api_key es opcional para traer nombre y avatar.', 'Create an active integration with provider steam and config_json with connect_enabled true. steam_api_key is optional for fetching name and avatar.')) ?></p>
        <pre class="code-view">{"login_enabled":false,"connect_enabled":true,"steam_api_key":"optional"}</pre>
    </article>

    <article class="panel">
        <h2><?= e(i18n_text('Correo y EULA', 'Email and EULA')) ?></h2>
        <p class="muted"><?= e(i18n_text('Configura PHPMailer SMTP, verificacion por correo y versiones EN/ES del EULA desde Superroot.', 'Configure PHPMailer SMTP, email verification and EN/ES EULA versions from Superroot.')) ?></p>
    </article>
</section>
<?php endif; ?>

<section class="panel">
    <h2><?= e(i18n_text('Flujo recomendado para juegos', 'Recommended game flow')) ?></h2>
    <ol class="list">
        <li><?= e(i18n_text('Obtén o crea una API key del juego desde Admin o las APIs developer.', 'Get or create a game API key from Admin or the developer APIs.')) ?></li>
        <li><?= e(i18n_text('La app pide device-code con public_key y abre verification_uri_complete.', 'The app requests a device-code with public_key and opens verification_uri_complete.')) ?></li>
        <li><?= e(i18n_text('El usuario aprueba el acceso y la app hace polling a /api/oauth/token/.', 'The user approves access and the app polls /api/oauth/token/.')) ?></li>
        <li><?= e(i18n_text('Con access_token usa player-data, achievements, inventory, redeem, cloud saves y DRM.', 'With access_token use player-data, achievements, inventory, redeem, cloud saves and DRM.')) ?></li>
    </ol>
</section>

<section class="panel">
    <h2><?= e(i18n_text('Unity SDK y launcher', 'Unity SDK and launcher')) ?></h2>
    <p class="muted"><?= e(i18n_text('El paquete SDK permite que un juego iniciado por el cliente tipo Steam lea el token del launcher, marque presencia y desbloquee logros con notificacion inferior en pantalla.', 'The SDK package lets a game launched by the Steam-like client read the launcher token, set presence and unlock achievements with a bottom-screen notification.')) ?></p>
    <ol class="list">
        <li><?= e(i18n_text('Importa sdks/unity/JevzGamesApi.unitypackage en Unity.', 'Import sdks/unity/JevzGamesApi.unitypackage in Unity.')) ?></li>
        <li><?= e(i18n_text('Agrega JevzGamesLauncherBridge a un GameObject de la primera escena.', 'Add JevzGamesLauncherBridge to a GameObject in the first scene.')) ?></li>
        <li><?= e(i18n_text('El launcher debe abrir el juego con --jevzgames-api, --jevzgames-token y --jevzgames-game, o con variables JEVZGAMES_API_BASE, JEVZGAMES_CLIENT_TOKEN y JEVZGAMES_GAME_SLUG.', 'The launcher should open the game with --jevzgames-api, --jevzgames-token and --jevzgames-game, or with JEVZGAMES_API_BASE, JEVZGAMES_CLIENT_TOKEN and JEVZGAMES_GAME_SLUG variables.')) ?></li>
        <li><?= e(i18n_text('Desde el juego llama UnlockAchievement con el codigo configurado en Admin; el launcher/API responde sin exponer ese codigo en la UI.', 'From the game call UnlockAchievement with the code configured in Admin; the launcher/API response does not expose that code in the UI.')) ?></li>
    </ol>
    <pre class="code-view">using JevzGames.Api;

public void OnFirstRun()
{
    JevzGamesApiClient.Instance.UnlockAchievement("first_run");
}</pre>
</section>

<section class="grid profile-grid">
    <article class="panel">
        <h2><?= e(i18n_text('Endpoints developer', 'Developer endpoints')) ?></h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= e(i18n_text('Endpoint', 'Endpoint')) ?></th>
                        <th><?= e(i18n_text('Uso', 'Purpose')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>/api/developer/games/list/</code></td><td><?= e(i18n_text('Lista juegos accesibles.', 'List accessible games.')) ?></td></tr>
                    <tr><td><code>/api/developer/games/detail/</code></td><td><?= e(i18n_text('Detalle, builds y API keys sin secret.', 'Details, builds and API keys without secrets.')) ?></td></tr>
                    <tr><td><code>/api/developer/api-keys/create/</code></td><td><?= e(i18n_text('Crea una key y muestra secret una sola vez.', 'Create a key and show the secret only once.')) ?></td></tr>
                    <tr><td><code>/api/developer/api-keys/revoke/</code></td><td><?= e(i18n_text('Revoca una key del juego permitido.', 'Revoke a key for an allowed game.')) ?></td></tr>
                    <tr><td><code>/api/developer/games/test/</code></td><td><?= e(i18n_text('Devuelve request/response para pruebas guiadas.', 'Return request/response for guided tests.')) ?></td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel">
        <h2><?= e(i18n_text('Ejemplo cURL', 'cURL example')) ?></h2>
        <pre class="code-view">curl -X POST <?= e(url('/api/developer/games/list/')) ?> \
  -H "Authorization: Bearer jvg_ct_..." \
  -H "Content-Type: application/json" \
  -d "{}"</pre>
    </article>
</section>

<section class="panel">
    <h2><?= e(i18n_text('Probar APIs de juego', 'Test game APIs')) ?></h2>
    <p class="muted"><?= e(i18n_text('Usa un client_token del cliente local. Developer solo puede probar sus juegos; admin y superroot pueden probar todos.', 'Use a client_token from the local client. Developers can only test their own games; admin and superroot can test all games.')) ?></p>

    <?php if ($testError !== null): ?>
        <div class="alert alert--error"><?= e($testError) ?></div>
    <?php endif; ?>

    <form class="form" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="test_developer_api">
        <div class="form-grid">
            <div class="field">
                <label for="client_token">client_token</label>
                <input id="client_token" name="client_token" placeholder="jvg_ct_..." required>
            </div>
            <div class="field">
                <label for="slug">slug</label>
                <input id="slug" name="slug" placeholder="jumpfall">
            </div>
            <div class="field">
                <label for="game_id">game_id</label>
                <input id="game_id" name="game_id" type="number" min="1">
            </div>
            <div class="field">
                <label for="public_key">public_key</label>
                <input id="public_key" name="public_key" placeholder="jvg_pk_...">
            </div>
            <div class="field">
                <label for="test">test</label>
                <select id="test" name="test">
                    <option value="game_info">game_info</option>
                    <option value="version_check">version_check</option>
                    <option value="database_status">database_status</option>
                    <option value="oauth_device_code">oauth_device_code</option>
                </select>
            </div>
            <div class="field">
                <label for="version">version</label>
                <input id="version" name="version" value="0.0.0">
            </div>
        </div>
        <div class="actions">
            <button type="submit"><?= e(i18n_text('Ejecutar prueba', 'Run test')) ?></button>
        </div>
    </form>

    <?php if ($testResult !== null): ?>
        <h3><?= e(i18n_text('Resultado', 'Result')) ?></h3>
        <pre class="code-view"><?= e(json_encode($testResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></pre>
    <?php endif; ?>
</section>
<?php
Page::footer();
