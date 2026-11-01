<?php
// RecompStore website (3.0): comments on a game's page.
//   GET  ?game=pc:<id>|ps5:<id>[&sort=helpful|new] -> {ok, comments[], count}
//   POST a=post game body [parent] turnstile -> {ok, comments[]}
//   POST a=helpful id on | a=report id reason | a=remove id
// Reading is open to everyone; writing needs a signed-in account with a username and a confirmed email.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function rs_cmo(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function rs_cme(int $code, string $why, array $more = []): void { rs_cmo($code, ['ok' => false, 'error' => $why] + $more); }

// (3.1g) Signed-out readers of a game's comments all get the same list: a 30-second copy of it.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && !preg_match('/^[0-9a-f]{64}$/', (string)($_COOKIE['rs_acct'] ?? ''))) { require_once dirname(__DIR__) . '/admin/inc/jcache.php'; rs_json_cache('cm-' . md5((string)($_GET['game'] ?? '') . '|' . (string)($_GET['sort'] ?? '')), 30); }
try {
    rs_acct_tables();
    rs_cm_tables();
    if (!rs_acct_on()) rs_cme(404, 'Comments aren\'t open yet.');
    $me = rs_acct_current();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $game = rs_acct_game_key((string)($_GET['game'] ?? ''));
        if (!$game) rs_cme(400, 'Unknown game.');
        rs_cmo(200, ['ok' => true, 'comments' => rs_cm_list($game, $me, (string)($_GET['sort'] ?? 'helpful')), 'count' => rs_cm_count($game)]);
    }
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST))) !== rs_acct_host() || ($_SERVER['HTTP_X_RS'] ?? '') !== '1') rs_cme(403, 'Do that from the website.');
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
    if (!is_array($in)) rs_cme(400, 'The form didn\'t arrive whole. Try again.');
    if (!$me) rs_cme(401, 'Sign in to comment.');
    if ($me['username'] === null) rs_cme(403, 'Pick a username first.');
    $id = (int)$me['id'];
    $db = rs_db();
    $a = (string)($in['a'] ?? '');

    if ($a === 'post') {
        if (!$me['email_ok']) rs_cme(403, 'Confirm your email first. The link is in the email we sent you.', ['needEmail' => true]);
        $game = rs_acct_game_key((string)($in['game'] ?? ''));
        if (!$game) rs_cme(400, 'Unknown game.');
        $body = trim(str_replace("\r\n", "\n", (string)($in['body'] ?? '')));
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        if ($e = rs_cm_body_error($body)) rs_cme(422, $e);
        $n = $db->prepare('SELECT COUNT(*) FROM comments WHERE account_id = ? AND created > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)');
        $n->execute([$id, 10]);
        if ((int)$n->fetchColumn() >= 5) rs_cme(429, 'That\'s a lot of comments. Take a breather and try again in a few minutes.');
        $n->execute([$id, 1440]);
        if ((int)$n->fetchColumn() >= 40) rs_cme(429, 'That\'s the most comments for today. Try again tomorrow.');
        // A new account's first few comments do the "you're a person" check.
        $all = $db->prepare('SELECT COUNT(*) FROM comments WHERE account_id = ?');
        $all->execute([$id]);
        if ((int)$all->fetchColumn() < 3 && !rs_acct_turnstile_ok(mb_substr((string)($in['turnstile'] ?? ''), 0, 2048))) rs_cme(422, 'Do the check below, then post.', ['needCheck' => true]);
        $parent = null;
        if (!empty($in['parent'])) {
            $p = $db->prepare('SELECT id, parent_id FROM comments WHERE id = ? AND game = ?');
            $p->execute([(int)$in['parent'], $game]);
            $pr = $p->fetch();
            if (!$pr) rs_cme(422, 'That comment is gone.');
            $parent = $pr['parent_id'] === null ? (int)$pr['id'] : (int)$pr['parent_id']; // replies stay one level deep
        }
        $same = $db->prepare('SELECT COUNT(*) FROM comments WHERE account_id = ? AND game = ? AND body = ? AND created > (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        $same->execute([$id, $game, $body]);
        if ((int)$same->fetchColumn()) rs_cme(422, 'You already posted that.');
        $db->prepare('INSERT INTO comments (game, account_id, parent_id, body, created) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')->execute([$game, $id, $parent, $body]);
        rs_cmo(200, ['ok' => true, 'comments' => rs_cm_list($game, $me), 'count' => rs_cm_count($game)]);
    }

    $cid = (int)($in['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM comments WHERE id = ?');
    $st->execute([$cid]);
    $c = $st->fetch();
    if (!$c) rs_cme(404, 'That comment is gone.');

    if ($a === 'helpful') {
        if ((int)$c['account_id'] === $id) rs_cme(422, 'That\'s your own comment.');
        if (!empty($in['on'])) {
            $ins = $db->prepare('INSERT IGNORE INTO comment_votes (comment_id, account_id) VALUES (?, ?)');
            $ins->execute([$cid, $id]);
            if ($ins->rowCount()) $db->prepare('UPDATE comments SET helpful = helpful + 1 WHERE id = ?')->execute([$cid]);
        } else {
            $del = $db->prepare('DELETE FROM comment_votes WHERE comment_id = ? AND account_id = ?');
            $del->execute([$cid, $id]);
            if ($del->rowCount()) $db->prepare('UPDATE comments SET helpful = GREATEST(helpful - 1, 0) WHERE id = ?')->execute([$cid]);
        }
        rs_cmo(200, ['ok' => true]);
    }
    if ($a === 'report') {
        $reason = in_array($in['reason'] ?? '', ['spam', 'rude', 'piracy', 'other'], true) ? (string)$in['reason'] : 'other';
        if ((int)$c['account_id'] === $id) rs_cme(422, 'That\'s your own comment.');
        $ins = $db->prepare('INSERT IGNORE INTO comment_reports (comment_id, account_id, reason, created) VALUES (?, ?, ?, UTC_TIMESTAMP())');
        $ins->execute([$cid, $id, $reason]);
        if ($ins->rowCount()) {
            $db->prepare('UPDATE comments SET reports = reports + 1 WHERE id = ?')->execute([$cid]);
            $db->prepare('UPDATE comments SET hidden = 1 WHERE id = ? AND reports >= 3')->execute([$cid]);
        }
        rs_cmo(200, ['ok' => true, 'message' => 'Thanks. We\'ll take a look.']);
    }
    if ($a === 'remove') {
        if ((int)$c['account_id'] !== $id) rs_cme(403, 'You can only remove your own comments.');
        $kids = $db->prepare('SELECT COUNT(*) FROM comments WHERE parent_id = ?');
        $kids->execute([$cid]);
        if ((int)$kids->fetchColumn()) $db->prepare('UPDATE comments SET removed = 1, body = \'\' WHERE id = ?')->execute([$cid]);
        else {
            $db->prepare('DELETE FROM comment_votes WHERE comment_id = ?')->execute([$cid]);
            $db->prepare('DELETE FROM comment_reports WHERE comment_id = ?')->execute([$cid]);
            $db->prepare('DELETE FROM comments WHERE id = ?')->execute([$cid]);
        }
        rs_cmo(200, ['ok' => true, 'comments' => rs_cm_list((string)$c['game'], $me), 'count' => rs_cm_count((string)$c['game'])]);
    }
    rs_cme(400, 'Unknown request.');
} catch (Throwable $e) {
    error_log('comments.php: ' . $e->getMessage());
    rs_cme(500, 'Something went wrong on our side. Try again in a moment.');
}
