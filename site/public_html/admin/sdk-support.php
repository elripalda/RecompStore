<?php
// (4.3) SDK support: every recomp on RecompLauncher and whether its own GitHub ships a
// recomplauncher.json, so the team can send the developers that don't a pull request (or a nudge).
// (4.6) Xarishark: one colored line instead of the four boxes, red/green status, a star for the
// README badge, a live progress while checking, and Check again / marks without reloading the page.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
require_once __DIR__ . '/inc/sdk.php'; // (4.6) the data helpers, shared with the PR queue's API
require_once __DIR__ . '/inc/prq.php'; // (4.6) the PR queue

// One background run at a time, after the answer has gone: the due ones for up to a minute. The
// half-hourly job does the same; this only speeds it up while someone has the page open.
function rs_sdk_kick(array $due): bool {
    if (!$due) return false;
    $run = rs_kv_get('sdk_scan_run');
    if ($run && $run['age'] <= 150) return true;
    rs_kv_put('sdk_scan_run', (string)time());
    register_shutdown_function(function () use ($due) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @set_time_limit(150);
        $t0 = microtime(true);
        foreach ($due as $id) {
            if (microtime(true) - $t0 > 60) break;
            if (($a = rs_pc_app($id)) && rs_pc_sdk_due($a)) { try { rs_pc_sdk_check($a, true); } catch (Throwable $e) { /* next run */ } }
        }
        rs_db()->exec("DELETE FROM kv_cache WHERE k = 'sdk_scan_run'");
    });
    return true;
}

const RS_SDK_NOW = [
    'json' => ['Its file, in our catalog', 'The developer\'s recomplauncher.json is pasted into the catalog. Shipping it in the release keeps it up to date.'],
    'profile' => ['Our setup profile', 'Easy Setup works from a profile we wrote. The developer\'s own file would be better.'],
    'steps' => ['Setup steps only', 'Players follow written steps before the first play.'],
    'none' => ['Nothing yet', 'Players set it up by hand.'],
];
const RS_SDK_STATE = ['ships' => ['Ships its own file', ' ok'], 'pr' => ['Pull request open', ' blue'], 'no' => ['Won\'t support it', ' warn'], 'locked' => ['Pull requests closed', ' violet'], 'missing' => ['No file yet', ' bad'], 'unknown' => ['Couldn\'t check yet', '']];

// One recomp's table row (a <tbody>: the row and its ⋯ panel), for the page and for the live updates.
function rs_sdk_row_html(array $row, string $csrf): string {
    $a = $row['a']; $st = $row['state']; $mk = $row['m']; [$sl, $sc] = RS_SDK_STATE[$st];
    if ($st === 'pr' && $row['pr'] !== '') { $sl = 'Pull request ' . ($row['pr'] === 'merged' ? 'merged' : ($row['pr'] === 'closed' ? 'closed' : 'open')); $sc = $row['pr'] === 'merged' ? ' ok' : ($row['pr'] === 'closed' ? ' warn' : ' blue'); }
    if ($st === 'unknown' && $row['waiting']) $sl = 'Not checked yet';
    [$nl, $nw] = RS_SDK_NOW[$row['now']];
    $gh = rs_host_web(rs_pc_host($a), (string)$a['repo']);
    $find = mb_strtolower($a['name'] . ' ' . $a['game'] . ' ' . $a['repo'] . ' ' . $a['id']);
    $id = h($a['id']);
    $promptBtn = fn(string $cls) => '<button type="button" class="btn' . $cls . '" data-prompt-repo="' . h($a['repo']) . '" data-prompt-url="' . h($gh) . '" data-prompt-id="' . $id . '" data-prompt-game="' . h((string)($a['game'] ?: $a['name'])) . '">Copy agent prompt</button>';
    $badgePrBtn = fn(string $cls) => '<button type="button" class="btn' . $cls . '" data-badgepr-repo="' . h($a['repo']) . '" data-badgepr-id="' . $id . '" data-badgepr-game="' . h($a['name']) . '">Copy badge PR prompt</button>';
    $form = fn(string $action, string $label) => '<form method="post" data-sdk-ajax>' . $csrf . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn">' . $label . '</button></form>';
    ob_start(); ?>
      <tbody class="sdk-item" id="r-<?= $id ?>" data-id="<?= $id ?>" data-state="<?= $st ?>" data-kind="<?= $row['kind'] ?>" data-rank="<?= (int)$row['rank'] ?>" data-dl="<?= (int)$a['downloads'] ?>" data-name="<?= h(mb_strtolower((string)$a['name'])) ?>" data-badge="<?= $row['badge'] ? '1' : '0' ?>" data-find="<?= h($find) ?>">
        <tr>
          <td class="c-pick"><input type="checkbox" class="sdk-pick" value="<?= $id ?>" aria-label="Pick <?= h($a['name']) ?>"></td>
          <td class="c-rank"><?= (int)$row['rank'] ?></td>
          <td><div class="sdk-who"><?php if ($a['icon']): ?><img src="<?= h($a['icon']) ?>" alt="" width="32" height="32" loading="lazy"><?php else: ?><span class="sdk-ico"><?= h(mb_substr($a['name'], 0, 2)) ?></span><?php endif; ?><div class="sdk-name"><span class="sdk-title"><?php if ($row['badge']): $bn = implode(', ', array_map(fn($b) => RS_BADGE_NAMES[$b] ?? $b, $row['badges'])); ?><span class="sdk-star" title="Its README has the <?= h($bn) ?> badge" aria-label="Badge in README: <?= h($bn) ?>">★</span><?php endif; ?><a href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>"><strong><?= h($a['name']) ?></strong></a></span><span class="muted small"><?= h($a['repo']) ?><?= $row['kind'] === 'pc' ? ' · PC game' : '' ?></span></div></div></td>
          <td class="c-num"><?= number_format((int)$a['downloads']) ?></td>
          <td><div class="sdk-st"><span class="pill<?= $sc ?>"><?= h($sl) ?></span><span class="muted small" title="Easy Setup now: <?= h($nw) ?>"><?= h($nl) ?><?= $row['dlss'] ? ' · gets DLSS from the launcher' : '' ?></span></div></td>
          <td class="c-act"><div class="sdk-btns">
            <a class="btn" href="<?= h($gh) ?>" target="_blank" rel="noopener">GitHub ↗</a>
            <?php if ($st === 'ships'): ?><?= in_array('launcher', $row['badges'], true) ? '' : $badgePrBtn(' primary') ?>
            <?php elseif ($st === 'pr' && (string)$mk['url'] !== ''): ?><a class="btn" href="<?= h((string)$mk['url']) ?>" target="_blank" rel="noopener">Pull request ↗</a>
            <?php elseif ($st === 'locked'): ?>
            <?php else: ?><?= $promptBtn(' primary') ?><?php endif; ?>
            <button type="button" class="btn sdk-more" aria-expanded="false" aria-controls="m-<?= $id ?>" aria-label="More for <?= h($a['name']) ?>" title="Badge, Check again, marks">⋯</button>
          </div></td>
        </tr>
        <tr class="sdk-extra" id="m-<?= $id ?>" hidden><td colspan="6"><div class="sdk-extra-in">
          <div class="sdk-btns sdk-btns-l">
            <?php if ($st !== 'ships'): ?><button type="button" class="btn" data-badge-id="<?= $id ?>">Copy badge</button><?= in_array('launcher', $row['badges'], true) ? '' : $badgePrBtn('') ?><?php endif; ?>
            <?php if ($st === 'pr'): ?><?= $promptBtn('') ?><?php endif; ?>
            <?= $form('recheck', 'Check again') ?>
            <?php if ($mk): ?><?= $form('unmark', 'Clear the mark') ?><span class="muted small">Marked by <?= h((string)$mk['who']) ?> on <?= h((string)$mk['at']) ?><?= $st === 'no' && (string)$mk['url'] !== '' ? ' · <a href="' . h((string)$mk['url']) . '" target="_blank" rel="noopener">where they said so ↗</a>' : '' ?></span><?php endif; ?>
          </div>
          <?php if (!$mk && $st !== 'ships'): ?>
          <div class="sdk-mark-forms">
            <form method="post" class="sdk-mark-form" data-sdk-ajax><?= $csrf ?><input type="hidden" name="action" value="mark_pr"><input type="hidden" name="id" value="<?= $id ?>">
              <label>A pull request is open<input name="url" required placeholder="https://github.com/<?= h($a['repo']) ?>/pull/…" spellcheck="false"></label><button class="btn">Save</button></form>
            <form method="post" class="sdk-mark-form" data-sdk-ajax><?= $csrf ?><input type="hidden" name="action" value="mark_no"><input type="hidden" name="id" value="<?= $id ?>">
              <label>The developer doesn't want it<input name="url" placeholder="Link where they said so (optional)" spellcheck="false"></label><button class="btn danger">Mark</button></form>
            <form method="post" class="sdk-mark-form" data-sdk-ajax><?= $csrf ?><input type="hidden" name="action" value="mark_locked"><input type="hidden" name="id" value="<?= $id ?>">
              <label>Pull requests are closed<span class="sdk-mark-note">The repo takes no pull requests or issues, so nothing can be sent.</span></label><button class="btn">Mark</button></form>
          </div>
          <?php endif; ?>
        </div></td></tr>
      </tbody>
<?php
    return (string)ob_get_clean();
}
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$ajax = ($_POST['ajax'] ?? '') === '1';
function rs_sdk_json(array $out): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function rs_sdk_db_now(): int { return (int)rs_db()->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn(); }

// Live progress while checking: the counts, and the rows checked since the page last asked.
if (isset($_GET['progress'])) {
    [$rows, $count, $due] = rs_sdk_all();
    $since = (int)($_GET['since'] ?? 0);
    $changed = [];
    if ($since > 0) foreach ($rows as $id => $row) if ($row['t'] >= $since && count($changed) < 150) $changed[$id] = rs_sdk_row_html($row, $csrf);
    $running = rs_sdk_kick($due);
    rs_sdk_json(['ok' => true, 'count' => $count, 'running' => $running, 'rows' => $changed, 'now' => rs_sdk_db_now()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (string)($_POST['id'] ?? '');
    $msg = ''; $kind = 'ok';
    if (($_POST['action'] ?? '') === 'recheck' && ($a = rs_pc_app($id))) {
        $r = rs_pc_sdk_check($a, true, true);
        $msg = $a['name'] . ($r['has'] === 'release' || $r['has'] === 'repo' ? ' ships its own recomplauncher.json.' : ($r['has'] === 'none' ? ' doesn\'t ship one yet.' : ': GitHub didn\'t answer. Try again in a minute.'))
            . (array_key_exists('badge', $r) && $r['badge'] !== null ? ($r['badge'] ? ' Its README has the ' . implode(', ', array_map(fn($b) => RS_BADGE_NAMES[$b] ?? $b, (array)($r['badges'] ?? []))) . ' badge.' : ' No badge in its README.') : '');
    } elseif (in_array($_POST['action'] ?? '', ['mark_pr', 'mark_no'], true) && ($a = rs_pc_app($id))) {
        $pr = $_POST['action'] === 'mark_pr';
        $url = trim((string)($_POST['url'] ?? ''));
        [$link, $why] = $url === '' && !$pr ? ['', ''] : rs_sdk_link($a, $url, $pr);
        if ($link === null) { $msg = $why; $kind = 'err'; }
        else {
            $m = rs_sdk_marks();
            $m[$id] = ['kind' => $pr ? 'pr' : 'no', 'url' => $link, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200), 'who' => rs_me_name(), 'at' => gmdate('Y-m-d')];
            rs_sdk_marks_save($m);
            rs_log('sdk', $id, $pr ? 'pull request: ' . $link : 'won\'t support' . ($link ? ': ' . $link : ''));
            $msg = $pr ? $a['name'] . ' marked: pull request open.' : $a['name'] . ' marked: won\'t support it.';
        }
    } elseif (($_POST['action'] ?? '') === 'mark_locked' && ($a = rs_pc_app($id))) {
        // (4.6) Xarishark: the repo doesn't take pull requests or issues, so there's nothing to link to.
        $m = rs_sdk_marks();
        $m[$id] = ['kind' => 'locked', 'url' => '', 'note' => '', 'who' => rs_me_name(), 'at' => gmdate('Y-m-d')];
        rs_sdk_marks_save($m);
        rs_log('sdk', $id, 'pull requests closed on its repo');
        $msg = $a['name'] . ' marked: pull requests closed.';
    } elseif (($_POST['action'] ?? '') === 'unmark' && ($a = rs_pc_app($id))) {
        $m = rs_sdk_marks();
        unset($m[$id]);
        rs_sdk_marks_save($m);
        rs_log('sdk', $id, 'back on the list');
        $msg = $a['name'] . '\'s mark is cleared.';
    } elseif (($_POST['action'] ?? '') === 'recheck_ids' || (($_POST['action'] ?? '') === 'recheck_all' && trim((string)($_POST['ids'] ?? '')) !== '')) {
        // (4.6) Xarishark: Check again for the picked ones, or for just the ones the filters show.
        $ids = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['ids'] ?? ''))), fn($x) => preg_match('/^[a-z0-9._-]{1,100}$/i', $x)));
        if ($ids) {
            rs_cache_tables();
            $in = implode(',', array_map(fn($x) => rs_db()->quote('sdk:' . $x), array_slice($ids, 0, 2000)));
            rs_db()->exec("UPDATE kv_cache SET at = at - INTERVAL 8 DAY WHERE k IN ($in)");
            $msg = 'Checking ' . count($ids) . ' again. The progress shows next to the button.';
        } else { $msg = 'Pick some first.'; $kind = 'err'; }
        $id = '';
    } elseif (($_POST['action'] ?? '') === 'prq_queue') {
        $ids = array_filter(array_map('trim', explode(',', (string)($_POST['ids'] ?? ''))));
        $n = rs_prq_queue($ids);
        $msg = $n ? $n . ' queued: the next daily drafts do them first.' : 'Nothing new to queue (already in the queue, or not on GitHub).';
        $id = '';
        rs_flash($msg, $n ? 'ok' : 'err');
        header('Location: sdk-support.php#prq'); exit;
    } elseif (str_starts_with((string)($_POST['action'] ?? ''), 'prq_')) {
        // (4.6) The PR queue: save, send, skip, draft again; and (owner) the token and the drafter's key.
        $act = (string)$_POST['action'];
        $qid = (int)($_POST['qid'] ?? 0);
        try {
            if ($act === 'prq_save' || $act === 'prq_send') {
                $j = json_decode((string)($_POST['json'] ?? ''), true);
                if (!is_array($j)) throw new RuntimeException('The recomplauncher.json isn\'t valid JSON. Fix it and save again.');
                rs_db()->prepare("UPDATE pc_prq SET title = ?, body = ?, json = ?, updated = NOW() WHERE id = ? AND status = 'draft'")
                    ->execute([mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200) ?: RS_PRQ_TITLE, mb_substr(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 0, 60000), json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $qid]);
                if ($act === 'prq_send') { @set_time_limit(120); $url = rs_prq_send($qid); rs_flash('Pull request sent: ' . $url); }
                else rs_flash('Saved.');
            } elseif ($act === 'prq_skip') {
                rs_db()->prepare("UPDATE pc_prq SET status = 'skipped', updated = NOW() WHERE id = ?")->execute([$qid]);
                rs_flash('Skipped. It won\'t be drafted again.');
            } elseif ($act === 'prq_redo') {
                rs_db()->prepare("UPDATE pc_prq SET status = 'todo', json = NULL, body = NULL, note = NULL, error = NULL, updated = NOW() WHERE id = ? AND status <> 'sent'")->execute([$qid]);
                rs_flash('Back in line: the next daily run drafts it again.');
            } elseif ($act === 'prq_token' && rs_is_owner()) {
                $t = trim((string)($_POST['token'] ?? ''));
                rs_secret_put('prq_gh_token', $t);
                if ($t !== '') { $me = rs_prq_gh('GET', '/user'); if ($me['status'] !== 200) { rs_secret_put('prq_gh_token', ''); throw new RuntimeException('GitHub didn\'t accept that token (' . $me['status'] . ').'); } rs_set_setting('prq_gh_login', (string)$me['json']['login']); rs_flash('Saved. Pull requests will come from @' . $me['json']['login'] . '.'); }
                else { rs_set_setting('prq_gh_login', ''); rs_flash('Token removed.'); }
            } elseif ($act === 'prq_key' && rs_is_owner()) {
                $_SESSION['prq_key_once'] = rs_prq_key_new();
                rs_flash('New key made. Copy it now: it\'s shown only once.');
            }
        } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); }
        header('Location: sdk-support.php' . ($qid ? '#q-' . $qid : '#prq')); exit;
    } elseif (($_POST['action'] ?? '') === 'recheck_all') {
        // Everything becomes due, but keeps what it showed until its new check is in.
        rs_cache_tables();
        rs_db()->exec("UPDATE kv_cache SET at = at - INTERVAL 8 DAY WHERE k LIKE 'sdk:%'");
        $msg = 'Checking everything again. The progress shows next to the button.';
        $id = '';
    }
    if ($ajax) {
        [$rows, $count, $due] = rs_sdk_all();
        $running = rs_sdk_kick($due);
        rs_sdk_json(['ok' => $kind === 'ok', 'msg' => $msg, 'id' => $id, 'row' => $id !== '' && isset($rows[$id]) ? rs_sdk_row_html($rows[$id], $csrf) : '', 'count' => $count, 'running' => $running, 'now' => rs_sdk_db_now()]);
    }
    if ($msg !== '') rs_flash($msg, $kind);
    header('Location: sdk-support.php' . ($id !== '' ? '#r-' . rawurlencode($id) : ''));
    exit;
}

[$rows, $count, $due] = rs_sdk_all();
rs_sdk_kick($due);
$pageNow = rs_sdk_db_now();

// Look up a GitHub repo that isn't on RecompLauncher (owner/name or a github.com link): its prompt
// and badge still work, so a pull request can go out before it's listed. Listed ones point at the table.
$q = trim((string)($_GET['q'] ?? ''));
$found = [];
$outside = null;
$parked = null;
if (mb_strlen($q) >= 2) {
    $gm = [];
    $isRepo = (bool)preg_match('#^(?:https?://)?(?:www\.)?(?:github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?(?:[/?\#].*)?$#i', $q, $gm);
    $ql = $isRepo ? mb_strtolower($gm[1] . '/' . $gm[2]) : mb_strtolower($q);
    foreach ($rows as $row) {
        $a = $row['a'];
        if (str_contains(mb_strtolower((string)$a['name']), $ql) || str_contains(mb_strtolower((string)$a['game']), $ql) || str_contains(mb_strtolower((string)$a['repo']), $ql) || str_contains(mb_strtolower((string)$a['id']), $ql)) $found[] = $row;
        if (count($found) >= 15) break;
    }
    if ($isRepo) {
        $want = $gm[1] . '/' . $gm[2];
        $listed = false;
        foreach (rs_pc_apps() as $a) if (strcasecmp((string)$a['repo'], $want) === 0 && rs_pc_host($a) === '') {
            $listed = true;
            // In the catalog but not in the table: hidden, or still Coming soon.
            if (!isset($rows[$a['id']])) $parked = $a;
            break;
        }
        if (!$listed) {
            $r = rs_http(rs_gh_api() . '/repos/' . $want, ['github' => true, 'timeout' => 8, 'max' => 2000000]);
            $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
            $outside = is_array($j) ? ['repo' => (string)$j['full_name'], 'about' => (string)($j['description'] ?? ''), 'stars' => (int)($j['stargazers_count'] ?? 0)] : ['repo' => $want, 'missing' => $r['status'] === 404];
        }
    }
}
$chips = ['all' => 'All', 'missing' => 'No file yet', 'ships' => 'Ships its own file', 'pr' => 'Pull request open', 'no' => 'Won\'t support it', 'locked' => 'Pull requests closed', 'unknown' => 'Not checked', 'badge' => 'Badge in README'];
$meter = ['ships' => 'ship their own file', 'missing' => 'no file yet', 'pr' => 'pull request open', 'no' => 'won\'t support it', 'locked' => 'pull requests closed', 'unknown' => 'not checked'];

rs_head('SDK support', true, ['assets/account.css']);
?>
<section class="head-row">
  <div>
    <h1>SDK support</h1>
    <p class="muted">Every recomp on RecompLauncher, and whether its own GitHub ships a <code>recomplauncher.json</code>. Red ones don't yet, green ones do, and ★ means its README has one of our badges (Get it on RecompLauncher, Get it on RecompStore or Verified developer; hover the ★ to see which). Each red one is a pull request away from Easy Setup for everyone: open its GitHub, give your AI agent the prompt, and send the developer the result. The prompt also adds the badge and checks the project against the Recomp Standards (adding CHD and the other standard formats where it's a small change), and ends the pull request with a "For the developer" note inviting them to claim it. For green ones without the RecompLauncher badge, Copy badge PR prompt adds just that badge. The ⋯ button on a row has the badge code, Check again and the marks.</p>
  </div>
  <div class="sdk-head-act">
    <span class="sdk-prog" id="sdk-prog" aria-live="polite"<?= $count['todo'] ? '' : ' hidden' ?>><span class="sdk-spin" aria-hidden="true"></span><span id="sdk-prog-t">Checking: <?= number_format($count['all'] - $count['todo']) ?> of <?= number_format($count['all']) ?> done</span></span>
    <form method="post" class="actions" data-sdk-ajax id="sdk-all"><?= $csrf ?><input type="hidden" name="action" value="recheck_all"><input type="hidden" name="ids" value=""><button class="btn" id="sdk-all-b">Check them all again</button></form>
  </div>
</section>
<?php // (4.6) The PR queue
$prqDrafts = rs_prq_list(['draft']);
$prqWaiting = (int)rs_db()->query("SELECT COUNT(*) FROM pc_prq WHERE status = 'todo'")->fetchColumn();
$prqSent = rs_prq_list(['sent'], 8);
$prqLogin = (string)rs_setting('prq_gh_login', '');
$prqHasToken = rs_prq_token() !== '';
$prqKeyOnce = (string)($_SESSION['prq_key_once'] ?? ''); unset($_SESSION['prq_key_once']);
$prqPick = ['new' => 'Just added', 'popular' => 'Popular port', 'asked' => 'Queued by hand'];
?>
<section class="card prq-card" id="prq">
  <div class="an-head"><h2>PR queue</h2><span class="muted small"><?= count($prqDrafts) ?> to look over<?= $prqWaiting ? ' · ' . $prqWaiting . ' waiting for the next drafts' : '' ?> · drafted daily<?= $prqLogin !== '' ? ' · sent as @' . h($prqLogin) : '' ?></span></div>
  <p class="muted small">Every day the drafter picks recomps with no file yet (half just added, half popular console ports, and anything you queued first) and drafts their <code>recomplauncher.json</code> and the pull request. Look each one over, fix anything, then Send: it forks the repo, adds the file and the badge, opens the pull request and marks the recomp "Pull request open".</p>
  <?php if (!$prqHasToken && rs_is_owner()): ?><p class="warn small">Add the GitHub token below before sending: it's the account the pull requests come from.</p><?php endif; ?>
  <?php if (!$prqDrafts): ?><p class="muted">No drafts right now.</p><?php endif; ?>
  <?php foreach ($prqDrafts as $q): $qa = rs_pc_app((string)$q['app_id']); if (!$qa) continue; ?>
  <details class="prq-item" id="q-<?= (int)$q['id'] ?>"<?= (string)$q['error'] !== '' ? ' open' : '' ?>>
    <summary><span class="prq-name"><strong><?= h($qa['name']) ?></strong><span class="muted small"><?= h($q['repo']) ?> · <?= number_format((int)$qa['downloads']) ?> downloads</span></span>
      <?php if (isset($prqPick[$q['pick']])): ?><span class="pill"><?= h($prqPick[$q['pick']]) ?></span><?php endif; ?>
      <?php if ((string)$q['error'] !== ''): ?><span class="pill bad">Didn't send</span><?php endif; ?></summary>
    <form method="post" class="prq-form"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
      <?php if ((string)$q['error'] !== ''): ?><p class="bad small"><?= h((string)$q['error']) ?></p><?php endif; ?>
      <?php if (trim((string)$q['note']) !== ''): ?><p class="prq-note small"><strong>Drafter's notes:</strong> <?= nl2br(h((string)$q['note'])) ?></p><?php endif; ?>
      <div class="prq-links small"><a href="https://github.com/<?= h($q['repo']) ?>" target="_blank" rel="noopener">GitHub ↗</a> · <a href="https://github.com/<?= h($q['repo']) ?>/releases" target="_blank" rel="noopener">Releases ↗</a> · <a href="https://recomplauncher.com/game/<?= h($qa['id']) ?>" target="_blank" rel="noopener">Its page ↗</a></div>
      <div class="prq-grid">
        <label>Pull request title<input name="title" value="<?= h((string)$q['title']) ?>" maxlength="200"></label>
        <label class="prq-span">Pull request description<textarea name="body" rows="14"><?= h((string)$q['body']) ?></textarea></label>
        <label class="prq-span">recomplauncher.json<textarea name="json" rows="14" class="mono" spellcheck="false"><?= h((string)$q['json']) ?></textarea></label>
      </div>
      <div class="prq-btns">
        <button class="btn primary" name="action" value="prq_send" onclick="return confirm('Open this pull request on <?= h(addslashes((string)$q['repo'])) ?>?')"<?= $prqHasToken ? '' : ' disabled title="Add the GitHub token first"' ?>>Send pull request</button>
        <button class="btn" name="action" value="prq_save">Save</button>
        <button class="btn" name="action" value="prq_redo" title="The next daily run drafts it again">Draft it again</button>
        <button class="btn danger" name="action" value="prq_skip">Skip</button>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
  <?php if ($prqSent): ?>
  <h3 class="prq-h3">Sent lately</h3>
  <ul class="prq-sent"><?php foreach ($prqSent as $q): ?><li><a href="<?= h((string)$q['pr_url']) ?>" target="_blank" rel="noopener"><?= h((string)$q['repo']) ?></a><span class="muted small"><?= h((string)$q['sent_at']) ?><?= (string)$q['who'] !== '' ? ' · ' . h((string)$q['who']) : '' ?></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if (rs_is_owner()): ?>
  <details class="prq-setup"<?= !$prqHasToken || $prqKeyOnce !== '' ? ' open' : '' ?>><summary>Setup</summary>
    <div class="prq-setup-grid">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_token">
        <label>GitHub token for pull requests <span class="muted small"><?= $prqHasToken ? 'saved, for @' . h($prqLogin) : 'not set' ?></span>
          <input type="password" name="token" autocomplete="off" placeholder="<?= $prqHasToken ? 'Paste a new one to replace it, or leave empty and save to remove it' : 'A fine-grained or classic token with public_repo' ?>"></label>
        <small class="muted">Make it on github.com → Settings → Developer settings → Personal access tokens, signed in as the account the pull requests should come from. A classic token needs the <code>public_repo</code> scope. It's kept encrypted on the server.</small>
        <button class="btn">Save token</button></form>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_key">
        <label>Drafter key <span class="muted small"><?= (string)rs_setting('prq_key_hash', '') !== '' ? 'set' : 'not set' ?></span></label>
        <?php if ($prqKeyOnce !== ''): ?><input readonly class="mono" value="<?= h($prqKeyOnce) ?>" onclick="this.select()"><small class="warn">Copy it now into the RecompStore repo on GitHub as the secret <code>RS_PRQ_KEY</code> (Settings → Secrets and variables → Actions). It isn't shown again.</small>
        <?php else: ?><small class="muted">The daily GitHub Action sends it with every call. Making a new one stops the old one working.</small><?php endif; ?>
        <button class="btn">Make a new key</button></form>
    </div>
  </details>
  <?php endif; ?>
</section>
<section class="card sdk-card">
  <div class="sdk-meter" id="sdk-meter" role="img" aria-label="<?php foreach ($meter as $k => $l) echo number_format($count[$k]) . ' ' . $l . '. '; ?>">
    <?php foreach ($meter as $k => $l): ?><span class="m-<?= $k ?>" data-m="<?= $k ?>" data-l="<?= h($l) ?>" style="flex-grow:<?= $count[$k] ?>" title="<?= number_format($count[$k]) . ' ' . h($l) ?>"></span><?php endforeach; ?>
  </div>
  <div class="sdk-bar">
    <div class="sdk-chips" role="group" aria-label="Status">
      <?php foreach ($chips as $k => $label): ?><button type="button" class="sdk-chip<?= $k === 'all' ? ' on' : '' ?>" data-sdk-state="<?= $k ?>" aria-pressed="<?= $k === 'all' ? 'true' : 'false' ?>"><?php if ($k === 'badge'): ?><span class="sdk-star" aria-hidden="true">★</span><?php elseif ($k !== 'all'): ?><i class="dot m-<?= $k ?>" aria-hidden="true"></i><?php endif; ?><?= h($label) ?><span class="n" data-n="<?= $k ?>"><?= number_format($count[$k]) ?></span></button><?php endforeach; ?>
    </div>
    <div class="sdk-tools">
      <select id="sdk-kind" aria-label="Type"><option value="">Every type</option><option value="console">Console recomps</option><option value="pc">PC games</option></select>
      <input type="search" id="sdk-find" placeholder="Filter by name, game or repo" spellcheck="false" autocomplete="off" aria-label="Filter">
    </div>
  </div>
  <div class="sdk-shown-row">
    <p class="muted small sdk-shown" id="sdk-shown">Showing <?= number_format($count['all']) ?> of <?= number_format($count['all']) ?></p>
    <div class="sdk-picked" id="sdk-picked" hidden>
      <span class="small" id="sdk-picked-n">0 picked</span>
      <form method="post" data-sdk-ajax data-sdk-picks><?= $csrf ?><input type="hidden" name="action" value="recheck_ids"><input type="hidden" name="ids" value=""><button class="btn">Check picked again</button></form>
      <form method="post" data-sdk-picks><?= $csrf ?><input type="hidden" name="action" value="prq_queue"><input type="hidden" name="ids" value=""><button class="btn primary" title="The daily drafts do these first">Queue for PR drafts</button></form>
      <button type="button" class="btn" id="sdk-pick-none">Clear</button>
    </div>
  </div>
  <div class="sdk-scroll">
    <table class="sdk-table" id="sdk-table" data-now="<?= $pageNow ?>" data-todo="<?= $count['todo'] ?>">
      <thead><tr>
        <th class="c-pick"><input type="checkbox" id="sdk-pick-all" aria-label="Pick every recomp shown" title="Pick every recomp shown (the filters apply)"></th>
        <th class="c-rank"><button type="button" data-sdk-sort="rank" aria-sort="ascending">#</button></th>
        <th><button type="button" data-sdk-sort="name">Recomp</button></th>
        <th class="c-num"><button type="button" data-sdk-sort="dl">Downloads</button></th>
        <th><button type="button" data-sdk-sort="state">Status</button></th>
        <th class="c-act"><span class="sr-only">Actions</span></th>
      </tr></thead>
      <?php foreach ($rows as $row) echo rs_sdk_row_html($row, $csrf); ?>
      <tbody id="sdk-none" hidden><tr><td colspan="6" class="sdk-empty"><span class="muted">Nothing matches.</span> <a class="btn" id="sdk-lookup" href="#lookup">Look it up on GitHub</a></td></tr></tbody>
    </table>
  </div>
</section>
<section class="card" id="lookup">
  <h2>Not on RecompLauncher yet?</h2>
  <p class="muted small">For a recomp that isn't in the table above: paste its GitHub link or owner/name to get the agent prompt and badge, so a pull request can go out before it's listed. To list it, use Add it to the store (or Recomps, Found on GitHub). Ones already on RecompLauncher but hidden or Coming soon show here with a link to open them.</p>
  <form method="get" class="sdk-search" action="sdk-support.php#lookup">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="github.com/owner/name, or owner/name" spellcheck="false" autocomplete="off">
    <button class="btn primary">Look up</button><?php if ($q !== ''): ?> <a class="btn" href="sdk-support.php">Clear</a><?php endif; ?>
  </form>
  <?php if ($q !== ''): ?>
  <div class="sdk-list" style="margin-top:12px">
  <?php foreach ($found as $f): $a = $f['a']; [$sl, $sc] = RS_SDK_STATE[$f['state']]; ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($a['name']) ?></strong><span class="muted small"><?= h($a['repo']) ?> · <?= number_format((int)$a['downloads']) ?> downloads</span></div>
      <span class="pill<?= $sc ?>"><?= h($sl) ?></span>
      <div class="sdk-btns"><a class="btn" href="#r-<?= h($a['id']) ?>" data-sdk-jump="<?= h($a['id']) ?>">Show it in the table</a></div>
    </article>
  <?php endforeach; ?>
  <?php if ($outside): ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($outside['repo']) ?></strong><span class="muted small"><?= !empty($outside['missing']) ? 'GitHub has no repo by that name.' : 'Not on RecompLauncher yet' . ($outside['about'] !== '' ? ' · ' . h(mb_strimwidth($outside['about'], 0, 90, '…')) : '') ?></span></div>
      <span class="pill<?= !empty($outside['missing']) ? ' warn' : '' ?>"><?= !empty($outside['missing']) ? 'Not found' : 'Not listed' ?></span>
      <div class="sdk-btns">
        <?php if (empty($outside['missing'])): ?>
        <a class="btn" href="https://github.com/<?= h($outside['repo']) ?>" target="_blank" rel="noopener">GitHub ↗</a>
        <button type="button" class="btn primary" data-prompt-repo="<?= h($outside['repo']) ?>" data-prompt-id="">Copy agent prompt</button> <button type="button" class="btn" data-badge-id="">Copy badge</button>
        <?php if (rs_role_at_least('curator')): ?><a class="btn" href="pc.php#found">Add it to the store</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endif; ?>
  <?php if ($parked): ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($parked['name']) ?></strong><span class="muted small"><?= h($parked['repo']) ?> · on RecompLauncher, but <?= !empty($parked['hidden']) ? 'hidden' : 'still Coming soon' ?>, so it isn't in the table</span></div>
      <span class="pill"><?= !empty($parked['hidden']) ? 'Hidden' : 'Coming soon' ?></span>
      <div class="sdk-btns"><a class="btn" href="pc.php?edit=<?= h(rawurlencode($parked['id'])) ?>">Open it</a></div>
    </article>
  <?php endif; ?>
  <?php if (!$found && !$outside && !$parked): ?><p class="muted">Nothing matches "<?= h($q) ?>". Paste its GitHub link or owner/name.</p><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<div class="sdk-toast" id="sdk-toast" role="status" aria-live="polite" hidden></div>
<style>
.sdk-head-act{display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.sdk-head-act form{margin:0}
.sdk-prog{display:inline-flex;align-items:center;gap:8px;color:var(--muted);font:600 14px Figtree,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap}
.sdk-prog[hidden]{display:none}
.sdk-spin{width:14px;height:14px;border-radius:50%;border:2px solid rgba(var(--ink-rgb),.2);border-top-color:var(--cyan-ink);animation:sdkspin .8s linear infinite}
@keyframes sdkspin{to{transform:rotate(360deg)}}
.sdk-meter{display:flex;height:10px;border-radius:999px;overflow:hidden;background:rgba(var(--ink-rgb),.08);margin:0 0 14px;gap:2px}
.sdk-meter span{flex-basis:0;min-width:0;transition:flex-grow .6s ease}
.m-ships{background:var(--ok)}.m-missing{background:var(--bad)}.m-pr{background:var(--blue)}.m-no{background:var(--warn)}.m-locked{background:var(--violet)}.m-unknown{background:rgba(var(--ink-rgb),.28)}
.sdk-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 10px}
.sdk-chips{display:flex;flex-wrap:wrap;gap:8px}
.sdk-chip{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border-radius:999px;border:1px solid rgba(var(--ink-rgb),.16);background:rgba(var(--ink-rgb),.06);color:var(--frost);font:600 14px Figtree,sans-serif;cursor:pointer}
.sdk-chip .n{opacity:.7;font-weight:700}
.sdk-chip .dot{width:9px;height:9px;border-radius:50%;flex:none}
.sdk-chip.on{background:var(--cyan);border-color:var(--cyan);color:var(--on-accent)}
.sdk-chip.on .sdk-star{color:var(--on-accent)}
.sdk-chip:focus-visible{outline:2px solid var(--cyan);outline-offset:2px}
.sdk-star{color:#f5b301;font-size:15px;line-height:1}
.sdk-tools{display:flex;gap:8px;align-items:center}
.sdk-tools select,.sdk-tools input{height:40px;padding:0 12px;margin:0;box-sizing:border-box;font-size:14px;line-height:normal}
.sdk-tools select{min-width:160px}
.sdk-tools input{min-width:260px}
.sdk-shown{margin:0 0 8px}
.sdk-scroll{max-height:min(1360px,calc(100vh - 150px));overflow:auto;border:1px solid var(--line);border-radius:14px;overscroll-behavior:contain}
.sdk-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14.5px}
.sdk-table thead th{position:sticky;top:0;z-index:2;background:var(--surface);text-align:left;padding:0;border-bottom:1px solid var(--line);white-space:nowrap}
.sdk-table thead th button,.sdk-table thead th>span{display:block;width:100%;padding:10px 12px;background:none;border:0;color:var(--muted);font:600 13.5px Figtree,sans-serif;text-align:inherit;cursor:pointer}
.sdk-table thead th button:hover{color:var(--frost)}
.sdk-table thead th button[aria-sort]::after{content:" ↓";color:var(--cyan-ink)}
.sdk-table thead th button[aria-sort=ascending]::after{content:" ↑"}
.sdk-table td{padding:6px 12px;border-bottom:1px solid rgba(var(--ink-rgb),.06);vertical-align:middle}
.sdk-item:hover>tr:first-child td{background:rgba(var(--ink-rgb),.03)}
.sdk-table .c-rank{width:40px;color:var(--muted);font-weight:700;font-variant-numeric:tabular-nums}
.sdk-table .c-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.sdk-table thead .c-num button{text-align:right}
.sdk-table .c-act{width:1%;white-space:nowrap}
.sdk-table .c-act .sdk-btns{flex-wrap:nowrap}
.sdk-who{display:flex;gap:10px;align-items:center;min-width:0}
.sdk-who img,.sdk-ico{width:32px;height:32px;border-radius:9px;object-fit:cover;flex:none}
.sdk-ico{display:grid;place-items:center;background:rgba(var(--ink-rgb),.08);font:700 12px Sora,sans-serif}
.sdk-name{display:flex;flex-direction:column;min-width:0}
.sdk-title{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px}
.sdk-title .sdk-star{margin-right:6px;vertical-align:1px}
.sdk-name a{color:var(--frost);text-decoration:none}
.sdk-name>.small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:300px}
.sdk-st{display:flex;flex-direction:column;align-items:flex-start;gap:3px}
.sdk-st .pill{white-space:nowrap;margin-left:0}
.sdk-st .small{white-space:nowrap;font-size:12.5px}
.pill.bad{background:rgba(255,138,128,.16);color:var(--bad)}
.pill.blue{background:rgba(143,193,255,.16);color:var(--blue)}
.sdk-btns{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.sdk-btns-l{justify-content:flex-start}
.sdk-btns form{margin:0}
.sdk-btns .btn{min-height:32px;padding:5px 11px;font-size:13.5px}
.sdk-more{min-width:38px;font-size:16px!important;letter-spacing:1px}
.sdk-more[aria-expanded=true]{background:rgba(var(--ink-rgb),.12)}
.sdk-extra td{background:rgba(var(--ink-rgb),.035)}
.sdk-extra-in{display:flex;flex-direction:column;gap:10px;padding:6px 0}
.sdk-empty{text-align:center;padding:24px 12px!important}
.sdk-mark-forms{display:grid;grid-template-columns:minmax(0,1fr);gap:10px}
.sdk-mark-note{display:flex;align-items:center;min-height:40px;color:var(--muted);font-size:13px;font-weight:400}
.pill.violet{background:rgba(205,184,255,.16);color:var(--violet)}
.sdk-mark-form{display:flex;gap:8px;align-items:flex-end}
.sdk-mark-form label{flex:1;margin:0;font-size:14px}
.btn.sdk-busy{opacity:.6;pointer-events:none}
.sdk-search{display:flex;gap:8px;align-items:center}
.sdk-search input{flex:1;min-width:0}
.sdk-list{display:flex;flex-direction:column;gap:8px}
.sdk-row-s{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:14px;align-items:center;padding:10px 12px;border:1px solid var(--line);border-radius:14px}
.sdk-flash td{animation:sdkflash 1.6s ease-out}
@keyframes sdkflash{from{background:rgba(52,242,232,.22)}to{background:transparent}}
.sdk-toast{position:fixed;right:20px;bottom:20px;z-index:50;max-width:420px;padding:12px 16px;border-radius:12px;background:var(--surface);border:1px solid var(--line);box-shadow:0 8px 30px rgba(0,0,0,.35);font-size:14.5px}
.sdk-toast[hidden]{display:none}
.sdk-toast.err{border-color:var(--bad);color:var(--bad)}
.c-pick{width:34px;text-align:center}.c-pick input{width:16px;height:16px;margin:0;cursor:pointer}
.sdk-shown-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.sdk-picked{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.sdk-picked form{margin:0}.sdk-picked[hidden]{display:none}
.prq-card .an-head{margin-bottom:6px}
.prq-item{border:1px solid var(--line);border-radius:12px;margin-top:10px}
.prq-item summary{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;list-style:none}
.prq-item summary::-webkit-details-marker{display:none}
.prq-name{display:flex;flex-direction:column;flex:1;min-width:0}
.prq-form{padding:0 14px 14px}
.prq-grid{display:grid;grid-template-columns:1fr;gap:10px;margin:10px 0}
.prq-grid label{display:flex;flex-direction:column;gap:6px}
.prq-grid textarea{font-size:13.5px;line-height:1.45}
.prq-btns{display:flex;gap:8px;flex-wrap:wrap}
.prq-note{background:var(--surface-2,rgba(255,255,255,.04));border-radius:10px;padding:10px 12px}
.prq-h3{margin:18px 0 6px;font-size:15px}
.prq-sent{list-style:none;margin:0;padding:0;display:grid;gap:6px}.prq-sent li{display:flex;justify-content:space-between;gap:10px}
.prq-setup{margin-top:16px;border-top:1px solid var(--line);padding-top:12px}.prq-setup summary{cursor:pointer;font-weight:600}
.prq-setup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:10px}
.prq-setup-grid form{display:flex;flex-direction:column;gap:8px;padding:14px;border:1px solid var(--line);border-radius:12px}.prq-setup-grid form > .btn{margin-top:auto;align-self:flex-start}.prq-setup-grid label{display:flex;flex-direction:column;gap:6px}
@media (max-width:820px){.prq-setup-grid{grid-template-columns:1fr}}

.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
@media (prefers-reduced-motion:reduce){.sdk-spin{animation:none}.sdk-meter span{transition:none}}
@media (max-width:860px){.sdk-tools{width:100%}.sdk-tools input{min-width:0;flex:1}.sdk-table .c-rank,.sdk-table .c-num{display:none}.sdk-mark-forms{grid-template-columns:minmax(0,1fr)}.sdk-row-s{grid-template-columns:minmax(0,1fr)}.sdk-name>.small{max-width:200px}}
</style>
<script>
(function () {
  var table = document.getElementById('sdk-table');
  if (!table) return;
  var none = document.getElementById('sdk-none');
  var shown = document.getElementById('sdk-shown');
  var find = document.getElementById('sdk-find');
  var kind = document.getElementById('sdk-kind');
  var lookup = document.getElementById('sdk-lookup');
  var prog = document.getElementById('sdk-prog'), progT = document.getElementById('sdk-prog-t'), spin = prog.querySelector('.sdk-spin');
  var toast = document.getElementById('sdk-toast'), toastT = 0;
  var order = {missing: 0, unknown: 1, pr: 2, locked: 3, no: 4, ships: 5};
  var st = {state: 'all', kind: '', q: '', sort: 'rank', dir: 1};
  var since = Number(table.dataset.now), polling = 0;
  function items() { return Array.prototype.slice.call(table.querySelectorAll('tbody.sdk-item')); }
  try { var saved = JSON.parse(sessionStorage.getItem('sdk-view') || 'null'); if (saved) { st.state = saved.state || 'all'; st.kind = saved.kind || ''; st.sort = saved.sort === 'now' ? 'rank' : (saved.sort || 'rank'); st.dir = saved.dir || 1; } } catch (e) {}
  function save() { try { sessionStorage.setItem('sdk-view', JSON.stringify({state: st.state, kind: st.kind, sort: st.sort, dir: st.dir})); } catch (e) {} }
  function fmt(n) { return Number(n).toLocaleString(); }
  function apply() {
    var n = 0, all = items(), q = st.q.toLowerCase().trim();
    // A pasted GitHub link filters by its owner/name.
    var m = q.match(/^(?:https?:\/\/)?(?:www\.)?github\.com\/([\w.-]+\/[\w.-]+)/);
    if (m) q = m[1].replace(/\.git$/, '');
    all.forEach(function (it) {
      var ok = (st.state === 'all' || it.dataset.state === st.state || (st.state === 'badge' && it.dataset.badge === '1')) && (!st.kind || it.dataset.kind === st.kind) && (!q || it.dataset.find.indexOf(q) !== -1);
      it.hidden = !ok;
      if (ok) n++;
    });
    none.hidden = n > 0;
    if (lookup) { lookup.hidden = !st.q.trim(); lookup.href = 'sdk-support.php?q=' + encodeURIComponent(st.q.trim()) + '#lookup'; }
    shown.textContent = 'Showing ' + fmt(n) + ' of ' + fmt(all.length);
    // (4.6) With a filter on, "Check them all again" checks just the ones shown.
    var allB = document.getElementById('sdk-all-b');
    if (allB) allB.textContent = n < all.length ? 'Check the ' + fmt(n) + ' shown again' : 'Check them all again';
    if (typeof picked === 'function') picked();
    document.querySelectorAll('[data-sdk-state]').forEach(function (b) { var on = b.dataset.sdkState === st.state; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    kind.value = st.kind;
  }
  function key(it) {
    if (st.sort === 'name') return it.dataset.name;
    if (st.sort === 'dl') return -Number(it.dataset.dl);
    if (st.sort === 'state') return order[it.dataset.state] * 1e6 + Number(it.dataset.rank);
    return Number(it.dataset.rank);
  }
  function sort() {
    items().sort(function (a, b) { var x = key(a), y = key(b); return (x < y ? -1 : x > y ? 1 : 0) * st.dir; }).forEach(function (it) { table.insertBefore(it, none); });
    table.querySelectorAll('[data-sdk-sort]').forEach(function (b) {
      if (b.dataset.sdkSort === st.sort) b.setAttribute('aria-sort', st.dir === 1 ? 'ascending' : 'descending'); else b.removeAttribute('aria-sort');
    });
  }
  // The counts, the colored line and the progress next to the button.
  function counts(c) {
    document.querySelectorAll('[data-n]').forEach(function (el) { if (c[el.dataset.n] !== undefined) el.textContent = fmt(c[el.dataset.n]); });
    var label = [];
    document.querySelectorAll('[data-m]').forEach(function (el) {
      var t = fmt(c[el.dataset.m]) + ' ' + el.dataset.l;
      el.style.flexGrow = c[el.dataset.m]; el.title = t; label.push(t);
    });
    document.getElementById('sdk-meter').setAttribute('aria-label', label.join('. '));
    table.dataset.todo = c.todo;
    if (c.todo > 0) { prog.hidden = false; spin.hidden = false; progT.textContent = 'Checking: ' + fmt(c.all - c.todo) + ' of ' + fmt(c.all) + ' done'; }
    else if (!prog.hidden) { spin.hidden = true; progT.textContent = 'All ' + fmt(c.all) + ' checked'; setTimeout(function () { if (Number(table.dataset.todo) === 0) prog.hidden = true; }, 5000); }
  }
  // A row from the server takes the old one's place, keeping its ⋯ open if it was.
  function put(id, html) {
    var old = document.getElementById('r-' + id);
    if (!old || !html) return;
    var wasOpen = old.querySelector('.sdk-more[aria-expanded=true]');
    var tmp = document.createElement('table'); tmp.innerHTML = html.trim();
    var neu = tmp.querySelector('tbody'); if (!neu) return;
    old.replaceWith(neu);
    if (wasOpen) { var b = neu.querySelector('.sdk-more'), r = neu.querySelector('.sdk-extra'); if (b && r) { b.setAttribute('aria-expanded', 'true'); r.hidden = false; } }
    neu.classList.add('sdk-flash'); setTimeout(function () { neu.classList.remove('sdk-flash'); }, 1700);
  }
  function say(msg, err) {
    if (!msg) return;
    toast.textContent = msg; toast.className = 'sdk-toast' + (err ? ' err' : ''); toast.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { toast.hidden = true; }, err ? 7000 : 4000);
  }
  function poll() {
    if (polling) return;
    polling = setTimeout(function tick() {
      fetch('sdk-support.php?progress=1&since=' + since, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var ids = Object.keys(j.rows || {});
          ids.forEach(function (id) { put(id, j.rows[id]); });
          if (ids.length) { if (st.sort === 'state') sort(); apply(); }
          since = j.now - 1;
          counts(j.count);
          polling = j.count.todo > 0 ? setTimeout(tick, document.hidden ? 15000 : 5000) : 0;
        })
        .catch(function () { polling = setTimeout(tick, 15000); });
    }, 4000);
  }
  // (4.6) Xarishark: pick rows (or every one shown), then Check picked again or queue them for PR drafts.
  var pickAll = document.getElementById('sdk-pick-all'), pickBar = document.getElementById('sdk-picked'), pickN = document.getElementById('sdk-picked-n');
  function pickedIds() { return Array.prototype.slice.call(table.querySelectorAll('.sdk-pick:checked')).map(function (c) { return c.value; }); }
  function shownIds() { return items().filter(function (it) { return !it.hidden; }).map(function (it) { return it.dataset.id; }); }
  function picked() {
    if (!pickBar) return; // apply() runs before this part is set up
    var ids = pickedIds(), vis = items().filter(function (it) { return !it.hidden; });
    pickBar.hidden = !ids.length;
    pickN.textContent = fmt(ids.length) + ' picked';
    var visPicked = vis.filter(function (it) { var c = it.querySelector('.sdk-pick'); return c && c.checked; }).length;
    pickAll.checked = vis.length > 0 && visPicked === vis.length;
    pickAll.indeterminate = visPicked > 0 && visPicked < vis.length;
  }
  pickAll.addEventListener('change', function () {
    items().forEach(function (it) { if (!it.hidden) { var c = it.querySelector('.sdk-pick'); if (c) c.checked = pickAll.checked; } });
    picked();
  });
  table.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('sdk-pick')) picked(); });
  document.getElementById('sdk-pick-none').addEventListener('click', function () { table.querySelectorAll('.sdk-pick:checked').forEach(function (c) { c.checked = false; }); picked(); });
  // Fill in which ones before the form goes (this runs ahead of the no-reload sender below).
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches && f.matches('[data-sdk-picks]')) f.ids.value = pickedIds().join(',');
    if (f.id === 'sdk-all') { var sh = shownIds(); f.ids.value = sh.length < items().length ? sh.join(',') : ''; }
  }, true);
  // Check again, the marks and Check them all again, without reloading the page.
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-sdk-ajax]');
    if (!f || !window.fetch) return;
    e.preventDefault();
    var btn = f.querySelector('button'), was = btn.textContent;
    var fd = new FormData(f); fd.append('ajax', '1');
    btn.classList.add('sdk-busy'); btn.textContent = fd.get('action') === 'recheck' ? 'Checking…' : (fd.get('action') === 'recheck_all' ? 'Starting…' : 'Saving…');
    fetch('sdk-support.php', {method: 'POST', body: fd, credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then(function (r) { if (!r.ok) throw new Error(r.status === 400 ? 'This page expired. Reload it and try again.' : 'Something went wrong. Try again.'); return r.json(); })
      .then(function (j) {
        if (j.row) { put(j.id, j.row); if (st.sort === 'state') sort(); apply(); }
        if (j.now) since = Math.min(since, j.now - 1);
        counts(j.count);
        say(j.msg, !j.ok);
        if (j.count.todo > 0) poll();
      })
      .catch(function (err) { say(err.message, true); })
      .then(function () { if (document.body.contains(btn)) { btn.classList.remove('sdk-busy'); btn.textContent = was; } });
  });
  document.querySelectorAll('[data-sdk-state]').forEach(function (b) { b.addEventListener('click', function () { st.state = b.dataset.sdkState; save(); apply(); }); });
  kind.addEventListener('change', function () { st.kind = kind.value; save(); apply(); });
  find.addEventListener('input', function () { st.q = find.value; apply(); });
  find.addEventListener('keydown', function (e) { if (e.key === 'Escape' && find.value) { e.preventDefault(); find.value = ''; st.q = ''; apply(); } });
  table.querySelectorAll('[data-sdk-sort]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.dataset.sdkSort;
      if (st.sort === k) st.dir = -st.dir; else { st.sort = k; st.dir = 1; }
      save(); sort();
    });
  });
  table.addEventListener('click', function (e) {
    var b = e.target.closest('.sdk-more');
    if (!b) return;
    var row = document.getElementById(b.getAttribute('aria-controls'));
    var open = b.getAttribute('aria-expanded') !== 'true';
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
    row.hidden = !open;
  });
  // Show one row: clear the filters that hide it, open its ⋯, scroll it into view.
  function jump(id) {
    var it = document.getElementById('r-' + id);
    if (!it) return;
    if (it.hidden) { st.state = 'all'; st.kind = ''; st.q = ''; find.value = ''; apply(); }
    var more = it.querySelector('.sdk-more'), row = it.querySelector('.sdk-extra');
    if (more && row) { more.setAttribute('aria-expanded', 'true'); row.hidden = false; }
    it.scrollIntoView({block: 'center'});
    it.classList.add('sdk-flash');
    setTimeout(function () { it.classList.remove('sdk-flash'); }, 1700);
  }
  document.addEventListener('click', function (e) { var a = e.target.closest('[data-sdk-jump]'); if (a) { e.preventDefault(); jump(a.dataset.sdkJump); } });
  sort();
  apply();
  if (Number(table.dataset.todo) > 0) poll();
  if (location.hash.indexOf('#r-') === 0) jump(decodeURIComponent(location.hash.slice(3)));
})();
</script>
<?php rs_foot(['assets/sdk-prompt.js']);
