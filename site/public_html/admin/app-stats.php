<?php
// Recomp Store admin (2.3): one app's numbers. Installs, updates and removals day by day, the
// versions consoles have, GitHub's downloads per release, its beta builds with their downloads and
// what testers said, and its latest logs. Every table as CSV (?export=). From Analytics' Apps tab
// and the Stats link on a game's page.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_stats_tables();
rs_beta_tables();
rs_analytics_tables();
rs_inbox_table();
rs_inbox_category_column();

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id)) $id = '';
$apps = $id !== '' ? rs_an_apps() : [];
$a = $apps[$id] ?? null;
$R = rs_an_range();
$from = $R['from'];
$sFrom = gmdate('Y-m-d', time() - ($R['n'] - 1) * 86400);
$known = $a !== null || ($id !== '' && rs_an_int('SELECT COUNT(*) FROM app_installs WHERE app_id = ?', [$id]) + rs_an_int('SELECT COUNT(*) FROM app_events WHERE app_id = ?', [$id]) > 0);
if (!$known) {
    http_response_code(404);
    rs_head('App stats', true, ['assets/analytics.css']);
    echo '<section class="head-row"><div><a class="back" href="analytics.php?tab=apps">← Analytics</a><h1>No such app</h1><p class="muted">Nothing is known here about ' . ($id !== '' ? '<code>' . h($id) . '</code>' : 'that app') . '.</p></div></section>';
    rs_foot();
    exit;
}
$a ??= ['id' => $id, 'name' => $id, 'category' => 'other', 'icon' => '', 'repo' => '', 'asset' => '', 'mine' => false, 'flags' => ['Other source'], 'game' => null];
$g = $a['game'];
$verdicts = defined('RS_VERDICTS') ? RS_VERDICTS : ['works' => 'Works', 'crashes' => 'Crashes', 'slow' => 'Slow', 'other' => 'Other'];
$hasFeedback = rs_an_has('inbox', 'feedback');
$hasBuild = rs_an_has('inbox', 'build');
$hasLikes = rs_an_has('likes');
$T = [];
$charts = [];

// Day by day.
$ser = fn(string $kind) => rs_series('SELECT day, n FROM app_events WHERE app_id = ? AND kind = ? AND day >= ?', [$id, $kind, $sFrom], $R['n']);
$s = ['Installs' => $ser('install'), 'Updates' => $ser('update')];
$rem = ['Removals' => $ser('remove')];
$charts['events'] = rs_an_chart($s);
$charts['removals'] = rs_an_chart($rem);
$T['daily'] = ['Installs, updates and removals, day by day', rs_an_daily_cols($s + $rem), rs_an_daily_rows($s + $rem), []];

$k = [
    'now' => rs_an_int('SELECT COUNT(*) FROM app_installs WHERE app_id = ? AND removed_at IS NULL', [$id]),
    'ever' => rs_an_int('SELECT COUNT(*) FROM app_installs WHERE app_id = ?', [$id]),
    'install' => rs_an_int("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE app_id = ? AND kind = 'install' AND day >= ?", [$id, $from]),
    'update' => rs_an_int("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE app_id = ? AND kind = 'update' AND day >= ?", [$id, $from]),
    'remove' => rs_an_int("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE app_id = ? AND kind = 'remove' AND day >= ?", [$id, $from]),
    'beta' => rs_an_int('SELECT COUNT(*) FROM beta_downloads WHERE game_id = ? AND day >= ?', [$id, $from]),
    'likes' => $hasLikes ? rs_an_int('SELECT COUNT(*) FROM likes WHERE app_id = ?', [$id]) : 0,
    'logs' => rs_an_int("SELECT COUNT(*) FROM inbox WHERE kind = 'logs' AND app_id = ? AND created >= ?", [$id, $from]),
    'feedback' => $hasFeedback ? rs_an_int("SELECT COUNT(*) FROM inbox WHERE kind = 'logs' AND app_id = ? AND feedback <> '' AND created >= ?", [$id, $from]) : 0,
];

// Versions on consoles now.
$vers = rs_an_rows('SELECT version v, COUNT(*) n FROM app_installs WHERE app_id = ? AND removed_at IS NULL GROUP BY version', [$id]);
usort($vers, fn($x, $y) => version_compare((string)$y['v'], (string)$x['v']));
$vSum = max(1, array_sum(array_map(fn($v) => (int)$v['n'], $vers)));
$T['versions'] = ['Versions on consoles now', ['version' => ['Version', []], 'consoles' => ['Consoles', ['n' => true, 'bar' => true]], 'share' => ['Share', ['n' => true]]],
    array_map(fn($v) => ['version' => (string)$v['v'] !== '' ? (string)$v['v'] : ['v' => '', 'h' => '<span class="muted">Not reported</span>'],
        'consoles' => (int)$v['n'], 'share' => ['v' => round(100 * (int)$v['n'] / $vSum, 1), 'h' => round(100 * (int)$v['n'] / $vSum) . '%']], $vers),
    ['empty' => 'Not on any console right now.']];

// GitHub: each release's downloads of this app's own file.
$ghd = null;
$ghInfo = null;
if ($a['repo'] !== '') {
    $ghInfo = rs_an_gh($a['repo']);
    $ghd = rs_an_gh_downloads($ghInfo['releases'], $a['asset']);
    $rows = [];
    foreach ($ghd['releases'] as $r) {
        $rows[] = ['release' => $r['url'] !== '' ? ['v' => $r['tag'], 'h' => '<a href="' . h($r['url']) . '" target="_blank" rel="noopener">' . h($r['tag']) . '</a>' . ($r['pre'] ? ' <span class="tag mini">Pre-release</span>' : '')] : $r['tag'],
            'date' => ['v' => $r['date'], 'h' => $r['date'] !== '' ? h(gmdate('M j, Y', strtotime($r['date'] . ' 12:00 UTC'))) : ''], 'files' => ['v' => implode(' ', array_keys($r['files'])), 'h' => $r['files'] ? h(implode(', ', array_keys($r['files']))) : '<span class="muted">No matching file</span>'],
            'downloads' => $r['downloads']];
    }
    $T['releases'] = ['GitHub downloads by release', ['release' => ['Release', []], 'date' => ['Published', []], 'files' => ['Files counted', []], 'downloads' => ['Downloads', ['n' => true, 'bar' => true]]], $rows,
        ['empty' => $ghInfo['missing'] ? 'GitHub says ' . $a['repo'] . ' isn\'t there (or is private).' : ($ghInfo['fetched'] ? 'No releases on GitHub.' : 'No numbers from GitHub yet.')]];
    $last = array_reverse(array_slice($ghd['releases'], 0, 15));
    $charts['releases'] = ['type' => 'bars', 'labels' => array_map(fn($r) => $r['tag'], $last), 'series' => [['name' => 'Downloads', 'values' => array_map(fn($r) => $r['downloads'], $last)]]];
}

// Beta builds: downloads and what testers said, build by build. Downloads are counted per build
// from 2.3 on; before that only per day, so those days go to the build that was up then (about).
$builds = $g ? rs_beta_builds($g) : [];
if ($builds) {
    $exact = rs_an_has('beta_file_downloads') ? rs_an_pairs('SELECT file, COUNT(*) FROM beta_file_downloads WHERE game_id = ? GROUP BY file', [$id]) : [];
    $since = rs_an_has('beta_file_downloads') ? (string)(rs_an_rows('SELECT MIN(day) d FROM beta_file_downloads')[0]['d'] ?? '') : '';
    $days = rs_an_pairs('SELECT day, COUNT(*) FROM beta_downloads WHERE game_id = ?' . ($since !== '' ? ' AND day < ?' : '') . ' GROUP BY day', $since !== '' ? [$id, $since] : [$id]);
    $fb = [];
    if ($hasFeedback && $hasBuild) foreach (rs_an_rows("SELECT build, feedback, COUNT(*) n FROM inbox WHERE kind = 'logs' AND app_id = ? AND feedback <> '' GROUP BY build, feedback", [$id]) as $r) $fb[(string)$r['build']][(string)$r['feedback']] = (int)$r['n'];
    // Newest first (the build that's up now, then the ones before it). A build's days run from
    // when it went up to when the next one did; unknown when either date is.
    $rows = [];
    $until = '9999-12-31';
    foreach ($builds as $b) {
        $t = $b['at'] !== '' ? strtotime($b['at']) : false;
        $start = $t ? gmdate('Y-m-d', $t) : '';
        $about = 0;
        if ($start !== '' && $until !== '') foreach ($days as $d => $n) if ($d >= $start && $d < $until) $about += $n;
        $until = $start;
        $dl = ($exact[$b['file']] ?? 0) + $about;
        $r = ['build' => ['v' => $b['version'], 'h' => h($b['version'] ?: '?') . ($b['current'] ? ' <span class="tag beta mini">Now</span>' : '')],
            'file' => $b['file'], 'up' => ['v' => $b['at'], 'h' => $b['at'] !== '' ? h(gmdate('M j, Y', strtotime($b['at']) ?: 0)) : '<span class="muted">?</span>'],
            'downloads' => ['v' => $dl, 'h' => ($about ? '≈ ' : '') . number_format($dl)]];
        foreach ($verdicts as $v => $label) $r['f_' . $v] = $fb[$b['version']][$v] ?? 0;
        $rows[] = $r;
    }
    $cols = ['build' => ['Build', []], 'file' => ['File', []], 'up' => ['Uploaded', []], 'downloads' => ['Downloads', ['n' => true, 'bar' => true]]];
    if ($hasFeedback && $hasBuild) foreach ($verdicts as $v => $label) $cols['f_' . $v] = [$label, ['n' => true]];
    $T['builds'] = ['Beta builds', $cols, $rows, []];
    $codes = rs_an_rows('SELECT COUNT(*) n, SUM(install_id IS NOT NULL) used FROM beta_codes WHERE game_id = ? AND revoked = 0', [$id])[0] ?? ['n' => 0, 'used' => 0];
}

// Latest logs.
$logCols = ['when' => ['When', []], 'category' => ['Category', []]];
if ($hasFeedback) $logCols['feedback'] = ['Feedback', []];
$logCols += ['version' => ['Version', []], 'store' => ['Store', []], 'message' => ['Message', []]];
$rows = [];
foreach (rs_an_rows("SELECT id, created, category, " . ($hasFeedback ? 'feedback' : "''") . " feedback, " . ($hasBuild ? 'build' : "''") . " build, version, name, body, status FROM inbox WHERE kind = 'logs' AND app_id = ? ORDER BY created DESC, id DESC LIMIT 25", [$id]) as $r) {
    $cat = (string)$r['category'];
    $line = trim((string)strtok((string)$r['body'], "\n"));
    $rows[] = ['when' => ['v' => (string)$r['created'], 'h' => '<a href="inbox.php?show=' . ($r['status'] === 'done' ? 'done' : 'logs') . '#m' . (int)$r['id'] . '">' . h(date('M j, g:i a', strtotime((string)$r['created']))) . '</a>'],
        'category' => ['v' => $cat, 'h' => $cat !== '' ? '<span class="tag cat-' . h($cat) . '">' . h(RS_LOG_CATEGORIES[$cat] ?? $cat) . '</span>' : '<span class="muted">–</span>'],
        'feedback' => ['v' => (string)$r['feedback'], 'h' => (string)$r['feedback'] !== '' ? '<span class="tag fb-' . h((string)$r['feedback']) . '">' . h($verdicts[(string)$r['feedback']] ?? (string)$r['feedback']) . '</span>' : '<span class="muted">–</span>'],
        'version' => (string)$r['build'] !== '' ? (string)$r['build'] : (string)$r['version'],
        'store' => preg_replace('/^Recomp Store /', '', (string)$r['name']),
        'message' => ['v' => (string)$r['body'], 'h' => $line !== '' ? '<span class="log-line" title="' . h(mb_substr((string)$r['body'], 0, 400)) . '">' . h($line) . '</span>' : '<span class="muted">No message</span>']];
}
$T['logs'] = ['Latest logs', $logCols, $rows, ['empty' => 'No logs sent for this app yet.']];

if (isset($_GET['export'])) {
    $key = (string)$_GET['export'];
    if (!isset($T[$key])) { http_response_code(404); exit('No such table.'); }
    rs_an_csv('recompstore-' . $id . '-' . $key . '-' . ($R['days'] ? $R['days'] . 'd' : 'all') . '-' . gmdate('Y-m-d'), $T[$key][1], $T[$key][2]);
}

$table = fn(string $key) => rs_an_table($key, $T[$key][1], $T[$key][2], $T[$key][3]);
$head = fn(string $title, string $key, string $extra = '') => '<div class="an-head"><h2>' . h($title) . '</h2>' . $extra . '<a class="an-csv" href="' . h(rs_an_url(['export' => $key])) . '" download>CSV</a></div>';
$kpi = fn(string $label, int $v, string $small = '') => '<div class="kpi"><span>' . h($label) . '</span><strong>' . rs_an_fmt($v) . '</strong>' . ($small !== '' ? '<small>' . $small . '</small>' : '') . '</div>';
$stale = $ghInfo && time() - $ghInfo['fetched'] >= 3600;

rs_head($a['name'] . ' · Stats', true, ['assets/analytics.css']);
?>
<section class="head-row">
  <div>
    <a class="back" href="analytics.php?tab=apps<?= $R['days'] !== 30 ? '&amp;days=' . $R['days'] : '' ?>">← Analytics</a>
    <div class="app-title">
      <?php if ($a['icon'] !== ''): ?><img src="<?= h($a['icon']) ?>" alt=""><?php else: ?><span class="icon"><?= h(mb_substr($a['name'], 0, 2)) ?></span><?php endif; ?>
      <div>
        <h1><?= h($a['name']) ?></h1>
        <p><span class="muted"><?= h($id) ?></span><span class="tag"><?= h((RS_CATEGORIES + ['other' => 'Other source'])[$a['category']] ?? $a['category']) ?></span><?php foreach ($a['flags'] as $f): if ($f === 'Other source') continue; ?><span class="tag<?= $f === 'Beta' ? ' beta' : ($f === 'Testers only' ? ' test' : '') ?>"><?= h($f) ?></span><?php endforeach; ?></p>
      </div>
    </div>
  </div>
  <div class="actions">
    <?php if ($a['mine']): ?><a class="btn" href="edit.php?id=<?= h(rawurlencode($id)) ?>">Edit</a><?php endif; ?>
    <?php if ($a['repo'] !== ''): ?><a class="btn" href="https://github.com/<?= h($a['repo']) ?>/releases" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
    <?php if ($hasFeedback && $builds && is_file(__DIR__ . '/feedback.php')): ?><a class="btn" href="feedback.php?app=<?= h(rawurlencode($id)) ?>">Feedback</a><?php endif; ?>
  </div>
</section>
<div class="an-bar">
  <nav class="chips" aria-label="Range">
    <?php foreach (RS_AN_RANGES as $d => $label): ?>
    <a class="chip<?= $R['days'] === $d ? ' on' : '' ?>" href="<?= h(rs_an_url(['days' => $d === 30 ? null : $d])) ?>"<?= $R['days'] === $d ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($ghInfo): ?><p class="muted an-asof"<?= $stale ? ' data-gh-refresh="' . h('analytics.php?gh=refresh&id=' . rawurlencode($id) . '&t=' . rawurlencode(rs_csrf())) . '"' : '' ?>><?= h(rs_an_asof([$ghInfo['fetched']])) ?></p><?php endif; ?>
</div>
<div class="kpis k4">
  <?= $kpi('On consoles now', $k['now'], rs_an_fmt($k['ever']) . ' ever installed') ?>
  <?= $kpi('Installs', $k['install'], h($R['label'])) ?>
  <?= $kpi('Updates', $k['update'], rs_an_fmt($k['remove']) . ' removed') ?>
  <?php if ($ghd && $ghd['releases']): ?><?= $kpi('GitHub downloads', $ghd['total'], $ghd['latest_tag'] !== '' ? h($ghd['latest_tag']) . ': ' . rs_an_fmt($ghd['latest']) : 'All time') ?><?php endif; ?>
  <?php if ($ghInfo && $ghInfo['stars'] !== null && !$ghInfo['missing']): ?><?= $kpi('Stars', (int)$ghInfo['stars'], 'On GitHub') ?><?php endif; ?>
  <?php if ($builds || $k['beta']): ?><?= $kpi('Beta downloads', $k['beta'], h($R['label']) . (isset($codes) ? ' · ' . (int)$codes['used'] . ' of ' . (int)$codes['n'] . ' codes used' : '')) ?><?php endif; ?>
  <?php if ($hasLikes): ?><?= $kpi('Likes', $k['likes'], 'All time') ?><?php endif; ?>
  <?= $kpi('Logs sent', $k['logs'], $hasFeedback ? rs_an_fmt($k['feedback']) . ' with feedback' : h($R['label'])) ?>
</div>
<section class="card chart-card">
  <?= $head('Installs and updates', 'daily') ?>
  <div class="chart" data-chart="events" tabindex="0" role="img" aria-label="Installs and updates of <?= h($a['name']) ?>, per day. Use the arrow keys to read each day."></div>
  <details><summary>Show as a table</summary><?= $table('daily') ?></details>
</section>
<div class="grid2 analytics-pair">
  <section class="card chart-card">
    <div class="an-head"><h2>Removals</h2></div>
    <div class="chart" data-chart="removals" tabindex="0" role="img" aria-label="Removals of <?= h($a['name']) ?>, per day. Use the arrow keys to read each day."></div>
  </section>
  <section class="card"><?= $head('Versions in use', 'versions') ?><?= $table('versions') ?></section>
</div>
<?php if (isset($T['releases'])): ?>
<section class="card chart-card">
  <?= $head('GitHub downloads by release', 'releases', '<a class="muted an-repo" href="https://github.com/' . h($a['repo']) . '/releases" target="_blank" rel="noopener">' . h($a['repo']) . '</a>') ?>
  <?php if ($charts['releases']['labels']): ?><div class="chart" data-chart="releases" tabindex="0" role="img" aria-label="Downloads of each release. Use the arrow keys to read each release."></div><?php endif; ?>
  <?php if ($T['releases'][2]): ?><details<?= count($T['releases'][2]) <= 6 ? ' open' : '' ?>><summary>Show as a table</summary><?= $table('releases') ?></details><?php else: ?><?= $table('releases') ?><?php endif; ?>
  <p class="muted small-note"><?= $ghd && !$ghd['all_files'] ? 'Only files named like ' . h($a['asset']) . ' count.' : 'Every file of each release counts.' ?> GitHub's own counts, all time, for its 30 newest releases.</p>
</section>
<?php endif; ?>
<?php if (isset($T['builds'])): ?>
<section class="card">
  <?= $head('Beta builds', 'builds') ?>
  <?= $table('builds') ?>
  <p class="muted small-note">Downloads from this site, one per console a day; Cloudflare R2's aren't counted. ≈: from before builds were counted one by one, so the days a build was up count for it.<?= $hasFeedback && $hasBuild ? ' Feedback is what testers sent from the beta\'s page for that build.' : '' ?></p>
</section>
<?php endif; ?>
<section class="card"><?= $head('Latest logs', 'logs', '<a class="muted" href="inbox.php?show=logs">Inbox</a>') ?><?= $table('logs') ?></section>
<script>window.RS_CHARTS = <?= json_encode((object)$charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/analytics.js']);
