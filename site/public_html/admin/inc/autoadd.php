<?php
// (4.7) Auto-add: paste a GitHub, GitLab or SourceForge link and the server does what we do by hand.
// It reads the repo and its newest release, makes the listing (hidden), checks the download (game files,
// emulator wrappers, ClamAV), puts it in line for VirusTotal, has Claude write the page and the boot
// profile (Easy Setup, launcher settings) from the README and the release, and picks the art and the
// game's info. Each run is a draft on Auto-add with what every step found; Publish puts it live.
// The work runs on this server in the background (auto-run.php), so the page answers at once.
declare(strict_types=1);

const RS_AUTO_STEPS = [
    'read' => 'Repo and release',
    'add' => 'Listing made (hidden)',
    'files' => 'Download looked inside',
    'clam' => 'ClamAV',
    'vt' => 'VirusTotal',
    'ai' => 'Name, game and page written',
    'art' => 'Cover, banner and logo',
    'about' => 'Game info (IGDB)',
    'easy' => 'Easy Setup',
    'settings' => 'Launcher settings',
];
const RS_AUTO_STUCK = 1200; // a run that hasn't moved in 20 minutes stopped
const RS_AUTO_MODEL = 'claude-sonnet-5-5';
const RS_AUTO_EMULATORS = 'melonds|dolphin|xenia|pcsx2|rpcs3|retroarch|ppsspp|cemu|ryujinx|yuzu|suyu|citra|lime3ds|desmume|mgba|snes9x|bsnes|cxbx|xemu|duckstation|mednafen|project64|mupen64|simple64|flycast|redream|vita3k|shadps4|azahar|eden|sudachi';

function rs_auto_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec("CREATE TABLE IF NOT EXISTS pc_auto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link VARCHAR(400) NOT NULL,
        repo_key VARCHAR(300) NOT NULL DEFAULT '',
        app_id VARCHAR(100) NOT NULL DEFAULT '',
        made TINYINT NOT NULL DEFAULT 0,
        tag VARCHAR(200) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'queued',
        steps MEDIUMTEXT NULL,
        verdict VARCHAR(10) NOT NULL DEFAULT '',
        notes TEXT NULL,
        who VARCHAR(80) NOT NULL DEFAULT '',
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function rs_auto_get(int $id): ?array {
    rs_auto_tables();
    $st = rs_db()->prepare('SELECT * FROM pc_auto WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['steps'] = json_decode((string)$r['steps'], true) ?: [];
    return $r;
}
function rs_auto_list(int $limit = 60): array {
    rs_auto_tables();
    $out = [];
    foreach (rs_db()->query("SELECT * FROM pc_auto WHERE status <> 'removed' ORDER BY id DESC LIMIT " . (int)$limit) as $r) {
        $r['steps'] = json_decode((string)$r['steps'], true) ?: [];
        $out[] = $r;
    }
    return $out;
}
function rs_auto_set(int $id, array $cols): void {
    $sets = []; $vals = [];
    foreach ($cols as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $v; }
    $vals[] = $id;
    rs_db()->prepare('UPDATE pc_auto SET ' . implode(', ', $sets) . ', updated = NOW() WHERE id = ?')->execute($vals);
}
// One step's state: run, ok, warn, bad or skip, with a short note.
function rs_auto_step(int $id, string $key, string $state, string $note = ''): void {
    $j = rs_auto_get($id);
    if (!$j) return;
    $s = $j['steps'];
    $s[$key] = ['s' => $state, 'n' => mb_substr($note, 0, 300)];
    rs_auto_set($id, ['steps' => $s]);
}

// Start one: a draft row and the background run. -> the draft's id (an open one for the same repo
// is reused instead of a second).
function rs_auto_start(string $link, string $who, bool $launch = true): int {
    rs_auto_tables();
    $l = rs_repo_from_link($link);
    if (!$l) throw new RuntimeException('Paste a GitHub, GitLab or SourceForge link, like github.com/owner/name.');
    $key = rs_repo_key($l['host'], $l['repo']);
    $st = rs_db()->prepare("SELECT id FROM pc_auto WHERE repo_key = ? AND status IN ('queued', 'running', 'review') ORDER BY id DESC LIMIT 1");
    $st->execute([$key]);
    if ($had = (int)$st->fetchColumn()) return $had;
    rs_db()->prepare('INSERT INTO pc_auto (link, repo_key, who, steps, created, updated) VALUES (?, ?, ?, ?, NOW(), NOW())')
        ->execute([mb_substr(trim($link), 0, 400), $key, mb_substr($who, 0, 80), '{}']);
    $id = (int)rs_db()->lastInsertId();
    if ($launch) rs_auto_launch($id);
    return $id;
}
// Run it again from the top (the listing it made is kept and filled in again).
function rs_auto_again(int $id): void {
    rs_auto_set($id, ['status' => 'queued', 'steps' => [], 'verdict' => '', 'notes' => '']);
    rs_auto_launch($id);
}
function rs_auto_dir(): string {
    $d = dirname(RS_PUBLIC) . '/recompstore-private/auto';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
function rs_auto_launch(int $id): void {
    $p = proc_open('setsid php ' . escapeshellarg(__DIR__ . '/auto-run.php') . ' ' . $id . ' >> ' . escapeshellarg(rs_auto_dir() . '/run.log') . ' 2>&1 &',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, rs_auto_dir(),
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => rs_auto_dir(), 'LANG' => 'C.UTF-8']);
    if (!is_resource($p)) { rs_auto_set($id, ['status' => 'failed', 'notes' => 'The background run didn\'t start on the server.']); return; }
    proc_close($p);
}

// ---- The run ---------------------------------------------------------------------------------------

function rs_auto_run(int $id): void {
    $job = rs_auto_get($id);
    if (!$job || in_array($job['status'], ['published', 'removed', 'running'], true)) return;
    rs_auto_set($id, ['status' => 'running']);
    $cur = 'read';
    try {
        // 1. The repo and its newest release (the finder's reader).
        rs_auto_step($id, 'read', 'run');
        $f = rs_pc_find_one($job['link']);
        if (empty($f['ok'])) throw new RuntimeException($f['why'] ?? 'That repo couldn\'t be read.');
        $host = rs_host_norm((string)($f['host'] ?? (rs_repo_from_link($job['link'])['host'] ?? '')));
        $key = rs_repo_key($host, (string)$f['repo']);
        $files = array_filter([(string)$f['assets']['win'], (string)$f['assets']['linux'], (string)$f['assets']['mac']]);
        if (!$files) throw new RuntimeException('Its newest release has no download for Windows, Linux or macOS.');
        $rel = rs_host_releases($host, (string)$f['repo'], 3600);
        $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
        $latest = null;
        foreach ($list as $r) if (empty($r['draft']) && empty($r['prerelease'])) { $latest = $r; break; }
        if (!$latest && $list) $latest = $list[0];
        $tag = (string)($latest['tag_name'] ?? '');
        rs_auto_set($id, ['repo_key' => $key, 'tag' => $tag]);
        $warnRead = [];
        if (!empty($f['archived'])) $warnRead[] = 'archived';
        if (!empty($f['fork'])) $warnRead[] = 'a fork';
        $per = [];
        foreach (['win' => 'Windows', 'linux' => 'Linux', 'mac' => 'Mac'] as $sk => $sl) if ((string)$f['assets'][$sk] !== '') $per[] = $sl . ': ' . $f['assets'][$sk];
        rs_auto_step($id, 'read', $warnRead ? 'warn' : 'ok', ($f['version'] !== '' ? 'v' . $f['version'] . ' · ' : '') . implode(' · ', $per) . ' · ' . (int)$f['stars'] . ' stars' . ($warnRead ? ' · ' . implode(', ', $warnRead) : ''));

        // 2. The listing, hidden. One the catalog already has is left alone.
        $cur = 'add';
        rs_auto_step($id, 'add', 'run');
        if ($job['app_id'] !== '' && ($a = rs_pc_app($job['app_id']))) {
            rs_auto_step($id, 'add', 'ok', 'Hidden as ' . $a['id'] . ' (filled in again)');
        } else {
            foreach (rs_pc_apps() as $x) if (strcasecmp(rs_pc_key($x), $key) === 0) {
                rs_auto_set($id, ['app_id' => $x['id']]);
                throw new RuntimeException('Already on RecompLauncher as ' . $x['name'] . (!empty($x['hidden']) ? ' (hidden)' : '') . '.');
            }
            $a = rs_pc_add($job['link']);
            rs_auto_set($id, ['app_id' => $a['id'], 'made' => 1]);
            rs_auto_step($id, 'add', 'ok', 'Hidden as ' . $a['id']);
        }

        // 3. VirusTotal: every download of the newest release in line, first in line (prio 1). The
        // step's answer is read live on the page from the security checks.
        if ($latest && function_exists('rs_vt_add')) {
            foreach ((array)$latest['assets'] as $as) if (in_array((string)$as['name'], $files, true)) rs_vt_add($key, $tag, $as, 'auto', 'auto:' . $id, 1);
            rs_auto_step($id, 'vt', 'run', 'In line');
        } else rs_auto_step($id, 'vt', 'skip', 'VirusTotal isn\'t set up');

        // 4. Inside the download (the Windows one, else the first): game files, an emulator in
        // disguise, what it ships. Then ClamAV on it.
        $cur = 'files';
        rs_auto_step($id, 'files', 'run');
        $pick = null;
        foreach ((array)($latest['assets'] ?? []) as $as) if ((string)$as['name'] === (string)$f['assets']['win']) $pick = $as;
        if (!$pick) foreach ((array)($latest['assets'] ?? []) as $as) if (in_array((string)$as['name'], $files, true)) { $pick = $as; break; }
        $names = []; $concerns = [];
        $tmp = '';
        if ($pick && (int)($pick['size'] ?? 0) > 900 << 20) {
            rs_auto_step($id, 'files', 'warn', 'The download is ' . round((int)$pick['size'] / 1073741824, 1) . ' GB: too big to look inside here');
        } elseif ($pick) {
            $tmp = rs_auto_dir() . '/dl-' . $id . '-' . bin2hex(random_bytes(4));
            $got = rs_auto_download((string)($pick['browser_download_url'] ?? $pick['url'] ?? ''), $tmp);
            if ($got !== '') { rs_auto_step($id, 'files', 'warn', 'Couldn\'t download it: ' . $got); $tmp = ''; }
            else {
                $lower = strtolower((string)$pick['name']);
                if (str_ends_with($lower, '.zip')) {
                    $z = new ZipArchive();
                    if ($z->open($tmp) === true) {
                        for ($i = 0; $i < $z->numFiles; $i++) $names[] = (string)$z->getNameIndex($i);
                        $z->close();
                    }
                }
                $game = array_values(array_filter($names, fn($n) => preg_match('/\.(z64|n64|v64|iso|xex|xiso|god|cue|chd|gcm|rvz|wbfs|nsp|xci|nds|3ds|cia|gba|sfc|smc|psarc|pkg|sfo|rpx|wud|wux)$/i', $n)));
                $emu = array_values(array_filter($names, fn($n) => preg_match('#(^|[/\\\\])(' . RS_AUTO_EMULATORS . ')[^/\\\\]*\.(exe|dll|toml|ini|so|dylib)$#i', $n)));
                if ($game) $concerns[] = 'Ships what looks like game data: ' . implode(', ', array_slice(array_map('basename', $game), 0, 3));
                if ($emu) $concerns[] = 'Ships an emulator (' . implode(', ', array_slice(array_map('basename', $emu), 0, 2)) . '): it may be an emulator wrapper, not a recomp';
                $what = $names ? count($names) . ' files' : (preg_match('/\.(exe|msi)$/', $lower) ? 'an installer (can\'t look inside)' : 'not a zip (not looked inside)');
                rs_auto_step($id, 'files', $game || $emu ? 'bad' : 'ok', $game || $emu ? implode('. ', $concerns) : 'No game files or emulator in it · ' . $what);
            }
        } else rs_auto_step($id, 'files', 'skip', 'No download to look at');

        $cur = 'clam';
        if ($tmp !== '' && function_exists('rs_clam_on') && rs_clam_on()) {
            rs_auto_step($id, 'clam', 'run');
            $c = rs_clam_scan($tmp);
            if ($c['ok'] && $c['found'] === '') rs_auto_step($id, 'clam', 'ok', 'Clean');
            elseif ($c['found'] !== '') { rs_auto_step($id, 'clam', 'bad', 'Found: ' . $c['found']); $concerns[] = 'ClamAV found ' . $c['found']; }
            else rs_auto_step($id, 'clam', 'warn', $c['err'] ?: 'No answer');
        } else rs_auto_step($id, 'clam', 'skip', $tmp === '' ? 'Nothing downloaded to scan' : 'ClamAV isn\'t installed');
        if ($tmp !== '') @unlink($tmp);

        // 5. Claude writes the page and the boot profile.
        $cur = 'ai';
        $a = rs_pc_app($a['id']);
        if (rs_secret_get('prq_ai_key') === '') {
            rs_auto_step($id, 'ai', 'skip', 'Add the Anthropic API key on SDK support (PR queue, Setup) for this step');
            rs_auto_step($id, 'easy', 'skip', 'Needs the Claude step');
            rs_auto_step($id, 'settings', 'skip', 'Needs the Claude step');
            $d = null;
        } else {
            rs_auto_step($id, 'ai', 'run', 'Reading the README and the release');
            $rd = rs_pc_readme((string)$f['repo'], $host, 3600);
            $d = rs_auto_ai($a, $f, $latest ?: [], (string)($rd['md'] ?? ''), $names);
            $a = rs_auto_apply($a, $d);
            rs_pc_save($a);
            $concerns = array_merge($concerns, array_map('strval', (array)($d['concerns'] ?? [])));
            rs_auto_step($id, 'ai', in_array($d['verdict'] ?? '', ['check', 'skip'], true) ? 'warn' : 'ok', $a['name'] . ' · ' . $a['game'] . ' · ' . (RS_PC_ORIGINS[$a['origin']] ?? $a['origin']));
            $b = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
            $easy = is_array($b) && (isset($b['game']) || isset($b['prepare']) || isset($b['inputs']));
            $sets = is_array($b) ? count((array)($b['settings'] ?? [])) : 0;
            $bootWhy = (string)($GLOBALS['rs_auto_boot_why'] ?? '');
            rs_auto_step($id, 'easy', $easy ? 'ok' : ($bootWhy !== '' ? 'warn' : 'skip'), $easy ? 'Players point it at their copy and it sets up' : ($bootWhy !== '' ? $bootWhy : (!empty($a['setup_required']) ? 'Not for this one: players follow the setup steps' : 'Not needed or not possible for this one')));
            rs_auto_step($id, 'settings', $sets ? 'ok' : 'skip', $sets ? $sets . ' in the launcher' : 'None its config allows');
        }

        // 6. Art and the game's info, for the game Claude named.
        $cur = 'about';
        rs_auto_step($id, 'about', 'run');
        try { if ($ab = rs_pc_about_fetch($a)) { $a['about'] = $ab; unset($a['about_none']); } } catch (Throwable $e) { $ab = null; }
        rs_auto_step($id, 'about', !empty($ab) ? 'ok' : 'warn', !empty($ab) ? $ab['name'] . (($ab['released'] ?? '') !== '' ? ' (' . substr((string)$ab['released'], 0, 4) . ')' : '') : 'IGDB has no close match');
        $cur = 'art';
        rs_auto_step($id, 'art', 'run');
        $got = [];
        if (rs_sgdb_key() !== '' && $a['game'] !== '') {
            $art = rs_sgdb_art($a['game'], rs_pc_art_used($a['id']), 0, rs_pc_about_year($a));
            $m = rs_pc_media_many(array_intersect_key($art, array_flip(['cover', 'hero', 'logo', 'icon'])), $a['id']);
            $a = array_merge($a, $m);
            unset($a['hero_web']);
            foreach (['cover', 'hero', 'logo', 'icon'] as $k) if (isset($m[$k . '_src']) || (!empty($m[$k]))) $got[] = $k;
        }
        if (trim((string)$a['features']) === '') $a = rs_pc_fill_features($a, true);
        rs_pc_save($a);
        $missing = array_diff(['cover', 'hero', 'logo'], array_keys(array_filter(['cover' => $a['cover'], 'hero' => $a['hero'], 'logo' => $a['logo']])));
        rs_auto_step($id, 'art', $missing ? 'warn' : 'ok', $missing ? 'Missing: ' . implode(', ', $missing) : 'From SteamGridDB');

        $verdict = (string)($d['verdict'] ?? '');
        foreach (rs_auto_get($id)['steps'] as $s) if (($s['s'] ?? '') === 'bad') $verdict = 'skip';
        rs_auto_set($id, ['status' => 'review', 'verdict' => $verdict ?: 'check', 'notes' => mb_substr(trim(implode("\n", array_unique(array_filter(array_merge($concerns, [(string)($d['notes'] ?? '')]))))), 0, 3000)]);
        rs_log('pc', 'auto', 'Auto-add ready: ' . $a['id']);
    } catch (Throwable $e) {
        rs_auto_step($id, $cur, 'bad', $e->getMessage());
        rs_auto_set($id, ['status' => 'failed', 'notes' => mb_substr($e->getMessage(), 0, 1000)]);
        if (function_exists('rs_errlog') && !($e instanceof RuntimeException)) rs_errlog('auto-add', $e);
    }
}

// A download to a file, up to 900 MB. -> '' or what went wrong.
function rs_auto_download(string $url, string $to): string {
    if (!preg_match('#^https://#', $url)) return 'no download link';
    $fh = @fopen($to, 'wb');
    if (!$fh) return 'can\'t write here';
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'RecompLauncher', CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($ch, $dt, $d) => $d > (900 << 20) ? 1 : 0]);
    $ok = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    fclose($fh);
    if (!$ok || $code !== 200) { @unlink($to); return 'HTTP ' . $code; }
    return '';
}

// The SDK guide the boot profile follows (kept a day).
function rs_auto_spec(): string {
    $k = rs_kv_get('auto_spec');
    if ($k && $k['age'] < 86400 && strlen($k['v']) > 2000) return $k['v'];
    $r = rs_http('https://recomplauncher.com/sdk-files/recomplauncher-agent.md', ['timeout' => 30, 'max' => 2 << 20]);
    if ((int)$r['status'] === 200 && strlen((string)$r['body']) > 2000) { rs_kv_put('auto_spec', (string)$r['body']); return (string)$r['body']; }
    return $k['v'] ?? '';
}

const RS_AUTO_SYSTEM = 'You curate a listing on RecompLauncher, a launcher for PC recomps and decomps (native PC ports of console games; players bring their own copy of the game). From the project\'s GitHub facts, README, newest release and the files inside its download, you write the listing and, when the project allows it, a boot profile (a recomplauncher.json, following the SDK guide below) so the launcher can set the game up and offer settings.

Rules:
- Only state what the project really has. Paths, arguments, file and folder names come from the README, the release or the download\'s file list. Leave a field out rather than guess.
- name: the project\'s own name, cleaned up (spaces, capitals), never the raw repo slug. game: the official title of the game it ports. origin: the console the port comes from, one of the keys given. genre: one of the keys given.
- blurb: one plain line, under 150 characters. description: two or three short paragraphs in plain words, like a person wrote them. No hype, no emoji, no lists, no debugging detail, nothing about AI.
- features: up to 8 short lines, real things players get (widescreen, high frame rate, mods, online). needs: exactly what the player brings (the console version, region, version or update, format), in one line.
- setup_required: true only when players must do something by hand before the first play that the boot profile can\'t do; then setup_steps, short and in order, as a player would follow them in the launcher (Open Folder is the button that opens the install folder).
- tags: up to 6, from: Widescreen, 60 FPS, High FPS, Mods, Online, Multiplayer, Controller, Ray Tracing, Upscaling, Ultrawide, HDR, Achievements.
- boot: a recomplauncher.json for the catalog (format 1, start.program from the download\'s file list, and game, inputs, prepare, config and settings only where the README or files show how). null when there isn\'t enough to go on. Never invent arguments or config keys.
- concerns: anything an admin must know before listing it: an emulator wrapped up as a "recomp", no playable build yet, game files in the download, a fork that only repackages another project, a project that says it\'s not ready. launcher_downloads is what the launcher will download on each system; only bring up other release files if one of those picks looks wrong. verdict: good (list it), check (look first), skip (don\'t list).
- notes: one or two short lines for the admin.

Answer with one JSON object and nothing else:
{"name": "", "game": "", "origin": "", "genre": "", "blurb": "", "description": "", "features": [], "needs": "", "setup_required": false, "setup_steps": [], "tags": [], "boot": null, "concerns": [], "verdict": "good", "notes": ""}

SDK guide:
';

function rs_auto_ai(array $a, array $f, array $latest, string $readme, array $names): array {
    $user = [
        'repo' => $f['repo'], 'description' => $f['description'] ?? '', 'stars' => $f['stars'] ?? 0, 'license' => $f['license'] ?? '', 'topics' => $f['topics'] ?? [],
        'fork' => !empty($f['fork']), 'archived' => !empty($f['archived']),
        'release' => ['tag' => $latest['tag_name'] ?? '', 'name' => $latest['name'] ?? '', 'notes' => mb_substr((string)($latest['body'] ?? ''), 0, 3000),
            'assets' => array_map(fn($x) => ['name' => $x['name'], 'mb' => round((int)($x['size'] ?? 0) / 1048576, 1)], (array)($latest['assets'] ?? []))],
        // What the launcher will actually download on each system (already picked; no need to flag other files).
        'launcher_downloads' => ['windows' => $f['assets']['win'] ?? '', 'linux' => $f['assets']['linux'] ?? '', 'mac' => $f['assets']['mac'] ?? ''],
        'inside_the_download' => array_slice($names, 0, 400),
        'readme' => mb_substr($readme, 0, 24000),
        'our_guess' => ['name' => $a['name'], 'game' => $a['game'], 'origin' => $a['origin']],
        'origins' => RS_PC_ORIGINS, 'genres' => array_keys(RS_PC_GENRES),
    ];
    $body = ['model' => RS_AUTO_MODEL, 'max_tokens' => 8000,
        'system' => [['type' => 'text', 'text' => RS_AUTO_SYSTEM . rs_auto_spec(), 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => [['role' => 'user', 'content' => "Curate this project for RecompLauncher.\n\n" . json_encode($user, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]];
    return rs_ai_json($body);
}

// One call to Claude with the Anthropic key from SDK support; the JSON it answers with. Busy or overloaded
// answers are tried again a few times.
function rs_ai_json(array $body) {
    for ($try = 0; $try < 3; $try++) {
        $c = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . rs_secret_get('prq_ai_key'), 'anthropic-version: 2023-06-01', 'content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        $res = (string)curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if ($code === 200) {
            $j = json_decode($res, true) ?: [];
            $text = '';
            foreach ((array)($j['content'] ?? []) as $p) if (($p['type'] ?? '') === 'text') $text .= $p['text'];
            if (preg_match('/[\[{].*[\]}]/s', $text, $m) && is_array($d = json_decode($m[0], true))) return $d;
            throw new RuntimeException('Claude\'s answer wasn\'t the JSON asked for.');
        }
        if (in_array($code, [429, 500, 502, 503, 529], true)) { sleep(15 * ($try + 1)); continue; }
        throw new RuntimeException('Claude answered HTTP ' . $code . ': ' . mb_substr($res, 0, 160));
    }
    throw new RuntimeException('Claude is busy. Run it again in a few minutes.');
}

// What Claude wrote, onto the listing (only what passes our own checks).
function rs_auto_apply(array $a, array $d): array {
    $GLOBALS['rs_auto_boot_why'] = '';
    $s = fn($k, $max) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
    if ($s('name', 120) !== '') $a['name'] = $s('name', 120);
    if ($s('game', 160) !== '') $a['game'] = $s('game', 160);
    if (isset(RS_PC_ORIGINS[(string)($d['origin'] ?? '')])) $a['origin'] = (string)$d['origin'];
    if (isset(RS_PC_GENRES[(string)($d['genre'] ?? '')])) $a['genre'] = (string)$d['genre'];
    if ($s('blurb', 160) !== '') $a['blurb'] = $s('blurb', 160);
    if ($s('description', 8000) !== '') $a['description'] = $s('description', 8000);
    $feat = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 150), (array)($d['features'] ?? []))));
    if ($feat) { $a['features'] = implode("\n", array_slice($feat, 0, 8)); $a['features_read'] = true; $a['features_v'] = 2; }
    if ($s('needs', 200) !== '') $a['needs'] = $s('needs', 200);
    $steps = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), (array)($d['setup_steps'] ?? []))));
    if (!empty($d['setup_required']) && $steps) { $a['setup_required'] = true; $a['setup_steps'] = implode("\n", array_slice($steps, 0, 8)); }
    $tags = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 24), (array)($d['tags'] ?? []))));
    if ($tags) $a['tags'] = implode(', ', array_slice(array_unique($tags), 0, 8));
    if (is_array($d['boot'] ?? null) && $d['boot']) {
        if (!function_exists('rs_boot_check')) require_once __DIR__ . '/boot.php';
        $errs = array_filter(rs_boot_check($d['boot']), fn($p) => ($p[0] ?? '') === 'err');
        if (!$errs) $a['boot'] = json_encode($d['boot'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        else $GLOBALS['rs_auto_boot_why'] = 'Boot profile left out: ' . mb_substr((string)reset($errs)[1], 0, 150);
    }
    return $a;
}

// The VirusTotal step, read live: [state, note].
function rs_auto_vt(array $job): array {
    if ($job['repo_key'] === '' || $job['tag'] === '' || !function_exists('rs_vt_release')) return ['skip', ''];
    [$rows, $v] = rs_vt_release($job['repo_key'], $job['tag']);
    if (!$rows) return [($job['steps']['vt']['s'] ?? '') === 'skip' ? 'skip' : 'run', 'In line'];
    $clamOnly = !array_filter($rows, fn($r) => (string)($r['scanner'] ?? 'vt') !== 'clamav');
    if ($v === 'green' && $clamOnly) return ['warn', 'Only ClamAV checked it (clean); VirusTotal hasn\'t yet. Run again to send it to VirusTotal'];
    $flag = implode(' · ', array_filter(array_map(fn($r) => (string)$r['flagged_by'], $rows)));
    $eng = max(array_map(fn($r) => (int)$r['engines'], $rows));
    $hits = max(array_map(fn($r) => (int)$r['malicious'] + (int)$r['suspicious'], $rows));
    return match ($v) {
        'green' => ['ok', 'Clean' . ($eng ? ' (0 of ' . $eng . ')' : '')],
        'yellow' => ['warn', $hits . ' minor flag' . ($hits === 1 ? '' : 's') . ($flag !== '' ? ': ' . mb_strimwidth($flag, 0, 120, '…') : '') . ' (common for unsigned programs)'],
        'red' => ['bad', $hits . ' engines flagged it' . ($flag !== '' ? ': ' . mb_strimwidth($flag, 0, 120, '…') : '')],
        default => ['run', 'Checking (big files can take a while)'],
    };
}

// Publish: shown and the catalog published. Held while VirusTotal flags it red.
function rs_auto_publish(int $id): string {
    $job = rs_auto_get($id);
    if (!$job || $job['status'] !== 'review') throw new RuntimeException('That draft isn\'t ready to publish.');
    if (rs_auto_vt($job)[0] === 'bad') throw new RuntimeException('VirusTotal flagged it, so it stays hidden. Decide on Security first.');
    $a = rs_pc_app($job['app_id']);
    if (!$a) throw new RuntimeException('Its listing is gone.');
    $a['hidden'] = false;
    $a['live_at'] = $a['live_at'] ?? gmdate('c');
    rs_pc_save($a);
    rs_pc_publish();
    rs_auto_set($id, ['status' => 'published']);
    rs_log('pc', $a['id'], 'Auto-add published');
    return $a['name'];
}
// Remove a draft; the hidden listing it made goes too (one that was already there stays).
function rs_auto_remove(int $id): void {
    $job = rs_auto_get($id);
    if (!$job) return;
    if ((int)$job['made'] && $job['app_id'] !== '' && $job['status'] !== 'published' && ($a = rs_pc_app($job['app_id'])) && !empty($a['hidden']))
        rs_db()->prepare('DELETE FROM pc_apps WHERE id = ?')->execute([$job['app_id']]);
    rs_auto_set($id, ['status' => 'removed']);
}
