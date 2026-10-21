<?php
// Recomp Store admin: Discover, the store's first screen and the site's home page, as
// sections (rows, grids, wide tiles, a spotlight, Coming soon), with a live preview.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    try {
        if (($_POST['action'] ?? '') === 'reset') {
            rs_db()->prepare('DELETE FROM settings WHERE k = ?')->execute(['discover_sections']);
            rs_flash('Discover is back to the default: every game in a grid, then Coming soon. Press Publish to send it out.');
        } else {
            $in = json_decode((string)($_POST['sections'] ?? ''), true);
            if (!is_array($in)) throw new RuntimeException('The sections didn\'t arrive. Reload the page and try again.');
            $clean = rs_clean_sections($in);
            rs_set_setting('discover_sections', json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            // (2.1) The header: its style, its games and how often it turns.
            $hd = json_decode((string)($_POST['header'] ?? ''), true);
            if (is_array($hd)) {
                rs_set_setting('discover_header', json_encode([
                    'style' => isset(RS_HEADER_STYLES[$hd['style'] ?? '']) ? $hd['style'] : 'carousel',
                    'games' => array_slice(array_values(array_filter(array_map('strval', (array)($hd['games'] ?? [])), fn($id) => (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id))), 0, 12),
                    'interval' => max(4, min(30, (int)($hd['interval'] ?? 8))),
                ]));
            }
            $n = count($clean);
            // (2.2) The change log says which charts show only one part of the store.
            $only = [];
            foreach ($clean as $s) if (isset($s['category']) && in_array($s['type'], ['top', 'trending', 'new', 'updated', 'liked'], true)) $only[] = ($s['title'] !== '' ? $s['title'] : $s['type']) . ': ' . RS_CATEGORIES[$s['category']];
            rs_log('discover', 'sections', $n . ' section' . ($n === 1 ? '' : 's') . ($only ? '; only one part: ' . implode(', ', $only) : ''));
            rs_flash("Saved Discover ($n " . ($n === 1 ? 'section' : 'sections') . '). Press Publish to send it to the store and the site.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: discover.php');
    exit;
}

// What the editor and the preview need of each game.
$games = [];
foreach (rs_games() as $g) {
    $games[] = [
        'id' => $g['id'], 'name' => $g['name'], 'kind' => $g['kind'], 'author' => $g['author'],
        'blurb' => $g['blurb'] ?: $g['description'], 'version' => $g['version'],
        'icon' => $g['icon'], 'hero' => $g['hero_web'] ?: $g['hero'],
        'x' => (int)$g['focus_x'], 'y' => (int)$g['focus_y'], 'zoom' => (int)$g['zoom'],
        'featured' => (bool)$g['featured'], 'hidden' => (bool)$g['hidden'], 'soon' => (bool)$g['soon'], 'date' => $g['date'],
        'category' => rs_category((string)$g['category']), 'testers' => !empty($g['testers']),
    ];
}
$state = ['games' => $games, 'sections' => rs_discover_sections(), 'saved' => rs_setting('discover_sections') !== null, 'categories' => RS_CATEGORIES,
    'header' => rs_discover_header(), 'headerStyles' => RS_HEADER_STYLES, 'collections' => rs_collections()];
rs_head('Discover');
?>
<section class="head-row">
  <div>
    <h1>Discover</h1>
    <p class="muted">The store's first screen, and the site's home page, top to bottom under the featured banner. Add sections, pick their layout and games, and watch the preview.</p>
  </div>
  <div class="actions">
    <form method="post" onsubmit="return confirm('Go back to the default (every game in a grid, then Coming soon)?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="reset"><button>Reset to default</button></form>
    <form method="post" action="index.php" data-publish><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish</button></form>
  </div>
</section>

<div class="disc">
  <div class="disc-edit">
    <section class="d-header-edit" data-header aria-labelledby="hdr-title">
      <h2 id="hdr-title">Header</h2>
      <p class="muted">How the featured games show at the top of Discover. Recomp Store 2.1 and newer; older stores keep the carousel.</p>
      <div class="hstyles" role="radiogroup" aria-label="Header style" data-hstyles></div>
      <div class="hgames" data-hgames></div>
      <label class="hint" data-hinterval>Changes every <input type="range" min="4" max="30" step="1" data-interval> <output data-interval-out></output> seconds</label>
    </section>
    <div class="sections" data-sections></div>
    <div class="add-row">
      <span class="muted">Add</span>
      <button type="button" data-add="games">Games</button>
      <button type="button" data-add="soon">Coming soon</button>
      <button type="button" data-add="spotlight">Spotlight</button>
      <button type="button" data-add="category">Category</button>
      <button type="button" data-add="top">Top charts</button>
      <button type="button" data-add="trending">Trending</button>
      <button type="button" data-add="new">New</button>
      <button type="button" data-add="updated">Recently updated</button>
      <button type="button" data-add="liked">Most liked</button>
      <button type="button" data-add="collection">Collection</button>
    </div>
    <p class="muted small-note">Games: a row (seven at a time, scrolls sideways), a grid (every one, seven to a line) or wide tiles (three at a time, the backdrop art). Coming soon: the games marked Coming soon. Spotlight: one game as a still banner. Category: every game in one part of the store (Games, Emulators, Apps, Tools), from Recomp Store 2.0; older stores leave it out. Top charts, Trending, New and Recently updated fill themselves in from installs and GitHub's releases (2.0 and newer too). Most liked fills itself in from thumbs-up, and Collection shows one of your collections (both 2.3.0 and newer). Stores older than 1.0.6 keep their own single row.</p>
  </div>
  <div class="disc-preview">
    <div class="preview-head"><h2>Preview</h2><span class="muted">The store on a TV. Above the dashed line shows first; the rest scrolls up.</span></div>
    <div class="preview-scroll"><div class="stage-wrap" data-preview></div></div>
  </div>
</div>

<form method="post" class="sticky-save" data-save>
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="sections" value="">
  <input type="hidden" name="header" value="">
  <button class="primary">Save</button>
  <span class="muted" data-save-note>Saving doesn't publish. Press Publish when you're done.</span>
</form>
<script>window.RS_DISCOVER = <?= json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/discover.js']);
