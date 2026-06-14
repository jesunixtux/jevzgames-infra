<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Community;
use App\Security\Auth;
use App\Security\Csrf;

require_installed();

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

if (request_is_post()) {
    if ($userId <= 0) {
        $_SESSION['after_login_redirect'] = '/community/';
        redirect_to('/login/');
    }

    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        flash('error', 'Token CSRF invalido. Recarga la pagina e intenta de nuevo.');
        redirect_to('/community/');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_post') {
            $postId = Community::createPost($userId, $_POST);
            flash('message', 'Publicacion creada.');
            redirect_to('/community/?post=' . $postId);
        }

        if ($action === 'add_comment') {
            $postId = (int) ($_POST['post_id'] ?? 0);
            Community::addComment($postId, $userId, (string) ($_POST['body'] ?? ''));
            flash('message', 'Comentario publicado.');
            redirect_to('/community/?post=' . $postId . '#comments');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        $postId = (int) ($_POST['post_id'] ?? 0);
        redirect_to($postId > 0 ? '/community/?post=' . $postId : '/community/');
    }
}

$postId = (int) ($_GET['post'] ?? 0);
$selectedPost = $postId > 0 ? Community::findPost($postId) : null;
$comments = $selectedPost ? Community::comments((int) $selectedPost['id']) : [];
$posts = Community::listPosts();

Page::header('Comunidad');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Comunidad</h1>
            <p class="muted">Publicaciones y conversaciones de usuarios de JevzGames.</p>
        </div>
        <?php if ($selectedPost): ?>
            <a class="button button--secondary" href="<?= e(url('/community/')) ?>">Ver feed</a>
        <?php endif; ?>
    </div>
</section>

<?php if (!$selectedPost): ?>
    <section class="grid community-layout">
        <article class="panel">
            <h2>Nueva publicacion</h2>
            <?php if ($userId <= 0): ?>
                <p class="muted">Inicia sesion para publicar en la comunidad.</p>
                <div class="actions">
                    <a class="button button--secondary" href="<?= e(url('/login/')) ?>">Login</a>
                </div>
            <?php else: ?>
                <form class="form" method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="create_post">
                    <div class="field">
                        <label for="title">Titulo</label>
                        <input id="title" name="title" maxlength="180" required>
                    </div>
                    <div class="field">
                        <label for="body">Contenido</label>
                        <textarea id="body" name="body" rows="7" maxlength="8000" required></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit">Publicar</button>
                    </div>
                </form>
            <?php endif; ?>
        </article>

        <article class="panel">
            <h2>Feed</h2>
            <?php if ($posts === []): ?>
                <p class="muted">Aun no hay publicaciones.</p>
            <?php else: ?>
                <div class="community-feed">
                    <?php foreach ($posts as $post): ?>
                        <article class="community-post">
                            <div class="community-post__header">
                                <div>
                                    <h3><a href="<?= e(url('/community/?post=' . (int) $post['id'])) ?>"><?= e($post['title']) ?></a></h3>
                                    <p class="muted">
                                        <a href="<?= e(url('/user/@' . rawurlencode((string) $post['username']))) ?>">@<?= e($post['username']) ?></a>
                                        - <?= e($post['created_at']) ?>
                                    </p>
                                </div>
                                <span class="status-pill"><?= e((string) ($post['comment_count'] ?? 0)) ?> comentarios</span>
                            </div>
                            <?php $preview = strlen((string) $post['body']) > 320 ? substr((string) $post['body'], 0, 320) . '...' : (string) $post['body']; ?>
                            <p><?= nl2br(e($preview)) ?></p>
                            <a class="button button--secondary" href="<?= e(url('/community/?post=' . (int) $post['id'])) ?>">Abrir</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
<?php else: ?>
    <section class="panel">
        <article class="community-post community-post--detail">
            <div class="community-post__header">
                <div>
                    <h2><?= e($selectedPost['title']) ?></h2>
                    <p class="muted">
                        <a href="<?= e(url('/user/@' . rawurlencode((string) $selectedPost['username']))) ?>">@<?= e($selectedPost['username']) ?></a>
                        - <?= e($selectedPost['created_at']) ?>
                    </p>
                </div>
            </div>
            <p><?= nl2br(e($selectedPost['body'])) ?></p>
        </article>
    </section>

    <section class="panel" id="comments">
        <h2>Comentarios</h2>
        <?php if ($comments === []): ?>
            <p class="muted">No hay comentarios todavia.</p>
        <?php else: ?>
            <div class="compact-list">
                <?php foreach ($comments as $comment): ?>
                    <article class="compact-row">
                        <div>
                            <strong><a href="<?= e(url('/user/@' . rawurlencode((string) $comment['username']))) ?>">@<?= e($comment['username']) ?></a></strong>
                            <span class="muted"> - <?= e($comment['created_at']) ?></span>
                            <p><?= nl2br(e($comment['body'])) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3>Responder</h3>
        <?php if ($userId <= 0): ?>
            <p class="muted">Inicia sesion para comentar.</p>
            <div class="actions">
                <a class="button button--secondary" href="<?= e(url('/login/')) ?>">Login</a>
            </div>
        <?php else: ?>
            <form class="reply-form" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="add_comment">
                <input type="hidden" name="post_id" value="<?= e($selectedPost['id']) ?>">
                <div class="field">
                    <label for="comment-body">Comentario</label>
                    <textarea id="comment-body" name="body" rows="4" maxlength="3000" required></textarea>
                </div>
                <div class="actions">
                    <button type="submit">Comentar</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
Page::footer();
