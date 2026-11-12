<?php
// (4.7) Auto-add: paste links, the server makes the listing and checks everything, you look and Publish.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
require_once __DIR__ . '/inc/pc.php';
if (is_file(__DIR__ . '/inc/vt.php')) require_once __DIR__ . '/inc/vt.php';
require_once __DIR__ . '/inc/autoadd.php';
require_once __DIR__ . '/inc/scout.php';
require_once __DIR__ . '/inc/requests.php'; // (4.8) Request a Recomp
$reqView = ($_GET['view'] ?? '') === 'requests';

// The page's live check while runs are going: a fingerprint of every draft's state.
if (isset($_GET['poll'])) {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $sig = '';
    foreach (rs_auto_list() as $j) $sig .= $j['id'] . $j['status'] . md5(json_encode($j['steps'])) . implode('', rs_auto_vt($j)) . '|';
    $ss = rs_scout_state();
    $sig .= ($ss['phase'] ?? '') . ($ss['done'] ?? '') . (rs_scout_last()['done'] ?? '');
    echo json_encode(['sig' => md5($sig)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($act === 'start') {
            $links = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($_POST['links'] ?? '')) ?: []))));
            if (!$links) throw new RuntimeException('Paste a link first.');
            $n = 0; $bad = [];
            $made = [];
            foreach (array_slice($links, 0, 15) as $l) { try { $made[] = rs_auto_start($l, rs_me_name(), false); ++$n; } catch (RuntimeException $e) { $bad[] = $l . ': ' . $e->getMessage(); } }
            rs_auto_launch_many($made); // (4.7) one after another, not all at once
            rs_flash(($n ? 'Working on ' . $n . ($n > 1 ? ', one after another' : '') . '. Each one shows its progress below.' : '') . ($bad ? ' ' . implode(' ', $bad) : ''), $n ? 'ok' : 'err');
        } elseif ($act === 'scout_now') {
            rs_flash(rs_scout_launch() ? 'The scout is running. It shows what it\'s doing here.' : 'The scout is already running.', 'ok');
        } elseif ($act === 'scout_toggle') {
            $on = !rs_scout_on();
            rs_kv_put('scout_on', $on ? '1' : '0');
            rs_flash($on ? 'The daily scout is on. It runs every day at 12 pm.' : 'The daily scout is off. Run now still works.');
        } elseif ($act === 'scout_draft') {
            $key = (string)($_POST['key'] ?? '');
            $row = null;
            foreach (rs_scout_passed(200) as $r) if (strtolower((string)$r['repo']) === strtolower($key)) $row = $r;
            if (!$row) throw new RuntimeException('That find isn\'t on the list anymore.');
            $id = rs_auto_start(rs_host_web((string)$row['host'], (string)$row['path']), rs_me_name());
            rs_flash('Working on it. Its draft is below.');
        } elseif ($act === 'dismiss') {
            rs_auto_dismiss($id);
            rs_flash('Dismissed. It\'s still live on RecompLauncher.');
        } elseif ($act === 'dismiss_all') {
            $n = rs_auto_dismiss();
            rs_flash($n . ' published ' . ($n === 1 ? 'draft' : 'drafts') . ' dismissed. They\'re still live on RecompLauncher.');
            header('Location: auto.php');
            exit;
        } elseif ($act === 'publish') {
            @set_time_limit(180);
            $job = rs_auto_get($id);
            $name = rs_auto_publish($id);
            // From a submission: it's marked done, and the "you're live" email waits on Submissions.
            if (!empty($job['sub_id'])) {
                rs_flash($name . ' is live on RecompLauncher. Your "you\'re live" email is ready below: look it over and press Send.');
                header('Location: submissions.php?show=done&live=' . (int)$job['sub_id'] . '#m' . (int)$job['sub_id']);
                exit;
            }
            rs_flash($name . ' is live on RecompLauncher.');
        } elseif ($act === 'again') {
            rs_auto_again($id);
            rs_flash('Running it again from the top.');
        } elseif ($act === 'remove') {
            rs_auto_remove($id);
            rs_flash('Draft removed (and the hidden listing it made).');
        } elseif (in_array($act, ['req_add', 'req_mark'], true)) {
            // (4.8) A player's request: Auto-add it, or mark it handled.
            $rq = rs_req_get((int)($_POST['req'] ?? 0));
            if (!$rq) throw new RuntimeException('That request isn\'t there anymore.');
            if ($act === 'req_add') {
                $jid = rs_auto_start((string)$rq['url'], rs_me_name() . ' · request');
                rs_req_mark((int)$rq['id'], 'added');
                rs_flash('Working on it. Its draft is on Drafts.');
                header('Location: auto.php#d-' . (int)$jid);
                exit;
            }
            $to = (string)($_POST['to'] ?? '');
            rs_req_mark((int)$rq['id'], $to);
            rs_flash($to === 'new' ? 'Back on the list.' : 'Marked ' . strtolower(RS_REQ_STATUS[$to] ?? $to) . '.');
            header('Location: auto.php?view=requests' . ($to === 'new' ? '&handled=1' : ''));
            exit;
        }
    } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); if ($reqView || str_starts_with($act, 'req_')) { header('Location: auto.php?view=requests'); exit; } }
    header('Location: auto.php' . ($id ? '#d-' . $id : ''));
    exit;
}

$jobs = rs_auto_list();
$working = false;
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✕', 'skip' => '–', 'run' => '', '' => ''];
$statusName = ['queued' => 'In line', 'running' => 'Working on it', 'review' => 'Ready to look at', 'failed' => 'Didn\'t finish', 'published' => 'Published'];
$verdictName = ['good' => ['Looks good', 'ok'], 'check' => ['Look first', 'warn'], 'skip' => ['Don\'t list it', 'bad']];
$hasAi = rs_secret_get('prq_ai_key') !== '';
$scoutOn = rs_scout_on();
$scoutRun = rs_scout_running();
$scoutState = rs_scout_state();
$scoutLast = rs_scout_last();
$scoutPassed = rs_scout_passed();
$scoutWhy = [];
foreach (rs_scout_seen() as $k => $r) if ((int)$r['pick'] === 1) $scoutWhy[$k] = (string)$r['why'];
if ($scoutRun) $working = true;

rs_head($reqView ? 'Requests' : 'Auto-add');
if ($reqView):
    // (4.8) Projects players asked for from RecompLauncher's Coming Soon page, most asked first.
    $handled = !empty($_GET['handled']);
    $reqs = rs_req_list($handled);
    $newN = rs_req_new_count();
?>
<section class="head-row">
  <div>
    <h1>Requests</h1>
    <p class="muted">What players asked for with Request a Recomp on RecompLauncher's Coming Soon page. The same project asked for again adds to its count, so the most wanted are on top. Auto-add makes its draft on Drafts; Already listed and Decline mark it done. A declined project tells the next player who asks that it can't go on RecompLauncher.</p>
  </div>
</section>
<nav class="req-tabs">
  <a href="auto.php?view=requests"<?= !$handled ? ' class="on" aria-current="page"' : '' ?>>New<?= $newN ? ' <span>' . $newN . '</span>' : '' ?></a>
  <a href="auto.php?view=requests&amp;handled=1"<?= $handled ? ' class="on" aria-current="page"' : '' ?>>Handled</a>
</nav>
<?php if (!$reqs): ?>
<section class="card"><p class="muted"><?= $handled ? 'Nothing handled yet.' : 'No new requests. They show up here as players send them from Coming Soon.' ?></p></section>
<?php else: ?>
<section class="card req-card">
  <table class="req-table">
    <thead><tr><th class="req-n">Asks</th><th>Project</th><th>What players said</th><th><?= $handled ? 'Status' : 'Asked' ?></th><th class="req-act"></th></tr></thead>
    <tbody>
    <?php foreach ($reqs as $r):
        $label = preg_replace('#^https?://(www\.)?#', '', (string)$r['url']);
        $first = strtotime($r['created'] . ' UTC'); $last = strtotime($r['updated'] . ' UTC');
    ?>
      <tr>
        <td class="req-n"><strong><?= (int)$r['asks'] ?></strong><?php if ($r['signed']): ?><span class="muted small"><?= (int)$r['signed'] ?> signed in</span><?php endif; ?></td>
        <td class="req-url"><a href="<?= h((string)$r['url']) ?>" target="_blank" rel="noopener noreferrer" class="mono"><?= h($label) ?> ↗</a></td>
        <td class="req-notes"><?php if (!$r['notes']): ?><span class="muted small">No notes</span><?php else: ?>
          <?php foreach ($r['notes'] as $i => $n): ?><p class="small<?= $i ? ' req-more' : '' ?>"><?= h($n['note']) ?></p><?php endforeach; ?>
        <?php endif; ?></td>
        <td class="req-when small"><?php if ($handled): ?><span class="pill<?= $r['status'] === 'declined' ? ' bad' : ($r['status'] === 'added' ? ' ok' : '') ?>"><?= h(RS_REQ_STATUS[$r['status']] ?? $r['status']) ?></span><span class="muted"><?= h((string)$r['handled_by']) ?><?= $r['handled_at'] ? ', ' . h(rs_date('M j', strtotime($r['handled_at'] . ' UTC'))) : '' ?></span>
          <?php else: ?><span><?= h(rs_date('M j, g:i a', $last)) ?></span><?php if ($last - $first > 60): ?><span class="muted">first <?= h(rs_date('M j', $first)) ?></span><?php endif; ?><?php endif; ?></td>
        <td class="req-act">
          <?php if ($handled): ?>
          <form method="post"><?= $csrf ?><input type="hidden" name="action" value="req_mark"><input type="hidden" name="req" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="new"><button>Back to New</button></form>
          <?php else: ?>
          <form method="post"><?= $csrf ?><input type="hidden" name="action" value="req_add"><input type="hidden" name="req" value="<?= (int)$r['id'] ?>"><button class="primary">Auto-add</button></form>
          <form method="post"><?= $csrf ?><input type="hidden" name="action" value="req_mark"><input type="hidden" name="req" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="listed"><button>Already listed</button></form>
          <form method="post" onsubmit="return confirm('Decline <?= h(addslashes($label)) ?>? Players who ask for it later are told it can\'t go on RecompLauncher.')"><?= $csrf ?><input type="hidden" name="action" value="req_mark"><input type="hidden" name="req" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="declined"><button class="danger">Decline</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<style>
.req-tabs{display:flex;gap:6px;margin:0 0 16px}
.req-tabs a{padding:7px 14px;border-radius:99px;border:1px solid var(--line);color:inherit;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:8px}
.req-tabs a.on{background:rgba(var(--ink-rgb),.08);border-color:rgba(var(--ink-rgb),.2)}
.req-tabs a span{font-size:12px;padding:1px 7px;border-radius:99px;background:var(--cyan-ink,#46c8ff);color:#04121c}
.req-card{padding:0;overflow-x:auto}
.req-table{width:100%;border-collapse:collapse}
.req-table th,.req-table td{padding:12px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.req-table tr:last-child td{border-bottom:0}
.req-table th{font-size:12px;color:var(--muted);font-weight:600}
.req-n{width:80px}.req-n strong{display:block;font-size:20px;line-height:1.1}.req-n span{display:block;margin-top:2px}
.req-url{width:30%}.req-url a{overflow-wrap:anywhere}
.req-notes p{margin:0 0 6px;overflow-wrap:anywhere}.req-notes p:last-child{margin:0}.req-notes .req-more{color:var(--muted)}
.req-when{width:140px}.req-when>span{display:block}.req-when .pill{margin:0 0 4px}
.req-act{width:1%;white-space:nowrap}.req-act form{display:block;margin:0 0 6px}.req-act form:last-child{margin:0}.req-act button{width:100%}
.pill.bad{background:rgba(255,95,95,.14);color:var(--bad)}
@media (max-width:860px){.req-table thead{display:none}.req-table tr{display:grid;grid-template-columns:80px minmax(0,1fr);gap:4px 12px;padding:12px 14px;border-bottom:1px solid var(--line)}.req-table td{border:0;padding:0;width:auto}.req-table .req-notes,.req-table .req-when,.req-table .req-act{grid-column:2}.req-act{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.req-act form{margin:0}.req-act:has(form:only-child){grid-template-columns:1fr}}
</style>
<?php rs_foot(); exit; endif; ?>
<section class="head-row">
  <div>
    <h1>Auto-add</h1>
    <p class="muted">Paste a GitHub, GitLab or SourceForge link and the server does the whole listing: it reads the repo and its newest release, makes the listing (hidden), looks inside the download for game files or an emulator in disguise, runs ClamAV and VirusTotal, has Claude write the name, game, page and setup from the README, builds the boot profile for Easy Setup and launcher settings when the project allows it, and picks the cover, banner, logo and game info. Each one lands below as a draft with what every step found. Look it over, change anything on its listing, then Publish.</p>
  </div>
</section>
<div class="auto-top">
<section class="card auto-box">
  <form method="post" class="auto-form"><?= $csrf ?><input type="hidden" name="action" value="start">
    <label>Links <span class="muted small">one per line, up to 15 at once</span>
      <textarea name="links" rows="3" class="mono" spellcheck="false" placeholder="https://github.com/owner/name&#10;https://gitlab.com/group/project" required></textarea></label>
    <div class="auto-form-row"><button class="primary">Auto-add</button>
      <?php if (!$hasAi): ?><span class="warn small">Claude's step needs the Anthropic API key on SDK support (PR queue, Setup). Everything else runs without it.</span><?php endif; ?></div>
  </form>
</section>
<section class="card auto-box scout-box">
  <div class="scout-top"><b>Daily scout</b><span class="pill<?= $scoutOn ? ' ok' : '' ?>"><?= $scoutOn ? 'On · every day at 12 pm' : 'Off' ?></span></div>
  <p class="muted small">Looks on GitHub, the Discord suggestions and Quiver for new recomps, has Claude pick the real ones, and makes a draft below for each. Nothing goes live until you Publish.</p>
  <p class="scout-now small" role="status">
    <?php if ($scoutRun): ?><span class="sdk-spin" aria-hidden="true"></span> <?= h((string)($scoutState['phase'] ?? 'Working')) ?><?= !empty($scoutState['total']) ? ' · ' . (int)$scoutState['done'] . ' of ' . (int)$scoutState['total'] : '' ?>
    <?php elseif ($scoutLast): ?>Last run <?= h(rs_date('M j, g:i a', (int)$scoutLast['at'])) ?>: looked at <?= (int)$scoutLast['looked'] ?> new <?= (int)$scoutLast['looked'] === 1 ? 'find' : 'finds' ?>, picked <?= (int)$scoutLast['picked'] ?>, made <?= count((array)$scoutLast['drafted']) ?> <?= count((array)$scoutLast['drafted']) === 1 ? 'draft' : 'drafts' ?>.<?= (int)($scoutLast['waiting'] ?? 0) ? ' ' . (int)$scoutLast['waiting'] . ' more wait for the next run.' : '' ?><?php if (($scoutLast['error'] ?? '') !== ''): ?> <span class="warn"><?= h((string)$scoutLast['error']) ?></span><?php endif; ?>
    <?php else: ?><span class="muted">Hasn't run yet.</span><?php endif; ?>
  </p>
  <div class="auto-form-row">
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="scout_now"><button class="primary"<?= $scoutRun || !$hasAi ? ' disabled' : '' ?>>Run now</button></form>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="scout_toggle"><button><?= $scoutOn ? 'Turn off' : 'Turn on' ?></button></form>
  </div>
</section>
</div>
<?php if ($scoutPassed): ?>
<details class="card scout-passed">
  <summary><b>What the scout passed on</b> <span class="muted small"><?= count($scoutPassed) ?> <?= count($scoutPassed) === 1 ? 'find' : 'finds' ?> · still on Found on GitHub too</span></summary>
  <ul>
    <?php foreach ($scoutPassed as $r): ?>
    <li><a href="<?= h(rs_host_web((string)$r['host'], (string)$r['path'])) ?>" target="_blank" rel="noopener" class="mono small"><?= h((string)$r['repo']) ?> ↗</a>
      <?php if ((string)$r['kind'] !== ''): ?><span class="pill"><?= h((string)$r['kind']) ?></span><?php endif; ?>
      <span class="small muted"><?= h((string)$r['why']) ?></span>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="scout_draft"><input type="hidden" name="key" value="<?= h((string)$r['repo']) ?>"><button class="small">Draft it anyway</button></form></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>

<?php if (!$jobs): ?><section class="card"><p class="muted">No drafts yet. Paste a link above.</p></section><?php endif; ?>
<?php $pubN = count(array_filter($jobs, fn($j) => $j['status'] === 'published')); if ($pubN): ?>
<form method="post" class="auto-dismiss-all"><?= $csrf ?><input type="hidden" name="action" value="dismiss_all"><span class="muted small"><?= $pubN ?> published <?= $pubN === 1 ? 'draft is' : 'drafts are' ?> still on the page.</span><button>Dismiss all published</button></form>
<?php endif; ?>
<div class="auto-list">
<?php foreach ($jobs as $j):
    $a = $j['app_id'] !== '' ? rs_pc_app($j['app_id']) : null;
    $steps = $j['steps'];
    $steps['vt'] = ($steps['vt']['s'] ?? '') === 'skip' ? $steps['vt'] : ['s' => rs_auto_vt($j)[0], 'n' => rs_auto_vt($j)[1]];
    if ($j['status'] === 'queued' || $j['status'] === 'running') $stuck = strtotime($j['updated'] . ' UTC') < time() - RS_AUTO_STUCK; else $stuck = false;
    $live = in_array($j['status'], ['queued', 'running'], true) && !$stuck;
    if ($live || ($steps['vt']['s'] ?? '') === 'run') $working = true;
    $done = count(array_filter(RS_AUTO_STEPS, fn($l, $k) => in_array($steps[$k]['s'] ?? '', ['ok', 'warn', 'bad', 'skip'], true), ARRAY_FILTER_USE_BOTH));
    $vtBad = ($steps['vt']['s'] ?? '') === 'bad';
    $title = $a['name'] ?? (preg_replace('#^https?://(www\.)?#', '', $j['link']));
    $st = $stuck ? 'Stopped' : ($statusName[$j['status']] ?? $j['status']);
?>
  <article class="card auto-item auto-<?= h($j['status']) ?>" id="d-<?= (int)$j['id'] ?>">
    <div class="auto-head">
      <?php if (!empty($a['icon'])): ?><img src="<?= h($a['icon']) ?>" alt="" width="44" height="44"><?php else: ?><span class="auto-ico"></span><?php endif; ?>
      <div class="auto-title"><strong><?= h($title) ?></strong>
        <span class="muted small"><?= $j['repo_key'] !== '' ? '<a href="' . h(rs_host_web(str_starts_with($j['repo_key'], 'gitlab.com/') ? 'gitlab' : (str_starts_with($j['repo_key'], 'sourceforge.net/') ? 'sourceforge' : ''), preg_replace('#^(gitlab\.com|sourceforge\.net)/#', '', $j['repo_key']))) . '" target="_blank" rel="noopener">' . h($j['repo_key']) . ' ↗</a>' : h($j['link']) ?><?= $a && $a['game'] !== '' ? ' · ' . h($a['game']) : '' ?><?= $a ? ' · ' . h(RS_PC_ORIGINS[$a['origin']] ?? $a['origin']) : '' ?> · <?= h($j['who']) ?><?= !empty($j['sub_id']) ? ' · <a href="submissions.php#m' . (int)$j['sub_id'] . '">from a submission</a>' : '' ?>, <?= h(rs_date('M j, g:i a', strtotime($j['created'] . ' UTC'))) ?></span></div>
      <span class="pill<?= $j['status'] === 'review' ? ' cyan' : ($j['status'] === 'published' ? ' ok' : ($j['status'] === 'failed' || $stuck ? ' bad' : '')) ?>"><?php if ($live): ?><span class="sdk-spin" aria-hidden="true"></span> <?php endif; ?><?= h($st) ?></span>
      <?php
      // Claude's verdict is written before VirusTotal answers, so the live VirusTotal state wins:
      // flagged turns it red, still checking holds it at "Wait for VirusTotal".
      if (isset($verdictName[$j['verdict']]) && $j['status'] === 'review'):
          [$vn, $vc] = $verdictName[$j['verdict']];
          $vtS = $steps['vt']['s'] ?? '';
          if ($vtS === 'bad') [$vn, $vc] = ['VirusTotal flagged it', 'bad'];
          elseif ($vtS === 'run' && $j['verdict'] === 'good') [$vn, $vc] = ['Wait for VirusTotal', 'warn'];
          elseif ($vtS === 'warn' && $j['verdict'] === 'good') [$vn, $vc] = ['Look first', 'warn'];
      ?><span class="pill <?= $vc ?>"><?= h($vn) ?></span><?php endif; ?>
    </div>
    <div class="auto-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count(RS_AUTO_STEPS) ?>" aria-valuenow="<?= $done ?>"><span style="width:<?= round($done / count(RS_AUTO_STEPS) * 100) ?>%"></span></div>
    <ul class="auto-steps">
      <?php foreach (RS_AUTO_STEPS as $k => $label): $s = $steps[$k] ?? ['s' => '', 'n' => '']; ?>
      <li class="as-<?= h($s['s'] ?: 'wait') ?>"><span class="as-i"><?php if ($s['s'] === 'run' && ($live || $k === 'vt')): ?><span class="sdk-spin" aria-hidden="true"></span><?php else: ?><?= $icon[$s['s']] ?? '' ?><?php endif; ?></span><span class="as-t"><b><?= h($label) ?></b><?php if (($s['n'] ?? '') !== ''): ?><small><?= h($s['n']) ?></small><?php elseif (!$s['s']): ?><small class="muted">Waiting</small><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($j['who'] === RS_SCOUT_WHO && isset($scoutWhy[strtolower($j['repo_key'])])): ?><p class="small muted scout-why">Why the scout picked it: <?= h($scoutWhy[strtolower($j['repo_key'])]) ?></p><?php endif; ?>
    <?php if (trim((string)$j['notes']) !== ''): ?><p class="auto-notes small"><?= nl2br(h((string)$j['notes'])) ?></p><?php endif; ?>
    <div class="auto-btns">
      <?php if ($j['status'] === 'review'): ?>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><button class="primary"<?= $vtBad ? ' disabled title="VirusTotal flagged it"' : '' ?> onclick="return confirm('Put <?= h(addslashes($title)) ?> live on RecompLauncher?')">Publish</button></form>
      <?php endif; ?>
      <?php if ($a): ?><a class="btn" href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>#edit">Open its listing</a><?php endif; ?>
      <?php if ($j['status'] === 'published' && $a): ?><a class="btn" href="https://recomplauncher.com/game/<?= h(rawurlencode($a['id'])) ?>" target="_blank" rel="noopener">Its page ↗</a><?php endif; ?>
      <?php if ($j['status'] === 'published'): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="dismiss"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><button>Dismiss</button></form><?php endif; ?>
      <?php if (!$live && $j['status'] !== 'published'): ?>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="again"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><button>Run again</button></form>
      <form method="post" onsubmit="return confirm('Remove this draft<?= (int)$j['made'] ? ' and the hidden listing it made' : '' ?>?')"><?= $csrf ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><button class="danger">Remove</button></form>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<style>
.auto-top{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:stretch}
.auto-top .auto-box{margin:0;display:flex;flex-direction:column;height:100%}
.auto-top .auto-form{display:flex;flex-direction:column;flex:1}.auto-top .auto-form label{flex:1}.auto-top .auto-form textarea{flex:1;min-height:84px}
.scout-box .auto-form-row{margin-top:auto}.scout-box .auto-form-row form{margin:0}
.scout-top{display:flex;align-items:center;justify-content:space-between;gap:10px}.scout-top .pill{margin:0}
.scout-box p{margin:8px 0 0}.scout-now{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-height:22px;padding-bottom:12px}
.scout-passed{margin-top:16px}.scout-passed summary{cursor:pointer;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}
.scout-passed ul{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
.scout-passed li{display:grid;grid-template-columns:minmax(0,1.2fr) auto minmax(0,2fr) auto;gap:10px;align-items:center;padding:8px 10px;border:1px solid var(--line);border-radius:10px}
.scout-passed li .pill{margin:0}.scout-passed li form{margin:0}.scout-passed a{overflow-wrap:anywhere}
.scout-why{margin:12px 0 0}
@media (max-width:760px){.auto-top{grid-template-columns:1fr}.scout-passed li{grid-template-columns:1fr}}
.auto-dismiss-all{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:16px 0 0;padding:10px 14px;border:1px solid var(--line);border-radius:12px}
.auto-form label{display:flex;flex-direction:column;gap:6px}
.auto-form-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}
.auto-list{display:flex;flex-direction:column;gap:16px;margin-top:16px}
.auto-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.auto-head img,.auto-ico{width:44px;height:44px;border-radius:10px;object-fit:cover;background:rgba(var(--ink-rgb),.08);flex:none}
.auto-title{display:flex;flex-direction:column;gap:2px;flex:1 1 260px;min-width:0}
.auto-title .small{overflow-wrap:anywhere}
.auto-head .pill{margin-left:0;display:inline-flex;align-items:center;gap:6px}
.pill.cyan{background:rgba(70,200,255,.14);color:var(--cyan-ink,#46c8ff)}.pill.warn{background:rgba(255,190,70,.14);color:var(--warn)}.pill.bad{background:rgba(255,95,95,.14);color:var(--bad)}
.auto-bar{height:6px;border-radius:99px;background:rgba(var(--ink-rgb),.08);margin:14px 0;overflow:hidden}
.auto-bar span{display:block;height:100%;background:var(--cyan-ink,#46c8ff);transition:width .4s ease}
.auto-published .auto-bar span{background:var(--ok)}
.auto-steps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.auto-steps li{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line);border-radius:10px;min-width:0}
.as-i{width:18px;height:18px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800}
.as-ok .as-i{color:var(--ok)}.as-warn .as-i{color:var(--warn)}.as-bad .as-i{color:var(--bad)}.as-skip .as-i,.as-wait .as-i{color:var(--muted)}
.as-bad{border-color:rgba(255,95,95,.5)!important}.as-warn{border-color:rgba(255,190,70,.4)!important}
.as-t{display:flex;flex-direction:column;gap:2px;min-width:0}.as-t small{color:var(--muted);overflow-wrap:anywhere}
.auto-notes{margin:12px 0 0;padding:10px 12px;border-radius:10px;background:rgba(255,190,70,.08);border:1px solid rgba(255,190,70,.3)}
.auto-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.auto-btns form{margin:0}
@media (max-width:760px){.auto-steps{grid-template-columns:1fr}}
</style>
<?php if ($working): ?>
<script>
(function () {
  var sig = null, tries = 0;
  var t = setInterval(function () {
    if (++tries > 300) { clearInterval(t); return; }
    fetch('auto.php?poll=1', { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (sig === null) { sig = j.sig; return; }
      if (j.sig !== sig) { clearInterval(t); var y = window.scrollY; sessionStorage.setItem('autoY', y); location.reload(); }
    }).catch(function () {});
  }, 4000);
})();
</script>
<?php endif; ?>
<script>try { var y = sessionStorage.getItem('autoY'); if (y) { sessionStorage.removeItem('autoY'); window.scrollTo(0, +y); } } catch (e) {}</script>
<?php rs_foot();
