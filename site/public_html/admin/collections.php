<?php
// Recomp Store admin (2.3.0): collections, a name, a line about it and apps in order. The store shows
// one as a Discover row (a Collection section) and as its own page with Install all.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    try {
        $in = json_decode((string)($_POST['collections'] ?? ''), true);
        if (!is_array($in)) throw new RuntimeException('The collections didn\'t arrive. Reload the page and try again.');
        // A new one gets its id from its name, once: Discover's sections keep pointing at it after a rename.
        $taken = [];
        foreach ($in as $c) if (is_array($c) && is_string($c['id'] ?? null) && $c['id'] !== '') $taken[$c['id']] = true;
        foreach ($in as &$c) {
            if (!is_array($c) || (is_string($c['id'] ?? null) && $c['id'] !== '')) continue;
            $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($c['title'] ?? ''))), '-') ?: 'collection';
            $base = substr($base, 0, 40);
            $id = $base;
            for ($n = 2; isset($taken[$id]); ++$n) $id = $base . '-' . $n;
            $taken[$id] = true;
            $c['id'] = $id;
        }
        unset($c);
        $clean = rs_collections_clean($in);
        rs_set_setting('collections', json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $n = count($clean);
        rs_log('collections', 'saved', $n . ' collection' . ($n === 1 ? '' : 's') . ($clean ? ': ' . implode(', ', array_column($clean, 'title')) : ''));
        rs_flash("Saved $n " . ($n === 1 ? 'collection' : 'collections') . '. Add one to Discover as a Collection section, then Publish.');
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: collections.php');
    exit;
}

$games = [];
foreach (rs_games() as $g) {
    if ($g['hidden'] || $g['soon'] || $g['beta']) continue;
    $games[] = ['id' => $g['id'], 'name' => $g['name'], 'icon' => $g['icon'], 'testers' => !empty($g['testers'])];
}
rs_head('Collections');
?>
<section class="head-row">
  <div>
    <h1>Collections</h1>
    <p class="muted">Apps that go together, like "Racing night" or "Start here". The store shows a collection as a row on Discover and as its own page, where Install all puts every one the player doesn't have in the queue. Recomp Store 2.3.0 and newer.</p>
  </div>
  <div class="actions">
    <a class="btn" href="discover.php">Discover</a>
    <form method="post" action="index.php" data-publish><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish</button></form>
  </div>
</section>

<div class="colls" data-colls></div>
<div class="add-row"><button type="button" data-add-coll>New collection</button></div>
<p class="muted small-note">Only apps that are out for everyone can go in (not hidden, Coming soon or beta). A testers-only app shows only on testers' consoles. A collection with nothing listed in it is left out of the store.</p>

<form method="post" class="sticky-save" data-save>
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="collections" value="">
  <button class="primary">Save</button>
  <span class="muted" data-save-note>Saving doesn't publish. Press Publish when you're done.</span>
</form>
<script>window.RS_COLLECTIONS = <?= json_encode(['games' => $games, 'collections' => rs_collections()], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/collections.js']);
