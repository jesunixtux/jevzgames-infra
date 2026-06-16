<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Inventory;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$redeemed = null;

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/redeem/');
    }

    try {
        $redeemed = Inventory::redeemCode($userId, (string) ($_POST['code'] ?? ''));
        ActivityLogger::info('code_redeemed', ['user_id' => $userId, 'code_preview' => $redeemed['code_preview'] ?? '']);
        flash('message', 'Codigo canjeado. Recompensa agregada a tu inventario.');
        redirect_to('/inventory/');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/redeem/');
    }
}

Page::header('Canjear codigo');
?>
<section class="panel panel--narrow">
    <h1>Canjear codigo</h1>
    <p class="muted">Ingresa un codigo activo. Puede entregar items, licencias de juegos o recompensas configuradas.</p>
    <form class="form" method="post">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="code">Codigo</label>
            <input id="code" name="code" placeholder="JVG-XXXX-XXXX" maxlength="80" required>
        </div>
        <div class="actions">
            <button type="submit">Canjear</button>
            <a class="button button--secondary" href="<?= e(url('/inventory/')) ?>">Ver inventario</a>
        </div>
    </form>
</section>
<?php
Page::footer();
