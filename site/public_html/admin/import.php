<?php
// Recomp Store admin (2.0): import apps from a JSON file (a list made for another store, say):
//   { "apps": [ { id?, name, author, category, tags, blurb, description, repo, asset, icon, hero, canMove? } ] }
// A preview first: what's new, what's already here (by title ID or repo) and skipped, and what
// needs a title ID or a zip name before it can go in. What's imported is set to Testers only:
// testers get it right away, and it stays out of the public catalog until you set it to Everyone.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
@set_time_limit(300);

const RS_IMPORT_MAX = 300;

// One entry of the file, cleaned, with the other names lists use for the same things.
function rs_import_clean(array $x): array {
    $pick = function (array $keys, int $max) use ($x): string {
        foreach ($keys as $k) {
            if (isset($x[$k]) && is_scalar($x[$k]) && trim((string)$x[$k]) !== '') {
                return mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub((string)$x[$k], 'UTF-8'))), 0, $max);
            }
        }
        return '';
    };
    $repo = $pick(['repo', 'github', 'repository'], 200);
    $repo = (string)preg_replace(['#^https?://(www\.)?github\.com/#i', '#\.git$#i', '#/+$#'], '', $repo);
    $repo = implode('/', array_slice(explode('/', $repo), 0, 2));
    $cat = strtolower($pick(['category'], 20));
    $cats = ['game' => 'games', 'games' => 'games', 'emulator' => 'emulators', 'emulators' => 'emulators', 'emu' => 'emulators',
             'app' => 'apps', 'apps' => 'apps', 'application' => 'apps', 'applications' => 'apps', 'homebrew' => 'apps',
             'tool' => 'tools', 'tools' => 'tools', 'utility' => 'tools', 'utilities' => 'tools'];
    $tags = $x['tags'] ?? ($x['labels'] ?? []);
    $tags = is_array($tags) ? implode(',', array_map('strval', array_filter($tags, 'is_scalar'))) : (is_string($tags) ? $tags : '');
    $url = fn(string $u): string => rs_import_url_ok($u) ? $u : '';
    $can = $x['canMove'] ?? ($x['can_move'] ?? true);
    $desc = $pick(['description', 'desc'], 2000);
    return [
        'id' => strtoupper($pick(['id', 'titleId', 'titleID', 'title_id'], 32)),
        'name' => $pick(['name', 'title'], 80),
        'author' => $pick(['author', 'developer', 'by'], 60) ?: (str_contains($repo, '/') ? explode('/', $repo)[0] : ''),
        'category' => $cats[$cat] ?? 'apps',
        'category_given' => $cat,
        'tags' => implode(', ', rs_tags_list($tags)),
        'blurb' => $pick(['blurb', 'summary', 'short'], 180) ?: mb_substr($desc, 0, 180),
        'description' => $desc,
        'repo' => $repo,
        'asset' => $pick(['asset', 'file', 'pattern'], 120),
        'icon' => $url($pick(['icon', 'iconUrl', 'icon_url'], 500)),
        'hero' => $url($pick(['hero', 'heroUrl', 'banner', 'background'], 500)),
        'can_move' => !($can === false || $can === 0 || $can === 'false'),
        'version' => '', 'size' => '', 'released' => '', 'note' => '',
    ];
}

// A picture's address in a list: https (or, in tests, the test GitHub's own host).
function rs_import_url_ok(string $u): bool {
    if (!preg_match('#^https?://[^\s<>"\']{4,500}$#', $u)) return false;
    return str_starts_with($u, 'https://') || rs_gh_allowed($u);
}

// What's in the admin already: title IDs, and repos (lowercase) with the name using them.
function rs_import_known(): array {
    $ids = $repos = [];
    foreach (rs_games() as $g) {
        $ids[(string)$g['id']] = (string)$g['name'];
        if ((string)$g['repo'] !== '') $repos[strtolower((string)$g['repo'])] = (string)$g['name'];
    }
    return [$ids, $repos];
}

// A row's state: ['new'|'skip'|'needs'|'bad', why].
function rs_import_state(array $r, array $ids, array $repos, array &$seen): array {
    if (isset($ids[$r['id']]) && $r['id'] !== '') return ['skip', 'Already here: ' . $ids[$r['id']] . ' has this title ID.'];
    if ($r['repo'] !== '' && isset($repos[strtolower($r['repo'])])) return ['skip', 'Already here: ' . $repos[strtolower($r['repo'])] . ' comes from this repo.'];
    $k1 = $r['id'] !== '' ? 'id:' . $r['id'] : null;
    $k2 = $r['repo'] !== '' ? 'repo:' . strtolower($r['repo']) : null;
    if (($k1 && isset($seen[$k1])) || ($k2 && isset($seen[$k2]))) return ['skip', 'Twice in the file: the first one counts.'];
    if ($k1) $seen[$k1] = true;
    if ($k2) $seen[$k2] = true;
    if ($r['name'] === '') return ['bad', 'No name.'];
    if (!rs_repo_ok($r['repo'])) return ['bad', 'No GitHub repo (owner/name).'];
    if ($r['id'] === '') return ['needs', 'Needs its title ID: the store installs it by it.'];
    if (!preg_match('/^[A-Z]{4}\d{5}$/', $r['id'])) return ['needs', 'A title ID is four letters and five numbers, like PPSA12345.'];
    if (rs_move_block($r['id'])) return ['bad', RS_MOVE_BLOCK[0] . ' to ' . RS_MOVE_BLOCK[1] . ' are kept for the store itself.'];
    if (isset(rs_taken_ids()[$r['id']])) return ['bad', $r['id'] . ' is used outside the store (' . (rs_taken_ids()[$r['id']] ?: 'Settings') . ').'];
    if ($r['asset'] === '') return ['needs', 'Needs the zip name on its releases, so the store knows which file to get.'];
    if (strlen($r['asset']) > 120 || !preg_match('/^[^\/\\\\]+\.(zip|7z)$/i', $r['asset'])) return ['needs', 'The zip name has to end in .zip or .7z (* for the part that changes).'];
    return ['new', $r['note'] !== '' ? $r['note'] : 'New.'];
}

// Fills in what GitHub's newest release says: the title ID and icon from its zip, the file's name.
function rs_import_lookup(array $r): array {
    $res = rs_source_repo($r['repo'], preg_match('/\.(zip|7z)$/i', $r['asset']) ? $r['asset'] : '');
    if (!($res['ok'] ?? false) || empty($res['apps'])) { $r['note'] = 'GitHub: ' . (string)($res['error'] ?? 'nothing found.'); return $r; }
    $a = $res['apps'][0];
    $from = [];
    if ($r['id'] === '' && preg_match('/^[A-Z]{4}\d{5}$/', (string)$a['titleId'])) { $r['id'] = (string)$a['titleId']; $from[] = 'title ID'; }
    if ($r['asset'] === '' && !empty($a['source']['asset'])) { $r['asset'] = (string)$a['source']['asset']; $from[] = 'zip name'; }
    if ($r['icon'] === '' && $a['icon'] !== '') {
        $p = (string)parse_url((string)$a['icon'], PHP_URL_PATH);
        // The zip's own icon, not the owner's GitHub picture (source.php's stand-in).
        $avatar = rs_db()->prepare("SELECT COUNT(*) FROM kv_cache WHERE k LIKE 'avatar:%' AND v = ?");
        $avatar->execute([$p]);
        if (preg_match('#^/media/gh/[0-9a-f]{40}\.(png|jpg)$#', $p) && is_file(RS_PUBLIC . $p) && !(int)$avatar->fetchColumn()) { $r['icon'] = $p; $from[] = 'icon'; }
    }
    $r['version'] = (string)($a['version'] ?? '');
    $r['size'] = (string)($a['size'] ?? '');
    $r['released'] = (string)($a['released'] ?? '');
    $r['note'] = ($from ? 'From its release on GitHub: ' . implode(', ', $from) . '.' : 'Checked on GitHub.')
        . (count($res['apps']) > 1 ? ' Its zip has ' . count($res['apps']) . ' apps: this is the first.' : '')
        . ($r['id'] === '' ? ' No title ID in its zip (a .7z can\'t be looked into).' : '');
    return $r;
}

// A picture from GitHub saved here (icon 512x512, banner 3840 and 1920 wide); any other address is
// kept as it is.
function rs_import_art(string $url, string $kind, string $id): array {
    if ($url === '' || str_starts_with($url, '/media/')) return $url === '' ? [] : ($kind === 'icon' ? ['icon' => $url] : ['hero' => $url, 'hero_web' => $url]);
    $keep = $kind === 'icon' ? ['icon' => $url] : ['hero' => $url, 'hero_web' => $url];
    if (!rs_gh_allowed($url)) return $keep;
    $g = rs_http($url, ['max' => 12 << 20, 'timeout' => 20, 'github_only' => true]);
    if ($g['status'] !== 200 || !@getimagesizefromstring($g['body'])) return $keep;
    $tmp = tempnam(sys_get_temp_dir(), 'rsimp');
    file_put_contents($tmp, $g['body']);
    try {
        return rs_save_image(['error' => UPLOAD_ERR_OK, 'size' => strlen($g['body']), 'tmp_name' => $tmp], $kind, $id);
    } catch (Throwable $e) {
        return $keep;
    } finally {
        @unlink($tmp);
    }
}

$step = 'start';
$rows = [];
$results = [];
$error = '';
[$knownIds, $knownRepos] = rs_import_known();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'preview') {
        $text = '';
        if (!empty($_FILES['file']['name'])) {
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) $error = 'The file didn\'t arrive. Try again.';
            elseif ($f['size'] > 2 << 20) $error = 'That file is over 2 MB. A list of apps is much smaller: is it the right file?';
            else $text = (string)file_get_contents($f['tmp_name']);
        } else {
            $text = (string)($_POST['json'] ?? '');
        }
        if ($error === '') {
            $text = preg_replace('/^\xEF\xBB\xBF/', '', trim($text)) ?? '';
            $j = json_decode($text, true);
            $list = is_array($j) && array_is_list($j) ? $j : (is_array($j['apps'] ?? null) ? $j['apps'] : null);
            if ($text === '') $error = 'Choose a JSON file, or paste its text.';
            elseif ($list === null) $error = 'That isn\'t a list of apps: it should look like {"apps": [{"name": …, "repo": "owner/name", …}]}' . (json_last_error() !== JSON_ERROR_NONE ? ' (the JSON itself doesn\'t read: ' . json_last_error_msg() . ')' : '') . '.';
            elseif (!$list) $error = 'The list is empty.';
            else {
                $lookup = !empty($_POST['lookup']);
                $deadline = microtime(true) + 60;
                $skipped = 0;
                foreach (array_slice($list, 0, RS_IMPORT_MAX) as $x) {
                    if (!is_array($x)) continue;
                    $r = rs_import_clean($x);
                    if ($lookup && rs_repo_ok($r['repo']) && !isset($knownIds[$r['id']]) && !isset($knownRepos[strtolower($r['repo'])])
                        && ($r['id'] === '' || $r['asset'] === '')) {
                        if (microtime(true) < $deadline) $r = rs_import_lookup($r);
                        else ++$skipped;
                    }
                    // No description in the file: the developer's own, from the repo's GitHub page (one cached
                    // question per repo, so a long list doesn't run out of time).
                    if ($lookup && $r['description'] === '' && rs_repo_ok($r['repo']) && !isset($knownIds[$r['id']])
                        && !isset($knownRepos[strtolower($r['repo'])]) && microtime(true) < $deadline) {
                        $info = rs_gh_repo($r['repo'], 86400);
                        $d = trim((string)($info['description'] ?? ''));
                        if ($d !== '') {
                            $r['description'] = mb_substr($d, 0, 2000);
                            if ($r['blurb'] === '') $r['blurb'] = mb_substr($d, 0, 180);
                            $r['note'] = trim($r['note'] . ' Description from its GitHub page.');
                        }
                    }
                    $rows[] = $r;
                }
                $step = 'preview';
                if (count($list) > RS_IMPORT_MAX) $error = 'Only the first ' . RS_IMPORT_MAX . ' apps are shown. Import them, then the rest in another file.';
                if ($skipped) $error = trim($error . ' GitHub wasn\'t asked about ' . $skipped . ' of them (it took too long): preview again to check the rest.');
            }
        }
    } elseif ($action === 'import') {
        $step = 'done';
        $seen = [];
        $made = 0;
        foreach (array_slice((array)($_POST['rows'] ?? []), 0, RS_IMPORT_MAX) as $p) {
            if (!is_array($p)) continue;
            $r = json_decode((string)base64_decode((string)($p['d'] ?? ''), true), true);
            if (!is_array($r) || empty($p['pick'])) continue;
            $r = array_merge(rs_import_clean([]), array_intersect_key($r, rs_import_clean([])));
            foreach (['name', 'author', 'blurb', 'description', 'tags', 'repo', 'version', 'size', 'released', 'note'] as $k) $r[$k] = is_scalar($r[$k]) ? (string)$r[$k] : '';
            if (!rs_import_url_ok((string)$r['icon']) && !preg_match('#^/media/gh/[0-9a-f]{40}\.(png|jpg)$#', (string)$r['icon'])) $r['icon'] = '';
            if (!rs_import_url_ok((string)$r['hero'])) $r['hero'] = '';
            // What was changed in the preview.
            $r['id'] = strtoupper(trim((is_string($p['id'] ?? null) ? $p['id'] : (string)$r['id'])));
            $r['asset'] = trim((is_string($p['asset'] ?? null) ? $p['asset'] : (string)$r['asset']));
            $r['category'] = rs_category((is_string($p['category'] ?? null) ? $p['category'] : (string)$r['category']));
            [$state, $why] = rs_import_state($r, $knownIds, $knownRepos, $seen);
            if ($state !== 'new') { $results[] = [$r, $state === 'skip' ? 'left' : 'bad', $why]; continue; }
            $g = array_merge(rs_game_defaults(), [
                'id' => $r['id'], 'name' => $r['name'], 'kind' => 'community', 'author' => $r['author'],
                'blurb' => $r['blurb'], 'description' => $r['description'], 'category' => $r['category'], 'tags' => $r['tags'],
                'source' => 'github', 'repo' => $r['repo'], 'asset' => $r['asset'], 'needs' => '',
                'version' => $r['version'], 'size' => $r['size'], 'released' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['released']) ? $r['released'] : '',
                'can_move' => (bool)$r['can_move'], 'testers' => true,
            ]);
            $g = array_merge($g, rs_import_art($r['icon'], 'icon', $r['id']), rs_import_art($r['hero'], 'hero', $r['id']));
            try {
                rs_save_game($g);
                $knownIds[$r['id']] = $r['name'];
                $knownRepos[strtolower($r['repo'])] = $r['name'];
                ++$made;
                $results[] = [$r, 'made', 'Imported, Testers only.'];
            } catch (Throwable $e) {
                $results[] = [$r, 'bad', 'Couldn\'t be saved: ' . $e->getMessage()];
            }
        }
    }
}

rs_head('Import');
?>
<section class="head-row">
  <div>
    <h1>Import</h1>
    <p class="muted">Add many apps at once from a JSON list. Each comes in as <strong>Testers only</strong>: consoles marked as testers get it right away, and it stays out of the public catalog and the website until you set it to Everyone and publish.</p>
  </div>
</section>
<?php if ($error !== ''): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<?php if ($step === 'start' || ($step === 'preview' && !$rows)): ?>
<section class="card">
  <form method="post" enctype="multipart/form-data" class="drop">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="preview">
    <label>A JSON file<input type="file" name="file" accept=".json,application/json"></label>
    <label><span>Or paste it <span class="muted">(optional)</span></span><textarea name="json" rows="8" class="mono" placeholder='{"apps": [{"id": "PPSA12345", "name": "An emulator", "author": "someone", "category": "emulators", "tags": ["NES"], "blurb": "…", "description": "…", "repo": "someone/emulator-ps5", "asset": "Emulator-PS5-*.zip", "icon": "https://…/icon.png", "hero": "https://…/banner.jpg"}]}'><?= h((string)($_POST['json'] ?? '')) ?></textarea>
      <small>Each app needs a name and a GitHub repo. Its title ID and zip name too: leave them out and they're read from its newest release when you tick the box below (a .zip is looked into; a .7z can't be). Category is games, emulators, apps or tools (apps when it isn't given). Pictures from GitHub are copied here; others are linked as they are.</small></label>
    <label class="check"><input type="checkbox" name="lookup" value="1" checked> Look up missing title IDs and zip names on GitHub (a few seconds an app)</label>
    <div><button class="primary">Preview</button></div>
  </form>
</section>
<?php elseif ($step === 'preview'):
    $seen = [];
    $states = [];
    foreach ($rows as $i => $r) $states[$i] = rs_import_state($r, $knownIds, $knownRepos, $seen);
    $counts = array_count_values(array_column($states, 0));
?>
<section class="card">
  <p><strong><?= count($rows) ?> in the file:</strong>
    <?= (int)($counts['new'] ?? 0) ?> new, <?= (int)($counts['needs'] ?? 0) ?> need something filled in, <?= (int)($counts['skip'] ?? 0) ?> already here or twice (skipped)<?= !empty($counts['bad']) ? ', ' . (int)$counts['bad'] . ' that can\'t go in' : '' ?>.
    Fill in a title ID or zip name to bring one in; untick any you don't want.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="import">
    <div class="table-wrap"><table class="stats import">
      <thead><tr><th>Import</th><th>App</th><th>Title ID</th><th>Category</th><th>Repo and zip</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): [$state, $why] = $states[$i]; $off = $state === 'skip' || $state === 'bad'; ?>
        <tr class="<?= $off ? 'skip' : '' ?>">
          <td><input type="hidden" name="rows[<?= $i ?>][d]" value="<?= h(base64_encode((string)json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) ?>">
              <input type="checkbox" name="rows[<?= $i ?>][pick]" value="1" aria-label="Import <?= h($r['name']) ?>" <?= $off ? 'disabled' : 'checked' ?>></td>
          <td><div class="app"><?php if ($r['icon'] !== ''): ?><img src="<?= h($r['icon']) ?>" alt="" loading="lazy"><?php else: ?><span class="blank"></span><?php endif; ?>
              <span><strong><?= h($r['name'] ?: '(no name)') ?></strong><span class="muted"><?= h($r['author']) ?><?= $r['version'] !== '' ? ' · ' . h($r['version']) : '' ?></span></span></div></td>
          <td><input class="id" name="rows[<?= $i ?>][id]" value="<?= h($r['id']) ?>" maxlength="9" placeholder="Title ID" autocomplete="off" spellcheck="false" <?= $off ? 'disabled' : '' ?>></td>
          <td><select name="rows[<?= $i ?>][category]" <?= $off ? 'disabled' : '' ?>><?php foreach (RS_CATEGORIES as $ck => $cl): ?><option value="<?= h($ck) ?>" <?= $r['category'] === $ck ? 'selected' : '' ?>><?= h($cl) ?></option><?php endforeach; ?></select></td>
          <td><?php if (rs_repo_ok($r['repo'])): ?><a href="https://github.com/<?= h($r['repo']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['repo']) ?></a><?php else: ?><span class="muted"><?= h($r['repo'] ?: 'no repo') ?></span><?php endif; ?>
              <input class="id" style="width:220px;margin-top:6px" name="rows[<?= $i ?>][asset]" value="<?= h($r['asset']) ?>" maxlength="120" placeholder="Name-*.zip" autocomplete="off" spellcheck="false" <?= $off ? 'disabled' : '' ?>></td>
          <td class="why<?= $state === 'bad' ? ' bad' : '' ?>"><?= $state === 'new' ? '<span class="tag test">New</span> ' : ($state === 'needs' ? '<span class="tag">Needs</span> ' : '') ?><?= h($why) ?><?php if ($r['note'] !== '' && $state !== 'new'): ?><br><?= h($r['note']) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="sticky-save"><button class="primary">Import the ticked ones as Testers only</button><a class="btn" href="import.php">Start over</a></div>
  </form>
</section>
<?php else:
    $made = count(array_filter($results, fn($x) => $x[1] === 'made'));
?>
<section class="card">
  <h2><?= $made === 1 ? '1 app imported' : $made . ' apps imported' ?></h2>
  <p class="muted"><?= $made ? 'They\'re in the Games list as Testers only. Testers get them now, no Publish needed. Check each one\'s page, then set it to Everyone and publish when it\'s ready.' : 'Nothing new came in.' ?></p>
  <div class="table-wrap"><table class="stats import">
    <thead><tr><th>App</th><th>Title ID</th><th>What happened</th></tr></thead>
    <tbody>
    <?php foreach ($results as [$r, $what, $why]): ?>
      <tr class="<?= $what === 'made' ? '' : 'skip' ?>"><td><?= $what === 'made' ? '<a href="edit.php?id=' . urlencode($r['id']) . '">' . h($r['name']) . '</a>' : h($r['name'] ?: '(no name)') ?></td><td><code><?= h($r['id'] ?: '—') ?></code></td><td class="why<?= $what === 'bad' ? ' bad' : '' ?>"><?= h($why) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p><a class="btn" href="index.php">Go to Games</a> <a class="btn" href="import.php">Import more</a></p>
</section>
<?php endif; ?>
<?php rs_foot();
