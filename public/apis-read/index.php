<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Security\Auth;

require_installed();
Auth::requireRole(['developer', 'developer-extern', 'admin', 'superroot']);

Page::header('APIs Read');
?>
<section class="panel">
    <h1>APIs Read</h1>
    <p class="muted"><?= e(i18n_text('Guia larga para integrar juegos, launcher, cloud saves, logros, DRM, codigos y presencia.', 'Long-form guide for integrating games, launcher, cloud saves, achievements, DRM, codes and presence.')) ?></p>
</section>

<section class="panel">
    <h2>1. Launcher flow</h2>
    <pre class="code-view">POST /api/client/login/
POST /api/client/library/
POST /api/client/presence/
POST /api/client/redeem/
POST /api/client/cloud/configs/
POST /api/client/cloud/push/
POST /api/client/cloud/pull/
POST /api/client/groups/
POST /api/client/family/
POST /api/client/launcher/update-check/</pre>
    <p><?= e(i18n_text('El launcher guarda token, cache de biblioteca y metadatos de instalacion. No guarda contrasenas.', 'The launcher stores token, library cache and install metadata. It never stores passwords.')) ?></p>
</section>

<section class="panel">
    <h2>2. Game launch context</h2>
    <p><?= e(i18n_text('Cuando el launcher abre un juego, pasa contexto para que el juego use las APIs del cliente.', 'When the launcher opens a game, it passes context so the game can use client APIs.')) ?></p>
    <pre class="code-view">--jevzgames-api=http://jevzgames.local
--jevzgames-token=jvg_ct_...
--jevzgames-game=jumpfall</pre>
</section>

<section class="panel">
    <h2>3. Achievements</h2>
    <pre class="code-view">POST /api/client/achievements/list/
{"game_slug":"jumpfall"}

POST /api/client/achievements/unlock/
{"game_slug":"jumpfall","achievement_code":"first_run"}</pre>
    <p><?= e(i18n_text('La respuesta del unlock trae un objeto toast para mostrar notificacion inferior tipo Steam.', 'The unlock response includes a toast object for a Steam-like bottom notification.')) ?></p>
</section>

<section class="panel">
    <h2>4. Cloud sync</h2>
    <p><?= e(i18n_text('Modo viejo: api_slot guarda JSON por slot. Modo nuevo: file_path indica al launcher donde leer/escribir savegames y sube el contenido como base64.', 'Old mode: api_slot stores JSON by slot. New mode: file_path tells the launcher where to read/write savegames and uploads content as base64.')) ?></p>
    <pre class="code-view">POST /api/client/cloud/configs/
{"game_slug":"jumpfall"}

POST /api/client/cloud/push/
{"game_slug":"jumpfall","config_key":"default","slot":1,"content_base64":"...","local_path":"%USERPROFILE%/Saved Games/JumpFall/save.dat"}

POST /api/client/cloud/pull/
{"game_slug":"jumpfall","config_key":"default","slot":1}</pre>
</section>

<section class="panel">
    <h2>5. Codes</h2>
    <p><?= e(i18n_text('Los codigos de objetos siguen usando Admin > Codigos. Los codigos de juegos se generan o solicitan desde /games-code/. Ambos se canjean desde el mismo endpoint.', 'Item codes still use Admin > Codes. Game codes are generated or requested from /games-code/. Both redeem through the same endpoint.')) ?></p>
    <pre class="code-view">POST /api/client/redeem/
{"code":"JVG-GAME-...."}</pre>
    <ul>
        <li><?= e(i18n_text('Internos: el owner/admin genera hasta 100 codigos por batch.', 'Internal games: owner/admin generates up to 100 codes per batch.')) ?></li>
        <li><?= e(i18n_text('Externos: el owner solicita copias y Admin/Superroot aprueba, rechaza o revoca.', 'External games: owner requests copies and Admin/Superroot approves, rejects or revokes.')) ?></li>
        <li><?= e(i18n_text('Rechazar o revocar requiere motivo y dispara notificacion al solicitante.', 'Rejecting or revoking requires a reason and sends a notification to the requester.')) ?></li>
    </ul>
</section>

<section class="panel">
    <h2>6. OAuth game API</h2>
    <p><?= e(i18n_text('Para juegos que no vienen del launcher, usa public_key, device-code y access_token persistente hasta desvincular.', 'For games not launched by the launcher, use public_key, device-code and persistent access_token until unlink.')) ?></p>
    <pre class="code-view">POST /api/oauth/device-code/
POST /api/oauth/token/
POST /api/user-profile/
POST /api/player-data/save/
POST /api/achievements/unlock/</pre>
</section>

<section class="panel">
    <h2>7. Family Sharing, groups and playtime</h2>
    <p><?= e(i18n_text('Family Sharing agrega juegos compartidos a owned_games con shared_from_family=true. La presencia in_game inicia el contador de horas y online/offline lo cierra.', 'Family Sharing adds shared games to owned_games with shared_from_family=true. in_game presence starts the playtime counter and online/offline closes it.')) ?></p>
    <pre class="code-view">POST /api/client/groups/
POST /api/client/family/
POST /api/client/presence/
{"status":"in_game","game_slug":"jumpfall"}</pre>
</section>

<section class="panel">
    <h2>8. Launcher updates</h2>
    <p><?= e(i18n_text('Superroot publica releases desde /client/. El launcher descarga el ZIP, verifica checksum_sha256 si existe y reemplaza archivos al cerrar.', 'Superroot publishes releases from /client/. The launcher downloads the ZIP, verifies checksum_sha256 when present and replaces files after closing.')) ?></p>
    <pre class="code-view">POST /api/client/launcher/update-check/
{"current_version":"0.1.12-beta","os":"windows"}</pre>
</section>
<?php
Page::footer();
