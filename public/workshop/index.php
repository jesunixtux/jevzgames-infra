<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\PlatformSettings;
use App\Models\Workshop;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();

$enabled = PlatformSettings::enabled('workshop');
$user = Auth::user();
$userId = $user ? (int) $user['id'] : 0;

if (request_is_post()) {
    Auth::requireLogin();
    if (!$enabled) {
        flash('error', 'Workshop esta deshabilitado.');
        redirect_to('/workshop/');
    }
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/workshop/');
    }

    try {
        $itemId = Workshop::submitItem($userId, $_POST);
        ActivityLogger::info('workshop_item_submitted', ['user_id' => $userId, 'item_id' => $itemId]);
        flash('message', 'Item enviado al workshop.');
        redirect_to('/workshop/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/workshop/');
    }
}

$games = $enabled ? Workshop::enabledGames() : [];
$items = $enabled ? Workshop::items(null, false) : [];

Page::header('Workshop');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Workshop</h1>
            <p class="muted">Mods, mapas, skins y contenido publicado por usuarios.</p>
        </div>
        <span class="status-pill <?= $enabled ? 'status-pill--published' : 'status-pill--archived' ?>">
            <?= $enabled ? 'Activo' : 'Deshabilitado' ?>
        </span>
    </div>
</section>

<?php if (!$enabled): ?>
    <section class="panel">
        <h2>Workshop cerrado</h2>
        <p class="muted">Superroot debe activar Workshop y Admin debe habilitarlo por juego.</p>
    </section>
<?php else: ?>
    <?php if ($user && $games !== []): ?>
        <section class="panel">
            <h2>Publicar item</h2>
            <form class="form" method="post">
                <?= Csrf::field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="game_id">Juego</label>
                        <select id="game_id" name="game_id" required>
                            <?php foreach ($games as $game): ?>
                                <?php if ((int) $game['allow_user_uploads'] === 1): ?>
                                    <option value="<?= e($game['game_id']) ?>"><?= e($game['game_name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="title">Titulo</label>
                        <input id="title" name="title" maxlength="160" required>
                    </div>
                    <div class="field">
                        <label for="slug">Slug</label>
                        <input id="slug" name="slug" pattern="[a-z0-9-]{2,180}" maxlength="180" required>
                    </div>
                    <div class="field">
                        <label for="file_url">URL archivo</label>
                        <input id="file_url" name="file_url" placeholder="https://...">
                    </div>
                    <div class="field">
                        <label for="image_url">URL imagen</label>
                        <input id="image_url" name="image_url" placeholder="https://...">
                    </div>
                </div>
                <div class="field">
                    <label for="description">Descripcion</label>
                    <textarea id="description" name="description" rows="4"></textarea>
                </div>
                <div class="field">
                    <label for="metadata_json">Metadata JSON</label>
                    <textarea id="metadata_json" name="metadata_json" rows="3" placeholder='{"version":"1.0"}'></textarea>
                </div>
                <div class="actions">
                    <button type="submit">Enviar item</button>
                </div>
            </form>
        </section>
    <?php elseif (!$user): ?>
        <section class="panel">
            <p class="muted">Inicia sesion para publicar contenido en juegos que acepten workshop.</p>
            <div class="actions"><a class="button" href="<?= e(url('/login/')) ?>">Login</a></div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Items publicados</h2>
        <?php if ($items === []): ?>
            <p class="muted">Todavia no hay items publicados.</p>
        <?php else: ?>
            <div class="game-grid">
                <?php foreach ($items as $item): ?>
                    <article class="game-card">
                        <div class="game-card__header">
                            <h3><?= e($item['title']) ?></h3>
                            <span class="status-pill status-pill--published"><?= e($item['game_name']) ?></span>
                        </div>
                        <p class="muted">@<?= e($item['username']) ?> · <code><?= e($item['slug']) ?></code></p>
                        <?php if (!empty($item['description'])): ?>
                            <p><?= e($item['description']) ?></p>
                        <?php endif; ?>
                        <div class="actions">
                            <?php if (!empty($item['file_url'])): ?>
                                <a class="button button--secondary" href="<?= e($item['file_url']) ?>" target="_blank" rel="noreferrer">Descargar</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
Page::footer();
