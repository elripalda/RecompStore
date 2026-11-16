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
// ---- (4.11) After a pull request: our copy follows the developer's file ------------------------------------------
// The launcher reads a developer's recomplauncher.json only from their release; until it's there, players get our
// profile. So for every game we sent a pull request to (ours from the queue, or one marked "pull request open"), the
// 30-minute job reads the file in their repo every 6 hours. When they've changed it and it passes the launcher's
// schema, our profile becomes their file and the game is republished. It stops once their release ships the file
// (theirs wins then), and never replaces an older-style profile of ours (those keep Easy Setup some files don't have).
// State per game in kv_cache 'prqsync:<id>' => {at, state: synced|same|none|bad|release|kept, why}.
function rs_json_norm($v) {
    if (!is_array($v)) return $v;
    if (!array_is_list($v)) ksort($v);
    return array_map('rs_json_norm', $v);
}
function rs_prq_same_file(array $x, array $y): bool {
    foreach (['repo', '$schema'] as $k) { unset($x[$k], $y[$k]); }
    return json_encode(rs_json_norm($x)) === json_encode(rs_json_norm($y));
}
function rs_prq_sync_repo_files(int $secs = 40): array {
    require_once __DIR__ . '/sdk.php';
    require_once __DIR__ . '/sdkcheck.php';
    rs_prq_tables();
    $t = microtime(true);
    $ids = array_column(rs_db()->query("SELECT DISTINCT app_id FROM pc_prq WHERE status = 'sent'")->fetchAll(), 'app_id');
    foreach (rs_sdk_marks() as $id => $m) if (($m['kind'] ?? '') === 'pr') $ids[] = (string)$id;
    // (4.12) And any game whose developer put the file in their repo themselves: many close our pull
    // request and add the commits on their own, which no queue row or mark says.
    foreach (rs_sdk_checks() as $id => $c) if (is_array($c['v']) && ($c['v']['has'] ?? '') === 'repo') $ids[] = (string)$id;
    $n = ['looked' => 0, 'synced' => 0, 'bad' => 0];
    foreach (array_values(array_unique(array_map('strval', $ids))) as $id) {
        if (microtime(true) - $t > $secs) break;
        $c = rs_kv_get('prqsync:' . $id);
        if ($c && $c['age'] < 6 * 3600) continue;
        $a = rs_pc_app($id);
        if (!$a || (string)$a['repo'] === '' || rs_pc_host($a) !== '') continue;
        $n['looked']++;
        $put = function (string $state, string $why = '') use ($id) { rs_kv_put('prqsync:' . $id, (string)json_encode(['at' => time(), 'state' => $state, 'why' => $why])); };
        $sdk = rs_kv_get('sdk:' . $id);
        if ($sdk && ((json_decode($sdk['v'], true) ?: [])['has'] ?? '') === 'release') { $put('release'); continue; } // theirs wins
        $boot = trim((string)($a['boot'] ?? ''));
        $ours = $boot !== '' ? json_decode($boot, true) : null;
        if ($boot !== '' && !(is_array($ours) && (int)($ours['format'] ?? 0) === 1)) { $put('kept', 'our older profile stays'); continue; }
        try { [$st, $txt] = rs_sdk_repo_file('', (string)$a['repo']); } catch (Throwable $e) { continue; }
        if ($st === 404) { $put('none'); continue; } // not merged (or no file) yet
        if ($st !== 200) continue; // GitHub didn't answer: next run
        $theirs = json_decode((string)$txt, true);
        $errs = is_array($theirs) && ($schema = rs_sdk_schema()) ? rs_json_schema_errors(json_decode((string)$txt), $schema, 5) : [['(top)', 'isn\'t valid JSON']];
        if (!is_array($theirs) || $errs || !isset($theirs['start'])) {
            $why = $errs ? implode('; ', array_map(fn($e) => $e[0] . ' ' . $e[1], $errs)) : 'no "start"';
            if (($c ? (json_decode($c['v'], true)['why'] ?? '') : '') !== $why) rs_log('sdk', $id, 'Their recomplauncher.json in the repo fails the launcher\'s rules, so our copy stays: ' . $why);
            $put('bad', $why); $n['bad']++; continue;
        }
        if (is_array($ours) && rs_prq_same_file($ours, $theirs)) { $put('same'); continue; }
        // The program it starts has to be in their current download (a file ahead of its release, or a typo).
        $prog = array_map('strval', (array)($theirs['start']['program'] ?? []));
        // (A file with a setup step can start a program that step builds, so it isn't in the download.)
        if (!isset($theirs['prepare']) && rs_sdk_program_in($prog, rs_sdk_release_names($a)) === false) {
            $why = implode(', ', $prog) . ' isn\'t in their current download';
            if (($c ? (json_decode($c['v'], true)['why'] ?? '') : '') !== $why) rs_log('sdk', $id, 'Their recomplauncher.json in the repo starts a program their release doesn\'t have, so our copy stays: ' . $why);
            $put('bad', $why); $n['bad']++; continue;
        }
        unset($theirs['repo']);
        $a['boot'] = json_encode($theirs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        rs_pc_save($a);
        if (empty($a['hidden'])) { try { rs_pc_publish_one($a['id']); } catch (Throwable $e) { /* the next publish carries it */ } }
        rs_db()->prepare('DELETE FROM kv_cache WHERE k = ?')->execute(['sdkhealth:' . $id]);
        rs_log('pc', $id, 'Our profile now follows the developer\'s recomplauncher.json from their repo (they changed it)');
        $put('synced'); $n['synced']++;
    }
    return $n;
}

// ---- (4.11) Before sending: what a draft gives players, what's missing, and what stops it going out ------------
// -> ['level' => ok|warn|bad, 'blockers' => [..], 'warns' => [..], 'gives' => [[name, yes|no|na, how]], 'new' => bool]
// A blocker means it can't be sent (the server refuses too): a file that isn't JSON, fails the launcher's rules,
// or has nothing to start. Warnings are shown in the review and the Send confirmation.
function rs_prq_review(array $q): array {
    require_once __DIR__ . '/sdkcheck.php';
    $j = json_decode((string)($q['json'] ?? ''), true);
    $found = (string)($q['found'] ?? '') !== '' ? (json_decode((string)$q['found'], true) ?: []) : [];
    $code = (string)($q['code'] ?? '') !== '' ? (json_decode((string)$q['code'], true) ?: []) : [];
    $feat = [];
    foreach ((array)($found['features'] ?? []) as $f) if (is_array($f) && isset($f['what'])) $feat[strtolower((string)$f['what'])] = $f;
    $new = (int)($found['v'] ?? 0) >= 411 || isset($feat['release packaging']);
    $out = ['level' => 'ok', 'blockers' => [], 'warns' => [], 'gives' => [], 'new' => $new];
    if (!is_array($j)) { $out['blockers'][] = 'The recomplauncher.json isn\'t valid JSON. Fix it under "Edit the text and the file", or make a new draft.'; $out['level'] = 'bad'; return $out; }
    if (($schema = rs_sdk_schema()) !== null)
        foreach (array_slice(rs_json_schema_errors(json_decode((string)$q['json']), $schema), 0, 5) as [$path, $msg]) $out['blockers'][] = 'The launcher would reject the file: ' . $path . ' ' . $msg . '.';
    if (!isset($j['start'])) $out['blockers'][] = 'The file doesn\'t say which program to start ("start").';
    $edits = !empty($code['edits']);
    $added = fn(string $what) => !empty($feat[$what]['added']) || str_starts_with(trim((string)($feat[$what]['how'] ?? '')), 'Added in this pull request');
    $why = fn(string $what) => trim((string)($feat[$what]['why'] ?? ''));
    $notNeeded = fn(string $what) => ($feat[$what]['has'] ?? null) === false && ($feat[$what]['needed'] ?? null) === false;
    // Easy Setup
    // (Easy Setup that only works with this PR's code changes: the file without them doesn't have it.)
    $plainJ = is_array($code['plain'] ?? null) ? $code['plain'] : $j;
    $easyNew = $edits && !rs_sdk_easy($plainJ);
    if (rs_sdk_easy($j)) $out['gives'][] = ['Easy Setup from the player\'s copy', 'yes', $easyNew || $added('game location') ? 'through what this PR adds to their code (' . (isset($j['prepare']) && !isset($plainJ['prepare']) ? 'a new setup step' : 'a new option') . '), so only once they merge it' : 'through an option the game already has'];
    elseif ($notNeeded('game location')) $out['gives'][] = ['Easy Setup from the player\'s copy', 'na', 'not needed: ' . ($why('game location') ?: 'the game needs nothing from the player')];
    else { $out['gives'][] = ['Easy Setup from the player\'s copy', 'no', $why('game location') ?: 'the game has no way to be handed the player\'s copy yet']; $out['warns'][] = 'No Easy Setup: players still set the game up by hand.'; }
    // Setup step
    if (isset($j['prepare'])) $out['gives'][] = ['Setup step', 'yes', (string)($j['prepare']['label'] ?? '') . ($added('setup or build step') ? ' (a new --prepare this PR adds)' : '')];
    elseif (!empty($feat['setup or build step']['has']) && ($feat['setup or build step']['needed'] ?? null) !== false) { $out['gives'][] = ['Setup step', 'no', $why('setup or build step') ?: 'it has one, but the launcher can\'t run it yet']; $out['warns'][] = 'Its setup step isn\'t in this PR, so players run it themselves.'; }
    else $out['gives'][] = ['Setup step', 'na', 'not needed'];
    // Settings, saves, logs
    $labels = array_values(array_filter(array_map(fn($x) => is_array($x) ? (string)($x['label'] ?? '') : '', (array)($j['settings'] ?? []))));
    $out['gives'][] = ['Settings in the launcher', $labels ? 'yes' : 'no', $labels ? implode(', ', array_slice($labels, 0, 4)) . (count($labels) > 4 ? ' and ' . (count($labels) - 4) . ' more' : '') : ($why('settings file') ?: 'none found')];
    $first = function ($v) { foreach ((array)$v as $x) { $p = is_array($x) ? (string)($x['path'] ?? '') : (string)$x; if ($p !== '') return $p; } return ''; };
    $sv = $first($j['saves'] ?? []) ?: $first($j['folders']['saves'] ?? []);
    $out['gives'][] = ['Save backups', $sv !== '' ? 'yes' : 'no', $sv !== '' ? $sv : ($why('saves') ?: 'where saves go wasn\'t found')];
    $lg = $first($j['folders']['logs'] ?? []);
    $out['gives'][] = ['Logs with crash reports', $lg !== '' ? 'yes' : 'no', $lg !== '' ? $lg : ($why('logs') ?: 'no log file found')];
    // Does the file reach players? Only from the release.
    $pack = (array)($code['packaging'] ?? []);
    if ($edits && !empty($pack['done'])) $out['gives'][] = ['The file ships in every release', 'yes', (string)($pack['how'] ?? 'a copy line this PR adds')];
    else { $out['gives'][] = ['The file ships in every release', 'no', 'the developer has to copy it into their release zip (the PR says where)']; }
    $out['gives'][] = ['"Get it on RecompLauncher" badge', 'yes', 'added to the README'];
    if (!$new) $out['warns'][] = 'Made by the old drafter. Make a new draft first: the new one finds setup steps and the release copy line.';
    if ($edits) $out['warns'][] = 'It changes their code (' . count((array)$code['edits']) . ' edit' . (count((array)$code['edits']) === 1 ? '' : 's') . '). Nobody has built it, so read the changes; the PR asks them to build before merging.';
    if (trim((string)($q['note'] ?? '')) !== '') $out['warns'][] = 'Read the drafter\'s notes: they say what it wasn\'t sure of.';
    $out['level'] = $out['blockers'] ? 'bad' : (array_filter($out['gives'], fn($g) => $g[0] === 'Easy Setup from the player\'s copy' && $g[1] === 'no') || !$new ? 'warn' : 'ok');
    return $out;
}

// ---- (4.11) Test runs (Launcher support > Check a game) ------------------------------------------------------
// The drafter run on chosen recomps with a chosen model, its drafts written to files instead of the queue, so
// models can be compared and a draft looked at before anything is saved or sent. Each run's cost still goes on
// Settings > AI usage. A test draft can then be put in the PR queue, or used as our own profile.
function rs_prq_dry_dir(): string { return dirname(rs_prq_script()) . '/dry'; }
function rs_prq_dry_picks(array $appIds): array {
    $res = [];
    foreach (array_slice($appIds, 0, 10) as $id) {
        if (preg_match('/^sub-(\d+)$/', (string)$id, $m)) { if ($p = rs_prq_sub_pick((int)$m[1])) $res[] = $p; continue; }
        if ($a = rs_pc_app((string)$id)) if ((string)$a['repo'] !== '' && rs_pc_host($a) === '') $res[] = rs_prq_pick_row($a, 'asked');
    }
    return $res;
}
// (4.12) A PC submission that isn't on RecompLauncher yet, as a test-run pick (id sub-<inbox id>), so its
// recomplauncher.json can be made before it's approved. Only GitHub projects (the drafter reads GitHub).
function rs_prq_sub_pick(int $sid): ?array {
    $st = rs_db()->prepare("SELECT data FROM inbox WHERE id = ? AND kind = 'submit'");
    $st->execute([$sid]);
    $d = json_decode((string)($st->fetchColumn() ?: ''), true);
    if (!is_array($d) || ($d['platform'] ?? '') !== 'pc' || trim((string)($d['repo'] ?? '')) === '') return null;
    if (rs_host_norm((string)($d['host'] ?? '')) !== '') return null;
    $as = array_filter(array_map('strval', (array)($d['assets'] ?? [])));
    return [
        'id' => 'sub-' . $sid, 'pick' => 'asked', 'repo' => (string)$d['repo'], 'name' => (string)($d['name'] ?? basename((string)$d['repo'])),
        'game' => (string)($d['game'] ?? $d['name'] ?? ''), 'console' => (string)($d['origin'] ?? ''), 'page' => 'https://recomplauncher.com', 'unlisted' => true,
        'assets' => array_intersect_key($as, array_flip(['win', 'linux', 'mac'])), 'needs' => (string)($d['needs'] ?? ''),
        'setup_required' => !empty($d['setup_required']), 'setup_steps' => (string)($d['setup_steps'] ?? ''), 'our_profile' => null, 'downloads' => 0,
    ];
}
// (4.12) The listing a submission became (through Auto-add), or null while it's still a submission.
function rs_prq_sub_app(int $sid): ?array {
    $st = rs_db()->prepare("SELECT app_id FROM pc_auto WHERE sub_id = ? AND app_id <> '' ORDER BY id DESC LIMIT 1");
    try { $st->execute([$sid]); $id = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) { $id = ''; }
    return $id !== '' ? rs_pc_app($id) : null;
}
// Starts one test run per model (models are RS_AI_MODELS keys; 'sonnet+opus' = Sonnet reads, Opus writes the code).
function rs_prq_dry_start(array $appIds, array $models): int {
    require_once __DIR__ . '/aiuse.php';
    if (!rs_prq_now_ready()) throw new RuntimeException('Add the Anthropic API key in Setup first.');
    $ids = array_values(array_filter(array_map('strval', array_slice(array_unique($appIds), 0, 10)), fn($id) => preg_match('/^sub-(\d+)$/', $id, $m)
        ? rs_prq_sub_pick((int)$m[1]) !== null : (($a = rs_pc_app($id)) && (string)$a['repo'] !== '' && rs_pc_host($a) === '')));
    if (!$ids) throw new RuntimeException('Pick a recomp whose project is on GitHub.');
    $dir = rs_prq_dry_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Couldn\'t make the folder for test runs.');
    $n = 0;
    foreach (array_slice(array_unique($models), 0, 4) as $m) {
        [$read, $code] = array_pad(explode('+', (string)$m, 2), 2, '');
        if (!isset(RS_AI_MODELS[$read]) || ($code !== '' && !isset(RS_AI_MODELS[$code]))) continue;
        $key = 'prqrun_' . bin2hex(random_bytes(20));
        $run = array_values(array_filter(json_decode((string)(rs_kv_get('prq_run_keys')['v'] ?? '[]'), true) ?: [], fn($r) => is_array($r) && (int)($r['until'] ?? 0) > time()));
        $run[] = ['h' => hash('sha256', $key), 'until' => time() + 3600];
        rs_kv_put('prq_run_keys', (string)json_encode(array_slice($run, -20)));
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => dirname($dir), 'LANG' => 'C.UTF-8', 'RS_SITE' => rtrim((string)rs_setting('site_url', 'https://recompstore.com'), '/'), 'RS_PRQ_KEY' => $key,
            'ANTHROPIC_API_KEY' => rs_secret_get('prq_ai_key'), 'GITHUB_TOKEN' => rs_prq_token(), 'PRQ_IDS' => implode(',', $ids), 'PRQ_COUNT' => (string)count($ids),
            'PRQ_DRY' => $dir, 'PRQ_MODEL' => RS_AI_MODELS[$read], 'PRQ_MODEL_CODE' => RS_AI_MODELS[$code !== '' ? $code : $read], 'PRQ_WORKERS' => '3'];
        $p = proc_open('setsid python3 -I ' . escapeshellarg(rs_prq_script()) . ' >> ' . escapeshellarg($dir . '/run.log') . ' 2>&1 &',
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname($dir), $env);
        if (is_resource($p)) { proc_close($p); $n++; }
    }
    if (!$n) throw new RuntimeException('The test run didn\'t start on the server.');
    rs_log('sdk', 'prq', 'Test run: ' . implode(', ', $ids) . ' with ' . implode(', ', $models));
    return $n;
}
// The test drafts on file, newest first (one recomp's, or all), each with what it cost.
function rs_prq_dry_list(string $appId = '', int $limit = 60): array {
    require_once __DIR__ . '/aiuse.php';
    $out = [];
    foreach (glob(rs_prq_dry_dir() . '/*.json') ?: [] as $f) {
        $base = basename($f, '.json');
        $parts = explode('__', $base);
        if (count($parts) !== 3 || ($appId !== '' && $parts[0] !== preg_replace('/[^A-Za-z0-9_.-]+/', '-', $appId))) continue;
        $out[] = ['file' => $base, 'mtime' => (int)filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    $res = [];
    foreach (array_slice($out, 0, $limit) as $x) if ($r = rs_prq_dry_get($x['file'])) $res[] = $r;
    return $res;
}
function rs_prq_dry_get(string $name): ?array {
    if (!preg_match('/^[A-Za-z0-9_.+-]+$/', $name)) return null;
    $f = rs_prq_dry_dir() . '/' . $name . '.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($j)) return null;
    require_once __DIR__ . '/aiuse.php';
    $usage = $j['draft']['usage'] ?? [];
    $usage = is_array($usage) ? (array_is_list($usage) ? $usage : [$usage]) : [];
    $cost = 0.0; $calls = 0;
    foreach ($usage as $u) if (is_array($u)) { $cost += rs_ai_cost((string)($u['model'] ?? ''), (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), (int)($u['cache_read_input_tokens'] ?? 0), (int)($u['cache_creation_input_tokens'] ?? 0)); $calls += (int)($u['calls'] ?? 0); }
    // A run that stopped without a word (the server restarted it, say) isn't "running" forever.
    if (($j['status'] ?? '') === 'running' && time() - (int)filemtime($f) > 1800) $j['status'] = 'stopped';
    return $j + ['name' => $name, 'cost' => $cost, 'calls' => $calls, 'secs' => isset($j['started'], $j['finished']) ? (int)round((float)$j['finished'] - (float)$j['started']) : null];
}
// A test draft into the PR queue, where it's looked over and sent like any other.
function rs_prq_dry_to_queue(string $name): string {
    $r = rs_prq_dry_get($name);
    if (!$r || ($r['status'] ?? '') !== 'done' || !is_array($r['draft'] ?? null)) return 'That test draft isn\'t finished.';
    $d = $r['draft'];
    unset($d['usage']); // (counted when the test ran)
    $d['pick'] = 'asked';
    // (4.12) Made while it was a submission: it goes in under the listing it became.
    if (preg_match('/^sub-(\d+)$/', (string)($d['id'] ?? ''), $m)) {
        $sa = rs_prq_sub_app((int)$m[1]);
        if (!$sa) return 'It isn\'t on RecompLauncher yet. Approve it first, then put this in the PR queue.';
        $d['id'] = (string)$sa['id'];
    }
    $s = rs_prq_draft_save($d);
    if (!$s['ok']) return (string)($s['error'] ?? 'Couldn\'t save it.');
    if (($s['kept'] ?? '') !== '') return 'It already has a pull request (' . $s['kept'] . '), so the queue kept that one.';
    rs_log('sdk', (string)$d['id'], 'Test draft ' . $name . ' put in the PR queue');
    return '';
}
function rs_prq_dry_delete(string $name): void {
    if (preg_match('/^[A-Za-z0-9_.+-]+$/', $name)) @unlink(rs_prq_dry_dir() . '/' . $name . '.json');
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
    if (!$skip && rs_prq_auto_line((string)$a['repo'])) return ['ok' => true, 'status' => 'outbox'];
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
            $files[$path] = ['sha' => (string)$r['json']['sha'], 'old' => (string)base64_decode((string)($r['json']['content'] ?? '')), 'new' => null, 'why' => ''];
            $files[$path]['new'] = $files[$path]['old'];
        }
        // (4.11) The drafter's own commit line for this file (a packaging edit isn't a launch option).
        if ($files[$path]['why'] === '') $files[$path]['why'] = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($e['why'] ?? ''))), 0, 72);
        $find = (string)($e['find'] ?? ''); $rep = (string)($e['replace'] ?? '');
        $n = substr_count($files[$path]['new'], $find);
        if ($n === 1) $files[$path]['new'] = str_replace($find, $rep, $files[$path]['new']);
        elseif (!($n === 0 && $rep !== '' && str_contains($files[$path]['new'], $rep)))
            throw new RuntimeException($path . ' changed since the draft, so its code change doesn\'t fit any more. Draft it again.');
    }
    foreach ($files as $path => $f) {
        if ($f['new'] === $f['old']) continue;
        rs_prq_gh_ok(rs_prq_gh('PUT', '/repos/' . $forkName . '/contents/' . str_replace('%2F', '/', rawurlencode($path)), [
            'message' => $f['why'] !== '' ? $f['why'] : 'Add launch options so launchers can pass the game\'s location (' . basename($path) . ')', 'content' => base64_encode($f['new']), 'branch' => $branch, 'sha' => $f['sha'],
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
// (4.12) Xarishark: a draft the review marks green (nothing missing, nothing blocking, made by the new
// drafter) goes in line to be sent by itself, so nobody has to press Send on the easy ones. Drafts that
// change the developer's code still wait for a person (nobody has built them), and so does a redraft of
// one already sent. Owner switch on the PR queue's Settings (prq_auto_green, on unless turned off).
function rs_prq_auto_on(): bool { return (string)rs_setting('prq_auto_green', '1') === '1'; }
function rs_prq_auto_green(array $q): bool {
    if ($q['status'] !== 'draft' || (string)$q['pr_url'] !== '' || (string)$q['pick'] === 'redo') return false;
    $code = (string)($q['code'] ?? '') !== '' ? (json_decode((string)$q['code'], true) ?: []) : [];
    if (!empty($code['edits'])) return false;
    $rv = rs_prq_review($q);
    return $rv['level'] === 'ok' && !$rv['blockers'];
}
function rs_prq_auto_line(string $repo): bool {
    if (!rs_prq_auto_on()) return false;
    $st = rs_db()->prepare('SELECT * FROM pc_prq WHERE repo = ?');
    $st->execute([$repo]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q || !rs_prq_auto_green($q)) return false;
    $u = rs_db()->prepare("UPDATE pc_prq SET status = 'outbox', opts = ?, who = 'Drafter (green)', updated = NOW() WHERE id = ? AND status = 'draft'");
    $u->execute([json_encode(['here' => false, 'code' => false]), (int)$q['id']]);
    if ($u->rowCount()) rs_log('sdk', (string)$q['app_id'], 'green draft put in line to be sent by itself');
    return $u->rowCount() > 0;
}
// Green drafts already waiting (made before the switch, or while it was off) join the line too.
function rs_prq_auto_sweep(): int {
    if (!rs_prq_auto_on()) return 0;
    $n = 0;
    foreach (rs_db()->query("SELECT repo FROM pc_prq WHERE status = 'draft' AND pr_url = '' AND pick <> 'redo'")->fetchAll() as $r) if (rs_prq_auto_line((string)$r['repo'])) ++$n;
    return $n;
}

// ---- (4.12) Developer replied --------------------------------------------------------------------
// Xarishark: when a developer answers on a pull request (or the issue asking for support), it leaves Sent
// for "Developer replied" until one of us answers on GitHub or presses Mark answered. Who's "us":
// prq_team_logins and our PR account; bots never count. (4.13) Read from the threads below.
function rs_prq_team_logins(): array {
    $l = array_map('trim', explode(',', strtolower((string)rs_setting('prq_team_logins', 'elripalda,xarishark'))));
    $me = strtolower((string)rs_setting('prq_gh_login', ''));
    if ($me !== '') $l[] = $me;
    return array_values(array_unique(array_filter($l)));
}
// [app id => [repo, url]]: pull requests we sent from the queue, and the ones marked by hand.
function rs_prq_reply_targets(): array {
    $out = [];
    foreach (rs_db()->query("SELECT app_id, pr_url FROM pc_prq WHERE status = 'sent' AND pr_url <> '' AND sent_at > NOW() - INTERVAL 180 DAY")->fetchAll() as $r) $out[(string)$r['app_id']] = (string)$r['pr_url'];
    foreach (rs_sdk_marks() as $id => $m) if (in_array($m['kind'] ?? '', ['pr', 'issue'], true) && !isset($out[(string)$id])) $out[(string)$id] = (string)($m['url'] ?? '');
    return array_filter($out, fn($u) => (bool)preg_match('#github\.com/[\w.-]+/[\w.-]+/(pull|issues)/\d+#', $u));
}
function rs_prq_gh_read(string $path): ?array {
    $r = rs_prq_token() !== '' ? rs_prq_gh('GET', $path) : null;
    if ($r && $r['status'] === 200) return is_array($r['json']) ? $r['json'] : null;
    if ($r) return null;
    $h = rs_http(rs_gh_api() . $path, ['github' => true, 'timeout' => 10, 'max' => 4000000]);
    return $h['status'] === 200 ? (json_decode((string)$h['body'], true) ?: null) : null;
}
// (4.13) Replies: every pull request and issue we sent (and the ones marked by hand) has a thread
// (pc_prq_threads) and its messages (pc_prq_msgs: comments, reviews, code comments, and who closed or
// merged it), ours and theirs, so Launcher support > Replies shows each conversation and what's new.
// Open ones are read every hour; closed or merged ones every 6 hours for two weeks, daily up to 90 days,
// then left alone. "New" = something of theirs (a reply, a merge, a close) after our last comment and
// after someone pressed Got it (read_at). The PR queue's "Developer replied" stays open ones only.
function rs_prq_reply_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec("CREATE TABLE IF NOT EXISTS pc_prq_threads (
        app_id VARCHAR(100) NOT NULL PRIMARY KEY,
        url VARCHAR(300) NOT NULL DEFAULT '',
        repo VARCHAR(200) NOT NULL DEFAULT '',
        kind VARCHAR(8) NOT NULL DEFAULT 'pull',
        num INT NOT NULL DEFAULT 0,
        title VARCHAR(300) NOT NULL DEFAULT '',
        state VARCHAR(8) NOT NULL DEFAULT 'open',
        closed_at INT NOT NULL DEFAULT 0,
        theirs_at INT NOT NULL DEFAULT 0,
        news_at INT NOT NULL DEFAULT 0,
        ours_at INT NOT NULL DEFAULT 0,
        read_at INT NOT NULL DEFAULT 0,
        n_theirs INT NOT NULL DEFAULT 0,
        checked DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4");
    rs_db()->exec("CREATE TABLE IF NOT EXISTS pc_prq_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app_id VARCHAR(100) NOT NULL,
        ext VARCHAR(60) NOT NULL,
        who VARCHAR(80) NOT NULL DEFAULT '',
        mine TINYINT NOT NULL DEFAULT 0,
        kind VARCHAR(10) NOT NULL DEFAULT 'comment',
        at INT NOT NULL DEFAULT 0,
        body TEXT NULL,
        url VARCHAR(300) NOT NULL DEFAULT '',
        UNIQUE KEY ext (app_id, ext),
        KEY app (app_id, at)
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}
// A thread is due: open every hour, closed ones less and less often. $force: open ones and ones closed
// in the last two weeks now.
function rs_prq_reply_due(?array $t, bool $force): bool {
    if (!$t || $t['checked'] === null) return true;
    $age = time() - (int)strtotime((string)$t['checked'] . ' UTC');
    if ($t['state'] === 'open') return $force || $age >= 3300;
    $since = time() - (int)$t['closed_at'];
    if ($since < 14 * 86400) return $force || $age >= 6 * 3600;
    return $since < 90 * 86400 && $age >= 86400;
}
// Reads one thread from GitHub and saves it. -> true when GitHub answered.
function rs_prq_reply_read(string $id, string $url, array $team, ?array $old): bool {
    if (!preg_match('#github\.com/([\w.-]+/[\w.-]+)/(pull|issues)/(\d+)#', $url, $m)) return false;
    [$repo, $kind, $num] = [$m[1], $m[2], (int)$m[3]];
    $item = rs_prq_gh_read('/repos/' . $repo . '/issues/' . $num);
    if (!$item) return false;
    $state = !empty($item['pull_request']['merged_at']) ? 'merged' : ((string)($item['state'] ?? 'open') === 'closed' ? 'closed' : 'open');
    $closedAt = $state === 'open' ? 0 : (int)(strtotime((string)($item['pull_request']['merged_at'] ?? '') ?: (string)($item['closed_at'] ?? '')) ?: 0);
    $msgs = [];
    $add = function (array $x, string $ext, string $k, string $at, string $text, string $link) use (&$msgs, $team) {
        $u = (array)($x['user'] ?? []);
        $login = (string)($u['login'] ?? '');
        if ($login === '' || ($u['type'] ?? '') === 'Bot' || str_ends_with($login, '[bot]')) return;
        $text = trim((string)preg_replace('/<!--.*?-->/s', '', $text));
        $msgs[] = ['ext' => $ext, 'who' => $login, 'mine' => in_array(strtolower($login), $team, true) ? 1 : 0, 'kind' => $k, 'at' => (int)(strtotime($at) ?: 0), 'body' => mb_substr($text, 0, 4000), 'url' => $link];
    };
    // Its opening post: ours when we sent it; a developer's issue we marked counts as theirs.
    $add((array)$item, 'o:' . $num, 'opened', (string)($item['created_at'] ?? ''), '', (string)($item['html_url'] ?? $url));
    foreach ((array)rs_prq_gh_read('/repos/' . $repo . '/issues/' . $num . '/comments?per_page=100') as $x) if (is_array($x)) $add($x, 'c:' . ($x['id'] ?? ''), 'comment', (string)($x['created_at'] ?? ''), (string)($x['body'] ?? ''), (string)($x['html_url'] ?? $url));
    if ($kind === 'pull') {
        foreach ((array)rs_prq_gh_read('/repos/' . $repo . '/pulls/' . $num . '/reviews?per_page=100') as $x) {
            if (!is_array($x)) continue;
            $b = trim((string)($x['body'] ?? ''));
            $rs = (string)($x['state'] ?? '');
            if ($b === '' && $rs === 'COMMENTED') continue;
            $add($x, 'r:' . ($x['id'] ?? ''), 'review', (string)($x['submitted_at'] ?? ''), $b !== '' ? $b : ($rs === 'APPROVED' ? 'Approved the pull request.' : ($rs === 'CHANGES_REQUESTED' ? 'Asked for changes.' : '')), (string)($x['html_url'] ?? $url));
        }
        foreach ((array)rs_prq_gh_read('/repos/' . $repo . '/pulls/' . $num . '/comments?per_page=100') as $x) if (is_array($x)) $add($x, 'k:' . ($x['id'] ?? ''), 'code', (string)($x['created_at'] ?? ''), (string)($x['body'] ?? ''), (string)($x['html_url'] ?? $url));
    }
    if ($state !== 'open' && $closedAt) {
        $by = (array)($item['closed_by'] ?? []);
        if ($by) $add(['user' => $by], 'x:' . $closedAt, $state, gmdate('c', $closedAt), '', (string)($item['html_url'] ?? $url));
    }
    $db = rs_db();
    $ins = $db->prepare('INSERT INTO pc_prq_msgs (app_id, ext, who, mine, kind, at, body, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE body = VALUES(body), mine = VALUES(mine)');
    $theirs = 0; $news = 0; $ours = 0; $n = 0;
    foreach ($msgs as $x) {
        $ins->execute([$id, mb_substr($x['ext'], 0, 60), mb_substr($x['who'], 0, 80), $x['mine'], $x['kind'], $x['at'], $x['body'], mb_substr($x['url'], 0, 300)]);
        if ($x['mine']) { if ($x['kind'] !== 'merged' && $x['kind'] !== 'closed') $ours = max($ours, $x['at']); continue; }
        $news = max($news, $x['at']);
        if (in_array($x['kind'], ['comment', 'review', 'code'], true) || ($x['kind'] === 'opened')) { $theirs = max($theirs, $x['at']); ++$n; }
    }
    // A first look at a thread closed a while back starts read: only what happens from now shows as new.
    $read = $old ? (int)$old['read_at'] : max((int)(($k = rs_kv_get('prreplyok:' . $id)) ? $k['v'] : 0), $state !== 'open' && $closedAt < time() - 7 * 86400 ? time() : 0);
    $db->prepare('INSERT INTO pc_prq_threads (app_id, url, repo, kind, num, title, state, closed_at, theirs_at, news_at, ours_at, read_at, n_theirs, checked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE url = VALUES(url), repo = VALUES(repo), kind = VALUES(kind), num = VALUES(num), title = VALUES(title), state = VALUES(state), closed_at = VALUES(closed_at), theirs_at = VALUES(theirs_at), news_at = VALUES(news_at), ours_at = VALUES(ours_at), n_theirs = VALUES(n_theirs), checked = VALUES(checked)')
        ->execute([$id, mb_substr($url, 0, 300), $repo, $kind, $num, mb_substr((string)($item['title'] ?? ''), 0, 300), $state, $closedAt, $theirs, $news, $ours, $read, $n]);
    return true;
}
// Looks at the threads that are due, for up to $secs seconds. -> how many it read
function rs_prq_reply_scan(int $secs = 50, bool $force = false): int {
    rs_prq_reply_tables();
    $t0 = microtime(true); $n = 0;
    $team = rs_prq_team_logins();
    $have = [];
    foreach (rs_db()->query('SELECT * FROM pc_prq_threads') as $t) $have[(string)$t['app_id']] = $t;
    $targets = rs_prq_reply_targets();
    // Never read ones first, then the ones read longest ago.
    uksort($targets, fn($x, $y) => strcmp((string)($have[$x]['checked'] ?? ''), (string)($have[$y]['checked'] ?? '')));
    foreach ($targets as $id => $url) {
        if (microtime(true) - $t0 > $secs) break;
        $old = $have[(string)$id] ?? null;
        if ($old && (string)$old['url'] !== $url) $old = null;
        if (!rs_prq_reply_due($old, $force)) continue;
        try { if (rs_prq_reply_read((string)$id, $url, $team, $have[(string)$id] ?? null)) ++$n; } catch (Throwable $e) { /* next run */ }
    }
    rs_kv_put('prq_reply_scan', (string)time());
    return $n;
}
// Every thread with its newest message from them. -> [app id => thread + 'last' (their newest message) + 'new' (bool) + 'waiting' (bool)]
function rs_prq_threads(): array {
    try {
        rs_prq_reply_tables();
        $out = [];
        foreach (rs_db()->query('SELECT * FROM pc_prq_threads') as $t) {
            $t['new'] = (int)$t['news_at'] > max((int)$t['ours_at'], (int)$t['read_at']);
            $t['waiting'] = $t['state'] === 'open' && (int)$t['theirs_at'] > max((int)$t['ours_at'], (int)$t['read_at']);
            $t['last'] = null;
            $out[(string)$t['app_id']] = $t;
        }
        foreach (rs_db()->query("SELECT m.* FROM pc_prq_msgs m JOIN (SELECT app_id, MAX(at) at FROM pc_prq_msgs WHERE mine = 0 AND kind IN ('comment', 'review', 'code') GROUP BY app_id) l ON l.app_id = m.app_id AND l.at = m.at WHERE m.mine = 0") as $m)
            if (isset($out[(string)$m['app_id']])) $out[(string)$m['app_id']]['last'] = $m;
        return $out;
    } catch (Throwable $e) { return []; }
}
// One thread's messages, oldest first.
function rs_prq_thread_msgs(string $id): array {
    rs_prq_reply_tables();
    $st = rs_db()->prepare('SELECT * FROM pc_prq_msgs WHERE app_id = ? ORDER BY at, id');
    $st->execute([$id]);
    return $st->fetchAll();
}
// [app id => reply] for open ones where the developer is waiting on us (the PR queue's Developer replied
// and the Games list). Same fields as before 4.13: who, at, t, text, url, state, kind, pr.
function rs_prq_replies(): array {
    $out = [];
    foreach (rs_prq_threads() as $id => $t) {
        if (!$t['waiting'] || !$t['last']) continue;
        $m = $t['last'];
        $out[$id] = ['who' => (string)$m['who'], 'at' => gmdate('Y-m-d H:i', (int)$m['at']), 't' => (int)$m['at'],
            'text' => mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags((string)$m['body']))), 0, 280),
            'url' => (string)$m['url'], 'state' => (string)$t['state'], 'kind' => $t['kind'] === 'pull' ? 'pull' : 'issues', 'pr' => (string)$t['url']];
    }
    return $out;
}
// Mark answered / Got it: what's there now isn't new any more.
function rs_prq_reply_ok(string $appId): void {
    rs_prq_reply_tables();
    rs_db()->prepare('UPDATE pc_prq_threads SET read_at = GREATEST(news_at, theirs_at, ?) WHERE app_id = ?')->execute([time(), $appId]);
    rs_kv_put('prreplyok:' . $appId, (string)time());
}
// Check now: a background run over every open thread (and ones closed in the last two weeks).
function rs_prq_reply_launch(): bool {
    $last = rs_kv_get('prq_reply_now');
    if ($last && $last['age'] < 120) return false;
    rs_kv_put('prq_reply_now', (string)time());
    $p = proc_open('setsid php ' . escapeshellarg(__DIR__ . '/prq-replies-run.php') . ' > /dev/null 2>&1 &',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__,
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8']);
    if (!is_resource($p)) return false;
    proc_close($p);
    return true;
}

// The half-hourly job: the next ones in line, keeping to the hourly number. -> [sent, failed]
function rs_prq_send_due(): array {
    rs_prq_tables();
    $done = [0, 0];
    // (4.12) Green drafts join the line, and developers' answers on pull requests are looked for.
    try { rs_prq_auto_sweep(); } catch (Throwable $e) { rs_errlog('prq auto', $e); }
    try { rs_prq_reply_scan(50); } catch (Throwable $e) { rs_errlog('prq replies', $e); }
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
