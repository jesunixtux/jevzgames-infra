<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\OAuth;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();

$userCode = strtoupper(trim((string) ($_GET['user_code'] ?? $_POST['user_code'] ?? '')));
$device = $userCode !== '' ? OAuth::findDeviceByUserCode($userCode) : null;

if (request_is_post() && (string) ($_POST['action'] ?? '') === 'lookup') {
    redirect_to('/oauth/authorize/?user_code=' . rawurlencode($userCode));
}

if ($device && !Auth::check()) {
    $_SESSION['after_login_redirect'] = '/oauth/authorize/?user_code=' . rawurlencode($userCode);
        flash('message', t('oauth.requires_access'));
    redirect_to('/login/');
}

if ($device && Auth::check() && $device['status'] === 'pending' && !Auth::hasRole(['admin', 'superroot']) && !request_is_post()) {
    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);
    try {
        OAuth::approveDevice((int) $device['id'], $userId);
        ActivityLogger::info('oauth_device_auto_approved', ['user_id' => $userId, 'game_id' => (int) $device['game_id'], 'device_id' => (int) $device['id']]);
        flash('message', t('oauth.approved'));
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect_to('/oauth/authorize/?user_code=' . rawurlencode($userCode));
}

if (request_is_post() && $device && Auth::check()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/oauth/authorize/?user_code=' . rawurlencode($userCode));
    }

    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'approve') {
            OAuth::approveDevice((int) $device['id'], $userId);
            ActivityLogger::info('oauth_device_approved', ['user_id' => $userId, 'game_id' => (int) $device['game_id'], 'device_id' => (int) $device['id']]);
            flash('message', t('oauth.approved'));
        } elseif ($action === 'deny') {
            OAuth::denyDevice((int) $device['id'], $userId);
            ActivityLogger::info('oauth_device_denied', ['user_id' => $userId, 'game_id' => (int) $device['game_id'], 'device_id' => (int) $device['id']]);
            flash('message', t('oauth.denied'));
        } else {
            throw new RuntimeException('Accion no valida.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }

    redirect_to('/oauth/authorize/?user_code=' . rawurlencode($userCode));
}

Page::header(t('oauth.authorize_title'));
?>
<section class="panel panel--narrow">
    <h1><?= e(t('oauth.authorize_title')) ?></h1>

    <?php if ($userCode === ''): ?>
        <p class="muted"><?= e(t('oauth.enter_code')) ?></p>
        <form class="form" method="post">
            <input type="hidden" name="action" value="lookup">
            <div class="field">
                <label for="user_code"><?= e(t('oauth.code')) ?></label>
                <input id="user_code" name="user_code" placeholder="ABCD-1234" required>
            </div>
            <div class="actions">
                <button type="submit"><?= e(t('oauth.continue')) ?></button>
            </div>
        </form>
    <?php elseif (!$device): ?>
        <div class="alert alert--error"><?= e(t('oauth.no_request')) ?></div>
        <form class="form" method="post">
            <input type="hidden" name="action" value="lookup">
            <div class="field">
                <label for="user_code_retry"><?= e(t('oauth.code')) ?></label>
                <input id="user_code_retry" name="user_code" value="<?= e($userCode) ?>" required>
            </div>
            <div class="actions">
                <button type="submit"><?= e(t('oauth.try_again')) ?></button>
            </div>
        </form>
    <?php else: ?>
        <dl class="meta">
            <div><dt><?= e(t('oauth.game')) ?></dt><dd><?= e($device['game_name']) ?></dd></div>
            <div><dt><?= e(t('oauth.code')) ?></dt><dd><code><?= e($device['user_code_preview']) ?></code></dd></div>
            <div><dt><?= e(t('oauth.status')) ?></dt><dd><?= e($device['status']) ?></dd></div>
            <div><dt><?= e(t('oauth.expires')) ?></dt><dd><?= e($device['expires_at']) ?></dd></div>
        </dl>

        <?php if ($device['status'] === 'pending'): ?>
            <p class="muted"><?= e(t('oauth.requires_access')) ?></p>
            <div class="actions">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_code" value="<?= e($userCode) ?>">
                    <button type="submit"><?= e(t('oauth.approve')) ?></button>
                </form>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="deny">
                    <input type="hidden" name="user_code" value="<?= e($userCode) ?>">
                    <button type="submit" class="button button--secondary"><?= e(t('oauth.deny')) ?></button>
                </form>
            </div>
        <?php elseif ($device['status'] === 'authorized'): ?>
            <div class="alert alert--success"><?= e(t('oauth.approved')) ?></div>
        <?php elseif ($device['status'] === 'denied'): ?>
            <div class="alert alert--error"><?= e(t('oauth.denied')) ?></div>
        <?php else: ?>
            <div class="alert alert--error"><?= e(t('oauth.expired')) ?></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
Page::footer();
