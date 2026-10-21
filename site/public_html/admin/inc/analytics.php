<?php
// Recomp Store website (2.1): what the Analytics page counts, day by day. Consoles that opened the
// store, installs, updates and removals per app, beta downloads, and the website's visits. No
// cookies and no third parties: a website visitor is a code made from their address and browser
// with a salt that changes every day, so the same person can't be followed from one day to the next.
declare(strict_types=1);

function rs_analytics_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS console_days (day DATE NOT NULL, install_id CHAR(16) NOT NULL, PRIMARY KEY (day, install_id)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS app_events (day DATE NOT NULL, app_id VARCHAR(64) NOT NULL, kind VARCHAR(8) NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (day, app_id, kind)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS beta_downloads (day DATE NOT NULL, game_id VARCHAR(64) NOT NULL, install_id CHAR(16) NOT NULL, PRIMARY KEY (day, game_id, install_id)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS site_pages (day DATE NOT NULL, path VARCHAR(120) NOT NULL, views INT NOT NULL DEFAULT 0, PRIMARY KEY (day, path)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS site_visitors (day DATE NOT NULL, vhash CHAR(16) NOT NULL, device VARCHAR(8) NOT NULL, ref VARCHAR(80) NOT NULL, hits INT NOT NULL DEFAULT 1, PRIMARY KEY (day, vhash)) CHARACTER SET utf8mb4');
    $done = true;
}

// A console opened the store today (called with every request it makes; one row a day).
function rs_count_console(PDO $db, string $install): void {
    static $seen = [];
    if (isset($seen[$install])) return;
    $seen[$install] = true;
    try {
        rs_analytics_tables();
        $db->prepare('INSERT IGNORE INTO console_days (day, install_id) VALUES (UTC_DATE(), ?)')->execute([$install]);
    } catch (Throwable $e) {
    }
}

// An install (first time on that console), an update (a new version over one) or a removal.
function rs_count_app(PDO $db, string $app, string $kind): void {
    try {
        rs_analytics_tables();
        $db->prepare('INSERT INTO app_events (day, app_id, kind, n) VALUES (UTC_DATE(), ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$app, $kind]);
    } catch (Throwable $e) {
    }
}

// (2.3) $file: which build (the zip's name), so App stats can count each build's downloads.
function rs_count_beta_download(PDO $db, string $game, string $install, string $file = ''): void {
    try {
        rs_analytics_tables();
        $db->prepare('INSERT IGNORE INTO beta_downloads (day, game_id, install_id) VALUES (UTC_DATE(), ?, ?)')->execute([$game, $install]);
        if ($file !== '') {
            $db->exec('CREATE TABLE IF NOT EXISTS beta_file_downloads (day DATE NOT NULL, game_id VARCHAR(64) NOT NULL, file VARCHAR(190) NOT NULL, install_id CHAR(16) NOT NULL, PRIMARY KEY (day, game_id, file, install_id)) CHARACTER SET utf8mb4');
            $db->prepare('INSERT IGNORE INTO beta_file_downloads (day, game_id, file, install_id) VALUES (UTC_DATE(), ?, ?, ?)')->execute([$game, substr($file, 0, 190), $install]);
        }
    } catch (Throwable $e) {
    }
}

function rs_device_kind(string $ua): string {
    if (preg_match('/PlayStation 5|PS5/i', $ua)) return 'ps5';
    if (preg_match('/iPad|Tablet/i', $ua)) return 'tablet';
    if (preg_match('/Mobi|iPhone|Android/i', $ua)) return 'phone';
    return 'computer';
}

function rs_is_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|headless|lighthouse/i', $ua);
}

// A page of the website was shown. $path is the page (#/game/X becomes /game/X), $ref where the
// visitor came from (another site's address; only its host is kept).
function rs_count_visit(string $path, string $ref): void {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (rs_is_bot($ua)) return;
    $path = '/' . ltrim((string)preg_replace('#[^A-Za-z0-9/_.-]#', '', $path), '/');
    $path = (string)preg_replace('#^/(l|r)/.*$#', '/$1', $path); // link and report codes aren't kept
    $path = substr($path, 0, 120);
    $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
    $self = strtolower((string)parse_url(rs_setting('site_url', 'https://recompstore.com'), PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? '';
    if ($host === '' || $host === $self || $host === preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) $host = 'direct';
    $salt = rs_setting('visit_salt_' . gmdate('Ymd'), '');
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        rs_set_setting('visit_salt_' . gmdate('Ymd'), $salt);
        // Yesterday's and older salts go, so old codes can never be remade.
        try { rs_db()->exec("DELETE FROM settings WHERE k LIKE 'visit\\_salt\\_%' AND k <> " . rs_db()->quote('visit_salt_' . gmdate('Ymd'))); } catch (Throwable $e) {}
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $vhash = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);
    try {
        rs_analytics_tables();
        $db = rs_db();
        $st = $db->prepare('INSERT INTO site_visitors (day, vhash, device, ref, hits) VALUES (UTC_DATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1');
        $st->execute([$vhash, rs_device_kind($ua), substr($host, 0, 80)]);
        $h = $db->prepare('SELECT hits FROM site_visitors WHERE day = UTC_DATE() AND vhash = ?');
        $h->execute([$vhash]);
        if ((int)$h->fetchColumn() > 500) return; // one visitor hammering the site counts once it calms down
        $db->prepare('INSERT INTO site_pages (day, path, views) VALUES (UTC_DATE(), ?, 1) ON DUPLICATE KEY UPDATE views = views + 1')->execute([$path]);
    } catch (Throwable $e) {
    }
}

// ---- What the Analytics page reads -----------------------------------------------------------

// One value per day from $from to today (UTC), zeros where nothing happened.
function rs_series(string $sql, array $args, int $days): array {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', time() - $i * 86400)] = 0;
    try {
        $st = rs_db()->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$d, $n]) if (isset($out[(string)$d])) $out[(string)$d] = (int)$n;
    } catch (Throwable $e) {
    }
    return $out;
}

// ---- (2.3) Analytics, App stats and the Dashboard: ranges, tables, CSV, GitHub numbers -------
// Everything below only reads. GitHub's numbers come from the cache (gh_cache) and are never
// asked for while a page is drawn; rs_an_gh_refresh() asks again for ones an hour old or older.

const RS_AN_RANGES = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 0 => 'All time'];

// The range picked with ?days= (7, 30, 90, or 0 for all time). 'from' is the first day counted
// (Y-m-d, UTC); 'n' how many days the charts show (all time: since anything was first counted).
function rs_an_range(): array {
    $d = (int)($_GET['days'] ?? 30);
    if (!array_key_exists($d, RS_AN_RANGES)) $d = 30;
    $n = $d;
    if ($d === 0) {
        $first = rs_an_first_day();
        $n = $first === '' ? 30 : (int)round((strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC') - strtotime($first . ' 00:00:00 UTC')) / 86400) + 1;
        $n = max(7, min(1100, $n));
    }
    return ['days' => $d, 'n' => $n, 'from' => $d === 0 ? '2000-01-01' : gmdate('Y-m-d', time() - ($n - 1) * 86400),
        'label' => RS_AN_RANGES[$d], 'short' => $d === 0 ? 'all time' : "the last $d days"];
}

function rs_an_first_day(): string {
    $first = '';
    foreach (['SELECT MIN(day) FROM console_days', 'SELECT MIN(day) FROM app_events', 'SELECT MIN(day) FROM site_visitors', 'SELECT MIN(day) FROM beta_downloads'] as $q) {
        try { $v = (string)(rs_db()->query($q)->fetchColumn() ?: ''); } catch (Throwable $e) { $v = ''; }
        if ($v !== '' && ($first === '' || $v < $first)) $first = $v;
    }
    return $first;
}

// Whether a table (or one of its columns) is there yet: some come with other parts of 2.3.
function rs_an_has(string $table, string $col = ''): bool {
    static $memo = [];
    $k = $table . '.' . $col;
    if (isset($memo[$k])) return $memo[$k];
    try {
        $db = rs_db();
        $t = $db->prepare('SELECT COUNT(*) FROM information_schema.' . ($col === '' ? 'TABLES' : 'COLUMNS') . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?' . ($col === '' ? '' : ' AND COLUMN_NAME = ?'));
        $t->execute($col === '' ? [$table] : [$table, $col]);
        $ok = (int)$t->fetchColumn() > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $memo[$k] = $ok;
}

function rs_an_int(string $sql, array $args = []): int {
    try { $st = rs_db()->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
}

function rs_an_rows(string $sql, array $args = []): array {
    try { $st = rs_db()->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

// First column => second, as whole numbers.
function rs_an_pairs(string $sql, array $args = []): array {
    $out = [];
    foreach (rs_an_rows($sql, $args) as $r) { $v = array_values($r); $out[(string)$v[0]] = (int)$v[1]; }
    return $out;
}

// Every app the pages can name: the admin's own (hidden, beta and testers-only ones too), the
// published catalog's, and anything else consoles reported (apps from other sources) by its id.
function rs_an_apps(): array {
    $out = [];
    foreach (rs_published_apps() as $id => $a) {
        $src = is_array($a['source'] ?? null) ? $a['source'] : [];
        $out[(string)$id] = ['id' => (string)$id, 'name' => (string)($a['name'] ?? $id), 'category' => rs_category((string)($a['category'] ?? 'games')),
            'icon' => (string)($a['icon'] ?? ''), 'repo' => ($src['type'] ?? '') === 'github' ? (string)($src['repo'] ?? '') : '',
            'asset' => (string)($src['asset'] ?? ''), 'mine' => false, 'flags' => [], 'game' => null];
    }
    foreach (rs_games(true) as $g) {
        $flags = [];
        if ($g['hidden']) $flags[] = 'Hidden';
        if (!empty($g['testers'])) $flags[] = 'Testers only';
        if ($g['beta']) $flags[] = 'Beta';
        if ($g['soon']) $flags[] = 'Coming soon';
        $out[$g['id']] = ['id' => $g['id'], 'name' => $g['name'] !== '' ? $g['name'] : $g['id'], 'category' => rs_category((string)$g['category']),
            'icon' => (string)$g['icon'], 'repo' => $g['source'] === 'github' ? (string)$g['repo'] : '',
            'asset' => $g['source'] === 'github' ? (string)$g['asset'] : '', 'mine' => true, 'flags' => $flags, 'game' => $g];
    }
    return $out;
}

// GitHub's numbers for a repo, from the cache only: its releases (newest first, null when there
// are none cached), its stars, and when they were fetched (unix time, 0 for never).
function rs_an_gh(string $repo): array {
    $out = ['releases' => null, 'stars' => null, 'fetched' => 0, 'missing' => false];
    if (!rs_repo_ok($repo)) return $out;
    try {
        rs_cache_tables();
        $st = rs_db()->prepare('SELECT k, status, body, UNIX_TIMESTAMP(fetched) t FROM gh_cache WHERE k IN (?, ?)');
        $st->execute([strtolower($repo), strtolower($repo) . '#info']);
        foreach ($st->fetchAll() as $r) {
            $j = json_decode((string)$r['body'], true);
            if (str_ends_with((string)$r['k'], '#info')) {
                if ((int)$r['status'] === 200 && is_array($j)) $out['stars'] = (int)($j['stargazers_count'] ?? 0);
                continue;
            }
            $out['fetched'] = (int)$r['t'];
            if ((int)$r['status'] === 200 && is_array($j)) $out['releases'] = $j;
            elseif ((int)$r['status'] === 404) $out['missing'] = true;
        }
    } catch (Throwable $e) {
    }
    return $out;
}

// Download counts from cached releases: each release (newest first) with its files' counts, the
// total, and the newest full release's. Only files named like $pattern count (the app's own zip;
// * and ? allowed); when no release has such a file, every file does.
function rs_an_gh_downloads(?array $releases, string $pattern = ''): array {
    $out = ['total' => 0, 'latest' => 0, 'latest_tag' => '', 'releases' => [], 'all_files' => $pattern === ''];
    foreach ($releases ?? [] as $r) {
        if (!is_array($r) || !empty($r['draft'])) continue;
        $files = [];
        foreach (is_array($r['assets'] ?? null) ? $r['assets'] : [] as $a) {
            $name = (string)($a['name'] ?? '');
            if ($pattern !== '' && !rs_pattern_matches($pattern, $name)) continue;
            $files[$name] = (int)($a['download_count'] ?? 0);
        }
        $sum = array_sum($files);
        $out['releases'][] = ['tag' => (string)($r['tag_name'] ?? ''), 'date' => substr((string)($r['published_at'] ?? ''), 0, 10),
            'pre' => !empty($r['prerelease']), 'downloads' => $sum, 'files' => $files, 'url' => (string)($r['html_url'] ?? '')];
        $out['total'] += $sum;
        if ($out['latest_tag'] === '' && empty($r['prerelease']) && $files) { $out['latest_tag'] = (string)($r['tag_name'] ?? ''); $out['latest'] = $sum; }
    }
    if ($pattern !== '' && $out['total'] === 0 && !array_filter($out['releases'], fn($x) => $x['files'])) return rs_an_gh_downloads($releases, '');
    return $out;
}

// The store's own repo: from the store zip's or the installer's link in Settings.
function rs_an_store_repo(): string {
    foreach (['store_url', 'payload_url'] as $k) {
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/#', (string)rs_setting($k, ''), $m) && rs_repo_ok($m[1])) return $m[1];
    }
    return 'elripalda/RecompStore';
}

// Asks GitHub again about repos whose cached numbers are an hour old or older, oldest first, up
// to $max in one call. A repo GitHub didn't answer for isn't asked about again for an hour either.
// -> ['refreshed' => how many have newer numbers now, 'left' => how many are still waiting].
function rs_an_gh_refresh(array $repos, int $max = 5): array {
    $todo = [];
    foreach (array_unique(array_filter($repos, fn($r) => is_string($r) && rs_repo_ok($r))) as $repo) {
        $g = rs_an_gh($repo);
        if (time() - $g['fetched'] < 3600) continue;
        $tried = rs_kv_get('an_gh_try:' . strtolower($repo));
        if ($tried && $tried['age'] < 3600) continue;
        $todo[$repo] = $g['fetched'];
    }
    asort($todo);
    $n = 0;
    foreach (array_slice(array_keys($todo), 0, $max) as $repo) {
        rs_kv_put('an_gh_try:' . strtolower((string)$repo), '1');
        rs_gh_releases((string)$repo, 3600);
        rs_gh_repo((string)$repo, 3600);
        if (rs_an_gh((string)$repo)['fetched'] > $todo[$repo]) ++$n;
    }
    return ['refreshed' => $n, 'left' => max(0, count($todo) - $max)];
}

// How long ago a unix time was, short: "just now", "12 min ago", "3 h ago", "Oct 19".
function rs_an_ago(int $t): string {
    $s = time() - $t;
    return $s < 90 ? 'just now' : ($s < 3600 ? round($s / 60) . ' min ago' : ($s < 86400 ? round($s / 3600) . ' h ago' : gmdate('M j', $t)));
}

// "as of" for GitHub's numbers: the oldest of the times given (unix), in UTC.
function rs_an_asof(array $times): string {
    $times = array_filter($times, fn($t) => $t > 0);
    if (!$times) return 'GitHub numbers: none fetched yet.';
    $t = min($times);
    return 'GitHub numbers as of ' . gmdate('M j, g:i a', $t) . ' UTC (' . rs_an_ago($t) . '). Asked again at most once an hour.';
}

// ---- Tables: drawn on the page and handed over as CSV --------------------------------------------
// $cols: key => [label, options]; options: 'n' (a number, right-aligned), 'bar' (with a bar sized
// to the column's biggest), 'csv' => false (page only), 'page' => false (CSV only), 'csv_label'
// (a longer name in the CSV), 'group' (a heading over this and the columns after it with the same).
// Options for the table: 'sortable', 'class', 'empty'.
// A cell is a value, or ['v' => value for CSV and sorting, 'h' => markup for the page].
// A row may carry '_attr' => [attribute => value] for its <tr>.

function rs_an_cell_value($c) { return is_array($c) ? ($c['v'] ?? '') : $c; }

function rs_an_table(string $key, array $cols, array $rows, array $opt = []): string {
    $empty = (string)($opt['empty'] ?? 'Nothing yet.');
    if (!$rows) return '<p class="muted">' . h($empty) . '</p>';
    $max = [];
    foreach ($cols as $k => $c) if (!empty($c[1]['bar'])) $max[$k] = max(1, ...array_map(fn($r) => (int)rs_an_cell_value($r[$k] ?? 0), $rows));
    $o = '<div class="table-wrap"><table class="stats an-table' . (!empty($opt['class']) ? ' ' . h($opt['class']) : '') . '" id="t-' . h($key) . '"' . (!empty($opt['sortable']) ? ' data-sortable' : '') . '><thead>';
    $shown = array_filter($cols, fn($c) => ($c[1]['page'] ?? true) !== false);
    if (array_filter($shown, fn($c) => isset($c[1]['group']))) {
        // The headings over groups of columns.
        $o .= '<tr class="an-groups">';
        $run = [];
        foreach ($shown as $c) {
            $g = (string)($c[1]['group'] ?? '');
            if ($run && $run[0] === $g) { $run[1]++; continue; }
            if ($run) $o .= '<th colspan="' . $run[1] . '"' . ($run[0] !== '' ? ' scope="colgroup"' : '') . '>' . h($run[0]) . '</th>';
            $run = [$g, 1];
        }
        if ($run) $o .= '<th colspan="' . $run[1] . '"' . ($run[0] !== '' ? ' scope="colgroup"' : '') . '>' . h($run[0]) . '</th>';
        $o .= '</tr>';
    }
    $o .= '<tr>';
    foreach ($cols as $k => $c) {
        if (($c[1]['page'] ?? true) === false) continue;
        $o .= '<th' . (!empty($c[1]['n']) ? ' class="n"' : '') . ' scope="col">' . h($c[0]) . '</th>';
    }
    $o .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $o .= '<tr';
        foreach ((array)($r['_attr'] ?? []) as $a => $v) $o .= ' ' . h((string)$a) . '="' . h((string)$v) . '"';
        $o .= '>';
        foreach ($cols as $k => $c) {
            if (($c[1]['page'] ?? true) === false) continue;
            $cell = $r[$k] ?? '';
            $v = rs_an_cell_value($cell);
            $html = is_array($cell) && isset($cell['h']) ? (string)$cell['h'] : (!empty($c[1]['n']) && is_int($v) ? number_format($v) : h((string)$v));
            // A bar up to 110px long (in px: a % of a table cell would push the number out of it).
            if (isset($max[$k])) $html = '<span class="ibar" style="--w:' . round(110 * (int)$v / $max[$k], 1) . 'px" aria-hidden="true"></span>' . $html;
            $cls = trim((!empty($c[1]['n']) ? 'n' : '') . ($v === 0 && !isset($max[$k]) && !(is_array($cell) && isset($cell['h'])) ? ' z' : ''));
            $o .= '<td' . ($cls !== '' ? ' class="' . $cls . '"' : '') . (is_int($v) || is_float($v) ? ' data-s="' . h((string)$v) . '"' : '') . '>' . $html . '</td>';
        }
        $o .= '</tr>';
    }
    return $o . '</tbody></table></div>';
}

function rs_an_csv(string $name, array $cols, array $rows): void {
    $file = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
    $keys = array_keys(array_filter($cols, fn($c) => ($c[1]['csv'] ?? true) !== false));
    fputcsv($out, array_map(fn($k) => (string)($cols[$k][1]['csv_label'] ?? $cols[$k][0]), $keys), ',', '"', '');
    foreach ($rows as $r) {
        $line = [];
        foreach ($keys as $k) {
            $v = rs_an_cell_value($r[$k] ?? '');
            if (is_bool($v)) $v = $v ? 'yes' : 'no';
            $v = (string)$v;
            // Never a formula when it's opened in a spreadsheet.
            if ($v !== '' && (strpbrk($v[0], "=+@\t\r") !== false || ($v[0] === '-' && !is_numeric($v)))) $v = "'" . $v;
            $line[] = $v;
        }
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}

// This page's address with some of its query changed (null drops one).
function rs_an_url(array $set): string {
    $q = array_merge($_GET, $set);
    foreach ($q as $k => $v) if ($v === null) unset($q[$k]);
    unset($q['export'], $q['gh']);
    if (isset($set['export'])) $q['export'] = $set['export'];
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? 'analytics.php')) . ($q ? '?' . http_build_query($q) : '');
}

// A chart's data as analytics.js draws it: series name => [day => value].
function rs_an_chart(array $series): array {
    $days = array_keys(reset($series) ?: []);
    $out = ['days' => $days, 'series' => []];
    foreach ($series as $name => $data) $out['series'][] = ['name' => (string)$name, 'values' => array_values($data)];
    return $out;
}

// The day-by-day table under a chart (newest first) and its CSV.
function rs_an_daily_rows(array $series): array {
    $days = array_reverse(array_keys(reset($series) ?: []));
    $rows = [];
    foreach ($days as $d) {
        $r = ['day' => ['v' => $d, 'h' => h(gmdate('D M j, Y', strtotime($d . ' 12:00:00 UTC')))]];
        foreach ($series as $name => $data) $r[$name] = (int)$data[$d];
        $rows[] = $r;
    }
    return $rows;
}

function rs_an_daily_cols(array $series): array {
    $cols = ['day' => ['Day', []]];
    foreach (array_keys($series) as $name) $cols[$name] = [(string)$name, ['n' => true]];
    return $cols;
}

function rs_an_fmt(int $v): string {
    return $v >= 1000000 ? round($v / 1000000, 1) . 'M' : ($v >= 100000 ? round($v / 1000) . 'K' : number_format($v));
}
