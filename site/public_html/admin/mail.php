<?php
// RecompStore admin (2.7.1): Mail. The mailbox the site sends from, the developer list, and emails
// to the list, sent a few at a time while this page is open.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_mail_tables();
$db = rs_db();

// (2.7.2) Who an email goes to: everyone on the list, or the people picked ('only_ids', JSON ids).
$whoSql = function (array $b): string {
    $ids = array_values(array_filter(array_map('intval', (array)json_decode((string)($b['only_ids'] ?? ''), true))));
    return $ids ? ' AND l.id IN (' . implode(',', $ids) . ')' : '';
};
// One person's copy of an email: [html, text, invite url or ''].
$personal = function (array $b, array $p): array {
    $name = trim((string)$p['name']) !== '' ? trim((string)$p['name']) : 'there';
    $blocks = rs_mb_clean(json_decode((string)($b['blocks'] ?? ''), true));
    if (!$blocks) $blocks = rs_mb_from_text((string)$b['body']);
    $invite = '';
    if (str_contains(json_encode($blocks), '{invite}') && function_exists('rs_dev_invite_url')) $invite = rs_dev_invite_url((string)$p['email'], (string)$p['name']);
    [$html, $text] = rs_mb_email($blocks, ['name' => $name, 'invite' => $invite], rs_mail_get('mail_sign'), rs_list_footer((string)$p['token']));
    return [$html, $text];
};

// (2.7.2) The builder's live preview: the email as it would arrive. JSON in, HTML out.
if (($_POST['action'] ?? '') === 'preview') {
    rs_session();
    if (!is_string($_POST['csrf'] ?? null) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) { http_response_code(400); exit('Reload the page.'); }
    session_write_close();
    header('Content-Type: text/html; charset=utf-8');
    $blocks = rs_mb_clean(json_decode((string)($_POST['blocks'] ?? ''), true));
    [$html] = rs_mb_email($blocks, ['name' => 'Ruben', 'invite' => 'https://recompstore.com/developers?invite=PREVIEW'], rs_mail_get('mail_sign'), rs_list_footer(str_repeat('0', 32)));
    echo $html;
    exit;
}

// A few emails to the list per call, while the page is open. JSON in, JSON out.
if (($_POST['action'] ?? '') === 'step') {
    header('Content-Type: application/json; charset=utf-8');
    rs_session();
    if (!is_string($_POST['csrf'] ?? null) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) { echo json_encode(['ok' => false, 'error' => 'Reload the page.']); exit; }
    session_write_close();
    $bid = (int)($_POST['blast'] ?? 0);
    $st = $db->prepare('SELECT * FROM mail_blasts WHERE id = ?');
    $st->execute([$bid]);
    $b = $st->fetch();
    if (!$b) { echo json_encode(['ok' => false, 'error' => 'That email is gone.']); exit; }
    $next = $db->prepare("SELECT l.* FROM mail_list l WHERE l.status = 'on'" . $whoSql($b) . " AND NOT EXISTS (SELECT 1 FROM mail_sent s WHERE s.blast_id = ? AND s.list_id = l.id) ORDER BY l.id LIMIT 6");
    $next->execute([$bid]);
    $people = $next->fetchAll();
    $sent = $failed = 0;
    $error = '';
    if ($people && $b['status'] === 'sending') {
        $msgs = [];
        foreach ($people as $i => $p) {
            [$html, $text] = $personal($b, $p);
            $m = rs_mail_build((string)$p['email'], str_replace('{name}', trim((string)$p['name']) ?: 'there', (string)$b['subject']), $text,
                ['html' => $html, 'footer' => rs_list_footer((string)$p['token']), 'unsub' => rs_list_url('off', (string)$p['token'])]);
            $msgs[$i] = [(string)$p['email'], $m['data']];
        }
        try {
            $res = rs_smtp_send($msgs);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $saved = (int)$db->query('SELECT COUNT(*) FROM mail_sent WHERE blast_id = ' . $bid . ' AND ok = 1')->fetchColumn() > 0;
        foreach ($people as $i => $p) {
            $err = $res[$i] ?? 'Not sent.';
            rs_mail_log('blast', (string)$p['email'], (string)$b['subject'], null, $err, null, $bid, (int)$p['id']);
            if ($err === '') {
                $sent++;
                // One copy in Sent, so it's in webmail with everything else.
                if (!$saved) { rs_mail_save_sent($msgs[$i][1]); $saved = true; }
            } else { $failed++; $error = $err; }
        }
    }
    $left = (int)$db->query("SELECT COUNT(*) FROM mail_list l WHERE l.status = 'on'" . $whoSql($b) . " AND NOT EXISTS (SELECT 1 FROM mail_sent s WHERE s.blast_id = $bid AND s.list_id = l.id)")->fetchColumn();
    if ($left === 0 || $b['status'] !== 'sending') $db->prepare("UPDATE mail_blasts SET status = 'done' WHERE id = ?")->execute([$bid]);
    echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'left' => $b['status'] === 'sending' ? $left : 0, 'error' => $error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'mail.php';
    $subject = trim((string)($_POST['subject'] ?? ''));
    $blocks = rs_mb_clean(json_decode((string)($_POST['blocks'] ?? ''), true));
    $only = array_values(array_filter(array_map('intval', (array)($_POST['only'] ?? []))));
    $pick = ($_POST['audience'] ?? '') === 'pick';
    try {
        if ($action === 'setup') {
            $from = strtolower(trim((string)($_POST['from'] ?? '')));
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The mailbox has to be an email address.');
            rs_set_setting('mail_from', $from);
            rs_set_setting('mail_name', mb_substr(trim((string)($_POST['name'] ?? '')) ?: 'RecompStore', 0, 80));
            rs_set_setting('mail_sign', mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['sign'] ?? ''))), 0, 500));
            foreach (['host' => 'mail_host', 'imap' => 'mail_imap'] as $f => $k) {
                $v = strtolower(trim((string)($_POST[$f] ?? '')));
                if ($v !== '' && preg_match('/^[a-z0-9.-]+$/', $v)) rs_set_setting($k, $v);
            }
            $port = (int)($_POST['port'] ?? 465);
            rs_set_setting('mail_port', in_array($port, [465, 587], true) ? (string)$port : '465');
            $pass = (string)($_POST['pass'] ?? '');
            if ($pass !== '') rs_mail_set_password($pass);
            rs_log('settings', 'Mail', 'mailbox ' . $from . ($pass !== '' ? ', password changed' : ''));
            // Straight away: does it work?
            if (rs_mail_ready()) {
                rs_mail_send($from, 'RecompStore can send email', "This came from the admin's Mail page. Replies and emails to the developer list will go out from this mailbox, with a copy in Sent.", ['save' => true]);
                rs_mail_log('test', $from, 'RecompStore can send email', null, '');
                rs_flash('Saved, and a test email went to ' . $from . '. Check that it arrived.');
            } else rs_flash('Saved. Add the mailbox password to start sending.');
            $back = 'mail.php#setup';
        } elseif ($action === 'relay') {
            // (3.1g) The sending service.
            $rh = strtolower(trim((string)($_POST['relay_host'] ?? '')));
            rs_set_setting('relay_host', $rh !== '' && preg_match('/^[a-z0-9.-]+$/', $rh) ? $rh : 'smtp.resend.com');
            $ru = trim((string)($_POST['relay_user'] ?? ''));
            rs_set_setting('relay_user', $ru !== '' ? mb_substr($ru, 0, 80) : 'resend');
            $rp = (int)($_POST['relay_port'] ?? 465);
            rs_set_setting('relay_port', in_array($rp, [465, 587, 2525], true) ? (string)$rp : '465');
            $rk = trim((string)($_POST['relay_key'] ?? ''));
            if ($rk !== '') rs_set_setting('relay_key', rs_mail_lock($rk));
            rs_set_setting('relay_on', !empty($_POST['relay_on']) ? '1' : '0');
            rs_log('settings', 'Mail', 'sending service ' . (rs_relay_on() ? 'on' : 'off') . ($rk !== '' ? ', key changed' : ''));
            if (rs_relay_on()) {
                $me = rs_mail_get('mail_from');
                rs_mail_send($me, 'RecompStore can send through the sending service', "This went out through the sending service. Emails to the list, account emails and replies now go this way, still from " . $me . ".", ['save' => true]);
                rs_mail_log('test', $me, 'RecompStore can send through the sending service', null, '');
                rs_flash('Saved, and a test email went to ' . $me . ' through the sending service. Check that it arrived.');
            } else rs_flash(rs_setting('relay_on', '0') === '1' ? 'Saved. Add the key to start sending through it.' : 'Saved. Emails go out from the mailbox again.');
            $back = 'mail.php#relay';
        } elseif ($action === 'forget') {
            rs_mail_set_password('');
            rs_log('settings', 'Mail', 'password removed');
            rs_flash('The mailbox password is gone. Nothing sends until you add it again.');
            $back = 'mail.php#setup';
        } elseif ($action === 'submitters') {
            $n = ['added' => 0, 'back' => 0, 'there' => 0, 'off' => 0];
            foreach (rs_list_submitters() as $mail => $name) $n[rs_list_add($mail, $name, 'submission')]++;
            rs_flash($n['added'] . ' added from submissions' . ($n['there'] ? ', ' . $n['there'] . ' already on the list' : '') . ($n['off'] ? ', ' . $n['off'] . ' left out because they unsubscribed' : '') . '.');
            $back = 'mail.php#list';
        } elseif ($action === 'add') {
            $r = rs_list_add((string)($_POST['email'] ?? ''), (string)($_POST['who'] ?? ''), 'added', 'on', true);
            rs_flash(['added' => 'Added.', 'back' => 'Back on the list.', 'there' => 'Already on the list.'][$r] ?? 'Done.');
            $back = 'mail.php#list';
        } elseif ($action === 'on' || $action === 'off') {
            $db->prepare('UPDATE mail_list SET status = ?, changed = NOW() WHERE id = ?')->execute([$action, (int)($_POST['id'] ?? 0)]);
            $back = 'mail.php#list';
        } elseif ($action === 'remove') {
            $db->prepare('DELETE FROM mail_list WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            rs_flash('Removed from the list.');
            $back = 'mail.php#list';
        } elseif (in_array($action, ['draft', 'test', 'send'], true)) {
            if ($subject === '' || !$blocks) throw new RuntimeException('Write a subject and add something to the email first.');
            rs_set_setting('mail_draft_subject', mb_substr($subject, 0, 200));
            rs_set_setting('mail_draft_blocks', json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            rs_set_setting('mail_draft_pick', $pick ? json_encode($only) : '');
            $back = 'mail.php#write';
            $text = rs_mb_text($blocks, ['name' => 'there']);
            if ($action === 'draft') rs_flash('Draft saved.');
            elseif ($action === 'test') {
                $me = rs_mail_get('mail_from');
                [$html, $text] = rs_mb_email($blocks, ['name' => 'Ruben', 'invite' => 'https://recompstore.com/developers?invite=TEST'], rs_mail_get('mail_sign'), rs_list_footer(str_repeat('0', 32)));
                rs_mail_send($me, '[Test] ' . str_replace('{name}', 'Ruben', $subject), $text,
                    ['html' => $html, 'footer' => rs_list_footer(str_repeat('0', 32)), 'unsub' => rs_list_url('off', str_repeat('0', 32))]);
                rs_mail_log('test', $me, '[Test] ' . $subject, null, '');
                rs_flash('A test went to ' . $me . '. It looks just like what each person gets, with your name in it.');
            } else {
                if ($pick && !$only) throw new RuntimeException('Tick the people it goes to, or choose Everyone on the list.');
                $total = (int)$db->query("SELECT COUNT(*) FROM mail_list l WHERE l.status = 'on'" . ($pick ? ' AND l.id IN (' . implode(',', $only) . ')' : ''))->fetchColumn();
                if ($total === 0) throw new RuntimeException('Nobody on the list to send it to.');
                if (!rs_mail_ready()) throw new RuntimeException('Add the mailbox password first.');
                if ($db->query("SELECT COUNT(*) FROM mail_blasts WHERE status = 'sending'")->fetchColumn()) throw new RuntimeException('Another email is still going out. Let it finish first.');
                // (2.7.3) The same email again within a day only when you said so (the Send button asks).
                $dup = $db->prepare('SELECT b.created, b.total, (SELECT COUNT(*) FROM mail_sent s WHERE s.blast_id = b.id AND s.ok = 1) sent_ok FROM mail_blasts b WHERE b.subject = ? AND b.created > (NOW() - INTERVAL 1 DAY) ORDER BY b.id DESC LIMIT 1');
                $dup->execute([$subject]);
                if (($d = $dup->fetch()) && empty($_POST['again'])) throw new RuntimeException('You already sent "' . $subject . '" at ' . rs_date('g:i a', strtotime($d['created'])) . ' (' . (int)$d['sent_ok'] . ' of ' . (int)$d['total'] . ' sent). Nothing was sent again.');
                $db->prepare("INSERT INTO mail_blasts (subject, body, blocks, only_ids, status, total, created) VALUES (?, ?, ?, ?, 'sending', ?, NOW())")
                   ->execute([$subject, $text, json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $pick ? json_encode($only) : null, $total]);
                rs_flash('Sending "' . $subject . '" to ' . $total . ' ' . ($total === 1 ? 'person' : 'people') . '. Keep this page open until it says it\'s done.');
                $back = 'mail.php?sending=' . (int)$db->lastInsertId() . '#history';
            }
        } elseif ($action === 'stop') {
            $db->prepare("UPDATE mail_blasts SET status = 'done' WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            rs_flash('Stopped. Whoever got it already, got it.');
            $back = 'mail.php#history';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$ready = rs_mail_ready();
$hasPass = rs_mail_password() !== '';
$counts = $db->query('SELECT status, COUNT(*) n FROM mail_list GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$on = (int)($counts['on'] ?? 0);
$people = $db->query("SELECT * FROM mail_list ORDER BY FIELD(status, 'on', 'pending', 'off'), created DESC LIMIT 500")->fetchAll();
$newFromSubs = 0;
$have = array_flip(array_column($db->query('SELECT email FROM mail_list')->fetchAll(), 'email'));
foreach (rs_list_submitters() as $mail => $_) if (!isset($have[$mail])) $newFromSubs++;
$blasts = $db->query('SELECT b.*, (SELECT COUNT(*) FROM mail_sent s WHERE s.blast_id = b.id AND s.ok = 1) sent_ok, (SELECT COUNT(*) FROM mail_sent s WHERE s.blast_id = b.id AND s.ok = 0) sent_bad FROM mail_blasts b ORDER BY b.id DESC LIMIT 20')->fetchAll();
$sending = (int)($_GET['sending'] ?? 0);
$sendingRow = null;
foreach ($blasts as $b) if ((int)$b['id'] === $sending && $b['status'] === 'sending') $sendingRow = $b;
$lastTest = $db->query("SELECT * FROM mail_sent WHERE kind = 'test' ORDER BY id DESC LIMIT 1")->fetch();
// The draft: blocks from the builder; an older plain-text draft as one text block; else the press kit email.
$tpls = rs_mb_templates();
$subject = (string)rs_setting('mail_draft_subject', $tpls['press']['subject']);
$draft = rs_mb_clean(json_decode((string)rs_setting('mail_draft_blocks', ''), true));
if (!$draft) $draft = ($old = trim((string)rs_setting('mail_draft_body', ''))) !== '' ? rs_mb_from_text($old) : $tpls['press']['blocks'];
$draftPick = array_map('intval', (array)json_decode((string)rs_setting('mail_draft_pick', ''), true));
$appChoices = array_values(array_map(fn($a) => ['id' => (string)$a['id'], 'name' => (string)$a['name']], rs_published_apps()));
usort($appChoices, fn($x, $y) => strnatcasecmp($x['name'], $y['name']));
$onPeople = array_values(array_filter($people, fn($p) => $p['status'] === 'on'));
$sources = ['submission' => 'Submitted an app', 'signup' => 'Signed up', 'added' => 'You added'];
$when = fn(?string $d) => $d ? rs_date('M j, Y', strtotime($d)) : '';

rs_head('Mail');
?>
<section class="head-row">
  <div>
    <h1>Mail</h1>
    <p class="muted">Email from <?= h(rs_mail_get('mail_from')) ?>: replies to the Inbox and news for the developer list. A copy of everything goes in the mailbox's Sent folder, and replies come back to its inbox in webmail.</p>
  </div>
</section>

<section class="card" id="setup">
  <h2>Mailbox <?= $ready ? '<span class="status-pill used">Connected</span>' : '<span class="status-pill off">Not set up</span>' ?></h2>
  <?php if (!$ready): ?><p class="muted">Type the password of the mailbox (the one you use for webmail). It's kept encrypted on the server and never shown again. Saving sends a test email to the mailbox.</p>
  <?php elseif ($lastTest): ?><p class="muted">Last test <?= h(rs_date('M j, g:i a', strtotime($lastTest['created']))) ?><?= (int)$lastTest['ok'] ? ': sent.' : ': ' . h($lastTest['error']) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="setup">
    <div class="filters">
      <label>Mailbox<input name="from" type="email" value="<?= h(rs_mail_get('mail_from')) ?>" required></label>
      <label>Name people see<input name="name" value="<?= h(rs_mail_get('mail_name')) ?>" maxlength="80"></label>
      <label>Password<input name="pass" type="password" autocomplete="new-password" placeholder="<?= $hasPass ? 'Saved. Type a new one to change it' : 'The mailbox password' ?>"></label>
    </div>
    <label>Signature<textarea name="sign" rows="2" maxlength="500"><?= h(rs_mail_get('mail_sign')) ?></textarea><small>Goes under every reply and every email to the list.</small></label>
    <details><summary class="muted">Server (Hostinger Email)</summary>
      <div class="filters" style="margin-top:12px">
        <label>SMTP server<input name="host" value="<?= h(rs_mail_get('mail_host')) ?>"></label>
        <label style="min-width:120px">Port<select name="port"><?php foreach ([465, 587] as $p): ?><option<?= (int)rs_mail_get('mail_port') === $p ? ' selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></label>
        <label>IMAP server (for Sent)<input name="imap" value="<?= h(rs_mail_get('mail_imap')) ?>"></label>
      </div>
    </details>
    <p><button class="primary">Save<?= $hasPass ? ' and send a test' : '' ?></button></p>
  </form>
  <?php if ($hasPass): ?><form method="post" onsubmit="return confirm('Remove the saved password? Nothing sends until you add it again.')"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button name="action" value="forget" class="danger">Remove the password</button></form><?php endif; ?>
</section>

<?php $relayOn = rs_relay_on(); $relayKey = rs_mail_unlock('relay_key') !== ''; ?>
<section class="card" id="relay">
  <h2>Sending service <?= $relayOn ? '<span class="status-pill used">On</span>' : '<span class="status-pill off">Off</span>' ?></h2>
  <p class="muted">With this on, every email still comes from <?= h(rs_mail_get('mail_from')) ?> and replies still land in its inbox, but it goes out through a sending service (Brevo, SMTP2GO, MailerSend or any other with SMTP), so the mailbox's daily limit doesn't apply. Copy the server, port, username and SMTP key from the service's SMTP settings. The key is kept encrypted and never shown again.</p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="relay">
    <div class="filters">
      <label>SMTP key<input name="relay_key" type="password" autocomplete="new-password" placeholder="<?= $relayKey ? 'Saved. Paste a new one to change it' : 'From the service\'s SMTP settings' ?>"></label>
      <label style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" name="relay_on" value="1"<?= rs_setting('relay_on', '0') === '1' ? ' checked' : '' ?>> Send through it</label>
    </div>
    <?php $rport = (string)rs_setting('relay_port', '465'); ?>
    <div class="filters">
      <label>SMTP server<input name="relay_host" value="<?= h((string)rs_setting('relay_host', 'smtp.resend.com')) ?>"></label>
      <label>Port<select name="relay_port"><?php foreach (['465' => '465 (SSL)', '587' => '587 (STARTTLS)', '2525' => '2525 (STARTTLS)'] as $pv => $pl): ?><option value="<?= $pv ?>"<?= $rport === (string)$pv ? ' selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select></label>
      <label>Username<input name="relay_user" value="<?= h((string)rs_setting('relay_user', 'resend')) ?>"></label>
    </div>
    <p><button class="primary">Save<?= rs_setting('relay_on', '0') === '1' || $relayKey ? ' and send a test' : '' ?></button></p>
  </form>
</section>

<section class="card" id="list">
  <h2>Developer list</h2>
  <p class="muted"><?= $on ?> on the list<?= !empty($counts['pending']) ? ', ' . (int)$counts['pending'] . ' waiting to confirm' : '' ?><?= !empty($counts['off']) ? ', ' . (int)$counts['off'] . ' unsubscribed' : '' ?>. People join when they tick the box on the submit form or sign up on the Press kit page. "Add everyone who submitted" only adds people whose app is in the store or waiting as a draft, and skips developers who already have a hub. Every email has an unsubscribe link.</p>
  <div class="filters">
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button name="action" value="submitters"<?= $newFromSubs ? ' class="primary"' : '' ?>>Add everyone who submitted an app<?= $newFromSubs ? ' (' . $newFromSubs . ' new)' : '' ?></button></form>
  </div>
  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="add">
    <label>Email<input name="email" type="email" required></label>
    <label>Name<input name="who" maxlength="120" placeholder="Optional"></label>
    <button>Add</button>
  </form>
  <?php if ($people): ?>
  <div class="table-wrap"><table class="codes">
    <thead><tr><th>Email</th><th>Name</th><th>How they joined</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): ?>
      <tr>
        <td><?= h($p['email']) ?></td>
        <td><?= h($p['name']) ?></td>
        <td class="muted"><?= h($sources[$p['source']] ?? $p['source']) ?> · <?= h($when($p['created'])) ?></td>
        <td><?= $p['status'] === 'on' ? '<span class="status-pill used">On</span>' : ($p['status'] === 'pending' ? '<span class="status-pill">Not confirmed</span>' : '<span class="status-pill off">Unsubscribed</span>') ?></td>
        <td style="text-align:right;white-space:nowrap">
          <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <?php if ($p['status'] === 'on'): ?><button name="action" value="off">Take off</button><?php elseif ($p['status'] === 'pending'): ?><button name="action" value="on">Put on</button><?php endif; ?>
            <button name="action" value="remove" class="danger" onclick="return confirm('Remove <?= h($p['email']) ?> from the list for good?')">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card" id="write">
  <h2>Write an email</h2>
  <p class="muted">Build it from blocks, or start from a template. <code>{name}</code> becomes each person's name, and <code>{invite}</code> (in a button) their own developer invite link. Each person gets their own copy; nobody sees the others. Your signature and an unsubscribe line go under it.</p>
  <form method="post" class="mb" data-mb>
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <textarea name="blocks" hidden data-mb-json><?= h(json_encode($draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>
    <script type="application/json" data-mb-templates><?= json_encode(array_map(fn($t) => ['name' => $t['name'], 'subject' => $t['subject'], 'blocks' => $t['blocks']], $tpls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script type="application/json" data-mb-apps><?= json_encode($appChoices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <div class="filters">
      <label>Start from a template<select data-mb-template><option value="">Choose…</option><?php foreach ($tpls as $k => $t): ?><option value="<?= h($k) ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
      <label class="grow" style="flex:1">Subject<input name="subject" maxlength="200" value="<?= h($subject) ?>" required></label>
    </div>
    <div class="mb-grid">
      <div class="mb-edit">
        <div data-mb-blocks></div>
        <div class="mb-add"><span class="muted">Add</span>
          <?php foreach (['heading' => 'Heading', 'text' => 'Text', 'button' => 'Button', 'app' => 'App', 'apps' => 'Latest apps', 'image' => 'Picture', 'divider' => 'Line', 'spacer' => 'Space'] as $t => $l): ?><button type="button" data-mb-add="<?= h($t) ?>">+ <?= h($l) ?></button><?php endforeach; ?>
        </div>
      </div>
      <div class="mb-preview"><span class="lbl">Preview <span class="muted">(as Ruben would get it)</span></span><iframe data-mb-preview title="Preview" sandbox=""></iframe></div>
    </div>
    <fieldset class="mb-who"><legend>Send it to</legend>
      <label class="check"><input type="radio" name="audience" value="all" <?= $draftPick ? '' : 'checked' ?>> Everyone on the list (<?= $on ?>)</label>
      <label class="check"><input type="radio" name="audience" value="pick" <?= $draftPick ? 'checked' : '' ?>> Only the people I tick</label>
      <div class="mb-pick" data-mb-pick <?= $draftPick ? '' : 'hidden' ?>>
        <?php foreach ($onPeople as $p): ?><label class="check"><input type="checkbox" name="only[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $draftPick, true) ? 'checked' : '' ?>> <?= h($p['name'] !== '' ? $p['name'] . ' · ' : '') ?><span class="muted"><?= h($p['email']) ?></span></label><?php endforeach; ?>
        <?php if (!$onPeople): ?><p class="muted">Nobody on the list yet.</p><?php endif; ?>
      </div>
    </fieldset>
    <p class="filters">
      <button name="action" value="draft">Save draft</button>
      <button name="action" value="test"<?= $ready ? '' : ' disabled' ?>>Send a test to me</button>
      <?php $recent = []; foreach ($blasts as $b) if (strtotime($b['created']) > time() - 86400) $recent[] = ['subject' => $b['subject'], 'when' => rs_date('g:i a', strtotime($b['created'])), 'sent' => (int)$b['sent_ok'], 'total' => (int)$b['total']]; ?>
      <input type="hidden" name="again" value="" data-mb-again>
      <button name="action" value="send" class="primary"<?= $ready && $on ? '' : ' disabled' ?> data-mb-send data-recent="<?= h(json_encode($recent, JSON_UNESCAPED_UNICODE)) ?>">Send</button>
    </p>
  </form>
</section>

<section class="card" id="history">
  <h2>Sent to the list</h2>
  <?php if (!$blasts): ?><p class="muted">Nothing yet.</p><?php endif; ?>
  <?php foreach ($blasts as $b): ?>
    <div class="blast" <?= $sendingRow && (int)$b['id'] === (int)$sendingRow['id'] ? 'data-sending="' . (int)$b['id'] . '"' : '' ?>>
      <strong><?= h($b['subject']) ?></strong>
      <span class="muted"><?= h(rs_date('M j, Y g:i a', strtotime($b['created']))) ?> · <span data-progress><?= (int)$b['sent_ok'] ?> of <?= (int)$b['total'] ?> sent<?= (int)$b['sent_bad'] ? ', ' . (int)$b['sent_bad'] . ' failed' : '' ?></span></span>
      <?php if ($b['status'] === 'sending'): ?>
        <span class="status-pill">Sending</span>
        <?php if (!$sendingRow || (int)$b['id'] !== (int)$sendingRow['id']): ?><a class="btn" href="mail.php?sending=<?= (int)$b['id'] ?>#history">Keep sending</a><?php endif; ?>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button name="action" value="stop" class="danger">Stop</button></form>
      <?php endif; ?>
      <p class="muted" data-note></p>
    </div>
  <?php endforeach; ?>
</section>
<script>
(() => {
  const box = document.querySelector('[data-sending]');
  if (!box) return;
  const id = box.dataset.sending, csrf = <?= json_encode(rs_csrf()) ?>;
  const note = box.querySelector('[data-note]'), prog = box.querySelector('[data-progress]');
  let sent = <?= (int)($sendingRow['sent_ok'] ?? 0) ?>, failed = <?= (int)($sendingRow['sent_bad'] ?? 0) ?>, total = <?= (int)($sendingRow['total'] ?? 0) ?>;
  note.textContent = 'Sending… keep this page open until it finishes.';
  // (2.7.3) A bar at the top while it goes out, and a clear Sent when it's done.
  const bar = document.createElement('div');
  bar.className = 'send-bar';
  bar.innerHTML = '<strong></strong><span></span>';
  document.body.appendChild(bar);
  const show = (head, sub, done) => { bar.querySelector('strong').textContent = head; bar.querySelector('span').textContent = sub; bar.classList.toggle('done', !!done); };
  show('Sending ' + sent + ' of ' + total + '…', 'Keep this page open until it finishes.');
  const step = () => {
    const fd = new FormData(); fd.append('action', 'step'); fd.append('blast', id); fd.append('csrf', csrf);
    fetch('mail.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then((r) => r.json()).then((r) => {
      if (!r.ok) { note.textContent = 'Paused: ' + r.error + ' Reload the page to try again.'; show('Paused', r.error || ''); return; }
      sent += r.sent; failed += r.failed;
      prog.textContent = sent + ' of ' + total + ' sent' + (failed ? ', ' + failed + ' failed' : '');
      if (r.left > 0) show('Sending ' + sent + ' of ' + total + '…', 'Keep this page open until it finishes.');
      else show(failed ? 'Sent ' + sent + ' of ' + total + ', ' + failed + ' failed' : 'Sent to ' + sent + ' of ' + total + ' ✓', 'Done. Each copy is in the mailbox\'s Sent folder. Don\'t press Send again.', true);
      if (r.error) note.textContent = 'Last problem: ' + r.error;
      if (r.left > 0) setTimeout(step, 1500); else { note.textContent = 'Done.'; box.querySelector('.status-pill')?.remove(); box.querySelector('form')?.remove(); }
    }).catch(() => { note.textContent = 'Lost the connection. Reload the page to keep going.'; });
  };
  step();
})();
</script>
<?php rs_foot(['assets/mail-builder.js']);
