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
$orig = $g; // (2.1) as saved: the beta build a new zip replaces goes into its history
// (2.1) "Add to Emulators" etc. from a category's list starts in that category.
if ($oldId === null && isset($_GET['category'], RS_CATEGORIES[(string)$_GET['category']])) $g['category'] = (string)$_GET['category'];
$error = '';

$slotTemplate = json_encode([
    ['key' => 'game', 'label' => 'Game disc', 'kind' => 'iso', 'to' => 'game', 'required' => true],
    ['key' => 'update', 'label' => 'Title Update', 'kind' => 'stfs', 'to' => 'update', 'required' => true],
    ['key' => 'dlc', 'label' => 'DLC (optional)', 'kind' => 'stfs', 'to' => 'dlc', 'required' => false, 'multiple' => true],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
// (2.3.0) What the Mods box shows when it's empty.
$modsTemplate = json_encode([['id' => 'hd-pack', 'name' => 'HD textures', 'author' => 'someone', 'blurb' => 'Sharper textures.',
    'repo' => 'owner/name', 'asset' => 'v1.0/hd-pack.zip', 'sha256' => str_repeat('0', 64), 'size' => 1048576, 'target' => 'hd-pack']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $text = ['id', 'name', 'author', 'blurb', 'description', 'news', 'credits', 'version', 'released', 'size', 'needs',
             'repo', 'asset', 'inside', 'zip_id', 'url', 'sha256', 'data_dir', 'slots', 'min_store', 'date', 'saves', 'logs', 'keep'];
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
    // (2.0) Its part of the store, its labels, and whether the store may move it to a new title ID.
    $g['category'] = rs_category((string)($_POST['category'] ?? 'games'));
    $g['tags'] = trim((string)($_POST['tags'] ?? ''));
    $g['can_move'] = !empty($_POST['can_move']);
    // (2.0) Who gets it: everyone (once published), or only consoles marked as testers.
    $g['testers'] = ($_POST['visible'] ?? '') === 'testers';
    $badTag = '';
    foreach (explode(',', $g['tags']) as $t) if (mb_strlen(trim($t)) > 24) $badTag = trim($t);
    if ($badTag === '' && count(array_filter(array_map('trim', explode(',', $g['tags'])), 'strlen')) > 6) $badTag = '(more than 6)';
    $badSave = null;
    rs_saves_list($g['saves'], $badSave);
    $badLog = null;
    rs_logs_list($g['logs'], $badLog);
    $badKeep = null;
    rs_keep_list($g['keep'], $badKeep);
    // (2.3.0) What it needs to be ready to play, and its mods.
    $g['requires'] = rs_requires_clean((array)($_POST['requires'] ?? []));
    $g['mods_dir'] = trim((string)($_POST['mods_dir'] ?? ''), " /");
    $g['mods'] = trim(str_replace("\r\n", "\n", (string)($_POST['mods'] ?? '')));
    $badMods = null;
    $cleanMods = rs_mods_clean($g['mods'], $badMods);
    if ($badMods === null) $g['mods'] = $cleanMods ? json_encode($cleanMods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    // Extra buttons: rows of text, link and color; an empty row is dropped, a half-filled one is an error.
    $linkRows = [];
    $badLink = '';
    $lt = (array)($_POST['link_label'] ?? []); $lu = (array)($_POST['link_url'] ?? []); $lc = (array)($_POST['link_color'] ?? []);
    foreach ($lt as $i => $label) {
        $row = ['label' => trim((string)$label), 'url' => trim((string)($lu[$i] ?? '')), 'color' => trim((string)($lc[$i] ?? ''))];
        if ($row['label'] === '' && $row['url'] === '') continue;
        if ($row['label'] === '') $badLink = 'Extra buttons: give the button for ' . $row['url'] . ' some text.';
        elseif (!preg_match('#^https://\S+$#', $row['url'])) $badLink = 'Extra buttons: "' . $row['label'] . '" needs a link that starts with https://';
        $linkRows[] = $row;
    }
    if (count($linkRows) > 12) $badLink = 'Extra buttons: 12 at most.';
    $g['links'] = $badLink === '' ? rs_clean_links($linkRows) : $linkRows;
    // Beta: only testers with a code get it; its zip is kept on this site.
    $g['beta'] = !empty($_POST['beta']);
    $g['beta_version'] = trim((string)($_POST['beta_version'] ?? ''));
    // (2.1) What's new in this build, for testers (shown on the game's page in the store).
    $g['beta_notes'] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['beta_notes'] ?? ''))), 0, 4000);
    $pickedBeta = basename(trim((string)($_POST['beta_existing'] ?? '')));
    if ($pickedBeta !== '' && isset(rs_beta_files()[$pickedBeta]) && $pickedBeta !== $g['beta_file']) {
        $g['beta_file'] = $pickedBeta; $g['beta_sha256'] = ''; $g['beta_size'] = 0;
    }
    // (2.1) Earlier builds the admin no longer offers to testers (the zips stay in the folder).
    $forget = array_map('basename', array_map('strval', (array)($_POST['beta_forget'] ?? [])));
    $g['beta_history'] = array_values(array_filter((array)$g['beta_history'], fn($h) => is_array($h) && !in_array((string)($h['file'] ?? ''), $forget, true)));

    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $g['id'])) $error = 'The title ID must be 3 to 32 letters, numbers, - or _ (for example PPSA92011).';
    elseif ($g['name'] === '') $error = 'Give the game a name.';
    elseif (mb_strlen($g['date']) > 40) $error = 'Keep the Coming soon date short, like December 2026.';
    // Coming soon: no download yet. Beta: testers download the beta zip from this site. Either
    // way the repo and link are optional until it's out (but well formed when given).
    elseif ($g['source'] === 'github' && !(($g['soon'] || $g['beta']) && $g['repo'] === '') && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $g['repo'])) $error = 'Enter the GitHub repo as owner/name, for example elripalda/SvR2011-Recomp-PS5.';
    elseif ($g['source'] === 'url' && !(($g['soon'] || $g['beta']) && $g['url'] === '') && !preg_match('#^https://#', $g['url'])) $error = 'The download link has to start with https://';
    elseif ($badLink !== '') $error = $badLink;
    elseif ($badMods !== null) $error = $badMods;
    elseif (!rs_mods_dir_ok($g['mods_dir'])) $error = 'Mods folder: a folder inside the game\'s, like mods or data/mods (no .. and no \\).';
    elseif ($g['beta'] && $g['soon']) $error = 'A game can be Beta or Coming soon, not both.';
    elseif (mb_strlen($g['beta_version']) > 40) $error = 'Keep the beta version short, like 1.2.0-beta1.';
    elseif ($badSave !== null) $error = 'Save folders: "' . $badSave . '" isn\'t a folder inside the game\'s (one per line, like userdata, with no .. or \\).';
    elseif ($badKeep !== null) $error = 'Keep when updating: "' . $badKeep . '" isn\'t a file or folder inside the game\'s (one per line, like user_data or dbz3.toml, with no .., * or \\).';
    elseif ($badLog !== null) $error = 'Log files: "' . $badLog . '" isn\'t a path inside the game\'s folder (one per line, like logs/*.log, with no .. or \\).';
    elseif ($g['sha256'] !== '' && !preg_match('/^[A-Fa-f0-9]{64}$/', $g['sha256'])) $error = 'The SHA-256 should be 64 letters and numbers (0-9, a-f), or left empty.';
    elseif ($g['min_store'] !== '' && !preg_match('/^\d+(\.\d+){0,3}$/', $g['min_store'])) $error = 'Needs Recomp Store: a version like 1.0.3, or leave it empty.';
    elseif ($g['source'] === 'github' && strpbrk($g['asset'], '*?') !== false && !preg_match('/\.(zip|7z)$/i', $g['asset'])) $error = 'A file name with * has to end in .zip or .7z, so it can only match the release\'s archive (not a checksum or another file).';
    elseif ($badTag !== '') $error = 'Labels: "' . $badTag . '" is too long. Up to 6 short labels, comma-separated, like Recomp, Xbox 360.';
    elseif (($isNew || $g['id'] !== $oldId) && rs_move_block($g['id'])) $error = RS_MOVE_BLOCK[0] . ' to ' . RS_MOVE_BLOCK[1] . ' are kept for the PS5 store itself (it gives them to games whose own ID is taken on a console). Pick another title ID.';
    elseif (($isNew || $g['id'] !== $oldId) && isset(rs_taken_ids()[$g['id']])) $error = $g['id'] . ' is already used outside the store (' . (rs_taken_ids()[$g['id']] ?: 'see Settings') . '). Two apps with one title ID clash on a PS5: pick another.';
    elseif ($g['zip_id'] !== '' && (!preg_match('/^[A-Za-z]{4}\d{5}$/', $g['zip_id']) || strcasecmp($g['zip_id'], $g['id']) === 0)) $error = 'Title ID in its zip: four letters and five numbers, like PPSA12345, and not this game\'s own (leave it empty then).';
    elseif ($g['inside'] !== '' && (!preg_match('/\.zip$/i', $g['inside']) || strlen($g['inside']) > 300 || $g['inside'][0] === '/' || preg_match('#\\\\|(^|/)\.\.(/|$)|[\x00-\x1f\x7f]#', $g['inside']))) $error = 'The zip inside the download: its whole name in that zip, ending in .zip, with / between folders (for example */prospero/builder/Assets/payload.zip). No .. and no \\.';
    elseif ($g['setup'] && $g['slots'] !== '' && !is_array(json_decode($g['slots'], true))) $error = 'The game files list isn\'t valid. Use the template\'s format.';
    elseif ($g['setup'] && $g['slots'] !== '' && ($slotErr = rs_slots_error(json_decode($g['slots'], true)))) $error = 'Game files: ' . $slotErr;
    elseif (($isNew || $g['id'] !== $oldId) && rs_game($g['id'])) $error = 'Another game already uses that title ID.';

    if ($error === '') {
        try {
            foreach (['icon_file' => 'icon', 'hero_file' => 'hero', 'logo_file' => 'logo'] as $field => $kind) {
                if (!empty($_FILES[$field]['name'])) $g = array_merge($g, rs_save_image($_FILES[$field], $kind, $g['id']));
            }
            // A beta zip uploaded here: kept in the beta folder, named after the game.
            if (!empty($_FILES['beta_upload']['name'])) {
                $f = $_FILES['beta_upload'];
                if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('That zip is bigger than this server takes in a form. Put it in the beta folder with File Manager instead, then choose it here.');
                if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The beta zip didn\'t arrive. Try again.');
                if (file_get_contents($f['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") throw new RuntimeException('The beta file has to be a .zip.');
                $name = preg_replace('/[^A-Za-z0-9._-]/', '', $g['id'] . '-' . ($g['beta_version'] !== '' ? $g['beta_version'] : date('Ymd-His'))) . '.zip';
                if (!move_uploaded_file($f['tmp_name'], rs_beta_dir_ready() . '/' . $name)) throw new RuntimeException('The beta zip couldn\'t be saved in ' . rs_beta_dir() . '.');
                $g['beta_file'] = $name; $g['beta_sha256'] = ''; $g['beta_size'] = 0;
            }
            // Its checksum and size, worked out once (the store checks the download against them).
            if ($g['beta_file'] !== '' && $g['beta_sha256'] === '') {
                $p = rs_beta_dir() . '/' . basename($g['beta_file']);
                if (!is_file($p)) throw new RuntimeException('The beta zip ' . $g['beta_file'] . ' isn\'t in the beta folder anymore.');
                $g['beta_sha256'] = rs_beta_hash($p);
                $g['beta_size'] = filesize($p);
            }
            // (2.1) A new build: the one it replaces is kept for testers to go back to (up to 10),
            // and a build picked back from the history leaves it.
            if ($g['beta_file'] !== $orig['beta_file']) {
                $hist = array_values(array_filter((array)$g['beta_history'], fn($h) => is_array($h) && ($h['file'] ?? '') !== $g['beta_file'] && ($h['file'] ?? '') !== $orig['beta_file']));
                if ($orig['beta_file'] !== '' && preg_match('/^[0-9a-f]{64}$/', (string)$orig['beta_sha256'])) {
                    array_unshift($hist, ['file' => $orig['beta_file'], 'version' => (string)($orig['beta_version'] ?: $orig['version']), 'sha256' => (string)$orig['beta_sha256'],
                        'size' => (int)$orig['beta_size'], 'notes' => (string)$orig['beta_notes'], 'at' => (string)$orig['beta_at']]);
                }
                $g['beta_history'] = array_slice($hist, 0, 10);
                $g['beta_at'] = gmdate('M j, Y');
                if ($g['beta_notes'] === (string)$orig['beta_notes']) $g['beta_notes'] = ''; // a new build starts with fresh notes unless they were typed in this save
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
  <div class="actions"><a class="btn" href="app-stats.php?id=<?= h(rawurlencode($g['id'])) ?>">Stats</a>
  <form method="post" action="index.php" onsubmit="return confirm('Remove <?= h(addslashes($g['name'])) ?> from the admin?');">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($g['id']) ?>">
    <button class="btn danger">Remove game</button>
  </form></div>
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
    <label>Category
      <select name="category">
        <?php foreach (RS_CATEGORIES as $ck => $cl): ?>
        <option value="<?= h($ck) ?>" <?= rs_category((string)$g['category']) === $ck ? 'selected' : '' ?>><?= h($cl) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Its part of the store (Recomp Store 2.0). Older stores list everything as games.</small>
    </label>
    <label><span>Labels <span class="muted">(optional)</span></span><input name="tags" value="<?= h($g['tags']) ?>" maxlength="200" placeholder="Recomp, Xbox 360"><small>Up to 6, comma-separated, shown on its page.</small></label>
    <label>Visible to
      <select name="visible">
        <option value="everyone" <?= empty($g['testers']) ? 'selected' : '' ?>>Everyone</option>
        <option value="testers" <?= !empty($g['testers']) ? 'selected' : '' ?>>Testers only</option>
      </select>
      <small>Testers only: left out of the published catalog and the website; consoles marked as testers (Settings) get it right away, marked as testing.</small>
    </label>
    <label class="span2">Short line<input name="blurb" value="<?= h($g['blurb']) ?>" maxlength="180"><small>One sentence for the featured banner.</small></label>
    <label class="span2">Description<textarea name="description" rows="4"><?= h($g['description']) ?></textarea></label>
    <label class="span2">What's new<textarea name="news" rows="3"><?= h($g['news']) ?></textarea></label>
    <label class="span2">Credits<input name="credits" value="<?= h($g['credits']) ?>" placeholder="PS5 port by elripalda · PC recompilation by …"></label>
    <label>You bring<input name="needs" value="<?= h($g['needs']) ?>" placeholder="Xbox 360 ISO"></label>
    <div class="checks">
      <label class="check"><input type="checkbox" name="featured" <?= $g['featured'] ? 'checked' : '' ?>> Featured on the banner</label>
      <label class="check"><input type="checkbox" name="hidden" <?= $g['hidden'] ? 'checked' : '' ?>> Hidden (kept here, left out when publishing)</label>
      <label class="check"><input type="checkbox" name="soon" data-soon <?= $g['soon'] ? 'checked' : '' ?>> Coming soon (not out yet)</label>
      <label class="check" title="When another app already has this title ID on a console, the store can install this one under a new ID (PPSA97001 on) and keep its updates going there. Untick it for a port that only works from its own folder."><input type="checkbox" name="can_move" <?= $g['can_move'] ? 'checked' : '' ?>> Can move to a new title ID if its own is taken</label>
    </div>
    <label class="span2" data-soon-fields><span>When it's coming <span class="muted">(optional)</span></span><input name="date" value="<?= h($g['date']) ?>" maxlength="40" placeholder="December 2026"><small>Shown in place of a version, in the store's Coming soon row and on the site. Leave empty for just "Coming soon". A Coming soon game needs only its name, title ID and art; untick it when it's out and fill in Download.</small></label>
  </div>
</section>

<section class="card" data-not-soon data-download-card>
  <h2>Download</h2>
  <p class="beta-note" data-beta-note hidden>This game is a beta: testers get the beta zip from the Beta card below, so you can leave the repo and link empty for now. Fill them in when it's out for everyone, then untick Beta.</p>
  <div class="grid2">
    <label>Where the zip comes from
      <select name="source" data-source>
        <option value="github" <?= $g['source'] === 'github' ? 'selected' : '' ?>>GitHub releases (always the newest)</option>
        <option value="url" <?= $g['source'] === 'url' ? 'selected' : '' ?>>A fixed download link</option>
      </select>
    </label>
    <label data-gh>GitHub repo<input name="repo" value="<?= h($g['repo']) ?>" placeholder="elripalda/SvR2011-Recomp-PS5"></label>
    <label data-gh>Zip name on the release<input name="asset" value="<?= h($g['asset']) ?>" placeholder="PPSA92011.zip"><small>The file the store downloads from each release. When its name changes with each version, put * for that part, like WindWaker-PS5-*.zip: the store then finds the newest itself (needs Recomp Store 1.0.3, set for you). A .7z works too, from Recomp Store 2.0 (set for you).</small></label>
    <label data-url>Download link<input name="url" value="<?= h($g['url']) ?>" placeholder="https://…/PPSA92011.zip"></label>
    <label class="span2">Zip inside the download (optional)<input name="inside" value="<?= h($g['inside']) ?>" placeholder="*/prospero/builder/Assets/payload.zip"><small>Only for a release that has no zip of its own PS5 files, just a bigger one (like a source zip) with that zip inside. Put its whole name inside the download, with * for a part that changes with each version. The store takes it out and installs it (needs Recomp Store 1.0.4, set for you). Leave empty for a normal release.</small></label>
    <label class="span2">Title ID in its zip (optional)<input name="zip_id" value="<?= h($g['zip_id'] ?? '') ?>" placeholder="Same as the title ID above" maxlength="9"><small>Only when its release uses another app's title ID: put the zip's ID here and give this game a free one above. The store installs it under that one, so both fit on a PS5 (needs Recomp Store 2.5.1, set for you).</small></label>
    <label>Version<input name="version" value="<?= h($g['version']) ?>" placeholder="1.0.0"><small>Shown until the store and the site have read GitHub; updates come from GitHub's newest release.</small></label>
    <label>Needs Recomp Store<input name="min_store" value="<?= h($g['min_store']) ?>" placeholder="any version"><small>The oldest store that can install it; older ones are told to update first (before 1.0.3 they don't list it at all). <?php $auto = rs_min_store(array_merge($g, ['min_store' => ''])); echo $auto !== '' ? 'This game needs at least ' . h($auto) . ' for its category, zip name or game files' . (version_compare($auto, RS_V2_FROM, '>=') ? ' (2.0 only: older stores never see it)' : '') . '.' : 'Leave empty for any.'; ?></small></label>
    <label>Build<input type="number" min="1" name="build" value="<?= h((string)$g['build']) ?>"></label>
    <label>Release date<input type="date" name="released" value="<?= h($g['released']) ?>"></label>
    <label>Download size<input name="size" value="<?= h($g['size']) ?>" placeholder="55 MB"></label>
    <label class="span2">Zip SHA-256 (optional)<input name="sha256" value="<?= h($g['sha256']) ?>" placeholder="64 characters"><small>For your own games: the store refuses a download that doesn't match. Community games use GitHub's own check.</small></label>
  </div>
</section>

<?php
$betaFiles = rs_beta_files();
$betaCounts = ['all' => 0, 'used' => 0];
if (!$isNew) {
    try {
        rs_beta_tables();
        $st = rs_db()->prepare('SELECT COUNT(*) n, SUM(install_id IS NOT NULL AND revoked = 0) used FROM beta_codes WHERE game_id = ?');
        $st->execute([$g['id']]);
        $r = $st->fetch();
        $betaCounts = ['all' => (int)$r['n'], 'used' => (int)$r['used']];
    } catch (Throwable $e) {}
}
?>
<section class="card beta-card" id="beta" data-not-soon>
  <h2>Beta</h2>
  <label class="check"><input type="checkbox" name="beta" <?= $g['beta'] ? 'checked' : '' ?> data-beta> Beta: only testers with a code get this game. It's left out of the public store and the site.</label>
  <div class="grid2" data-beta-fields>
    <label>Beta version<input name="beta_version" value="<?= h($g['beta_version']) ?>" maxlength="40" placeholder="1.0.0-beta1"><small>What testers see. Change it with each new beta zip, so their store offers the update.</small></label>
    <div class="beta-file">
      <span class="lbl">Beta zip</span>
      <?php if ($g['beta_file'] !== ''): ?>
        <strong><?= h($g['beta_file']) ?></strong>
        <span class="muted"><?= $g['beta_size'] ? h(rs_human_size((int)$g['beta_size'])) : '' ?><?= $g['beta_sha256'] ? ' · SHA-256 ' . h(substr($g['beta_sha256'], 0, 12)) . '…' : '' ?><?= isset($betaFiles[$g['beta_file']]) ? '' : ' · missing from the beta folder' ?></span>
      <?php else: ?><span class="muted">None yet.</span><?php endif; ?>
      <?php if ($g['beta_file'] !== '' && rs_sign_on()): $signed = preg_match('/^[0-9a-f]{64}$/', (string)$g['beta_sha256']) && rs_sig_for('beta', (string)$g['beta_sha256']) !== ''; ?>
        <span class="pill<?= $signed ? ' ok' : '' ?>"><?= $signed ? 'Signed' : 'Waiting for your signature' ?></span>
      <?php endif; ?>
    </div>
    <label class="span2">What's new in this build <span class="muted">(optional)</span>
      <textarea name="beta_notes" rows="4" maxlength="4000" placeholder="What changed, what to test, known problems"><?= h((string)$g['beta_notes']) ?></textarea>
      <small>Testers see this on the game's page in the store. A new zip starts with empty notes unless you type new ones in the same save.</small></label>
    <?php $builds = array_values(array_filter(rs_beta_builds($g), fn($b) => !$b['current'])); if (!$isNew && $builds): ?>
    <div class="span2 beta-history">
      <span class="lbl">Earlier builds testers can go back to</span>
      <table class="stats">
        <thead><tr><th>Version</th><th>Zip</th><th>Up since</th><th>Signed</th><th>Stop offering</th></tr></thead>
        <tbody>
        <?php foreach ($builds as $b): $bs = rs_sig_for('beta', $b['sha256']) !== ''; ?>
          <tr><td><?= h($b['version']) ?></td><td><code><?= h($b['file']) ?></code> <span class="muted"><?= h(rs_human_size(max(1, $b['size']))) ?></span></td><td><?= h($b['at'] ?: '?') ?></td>
            <td><?= $bs ? 'Yes' : '<span class="warn">Not yet</span>' ?></td>
            <td><input type="checkbox" name="beta_forget[]" value="<?= h($b['file']) ?>" aria-label="Stop offering <?= h($b['version']) ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <small class="muted">In Recomp Store 2.1 a tester picks one under Versions on the game's page, if the newest build gives them trouble. Ticked ones stop being offered when you save (their zips stay in the beta folder).</small>
    </div>
    <?php endif; ?>
    <?php if (!$isNew && $g['beta_file'] !== '' && rs_r2()): $r2copy = rs_r2_beta_copy($g); ?>
    <div class="beta-file">
      <span class="lbl">Cloudflare R2</span>
      <?php if ($r2copy): ?><strong>On R2</strong> <span class="muted">copied <?= h(date('M j, g:i a', (int)$r2copy['at'])) ?><?= rs_setting('r2_on', '1') === '1' ? ' · testers download it from there' : ' · testers download it from this site (R2 is unticked in Settings)' ?></span>
      <?php else: ?><span class="muted">Not on R2: testers download it from this site.</span><?php endif; ?>
      <button class="btn" formaction="r2.php?id=<?= h(urlencode($g['id'])) ?>" formmethod="post" formnovalidate><?= $r2copy ? 'Copy to R2 again' : 'Copy to R2' ?></button>
      <small class="muted">Save your other changes first: this only copies the zip.</small>
    </div>
    <?php endif; ?>
    <div class="drop-zone span2" data-beta-drop data-game="<?= h($isNew ? '' : $g['id']) ?>" data-csrf="<?= h(rs_csrf()) ?>">
      <input type="file" accept=".zip,application/zip" hidden data-beta-pick>
      <strong>Drop the beta zip here</strong>
      <span class="muted">or <button type="button" class="link" data-beta-choose>choose it</button>. Any size; it goes up in pieces.</span>
      <div class="drop-progress" hidden><div class="drop-bar"></div></div>
      <p class="drop-status" data-beta-status></p>
    </div>
    <label class="span2">Or pick one already in the beta folder
      <select name="beta_existing">
        <option value="">Keep the current zip</option>
        <?php foreach ($betaFiles as $name => $f): ?><option value="<?= h($name) ?>"><?= h($name) ?> (<?= h(rs_human_size((int)$f['size'])) ?>, <?= h(date('M j', $f['time'])) ?>)</option><?php endforeach; ?>
      </select>
      <small>The beta folder: <code><?= h(str_replace(dirname(RS_PUBLIC), '', rs_beta_dir())) ?></code> <?= str_starts_with(rs_beta_dir(), RS_PUBLIC) ? '(blocked from the web; only signed-in testers\' stores can download from it)' : '(beside public_html, so it\'s never public)' ?>.</small></label>
    <?php if (!$isNew): ?>
    <p class="span2 beta-codes-line"><?= $betaCounts['all'] ? h($betaCounts['used'] . ' of ' . $betaCounts['all'] . ' codes redeemed.') : 'No codes yet.' ?> <a href="beta.php?game=<?= urlencode($g['id']) ?>">Make and manage codes →</a></p>
    <?php endif; ?>
  </div>
</section>

<section class="card" data-links-card>
  <h2>Extra buttons</h2>
  <p class="muted">Buttons on the game's page on the website, below Source on GitHub: texture packs, patches, anything with a link. Pick each one's text and color. They open in a new tab.</p>
  <div class="links-list" data-links>
    <?php $rows = $g['links'] ?: []; foreach ($rows as $l): ?>
    <div class="link-row" data-link-row>
      <label>Text<input name="link_label[]" value="<?= h($l['label'] ?? '') ?>" maxlength="40" placeholder="4K texture pack"></label>
      <label class="grow">Link<input name="link_url[]" value="<?= h($l['url'] ?? '') ?>" placeholder="https://…"></label>
      <label>Color<input type="color" name="link_color[]" value="<?= h(preg_match('/^#[0-9a-fA-F]{6}$/', (string)($l['color'] ?? '')) ? $l['color'] : '#2a3446') ?>"></label>
      <button type="button" class="icon-btn" data-link-remove aria-label="Remove this button" title="Remove">✕</button>
    </div>
    <?php endforeach; ?>
  </div>
  <template data-link-template>
    <div class="link-row" data-link-row>
      <label>Text<input name="link_label[]" maxlength="40" placeholder="4K texture pack"></label>
      <label class="grow">Link<input name="link_url[]" placeholder="https://…"></label>
      <label>Color<input type="color" name="link_color[]" value="#2a3446"></label>
      <button type="button" class="icon-btn" data-link-remove aria-label="Remove this button" title="Remove">✕</button>
    </div>
  </template>
  <div class="links-foot"><button type="button" data-link-add>Add a button</button></div>
  <div class="links-preview" data-links-preview aria-label="How they look on the game page"></div>
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
  <label class="check"><input type="checkbox" name="setup" data-setup <?= $g['setup'] ? 'checked' : '' ?>> Recomp Store sets this game up from the player's own files (disc image, Xbox Live Arcade package, Title Update, DLC, a ROM or data files)</label>
  <div class="grid2" data-setup-fields>
    <label class="span2">Where the port reads its files<input name="data_dir" value="<?= h($g['data_dir']) ?>" placeholder="/data/homebrew/PPSA92011"></label>
    <label class="span2">Files to ask for<textarea name="slots" rows="9" class="mono"><?= h($g['slots'] !== '' ? $g['slots'] : $slotTemplate) ?></textarea>
      <small>One entry per file: what it's called, its kind, and the folder it goes in, relative to the folder above ("." means right in it). Kinds: iso (an Xbox 360 disc image, unpacked), stfs (a Title Update or DLC package from the console), image (a GameCube or Wii disc image copied as it is, .iso/.gcm/.ciso; needs Recomp Store 1.0.3), arcade (an Xbox Live Arcade game's own package, the long-named file from the console's 000D0000 folder, unpacked; needs Recomp Store 1.0.7, set for you). titleId (Xbox 360, like 584111EC) or discId (GameCube/Wii, like GZLE01) is optional: when set, the store refuses a disc or package that isn't this game.</small>
      <small><strong>ready</strong> (needs Recomp Store 1.0.8, set for you): the file whose being in the slot's folder means it's set up, in any case (<code>Default.xexp</code> for a Title Update that shares the game's folder). <strong>updateId</strong> and <strong>updateSha1</strong> (1.0.8): a Title Update the store can download for the player, shown as a Download button. updateId is the update's content ID (XboxUnity lists it as its hash); updateSha1 is the whole file's SHA-1, which the store checks before using it. Both need the slot's titleId.</small>
      <small><strong>only</strong>, <strong>files</strong> and <strong>wrong</strong> (needs Recomp Store 1.1.2, set for you), for a disc: <code>only</code> the disc's folders to copy, each with its path (<code>["DBZ3"]</code> copies DBZ3 and what's in it, nothing else); <code>files</code> the files the disc must have, <code>[{"path": "DBZ3/yae3_xenon.xex", "size": 4890624, "crc32": "F67F6C97"}, ...]</code> (crc32 optional): a disc without them, or with another size or CRC, is refused before anything is copied, and the slot counts as set up once they're all there at their sizes; <code>wrong</code> what to tell a player whose disc that is ("This app needs the USA Budokai HD Collection.").</small>
      <small><strong>file</strong> (needs Recomp Store 1.0.6, set for you): the player's own file copied in, such as a ROM or a game's data files. <code>ext</code> the extensions to show (".z64 .n64 .v64"); <code>pick</code> the file to choose ("pak0.pk3"); <code>also</code> the files beside it that go too ("pak?.pk3"); <code>need</code> how many must be there to play (9); <code>name</code> what to call it in the game's folder ("DIABDAT.MPQ"); <code>sha1</code> the SHA-1s it may have, space-separated; <code>n64</code> true for a Nintendo 64 ROM (any byte order, saved as .z64); <code>made</code> a file the game makes from it, which counts as set up ("sm64.o2r").</small></label>
  </div>
  <label><span>Save folders <span class="muted">(optional)</span></span><textarea name="saves" rows="2" class="mono" placeholder="userdata"><?= h($g['saves']) ?></textarea>
    <small>Where the game keeps its saves, one per line, inside its folder (like userdata, or baseq3/q3config.cfg). With these, Delete in Recomp Store 1.0.6 offers Keep my saves: everything else goes, and the saves are there when it's installed again.</small></label>
  <label><span>Keep when updating <span class="muted">(optional)</span></span><textarea name="keep" rows="3" class="mono" placeholder="user_data"><?= h($g['keep']) ?></textarea>
    <small>The player's own files and folders inside the game's folder, one per line (like Assets, user_data or dbz3.toml). Recomp Store 1.1.2 never writes over them when it installs an update; the release's copy goes in only when the player has none yet.</small></label>
  <label><span>Log files <span class="muted">(optional)</span></span><textarea name="logs" rows="2" class="mono" placeholder="UserData/crashes/*.txt"><?= h($g['logs']) ?></textarea>
    <small>What Send logs in Recomp Store 1.1.0 sends from this game, one per line, inside its folder; * and ? match any characters. Leave it empty and the store finds them itself: any .log file, and .txt files with log, crash or trace in the name. The newest 8 go, with the store's own log.</small></label>
</section>

<section class="card" data-not-soon>
  <h2>Ready to play</h2>
  <p class="muted">Recomp Store 2.3.0 shows a checklist on the game's page: the payload loader, ShadowMountPlus, free space and the game's files are checked for every game. Tick what else it needs; what the store can't check shows as "Needed".</p>
  <div class="checks">
    <?php foreach (RS_REQUIRES as $rk => $rl): ?>
    <label class="check"><input type="checkbox" name="requires[]" value="<?= h($rk) ?>" <?= in_array($rk, (array)$g['requires'], true) ? 'checked' : '' ?>> <?= h($rl) ?></label>
    <?php endforeach; ?>
  </div>
</section>

<section class="card" data-not-soon>
  <h2>Mods</h2>
  <p class="muted">Files from GitHub releases the player can install from the game's page (Recomp Store 2.3.0): turned on and off, or removed, only inside the game's mods folder.</p>
  <div class="grid2">
    <label><span>Mods folder <span class="muted">(optional)</span></span><input name="mods_dir" value="<?= h($g['mods_dir']) ?>" maxlength="200" placeholder="mods" class="mono"><small>Inside the game's folder. Empty: mods.</small></label>
    <label class="span2">The mods<textarea name="mods" rows="8" class="mono" placeholder="<?= h($modsTemplate) ?>"><?= h($g['mods']) ?></textarea>
      <small>A JSON list. Each: <code>id</code> (letters, digits, - _ .), <code>name</code>, <code>author</code>, <code>blurb</code>, <code>repo</code> (owner/name), <code>asset</code> (the release tag and file, <code>v1.0/hd-pack.zip</code>), <code>sha256</code> (needed: the store checks the download), <code>size</code> in bytes, and <code>target</code>, its folder inside the mods folder. A .zip is unpacked into it; any other file is copied there.</small></label>
  </div>
</section>

<div class="sticky-save"><button class="primary">Save</button><span class="muted">Saving doesn't publish. Press Publish on the Games page when you're done.</span></div>
</form>
<?php rs_foot(['assets/admin.js']);
