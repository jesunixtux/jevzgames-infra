<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Support;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\ActivityLogger;

require_installed();
Auth::requireRole(['supporter', 'admin', 'superroot']);

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$error = null;
$statusFilter = (string) ($_GET['status'] ?? 'open');
$allowedFilters = ['open', 'closed', 'solved', 'unsolved', 'all'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'open';
}

if (request_is_post()) {
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);

    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/supporter/');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        $ticket = Support::findTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException('El ticket indicado no existe.');
        }

        if ($action === 'assign') {
            Support::assignTicket($ticketId, $userId);
            ActivityLogger::info('support_ticket_assigned', ['ticket_id' => $ticketId, 'user_id' => $userId]);
            flash('message', 'Ticket asignado.');
        } elseif ($action === 'reply') {
            Support::assignTicket($ticketId, $userId);
            Support::addMessage($ticketId, $userId, (string) ($_POST['message'] ?? ''));
            ActivityLogger::info('support_ticket_replied', ['ticket_id' => $ticketId, 'user_id' => $userId]);
            flash('message', 'Respuesta enviada.');
        } elseif ($action === 'extend') {
            Support::extendTicket($ticketId, (int) ($_POST['minutes'] ?? 3));
            ActivityLogger::info('support_ticket_extended', ['ticket_id' => $ticketId, 'user_id' => $userId]);
            flash('message', 'Tiempo del chat extendido.');
        } elseif (in_array($action, ['closed', 'solved', 'unsolved'], true)) {
            Support::closeTicket($ticketId, $userId, $action);
            ActivityLogger::info('support_ticket_closed', ['ticket_id' => $ticketId, 'user_id' => $userId, 'status' => $action]);
            flash('message', 'Ticket actualizado.');
        } else {
            throw new RuntimeException('Accion no valida.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }

    redirect_to('/supporter/?ticket=' . $ticketId . '&status=' . rawurlencode($statusFilter));
}

$tickets = Support::listTickets($statusFilter);
$selectedTicketId = (int) ($_GET['ticket'] ?? 0);
if ($selectedTicketId === 0 && $tickets !== []) {
    $selectedTicketId = (int) $tickets[0]['id'];
}

$selectedTicket = $selectedTicketId > 0 ? Support::findTicket($selectedTicketId) : null;
$messages = $selectedTicket ? Support::messages((int) $selectedTicket['id']) : [];
$canReply = $selectedTicket ? Support::canReply($selectedTicket) : false;
$timeLeft = $selectedTicket ? Support::timeLeftSeconds($selectedTicket) : 0;

Page::header('Panel Supporter');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Panel Supporter</h1>
            <p class="muted">Gestion de tickets, conversacion simple con polling, cierre y resultado.</p>
        </div>
        <a class="button button--secondary" href="<?= e(url('/support/')) ?>">Vista usuario</a>
    </div>
</section>

<section class="support-layout">
    <aside class="support-sidebar" aria-label="Tickets">
        <form class="filter-bar" method="get">
            <label for="status">Estado</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <?php foreach ($allowedFilters as $filter): ?>
                    <option value="<?= e($filter) ?>" <?= $statusFilter === $filter ? 'selected' : '' ?>>
                        <?= e($filter === 'all' ? 'Todos' : Support::statusLabel($filter)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="ticket-list">
            <?php if ($tickets === []): ?>
                <p class="muted ticket-empty">No hay tickets para este filtro.</p>
            <?php endif; ?>

            <?php foreach ($tickets as $ticket): ?>
                <?php
                $active = (int) $ticket['id'] === $selectedTicketId;
                $ticketUrl = url('/supporter/?ticket=' . (int) $ticket['id'] . '&status=' . rawurlencode($statusFilter));
                ?>
                <a class="ticket-row <?= $active ? 'ticket-row--active' : '' ?>" href="<?= e($ticketUrl) ?>">
                    <span class="ticket-row__title">#<?= e($ticket['id']) ?> <?= e($ticket['subject']) ?></span>
                    <span class="ticket-row__meta">
                        <?= e($ticket['requester_username'] ?? 'Usuario eliminado') ?> ·
                        <?= e(Support::statusLabel((string) $ticket['status'])) ?> ·
                        <?= e((string) ($ticket['message_count'] ?? 0)) ?> msg
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <article class="support-thread">
        <?php if (!$selectedTicket): ?>
            <div class="empty-state">
                <h2>Sin ticket seleccionado</h2>
                <p class="muted">Selecciona un ticket de la lista para responder o cerrar la solicitud.</p>
            </div>
        <?php else: ?>
            <header class="thread-header">
                <div>
                    <h2>#<?= e($selectedTicket['id']) ?> <?= e($selectedTicket['subject']) ?></h2>
                    <p class="muted">
                        Usuario: <?= e($selectedTicket['requester_username'] ?? 'Usuario eliminado') ?>
                        <?php if (!empty($selectedTicket['assigned_username'])): ?>
                            · Asignado a <?= e($selectedTicket['assigned_username']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="status-pill status-pill--<?= e((string) $selectedTicket['status']) ?>">
                    <?= e(Support::statusLabel((string) $selectedTicket['status'])) ?>
                </span>
            </header>

            <div class="thread-meta">
                <span>Creado: <?= e((string) $selectedTicket['created_at']) ?></span>
                <span>Expira: <?= e((string) ($selectedTicket['expires_at'] ?? 'sin limite')) ?></span>
                <span id="ticket-time-left" data-seconds="<?= e((string) $timeLeft) ?>">
                    Tiempo restante: <?= e((string) floor($timeLeft / 60)) ?>m <?= e((string) ($timeLeft % 60)) ?>s
                </span>
            </div>

            <div
                id="support-messages"
                class="messages"
                data-ticket-id="<?= e((string) $selectedTicket['id']) ?>"
                data-after-id="<?= e((string) ($messages !== [] ? end($messages)['id'] : 0)) ?>"
                data-poll-url="<?= e(url('/supporter/messages/')) ?>"
                data-ticket-user-id="<?= e((string) ($selectedTicket['user_id'] ?? 0)) ?>"
            >
                <?php foreach ($messages as $message): ?>
                    <?php $fromRequester = (int) $message['sender_user_id'] === (int) $selectedTicket['user_id']; ?>
                    <div class="message <?= $fromRequester ? 'message--requester' : 'message--support' ?>" data-message-id="<?= e($message['id']) ?>">
                        <div class="message__meta">
                            <strong><?= e($message['sender_username'] ?? 'Sistema') ?></strong>
                            <span><?= e($message['created_at']) ?></span>
                        </div>
                        <p><?= nl2br(e($message['message'])) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="support-actions">
                <?php if (($selectedTicket['status'] ?? '') === 'open'): ?>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="ticket_id" value="<?= e($selectedTicket['id']) ?>">
                        <input type="hidden" name="action" value="assign">
                        <button type="submit" class="button button--secondary">Asignarme</button>
                    </form>

                    <form method="post" class="inline-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="ticket_id" value="<?= e($selectedTicket['id']) ?>">
                        <input type="hidden" name="action" value="extend">
                        <label for="minutes">Extender</label>
                        <input id="minutes" name="minutes" type="number" min="1" max="120" value="3">
                        <button type="submit" class="button button--secondary">Agregar minutos</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($canReply): ?>
                <form class="reply-form" method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="ticket_id" value="<?= e($selectedTicket['id']) ?>">
                    <input type="hidden" name="action" value="reply">
                    <label for="message">Responder</label>
                    <textarea id="message" name="message" rows="4" maxlength="5000" required></textarea>
                    <div class="actions">
                        <button type="submit">Enviar respuesta</button>
                    </div>
                </form>
            <?php elseif (($selectedTicket['status'] ?? '') === 'open'): ?>
                <div class="alert alert--error">El chat esta vencido. Extiende el tiempo para responder.</div>
            <?php endif; ?>

            <div class="closure-actions">
                <?php foreach (['solved' => 'Marcar solucionado', 'unsolved' => 'Marcar no solucionado', 'closed' => 'Cerrar'] as $status => $label): ?>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="ticket_id" value="<?= e($selectedTicket['id']) ?>">
                        <input type="hidden" name="action" value="<?= e($status) ?>">
                        <button type="submit" class="button button--secondary"><?= e($label) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<script>
(function () {
    var box = document.getElementById('support-messages');
    if (!box) {
        return;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function appendMessage(message, ticketUserId) {
        var fromRequester = Number(message.sender_user_id) === Number(ticketUserId);
        var item = document.createElement('div');
        item.className = 'message ' + (fromRequester ? 'message--requester' : 'message--support');
        item.setAttribute('data-message-id', message.id);
        item.innerHTML =
            '<div class="message__meta"><strong>' + escapeHtml(message.sender_username || 'Sistema') + '</strong>' +
            '<span>' + escapeHtml(message.created_at || '') + '</span></div>' +
            '<p>' + escapeHtml(message.message || '').replace(/\n/g, '<br>') + '</p>';
        box.appendChild(item);
        box.scrollTop = box.scrollHeight;
    }

    function poll() {
        var ticketId = box.getAttribute('data-ticket-id');
        var afterId = box.getAttribute('data-after-id') || '0';
        var url = box.getAttribute('data-poll-url') + '?ticket_id=' + encodeURIComponent(ticketId) + '&after_id=' + encodeURIComponent(afterId);

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload.success) {
                    return;
                }

                var ticketUserId = box.getAttribute('data-ticket-user-id');
                payload.data.messages.forEach(function (message) {
                    appendMessage(message, ticketUserId);
                    box.setAttribute('data-after-id', message.id);
                });
            })
            .catch(function () {});
    }

    setInterval(poll, 4000);
})();
</script>
<?php
Page::footer();
