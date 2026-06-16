<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use App\Core\Page;
use App\Models\Achievement;
use App\Models\Game;
use App\Security\Auth;

require_installed();
Auth::requireLogin();

function achievement_page_image(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    return url('/' . ltrim($path, '/'));
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$selectedGameId = (int) ($_GET['game'] ?? 0);
$links = array_values(array_filter(Game::userLinks($userId), static function (array $game): bool {
    return in_array((string) $game['status'], Game::visibleStatuses(), true);
}));

$gamesWithAchievements = [];
$totalAchievements = 0;
$unlockedCount = 0;
$points = 0;

foreach ($links as $game) {
    $gameId = (int) $game['game_id'];
    if ($selectedGameId > 0 && $selectedGameId !== $gameId) {
        continue;
    }

    $achievements = Achievement::listForPlayer($gameId, $userId);
    foreach ($achievements as $achievement) {
        $totalAchievements++;
        if (!empty($achievement['unlocked'])) {
            $unlockedCount++;
            $points += (int) $achievement['points'];
        }
    }

    $gamesWithAchievements[] = [
        'game' => $game,
        'achievements' => $achievements,
    ];
}

Page::header('Logros');
?>
<section class="panel">
    <div class="section-heading">
        <div>
            <h1>Logros</h1>
            <p class="muted">Progreso y logros desbloqueados por juego vinculado.</p>
        </div>
        <a class="button button--secondary" href="<?= e(url('/library/')) ?>">Biblioteca</a>
    </div>
</section>

<section class="grid">
    <article class="tile metric-tile">
        <span class="metric"><?= e($unlockedCount) ?></span>
        <h2>Desbloqueados</h2>
        <p class="muted">De <?= e($totalAchievements) ?> logros visibles.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e($points) ?></span>
        <h2>Puntos</h2>
        <p class="muted">Suma de logros desbloqueados.</p>
    </article>
    <article class="tile metric-tile">
        <span class="metric"><?= e(count($links)) ?></span>
        <h2>Juegos</h2>
        <p class="muted">Conectados a tu cuenta.</p>
    </article>
</section>

<section class="panel">
    <div class="section-heading">
        <div>
            <h2>Filtro</h2>
            <p class="muted">Puedes ver todos tus logros o solo los de un juego.</p>
        </div>
        <form class="filter-bar filter-bar--inline" method="get">
            <label for="game">Juego</label>
            <select id="game" name="game" onchange="this.form.submit()">
                <option value="0">Todos</option>
                <?php foreach ($links as $game): ?>
                    <option value="<?= e($game['game_id']) ?>" <?= $selectedGameId === (int) $game['game_id'] ? 'selected' : '' ?>>
                        <?= e($game['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</section>

<?php if ($links === []): ?>
    <section class="panel">
        <div class="empty-state">
            <h3>No tienes juegos vinculados</h3>
            <p class="muted">Los logros apareceran cuando el juego se vincule e informe progreso por API.</p>
            <a class="button button--secondary" href="<?= e(url('/library/')) ?>">Abrir biblioteca</a>
        </div>
    </section>
<?php elseif ($gamesWithAchievements === []): ?>
    <section class="panel">
        <p class="muted">No hay logros para el filtro seleccionado.</p>
    </section>
<?php else: ?>
    <?php foreach ($gamesWithAchievements as $group): ?>
        <?php $game = $group['game']; ?>
        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2><?= e($game['name']) ?></h2>
                    <p class="muted"><code><?= e($game['slug']) ?></code> &middot; <?= e(Game::statusLabel((string) $game['status'])) ?></p>
                </div>
                <a class="button button--secondary" href="<?= e(url('/games/?game=' . rawurlencode((string) $game['slug']))) ?>">Ver juego</a>
            </div>

            <?php if ($group['achievements'] === []): ?>
                <p class="muted">Este juego no tiene logros configurados.</p>
            <?php else: ?>
                <div class="achievement-list">
                    <?php foreach ($group['achievements'] as $achievement): ?>
                        <?php
                        $unlocked = !empty($achievement['unlocked']);
                        $title = (!$unlocked && !empty($achievement['is_secret'])) ? 'Logro oculto' : (string) $achievement['title'];
                        $description = (!$unlocked && !empty($achievement['is_secret'])) ? 'Este logro es secreto hasta desbloquearlo.' : (string) ($achievement['description'] ?? '');
                        $image = achievement_page_image($unlocked ? ($achievement['image_path'] ?? '') : ($achievement['locked_image_path'] ?? $achievement['image_path'] ?? ''));
                        ?>
                        <article class="achievement-item">
                            <?php if ($image !== ''): ?>
                                <img class="achievement-thumb" src="<?= e($image) ?>" alt="">
                            <?php endif; ?>
                            <div>
                                <h3><?= e($title) ?></h3>
                                <?php if ($description !== ''): ?>
                                    <p><?= e($description) ?></p>
                                <?php endif; ?>
                                <p class="muted"><code><?= e($achievement['code']) ?></code> &middot; <?= e($achievement['progress_percent']) ?>%</p>
                                <progress value="<?= e($achievement['progress_value']) ?>" max="<?= e($achievement['goal_value']) ?>"></progress>
                            </div>
                            <div class="achievement-item__meta">
                                <strong><?= $unlocked ? 'Desbloqueado' : 'Pendiente' ?></strong>
                                <span><?= e($achievement['points']) ?> pts</span>
                                <?php if ($unlocked): ?>
                                    <span class="muted"><?= e($achievement['unlocked_at'] ?? '') ?></span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php
Page::footer();
