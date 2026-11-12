<?php
// (4.8) The Submissions hub: everything that comes in to be listed, on one board per store, from the
// first look to live. PC: New (submissions) -> Checking (Auto-add's run) -> Ready to publish -> live,
// and Waiting on the developer for the ones sent back. PS5: New -> Draft made (hidden, the owner signs
// and publishes the store) -> live. Players' requests, Found on GitHub, developers' changes, messages,
// Security, flags for the owner and the How to approve guide sit beside it. Anything handled leaves
// the board at once. The board is drawn from the database only; each submission's checks (its release,
// VirusTotal, README) are worked out after the page is up and kept, so the page opens straight away.
declare(strict_types=1);

require_once __DIR__ . '/pc.php';
require_once __DIR__ . '/autoadd.php';
require_once __DIR__ . '/scout.php';
require_once __DIR__ . '/requests.php';

const RS_HUB_COLS = [
    'pc' => ['new' => ['New', 'Look it over, then Approve and check'], 'check' => ['Checking', 'Builds, virus scans, art and its page'],
        'ready' => ['Ready to publish', 'One more look, then Publish'], 'back' => ['Waiting on the developer', 'Sent back with what to change']],
    'ps5' => ['new' => ['New', 'Look it over, then Approve'], 'draft' => ['Draft made', 'Hidden in PS5 games until the store is published'],
        'back' => ['Waiting on the developer', 'Sent back with what to change']],
];
const RS_HUB_STEP_NAMES = ['own' => 'It\'s theirs', 'files' => 'Builds are there', 'safe' => 'Safe to download', 'new' => 'Not listed yet', 'page' => 'Its page', 'easy' => 'Easy Setup and badge', 'tid' => 'Title ID'];
const RS_HUB_CHECK_AGE = 1800;  // a submission's checks are worked out again after 30 minutes
const RS_HUB_WAIT_DAYS = 45;    // sent back and not heard from in 45 days: off the board (still under Handled)

function rs_hub_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (rs_setting('hub_schema', '') === '1') return;
    $db = rs_db();
    $db->exec("CREATE TABLE IF NOT EXISTS hub_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item VARCHAR(220) NOT NULL,
        uid INT NOT NULL DEFAULT 0,
        who VARCHAR(80) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        created DATETIME NOT NULL,
        KEY item (item)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS hub_flags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item VARCHAR(220) NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT '',
        store VARCHAR(4) NOT NULL DEFAULT '',
        uid INT NOT NULL DEFAULT 0,
        who VARCHAR(80) NOT NULL DEFAULT '',
        note TEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        answer TEXT NULL,
        answered_by VARCHAR(80) NOT NULL DEFAULT '',
        answered DATETIME NULL,
        seen TINYINT NOT NULL DEFAULT 1,
        created DATETIME NOT NULL,
        KEY status (status),
        KEY item (item)
    ) DEFAULT CHARSET=utf8mb4");
    rs_set_setting('hub_schema', '1');
}

// ---- Small helpers --------------------------------------------------------------------------------

function rs_hub_ts(?string $dt): int { return $dt ? (int)strtotime($dt . ' UTC') : 0; }
function rs_hub_ago(int $ts): string {
    $s = max(0, time() - $ts);
    if ($s < 60) return 'now';
    if ($s < 3600) return intdiv($s, 60) . ' min';
    if ($s < 86400) return intdiv($s, 3600) . ' h';
    if ($s < 86400 * 60) return intdiv($s, 86400) . ' d';
    return rs_date('M j', $ts);
}
function rs_hub_ago_long(int $ts): string {
    $s = max(0, time() - $ts);
    if ($s < 90) return 'just now';
    if ($s < 3600) return intdiv($s, 60) . ' minutes ago';
    if ($s < 7200) return 'an hour ago';
    if ($s < 86400) return intdiv($s, 3600) . ' hours ago';
    if ($s < 172800) return 'yesterday';
    if ($s < 86400 * 45) return intdiv($s, 86400) . ' days ago';
    return 'on ' . rs_date('M j, Y', $ts);
}
// A console's short name for the cards.
function rs_hub_con(string $origin): string {
    $short = ['n64' => 'N64', 'ps1' => 'PS1', 'ps2' => 'PS2', 'ps3' => 'PS3', 'ps4' => 'PS4', 'ps5' => 'PS5', 'snes' => 'SNES', 'gba' => 'GBA', 'gbc' => 'GBC', 'gb' => 'Game Boy',
        'ds' => 'DS', '3ds' => '3DS', 'switch' => 'Switch', 'switch2' => 'Switch 2', 'dc' => 'Dreamcast', 'vb' => 'Virtual Boy'];
    return $short[$origin] ?? (RS_PC_ORIGINS[$origin] ?? '');
}
function rs_hub_store_ok(string $store): bool { return in_array($store, rs_me()['stores_list'] ?? [], true); }
function rs_hub_can_manage(): bool { return rs_role_at_least('manager'); } // answers flags, deletes for good
// A submission row's store.
function rs_hub_sub_store(array $d): string { return ($d['platform'] ?? '') === 'pc' ? 'pc' : 'ps5'; }
function rs_hub_cat(string $c): string { return ['games' => 'Game port', 'emulators' => 'Emulator', 'apps' => 'App', 'tools' => 'Tool'][$c] ?? 'App'; }
function rs_hub_job_host(string $key): string { return str_starts_with($key, 'gitlab.com/') ? 'gitlab' : (str_starts_with($key, 'sourceforge.net/') ? 'sourceforge' : ''); }
function rs_hub_job_path(string $key): string { return (string)preg_replace('#^(gitlab\.com|sourceforge\.net)/#', '', $key); }

// One submission: [row, data] or null.
function rs_hub_sub(int $id): ?array {
    $st = rs_db()->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'submit'");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $d = json_decode((string)($r['data'] ?? ''), true);
    return is_array($d) ? [$r, $d] : null;
}
function rs_hub_sub_save(int $id, array $d, ?string $status = null): void {
    $j = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($status === null) rs_db()->prepare('UPDATE inbox SET data = ? WHERE id = ?')->execute([$j, $id]);
    else rs_db()->prepare('UPDATE inbox SET data = ?, status = ? WHERE id = ?')->execute([$j, $status, $id]);
}
function rs_hub_pics(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $out = [];
    foreach (rs_db()->query('SELECT id, inbox_id, role, w, h FROM inbox_pictures WHERE inbox_id IN (' . implode(',', $ids) . ') ORDER BY id') as $p) $out[(int)$p['inbox_id']][] = $p;
    return $out;
}

// ---- A submission's checks ------------------------------------------------------------------------
// -> ['steps' => [key => [state ok|warn|bad|run|skip, note]], 'note' => [state, short text], 'latest' => [tag, when, names],
//     'rel' => status, 'at' => time]. Kept in kv_cache as hubchk:<id> for the board.

function rs_hub_check(int $id, array $d, bool $fresh = false): array {
    $pc = ($d['platform'] ?? '') === 'pc';
    $host = rs_host_norm((string)($d['host'] ?? ''));
    $repo = (string)($d['repo'] ?? '');
    $hn = rs_host_name($host);
    $steps = [];
    // 1. It's theirs.
    $by = ltrim((string)($d['account_name'] ?? ''), '@');
    $ver = !empty($d['verified']);
    if ($host === 'gitlab') $steps['own'] = ['warn', 'A GitLab project: check it\'s theirs (their name on the project, or a link to it from their site)'];
    elseif ($host === 'sourceforge') $steps['own'] = ['warn', 'A SourceForge project: check it\'s theirs'];
    else {
        $ghOwner = strtolower((string)strtok($repo, '/'));
        if (empty($d['account']) || !str_starts_with((string)($d['account_name'] ?? ''), '@')) $steps['own'] = ['bad', 'Not sent from a GitHub account, so we can\'t tell it\'s theirs. Ask them to send it from their developer account', 'Not sent from a GitHub account'];
        elseif (strtolower($by) === $ghOwner) $steps['own'] = ['ok', 'Sent from the owner\'s GitHub (@' . $by . ')' . ($ver ? ', a verified developer' : '')];
        else $steps['own'] = ['warn', 'Sent by @' . $by . ', but the repo is under ' . $ghOwner . '. One of their organizations? Check they\'re part of it', 'Repo is under another account'];
    }
    // 2. Builds are there.
    $rel = $repo !== '' && rs_host_repo_ok($host, $repo) ? rs_host_releases($host, $repo, $fresh ? 900 : 7 * 86400) : ['status' => 0, 'body' => ''];
    $rels = (int)$rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    if (rs_sub_rel_stale($d, $rel, $rels)) { $rel = rs_host_releases($host, $repo, 900); $rels = (int)$rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : []; }
    $latest = is_array($rels) && $rels ? $rels[0] : null;
    $names = $latest ? array_map(fn($x) => (string)$x['name'], (array)($latest['assets'] ?? [])) : [];
    $pats = $pc ? array_filter((array)($d['assets'] ?? [])) : array_filter([(string)($d['asset'] ?? '')]);
    if ((int)$rel['status'] === 404) $steps['files'] = ['bad', $hn . ' can\'t find the ' . ($host === 'gitlab' ? 'project' : 'repo') . '. It\'s private or the link is wrong'];
    elseif (!$latest) $steps['files'] = (int)$rel['status'] === 200 ? ['bad', 'No release to download yet', 'No release yet'] : ['run', $hn . ' didn\'t answer. Checked again in a few minutes'];
    else {
        $miss = array_filter($pats, fn($p) => !array_filter($names, fn($n) => rs_pattern_matches((string)$p, $n)));
        $tag = (string)$latest['tag_name'];
        $when = !empty($latest['published_at']) ? ', released ' . rs_hub_ago_long((int)strtotime((string)$latest['published_at'])) : '';
        if (!$pats) $steps['files'] = ['warn', 'They didn\'t say which release files to use. Newest is ' . $tag . $when];
        elseif ($miss) {
            $sys = $pc ? array_map(fn($k) => ['win' => 'Windows', 'mac' => 'macOS', 'linux' => 'Linux'][$k] ?? $k, array_keys(array_intersect($pats, $miss))) : [];
            $steps['files'] = ['bad', ($sys ? implode(' and ', $sys) . ' file' . (count($sys) > 1 ? 's' : '') : 'The file they named') . ' not in the newest release (' . $tag . ')', 'Files not in the newest release'];
        } else {
            $sys = $pc ? array_map(fn($k) => ['win' => 'Windows', 'mac' => 'macOS', 'linux' => 'Linux'][$k] ?? $k, array_keys($pats)) : [];
            $steps['files'] = ['ok', ($pc ? rs_hub_list($sys) . ' ' . (count($sys) === 1 ? 'file' : 'files') : 'Its file') . ' in ' . $tag . $when];
        }
        if (!empty($latest['prerelease']) && $steps['files'][0] === 'ok') $steps['files'] = ['warn', $tag . ' is a pre-release' . $when . '. Fine if it\'s their only release', 'Pre-release only'];
    }
    // 3. Safe to download.
    if (!$latest) $steps['safe'] = ['skip', 'Nothing to scan until there\'s a release'];
    elseif (function_exists('rs_vt_on') && rs_vt_on()) {
        [$rows, $v] = rs_vt_release(rs_repo_key($host, $repo), (string)$latest['tag_name']);
        $hits = $rows ? max(array_map(fn($r) => (int)$r['malicious'] + (int)$r['suspicious'], $rows)) : 0;
        if (!$rows || $v === 'grey') $steps['safe'] = ['run', 'VirusTotal is checking ' . (string)$latest['tag_name'] . ($pc ? '. ClamAV runs too when you approve' : '')];
        elseif ($v === 'red') $steps['safe'] = ['bad', 'Flagged by VirusTotal (' . $hits . ' engines). Look at it on Security before anything else', 'Flagged by VirusTotal'];
        elseif ($v === 'yellow') $steps['safe'] = ['ok', 'VirusTotal: ' . $hits . ' minor flag' . ($hits === 1 ? '' : 's') . ' (common for unsigned programs)'];
        else $steps['safe'] = ['ok', 'VirusTotal: clean' . ($pc ? '. ClamAV runs too when you approve' : '')];
    } else $steps['safe'] = ['skip', $pc ? 'ClamAV and VirusTotal run when you approve' : 'Scanned when it\'s added'];
    // 4. Not listed yet.
    $key = strtolower(rs_repo_key($host, $repo));
    $steps['new'] = ['ok', 'Not on ' . ($pc ? 'RecompLauncher' : 'RecompStore') . ' yet'];
    foreach (rs_pc_apps() as $a) if (strtolower(rs_pc_key($a)) === $key) { $steps['new'] = ['bad', 'Already on RecompLauncher as ' . $a['name'] . (!empty($a['hidden']) ? ' (hidden)' : ''), 'Already on RecompLauncher']; break; }
    if ($steps['new'][0] === 'ok') foreach (rs_games() as $g) if (strtolower((string)$g['repo']) === $key) { $steps['new'] = ['bad', 'Already on RecompStore as ' . $g['name']]; break; }
    if ($steps['new'][0] === 'ok') {
        $k = rs_auto_norm((string)($d['name'] ?? ''));
        $listed = rs_auto_listed_games();
        if ($k !== '' && isset($listed[$k])) $steps['new'] = ['ok', 'Another version of ' . $listed[$k][0] . ' is listed. This one goes on its Versions page'];
    }
    // 5. Its page.
    $pics = rs_hub_pics([$id])[$id] ?? [];
    $roles = array_count_values(array_column($pics, 'role'));
    $miss = []; $have = [];
    $contact = trim((string)($d['contact'] ?? ''));
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) $miss[] = $contact === '' ? 'no email to reach them' : 'no email (they left: ' . mb_strimwidth($contact, 0, 40, '…') . ')';
    if (empty($roles['icon'])) $miss[] = 'no icon';
    if (empty($roles['shot'])) $miss[] = 'no screenshot'; else $have[] = $roles['shot'] . ' screenshot' . ($roles['shot'] > 1 ? 's' : '');
    if (!empty($roles['icon'])) $have[] = 'icon';
    if (!empty($roles['hero'])) $have[] = 'backdrop';
    if (mb_strlen(trim((string)($d['description'] ?? ''))) < 40) $miss[] = 'a very short description';
    if (trim((string)($d['blurb'] ?? '')) === '') $miss[] = 'no one-line summary';
    $ai = $pc && ($d['ai'] ?? '') === 'yes' ? '. Made with AI help (shown on its page)' : '';
    $badContact = !filter_var($contact, FILTER_VALIDATE_EMAIL);
    if ($miss) $steps['page'] = [$badContact ? 'bad' : 'warn', ucfirst(implode(', ', $miss)) . ($have ? '. ' . ucfirst(rs_hub_list($have)) . ' are in' : '') . $ai, $badContact ? 'No email to reach them' : ucfirst((string)preg_replace('/ \(.*$/', '', $miss[0]))];
    else $steps['page'] = [$ai ? 'warn' : 'ok', ucfirst(rs_hub_list($have)) . ' and a description are in' . $ai, $ai ? 'Made with AI help' : ''];
    // 6. PC: Easy Setup and the badge (both optional, never orange). PS5: its title ID.
    if ($pc) {
        $easy = ($d['rl_quick'] ?? '') === 'yes' && ($d['rl_settings'] ?? '') === 'yes';
        $t = $easy ? 'Quick install and launcher settings are set up' : 'No recomplauncher.json yet (optional). The Easy Setup PR can go out once it\'s live';
        $ok = $easy;
        if ((int)$rel['status'] !== 404 && $repo !== '' && in_array($host, ['', 'gitlab'], true)) {
            try {
                $rd = rs_pc_readme($repo, $host, 86400);
                $md = (string)($rd['md'] ?? '');
                $neg = (int)$rd['status'] === 404 || ((int)$rd['status'] === 200 && !rs_pc_badge_kinds($md) && !rs_pc_readme_link($md));
                // A README without our badge is read again after 30 minutes: they often add it once asked.
                if ($neg && (int)($rd['age'] ?? 0) > RS_HUB_CHECK_AGE) { $rd = rs_pc_readme($repo, $host, RS_HUB_CHECK_AGE); $md = (string)($rd['md'] ?? ''); }
                if ((int)$rd['status'] === 200) {
                    $bk = rs_pc_badge_kinds($md);
                    $t .= $bk ? '. Its README has our badge' : (rs_pc_readme_link($md) ? '. Its README links to us' : '. No badge or link to us in its README (optional)');
                    if (!$bk && !rs_pc_readme_link($md)) $ok = false;
                } elseif ((int)$rd['status'] === 404) $t .= '. No README (optional)';
            } catch (Throwable $e) { /* next time */ }
        }
        $steps['easy'] = [$ok ? 'ok' : 'skip', $t];
    } else {
        $tid = (string)($d['title_id'] ?? '');
        if (!preg_match('/^[A-Z]{4}\d{5}$/', $tid)) $steps['tid'] = ['warn', 'No title ID yet. It gets a placeholder; the owner gives it its real one'];
        elseif (rs_game($tid)) $steps['tid'] = ['bad', $tid . ' is already used by ' . rs_game($tid)['name']];
        else {
            $cl = array_filter(rs_tid_uses($tid)['uses'], fn($u) => $u['key'] !== strtolower($repo) && $u['where'] !== 'submission');
            $steps['tid'] = $cl ? ['bad', $tid . ' clashes with ' . implode(', ', array_map(fn($u) => $u['name'], $cl))] : ['ok', $tid . ' is free'];
        }
    }
    $out = ['steps' => $steps, 'note' => rs_hub_note($steps), 'rel' => (int)$rel['status'], 'at' => time(),
        'latest' => $latest ? ['tag' => (string)$latest['tag_name'], 'when' => (string)($latest['published_at'] ?? ''), 'names' => array_slice($names, 0, 12)] : null,
        'sig' => md5(json_encode($d))];
    try { rs_kv_put('hubchk:' . $id, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { /* worked out again next time */ }
    return $out;
}
function rs_hub_list(array $x): string {
    $x = array_values($x);
    if (count($x) < 2) return (string)($x[0] ?? '');
    return implode(', ', array_slice($x, 0, -1)) . ' and ' . end($x);
}
// The card's one line: the first thing that stops it, then VirusTotal still checking, then a note.
function rs_hub_note(array $steps): array {
    $short = fn(array $x) => (string)($x[2] ?? '') !== '' ? (string)$x[2] : rs_hub_short((string)$x[1]);
    foreach ($steps as $x) if ($x[0] === 'bad') return ['bad', $short($x)];
    foreach ($steps as $k => $x) if ($x[0] === 'run') return ['run', $k === 'safe' ? 'VirusTotal checking' : 'Checking'];
    foreach ($steps as $x) if ($x[0] === 'warn') return ['warn', $short($x)];
    return ['ok', 'Ready to approve'];
}
function rs_hub_short(string $t): string { $t = (string)preg_replace('/(\.\s|:\s|\s\().*$/s', '', $t); return mb_strimwidth(rtrim(trim($t), '.'), 0, 60, '…'); }
// Kept checks for many submissions at once: [id => [check, age]].
function rs_hub_checks_kept(array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    rs_cache_tables();
    $out = [];
    $in = implode(',', array_map(fn($i) => rs_db()->quote('hubchk:' . $i), $ids));
    foreach (rs_db()->query("SELECT k, v, TIMESTAMPDIFF(SECOND, at, NOW()) AS age FROM kv_cache WHERE k IN ($in)") as $r) {
        $c = json_decode((string)$r['v'], true);
        if (is_array($c)) $out[(int)substr((string)$r['k'], 7)] = [$c, (int)$r['age']];
    }
    return $out;
}

// ---- The board ------------------------------------------------------------------------------------

// Every submission still on a board (both stores): open ones, ones sent back to fix, PS5 drafts not out.
function rs_hub_sub_rows(): array {
    $out = [];
    $rows = rs_db()->query("SELECT id, status, created, data, contact FROM inbox WHERE kind = 'submit' AND (status <> 'done' OR data LIKE '%\"declined\":{%' OR data LIKE '%\"ps5_draft\"%') ORDER BY created DESC LIMIT 500")->fetchAll();
    foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true);
        if (!is_array($d)) continue;
        if ($r['status'] === 'done') {
            if (!empty($d['declined']['fix'])) {
                if ((int)strtotime((string)($d['declined']['at'] ?? '')) < time() - RS_HUB_WAIT_DAYS * 86400) continue;
                $r['col'] = 'back';
            } elseif (!empty($d['ps5_draft'])) {
                $g = rs_game((string)$d['ps5_draft']);
                if (!$g || empty($g['hidden'])) continue; // out on the store (or gone): off the board
                $r['col'] = 'draft';
            } else continue;
        } else $r['col'] = 'new';
        $r['d'] = $d;
        $out[] = $r;
    }
    return $out;
}
function rs_hub_jobs(): array {
    rs_auto_tables();
    $out = [];
    foreach (rs_db()->query("SELECT * FROM pc_auto WHERE status IN ('queued', 'running', 'review', 'failed') ORDER BY id DESC LIMIT 120") as $r) {
        $r['steps'] = json_decode((string)$r['steps'], true) ?: [];
        $out[] = $r;
    }
    return $out;
}
// A draft's state for its card and drawer: [col, note [state, text], stuck, vt [state, note]].
function rs_hub_job_state(array $j): array {
    $vt = ($j['steps']['vt']['s'] ?? '') === 'skip' ? ['skip', (string)($j['steps']['vt']['n'] ?? '')] : rs_auto_vt($j);
    $stuck = in_array($j['status'], ['queued', 'running'], true) && rs_hub_ts((string)$j['updated']) < time() - RS_AUTO_STUCK;
    if ($j['status'] === 'failed' || $stuck) return ['check', ['bad', $stuck ? 'Stopped. Run it again' : 'Didn\'t finish. Run it again'], $stuck, $vt];
    if ($j['status'] === 'queued') return ['check', ['run', 'In line to start'], false, $vt];
    if ($j['status'] === 'running') {
        $n = 0; $at = '';
        foreach (RS_AUTO_STEPS as $k => $label) {
            $s = $j['steps'][$k]['s'] ?? '';
            if (in_array($s, ['ok', 'warn', 'bad', 'skip'], true)) $n++;
            elseif ($at === '') $at = $label;
        }
        return ['check', ['run', ($at !== '' ? $at : 'Finishing') . ' · ' . $n . ' of ' . count(RS_AUTO_STEPS)], false, $vt];
    }
    // Ready: Claude's verdict, with the live VirusTotal state on top.
    $note = match ($j['verdict']) { 'good' => ['ok', 'Looks good'], 'skip' => ['bad', 'Claude says don\'t list it'], default => ['warn', 'Look first'] };
    if ($vt[0] === 'bad') $note = ['bad', 'VirusTotal flagged it'];
    elseif ($vt[0] === 'run') $note = ['run', 'VirusTotal checking'];
    elseif ($vt[0] === 'warn' && $note[0] === 'ok') $note = ['warn', 'Look first: ' . rs_hub_short($vt[1])];
    if ($note[0] === 'warn' && $note[1] === 'Look first' && trim((string)$j['notes']) !== '') $note[1] = 'Look first: ' . rs_hub_short((string)strtok(trim((string)$j['notes']), "\n"));
    return ['ready', $note, false, $vt];
}
// Where a draft came from.
function rs_hub_job_src(array $j): string {
    if (!empty($j['sub_id'])) return 'Submission';
    if ($j['who'] === RS_SCOUT_WHO) return 'Scout';
    if (str_ends_with((string)$j['who'], '· request')) return 'Request';
    if (str_ends_with((string)$j['who'], '· found')) return 'Found on GitHub';
    return 'Link';
}

// Open flags and team notes by item, for the cards.
function rs_hub_marks(): array {
    rs_hub_tables();
    $flags = rs_db()->query("SELECT item, COUNT(*) n FROM hub_flags WHERE status = 'open' GROUP BY item")->fetchAll(PDO::FETCH_KEY_PAIR);
    $notes = rs_db()->query('SELECT item, COUNT(*) n FROM hub_notes GROUP BY item')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [$flags, $notes];
}

// The board for one store: [col => [card]], plus the submissions whose checks need working out.
function rs_hub_board(string $store): array {
    $cols = array_fill_keys(array_keys(RS_HUB_COLS[$store]), []);
    [$flags, $notes] = rs_hub_marks();
    $jobs = $store === 'pc' ? rs_hub_jobs() : [];
    $jobBySub = [];
    foreach ($jobs as $j) if (!empty($j['sub_id'])) $jobBySub[(int)$j['sub_id']] = $j;
    $subs = array_values(array_filter(rs_hub_sub_rows(), fn($r) => rs_hub_sub_store($r['d']) === $store));
    $subById = [];
    foreach ($subs as $r) $subById[(int)$r['id']] = $r;
    // Submissions under a draft show as the draft; their own data fills its card.
    $extra = array_diff(array_keys($jobBySub), array_keys($subById));
    if ($extra) foreach (rs_db()->query('SELECT id, status, created, data, contact FROM inbox WHERE id IN (' . implode(',', array_map('intval', $extra)) . ')') as $r) { $r['d'] = json_decode((string)$r['data'], true) ?: []; $subById[(int)$r['id']] = $r; }
    $pics = rs_hub_pics(array_keys($subById));
    $icon = function (int $sid) use ($pics): string { foreach ($pics[$sid] ?? [] as $p) if ($p['role'] === 'icon') return 'picture.php?id=' . (int)$p['id']; return ''; };
    $kept = rs_hub_checks_kept(array_keys($subById));
    $need = [];
    foreach ($subs as $r) {
        $sid = (int)$r['id'];
        if ($r['col'] === 'new' && isset($jobBySub[$sid])) continue;
        $d = $r['d'];
        $c = $kept[$sid] ?? null;
        $note = ['run', 'Checking…'];
        if ($r['col'] === 'back') $note = ['wait', 'Asked for: ' . mb_strimwidth(trim((string)($d['declined']['reason'] ?? '')) ?: 'changes', 0, 70, '…')];
        elseif ($r['col'] === 'draft') $note = ['wait', 'Hidden draft · ' . (string)$d['ps5_draft']];
        elseif ($c && ($c[0]['sig'] ?? '') === md5(json_encode($d))) {
            $note = $c[0]['note'];
            if ($c[1] > RS_HUB_CHECK_AGE || ($note[0] === 'run' && $c[1] > 90)) $need[] = $sid;
        } else $need[] = $sid;
        $cols[$r['col']][] = [
            'key' => 'sub:' . $sid, 'name' => (string)($d['name'] ?? 'Untitled'), 'dev' => (string)($d['author'] ?? ''), 'verified' => !empty($d['verified']),
            'acct' => !empty($d['account']), 'src' => !empty($d['resubmitted']) && $r['col'] === 'new' ? 'Sent again' : 'Submission',
            'con' => $store === 'pc' ? rs_hub_con((string)($d['origin'] ?? '')) : rs_hub_cat((string)($d['category'] ?? '')),
            'when' => rs_hub_ts((string)$r['created']), 'note' => $note, 'icon' => $icon($sid), 'pre' => in_array($sid, $need, true),
            'flag' => !empty($flags['sub:' . $sid]), 'notes' => (int)($notes['sub:' . $sid] ?? 0),
            'q' => mb_strtolower(($d['name'] ?? '') . ' ' . ($d['author'] ?? '') . ' ' . ($d['repo'] ?? '') . ' ' . ($d['account_name'] ?? '')),
        ];
    }
    foreach ($jobs as $j) {
        [$col, $note] = rs_hub_job_state($j);
        $a = $j['app_id'] !== '' ? rs_pc_app($j['app_id']) : null;
        $sub = !empty($j['sub_id']) ? ($subById[(int)$j['sub_id']]['d'] ?? null) : null;
        $item = $sub !== null ? 'sub:' . (int)$j['sub_id'] : 'job:' . (int)$j['id'];
        $src = rs_hub_job_src($j);
        $name = $a['name'] ?? ($sub['name'] ?? rs_hub_job_path((string)$j['repo_key']));
        $con = rs_hub_con((string)($a['origin'] ?? ($sub['origin'] ?? '')));
        $cols[$col][] = [
            'key' => 'job:' . (int)$j['id'], 'name' => $name !== '' ? $name : preg_replace('#^https?://(www\.)?#', '', (string)$j['link']),
            'dev' => $sub !== null ? (string)($sub['author'] ?? '') : ($src === 'Link' ? 'Added by ' . (string)$j['who'] : ''), 'verified' => $sub !== null && !empty($sub['verified']),
            'acct' => $sub !== null && !empty($sub['account']), 'src' => $src, 'con' => $con, 'when' => rs_hub_ts((string)$j['created']), 'note' => $note,
            'icon' => (string)($a['icon'] ?? '') !== '' ? (string)$a['icon'] : ($sub !== null ? $icon((int)$j['sub_id']) : ''), 'pre' => false,
            'flag' => !empty($flags[$item]), 'notes' => (int)($notes[$item] ?? 0),
            'q' => mb_strtolower($name . ' ' . ($sub['author'] ?? '') . ' ' . $j['repo_key']),
        ];
    }
    // Newest first in New; the rest by when they started.
    foreach ($cols as &$c) usort($c, fn($x, $y) => $y['when'] <=> $x['when']);
    unset($c);
    return ['cols' => $cols, 'need' => $need];
}
// How many are waiting on someone in each store (for the store switch and the menu).
function rs_hub_counts(): array {
    $n = ['pc' => 0, 'ps5' => 0];
    try {
        $open = [];
        foreach (rs_db()->query("SELECT sub_id FROM pc_auto WHERE sub_id > 0 AND status IN ('queued', 'running', 'review', 'failed')") as $x) $open[(int)$x['sub_id']] = true;
        foreach (rs_db()->query("SELECT id, data FROM inbox WHERE kind = 'submit' AND status <> 'done'") as $r) {
            if (isset($open[(int)$r['id']])) continue;
            $d = json_decode((string)$r['data'], true);
            if (is_array($d)) $n[rs_hub_sub_store($d)]++;
        }
        $n['pc'] += (int)rs_db()->query("SELECT COUNT(*) FROM pc_auto WHERE status = 'review'")->fetchColumn();
    } catch (Throwable $e) { /* tables not made yet */ }
    return $n;
}
// Fingerprint of everything on the boards, for the page's live refresh.
function rs_hub_sig(): string {
    $s = '';
    try {
        foreach (rs_db()->query("SELECT id, status, MD5(data) m FROM inbox WHERE kind = 'submit' AND status <> 'done' ORDER BY id") as $r) $s .= $r['id'] . $r['status'] . $r['m'] . '|';
        foreach (rs_hub_jobs() as $j) $s .= $j['id'] . $j['status'] . md5(json_encode($j['steps'])) . implode('', rs_auto_vt($j)) . '|';
        rs_hub_tables();
        $s .= rs_db()->query('SELECT CONCAT(COUNT(*), MAX(id)) FROM hub_notes')->fetchColumn() . rs_db()->query("SELECT CONCAT(COUNT(*), MAX(id), SUM(status = 'open')) FROM hub_flags")->fetchColumn();
    } catch (Throwable $e) { /* nothing to compare */ }
    return md5($s);
}

// ---- Notes, flags and the conversation ---------------------------------------------------------------

function rs_hub_note_add(string $item, string $body): void {
    rs_hub_tables();
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') throw new RuntimeException('Write the note first.');
    if (mb_strlen($body) > 4000) throw new RuntimeException('Keep notes under 4,000 characters.');
    rs_db()->prepare('INSERT INTO hub_notes (item, uid, who, body, created) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')->execute([$item, (int)(rs_me()['id'] ?? 0), rs_me_name(), $body]);
}
function rs_hub_flag_add(string $item, string $title, string $store, string $note): int {
    rs_hub_tables();
    $note = trim(str_replace("\r\n", "\n", $note));
    if (mb_strlen($note) < 3) throw new RuntimeException('Say what you\'d like the owner to look at.');
    rs_db()->prepare('INSERT INTO hub_flags (item, title, store, uid, who, note, created) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$item, mb_substr($title, 0, 200), $store, (int)(rs_me()['id'] ?? 0), rs_me_name(), mb_substr($note, 0, 4000)]);
    $id = (int)rs_db()->lastInsertId();
    rs_log('hub', 'flag', mb_substr($title . ': ' . $note, 0, 200));
    return $id;
}
function rs_hub_flags(string $state = 'open', int $limit = 100): array {
    rs_hub_tables();
    $st = rs_db()->prepare('SELECT * FROM hub_flags WHERE ' . ($state === 'open' ? "status = 'open'" : "status <> 'open'") . ' ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}
// Flags waiting on the owner (owners and managers), or answers waiting for the person who flagged.
function rs_hub_flag_count(): int {
    try {
        rs_hub_tables();
        if (rs_hub_can_manage()) return (int)rs_db()->query("SELECT COUNT(*) FROM hub_flags WHERE status = 'open' AND answer IS NULL")->fetchColumn();
        $st = rs_db()->prepare('SELECT COUNT(*) FROM hub_flags WHERE uid = ? AND seen = 0');
        $st->execute([(int)(rs_me()['id'] ?? 0)]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
// Everything said about an item, oldest first: team notes, flags and answers, emails, developer messages.
function rs_hub_thread(string $item, ?array $sub = null, int $subId = 0): array {
    rs_hub_tables();
    $out = [];
    $st = rs_db()->prepare('SELECT * FROM hub_notes WHERE item = ? ORDER BY id');
    $st->execute([$item]);
    foreach ($st->fetchAll() as $n) $out[] = ['k' => 'team', 'who' => (string)$n['who'], 'body' => (string)$n['body'], 'at' => rs_hub_ts((string)$n['created'])];
    $st = rs_db()->prepare('SELECT * FROM hub_flags WHERE item = ? ORDER BY id');
    $st->execute([$item]);
    foreach ($st->fetchAll() as $f) {
        $out[] = ['k' => 'flag', 'who' => (string)$f['who'], 'body' => (string)$f['note'], 'at' => rs_hub_ts((string)$f['created']), 'open' => $f['status'] === 'open', 'id' => (int)$f['id']];
        if ($f['answer'] !== null && $f['answer'] !== '') $out[] = ['k' => 'answer', 'who' => (string)$f['answered_by'], 'body' => (string)$f['answer'], 'at' => rs_hub_ts((string)$f['answered'])];
    }
    if ($subId > 0) {
        try {
            rs_mail_tables();
            $st = rs_db()->prepare("SELECT to_addr, subject, body, created FROM mail_sent WHERE kind = 'reply' AND inbox_id = ? ORDER BY id");
            $st->execute([$subId]);
            foreach ($st->fetchAll() as $m) $out[] = ['k' => 'email', 'who' => (string)$m['to_addr'], 'subj' => (string)$m['subject'], 'body' => (string)$m['body'], 'at' => rs_hub_ts((string)$m['created'])];
        } catch (Throwable $e) { /* no mail yet */ }
    }
    $acc = $sub && !empty($sub['account']) ? (int)$sub['account'] : 0;
    if ($acc > 0) {
        try {
            foreach (array_slice(rs_dev_dm($acc, 40), -20) as $m) $out[] = ['k' => $m['team'] ? 'dm-team' : 'dm-dev', 'who' => $m['team'] ? 'RecompStore' : $m['who'], 'body' => $m['body'], 'at' => (int)strtotime($m['at'])];
        } catch (Throwable $e) { /* no hub messages */ }
    }
    usort($out, fn($a, $b) => $a['at'] <=> $b['at']);
    return $out;
}

// ---- Actions shared by the board ----------------------------------------------------------------------

// (2.1) A PS5 submission into PS5 games as a hidden draft with everything the developer sent. -> its id.
function rs_hub_ps5_apply(int $id, array $r, array $d): string {
    if (rs_host_norm((string)($d['host'] ?? '')) === 'gitlab') throw new RuntimeException('PS5 apps come from GitHub only for now.');
    $tid = (string)($d['title_id'] ?? '');
    $gid = preg_match('/^[A-Z]{4}\d{5}$/', $tid) && !rs_game($tid) && !isset(rs_taken_ids()[$tid]) && !rs_move_block($tid) ? $tid : 'SUB' . $id;
    if (rs_game($gid)) throw new RuntimeException('It\'s already added, as ' . $gid . '.');
    $g = rs_game_defaults();
    $links = [];
    foreach ((array)($d['links'] ?? []) as $u) {
        $host = (string)parse_url((string)$u, PHP_URL_HOST);
        $links[] = ['label' => preg_match('/discord/i', $host) ? 'Discord' : (preg_match('/ko-?fi/i', $host) ? 'Ko-fi' : (preg_match('/patreon/i', $host) ? 'Patreon' : preg_replace('/^www\./', '', $host))), 'url' => (string)$u, 'color' => ''];
    }
    $g = array_merge($g, [
        'id' => $gid, 'name' => (string)$d['name'], 'kind' => 'community', 'author' => (string)$d['author'],
        'category' => rs_category((string)$d['category']), 'source' => 'github', 'repo' => (string)$d['repo'],
        'asset' => (string)($d['asset'] ?? ''), 'version' => (string)($d['version'] ?? ''), 'blurb' => (string)($d['blurb'] ?? ''),
        'description' => (string)($d['description'] ?? ''), 'needs' => (string)($d['needs'] ?? ''), 'credits' => (string)($d['credits'] ?? ''),
        'links' => rs_clean_links($links), 'hidden' => true, 'featured' => false,
    ]);
    $pics = rs_db()->prepare('SELECT file, role, size FROM inbox_pictures WHERE inbox_id = ? ORDER BY id');
    $pics->execute([$id]);
    foreach ($pics->fetchAll() as $p) {
        if (!in_array($p['role'], ['icon', 'hero', 'logo'], true)) continue;
        $src = rs_report_dir() . '/' . basename((string)$p['file']);
        if (!is_file($src)) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'rssub');
        copy($src, $tmp);
        try { $g = array_merge($g, rs_save_image(['error' => UPLOAD_ERR_OK, 'size' => (int)$p['size'], 'tmp_name' => $tmp], (string)$p['role'], $gid)); }
        catch (Throwable $e) { /* a picture that won't convert is left for the admin to add */ }
        finally { @unlink($tmp); }
    }
    rs_save_game($g, null);
    $d['ps5_draft'] = $gid;
    rs_hub_sub_save($id, $d, 'done');
    rs_log('submission-added', (string)$id, 'PS5 draft ' . $gid);
    return $gid;
}
// The "you're live" email for a published PC submission: [to, subject, body] (to '' when they left no email).
function rs_hub_live_mail(int $subId): ?array {
    $s = rs_hub_sub($subId);
    if (!$s) return null;
    [$r, $d] = $s;
    $a = null;
    foreach (rs_pc_apps() as $x) if (strcasecmp(rs_pc_key($x), rs_repo_key((string)($d['host'] ?? ''), (string)($d['repo'] ?? ''))) === 0) $a = $x;
    if (!$a) return null;
    $who = trim((string)($d['author'] ?? ''));
    $sdk = ($d['rl_quick'] ?? '') !== 'yes' || ($d['rl_settings'] ?? '') !== 'yes';
    return ['to' => rs_inbox_email($r, null), 'name' => (string)$a['name'], 'subject' => (string)$a['name'] . ' is live on RecompLauncher',
        'body' => 'Hi' . ($who !== '' ? ' ' . $who : '') . ",\n\n" . 'Good news: ' . $a['name'] . ' is now live on RecompLauncher. Here\'s its page:' . "\n" . 'https://recomplauncher.com/game/' . rawurlencode((string)$a['id']) . "\n\n"
            . 'Players can install it from the launcher, and every release you publish on ' . rs_host_name((string)($d['host'] ?? '')) . ' reaches them automatically.'
            . ($sdk ? "\n\n" . 'If you\'d like it to feel even more native, quick install and in-launcher settings come from one small file in your release. The SDK walks you through it:' . "\n" . 'https://recomplauncher.com/sdk' : '')
            . "\n\n" . 'Thanks for sending it in.' . "\n"];
}
