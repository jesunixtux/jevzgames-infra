<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Inventory;
use App\Security\Auth;

require_installed();
Auth::requireLogin();

function inventory_item_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    return filter_var($path, FILTER_VALIDATE_URL) ? $path : url('/' . ltrim($path, '/'));
}

$user = Auth::user();
$items = Inventory::listForUser((int) ($user['id'] ?? 0));

Page::header('Inventario');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Inventario</h1>
            <p class="muted">Items, monedas, skins o recompensas entregadas por codigos y APIs.</p>
        </div>
        <a class="button" href="<?= e(url('/redeem/')) ?>">Canjear codigo</a>
    </div>
</section>

<section class="panel">
    <?php if ($items === []): ?>
        <div class="empty-state">
            <h2>Sin items</h2>
            <p class="muted">Cuando canjees codigos o un juego entregue recompensas, apareceran aqui.</p>
        </div>
    <?php else: ?>
        <div class="game-grid">
            <?php foreach ($items as $item): ?>
                <?php $imageUrl = inventory_item_image_url($item['image_path'] ?? null); ?>
                <article class="game-card">
                    <div class="game-card__header">
                        <h3><?= e($item['name']) ?></h3>
                        <span class="status-pill status-pill--published">x<?= e($item['quantity']) ?></span>
                    </div>
                    <?php if ($imageUrl !== ''): ?>
                        <img class="inventory-thumb" src="<?= e($imageUrl) ?>" alt="">
                    <?php endif; ?>
                    <p class="muted">
                        <?= e($item['game']['name'] ?? 'Global') ?>
                        · <code><?= e($item['item_key']) ?></code>
                    </p>
                    <dl class="meta">
                        <div><dt>Tipo</dt><dd><?= e($item['item_type']) ?></dd></div>
                        <div><dt>Origen</dt><dd><?= e($item['source'] ?? 'desconocido') ?></dd></div>
                        <div><dt>Actualizado</dt><dd><?= e($item['updated_at'] ?? $item['acquired_at'] ?? '') ?></dd></div>
                    </dl>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
Page::footer();
