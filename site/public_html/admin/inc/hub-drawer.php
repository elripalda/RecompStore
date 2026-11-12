<?php
// (4.8) The Submissions hub's drawer (hub.php?item=sub:<id> | job:<id> | live:<id>): one submission or
// Auto-add draft with its checks, what they sent, the conversation and its buttons.
declare(strict_types=1);

$key = (string)$_GET['item'];
$csrfIn = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$gone = function (string $why): void { echo '<div class="sh-d-gone"><p>' . h($why) . '</p><button type="button" data-close>Close</button></div>'; };
$sysName = ['win' => 'Windows', 'mac' => 'macOS', 'linux' => 'Linux'];

// ---- "It's live": the email to the developer, after Publish -----------------------------------------
if (preg_match('/^live:(\d+)$/', $key, $m)) {
    $lm = rs_hub_live_mail((int)$m[1]);
    if (!$lm) { $gone('It\'s live. There was no email to write for this one.'); return; }
    $ready = rs_mail_ready() && $lm['to'] !== ''; ?>
  <button type="button" class="sh-close" data-close aria-label="Close"><?= HUB_IC['x'] ?></button>
  <div class="sh-d-live">
    <span class="sh-live-ic"><?= HUB_IC['check'] ?></span>
    <h2><?= h($lm['name']) ?> is live</h2>
    <p class="muted">It's on RecompLauncher and off the board. Let the developer know.</p>
  </div>
  <form class="sh-d-sec sh-mail" data-form="live_send">
    <input type="hidden" name="item" value="sub:<?= (int)$m[1] ?>">
    <label>To<input value="<?= h($lm['to'] !== '' ? $lm['to'] : 'They left no email address') ?>" disabled></label>
    <label>Subject<input name="subject" value="<?= h($lm['subject']) ?>" maxlength="200" required></label>
    <label>Message<textarea name="body" rows="12" required><?= h($lm['body']) ?></textarea></label>
    <p class="muted small"><?= $ready ? 'It goes from ' . h(rs_mail_get('mail_from')) . ' with the usual signature.' : ($lm['to'] === '' ? 'Copy the message and send it to them another way (Discord, their hub).' : 'The mailbox isn\'t set up, so copy the message and send it yourself.') ?></p>
    <div class="sh-d-foot"><?php if ($ready): ?><button class="primary">Send the email</button><?php endif; ?><button type="button" data-close><?= $ready ? 'Not now' : 'Done' ?></button></div>
  </form>
<?php
    return;
}

// ---- Find what was asked for ----------------------------------------------------------------------
$job = null; $subId = 0; $r = null; $d = null;
if (preg_match('/^job:(\d+)$/', $key, $m)) {
    $job = rs_auto_get((int)$m[1]);
    if (!$job || in_array($job['status'], ['removed', 'dismissed'], true)) { $gone('That draft isn\'t on the board anymore.'); return; }
    if (!rs_hub_store_ok('pc')) { $gone('Auto-add drafts are for the PC store.'); return; }
    $subId = (int)$job['sub_id'];
} elseif (preg_match('/^sub:(\d+)$/', $key, $m)) {
    $subId = (int)$m[1];
    // A submission whose draft is still open shows as the draft.
    $st = rs_db()->prepare("SELECT id FROM pc_auto WHERE sub_id = ? AND status IN ('queued', 'running', 'review', 'failed') ORDER BY id DESC LIMIT 1");
    try { $st->execute([$subId]); if ($jid = (int)$st->fetchColumn()) $job = rs_auto_get($jid); } catch (Throwable $e) { /* no Auto-add yet */ }
} else { $gone('That isn\'t on the board.'); return; }
if ($subId > 0) {
    $s = rs_hub_sub($subId);
    if ($s) [$r, $d] = $s;
    elseif (!$job) { $gone('That submission is gone.'); return; }
    if ($d !== null && !rs_hub_store_ok(rs_hub_sub_store($d))) { $gone('That submission is for a store you don\'t work on.'); return; }
}
$item = $subId > 0 && $d !== null ? 'sub:' . $subId : 'job:' . (int)$job['id'];
$email = $r ? rs_inbox_email($r, null) : ''; // a draft from a link or the scout has no submission
$chk = ['steps' => []];
$app = $job && $job['app_id'] !== '' ? rs_pc_app($job['app_id']) : null;
$pcSide = $job !== null || ($d !== null && ($d['platform'] ?? '') === 'pc');
$pics = $subId > 0 ? (rs_hub_pics([$subId])[$subId] ?? []) : [];
$byRole = fn(string $role) => array_values(array_filter($pics, fn($p) => $p['role'] === $role));
$name = (string)($app['name'] ?? ($d['name'] ?? rs_hub_job_path((string)($job['repo_key'] ?? ''))));
$host = $d !== null ? rs_host_norm((string)($d['host'] ?? '')) : rs_hub_job_host((string)$job['repo_key']);
$repo = $d !== null ? (string)($d['repo'] ?? '') : rs_hub_job_path((string)$job['repo_key']);
$repoUrl = $repo !== '' ? rs_host_web($host, $repo) : '';
// The developer: their hub account (live, so a badge they earned since shows) or what they typed.
$acc = $d !== null && !empty($d['account']) ? rs_dev_account((int)$d['account']) : null;
$accInfo = $acc ? (rs_dev_msg_names([(int)$acc['id']])[(int)$acc['id']] ?? null) : null;
$verified = ($d !== null && !empty($d['verified'])) || !empty($accInfo['verified']);
$dev = $d !== null ? trim((string)($d['author'] ?? '')) : '';
$heroImg = ($h = $byRole('hero')) ? 'picture.php?id=' . (int)$h[0]['id'] : (string)($app['hero'] ?? '');
$iconImg = ($i = $byRole('icon')) ? 'picture.php?id=' . (int)$i[0]['id'] : (string)($app['icon'] ?? '');
$openFlags = array_values(array_filter(rs_hub_flags('open', 200), fn($f) => $f['item'] === $item));
$state = $job ? 'job-' . $job['status'] : ($r['status'] !== 'done' ? 'new' : (!empty($d['declined']) ? 'back' : (!empty($d['ps5_draft']) ? 'draft' : 'handled')));
$by = $verified ? '<span class="sh-ver">' . HUB_IC['ver'] . '</span>' . h($dev !== '' ? $dev : (string)($d['account_name'] ?? '')) . ' · <b>Verified developer</b>'
    : ($d !== null ? h($dev !== '' ? $dev : 'No name given') . ($acc ? ' · Developer account ' . h(rs_dev_label($acc)) : ' · No developer account') : h(rs_hub_job_src($job) === 'Link' ? 'Added by ' . (string)$job['who'] : rs_hub_job_src($job)));
$canMsg = $d !== null && ($acc || $email !== '');
$devName = $dev !== '' ? $dev : ($acc ? rs_dev_label($acc) : 'them');
$canMail = $email !== '' && rs_mail_ready();
// (4.8) What VirusTotal flagged, file by file with its report, and the note to the developer about it.
$vtFiles = []; $vtTag = '';
$vtSec = function () use (&$vtFiles, $d, $canMail, $acc, $devName): void {
    if (!array_filter($vtFiles, fn($f) => $f['hits'] > 0)) return;
    usort($vtFiles, fn($x, $y) => $y['hits'] <=> $x['hits']);
    $tell = []; ?>
<section class="sh-d-sec">
  <h3>VirusTotal <small>open a report to see what each engine said</small></h3>
  <ol class="sh-steps sh-vt">
    <?php foreach ($vtFiles as $f): $st = $f['state'] === 'skip' ? 'ok' : $f['state']; ?>
    <li class="sh-s-<?= h($st) ?>"><span class="sh-s-i"><?= hub_step_icon($st) ?></span><span><b><?= h($f['name']) ?></b><small><?= h($f['state'] === 'run' ? 'Still checking' : ($f['state'] === 'skip' ? 'Too big to check' : ($f['hits'] ? 'Flagged by ' . $f['hits'] . ($f['engines'] ? ' of ' . $f['engines'] : '') . ($f['clam'] ? ' (ClamAV)' : ' engines') . ($f['by'] !== '' ? ': ' . $f['by'] : '') : 'Clean' . ($f['engines'] ? ' (0 of ' . $f['engines'] . ')' : '')))) ?></small></span>
      <?php if ($f['link'] !== ''): ?><a class="btn small sh-vt-go" href="<?= h($f['link']) ?>" target="_blank" rel="noopener noreferrer">View report <?= HUB_IC['ext'] ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($d !== null):
      if ($canMail) $tell[] = '<button type="button" data-open-panel="vt" data-via="email">Email ' . h($devName) . ' about it</button>';
      if ($acc) $tell[] = '<button type="button" data-open-panel="vt" data-via="hub">Message them on their hub</button>';
      if (!$tell) $tell[] = '<button type="button" data-open-panel="vt">Write it up to send them</button>'; ?>
  <div class="sh-vt-acts"><?= implode('', $tell) ?></div>
  <?php endif; ?>
</section>
<?php
};
?>
<button type="button" class="sh-close" data-close aria-label="Close"><?= HUB_IC['x'] ?></button>
<div class="sh-d-hero" style="--h:<?= hub_hue($name) ?>"><?= $heroImg !== '' ? '<img src="' . h($heroImg) . '" alt="">' : '' ?>
  <div class="sh-d-id"><?= hub_tile($name, $iconImg) ?><div><h2><?= h($name) ?></h2><span class="sh-by big<?= $verified ? ' ver' : '' ?>"><?= $by ?></span></div></div>
</div>
<div class="sh-d-meta">
  <span class="sh-src"><?= h($job ? rs_hub_job_src($job) : 'Submission') ?></span>
  <?php if ($d !== null && !empty($d['resubmitted'])): ?><span class="sh-tag cyan" title="<?= h('We asked for: ' . (string)($d['was_declined']['reason'] ?? '')) ?>">Sent again with changes</span><?php endif; ?>
  <span><?= h($pcSide ? (RS_PC_ORIGINS[(string)($app['origin'] ?? ($d['origin'] ?? ''))] ?? 'Console not given') : rs_hub_cat((string)($d['category'] ?? ''))) ?></span>
  <span><?= h(($job && !$d ? 'Started ' : 'Sent ') . rs_date('M j, g:i a', rs_hub_ts((string)($r['created'] ?? $job['created'])))) ?></span>
  <?php if ($repoUrl !== ''): ?><a href="<?= h($repoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://(www\.)?#', '', $repoUrl)) ?> <?= HUB_IC['ext'] ?></a><?php endif; ?>
</div>
<?php foreach ($openFlags as $f): ?>
<div class="sh-d-flag"><?= HUB_IC['flag'] ?><div><b>Flagged by <?= h((string)$f['who']) ?></b> <span class="muted small"><?= h(rs_hub_ago_long(rs_hub_ts((string)$f['created']))) ?></span><p><?= nl2br(h((string)$f['note'])) ?></p>
  <?php if ($f['answer'] !== null && $f['answer'] !== ''): ?><p class="sh-d-ans"><b><?= h((string)$f['answered_by']) ?>:</b> <?= nl2br(h((string)$f['answer'])) ?></p><?php elseif (rs_hub_can_manage()): ?>
  <form class="sh-row" data-form="flag_answer"><input type="hidden" name="flag" value="<?= (int)$f['id'] ?>"><input name="body" placeholder="Answer <?= h((string)$f['who']) ?>" required><button class="primary" name="close" value="1">Answer and close</button></form><?php else: ?><p class="muted small">Waiting for <?= h($GLOBALS['owner']) ?>'s answer.</p><?php endif; ?></div></div>
<?php endforeach; ?>
<?php if ($state === 'back'): ?><div class="sh-d-state wait"><b>Sent back <?= h(rs_hub_ago_long((int)strtotime((string)$d['declined']['at']))) ?><?= !empty($d['declined']['by']) ? ' by ' . h((string)$d['declined']['by']) : '' ?>.</b> We asked for: <?= h((string)$d['declined']['reason']) ?>. It comes back to New when they send their fix.</div>
<?php elseif ($state === 'draft'): ?><div class="sh-d-state ok"><b>Added to PS5 games as a hidden draft (<?= h((string)$d['ps5_draft']) ?>).</b> It leaves the board when the PS5 store is published with it shown.</div>
<?php elseif ($state === 'handled'): ?><div class="sh-d-state<?= !empty($d['turned_down']) ? ' bad' : ' ok' ?>"><b><?= !empty($d['turned_down']) ? 'Declined' : (!empty($d['handled']) ? 'Marked done' : 'Handled') ?></b><?= !empty($d['turned_down']) ? ': ' . h((string)$d['turned_down']['reason']) : (!empty($d['handled']) ? ': ' . h((string)$d['handled']['reason']) : '. It\'s live, or it was added another way.') ?></div><?php endif; ?>

<?php
// ---- How to approve (a submission) or how it was checked (a draft) ----------------------------------
if ($job):
    [$col, $note, $stuck, $vt] = rs_hub_job_state($job);
    $steps = $job['steps'];
    if (($steps['vt']['s'] ?? '') !== 'skip') $steps['vt'] = ['s' => $vt[0], 'n' => $vt[1]];
    $done = count(array_filter(array_keys(RS_AUTO_STEPS), fn($k) => in_array($steps[$k]['s'] ?? '', ['ok', 'warn', 'bad', 'skip'], true)));
    $live = in_array($job['status'], ['queued', 'running'], true) && !$stuck; ?>
<section class="sh-d-sec">
  <h3><?= $job['status'] === 'review' ? 'How it was checked' : ($live ? 'Checking it now' : 'Where it stopped') ?> <small><?= $job['status'] === 'review' ? 'everything below ran on its own; look over anything orange or red' : ($live ? $done . ' of ' . count(RS_AUTO_STEPS) . ' done · this updates by itself' : 'run it again, or flag it if it keeps stopping') ?></small></h3>
  <div class="sh-bar"><span style="width:<?= round($done / count(RS_AUTO_STEPS) * 100) ?>%"></span></div>
  <ol class="sh-steps two">
    <?php foreach (RS_AUTO_STEPS as $k => $label): $s = $steps[$k] ?? ['s' => '', 'n' => '']; $st = (string)($s['s'] ?? ''); if ($st === 'run' && !$live && $k !== 'vt') $st = ''; ?>
    <li class="sh-s-<?= h($st !== '' ? $st : 'wait') ?>"><span class="sh-s-i"><?= $st === '' ? '' : hub_step_icon($st) ?></span><span><b><?= h($label) ?></b><?php if ((string)($s['n'] ?? '') !== ''): ?><small><?= h((string)$s['n']) ?></small><?php endif; ?></span></li>
    <?php endforeach; ?>
  </ol>
  <?php if (trim((string)$job['notes']) !== ''): ?><div class="sh-d-notes"><b><?= $job['verdict'] === 'skip' ? 'Why Claude says not to list it' : 'Worth a look' ?></b><p><?= nl2br(h(trim((string)$job['notes']))) ?></p></div><?php endif; ?>
</section>
<?php $vtTag = (string)$job['tag']; $vtFiles = rs_hub_vt_files((string)$job['repo_key'], $vtTag); $vtSec(); ?>
<?php if ($app && $job['status'] === 'review'): ?>
<section class="sh-d-sec">
  <h3>Its page <small>what players will see; <?= rs_can_page('pc.php') ? '<a href="pc.php?edit=' . h(rawurlencode($app['id'])) . '#edit">edit it</a>' : 'flag anything that needs changing' ?></small></h3>
  <div class="sh-preview">
    <?php if ((string)$app['cover'] !== ''): ?><img class="sh-pv-cover" src="<?= h((string)$app['cover']) ?>" alt="Cover"><?php else: ?><span class="sh-pv-cover none">No cover</span><?php endif; ?>
    <div>
      <b><?= h((string)$app['name']) ?></b>
      <?php if ((string)$app['blurb'] !== ''): ?><p class="sh-pv-blurb"><?= h((string)$app['blurb']) ?></p><?php else: ?><p class="warn small">No one-line summary</p><?php endif; ?>
      <div class="sh-pv-tags"><?php foreach (array_slice(array_filter(array_map('trim', is_array($app['tags']) ? $app['tags'] : explode(',', (string)$app['tags']))), 0, 6) as $t): ?><span><?= h($t) ?></span><?php endforeach; ?></div>
      <?php if ((string)$app['needs'] !== ''): ?><p class="small muted">Players need: <?= h((string)$app['needs']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php if (trim((string)$app['description']) !== ''): ?><div class="sh-desc clamp" data-clamp><?= nl2br(h(trim((string)$app['description']))) ?></div><?php endif; ?>
  <div class="sh-art"><?php foreach (['hero' => 'Banner', 'logo' => 'Logo', 'icon' => 'Icon'] as $k => $l): ?><figure><?= (string)$app[$k] !== '' ? '<img src="' . h((string)$app[$k]) . '" alt="">' : '<span class="none">None</span>' ?><figcaption><?= $l ?></figcaption></figure><?php endforeach; ?></div>
</section>
<?php endif; ?>
<?php elseif (in_array($state, ['new', 'back'], true)):
    $chk = rs_hub_check($subId, $d, !empty($_GET['fresh']) ? 30 : 900); // opened: the release as it is now ?>
<section class="sh-d-sec">
  <h3>How to approve <small><?= $state === 'new' ? 'checked for you; look over anything orange or red' : 'as it stands now' ?> · <button type="button" class="linkish" data-recheck>check again</button></small></h3>
  <ol class="sh-steps">
    <?php $i = 0; foreach ($chk['steps'] as $k => [$s, $t]): $i++; ?>
    <li class="sh-s-<?= h($s) ?>"><span class="sh-s-i"><?= hub_step_icon($s) ?></span><span><b><?= $i ?>. <?= h(RS_HUB_STEP_NAMES[$k] ?? $k) ?></b><small><?= h($t) ?></small></span></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php $vtTag = (string)($chk['latest']['tag'] ?? ''); $vtFiles = $repo !== '' ? rs_hub_vt_files(rs_repo_key($host, $repo), $vtTag) : []; $vtSec(); ?>
<?php endif; ?>

<?php
// ---- What they sent ----------------------------------------------------------------------------------
if ($d !== null):
    $facts = [];
    $contact = trim((string)($d['contact'] ?? ''));
    if ($pcSide) {
        $facts['Version'] = (string)($d['version'] ?? '') ?: '—';
        $facts['Players need'] = trim((string)($d['needs'] ?? '')) . (trim((string)($d['disc'] ?? '')) !== '' ? ' (' . trim((string)$d['disc']) . ')' : '');
        if (is_array($d['assets'] ?? null) && $d['assets']) $facts['Release files'] = implode("\n", array_map(fn($k, $g) => ($sysName[$k] ?? $k) . ': ' . $g, array_keys($d['assets']), $d['assets']));
        $facts['Before first play'] = ($d['setup'] ?? '') === 'yes' ? 'Needs setup: ' . trim((string)($d['setup_steps'] ?? '')) : 'Ready to play';
        if (!empty($d['features'])) $facts['Has'] = implode(', ', (array)$d['features']);
        $facts['AI tools'] = ($d['ai'] ?? '') === 'yes' ? 'Yes, for ' . strtolower(implode(', ', array_map(fn($k) => RS_AI_USES[$k] ?? $k, rs_ai_uses_clean($d['ai_uses'] ?? [])))) : (($d['ai'] ?? '') === 'no' ? 'No, they say' : 'Not answered');
    } else {
        $facts['Version'] = (string)($d['version'] ?? '') ?: '—';
        $facts['Release file'] = (string)($d['asset'] ?? '') ?: 'Not given';
        $facts['Title ID'] = (string)($d['title_id'] ?? '') ?: 'None yet';
        $facts['Players need'] = (string)($d['needs'] ?? '');
    }
    $facts['Contact'] = $contact !== '' ? $contact : 'None';
    $facts = array_filter($facts, fn($v) => trim((string)$v) !== '');
    // Two to a row, the long ones across the whole row at the end, no lone box.
    $wideK = ['Release files', 'Before first play'];
    $short = array_diff_key($facts, array_flip($wideK));
    if (count($short) % 2) $short['Sent'] = rs_date('M j, Y', rs_hub_ts((string)$r['created']));
    $facts = $short + array_intersect_key($facts, array_flip($wideK)); ?>
<section class="sh-d-sec">
  <h3>What they sent</h3>
  <dl class="sh-facts"><?php foreach ($facts as $k => $v): ?><div<?= $k === 'Release files' || $k === 'Before first play' ? ' class="wide"' : '' ?>><dt><?= h($k) ?></dt><dd><?= $k === 'Contact' && filter_var($v, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . h($v) . '">' . h($v) . '</a>' : nl2br(h((string)$v)) ?></dd></div><?php endforeach; ?></dl>
  <?php if (trim((string)($d['blurb'] ?? '')) !== ''): ?><p class="sh-blurb"><?= h((string)$d['blurb']) ?></p><?php endif; ?>
  <?php if (trim((string)($d['description'] ?? '')) !== ''): ?><div class="sh-desc clamp" data-clamp><?= nl2br(h(trim((string)$d['description']))) ?></div><?php endif; ?>
  <?php if ($sh = $byRole('shot')): ?><div class="sh-shots"><?php foreach ($sh as $p): ?><a href="picture.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener"><img src="picture.php?id=<?= (int)$p['id'] ?>" alt="Screenshot" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
  <?php if (trim((string)($d['notes'] ?? '')) !== ''): ?><div class="sh-d-notes cyan"><b>Their note to us</b><p><?= nl2br(h(trim((string)$d['notes']))) ?></p></div><?php endif; ?>
  <?php if (!empty($d['links'])): ?><p class="small sh-links"><?php foreach ((array)$d['links'] as $u): ?><a href="<?= h((string)$u) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://(www\.)?#', '', (string)$u)) ?></a><?php endforeach; ?></p><?php endif; ?>
  <?php if (trim((string)($d['credits'] ?? '')) !== ''): ?><p class="small muted"><b>Credits:</b> <?= nl2br(h(trim((string)$d['credits']))) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php
// ---- The conversation --------------------------------------------------------------------------------
$thread = rs_hub_thread($item, $d, $subId); ?>
<section class="sh-d-sec">
  <h3>Conversation <small>team notes stay in the admin; messages go to the developer</small></h3>
  <div class="sh-thread">
    <?php if (!$thread): ?><p class="muted small">Nothing yet.</p><?php endif; ?>
    <?php foreach ($thread as $m): $k = $m['k']; ?>
    <div class="sh-m <?= h($k) ?>"><b><?= h(['team' => 'Team note · ', 'flag' => 'Flag · ', 'answer' => 'Answer · ', 'email' => 'Email to ', 'dm-team' => 'Message to ' . $devName . ' · ', 'dm-dev' => 'From '][$k] ?? '') ?><?= h($k === 'dm-team' ? (string)$m['who'] : $m['who']) ?></b><?php if ($k === 'email'): ?><span class="muted small"> · <?= h((string)$m['subj']) ?></span><?php endif; ?>
      <p><?= nl2br(h(mb_strimwidth((string)$m['body'], 0, 1500, '…'))) ?></p><small><?= h(rs_hub_ago_long((int)$m['at'])) ?></small></div>
    <?php endforeach; ?>
    <form class="sh-compose" data-compose>
      <input type="hidden" name="item" value="<?= h($item) ?>">
      <div class="sh-seg" role="group"><button type="button" class="on" data-mode="note">Note for the team</button><?php if ($canMsg): ?><button type="button" data-mode="message">Message <?= h($devName) ?></button><?php endif; ?></div>
      <textarea name="body" rows="2" placeholder="Only the team sees notes" data-ph-note="Only the team sees notes" data-ph-message="<?= h($acc ? 'Goes to their developer hub' : 'Goes to ' . $email . ' by email') ?>" required></textarea>
      <div class="sh-row"><?php if ($acc): ?><label class="check small" data-only="message" hidden><input type="checkbox" name="email" value="1" checked> Email them that it's there too</label><?php endif; ?><span class="grow"></span><button class="primary small" data-send>Save note</button></div>
    </form>
  </div>
</section>

<?php
// ---- Ask for changes / decline / flag / done panels (open from the buttons below) -------------------
$where = $pcSide ? 'RecompLauncher' : 'RecompStore';
$hello = 'Hi' . ($dev !== '' ? ' ' . $dev : '') . ",\n\n";
$chips = [];
if ($d !== null && empty($job)) {
    $cs = $chk['steps'] ?? [];
    if (($cs['files'][0] ?? '') === 'bad') $chips[] = str_contains((string)$cs['files'][1], 'No release') ? 'A release with a download players can install' : 'A release that has the files you named (the newest one doesn\'t)';
    if (($cs['own'][0] ?? '') === 'bad') $chips[] = 'Send it from your developer account (sign in with GitHub), so we can tell the project is yours';
    if (($cs['page'][0] ?? '') !== 'ok' && str_contains((string)($cs['page'][1] ?? ''), 'icon')) $chips[] = 'An icon (a square PNG, 512×512 or bigger)';
    if (($cs['page'][0] ?? '') !== 'ok' && str_contains((string)($cs['page'][1] ?? ''), 'screenshot')) $chips[] = 'A screenshot of it running';
    if (($cs['page'][0] ?? '') !== 'ok' && str_contains((string)($cs['page'][1] ?? ''), 'email')) $chips[] = 'An email address we can reach you at';
    if (($cs['page'][0] ?? '') !== 'ok' && str_contains((string)($cs['page'][1] ?? ''), 'description')) $chips[] = 'A short description: what it is, what it adds and what players need';
}
$chips = array_values(array_unique(array_merge($chips, ['Which game files players need (their own copy)', 'A short description: what it is, what it adds and what players need'])));
$declineChips = ['It includes game files, ROMs or keys', 'It isn\'t a game (a tool, a launcher or a mod manager)', 'It\'s someone else\'s project', 'VirusTotal flagged it and we couldn\'t clear it', 'It\'s already listed'];
if ($d !== null): ?>
<form class="sh-panel" data-panel="changes" data-form="changes" hidden>
  <input type="hidden" name="item" value="sub:<?= $subId ?>">
  <h3>Ask for changes</h3>
  <p class="muted small">They get an email with what to fix and a link that opens their submission again, filled in. It waits on the board until they send it.</p>
  <div class="sh-chips" data-chips><?php foreach ($chips as $c): ?><button type="button" data-chip="<?= h($c) ?>">+ <?= h($c) ?></button><?php endforeach; ?></div>
  <label>What needs to change <span class="muted small">(a short line for the board)</span><input name="reason" maxlength="600" required></label>
  <label>Subject<input name="subject" maxlength="200" value="<?= h('About your ' . $where . ' submission: ' . (string)($d['name'] ?? '')) ?>"></label>
  <label>Message<textarea name="body" rows="9"><?= h($hello . 'Thanks for sending ' . (string)($d['name'] ?? 'it') . '. Before it can go on ' . $where . ', it needs a few changes:' . "\n\n- \n\nWhen that's done, send it again with the link below. Everything you sent is already filled in.\n") ?></textarea></label>
  <p class="muted small"><?= $email === '' ? 'They left no email address, so nothing goes out: let them know another way.' : (rs_mail_ready() ? 'Goes to ' . h($email) . ', with the fix link added under your message.' : 'The mailbox isn\'t set up, so nothing goes out: copy the message and send it yourself.') ?></p>
  <div class="sh-d-foot"><button class="primary">Send it back to them</button><button type="button" data-panel-close>Cancel</button></div>
</form>
<form class="sh-panel" data-panel="decline" data-form="decline" hidden>
  <input type="hidden" name="item" value="sub:<?= $subId ?>">
  <h3>Decline</h3>
  <p class="muted small">For things that can't be fixed. To ask for a fix instead, use Ask for changes.</p>
  <div class="sh-chips" data-chips><?php foreach ($declineChips as $c): ?><button type="button" data-chip="<?= h($c) ?>">+ <?= h($c) ?></button><?php endforeach; ?></div>
  <label>Why <span class="muted small">(a short line for Handled)</span><input name="reason" maxlength="600" required></label>
  <label>Subject<input name="subject" maxlength="200" value="<?= h('About your ' . $where . ' submission: ' . (string)($d['name'] ?? '')) ?>"></label>
  <label>Message<textarea name="body" rows="8"><?= h($hello . 'Thanks for sending ' . (string)($d['name'] ?? 'it') . '. We looked it over, and we can\'t list it on ' . $where . ':' . "\n\n- \n\nIf you think we got it wrong, just reply to this email.\n") ?></textarea></label>
  <label class="check"><input type="checkbox" name="send" value="1"<?= $email !== '' && rs_mail_ready() ? ' checked' : ' disabled' ?>> Email it to them<?= $email === '' ? ' (they left no email address)' : '' ?></label>
  <div class="sh-d-foot"><button class="danger">Decline</button><button type="button" data-panel-close>Cancel</button></div>
</form>
<form class="sh-panel" data-panel="done" data-form="done" hidden>
  <input type="hidden" name="item" value="sub:<?= $subId ?>">
  <h3>Mark done without an email</h3>
  <div class="sh-chips" data-chips data-to-reason><?php foreach (['A duplicate of another submission', 'Already listed', 'Spam or a test', 'Handled on Discord'] as $c): ?><button type="button" data-chip="<?= h($c) ?>"><?= h($c) ?></button><?php endforeach; ?></div>
  <label>Why<input name="reason" maxlength="200" required></label>
  <div class="sh-d-foot"><button class="primary">Mark done</button><button type="button" data-panel-close>Cancel</button></div>
</form>
<?php if (array_filter($vtFiles, fn($f) => $f['hits'] > 0)): ?>
<form class="sh-panel" data-panel="vt" data-form="vt_tell" hidden>
  <input type="hidden" name="item" value="<?= h($item) ?>">
  <h3>Tell <?= h($devName) ?> what VirusTotal flagged</h3>
  <p class="muted small">Each flagged file with its report link, and what they can do about it. Change anything you like.</p>
  <label>Email subject<input name="subject" maxlength="200" value="<?= h('About your ' . $where . ' submission: ' . (string)($d['name'] ?? $name)) ?>"></label>
  <label>Message<textarea name="body" rows="13" required data-vt-body><?= h(rs_hub_vt_note($vtFiles, $dev, (string)($d['name'] ?? $name), $where, $vtTag)) ?></textarea></label>
  <?php if ($canMail || $acc): ?>
  <div class="sh-vt-via">
    <label class="check"><input type="checkbox" name="via_email" value="1"<?= $canMail ? ' checked' : ' disabled' ?>> Email it<?= $email !== '' ? ' to ' . h($email) : ' (they left no email address)' ?><?= $email !== '' && !rs_mail_ready() ? ' (the mailbox isn\'t set up)' : '' ?></label>
    <label class="check"><input type="checkbox" name="via_hub" value="1"<?= $acc ? ' checked' : ' disabled' ?>> Send it to their developer hub<?= $acc ? '' : ' (no developer account)' ?></label>
  </div>
  <?php else: ?><p class="warn small">They left no email address and have no developer account, so copy it and send it another way (Discord, GitHub).</p><?php endif; ?>
  <div class="sh-d-foot"><?php if ($canMail || $acc): ?><button class="primary">Send it</button><?php endif; ?><button type="button" data-copy-body>Copy</button><button type="button" data-panel-close>Cancel</button></div>
</form>
<?php endif; ?>
<?php endif; ?>
<form class="sh-panel" data-panel="flag" data-form="flag" hidden>
  <input type="hidden" name="item" value="<?= h($item) ?>">
  <h3><?= HUB_IC['flag'] ?>Flag for <?= h($GLOBALS['owner']) ?></h3>
  <p class="muted small">For anything you're not sure about. It shows on Flags and on this card until <?= h($GLOBALS['owner']) ?> answers.</p>
  <label>What should <?= h($GLOBALS['owner']) ?> look at?<textarea name="body" rows="4" required></textarea></label>
  <div class="sh-d-foot"><button class="primary">Flag it</button><button type="button" data-panel-close>Cancel</button></div>
</form>

<?php
// ---- The buttons -------------------------------------------------------------------------------------
$flagBtn = !$GLOBALS['isOwner'] ? '<button type="button" class="sh-flag" data-open-panel="flag">' . HUB_IC['flag'] . 'Flag for ' . h($GLOBALS['owner']) . '</button>' : '';
$more = [];
echo '<div class="sh-d-foot main" data-item="' . h($job ? 'job:' . (int)$job['id'] : $item) . '">';
if ($job) {
    $vtBad = ($vt[0] ?? '') === 'bad';
    if ($job['status'] === 'review') echo '<button class="primary" data-act="publish" data-confirm="Click again to put it live"' . ($vtBad ? ' disabled title="VirusTotal flagged it. Look at it on Security first."' : '') . '>Publish</button>';
    elseif (!$live) echo '<button class="primary" data-act="again">Run it again</button>';
    if ($job['status'] === 'review') echo '<button data-act="again">Run again</button>';
    echo $flagBtn;
    echo '<button class="danger" data-act="remove" data-confirm="Click again to remove"' . ($live ? ' disabled title="Wait for it to finish"' : '') . '>Remove</button>';
    if ($d !== null) $more[] = '<button type="button" data-open-panel="changes">Ask for changes instead</button>';
} elseif ($state === 'new') {
    // (4.8) No release yet: it can wait on RecompLauncher's Coming Soon instead (Approve moves to More).
    $noRel = $pcSide && ($chk['steps']['files'][2] ?? '') === 'No release yet';
    if ($noRel) {
        echo '<button class="primary" data-act="soon" data-confirm="Click again to put it on Coming Soon">Add to Coming Soon</button>';
        $more[] = '<button type="button" data-act="approve" data-confirm="There\'s no release. Click again to approve anyway">Approve and check anyway</button>';
    } else echo '<button class="primary" data-act="approve"' . (array_filter($chk['steps'], fn($x) => $x[0] === 'bad') ? ' data-confirm="Some checks are red. Click again to approve anyway"' : '') . '>' . ($pcSide ? 'Approve and check' : 'Approve') . '</button>';
    echo '<button type="button" data-open-panel="changes">Ask for changes</button>' . $flagBtn . '<button type="button" class="danger" data-open-panel="decline">Decline</button>';
    $more[] = '<button type="button" data-open-panel="done">Mark done without an email</button>';
    $more[] = '<button type="button" data-act="move">Move to the ' . ($pcSide ? 'PS5' : 'PC') . ' board</button>';
    if (rs_hub_can_manage()) $more[] = '<button type="button" class="danger" data-act="delete" data-confirm="Click again to delete it for good">Delete for good</button>';
} elseif ($state === 'back') {
    echo '<button class="primary" data-act="reopen">Back to New</button><button data-act="stop_wait" data-confirm="Click again to take it off the board">Stop waiting</button>' . $flagBtn;
} elseif ($state === 'draft') {
    if (rs_can_page('edit.php')) echo '<a class="btn primary" href="edit.php?id=' . h(rawurlencode((string)$d['ps5_draft'])) . '">Open the draft</a>';
    echo $flagBtn . '<button data-act="reopen" data-confirm="Click again to put it back on New">Back to New</button>';
} else {
    echo '<button data-act="reopen">Back to New</button>' . $flagBtn;
}
if ($more) echo '<details class="sh-more"><summary aria-label="More">' . HUB_IC['more'] . '</summary><div class="sh-more-pop">' . implode('', $more) . '</div></details>';
echo '</div>';
