<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Notification;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

$openId = (string) ($_GET['open'] ?? '');
if ($openId !== '') {
    $notification = Notification::findForUser($userId, $openId);
    if ($notification) {
        Notification::markRead($userId, $openId);
        $targetUrl = (string) ($notification['target_url'] ?? '');
        if ($targetUrl !== '' && str_starts_with($targetUrl, '/') && !str_starts_with($targetUrl, '//')) {
            redirect_to($targetUrl);
        }
    }
    redirect_to('/notifications/');
}

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/notifications/');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        Notification::markAllRead($userId);
        flash('message', 'Notificaciones marcadas como leidas.');
        redirect_to('/notifications/');
    }

    if ($action === 'mark_read') {
        Notification::markRead($userId, (string) ($_POST['notification_id'] ?? ''));
        flash('message', 'Notificacion marcada como leida.');
        redirect_to('/notifications/');
    }
}

$notifications = Notification::listForUser($userId);
$unreadCount = Notification::unreadCount($userId);

Page::header('Notificaciones');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Notificaciones</h1>
            <p class="muted"><?= e($unreadCount) ?> pendientes.</p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="button button--secondary">Marcar todo leido</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <?php if ($notifications === []): ?>
        <p class="muted">No hay notificaciones.</p>
    <?php else: ?>
        <div class="compact-list">
            <?php foreach ($notifications as $notification): ?>
                <?php $isUnread = empty($notification['read_at']); ?>
                <article class="<?= $isUnread ? 'notification-row notification-row--unread' : 'notification-row' ?>">
                    <div>
                        <h3><?= e($notification['title']) ?></h3>
                        <?php if (!empty($notification['body'])): ?>
                            <p><?= e($notification['body']) ?></p>
                        <?php endif; ?>
                        <p class="muted">
                            <?= e($notification['created_at']) ?>
                            <?php if (!empty($notification['actor_username'])): ?>
                                - por @<?= e($notification['actor_username']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="table-actions">
                        <?php if (!empty($notification['target_url'])): ?>
                            <a class="button button--secondary" href="<?= e(url('/notifications/?open=' . rawurlencode((string) $notification['id']))) ?>">Abrir</a>
                        <?php endif; ?>
                        <?php if ($isUnread): ?>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= e($notification['id']) ?>">
                                <button type="submit">Leida</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
Page::footer();
