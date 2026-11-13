<?php
// (4.8) Repo watch. Every listed game's repo, checked twice a day (cron runs inc/repowatch-run.php at
// 5:23 and 17:23 UTC; a page asks for a run too when the last one is over 13 hours old). A repo
// that's gone (deleted, made private, the account removed), taken down (a DMCA notice, blocked or
// disabled by GitHub), locked, renamed, or emptied of its releases is pinned on top of the
// Submissions board with what to do about it: point the listing at a new repo, hide it from the
// store, or mark it fine. Everything else stays quiet.
// GitHub is asked 50 repos at a time (GraphQL, with the site's token). A repo it can't show is asked
// again on its own (the API, then the public page), so a hiccup never reads as a takedown.
declare(strict_types=1);
require_once __DIR__ . '/pc.php';

const RS_WATCH_EVERY = 12 * 3600;
const RS_WATCH_BATCH = 50;
// What each state means, worst first. bad: players can't get it. warn: worth a look.
const RS_WATCH_STATES = [
    'dmca' => ['bad', 'Taken down (DMCA)'], 'blocked' => ['bad', 'Blocked'], 'gone' => ['bad', 'Repo gone'],
    'private' => ['bad', 'Made private'], 'norel' => ['bad', 'Releases deleted'],
    'locked' => ['warn', 'Locked'], 'moved' => ['warn', 'Renamed'], 'back' => ['warn', 'Back up'],
];

function rs_watch_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS repo_watch (
        store VARCHAR(4) NOT NULL,
        app_id VARCHAR(64) NOT NULL,
        repo VARCHAR(200) NOT NULL DEFAULT \'\',
        state VARCHAR(10) NOT NULL DEFAULT \'ok\',
        why VARCHAR(600) NOT NULL DEFAULT \'\',
        link VARCHAR(400) NOT NULL DEFAULT \'\',
        since DATETIME NULL,
        checked DATETIME NULL,
        ok_at DATETIME NULL,
        ack VARCHAR(10) NOT NULL DEFAULT \'\',
        err VARCHAR(200) NOT NULL DEFAULT \'\',
        PRIMARY KEY (store, app_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS repo_watch_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        store VARCHAR(4) NOT NULL,
        app_id VARCHAR(64) NOT NULL,
        name VARCHAR(160) NOT NULL DEFAULT \'\',
        repo VARCHAR(200) NOT NULL DEFAULT \'\',
        state VARCHAR(10) NOT NULL,
        why VARCHAR(600) NOT NULL DEFAULT \'\',
        KEY at (at)
    ) CHARACTER SET utf8mb4');
    // (4.8) The repo it was forked from (duplicates), and its most active forks with releases, noted
    // while it's up so a deleted repo has somewhere to go.
    if (rs_col_missing($db, 'repo_watch', 'forks'))
        $db->exec("ALTER TABLE repo_watch ADD COLUMN parent VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN forks TEXT NULL, ADD COLUMN forks_at DATETIME NULL");
    $done = true;
}

// Test copies point these elsewhere (like RS_GH_API).
function rs_watch_gh_web(): string { $e = getenv('RS_GH_WEB'); return rtrim(is_string($e) && $e !== '' ? $e : 'https://github.com', '/'); }
function rs_watch_sf_base(): string { $e = getenv('RS_SF_BASE'); return rtrim(is_string($e) && $e !== '' ? $e : 'https://sourceforge.net', '/'); }

function rs_watch_level(string $state): string { return RS_WATCH_STATES[$state][0] ?? ''; }
function rs_watch_label(string $state): string { return RS_WATCH_STATES[$state][1] ?? ['ok' => 'Up', 'fixed' => 'Followed rename'][$state] ?? 'Not checked'; }

// One target from a PC recomp or a PS5 listing, or null when there's nothing to watch.
function rs_watch_target_pc(array $a): ?array {
    if ((string)$a['repo'] === '' || !rs_host_repo_ok(rs_pc_host($a), (string)$a['repo'])) return null;
    $hid = (string)($a['watch_hid'] ?? '') !== '';
    if (!empty($a['hidden']) && !$hid) return null;
    return ['store' => 'pc', 'id' => (string)$a['id'], 'name' => (string)$a['name'], 'host' => rs_pc_host($a), 'repo' => (string)$a['repo'],
        'soon' => !empty($a['soon']), 'hid' => $hid, 'icon' => (string)$a['icon']];
}
function rs_watch_target_ps5(array $g): ?array {
    if ((string)($g['source'] ?? '') === 'url' || !rs_repo_ok((string)$g['repo'])) return null;
    $hid = (string)($g['watch_hid'] ?? '') !== '';
    if (!empty($g['hidden']) && !$hid) return null;
    return ['store' => 'ps5', 'id' => (string)$g['id'], 'name' => (string)($g['name'] ?? $g['id']), 'host' => '', 'repo' => (string)$g['repo'],
        'soon' => !empty($g['soon']) || !empty($g['beta']), 'hid' => $hid, 'icon' => (string)($g['icon'] ?? '')];
}

// What's watched: every PC recomp and PS5 listing that's out (Coming soon ones with a repo too), and
// the ones the watch hid, so it can say when they're back.
function rs_watch_targets(): array {
    $out = [];
    foreach (rs_pc_apps() as $a) if ($t = rs_watch_target_pc($a)) $out[] = $t;
    foreach (rs_games(true) as $g) if ($t = rs_watch_target_ps5($g)) $out[] = $t;
    return $out;
}

// ---- Asking the hosts -------------------------------------------------------------------------------

// Up to 50 GitHub repos in one question. -> [lowercase repo => facts, or null when GitHub couldn't show
// it], or ['_err' => why] when the whole question failed. Facts: name (as GitHub has it now), disabled,
// private, locked, lock, releases.
// (4.8) $forksFor: the repos (lowercase) whose forks are asked for this time too.
function rs_watch_gh_batch(array $repos, array $forksFor = []): array {
    $q = 'query{';
    foreach (array_values($repos) as $i => $r) {
        [$o, $n] = explode('/', $r, 2);
        $q .= 'r' . $i . ':repository(owner:' . json_encode($o) . ',name:' . json_encode($n) . '){nameWithOwner isDisabled isPrivate isLocked lockReason releases(first:1){totalCount} parent{nameWithOwner}'
            . (isset($forksFor[strtolower($r)]) ? ' forks(first:6,orderBy:{field:STARGAZERS,direction:DESC}){nodes{nameWithOwner stargazerCount pushedAt releases(first:1){totalCount}}}' : '') . '}';
    }
    $q .= '}';
    $res = rs_http(rs_gh_api() . '/graphql', ['github' => true, 'post' => (string)json_encode(['query' => $q]), 'headers' => ['Content-Type: application/json'], 'timeout' => 45, 'max' => 4 << 20]);
    if ($res['status'] === 401) return ['_err' => 'auth'];
    if ($res['status'] !== 200) return ['_err' => 'GitHub answered ' . ($res['status'] ?: 'nothing') . ($res['error'] !== '' ? ' (' . $res['error'] . ')' : '')];
    $j = json_decode($res['body'], true);
    if (!is_array($j) || !is_array($j['data'] ?? null)) return ['_err' => 'GitHub sent no data' . (is_array($j['errors'][0] ?? null) ? ': ' . mb_substr((string)($j['errors'][0]['message'] ?? ''), 0, 120) : '')];
    $out = [];
    foreach (array_values($repos) as $i => $r) {
        $x = $j['data']['r' . $i] ?? null;
        $forks = null;
        if (is_array($x) && is_array($x['forks']['nodes'] ?? null)) {
            $forks = [];
            foreach ($x['forks']['nodes'] as $fk) if (is_array($fk) && (int)($fk['releases']['totalCount'] ?? 0) > 0)
                $forks[] = ['repo' => (string)$fk['nameWithOwner'], 'stars' => (int)($fk['stargazerCount'] ?? 0), 'pushed' => substr((string)($fk['pushedAt'] ?? ''), 0, 10), 'via' => 'fork'];
            $forks = array_slice($forks, 0, 3);
        }
        $out[strtolower($r)] = is_array($x) ? [
            'name' => (string)($x['nameWithOwner'] ?? ''), 'disabled' => !empty($x['isDisabled']), 'private' => !empty($x['isPrivate']),
            'locked' => !empty($x['isLocked']), 'lock' => (string)($x['lockReason'] ?? ''), 'releases' => (int)($x['releases']['totalCount'] ?? 0),
            'parent' => (string)($x['parent']['nameWithOwner'] ?? ''), 'forks' => $forks,
        ] : null;
    }
    return $out;
}

// A block GitHub put on a repo (its API's "block": reason, created_at, html_url). -> [state, why, link]
function rs_watch_blocked(array $b): array {
    $reason = strtolower((string)($b['reason'] ?? ''));
    $at = strtotime((string)($b['created_at'] ?? ''));
    $link = (string)($b['html_url'] ?? '');
    if (!preg_match('#^https://github\.com/#', $link)) $link = '';
    $when = $at ? ' on ' . gmdate('M j, Y', $at) : '';
    if ($reason === 'dmca') return ['dmca', 'Taken down by a DMCA copyright notice' . $when . '. GitHub blocks the repo and its downloads.', $link];
    return ['blocked', 'GitHub blocked the repo' . ($reason !== '' ? ' (' . $reason . ')' : '') . $when . ', so its downloads don\'t work.', $link];
}

// One GitHub repo asked on its own, when the batch couldn't show it. -> facts (as the batch gives
// them), a [state, why, link] answer, or null when GitHub didn't answer clearly (asked next time).
function rs_watch_gh_one(string $repo): array|null {
    $r = rs_http(rs_gh_api() . '/repos/' . $repo, ['github' => true, 'timeout' => 20, 'max' => 1 << 20]);
    $j = json_decode((string)$r['body'], true);
    $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
    if ($r['status'] === 451 || stripos($msg, 'access blocked') !== false) return ['answer' => rs_watch_blocked(is_array($j['block'] ?? null) ? $j['block'] : [])];
    if ($r['status'] === 404) {
        // The public page has to agree before it counts as gone.
        $w = rs_http(rs_watch_gh_web() . '/' . $repo, ['timeout' => 20, 'max' => 6 << 20]);
        if ($w['status'] === 404) return ['answer' => ['gone', 'The repo is gone: deleted, made private, or the account was removed.', '']];
        if ($w['status'] === 451) return ['answer' => ['dmca', 'Taken down for legal reasons (most likely a DMCA notice). GitHub blocks the repo and its downloads.', '']];
        return null;
    }
    if ($r['status'] !== 200 || !is_array($j)) return null;
    $rel = rs_http(rs_gh_api() . '/repos/' . $repo . '/releases?per_page=1', ['github' => true, 'timeout' => 20, 'max' => 2 << 20]);
    $list = $rel['status'] === 200 ? json_decode($rel['body'], true) : null;
    if (!is_array($list)) return null;
    return ['name' => (string)($j['full_name'] ?? ''), 'disabled' => !empty($j['disabled']), 'private' => !empty($j['private']),
        'locked' => false, 'lock' => '', 'releases' => count($list), 'parent' => (string)($j['parent']['full_name'] ?? ''), 'forks' => null];
}

// What GitHub's facts mean for one listing. -> [state, why, link]
function rs_watch_gh_state(array $t, array $f): array {
    if ($f['disabled']) return ['blocked', 'GitHub disabled the repo, which usually means a takedown or a terms of service problem. Its downloads don\'t work.', ''];
    if ($f['private']) return ['private', 'The repo is private now, so players can\'t download it.', ''];
    if ($f['releases'] === 0 && !$t['soon']) return ['norel', 'Every release was deleted, so there\'s nothing to download. Developers often do this after a copyright notice.', ''];
    if ($f['locked']) return ['locked', 'GitHub locked the repo' . ($f['lock'] !== '' ? ' (' . strtolower(str_replace('_', ' ', $f['lock'])) . ')' : '') . '. Downloads may stop working until it\'s unlocked.', ''];
    if ($f['name'] !== '' && strcasecmp($f['name'], $t['repo']) !== 0)
        return ['moved', 'The repo is ' . $f['name'] . ' now. ' . ($t['store'] === 'ps5' ? 'The PS5 store won\'t install from the new name until the listing points there.' : 'Downloads still work through GitHub\'s redirect, but the listing should point there.'), $f['name']];
    return $t['hid'] ? ['back', 'The repo is back up. The listing is still hidden from the store.', ''] : ['ok', '', ''];
}

// A GitLab project. -> [state, why, link] or null.
function rs_watch_gl_one(array $t): ?array {
    $enc = rawurlencode($t['repo']);
    $r = rs_http(rs_gl_api() . '/projects/' . $enc, ['gitlab' => true, 'timeout' => 20, 'max' => 2 << 20]);
    if ($r['status'] === 404) return ['gone', 'The GitLab project is gone: deleted, made private or moved.', ''];
    if ($r['status'] === 451) return ['blocked', 'GitLab blocked the project for legal reasons, so its downloads don\'t work.', ''];
    $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
    if (!is_array($j)) return null;
    $name = (string)($j['path_with_namespace'] ?? '');
    if (!$t['soon']) {
        $rel = rs_http(rs_gl_api() . '/projects/' . $enc . '/releases?per_page=1', ['gitlab' => true, 'timeout' => 20, 'max' => 2 << 20]);
        if ($rel['status'] === 200 && json_decode($rel['body'], true) === []) return ['norel', 'Every release was deleted, so there\'s nothing to download.', ''];
    }
    if ($name !== '' && strcasecmp($name, $t['repo']) !== 0) return ['moved', 'The project is gitlab.com/' . $name . ' now. The listing should point there.', 'gitlab.com/' . $name];
    return $t['hid'] ? ['back', 'The project is back up. The listing is still hidden from the store.', ''] : ['ok', '', ''];
}

// A SourceForge project. -> [state, why, link] or null.
function rs_watch_sf_one(array $t): ?array {
    $r = rs_http(rs_watch_sf_base() . '/rest/p/' . rawurlencode($t['repo']), ['timeout' => 20, 'max' => 2 << 20]);
    if ($r['status'] === 404) return ['gone', 'The SourceForge project is gone.', ''];
    $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
    if (!is_array($j)) return null;
    $st = strtolower((string)($j['status'] ?? ''));
    if ($st === 'moved' && preg_match('#^https://#', (string)($j['moved_to_url'] ?? ''))) return ['moved', 'The project moved to ' . $j['moved_to_url'] . '.', (string)$j['moved_to_url']];
    if (in_array($st, ['deleted', 'disabled'], true)) return ['gone', 'SourceForge shows the project as ' . $st . '.', ''];
    if (!$t['soon']) {
        $f = rs_http(rs_watch_sf_base() . '/projects/' . rawurlencode($t['repo']) . '/rss?path=/', ['timeout' => 25, 'max' => 8 << 20]);
        $files = $f['status'] === 200 ? rs_sf_feed_parse($f['body']) : null;
        if ($files === []) return ['norel', 'It has no files left to download.', ''];
    }
    return $t['hid'] ? ['back', 'The project is back up. The listing is still hidden from the store.', ''] : ['ok', '', ''];
}

// Every target checked. -> [index => [state, why, link] | null (couldn't tell)], and whether GitHub
// turned the token down.
function rs_watch_check_all(array $targets, ?callable $say = null, array $forksFor = []): array {
    $res = array_fill_keys(array_keys($targets), null);
    $extra = []; // index => ['parent' => ..., 'forks' => list|null]
    $byRepo = [];
    foreach ($targets as $i => $t) if ($t['host'] === '') $byRepo[strtolower($t['repo'])][] = $i;
    $facts = [];
    $auth = false; $batchErr = '';
    $keys = array_keys($byRepo);
    foreach (array_chunk($keys, RS_WATCH_BATCH) as $n => $chunk) {
        $b = rs_watch_gh_batch(array_map(fn($k) => $targets[$byRepo[$k][0]]['repo'], $chunk), $forksFor);
        if (isset($b['_err'])) {
            if ($b['_err'] === 'auth') $auth = true; else $batchErr = (string)$b['_err'];
            foreach ($chunk as $k) $facts[$k] = false; // asked one by one below
            continue;
        }
        foreach ($chunk as $k) $facts[$k] = $b[$k] ?? null;
        if ($say && $n % 10 === 9) $say('GitHub: ' . min(count($keys), ($n + 1) * RS_WATCH_BATCH) . ' of ' . count($keys));
    }
    foreach ($byRepo as $k => $idx) {
        $f = $facts[$k] ?? null;
        if (!is_array($f)) {
            // Not shown in the batch (or the batch failed): asked on its own. Without a working token
            // GitHub allows too few of those, so the rest wait for the next run.
            if ($auth) continue;
            $one = rs_watch_gh_one($targets[$idx[0]]['repo']);
            if ($one === null) continue;
            if (isset($one['answer'])) { foreach ($idx as $i) $res[$i] = $one['answer']; continue; }
            $f = $one;
        }
        foreach ($idx as $i) { $res[$i] = rs_watch_gh_state($targets[$i], $f); $extra[$i] = ['parent' => (string)($f['parent'] ?? ''), 'forks' => $f['forks'] ?? null]; }
    }
    foreach ($targets as $i => $t) {
        if ($t['host'] === 'gitlab') $res[$i] = rs_watch_gl_one($t);
        elseif ($t['host'] === 'sourceforge') $res[$i] = rs_watch_sf_one($t);
    }
    return [$res, $auth, $batchErr, $extra];
}

// (4.8) Copies of a deleted repo on GitHub: same name, another owner, with releases (forks outlive the
// repo they came from). For a repo that was already gone before its forks could be noted. -> forks list.
function rs_watch_find_copies(string $repo): array {
    [$owner, $name] = explode('/', $repo, 2);
    $r = rs_http(rs_gh_api() . '/search/repositories?q=' . rawurlencode($name . ' in:name fork:true') . '&sort=stars&per_page=15', ['github' => true, 'timeout' => 20, 'max' => 4 << 20]);
    $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
    $cands = [];
    foreach (is_array($j['items'] ?? null) ? $j['items'] : [] as $it) {
        $full = (string)($it['full_name'] ?? '');
        if (!rs_repo_ok($full) || strcasecmp(explode('/', $full)[0], $owner) === 0) continue;
        if (strcasecmp(explode('/', $full)[1], $name) !== 0) continue; // the same name only
        $cands[$full] = ['repo' => $full, 'stars' => (int)($it['stargazers_count'] ?? 0), 'pushed' => substr((string)($it['pushed_at'] ?? ''), 0, 10), 'via' => 'search'];
        if (count($cands) >= 6) break;
    }
    if (!$cands) return [];
    // Only ones with releases: there has to be something to download.
    $b = rs_watch_gh_batch(array_keys($cands));
    if (isset($b['_err'])) return [];
    $out = [];
    foreach ($cands as $full => $c) if (is_array($b[strtolower($full)] ?? null) && $b[strtolower($full)]['releases'] > 0 && !$b[strtolower($full)]['disabled'] && !$b[strtolower($full)]['private']) $out[] = $c;
    return array_slice($out, 0, 3);
}

// (4.8) A PC listing follows its repo's rename by the same owner (owner/old -> owner/new): the same
// project and the same files, so its checked downloads move with it and nothing is held again.
function rs_watch_follow_rename(array $t, string $to): bool {
    if ($t['store'] !== 'pc' || $t['host'] !== '' || !rs_repo_ok($to)) return false;
    if (strcasecmp(explode('/', $t['repo'])[0], explode('/', $to)[0]) !== 0) return false;
    $a = rs_pc_app($t['id']);
    if (!$a || strcasecmp((string)$a['repo'], $t['repo']) !== 0) return false;
    foreach (rs_pc_apps() as $x) if ($x['id'] !== $a['id'] && rs_pc_host($x) === '' && strcasecmp((string)$x['repo'], $to) === 0) return false; // another listing has it: a duplicate, for you to decide
    $db = rs_db();
    if (function_exists('rs_vt_tables')) {
        rs_vt_tables();
        $db->prepare('UPDATE vt_files SET repo = ?, url = REPLACE(url, ?, ?) WHERE repo = ?')->execute([$to, '/' . $t['repo'] . '/', '/' . $to . '/', $t['repo']]);
    }
    $a['repo'] = $to;
    $a['gh_at'] = 0; // read again on the next refresh
    rs_pc_save($a);
    if (empty($a['hidden'])) { try { rs_pc_publish_one($a['id']); } catch (Throwable $e) { /* goes out with the next publish */ } }
    rs_log('watch', 'pc:' . $a['id'], 'followed the rename: ' . $t['repo'] . ' -> ' . $to);
    return true;
}

// (4.8) PC listings that look like the same thing twice: the same repo, one a fork of the other's
// repo, or the same repo name under another owner. Pairs marked "not the same" stay off. -> pairs
// [a, b, why] (ids), a the one that's been listed longer.
function rs_watch_dupes_find(array $targets, array $parents): array {
    $pc = array_values(array_filter($targets, fn($t) => $t['store'] === 'pc'));
    $by = []; $byName = [];
    foreach ($pc as $t) {
        $by[strtolower(rs_repo_key($t['host'], $t['repo']))][] = $t['id'];
        if ($t['host'] === '' && strlen(explode('/', $t['repo'])[1]) >= 6) $byName[strtolower(explode('/', $t['repo'])[1])][] = $t;
    }
    $pairs = [];
    $add = function (string $a, string $b, string $why) use (&$pairs) {
        if ($a === $b) return;
        $k = min($a, $b) . '|' . max($a, $b);
        if (!isset($pairs[$k])) $pairs[$k] = [$a, $b, $why];
    };
    foreach ($by as $ids) for ($i = 1; $i < count($ids); $i++) $add($ids[0], $ids[$i], 'Both point at the same repo.');
    foreach ($pc as $t) {
        $p = strtolower((string)($parents[$t['id']] ?? ''));
        if ($p !== '' && !empty($by[$p])) $add($by[$p][0], $t['id'], 'Its repo is a fork of the other one\'s.');
    }
    foreach ($byName as $list) {
        if (count($list) < 2) continue;
        for ($i = 1; $i < count($list); $i++) if (strcasecmp(explode('/', $list[0]['repo'])[0], explode('/', $list[$i]['repo'])[0]) !== 0) $add($list[0]['id'], $list[$i]['id'], 'Same repo name under another account (a mirror or a copy?).');
    }
    $out = [];
    foreach ($pairs as $k => $p) if (!rs_kv_get('dupe_ok:' . $k)) $out[] = $p;
    return $out;
}

// ---- Keeping the answers ----------------------------------------------------------------------------

function rs_watch_row(string $store, string $id): ?array {
    rs_watch_tables();
    $st = rs_db()->prepare('SELECT * FROM repo_watch WHERE store = ? AND app_id = ?');
    $st->execute([$store, $id]);
    return $st->fetch() ?: null;
}

// One listing's answer. $r null: it couldn't be told this time (what was known stays). -> the state
// when it changed to one that needs a look, else ''.
function rs_watch_save(array $t, ?array $r, string $err = '', array $extra = []): string {
    $db = rs_db();
    $old = rs_watch_row($t['store'], $t['id']);
    if ($r === null) {
        if ($old) $db->prepare('UPDATE repo_watch SET err = ? WHERE store = ? AND app_id = ?')->execute([mb_substr($err !== '' ? $err : 'No clear answer', 0, 200), $t['store'], $t['id']]);
        else $db->prepare('INSERT INTO repo_watch (store, app_id, repo, state, err) VALUES (?, ?, ?, \'unk\', ?)')->execute([$t['store'], $t['id'], mb_substr($t['repo'], 0, 200), mb_substr($err !== '' ? $err : 'No clear answer', 0, 200)]);
        return '';
    }
    [$state, $why, $link] = $r;
    $changed = !$old || $old['state'] !== $state || strcasecmp((string)$old['repo'], $t['repo']) !== 0;
    $db->prepare('INSERT INTO repo_watch (store, app_id, repo, state, why, link, since, checked, ok_at, ack, err) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, \'\', \'\')
        ON DUPLICATE KEY UPDATE repo = VALUES(repo), state = VALUES(state), why = VALUES(why), link = VALUES(link), checked = NOW(), err = \'\',
        since = IF(?, NOW(), since), ok_at = IF(VALUES(state) = \'ok\', NOW(), ok_at), ack = IF(?, \'\', ack)')
        ->execute([$t['store'], $t['id'], mb_substr($t['repo'], 0, 200), $state, mb_substr($why, 0, 600), mb_substr($link, 0, 400), $state === 'ok' ? gmdate('Y-m-d H:i:s') : null, $changed ? 1 : 0, $changed ? 1 : 0]);
    if (array_key_exists('parent', $extra)) $db->prepare('UPDATE repo_watch SET parent = ? WHERE store = ? AND app_id = ?')->execute([mb_substr((string)$extra['parent'], 0, 200), $t['store'], $t['id']]);
    if (is_array($extra['forks'] ?? null)) $db->prepare('UPDATE repo_watch SET forks = ?, forks_at = NOW() WHERE store = ? AND app_id = ?')->execute([json_encode($extra['forks'], JSON_UNESCAPED_SLASHES), $t['store'], $t['id']]);
    // Changes go in the log (not a first look that found it fine).
    if ($changed && (($old && $old['state'] !== 'unk') || $state !== 'ok'))
        $db->prepare('INSERT INTO repo_watch_log (at, store, app_id, name, repo, state, why) VALUES (NOW(), ?, ?, ?, ?, ?, ?)')
            ->execute([$t['store'], $t['id'], mb_substr($t['name'], 0, 160), mb_substr($t['repo'], 0, 200), $state, mb_substr($why, 0, 600)]);
    return $changed && rs_watch_level($state) !== '' ? $state : '';
}

// A whole run: everything checked and kept. -> what it found (kept as the last run, for the report).
function rs_watch_scan(?callable $say = null): array {
    rs_watch_tables();
    $t0 = microtime(true);
    $targets = rs_watch_targets();
    if ($say) $say('Watching ' . count($targets) . ' listings');
    // (4.8) Forks are noted once a week per repo, a share of them each run.
    $fresh = [];
    foreach (rs_db()->query('SELECT store, app_id FROM repo_watch WHERE forks_at > NOW() - INTERVAL 7 DAY')->fetchAll() as $r) $fresh[$r['store'] . '|' . $r['app_id']] = true;
    $forksFor = [];
    foreach ($targets as $t) if ($t['host'] === '' && !isset($fresh[$t['store'] . '|' . $t['id']]) && count($forksFor) < 300) $forksFor[strtolower($t['repo'])] = true;
    [$res, $auth, $batchErr, $extra] = rs_watch_check_all($targets, $say, $forksFor);
    // (4.8) Renames by the same owner are followed at once (PC); the rest wait for you.
    $followed = [];
    foreach ($targets as $i => $t) {
        if (($res[$i][0] ?? '') !== 'moved' || !rs_watch_follow_rename($t, (string)$res[$i][2])) continue;
        $old = $t['repo'];
        $targets[$i]['repo'] = (string)$res[$i][2];
        $res[$i] = ['ok', '', ''];
        $followed[] = $targets[$i]['name'];
        rs_db()->prepare('INSERT INTO repo_watch_log (at, store, app_id, name, repo, state, why) VALUES (NOW(), ?, ?, ?, ?, \'fixed\', ?)')
            ->execute([$t['store'], $t['id'], mb_substr($t['name'], 0, 160), mb_substr($targets[$i]['repo'], 0, 200), 'Renamed from ' . $old . '. The listing follows it now.']);
        // Its row moves with it, so the save below sees nothing new (the log line above says what happened).
        rs_db()->prepare('UPDATE repo_watch SET repo = ?, state = \'ok\', why = \'\', link = \'\', since = NOW(), ok_at = NOW(), ack = \'\' WHERE store = ? AND app_id = ?')
            ->execute([mb_substr($targets[$i]['repo'], 0, 200), $t['store'], $t['id']]);
    }
    $new = []; $n = ['ok' => 0, 'flag' => 0, 'unk' => 0];
    $keep = [];
    $parents = [];
    foreach ($targets as $i => $t) {
        $keep[$t['store'] . '|' . $t['id']] = true;
        if (($extra[$i]['parent'] ?? '') !== '') $parents[$t['id']] = $extra[$i]['parent'];
        $err = $auth ? 'GitHub turned the site\'s token down' : $batchErr;
        $ch = rs_watch_save($t, $res[$i], $err, $extra[$i] ?? []);
        if ($ch !== '') $new[] = ['store' => $t['store'], 'id' => $t['id'], 'name' => $t['name'], 'state' => $ch];
        if ($res[$i] === null) ++$n['unk'];
        elseif (rs_watch_level($res[$i][0]) !== '') ++$n['flag'];
        else ++$n['ok'];
    }
    // Listings that left (deleted, or hidden by hand): their rows go.
    foreach (rs_db()->query('SELECT store, app_id FROM repo_watch')->fetchAll() as $r)
        if (!isset($keep[$r['store'] . '|' . $r['app_id']])) rs_db()->prepare('DELETE FROM repo_watch WHERE store = ? AND app_id = ?')->execute([$r['store'], $r['app_id']]);
    rs_db()->exec('DELETE FROM repo_watch_log WHERE at < NOW() - INTERVAL 180 DAY');
    // (4.8) Deleted repos with no forks noted: GitHub is searched for copies (a few a run, weekly each).
    if (!$auth) {
        $looked = 0;
        foreach (rs_db()->query("SELECT store, app_id, repo FROM repo_watch WHERE state = 'gone' AND repo LIKE '%/%' AND repo NOT LIKE 'gitlab.com/%' AND repo NOT LIKE 'sourceforge.net/%' AND (forks IS NULL OR forks = '[]') AND (forks_at IS NULL OR forks_at < NOW() - INTERVAL 7 DAY)")->fetchAll() as $r) {
            if ($looked++ >= 15 || !rs_repo_ok((string)$r['repo'])) break;
            $c = rs_watch_find_copies((string)$r['repo']);
            rs_db()->prepare('UPDATE repo_watch SET forks = ?, forks_at = NOW() WHERE store = ? AND app_id = ?')->execute([json_encode($c, JSON_UNESCAPED_SLASHES), $r['store'], $r['app_id']]);
        }
    }
    // (4.8) Listings that look like the same thing twice (PC). Parents not asked this run stay as saved.
    foreach (rs_db()->query("SELECT app_id, parent FROM repo_watch WHERE store = 'pc' AND parent <> ''")->fetchAll() as $r) $parents[$r['app_id']] = $parents[$r['app_id']] ?? (string)$r['parent'];
    rs_kv_put('watch_dupes', (string)json_encode(rs_watch_dupes_find($targets, $parents), JSON_UNESCAPED_SLASHES));
    $sum = ['at' => time(), 'took' => (int)round(microtime(true) - $t0), 'checked' => count($targets), 'ok' => $n['ok'], 'flag' => $n['flag'], 'unk' => $n['unk'],
        'auth' => $auth, 'err' => $batchErr, 'new' => $new, 'followed' => $followed,
        'pc' => count(array_filter($targets, fn($t) => $t['store'] === 'pc')), 'ps5' => count(array_filter($targets, fn($t) => $t['store'] === 'ps5'))];
    rs_kv_put('watch_last', (string)json_encode($sum, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($new) rs_log('watch', 'scan', count($new) . ' new: ' . implode(', ', array_map(fn($x) => $x['name'] . ' (' . rs_watch_label($x['state']) . ')', array_slice($new, 0, 8))));
    if ($followed) rs_log('watch', 'renames', 'followed ' . count($followed) . ': ' . implode(', ', array_slice($followed, 0, 8)));
    return $sum;
}

function rs_watch_last(): array {
    $c = rs_kv_get('watch_last');
    $j = $c ? json_decode($c['v'], true) : null;
    return is_array($j) ? $j : [];
}

// ---- Running it -------------------------------------------------------------------------------------

function rs_watch_running(): bool { $c = rs_kv_get('watch_running'); return $c !== null && $c['v'] === '1' && $c['age'] < 1800; }

function rs_watch_dir(): string {
    $d = dirname(RS_PUBLIC) . '/recompstore-private/watch';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

// A run in the background (Scan now, or a page noticing the last one is old). -> false when one is going.
function rs_watch_launch(): bool {
    if (rs_watch_running()) return false;
    rs_kv_put('watch_running', '1');
    $p = proc_open('setsid php ' . escapeshellarg(__DIR__ . '/repowatch-run.php') . ' now >> ' . escapeshellarg(rs_watch_dir() . '/run.log') . ' 2>&1 &',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, rs_watch_dir(),
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => rs_watch_dir(), 'LANG' => 'C.UTF-8']);
    if (!is_resource($p)) { rs_kv_put('watch_running', '0'); return false; }
    proc_close($p);
    return true;
}

// From the hub: when cron hasn't run it for over 13 hours, a page starts it.
function rs_watch_maybe(): void {
    try {
        $last = rs_watch_last();
        if ((int)($last['at'] ?? 0) < time() - RS_WATCH_EVERY - 3600 && !rs_watch_running()) rs_watch_launch();
    } catch (Throwable $e) { /* the next page tries again */ }
}

// ---- What the hub shows -----------------------------------------------------------------------------

// The listings that need a look, worst first: [store, id, name, icon, repo, host, state, level, label,
// why, link, since, checked, ack, hidden, hid]. $acked: the ones marked fine too. Stores: the ones given.
function rs_watch_flags(array $stores, bool $acked = false): array {
    rs_watch_tables();
    if (!$stores) return [];
    $in = implode(',', array_fill(0, count($stores), '?'));
    $st = rs_db()->prepare("SELECT * FROM repo_watch WHERE store IN ($in) AND state NOT IN ('ok', 'unk')");
    $st->execute(array_values($stores));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $isAcked = (string)$r['ack'] === (string)$r['state'];
        if ($isAcked && !$acked) continue;
        $x = $r['store'] === 'pc' ? rs_pc_app((string)$r['app_id']) : rs_game((string)$r['app_id']);
        if (!$x) continue;
        $out[] = ['store' => (string)$r['store'], 'id' => (string)$r['app_id'], 'name' => (string)$x['name'], 'icon' => (string)($x['icon'] ?? ''),
            'repo' => (string)$r['repo'], 'host' => $r['store'] === 'pc' ? rs_pc_host($x) : '', 'state' => (string)$r['state'],
            'level' => rs_watch_level((string)$r['state']), 'label' => rs_watch_label((string)$r['state']), 'why' => (string)$r['why'], 'link' => (string)$r['link'],
            'since' => (string)$r['since'], 'checked' => (string)$r['checked'], 'acked' => $isAcked, 'hidden' => !empty($x['hidden']), 'hid' => (string)($x['watch_hid'] ?? '') !== '',
            // (4.8) Where a deleted repo could go: its forks noted while it was up, or copies found since.
            // Never offered for a takedown.
            'homes' => (string)$r['state'] === 'gone' ? (json_decode((string)($r['forks'] ?? ''), true) ?: []) : []];
    }
    usort($out, fn($a, $b) => [$a['level'] === 'bad' ? 0 : 1, -strtotime($a['since'] ?: '2000-01-01')] <=> [$b['level'] === 'bad' ? 0 : 1, -strtotime($b['since'] ?: '2000-01-01')]);
    return $out;
}

// How many need a look in these stores (not marked fine).
function rs_watch_count(array $stores): int {
    if (!$stores) return 0;
    rs_watch_tables();
    $in = implode(',', array_fill(0, count($stores), '?'));
    $st = rs_db()->prepare("SELECT COUNT(*) FROM repo_watch WHERE store IN ($in) AND state NOT IN ('ok', 'unk') AND ack <> state");
    $st->execute(array_values($stores));
    return (int)$st->fetchColumn();
}

// The counts for the report: watched, up, need a look (marked fine too), couldn't tell.
function rs_watch_counts(array $stores): array {
    $n = ['all' => 0, 'ok' => 0, 'flag' => 0, 'unk' => 0];
    if (!$stores) return $n;
    rs_watch_tables();
    $in = implode(',', array_fill(0, count($stores), '?'));
    $st = rs_db()->prepare("SELECT state, COUNT(*) c FROM repo_watch WHERE store IN ($in) GROUP BY state");
    $st->execute(array_values($stores));
    foreach ($st->fetchAll() as $r) {
        $n['all'] += (int)$r['c'];
        $k = $r['state'] === 'ok' ? 'ok' : ($r['state'] === 'unk' ? 'unk' : 'flag');
        $n[$k] += (int)$r['c'];
    }
    return $n;
}

function rs_watch_log(array $stores, int $limit = 40): array {
    if (!$stores) return [];
    rs_watch_tables();
    $in = implode(',', array_fill(0, count($stores), '?'));
    $st = rs_db()->prepare("SELECT * FROM repo_watch_log WHERE store IN ($in) ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(array_values($stores));
    return $st->fetchAll();
}

// ---- What the buttons do ----------------------------------------------------------------------------

// The listing behind a hub item "w:pc:<id>" or "w:ps5:<id>". -> [store, id, listing]
function rs_watch_item(string $item): array {
    if (!preg_match('/^w:(pc|ps5):([A-Za-z0-9_.-]{1,64})$/', $item, $m)) throw new RuntimeException('That isn\'t on the repo watch.');
    $x = $m[1] === 'pc' ? rs_pc_app($m[2]) : rs_game($m[2]);
    if (!$x) throw new RuntimeException('That listing is gone.');
    return [$m[1], $m[2], $x];
}

// One listing checked again now. -> its row.
function rs_watch_check_one(string $store, string $id): ?array {
    rs_watch_tables();
    $x = $store === 'pc' ? rs_pc_app($id) : rs_game($id);
    $t = $x ? ($store === 'pc' ? rs_watch_target_pc($x) : rs_watch_target_ps5($x)) : null;
    if (!$t) { rs_db()->prepare('DELETE FROM repo_watch WHERE store = ? AND app_id = ?')->execute([$store, $id]); return null; }
    [$res, , , $extra] = rs_watch_check_all([$t], null, $t['host'] === '' ? [strtolower($t['repo']) => true] : []);
    rs_watch_save($t, $res[0], $res[0] === null ? 'No clear answer' : '', $extra[0] ?? []);
    if (($res[0][0] ?? '') === 'gone') {
        $row = rs_watch_row($store, $id);
        if ($row && in_array((string)($row['forks'] ?? ''), ['', '[]'], true) && $t['host'] === '')
            rs_db()->prepare('UPDATE repo_watch SET forks = ?, forks_at = NOW() WHERE store = ? AND app_id = ?')->execute([json_encode(rs_watch_find_copies($t['repo']), JSON_UNESCAPED_SLASHES), $store, $id]);
    }
    return rs_watch_row($store, $id);
}

// The PC catalog written now (no GitHub round) and open launchers told.
function rs_watch_pc_out(): void {
    rs_pc_write_catalog();
    try { require_once __DIR__ . '/rlpush.php'; rl_push('catalog'); } catch (Throwable $e) { /* launchers look again soon */ }
}

// The PS5 catalog published (it waits for the signer on the owner's PC when that's set up). -> a note.
function rs_watch_ps5_out(): string {
    rs_publish();
    return function_exists('rs_sign_on') && rs_sign_on() ? ' It goes out when the signer on your PC signs the new catalog.' : '';
}

// Off the store (and so off Discover, the banner and search) until it's put back. -> message.
function rs_watch_hide(string $item): string {
    [$store, $id, $x] = rs_watch_item($item);
    if (!empty($x['hidden'])) throw new RuntimeException($x['name'] . ' is already hidden.');
    $row = rs_watch_row($store, $id);
    $x['hidden'] = true;
    $x['watch_hid'] = (string)($row['state'] ?? 'hand') . '|' . gmdate('c');
    if ($store === 'pc') { rs_pc_save($x); rs_watch_pc_out(); $note = ''; }
    else { rs_save_game($x); $note = rs_watch_ps5_out(); }
    rs_db()->prepare('UPDATE repo_watch SET ack = state WHERE store = ? AND app_id = ?')->execute([$store, $id]);
    rs_log('watch', $store . ':' . $id, 'hidden from the store (' . (string)($row['state'] ?? '') . ')');
    return $x['name'] . ' is hidden from the store. The watch keeps checking its repo and tells you if it comes back.' . $note;
}

// Back on the store. -> message.
function rs_watch_unhide(string $item): string {
    [$store, $id, $x] = rs_watch_item($item);
    if (empty($x['hidden'])) throw new RuntimeException($x['name'] . ' isn\'t hidden.');
    if ($store === 'pc' && str_starts_with((string)($x['ok_tag'] ?? ''), 'moved:'))
        throw new RuntimeException('Its new repo\'s release is still being checked. It can go back once that\'s done (Security shows it).');
    $x['hidden'] = false;
    unset($x['watch_hid']);
    if ($store === 'pc') { rs_pc_save($x); rs_watch_pc_out(); $note = ''; }
    else { rs_save_game($x); $note = rs_watch_ps5_out(); }
    rs_log('watch', $store . ':' . $id, 'back on the store');
    // Out again: if its repo is still down, it shows up again.
    rs_db()->prepare('UPDATE repo_watch SET ack = \'\' WHERE store = ? AND app_id = ?')->execute([$store, $id]);
    rs_watch_check_one($store, $id);
    return $x['name'] . ' is back on the store.' . $note;
}

// Pointed at another repo (the project moved, or a mirror took over). -> message.
function rs_watch_move(string $item, string $link): string {
    [$store, $id, $x] = rs_watch_item($item);
    $l = rs_repo_from_link(trim($link));
    if (!$l) throw new RuntimeException('Paste the new repo\'s link, like github.com/owner/name.');
    if ($store === 'ps5') {
        if (rs_host_norm($l['host']) !== '') throw new RuntimeException('PS5 listings come from GitHub.');
        $old = (string)$x['repo'];
        if (strcasecmp($old, $l['repo']) === 0) throw new RuntimeException('It already points there.');
        $x['repo'] = $l['repo'];
        rs_save_game($x);
        if (function_exists('rs_health_check')) { [$hs, $hp] = rs_health_check($x); rs_health_save($x['id'], $hs, $hp); }
        rs_log('change', 'watch ' . $id, 'repo ' . $old . ' -> ' . $l['repo']);
        $note = empty($x['hidden']) ? rs_watch_ps5_out() : '';
        $row = rs_watch_check_one($store, $id);
        return $x['name'] . ' now gets its downloads from ' . $l['repo'] . '.' . ($row && rs_watch_level((string)$row['state']) === 'bad' ? ' The new repo has a problem too: ' . $row['why'] : '') . $note;
    }
    if (strcasecmp(rs_pc_key($x), rs_repo_key($l['host'], $l['repo'])) === 0) throw new RuntimeException('It already points there.');
    $x = rs_pc_switch_repo($x, $l['repo'], $l['host']);
    rs_pc_save($x);
    $row = rs_watch_check_one($store, $id);
    return $x['name'] . ' now points at ' . rs_repo_key(rs_pc_host($x), (string)$x['repo']) . '. Its release there gets the usual security check first, then players get it'
        . (!empty($x['hidden']) ? '. It\'s still hidden: put it back from here once the check is done.' : ' on its own.')
        . ($row && rs_watch_level((string)$row['state']) === 'bad' ? ' Heads up, the new repo has a problem too: ' . $row['why'] : '');
}

// Fine as it is: it stays quiet until its state changes. -> message.
function rs_watch_ack(string $item): string {
    [$store, $id, $x] = rs_watch_item($item);
    rs_db()->prepare('UPDATE repo_watch SET ack = state WHERE store = ? AND app_id = ?')->execute([$store, $id]);
    rs_log('watch', $store . ':' . $id, 'marked fine');
    return 'Okay. ' . $x['name'] . ' stays off the list unless something else changes.';
}

// ---- (4.8) Duplicates ---------------------------------------------------------------------------------

// The pairs from the last run, with their listings: [[a, b, why, key]] (a, b listings). Gone or hidden
// ones drop out.
function rs_watch_dupes(): array {
    $c = rs_kv_get('watch_dupes');
    $out = [];
    foreach ((array)json_decode($c['v'] ?? '[]', true) as $p) {
        if (!is_array($p) || count($p) < 3) continue;
        $a = rs_pc_app((string)$p[0]); $b = rs_pc_app((string)$p[1]);
        if (!$a || !$b || !empty($a['hidden']) || !empty($b['hidden'])) continue;
        $k = min($a['id'], $b['id']) . '|' . max($a['id'], $b['id']);
        if (rs_kv_get('dupe_ok:' . $k)) continue;
        $out[] = [$a, $b, (string)$p[2], $k];
    }
    return $out;
}
// One kept, the other hidden and moved over to it (players who have it are moved by the launcher). -> message.
function rs_watch_merge(string $keep, string $drop): string {
    $k = rs_pc_app($keep); $d = rs_pc_app($drop);
    if (!$k || !$d || $k['id'] === $d['id']) throw new RuntimeException('One of those listings is gone.');
    $d['hidden'] = true;
    $d['replaced_by'] = $k['id'];
    rs_pc_save($d);
    rs_watch_pc_out();
    rs_log('watch', 'merge', $d['id'] . ' -> ' . $k['id']);
    return $d['name'] . ' is hidden and points players to ' . $k['name'] . ' now.';
}
function rs_watch_dupe_ok(string $a, string $b): string {
    if (!rs_pc_app($a) || !rs_pc_app($b)) throw new RuntimeException('One of those listings is gone.');
    rs_kv_put('dupe_ok:' . min($a, $b) . '|' . max($a, $b), '1');
    return 'Okay, they stay separate.';
}
