<?php
// RecompStore admin (2.8): PC. The PC recomps on recompstore.com/pc: what the finder found on GitHub
// (Add puts one in, hidden, with its art), each one's page, and Publish, which writes pc-catalog.json.
// Nothing here touches the PS5 store.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/boot.php';
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
// (4.3) The boot profile editor's check as you type. It changes nothing, so it isn't in the change log.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'boot_check') {
    rs_session();
    header('Content-Type: application/json');
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) { http_response_code(400); exit('{"problems":[["err","This page expired. Reload it."]]}'); }
    $bj = json_decode((string)($_POST['boot'] ?? ''), true);
    if (!is_array($bj)) exit(json_encode(['problems' => [['err', 'Not valid JSON.']]]));
    exit(json_encode(['problems' => rs_boot_check($bj), 'uses' => rs_boot_uses($bj), 'summary' => rs_boot_summary($bj)], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'pc.php';
    try {
        if ($act === 'find') {
            [$n, $looked] = rs_pc_find(45);
            try { [$dn, $dl] = rs_pc_find_discord(25); $n += $dn; $looked += $dl; } catch (Throwable $e) { /* GitHub's still counted */ } // (4.1)
            try { [$qn, $ql] = rs_pc_find_quiver(25, 3); $n += $qn; $looked += $ql; } catch (Throwable $e) { /* the rest still counted */ } // (4.3)
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
        } elseif ($act === 'add_soon') {
            $a = rs_pc_add_soon((string)($_POST['name'] ?? ''), (string)($_POST['follow'] ?? ''));
            rs_flash($a['name'] . ' is in, on Coming soon and hidden. Set its game and art, then show it and Publish.');
            $back = 'pc.php?edit=' . rawurlencode($a['id']) . '#edit';
        } elseif ($act === 'skip' || $act === 'unskip') {
            rs_db()->prepare('UPDATE pc_found SET state = ? WHERE repo = ?')->execute([$act === 'skip' ? 'skip' : 'new', (string)($_POST['repo'] ?? '')]);
            $back = 'pc.php?view=found';
        } elseif ($act === 'save') {
            $a = rs_pc_app((string)($_POST['id'] ?? ''));
            if (!$a) throw new RuntimeException('That app is gone.');
            $macBefore = (string)$a['asset_mac'];
            $keyBefore = rs_pc_key($a); // (4.3)
            foreach (['name' => 120, 'game' => 120, 'repo' => 160, 'asset_win' => 160, 'asset_linux' => 160, 'asset_mac' => 160, 'blurb' => 160, 'adds' => 120, 'description' => 8000,
                'needs' => 200, 'credits' => 1000, 'author' => 80, 'tags' => 120, 'version' => 40, 'size' => 20, 'features' => 1200, 'media' => 5000, 'report_url' => 300, 'follow' => 300] as $k => $max)
                $a[$k] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max);
            // (4.2) A new Mac download has to be one RecompLauncher on Mac can install (a .zip, not Intel-only).
            if ($a['asset_mac'] !== $macBefore && ($why = rs_pc_mac_why($a['asset_mac'])) !== '') throw new RuntimeException($why . ' Leave it empty if there isn\'t one.');
            // (3.9) The developer's "how to report bugs" page: https only.
            if ($a['report_url'] !== '' && rs_pc_report_url($a['report_url']) === '') throw new RuntimeException('The bug report link has to be a full https:// address.');
            if ($a['follow'] !== '' && rs_pc_report_url($a['follow']) === '') throw new RuntimeException('The follow link has to be a full https:// address.');
            $a['origin'] = isset(RS_PC_ORIGINS[$_POST['origin'] ?? '']) ? (string)$_POST['origin'] : 'other';
            $a['genre'] = isset(RS_PC_GENRES[$_POST['genre'] ?? '']) ? (string)$_POST['genre'] : '';
            $a['dev'] = preg_match('/^[a-z0-9-]{1,40}$/', (string)($_POST['dev'] ?? '')) ? (string)$_POST['dev'] : '';
            // (4.0) Why it can't be listed yet (no Windows build...): only you see it, and it
            // puts the recomp under Not compatible instead of Hidden.
            $a['blocked_why'] = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($_POST['blocked_why'] ?? ''))), 0, 200);
            // (4.0) Project status: on hold, or ended (no more updates). Empty: active, or worked out from GitHub.
            $a['status'] = in_array((string)($_POST['status'] ?? ''), RS_PC_STATUSES, true) ? (string)$_POST['status'] : '';
            $a['status_note'] = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($_POST['status_note'] ?? ''))), 0, 200);
            $a['featured'] = !empty($_POST['featured']);
            // Setup required: needs Python, build tools or a long build first. Yellow Set Up in the launcher, a yellow badge and the steps on the site.
            $a['igdb_id'] = (int)preg_replace('/\D/', '', (string)($_POST['igdb_id'] ?? '')) ?: 0;
            $a['setup_required'] = !empty($_POST['setup_required']);
            // (4.3) Only a setup .exe in its releases: the launcher runs it, then finds where it installed.
            $a['installer'] = !empty($_POST['installer']);
            if ($a['installer']) $a['setup_required'] = true;
            $a['setup_steps'] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['setup_steps'] ?? ''))), 0, 2000);
            // (4.7) Several games in one recomp: one per line, "id | name | launch args | title id | inputs".
            if (isset($_POST['games'])) {
                [$pg, $pgErr] = rs_pc_games_parse((string)$_POST['games'], (array)($a['games'] ?? []));
                if ($pgErr !== '') throw new RuntimeException('Games in this recomp: ' . $pgErr);
                if ($pg) $a['games'] = $pg; else unset($a['games']);
                if ($pg && (!empty($_POST['games_art']) || array_filter($pg, fn($g) => (string)($g['cover'] ?? '') === ''))) {
                    [$a, $pgArt] = rs_pc_games_art($a, !empty($_POST['games_art']));
                    if (!empty($_POST['games_art'])) rs_flash('Art for ' . $pgArt . ' of ' . count($pg) . ' games from SteamGridDB.', $pgArt ? 'ok' : 'err');
                }
            }
            // (3.3) The boot profile: one JSON object, or empty for none.
            $boot = trim(str_replace("\r\n", "\n", (string)($_POST['boot'] ?? '')));
            if ($boot !== '') {
                $bj = json_decode($boot, true);
                if (!is_array($bj) || $boot[0] !== '{') throw new RuntimeException('The boot profile isn\'t valid JSON. It has to be one { ... } object.');
                if (strlen($boot) > 100000) throw new RuntimeException('The boot profile is too long.');
                // (4.3) Checked the way the launcher reads it: a mistake that would break it stops the save.
                $bootProbs = rs_boot_check($bj);
                $bootErr = array_values(array_filter($bootProbs, fn($p) => $p[0] === 'err'));
                if ($bootErr) throw new RuntimeException('The boot profile: ' . $bootErr[0][1] . (count($bootErr) > 1 ? ' (and ' . (count($bootErr) - 1) . ' more)' : ''));
                $bootWarn = count($bootProbs);
                $boot = json_encode($bj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $a['boot'] = $boot;
            // (4.1) The disc or cartridge IDs this recomp is made for (GZ2E01, SLUS-203.12...): the launcher
            // reads the player's copy and says so when it's another release.
            $a['copy_ids'] = implode(', ', rs_pc_copy_ids((string)($_POST['copy_ids'] ?? '')));
            $a = rs_ai_from_post($a); // (2.9) AI-assisted
            $wasSoon = !empty($a['soon']);
            $a['soon'] = !empty($_POST['soon']); // (2.9) no release yet: on Coming soon only
            $wasHidden = !empty($a['hidden']);
            $a['hidden'] = empty($_POST['shown']);
            // (2.9) The moment it first goes live (or comes out of Coming soon with its first release)
            // puts it at the front of Newest arrivals.
            if (!$a['hidden'] && empty($_POST['soon']) && (($wasHidden && (string)($a['live_at'] ?? '') === '') || $wasSoon)) $a['live_at'] = gmdate('Y-m-d\TH:i:s\Z');
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($_POST['live_at'] ?? ''))) $a['live_at'] = (string)$_POST['live_at'];
            // (3.9) GitHub or GitLab: a pasted link says which; else the host picked next to it.
            $a['host'] = rs_host_norm((string)($_POST['host'] ?? ''));
            if ($a['repo'] !== '') {
                $l = rs_repo_from_link($a['repo'], $a['host']);
                if (!$l) throw new RuntimeException(['gitlab' => 'The GitLab project goes as group/project (or group/sub/project).', 'sourceforge' => 'The SourceForge project goes as its short name (sourceforge.net/projects/<name>).'][$a['host']] ?? 'The GitHub repo goes as owner/name.');
                $a['repo'] = $l['repo']; $a['host'] = $l['host'];
            }
            // (4.3) Moved to another project (another host): nothing from the old one carries over, and
            // the new one's release is checked before it goes out, as with "Switch to it".
            if ($keyBefore !== '' && strcasecmp($keyBefore, rs_pc_key($a)) !== 0 && $a['repo'] !== '') {
                $a['ok_tag'] = 'moved:' . (string)($a['ok_tag'] ?? '');
                unset($a['pending']);
                $a['gh_at'] = 0;
                rs_log('pc', 'move', $a['id'] . ': ' . $keyBefore . ' -> ' . rs_pc_key($a));
            }
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
                $art = rs_sgdb_art($a['game'] !== '' ? $a['game'] : $a['name'], rs_pc_art_used($a['id']), $a['art_n'], rs_pc_about_year($a));
                $got = [];
                $m = rs_pc_media_many(array_intersect_key($art, array_flip($next ? ['cover', 'hero'] : ['cover', 'hero', 'logo', 'icon'])), $a['id']); // (4.1) at once
                $a = array_merge($a, $m);
                foreach (['cover', 'hero', 'logo', 'icon'] as $k) if (isset($m[$k . '_src'])) $got[] = $k;
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
            if (empty($_POST['art']) && empty($_POST['readme']) && empty($_POST['about'])) rs_flash('Saved. Publish when you\'re ready.' . (!empty($bootWarn) ? ' Its boot profile has ' . $bootWarn . ' thing' . ($bootWarn === 1 ? '' : 's') . ' the launcher will skip (shown under it).' : ''));
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
        } elseif ($act === 'dl-ok' || $act === 'moved-ok' || $act === 'move-repo') {
            // (4.0) The download or moved alerts on its edit page: fine as it is, or follow the move.
            $a = rs_pc_app((string)($_POST['id'] ?? ''));
            if (!$a) throw new RuntimeException('That app is gone.');
            if ($act === 'dl-ok') { $a['dl_ack'] = (string)($a['dl_state'] ?? '') . '|' . (string)($a['dl_why'] ?? ''); rs_flash('Marked as fine. It shows again only if something new comes up.'); }
            if ($act === 'moved-ok') { $a['moved_ack'] = (string)($a['moved_to'] ?? ''); rs_flash('Okay, it stays on ' . $a['repo'] . '.'); }
            if ($act === 'move-repo') {
                $to = (string)($a['moved_to'] ?? '');
                if ($to === '') throw new RuntimeException('There\'s no new repo to move to.');
                $a = rs_pc_switch_repo($a, $to);
                rs_flash('Moved to ' . $a['repo'] . '. Its next release is checked before it goes out; press Publish PC when you\'re ready.');
            }
            rs_pc_save($a);
            $back = 'pc.php?edit=' . rawurlencode($a['id']) . '#edit';
        } elseif ($act === 'publish') {
            $n = rs_pc_publish();
            rs_flash('Published: ' . $n . ($n === 1 ? ' PC recomp is' : ' PC recomps are') . ' on recomplauncher.com.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    // (4.0) Back on the same list page (search, filter, sort, page) after a save from the edit page.
    $lq = (string)($_POST['list'] ?? '');
    if ($lq !== '' && preg_match('/^[\w%=&.+-]{1,300}$/', $lq) && str_starts_with($back, 'pc.php?edit=')) {
        [$path, $hash] = array_pad(explode('#', $back, 2), 2, '');
        $back = $path . '&' . $lq . ($hash !== '' ? '#' . $hash : '');
    }
    header('Location: ' . $back);
    exit;
}

$apps = rs_pc_apps();
$view = (string)($_GET['view'] ?? '');
// (4.0) The finder's lists only on their own page: the recomps list doesn't wait on them.
$found = $view === 'found' ? rs_pc_found('new') : [];
$skipped = $view === 'found' ? rs_pc_found('skip') : [];
$lastFind = $view === 'found' ? rs_kv_get('pc_find_run') : null;
// (4.1) Links from the Discord server's #recomp-suggestions, and how the last read went.
$dcFind = $view === 'found' ? (json_decode((string)(rs_kv_get('pc_find_dc')['v'] ?? ''), true) ?: null) : null;
if ($view === 'found') rs_pc_find_discord_maybe();

// (4.0) The recomps list in pages: search, what to show, the order, and how many per page. The order
// and page size are remembered (a cookie) so the list comes back the way you left it.
$lsOpt = ['show' => ['all' => 'All', 'live' => 'Live', 'hidden' => 'Hidden', 'soon' => 'Coming soon', 'featured' => 'Featured', 'ended' => 'Ended or on hold', 'noart' => 'No cover', 'look' => 'Needs a look', 'blocked' => 'Not compatible', 'mac' => 'On Mac', 'macfix' => 'Mac needs a fix'],
    'sort' => ['new' => 'Newest', 'old' => 'Oldest', 'az' => 'A to Z', 'za' => 'Z to A', 'updated' => 'Recently updated', 'downloads' => 'Most downloads', 'reports' => 'Most reports'],
    'per' => [25, 50, 100, 200]];
$lsPick = function (string $k, $def) use ($lsOpt) {
    $v = $_GET[$k] ?? ($_COOKIE['rs_pc_' . $k] ?? $def);
    $ok = $k === 'per' ? in_array((int)$v, $lsOpt['per'], true) : isset($lsOpt[$k][(string)$v]);
    $v = $ok ? ($k === 'per' ? (int)$v : (string)$v) : $def;
    if (isset($_GET[$k]) && $ok && in_array($k, ['sort', 'per'], true)) setcookie('rs_pc_' . $k, (string)$v, ['expires' => time() + 31536000, 'path' => '/admin/', 'samesite' => 'Lax', 'secure' => true, 'httponly' => true]);
    return $v;
};
$ls = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80), 'show' => $lsPick('show', 'all'), 'sort' => $lsPick('sort', 'new'), 'per' => $lsPick('per', 50), 'page' => max(1, (int)($_GET['page'] ?? 1))];
$lsQs = fn(array $over = []): string => http_build_query(array_filter(array_merge(['q' => $ls['q'], 'show' => $ls['show'], 'sort' => $ls['sort'], 'per' => $ls['per'], 'page' => $ls['page']], $over), fn($v) => $v !== '' && $v !== null));
$devs = rs_developers();
$edit = $editId !== '' ? rs_pc_app($editId) : null;
$origin = fn(string $k) => RS_PC_ORIGINS[$k] ?? 'Other';
$sys = fn(array $a) => implode(' · ', array_keys(array_filter(['Windows' => $a['asset_win'], 'Linux' => $a['asset_linux'], 'macOS' => rs_pc_mac_ok((string)$a['asset_mac'])])));
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

rs_head('PC', true, ['assets/health.css', 'assets/artpick.css', 'assets/vt.css', 'assets/boot.css']);
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
  <div class="an-head"><h2><?= h($a['name']) ?></h2><a class="an-csv an-plain" href="pc.php?<?= h($lsQs()) ?>#list">All PC recomps</a></div>
  <?php // (3.8) Its release files, as VirusTotal saw them, and a held update.
  if (function_exists('rs_vt_on') && rs_vt_on()): $vb = rs_vt_repo_badge(rs_pc_key($a), (string)($a['pending']['tag'] ?? ($a['ok_tag'] ?? ''))); ?>
  <p class="vt-line"><?= $vb ? rs_vt_badge_html($vb) : '<span class="vt-badge grey"><span class="vt-dot">…</span>Not checked yet</span>' ?>
    <?php if (!empty($a['pending']['tag'])): ?> <a href="security.php">Update to <?= h($a['pending']['version'] ?: $a['pending']['tag']) ?> is on hold</a><?php endif; ?></p>
  <?php endif; ?>
    <?php $al = rs_pc_alerts($a); if ($al): ?>
  <div class="pc-alert">
    <?php if (isset($al['dl'])): ?><p><strong>Download needs a look.</strong> <?= h($al['dl']) ?></p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button name="action" value="dl-ok">It's fine</button><?php if ($a['repo'] !== ''): ?> <a class="btn" href="<?= h(rs_host_releases_url(rs_pc_host($a), $a['repo'])) ?>" target="_blank" rel="noopener">Its releases</a><?php endif; ?></form><?php endif; ?>
    <?php if (isset($al['moved'])): ?><p><strong>This project may have moved.</strong> Its README points to <a href="https://github.com/<?= h($al['moved']) ?>" target="_blank" rel="noopener"><?= h($al['moved']) ?></a>.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button name="action" value="move-repo" class="primary" onclick="return confirm('Point this listing at <?= h($al['moved']) ?>? Its next release gets the security checks before it goes out.')">Switch to it</button> <button name="action" value="moved-ok">Keep this one</button></form><?php endif; ?>
  </div>
  <?php endif; ?>
<form method="post" enctype="multipart/form-data" class="grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><input type="hidden" name="list" value="<?= h($lsQs()) ?>">
    <label>Name<input name="name" value="<?= h($a['name']) ?>" maxlength="120"><small>The project's name, like Zelda 64: Recompiled.</small></label>
    <label>The game<input name="game" value="<?= h($a['game']) ?>" maxlength="120"><small>The original game, like The Legend of Zelda: Majora's Mask.</small></label>
    <label>From<select name="origin"><?php foreach (RS_PC_ORIGINS as $k => $n): ?><option value="<?= h($k) ?>"<?= $a['origin'] === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label>Kind of game<select name="genre"><option value="">Pick one</option><?php foreach (RS_PC_GENRES as $k => $n): ?><option value="<?= h($k) ?>"<?= $a['genre'] === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select><small>Each kind is a category on the PC store, with its own row and page.</small></label>
    <?php $aHost = rs_pc_host($a); ?>
    <label>Project<span class="pc-repo-row"><select name="host" aria-label="Where it lives"><option value="">GitHub</option><option value="gitlab"<?= $aHost === 'gitlab' ? ' selected' : '' ?>>GitLab</option><option value="sourceforge"<?= $aHost === 'sourceforge' ? ' selected' : '' ?>>SourceForge</option></select><input name="repo" value="<?= h($a['repo']) ?>" class="mono" placeholder="owner/name"></span><small><?php if ($a['repo'] !== ''): ?><a href="<?= h(rs_host_web($aHost, $a['repo'])) ?>" target="_blank" rel="noopener">Open on <?= h(rs_host_name($aHost)) ?></a>. <?php endif; ?>owner/name, or paste a github.com, gitlab.com or sourceforge.net link.<?php if ($aHost === 'sourceforge'): ?> Each new SourceForge release is held until its files are checked.<?php endif; ?></small></label>
    <label>Windows download<input name="asset_win" value="<?= h($a['asset_win']) ?>" class="mono" placeholder="Zelda64Recompiled-*-Windows.zip"><small>The release file, * for the part that changes.</small></label>
    <label>Linux download<input name="asset_linux" value="<?= h($a['asset_linux']) ?>" class="mono"></label>
    <label>macOS download<input name="asset_mac" value="<?= h($a['asset_mac']) ?>" class="mono" placeholder="MyRecomp-*-macOS-arm64.zip"><?php $macWhy = rs_pc_mac_why((string)$a['asset_mac']); ?><small<?= $macWhy !== '' ? ' class="bad-text"' : '' ?>><?= $macWhy !== '' ? h($macWhy) . ' It isn\'t offered on Mac until it\'s fixed.' : 'A .zip, .dmg, .rar or .7z with an Apple Silicon or universal build.' ?></small></label>
    <label>Version<input name="version" value="<?= h($a['version']) ?>" maxlength="40"><small>Updated from <?= h(rs_host_name($aHost)) ?> on Publish.</small></label>
    <label class="span2">One-line summary<input name="blurb" value="<?= h($a['blurb']) ?>" maxlength="160"></label>
    <label class="span2">What this version adds<input name="adds" value="<?= h($a['adds'] ?? '') ?>" maxlength="120" placeholder="DLSS, FSR 4 and a 13-language launcher"><small>For games with more than one version: a few words on what sets this one apart. Shown in the versions list.</small></label>
    <label class="span2">Description<textarea name="description" rows="7" maxlength="8000"><?= h($a['description']) ?></textarea><small>What it is, what works, how to set it up. Markdown: ## headings, - lists, **bold**, links.</small></label>
    <label>What players need<input name="needs" value="<?= h($a['needs']) ?>" maxlength="200"><small>Like: Your own US copy of Majora's Mask (a .z64 ROM).</small></label>
    <label>Credits<input name="credits" value="<?= h($a['credits']) ?>" maxlength="1000"></label>
    <label>Made by<input name="author" value="<?= h($a['author']) ?>" maxlength="80"></label>
    <label>Developer page<select name="dev"><option value="">None</option><?php foreach ($devs as $slug => $d): ?><option value="<?= h($slug) ?>"<?= $a['dev'] === $slug ? ' selected' : '' ?>><?= h((string)$d['name']) ?></option><?php endforeach; ?></select></label>
    <label>Labels<input name="tags" value="<?= h($a['tags']) ?>" maxlength="120" placeholder="Recomp, 4K, Mods"><small>Shown as pills on its page, comma between.</small></label>
 <?php if ((string)$a['repo'] === ''): ?><label>Where to follow it<input name="follow" value="<?= h((string)($a['follow'] ?? '')) ?>" maxlength="300" class="mono" placeholder="https://x.com/dev/status/…"><small>No repo yet: the Coming soon page links here (the developer's post or page). https only.</small></label><?php endif; ?>
    <label>How to report bugs<input name="report_url" value="<?= h((string)($a['report_url'] ?? '')) ?>" maxlength="300" class="mono" placeholder="https://github.com/owner/name/issues/new/choose"><small>The developer's own page for bug reports. Shown next to the report form. https only.</small></label>
    <label>Project status<select name="status"><option value="">Active</option><option value="complete"<?= ($a['status'] ?? '') === 'complete' ? ' selected' : '' ?>>Feature complete (finished)</option><option value="paused"<?= ($a['status'] ?? '') === 'paused' ? ' selected' : '' ?>>On hold</option><option value="ended"<?= ($a['status'] ?? '') === 'ended' ? ' selected' : '' ?>>Ended (no more updates)</option></select><small><?= !empty($a['archived']) ? 'Archived on GitHub, so it shows as ended unless you pick Feature complete.' : 'Feature complete: finished, never shown as abandoned or quiet. On hold or Ended show a label on its tile and page. Archived GitHub projects show as ended by themselves.' ?></small></label>
    <label>Status note<input name="status_note" value="<?= h((string)($a['status_note'] ?? '')) ?>" maxlength="200" placeholder="The developer moved on to the sequel."><small>Optional. A short line shown under the label, ideally the developer's own words.</small></label>
    <label class="span2">Not compatible yet<input name="blocked_why" value="<?= h((string)($a['blocked_why'] ?? '')) ?>" maxlength="200" placeholder="Like: there's no Windows build yet"><small>Only you see this. A hidden recomp with a reason here sits under Not compatible in the list instead of Hidden, until its developer puts out a build that works.</small></label>
    <label class="span2">Key features<textarea name="features" rows="5" maxlength="1200" placeholder="One per line"><?= h($a['features']) ?></textarea><small>One per line, shown at the top of its page. Picked up from the README's Features list when it was added.</small></label>
    <label class="span2">Around the web<textarea name="media" rows="4" maxlength="5000" placeholder="https://www.youtube.com/watch?v=…&#10;https://www.reddit.com/r/…/comments/…&#10;https://x.com/…/status/…"><?= h($a['media']) ?></textarea><small>Videos, posts and articles about it, one link per line. YouTube shows as a video, Reddit and X as cards.</small></label>
    <?php $bootBi = (string)($a['boot'] ?? '') === '' ? (rs_boot_builtin()[$a['id']] ?? null) : null; ?>
    <div class="span2 boot-ed" id="boot" data-boot-ed data-csrf="<?= h(rs_csrf()) ?>"<?= $bootBi ? ' data-builtin="' . h(json_encode($bootBi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
      <div class="boot-top"><strong>Boot profile</strong> <span class="muted" data-boot-sum></span> <a class="muted" href="boot.php" style="margin-left:auto;font-size:13px">All boot profiles</a></div>
      <small class="muted">How RecompLauncher starts it, sets it up from the player's copy (Easy Setup) and which of its settings it shows. It reaches players with the next Publish, no launcher update needed.</small>
      <div data-boot-form></div>
      <ul class="boot-probs" data-boot-probs></ul>
      <div class="boot-uses muted" data-boot-uses></div>
      <details><summary>Edit as JSON</summary><textarea name="boot" rows="14" maxlength="100000" spellcheck="false" class="mono" placeholder='{ "kind": "portable", "start": "Game.exe", "start_is": "game", "files": { "how": "folder", ... } }'><?= h((string)($a['boot'] ?? '')) ?></textarea><small>One JSON object, per the curator setup guide. The fields above edit this; anything they don't show stays as it is.</small></details>
    </div>
    <?php if ((string)$a['repo'] !== '' && empty($a['soon'])):
      // (4.6) The developer's own recomplauncher.json: what SDK support last saw, and the agent prompt for a pull request.
      $sdkC = rs_kv_get('sdk:' . $a['id']); $sdkR = $sdkC ? json_decode((string)$sdkC['v'], true) : null; $sdkHas = is_array($sdkR) ? (string)($sdkR['has'] ?? '') : '';
      $sdkMk = (json_decode((string)rs_setting('sdk_marks', '{}'), true) ?: [])[$a['id']] ?? null;
      [$sdkL, $sdkC2] = in_array($sdkHas, ['release', 'repo'], true) ? ['Ships its own file', ' ok'] : ($sdkMk ? ($sdkMk['kind'] === 'pr' ? ['Pull request open', ''] : ['Won\'t support it', ' warn']) : ($sdkHas === 'none' ? ['No file yet', ''] : ['Not checked yet', ''])); ?>
    <div class="span2 pc-sdk">
      <div><strong>The developer's own recomplauncher.json</strong> <span class="pill<?= $sdkC2 ?>"><?= h($sdkL) ?></span><small class="muted">When the developer ships the file in their releases, their setup and settings reach players without a boot profile. Copy agent prompt gives your AI agent everything it needs for the pull request, including the badge and the Recomp Standards check.</small></div>
      <div class="pc-sdk-btns"><button type="button" class="btn" data-badgepr-repo="<?= h($a['repo']) ?>" data-badgepr-id="<?= h($a['id']) ?>" data-badgepr-game="<?= h($a['name']) ?>">Copy badge PR prompt</button><button type="button" class="btn" data-prompt-repo="<?= h($a['repo']) ?>" data-prompt-url="<?= h(rs_host_web($aHost, (string)$a['repo'])) ?>" data-prompt-id="<?= h($a['id']) ?>" data-prompt-game="<?= h((string)($a['game'] ?: $a['name'])) ?>">Copy agent prompt</button><button type="button" class="btn" data-badge-id="<?= h($a['id']) ?>">Copy badge</button><a class="btn" href="sdk-support.php?q=<?= h(rawurlencode((string)$a['repo'])) ?>">SDK support</a></div>
    </div>
    <?php endif; ?>
    <label class="span2">Copy IDs<input name="copy_ids" value="<?= h((string)($a['copy_ids'] ?? '')) ?>" maxlength="300" class="mono" spellcheck="false" placeholder="GZ2E01, GZ2P01"><small>The disc or cartridge IDs of the releases it's made for (GameCube and Wii: GZ2E01; PlayStation: SLUS-203.12; N64: NZSE). The launcher reads the player's copy and warns when it's another release. A shorter one, like GZ2E, fits every disc that starts with it. Empty: any copy.</small></label>
    <div class="checks"><label class="check"><input type="checkbox" name="shown" value="1"<?= $a['hidden'] ? '' : ' checked' ?>> Show it on the site</label><label class="check"><input type="checkbox" name="featured" value="1"<?= $a['featured'] ? ' checked' : '' ?>> Featured</label><label class="check" title="Being worked on, nothing to download yet: it shows only on Coming soon"><input type="checkbox" name="soon" value="1"<?= !empty($a['soon']) ? ' checked' : '' ?>> Coming soon</label><label class="check" title="Needs Python, build tools or a long build first: a yellow Set Up instead of Play"><input type="checkbox" name="setup_required" value="1"<?= !empty($a['setup_required']) ? ' checked' : '' ?>> Setup required</label><label class="check" title="Its release is only a setup .exe: RecompLauncher runs the installer, then finds where it installed (needs a launcher with the installer flow)"><input type="checkbox" name="installer" value="1"<?= !empty($a['installer']) ? ' checked' : '' ?>> Installer only</label></div>
    <?php $pgList = rs_pc_games($a); ?>
    <div class="span2 pc-games-adm">
      <label>Games in this recomp<textarea name="games" rows="<?= max(3, count($pgList) + 1) ?>" maxlength="3000" class="mono" spellcheck="false" placeholder="gow1 | Gears of War | --game=gow1 | 4D5307D5&#10;gow2 | Gears of War 2 | --game=gow2"><?= h(rs_pc_games_text($pgList)) ?></textarea>
        <small>Only for a recomp that carries several games (a trilogy, a bundle). One per line: <code>id | name | launch args | title id | inputs</code>. Only the id and name are needed. The id is short (gow2) and stays the same, since achievements and saves hang on it. Launch args are what starts that game directly; leave them out when the recomp has no such option and players pick the game in its menu. Title id: Xbox 360 only. Inputs: the setup inputs only that game needs. Each game gets its own art and its own tile in RecompLauncher (Beta 36 and newer).</small></label>
      <?php if ($pgList): ?><div class="pc-games-row"><?php foreach ($pgList as $g): ?><span class="pc-games-it"><?php if (!empty($g['cover'])): ?><img src="<?= h((string)$g['cover']) ?>" alt="" width="40" height="60" loading="lazy"><?php else: ?><i></i><?php endif; ?><span><?= h((string)$g['name']) ?><small class="muted mono"><?= h($a['id'] . '--' . $g['id']) ?></small></span></span><?php endforeach; ?></div>
      <button name="games_art" value="1">Get art for each game again</button><?php endif; ?>
    </div>
    <label>Setup steps<textarea name="setup_steps" rows="5" maxlength="2000"><?= h((string)($a['setup_steps'] ?? '')) ?></textarea><small>Only for Setup required. One step per line, in order. Shown with the yellow Set Up button and on its page, with a link to the README.</small></label>
    <?php $ab = is_array($a['about'] ?? null) ? $a['about'] : []; ?>
    <div class="span2 pc-about-adm"><strong>About the game</strong> <span class="muted"><?= $ab ? h($ab['name'] . (!empty($ab['released']) ? ' · ' . substr($ab['released'], 0, 4) : '') . (!empty($ab['developer']) ? ' · ' . $ab['developer'] : '')) . ' · <a href="' . h($ab['url'] ?? '') . '" target="_blank">IGDB</a>' : (!empty($a['about_none']) ? 'No close match on IGDB.' : 'Not looked up yet.') ?></span>
      <label>IGDB game ID<input name="igdb_id" value="<?= (int)($a['igdb_id'] ?? 0) ?: '' ?>" inputmode="numeric" placeholder="Only if it picked the wrong game"><small>The number from the game's IGDB page (bottom of the page, "IGDB ID").</small></label>
      <button name="about" value="1">Get game info from IGDB</button></div>
    <?= rs_ai_fieldset($a, $aHost === '' ? (string)$a['repo'] : '') ?>
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

<?php $counts = $view === '' ? rs_pc_feedback_counts() : []; ?>
<?php if ($view === ''):
    // (4.0) What each filter matches, its count, then the search, the order and the page.
    $lifeOf = fn(array $a): bool => (string)($a['status'] ?? '') !== 'complete' && (in_array((string)($a['status'] ?? ''), RS_PC_STATUSES, true) || !empty($a['archived']));
    $lsMatch = ['all' => fn($a) => true, 'live' => fn($a) => !$a['hidden'] && empty($a['soon']), 'hidden' => fn($a) => (bool)$a['hidden'] && trim((string)($a['blocked_why'] ?? '')) === '', 'soon' => fn($a) => !empty($a['soon']),
        'featured' => fn($a) => !empty($a['featured']), 'ended' => $lifeOf, 'noart' => fn($a) => (string)$a['cover'] === '', 'look' => fn($a) => (bool)rs_pc_alerts($a), 'blocked' => fn($a) => (bool)$a['hidden'] && trim((string)($a['blocked_why'] ?? '')) !== '',
        // (4.2) Mac: ones players get on Mac, and ones whose Mac download the launcher can't use.
        'mac' => fn($a) => rs_pc_mac_ok((string)$a['asset_mac']) !== '', 'macfix' => fn($a) => rs_pc_mac_why((string)$a['asset_mac']) !== ''];
    $q = mb_strtolower($ls['q']);
    $inQ = $q === '' ? $apps : array_values(array_filter($apps, fn($a) => str_contains(mb_strtolower($a['name'] . ' ' . $a['game'] . ' ' . $a['repo'] . ' ' . $a['id'] . ' ' . $a['author']), $q)));
    $lsCount = array_map(fn($f) => count(array_filter($inQ, $f)), $lsMatch);
    $list = array_values(array_filter($inQ, $lsMatch[$ls['show']]));
    $when = fn($a) => (string)(($a['live_at'] ?? '') !== '' ? $a['live_at'] : ($a['added'] ?? ''));
    $rep = fn($a) => array_sum($counts[$a['id']] ?? []);
    $cmp = ['new' => fn($x, $y) => strcmp($when($y), $when($x)), 'old' => fn($x, $y) => strcmp($when($x), $when($y)),
        'az' => fn($x, $y) => strnatcasecmp($x['name'], $y['name']), 'za' => fn($x, $y) => strnatcasecmp($y['name'], $x['name']),
        'updated' => fn($x, $y) => strcmp((string)$y['released'], (string)$x['released']), 'downloads' => fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads'],
        'reports' => fn($x, $y) => $rep($y) <=> $rep($x)][$ls['sort']];
    usort($list, fn($x, $y) => $cmp($x, $y) ?: strnatcasecmp($x['name'], $y['name']));
    $pages = max(1, (int)ceil(count($list) / $ls['per']));
    $ls['page'] = min($ls['page'], $pages);
    $shown = array_slice($list, ($ls['page'] - 1) * $ls['per'], $ls['per']);
    $listQs = $lsQs();
    $pager = function () use ($ls, $pages, $lsQs, $list): string {
        if ($pages < 2) return '';
        $nums = array_unique(array_filter([1, 2, $ls['page'] - 1, $ls['page'], $ls['page'] + 1, $pages - 1, $pages], fn($n) => $n >= 1 && $n <= $pages));
        sort($nums);
        $o = '<nav class="pc-pager" aria-label="Pages">';
        $o .= $ls['page'] > 1 ? '<a href="pc.php?' . h($lsQs(['page' => $ls['page'] - 1])) . '" rel="prev">‹ Back</a>' : '<span class="off">‹ Back</span>';
        $last = 0;
        foreach ($nums as $n) { if ($n - $last > 1) $o .= '<span class="gap">…</span>'; $o .= $n === $ls['page'] ? '<span class="on" aria-current="page">' . $n . '</span>' : '<a href="pc.php?' . h($lsQs(['page' => $n])) . '">' . $n . '</a>'; $last = $n; }
        $o .= $ls['page'] < $pages ? '<a href="pc.php?' . h($lsQs(['page' => $ls['page'] + 1])) . '" rel="next">Next ›</a>' : '<span class="off">Next ›</span>';
        return $o . '</nav>';
    };
?>
<section class="card" id="list">
  <div class="found-head"><h2>In the PC store <span class="pill cyan"><?= count($apps) ?></span></h2></div>
  <?php if (!$apps): ?><p class="muted">Nothing yet. Add what the finder found, under Found on GitHub.</p><?php else: ?>
  <form class="pc-ls" method="get" action="pc.php#list" data-pc-ls>
    <label class="find pc-find-box"><input type="search" name="q" value="<?= h($ls['q']) ?>" placeholder="Find a recomp, game, repo or developer" autocomplete="off" data-pc-q></label>
    <input type="hidden" name="show" value="<?= h($ls['show']) ?>">
    <label class="pc-ls-sel">Sort<select name="sort" data-pc-auto><?php foreach ($lsOpt['sort'] as $k => $n): ?><option value="<?= h($k) ?>"<?= $ls['sort'] === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label class="pc-ls-sel">Per page<select name="per" data-pc-auto><?php foreach ($lsOpt['per'] as $n): ?><option value="<?= $n ?>"<?= $ls['per'] === $n ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
  </form>
  <div class="chips pc-ls-chips" role="group" aria-label="Show">
    <?php foreach ($lsOpt['show'] as $k => $n): if ($k !== 'all' && $k !== $ls['show'] && !$lsCount[$k]) continue; ?>
    <a class="chip<?= $ls['show'] === $k ? ' on' : '' ?>" href="pc.php?<?= h($lsQs(['show' => $k, 'page' => null])) ?>#list"<?= $ls['show'] === $k ? ' aria-current="true"' : '' ?>><?= h($n) ?><span class="n"><?= $lsCount[$k] ?></span></a>
    <?php endforeach; ?>
  </div>
  <p class="muted small-note pc-ls-note"><?= $list ? 'Showing ' . (($ls['page'] - 1) * $ls['per'] + 1) . '–' . (($ls['page'] - 1) * $ls['per'] + count($shown)) . ' of ' . count($list) . ($ls['q'] !== '' ? ' for “' . h($ls['q']) . '”' : '') . '.' : 'Nothing matches.' ?><?= $ls['q'] !== '' || $ls['show'] !== 'all' ? ' <a href="pc.php?' . h(http_build_query(['sort' => $ls['sort'], 'per' => $ls['per']])) . '#list">Clear</a>' : '' ?></p>
  <?= $pager() ?>
  <div class="pc-cards">
    <?php foreach ($shown as $a): $c = $counts[$a['id']] ?? []; $n = array_sum($c); $life = (string)($a['status'] ?? '') === 'paused' ? 'On hold' : ((string)($a['status'] ?? '') === 'complete' ? 'Complete' : ($lifeOf($a) ? 'Ended' : '')); ?>
    <a class="pc-card<?= $a['hidden'] ? ' dim' : '' ?><?= $editId === $a['id'] ? ' here' : '' ?>" href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>&amp;<?= h($listQs) ?>#edit">
      <span class="pc-card-cover"><?= $a['cover'] ? '<img src="' . h($thumb($a['cover'], 256)) . '" alt="" loading="lazy" decoding="async" width="150" height="225">' : '<span>' . h(mb_substr($a['name'], 0, 2)) . '</span>' ?><?= $a['hidden'] ? (trim((string)($a['blocked_why'] ?? '')) !== '' ? '<em class="blocked">Not compatible</em>' : '<em>Hidden</em>') : (!empty($a['soon']) ? '<em>Soon</em>' : ($a['featured'] ? '<em class="ok">Featured</em>' : '')) ?><?= rs_pc_alerts($a) ? '<em class="look">Needs a look</em>' : ($life !== '' ? '<em class="life">' . $life . '</em>' : '') ?></span>
      <span class="pc-card-words"><strong><?= h($a['name']) ?></strong><small><?= h($a['game'] ?: '?') ?></small>
        <small class="muted"><?= h($origin($a['origin'])) ?> · <?= h(RS_PC_GENRES[$a['genre']] ?? 'No kind yet') ?></small>
        <small class="muted"><?= h($a['version'] ?: 'no release') ?><?= $n ? ' · ' . $n . ($n === 1 ? ' report' : ' reports') : '' ?></small><?= $a['hidden'] && trim((string)($a['blocked_why'] ?? '')) !== '' ? '<small class="pc-blocked-why">' . h((string)$a['blocked_why']) . '</small>' : '' ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?= $pager() ?>
  <?php endif; ?>
</section>
<?php elseif ($view === 'found'): ?>
<section class="card" id="found">
  <div class="found-head">
    <h2>Found on GitHub <span class="pill<?= $found ? ' cyan' : '' ?>"><?= count($found) ?></span></h2>
    <div class="found-acts">
      <form method="post" class="pc-lookup"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="lookup"><input name="repo" placeholder="github.com/owner/repo, gitlab.com/group/project or sourceforge.net/projects/name" class="mono" required><button>Look up</button></form>
      <details class="pc-soon-add"><summary>Announced, but no repo yet? Add it to Coming soon</summary>
        <form method="post" class="pc-lookup"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="add_soon"><input name="name" placeholder="Name (e.g. Ultimate Alliance 3 Native PC Port)" maxlength="120" required><input name="follow" placeholder="https://x.com/dev/status/… (where to follow it)" class="mono" maxlength="300" required><button>Add</button></form>
      </details>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="find"><button>Look again</button></form>
    </div>
  </div>
  <p class="muted small-note">Repos that look like PC recomps, with a download in their latest release. Looked for every morning<?= $lastFind ? ', last ' . h(rs_date('M j, g:i a', (int)$lastFind['v'])) : '' ?>. Check each one is the developer's own build with no game files in it, and that it plays well, before you add it.<?= $dcFind ? ' Links posted in #recomp-suggestions on Discord come in too' . (!empty($dcFind['at']) ? ', last read ' . h(rs_date('M j, g:i a', (int)$dcFind['at'])) : '') . '.' : '' ?></p>
  <?php if ($dcFind && ($dcFind['why'] ?? '') !== ''): ?><p class="bad-text small-note"><?= h((string)$dcFind['why']) ?></p><?php endif; ?>
  <?php if (!$found): ?><p class="muted">Nothing waiting.</p><?php else: ?>
  <div class="ftable" role="table">
    <div class="ftr fth" role="row"><span>Project</span><span>Console</span><span>Release</span><span>Stars</span><span></span></div>
    <?php foreach ($found as $f): $flags = (array)($f['flags'] ?? []); ?>
    <div class="ftr" role="row">
      <span class="ft-name"><?= !empty($f['avatar']) ? '<img src="' . h($f['avatar']) . '" alt="" loading="lazy">' : '<i></i>' ?><span><a href="<?= h(rs_host_web($f['host'], $f['repo'])) ?>" target="_blank" rel="noopener"><?= h($f['repo']) ?></a><?= $f['host'] === 'gitlab' ? ' <span class="pill">GitLab</span>' : '' ?><?= (($f['via'] ?? '') === 'quiver' ? ' <span class="pill" title="Listed on Quiver Launcher, not on RecompLauncher yet">Also on Quiver</span>' : '') . (($f['host'] ?? '') === 'sourceforge' ? ' <span class="pill">SourceForge</span>' : '') . (($f['via'] ?? '') === 'discord' ? ' <a class="pill cyan" href="' . h((string)($f['msg'] ?? '')) . '" target="_blank" rel="noopener" title="Posted in #recomp-suggestions">Discord' . (($f['by'] ?? '') !== '' ? ' · ' . h((string)$f['by']) : '') . '</a>' : '') ?><small title="<?= h((string)($f['description'] ?? '')) ?>"><?= h($f['game'] ?: mb_strimwidth((string)($f['description'] ?? ''), 0, 80, '…')) ?></small><?php if (!empty($flags['gamefiles']) || !empty($flags['huge'])): ?><small class="bad-text"><?= !empty($flags['gamefiles']) ? 'Has a file that looks like game data' : 'Has a very large file' ?></small><?php endif; ?></span></span>
      <span><?= h($origin((string)$f['origin'])) ?></span>
      <span><?= h($f['version'] ?: 'none') ?><small class="muted"><?= h(implode(', ', array_keys(array_filter((array)$f['assets'])))) ?></small></span>
      <span><?= (int)$f['stars'] ?></span>
      <span class="ft-btns">
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="repo" value="<?= h($f['key']) ?>"><button class="primary">Add</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="skip"><input type="hidden" name="repo" value="<?= h($f['key']) ?>"><button title="Not a PC recomp, or not good enough">Skip</button></form>
      </span>
      <?php // (3.8) Pre-checked: VirusTotal, already listed, game data, how recent.
      $fn = function_exists('rs_auto_find_notes') ? rs_auto_find_notes($f) : []; $fb = function_exists('rs_vt_repo_badge') && rs_vt_on() ? rs_vt_repo_badge((string)$f['key']) : null; if ($fn || $fb): ?>
      <span class="ft-notes"><?= $fb ? rs_vt_badge_html($fb) : '' ?><?php foreach ($fn as [$k, $t]): ?><em class="fn <?= $k ?>"><?= h($t) ?></em><?php endforeach; ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($skipped): ?>
  <details class="hl-fine"><summary>Skipped (<?= count($skipped) ?>)</summary>
    <ul class="pc-skipped"><?php foreach ($skipped as $f): ?><li><a href="<?= h(rs_host_web($f['host'], $f['repo'])) ?>" target="_blank" rel="noopener"><?= h($f['key']) ?></a> <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="unskip"><input type="hidden" name="repo" value="<?= h($f['key']) ?>"><button>Put back</button></form></li><?php endforeach; ?></ul>
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
  var form = document.querySelector('[data-pc-ls]');
  if (!form) return;
  // Search after a short pause in typing, and the selects as soon as they change. Back to page 1.
  var q = form.querySelector('[data-pc-q]'), timer = null, last = q.value;
  var go = function () { if (q.value.trim() === last.trim() && !go.force) return; form.submit(); };
  q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(go, 450); });
  q.addEventListener('search', function () { clearTimeout(timer); go.force = true; go(); });
  form.querySelectorAll('[data-pc-auto]').forEach(function (s) { s.addEventListener('change', function () { go.force = true; form.submit(); }); });
  if (q.value) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
})();
</script>
<?php rs_foot(['assets/artpick.js', 'assets/boot.js', 'assets/sdk-prompt.js']);
