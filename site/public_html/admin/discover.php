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
            $n = count($clean);
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
$state = ['games' => $games, 'sections' => rs_discover_sections(), 'saved' => rs_setting('discover_sections') !== null, 'categories' => RS_CATEGORIES];
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
    </div>
    <p class="muted small-note">Games: a row (seven at a time, scrolls sideways), a grid (every one, seven to a line) or wide tiles (three at a time, the backdrop art). Coming soon: the games marked Coming soon. Spotlight: one game as a still banner. Category: every game in one part of the store (Games, Emulators, Apps, Tools), from Recomp Store 2.0; older stores leave it out. Top charts, Trending, New and Recently updated fill themselves in from installs and GitHub's releases (2.0 and newer too). Stores older than 1.0.6 keep their own single row.</p>
  </div>
  <div class="disc-preview">
    <div class="preview-head"><h2>Preview</h2><span class="muted">The store on a TV. Above the dashed line shows first; the rest scrolls up.</span></div>
    <div class="preview-scroll"><div class="stage-wrap" data-preview></div></div>
  </div>
</div>

<form method="post" class="sticky-save" data-save>
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="sections" value="">
  <button class="primary">Save</button>
  <span class="muted" data-save-note>Saving doesn't publish. Press Publish when you're done.</span>
</form>
<script>window.RS_DISCOVER = <?= json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/discover.js']);
