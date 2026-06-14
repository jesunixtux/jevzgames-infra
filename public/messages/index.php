<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\DirectMessage;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

if (request_is_post()) {
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/messages/');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'send_message') {
            $recipientId = (int) ($_POST['recipient_user_id'] ?? 0);
            if ($recipientId <= 0) {
                $recipient = DirectMessage::userByUsername((string) ($_POST['recipient_username'] ?? ''));
                if (!$recipient) {
                    throw new RuntimeException('Destinatario no encontrado.');
                }
                $recipientId = (int) $recipient['id'];
            }

            $threadId = DirectMessage::send($userId, $recipientId, (string) ($_POST['message'] ?? ''));
            flash('message', 'Mensaje enviado.');
            redirect_to('/messages/?thread=' . $threadId);
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('/messages/');
    }
}

$inbox = DirectMessage::inbox($userId);
$activeThreadId = (int) ($_GET['thread'] ?? 0);
$composeTo = ltrim(trim((string) ($_GET['to'] ?? '')), '@');
$composeUser = $composeTo !== '' ? DirectMessage::userByUsername($composeTo) : null;
$activeThread = $activeThreadId > 0 ? DirectMessage::findThreadForUser($activeThreadId, $userId) : null;
$messages = $activeThread ? DirectMessage::messages((int) $activeThread['id'], $userId) : [];
$lastMessage = $messages !== [] ? $messages[array_key_last($messages)] : null;
$lastMessageId = is_array($lastMessage) ? (int) $lastMessage['id'] : 0;

Page::header('Mensajes');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Mensajes</h1>
            <p class="muted">Conversaciones privadas entre usuarios.</p>
        </div>
        <a class="button button--secondary" href="<?= e(url('/messages/')) ?>">Nuevo mensaje</a>
    </div>
</section>

<section class="support-layout">
    <aside class="support-sidebar">
        <form class="filter-bar" method="get">
            <label for="to">Enviar a usuario</label>
            <input id="to" name="to" placeholder="usuario">
            <button type="submit">Componer</button>
        </form>

        <div class="ticket-list">
            <?php if ($inbox === []): ?>
                <p class="ticket-empty muted">No hay conversaciones.</p>
            <?php else: ?>
                <?php foreach ($inbox as $thread): ?>
                    <?php $isActive = $activeThread && (int) $activeThread['id'] === (int) $thread['id']; ?>
                    <a class="<?= $isActive ? 'ticket-row ticket-row--active' : 'ticket-row' ?>" href="<?= e(url('/messages/?thread=' . (int) $thread['id'])) ?>">
                        <span class="ticket-row__title">
                            @<?= e($thread['other_username']) ?>
                            <?php if ((int) ($thread['unread_count'] ?? 0) > 0): ?>
                                <span class="nav__badge"><?= e((string) min((int) $thread['unread_count'], 99)) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ticket-row__meta"><?= e(substr((string) ($thread['last_message'] ?? ''), 0, 90)) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <section class="support-thread">
        <?php if ($activeThread): ?>
            <div class="thread-header">
                <div>
                    <h2>@<?= e($activeThread['other_username']) ?></h2>
                    <p class="muted"><?= e($activeThread['other_display_name']) ?></p>
                </div>
                <a class="button button--secondary" href="<?= e(url('/user/@' . rawurlencode((string) $activeThread['other_username']))) ?>">Ver perfil</a>
            </div>

            <div
                id="direct-messages"
                class="messages"
                data-thread-id="<?= e($activeThread['id']) ?>"
                data-after-id="<?= e($lastMessageId) ?>"
                data-current-user-id="<?= e($userId) ?>"
                data-poll-url="<?= e(url('/messages/poll/')) ?>"
            >
                <?php if ($messages === []): ?>
                    <p class="muted">No hay mensajes en esta conversacion.</p>
                <?php endif; ?>
                <?php foreach ($messages as $message): ?>
                    <?php $fromMe = (int) $message['sender_user_id'] === $userId; ?>
                    <div class="message <?= $fromMe ? 'message--support' : 'message--requester' ?>">
                        <div class="message__meta">
                            <strong><?= e($fromMe ? 'Tu' : (string) $message['sender_username']) ?></strong>
                            <span><?= e($message['created_at']) ?></span>
                        </div>
                        <p><?= nl2br(e($message['message'])) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <form class="reply-form" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="recipient_user_id" value="<?= e($activeThread['other_user_id']) ?>">
                <div class="field">
                    <label for="message">Responder</label>
                    <textarea id="message" name="message" rows="4" maxlength="5000" required></textarea>
                </div>
                <div class="actions">
                    <button type="submit">Enviar</button>
                </div>
            </form>
        <?php else: ?>
            <h2>Nuevo mensaje</h2>
            <?php if ($composeTo !== '' && !$composeUser): ?>
                <p class="alert alert--error">No encontre el usuario @<?= e($composeTo) ?>.</p>
            <?php endif; ?>
            <form class="form" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="send_message">
                <?php if ($composeUser): ?>
                    <input type="hidden" name="recipient_user_id" value="<?= e($composeUser['id']) ?>">
                    <p class="muted">Para @<?= e($composeUser['username']) ?></p>
                <?php else: ?>
                    <div class="field">
                        <label for="recipient_username">Usuario</label>
                        <input id="recipient_username" name="recipient_username" value="<?= e($composeTo) ?>" required>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="new-message">Mensaje</label>
                    <textarea id="new-message" name="message" rows="5" maxlength="5000" required></textarea>
                </div>
                <div class="actions">
                    <button type="submit">Enviar mensaje</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</section>
<?php if ($activeThread): ?>
<script>
(function () {
    var box = document.getElementById('direct-messages');
    if (!box) {
        return;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function appendMessage(message) {
        var currentUserId = Number(box.getAttribute('data-current-user-id') || 0);
        var fromMe = Number(message.sender_user_id) === currentUserId;
        var item = document.createElement('div');
        item.className = 'message ' + (fromMe ? 'message--support' : 'message--requester');
        item.setAttribute('data-message-id', message.id);
        item.innerHTML =
            '<div class="message__meta"><strong>' + escapeHtml(fromMe ? 'Tu' : message.sender_username) + '</strong>' +
            '<span>' + escapeHtml(message.created_at || '') + '</span></div>' +
            '<p>' + escapeHtml(message.message || '').replace(/\n/g, '<br>') + '</p>';
        box.appendChild(item);
        box.scrollTop = box.scrollHeight;
    }

    function poll() {
        var pollUrl = box.getAttribute('data-poll-url');
        var threadId = box.getAttribute('data-thread-id');
        var afterId = box.getAttribute('data-after-id') || '0';
        fetch(pollUrl + '?thread=' + encodeURIComponent(threadId) + '&after_id=' + encodeURIComponent(afterId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data || !payload.data.messages) {
                    return;
                }

                payload.data.messages.forEach(function (message) {
                    appendMessage(message);
                    box.setAttribute('data-after-id', message.id);
                });
            })
            .catch(function () {});
    }

    window.setInterval(poll, 3000);
})();
</script>
<?php endif; ?>
<?php
Page::footer();
