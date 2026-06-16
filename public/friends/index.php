<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Friend;
use App\Models\Presence;
use App\Models\PublicProfile;
use App\Models\SocialSettings;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/friends/');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'accept_friend') {
            Friend::accept($userId, (int) ($_POST['friend_request_id'] ?? 0));
            flash('message', 'Solicitud aceptada.');
            redirect_to('/friends/');
        }

        if ($action === 'reject_friend') {
            Friend::reject($userId, (int) ($_POST['friend_request_id'] ?? 0));
            flash('message', 'Solicitud rechazada.');
            redirect_to('/friends/');
        }

        if ($action === 'remove_friend') {
            Friend::remove($userId, (int) ($_POST['friend_user_id'] ?? 0));
            flash('message', 'Amigo eliminado.');
            redirect_to('/friends/');
        }

        if ($action === 'mute_user') {
            SocialSettings::setControl($userId, (int) ($_POST['target_user_id'] ?? 0), 'muted');
            flash('message', 'Usuario silenciado.');
            redirect_to('/friends/');
        }

        if ($action === 'block_user') {
            SocialSettings::setControl($userId, (int) ($_POST['target_user_id'] ?? 0), 'blocked');
            flash('message', 'Usuario bloqueado.');
            redirect_to('/friends/');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/friends/');
    }
}

$friends = Friend::friendsForUser($userId);
$pendingFriends = Friend::pendingForUser($userId);
$selectedFriendId = (int) ($_GET['user'] ?? 0);
$selectedFriend = null;

foreach ($friends as $friend) {
    if ($selectedFriendId > 0 && (int) $friend['friend_id'] === $selectedFriendId) {
        $selectedFriend = $friend;
        break;
    }
}

if (!$selectedFriend && $friends !== []) {
    $selectedFriend = $friends[0];
}

$selectedPresence = $selectedFriend ? Presence::forUser((int) $selectedFriend['friend_id']) : null;
$selectedAvatarUrl = $selectedFriend ? PublicProfile::avatarUrl($selectedFriend['avatar_path'] ?? '') : '';
$onlineCount = 0;
$friendPresences = [];
foreach ($friends as $friend) {
    $presence = Presence::forUser((int) $friend['friend_id']);
    $friendPresences[(int) $friend['friend_id']] = $presence;
    if (!empty($presence['connected'])) {
        $onlineCount++;
    }
}

Page::header(t('friends.title'));
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e(t('friends.title')) ?></h1>
            <p class="muted"><?= e(t('friends.subtitle')) ?></p>
        </div>
        <div class="friends-summary">
            <span><strong><?= e((string) count($friends)) ?></strong> <?= e(t('friends.total')) ?></span>
            <span><strong><?= e((string) $onlineCount) ?></strong> <?= e(t('friends.online')) ?></span>
            <span><strong><?= e((string) count($pendingFriends)) ?></strong> <?= e(t('friends.pending')) ?></span>
        </div>
    </div>
</section>

<section class="friends-layout">
    <aside class="friends-sidebar" aria-label="<?= e(t('friends.list')) ?>">
        <div class="friends-sidebar__header">
            <h2><?= e(t('friends.list')) ?></h2>
            <span class="status-pill"><?= e((string) count($friends)) ?></span>
        </div>

        <div class="friends-list">
            <?php if ($friends === []): ?>
                <p class="friends-empty muted"><?= e(t('friends.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($friends as $friend): ?>
                    <?php
                    $friendId = (int) $friend['friend_id'];
                    $presence = $friendPresences[$friendId] ?? Presence::forUser($friendId);
                    $avatarUrl = PublicProfile::avatarUrl($friend['avatar_path'] ?? '');
                    $isActive = $selectedFriend && (int) $selectedFriend['friend_id'] === $friendId;
                    ?>
                    <a class="<?= $isActive ? 'friend-row friend-row--active' : 'friend-row' ?>" href="<?= e(url('/friends/?user=' . $friendId)) ?>">
                        <span class="friend-avatar">
                            <?php if ($avatarUrl !== ''): ?>
                                <img src="<?= e($avatarUrl) ?>" alt="">
                            <?php else: ?>
                                <?= e(strtoupper(substr((string) ($friend['display_name'] ?? $friend['username']), 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <span class="friend-row__body">
                            <strong><?= e($friend['display_name']) ?></strong>
                            <span>@<?= e($friend['username']) ?></span>
                            <small
                                class="presence-pill presence-pill--<?= e((string) $presence['status']) ?>"
                                data-presence-user-id="<?= e((string) $friendId) ?>"
                                data-presence-poll-url="<?= e(url('/api/presence/user/')) ?>"
                            ><?= e(Presence::label($presence)) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="friends-sidebar__footer">
            <a class="button button--secondary" href="<?= e(url('/community/')) ?>"><?= e(t('friends.find')) ?></a>
        </div>
    </aside>

    <section class="friends-detail">
        <?php if ($selectedFriend): ?>
            <?php
            $selectedId = (int) $selectedFriend['friend_id'];
            $selectedUsername = (string) $selectedFriend['username'];
            ?>
            <div class="friend-detail-hero">
                <div class="friend-avatar friend-avatar--large">
                    <?php if ($selectedAvatarUrl !== ''): ?>
                        <img src="<?= e($selectedAvatarUrl) ?>" alt="">
                    <?php else: ?>
                        <?= e(strtoupper(substr((string) $selectedFriend['display_name'], 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h2><?= e($selectedFriend['display_name']) ?></h2>
                    <p class="muted">@<?= e($selectedUsername) ?></p>
                    <?php if ($selectedPresence): ?>
                        <p>
                            <span
                                class="presence-pill presence-pill--<?= e((string) $selectedPresence['status']) ?>"
                                data-presence-user-id="<?= e((string) $selectedId) ?>"
                                data-presence-poll-url="<?= e(url('/api/presence/user/')) ?>"
                            ><?= e(Presence::label($selectedPresence)) ?></span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="friend-action-grid">
                <a class="friend-action" href="<?= e(url('/messages/?to=' . rawurlencode($selectedUsername))) ?>">
                    <strong><?= e(t('friends.send_message')) ?></strong>
                    <span><?= e(t('friends.send_message_help')) ?></span>
                </a>
                <a class="friend-action" href="<?= e(url('/user/@' . rawurlencode($selectedUsername))) ?>">
                    <strong><?= e(t('friends.view_profile')) ?></strong>
                    <span><?= e(t('friends.view_profile_help')) ?></span>
                </a>
            </div>

            <div class="friend-management">
                <h3><?= e(t('friends.manage')) ?></h3>
                <div class="table-actions">
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="remove_friend">
                        <input type="hidden" name="friend_user_id" value="<?= e((string) $selectedId) ?>">
                        <button type="submit" class="button button--secondary"><?= e(t('friends.remove')) ?></button>
                    </form>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="mute_user">
                        <input type="hidden" name="target_user_id" value="<?= e((string) $selectedId) ?>">
                        <button type="submit" class="button button--secondary"><?= e(t('friends.mute')) ?></button>
                    </form>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="block_user">
                        <input type="hidden" name="target_user_id" value="<?= e((string) $selectedId) ?>">
                        <button type="submit" class="button button--danger"><?= e(t('friends.block')) ?></button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h2><?= e(t('friends.no_selection')) ?></h2>
                <p class="muted"><?= e(t('friends.no_selection_help')) ?></p>
                <a class="button button--secondary" href="<?= e(url('/community/')) ?>"><?= e(t('friends.find')) ?></a>
            </div>
        <?php endif; ?>
    </section>
</section>

<section class="panel">
    <div class="section-heading">
        <div>
            <h2><?= e(t('friends.requests')) ?></h2>
            <p class="muted"><?= e(t('friends.requests_help')) ?></p>
        </div>
    </div>

    <?php if ($pendingFriends === []): ?>
        <p class="muted"><?= e(t('friends.no_requests')) ?></p>
    <?php else: ?>
        <div class="compact-list">
            <?php foreach ($pendingFriends as $request): ?>
                <?php $requestAvatar = PublicProfile::avatarUrl($request['avatar_path'] ?? ''); ?>
                <div class="compact-row friend-request-row">
                    <div class="friend-request-row__profile">
                        <span class="friend-avatar">
                            <?php if ($requestAvatar !== ''): ?>
                                <img src="<?= e($requestAvatar) ?>" alt="">
                            <?php else: ?>
                                <?= e(strtoupper(substr((string) ($request['display_name'] ?? $request['username']), 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <div>
                            <strong><a href="<?= e(url('/user/@' . rawurlencode((string) $request['username']))) ?>">@<?= e($request['username']) ?></a></strong><br>
                            <span class="muted"><?= e($request['display_name']) ?></span>
                        </div>
                    </div>
                    <div class="table-actions">
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="accept_friend">
                            <input type="hidden" name="friend_request_id" value="<?= e((string) $request['id']) ?>">
                            <button type="submit"><?= e(t('friends.accept')) ?></button>
                        </form>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="reject_friend">
                            <input type="hidden" name="friend_request_id" value="<?= e((string) $request['id']) ?>">
                            <button type="submit" class="button button--secondary"><?= e(t('friends.reject')) ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
Page::footer();
