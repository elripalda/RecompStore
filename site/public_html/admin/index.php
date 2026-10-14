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
        } elseif ($action === 'order') {
            // The whole list in its new order, from dragging on this page (Save order).
            $want = json_decode((string)($_POST['order'] ?? ''), true);
            $ids = array_column(rs_games(), 'id');
            $ok = is_array($want) && count($want) === count($ids) && count(array_unique(array_map('strval', $want))) === count($want);
            if ($ok) foreach ($want as $id) if (!is_string($id) || !in_array($id, $ids, true)) $ok = false;
            if (!$ok) throw new RuntimeException('The order didn\'t match the games here (a game may have been added or removed meanwhile). Reload the page and try again.');
            $db = rs_db();
            $st = $db->prepare('UPDATE games SET sort = ? WHERE id = ?');
            $db->beginTransaction();
            foreach (array_values($want) as $k => $id) $st->execute([($k + 1) * 10, $id]);
            $db->commit();
            rs_flash('Saved the order. Press Publish to send it to the store and the site.');
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
    <form method="post" data-publish-games><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish</button></form>
  </div>
</section>

<?php if (count($games) > 1): ?><p class="muted order-tip">Drag a game by the handle on its left to move it, or use the arrows, then press Save order.</p><?php endif; ?>
<section class="list" data-order>
<?php foreach ($games as $i => $g): ?>
  <article class="row<?= $g['hidden'] ? ' dim' : '' ?>" data-id="<?= h($g['id']) ?>">
    <?php if (count($games) > 1): ?><button type="button" class="grip" data-grip aria-label="Move <?= h($g['name'] ?: 'Untitled') ?>: drag, or use the arrow keys" title="Drag to move"><svg width="14" height="22" viewBox="0 0 14 22" aria-hidden="true"><g fill="currentColor"><circle cx="3.5" cy="4" r="2"/><circle cx="10.5" cy="4" r="2"/><circle cx="3.5" cy="11" r="2"/><circle cx="10.5" cy="11" r="2"/><circle cx="3.5" cy="18" r="2"/><circle cx="10.5" cy="18" r="2"/></g></svg></button><?php endif; ?>
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
      <?php if (count($games) > 1): ?>
      <button type="button" class="icon-btn" data-move="top" aria-label="Move to the top" title="Move to the top" <?= $i === 0 ? 'disabled' : '' ?>>⤒</button>
      <button type="button" class="icon-btn" data-move="up" aria-label="Move up" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
      <button type="button" class="icon-btn" data-move="down" aria-label="Move down" title="Move down" <?= $i === count($games) - 1 ? 'disabled' : '' ?>>↓</button>
      <?php endif; ?>
      <a class="btn" href="edit.php?id=<?= urlencode($g['id']) ?>">Edit</a>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$games): ?><p class="muted">No games yet. Add one to get started.</p><?php endif; ?>
</section>
<form method="post" class="sticky-save order-bar" data-order-bar hidden>
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="action" value="order">
  <input type="hidden" name="order" value="">
  <button class="primary">Save order</button>
  <button type="button" data-order-undo>Undo</button>
  <span class="muted">The new order isn't saved yet. Saving doesn't publish.</span>
</form>
<?php rs_foot(['assets/games.js']);
