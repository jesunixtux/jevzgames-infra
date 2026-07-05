<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\GameGroup;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/groups/');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            GameGroup::create($userId, $_POST);
            flash('message', 'Grupo creado.');
            redirect_to('/groups/');
        }
        if ($action === 'join') {
            GameGroup::join((int) ($_POST['group_id'] ?? 0), $userId);
            flash('message', 'Te uniste al grupo.');
            redirect_to('/groups/');
        }
        if ($action === 'leave') {
            GameGroup::leave((int) ($_POST['group_id'] ?? 0), $userId);
            flash('message', 'Saliste del grupo.');
            redirect_to('/groups/');
        }
        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/groups/');
    }
}

$myGroups = GameGroup::listForUser($userId);
$publicGroups = GameGroup::publicGroups();

Page::header(i18n_text('Grupos', 'Groups'));
?>
<section class="panel">
    <h1><?= e(i18n_text('Grupos', 'Groups')) ?></h1>
    <p class="muted"><?= e(i18n_text('Crea grupos publicos o privados para organizar comunidades dentro del launcher.', 'Create public or private groups to organize communities inside the launcher.')) ?></p>
</section>

<section class="panel">
    <h2><?= e(i18n_text('Crear grupo', 'Create group')) ?></h2>
    <form class="form" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field">
                <label for="name"><?= e(i18n_text('Nombre', 'Name')) ?></label>
                <input id="name" name="name" maxlength="120" required>
            </div>
            <div class="field">
                <label for="visibility"><?= e(i18n_text('Visibilidad', 'Visibility')) ?></label>
                <select id="visibility" name="visibility">
                    <option value="public">public</option>
                    <option value="private">private</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="description"><?= e(i18n_text('Descripcion', 'Description')) ?></label>
            <textarea id="description" name="description" rows="3"></textarea>
        </div>
        <button type="submit"><?= e(i18n_text('Crear', 'Create')) ?></button>
    </form>
</section>

<section class="grid profile-grid">
    <article class="panel">
        <h2><?= e(i18n_text('Mis grupos', 'My groups')) ?></h2>
        <?php if ($myGroups === []): ?>
            <p class="muted"><?= e(i18n_text('Todavia no estas en grupos.', 'You are not in any groups yet.')) ?></p>
        <?php endif; ?>
        <?php foreach ($myGroups as $group): ?>
            <div class="list-row">
                <strong><?= e($group['name']) ?></strong>
                <span class="muted"><?= e($group['role']) ?> · <?= e($group['member_count'] ?? 1) ?> members</span>
                <?php if ($group['role'] !== 'owner'): ?>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="leave">
                        <input type="hidden" name="group_id" value="<?= e($group['id']) ?>">
                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Salir', 'Leave')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel">
        <h2><?= e(i18n_text('Grupos publicos', 'Public groups')) ?></h2>
        <?php foreach ($publicGroups as $group): ?>
            <div class="list-row">
                <strong><?= e($group['name']) ?></strong>
                <span class="muted"><?= e($group['member_count'] ?? 0) ?> members</span>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="join">
                    <input type="hidden" name="group_id" value="<?= e($group['id']) ?>">
                    <button type="submit" class="button button--secondary"><?= e(i18n_text('Unirse', 'Join')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </article>
</section>
<?php
Page::footer();
