<?php
// RecompStore admin: the Dashboard, where logging in lands.
// (4.3) Made for the whole team: what needs doing first (for your role and your stores), then the
// numbers, shortcuts and the latest changes. Owners and anyone on both stores can look at PC, PS5
// or both at once.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/lstats.php';
require_once __DIR__ . '/inc/maint.php';
rs_require_login();
rs_stats_tables();
rs_link_tables();
rs_beta_tables();
rs_analytics_tables();
rs_inbox_table();
rs_log_table();

$me = rs_me();
$mine = $me['stores_list'];
$both = count($mine) > 1;
// Which store to look at: ?store=pc|ps5|both, remembered for the session.
$pick = (string)($_GET['store'] ?? ($_SESSION['dash_store'] ?? 'both'));
if (!in_array($pick, ['pc', 'ps5', 'both'], true) || ($pick === 'both' && !$both) || ($pick !== 'both' && !in_array($pick, $mine, true))) $pick = $both ? 'both' : $mine[0];
$_SESSION['dash_store'] = $pick;
$showPc = $pick !== 'ps5';
$showPs5 = $pick !== 'pc';
$mgr = rs_role_at_least('manager');
$owner = rs_is_owner();
$safe = function (callable $f, $fallback = 0) { try { return $f(); } catch (Throwable $e) { return $fallback; } };
$today = gmdate('Y-m-d');
$yday = gmdate('Y-m-d', time() - 86400);
$since14 = gmdate('Y-m-d', time() - 13 * 86400);

// ---- What needs doing ----------------------------------------------------------------------------
// Each task: count, title, what it means, where to go, which store ('' = both), and who sees it.
$open = $safe(fn() => rs_an_pairs("SELECT kind, COUNT(*) FROM inbox WHERE status = 'new' GROUP BY kind"), []);
$subs = $safe(function () {
    $n = ['pc' => 0, 'ps5' => 0];
    foreach (rs_db()->query("SELECT data FROM inbox WHERE kind = 'submit' AND status = 'new'") as $r) {
        $d = json_decode((string)$r['data'], true);
        $n[is_array($d) && ($d['platform'] ?? '') === 'pc' ? 'pc' : 'ps5']++;
    }
    return $n;
}, ['pc' => 0, 'ps5' => 0]);
$tasks = [];
$task = function (int $n, string $one, string $many, string $note, string $href, string $store = '', string $role = 'curator') use (&$tasks) {
    $tasks[] = compact('n', 'note', 'href', 'store', 'role') + ['label' => $n === 1 ? $one : $many];
};
if ($owner && rs_sign_on()) $task(rs_sign_waiting(), 'Thing to sign', 'Things to sign', 'In RecompStore Signer on your PC. Until then they reach no one.', 'settings.php#signer', '', 'owner');
// PC
$pcFound = $safe(fn() => count(rs_pc_found('new')));
// (4.6) Coming soon watch: releases, betas and new repos for the recomps on Coming soon.
$soonNews = $showPc && rs_can_page('pc.php') ? $safe(function () { require_once __DIR__ . '/inc/pc.php'; return rs_pc_soon_news(); }, []) : [];
$soonAt = $soonNews || ($showPc && rs_can_page('pc.php')) ? $safe(fn() => rs_pc_soon_watch_state()['at'], 0) : 0;
$soonCount = $showPc && rs_can_page('pc.php') ? $safe(fn() => count(array_filter(rs_pc_apps(), fn($a) => !empty($a['soon']))), 0) : 0;
$task(count($soonNews), 'Coming soon update', 'Coming soon updates', 'A recomp on Coming soon put out a release, a beta or a repo.', 'pc.php?show=soon', 'pc');
$pcPub = $safe(fn() => (string)rs_db()->query("SELECT MAX(at) FROM admin_log WHERE action = 'publish' AND target = 'pc-catalog'")->fetchColumn(), '');
// Changed since Publish PC: what the catalog would say now against what's live, on the fields a
// curator changes (not downloads or stars, which GitHub moves all day). Worked out every 5 minutes.
$pcChanged = !$showPc || !rs_can_page('pc.php') ? 0 : $safe(function () {
    $c = function_exists('rs_kv_get') ? rs_kv_get('dash_pc_changed') : null;
    if ($c && $c['age'] < 300) return (int)$c['v'];
    $keys = array_flip(['name', 'game', 'blurb', 'adds', 'description', 'needs', 'credits', 'icon', 'cover', 'hero', 'logo', 'assets', 'tags', 'genre', 'tag', 'soon', 'featured', 'boot', 'launcher', 'copy_ids', 'setup_required', 'installer', 'setup_steps', 'status', 'status_note', 'links', 'dev', 'report_url', 'media', 'features']);
    $fp = function (array $e) use ($keys) { $x = array_intersect_key($e, $keys); ksort($x); return md5((string)json_encode($x)); };
    $live = [];
    foreach ((json_decode((string)@file_get_contents(RS_PC_CATALOG), true)['apps'] ?? []) as $e) $live[(string)$e['id']] = $fp($e);
    $n = 0; $seen = [];
    foreach (rs_pc_catalog()['apps'] as $e) { $seen[$e['id']] = 1; if (($live[$e['id']] ?? '') !== $fp($e)) $n++; }
    $n += count(array_diff_key($live, $seen));
    if (function_exists('rs_kv_put')) rs_kv_put('dash_pc_changed', (string)$n);
    return $n;
});
$pcReports = $safe(fn() => (int)rs_db()->query("SELECT COUNT(*) FROM play_reports WHERE kind = 'pc' AND hidden = 0 AND flags > 0")->fetchColumn());
$pcHeld = $safe(fn() => count(array_filter(rs_pc_apps(), fn($x) => !empty($x['pending']['tag']))));
$task($pcFound, 'Recomp found on GitHub', 'Recomps found on GitHub', 'New ones the finder spotted. Add the good ones, skip the rest.', 'pc.php?view=found', 'pc');
$task($pcChanged, 'Recomp changed, not published', 'Recomps changed, not published', 'Players see them after Publish PC' . ($pcPub !== '' ? ' (last ' . rs_date('M j, g:i a', (int)strtotime($pcPub)) . ')' : '') . '.', 'pc.php', 'pc');
$task($subs['pc'], 'PC submission', 'PC submissions', 'Recomps developers sent in.', 'submissions.php' . ($both ? '?pf=pc' : ''), 'pc');
$task($pcHeld, 'Update on hold', 'Updates on hold', 'New releases waiting for their virus check or for you.', 'security.php', 'pc');
$task($pcReports, 'Flagged player report', 'Flagged player reports', 'Other players flagged these. Hide them or keep them.', 'pc.php?view=reports', 'pc');
// PS5
$published = (string)rs_setting('published_at', '');
$psChanged = $safe(fn() => $published !== '' ? rs_an_int('SELECT COUNT(*) FROM games WHERE UNIX_TIMESTAMP(updated) > ?', [(int)strtotime($published)]) : rs_an_int('SELECT COUNT(*) FROM games'));
$health = $showPs5 && rs_has_store('ps5') ? $safe(function () { rs_health_maybe(); return rs_health_summary(); }, ['bad' => 0, 'warn' => 0]) : ['bad' => 0, 'warn' => 0];
$psFound = $showPs5 && rs_has_store('ps5') ? $safe(fn() => count(rs_found_list()['new'])) : 0;
$task($psFound, 'PS5 app found', 'PS5 apps found', 'On GitHub and in other catalogs, not in the store yet.', 'health.php#found', 'ps5');
$task($psChanged, 'Game changed, not published', 'Games changed, not published', $published !== '' ? 'Last published ' . rs_date('M j, g:i a', (int)strtotime($published)) . '.' : 'Not published yet.', 'index.php', 'ps5');
$task($subs['ps5'], 'PS5 submission', 'PS5 submissions', 'Apps developers sent in for the store.', 'submissions.php' . ($both ? '?pf=ps5' : ''), 'ps5');
$task((int)$health['bad'] + (int)$health['warn'], 'Listing needs a look', 'Listings need a look', 'A renamed repo, a missing zip, a changed title ID or a dead link.', 'health.php', 'ps5');
// Both stores
$task($safe(fn() => rs_vt_need_count()), 'Download flagged', 'Downloads flagged', 'Flagged by several virus scanners. Look before it goes out.', 'security.php');
$task($safe(fn() => rs_dev_waiting()), 'Developer request', 'Developer requests', 'New developer accounts, page changes and claims to approve.', 'developers.php');
$task($safe(fn() => rs_dev_admin_unread()), 'Unread developer message', 'Unread developer messages', 'Replies stay between the team and the developer.', 'messages.php');
// Managers
$task((int)($open['bug'] ?? 0), 'Bug report', 'Bug reports', 'From the website.', 'inbox.php?show=bug', '', 'manager');
$task((int)($open['logs'] ?? 0), 'Log sent in', 'Logs sent in', 'From the launcher and consoles, grouped by what went wrong.', 'inbox.php?show=logs', '', 'manager');
$task((int)($open['message'] ?? 0) + (int)($open['source'] ?? 0) + (int)($open['copyright'] ?? 0), 'Inbox message', 'Inbox messages', 'Messages, reported sources and notices.', 'inbox.php', '', 'manager');
$tasks = array_values(array_filter($tasks, fn($t) => rs_role_at_least($t['role']) && rs_can_page($t['href'])
    && ($t['store'] === '' || ($t['store'] === 'pc' ? $showPc : $showPs5))));
usort($tasks, fn($a, $b) => ($b['n'] > 0) <=> ($a['n'] > 0));
$todo = count(array_filter($tasks, fn($t) => $t['n'] > 0));

// ---- Numbers (managers and owners) ---------------------------------------------------------------
$kpi = fn(string $label, int $v, string $small = '') => '<div class="kpi"><span>' . h($label) . '</span><strong>' . rs_an_fmt($v) . '</strong>' . ($small !== '' ? '<small>' . $small . '</small>' : '') . '</div>';
$vs = function (int $now, int $then): string {
    if ($now === $then) return 'Same as yesterday';
    return '<span class="' . ($now > $then ? 'up' : 'down') . '">' . ($now > $then ? '+' : '−') . number_format(abs($now - $then)) . '</span> on yesterday';
};
$charts = [];
$pcKpis = $psKpis = '';
if ($mgr) {
    if ($showPc && rs_has_store('pc')) {
        $ls = $safe(fn() => rs_ls_summary(), []);
        $live = $safe(fn() => rs_ls_live(), ['open' => 0, 'playing' => 0]);
        $pcKpis = $kpi('Open right now', (int)$live['open'], number_format((int)$live['playing']) . ' playing a game')
            . $kpi('Players today', (int)($ls['today'] ?? 0), number_format((int)($ls['new_today'] ?? 0)) . ' new')
            . $kpi('This week', (int)($ls['week'] ?? 0), number_format((int)($ls['month'] ?? 0)) . ' this month')
            . $kpi('Hours played today', (int)round((float)($ls['hours_today'] ?? 0)), '<a href="launcher-stats.php">Launcher stats</a>');
        if (!empty($ls['series'])) $charts['PC players'] = $ls['series'];
    }
    if ($showPs5 && rs_has_store('ps5')) {
        $two = fn(string $sql) => [$safe(fn() => rs_an_int($sql, [$today])), $safe(fn() => rs_an_int($sql, [$yday]))];
        $consoles = $two('SELECT COUNT(*) FROM console_days WHERE day = ?');
        $installs = $two("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE kind = 'install' AND day = ?");
        $newC = $two('SELECT COUNT(*) FROM beta_installs WHERE DATE(created) = ?');
        $betas = $two('SELECT COUNT(*) FROM beta_downloads WHERE day = ?');
        $psKpis = $kpi('Consoles today', $consoles[0], $vs($consoles[0], $consoles[1]))
            . $kpi('New consoles', $newC[0], $vs($newC[0], $newC[1]))
            . $kpi('Installs today', $installs[0], $vs($installs[0], $installs[1]))
            . $kpi('Beta downloads', $betas[0], 'Yesterday ' . number_format($betas[1]));
        $charts['PS5 consoles'] = $safe(fn() => rs_series('SELECT day, COUNT(*) FROM console_days WHERE day >= ? GROUP BY day', [$since14], 14), []);
    }
    $visitors = $safe(fn() => rs_an_int('SELECT COUNT(*) FROM site_visitors WHERE day = ?', [$today]));
}
$charts = array_filter($charts);
$chartData = $charts ? ['week' => rs_an_chart($charts)] : [];

// ---- The side column -----------------------------------------------------------------------------
$pcApps = $showPc && rs_has_store('pc') ? $safe(fn() => rs_pc_apps(), []) : [];
$pcListed = count(array_filter($pcApps, fn($a) => empty($a['hidden']) && empty($a['soon'])));
$pcSoon = count(array_filter($pcApps, fn($a) => empty($a['hidden']) && !empty($a['soon'])));
$pcHidden = count(array_filter($pcApps, fn($a) => !empty($a['hidden'])));
$psGames = $showPs5 && rs_has_store('ps5') ? $safe(fn() => rs_games(true), []) : [];
$psListed = count(array_filter($psGames, fn($g) => empty($g['hidden'])));
$psHidden = count($psGames) - $psListed;
$mt = $safe(fn() => rs_maint_state(), ['ps5' => ['on' => false], 'pc' => ['on' => false]]);
$acts = $safe(fn() => $mgr
    ? rs_an_rows('SELECT at, who, action, target, detail FROM admin_log ORDER BY at DESC, id DESC LIMIT 8')
    : rs_an_rows('SELECT at, who, action, target, detail FROM admin_log WHERE who = ? ORDER BY at DESC, id DESC LIMIT 8', [(string)$me['username']]), []);
$actNames = ['login' => 'Logged in', 'login-failed' => 'Failed login', 'publish' => 'Published', 'signed' => 'Signed', 'change' => 'Changed',
    'settings' => 'Settings', 'signer' => 'Signer', 'two-step' => 'Two-step login', 'discover' => 'Discover', 'team' => 'Team', 'password' => 'Password'];
$ago = function (string $at): string {
    $s = time() - (strtotime($at) ?: 0);
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    return rs_date('M j', strtotime($at) ?: 0);
};
// Shortcuts: the pages people use most, for this role and these stores.
$jump = array_filter([
    ['pc.php', 'PC recomps', 'pc'], ['pc.php?view=found', 'Found on GitHub (PC)', 'pc'], ['titles.php', 'Versions', 'pc'], ['launcher-beta.php', 'Launcher beta', 'pc'],
    ['index.php', 'PS5 games', 'ps5'], ['edit.php', 'Add a PS5 game', 'ps5'], ['health.php', 'Found on GitHub (PS5)', 'ps5'], ['discover.php', 'Discover page', 'ps5'],
    ['submissions.php', 'Submissions', ''], ['developers.php', 'Developers', ''], ['security.php', 'Security', ''], ['inbox.php', 'Inbox', ''],
    ['analytics.php', 'Analytics', ''], ['status.php', 'Status', ''], ['docs.php', 'Developer docs', ''],
], fn($j) => rs_can_page($j[0]) && ($j[2] === '' || ($j[2] === 'pc' ? $showPc : $showPs5)));
$hour = (int)rs_date('G');
$hello = $hour < 5 ? 'Up late' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));
$where = $pick === 'both' ? 'PC and PS5' : RS_STORES[$pick];
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';

rs_head('Dashboard', true, ['assets/analytics.css', 'assets/dashboard.css']);
?>
<section class="dash-hello">
  <div>
    <h1><?= h($hello) ?>, <?= h(rs_me_name()) ?></h1>
    <p class="muted"><?= $todo ? ($todo === 1 ? 'One thing needs' : $todo . ' things need') . ' you on ' . h($where) . '.' : 'All caught up on ' . h($where) . '. Nice.' ?></p>
  </div>
  <div class="dash-tools">
    <?php if ($both): ?>
    <nav class="seg-store" aria-label="Which store">
      <?php foreach (['both' => 'Both', 'pc' => 'PC', 'ps5' => 'PS5'] as $k => $n): ?><a href="dashboard.php?store=<?= $k ?>"<?= $pick === $k ? ' class="on" aria-current="true"' : '' ?>><?= $n ?></a><?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if ($showPc && rs_can_page('pc.php')): ?><form method="post" action="pc.php"><?= $csrf ?><input type="hidden" name="action" value="publish"><button class="primary" title="Put the PC catalog's changes live">Publish PC</button></form><?php endif; ?>
    <?php if ($showPs5 && rs_can_page('index.php')): ?><form method="post" action="index.php"><?= $csrf ?><input type="hidden" name="action" value="publish"><button class="<?= $showPc ? '' : 'primary' ?>" title="Put the PS5 store's changes live">Publish PS5</button></form><?php endif; ?>
  </div>
</section>


<div class="dash-cols">
<div class="dash-main">
  <section class="card">
    <div class="an-head"><h2>Needs you</h2><span class="muted small">Click one to go straight there.</span></div>
    <?php $busy = array_values(array_filter($tasks, fn($t) => $t['n'] > 0)); $clear = array_values(array_filter($tasks, fn($t) => !$t['n'])); ?>
    <?php if ($busy): ?>
    <div class="tasks<?= count($busy) % 2 ? ' odd' : '' ?>">
      <?php foreach ($busy as $t): ?>
      <a class="task" href="<?= h($t['href']) ?>">
        <span class="n"><?= number_format((int)$t['n']) ?></span>
        <span class="tx"><strong><?= h($t['label']) ?><?php if ($t['store'] !== '' && $pick === 'both'): ?> <em class="store-chip store-<?= h($t['store']) ?>"><?= h(RS_STORES[$t['store']]) ?></em><?php endif; ?></strong><small><?= h($t['note']) ?></small></span>
        <span class="go" aria-hidden="true">→</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="all-clear">Nothing waiting. Everything's checked, published and read.</p>
    <?php endif; ?>
    <?php if ($clear): ?>
    <div class="clear-row"><span class="muted small">All clear:</span>
      <?php foreach ($clear as $t): ?><a href="<?= h($t['href']) ?>" title="<?= h($t['note']) ?>"><?= h($t['label']) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($showPc && rs_can_page('pc.php')): // (4.6) ?>
  <section class="card soon-watch" id="soon">
    <div class="an-head"><h2>Coming soon watch</h2><span class="muted small"><?= $soonAt ? 'Checked ' . h(rs_date('M j, g:i a', $soonAt)) . ' · daily' : 'Checks once a day' ?> · <?= (int)$soonCount ?> on Coming soon</span></div>
    <?php if ($soonNews): ?>
    <ul class="soon-news">
      <?php foreach ($soonNews as $it): $kind = ['release' => 'Release', 'pre' => 'Beta', 'repo' => 'New repo'][$it['kind']] ?? 'News'; ?>
      <li>
        <span class="soon-kind k-<?= h($it['kind']) ?>"><?= $kind ?></span>
        <span class="soon-tx"><strong><?= h($it['name']) ?></strong>
          <small><?= $it['kind'] === 'repo' ? 'Possible project: ' . h($it['repo']) : h($it['tag']) . ' is out' ?><?= $it['date'] !== '' ? ' · ' . h(gmdate('M j', (int)strtotime($it['date'] . ' 12:00:00 UTC'))) : '' ?><?= $it['files'] ? ' · ' . h(implode(', ', $it['files'])) : '' ?></small></span>
        <span class="soon-go"><a class="btn" href="<?= h($it['url']) ?>" target="_blank" rel="noopener">Look</a><a class="btn" href="pc.php?edit=<?= h(rawurlencode((string)$it['id'])) ?>#edit">Edit</a>
          <form method="post" action="pc.php"><?= $csrf ?><input type="hidden" name="action" value="soon_seen"><input type="hidden" name="id" value="<?= h((string)$it['id']) ?>"><button>Dismiss</button></form></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">Nothing new from the recomps on Coming soon.</p><?php endif; ?>
    <div class="soon-tools">
      <form method="post" action="pc.php"><?= $csrf ?><input type="hidden" name="action" value="soon_check"><button>Check now</button></form>
      <?php if (count($soonNews) > 1): ?><form method="post" action="pc.php"><?= $csrf ?><input type="hidden" name="action" value="soon_seen"><input type="hidden" name="id" value="*"><button>Dismiss all</button></form><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php if ($pcKpis !== ''): ?>
  <section class="dash-block">
    <div class="an-head"><h2><span class="store-dot pc"></span>RecompLauncher</h2><a class="an-csv an-plain" href="launcher-stats.php">More</a></div>
    <div class="kpis k4"><?= $pcKpis ?></div>
  </section>
  <?php endif; ?>
  <?php if ($psKpis !== ''): ?>
  <section class="dash-block">
    <div class="an-head"><h2><span class="store-dot ps5"></span>RecompStore on PS5</h2><a class="an-csv an-plain" href="analytics.php">More</a></div>
    <div class="kpis k4"><?= $psKpis ?></div>
  </section>
  <?php endif; ?>
  <?php if ($chartData): ?>
  <section class="card chart-card">
    <div class="an-head"><h2>The last 14 days</h2><?php if (isset($visitors)): ?><span class="muted small"><?= number_format($visitors) ?> website visitors today</span><?php endif; ?></div>
    <div class="chart" data-chart="week" tabindex="0" role="img" aria-label="Players per day for the last 14 days. Use the arrow keys to read each day."></div>
  </section>
  <?php endif; ?>
  <?php if (!$mgr): ?>
  <section class="card">
    <h2>Shortcuts</h2>
    <div class="jump">
      <?php foreach ($jump as [$href, $label]): ?><a href="<?= h($href) ?>"><?= h($label) ?></a><?php endforeach; ?>
    </div>
  </section>
  <section class="card">
    <div class="an-head"><h2><?= $mgr ? 'Latest changes' : 'Your latest changes' ?></h2><?php if (rs_can_page('log.php')): ?><a class="an-csv an-plain" href="log.php">Change log</a><?php endif; ?></div>
    <?php if (!$acts): ?><p class="muted">Nothing yet.</p><?php else: ?>
    <ul class="acts">
      <?php foreach ($acts as $r): ?>
      <li><time datetime="<?= h(rs_date('c', strtotime((string)$r['at']) ?: 0)) ?>" title="<?= h((string)$r['at']) ?>"><?= h($ago((string)$r['at'])) ?></time>
        <span class="what"><?= h($actNames[(string)$r['action']] ?? ucfirst((string)$r['action'])) ?><?= (string)$r['target'] !== '' ? ': ' . h((string)$r['target']) : '' ?><small><?= h(trim(((string)$r['who'] !== '' && $mgr ? (string)$r['who'] . ' · ' : '') . mb_strimwidth((string)$r['detail'], 0, 90, '…'), ' ·')) ?></small></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>

<aside class="dash-side">
  <?php if (!$owner): ?>
  <section class="card role-card-dash">
    <div class="chips-row"><span class="role-chip role-<?= h((string)$me['role']) ?>"><?= h(RS_ROLES[$me['role']]) ?></span><?php foreach ($mine as $s): ?><span class="store-chip store-<?= h($s) ?>"><?= h(RS_STORES[$s]) ?></span><?php endforeach; ?></div>
    <p class="muted small"><?= h(RS_ROLE_ABOUT[$me['role']]) ?></p>
    <p class="small"><a href="account.php">My account</a></p>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>In the catalog</h2>
    <div class="cat-nums">
      <?php if ($pcApps): ?>
      <a href="pc.php"><span class="store-dot pc"></span><span><strong><?= number_format($pcListed) ?></strong> PC recomps</span><small><?= number_format($pcSoon) ?> coming soon · <?= number_format($pcHidden) ?> hidden</small></a>
      <?php endif; ?>
      <?php if ($psGames): ?>
      <a href="index.php"><span class="store-dot ps5"></span><span><strong><?= number_format($psListed) ?></strong> PS5 games and apps</span><small><?= number_format($psHidden) ?> hidden</small></a>
      <?php endif; ?>
    </div>
  </section>


  <section class="card">
    <div class="an-head"><h2>Sites</h2><a class="an-csv an-plain" href="status.php">Status</a></div>
    <ul class="site-state">
      <li><span class="dot<?= !empty($mt['pc']['on']) ? ' warn' : '' ?>"></span>recomplauncher.com<small><?= !empty($mt['pc']['on']) ? 'Maintenance mode' : 'Up' ?></small></li>
      <li><span class="dot<?= !empty($mt['ps5']['on']) ? ' warn' : '' ?>"></span>recompstore.com<small><?= !empty($mt['ps5']['on']) ? 'Maintenance mode' : 'Up' ?></small></li>
    </ul>
  </section>

  <?php if ($mgr): ?>
  <section class="card">
    <h2>Shortcuts</h2>
    <div class="jump">
      <?php foreach ($jump as [$href, $label]): ?><a href="<?= h($href) ?>"><?= h($label) ?></a><?php endforeach; ?>
    </div>
  </section>
  <section class="card">
    <div class="an-head"><h2><?= $mgr ? 'Latest changes' : 'Your latest changes' ?></h2><?php if (rs_can_page('log.php')): ?><a class="an-csv an-plain" href="log.php">Change log</a><?php endif; ?></div>
    <?php if (!$acts): ?><p class="muted">Nothing yet.</p><?php else: ?>
    <ul class="acts">
      <?php foreach ($acts as $r): ?>
      <li><time datetime="<?= h(rs_date('c', strtotime((string)$r['at']) ?: 0)) ?>" title="<?= h((string)$r['at']) ?>"><?= h($ago((string)$r['at'])) ?></time>
        <span class="what"><?= h($actNames[(string)$r['action']] ?? ucfirst((string)$r['action'])) ?><?= (string)$r['target'] !== '' ? ': ' . h((string)$r['target']) : '' ?><small><?= h(trim(((string)$r['who'] !== '' && $mgr ? (string)$r['who'] . ' · ' : '') . mb_strimwidth((string)$r['detail'], 0, 90, '…'), ' ·')) ?></small></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</aside>
</div>
<script>window.RS_CHARTS = <?= json_encode((object)$chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<style>
.soon-news{list-style:none;margin:10px 0 0;padding:0;display:grid;gap:8px}
.soon-news li{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px 12px;border:1px solid var(--line);border-radius:12px}
.soon-kind{font-size:12px;font-weight:700;padding:3px 9px;border-radius:999px;background:var(--panel-2,rgba(255,255,255,.06))}
.soon-kind.k-release{color:var(--ok,#4ade80)}.soon-kind.k-pre{color:var(--warn,#facc15)}.soon-kind.k-repo{color:var(--accent,#35d6f4)}
.soon-tx{display:flex;flex-direction:column;min-width:0}.soon-tx small{color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.soon-go{display:flex;gap:6px;align-items:center}.soon-go form,.soon-tools form{margin:0}
.soon-tools{display:flex;gap:8px;margin-top:12px}
@media (max-width:720px){.soon-news li{grid-template-columns:1fr}.soon-go{flex-wrap:wrap}}
</style>
<?php rs_foot(['assets/analytics.js']);
