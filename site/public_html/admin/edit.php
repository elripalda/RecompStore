<?php
// Recomp Store admin: add or edit one game.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

$oldId = isset($_GET['id']) ? (string)$_GET['id'] : null;
$g = $oldId !== null ? rs_game($oldId) : null;
if ($oldId !== null && !$g) { rs_flash('That game isn\'t in the admin.', 'err'); header('Location: index.php'); exit; }
$isNew = !$g;
$g = $g ?: rs_game_defaults();
$error = '';

$slotTemplate = json_encode([
    ['key' => 'game', 'label' => 'Game disc', 'kind' => 'iso', 'to' => 'game', 'required' => true],
    ['key' => 'update', 'label' => 'Title Update', 'kind' => 'stfs', 'to' => 'update', 'required' => true],
    ['key' => 'dlc', 'label' => 'DLC (optional)', 'kind' => 'stfs', 'to' => 'dlc', 'required' => false, 'multiple' => true],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $text = ['id', 'name', 'author', 'blurb', 'description', 'news', 'credits', 'version', 'released', 'size', 'needs',
             'repo', 'asset', 'inside', 'url', 'sha256', 'data_dir', 'slots', 'min_store', 'date', 'saves'];
    foreach ($text as $k) $g[$k] = trim((string)($_POST[$k] ?? ''));
    $g['kind'] = ($_POST['kind'] ?? '') === 'community' ? 'community' : 'original';
    $g['source'] = ($_POST['source'] ?? '') === 'url' ? 'url' : 'github';
    $g['build'] = max(1, (int)($_POST['build'] ?? 1));
    foreach (['focus_x', 'focus_y'] as $k) $g[$k] = max(0, min(100, (int)($_POST[$k] ?? 50)));
    $g['zoom'] = max(100, min(250, (int)($_POST['zoom'] ?? 100)));
    foreach (['logo_bx', 'logo_px'] as $k) $g[$k] = max(-1200, min(1200, (int)($_POST[$k] ?? 0)));
    foreach (['logo_by', 'logo_py'] as $k) $g[$k] = max(-600, min(600, (int)($_POST[$k] ?? 0)));
    foreach (['logo_bs', 'logo_ps'] as $k) $g[$k] = max(20, min(300, (int)($_POST[$k] ?? 100)));
    if (!empty($_POST['logo_remove'])) $g['logo'] = '';
    foreach (['featured', 'hidden', 'setup', 'soon'] as $k) $g[$k] = !empty($_POST[$k]);
    $badSave = null;
    rs_saves_list($g['saves'], $badSave);

    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $g['id'])) $error = 'The title ID must be 3 to 32 letters, numbers, - or _ (for example PPSA92011).';
    elseif ($g['name'] === '') $error = 'Give the game a name.';
    elseif (mb_strlen($g['date']) > 40) $error = 'Keep the Coming soon date short, like December 2026.';
    // Coming soon: no download yet, so the repo and link are optional (but well formed when given).
    elseif ($g['source'] === 'github' && !($g['soon'] && $g['repo'] === '') && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $g['repo'])) $error = 'Enter the GitHub repo as owner/name, for example elripalda/SvR2011-Recomp-PS5.';
    elseif ($g['source'] === 'url' && !($g['soon'] && $g['url'] === '') && !preg_match('#^https://#', $g['url'])) $error = 'The download link has to start with https://';
    elseif ($badSave !== null) $error = 'Save folders: "' . $badSave . '" isn\'t a folder inside the game\'s (one per line, like userdata, with no .. or \\).';
    elseif ($g['sha256'] !== '' && !preg_match('/^[A-Fa-f0-9]{64}$/', $g['sha256'])) $error = 'The SHA-256 should be 64 letters and numbers (0-9, a-f), or left empty.';
    elseif ($g['min_store'] !== '' && !preg_match('/^\d+(\.\d+){0,3}$/', $g['min_store'])) $error = 'Needs Recomp Store: a version like 1.0.3, or leave it empty.';
    elseif ($g['source'] === 'github' && strpbrk($g['asset'], '*?') !== false && !preg_match('/\.zip$/i', $g['asset'])) $error = 'A zip name with * has to end in .zip, so it can only match the release\'s zip (not a checksum or another file).';
    elseif ($g['inside'] !== '' && (!preg_match('/\.zip$/i', $g['inside']) || strlen($g['inside']) > 300 || $g['inside'][0] === '/' || preg_match('#\\\\|(^|/)\.\.(/|$)|[\x00-\x1f\x7f]#', $g['inside']))) $error = 'The zip inside the download: its whole name in that zip, ending in .zip, with / between folders (for example */prospero/builder/Assets/payload.zip). No .. and no \\.';
    elseif ($g['setup'] && $g['slots'] !== '' && !is_array(json_decode($g['slots'], true))) $error = 'The game files list isn\'t valid. Use the template\'s format.';
    elseif ($g['setup'] && $g['slots'] !== '' && ($slotErr = rs_slots_error(json_decode($g['slots'], true)))) $error = 'Game files: ' . $slotErr;
    elseif (($isNew || $g['id'] !== $oldId) && rs_game($g['id'])) $error = 'Another game already uses that title ID.';

    if ($error === '') {
        try {
            foreach (['icon_file' => 'icon', 'hero_file' => 'hero', 'logo_file' => 'logo'] as $field => $kind) {
                if (!empty($_FILES[$field]['name'])) $g = array_merge($g, rs_save_image($_FILES[$field], $kind, $g['id']));
            }
            rs_save_game($g, $isNew ? null : $oldId);
            rs_flash('Saved ' . $g['name'] . '. Press Publish to send it to the store and the site.');
            header('Location: edit.php?id=' . urlencode($g['id']));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

rs_head($isNew ? 'Add a game' : $g['name']);
$hero = $g['hero_web'] ?: $g['hero'];
?>
<section class="head-row">
  <div><a class="back" href="index.php">← Games</a><h1><?= $isNew ? 'Add a game' : h($g['name']) ?></h1></div>
  <?php if (!$isNew): ?>
  <form method="post" action="index.php" onsubmit="return confirm('Remove <?= h(addslashes($g['name'])) ?> from the admin?');">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($g['id']) ?>">
    <button class="btn danger">Remove game</button>
  </form>
  <?php endif; ?>
</section>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="edit">
<input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">

<section class="card">
  <h2>Basics</h2>
  <div class="grid2">
    <label>Name<input name="name" value="<?= h($g['name']) ?>" required></label>
    <label>Title ID<input name="id" value="<?= h($g['id']) ?>" required placeholder="PPSA92011"><small>The folder name on the PS5, /data/homebrew/&lt;title ID&gt;.</small></label>
    <label>Type
      <select name="kind">
        <option value="original" <?= $g['kind'] === 'original' ? 'selected' : '' ?>>Recomp Store</option>
        <option value="community" <?= $g['kind'] === 'community' ? 'selected' : '' ?>>Community (someone else's port)</option>
      </select>
    </label>
    <label>Author<input name="author" value="<?= h($g['author']) ?>"><small>Shown under community games.</small></label>
    <label class="span2">Short line<input name="blurb" value="<?= h($g['blurb']) ?>" maxlength="180"><small>One sentence for the featured banner.</small></label>
    <label class="span2">Description<textarea name="description" rows="4"><?= h($g['description']) ?></textarea></label>
    <label class="span2">What's new<textarea name="news" rows="3"><?= h($g['news']) ?></textarea></label>
    <label class="span2">Credits<input name="credits" value="<?= h($g['credits']) ?>" placeholder="PS5 port by elripalda · PC recompilation by …"></label>
    <label>You bring<input name="needs" value="<?= h($g['needs']) ?>" placeholder="Xbox 360 ISO"></label>
    <div class="checks">
      <label class="check"><input type="checkbox" name="featured" <?= $g['featured'] ? 'checked' : '' ?>> Featured on the banner</label>
      <label class="check"><input type="checkbox" name="hidden" <?= $g['hidden'] ? 'checked' : '' ?>> Hidden (kept here, left out when publishing)</label>
      <label class="check"><input type="checkbox" name="soon" data-soon <?= $g['soon'] ? 'checked' : '' ?>> Coming soon (not out yet)</label>
    </div>
    <label class="span2" data-soon-fields><span>When it's coming <span class="muted">(optional)</span></span><input name="date" value="<?= h($g['date']) ?>" maxlength="40" placeholder="December 2026"><small>Shown in place of a version, in the store's Coming soon row and on the site. Leave empty for just "Coming soon". A Coming soon game needs only its name, title ID and art; untick it when it's out and fill in Download.</small></label>
  </div>
</section>

<section class="card" data-not-soon>
  <h2>Download</h2>
  <div class="grid2">
    <label>Where the zip comes from
      <select name="source" data-source>
        <option value="github" <?= $g['source'] === 'github' ? 'selected' : '' ?>>GitHub releases (always the newest)</option>
        <option value="url" <?= $g['source'] === 'url' ? 'selected' : '' ?>>A fixed download link</option>
      </select>
    </label>
    <label data-gh>GitHub repo<input name="repo" value="<?= h($g['repo']) ?>" placeholder="elripalda/SvR2011-Recomp-PS5"></label>
    <label data-gh>Zip name on the release<input name="asset" value="<?= h($g['asset']) ?>" placeholder="PPSA92011.zip"><small>The file the store downloads from each release. When its name changes with each version, put * for that part, like WindWaker-PS5-*.zip: the store then finds the newest itself (needs Recomp Store 1.0.3, set for you).</small></label>
    <label data-url>Download link<input name="url" value="<?= h($g['url']) ?>" placeholder="https://…/PPSA92011.zip"></label>
    <label class="span2">Zip inside the download (optional)<input name="inside" value="<?= h($g['inside']) ?>" placeholder="*/prospero/builder/Assets/payload.zip"><small>Only for a release that has no zip of its own PS5 files, just a bigger one (like a source zip) with that zip inside. Put its whole name inside the download, with * for a part that changes with each version. The store takes it out and installs it (needs Recomp Store 1.0.4, set for you). Leave empty for a normal release.</small></label>
    <label>Version<input name="version" value="<?= h($g['version']) ?>" placeholder="1.0.0"><small>Shown until the store and the site have read GitHub; updates come from GitHub's newest release.</small></label>
    <label>Needs Recomp Store<input name="min_store" value="<?= h($g['min_store']) ?>" placeholder="any version"><small>The oldest store that can install it; older ones are told to update first (before 1.0.3 they don't list it at all). <?php $auto = rs_min_store(array_merge($g, ['min_store' => ''])); echo $auto !== '' ? 'This game needs at least ' . h($auto) . ' for its zip name or its game files.' : 'Leave empty for any.'; ?></small></label>
    <label>Build<input type="number" min="1" name="build" value="<?= h((string)$g['build']) ?>"></label>
    <label>Release date<input type="date" name="released" value="<?= h($g['released']) ?>"></label>
    <label>Download size<input name="size" value="<?= h($g['size']) ?>" placeholder="55 MB"></label>
    <label class="span2">Zip SHA-256 (optional)<input name="sha256" value="<?= h($g['sha256']) ?>" placeholder="64 characters"><small>For your own games: the store refuses a download that doesn't match. Community games use GitHub's own check.</small></label>
  </div>
</section>

<section class="card">
  <h2>Art</h2>
  <div class="art">
    <div>
      <label>Icon<input type="file" name="icon_file" accept="image/png,image/jpeg,image/webp" data-preview="icon"><small>Square, 512×512 or larger. Cropped to the center square.</small></label>
      <div class="tile-preview"><img data-icon src="<?= h($g['icon']) ?>" alt="" <?= $g['icon'] ? '' : 'hidden' ?>></div>
    </div>
    <div class="grow">
      <label>Backdrop<input type="file" name="hero_file" accept="image/png,image/jpeg,image/webp" data-preview="hero"><small>16:9, ideally 3840×2160. Used for the banner, the game page and the store's blurred background.</small></label>
      <p class="muted">Drag the picture to frame it in the banner; the slider zooms. This is exactly what the PS5 banner shows.</p>
      <div class="banner-preview" data-frame>
        <div class="glass"><span class="kicker">FEATURED</span><strong data-name><?= h($g['name'] ?: 'Game name') ?></strong></div>
        <div class="window"><img data-hero src="<?= h($hero) ?>" alt="" draggable="false" <?= $hero ? '' : 'hidden' ?>></div>
      </div>
      <div class="grid3">
        <label>Zoom <output data-out="zoom"><?= (int)$g['zoom'] ?>%</output><input type="range" name="zoom" min="100" max="250" value="<?= (int)$g['zoom'] ?>" data-range></label>
        <label>Left / right <output data-out="focus_x"><?= (int)$g['focus_x'] ?>%</output><input type="range" name="focus_x" min="0" max="100" value="<?= (int)$g['focus_x'] ?>" data-range></label>
        <label>Up / down <output data-out="focus_y"><?= (int)$g['focus_y'] ?>%</output><input type="range" name="focus_y" min="0" max="100" value="<?= (int)$g['focus_y'] ?>" data-range></label>
      </div>
    </div>
  </div>
</section>

<section class="card" data-logo-card>
  <h2>Logo</h2>
  <p class="muted">Optional. A logo (a PNG with a transparent background) takes the place of the game's name on the featured banner and at the top of its page. Drag it in each preview to move it, and use the slider to size it. The previews are the store's own layout.</p>
  <div class="logo-file">
    <div class="thumb"><img data-logo-img src="<?= h($g['logo']) ?>" alt="" <?= $g['logo'] ? '' : 'hidden' ?>></div>
    <div>
      <label>Logo image<input type="file" name="logo_file" accept="image/png,image/webp"><small>PNG or WebP with transparency, up to 1600×800.</small></label>
      <?php if ($g['logo']): ?><label class="check"><input type="checkbox" name="logo_remove"> Remove the logo (show the name again)</label><?php endif; ?>
    </div>
  </div>
  <div data-logo-editors <?= $g['logo'] ? '' : 'hidden' ?>>
    <h3 class="sub">Featured banner</h3>
    <div class="stage-wrap" data-stage="banner" data-w="1728" data-h="400"></div>
    <div class="logo-tools">
      <label>Size <output data-out="logo_bs"><?= (int)$g['logo_bs'] ?>%</output><input type="range" name="logo_bs" min="20" max="300" value="<?= (int)$g['logo_bs'] ?>" data-logo-range></label>
      <span class="muted">Moved <output data-out="logo_bxy"><?= (int)$g['logo_bx'] ?>, <?= (int)$g['logo_by'] ?></output></span>
      <button type="button" data-logo-reset="b">Reset</button>
    </div>
    <h3 class="sub">Game page</h3>
    <div class="stage-wrap" data-stage="page" data-w="1920" data-h="1080"></div>
    <div class="logo-tools">
      <label>Size <output data-out="logo_ps"><?= (int)$g['logo_ps'] ?>%</output><input type="range" name="logo_ps" min="20" max="300" value="<?= (int)$g['logo_ps'] ?>" data-logo-range></label>
      <span class="muted">Moved <output data-out="logo_pxy"><?= (int)$g['logo_px'] ?>, <?= (int)$g['logo_py'] ?></output></span>
      <button type="button" data-logo-reset="p">Reset</button>
    </div>
    <?php foreach (['logo_bx', 'logo_by', 'logo_px', 'logo_py'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$g[$k] ?>"><?php endforeach; ?>
  </div>
  <div hidden data-page-text data-blurb="<?= h($g['blurb'] ?: $g['description']) ?>" data-desc="<?= h($g['description']) ?>" data-id="<?= h($g['id']) ?>" data-kind="<?= h($g['kind']) ?>" data-author="<?= h($g['author']) ?>" data-needs="<?= h($g['needs']) ?>"></div>
</section>

<section class="card" data-not-soon>
  <h2>Game files</h2>
  <label class="check"><input type="checkbox" name="setup" data-setup <?= $g['setup'] ? 'checked' : '' ?>> Recomp Store sets this game up from the player's own files (disc image, Title Update, DLC, a ROM or data files)</label>
  <div class="grid2" data-setup-fields>
    <label class="span2">Where the port reads its files<input name="data_dir" value="<?= h($g['data_dir']) ?>" placeholder="/data/homebrew/PPSA92011"></label>
    <label class="span2">Files to ask for<textarea name="slots" rows="9" class="mono"><?= h($g['slots'] !== '' ? $g['slots'] : $slotTemplate) ?></textarea>
      <small>One entry per file: what it's called, its kind, and the folder it goes in, relative to the folder above ("." means right in it). Kinds: iso (an Xbox 360 disc image, unpacked), stfs (a Title Update or DLC package from the console), image (a GameCube or Wii disc image copied as it is, .iso/.gcm/.ciso; needs Recomp Store 1.0.3). titleId (Xbox 360) or discId (GameCube/Wii, like GZLE01) is optional: when set, the store refuses a disc that isn't this game.</small>
      <small><strong>file</strong> (needs Recomp Store 1.0.6, set for you): the player's own file copied in, such as a ROM or a game's data files. <code>ext</code> the extensions to show (".z64 .n64 .v64"); <code>pick</code> the file to choose ("pak0.pk3"); <code>also</code> the files beside it that go too ("pak?.pk3"); <code>need</code> how many must be there to play (9); <code>name</code> what to call it in the game's folder ("DIABDAT.MPQ"); <code>sha1</code> the SHA-1s it may have, space-separated; <code>n64</code> true for a Nintendo 64 ROM (any byte order, saved as .z64); <code>made</code> a file the game makes from it, which counts as set up ("sm64.o2r").</small></label>
  </div>
  <label><span>Save folders <span class="muted">(optional)</span></span><textarea name="saves" rows="2" class="mono" placeholder="userdata"><?= h($g['saves']) ?></textarea>
    <small>Where the game keeps its saves, one per line, inside its folder (like userdata, or baseq3/q3config.cfg). With these, Delete in Recomp Store 1.0.6 offers Keep my saves: everything else goes, and the saves are there when it's installed again.</small></label>
</section>

<div class="sticky-save"><button class="primary">Save</button><span class="muted">Saving doesn't publish. Press Publish on the Games page when you're done.</span></div>
</form>
<?php rs_foot(['assets/admin.js']);
