<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PlatformSettings;
use App\Models\PublishRequest;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();

$enabled = PlatformSettings::enabled('publish_on_games');
$user = Auth::user();
$userId = $user ? (int) $user['id'] : 0;

if (request_is_post()) {
    Auth::requireLogin();
    if (!$enabled) {
        flash('error', 'La publicacion abierta de juegos esta deshabilitada.');
        redirect_to('/publish-on-games/');
    }

    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/publish-on-games/');
    }

    try {
        $requestId = PublishRequest::submit($userId, $_POST);
        ActivityLogger::info('publish_request_submitted', ['user_id' => $userId, 'request_id' => $requestId]);
        flash('message', 'Solicitud enviada. Un admin revisara el juego antes de publicarlo.');
        redirect_to('/publish-on-games/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/publish-on-games/');
    }
}

$myRequests = $userId > 0 ? PublishRequest::mine($userId) : [];

Page::header('Publicar juego');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Publish on Games</h1>
            <p class="muted">Envio de juegos de la comunidad para revision.</p>
        </div>
        <span class="status-pill <?= $enabled ? 'status-pill--published' : 'status-pill--archived' ?>">
            <?= $enabled ? 'Activo' : 'Deshabilitado' ?>
        </span>
    </div>
</section>

<?php if (!$enabled): ?>
    <section class="panel">
        <h2>Cerrado por ahora</h2>
        <p class="muted">Superroot debe activar esta funcion en el panel de funciones antes de recibir juegos externos.</p>
    </section>
<?php elseif (!$user): ?>
    <section class="panel panel--narrow">
        <h2>Inicia sesion</h2>
        <p class="muted">Necesitas una cuenta para enviar un juego.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('/login/')) ?>">Login</a>
            <a class="button button--secondary" href="<?= e(url('/register/')) ?>">Registro</a>
        </div>
    </section>
<?php else: ?>
    <section class="panel">
        <h2>Enviar juego</h2>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="name">Nombre del juego</label>
                    <input id="name" name="name" maxlength="140" required>
                </div>
                <div class="field">
                    <label for="slug">Slug publico</label>
                    <input id="slug" name="slug" maxlength="160" pattern="[a-z0-9-]{2,160}" placeholder="mi-juego" required>
                </div>
                <div class="field">
                    <label for="contact_email">Email de contacto</label>
                    <input id="contact_email" name="contact_email" type="email" value="<?= e($user['email'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="website_url">Sitio del juego</label>
                    <input id="website_url" name="website_url" placeholder="https://...">
                </div>
                <div class="field">
                    <label for="build_url">Build o demo</label>
                    <input id="build_url" name="build_url" placeholder="https://...">
                </div>
            </div>
            <div class="field">
                <label for="description">Descripcion</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"></textarea>
            </div>
            <div class="actions">
                <button type="submit">Enviar para revision</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Mis solicitudes</h2>
        <?php if ($myRequests === []): ?>
            <p class="muted">Todavia no has enviado juegos.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Juego</th>
                            <th>Estado</th>
                            <th>Enviado</th>
                            <th>Revision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myRequests as $request): ?>
                            <tr>
                                <td><?= e($request['name']) ?><br><code><?= e($request['slug']) ?></code></td>
                                <td><?= e($request['status']) ?></td>
                                <td><?= e($request['created_at']) ?></td>
                                <td><?= e($request['review_note'] ?? $request['approved_game_slug'] ?? '') ?></td>
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
