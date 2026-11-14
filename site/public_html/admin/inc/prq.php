<?php
// (4.6) The PR queue: pull requests that add RecompLauncher support (recomplauncher.json and the README
// badge) to recomps on the SDK support list, drafted every day by a GitHub Action and sent from here
// with one click after a quick look.
//
//   1. The Action (tools/prq/draft.py, .github/workflows/pr-drafts.yml) asks api/prq.php for the next
//      picks: half the newest recomps, half the most downloaded console ports, all with no file yet.
//   2. It drafts the file and the PR's wording and posts them back here as a draft.
//   3. On SDK support, a draft can be edited, sent or skipped. Send forks the repo with the GitHub
//      token saved here (the account the PRs come from), commits recomplauncher.json and the badge on a
//      branch, opens the pull request and marks the recomp "Pull request open" with its link.
//
// Nothing here says who or what wrote the draft: the commits and the PR are the account's own.
declare(strict_types=1);
require_once __DIR__ . '/sdk.php';

const RS_PRQ_BRANCH = 'recomplauncher-support';
const RS_PRQ_TITLE = 'Add RecompLauncher support (recomplauncher.json and badge)';
// What RecompLauncher is, for developers who've never heard of it (the same words as the agent prompt).
const RS_PRQ_WHAT = 'RecompLauncher (https://recomplauncher.com) is a free, open-source launcher for PC recomps and decomps on Windows, Mac and Linux. '
    . 'Players point it at their own copy of the game once, and it installs, sets up and updates the recomp from your GitHub releases, with save backups and crash reports in one place. '
    . 'We want it to be the number one home for recomps and decomps, and we work with developers to help their projects reach more players and hold up to a high standard, for devs and players alike.';

function rs_prq_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec("CREATE TABLE IF NOT EXISTS pc_prq (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app_id VARCHAR(100) NOT NULL,
        repo VARCHAR(200) NOT NULL,
        pick VARCHAR(10) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'todo',
        json MEDIUMTEXT NULL,
        title VARCHAR(200) NOT NULL DEFAULT '',
        body MEDIUMTEXT NULL,
        note TEXT NULL,
        pr_url VARCHAR(300) NOT NULL DEFAULT '',
        error TEXT NULL,
        who VARCHAR(80) NOT NULL DEFAULT '',
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        sent_at DATETIME NULL,
        UNIQUE KEY repo (repo),
        KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    // (4.9) Code changes the drafter proposes (edits, what they add, the file without them), the description
    // without them, and how a queued send goes out (here, code).
    if (!rs_db()->query("SHOW COLUMNS FROM pc_prq LIKE 'code'")->fetch())
        rs_db()->exec("ALTER TABLE pc_prq ADD COLUMN code MEDIUMTEXT NULL, ADD COLUMN body_plain MEDIUMTEXT NULL, ADD COLUMN opts VARCHAR(255) NOT NULL DEFAULT ''");
    // (4.10) What the drafter saw the project already has (features with file:line, its command-line options).
    if (!rs_db()->query("SHOW COLUMNS FROM pc_prq LIKE 'found'")->fetch())
        rs_db()->exec("ALTER TABLE pc_prq ADD COLUMN found MEDIUMTEXT NULL");
    $done = true;
}

// The key the Action sends (X-PRQ-Key). Made here, shown once, kept only as a hash.
function rs_prq_key_new(): string {
    $k = 'prq_' . bin2hex(random_bytes(20));
    rs_set_setting('prq_key_hash', hash('sha256', $k));
    return $k;
}
function rs_prq_key_ok(string $k): bool {
    if ($k === '') return false;
    $h = (string)rs_setting('prq_key_hash', '');
    if ($h !== '' && hash_equals($h, hash('sha256', $k))) return true;
    // (4.7) Draft now: the run on this server carries its own key, good for an hour.
    // Kept in the database's key-value table: the public pages read settings from a copy that can be
    // a minute behind, and the run asks for its picks a second after it starts.
    $run = json_decode((string)(rs_kv_get('prq_run_keys')['v'] ?? '[]'), true) ?: [];
    foreach ($run as $r) if (is_array($r) && (int)($r['until'] ?? 0) > time() && hash_equals((string)$r['h'], hash('sha256', $k))) return true;
    return false;
}

// ---- (4.7) Draft now: the same drafter (tools/prq/draft.py), run on this server straight away ------
// Needs an Anthropic API key saved on SDK support (Setup). Rows show "Drafting" while it runs; one that
// doesn't come back within 10 minutes shows as stuck and can be drafted again.
const RS_PRQ_STUCK = 600;
function rs_prq_script(): string { return dirname(RS_PUBLIC) . '/recompstore-private/prq/draft.py'; }
function rs_prq_now_ready(): bool { return rs_secret_get('prq_ai_key') !== '' && is_file(rs_prq_script()); }
function rs_prq_run(array $appIds): int {
    rs_prq_tables();
    if (!rs_prq_now_ready()) throw new RuntimeException('Add the Anthropic API key in Setup first.');
    $db = rs_db();
    $ids = [];
    foreach (array_slice(array_values(array_unique($appIds)), 0, 40) as $id) {
        $st = $db->prepare("UPDATE pc_prq SET status = 'drafting', error = NULL, updated = NOW() WHERE app_id = ? AND (status = 'todo' OR (status = 'drafting' AND updated < NOW() - INTERVAL " . RS_PRQ_STUCK . " SECOND))");
        $st->execute([(string)$id]);
        if ($st->rowCount()) $ids[] = (string)$id;
    }
    if (!$ids) return 0;
    $key = 'prqrun_' . bin2hex(random_bytes(20));
    $run = array_values(array_filter(json_decode((string)(rs_kv_get('prq_run_keys')['v'] ?? '[]'), true) ?: [], fn($r) => is_array($r) && (int)($r['until'] ?? 0) > time()));
    $run[] = ['h' => hash('sha256', $key), 'until' => time() + 3600];
    rs_kv_put('prq_run_keys', (string)json_encode(array_slice($run, -20)));
    $dir = dirname(rs_prq_script());
    $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $dir, 'LANG' => 'C.UTF-8', 'RS_SITE' => rtrim((string)rs_setting('site_url', 'https://recompstore.com'), '/'), 'RS_PRQ_KEY' => $key,
        'ANTHROPIC_API_KEY' => rs_secret_get('prq_ai_key'), 'GITHUB_TOKEN' => rs_prq_token(), 'PRQ_IDS' => implode(',', $ids), 'PRQ_COUNT' => (string)count($ids)];
    // The shell starts it in the background and returns at once; the run goes on after the page has answered.
    $p = proc_open('setsid python3 -I ' . escapeshellarg(rs_prq_script()) . ' >> ' . escapeshellarg($dir . '/run.log') . ' 2>&1 &',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, $env);
    if (!is_resource($p)) {
        $db->prepare("UPDATE pc_prq SET status = 'todo', error = 'The drafter didn\'t start on the server.' WHERE app_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
        throw new RuntimeException('The drafter didn\'t start on the server.');
    }
    proc_close($p);
    rs_log('sdk', 'prq', 'Draft now: ' . implode(', ', $ids));
    return count($ids);
}
// A pick the drafter couldn't finish: back to waiting, with why.
function rs_prq_fail(string $appId, string $why): void {
    rs_prq_tables();
    rs_db()->prepare("UPDATE pc_prq SET status = 'todo', error = ?, updated = NOW() WHERE app_id = ? AND status = 'drafting'")
        ->execute([mb_substr($why !== '' ? $why : 'Something went wrong while drafting.', 0, 300), $appId]);
}
// (4.10) A "file:line" from the drafter's report as a link to that line on GitHub (else plain text).
function rs_prq_where(string $repo, string $where): string {
    $where = trim($where);
    if ($where === '') return '';
    if (preg_match('#^([\w./+@-]+\.[\w]+):(\d+)(?:[-–](\d+))?#u', $where, $m) && !str_contains($m[1], '..'))
        return '<a href="https://github.com/' . h($repo) . '/blob/HEAD/' . h(implode('/', array_map('rawurlencode', explode('/', $m[1])))) . '#L' . (int)$m[2] . (isset($m[3]) ? '-L' . (int)$m[3] : '') . '" target="_blank" rel="noopener" class="mono">' . h($where) . '</a>';
    return '<span class="mono">' . h($where) . '</span>';
}
// (4.10) The run is still on these (drafts go a few at a time, so the last ones wait their turn).
function rs_prq_touch(array $appIds): void {
    rs_prq_tables();
    $ids = array_slice(array_values(array_filter(array_map('strval', $appIds))), 0, 40);
    if ($ids) rs_db()->prepare("UPDATE pc_prq SET updated = NOW() WHERE status = 'drafting' AND app_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
}
// Draft now's picks: the rows it marked Drafting.
function rs_prq_by_ids(array $appIds): array {
    rs_prq_tables();
    $res = [];
    foreach (array_slice($appIds, 0, 40) as $id) {
        $st = rs_db()->prepare("SELECT pick FROM pc_prq WHERE app_id = ? AND status = 'drafting'");
        $st->execute([(string)$id]);
        $pick = $st->fetchColumn();
        if ($pick === false || !($a = rs_pc_app((string)$id))) continue;
        $res[] = rs_prq_pick_row($a, (string)$pick ?: 'asked');
    }
    return $res;
}
function rs_prq_token(): string { return rs_secret_get('prq_gh_token'); }

// The next picks for the Action: recomps on the list with no file yet, no mark and nothing in the queue,
// GitHub only. Ones added to the queue by hand (status 'todo') come first; then half the newest, half
// the most downloaded console ports.
function rs_prq_targets(int $n): array {
    rs_prq_tables();
    $n = max(1, min(20, $n));
    [$rows] = rs_sdk_all();
    $inQ = [];
    foreach (rs_db()->query('SELECT app_id, status FROM pc_prq') as $q) $inQ[(string)$q['app_id']] = (string)$q['status'];
    $ok = array_filter($rows, fn($r) => $r['state'] === 'missing' && rs_pc_host($r['a']) === '' && !isset($inQ[$r['a']['id']]));
    $out = [];
    foreach ($rows as $id => $r) if (($inQ[$id] ?? '') === 'todo' && count($out) < $n) $out[$id] = 'asked';
    $newest = $ok;
    uasort($newest, fn($x, $y) => strcmp((string)($y['a']['live_at'] ?: $y['a']['added']), (string)($x['a']['live_at'] ?: $x['a']['added'])));
    $popular = array_filter($ok, fn($r) => $r['kind'] === 'console'); // already most downloaded first
    $nNew = (int)ceil(($n - count($out)) / 2);
    foreach ($newest as $id => $r) { if ($nNew <= 0) break; if (!isset($out[$id])) { $out[$id] = 'new'; --$nNew; } }
    foreach ($popular as $id => $r) { if (count($out) >= $n) break; if (!isset($out[$id])) $out[$id] = 'popular'; }
    foreach ($newest as $id => $r) { if (count($out) >= $n) break; if (!isset($out[$id])) $out[$id] = 'new'; }
    $res = [];
    foreach ($out as $id => $pick) {
        $a = $rows[$id]['a'] ?? rs_pc_app($id);
        if ($a) $res[] = rs_prq_pick_row($a, $pick);
    }
    return $res;
}
// What the drafter gets for one recomp.
function rs_prq_pick_row(array $a, string $pick): array {
        $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
        return [
            'id' => $a['id'], 'pick' => $pick, 'repo' => (string)$a['repo'], 'name' => (string)$a['name'], 'game' => (string)$a['game'],
            'console' => (string)$a['origin'], 'page' => 'https://recomplauncher.com/game/' . $a['id'],
            'assets' => array_filter(['win' => (string)($a['asset_win'] ?? ''), 'linux' => (string)($a['asset_linux'] ?? ''), 'mac' => (string)($a['asset_mac'] ?? '')]),
            'needs' => (string)($a['needs'] ?? ''), 'setup_required' => !empty($a['setup_required']), 'setup_steps' => (string)($a['setup_steps'] ?? ''),
            'our_profile' => is_array($boot) ? $boot : null, 'downloads' => (int)$a['downloads'],
        ];
}

// A draft from the Action. A recomp that got a PR or a mark meanwhile is left alone.
function rs_prq_draft_save(array $d): array {
    rs_prq_tables();
    $a = rs_pc_app((string)($d['id'] ?? ''));
    if (!$a || (string)$a['repo'] === '') return ['ok' => false, 'error' => 'No such recomp.'];
    $skip = !empty($d['skip']);
    $json = $d['json'] ?? null;
    if (!$skip && !is_array($json)) return ['ok' => false, 'error' => 'No recomplauncher.json in the draft.'];
    $db = rs_db();
    $st = $db->prepare('SELECT status FROM pc_prq WHERE repo = ?');
    $st->execute([$a['repo']]);
    $was = $st->fetchColumn();
    if (in_array($was, ['sent', 'skipped'], true)) return ['ok' => true, 'kept' => $was];
    $row = [
        $a['id'], $a['repo'], mb_substr((string)($d['pick'] ?? ''), 0, 10), $skip ? 'skipped' : 'draft',
        $skip ? null : json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        mb_substr(trim((string)($d['title'] ?? RS_PRQ_TITLE)), 0, 200) ?: RS_PRQ_TITLE,
        mb_substr((string)($d['body'] ?? ''), 0, 60000), mb_substr(trim((string)($d['note'] ?? ($d['skip_reason'] ?? ''))), 0, 4000),
    ];
    $code = !$skip && is_array($d['code'] ?? null) && !empty($d['code']['edits']) ? json_encode($d['code'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $plain = $code !== null ? mb_substr((string)($d['body_plain'] ?? ''), 0, 60000) : null;
    $found = !$skip && is_array($d['found'] ?? null) ? mb_substr((string)json_encode($d['found'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 200000) : null;
    if ($was === false) $db->prepare('INSERT INTO pc_prq (app_id, repo, pick, status, json, title, body, note, code, body_plain, found, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([...$row, $code, $plain, $found]);
    else $db->prepare('UPDATE pc_prq SET app_id = ?, repo = ?, pick = ?, status = ?, json = ?, title = ?, body = ?, note = ?, code = ?, body_plain = ?, found = ?, error = NULL, updated = NOW() WHERE repo = ?')->execute([...$row, $code, $plain, $found, $a['repo']]);
    return ['ok' => true, 'status' => $skip ? 'skipped' : 'draft'];
}

function rs_prq_list(array $statuses, int $limit = 100): array {
    rs_prq_tables();
    $in = implode(',', array_map(fn($s) => rs_db()->quote($s), $statuses));
    return rs_db()->query("SELECT * FROM pc_prq WHERE status IN ($in) ORDER BY " . (in_array('sent', $statuses, true) ? 'sent_at DESC, updated DESC' : 'updated DESC') . ' LIMIT ' . (int)$limit)->fetchAll();
}
function rs_prq_get(int $id): ?array {
    rs_prq_tables();
    $st = rs_db()->prepare('SELECT * FROM pc_prq WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
// Queue recomps by hand (SDK support, picked rows): the Action drafts these first.
function rs_prq_queue(array $ids): int {
    rs_prq_tables();
    $n = 0;
    foreach ($ids as $id) {
        $a = rs_pc_app((string)$id);
        if (!$a || (string)$a['repo'] === '' || rs_pc_host($a) !== '') continue;
        // (4.7) One the drafter skipped goes back in line when it's picked by hand.
        $st = rs_db()->prepare("INSERT INTO pc_prq (app_id, repo, pick, status, created, updated) VALUES (?, ?, 'asked', 'todo', NOW(), NOW())
            ON DUPLICATE KEY UPDATE pick = IF(status = 'skipped', 'asked', pick), json = IF(status = 'skipped', NULL, json), body = IF(status = 'skipped', NULL, body),
            note = IF(status = 'skipped', NULL, note), error = IF(status = 'skipped', NULL, error), updated = IF(status = 'skipped', NOW(), updated),
            status = IF(status = 'skipped', 'todo', status)");
        $st->execute([$a['id'], $a['repo']]);
        $n += min(1, $st->rowCount());
    }
    return $n;
}

// ---- GitHub, as the account whose token is saved ------------------------------------------------

function rs_prq_gh(string $method, string $path, ?array $body = null): array {
    $tok = rs_prq_token();
    if ($tok === '') throw new RuntimeException('Save the GitHub token for pull requests first (PR queue, Settings).');
    $c = curl_init(rs_gh_api() . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'RecompLauncher', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'Content-Type: application/json'],
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($c, $opts);
    $raw = curl_exec($c);
    $code = curl_errno($c) ? 0 : (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['status' => $code, 'json' => is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}
function rs_prq_gh_ok(array $r, string $what): array {
    if ($r['status'] >= 200 && $r['status'] < 300) return $r['json'];
    $m = (string)($r['json']['message'] ?? '');
    throw new RuntimeException($what . ' failed (GitHub ' . $r['status'] . ($m !== '' ? ': ' . $m : '') . ').');
}

// The badge, as on the press page, linking to the recomp's page.
function rs_prq_badge(string $id): string {
    $base = 'https://recomplauncher.com/assets/press/launcher/';
    return '<a href="https://recomplauncher.com/game/' . $id . '"><picture><source media="(prefers-color-scheme: dark)" srcset="' . $base . 'badge-light.svg"><img alt="Get it on RecompLauncher" src="' . $base . 'badge-dark.svg" height="48"></picture></a>';
}
// The README with the badge near the top: under the first heading (or its badge row), else first.
function rs_prq_readme_with_badge(string $md, string $badge): string {
    if (stripos($md, 'Get it on RecompLauncher') !== false || stripos($md, 'recomplauncher.com/assets/press') !== false) return $md;
    $nl = str_contains($md, "\r\n") ? "\r\n" : "\n";
    $lines = explode($nl, $md);
    // Under the title when the README opens with one (a heading, or a centered block), else at the top.
    $at = 0;
    foreach ($lines as $i => $l) {
        if (trim($l) === '') continue;
        if (preg_match('/^\s*(#{1,2}\s|<h1|<p align|<div align)/i', $l)) $at = $i + 1;
        break;
    }
    // Past a block of badges right under the title.
    while ($at < count($lines) && preg_match('/^\s*(\[!\[|<a [^>]*><img|!\[)/i', $lines[$at])) $at++;
    $ins = [$badge];
    if ($at > 0 && trim($lines[$at - 1] ?? '') !== '') array_unshift($ins, '');
    if (trim($lines[$at] ?? 'x') !== '') $ins[] = '';
    array_splice($lines, $at, 0, $ins);
    return implode($nl, $lines);
}

// Send one draft: fork, branch, the two commits, the pull request, the "Pull request open" mark.
function rs_prq_send(int $id, bool $here = false, bool $codeOn = true, string $who = ''): string {
    $q = rs_prq_get($id);
    if (!$q || !in_array($q['status'], ['draft', 'outbox'], true)) throw new RuntimeException('That draft is gone or was already sent.');
    if ($who === '') $who = function_exists('rs_me_name') ? (string)rs_me_name() : '';
    if ((string)$q['pr_url'] !== '') return rs_prq_update($id, $here, $codeOn, $who); // (4.9) a redraft of one we sent
    $a = rs_pc_app((string)$q['app_id']);
    if (!$a) throw new RuntimeException('That recomp isn\'t on RecompLauncher any more.');
    [$json, $edits, $plain, $body] = rs_prq_parts($q, $codeOn);
    if (!is_array($json)) throw new RuntimeException('The recomplauncher.json in this draft isn\'t valid JSON.');
    $repo = (string)$q['repo'];
    try {
        $me = rs_prq_gh_ok(rs_prq_gh('GET', '/user'), 'Reading the token\'s account');
        $login = (string)$me['login'];
        $up = rs_prq_gh_ok(rs_prq_gh('GET', '/repos/' . $repo), 'Reading ' . $repo);
        if (!empty($up['archived'])) throw new RuntimeException($repo . ' is archived, so it takes no pull requests.');
        $base = (string)$up['default_branch'];
        // Someone already on it: an open PR that adds recomplauncher.json.
        $open = rs_prq_gh('GET', '/repos/' . $repo . '/pulls?state=open&per_page=50');
        foreach ((array)$open['json'] as $p) if (stripos((string)($p['title'] ?? '') . ' ' . (string)($p['body'] ?? ''), 'recomplauncher') !== false) throw new RuntimeException('There\'s already an open pull request for it: ' . $p['html_url']);
        // (4.6) Xarishark sends some by hand: one that was already sent and closed or merged (by anyone)
        // means no second one either.
        $had = rs_prq_gh('GET', '/search/issues?per_page=5&q=' . rawurlencode('repo:' . $repo . ' is:pr recomplauncher in:title,body'));
        if ($had['status'] === 200 && !empty($had['json']['items'][0]['html_url'])) throw new RuntimeException('It already had a RecompLauncher pull request: ' . $had['json']['items'][0]['html_url'] . '. Skip this one.');
        $head = rs_prq_gh_ok(rs_prq_gh('GET', '/repos/' . $repo . '/git/ref/heads/' . rawurlencode($base)), 'Reading its ' . $base . ' branch');
        $sha = (string)$head['object']['sha'];
        // The fork (GitHub hands back the existing one), then wait for it to be ready.
        $fork = rs_prq_gh_ok(rs_prq_gh('POST', '/repos/' . $repo . '/forks', ['default_branch_only' => true]), 'Forking');
        $forkName = (string)$fork['full_name'];
        for ($i = 0; $i < 12; $i++) { if (rs_prq_gh('GET', '/repos/' . $forkName . '/commits?per_page=1')['status'] === 200) break; sleep(2); }
        rs_prq_gh('POST', '/repos/' . $forkName . '/merge-upstream', ['branch' => $base]);
        $branch = RS_PRQ_BRANCH;
        $mk = rs_prq_gh('POST', '/repos/' . $forkName . '/git/refs', ['ref' => 'refs/heads/' . $branch, 'sha' => $sha]);
        if ($mk['status'] === 422) { // left over from before: start it again from upstream
            rs_prq_gh_ok(rs_prq_gh('PATCH', '/repos/' . $forkName . '/git/refs/heads/' . $branch, ['sha' => $sha, 'force' => true]), 'Resetting the branch');
        } elseif ($mk['status'] < 200 || $mk['status'] >= 300) rs_prq_gh_ok($mk, 'Making the branch');
        // 0. (4.9) The code changes, each file its own commit, before the file that uses them.
        if ($edits) rs_prq_apply_code($forkName, $branch, $edits);
        // 1. recomplauncher.json
        $file = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $have = rs_prq_gh('GET', '/repos/' . $forkName . '/contents/recomplauncher.json?ref=' . rawurlencode($branch));
        rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/recomplauncher.json', array_filter([
            'message' => 'Add recomplauncher.json for RecompLauncher', 'content' => base64_encode($file), 'branch' => $branch,
            'sha' => $have['status'] === 200 ? (string)($have['json']['sha'] ?? '') : null,
        ])), 'Adding recomplauncher.json');
        // 2. The badge in the README
        $rd = rs_prq_gh('GET', '/repos/' . $forkName . '/readme?ref=' . rawurlencode($branch));
        if ($rd['status'] === 200) {
            $md = (string)base64_decode((string)($rd['json']['content'] ?? ''));
            $new = rs_prq_readme_with_badge($md, rs_prq_badge($a['id']));
            if ($new !== $md) rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/' . str_replace('%2F', '/', rawurlencode((string)$rd['json']['path'])), [
                'message' => 'README: Get it on RecompLauncher badge', 'content' => base64_encode($new), 'branch' => $branch, 'sha' => (string)$rd['json']['sha'],
            ]), 'Adding the badge');
        }
        // 3. The pull request
        $pr = rs_prq_gh_ok(rs_prq_gh('POST', '/repos/' . $repo . '/pulls', [
            'title' => (string)$q['title'] ?: RS_PRQ_TITLE, 'head' => $login . ':' . $branch, 'base' => $base,
            'body' => $body, 'maintainer_can_modify' => true,
        ]), 'Opening the pull request');
        $url = (string)$pr['html_url'];
    } catch (Throwable $e) {
        rs_db()->prepare("UPDATE pc_prq SET error = ?, updated = NOW() WHERE id = ?")->execute([$e->getMessage(), $id]);
        throw $e;
    }
    rs_db()->prepare("UPDATE pc_prq SET status = 'sent', pr_url = ?, error = NULL, who = ?, sent_at = NOW(), updated = NOW() WHERE id = ?")->execute([$url, $who, $id]);
    $m = rs_sdk_marks();
    $m[$a['id']] = ['kind' => 'pr', 'url' => $url, 'note' => 'PR queue', 'who' => $who, 'at' => gmdate('Y-m-d')];
    rs_sdk_marks_save($m);
    rs_log('sdk', $a['id'], 'pull request sent: ' . $url . ($edits ? ' (with code changes)' : ''));
    if ($here) rs_prq_use_here($a, $plain);
    return $url;
}

// (4.9) What goes out: [file, code edits, the file the launcher can use today, description]. With the code
// changes left out, the file and description from before them.
function rs_prq_parts(array $q, bool $codeOn): array {
    $json = json_decode((string)$q['json'], true);
    if (!is_array($json)) throw new RuntimeException('The recomplauncher.json in this draft isn\'t valid JSON.');
    $code = (string)($q['code'] ?? '') !== '' ? json_decode((string)$q['code'], true) : null;
    if (!is_array($code) || empty($code['edits'])) return [$json, [], $json, (string)$q['body']];
    $plain = is_array($code['plain'] ?? null) ? $code['plain'] : $json;
    if (!$codeOn) return [$plain, [], $plain, (string)($q['body_plain'] ?? '') !== '' ? (string)$q['body_plain'] : (string)$q['body']];
    return [$json, (array)$code['edits'], $plain, (string)$q['body']];
}
// The edits put on the branch: every file read and checked first, so nothing goes up half done. An edit
// that's already there (sent before) is fine.
function rs_prq_apply_code(string $forkName, string $branch, array $edits): void {
    $files = [];
    foreach ($edits as $e) {
        $path = (string)($e['path'] ?? '');
        if ($path === '' || str_contains($path, '..')) throw new RuntimeException('A code change has a bad file path.');
        if (!isset($files[$path])) {
            $r = rs_prq_gh('GET', '/repos/' . $forkName . '/contents/' . str_replace('%2F', '/', rawurlencode($path)) . '?ref=' . rawurlencode($branch));
            if ($r['status'] !== 200) throw new RuntimeException('Couldn\'t read ' . $path . ' for the code change (GitHub ' . $r['status'] . '). Draft it again.');
            $files[$path] = ['sha' => (string)$r['json']['sha'], 'old' => (string)base64_decode((string)($r['json']['content'] ?? '')), 'new' => null];
            $files[$path]['new'] = $files[$path]['old'];
        }
        $find = (string)($e['find'] ?? ''); $rep = (string)($e['replace'] ?? '');
        $n = substr_count($files[$path]['new'], $find);
        if ($n === 1) $files[$path]['new'] = str_replace($find, $rep, $files[$path]['new']);
        elseif (!($n === 0 && $rep !== '' && str_contains($files[$path]['new'], $rep)))
            throw new RuntimeException($path . ' changed since the draft, so its code change doesn\'t fit any more. Draft it again.');
    }
    foreach ($files as $path => $f) {
        if ($f['new'] === $f['old']) continue;
        rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/' . str_replace('%2F', '/', rawurlencode($path)), [
            'message' => 'Add launch options so launchers can pass the game\'s location (' . basename($path) . ')', 'content' => base64_encode($f['new']), 'branch' => $branch, 'sha' => $f['sha'],
        ]), 'Committing the change to ' . $path);
    }
}

// ---- (4.9) Xarishark: at most 5 new pull requests an hour ------------------------------------------
const RS_PRQ_PER_HOUR = 5;
function rs_prq_sent_last_hour(): int { return (int)rs_db()->query("SELECT COUNT(*) FROM pc_prq WHERE status = 'sent' AND sent_at > NOW() - INTERVAL 1 HOUR AND pick <> 'redo'")->fetchColumn(); }
// Send pressed: straight out while there's room this hour, else in line. -> url, or '' when it's in line.
function rs_prq_send_or_queue(int $id, bool $here, bool $codeOn, string $who): string {
    rs_prq_tables();
    $q = rs_prq_get($id);
    if (!$q || $q['status'] !== 'draft') throw new RuntimeException('That draft is gone or was already sent.');
    $waiting = (int)rs_db()->query("SELECT COUNT(*) FROM pc_prq WHERE status = 'outbox'")->fetchColumn();
    if ((string)$q['pr_url'] !== '' || ($waiting === 0 && rs_prq_sent_last_hour() < RS_PRQ_PER_HOUR)) return rs_prq_send($id, $here, $codeOn, $who);
    rs_db()->prepare("UPDATE pc_prq SET status = 'outbox', opts = ?, who = ?, updated = NOW() WHERE id = ? AND status = 'draft'")
        ->execute([json_encode(['here' => $here, 'code' => $codeOn]), $who, $id]);
    return '';
}
// The half-hourly job: the next ones in line, keeping to the hourly number. -> [sent, failed]
function rs_prq_send_due(): array {
    rs_prq_tables();
    $done = [0, 0];
    if (rs_prq_token() === '') return $done;
    $room = RS_PRQ_PER_HOUR - rs_prq_sent_last_hour();
    if ($room <= 0) return $done;
    foreach (rs_db()->query("SELECT id, opts, who FROM pc_prq WHERE status = 'outbox' ORDER BY updated LIMIT " . min(3, $room))->fetchAll() as $r) {
        $o = json_decode((string)$r['opts'], true) ?: [];
        try { rs_prq_send((int)$r['id'], !empty($o['here']), ($o['code'] ?? true) !== false, (string)$r['who']); ++$done[0]; }
        catch (Throwable $e) { rs_db()->prepare("UPDATE pc_prq SET status = 'draft', error = ?, updated = NOW() WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), (int)$r['id']]); ++$done[1]; }
    }
    return $done;
}

// ---- (4.9) Xarishark: redraft a pull request we already sent, and use the file on our side meanwhile ----

// Back in line to be drafted again from scratch (the drafter then reads the repo's command line too).
function rs_prq_reopen(int $id): void {
    rs_prq_tables();
    $st = rs_db()->prepare("UPDATE pc_prq SET status = 'todo', pick = 'redo', error = NULL, updated = NOW() WHERE id = ? AND status = 'sent' AND pr_url <> ''");
    $st->execute([$id]);
    if (!$st->rowCount()) throw new RuntimeException('That pull request isn\'t one we sent, or it\'s already being drafted again.');
}
// A redraft that isn't wanted: the pull request stays as it was.
function rs_prq_keep_sent(int $id): void {
    rs_db()->prepare("UPDATE pc_prq SET status = 'sent', pick = 'redo', updated = NOW() WHERE id = ? AND status IN ('draft', 'todo') AND pr_url <> ''")->execute([$id]);
}
// The redrafted file and description pushed to the pull request we already have open.
function rs_prq_update(int $id, bool $here = false, bool $codeOn = true, string $who = ''): string {
    $q = rs_prq_get($id);
    if (!$q || !in_array($q['status'], ['draft', 'outbox'], true) || (string)$q['pr_url'] === '') throw new RuntimeException('That redraft is gone or was already sent.');
    $a = rs_pc_app((string)$q['app_id']);
    if (!$a) throw new RuntimeException('That recomp isn\'t on RecompLauncher any more.');
    [$json, $edits, $plain, $body] = rs_prq_parts($q, $codeOn);
    if (!preg_match('#github\.com/([\w.-]+/[\w.-]+)/pull/(\d+)$#', (string)$q['pr_url'], $m)) throw new RuntimeException('Its pull request link isn\'t one GitHub gave us.');
    try {
        $login = (string)rs_prq_gh_ok(rs_prq_gh('GET', '/user'), 'Reading the token\'s account')['login'];
        $p = rs_prq_gh_ok(rs_prq_gh('GET', '/repos/' . $m[1] . '/pulls/' . $m[2]), 'Reading the pull request');
        if (!empty($p['merged'])) throw new RuntimeException('That pull request was merged already. Nothing to update.');
        if (($p['state'] ?? '') !== 'open') throw new RuntimeException('That pull request was closed, so it can\'t be updated. Skip this redraft.');
        if (strcasecmp((string)($p['head']['repo']['owner']['login'] ?? ''), $login) !== 0) throw new RuntimeException('It was sent from another account (@' . ($p['head']['repo']['owner']['login'] ?? '?') . '), so only that account can update it.');
        $forkName = (string)$p['head']['repo']['full_name'];
        $branch = (string)$p['head']['ref'];
        // The developer has their own file now: ours would replace it, so a person decides.
        $baseRef = (string)($p['base']['ref'] ?? '');
        if ($baseRef !== '' && rs_prq_gh('GET', '/repos/' . $m[1] . '/contents/recomplauncher.json?ref=' . rawurlencode($baseRef))['status'] === 200)
            throw new RuntimeException('The developer has their own recomplauncher.json now, so this pull request may not be needed. Look at it on GitHub, then choose Keep it as it was.');
        if ($edits) rs_prq_apply_code($forkName, $branch, $edits);
        $file = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $have = rs_prq_gh('GET', '/repos/' . $forkName . '/contents/recomplauncher.json?ref=' . rawurlencode($branch));
        $old = $have['status'] === 200 ? (string)base64_decode((string)($have['json']['content'] ?? '')) : '';
        if ($old !== $file) rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/recomplauncher.json', array_filter([
            'message' => 'Update recomplauncher.json', 'content' => base64_encode($file), 'branch' => $branch,
            'sha' => $have['status'] === 200 ? (string)($have['json']['sha'] ?? '') : null,
        ])), 'Updating recomplauncher.json');
        $rd = rs_prq_gh('GET', '/repos/' . $forkName . '/readme?ref=' . rawurlencode($branch));
        if ($rd['status'] === 200) {
            $md = (string)base64_decode((string)($rd['json']['content'] ?? ''));
            $new = rs_prq_readme_with_badge($md, rs_prq_badge($a['id']));
            if ($new !== $md) rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/' . str_replace('%2F', '/', rawurlencode((string)$rd['json']['path'])), [
                'message' => 'README: Get it on RecompLauncher badge', 'content' => base64_encode($new), 'branch' => $branch, 'sha' => (string)$rd['json']['sha'],
            ]), 'Adding the badge');
        }
        rs_prq_gh_ok(rs_prq_gh('PATCH', '/repos/' . $m[1] . '/pulls/' . $m[2], ['title' => (string)$q['title'] ?: RS_PRQ_TITLE, 'body' => $body]), 'Updating the description');
    } catch (Throwable $e) {
        rs_db()->prepare("UPDATE pc_prq SET error = ?, updated = NOW() WHERE id = ?")->execute([$e->getMessage(), $id]);
        throw $e;
    }
    rs_db()->prepare("UPDATE pc_prq SET status = 'sent', error = NULL, who = ?, updated = NOW() WHERE id = ?")->execute([$who, $id]);
    rs_log('sdk', $a['id'], 'pull request updated: ' . $q['pr_url'] . ($edits ? ' (with code changes)' : ''));
    if ($here) rs_prq_use_here($a, $plain);
    return (string)$q['pr_url'];
}
// The file goes out in the catalog as the recomp's own (its Boot profile field), so the launcher uses it
// before the developer merges anything. A profile someone wrote by hand there is never replaced.
// -> '' when it's in use, else why not.
function rs_prq_use_here(array $a, array $json): string {
    $cur = trim((string)($a['boot'] ?? ''));
    $b = $cur !== '' ? json_decode($cur, true) : null;
    if ($cur !== '' && !(is_array($b) && (int)($b['format'] ?? 0) === 1)) return 'It already has its own Boot profile, so that one stays.';
    if (!isset($json['start'])) return 'The file has no "start", so the launcher wouldn\'t use it.';
    $a['boot'] = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    rs_pc_save($a);
    if (empty($a['hidden'])) { try { rs_pc_publish_one($a['id']); } catch (Throwable $e) { /* the next publish carries it */ } }
    rs_log('pc', $a['id'], 'Boot profile from the PR queue draft (until the developer merges it)');
    return '';
}

// (4.8) Xarishark: sent pull requests that later conflict (the developer edited the README after we
// sent it) are rebuilt on their latest code, with the same two changes, so they stay one click to
// merge. Run by the half-hourly job; each open PR is looked at every 6 hours at most. What it did is
// kept per PR in kv_cache ('prqfix:<id>' => {at, n, why}) and shown in the PR queue.
function rs_prq_fix_state(int $id): ?array {
    $c = rs_kv_get('prqfix:' . $id);
    return $c ? (json_decode((string)$c['v'], true) ?: null) : null;
}
function rs_prq_fix_conflicts(int $secs = 60): array {
    rs_prq_tables();
    $done = ['looked' => 0, 'fixed' => 0, 'flagged' => 0];
    if (rs_prq_token() === '') return $done;
    $t0 = microtime(true);
    $rows = rs_db()->query("SELECT * FROM pc_prq WHERE status = 'sent' AND pr_url <> '' AND sent_at > NOW() - INTERVAL 120 DAY ORDER BY sent_at DESC")->fetchAll();
    $login = '';
    foreach ($rows as $q) {
        if (microtime(true) - $t0 > $secs) break;
        if (!preg_match('#github\.com/([\w.-]+/[\w.-]+)/pull/(\d+)$#', (string)$q['pr_url'], $m)) continue;
        $id = (int)$q['id'];
        if (($c = rs_kv_get('prqchk:' . $id)) && $c['age'] < 6 * 3600) continue;
        rs_kv_put('prqchk:' . $id, '1');
        ++$done['looked'];
        try {
            $pr = rs_prq_gh('GET', '/repos/' . $m[1] . '/pulls/' . $m[2]);
            if ($pr['status'] !== 200) continue;
            $p = (array)$pr['json'];
            if (($p['state'] ?? '') !== 'open') continue;
            // GitHub works out mergeable in the background: null means ask again next run.
            if (($p['mergeable'] ?? null) !== false || ($p['mergeable_state'] ?? '') !== 'dirty') continue;
            $why = rs_prq_rebuild($q, $p, $login);
            $st = rs_prq_fix_state($id) ?: ['n' => 0];
            rs_kv_put('prqfix:' . $id, (string)json_encode(['at' => gmdate('Y-m-d H:i'), 'n' => (int)$st['n'] + ($why === '' ? 1 : 0), 'why' => $why]));
            if ($why === '') { ++$done['fixed']; rs_log('sdk', (string)$q['app_id'], 'pull request re-drafted after a conflict: ' . $q['pr_url']); }
            else ++$done['flagged'];
        } catch (Throwable $e) { /* next run */ }
    }
    return $done;
}
// One PR's branch made again from the developer's newest code: recomplauncher.json and the badge.
// Returns '' when done, else why a person should look (nothing is pushed then).
function rs_prq_rebuild(array $q, array $p, string &$login): string {
    $a = rs_pc_app((string)$q['app_id']);
    $json = json_decode((string)$q['json'], true);
    if (!$a || !is_array($json)) return 'Its draft is gone, so it can\'t be rebuilt.';
    $repo = (string)$q['repo'];
    $base = (string)($p['base']['ref'] ?? '');
    $forkName = (string)($p['head']['repo']['full_name'] ?? '');
    $branch = (string)($p['head']['ref'] ?? '');
    if ($base === '' || $forkName === '' || $branch === '') return 'GitHub didn\'t say where the pull request\'s branch is.';
    if ($login === '') $login = (string)(rs_prq_gh('GET', '/user')['json']['login'] ?? '');
    if (strcasecmp((string)($p['head']['repo']['owner']['login'] ?? ''), $login) !== 0) return 'It was sent from another account, so only that account can update it.';
    // They added their own recomplauncher.json meanwhile: ours would replace theirs, so a person decides.
    if (rs_prq_gh('GET', '/repos/' . $repo . '/contents/recomplauncher.json?ref=' . rawurlencode($base))['status'] === 200) return 'The developer added their own recomplauncher.json. Look at the pull request: it may not be needed any more.';
    $head = rs_prq_gh_ok(rs_prq_gh('GET', '/repos/' . $repo . '/git/ref/heads/' . rawurlencode($base)), 'Reading its ' . $base . ' branch');
    $sha = (string)$head['object']['sha'];
    rs_prq_gh('POST', '/repos/' . $forkName . '/merge-upstream', ['branch' => $base]);
    rs_prq_gh_ok(rs_prq_gh('PATCH', '/repos/' . $forkName . '/git/refs/heads/' . rawurlencode($branch), ['sha' => $sha, 'force' => true]), 'Moving the branch onto their newest code');
    $file = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/recomplauncher.json', [
        'message' => 'Add recomplauncher.json for RecompLauncher', 'content' => base64_encode($file), 'branch' => $branch,
    ]), 'Adding recomplauncher.json');
    $rd = rs_prq_gh('GET', '/repos/' . $forkName . '/readme?ref=' . rawurlencode($branch));
    if ($rd['status'] === 200) {
        $md = (string)base64_decode((string)($rd['json']['content'] ?? ''));
        $new = rs_prq_readme_with_badge($md, rs_prq_badge($a['id']));
        if ($new !== $md) rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/' . str_replace('%2F', '/', rawurlencode((string)$rd['json']['path'])), [
            'message' => 'README: Get it on RecompLauncher badge', 'content' => base64_encode($new), 'branch' => $branch, 'sha' => (string)$rd['json']['sha'],
        ]), 'Adding the badge');
    }
    return '';
}
