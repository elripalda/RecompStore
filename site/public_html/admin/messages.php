<?php
// RecompStore admin (3.0): messages with developers. Each developer has one conversation with the
// team, shown in their hub; the Lounge is the room every approved developer shares. You can post
// there as RecompStore and take posts down.
// (4.12) The inbox: "Waiting for reply" on conversations where the developer wrote last, Dismissed for
// ones to put away (back when they write again), New message to anyone with an account, files with a
// message (inc/devmsg.php), "Seen" when they've read yours, and new messages without reloading
// (assets/messages.js polls ?poll=1). The Lounge reads like a Discord channel.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_dev_tables();
$db = rs_db();
$view = in_array($_GET['view'] ?? '', ['lounge', 'all'], true) ? (string)$_GET['view'] : 'dm';
// (4.8) A game curator talks with developers one to one; the Lounge and Send to all stay with the owner.
$limitedMsg = !rs_can_act('messages.php', 'broadcast');
if ($limitedMsg) $view = 'dm';
$room = array_key_exists($_GET['room'] ?? '', RS_DEV_ROOMS) ? (string)$_GET['room'] : 'general';
$devId = (int)($_GET['dev'] ?? 0);
$box = ($_GET['box'] ?? '') === 'dismissed' ? 'dismissed' : 'inbox';
$ajax = !empty($_POST['ajax']) || !empty($_GET['poll']);
$jsonOut = function (array $o): void { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; };

// ---- Drawing --------------------------------------------------------------------------------------
$label = fn(array $r) => (string)($r['name'] ?: ($r['gh_login'] ?: $r['email']));
$pic = fn(array $r, int $s = 36) => (string)($r['avatar'] ?? '') !== '' ? '<img class="mx-av" src="' . h((string)$r['avatar']) . '" alt="" width="' . $s . '" height="' . $s . '" loading="lazy">'
    : '<span class="mx-av mx-letter">' . h(mb_strtoupper(mb_substr($label($r), 0, 1))) . '</span>';
$fmt = fn(string $t) => nl2br(preg_replace('#(https?://[^\s<]+[^\s<.,;:!?)\]])#', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', h($t)));
$ts = fn(string $c) => (int)strtotime($c . ' UTC');
$time = fn(int $t) => rs_date('g:i a', $t);
$dayName = function (int $t): string {
    $d = rs_date('Y-m-d', $t);
    if ($d === rs_date('Y-m-d', time())) return 'Today';
    if ($d === rs_date('Y-m-d', time() - 86400)) return 'Yesterday';
    return rs_date($d >= rs_date('Y', time()) . '-01-01' ? 'l, F j' : 'F j, Y', $t);
};
$ago = function (int $t) use ($time): string {
    $s = time() - $t;
    if ($s < 60) return 'now';
    if ($s < 3600) return floor($s / 60) . 'm';
    if (rs_date('Y-m-d', $t) === rs_date('Y-m-d', time())) return $time($t);
    if ($s < 6 * 86400) return rs_date('D', $t);
    return rs_date('M j', $t);
};
$size = fn(int $b) => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
$filesHtml = function (array $files) use ($size): string {
    if (!$files) return '';
    $o = '<div class="mx-files">';
    foreach ($files as $f) {
        $u = 'msg-file.php?f=' . h((string)$f['t']);
        if ($f['kind'] === 'image') $o .= '<a class="mx-img" href="' . $u . '" target="_blank" rel="noopener"><img src="' . $u . '" alt="' . h((string)$f['name']) . '" loading="lazy"></a>';
        elseif ($f['kind'] === 'video') $o .= '<video class="mx-vid" src="' . $u . '" controls preload="metadata"></video>';
        else $o .= '<a class="mx-file" href="' . $u . '"><span class="mx-file-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/></svg></span><span><b>' . h((string)$f['name']) . '</b><small>' . h($size((int)$f['size'])) . '</small></span></a>';
    }
    return $o . '</div>';
};
// The conversation list: Inbox (not dismissed) or Dismissed (plus everyone with no messages yet).
$listHtml = function (array $threads, array $quiet, string $box, int $cur) use ($label, $pic, $ago, $ts): string {
    $rows = array_values(array_filter($threads, fn($t) => (bool)(int)$t['dismissed'] === ($box === 'dismissed')));
    $o = '';
    if (!$rows && $box === 'inbox') $o .= '<p class="mx-empty">All caught up. Nothing waiting.</p>';
    foreach ($rows as $t) {
        $q = mb_strtolower($label($t) . ' ' . $t['gh_login'] . ' ' . $t['email']);
        $o .= '<a class="mx-row' . ((int)$t['id'] === $cur ? ' on' : '') . ((int)$t['unread'] ? ' unread' : '') . '" href="messages.php?dev=' . (int)$t['id'] . ($box === 'dismissed' ? '&amp;box=dismissed' : '') . '" data-q="' . h($q) . '" data-dev="' . (int)$t['id'] . '">'
            . '<span class="mx-row-av">' . $pic($t, 40) . ((int)$t['unread'] ? '<i class="mx-dot" aria-label="Unread"></i>' : '') . '</span>'
            . '<span class="mx-row-main"><span class="mx-row-top"><b>' . h($label($t)) . '</b><time>' . h($ago($ts((string)$t['last_at']))) . '</time></span>'
            . '<span class="mx-row-prev">' . h((string)$t['preview']) . '</span>'
            . ($t['waiting'] ? '<span class="mx-wait">Waiting for reply</span>' : '') . '</span>'
            . ((int)$t['unread'] ? '<span class="count">' . (int)$t['unread'] . '</span>' : '') . '</a>';
    }
    if ($box === 'dismissed') {
        if (!$rows) $o .= '<p class="mx-empty">Nothing dismissed yet.</p>';
        if ($quiet) {
            $o .= '<p class="mx-sub">No messages yet <span>' . count($quiet) . '</span></p>';
            foreach ($quiet as $t) $o .= '<a class="mx-row quiet' . ((int)$t['id'] === $cur ? ' on' : '') . '" href="messages.php?dev=' . (int)$t['id'] . '&amp;box=dismissed" data-q="' . h(mb_strtolower($label($t) . ' ' . $t['gh_login'] . ' ' . $t['email'])) . '" data-dev="' . (int)$t['id'] . '">'
                . '<span class="mx-row-av">' . $pic($t, 40) . '</span><span class="mx-row-main"><span class="mx-row-top"><b>' . h($label($t)) . '</b></span><span class="mx-row-prev">' . ($t['status'] === 'pending' ? 'Waiting for approval' : 'Say hello') . '</span></span></a>';
        }
    }
    return $o;
};
// One conversation: bubbles by day, files, and "Seen" on yours once they've read it.
$flowHtml = function (array $acc, array $rows) use ($label, $pic, $fmt, $ts, $time, $dayName, $filesHtml): string {
    if (!$rows) return '<div class="mx-hello">' . $pic($acc, 64) . '<b>' . h($label($acc)) . '</b><p class="muted">No messages yet. Say hello: it lands in their developer hub.</p></div>';
    $o = ''; $day = ''; $prev = null;
    $lastTeam = 0;
    foreach ($rows as $r) if ((int)$r['from_admin'] && !(int)$r['deleted']) $lastTeam = (int)$r['id'];
    foreach ($rows as $r) {
        if ((int)$r['deleted']) continue;
        $t = $ts((string)$r['created']);
        $d = rs_date('Y-m-d', $t);
        if ($d !== $day) { $o .= '<div class="mx-day"><span>' . h($dayName($t)) . '</span></div>'; $day = $d; $prev = null; }
        $team = (bool)(int)$r['from_admin'];
        $cont = $prev && (bool)(int)$prev['from_admin'] === $team && $t - $ts((string)$prev['created']) < 300;
        $seen = $team && (int)$r['read_dev'];
        $tick = $team ? '<span class="mx-tick' . ($seen ? ' seen' : '') . '" title="' . h($seen ? ('Seen' . ($r['read_dev_at'] ? ' ' . rs_date('M j, g:i a', $ts((string)$r['read_dev_at'])) : '')) : 'Sent, not seen yet') . '">' . ($seen ? '✓✓' : '✓') . '</span>' : '';
        $files = rs_msg_files_of($r);
        $o .= '<div class="mx-msg' . ($team ? ' team' : '') . ($cont ? ' cont' : '') . '" data-id="' . (int)$r['id'] . '">'
            . (!$team ? '<span class="mx-msg-av">' . ($cont ? '' : $pic($acc, 32)) . '</span>' : '')
            . '<div class="mx-bub">' . (trim((string)$r['body']) !== '' ? '<p>' . $fmt((string)$r['body']) . '</p>' : '') . $filesHtml($files)
            . '<span class="mx-meta">' . h($time($t)) . $tick . '</span></div></div>';
        if ((int)$r['id'] === $lastTeam) $o .= '<div class="mx-receipt">' . ($seen ? 'Seen' . ($r['read_dev_at'] ? ' ' . h(rs_date((rs_date('Y-m-d', $ts((string)$r['read_dev_at'])) === rs_date('Y-m-d', time()) ? '' : 'M j, ') . 'g:i a', $ts((string)$r['read_dev_at']))) : '') : 'Sent') . '</div>';
        $prev = $r;
    }
    return $o;
};
// A Lounge channel, like Discord: avatar and name once, then that person's next messages under it.
$loungeHtml = function (array $rows, string $room) use ($fmt, $ts, $time, $dayName, $filesHtml): string {
    if (!$rows) return '<div class="dc-hello"><span class="dc-hash">#</span><b>Welcome to #' . h(RS_DEV_ROOMS[$room]) . '</b><p class="muted">Nothing here yet.</p></div>';
    $names = rs_dev_msg_names(array_map(fn($r) => (int)$r['account_id'], $rows));
    $o = ''; $day = ''; $prev = null;
    foreach ($rows as $r) {
        $t = $ts((string)$r['created']);
        $d = rs_date('Y-m-d', $t);
        if ($d !== $day) { $o .= '<div class="dc-day"><span>' . h($dayName($t)) . '</span></div>'; $day = $d; $prev = null; }
        $team = (bool)(int)$r['from_admin'];
        $n = $team ? null : ($names[(int)$r['account_id']] ?? null);
        $who = $team ? 'RecompStore' : ($n['name'] ?? 'A developer');
        $cont = $prev && (int)$prev['from_admin'] === (int)$r['from_admin'] && (int)$prev['account_id'] === (int)$r['account_id'] && $t - $ts((string)$prev['created']) < 420;
        $av = $team ? '<span class="dc-av team">R</span>' : (($n['pic'] ?? '') !== '' ? '<img class="dc-av" src="' . h((string)$n['pic']) . '" alt="" loading="lazy">' : '<span class="dc-av">' . h(mb_strtoupper(mb_substr($who, 0, 1))) . '</span>');
        $gone = (int)$r['deleted'];
        $o .= '<div class="dc-msg' . ($cont ? ' cont' : '') . ($gone ? ' gone' : '') . '" data-id="' . (int)$r['id'] . '">'
            . ($cont ? '<time class="dc-side">' . h($time($t)) . '</time>' : $av)
            . '<div class="dc-body">' . ($cont ? '' : '<div class="dc-head"><b class="dc-name' . ($team ? ' team' : '') . '">' . h($who) . '</b>' . ($team ? '<span class="dc-tag">Team</span>' : (!empty($n['verified']) ? '<span class="dc-tag ver">Verified</span>' : '')) . '<time>' . h($dayName($t) . ' at ' . $time($t)) . '</time></div>')
            . (trim((string)$r['body']) !== '' ? '<div class="dc-text">' . $fmt((string)$r['body']) . '</div>' : '') . $filesHtml(rs_msg_files_of($r))
            . ($gone ? '<div class="dc-note">' . ($gone === 1 ? 'Taken down: developers don\'t see it' : 'Deleted by them') . '</div>' : '') . '</div>'
            . ($gone !== 2 ? '<form method="post" class="dc-tools"><input type="hidden" name="csrf" value="' . h(rs_csrf()) . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="room" value="' . h($room) . '"><button name="action" value="' . ($gone ? 'lounge-restore' : 'lounge-delete') . '">' . ($gone ? 'Put back' : 'Take down') . '</button></form>' : '')
            . '</div>';
        $prev = $r;
    }
    return $o;
};
$dmRows = function (int $accId) use ($db): array {
    $st = $db->prepare("SELECT * FROM dev_messages WHERE channel = 'dm' AND account_id = ? ORDER BY id DESC LIMIT 300");
    $st->execute([$accId]);
    return array_reverse($st->fetchAll());
};
$loungeRows = function (string $room) use ($db): array {
    $st = $db->prepare("SELECT * FROM dev_messages WHERE channel = 'lounge' AND room = ? ORDER BY id DESC LIMIT 200");
    $st->execute([$room]);
    return array_reverse($st->fetchAll());
};

// ---- Buttons --------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'messages.php';
    try {
        if ($action === 'send') {
            $acc = rs_dev_account((int)($_POST['dev'] ?? 0));
            if (!$acc) throw new RuntimeException('That developer is gone.');
            $body = (string)($_POST['body'] ?? '');
            $files = rs_msg_files_check($_FILES['files'] ?? null);
            rs_dev_msg_add('dm', (int)$acc['id'], true, $body, 'general', $files);
            $back = 'messages.php?dev=' . (int)$acc['id'];
            $note = 'Sent. It\'s in their hub.';
            // Only when you tick it: an email that there's a message waiting (you press Send).
            if (!empty($_POST['email'])) {
                if (!rs_mail_ready()) throw new RuntimeException('Sent to their hub, but the mailbox isn\'t set up, so no email went out.');
                if (!filter_var((string)$acc['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Sent to their hub. There\'s no email on their account.');
                [$html, $text] = rs_mb_email([
                    ['t' => 'text', 'text' => "Hi {name},\n\nThere's a new message for you in your developer hub:\n\n" . (trim($body) !== '' ? trim($body) : '(a file)')],
                    ['t' => 'button', 'label' => 'Reply in my hub', 'url' => 'https://recomplauncher.com/dev?tab=messages', 'style' => 'main'],
                ], ['name' => $acc['name'] ?: ($acc['gh_login'] ?: 'there')], rs_mail_get('mail_sign'), "You're getting this because you have a developer account on RecompLauncher.");
                rs_mail_send((string)$acc['email'], 'A message from RecompLauncher', $text, ['html' => $html, 'save' => true]);
                $note = 'Sent, and emailed to ' . $acc['email'] . '.';
            }
            rs_log('developers', 'message', 'to ' . rs_dev_label($acc) . ($files ? ' with ' . count($files) . ' file' . (count($files) === 1 ? '' : 's') : ''));
            if ($ajax) $jsonOut(['ok' => true, 'msg' => $note]);
            rs_flash($note);
        } elseif ($action === 'dismiss' || $action === 'undismiss') {
            $acc = rs_dev_account((int)($_POST['dev'] ?? 0));
            if (!$acc) throw new RuntimeException('That developer is gone.');
            rs_msg_dismiss((int)$acc['id'], $action === 'dismiss');
            $back = $action === 'dismiss' ? 'messages.php' : 'messages.php?dev=' . (int)$acc['id'];
            rs_flash($action === 'dismiss' ? 'Dismissed. It comes back to the inbox if they write again.' : 'Back in the inbox.');
        } elseif ($action === 'dismiss_quiet') {
            // Everyone in the inbox who isn't waiting on a reply, in one go.
            $n = 0;
            foreach (rs_msg_threads() as $t) if (!(int)$t['dismissed'] && !$t['waiting'] && !(int)$t['unread']) { rs_msg_dismiss((int)$t['id'], true); $n++; }
            rs_flash($n ? 'Dismissed ' . $n . ' conversation' . ($n === 1 ? '' : 's') . ' that weren\'t waiting on you.' : 'Every conversation in the inbox is waiting on a reply.');
        } elseif ($action === 'lounge-post') {
            $r = array_key_exists($_POST['room'] ?? '', RS_DEV_ROOMS) ? (string)$_POST['room'] : 'general';
            $files = rs_msg_files_check($_FILES['files'] ?? null);
            rs_dev_msg_add('lounge', 0, true, (string)($_POST['body'] ?? ''), $r, $files);
            $back = 'messages.php?view=lounge&room=' . $r;
            if ($ajax) $jsonOut(['ok' => true, 'msg' => 'Posted in #' . RS_DEV_ROOMS[$r] . '.']);
            rs_flash('Posted in #' . RS_DEV_ROOMS[$r] . '.');
        } elseif ($action === 'broadcast') {
            // (3.1) One message to many developers: it lands in each one's conversation with you.
            $aud = in_array($_POST['audience'] ?? '', ['all', 'pc', 'ps5'], true) ? (string)$_POST['audience'] : 'all';
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if ($body === '') throw new RuntimeException('Write the message first.');
            $who = rs_dev_broadcast_list($aud, !empty($_POST['waiting']));
            if (!$who) throw new RuntimeException('Nobody matches that yet.');
            // The same message twice in a few minutes is a double click, not a second message.
            $again = $db->prepare('SELECT id FROM dev_broadcasts WHERE body = ? AND created > NOW() - INTERVAL 5 MINUTE');
            $again->execute([$body]);
            if ($again->fetch()) throw new RuntimeException('You sent that a moment ago. It\'s already in their Messages.');
            @set_time_limit(0);
            ignore_user_abort(true);
            $mail = !empty($_POST['email']) && rs_mail_ready();
            // Everyone gets it in their hub first, all at once; the emails come after.
            $db->beginTransaction();
            foreach ($who as $acc) rs_dev_msg_add('dm', (int)$acc['id'], true, $body);
            $db->prepare('INSERT INTO dev_broadcasts (audience, body, sent_to, emailed, created) VALUES (?, ?, ?, 0, NOW())')->execute([$aud . (!empty($_POST['waiting']) ? '+waiting' : ''), $body, count($who)]);
            $castId = (int)$db->lastInsertId();
            $db->commit();
            $sent = count($who);
            $emailed = 0;
            foreach ($who as $acc) {
                if ($mail && filter_var((string)$acc['email'], FILTER_VALIDATE_EMAIL)) {
                    try {
                        [$html, $text] = rs_mb_email([
                            ['t' => 'text', 'text' => "Hi {name},\n\nThere's a new message for you in your developer hub:\n\n" . $body],
                            ['t' => 'button', 'label' => 'Open my hub', 'url' => 'https://recompstore.com/hub?tab=messages', 'style' => 'main'],
                        ], ['name' => $acc['name'] ?: ($acc['gh_login'] ?: 'there')], rs_mail_get('mail_sign'), "You're getting this because you have a developer account on RecompStore.");
                        rs_mail_send((string)$acc['email'], 'A message from RecompStore', $text, ['html' => $html, 'save' => true]);
                        $emailed++;
                        $db->prepare('UPDATE dev_broadcasts SET emailed = ? WHERE id = ?')->execute([$emailed, $castId]);
                    } catch (Throwable $e) { /* the hub message is there either way */ }
                }
            }
            rs_log('developers', 'message', 'to ' . $sent . ' developers (' . $aud . ')');
            rs_flash('Sent to ' . $sent . ' developer' . ($sent === 1 ? '' : 's') . ($emailed ? ', and emailed ' . $emailed : '') . '. It\'s in each one\'s Messages.');
            $back = 'messages.php?view=all';
        } elseif ($action === 'lounge-delete' || $action === 'lounge-restore') {
            // Only your own take-downs come back; a post its author deleted (2) stays gone.
            if ($action === 'lounge-delete') $db->prepare("UPDATE dev_messages SET deleted = 1 WHERE id = ? AND channel = 'lounge' AND deleted = 0")->execute([(int)($_POST['id'] ?? 0)]);
            else $db->prepare("UPDATE dev_messages SET deleted = 0 WHERE id = ? AND channel = 'lounge' AND deleted = 1")->execute([(int)($_POST['id'] ?? 0)]);
            $back = 'messages.php?view=lounge&room=' . (array_key_exists($_POST['room'] ?? '', RS_DEV_ROOMS) ? (string)$_POST['room'] : 'general');
        }
    } catch (Throwable $e) {
        if ($ajax) $jsonOut(['ok' => false, 'error' => $e->getMessage()]);
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

// ---- What's on the page ---------------------------------------------------------------------------
$threads = $view === 'dm' ? rs_msg_threads() : [];
$quiet = $view === 'dm' ? rs_msg_quiet() : [];
$cur = null;
if ($view === 'dm') {
    if ($devId === 0) foreach ($threads as $t) if ((bool)(int)$t['dismissed'] === ($box === 'dismissed')) { $devId = (int)$t['id']; break; }
    if ($devId > 0) {
        $cur = rs_dev_account($devId);
        if ($cur) foreach ($threads as $t) if ((int)$t['id'] === $devId) { $cur['dismissed'] = (int)$t['dismissed']; $cur['waiting'] = $t['waiting']; $cur['n'] = (int)$t['n']; }
    }
}
// Opening a conversation (or polling it while it's on screen) reads their messages.
$readCur = function () use ($db, &$cur, &$threads): void {
    if (!$cur) return;
    $st = $db->prepare("UPDATE dev_messages SET read_admin = 1 WHERE channel = 'dm' AND account_id = ? AND from_admin = 0 AND read_admin = 0");
    $st->execute([$cur['id']]);
    if ($st->rowCount()) foreach ($threads as &$t) if ((int)$t['id'] === (int)$cur['id']) $t['unread'] = 0;
};

// (4.12) Polling: the list and the open conversation, sent again only when something changed.
if (!empty($_GET['poll'])) {
    session_write_close();
    if ($view === 'lounge') {
        $rows = $loungeRows($room);
        $sig = md5(json_encode(array_map(fn($r) => [$r['id'], $r['deleted']], $rows)));
        if (($_GET['sig'] ?? '') === $sig) $jsonOut(['ok' => true, 'same' => true]);
        $jsonOut(['ok' => true, 'sig' => $sig, 'flow' => $loungeHtml($rows, $room), 'last' => $rows ? (int)end($rows)['id'] : 0]);
    }
    if (!empty($_GET['vis'])) $readCur();
    $rows = $cur ? $dmRows((int)$cur['id']) : [];
    $unread = array_sum(array_map(fn($t) => (int)$t['unread'], $threads));
    $sig = md5(json_encode([array_map(fn($t) => [$t['id'], $t['last_id'], $t['unread'], $t['dismissed']], $threads), array_map(fn($r) => [$r['id'], $r['read_dev'], $r['deleted']], $rows), $box]));
    if (($_GET['sig'] ?? '') === $sig) $jsonOut(['ok' => true, 'same' => true, 'unread' => $unread]);
    $jsonOut(['ok' => true, 'sig' => $sig, 'unread' => $unread, 'list' => $listHtml($threads, $quiet, $box, $cur ? (int)$cur['id'] : 0),
        'flow' => $cur ? $flowHtml($cur, $rows) : '', 'last' => $rows ? (int)end($rows)['id'] : 0, 'lastIn' => $rows ? max(array_map(fn($r) => (int)$r['from_admin'] ? 0 : (int)$r['id'], $rows)) : 0]);
}
$readCur();
$msgs = $cur ? $dmRows((int)$cur['id']) : [];
$roomCounts = $db->query("SELECT room, COUNT(*) FROM dev_messages WHERE channel = 'lounge' AND deleted = 0 GROUP BY room")->fetchAll(PDO::FETCH_KEY_PAIR);
$docLinks = $db->query('SELECT slug, title, audience, published FROM dev_docs ORDER BY FIELD(audience, \'pc\', \'ps5\', \'all\'), sort, id')->fetchAll();
$docPicker = fn() => $docLinks ? '<select class="doc-pick" data-doc-pick aria-label="Add a link to a doc"><option value="">Link a doc…</option>'
    . implode('', array_map(fn($x) => '<option value="https://recompstore.com/hub?tab=docs&amp;doc=' . h(rawurlencode((string)$x['slug'])) . '">' . h(strtoupper($x['audience']) . ' · ' . $x['title']) . ((int)$x['published'] ? '' : ' (draft)') . '</option>', $docLinks)) . '</select>' : '';
$counts = $view === 'all' ? ['all' => count(rs_dev_broadcast_list('all', false)), 'pc' => count(rs_dev_broadcast_list('pc', false)), 'ps5' => count(rs_dev_broadcast_list('ps5', false))] : [];
$casts = $view === 'all' ? $db->query('SELECT * FROM dev_broadcasts ORDER BY id DESC LIMIT 20')->fetchAll() : [];
$when = fn(string $c) => rs_date('M j, g:i a', strtotime($c));
$plats = fn(array $r) => implode('', array_map(fn($p) => '<span class="pill">' . h($p) . '</span>', rs_dev_platform_labels((string)($r['platforms'] ?? ''))));
$nInbox = count(array_filter($threads, fn($t) => !(int)$t['dismissed']));
$nWait = count(array_filter($threads, fn($t) => !(int)$t['dismissed'] && $t['waiting']));
$nDis = count(array_filter($threads, fn($t) => (int)$t['dismissed'])) + count($quiet);
$clip = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5 12.6 20a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4L15.6 7.4"/></svg>';
$composer = fn(string $ph, string $btn, string $extra) => '<div class="mx-chips" data-chips hidden></div>'
    . '<div class="mx-box"><button type="button" class="mx-attach" data-attach title="Add pictures, a short video or a log (up to 15 MB each)">' . $clip . '<span class="sr">Attach files</span></button>'
    . '<input type="file" name="files[]" multiple hidden data-file accept=".png,.jpg,.jpeg,.gif,.webp,.mp4,.webm,.mov,.txt,.log,.json,.ini,.toml,.cfg,.zip,.7z">'
    . '<textarea name="body" rows="1" maxlength="' . RS_DEV_MSG_MAX . '" placeholder="' . h($ph) . '" data-grow></textarea>'
    . '<button class="primary mx-sendbtn" data-send>' . h($btn) . '</button></div>'
    . '<div class="mx-under">' . $extra . '<span class="mx-hint">Enter to send · Shift+Enter for a new line</span><span class="mx-status" role="status" data-status></span></div>';

rs_head('Messages', true, ['assets/messages.css']);
?>
<section class="head-row">
  <div>
    <h1>Messages</h1>
    <p class="muted">Talk to developers in their hub. "Waiting for reply" means they wrote last. Dismiss what's handled: it comes back if they write again.</p>
  </div>
</section>
<nav class="tabs" aria-label="Messages">
  <a href="messages.php"<?= $view === 'dm' ? ' aria-current="page"' : '' ?>>Developers<?= ($u = rs_dev_admin_unread()) ? ' <span class="count">' . $u . '</span>' : '' ?></a>
  <?php if (!$limitedMsg): ?><a href="messages.php?view=lounge"<?= $view === 'lounge' ? ' aria-current="page"' : '' ?>>Lounge</a>
  <a href="messages.php?view=all"<?= $view === 'all' ? ' aria-current="page"' : '' ?>>Send to all</a><?php endif; ?>
</nav>

<?php if ($view === 'dm'):
  $everyone = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $label($t), 'login' => (string)$t['gh_login'], 'pic' => (string)$t['avatar'], 'note' => (int)$t['dismissed'] ? 'Dismissed' : ($t['waiting'] ? 'Waiting for reply' : '')], $threads);
  foreach ($quiet as $t) $everyone[] = ['id' => (int)$t['id'], 'name' => $label($t), 'login' => (string)$t['gh_login'], 'pic' => (string)$t['avatar'], 'note' => $t['status'] === 'pending' ? 'Waiting for approval' : 'No messages yet'];
?>
<div class="mx" data-mx data-dev="<?= $cur ? (int)$cur['id'] : 0 ?>" data-box="<?= h($box) ?>">
  <aside class="card mx-side" aria-label="Conversations">
    <div class="mx-side-top">
      <div class="mx-seg" role="tablist">
        <a href="messages.php"<?= $box === 'inbox' ? ' class="on" aria-current="page"' : '' ?>>Inbox <span><?= $nInbox ?></span></a>
        <a href="messages.php?box=dismissed"<?= $box === 'dismissed' ? ' class="on" aria-current="page"' : '' ?>>Dismissed <span><?= $nDis ?></span></a>
      </div>
      <button type="button" class="mx-new" data-new title="Message any developer"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>New</button>
    </div>
    <div class="mx-find"><input type="search" placeholder="Search conversations" data-find aria-label="Search conversations"></div>
    <?php if ($box === 'inbox' && $nWait): ?><p class="mx-waitline"><b><?= $nWait ?></b> waiting for a reply</p><?php endif; ?>
    <div class="mx-list" data-list><?= $listHtml($threads, $quiet, $box, $cur ? (int)$cur['id'] : 0) ?></div>
    <?php if ($box === 'inbox' && $nInbox > $nWait): ?>
    <form method="post" class="mx-side-foot"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button class="link" name="action" value="dismiss_quiet" onclick="return confirm('Dismiss every conversation that isn\'t waiting on a reply?');">Dismiss all that aren't waiting on you</button></form>
    <?php endif; ?>
    <div class="mx-newpop" data-newpop hidden>
      <div class="mx-newpop-top"><b>New message</b><button type="button" class="mx-x" data-new-close aria-label="Close">×</button></div>
      <input type="search" placeholder="Find a developer by name or GitHub" data-new-find aria-label="Find a developer">
      <div class="mx-newpop-list" data-new-list></div>
    </div>
    <script type="application/json" data-everyone><?= json_encode($everyone, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </aside>
  <section class="card mx-main">
    <?php if (!$cur): ?><div class="mx-pick"><p class="muted"><?= $box === 'inbox' && !$nInbox ? 'All caught up.' : 'Pick a conversation, or start a New one.' ?></p></div><?php else: ?>
    <header class="mx-head">
      <?= $pic($cur, 44) ?>
      <div class="mx-head-t"><h2><?= h($label($cur)) ?> <?= $plats($cur) ?><?= $cur['status'] === 'pending' ? '<span class="pill">Waiting for approval</span>' : '' ?></h2>
        <span class="muted small"><?= (string)$cur['gh_login'] !== '' ? '<a href="https://github.com/' . h(rawurlencode((string)$cur['gh_login'])) . '" target="_blank" rel="noopener">github.com/' . h((string)$cur['gh_login']) . '</a>' : h((string)$cur['email']) ?><?= $cur['slug'] !== '' ? ' · <a href="https://recomplauncher.com/dev/' . h(rawurlencode((string)$cur['slug'])) . '" target="_blank" rel="noopener">/dev/' . h((string)$cur['slug']) . '</a>' : '' ?><?= $cur['slug'] !== '' && rs_can_page('developers.php') ? ' · <a href="developers.php?edit=' . h(rawurlencode((string)$cur['slug'])) . '">Their developer page</a>' : '' ?></span></div>
      <div class="mx-head-act">
        <?php if (!empty($cur['waiting']) && empty($cur['dismissed'])): ?><span class="mx-wait big">Waiting for reply</span><?php endif; ?>
        <?php if (!empty($cur['n'])): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="dev" value="<?= (int)$cur['id'] ?>">
          <button class="btn small" name="action" value="<?= !empty($cur['dismissed']) ? 'undismiss' : 'dismiss' ?>"><?= !empty($cur['dismissed']) ? 'Back to inbox' : 'Dismiss' ?></button></form><?php endif; ?>
      </div>
    </header>
    <div class="mx-flow" data-flow aria-live="polite"><?= $flowHtml($cur, $msgs) ?></div>
    <form method="post" enctype="multipart/form-data" class="mx-send" data-send-form><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="dev" value="<?= (int)$cur['id'] ?>">
      <?= $composer('Message ' . $label($cur) . '…', 'Send', '<label class="check"><input type="checkbox" name="email" value="1"' . (rs_mail_ready() && filter_var((string)$cur['email'], FILTER_VALIDATE_EMAIL) ? '' : ' disabled') . '> Also email them</label>' . $docPicker()) ?>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php elseif ($view === 'lounge'): $lrows = $loungeRows($room); ?>
<div class="dc" data-lounge data-room="<?= h($room) ?>">
  <aside class="dc-chans">
    <div class="dc-server"><b>Developer Lounge</b><small>Every approved developer</small></div>
    <span class="dc-cat">Channels</span>
    <?php foreach (RS_DEV_ROOMS as $k => $name): ?><a class="dc-chan<?= $room === $k ? ' on' : '' ?>" href="messages.php?view=lounge&amp;room=<?= h($k) ?>"<?= $room === $k ? ' aria-current="page"' : '' ?>><span class="dc-hash">#</span><?= h($name) ?><?= !empty($roomCounts[$k]) ? '<span class="dc-n">' . (int)$roomCounts[$k] . '</span>' : '' ?></a><?php endforeach; ?>
  </aside>
  <section class="dc-main">
    <header class="dc-top"><span class="dc-hash big">#</span><b><?= h(RS_DEV_ROOMS[$room]) ?></b><span class="dc-topic"><?= h(['general' => 'Every approved developer can read and post here.', 'showcase' => 'What developers are working on: builds, screenshots and videos.', 'pc' => 'Developers who make PC recomps (and anyone who hasn\'t said yet).', 'ps5' => 'Developers who make PS5 apps (and anyone who hasn\'t said yet).'][$room]) ?></span></header>
    <div class="dc-flow" data-flow aria-live="polite"><?= $loungeHtml($lrows, $room) ?></div>
    <form method="post" enctype="multipart/form-data" class="mx-send dc-send" data-send-form><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="lounge-post"><input type="hidden" name="room" value="<?= h($room) ?>">
      <?= $composer('Message #' . RS_DEV_ROOMS[$room] . ' as RecompStore', 'Post', $docPicker()) ?>
    </form>
  </section>
</div>
<?php else: ?>
<section class="card cast">
  <h2>Send to all</h2>
  <p class="muted">One message to many developers at once. It lands in each one's conversation with you, so they can reply to you alone.</p>
  <form method="post" class="cast-form"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="broadcast">
    <fieldset class="cast-who"><legend>Who</legend>
      <?php foreach (['all' => 'Every developer', 'pc' => 'PC developers', 'ps5' => 'PS5 developers'] as $k => $v): ?>
      <label class="cast-pick"><input type="radio" name="audience" value="<?= h($k) ?>"<?= $k === 'all' ? ' checked' : '' ?>><span><strong><?= h($v) ?></strong><small><?= (int)$counts[$k] ?> approved</small></span></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="check"><input type="checkbox" name="waiting" value="1"> Developers waiting for approval too</label>
    <textarea name="body" rows="6" maxlength="<?= RS_DEV_MSG_MAX ?>" required placeholder="Write to them…"></textarea>
    <div class="msg-send-row">
      <label class="check"><input type="checkbox" name="email" value="1"<?= rs_mail_ready() ? '' : ' disabled' ?>> Also email each of them that there's a message</label>
      <span class="msg-send-btns"><?= $docPicker() ?><button class="primary" onclick="return confirm('Send this to every developer you picked?');">Send</button></span>
    </div>
    <p class="muted cast-note">PC and PS5 include developers who haven't said what they make yet.</p>
  </form>
</section>
<section class="card cast">
  <h2>Sent before</h2>
  <?php if (!$casts): ?><p class="muted">Nothing yet.</p><?php endif; ?>
  <?php foreach ($casts as $c): ?>
  <div class="msg-bubble team cast-old"><p><?= $fmt((string)$c['body']) ?></p><span class="muted"><?= h(['all' => 'Every developer', 'pc' => 'PC developers', 'ps5' => 'PS5 developers'][explode('+', (string)$c['audience'])[0]] ?? $c['audience']) ?><?= str_contains((string)$c['audience'], '+waiting') ? ' and waiting' : '' ?> · <?= (int)$c['sent_to'] ?> sent<?= (int)$c['emailed'] ? ', ' . (int)$c['emailed'] . ' emailed' : '' ?> · <?= h($when((string)$c['created'])) ?></span></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php rs_foot(['assets/messages.js']);
