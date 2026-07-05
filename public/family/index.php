<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\FamilySharing;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/family/');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'invite') {
            FamilySharing::invite($userId, (string) ($_POST['identity'] ?? ''));
            flash('message', 'Invitacion enviada.');
            redirect_to('/family/');
        }
        if ($action === 'accept') {
            FamilySharing::accept($userId, (int) ($_POST['owner_user_id'] ?? 0));
            flash('message', 'Family Sharing aceptado.');
            redirect_to('/family/');
        }
        if ($action === 'revoke') {
            FamilySharing::revoke($userId, (int) ($_POST['member_user_id'] ?? 0));
            flash('message', 'Acceso familiar revocado.');
            redirect_to('/family/');
        }
        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/family/');
    }
}

$rows = FamilySharing::rowsForUser($userId);

Page::header('Family Sharing');
?>
<section class="panel">
    <h1>Family Sharing</h1>
    <p class="muted"><?= e(i18n_text('Comparte tu biblioteca con usuarios de confianza. El acceso compartido no crea una licencia propia.', 'Share your library with trusted users. Shared access does not create an owned license.')) ?></p>
</section>

<section class="panel">
    <h2><?= e(i18n_text('Invitar usuario', 'Invite user')) ?></h2>
    <form class="form" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="invite">
        <div class="field">
            <label for="identity"><?= e(i18n_text('Usuario o email', 'Username or email')) ?></label>
            <input id="identity" name="identity" required>
        </div>
        <button type="submit"><?= e(i18n_text('Enviar invitacion', 'Send invite')) ?></button>
    </form>
</section>

<section class="panel">
    <h2><?= e(i18n_text('Mi Family Sharing', 'My Family Sharing')) ?></h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th><?= e(i18n_text('Propietario', 'Owner')) ?></th><th><?= e(i18n_text('Miembro', 'Member')) ?></th><th><?= e(i18n_text('Estado', 'Status')) ?></th><th><?= e(i18n_text('Accion', 'Action')) ?></th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="4"><?= e(i18n_text('No hay relaciones familiares.', 'No family relationships.')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>@<?= e($row['owner_username']) ?></td>
                        <td>@<?= e($row['member_username']) ?></td>
                        <td><?= e($row['status']) ?></td>
                        <td>
                            <?php if ((int) $row['member_user_id'] === $userId && $row['status'] === 'pending'): ?>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="accept">
                                    <input type="hidden" name="owner_user_id" value="<?= e($row['owner_user_id']) ?>">
                                    <button type="submit"><?= e(i18n_text('Aceptar', 'Accept')) ?></button>
                                </form>
                            <?php elseif ((int) $row['owner_user_id'] === $userId && $row['status'] !== 'revoked'): ?>
                                <form method="post">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="member_user_id" value="<?= e($row['member_user_id']) ?>">
                                    <button type="submit" class="button button--secondary"><?= e(i18n_text('Revocar', 'Revoke')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
Page::footer();
