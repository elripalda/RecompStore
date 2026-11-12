<?php
// RecompStore admin (2.6): listing health and new apps.
// Health: every listing is checked about once a day the way a console would install it: its
// GitHub repo still answers under the same name (a renamed repo's downloads carry the new name,
// and the store refuses those), a release still has its zip, a fixed link still answers, and the
// zip's title ID is still the listing's. Runs a little at a time from admin pages (after the page
// is sent), or with Check now.
// New apps: PS5 apps that aren't in the store yet, from Prospero Store's public catalog and a
// GitHub search, each one a press away from Import.
declare(strict_types=1);

const RS_HEALTH_EVERY = 20 * 3600;  // a listing is checked again after this long
const RS_HEALTH_BUDGET = 20;        // seconds a run may take; the rest waits for the next one
const RS_HEALTH_GAP = 3600;         // at most one background run an hour
const RS_FOUND_EVERY = 302400;      // (2.7.3) the other lists are read twice a week (every 3.5 days)
const RS_FOUND_RECHECK = 14 * 86400; // a GitHub repo with no PS5 zip is looked at again after this
const RS_FOUND_INDEX = 'https://prosperostore.net/api/v1/index.json';
const RS_FOUND_APP = 'https://raw.githubusercontent.com/darkxex/ps5-homebrew-catalog/main/apps/%s.json';

function rs_health_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS listing_health (
        app_id VARCHAR(64) PRIMARY KEY,
        checked DATETIME NOT NULL,
        state VARCHAR(8) NOT NULL,
        problems TEXT NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS found_apps (
        k VARCHAR(180) PRIMARY KEY,
        repo VARCHAR(160) NOT NULL DEFAULT \'\',
        title_id VARCHAR(16) NOT NULL DEFAULT \'\',
        name VARCHAR(120) NOT NULL DEFAULT \'\',
        via VARCHAR(16) NOT NULL,
        data TEXT NOT NULL,
        found DATETIME NOT NULL,
        state VARCHAR(8) NOT NULL DEFAULT \'new\'
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Test copies point these elsewhere (like RS_GH_API).
function rs_found_index_url(): string { $e = getenv('RS_FOUND_INDEX'); return is_string($e) && $e !== '' ? $e : RS_FOUND_INDEX; }
function rs_found_app_url(string $id): string { $e = getenv('RS_FOUND_APP'); return sprintf(is_string($e) && $e !== '' ? $e : RS_FOUND_APP, rawurlencode($id)); }

// The listings a console can get: not hidden, not Coming soon, not a beta.
function rs_health_listed(): array {
    return array_values(array_filter(rs_games(true), fn($g) => !$g['hidden'] && !$g['soon'] && empty($g['beta'])));
}

// Whether a download comes from the repo's own releases, as the store requires (src/store_engine.cpp,
// from_own_repo). The test GitHub's host stands in for github.com in test copies.
function rs_health_repo_of(string $url): string {
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host']) || empty($p['path'])) return '';
    $host = strtolower($p['host']);
    $t = parse_url(rs_gh_api());
    $test = is_array($t) && strtolower((string)($t['host'] ?? '')) !== 'api.github.com' && strtolower((string)($t['host'] ?? '')) === $host;
    if (!$test && ($host !== 'github.com' || ($p['scheme'] ?? '') !== 'https')) return '';
    return preg_match('#^/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/releases/download/#', $p['path'], $m) ? $m[1] : '';
}

// The title IDs of the PS5 apps in a zip on GitHub (read from its end, a few small requests), or
// null when it can't be read. A file at an address never changes, so the answer is kept for good.
function rs_health_zip_ids(string $url, int $size): ?array {
    $k = 'hz:' . sha1($url . '|' . $size);
    if (($c = rs_kv_get($k)) && is_array($j = json_decode($c['v'], true))) return $j;
    $dir = rs_zip_directory($url, $size);
    if (!($dir['ok'] ?? false)) return null;
    $ids = [];
    foreach ($dir['prefixes'] as $pre) {
        $en = $dir['entries'][$pre . 'sce_sys/param.json'] ?? null;
        if (!$en) continue;
        $pj = rs_zip_file($url, $en, 256 << 10);
        if ($pj === null) return null;
        $p = json_decode($pj, true);
        if (is_array($p) && preg_match('/^[A-Z]{4}\d{5}$/', (string)($p['titleId'] ?? ''))) $ids[] = (string)$p['titleId'];
    }
    rs_kv_put($k, json_encode($ids));
    return $ids;
}

// One listing, checked. -> [state ok|warn|bad|retry, problems [[level, text, fix?]]]
function rs_health_check(array $g): array {
    $p = [];
    $bad = function (string $t, array $fix = []) use (&$p) { $p[] = ['bad', $t] + ($fix ? [2 => $fix] : []); };
    $warn = function (string $t, array $fix = []) use (&$p) { $p[] = ['warn', $t] + ($fix ? [2 => $fix] : []); };
    if ($g['source'] === 'url') {
        $u = trim((string)$g['url']);
        if ($u === '') $bad('No download link.');
        elseif (!preg_match('#^https://#i', $u) && rs_health_repo_of($u) === '' && !rs_gh_allowed($u)) $bad('Its download link isn\'t https.');
        else {
            $r = rs_http($u, ['range' => [0, 0], 'max' => 64 << 10, 'timeout' => 15]);
            if (!in_array($r['status'], [200, 206], true)) $bad('Its download link answers ' . ($r['status'] ? 'with ' . $r['status'] : 'nothing') . '.');
        }
        if (trim((string)$g['sha256']) === '') $bad('No SHA-256: the store won\'t install a fixed link without one.');
        return [$p ? 'bad' : 'ok', $p];
    }
    $repo = trim((string)$g['repo']);
    $asset = trim((string)$g['asset']);
    if (!rs_repo_ok($repo)) { $bad('No GitHub repo.'); return ['bad', $p]; }
    if ($asset === '') $bad('No zip name, so the store can\'t pick the file.');
    $rel = rs_gh_releases($repo, 6 * 3600);
    if ($rel['status'] === 404) { $bad('The repo is gone, or it\'s private now.'); return ['bad', $p]; }
    if ($rel['status'] !== 200) return ['retry', [['warn', 'GitHub didn\'t answer. It\'s checked again within the hour.']]];
    $list = json_decode($rel['body'], true);
    $list = is_array($list) ? array_values(array_filter($list, fn($r) => is_array($r) && empty($r['draft']))) : [];
    $info = rs_gh_repo($repo, 86400);
    $renamed = $info && ($info['full_name'] ?? '') !== '' && strcasecmp((string)$info['full_name'], $repo) !== 0 ? (string)$info['full_name'] : '';
    // (2.6) Worth knowing, nothing to fix: it still installs as it is.
    if ($info && !empty($info['archived'])) $p[] = ['note', 'Archived on GitHub, so no more updates will come. It still installs.'];
    if (!$list) { $bad('The repo has no releases.'); return ['bad', $p]; }
    if ($asset === '') return ['bad', $p];
    $pick = null;
    foreach ($list as $r) {
        foreach ($r['assets'] ?? [] as $a) if (rs_pattern_matches($asset, (string)$a['name'])) { $pick = [$r, $a]; break 2; }
    }
    if (!$pick) {
        $bad('None of its releases has a file named ' . $asset . '.');
        if ($renamed !== '') $bad('The repo is called ' . $renamed . ' now.', ['repo' => $renamed]);
        return ['bad', $p];
    }
    [$r, $a] = $pick;
    // (2.6) A newer release with a zip under another name: most likely the file was renamed, which
    // is fixable here. A newer one with no zip or 7z at all (a PS4 build, a web portal, sources) is
    // something else, and players getting the newest PS5 build is right.
    // (4.7) Not worth a warning: a test pre-release while players are on a full release, zips for other
    // systems (Windows, Android, Linux, Mac, Switch, sources), and zips that hold another app (a repo
    // with several apps, like ZiZc3/Hydroid).
    $wantId = rs_zip_id($g) !== '' ? rs_zip_id($g) : (string)$g['id'];
    foreach ($list as $nr) {
        if ($nr === $r) break;
        if (!empty($nr['prerelease']) && empty($r['prerelease'])) continue;
        $zips = [];
        foreach ($nr['assets'] ?? [] as $x) {
            $n = (string)$x['name'];
            if (!preg_match('/\.(zip|7z)$/i', $n)) continue;
            if (preg_match('/(windows|win64|win32|android|linux|macos|osx|(^|[-_. ])mac([-_. ]|$)|switch|(^|[-_. ])(source|sources|src)([-_. ]|$))/i', $n)) continue;
            if (preg_match('/\.zip$/i', $n) && rs_gh_allowed((string)$x['browser_download_url'])) {
                $in = rs_health_zip_ids((string)$x['browser_download_url'], (int)$x['size']);
                if (is_array($in) && $in && !in_array($wantId, $in, true)) continue;
            }
            $zips[] = $n;
        }
        if ($zips) {
            $warn('Its newest release (' . $nr['tag_name'] . ') has no ' . $asset . ' but has ' . implode(', ', array_slice($zips, 0, 3)) . ', so players get ' . $r['tag_name'] . '. Its zip name may have changed.');
            break;
        }
    }
    $url = (string)$a['browser_download_url'];
    $from = rs_health_repo_of($url);
    if ($from === '' || strcasecmp($from, $repo) !== 0) {
        $to = $renamed !== '' ? $renamed : $from;
        $bad(($to !== '' ? 'The repo is called ' . $to . ' now: its downloads come from there' : 'Its downloads don\'t come from ' . $repo . '\'s releases')
            . ', so the store refuses them.', $to !== '' ? ['repo' => $to] : []);
    } elseif ($renamed !== '') {
        $warn('The repo is called ' . $renamed . ' now.', ['repo' => $renamed]);
    }
    // The title ID inside: a plain zip only (a zip inside a bigger one, or a .7z, is left alone).
    if (trim((string)$g['inside']) === '' && preg_match('/\.zip$/i', (string)$a['name']) && rs_gh_allowed($url)) {
        $ids = rs_health_zip_ids($url, (int)$a['size']);
        $want = rs_zip_id($g) !== '' ? rs_zip_id($g) : (string)$g['id'];
        if ($ids === null) $warn('Its zip (' . $a['name'] . ') couldn\'t be read.');
        elseif (!$ids) $bad('No PS5 app in its zip (' . $a['name'] . '): no sce_sys/param.json.');
        elseif (!in_array($want, $ids, true)) $bad('The app in its zip is ' . implode(', ', $ids) . ' now, not ' . $want . '.');
    }
    $lv = array_column($p, 0);
    return [in_array('bad', $lv, true) ? 'bad' : (in_array('warn', $lv, true) ? 'warn' : 'ok'), $p];
}

function rs_health_save(string $id, string $state, array $problems): void {
    // A retry is due again within the hour, not the next day.
    $at = $state === 'retry' ? gmdate('Y-m-d H:i:s', time() - RS_HEALTH_EVERY + RS_HEALTH_GAP) : gmdate('Y-m-d H:i:s');
    rs_db()->prepare('INSERT INTO listing_health (app_id, checked, state, problems) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE checked = VALUES(checked), state = VALUES(state), problems = VALUES(problems)')
        ->execute([$id, $at, $state === 'retry' ? 'warn' : $state, json_encode($problems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

// A short lock in kv_cache, so two runs never go at once.
function rs_health_lock(string $k, int $hold): bool {
    rs_cache_tables();
    $db = rs_db();
    $db->prepare('DELETE FROM kv_cache WHERE k = ? AND at < (NOW() - INTERVAL ? SECOND)')->execute([$k, $hold]);
    try {
        $db->prepare('INSERT INTO kv_cache (k, v, at) VALUES (?, \'1\', NOW())')->execute([$k]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
function rs_health_unlock(string $k): void { rs_db()->prepare('DELETE FROM kv_cache WHERE k = ?')->execute([$k]); }

// Checks the listings that are due (or, $all, every one), oldest first, for up to $budget seconds.
// -> [checked, still due]
function rs_health_run(int $budget = RS_HEALTH_BUDGET, bool $all = false): array {
    rs_health_tables();
    if (!rs_health_lock('health_lock', 300)) return [0, -1];
    $start = microtime(true);
    $done = 0;
    try {
        $apps = rs_health_listed();
        $ids = array_column($apps, 'id');
        $when = rs_db()->query('SELECT app_id, UNIX_TIMESTAMP(checked) FROM listing_health')->fetchAll(PDO::FETCH_KEY_PAIR);
        // Gone from the list: their rows go too.
        foreach (array_diff(array_map('strval', array_keys($when)), $ids) as $gone) rs_db()->prepare('DELETE FROM listing_health WHERE app_id = ?')->execute([$gone]);
        $due = array_values(array_filter($apps, fn($g) => $all || !isset($when[$g['id']]) || (int)$when[$g['id']] < time() - RS_HEALTH_EVERY));
        usort($due, fn($x, $y) => ((int)($when[$x['id']] ?? 0)) <=> ((int)($when[$y['id']] ?? 0)));
        foreach ($due as $g) {
            if (microtime(true) - $start > $budget) break;
            [$state, $problems] = rs_health_check($g);
            rs_health_save($g['id'], $state, $problems);
            ++$done;
        }
        rs_kv_put('health_run', (string)time());
        return [$done, count($due) - $done];
    } finally {
        rs_health_unlock('health_lock');
    }
}

// -> ['bad' => n, 'warn' => n, 'ok' => n, 'unchecked' => n, 'rows' => [id => [state, problems, checked]]]
function rs_health_summary(): array {
    rs_health_tables();
    $rows = [];
    foreach (rs_db()->query('SELECT app_id, state, problems, checked FROM listing_health')->fetchAll() as $r)
        $rows[(string)$r['app_id']] = [(string)$r['state'], json_decode((string)$r['problems'], true) ?: [], (string)$r['checked']];
    $n = ['bad' => 0, 'warn' => 0, 'ok' => 0, 'unchecked' => 0, 'rows' => []];
    foreach (rs_health_listed() as $g) {
        if (!isset($rows[$g['id']])) { ++$n['unchecked']; continue; }
        $n[$rows[$g['id']][0]] = ($n[$rows[$g['id']][0]] ?? 0) + 1;
        $n['rows'][$g['id']] = $rows[$g['id']];
    }
    return $n;
}

// ---- New apps --------------------------------------------------------------------------------

// What the store has already: title IDs (and zip IDs) and repos, lowercase.
function rs_found_known(): array {
    $ids = $repos = [];
    foreach (rs_games(true) as $g) {
        $ids[strtoupper((string)$g['id'])] = true;
        if (rs_zip_id($g) !== '') $ids[rs_zip_id($g)] = true;
        if ((string)$g['repo'] !== '') $repos[strtolower((string)$g['repo'])] = true;
    }
    return [$ids, $repos];
}

function rs_found_category(string $kind, string $cat): string {
    $k = strtolower($kind . ' ' . $cat);
    if (str_contains($k, 'emulat')) return 'emulators';
    if (preg_match('/\b(game|port|recomp)/', $k)) return 'games';
    if (preg_match('/\b(tool|utilit|system|dev)/', $k)) return 'tools';
    return 'apps';
}

function rs_found_add(string $k, string $repo, string $tid, string $name, string $via, array $data, string $state = 'new'): void {
    rs_db()->prepare('INSERT INTO found_apps (k, repo, title_id, name, via, data, found, state) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE repo = VALUES(repo), title_id = VALUES(title_id), name = VALUES(name), data = VALUES(data),
        found = IF(state = \'none\', NOW(), found), state = IF(state IN (\'skip\', \'added\'), state, VALUES(state))')
        ->execute([$k, mb_substr($repo, 0, 160), $tid, mb_substr($name, 0, 120), $via, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $state]);
}

// Reads the other lists for up to $budget seconds. -> [found new, looked at]
function rs_found_refresh(int $budget = RS_HEALTH_BUDGET): array {
    rs_health_tables();
    if (!rs_health_lock('found_lock', 300)) return [0, 0];
    $start = microtime(true);
    $new = $looked = 0;
    try {
        [$ids, $repos] = rs_found_known();
        $db = rs_db();
        $have = [];
        foreach ($db->query('SELECT k, state, UNIX_TIMESTAMP(found) t FROM found_apps')->fetchAll() as $r) $have[(string)$r['k']] = [(string)$r['state'], (int)$r['t']];
        // Listed since: marked added, so they leave the list.
        foreach ($db->query('SELECT k, repo, title_id FROM found_apps WHERE state = \'new\'')->fetchAll() as $r)
            if (isset($ids[(string)$r['title_id']]) || isset($repos[strtolower((string)$r['repo'])]))
                $db->prepare('UPDATE found_apps SET state = \'added\' WHERE k = ?')->execute([$r['k']]);
        // 1. Prospero Store's public catalog: one index, then each app we don't have (its repo and zip).
        $ix = rs_http(rs_found_index_url(), ['max' => 4 << 20, 'timeout' => 20]);
        $j = $ix['status'] === 200 ? json_decode($ix['body'], true) : null;
        foreach (is_array($j['apps'] ?? null) ? $j['apps'] : [] as $x) {
            if (microtime(true) - $start > $budget) break;
            $tid = strtoupper((string)($x['titleid'] ?? ''));
            if (!preg_match('/^[A-Z]{4}\d{5}$/', $tid) || ($x['status'] ?? '') !== 'available') continue;
            if (isset($ids[$tid])) {
                // (2.7.3) Already one of ours by ID: is it the same app? Its repo says (looked at once a fortnight).
                $ck = 'pid:' . $tid;
                if (isset($have[$ck]) && $have[$ck][1] > time() - RS_FOUND_RECHECK) continue;
                $a = rs_http(rs_found_app_url((string)($x['id'] ?? $tid)), ['max' => 1 << 20, 'timeout' => 15]);
                $app = $a['status'] === 200 ? json_decode($a['body'], true) : null;
                $repo = (string)preg_replace(['#^https?://(www\.)?github\.com/#i', '#\.git$#i', '#/+$#'], '', (string)($app['source_repo'] ?? ''));
                $repo = implode('/', array_slice(explode('/', $repo), 0, 2));
                rs_found_add($ck, $repo, $tid, (string)($app['name'] ?? $x['name'] ?? $tid), 'prospero', ['author' => (string)($app['author'] ?? $x['author'] ?? '')], 'added');
                $db->prepare('UPDATE found_apps SET found = NOW() WHERE k = ?')->execute([$ck]);
                continue;
            }
            if (isset($have['id:' . $tid])) continue;
            ++$looked;
            $a = rs_http(rs_found_app_url((string)($x['id'] ?? $tid)), ['max' => 1 << 20, 'timeout' => 15]);
            $app = $a['status'] === 200 ? json_decode($a['body'], true) : null;
            if (!is_array($app)) continue;
            $repo = (string)preg_replace(['#^https?://(www\.)?github\.com/#i', '#\.git$#i', '#/+$#'], '', (string)($app['source_repo'] ?? ''));
            $repo = implode('/', array_slice(explode('/', $repo), 0, 2));
            if ($repo !== '' && isset($repos[strtolower($repo)])) continue;
            $zip = strtolower((string)($app['format'] ?? $x['format'] ?? '')) === 'zip';
            $desc = trim((string)($app['description'] ?? ''));
            rs_found_add('id:' . $tid, $repo, $tid, (string)($app['name'] ?? $x['name'] ?? $tid), 'prospero', [
                'author' => (string)($app['author'] ?? $x['author'] ?? ''), 'description' => mb_substr($desc, 0, 600),
                'icon' => (string)($app['icon_url'] ?? ''), 'version' => (string)($app['version'] ?? ''),
                'asset' => !empty($app['artifact_name']) && $zip ? rs_asset_pattern((string)$app['artifact_name'], (string)($app['tag'] ?? '')) : '',
                'category' => rs_found_category((string)($app['kind'] ?? $x['kind'] ?? ''), (string)($x['category'] ?? '')),
                'installable' => $zip, 'page' => (string)($app['page'] ?? ''),
            ], $zip && $repo !== '' ? 'new' : 'none');
            if ($zip && $repo !== '') ++$new;
        }
        // 2. GitHub: repos about PS5 homebrew pushed in the last two months, with a PS5 zip in a release.
        $since = gmdate('Y-m-d', time() - 60 * 86400);
        foreach (['topic:ps5-homebrew', 'ps5 homebrew in:name,description pushed:>' . $since, 'prospero in:name,description pushed:>' . $since, 'ps5 in:name pushed:>' . gmdate('Y-m-d', time() - 14 * 86400)] as $q) {
            if (microtime(true) - $start > $budget) break;
            $s = rs_gh_cached('search:' . $q, rs_gh_api() . '/search/repositories?q=' . rawurlencode($q) . '&sort=updated&per_page=50', 86400, fn(array $r): array => [
                'items' => array_map(fn($i) => ['full_name' => (string)($i['full_name'] ?? ''), 'description' => (string)($i['description'] ?? ''),
                    'archived' => (bool)($i['archived'] ?? false), 'fork' => (bool)($i['fork'] ?? false)], array_slice((array)($r['items'] ?? []), 0, 50))]);
            $items = $s['status'] === 200 ? (json_decode($s['body'], true)['items'] ?? []) : [];
            foreach ($items as $i) {
                if (microtime(true) - $start > $budget) break;
                $repo = (string)$i['full_name'];
                $k = 'repo:' . strtolower($repo);
                if (!rs_repo_ok($repo) || !empty($i['archived']) || !empty($i['fork']) || isset($repos[strtolower($repo)])) continue;
                if (isset($have[$k]) && ($have[$k][0] !== 'none' || $have[$k][1] > time() - RS_FOUND_RECHECK)) continue;
                ++$looked;
                $res = rs_source_repo($repo);
                $app = ($res['ok'] ?? false) ? ($res['apps'][0] ?? null) : null;
                $tid = is_array($app) ? (string)$app['titleId'] : '';
                if ($tid !== '' && (isset($ids[$tid]) || isset($have['id:' . $tid]))) { rs_found_add($k, $repo, $tid, (string)$app['name'], 'github', ['author' => (string)$app['author']], 'added'); continue; }
                if (!$app || $tid === '') { rs_found_add($k, $repo, '', explode('/', $repo)[1], 'github', ['why' => (string)($res['error'] ?? 'No PS5 app in its zip.')], 'none'); continue; }
                rs_found_add($k, $repo, $tid, (string)$app['name'], 'github', [
                    'author' => (string)$app['author'], 'description' => mb_substr((string)($i['description'] ?: $app['description']), 0, 600),
                    'icon' => (string)$app['icon'], 'version' => (string)$app['version'], 'asset' => (string)$app['source']['asset'],
                    'category' => rs_found_category('', (string)$i['description']), 'installable' => true,
                ]);
                ++$new;
            }
        }
        rs_kv_put('found_run', (string)time());
        return [$new, $looked];
    } finally {
        rs_health_unlock('found_lock');
    }
}

// The ones to show: [new => [...], skip => [...]], newest first.
function rs_found_list(): array {
    rs_health_tables();
    $out = ['new' => [], 'skip' => []];
    foreach (rs_db()->query('SELECT k, repo, title_id, name, via, data, found, state FROM found_apps WHERE state IN (\'new\', \'skip\') ORDER BY found DESC, name')->fetchAll() as $r) {
        $r['data'] = json_decode((string)$r['data'], true) ?: [];
        $out[(string)$r['state']][] = $r;
    }
    return $out;
}

// One found app as an entry for Import (admin/import.php reads {"apps": [...]}).
function rs_found_import_entry(array $r): array {
    $d = $r['data'];
    $desc = trim((string)($d['description'] ?? ''));
    $blurb = preg_match('/^(.{20,180}?[.!?])(\s|$)/u', $desc, $m) ? $m[1] : mb_strimwidth($desc, 0, 180, '…');
    $e = ['name' => (string)$r['name'], 'author' => (string)($d['author'] ?? ''), 'category' => (string)($d['category'] ?? 'apps'),
        'repo' => (string)$r['repo'], 'blurb' => $blurb, 'description' => $desc];
    if ((string)$r['title_id'] !== '') $e['id'] = (string)$r['title_id'];
    if ((string)($d['asset'] ?? '') !== '') $e['asset'] = (string)$d['asset'];
    $icon = (string)($d['icon'] ?? '');
    if ($icon !== '') $e['icon'] = str_starts_with($icon, '/') ? rs_abs($icon) : $icon;
    return $e;
}

// Admin pages call this: when listings or the other lists are due, a short run happens after the
// page has gone to the browser (the session is let go first, so other pages don't wait on it).
function rs_health_maybe(): void {
    static $asked = false;
    if ($asked) return;
    $asked = true;
    try {
        rs_health_tables();
        $last = rs_kv_get('health_try');
        if ($last && $last['age'] < RS_HEALTH_GAP) return;
        rs_kv_put('health_try', (string)time());
    } catch (Throwable $e) {
        return;
    }
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        @set_time_limit(120);
        try {
            rs_health_run();
            $f = rs_kv_get('found_run');
            if (!$f || $f['age'] > RS_FOUND_EVERY) { rs_found_refresh(); rs_radar_tell(); }
        } catch (Throwable $e) {
            // Next time.
        }
    });
}

// ---- (2.7.3) Title ID radar ------------------------------------------------------------------
// Two different apps with one title ID clash on a PS5: installing one writes over the other. The
// radar puts every title ID it knows side by side: the store's (and the IDs in their zips), the
// taken list in Settings, apps in the Inbox, and the PS5 apps found on GitHub and in Prospero
// Store's catalog (above, refreshed daily). An ID used by two different apps, one of them the
// store's or a submission, is a clash and shows in red on Found on GitHub (and its count in the menu).

// Every use of every title ID: [ID => [[where, name, repo, link, key]]]. key tells apps apart:
// their repo (lowercase), else where + ID.
function rs_tid_all(): array {
    $out = [];
    $add = function (string $tid, string $where, string $name, string $repo, string $link = '', bool $moved = false, string $key = '') use (&$out) {
        $tid = strtoupper(trim($tid));
        if (!preg_match('/^[A-Z]{4}\d{5}$/', $tid)) return;
        $repo = strtolower(trim($repo, '/ '));
        if ($key === '') $key = $repo !== '' ? $repo : $where . ':' . $tid;
        foreach ($out[$tid] ?? [] as $u) if ($u['key'] === $key && $u['where'] === $where) return;
        $out[$tid][] = ['where' => $where, 'name' => $name, 'repo' => $repo, 'link' => $link, 'key' => $key, 'moved' => $moved];
    };
    foreach (rs_games(true) as $g) {
        $add((string)$g['id'], 'store', (string)$g['name'], (string)$g['repo'], 'edit.php?id=' . rawurlencode((string)$g['id']));
        // The ID in its zip: the store installs it under its own ID, so on its own that's no clash.
        if (rs_zip_id($g) !== '') $add(rs_zip_id($g), 'store', $g['name'] . ' (the ID in its zip; the store installs it as ' . $g['id'] . ')', (string)$g['repo'], 'edit.php?id=' . rawurlencode((string)$g['id']), true);
    }
    foreach (rs_taken_ids() as $tid => $note) $add($tid, 'taken', $note !== '' ? $note : 'On the taken list', '', 'settings.php');
    try {
        rs_inbox_table();
        foreach (rs_db()->query("SELECT id, app_id, name, repo FROM inbox WHERE kind = 'submit' AND status = 'new' AND app_id <> '' ORDER BY id DESC LIMIT 500")->fetchAll() as $r)
            $add((string)$r['app_id'], 'submission', (string)$r['name'], (string)preg_replace('#^https?://(www\.)?github\.com/#i', '', (string)$r['repo']), 'submissions.php#m' . (int)$r['id']);
    } catch (Throwable $e) { /* no Inbox yet */ }
    try {
        rs_health_tables();
        // (without a repo there's no telling whether it's the same app, so those are left out)
        foreach (rs_db()->query("SELECT repo, title_id, name, via FROM found_apps WHERE title_id <> '' AND repo <> '' AND state <> 'none'")->fetchAll() as $r)
            $add((string)$r['title_id'], (string)$r['via'] === 'prospero' ? 'prospero' : 'github', (string)$r['name'], (string)$r['repo'], 'https://github.com/' . $r['repo']);
    } catch (Throwable $e) { /* nothing found yet */ }
    // (2.7.3) IDs developers reserved and haven't put out yet.
    foreach (rs_res_active() as $r)
        if ($r['used_at'] === null) $add((string)$r['title_id'], 'reserved', 'Reserved by ' . $r['who'], '', 'dev-accounts.php', false, 'reserved:' . (int)$r['account_id']);
    return $out;
}

// One title ID: every app that uses it, and whether it's in the store's own block.
function rs_tid_uses(string $tid): array {
    $tid = strtoupper(trim($tid));
    return ['id' => $tid, 'uses' => rs_tid_all()[$tid] ?? [], 'block' => rs_move_block($tid)];
}

// The clashes, not counting ones you said are fine: [ID => uses]. A clash is two or more different
// apps (by repo) on one ID where one is the store's or a submission's.
function rs_radar_clashes(bool $withOk = false): array {
    $ok = json_decode((string)(rs_kv_get('radar_ok')['v'] ?? '[]'), true) ?: [];
    $out = [];
    foreach (rs_tid_all() as $tid => $all) {
        $uses = array_values(array_filter($all, fn($u) => empty($u['moved'])));
        $keys = array_values(array_unique(array_column($uses, 'key')));
        if (count($keys) < 2) continue;
        if (!array_intersect(array_column($uses, 'where'), ['store', 'submission', 'reserved'])) continue;
        sort($keys);
        $sig = $tid . '|' . implode(',', $keys);
        if (!$withOk && in_array($sig, $ok, true)) continue;
        $out[$tid] = ['uses' => $uses, 'sig' => $sig];
    }
    ksort($out);
    return $out;
}

function rs_radar_ok(string $sig): void {
    $ok = json_decode((string)(rs_kv_get('radar_ok')['v'] ?? '[]'), true) ?: [];
    if (!in_array($sig, $ok, true)) $ok[] = mb_substr($sig, 0, 600);
    rs_kv_put('radar_ok', json_encode(array_slice($ok, -300)));
    rs_kv_put('radar_count', (string)count(rs_radar_clashes()));
}
// (4.3) How many clashes are open, for the red count beside Found on GitHub in the menu.
// Worked out at most every 10 minutes.
function rs_radar_count(): int {
    try {
        $c = rs_kv_get('radar_count');
        if ($c && $c['age'] < 600) return (int)$c['v'];
        $n = count(rs_radar_clashes());
        rs_kv_put('radar_count', (string)$n);
        return $n;
    } catch (Throwable $e) { return 0; }
}

// Where a use comes from, in words.
function rs_tid_where(string $w): string {
    return ['store' => 'In RecompStore', 'taken' => 'Taken list', 'submission' => 'Submitted', 'github' => 'On GitHub', 'prospero' => 'Prospero Store', 'reserved' => 'Reserved'][$w] ?? $w;
}

// An email to you when a clash turns up that you haven't been told about.
function rs_radar_tell(): void {
    try {
        $told = json_decode((string)(rs_kv_get('radar_told')['v'] ?? '[]'), true) ?: [];
        $new = array_filter(rs_radar_clashes(), fn($c) => !in_array($c['sig'], $told, true));
        if (!$new) return;
        rs_kv_put('radar_told', json_encode(array_slice(array_merge($told, array_column($new, 'sig')), -300)));
        if (!rs_mail_ready()) return;
        $lines = [];
        foreach ($new as $tid => $c) $lines[] = $tid . ': ' . implode('; ', array_map(fn($u) => rs_tid_where($u['where']) . ' ' . $u['name'] . ($u['repo'] !== '' ? ' (' . $u['repo'] . ')' : ''), $c['uses']));
        rs_mail_send(rs_mail_get('mail_from'), 'Title ID clash: ' . implode(', ', array_keys($new)),
            "The title ID radar found apps that share a title ID. On a PS5 one writes over the other.\n\n" . implode("\n", $lines)
            . "\n\nSee them on Health: " . rs_abs('/admin/health.php#radar'));
    } catch (Throwable $e) { /* next time */ }
}

// ---- (2.7.3) Title IDs developers reserve -----------------------------------------------------
// A developer account keeps up to RS_RES_MAX title IDs for apps on their way. One that no app has
// come out with (in the store, sent in, or on their GitHub) goes back after RS_RES_DAYS; they can
// keep it longer from their hub. The public Title IDs page shows them as reserved.
const RS_RES_MAX = 5;
const RS_RES_DAYS = 182;

function rs_res_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS title_reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title_id VARCHAR(9) NOT NULL,
        account_id INT NOT NULL,
        created DATETIME NOT NULL,
        expires DATETIME NOT NULL,
        used_at DATETIME NULL,
        UNIQUE KEY (title_id), INDEX (account_id)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Reservations still held (used, or not run out), with the developer's name and page.
function rs_res_active(?int $account = null): array {
    static $cache = [];
    $k = (string)$account;
    if ($account === null && isset($cache[$k])) return $cache[$k]; // a developer's own list is always read fresh
    try {
        rs_res_table();
        rs_dev_tables();
        $db = rs_db();
        // Run-out ones go back.
        $db->exec('DELETE FROM title_reservations WHERE used_at IS NULL AND expires < NOW()');
        $sql = 'SELECT r.*, a.name, a.gh_login, a.slug FROM title_reservations r JOIN dev_accounts a ON a.id = r.account_id' . ($account !== null ? ' WHERE r.account_id = ' . (int)$account : '') . ' ORDER BY r.title_id';
        $rows = $db->query($sql)->fetchAll();
        $devs = rs_developers();
        foreach ($rows as &$r) {
            $r['who'] = (string)(($r['slug'] !== '' && isset($devs[$r['slug']])) ? $devs[$r['slug']]['name'] : ($r['name'] ?: $r['gh_login']));
        }
        unset($r);
        return $cache[$k] = $rows;
    } catch (Throwable $e) {
        return $cache[$k] = [];
    }
}

// Marks reservations used once the developer's app is out with the ID: in the store (theirs by repo
// owner or developer page), sent in from their hub, or found on their GitHub.
function rs_res_mark_used(): void {
    try {
        rs_res_table();
        $db = rs_db();
        $open = $db->query('SELECT r.id, r.title_id, r.account_id, a.gh_login, a.slug FROM title_reservations r JOIN dev_accounts a ON a.id = r.account_id WHERE r.used_at IS NULL')->fetchAll();
        if (!$open) return;
        $devs = rs_developers();
        $byId = [];
        foreach (rs_games(true) as $g) {
            $byId[strtoupper((string)$g['id'])][] = ['owner' => strtolower((string)strtok((string)$g['repo'], '/')), 'slug' => rs_dev_for($g, $devs)];
            if (rs_zip_id($g) !== '') $byId[rs_zip_id($g)][] = ['owner' => strtolower((string)strtok((string)$g['repo'], '/')), 'slug' => rs_dev_for($g, $devs)];
        }
        $sub = $db->prepare("SELECT COUNT(*) FROM inbox WHERE kind = 'submit' AND app_id = ? AND dev_account = ?");
        $gh = $db->prepare("SELECT COUNT(*) FROM found_apps WHERE title_id = ? AND LOWER(repo) LIKE ?");
        $mark = $db->prepare('UPDATE title_reservations SET used_at = NOW() WHERE id = ?');
        foreach ($open as $r) {
            $mine = false;
            foreach ($byId[$r['title_id']] ?? [] as $u)
                if (($r['gh_login'] !== '' && $u['owner'] === strtolower((string)$r['gh_login'])) || ($r['slug'] !== '' && $u['slug'] === $r['slug'])) $mine = true;
            if (!$mine) { try { rs_submission_columns(); $sub->execute([$r['title_id'], $r['account_id']]); $mine = (int)$sub->fetchColumn() > 0; } catch (Throwable $e) {} }
            if (!$mine && $r['gh_login'] !== '') { try { rs_health_tables(); $gh->execute([$r['title_id'], strtolower((string)$r['gh_login']) . '/%']); $mine = (int)$gh->fetchColumn() > 0; } catch (Throwable $e) {} }
            if ($mine) $mark->execute([$r['id']]);
        }
    } catch (Throwable $e) { /* next time */ }
}

// Every title ID something already uses (and the store's own block): what a new one must not be.
function rs_tid_used_set(): array {
    $used = array_fill_keys(array_keys(rs_tid_all()), true);
    try { rs_res_table(); foreach (rs_db()->query('SELECT title_id FROM title_reservations')->fetchAll(PDO::FETCH_COLUMN) as $t) $used[$t] = true; } catch (Throwable $e) {}
    return $used;
}

// A title ID nobody uses that we know of, from PPSA90000 to PPSA99999 (never the store's own block).
function rs_tid_free(): string {
    $used = rs_tid_used_set();
    for ($i = 0; $i < 400; ++$i) {
        $n = random_int(90000, 99999);
        $id = sprintf('PPSA%05d', $n);
        if (isset($used[$id]) || rs_move_block($id)) continue;
        return $id;
    }
    throw new RuntimeException('No free title ID found. Try again.');
}
