<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Achievement;
use App\Models\CloudSave;
use App\Models\Friend;
use App\Models\Game;
use App\Models\Inventory;
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
        redirect_to('/profile/');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_public_profile') {
            PublicProfile::save($userId, $_POST);
            flash('message', 'Perfil publico actualizado.');
            redirect_to('/profile/');
        }

        if ($action === 'upload_avatar') {
            PublicProfile::saveAvatar($userId, $_FILES['avatar'] ?? []);
            flash('message', 'Foto de perfil actualizada.');
            redirect_to('/profile/');
        }

        if ($action === 'save_social_settings') {
            SocialSettings::save($userId, $_POST);
            flash('message', 'Privacidad social actualizada.');
            redirect_to('/profile/');
        }

        if ($action === 'remove_relationship_control') {
            SocialSettings::removeControl($userId, (int) ($_POST['target_user_id'] ?? 0), (string) ($_POST['control'] ?? ''));
            flash('message', 'Control de usuario actualizado.');
            redirect_to('/profile/');
        }

        if ($action === 'accept_friend') {
            Friend::accept($userId, (int) ($_POST['friend_request_id'] ?? 0));
            flash('message', 'Solicitud aceptada.');
            redirect_to('/profile/');
        }

        if ($action === 'reject_friend') {
            Friend::reject($userId, (int) ($_POST['friend_request_id'] ?? 0));
            flash('message', 'Solicitud rechazada.');
            redirect_to('/profile/');
        }

        if ($action === 'remove_friend') {
            Friend::remove($userId, (int) ($_POST['friend_user_id'] ?? 0));
            flash('message', 'Amigo eliminado.');
            redirect_to('/profile/');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/profile/');
    }
}

$profile = PublicProfile::findByUserId($userId);
$avatarUrl = PublicProfile::avatarUrl($profile['avatar_path'] ?? '');
$publicProfileUrl = '/user/@' . rawurlencode((string) ($profile['username'] ?? $user['username'] ?? ''));
$linkedGames = Game::userLinks($userId);
$unlockedAchievements = Achievement::unlockedForUser($userId);
$friends = Friend::friendsForUser($userId);
$pendingFriends = Friend::pendingForUser($userId);
$cloudSaves = CloudSave::listForUser($userId);
$inventoryItems = Inventory::listForUser($userId);
$socialSettings = SocialSettings::settingsForUser($userId);
$relationshipControls = SocialSettings::controlsForUser($userId);

Page::header('Perfil');
?>
<section class="panel">
    <div class="profile-hero">
        <div class="profile-hero__avatar">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= e($avatarUrl) ?>" alt="Foto de perfil">
            <?php else: ?>
                <span><?= e(strtoupper(substr((string) ($profile['username'] ?? 'U'), 0, 1))) ?></span>
            <?php endif; ?>
        </div>
        <div>
            <h1><?= e($profile['display_name'] ?? $user['username'] ?? 'Usuario') ?></h1>
            <p class="muted">@<?= e($profile['username'] ?? $user['username'] ?? '') ?> · <?= e($profile['visibility'] ?? 'public') ?></p>
            <div class="actions">
                <a class="button button--secondary" href="<?= e(url($publicProfileUrl)) ?>">Ver perfil publico</a>
            </div>
        </div>
    </div>
</section>

<section class="grid profile-grid">
    <article class="panel">
        <h2>Perfil publico</h2>
        <p class="muted">Esta informacion se muestra en <code><?= e($publicProfileUrl) ?></code>.</p>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update_public_profile">
            <div class="field">
                <label for="display_name">Nombre publico</label>
                <input id="display_name" name="display_name" value="<?= e($profile['display_name'] ?? '') ?>" maxlength="120">
            </div>
            <div class="field">
                <label for="bio">Bio</label>
                <textarea id="bio" name="bio" rows="5" maxlength="1000"><?= e($profile['bio'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label for="visibility">Visibilidad</label>
                <select id="visibility" name="visibility">
                    <option value="public" <?= (($profile['visibility'] ?? 'public') === 'public') ? 'selected' : '' ?>>Publico</option>
                    <option value="private" <?= (($profile['visibility'] ?? 'public') === 'private') ? 'selected' : '' ?>>Privado</option>
                </select>
            </div>
            <div class="actions">
                <button type="submit">Guardar perfil</button>
            </div>
        </form>
    </article>

    <article class="panel">
        <h2>Foto de perfil</h2>
        <p class="muted">JPG, PNG o WEBP hasta 2 MB.</p>
        <form class="form" method="post" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="upload_avatar">
            <div class="field">
                <label for="avatar">Imagen</label>
                <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <div class="actions">
                <button type="submit">Subir foto</button>
            </div>
        </form>
    </article>
</section>

<section class="grid profile-grid">
    <article class="panel">
        <h2>Privacidad y contacto</h2>
        <form class="form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_social_settings">
            <div class="form-grid">
                <div class="field">
                    <label for="friend_request_policy">Solicitudes de amistad</label>
                    <select id="friend_request_policy" name="friend_request_policy">
                        <option value="anyone" <?= $socialSettings['friend_request_policy'] === 'anyone' ? 'selected' : '' ?>>Cualquiera</option>
                        <option value="mutual_friends" <?= $socialSettings['friend_request_policy'] === 'mutual_friends' ? 'selected' : '' ?>>Solo amigos en comun</option>
                        <option value="none" <?= $socialSettings['friend_request_policy'] === 'none' ? 'selected' : '' ?>>No recibir</option>
                    </select>
                </div>
                <div class="field">
                    <label for="message_policy">Mensajes</label>
                    <select id="message_policy" name="message_policy">
                        <option value="anyone" <?= $socialSettings['message_policy'] === 'anyone' ? 'selected' : '' ?>>Cualquiera</option>
                        <option value="friends" <?= $socialSettings['message_policy'] === 'friends' ? 'selected' : '' ?>>Solo amigos</option>
                        <option value="mutual_friends" <?= $socialSettings['message_policy'] === 'mutual_friends' ? 'selected' : '' ?>>Amigos o amigos en comun</option>
                        <option value="none" <?= $socialSettings['message_policy'] === 'none' ? 'selected' : '' ?>>No recibir</option>
                    </select>
                </div>
            </div>

            <p class="muted">Si tu perfil esta privado, puedes permitir que tus amigos vean estas partes.</p>
            <div class="role-checks">
                <label class="checkbox-field checkbox-field--compact">
                    <input type="checkbox" name="private_show_bio" value="1" <?= !empty($socialSettings['private_show_bio']) ? 'checked' : '' ?>>
                    Bio
                </label>
                <label class="checkbox-field checkbox-field--compact">
                    <input type="checkbox" name="private_show_games" value="1" <?= !empty($socialSettings['private_show_games']) ? 'checked' : '' ?>>
                    Juegos vinculados
                </label>
                <label class="checkbox-field checkbox-field--compact">
                    <input type="checkbox" name="private_show_achievements" value="1" <?= !empty($socialSettings['private_show_achievements']) ? 'checked' : '' ?>>
                    Logros
                </label>
                <label class="checkbox-field checkbox-field--compact">
                    <input type="checkbox" name="private_show_friends" value="1" <?= !empty($socialSettings['private_show_friends']) ? 'checked' : '' ?>>
                    Lista de amigos
                </label>
            </div>
            <div class="actions">
                <button type="submit">Guardar privacidad</button>
            </div>
        </form>
    </article>

    <article class="panel">
        <h2>Bloqueados y silenciados</h2>
        <?php if ($relationshipControls === []): ?>
            <p class="muted">No tienes usuarios bloqueados ni silenciados.</p>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($relationshipControls as $control): ?>
                    <div class="compact-row">
                        <div>
                            <strong>@<?= e($control['username']) ?></strong><br>
                            <span class="muted"><?= e($control['control'] === 'blocked' ? 'Bloqueado' : 'Silenciado') ?></span>
                        </div>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="remove_relationship_control">
                            <input type="hidden" name="target_user_id" value="<?= e($control['target_user_id']) ?>">
                            <input type="hidden" name="control" value="<?= e($control['control']) ?>">
                            <button type="submit" class="button button--secondary">Quitar</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="grid">
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($unlockedAchievements)) ?></span>
        <h2>Logros</h2>
        <p class="muted">Desbloqueados en tus juegos vinculados.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($friends)) ?></span>
        <h2>Amigos</h2>
        <p class="muted"><?= e(count($pendingFriends)) ?> solicitudes pendientes.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($cloudSaves)) ?></span>
        <h2>Cloud saves</h2>
        <p class="muted">Partidas guardadas por API.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($linkedGames)) ?></span>
        <h2>Juegos</h2>
        <p class="muted">Vinculados con OAuth.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($inventoryItems)) ?></span>
        <h2>Inventario</h2>
        <p class="muted"><a href="<?= e(url('/inventory/')) ?>">Ver items y recompensas.</a></p>
    </article>
</section>

<section class="panel">
    <h2>Logros desbloqueados</h2>
    <?php if ($unlockedAchievements === []): ?>
        <p class="muted">Todavia no tienes logros desbloqueados.</p>
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

<section class="grid profile-grid">
    <article class="panel">
        <h2>Amigos</h2>
        <?php if ($friends === []): ?>
            <p class="muted">Aun no tienes amigos agregados.</p>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($friends as $friend): ?>
                    <div class="compact-row">
                        <div>
                            <strong><a href="<?= e(url('/user/@' . rawurlencode((string) $friend['username']))) ?>">@<?= e($friend['username']) ?></a></strong><br>
                            <span class="muted"><?= e($friend['display_name']) ?></span>
                        </div>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="remove_friend">
                            <input type="hidden" name="friend_user_id" value="<?= e($friend['friend_id']) ?>">
                            <button type="submit" class="button button--secondary">Quitar</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <h2>Solicitudes</h2>
        <?php if ($pendingFriends === []): ?>
            <p class="muted">No hay solicitudes pendientes.</p>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($pendingFriends as $request): ?>
                    <div class="compact-row">
                        <div>
                            <strong><a href="<?= e(url('/user/@' . rawurlencode((string) $request['username']))) ?>">@<?= e($request['username']) ?></a></strong><br>
                            <span class="muted"><?= e($request['display_name']) ?></span>
                        </div>
                        <div class="table-actions">
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="accept_friend">
                                <input type="hidden" name="friend_request_id" value="<?= e($request['id']) ?>">
                                <button type="submit">Aceptar</button>
                            </form>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="reject_friend">
                                <input type="hidden" name="friend_request_id" value="<?= e($request['id']) ?>">
                                <button type="submit" class="button button--secondary">Rechazar</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <h2>Cloud saves</h2>
    <?php if ($cloudSaves === []): ?>
        <p class="muted">No hay partidas cloud guardadas todavia.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Juego</th>
                        <th>Config</th>
                        <th>Slot</th>
                        <th>Tamano</th>
                        <th>Actualizado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cloudSaves as $save): ?>
                        <tr>
                            <td><?= e($save['game_name']) ?><br><code><?= e($save['game_slug']) ?></code></td>
                            <td><?= e($save['config_name']) ?><br><code><?= e($save['config_key']) ?></code></td>
                            <td><?= e($save['slot']) ?></td>
                            <td><?= e($save['size_bytes']) ?> bytes</td>
                            <td><?= e($save['updated_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Cuenta</h2>
    <dl class="meta">
        <div>
            <dt>Usuario</dt>
            <dd><?= e($user['username'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Email</dt>
            <dd><?= e($user['email'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Estado</dt>
            <dd><?= e($user['status'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Roles</dt>
            <dd><?= e(implode(', ', $user['roles'] ?? [])) ?></dd>
        </div>
    </dl>
</section>
<?php
Page::footer();
