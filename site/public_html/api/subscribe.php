<?php
// RecompStore website (2.7.1): sign up for the developer list (the Press kit page).
//   POST JSON {email, website (left empty)} -> {ok, message} or {ok:false, error}
// The address gets an email with a link to confirm; nothing is sent to the list until it's confirmed.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_sub_reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_sub_reply(405, ['ok' => false, 'error' => 'Send the form from the website.']);
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) rs_sub_reply(403, ['ok' => false, 'error' => 'Send the form from the website.']);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
if (!is_array($in)) rs_sub_reply(400, ['ok' => false, 'error' => 'The form didn\'t arrive whole. Try again.']);
$sent = ['ok' => true, 'state' => 'sent', 'message' => 'Check your email: there\'s a link in it to confirm.'];
if (trim((string)($in['website'] ?? '')) !== '') rs_sub_reply(200, $sent); // a bot
$email = strtolower(trim((string)($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) rs_sub_reply(422, ['ok' => false, 'error' => 'That doesn\'t look like an email address.']);

try {
    rs_mail_tables();
    if (!rs_mail_ready()) rs_sub_reply(503, ['ok' => false, 'error' => 'Signing up isn\'t open right now. Try again later.']);
    $db = rs_db();
    $ip = rs_ip_hash();
    // At most 3 sign-ups an hour from one address, and 30 from everyone together.
    $st = $db->prepare('SELECT COUNT(*) FROM mail_list WHERE ip_hash = ? AND changed > (NOW() - INTERVAL 1 HOUR)');
    $st->execute([$ip]);
    $all = (int)$db->query("SELECT COUNT(*) FROM mail_sent WHERE kind = 'confirm' AND created > (NOW() - INTERVAL 1 HOUR)")->fetchColumn();
    if ((int)$st->fetchColumn() >= 3 || $all >= 30) rs_sub_reply(429, ['ok' => false, 'error' => 'Lots of sign-ups right now. Try again in an hour.']);

    $st = $db->prepare('SELECT * FROM mail_list WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row && $row['status'] === 'on') rs_sub_reply(200, ['ok' => true, 'state' => 'there', 'message' => 'You\'re already on the list.']);
    if ($row) {
        // Signed up before (or left): a fresh confirmation, at most one a day.
        $last = $db->prepare("SELECT COUNT(*) FROM mail_sent WHERE kind = 'confirm' AND list_id = ? AND created > (NOW() - INTERVAL 1 DAY)");
        $last->execute([$row['id']]);
        if ((int)$last->fetchColumn() > 0) rs_sub_reply(200, $sent);
        $db->prepare("UPDATE mail_list SET status = 'pending', ip_hash = ?, changed = NOW() WHERE id = ?")->execute([$ip, $row['id']]);
    } else {
        rs_list_add($email, '', 'signup', 'pending', false, $ip);
        $st->execute([$email]);
        $row = $st->fetch();
    }
    $text = "Hi,\n\nSomeone (hopefully you) asked to join RecompStore's developer list with this address. Confirm here:\n\n"
        . rs_list_url('on', (string)$row['token']) . "\n\nIt's for news about the store that matters to people who make apps for it: new features, the press kit, things like that. Not you? Ignore this and nothing happens.";
    try {
        rs_mail_send($email, 'Confirm your email for RecompStore', $text . "\n\n" . rs_mail_get('mail_sign'));
        rs_mail_log('confirm', $email, 'Confirm your email for RecompStore', null, '', null, null, (int)$row['id']);
    } catch (Throwable $e) {
        rs_mail_log('confirm', $email, 'Confirm your email for RecompStore', null, $e->getMessage(), null, null, (int)$row['id']);
        rs_sub_reply(503, ['ok' => false, 'error' => 'The email didn\'t go out. Try again later.']);
    }
    rs_sub_reply(200, $sent);
} catch (Throwable $e) {
    rs_sub_reply(503, ['ok' => false, 'error' => 'That didn\'t go through. Try again in a moment.']);
}
