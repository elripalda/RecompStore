<?php
// Recomp Store admin: the games list, ordering, and Publish.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'publish') {
            $n = rs_publish();
            $soon = rs_soon_count();
            rs_flash("Published. The catalog now lists $n " . ($n === 1 ? 'game' : 'games') . ($soon ? " and $soon Coming soon" : '') . '; the PS5 store and the site pick it up on their next load.');
        } elseif ($action === 'move' && isset($_POST['id'], $_POST['dir'])) {
            $games = rs_games();
            $ids = array_column($games, 'id');
            $i = array_search($_POST['id'], $ids, true);
            $j = $_POST['dir'] === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && $j >= 0 && $j < count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                $st = rs_db()->prepare('UPDATE games SET sort = ? WHERE id = ?');
                foreach ($ids as $k => $id) $st->execute([($k + 1) * 10, $id]);
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            rs_db()->prepare('DELETE FROM games WHERE id = ?')->execute([$_POST['id']]);
            rs_flash('Removed from the admin. Press Publish to take it out of the store and the site too.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: index.php');
    exit;
}

$games = rs_games();
$published = rs_setting('published_at');
rs_head('Games');
?>
<section class="head-row">
  <div>
    <h1>Games</h1>
    <p class="muted"><?= $published ? 'Last published ' . h(date('M j, Y g:i a', strtotime($published))) : 'Not published yet' ?>. Changes show in the store and on the site after you publish.</p>
  </div>
  <div class="actions">
    <a class="btn" href="edit.php">Add a game</a>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish</button></form>
  </div>
</section>

<section class="list">
<?php foreach ($games as $i => $g): ?>
  <article class="row<?= $g['hidden'] ? ' dim' : '' ?>">
    <?php if ($g['icon']): ?><img class="icon" src="<?= h($g['icon']) ?>" alt=""><?php else: ?><span class="icon blank"><?= h(mb_substr($g['name'] ?: '?', 0, 2)) ?></span><?php endif; ?>
    <div class="meta">
      <h2><?= h($g['name'] ?: 'Untitled') ?></h2>
      <p>
        <span class="tag<?= $g['kind'] === 'original' ? ' cyan' : '' ?>"><?= $g['kind'] === 'original' ? 'Recomp Store' : 'By ' . h($g['author']) ?></span>
        <?php if ($g['featured']): ?><span class="tag">Featured</span><?php endif; ?>
        <?php if ($g['hidden']): ?><span class="tag">Hidden</span><?php endif; ?>
        <?php if ($g['soon']): ?><span class="tag soon">Coming soon<?= $g['date'] !== '' ? ' · ' . h($g['date']) : '' ?></span><?php endif; ?>
        <?php if ($g['setup'] && !$g['soon']): ?><span class="tag">Store sets up files</span><?php endif; ?>
        <span class="muted"><?= h($g['id']) ?><?= $g['soon'] ? '' : ' · ' . h($g['version'] ?: 'no version') ?></span>
      </p>
    </div>
    <div class="row-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= h($g['id']) ?>"><input type="hidden" name="dir" value="up"><button class="icon-btn" aria-label="Move up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= h($g['id']) ?>"><input type="hidden" name="dir" value="down"><button class="icon-btn" aria-label="Move down" <?= $i === count($games) - 1 ? 'disabled' : '' ?>>↓</button></form>
      <a class="btn" href="edit.php?id=<?= urlencode($g['id']) ?>">Edit</a>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$games): ?><p class="muted">No games yet. Add one to get started.</p><?php endif; ?>
</section>
<?php rs_foot();
