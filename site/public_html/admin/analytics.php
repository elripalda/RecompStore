<?php
// Recomp Store admin (2.3): Analytics. Five tabs: Overview (consoles, installs, updates, removals,
// beta downloads, visitors, store versions), Apps (every app, sortable, with GitHub's numbers),
// Downloads (the store's own releases and each app's), Website and Logs. A range of 7, 30 or 90
// days or all time, and every table as CSV (?export=). Counted by Recomp Store 2.0 and newer and by
// the website: no cookies, nothing that says who anyone is. GitHub's numbers come from the cache;
// the page asks for newer ones in the background, at most once an hour per repo.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_stats_tables();
rs_link_tables();
rs_beta_tables();
rs_analytics_tables();
rs_inbox_table();
rs_inbox_category_column();

// GitHub's numbers, asked for again after the page is shown (analytics.js). Not a change, so not
// a form: the session's token in the address, and the session let go so other pages don't wait.
if (($_GET['gh'] ?? '') === 'refresh') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!hash_equals(rs_csrf(), (string)($_GET['t'] ?? ''))) { http_response_code(400); echo '{"ok":false}'; exit; }
    session_write_close();
    @set_time_limit(150);
    // One app's repo (App stats), or the store's and every app's.
    $one = (string)($_GET['id'] ?? '');
    $repos = $one === '' ? [rs_an_store_repo()] : [];
    foreach (rs_an_apps() as $id => $a) if ($a['repo'] !== '' && ($one === '' || (string)$id === $one)) $repos[] = $a['repo'];
    echo json_encode(['ok' => true] + rs_an_gh_refresh($repos));
    exit;
}

const AN_TABS = ['overview' => 'Overview', 'apps' => 'Apps', 'downloads' => 'Downloads', 'website' => 'Website', 'logs' => 'Logs and feedback'];
$tab = (string)($_GET['tab'] ?? 'overview');
if (!isset(AN_TABS[$tab])) $tab = 'overview';
$R = rs_an_range();
$from = $R['from'];
$sFrom = gmdate('Y-m-d', time() - ($R['n'] - 1) * 86400); // the charts' first day
$day = fn(int $back) => gmdate('Y-m-d', time() - $back * 86400);
$series = fn(string $sql) => rs_series($sql, [$sFrom], $R['n']);

$T = [];      // key => [title, cols, rows, options]: every table on the tab, for the page and CSV
$charts = []; // key => what analytics.js draws
$stale = false; // some GitHub number is an hour old or older: ask again in the background
$asof = [];
$catNames = RS_CATEGORIES + ['other' => 'Other sources'];
$verdicts = defined('RS_VERDICTS') ? RS_VERDICTS : ['works' => 'Works', 'crashes' => 'Crashes', 'slow' => 'Slow', 'other' => 'Other'];
$hasFeedback = rs_an_has('inbox', 'feedback');
$hasBuild = rs_an_has('inbox', 'build');
$hasLikes = rs_an_has('likes');
$daily = function (string $key, string $title, array $s) use (&$T, &$charts) {
    $charts[$key] = rs_an_chart($s);
    $T[$key] = [$title . ', day by day', rs_an_daily_cols($s), rs_an_daily_rows($s), []];
};
$gh = function (string $repo) use (&$stale, &$asof): array {
    $g = rs_an_gh($repo);
    if (time() - $g['fetched'] >= 3600) $stale = true;
    if ($g['fetched']) $asof[] = $g['fetched'];
    return $g;
};
// An app's name, linked to its App stats page, its flags (Hidden, Beta...) and, under it, its ID.
$appLink = fn(string $id, string $name, array $flags = [], string $more = '') => '<a href="app-stats.php?id=' . h(rawurlencode($id)) . '&amp;days=' . $R['days'] . '">' . h($name) . '</a>'
    . implode('', array_map(fn($f) => ' <span class="tag mini">' . h($f) . '</span>', $flags))
    . ($name !== $id || $more !== '' ? '<small class="an-sub">' . h(implode(' · ', array_filter([$name !== $id ? $id : '', $more]))) . '</small>' : '');

// ---- Overview -----------------------------------------------------------------------------------
if ($tab === 'overview') {
    $k = [
        'today' => rs_an_int('SELECT COUNT(*) FROM console_days WHERE day = UTC_DATE()'),
        'a7' => rs_an_int('SELECT COUNT(DISTINCT install_id) FROM console_days WHERE day >= ?', [$day(6)]),
        'a30' => rs_an_int('SELECT COUNT(DISTINCT install_id) FROM console_days WHERE day >= ?', [$day(29)]),
        'range' => rs_an_int('SELECT COUNT(DISTINCT install_id) FROM console_days WHERE day >= ?', [$from]),
        'new' => rs_an_int('SELECT COUNT(*) FROM beta_installs WHERE created >= ?', [$from]),
        'ever' => rs_an_int('SELECT COUNT(*) FROM beta_installs'),
        'beta' => rs_an_int('SELECT COUNT(*) FROM beta_downloads WHERE day >= ?', [$from]),
        'visitors' => rs_an_int('SELECT COUNT(*) FROM site_visitors WHERE day >= ?', [$from]),
        'views' => rs_an_int('SELECT COALESCE(SUM(views), 0) FROM site_pages WHERE day >= ?', [$from]),
        'phones' => rs_an_int('SELECT COUNT(*) FROM link_devices WHERE install_id IS NOT NULL'),
    ];
    $ev = rs_an_pairs('SELECT kind, SUM(n) FROM app_events WHERE day >= ? GROUP BY kind', [$from]);
    $daily('consoles', 'Consoles', [
        'Consoles' => $series('SELECT day, COUNT(*) FROM console_days WHERE day >= ? GROUP BY day'),
        'New consoles' => $series('SELECT DATE(created), COUNT(*) FROM beta_installs WHERE created >= ? GROUP BY DATE(created)')]);
    $daily('installs', 'Installs and updates', [
        'Installs' => $series("SELECT day, SUM(n) FROM app_events WHERE kind = 'install' AND day >= ? GROUP BY day"),
        'Updates' => $series("SELECT day, SUM(n) FROM app_events WHERE kind = 'update' AND day >= ? GROUP BY day")]);
    $daily('removals', 'Removals', ['Removals' => $series("SELECT day, SUM(n) FROM app_events WHERE kind = 'remove' AND day >= ? GROUP BY day")]);
    $daily('betas', 'Beta downloads', ['Beta downloads' => $series('SELECT day, COUNT(*) FROM beta_downloads WHERE day >= ? GROUP BY day')]);
    $daily('visits', 'Website', [
        'Visitors' => $series('SELECT day, COUNT(*) FROM site_visitors WHERE day >= ? GROUP BY day'),
        'Page views' => $series('SELECT day, SUM(views) FROM site_pages WHERE day >= ? GROUP BY day')]);

    // Store versions: what each console said it runs the last time it opened the store.
    $vers = rs_an_rows('SELECT store_version v, SUM(last_seen >= ?) recent, COUNT(*) ever FROM beta_installs GROUP BY store_version', [$from]);
    usort($vers, fn($a, $b) => ($a['v'] === '') <=> ($b['v'] === '') ?: version_compare((string)$b['v'], (string)$a['v']));
    $vSum = max(1, array_sum(array_map(fn($v) => (int)$v['recent'], $vers)));
    $T['versions'] = ['Store versions', [
        'version' => ['Version', []], 'recent' => ['Consoles seen in ' . $R['short'], ['n' => true, 'bar' => true]],
        'share' => ['Share', ['n' => true]], 'ever' => ['All time', ['n' => true]]],
        array_map(fn($v) => ['version' => $v['v'] !== '' ? (string)$v['v'] : ['v' => 'not reported', 'h' => '<span class="muted">Not reported</span>'],
            'recent' => (int)$v['recent'], 'share' => ['v' => round(100 * (int)$v['recent'] / $vSum, 1), 'h' => round(100 * (int)$v['recent'] / $vSum) . '%'], 'ever' => (int)$v['ever']], $vers),
        ['empty' => 'No consoles yet.']];

    // Phones and PCs linked to a console, and what they asked their PS5 to do. Requests the PS5
    // has taken are kept for 7 days only, so that's as far back as they go.
    $phones = ['linked' => $k['phones'], 'seen7' => rs_an_int('SELECT COUNT(*) FROM link_devices WHERE install_id IS NOT NULL AND last_seen >= (NOW() - INTERVAL 7 DAY)')];
    $kindNames = ['install' => 'Install an app', 'source' => 'Add a source', 'delete' => 'Delete an app', 'move' => 'Move an app', 'cancel' => 'Cancel a download',
        'favorite' => 'Favorite', 'autoupdate' => 'Auto-update', 'text' => 'Type on the PS5'];
    $T['phones'] = ['Requests from phones and PCs, last 7 days', ['kind' => ['Request', []], 'n' => ['Sent', ['n' => true, 'bar' => true]], 'taken' => ['Picked up by the PS5', ['n' => true]]],
        array_map(fn($r) => ['kind' => $kindNames[(string)$r['kind']] ?? (string)$r['kind'], 'n' => (int)$r['n'], 'taken' => (int)$r['taken']],
            rs_an_rows('SELECT kind, COUNT(*) n, SUM(taken IS NOT NULL) taken FROM link_queue WHERE created >= (NOW() - INTERVAL 7 DAY) GROUP BY kind ORDER BY n DESC')),
        ['empty' => 'No requests in the last 7 days.']];
}

// ---- Apps ---------------------------------------------------------------------------------------
if ($tab === 'apps') {
    $apps = rs_an_apps();
    $now = rs_an_pairs('SELECT app_id, COUNT(*) FROM app_installs WHERE removed_at IS NULL GROUP BY app_id');
    $ever = rs_an_pairs('SELECT app_id, COUNT(*) FROM app_installs GROUP BY app_id');
    $ev = [];
    foreach (rs_an_rows('SELECT app_id, kind, SUM(n) n FROM app_events WHERE day >= ? GROUP BY app_id, kind', [$from]) as $r) $ev[(string)$r['app_id']][(string)$r['kind']] = (int)$r['n'];
    $beta = rs_an_pairs('SELECT game_id, COUNT(*) FROM beta_downloads WHERE day >= ? GROUP BY game_id', [$from]);
    $likes = $hasLikes ? rs_an_pairs('SELECT app_id, COUNT(*) FROM likes GROUP BY app_id') : [];
    $fb = $hasFeedback ? rs_an_pairs("SELECT app_id, COUNT(*) FROM inbox WHERE kind = 'logs' AND feedback <> '' AND created >= ? GROUP BY app_id", [$from]) : [];
    $logs = [];
    foreach (rs_an_rows("SELECT app_id, category, COUNT(*) n FROM inbox WHERE kind = 'logs' AND created >= ? GROUP BY app_id, category", [$from]) as $r) $logs[(string)$r['app_id']][(string)$r['category']] = (int)$r['n'];
    // Apps from other sources: only what the consoles said, by id.
    foreach (array_merge(array_keys($now), array_keys($ever), array_keys($ev), array_keys($logs)) as $id) {
        $id = (string)$id;
        if ($id === '' || $id === 'store' || isset($apps[$id])) continue;
        $apps[$id] = ['id' => $id, 'name' => $id, 'category' => 'other', 'icon' => '', 'repo' => '', 'asset' => '', 'mine' => false, 'flags' => ['Other source'], 'game' => null];
    }
    $inR = 'In ' . $R['short'];
    $cols = ['app' => ['App', ['group' => '']], 'id' => ['ID', ['page' => false]], 'category' => ['Category', ['page' => false]],
        'now' => ['Now', ['n' => true, 'group' => 'On consoles', 'csv_label' => 'On consoles now']], 'ever' => ['Ever', ['n' => true, 'group' => 'On consoles', 'csv_label' => 'Installs ever']],
        'install' => ['Installs', ['n' => true, 'group' => $inR, 'csv_label' => 'Installs (' . $R['short'] . ')']], 'update' => ['Updates', ['n' => true, 'group' => $inR, 'csv_label' => 'Updates (' . $R['short'] . ')']],
        'remove' => ['Removals', ['n' => true, 'group' => $inR, 'csv_label' => 'Removals (' . $R['short'] . ')']],
        'beta' => ['Beta', ['n' => true, 'group' => $inR, 'csv_label' => 'Beta downloads (' . $R['short'] . ')']]];
    if ($hasFeedback) $cols['feedback'] = ['Feedback', ['n' => true, 'group' => $inR, 'csv_label' => 'Feedback (' . $R['short'] . ')']];
    $cols['logs'] = ['Logs', ['n' => true, 'group' => $inR, 'csv_label' => 'Logs (' . $R['short'] . ')']];
    $cols += ['gh' => ['Downloads', ['n' => true, 'group' => 'GitHub', 'csv_label' => 'GitHub downloads']], 'gh_latest' => ['Latest', ['n' => true, 'group' => 'GitHub', 'csv_label' => 'Latest release downloads']],
        'gh_tag' => ['Latest release', ['page' => false]], 'stars' => ['Stars', ['n' => true, 'group' => 'GitHub']]];
    if ($hasLikes) $cols['likes'] = ['Likes', ['n' => true, 'group' => '']];
    foreach (RS_LOG_CATEGORIES as $c => $label) $cols['log_' . $c] = ['Logs: ' . $label, ['n' => true, 'page' => false]];
    $cols['log_none'] = ['Logs: no category', ['n' => true, 'page' => false]];
    $rows = [];
    foreach ($apps as $id => $a) {
        $id = (string)$id;
        if ($a['game'] && $a['game']['soon'] && !isset($ever[$id])) continue; // Coming soon: nothing to count yet
        $g = $a['repo'] !== '' ? $gh($a['repo']) : null;
        $d = $g ? rs_an_gh_downloads($g['releases'], $a['asset']) : null;
        $stars = $g['stars'] ?? null;
        $lg = $logs[$id] ?? [];
        $dash = ['v' => '', 'h' => '<span class="muted">–</span>'];
        $r = ['_attr' => ['data-cat' => $a['category'], 'data-search' => mb_strtolower($a['name'] . ' ' . $id)],
            'app' => ['v' => $a['name'], 'h' => $appLink($id, $a['name'], array_values(array_diff($a['flags'], ['Other source'])), $a['category'] !== 'other' ? ($catNames[$a['category']] ?? '') : 'Other source')], 'id' => $id,
            'category' => $catNames[$a['category']] ?? $a['category'],
            'now' => $now[$id] ?? 0, 'ever' => $ever[$id] ?? 0,
            'install' => $ev[$id]['install'] ?? 0, 'update' => $ev[$id]['update'] ?? 0, 'remove' => $ev[$id]['remove'] ?? 0,
            'gh' => $d && $d['releases'] ? $d['total'] : $dash,
            'gh_latest' => $d && $d['latest_tag'] !== '' ? ['v' => $d['latest'], 'h' => number_format($d['latest']) . ' <span class="muted">' . h($d['latest_tag']) . '</span>'] : $dash,
            'gh_tag' => $d['latest_tag'] ?? '',
            'stars' => $stars ?? $dash, 'beta' => $a['game'] && $a['game']['beta'] ? ($beta[$id] ?? 0) : (isset($beta[$id]) ? $beta[$id] : $dash),
            'likes' => $likes[$id] ?? 0, 'feedback' => $fb[$id] ?? 0];
        $total = array_sum($lg);
        $parts = [];
        foreach (RS_LOG_CATEGORIES as $c => $label) { $r['log_' . $c] = $lg[$c] ?? 0; if (!empty($lg[$c])) $parts[$lg[$c] . ' ' . mb_strtolower($label)] = $lg[$c]; }
        $r['log_none'] = $lg[''] ?? 0;
        // The two biggest categories under the number; all of them when pointed at.
        arsort($parts);
        $top = array_slice(array_keys($parts), 0, 2);
        $r['logs'] = $total ? ['v' => $total, 'h' => number_format($total) . ($parts ? '<small class="an-sub" title="' . h(implode(', ', array_keys($parts))) . '">' . h(implode(', ', $top) . (count($parts) > 2 ? ' +' . (count($parts) - 2) : '')) . '</small>' : '')] : 0;
        $rows[] = $r;
    }
    usort($rows, fn($x, $y) => [$y['now'], $y['ever'], $y['install']] <=> [$x['now'], $x['ever'], $x['install']] ?: strcasecmp((string)$x['app']['v'], (string)$y['app']['v']));
    $T['apps'] = ['Apps', $cols, $rows, ['sortable' => true, 'class' => 'an-wide', 'empty' => 'No apps yet.']];
}

// ---- Downloads ----------------------------------------------------------------------------------
if ($tab === 'downloads') {
    $storeRepo = rs_an_store_repo();
    $sg = $gh($storeRepo);
    $sd = rs_an_gh_downloads($sg['releases'], '');
    // The store's own files: its zip (PPSA92000.zip) and the installer payload (.elf), by their names.
    $zipName = 'PPSA92000.zip';
    $elfName = 'RecompStore-Installer.elf';
    foreach ($sd['releases'] as $r) foreach (array_keys($r['files']) as $f) {
        if (str_ends_with(strtolower($f), '.zip')) { $zipName = $f; break 2; }
    }
    foreach ($sd['releases'] as $r) foreach (array_keys($r['files']) as $f) {
        if (str_ends_with(strtolower($f), '.elf')) { $elfName = $f; break 2; }
    }
    $rel = [];
    $files = [];
    $tz = $te = 0;
    foreach ($sd['releases'] as $r) {
        $z = $e = $o = 0;
        foreach ($r['files'] as $f => $c) {
            $lf = strtolower($f);
            if (str_ends_with($lf, '.zip')) $z += $c; elseif (str_ends_with($lf, '.elf')) $e += $c; else $o += $c;
            $files[] = ['release' => $r['tag'], 'file' => $f, 'downloads' => $c];
        }
        $tz += $z; $te += $e;
        $tag = $r['url'] !== '' ? ['v' => $r['tag'], 'h' => '<a href="' . h($r['url']) . '" target="_blank" rel="noopener">' . h($r['tag']) . '</a>' . ($r['pre'] ? ' <span class="tag mini">Pre-release</span>' : '')] : $r['tag'];
        $rel[] = ['release' => $tag, 'date' => ['v' => $r['date'], 'h' => $r['date'] !== '' ? h(gmdate('M j, Y', strtotime($r['date'] . ' 12:00 UTC'))) : ''], 'zip' => $z, 'elf' => $e, 'other' => $o, 'total' => $r['downloads']];
    }
    $T['store'] = ['Recomp Store releases', ['release' => ['Release', []], 'date' => ['Published', []], 'zip' => [$zipName, ['n' => true]],
        'elf' => [$elfName, ['n' => true]], 'other' => ['Other files', ['n' => true]], 'total' => ['All files', ['n' => true, 'bar' => true]]], $rel,
        ['empty' => $sg['fetched'] ? 'GitHub lists no releases for ' . $storeRepo . '.' : 'No numbers from GitHub yet. They come in the background; reload in a minute.']];
    $T['store-files'] = ['Every file', ['release' => ['Release', []], 'file' => ['File', []], 'downloads' => ['Downloads', ['n' => true, 'bar' => true]]], $files, []];
    $last = array_reverse(array_slice($sd['releases'], 0, 12));
    $charts['store'] = ['type' => 'bars', 'labels' => array_map(fn($r) => $r['tag'], $last), 'series' => [
        ['name' => $zipName, 'values' => array_map(fn($r) => array_sum(array_filter($r['files'], fn($f) => str_ends_with(strtolower($f), '.zip'), ARRAY_FILTER_USE_KEY)), $last)],
        ['name' => $elfName, 'values' => array_map(fn($r) => array_sum(array_filter($r['files'], fn($f) => str_ends_with(strtolower($f), '.elf'), ARRAY_FILTER_USE_KEY)), $last)]]];
    $storeK = ['total' => $sd['total'], 'latest' => $sd['latest'], 'tag' => $sd['latest_tag'], 'zip' => $tz, 'elf' => $te, 'repo' => $storeRepo];

    $rows = [];
    foreach (rs_an_apps() as $id => $a) {
        if ($a['repo'] === '') continue;
        $g = $gh($a['repo']);
        $d = rs_an_gh_downloads($g['releases'], $a['asset']);
        $rows[] = ['app' => ['v' => $a['name'], 'h' => $appLink((string)$id, $a['name'], $a['flags'])],
            'repo' => ['v' => $a['repo'], 'h' => '<a href="https://github.com/' . h($a['repo']) . '/releases" target="_blank" rel="noopener">' . h($a['repo']) . '</a>' . ($g['missing'] ? ' <span class="tag mini">Not found</span>' : '')],
            'releases' => count($d['releases']), 'downloads' => $d['total'], 'tag' => $d['latest_tag'], 'latest' => $d['latest'],
            'stars' => $g['stars'] ?? 0,
            'asof' => ['v' => $g['fetched'] ? gmdate('Y-m-d H:i', $g['fetched']) . ' UTC' : '', 'h' => $g['fetched'] ? '<span class="nw" title="' . h(gmdate('M j, Y g:i a', $g['fetched'])) . ' UTC">' . h(rs_an_ago($g['fetched'])) . '</span>' : '<span class="muted">Not yet</span>']];
    }
    usort($rows, fn($x, $y) => $y['downloads'] <=> $x['downloads']);
    $T['github'] = ['Apps on GitHub', ['app' => ['App', []], 'repo' => ['Repo', []], 'releases' => ['Releases', ['n' => true]],
        'downloads' => ['Downloads', ['n' => true, 'bar' => true]], 'tag' => ['Latest release', []], 'latest' => ['Its downloads', ['n' => true]],
        'stars' => ['Stars', ['n' => true]], 'asof' => ['As of', []]], $rows, ['sortable' => true, 'empty' => 'No apps from GitHub.']];

    $rows = [];
    $codes = [];
    foreach (rs_an_rows('SELECT game_id, COUNT(*) n, SUM(install_id IS NOT NULL) used FROM beta_codes WHERE revoked = 0 GROUP BY game_id') as $c) $codes[(string)$c['game_id']] = $c;
    $inRange = rs_an_pairs('SELECT game_id, COUNT(*) FROM beta_downloads WHERE day >= ? GROUP BY game_id', [$from]);
    $allTime = rs_an_pairs('SELECT game_id, COUNT(*) FROM beta_downloads GROUP BY game_id');
    foreach (rs_games(true) as $g) {
        if (!$g['beta'] && !isset($allTime[$g['id']])) continue;
        $rows[] = ['beta' => ['v' => $g['name'], 'h' => $appLink($g['id'], $g['name'] ?: $g['id'], $g['beta'] ? [] : ['Out of beta'])],
            'build' => (string)($g['beta_version'] ?: $g['version']), 'range' => $inRange[$g['id']] ?? 0, 'all' => $allTime[$g['id']] ?? 0,
            'codes' => ['v' => (int)($codes[$g['id']]['used'] ?? 0), 'h' => (int)($codes[$g['id']]['used'] ?? 0) . ' of ' . (int)($codes[$g['id']]['n'] ?? 0)]];
    }
    $T['betas'] = ['Beta downloads from this site', ['beta' => ['Beta', []], 'build' => ['Build now', []], 'range' => ['In ' . $R['short'], ['n' => true, 'bar' => true]],
        'all' => ['All time', ['n' => true]], 'codes' => ['Codes used', ['n' => true]]], $rows, ['empty' => 'No betas.']];
}

// ---- Website ------------------------------------------------------------------------------------
if ($tab === 'website') {
    $k = ['visitors' => rs_an_int('SELECT COUNT(*) FROM site_visitors WHERE day >= ?', [$from]),
        'views' => rs_an_int('SELECT COALESCE(SUM(views), 0) FROM site_pages WHERE day >= ?', [$from]),
        'today' => rs_an_int('SELECT COUNT(*) FROM site_visitors WHERE day = UTC_DATE()'),
        'ps5' => rs_an_int("SELECT COUNT(*) FROM site_visitors WHERE day >= ? AND device = 'ps5'", [$from])];
    $daily('visits', 'Visitors and page views', [
        'Visitors' => $series('SELECT day, COUNT(*) FROM site_visitors WHERE day >= ? GROUP BY day'),
        'Page views' => $series('SELECT day, SUM(views) FROM site_pages WHERE day >= ? GROUP BY day')]);
    $share = fn(int $v, int $of) => ['v' => $of ? round(100 * $v / $of, 1) : 0, 'h' => ($of ? round(100 * $v / $of) : 0) . '%'];
    $T['pages'] = ['Top pages', ['page' => ['Page', []], 'views' => ['Views', ['n' => true, 'bar' => true]], 'share' => ['Share', ['n' => true]]],
        array_map(fn($p) => ['page' => ['v' => (string)$p['path'], 'h' => '<code>' . h((string)$p['path']) . '</code>'], 'views' => (int)$p['n'], 'share' => $share((int)$p['n'], $k['views'])],
            rs_an_rows('SELECT path, SUM(views) n FROM site_pages WHERE day >= ? GROUP BY path ORDER BY n DESC LIMIT 50', [$from])), ['empty' => 'No visits counted yet.']];
    $T['referrers'] = ['Where visitors came from', ['from' => ['From', []], 'visitors' => ['Visitors', ['n' => true, 'bar' => true]], 'share' => ['Share', ['n' => true]]],
        array_map(fn($r) => ['from' => $r['ref'] === 'direct' ? ['v' => 'direct', 'h' => 'Typed in, a bookmark or an app'] : (string)$r['ref'], 'visitors' => (int)$r['n'], 'share' => $share((int)$r['n'], $k['visitors'])],
            rs_an_rows('SELECT ref, COUNT(*) n FROM site_visitors WHERE day >= ? GROUP BY ref ORDER BY n DESC LIMIT 30', [$from])), ['empty' => 'No visits counted yet.']];
    $devNames = ['ps5' => 'PS5 browser', 'phone' => 'Phone', 'tablet' => 'Tablet', 'computer' => 'Computer'];
    $T['devices'] = ['Devices', ['device' => ['Device', []], 'visitors' => ['Visitors', ['n' => true, 'bar' => true]], 'share' => ['Share', ['n' => true]]],
        array_map(fn($d) => ['device' => $devNames[$d['device']] ?? (string)$d['device'], 'visitors' => (int)$d['n'], 'share' => $share((int)$d['n'], $k['visitors'])],
            rs_an_rows('SELECT device, COUNT(*) n FROM site_visitors WHERE day >= ? GROUP BY device ORDER BY n DESC', [$from])), ['empty' => 'No visits counted yet.']];
}

// ---- Logs and feedback --------------------------------------------------------------------------
if ($tab === 'logs') {
    $kinds = rs_an_pairs('SELECT kind, COUNT(*) FROM inbox WHERE created >= ? GROUP BY kind', [$from]);
    $open = rs_an_pairs("SELECT kind, COUNT(*) FROM inbox WHERE status = 'new' GROUP BY kind");
    $fbAll = $hasFeedback ? rs_an_pairs("SELECT feedback, COUNT(*) FROM inbox WHERE kind = 'logs' AND feedback <> '' AND created >= ? GROUP BY feedback", [$from]) : [];
    $s = ['Logs' => $series("SELECT DATE(created), COUNT(*) FROM inbox WHERE kind = 'logs' AND created >= ? GROUP BY DATE(created)")];
    if ($hasFeedback) $s['With feedback'] = $series("SELECT DATE(created), COUNT(*) FROM inbox WHERE kind = 'logs' AND feedback <> '' AND created >= ? GROUP BY DATE(created)");
    $charts['logs'] = rs_an_chart($s);

    // Day by day, each category and verdict: only days something came in, newest first.
    $byDay = [];
    foreach (rs_an_rows("SELECT DATE(created) d, category, " . ($hasFeedback ? 'feedback' : "''") . " fb, COUNT(*) n FROM inbox WHERE kind = 'logs' AND created >= ? GROUP BY d, category, fb", [$from]) as $r) {
        $d = (string)$r['d'];
        $byDay[$d] ??= ['all' => 0, 'cat' => [], 'fb' => []];
        $byDay[$d]['all'] += (int)$r['n'];
        $byDay[$d]['cat'][(string)$r['category']] = ($byDay[$d]['cat'][(string)$r['category']] ?? 0) + (int)$r['n'];
        if ((string)$r['fb'] !== '') $byDay[$d]['fb'][(string)$r['fb']] = ($byDay[$d]['fb'][(string)$r['fb']] ?? 0) + (int)$r['n'];
    }
    krsort($byDay);
    $cols = ['day' => ['Day', ['group' => '']], 'all' => ['Logs', ['n' => true, 'group' => '']]];
    foreach (RS_LOG_CATEGORIES as $c => $label) $cols['c_' . $c] = [$label, ['n' => true, 'group' => 'Category']];
    $cols['c_'] = ['None', ['n' => true, 'group' => 'Category', 'csv_label' => 'No category']];
    if ($hasFeedback) foreach ($verdicts as $v => $label) $cols['f_' . $v] = [$label, ['n' => true, 'group' => 'Tester feedback', 'csv_label' => 'Feedback: ' . $label]];
    $rows = [];
    foreach ($byDay as $d => $x) {
        $r = ['day' => ['v' => $d, 'h' => h(gmdate('D M j, Y', strtotime($d . ' 12:00:00 UTC')))], 'all' => $x['all']];
        foreach (RS_LOG_CATEGORIES as $c => $label) $r['c_' . $c] = $x['cat'][$c] ?? 0;
        $r['c_'] = $x['cat'][''] ?? 0;
        foreach ($verdicts as $v => $label) $r['f_' . $v] = $x['fb'][$v] ?? 0;
        $rows[] = $r;
    }
    $T['logs-daily'] = ['Logs by category, day by day', $cols, $rows, ['class' => 'an-wide', 'empty' => 'No logs in ' . $R['short'] . '.']];

    // Each app.
    $names = array_map(fn($a) => $a['name'], rs_an_apps());
    $byApp = [];
    foreach (rs_an_rows("SELECT app_id, category, " . ($hasFeedback ? 'feedback' : "''") . " fb, COUNT(*) n FROM inbox WHERE kind = 'logs' AND created >= ? GROUP BY app_id, category, fb", [$from]) as $r) {
        $a = (string)$r['app_id'];
        $byApp[$a] ??= ['all' => 0, 'cat' => [], 'fb' => []];
        $byApp[$a]['all'] += (int)$r['n'];
        $byApp[$a]['cat'][(string)$r['category']] = ($byApp[$a]['cat'][(string)$r['category']] ?? 0) + (int)$r['n'];
        if ((string)$r['fb'] !== '') $byApp[$a]['fb'][(string)$r['fb']] = ($byApp[$a]['fb'][(string)$r['fb']] ?? 0) + (int)$r['n'];
    }
    uasort($byApp, fn($x, $y) => $y['all'] <=> $x['all']);
    $cols = ['app' => ['App', ['group' => '']]] + array_slice($cols, 1, null, true);
    $rows = [];
    foreach ($byApp as $a => $x) {
        $r = ['app' => $a === 'store' ? 'Recomp Store' : ['v' => $names[$a] ?? $a, 'h' => $appLink($a, $names[$a] ?? $a)], 'all' => $x['all']];
        foreach (RS_LOG_CATEGORIES as $c => $label) $r['c_' . $c] = $x['cat'][$c] ?? 0;
        $r['c_'] = $x['cat'][''] ?? 0;
        foreach ($verdicts as $v => $label) $r['f_' . $v] = $x['fb'][$v] ?? 0;
        $rows[] = $r;
    }
    $T['logs-apps'] = ['Logs by app', $cols, $rows, ['class' => 'an-wide', 'sortable' => true, 'empty' => 'No logs in ' . $R['short'] . '.']];

    $kindNames = ['logs' => 'Logs', 'bug' => 'Bug reports', 'message' => 'Messages', 'submit' => 'Submissions', 'source' => 'Sources'];
    $rows = [];
    foreach ($kindNames as $kk => $label) $rows[] = ['kind' => ['v' => $label, 'h' => '<a href="inbox.php?show=' . h($kk) . '">' . h($label) . '</a>'], 'range' => $kinds[$kk] ?? 0, 'open' => $open[$kk] ?? 0];
    $T['inbox'] = ['Inbox', ['kind' => ['What', []], 'range' => ['In ' . $R['short'], ['n' => true, 'bar' => true]], 'open' => ['Not read yet', ['n' => true]]], $rows, []];
}

// CSV: any table on this tab, as it is on the page (all its rows).
if (isset($_GET['export'])) {
    $key = (string)$_GET['export'];
    if (!isset($T[$key])) { http_response_code(404); exit('No such table.'); }
    rs_an_csv('recompstore-' . $key . '-' . ($R['days'] ? $R['days'] . 'd' : 'all') . '-' . gmdate('Y-m-d'), $T[$key][1], $T[$key][2]);
}

$table = fn(string $key) => rs_an_table($key, $T[$key][1], $T[$key][2], $T[$key][3]);
$csv = fn(string $key) => '<a class="an-csv" href="' . h(rs_an_url(['export' => $key])) . '" download>CSV</a>';
$head = fn(string $title, string $key, string $extra = '') => '<div class="an-head"><h2>' . h($title) . '</h2>' . $extra . $csv($key) . '</div>';
// A chart, and its numbers as a table underneath.
$chartCard = function (string $key, string $title, string $note = '', string $class = '') use ($T, $table, $head) {
    echo '<section class="card chart-card' . ($class !== '' ? ' ' . $class : '') . '">' . $head($title, $key);
    echo '<div class="chart" data-chart="' . h($key) . '" tabindex="0" role="img" aria-label="' . h($title) . ', per day. Use the arrow keys to read each day."></div>';
    if ($note !== '') echo '<p class="muted small-note">' . h($note) . '</p>';
    echo '<details><summary>Show as a table</summary>' . $table($key) . '</details></section>';
};
$kpi = fn(string $label, int $v, string $small = '') => '<div class="kpi"><span>' . h($label) . '</span><strong>' . rs_an_fmt($v) . '</strong>' . ($small !== '' ? '<small>' . $small . '</small>' : '') . '</div>';

rs_head('Analytics', true, ['assets/analytics.css']);
?>
<section class="head-row">
  <div><h1>Analytics</h1><p class="muted">Counted by Recomp Store 2.0 and newer and by the website, in UTC days. No cookies and nothing that says who anyone is.</p></div>
</section>
<nav class="tabs an-tabs" aria-label="Analytics">
  <?php foreach (AN_TABS as $tk => $label): ?><a href="<?= h(rs_an_url(['tab' => $tk === 'overview' ? null : $tk])) ?>"<?= $tab === $tk ? ' aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
</nav>
<div class="an-bar">
  <nav class="chips" aria-label="Range">
    <?php foreach (RS_AN_RANGES as $d => $label): ?>
    <a class="chip<?= $R['days'] === $d ? ' on' : '' ?>" href="<?= h(rs_an_url(['days' => $d === 30 ? null : $d])) ?>"<?= $R['days'] === $d ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (in_array($tab, ['apps', 'downloads'], true)): ?>
  <p class="muted an-asof" data-gh-asof<?= $stale ? ' data-gh-refresh="' . h('analytics.php?gh=refresh&t=' . rawurlencode(rs_csrf())) . '"' : '' ?>><?= h(rs_an_asof($asof)) ?></p>
  <?php endif; ?>
</div>

<?php if ($tab === 'overview'): ?>
<div class="kpis k4">
  <?= $kpi('Consoles today', $k['today']) ?>
  <?= $kpi('In the last 7 days', $k['a7']) ?>
  <?= $kpi('In the last 30 days', $k['a30'], $R['days'] === 7 || $R['days'] === 30 ? '' : rs_an_fmt($k['range']) . ' in ' . h($R['short'])) ?>
  <?= $kpi('New consoles', $k['new'], h($R['label']) . ' · ' . rs_an_fmt($k['ever']) . ' ever') ?>
  <?= $kpi('Installs', (int)($ev['install'] ?? 0), rs_an_fmt((int)($ev['update'] ?? 0)) . ' updates · ' . rs_an_fmt((int)($ev['remove'] ?? 0)) . ' removed') ?>
  <?= $kpi('Beta downloads', $k['beta'], h($R['label'])) ?>
  <?= $kpi('Website visitors', $k['visitors'], rs_an_fmt($k['views']) . ' page views') ?>
  <?= $kpi('Linked phones and PCs', $k['phones'], 'All time') ?>
</div>
<?php $chartCard('consoles', 'Consoles that opened Recomp Store', 'New consoles: the day a console first opened the store.'); ?>
<?php $chartCard('installs', 'Installs and updates', 'An install is an app put on a console for the first time; an update, a newer version over one.'); ?>
<div class="grid2 analytics-pair">
  <?php $chartCard('removals', 'Removals'); ?>
  <?php $chartCard('betas', 'Beta downloads', 'From this site, one per console a day. Cloudflare R2 downloads aren\'t counted.'); ?>
</div>
<?php $chartCard('visits', 'Website visitors and page views'); ?>
<section class="card">
  <?= $head('Store versions in use', 'versions') ?>
  <p class="muted small-note">What each console ran the last time it opened the store.</p>
  <?= $table('versions') ?>
</section>
<section class="card">
  <?= $head('Phones and PCs', 'phones', '<span class="muted an-count">' . number_format($phones['linked']) . ' linked · ' . number_format($phones['seen7']) . ' used in the last 7 days</span>') ?>
  <?= $table('phones') ?>
</section>
<?php endif; ?>

<?php if ($tab === 'apps'): ?>
<section class="card">
  <?= $head('Apps', 'apps', '<span class="muted an-count" data-an-count>' . count($T['apps'][2]) . ' apps</span>') ?>
  <div class="an-tools">
    <label class="an-find"><span class="sr">Find</span><input type="search" placeholder="Find by name or ID" data-an-find="t-apps" autocomplete="off"></label>
    <label class="an-cat"><span class="sr">Category</span><select data-an-cat="t-apps"><option value="">Every category</option><?php foreach ($catNames as $c => $label): ?><option value="<?= h($c) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
  </div>
  <?= $table('apps') ?>
  <p class="muted" data-an-none hidden>Nothing matches.</p>
  <p class="muted small-note">Installs, updates, removals, beta downloads, feedback and logs are for <?= h($R['short']) ?>. On consoles now, installs ever, likes and GitHub's numbers are all time. Click a column to sort; click an app for its own page.</p>
</section>
<?php endif; ?>

<?php if ($tab === 'downloads'): ?>
<div class="kpis k4">
  <?= $kpi('Recomp Store downloads', $storeK['total'], 'Every release on GitHub') ?>
  <?= $kpi($storeK['tag'] !== '' ? 'Latest: ' . $storeK['tag'] : 'Latest release', $storeK['latest']) ?>
  <?= $kpi($zipName, $storeK['zip'], 'Every release') ?>
  <?= $kpi($elfName, $storeK['elf'], 'Every release') ?>
</div>
<section class="card chart-card">
  <?= $head('Recomp Store downloads by release', 'store', '<a class="muted an-repo" href="https://github.com/' . h($storeK['repo']) . '/releases" target="_blank" rel="noopener">' . h($storeK['repo']) . '</a>') ?>
  <?php if ($charts['store']['labels']): ?><div class="chart" data-chart="store" tabindex="0" role="img" aria-label="Downloads of each release, the zip and the installer. Use the arrow keys to read each release."></div><?php endif; ?>
  <?= $table('store') ?>
  <?php if ($T['store-files'][2]): ?><details><summary>Every file</summary><div class="an-head an-head-sub"><span></span><?= $csv('store-files') ?></div><?= $table('store-files') ?></details><?php endif; ?>
  <p class="muted small-note">GitHub's own counts, all time, for the 30 newest releases. The store updating itself downloads the zip; the .elf is for sending with a payload sender.</p>
</section>
<section class="card">
  <?= $head('Apps on GitHub', 'github') ?>
  <?= $table('github') ?>
  <p class="muted small-note">Only each app's own file counts (its name in the app's settings), across GitHub's 30 newest releases.</p>
</section>
<section class="card">
  <?= $head('Beta downloads from this site', 'betas') ?>
  <?= $table('betas') ?>
  <p class="muted small-note">One per console a day. Downloads from Cloudflare R2 go straight to Cloudflare and aren't counted here.</p>
</section>
<?php endif; ?>

<?php if ($tab === 'website'): ?>
<div class="kpis k4">
  <?= $kpi('Visitors', $k['visitors'], h($R['label'])) ?>
  <?= $kpi('Page views', $k['views'], $k['visitors'] ? round($k['views'] / $k['visitors'], 1) . ' a visitor' : '') ?>
  <?= $kpi('Visitors today', $k['today']) ?>
  <?= $kpi('On a PS5', $k['ps5'], $k['visitors'] ? round(100 * $k['ps5'] / $k['visitors']) . '% of visitors' : '') ?>
</div>
<?php $chartCard('visits', 'Visitors and page views', 'A visitor is counted once a day. Bots aren\'t counted.'); ?>
<section class="card"><?= $head('Top pages', 'pages') ?><?= $table('pages') ?></section>
<div class="grid2 analytics-pair">
  <section class="card"><?= $head('Where visitors came from', 'referrers') ?><?= $table('referrers') ?></section>
  <section class="card"><?= $head('Devices', 'devices') ?><?= $table('devices') ?></section>
</div>
<?php endif; ?>

<?php if ($tab === 'logs'): ?>
<div class="kpis k4">
  <?= $kpi('Logs sent', (int)($kinds['logs'] ?? 0), h($R['label']) . (($open['logs'] ?? 0) ? ' · <a href="inbox.php?show=logs">' . (int)$open['logs'] . ' not read</a>' : '')) ?>
  <?php if ($hasFeedback): ?><?= $kpi('Tester feedback', array_sum($fbAll), h(implode(' · ', array_map(fn($v, $label) => ($fbAll[$v] ?? 0) . ' ' . mb_strtolower($label), array_keys($verdicts), $verdicts)))) ?><?php endif; ?>
  <?= $kpi('Bug reports', (int)($kinds['bug'] ?? 0), h($R['label'])) ?>
  <?= $kpi('Messages and submissions', (int)($kinds['message'] ?? 0) + (int)($kinds['submit'] ?? 0), (int)($kinds['submit'] ?? 0) . ' submissions') ?>
</div>
<section class="card chart-card">
  <?= $head('Logs sent, day by day', 'logs-daily') ?>
  <div class="chart" data-chart="logs" tabindex="0" role="img" aria-label="Logs sent per day<?= $hasFeedback ? ', and how many came with feedback' : '' ?>. Use the arrow keys to read each day."></div>
  <details><summary>By category, day by day</summary><?= $table('logs-daily') ?></details>
</section>
<section class="card"><?= $head('Logs by app', 'logs-apps') ?><?= $table('logs-apps') ?>
  <?php if ($hasFeedback && is_file(__DIR__ . '/feedback.php')): ?><p class="muted small-note">What testers said about each build: <a href="feedback.php">Feedback</a>.</p><?php endif; ?></section>
<section class="card"><?= $head('Inbox', 'inbox') ?><?= $table('inbox') ?></section>
<?php endif; ?>

<script>window.RS_CHARTS = <?= json_encode((object)$charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/analytics.js']);
