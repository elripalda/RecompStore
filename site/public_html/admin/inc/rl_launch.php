<?php
// (3.3) The RecompLauncher beta going out. What recomplauncher.com/get shows once it's live: the
// download, how to install it, what you need, questions, known issues, and where bugs go. Before
// that, a preview link shows the same page to whoever has it, while everyone else still sees the
// sign-up. The download is always the version published on the admin's Updates tab
// (launcher/version.json), so the page and the launcher's own updates never disagree.
declare(strict_types=1);

require_once __DIR__ . '/rl_cloud.php';

// The page's words: field => longest it may be.
const RS_RL_LAUNCH_FIELDS = ['headline' => 200, 'intro' => 600, 'soon' => 200, 'steps' => 3000, 'reqs' => 2000,
    'warn_title' => 120, 'warn' => 1200, 'faq' => 12000, 'issues' => 3000, 'bugs' => 1500];

function rs_rl_launch_defaults(): array {
    return [
        'headline' => 'The beta is here.',
        'intro' => 'Free for Windows. Install recomps in one click, keep your saves backed up, and play from your desk or your couch.',
        'soon' => 'Linux and Mac are next.',
        'steps' => "Download the installer and open it.\nIf Windows says it protected your PC, click More info, then Run anyway.\nPick where your games go, and add the folders where you keep your games.\nInstall a recomp, point it at your own copy of the game the first time, and play.",
        'reqs' => "Windows 10 or 11, 64-bit\nYour own copy of each game\nA graphics card from the last few years. Each game's page says what it needs.\nAn internet connection for installs and updates",
        'warn_title' => 'Windows may warn you',
        'warn' => 'The beta isn\'t signed yet, so the first time you open the installer Windows may say "Windows protected your PC". Click More info, then Run anyway. The installer comes straight from recomplauncher.com, and the launcher checks every game it downloads before installing it.',
        'faq' => "Is it free?\nYes. RecompLauncher is free, and so are the recomps in it.\n\n"
            . "Do I need my own copy of the games?\nYes. Recomps run your own copy of the game. RecompLauncher doesn't come with any game files.\n\n"
            . "I already have recomps installed. Do I start over?\nNo. Press Add games you have in your Library. It finds the recomps in your folders and in Steam, and they keep updating right where they are.\n\n"
            . "Where do my saves go?\nIn each game's own save folder, same as without the launcher. RecompLauncher also keeps a backup after every session.\n\n"
            . "How do cloud saves work?\nSign in with your RecompStore account and turn cloud saves on for a game. Your newest saves go up after you play, and the next PC you play on gets them first. Each account has 300 MB.\n\n"
            . "Does it send anything about me?\nOnly if Share usage and diagnostics is on (Settings, General), and that same screen shows exactly what's sent. The privacy page has the rest.",
        'issues' => "Windows only for now. Linux and Mac are next.\nThe installer isn't signed yet, so Windows shows a warning the first time.",
        'bugs' => 'When a game crashes, RecompLauncher offers to send its log to us. Say yes, it helps a lot. For anything else, tell us in the Discord: which game, what happened, and what PC you have.',
    ];
}

function rs_rl_launch_page(): array {
    $d = rs_rl_launch_defaults();
    $saved = json_decode((string)rs_setting('rl_launch_page', ''), true);
    if (is_array($saved)) foreach ($d as $k => $v) if (isset($saved[$k]) && is_string($saved[$k])) $d[$k] = $saved[$k];
    return $d;
}

function rs_rl_launch_page_save(array $in): void {
    $out = [];
    foreach (RS_RL_LAUNCH_FIELDS as $k => $max) $out[$k] = mb_substr(trim(str_replace("\r", '', (string)($in[$k] ?? ''))), 0, $max);
    rs_set_setting('rl_launch_page', (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function rs_rl_launch_live(): bool { return rs_setting('rl_launch_live', '0') === '1'; }
function rs_rl_launch_set_live(bool $on): void {
    rs_set_setting('rl_launch_live', $on ? '1' : '0');
    if ($on && rs_setting('rl_launch_at', '') === '') rs_set_setting('rl_launch_at', gmdate('Y-m-d H:i:s'));
}

// The preview link's key, made the first time it's needed.
function rs_rl_launch_key(): string {
    $k = (string)rs_setting('rl_launch_key', '');
    if (!preg_match('/^[0-9a-f]{32}$/', $k)) { $k = bin2hex(random_bytes(16)); rs_set_setting('rl_launch_key', $k); }
    return $k;
}
function rs_rl_launch_preview_ok(string $k): bool { return preg_match('/^[0-9a-f]{32}$/', $k) === 1 && hash_equals(rs_rl_launch_key(), $k); }
function rs_rl_launch_preview_url(): string { return 'https://recomplauncher.com/get?look=' . rs_rl_launch_key(); }

// Downloads from the page, a count per day.
function rs_rl_launch_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS rl_launch_dl (day DATE NOT NULL PRIMARY KEY, n INT NOT NULL DEFAULT 0)');
    $done = true;
}
function rs_rl_launch_count(): void {
    rs_rl_launch_tables();
    rs_db()->exec('INSERT INTO rl_launch_dl (day, n) VALUES (UTC_DATE(), 1) ON DUPLICATE KEY UPDATE n = n + 1');
}
function rs_rl_launch_downloads(): array {
    rs_rl_launch_tables();
    $r = rs_db()->query('SELECT COALESCE(SUM(n), 0) total, COALESCE(SUM(CASE WHEN day = UTC_DATE() THEN n ELSE 0 END), 0) today FROM rl_launch_dl')->fetch();
    return ['total' => (int)($r['total'] ?? 0), 'today' => (int)($r['today'] ?? 0)];
}

// One item per line; a leading "1." or "-" is dropped.
function rs_rl_launch_lines(string $s): array {
    $out = [];
    foreach (explode("\n", $s) as $l) { $l = trim(preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', $l)); if ($l !== '') $out[] = $l; }
    return $out;
}
// Questions: blocks split by an empty line; the first line asks, the rest answers.
function rs_rl_launch_faq(string $s): array {
    $out = [];
    foreach (preg_split('/\n\s*\n/', trim($s)) ?: [] as $b) {
        $ls = array_values(array_filter(array_map('trim', explode("\n", $b)), fn($x) => $x !== ''));
        if (count($ls) >= 2) $out[] = [$ls[0], implode(' ', array_slice($ls, 1))];
    }
    return $out;
}

// What the Get page draws. null: nothing published yet, so there's nothing to download.
function rs_rl_launch_linux(): ?array {
    try {
        if (!function_exists('rs_lb_gh_latest')) require_once __DIR__ . '/betawave.php';
        $g = rs_lb_gh_latest('linux');
    } catch (Throwable $e) { error_log('launch linux: ' . $e->getMessage()); return null; }
    if (!$g) return null;
    return ['version' => $g['version'], 'size' => $g['size'], 'sha256' => $g['sha256'], 'download' => '/api/launcher-beta.php?a=download&os=linux',
        'deb' => $g['deb'] ? ['size' => $g['deb']['size'], 'download' => '/api/launcher-beta.php?a=download&os=deb'] : null];
}
// (4.2) The Mac build (Apple Silicon), when the newest release on GitHub has one and the Launcher
// beta page shows it.
function rs_rl_launch_mac(): ?array {
    try {
        if (!function_exists('rs_lb_gh_latest')) require_once __DIR__ . '/betawave.php';
        if (!rs_lb_mac_shown()) return null;
        $g = rs_lb_gh_latest('macos');
    } catch (Throwable $e) { error_log('launch mac: ' . $e->getMessage()); return null; }
    if (!$g) return null;
    return ['version' => $g['version'], 'size' => $g['size'], 'sha256' => $g['sha256'], 'download' => '/api/launcher-beta.php?a=download&os=mac'];
}
function rs_rl_launch_public(bool $preview): ?array {
    $v = rs_rl_version();
    // Nothing published yet: a preview shows the newest installer uploaded, so the page can be
    // checked before anything goes out.
    if ((empty($v['url']) || empty($v['version'])) && $preview && ($f = rs_rl_installers())) {
        $f = $f[0];
        $v = ['version' => preg_match('/^RecompLauncher_([0-9A-Za-z.]+?)(?:-[A-Za-z]+)?_x64-setup\.exe$/', $f['file'], $m) ? $m[1] : '?',
            'url' => 'https://recomplauncher.com/launcher/' . rawurlencode($f['file']), 'size' => $f['size'], 'date' => gmdate('Y-m-d', $f['at'])];
    }
    if (empty($v['url']) || empty($v['version'])) return null;
    $p = rs_rl_launch_page();
    return [
        'live' => rs_rl_launch_live(),
        'preview' => $preview,
        'version' => (string)$v['version'],
        'date' => (string)($v['date'] ?? ''),
        'size' => (int)($v['size'] ?? 0),
        'sha256' => (string)($v['sha256'] ?? ''),
        // A preview downloads the file itself, so testing it doesn't count as a download.
        'download' => $preview && !rs_rl_launch_live() ? (string)$v['url'] : '/api/launcher-beta.php?a=download',
        // (3.7) The Linux build, when the newest release on GitHub has one.
        'linux' => rs_rl_launch_linux(),
        'mac' => rs_rl_launch_mac(),
        'page' => [
            'headline' => $p['headline'], 'intro' => $p['intro'], 'soon' => $p['soon'],
            'steps' => rs_rl_launch_lines($p['steps']), 'reqs' => rs_rl_launch_lines($p['reqs']),
            'warnTitle' => $p['warn_title'], 'warn' => $p['warn'],
            'faq' => rs_rl_launch_faq($p['faq']), 'issues' => rs_rl_launch_lines($p['issues']), 'bugs' => $p['bugs'],
        ],
    ];
}
