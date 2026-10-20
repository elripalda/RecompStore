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
            $t = count(array_filter(rs_games(false), fn($g) => !empty($g['testers'])));
            rs_flash("Published. The catalog now lists $n " . ($n === 1 ? 'game' : 'games') . ($soon ? " and $soon Coming soon" : '') . (rs_sign_on() ? '. It goes out once you sign it in Recomp Store Signer on your PC.' : '; the PS5 store and the site pick it up on their next load.') . ($t ? ' ' . ($t === 1 ? '1 app is' : "$t apps are") . ' for testers only and stay out of it.' : ''));
        } elseif ($action === 'catsort') {
            // (2.1) How the store and the site sort a category's page.
            $cat = rs_category((string)($_POST['cat'] ?? ''));
            $mode = (string)($_POST['sort'] ?? 'manual');
            if (!isset(RS_SORTS[$mode])) $mode = 'manual';
            $all = rs_category_sorts();
            $all[$cat] = $mode;
            rs_set_setting('category_sort', json_encode($all));
            rs_flash(RS_CATEGORIES[$cat] . ' is sorted ' . strtolower(RS_SORTS[$mode]) . ' now. Press Publish to send it to the store and the site.');
            header('Location: index.php?cat=' . urlencode($cat));
            exit;
        } elseif ($action === 'order') {
            // The whole list in its new order, from dragging on this page (Save order). (2.1) Or one
            // category's games: they swap places among themselves, the rest stays where it is.
            $want = json_decode((string)($_POST['order'] ?? ''), true);
            $cat = (string)($_POST['cat'] ?? '');
            $games = rs_games();
            if ($cat !== '' && isset(RS_CATEGORIES[$cat])) {
                $games = array_values(array_filter($games, fn($g) => rs_category((string)$g['category']) === $cat));
                $slots = array_map(fn($g) => (int)$g['sort'], $games);
                sort($slots);
                $ids = array_column($games, 'id');
                $ok = is_array($want) && count($want) === count($ids) && !array_diff($ids, array_map('strval', $want));
                if (!$ok) throw new RuntimeException('The order didn\'t match the games here (a game may have been added or removed meanwhile). Reload the page and try again.');
                $db = rs_db();
                $st = $db->prepare('UPDATE games SET sort = ? WHERE id = ?');
                $db->beginTransaction();
                foreach (array_values($want) as $k => $id) $st->execute([$slots[$k], $id]);
                $db->commit();
                rs_flash('Saved the order of ' . RS_CATEGORIES[$cat] . '. Press Publish to send it to the store and the site.');
                header('Location: index.php?cat=' . urlencode($cat));
                exit;
            }
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

$all = rs_games();
$cat = (string)($_GET['cat'] ?? '');
if (!isset(RS_CATEGORIES[$cat])) $cat = '';
$counts = array_fill_keys(array_keys(RS_CATEGORIES), 0);
foreach ($all as $g) $counts[rs_category((string)$g['category'])]++;
$games = $cat === '' ? $all : array_values(array_filter($all, fn($g) => rs_category((string)$g['category']) === $cat));
$sorts = rs_category_sorts();
$published = rs_setting('published_at');
rs_head('Games');
?>
<section class="head-row">
  <div>
    <h1><?= $cat === '' ? 'Everything in the store' : h(RS_CATEGORIES[$cat]) ?></h1>
    <p class="muted"><?= $published ? 'Last published ' . h(date('M j, Y g:i a', strtotime($published))) : 'Not published yet' ?>. Changes show in the store and on the site after you publish.</p>
  </div>
  <div class="actions">
    <a class="btn" href="edit.php<?= $cat !== '' ? '?category=' . urlencode($cat) : '' ?>">Add <?= $cat === '' || $cat === 'games' ? 'a game' : 'to ' . h(RS_CATEGORIES[$cat]) ?></a>
    <form method="post" data-publish-games><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish</button></form>
  </div>
</section>

<nav class="chips" aria-label="Category">
  <a class="chip<?= $cat === '' ? ' on' : '' ?>" href="index.php"<?= $cat === '' ? ' aria-current="page"' : '' ?>>Everything<span class="n"><?= count($all) ?></span></a>
  <?php foreach (RS_CATEGORIES as $k => $label): ?>
  <a class="chip<?= $cat === $k ? ' on' : '' ?>" href="index.php?cat=<?= h($k) ?>"<?= $cat === $k ? ' aria-current="page"' : '' ?>><?= h($label) ?><span class="n"><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
  <label class="find"><span class="sr">Find</span><input type="search" placeholder="Find by name, ID or author" data-find autocomplete="off"></label>
</nav>
<?php if ($cat !== ''): ?>
<form method="post" class="sortbar">
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="catsort"><input type="hidden" name="cat" value="<?= h($cat) ?>">
  <label>The store and the site sort <?= h(RS_CATEGORIES[$cat]) ?>
    <select name="sort" onchange="this.form.requestSubmit()">
      <?php foreach (RS_SORTS as $k => $label): ?><option value="<?= h($k) ?>"<?= ($sorts[$cat] ?? 'manual') === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select></label>
  <noscript><button class="btn">Save</button></noscript>
  <?php if (($sorts[$cat] ?? 'manual') !== 'manual'): ?><small class="muted">Your order below is kept for when you switch back to Your order.</small><?php endif; ?>
</form>
<?php endif; ?>
<?php if (count($games) > 1): ?><p class="muted order-tip">Drag a game by the handle on its left to move it, or use the arrows, then press Save order.<?= $cat !== '' ? ' Only ' . h(RS_CATEGORIES[$cat]) . ' swap places; the rest stay where they are.' : '' ?></p><?php endif; ?>
<section class="list" data-order>
<?php foreach ($games as $i => $g): ?>
  <article class="row<?= $g['hidden'] ? ' dim' : '' ?>" data-id="<?= h($g['id']) ?>" data-find-text="<?= h(mb_strtolower($g['name'] . ' ' . $g['id'] . ' ' . $g['author'])) ?>">
    <?php if (count($games) > 1): ?><button type="button" class="grip" data-grip aria-label="Move <?= h($g['name'] ?: 'Untitled') ?>: drag, or use the arrow keys" title="Drag to move"><svg width="14" height="22" viewBox="0 0 14 22" aria-hidden="true"><g fill="currentColor"><circle cx="3.5" cy="4" r="2"/><circle cx="10.5" cy="4" r="2"/><circle cx="3.5" cy="11" r="2"/><circle cx="10.5" cy="11" r="2"/><circle cx="3.5" cy="18" r="2"/><circle cx="10.5" cy="18" r="2"/></g></svg></button><?php endif; ?>
    <?php if ($g['icon']): ?><img class="icon" src="<?= h($g['icon']) ?>" alt=""><?php else: ?><span class="icon blank"><?= h(mb_substr($g['name'] ?: '?', 0, 2)) ?></span><?php endif; ?>
    <div class="meta">
      <h2><?= h($g['name'] ?: 'Untitled') ?></h2>
      <p>
        <span class="tag<?= $g['kind'] === 'original' ? ' cyan' : '' ?>"><?= $g['kind'] === 'original' ? 'Recomp Store' : 'By ' . h($g['author']) ?></span>
        <?php if ($g['featured']): ?><span class="tag">Featured</span><?php endif; ?>
        <?php if ($g['hidden']): ?><span class="tag">Hidden</span><?php endif; ?>
        <?php if (!empty($g['testers'])): ?><span class="tag test">Testers only</span><?php endif; ?>
        <?php if ($cat === '' && rs_category((string)$g['category']) !== 'games'): ?><span class="tag"><?= h(RS_CATEGORIES[rs_category((string)$g['category'])]) ?></span><?php endif; ?>
        <?php if ($g['beta']): ?><span class="tag beta">Beta</span><?php endif; ?>
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
<?php if (!$games): ?><p class="muted"><?= $cat === '' ? 'No games yet. Add one to get started.' : 'Nothing in ' . h(RS_CATEGORIES[$cat]) . ' yet.' ?></p><?php endif; ?>
<p class="muted" data-find-none hidden>Nothing matches.</p>
</section>
<form method="post" class="sticky-save order-bar" data-order-bar hidden>
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="action" value="order">
  <input type="hidden" name="cat" value="<?= h($cat) ?>">
  <input type="hidden" name="order" value="">
  <button class="primary">Save order</button>
  <button type="button" data-order-undo>Undo</button>
  <span class="muted">The new order isn't saved yet. Saving doesn't publish.</span>
</form>
<?php rs_foot(['assets/games.js']);
