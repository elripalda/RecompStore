<?php
// RecompStore admin (2.8): PC. The PC recomps on recompstore.com/pc: what the finder found on GitHub
// (Add puts one in, hidden, with its art), each one's page, and Publish, which writes pc-catalog.json.
// Nothing here touches the PS5 store.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_pc_tables();
@set_time_limit(180);

// (3.9) A player's log from a report, for you (never public). Its developer gets it from their hub.
if (isset($_GET['report_log'])) {
    $r = rs_play_report((int)$_GET['report_log']);
    if (!$r || empty($r['has_log'])) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('No log with that report.'); }
    rs_play_log_send($r);
}

$editId = (string)($_GET['edit'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'pc.php';
    try {
        if ($act === 'find') {
            [$n, $looked] = rs_pc_find(45);
            rs_flash($n ? 'Found ' . $n . ' new PC ' . ($n === 1 ? 'recomp' : 'recomps') . '.' : 'Nothing new' . ($looked ? ' (' . $looked . ' looked at)' : '') . '.');
            $back = 'pc.php?view=found';
        } elseif ($act === 'lookup') {
            $r = rs_pc_find_one((string)($_POST['repo'] ?? ''));
            rs_flash($r['ok'] ? ($r['name'] . ' is in the list below.') : $r['why'], $r['ok'] ? 'ok' : 'err');
            $back = 'pc.php?view=found';
        } elseif ($act === 'add') {
            $a = rs_pc_add((string)($_POST['repo'] ?? ''));
            rs_flash($a['name'] . ' is in, hidden. Look it over, then show it and Publish.');
            $back = 'pc.php?edit=' . rawurlencode($a['id']) . '#edit';
        } elseif ($act === 'skip' || $act === 'unskip') {
            rs_db()->prepare('UPDATE pc_found SET state = ? WHERE repo = ?')->execute([$act === 'skip' ? 'skip' : 'new', (string)($_POST['repo'] ?? '')]);
            $back = 'pc.php?view=found';
        } elseif ($act === 'save') {
            $a = rs_pc_app((string)($_POST['id'] ?? ''));
            if (!$a) throw new RuntimeException('That app is gone.');
            foreach (['name' => 120, 'game' => 120, 'repo' => 160, 'asset_win' => 160, 'asset_linux' => 160, 'asset_mac' => 160, 'blurb' => 160, 'adds' => 120, 'description' => 8000,
                'needs' => 200, 'credits' => 1000, 'author' => 80, 'tags' => 120, 'version' => 40, 'size' => 20, 'features' => 1200, 'media' => 5000, 'report_url' => 300] as $k => $max)
                $a[$k] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max);
            // (3.9) The developer's "how to report bugs" page: https only.
            if ($a['report_url'] !== '' && rs_pc_report_url($a['report_url']) === '') throw new RuntimeException('The bug report link has to be a full https:// address.');
            $a['origin'] = isset(RS_PC_ORIGINS[$_POST['origin'] ?? '']) ? (string)$_POST['origin'] : 'other';
            $a['genre'] = isset(RS_PC_GENRES[$_POST['genre'] ?? '']) ? (string)$_POST['genre'] : '';
            $a['dev'] = preg_match('/^[a-z0-9-]{1,40}$/', (string)($_POST['dev'] ?? '')) ? (string)$_POST['dev'] : '';
            $a['featured'] = !empty($_POST['featured']);
            // Setup required: needs Python, build tools or a long build first. Yellow Set Up in the launcher, a yellow badge and the steps on the site.
            $a['igdb_id'] = (int)preg_replace('/\D/', '', (string)($_POST['igdb_id'] ?? '')) ?: 0;
            $a['setup_required'] = !empty($_POST['setup_required']);
            $a['setup_steps'] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['setup_steps'] ?? ''))), 0, 2000);
            // (3.3) The boot profile: one JSON object, or empty for none.
            $boot = trim(str_replace("\r\n", "\n", (string)($_POST['boot'] ?? '')));
            if ($boot !== '') {
                $bj = json_decode($boot, true);
                if (!is_array($bj) || $boot[0] !== '{') throw new RuntimeException('The boot profile isn\'t valid JSON. It has to be one { ... } object.');
                if (strlen($boot) > 20000) throw new RuntimeException('The boot profile is too long.');
                $boot = json_encode($bj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $a['boot'] = $boot;
            $a = rs_ai_from_post($a); // (2.9) AI-assisted
            $wasSoon = !empty($a['soon']);
            $a['soon'] = !empty($_POST['soon']); // (2.9) no release yet: on Coming soon only
            $wasHidden = !empty($a['hidden']);
            $a['hidden'] = empty($_POST['shown']);
            // (2.9) The moment it first goes live (or comes out of Coming soon with its first release)
            // puts it at the front of Newest arrivals.
            if (!$a['hidden'] && empty($_POST['soon']) && (($wasHidden && (string)($a['live_at'] ?? '') === '') || $wasSoon)) $a['live_at'] = gmdate('Y-m-d\TH:i:s\Z');
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($_POST['live_at'] ?? ''))) $a['live_at'] = (string)$_POST['live_at'];
            if ($a['repo'] !== '' && !rs_repo_ok($a['repo'])) throw new RuntimeException('The GitHub repo goes as owner/name.');
            foreach (['icon_file' => 'icon', 'hero_file' => 'hero', 'logo_file' => 'logo'] as $field => $kind) {
                if (empty($_FILES[$field]['name'])) continue;
                $out = rs_save_image($_FILES[$field], $kind, 'pc-' . $a['id']);
                if ($kind === 'hero') $a['hero'] = $out['hero_web'] ?? $out['hero']; else $a[$kind] = $out[$kind];
            }
            if (!empty($_FILES['cover_file']['name'])) {
                $tmp = $_FILES['cover_file']['tmp_name'];
                $a = array_merge($a, rs_pc_media_file($tmp, $a['id']));
            }
            if (!empty($_POST['art'])) {
                // "Different art" moves on to the next cover and banner SteamGridDB has; the logo stays.
                $next = $_POST['art'] === 'next';
                $a['art_n'] = $next ? (int)($a['art_n'] ?? 0) + 1 : 0;
                $art = rs_sgdb_art($a['game'] !== '' ? $a['game'] : $a['name'], rs_pc_art_used($a['id']), $a['art_n']);
                $got = [];
                foreach ($next ? ['cover', 'hero'] : ['cover', 'hero', 'logo', 'icon'] as $k)
                    if ($art[$k] !== '' && ($m = rs_pc_media($art[$k], $k, $a['id']))) { unset($m['hero_web']); $a = array_merge($a, $m); $a[$k . '_src'] = $art[$k]; $got[] = $k; }
                rs_flash($got ? 'Art from SteamGridDB: ' . implode(', ', $got) . '.' : (rs_sgdb_key() === '' ? 'Add your SteamGridDB key in Settings first.' : 'SteamGridDB has no art for "' . ($a['game'] ?: $a['name']) . '". Try the game\'s exact name.'), $got ? 'ok' : 'err');
            }
            rs_pc_save($a);
            rs_log('change', 'pc ' . $a['id'], 'saved');
            if (!empty($_POST['readme'])) {
                $a = rs_pc_fill_features($a, true);
                rs_pc_save($a);
                rs_flash($a['features'] !== '' ? 'Key features from the README: ' . count(explode("\n", $a['features'])) . '.' : 'Its README has no Features list. Write a few lines yourself.', $a['features'] !== '' ? 'ok' : 'err');
            }
            if (!empty($_POST['about'])) {
                $ab = rs_pc_about_fetch($a, (int)($a['igdb_id'] ?? 0));
                if ($ab) { $a['about'] = $ab; unset($a['about_none']); } else $a['about_none'] = gmdate('Y-m-d');
                rs_pc_save($a);
                rs_flash($ab ? 'About the game: ' . $ab['name'] . ($ab['released'] ?? '' ? ' (' . substr($ab['released'], 0, 4) . ')' : '') . ' from IGDB.' : 'IGDB has no close match. Put its IGDB game ID in and try again.', $ab ? 'ok' : 'err');
            }
            if (empty($_POST['art']) && empty($_POST['readme']) && empty($_POST['about'])) rs_flash('Saved. Publish when you\'re ready.');
            $back = 'pc.php?edit=' . rawurlencode($a['id']) . '#edit';
        } elseif ($act === 'art_upgrade') {
            // (3.5) Sharper art for the ones listed: [{id, hero: width now, logo: width now}, ...]
            @set_time_limit(150);
            $res = [];
            foreach (array_slice((array)json_decode((string)($_POST['list'] ?? '[]'), true), 0, 6) as $it) {
                $x = rs_pc_app((string)($it['id'] ?? ''));
                if (!$x) { $res[] = ($it['id'] ?? '?') . ': gone'; continue; }
                [$x, $done] = rs_pc_art_upgrade($x, array_intersect_key((array)$it, ['hero' => 1, 'logo' => 1]));
                if ($done && ($y = rs_pc_app($x['id']))) { foreach ($done as $k) { $y[$k] = $x[$k]; $y[$k . '_src'] = $x[$k . '_src']; } rs_pc_save($y); }
                $res[] = $x['id'] . ': ' . ($done ? implode(', ', $done) : 'nothing bigger');
            }
            header('Content-Type: application/json');
            echo json_encode(['res' => $res]);
            exit;
        } elseif ($act === 'about_batch') {
            // (3.5) Fills "About the game" from IGDB for the ones that don't have it yet, a few at a time.
            @set_time_limit(120);
            $n = max(1, min(20, (int)($_POST['n'] ?? 10))); $redo = !empty($_POST['redo']);
            $todo = array_values(array_filter(rs_pc_apps(), fn($x) => empty($x['about']) && ($redo || empty($x['about_none']))));
            $got = []; $miss = [];
            foreach (array_slice($todo, 0, $n) as $x) {
                try { $ab = rs_pc_about_fetch($x, (int)($x['igdb_id'] ?? 0)); } catch (Throwable $e) { header('Content-Type: application/json'); echo json_encode(['error' => $e->getMessage()]); exit; }
                $x = rs_pc_app($x['id']) ?? $x; // saved on top of the newest copy, so edits made meanwhile stay
                if ($ab) { $x['about'] = $ab; unset($x['about_none']); $got[] = $x['id'] . ' = ' . $ab['name']; } else { $x['about_none'] = gmdate('Y-m-d'); $miss[] = $x['id']; }
                rs_pc_save($x);
            }
            header('Content-Type: application/json');
            echo json_encode(['got' => $got, 'miss' => $miss, 'left' => max(0, count($todo) - $n)]);
            exit;
        } elseif ($act === 'fb-hide' || $act === 'fb-show') {
            // (3.9) Reports are the game page's (play_reports): the website's and RecompLauncher's together.
            // One from RecompLauncher hides at its source too, so the copy doesn't come back.
            rs_play_tables();
            $db = rs_db(); $rid = (int)($_POST['fb'] ?? 0); $on = $act === 'fb-hide' ? 1 : 0;
            $db->prepare('UPDATE play_reports SET hidden = ?, flags = IF(? = 0, 0, flags) WHERE id = ?')->execute([$on, $on, $rid]);
            if (!$on) $db->prepare('DELETE FROM play_flags WHERE report_id = ?')->execute([$rid]);
            $src = $db->prepare('SELECT source, legacy_id FROM play_reports WHERE id = ?'); $src->execute([$rid]); $sr = $src->fetch();
            if ($sr && $sr['source'] === 'pcfb') $db->prepare('UPDATE pc_feedback SET hidden = ? WHERE id = ?')->execute([$on, (int)$sr['legacy_id']]);
            rs_play_scores_forget();
            rs_flash($act === 'fb-hide' ? 'Hidden from its page.' : 'Back on its page.');
            $back = ($_POST['from'] ?? '') === 'reports' ? 'pc.php?view=reports' : 'pc.php?edit=' . rawurlencode((string)($_POST['id'] ?? '')) . '#feedback';
        } elseif ($act === 'delete') {
            rs_db()->prepare('DELETE FROM pc_apps WHERE id = ?')->execute([(string)($_POST['id'] ?? '')]);
            rs_flash('Deleted from the PC store. Publish to take it off the site.');
        } elseif ($act === 'publish') {
            $n = rs_pc_publish();
            rs_flash('Published: ' . $n . ($n === 1 ? ' PC recomp is' : ' PC recomps are') . ' on recomplauncher.com.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$apps = rs_pc_apps();
$found = rs_pc_found('new');
$skipped = rs_pc_found('skip');
$lastFind = rs_kv_get('pc_find_run');
$devs = rs_developers();
$edit = $editId !== '' ? rs_pc_app($editId) : null;
$origin = fn(string $k) => RS_PC_ORIGINS[$k] ?? 'Other';
$sys = fn(array $a) => implode(' · ', array_keys(array_filter(['Windows' => $a['asset_win'], 'Linux' => $a['asset_linux'], 'macOS' => $a['asset_mac']])));
$published = is_file(RS_PC_CATALOG) ? (int)filemtime(RS_PC_CATALOG) : 0;
// (3.5) The list shows small copies of the covers (made once, then kept), not the full pictures.
// (3.9) Player reports on the game pages, from the website and RecompLauncher: what they said, their
// PC, a log if they sent one (Download log is only here and in the developer's hub), and the answers.
$pcReports = function (?string $app, int $limit): array {
    rs_acct_tables();
    rs_play_sync_legacy();
    $st = rs_db()->prepare("SELECT r.*, a.username FROM play_reports r LEFT JOIN accounts a ON a.id = r.account_id WHERE r.kind = 'pc'" . ($app !== null ? ' AND r.app = ?' : '') . ' ORDER BY r.updated DESC LIMIT ' . $limit);
    $st->execute($app !== null ? [$app] : []);
    return $st->fetchAll();
};
$pcNames = array_column($apps, 'name', 'id');
$pcReportLi = function (array $x, string $appId, string $from, array $replies) use ($pcNames): string {
    $rep = $replies[(int)$x['id']] ?? [];
    $specs = rs_play_specs_line($x);
    $probs = implode(', ', array_map(fn($k) => RS_PLAY_PROBLEMS[$k] ?? $k, array_keys(rs_play_problems_decode((string)$x['problems']))));
    $o = '<li class="' . ($x['hidden'] ? 'is-hidden' : '') . '">'
        . '<span class="pill ' . ($x['verdict'] === 'runs' ? 'ok' : ($x['verdict'] === 'issues' ? 'warn' : 'bad')) . '">' . h(RS_PLAY_VERDICTS[$x['verdict']] ?? $x['verdict']) . '</span> '
        . '<span class="muted">' . ($from === 'reports' ? '<a href="pc.php?edit=' . h(rawurlencode($appId)) . '#feedback">' . h($pcNames[$appId] ?? $appId) . '</a> · ' : '')
        . h(RS_PLAY_PC_SYS[$x['sys']] ?? $x['sys']) . ($x['version'] !== '' ? ' · ' . h($x['version']) : '') . ' · ' . h(rs_date('M j, g:i a', strtotime((string)$x['updated'] . ' UTC')))
        . ' · ' . ($x['source'] === 'pcfb' ? 'RecompLauncher' : 'website') . ($x['account_id'] !== null ? ' · account ' . h((string)($x['username'] ?? '#' . $x['account_id'])) : ' · guest') . '</span>'
        . ($probs !== '' ? '<p><strong>Problems:</strong> ' . h($probs) . '</p>' : '')
        . ($x['note'] !== '' ? '<p>' . nl2br(h((string)$x['note'])) . '</p>' : '')
        . ($x['fix'] !== '' ? '<p><strong>What helped:</strong> ' . h((string)$x['fix']) . '</p>' : '')
        . ($specs !== '' ? '<p class="muted"><strong>PC:</strong> ' . h($specs) . '</p>' : '')
        . (!empty($x['has_log']) ? '<p><a href="pc.php?report_log=' . (int)$x['id'] . '">Download log</a> <span class="muted">' . h((string)$x['log_name']) . '</span></p>' : '');
    foreach ($rep as $r) $o .= '<p class="muted"><strong>' . ($r['dev'] ? h((string)$r['name']) . ' (developer)' : 'Player') . ':</strong> ' . nl2br(h($r['text'])) . '</p>';
    return $o . '<form method="post"><input type="hidden" name="csrf" value="' . h(rs_csrf()) . '"><input type="hidden" name="action" value="' . ($x['hidden'] ? 'fb-show' : 'fb-hide') . '"><input type="hidden" name="fb" value="' . (int)$x['id'] . '"><input type="hidden" name="id" value="' . h($appId) . '">'
        . ($from !== '' ? '<input type="hidden" name="from" value="' . h($from) . '">' : '') . '<button>' . ($x['hidden'] ? 'Show' : 'Hide') . '</button></form></li>';
};
$thumb = fn(string $u, int $w): string => preg_match('#/media/([A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(jpe?g|png|webp))$#i', $u, $m) ? '/api/thumb.php?f=' . rawurlencode($m[1]) . '&w=' . $w : $u;

rs_head('PC', true, ['assets/health.css', 'assets/artpick.css', 'assets/vt.css']);
?>
<section class="head-row">
  <div>
    <h1>RecompStore for PC</h1>
    <p class="muted">PC recomps on <a href="https://recomplauncher.com/" target="_blank">recomplauncher.com</a> (RecompLauncher), kept apart from the PS5 store. Only each developer's own GitHub release is ever listed: no game files, players bring their own copy. <?= $published ? 'Last published ' . h(rs_date('M j, g:i a', $published)) . '.' : 'Not published yet.' ?></p>
  </div>
  <div class="actions">
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish PC</button></form>
  </div>
</section>

<?php if ($edit): $a = $edit; ?>
<section class="card" id="edit">
  <div class="an-head"><h2><?= h($a['name']) ?></h2><a class="an-csv an-plain" href="pc.php">All PC recomps</a></div>
  <?php // (3.8) Its release files, as VirusTotal saw them, and a held update.
  if (function_exists('rs_vt_on') && rs_vt_on()): $vb = rs_vt_repo_badge($a['repo'], (string)($a['pending']['tag'] ?? ($a['ok_tag'] ?? ''))); ?>
  <p class="vt-line"><?= $vb ? rs_vt_badge_html($vb) : '<span class="vt-badge grey"><span class="vt-dot">…</span>Not checked yet</span>' ?>
    <?php if (!empty($a['pending']['tag'])): ?> <a href="security.php">Update to <?= h($a['pending']['version'] ?: $a['pending']['tag']) ?> is on hold</a><?php endif; ?></p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
    <label>Name<input name="name" value="<?= h($a['name']) ?>" maxlength="120"><small>The project's name, like Zelda 64: Recompiled.</small></label>
    <label>The game<input name="game" value="<?= h($a['game']) ?>" maxlength="120"><small>The original game, like The Legend of Zelda: Majora's Mask.</small></label>
    <label>From<select name="origin"><?php foreach (RS_PC_ORIGINS as $k => $n): ?><option value="<?= h($k) ?>"<?= $a['origin'] === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label>Kind of game<select name="genre"><option value="">Pick one</option><?php foreach (RS_PC_GENRES as $k => $n): ?><option value="<?= h($k) ?>"<?= $a['genre'] === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select><small>Each kind is a category on the PC store, with its own row and page.</small></label>
    <label>GitHub repo<input name="repo" value="<?= h($a['repo']) ?>" class="mono" placeholder="owner/name"></label>
    <label>Windows download<input name="asset_win" value="<?= h($a['asset_win']) ?>" class="mono" placeholder="Zelda64Recompiled-*-Windows.zip"><small>The release file, * for the part that changes.</small></label>
    <label>Linux download<input name="asset_linux" value="<?= h($a['asset_linux']) ?>" class="mono"></label>
    <label>macOS download<input name="asset_mac" value="<?= h($a['asset_mac']) ?>" class="mono"></label>
    <label>Version<input name="version" value="<?= h($a['version']) ?>" maxlength="40"><small>Updated from GitHub on Publish.</small></label>
    <label class="span2">One-line summary<input name="blurb" value="<?= h($a['blurb']) ?>" maxlength="160"></label>
    <label class="span2">What this version adds<input name="adds" value="<?= h($a['adds'] ?? '') ?>" maxlength="120" placeholder="DLSS, FSR 4 and a 13-language launcher"><small>For games with more than one version: a few words on what sets this one apart. Shown in the versions list.</small></label>
    <label class="span2">Description<textarea name="description" rows="7" maxlength="8000"><?= h($a['description']) ?></textarea><small>What it is, what works, how to set it up. Markdown: ## headings, - lists, **bold**, links.</small></label>
    <label>What players need<input name="needs" value="<?= h($a['needs']) ?>" maxlength="200"><small>Like: Your own US copy of Majora's Mask (a .z64 ROM).</small></label>
    <label>Credits<input name="credits" value="<?= h($a['credits']) ?>" maxlength="1000"></label>
    <label>Made by<input name="author" value="<?= h($a['author']) ?>" maxlength="80"></label>
    <label>Developer page<select name="dev"><option value="">None</option><?php foreach ($devs as $slug => $d): ?><option value="<?= h($slug) ?>"<?= $a['dev'] === $slug ? ' selected' : '' ?>><?= h((string)$d['name']) ?></option><?php endforeach; ?></select></label>
    <label>Labels<input name="tags" value="<?= h($a['tags']) ?>" maxlength="120" placeholder="Recomp, 4K, Mods"><small>Shown as pills on its page, comma between.</small></label>
    <label>How to report bugs<input name="report_url" value="<?= h((string)($a['report_url'] ?? '')) ?>" maxlength="300" class="mono" placeholder="https://github.com/owner/name/issues/new/choose"><small>The developer's own page for bug reports. Shown next to the report form. https only.</small></label>
    <label class="span2">Key features<textarea name="features" rows="5" maxlength="1200" placeholder="One per line"><?= h($a['features']) ?></textarea><small>One per line, shown at the top of its page. Picked up from the README's Features list when it was added.</small></label>
    <label class="span2">Around the web<textarea name="media" rows="4" maxlength="5000" placeholder="https://www.youtube.com/watch?v=…&#10;https://www.reddit.com/r/…/comments/…&#10;https://x.com/…/status/…"><?= h($a['media']) ?></textarea><small>Videos, posts and articles about it, one link per line. YouTube shows as a video, Reddit and X as cards.</small></label>
    <label class="span2">Boot profile<textarea name="boot" rows="8" maxlength="20000" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;font-size:13px" placeholder='{ "kind": "portable", "start": "Game.exe", "start_is": "game", "files": { "how": "folder", ... } }'><?= h((string)($a['boot'] ?? '')) ?></textarea><small>How RecompLauncher starts it, sets it up from the player's copy (Easy Setup) and which of its settings to show. One JSON object, per the curator setup guide. Empty: the launcher works it out.</small></label>
    <div class="checks"><label class="check"><input type="checkbox" name="shown" value="1"<?= $a['hidden'] ? '' : ' checked' ?>> Show it on the site</label><label class="check"><input type="checkbox" name="featured" value="1"<?= $a['featured'] ? ' checked' : '' ?>> Featured</label><label class="check" title="Being worked on, nothing to download yet: it shows only on Coming soon"><input type="checkbox" name="soon" value="1"<?= !empty($a['soon']) ? ' checked' : '' ?>> Coming soon</label><label class="check" title="Needs Python, build tools or a long build first: a yellow Set Up instead of Play"><input type="checkbox" name="setup_required" value="1"<?= !empty($a['setup_required']) ? ' checked' : '' ?>> Setup required</label></div>
    <label>Setup steps<textarea name="setup_steps" rows="5" maxlength="2000"><?= h((string)($a['setup_steps'] ?? '')) ?></textarea><small>Only for Setup required. One step per line, in order. Shown with the yellow Set Up button and on its page, with a link to the README.</small></label>
    <?php $ab = is_array($a['about'] ?? null) ? $a['about'] : []; ?>
    <div class="span2 pc-about-adm"><strong>About the game</strong> <span class="muted"><?= $ab ? h($ab['name'] . (!empty($ab['released']) ? ' · ' . substr($ab['released'], 0, 4) : '') . (!empty($ab['developer']) ? ' · ' . $ab['developer'] : '')) . ' · <a href="' . h($ab['url'] ?? '') . '" target="_blank">IGDB</a>' : (!empty($a['about_none']) ? 'No close match on IGDB.' : 'Not looked up yet.') ?></span>
      <label>IGDB game ID<input name="igdb_id" value="<?= (int)($a['igdb_id'] ?? 0) ?: '' ?>" inputmode="numeric" placeholder="Only if it picked the wrong game"><small>The number from the game's IGDB page (bottom of the page, "IGDB ID").</small></label>
      <button name="about" value="1">Get game info from IGDB</button></div>
    <?= rs_ai_fieldset($a, (string)$a['repo']) ?>
    <div class="span2 pc-art">
      <?php foreach (['cover' => 'Cover (600×900)', 'hero' => 'Backdrop', 'logo' => 'Logo', 'icon' => 'Icon'] as $k => $n): ?>
      <label class="pc-art-<?= $k ?>"><span class="pc-art-box"><?= $a[$k] !== '' ? '<img src="' . h($a[$k]) . '" alt="">' : '<span class="muted">None</span>' ?></span><?= h($n) ?><input type="file" name="<?= $k ?>_file" accept="image/png,image/jpeg,image/webp"></label>
      <?php endforeach; ?>
    </div>
    <div class="actions span2"><button class="primary">Save</button><button name="art" value="1"<?= rs_sgdb_key() === '' ? ' disabled title="Add your SteamGridDB key in Settings"' : '' ?>>Get art from SteamGridDB</button><button name="art" value="next"<?= rs_sgdb_key() === '' ? ' disabled' : '' ?> title="The next cover and banner SteamGridDB has for it">Different art</button><button name="readme" value="1">Key features from the README</button><?php if ($a['repo'] !== ''): ?><a class="btn" href="https://github.com/<?= h($a['repo']) ?>" target="_blank" rel="noopener">Its GitHub</a><?php endif; ?></div>
  </form>
  <?php $fb = $pcReports($a['id'], 200); ?>
  <h3 id="feedback">Player reports <span class="pill<?= $fb ? ' cyan' : '' ?>"><?= count($fb) ?></span></h3>
  <?php if (!$fb): ?><p class="muted">Nobody has said how it runs yet.</p><?php else: ?>
  <ul class="pc-fb"><?php $fbRep = rs_play_replies(array_column($fb, 'id')); foreach ($fb as $x) echo $pcReportLi($x, $a['id'], '', $fbRep); ?></ul>
  <?php endif; ?>
  <form method="post" class="pc-del" onsubmit="return confirm('Delete <?= h(addslashes($a['name'])) ?> from the PC store?')"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="danger">Delete</button></form>
</section>
<?php endif; ?>

<?php $view = (string)($_GET['view'] ?? ''); $counts = rs_pc_feedback_counts(); ?>
<?php if ($view === ''): ?>
<section class="card">
  <div class="found-head"><h2>In the PC store <span class="pill cyan"><?= count($apps) ?></span></h2><label class="find pc-find-box"><input type="search" placeholder="Find a recomp" data-pc-find autocomplete="off"></label></div>
  <?php if (!$apps): ?><p class="muted">Nothing yet. Add what the finder found, under Found on GitHub.</p><?php else: ?>
  <div class="pc-cards">
    <?php foreach ($apps as $a): $c = $counts[$a['id']] ?? []; $n = array_sum($c); ?>
    <a class="pc-card<?= $a['hidden'] ? ' dim' : '' ?>" href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>#edit" data-text="<?= h(mb_strtolower($a['name'] . ' ' . $a['game'] . ' ' . $a['repo'])) ?>">
      <span class="pc-card-cover"><?= $a['cover'] ? '<img src="' . h($thumb($a['cover'], 256)) . '" alt="" loading="lazy" decoding="async">' : '<span>' . h(mb_substr($a['name'], 0, 2)) . '</span>' ?><?= $a['hidden'] ? '<em>Hidden</em>' : (!empty($a['soon']) ? '<em>Soon</em>' : ($a['featured'] ? '<em class="ok">Featured</em>' : '')) ?></span>
      <span class="pc-card-words"><strong><?= h($a['name']) ?></strong><small><?= h($a['game'] ?: '?') ?></small>
        <small class="muted"><?= h($origin($a['origin'])) ?> · <?= h(RS_PC_GENRES[$a['genre']] ?? 'No kind yet') ?></small>
        <small class="muted"><?= h($a['version'] ?: 'no release') ?><?= $n ? ' · ' . $n . ($n === 1 ? ' report' : ' reports') : '' ?></small></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php elseif ($view === 'found'): ?>
<section class="card" id="found">
  <div class="found-head">
    <h2>Found on GitHub <span class="pill<?= $found ? ' cyan' : '' ?>"><?= count($found) ?></span></h2>
    <div class="found-acts">
      <form method="post" class="pc-lookup"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="lookup"><input name="repo" placeholder="github.com/owner/repo" class="mono" required><button>Look up</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="find"><button>Look again</button></form>
    </div>
  </div>
  <p class="muted small-note">Repos that look like PC recomps, with a download in their latest release. Looked for every morning<?= $lastFind ? ', last ' . h(rs_date('M j, g:i a', (int)$lastFind['v'])) : '' ?>. Check each one is the developer's own build with no game files in it, and that it plays well, before you add it.</p>
  <?php if (!$found): ?><p class="muted">Nothing waiting.</p><?php else: ?>
  <div class="ftable" role="table">
    <div class="ftr fth" role="row"><span>Project</span><span>Console</span><span>Release</span><span>Stars</span><span></span></div>
    <?php foreach ($found as $f): $flags = (array)($f['flags'] ?? []); ?>
    <div class="ftr" role="row">
      <span class="ft-name"><?= !empty($f['avatar']) ? '<img src="' . h($f['avatar']) . '" alt="" loading="lazy">' : '<i></i>' ?><span><a href="https://github.com/<?= h($f['repo']) ?>" target="_blank" rel="noopener"><?= h($f['repo']) ?></a><small title="<?= h((string)($f['description'] ?? '')) ?>"><?= h($f['game'] ?: mb_strimwidth((string)($f['description'] ?? ''), 0, 80, '…')) ?></small><?php if (!empty($flags['gamefiles']) || !empty($flags['huge'])): ?><small class="bad-text"><?= !empty($flags['gamefiles']) ? 'Has a file that looks like game data' : 'Has a very large file' ?></small><?php endif; ?></span></span>
      <span><?= h($origin((string)$f['origin'])) ?></span>
      <span><?= h($f['version'] ?: 'none') ?><small class="muted"><?= h(implode(', ', array_keys(array_filter((array)$f['assets'])))) ?></small></span>
      <span><?= (int)$f['stars'] ?></span>
      <span class="ft-btns">
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="repo" value="<?= h($f['repo']) ?>"><button class="primary">Add</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="skip"><input type="hidden" name="repo" value="<?= h($f['repo']) ?>"><button title="Not a PC recomp, or not good enough">Skip</button></form>
      </span>
      <?php // (3.8) Pre-checked: VirusTotal, already listed, game data, how recent.
      $fn = function_exists('rs_auto_find_notes') ? rs_auto_find_notes($f) : []; $fb = function_exists('rs_vt_repo_badge') && rs_vt_on() ? rs_vt_repo_badge((string)$f['repo']) : null; if ($fn || $fb): ?>
      <span class="ft-notes"><?= $fb ? rs_vt_badge_html($fb) : '' ?><?php foreach ($fn as [$k, $t]): ?><em class="fn <?= $k ?>"><?= h($t) ?></em><?php endforeach; ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($skipped): ?>
  <details class="hl-fine"><summary>Skipped (<?= count($skipped) ?>)</summary>
    <ul class="pc-skipped"><?php foreach ($skipped as $f): ?><li><a href="https://github.com/<?= h($f['repo']) ?>" target="_blank" rel="noopener"><?= h($f['repo']) ?></a> <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="unskip"><input type="hidden" name="repo" value="<?= h($f['repo']) ?>"><button>Put back</button></form></li><?php endforeach; ?></ul>
  </details>
  <?php endif; ?>
</section>
<?php else: $all = $pcReports(null, 300); ?>
<section class="card">
  <h2>Player reports <span class="pill<?= $all ? ' cyan' : '' ?>"><?= count($all) ?></span></h2>
  <p class="muted small-note">What players say about how each recomp plays, newest first. Hide anything rude or spammy; it comes off the recomp's page.</p>
  <?php if (!$all): ?><p class="muted">No reports yet.</p><?php else: ?>
  <ul class="pc-fb"><?php $allRep = rs_play_replies(array_column($all, 'id')); foreach ($all as $x) echo $pcReportLi($x, (string)$x['app'], 'reports', $allRep); ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>
<script>
(function () {
  var box = document.querySelector('[data-pc-find]');
  if (!box) return;
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    document.querySelectorAll('.pc-card[data-text]').forEach(function (c) { c.hidden = q !== '' && c.dataset.text.indexOf(q) < 0; });
  });
})();
</script>
<?php rs_foot(['assets/artpick.js']);
