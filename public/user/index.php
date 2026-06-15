<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Achievement;
use App\Models\Friend;
use App\Models\Game;
use App\Models\PublicProfile;
use App\Models\SocialSettings;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();

$username = (string) ($_GET['username'] ?? '');
if ($username === '') {
    $path = current_path();
    if (preg_match('#/user/@([^/]+)#', $path, $matches)) {
        $username = rawurldecode($matches[1]);
    }
}

$profile = PublicProfile::findByUsername($username);
$viewer = Auth::user();
$viewerId = (int) ($viewer['id'] ?? 0);
$targetId = (int) ($profile['id'] ?? 0);
$isSelf = $viewerId > 0 && $viewerId === $targetId;

if (request_is_post()) {
    if (!$profile) {
        flash('error', 'Perfil no encontrado.');
        redirect_to('/games/');
    }

    if ($viewerId <= 0) {
        $_SESSION['after_login_redirect'] = '/user/@' . rawurlencode((string) $profile['username']);
        redirect_to('/login/');
    }

    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/user/@' . rawurlencode((string) $profile['username']));
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'send_friend_request') {
            Friend::request($viewerId, $targetId);
            flash('message', 'Solicitud de amistad enviada.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'accept_friend') {
            Friend::accept($viewerId, (int) ($_POST['friend_request_id'] ?? 0));
            flash('message', 'Solicitud aceptada.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'reject_friend') {
            Friend::reject($viewerId, (int) ($_POST['friend_request_id'] ?? 0));
            flash('message', 'Solicitud rechazada.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'remove_friend') {
            Friend::remove($viewerId, $targetId);
            flash('message', 'Relacion actualizada.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'block_user') {
            SocialSettings::setControl($viewerId, $targetId, 'blocked');
            flash('message', 'Usuario bloqueado.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'unblock_user') {
            SocialSettings::removeControl($viewerId, $targetId, 'blocked');
            flash('message', 'Usuario desbloqueado.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'mute_user') {
            SocialSettings::setControl($viewerId, $targetId, 'muted');
            flash('message', 'Usuario silenciado.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        if ($action === 'unmute_user') {
            SocialSettings::removeControl($viewerId, $targetId, 'muted');
            flash('message', 'Silencio quitado.');
            redirect_to('/user/@' . rawurlencode((string) $profile['username']));
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/user/@' . rawurlencode((string) $profile['username']));
    }
}

if (!$profile || ($profile['status'] ?? '') !== 'active') {
    http_response_code(404);
    Page::header('Usuario no encontrado');
    ?>
    <section class="panel panel--narrow">
        <h1>Usuario no encontrado</h1>
        <p class="muted">El perfil solicitado no existe o no esta disponible.</p>
    </section>
    <?php
    Page::footer();
    exit;
}

$avatarUrl = PublicProfile::avatarUrl($profile['avatar_path'] ?? '');
$relationship = $viewerId > 0 && !$isSelf ? Friend::relationship($viewerId, $targetId) : null;
$isFriend = $viewerId > 0 && SocialSettings::areFriends($viewerId, $targetId);
$isProfilePublic = ($profile['visibility'] ?? 'public') === 'public';
$blockedByViewer = $viewerId > 0 && !$isSelf && SocialSettings::isBlocked($viewerId, $targetId);
$viewerBlockedByTarget = $viewerId > 0 && !$isSelf && SocialSettings::isBlocked($targetId, $viewerId);
$isMutedByViewer = $viewerId > 0 && !$isSelf && SocialSettings::isMuted($viewerId, $targetId);
$canRequestFriend = $viewerId > 0 && !$isSelf && !$relationship && SocialSettings::canReceiveFriendRequest($targetId, $viewerId);
$canMessage = $viewerId > 0 && !$isSelf && SocialSettings::canReceiveMessage($targetId, $viewerId);
$canSeeBio = $isProfilePublic || $isSelf || SocialSettings::canSeePrivateSection($targetId, $viewerId, 'bio');
$canSeeGames = $isProfilePublic || $isSelf || SocialSettings::canSeePrivateSection($targetId, $viewerId, 'games');
$canSeeAchievements = $isProfilePublic || $isSelf || SocialSettings::canSeePrivateSection($targetId, $viewerId, 'achievements');
$canSeeFriends = $isProfilePublic || $isSelf || SocialSettings::canSeePrivateSection($targetId, $viewerId, 'friends');
$linkedGames = $canSeeGames ? array_values(array_filter(Game::userLinks($targetId), static function (array $game): bool {
    return in_array((string) $game['status'], Game::visibleStatuses(), true);
})) : [];
$unlockedAchievements = $canSeeAchievements ? Achievement::unlockedForUser($targetId) : [];
$friends = $canSeeFriends ? Friend::friendsForUser($targetId) : [];

Page::header('@' . (string) $profile['username']);
?>
<section class="panel">
    <div class="profile-hero">
        <div class="profile-hero__avatar">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= e($avatarUrl) ?>" alt="Foto de perfil">
            <?php else: ?>
                <span><?= e(strtoupper(substr((string) $profile['username'], 0, 1))) ?></span>
            <?php endif; ?>
        </div>
        <div>
            <h1><?= e($profile['display_name']) ?></h1>
            <p class="muted">@<?= e($profile['username']) ?> · Miembro desde <?= e(substr((string) $profile['created_at'], 0, 10)) ?></p>
            <?php if ($canSeeBio && trim((string) $profile['bio']) !== ''): ?>
                <p><?= e($profile['bio']) ?></p>
            <?php elseif (!$isProfilePublic && !$canSeeBio): ?>
                <p class="muted">Perfil privado.</p>
            <?php endif; ?>

            <?php if ($isSelf): ?>
                <div class="actions">
                    <a class="button button--secondary" href="<?= e(url('/profile/')) ?>">Editar perfil</a>
                </div>
            <?php elseif ($viewerId > 0): ?>
                <div class="actions">
                    <?php if ($canMessage): ?>
                        <a class="button button--secondary" href="<?= e(url('/messages/?to=' . rawurlencode((string) $profile['username']))) ?>">Mensaje</a>
                    <?php endif; ?>
                    <?php if (!$relationship && $canRequestFriend): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="send_friend_request">
                            <button type="submit">Agregar amigo</button>
                        </form>
                    <?php elseif (!$relationship && !$viewerBlockedByTarget && !$blockedByViewer): ?>
                        <span class="status-pill">Solicitudes cerradas</span>
                    <?php elseif ($relationship && $relationship['status'] === 'accepted'): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="remove_friend">
                            <button type="submit" class="button button--secondary">Quitar amigo</button>
                        </form>
                    <?php elseif ($relationship && $relationship['status'] === 'pending' && (int) $relationship['addressee_user_id'] === $viewerId): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="accept_friend">
                            <input type="hidden" name="friend_request_id" value="<?= e($relationship['id']) ?>">
                            <button type="submit">Aceptar solicitud</button>
                        </form>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="reject_friend">
                            <input type="hidden" name="friend_request_id" value="<?= e($relationship['id']) ?>">
                            <button type="submit" class="button button--secondary">Rechazar</button>
                        </form>
                    <?php elseif ($relationship && $relationship['status'] === 'pending'): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="remove_friend">
                            <button type="submit" class="button button--secondary">Cancelar solicitud</button>
                        </form>
                    <?php elseif (!$relationship && !$blockedByViewer && !$viewerBlockedByTarget): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="send_friend_request">
                            <button type="submit">Enviar solicitud</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($blockedByViewer): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="unblock_user">
                            <button type="submit" class="button button--secondary">Desbloquear</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="block_user">
                            <button type="submit" class="button button--secondary">Bloquear</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isMutedByViewer): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="unmute_user">
                            <button type="submit" class="button button--secondary">Quitar silencio</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="mute_user">
                            <button type="submit" class="button button--secondary">Silenciar</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="actions">
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="send_friend_request">
                        <button type="submit" class="button button--secondary">Login para agregar</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($canSeeAchievements || $canSeeFriends || $canSeeGames): ?>
    <section class="grid">
        <?php if ($canSeeAchievements): ?>
            <article class="tile metric-tile">
                <span class="metric"><?= e(count($unlockedAchievements)) ?></span>
                <h2>Logros</h2>
                <p class="muted">Desbloqueados.</p>
            </article>
        <?php endif; ?>
        <?php if ($canSeeFriends): ?>
            <article class="tile metric-tile">
                <span class="metric"><?= e(count($friends)) ?></span>
                <h2>Amigos</h2>
                <p class="muted">Conexiones aceptadas.</p>
            </article>
        <?php endif; ?>
        <?php if ($canSeeGames): ?>
            <article class="tile metric-tile">
                <span class="metric"><?= e(count($linkedGames)) ?></span>
                <h2>Juegos</h2>
                <p class="muted">Vinculados.</p>
            </article>
        <?php endif; ?>
    </section>

    <?php if ($canSeeAchievements): ?>
        <section class="panel">
            <h2>Logros desbloqueados</h2>
            <?php if ($unlockedAchievements === []): ?>
                <p class="muted">No hay logros desbloqueados visibles.</p>
            <?php else: ?>
                <div class="achievement-list">
                    <?php foreach ($unlockedAchievements as $achievement): ?>
                        <article class="achievement-item">
                            <?php if (!empty($achievement['image_path'])): ?>
                                <img class="achievement-thumb" src="<?= e($achievement['image_path']) ?>" alt="">
                            <?php endif; ?>
                            <div>
                                <h3><?= e($achievement['title']) ?></h3>
                                <p class="muted"><?= e($achievement['game']['name'] ?? '') ?> · <code><?= e($achievement['code']) ?></code></p>
                            </div>
                            <div class="achievement-item__meta">
                                <strong><?= e($achievement['points']) ?> pts</strong>
                                <span class="muted"><?= e($achievement['unlocked_at'] ?? '') ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canSeeGames || $canSeeFriends): ?>
        <section class="grid profile-grid">
            <?php if ($canSeeGames): ?>
                <article class="panel">
                    <h2>Juegos</h2>
                    <?php if ($linkedGames === []): ?>
                        <p class="muted">No hay juegos vinculados visibles.</p>
                    <?php else: ?>
                        <div class="compact-list">
                            <?php foreach ($linkedGames as $game): ?>
                                <div class="compact-row">
                                    <div>
                                        <strong><?= e($game['name']) ?></strong><br>
                                        <span class="muted"><code><?= e($game['slug']) ?></code> · <?= e(Game::statusLabel((string) $game['status'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($canSeeFriends): ?>
                <article class="panel">
                    <h2>Amigos</h2>
                    <?php if ($friends === []): ?>
                        <p class="muted">No hay amigos visibles.</p>
                    <?php else: ?>
                        <div class="compact-list">
                            <?php foreach ($friends as $friend): ?>
                                <a class="compact-row compact-row--link" href="<?= e(url('/user/@' . rawurlencode((string) $friend['username']))) ?>">
                                    <div>
                                        <strong>@<?= e($friend['username']) ?></strong><br>
                                        <span class="muted"><?= e($friend['display_name']) ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php elseif (!$isProfilePublic): ?>
    <section class="panel">
        <p class="muted">Este perfil es privado.</p>
    </section>
<?php endif; ?>
<?php
Page::footer();
