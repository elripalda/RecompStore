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


// (4.8) The Overview shows at once; its numbers come from live.php every 10 seconds, and what needs
// doing (the slower checks) comes in from ?part=needs and ?part=soon right after.
$part = (string)($_GET['part'] ?? '');
if ($part === '') {
    $csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
    $mgr0 = rs_role_at_least('manager');
    rs_head('Overview', true, ['assets/dashboard.css']);
    ?>
<div id="ov" data-mgr="<?= $mgr0 ? 1 : 0 ?>" data-pc="<?= rs_has_store('pc') ? 1 : 0 ?>">
  <div class="ov-head">
    <h1>Overview</h1>
    <span class="muted small"><?= h(rs_date('l, M j · g:i a')) ?> Phoenix</span>
    <span class="ov-live" data-live><i></i><span>Live</span></span>
    <span style="margin-left:auto;display:flex;gap:8px">
      <?php if (rs_can_page('pc.php')): ?><form method="post" action="pc.php" style="margin:0"><?= $csrf ?><input type="hidden" name="action" value="publish"><button class="primary" title="Put the PC catalog's changes live">Publish PC</button></form><?php endif; ?>
      <?php if (rs_can_page('index.php')): ?><form method="post" action="index.php" style="margin:0"><?= $csrf ?><input type="hidden" name="action" value="publish"><button title="Put the PS5 store's changes live">Publish PS5</button></form><?php endif; ?>
    </span>
  </div>
  <div class="ov-kpis">
    <div class="ov-kpi"><span>In the launcher right now</span><strong data-k="open">–</strong><small data-k="playing_s">&nbsp;</small></div>
    <div class="ov-kpi"><span>New accounts today</span><strong data-k="acc_today">–</strong><small data-k="acc_yday_s">&nbsp;</small></div>
    <div class="ov-kpi"><span>Players today</span><strong data-k="players_today">–</strong><small data-k="players_yday_s">&nbsp;</small></div>
    <div class="ov-kpi"><span>Accounts</span><strong data-k="acc_total">–</strong><small data-k="acc_ok_s">&nbsp;</small></div>
  </div>
  <section class="card" data-part="needs"><div class="ov-head"><h2>Needs you</h2></div><p class="ov-wait">Checking…</p></section>
  <?php if ($mgr0): ?>
  <section class="card">
    <div class="ov-head"><h2>Day by day</h2><span class="muted small">New accounts, players in the launcher and deleted accounts</span>
      <span class="seg" data-range><button type="button" data-n="7">7 days</button><button type="button" data-n="30" class="on">30 days</button><button type="button" data-n="90">90 days</button></span></div>
    <div class="ov-chart" data-chart></div>
    <div class="ov-axis"><span data-axis-a></span><span data-axis-b></span></div>
    <div class="ov-legend"><span><b class="c-new"></b>New accounts</span><span><b class="c-play"></b>Players in the launcher</span><span><b class="c-del"></b>Deleted accounts (counted from Nov 12, 2026)</span><span data-sum class="muted"></span></div>
  </section>
  <?php endif; ?>
  <div class="ov-grid2">
    <section class="card"><div class="ov-head"><h2>Playing right now</h2></div><ul class="ov-list" data-list="now_games"><li class="ov-wait">…</li></ul></section>
    <section class="card"><div class="ov-head"><h2>Most played today</h2></div><ul class="ov-list" data-list="games"><li class="ov-wait">…</li></ul></section>
    <section class="card"><div class="ov-head"><h2>Graphics cards</h2><span class="muted small">last 7 days</span></div><ul class="ov-list" data-list="gpus" data-bars><li class="ov-wait">…</li></ul></section>
    <section class="card"><div class="ov-head"><h2>Systems and versions</h2><span class="muted small">last 7 days</span></div><ul class="ov-list" data-list="os" data-bars></ul><ul class="ov-list" data-list="versions" data-bars style="margin-top:10px"></ul></section>
  </div>
  <?php if (rs_can_page('pc.php')): ?><section class="card" data-part="soon"><div class="ov-head"><h2>Coming soon watch</h2></div><p class="ov-wait">Checking…</p></section><?php endif; ?>
</div>
<?php
    rs_foot();
    exit;
}

$me = rs_me();
$mine = $me['stores_list'];
$both = count($mine) > 1;
// Which store to look at: ?store=pc|ps5|both, remembered for the session.
$pick = (string)($_GET['store'] ?? 'both');
if (!in_array($pick, ['pc', 'ps5', 'both'], true) || ($pick === 'both' && !$both) || ($pick !== 'both' && !in_array($pick, $mine, true))) $pick = $both ? 'both' : $mine[0];
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
$task($subs['pc'], 'PC submission', 'PC submissions', 'Recomps developers sent in.', 'hub.php?store=pc', 'pc');
$task($pcHeld, 'Update on hold', 'Updates on hold', 'New releases waiting for their virus check or for you.', 'security.php', 'pc');
$task($pcReports, 'Flagged player report', 'Flagged player reports', 'Other players flagged these. Hide them or keep them.', 'pc.php?view=reports', 'pc');
// PS5
$published = (string)rs_setting('published_at', '');
$psChanged = $safe(fn() => $published !== '' ? rs_an_int('SELECT COUNT(*) FROM games WHERE UNIX_TIMESTAMP(updated) > ?', [(int)strtotime($published)]) : rs_an_int('SELECT COUNT(*) FROM games'));
$health = $showPs5 && rs_has_store('ps5') ? $safe(function () { rs_health_maybe(); return rs_health_summary(); }, ['bad' => 0, 'warn' => 0]) : ['bad' => 0, 'warn' => 0];
$psFound = $showPs5 && rs_has_store('ps5') ? $safe(fn() => count(rs_found_list()['new'])) : 0;
$task($psFound, 'PS5 app found', 'PS5 apps found', 'On GitHub and in other catalogs, not in the store yet.', 'health.php#found', 'ps5');
$task($psChanged, 'Game changed, not published', 'Games changed, not published', $published !== '' ? 'Last published ' . rs_date('M j, g:i a', (int)strtotime($published)) . '.' : 'Not published yet.', 'index.php', 'ps5');
$task($subs['ps5'], 'PS5 submission', 'PS5 submissions', 'Apps developers sent in for the store.', 'hub.php?store=ps5', 'ps5');
$task((int)$health['bad'] + (int)$health['warn'], 'Listing needs a look', 'Listings need a look', 'A renamed repo, a missing zip, a changed title ID or a dead link.', 'health.php', 'ps5');
// Both stores
// (4.8) Repo watch: a listing's repo taken down, deleted, made private or emptied of its releases.
$task($safe(function () { require_once __DIR__ . '/inc/repowatch.php'; return rs_watch_count(array_values(array_intersect(rs_me()['stores_list'] ?? [], ['pc', 'ps5']))); }), 'Repo needs a look', 'Repos need a look', 'Deleted, taken down or emptied of releases. Hide or re-point them.', 'hub.php?tab=watch');
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

// ---- (4.8) The parts the Overview loads after it shows ---------------------------------------------
header('Cache-Control: no-store');
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
if ($part === 'needs'):
    $busy = array_values(array_filter($tasks, fn($t) => $t['n'] > 0)); $clear = array_values(array_filter($tasks, fn($t) => !$t['n'])); ?>
<div class="ov-head"><h2>Needs you</h2><span class="muted small"><?= $todo ? $todo . ($todo === 1 ? ' thing' : ' things') : 'All caught up' ?></span></div>
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
<?php else: ?><p class="all-clear">Nothing waiting. Everything's checked, published and read.</p><?php endif; ?>
<?php if ($clear): ?><div class="clear-row"><span class="muted small">All clear:</span><?php foreach ($clear as $t): ?> <a href="<?= h($t['href']) ?>" title="<?= h($t['note']) ?>"><?= h($t['label']) ?></a><?php endforeach; ?></div><?php endif; ?>
<?php elseif ($part === 'soon' && rs_can_page('pc.php')): ?>
<div class="ov-head"><h2>Coming soon watch</h2><span class="muted small"><?= $soonAt ? 'Checked ' . h(rs_date('M j, g:i a', $soonAt)) . ' · daily' : 'Checks once a day' ?> · <?= (int)$soonCount ?> on Coming soon</span></div>
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
<?php endif;

