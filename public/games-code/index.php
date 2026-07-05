<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Database;
use App\Core\Page;
use App\Models\Game;
use App\Models\GameCode;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canManageAll = Auth::hasRole(['admin', 'superroot']);
$selectedGameId = (int) ($_GET['game_id'] ?? $_POST['game_id'] ?? 0);
$generated = $_SESSION['_generated_game_codes'] ?? null;
unset($_SESSION['_generated_game_codes']);

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Csrf::failRedirect('/games-code/');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'generate_direct') {
            $result = GameCode::generateDirect((int) ($_POST['game_id'] ?? 0), (int) ($_POST['quantity'] ?? 1), $userId, $canManageAll);
            $_SESSION['_generated_game_codes'] = $result;
            ActivityLogger::info('game_license_codes_generated', ['user_id' => $userId, 'game_id' => (int) ($_POST['game_id'] ?? 0), 'quantity' => (int) $result['quantity']]);
            flash('message', 'Codigos generados. Copialos ahora.');
            redirect_to('/games-code/?game_id=' . (int) ($_POST['game_id'] ?? 0));
        }

        if ($action === 'request_external') {
            $requestId = GameCode::requestForExternalGame((int) ($_POST['game_id'] ?? 0), (int) ($_POST['quantity'] ?? 1), $userId, (string) ($_POST['request_note'] ?? ''));
            ActivityLogger::info('game_license_codes_requested', ['user_id' => $userId, 'request_id' => $requestId]);
            flash('message', 'Solicitud enviada para revision.');
            redirect_to('/games-code/?game_id=' . (int) ($_POST['game_id'] ?? 0));
        }

        if ($action === 'revoke_code') {
            GameCode::revokeCode((int) ($_POST['code_id'] ?? 0), $userId, (string) ($_POST['reason'] ?? ''), $canManageAll);
            ActivityLogger::info('game_license_code_revoked', ['user_id' => $userId, 'code_id' => (int) ($_POST['code_id'] ?? 0)]);
            flash('message', 'Codigo revocado.');
            redirect_to('/games-code/?game_id=' . (int) ($_POST['game_id'] ?? 0));
        }

        if ($canManageAll && $action === 'approve_request') {
            $result = GameCode::approveRequest((int) ($_POST['request_id'] ?? 0), $userId);
            $_SESSION['_generated_game_codes'] = $result;
            ActivityLogger::info('game_license_code_request_approved', ['user_id' => $userId, 'request_id' => (int) ($_POST['request_id'] ?? 0)]);
            flash('message', 'Solicitud aprobada. Codigos generados.');
            redirect_to('/games-code/');
        }

        if ($canManageAll && in_array($action, ['reject_request', 'revoke_request'], true)) {
            GameCode::rejectRequest((int) ($_POST['request_id'] ?? 0), $userId, (string) ($_POST['reason'] ?? ''), $action === 'revoke_request' ? 'revoked' : 'rejected');
            ActivityLogger::info('game_license_code_request_closed', ['user_id' => $userId, 'request_id' => (int) ($_POST['request_id'] ?? 0), 'action' => $action]);
            flash('message', 'Solicitud actualizada.');
            redirect_to('/games-code/');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to($selectedGameId > 0 ? '/games-code/?game_id=' . $selectedGameId : '/games-code/');
    }
}

GameCode::ensureTables();
Game::ensureVisibilityColumn();

if ($canManageAll) {
    $stmt = Database::pdo()->query(
        'SELECT id, name, slug, owner_user_id, source_type, external_game_id, status
         FROM games
         ORDER BY source_type ASC, name ASC'
    );
    $games = $stmt->fetchAll();
} else {
    $stmt = Database::pdo()->prepare(
        'SELECT id, name, slug, owner_user_id, source_type, external_game_id, status
         FROM games
         WHERE owner_user_id = :user_id
         ORDER BY source_type ASC, name ASC'
    );
    $stmt->execute(['user_id' => $userId]);
    $games = $stmt->fetchAll();
}

$selectedGame = null;
foreach ($games as $game) {
    if ((int) $game['id'] === $selectedGameId) {
        $selectedGame = $game;
        break;
    }
}
if ($selectedGame === null && $games !== []) {
    $selectedGame = $games[0];
    $selectedGameId = (int) $selectedGame['id'];
}

$codes = $selectedGame ? GameCode::listCodes($selectedGameId, $userId, $canManageAll) : [];
$myRequests = GameCode::listRequests(null, $userId);
$adminRequests = $canManageAll ? GameCode::listRequests() : [];
$isExternal = $selectedGame && (string) ($selectedGame['source_type'] ?? 'internal') === 'external';

Page::header(i18n_text('Codigos de juegos', 'Game codes'));
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1><?= e(i18n_text('Codigos de juegos', 'Game codes')) ?></h1>
            <p class="muted"><?= e(i18n_text('Codigos de licencia separados de los codigos de objetos/inventario.', 'License codes separated from item/inventory codes.')) ?></p>
        </div>
        <a class="button button--secondary" href="<?= e(url('/redeem/')) ?>"><?= e(i18n_text('Canjear codigo', 'Redeem code')) ?></a>
    </div>
</section>

<?php if (is_array($generated) && !empty($generated['codes'])): ?>
    <section class="panel">
        <h2><?= e(i18n_text('Codigos generados', 'Generated codes')) ?></h2>
        <p class="muted"><?= e(i18n_text('Copia estos codigos ahora; despues solo se guarda el preview y hash.', 'Copy these codes now; later only preview and hash are stored.')) ?></p>
        <pre class="code-view"><?= e(implode(PHP_EOL, array_map('strval', $generated['codes']))) ?></pre>
    </section>
<?php endif; ?>

<section class="panel">
    <h2><?= e(i18n_text('Seleccionar juego', 'Select game')) ?></h2>
    <?php if ($games === []): ?>
        <p class="muted"><?= e(i18n_text('No tienes juegos administrables.', 'You do not have manageable games.')) ?></p>
    <?php else: ?>
        <form class="filter-bar filter-bar--inline" method="get">
            <label for="game_id"><?= e(i18n_text('Juego', 'Game')) ?></label>
            <select id="game_id" name="game_id">
                <?php foreach ($games as $game): ?>
                    <option value="<?= e($game['id']) ?>" <?= (int) $game['id'] === $selectedGameId ? 'selected' : '' ?>>
                        <?= e($game['name']) ?> (<?= e($game['slug']) ?>) - <?= e((string) ($game['source_type'] ?? 'internal')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button--secondary"><?= e(i18n_text('Abrir', 'Open')) ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($selectedGame): ?>
    <section class="panel">
        <h2><?= e($selectedGame['name']) ?></h2>
        <?php if ($isExternal && !$canManageAll): ?>
            <p class="muted"><?= e(i18n_text('Los juegos externos solicitan codigos y Admin/Superroot aprueba o rechaza.', 'External games request codes and Admin/Superroot approves or rejects.')) ?></p>
            <form class="form" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="request_external">
                <input type="hidden" name="game_id" value="<?= e($selectedGameId) ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="quantity"><?= e(i18n_text('Copias', 'Copies')) ?></label>
                        <input id="quantity" name="quantity" type="number" min="1" max="100" value="10" required>
                    </div>
                    <div class="field">
                        <label for="request_note"><?= e(i18n_text('Nota opcional', 'Optional note')) ?></label>
                        <input id="request_note" name="request_note" maxlength="500">
                    </div>
                </div>
                <button type="submit"><?= e(i18n_text('Solicitar codigos', 'Request codes')) ?></button>
            </form>
        <?php else: ?>
            <form class="form" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="generate_direct">
                <input type="hidden" name="game_id" value="<?= e($selectedGameId) ?>">
                <div class="field">
                    <label for="quantity"><?= e(i18n_text('Cantidad maxima 100', 'Maximum quantity 100')) ?></label>
                    <input id="quantity" name="quantity" type="number" min="1" max="100" value="10" required>
                </div>
                <button type="submit"><?= e(i18n_text('Generar codigos', 'Generate codes')) ?></button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2><?= e(i18n_text('Codigos del juego', 'Game codes')) ?></h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Batch</th>
                        <th><?= e(i18n_text('Estado', 'Status')) ?></th>
                        <th><?= e(i18n_text('Canjeado por', 'Redeemed by')) ?></th>
                        <th><?= e(i18n_text('Accion', 'Action')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($codes === []): ?>
                        <tr><td colspan="5"><?= e(i18n_text('No hay codigos para este juego.', 'There are no codes for this game.')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($codes as $code): ?>
                        <tr>
                            <td><code><?= e($code['code_preview']) ?></code></td>
                            <td><code><?= e($code['batch_id']) ?></code></td>
                            <td><?= e($code['status']) ?></td>
                            <td><?= e($code['redeemed_username'] ?? '') ?></td>
                            <td>
                                <?php if ($code['status'] === 'active'): ?>
                                    <form method="post" class="row-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="revoke_code">
                                        <input type="hidden" name="game_id" value="<?= e($selectedGameId) ?>">
                                        <input type="hidden" name="code_id" value="<?= e($code['id']) ?>">
                                        <input name="reason" placeholder="<?= e(i18n_text('Motivo obligatorio', 'Required reason')) ?>" required>
                                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Revocar', 'Revoke')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted"><?= e($code['revoked_reason'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <h2><?= e(i18n_text('Mis solicitudes', 'My requests')) ?></h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th><?= e(i18n_text('Juego', 'Game')) ?></th><th><?= e(i18n_text('Copias', 'Copies')) ?></th><th><?= e(i18n_text('Estado', 'Status')) ?></th><th><?= e(i18n_text('Motivo', 'Reason')) ?></th></tr></thead>
            <tbody>
                <?php if ($myRequests === []): ?>
                    <tr><td colspan="4"><?= e(i18n_text('No tienes solicitudes.', 'You have no requests.')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($myRequests as $request): ?>
                    <tr>
                        <td><?= e($request['game_name']) ?></td>
                        <td><?= e($request['quantity']) ?></td>
                        <td><?= e($request['status']) ?></td>
                        <td><?= e($request['response_reason'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canManageAll): ?>
    <section class="panel">
        <h2><?= e(i18n_text('Solicitudes externas', 'External requests')) ?></h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= e(i18n_text('Juego', 'Game')) ?></th>
                        <th><?= e(i18n_text('Usuario', 'User')) ?></th>
                        <th><?= e(i18n_text('Copias', 'Copies')) ?></th>
                        <th><?= e(i18n_text('Estado', 'Status')) ?></th>
                        <th><?= e(i18n_text('Acciones', 'Actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($adminRequests === []): ?>
                        <tr><td colspan="5"><?= e(i18n_text('No hay solicitudes.', 'There are no requests.')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($adminRequests as $request): ?>
                        <tr>
                            <td><?= e($request['game_name']) ?><br><code><?= e($request['game_slug']) ?></code></td>
                            <td>@<?= e($request['requester_username']) ?></td>
                            <td><?= e($request['quantity']) ?></td>
                            <td><?= e($request['status']) ?><br><span class="muted"><?= e($request['response_reason'] ?? '') ?></span></td>
                            <td class="table-actions">
                                <?php if ($request['status'] === 'pending'): ?>
                                    <form method="post">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="approve_request">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <button type="submit"><?= e(i18n_text('Aprobar', 'Approve')) ?></button>
                                    </form>
                                    <form method="post" class="row-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reject_request">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <input name="reason" placeholder="<?= e(i18n_text('Motivo obligatorio', 'Required reason')) ?>" required>
                                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Rechazar', 'Reject')) ?></button>
                                    </form>
                                <?php elseif ($request['status'] === 'approved'): ?>
                                    <form method="post" class="row-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="revoke_request">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <input name="reason" placeholder="<?= e(i18n_text('Motivo obligatorio', 'Required reason')) ?>" required>
                                        <button type="submit" class="button button--secondary"><?= e(i18n_text('Revocar solicitud', 'Revoke request')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php
Page::footer();
