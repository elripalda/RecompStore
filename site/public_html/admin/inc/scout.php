<?php
// (4.7) The daily scout: every day at noon (Phoenix) the server looks for new recomps (GitHub, the
// Discord suggestions, Quiver's catalog), has Claude sort the real ones (a native PC build of a console
// game, players bring their own copy) from the rest (emulators, tools, mods, fan games, no build yet),
// and sends the ones it picks through Auto-add one after another. They wait on Auto-add as drafts;
// nothing goes live until Publish. Run from cron (scout-run.php) or with "Run now" on Auto-add.
declare(strict_types=1);

const RS_SCOUT_MODEL = 'claude-sonnet-5-5';
const RS_SCOUT_BATCH = 8;      // finds per Claude call
const RS_SCOUT_LOOK = 48;      // finds looked at per run, at most
const RS_SCOUT_DRAFTS = 8;     // drafts made per run, at most (the rest wait for the next run)
const RS_SCOUT_WHO = 'Daily scout';

function rs_scout_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec("CREATE TABLE IF NOT EXISTS pc_scout (
        repo VARCHAR(300) NOT NULL PRIMARY KEY,
        version VARCHAR(100) NOT NULL DEFAULT '',
        pick TINYINT NOT NULL DEFAULT 0,
        kind VARCHAR(20) NOT NULL DEFAULT '',
        why VARCHAR(400) NOT NULL DEFAULT '',
        auto_id INT NOT NULL DEFAULT 0,
        at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function rs_scout_on(): bool { $k = rs_kv_get('scout_on'); return !$k || $k['v'] !== '0'; }
function rs_scout_state(): array { $k = rs_kv_get('scout_state'); return $k ? (json_decode((string)$k['v'], true) ?: []) + ['age' => (int)$k['age']] : []; }
function rs_scout_put_state(array $s): void { rs_kv_put('scout_state', (string)json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
function rs_scout_last(): array { $k = rs_kv_get('scout_last'); return $k ? (json_decode((string)$k['v'], true) ?: []) : []; }
// A run counts as going while it has moved in the last hour (one draft can take a while).
function rs_scout_running(): bool { $s = rs_scout_state(); return !empty($s['running']) && ($s['age'] ?? 9999) < 3600; }

// What the scout decided about each find it looked at: [repo key => row].
function rs_scout_seen(): array {
    rs_scout_tables();
    $out = [];
    foreach (rs_db()->query('SELECT * FROM pc_scout') as $r) $out[strtolower((string)$r['repo'])] = $r;
    return $out;
}

// Starts a run in the background (Run now). -> false when one is already going.
function rs_scout_launch(): bool {
    if (rs_scout_running()) return false;
    rs_scout_put_state(['running' => true, 'phase' => 'Starting', 'at' => time()]);
    $p = proc_open('setsid php ' . escapeshellarg(__DIR__ . '/scout-run.php') . ' now >> ' . escapeshellarg(rs_auto_dir() . '/scout.log') . ' 2>&1 &',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, rs_auto_dir(),
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => rs_auto_dir(), 'LANG' => 'C.UTF-8']);
    if (!is_resource($p)) { rs_scout_put_state([]); return false; }
    proc_close($p);
    return true;
}

const RS_SCOUT_SYSTEM = 'You sort GitHub, GitLab and SourceForge projects for RecompLauncher, a launcher that lists PC recomps, decomps and ports: native PC builds of console games (Nintendo, PlayStation, Xbox, Sega and so on) where players bring their own copy of the game. For each project you get its facts, the files in its newest release and the start of its README.

Pick it (pick: true) only when all of these hold:
- It is a native PC build of a console game: a static recompilation, a decompilation, or a source port. Not an emulator, an emulator front end or wrapper, a launcher, a tool, a library, an SDK, a mod manager or a randomizer.
- The release has a build for Windows, Linux or macOS that players can download and run now.
- It does not ship the game itself (ROMs, disc images, the game\'s own data); a project that has the player provide their own copy is fine.
- It is the game itself, not a mod, texture pack, patch, translation or add-on for another recomp, and not a PC-original fan game or demake.
Another recomp of a game that is already listed is still a pick (it goes on that game\'s Versions page). A beta or early build is fine when it is playable.

kind is one of: recomp, decomp, port, emulator, tool, mod, fan-game, no-build, game-data, not-a-game, other.
why is one short plain sentence a busy admin reads at a glance, like "Static recomp of Sonic Unleashed with a Windows build" or "An Xbox 360 emulator, not a recomp".
game is the console game\'s name when there is one, else "".

Answer with one JSON array and nothing else, one object per project, in the order given:
[{"key": "", "pick": false, "kind": "", "game": "", "why": ""}]';

// Claude's call on a few finds at once. -> [key => [pick, kind, game, why]]
function rs_scout_judge(array $finds): array {
    $items = [];
    foreach ($finds as $f) {
        $md = '';
        try { $md = (string)(rs_pc_readme((string)$f['repo'], (string)$f['host'], 86400)['md'] ?? ''); } catch (Throwable $e) { /* no README */ }
        $items[] = [
            'key' => $f['key'], 'repo' => $f['repo'], 'name' => $f['name'] ?? '', 'description' => $f['description'] ?? '', 'topics' => $f['topics'] ?? [],
            'stars' => $f['stars'] ?? 0, 'fork' => !empty($f['fork']), 'archived' => !empty($f['archived']),
            'version' => $f['version'] ?? '', 'released' => $f['released'] ?? '',
            'downloads_picked' => $f['assets'] ?? [], 'flags' => $f['flags'] ?? [],
            'notes' => array_map(fn($n) => $n[1], rs_auto_find_notes($f)),
            'found_via' => $f['via'] ?? 'github',
            'readme_start' => mb_substr(preg_replace('/<!--.*?-->|!\[[^\]]*\]\([^)]*\)/s', '', $md) ?? $md, 0, 5000),
        ];
    }
    $body = ['model' => RS_SCOUT_MODEL, 'max_tokens' => 4000,
        'system' => [['type' => 'text', 'text' => RS_SCOUT_SYSTEM, 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => [['role' => 'user', 'content' => "Sort these projects.\n\n" . json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]];
    $out = [];
    foreach ((array)rs_ai_json($body) as $i => $d) {
        if (!is_array($d)) continue;
        $key = (string)($d['key'] ?? ($items[$i]['key'] ?? ''));
        if ($key === '') continue;
        $out[strtolower($key)] = [!empty($d['pick']), mb_substr((string)($d['kind'] ?? ''), 0, 20), mb_substr((string)($d['game'] ?? ''), 0, 120), mb_substr(trim((string)($d['why'] ?? '')), 0, 400)];
    }
    return $out;
}

// The whole run. $fresh: look for new finds first (the noon run; Run now does it too).
function rs_scout_run(bool $fresh = true): array {
    rs_scout_tables();
    rs_auto_tables();
    $sum = ['at' => time(), 'found' => 0, 'looked' => 0, 'picked' => 0, 'drafted' => [], 'passed' => [], 'waiting' => 0, 'error' => ''];
    $state = fn(string $phase, int $done = 0, int $total = 0) => rs_scout_put_state(['running' => true, 'phase' => $phase, 'done' => $done, 'total' => $total, 'at' => $sum['at']]);
    try {
        if (rs_secret_get('prq_ai_key') === '') throw new RuntimeException('The Anthropic API key isn\'t set (SDK support, PR queue setup).');
        // 1. New finds.
        if ($fresh) {
            $state('Looking on GitHub');
            try { $sum['found'] += rs_pc_find(150)[0]; } catch (Throwable $e) { /* the others still run */ }
            $state('Reading the Discord suggestions');
            try { $sum['found'] += rs_pc_find_discord(40)[0]; } catch (Throwable $e) {}
            $state('Looking at Quiver\'s catalog');
            try { $sum['found'] += rs_pc_find_quiver(60, 10)[0]; } catch (Throwable $e) {}
        }
        // 2. Claude sorts what it hasn't looked at (or has a new release since).
        $seen = rs_scout_seen();
        $drafted = [];
        foreach (rs_db()->query("SELECT repo_key, status FROM pc_auto") as $r) $drafted[strtolower((string)$r['repo_key'])] = (string)$r['status'];
        $todo = [];
        foreach (rs_pc_found('new') as $f) {
            $k = strtolower((string)$f['key']);
            if (isset($drafted[$k])) continue; // already a draft (or removed by hand: left alone)
            if (isset($seen[$k]) && (string)$seen[$k]['version'] === (string)($f['version'] ?? '')) continue;
            $todo[] = $f;
        }
        usort($todo, fn($a, $b) => strcmp((string)$b['found_at'], (string)$a['found_at']));
        $todo = array_slice($todo, 0, RS_SCOUT_LOOK);
        // Statements are prepared each time: a Claude call or a draft can outlast the database's
        // wait_timeout, and rs_db() then opens a new connection that an old statement doesn't use.
        $putSql = 'INSERT INTO pc_scout (repo, version, pick, kind, why, at) VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE version = VALUES(version), pick = VALUES(pick), kind = VALUES(kind), why = VALUES(why), at = NOW(), auto_id = IF(VALUES(pick) = 1, auto_id, 0)';
        foreach (array_chunk($todo, RS_SCOUT_BATCH) as $chunk) {
            $state('Claude is sorting the finds', $sum['looked'], count($todo));
            $j = rs_scout_judge($chunk);
            foreach ($chunk as $f) {
                $k = strtolower((string)$f['key']);
                if (!isset($j[$k])) continue;
                [$pick, $kind, $game, $why] = $j[$k];
                rs_db()->prepare($putSql)->execute([$f['key'], (string)($f['version'] ?? ''), $pick ? 1 : 0, $kind, $why]);
                ++$sum['looked'];
                if ($pick) ++$sum['picked'];
                else $sum['passed'][] = ['key' => $f['key'], 'kind' => $kind, 'why' => $why];
            }
        }
        // 3. Drafts for the picks, one after another (each downloads and scans its build). A draft
        // still waiting to start (a run that stopped) is picked up again.
        $drafted = [];
        foreach (rs_db()->query("SELECT repo_key, status FROM pc_auto") as $r) $drafted[strtolower((string)$r['repo_key'])] = (string)$r['status'];
        $picks = [];
        foreach (rs_db()->query('SELECT repo FROM pc_scout WHERE pick = 1 AND auto_id = 0 ORDER BY at DESC') as $r) {
            $k = strtolower((string)$r['repo']);
            if (!isset($drafted[$k])) $picks[] = (string)$r['repo'];
            elseif ($drafted[$k] === 'queued') array_unshift($picks, (string)$r['repo']); // a stopped one first
        }
        $found = [];
        foreach (rs_pc_found('new') as $f) $found[strtolower((string)$f['key'])] = $f;
        $mark = fn(int $id, string $key) => rs_db()->prepare('UPDATE pc_scout SET auto_id = ? WHERE repo = ?')->execute([$id, $key]);
        $sum['waiting'] = max(0, count($picks) - RS_SCOUT_DRAFTS);
        $picks = array_slice($picks, 0, RS_SCOUT_DRAFTS);
        foreach ($picks as $i => $key) {
            $f = $found[strtolower($key)] ?? null;
            if (!$f) { $mark(-1, $key); continue; } // no longer a new find (added or skipped by hand)
            $state('Making drafts', $i, count($picks));
            try {
                $id = rs_auto_start(rs_host_web((string)$f['host'], (string)$f['repo']), RS_SCOUT_WHO, false);
                $mark($id, $key);
                if ((rs_auto_get($id)['status'] ?? '') === 'queued') rs_auto_run($id); // not one already done
                $sum['drafted'][] = $id;
            } catch (Throwable $e) { error_log('scout draft ' . $key . ': ' . $e->getMessage()); }
        }
    } catch (Throwable $e) {
        $sum['error'] = $e->getMessage();
    }
    $sum['done'] = time();
    $sum['passed'] = array_slice($sum['passed'], 0, 80);
    rs_kv_put('scout_last', (string)json_encode($sum, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rs_scout_put_state([]);
    return $sum;
}

// What the scout passed on, newest first, for the Auto-add page: [[repo, kind, why, at]].
function rs_scout_passed(int $limit = 60): array {
    rs_scout_tables();
    $drafted = [];
    foreach (rs_db()->query("SELECT repo_key FROM pc_auto") as $r) $drafted[strtolower((string)$r['repo_key'])] = true;
    $new = [];
    foreach (rs_pc_found('new') as $f) $new[strtolower((string)$f['key'])] = $f;
    $out = [];
    foreach (rs_db()->query('SELECT * FROM pc_scout WHERE pick = 0 ORDER BY at DESC LIMIT ' . (int)($limit * 2)) as $r) {
        $k = strtolower((string)$r['repo']);
        if (isset($drafted[$k]) || !isset($new[$k])) continue; // drafted anyway, or handled on Found on GitHub
        $out[] = $r + ['host' => $new[$k]['host'], 'path' => $new[$k]['repo']];
        if (count($out) >= $limit) break;
    }
    return $out;
}
