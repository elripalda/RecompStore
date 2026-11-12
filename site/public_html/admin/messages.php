<?php
// RecompStore admin (3.0): messages with developers. Each developer has one conversation with the
// team, shown in their hub; the Lounge is the room every approved developer shares. You can post
// there as RecompStore and take posts down.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'messages.php';
    try {
        if ($action === 'send') {
            $acc = rs_dev_account((int)($_POST['dev'] ?? 0));
            if (!$acc) throw new RuntimeException('That developer is gone.');
            $body = (string)($_POST['body'] ?? '');
            rs_dev_msg_add('dm', (int)$acc['id'], true, $body);
            $back = 'messages.php?dev=' . (int)$acc['id'];
            // Only when you tick it: an email that there's a message waiting (you press Send).
            if (!empty($_POST['email'])) {
                if (!rs_mail_ready()) throw new RuntimeException('Sent to their hub, but the mailbox isn\'t set up, so no email went out.');
                if (!filter_var((string)$acc['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Sent to their hub. There\'s no email on their account.');
                [$html, $text] = rs_mb_email([
                    ['t' => 'text', 'text' => "Hi {name},\n\nThere's a new message for you in your developer hub:\n\n" . trim($body)],
                    ['t' => 'button', 'label' => 'Reply in my hub', 'url' => 'https://recompstore.com/hub?tab=messages', 'style' => 'main'],
                ], ['name' => $acc['name'] ?: ($acc['gh_login'] ?: 'there')], rs_mail_get('mail_sign'), "You're getting this because you have a developer account on RecompStore.");
                rs_mail_send((string)$acc['email'], 'A message from RecompStore', $text, ['html' => $html, 'save' => true]);
                rs_flash('Sent, and emailed to ' . $acc['email'] . '.');
            } else rs_flash('Sent. It\'s in their hub.');
            rs_log('developers', 'message', 'to ' . rs_dev_label($acc));
        } elseif ($action === 'lounge-post') {
            $r = array_key_exists($_POST['room'] ?? '', RS_DEV_ROOMS) ? (string)$_POST['room'] : 'general';
            rs_dev_msg_add('lounge', 0, true, (string)($_POST['body'] ?? ''), $r);
            $back = 'messages.php?view=lounge&room=' . $r;
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
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

// The developers: anyone with a conversation, then everyone approved or waiting, newest first.
$threads = $db->query("SELECT a.*, m.last_id, m.last_at, m.unread FROM dev_accounts a
    LEFT JOIN (SELECT account_id, MAX(id) AS last_id, MAX(created) AS last_at, SUM(from_admin = 0 AND read_admin = 0 AND deleted = 0) AS unread FROM dev_messages WHERE channel = 'dm' GROUP BY account_id) m ON m.account_id = a.id
    WHERE a.status IN ('approved', 'pending') OR m.last_id IS NOT NULL
    ORDER BY (m.last_id IS NULL), m.last_at DESC, a.name")->fetchAll();
if ($view === 'dm' && $devId === 0 && $threads) $devId = (int)$threads[0]['id'];
$cur = null;
foreach ($threads as $t) if ((int)$t['id'] === $devId) $cur = $t;
$msgs = [];
if ($cur) {
    $db->prepare("UPDATE dev_messages SET read_admin = 1 WHERE channel = 'dm' AND account_id = ? AND from_admin = 0")->execute([$cur['id']]);
    $msgs = rs_dev_dm((int)$cur['id'], 300);
}
$lounge = [];
if ($view === 'lounge') {
    $st = $db->prepare("SELECT * FROM dev_messages WHERE channel = 'lounge' AND room = ? ORDER BY id DESC LIMIT 200");
    $st->execute([$room]);
    $lounge = array_reverse($st->fetchAll());
}
$roomCounts = $db->query("SELECT room, COUNT(*) FROM dev_messages WHERE channel = 'lounge' AND deleted = 0 GROUP BY room")->fetchAll(PDO::FETCH_KEY_PAIR);
$docLinks = $db->query('SELECT slug, title, audience, published FROM dev_docs ORDER BY FIELD(audience, \'pc\', \'ps5\', \'all\'), sort, id')->fetchAll();
$docPicker = fn() => $docLinks ? '<select class="doc-pick" data-doc-pick aria-label="Add a link to a doc"><option value="">Add a link to a doc…</option>'
    . implode('', array_map(fn($x) => '<option value="https://recompstore.com/hub?tab=docs&amp;doc=' . h(rawurlencode((string)$x['slug'])) . '">' . h(strtoupper($x['audience']) . ' · ' . $x['title']) . ((int)$x['published'] ? '' : ' (draft)') . '</option>', $docLinks)) . '</select>' : '';
$counts = ['all' => count(rs_dev_broadcast_list('all', false)), 'pc' => count(rs_dev_broadcast_list('pc', false)), 'ps5' => count(rs_dev_broadcast_list('ps5', false))];
$casts = $view === 'all' ? $db->query('SELECT * FROM dev_broadcasts ORDER BY id DESC LIMIT 20')->fetchAll() : [];
$names = $lounge ? rs_dev_msg_names(array_map(fn($r) => (int)$r['account_id'], $lounge)) : [];
$when = fn(string $c) => rs_date('M j, g:i a', strtotime($c));
$label = fn(array $r) => (string)($r['name'] ?: ($r['gh_login'] ?: $r['email']));
$pic = fn(array $r) => (string)$r['avatar'] !== '' ? '<img class="msg-pic" src="' . h((string)$r['avatar']) . '" alt="" width="36" height="36">'
    : '<span class="msg-pic msg-letter">' . h(mb_strtoupper(mb_substr($label($r), 0, 1))) . '</span>';
$plats = fn(array $r) => implode('', array_map(fn($p) => '<span class="pill">' . h($p) . '</span>', rs_dev_platform_labels((string)($r['platforms'] ?? ''))));
// Links in a message, clickable; everything else as text.
$fmt = fn(string $t) => nl2br(preg_replace('#(https?://[^\s<]+[^\s<.,;:!?)\]])#', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', h($t)));

rs_head('Messages', true, ['assets/messages.css']);
?>
<section class="head-row">
  <div>
    <h1>Messages</h1>
    <p class="muted">Talk to developers in their hub. They see your messages under Messages there, and can write back. The Lounge has channels every approved developer shares, and Send to all reaches many of them at once.</p>
  </div>
</section>
<nav class="tabs" aria-label="Messages">
  <a href="messages.php"<?= $view === 'dm' ? ' aria-current="page"' : '' ?>>Developers<?= ($u = rs_dev_admin_unread()) ? ' <span class="count">' . $u . '</span>' : '' ?></a>
  <?php if (!$limitedMsg): ?><a href="messages.php?view=lounge"<?= $view === 'lounge' ? ' aria-current="page"' : '' ?>>Lounge</a>
  <a href="messages.php?view=all"<?= $view === 'all' ? ' aria-current="page"' : '' ?>>Send to all</a><?php endif; ?>
</nav>

<?php if ($view === 'dm'): ?>
<div class="msg-layout">
  <aside class="card msg-list" aria-label="Developers">
    <?php if (!$threads): ?><p class="muted">No developers yet.</p><?php endif; ?>
    <?php foreach ($threads as $t): ?>
    <a class="msg-who<?= $cur && (int)$t['id'] === (int)$cur['id'] ? ' on' : '' ?>" href="messages.php?dev=<?= (int)$t['id'] ?>">
      <?= $pic($t) ?>
      <span class="msg-who-words"><strong><?= h($label($t)) ?></strong><span class="muted"><?= $t['last_at'] ? h($when((string)$t['last_at'])) : ($t['status'] === 'pending' ? 'Waiting for approval' : 'No messages yet') ?></span></span>
      <?= (int)$t['unread'] ? '<span class="count">' . (int)$t['unread'] . '</span>' : '' ?>
    </a>
    <?php endforeach; ?>
  </aside>
  <section class="card msg-thread">
    <?php if (!$cur): ?><p class="muted">Pick a developer.</p><?php else: ?>
    <header class="msg-head"><?= $pic($cur) ?><div><h2><?= h($label($cur)) ?></h2>
      <span class="muted"><?= (string)$cur['gh_login'] !== '' ? 'github.com/' . h((string)$cur['gh_login']) : h((string)$cur['email']) ?><?= $cur['slug'] !== '' ? ' · <a href="/dev/' . h(rawurlencode((string)$cur['slug'])) . '" target="_blank">/dev/' . h((string)$cur['slug']) . '</a>' : '' ?></span>
      <?= $plats($cur) ?><?= $cur['status'] === 'pending' ? '<span class="pill">Waiting for approval</span>' : '' ?></div></header>
    <div class="msg-flow" id="flow">
      <?php if (!$msgs): ?><p class="muted">No messages yet. Say hello.</p><?php endif; ?>
      <?php foreach ($msgs as $m): ?>
      <div class="msg-bubble<?= $m['team'] ? ' team' : '' ?>"><p><?= $fmt($m['body']) ?></p><span class="muted"><?= $m['team'] ? 'You' : h($m['who']) ?> · <?= h($when(gmdate('Y-m-d H:i:s', strtotime($m['at'])))) ?></span></div>
      <?php endforeach; ?>
    </div>
    <form method="post" class="msg-send"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="dev" value="<?= (int)$cur['id'] ?>">
      <textarea name="body" rows="3" maxlength="<?= RS_DEV_MSG_MAX ?>" required placeholder="Write to <?= h($label($cur)) ?>…"></textarea>
      <div class="msg-send-row">
        <label class="check"><input type="checkbox" name="email" value="1"<?= rs_mail_ready() && filter_var((string)$cur['email'], FILTER_VALIDATE_EMAIL) ? '' : ' disabled' ?>> Also email them that there's a message</label>
        <span class="msg-send-btns"><?= $docPicker() ?><button class="primary">Send</button></span>
      </div>
    </form>
    <?php endif; ?>
  </section>
</div>
<script>{ const f = document.getElementById('flow'); if (f) f.scrollTop = f.scrollHeight; }</script>
<?php elseif ($view === 'lounge'): ?>
<nav class="chips room-chips" aria-label="Channels">
  <?php foreach (RS_DEV_ROOMS as $k => $name): ?><a href="messages.php?view=lounge&amp;room=<?= h($k) ?>"<?= $room === $k ? ' aria-current="page"' : '' ?>>#<?= h($name) ?><?= !empty($roomCounts[$k]) ? ' <span class="muted">' . (int)$roomCounts[$k] . '</span>' : '' ?></a><?php endforeach; ?>
</nav>
<section class="card msg-thread lounge">
  <p class="muted"><?= h(['general' => 'Every approved developer can read and post here.', 'showcase' => 'Every approved developer: what they\'re working on, builds and screenshots.', 'pc' => 'Developers who make PC recomps (and anyone who hasn\'t said yet).', 'ps5' => 'Developers who make PS5 apps (and anyone who hasn\'t said yet).'][$room]) ?> Posts you take down disappear for them.</p>
  <div class="msg-flow" id="flow">
    <?php if (!$lounge): ?><p class="muted">Nothing here yet.</p><?php endif; ?>
    <?php foreach ($lounge as $r): $n = $names[(int)$r['account_id']] ?? null; ?>
    <div class="msg-bubble<?= (int)$r['from_admin'] ? ' team' : '' ?><?= (int)$r['deleted'] ? ' gone' : '' ?>">
      <p><?= $fmt((string)$r['body']) ?></p>
      <span class="muted"><?= (int)$r['from_admin'] ? 'You (RecompStore)' : h($n['name'] ?? 'A developer') ?> · <?= h($when((string)$r['created'])) ?><?= (int)$r['deleted'] === 1 ? ' · taken down' : ((int)$r['deleted'] === 2 ? ' · deleted by them' : '') ?></span>
      <?php if ((int)$r['deleted'] !== 2): ?><form method="post" class="msg-mod"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="room" value="<?= h($room) ?>">
        <button class="link" name="action" value="<?= (int)$r['deleted'] ? 'lounge-restore' : 'lounge-delete' ?>"><?= (int)$r['deleted'] ? 'Put back' : 'Take down' ?></button></form><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="post" class="msg-send"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="lounge-post">
    <input type="hidden" name="room" value="<?= h($room) ?>">
    <textarea name="body" rows="3" maxlength="<?= RS_DEV_MSG_MAX ?>" required placeholder="Post in #<?= h(RS_DEV_ROOMS[$room]) ?> as RecompStore…"></textarea>
    <div class="msg-send-row"><span></span><span class="msg-send-btns"><?= $docPicker() ?><button class="primary">Post</button></span></div>
  </form>
</section>
<script>{ const f = document.getElementById('flow'); if (f) f.scrollTop = f.scrollHeight; }</script>
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
<script>
// A doc's link into the message being written.
document.querySelectorAll('[data-doc-pick]').forEach((s) => s.addEventListener('change', () => {
  const ta = s.closest('form').querySelector('textarea');
  if (s.value && ta) { ta.value = (ta.value.trim() ? ta.value.replace(/\s*$/, '') + '\n' : '') + s.value; ta.focus(); }
  s.value = '';
}));
</script>
<?php rs_foot();
