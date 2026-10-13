<?php
// Recomp Store website: takes a bug report or a recomp submission and files it in the
// admin's Inbox. JSON in, JSON out. Nothing is emailed or shown publicly.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_reply(405, ['ok' => false, 'error' => 'Send the form from the website.']);

// Only from this site's own pages.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', $host)) {
    rs_reply(403, ['ok' => false, 'error' => 'Send the form from the website.']);
}

$raw = file_get_contents('php://input', false, null, 0, 32768);
$in = json_decode((string)$raw, true);
if (!is_array($in)) rs_reply(400, ['ok' => false, 'error' => 'The form didn\'t arrive whole. Try again.']);

$str = function (string $k, int $max) use ($in): string {
    $v = isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
};

// A field people can't see: anything in it means a bot filled the form.
if ($str('website', 200) !== '') rs_reply(200, ['ok' => true]);

$kind = $str('kind', 16);
if (!in_array($kind, ['bug', 'submit'], true)) rs_reply(400, ['ok' => false, 'error' => 'Choose a form.']);

$row = ['kind' => $kind, 'app_id' => '', 'name' => '', 'contact' => '', 'repo' => '', 'version' => '', 'body' => ''];
if ($kind === 'bug') {
    $row['app_id'] = $str('app', 64);
    $row['version'] = $str('version', 64);
    $row['contact'] = $str('contact', 200);
    $row['body'] = $str('body', 5000);
    if ($row['app_id'] === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $row['app_id'])) rs_reply(422, ['ok' => false, 'error' => 'Choose the game the bug is in.']);
    if (mb_strlen($row['body']) < 10) rs_reply(422, ['ok' => false, 'error' => 'Tell us a little more about what happened.']);
} else {
    $row['name'] = $str('name', 120);
    $row['contact'] = $str('email', 200);
    $row['repo'] = $str('repo', 255);
    $row['body'] = $str('body', 5000);
    if ($row['name'] === '') rs_reply(422, ['ok' => false, 'error' => 'Add your name or handle.']);
    if (!filter_var($row['contact'], FILTER_VALIDATE_EMAIL)) rs_reply(422, ['ok' => false, 'error' => 'Add an email address we can reach you at.']);
    if (!preg_match('#^(https://)?(www\.)?github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $row['repo'])) {
        rs_reply(422, ['ok' => false, 'error' => 'Add the GitHub repository link, like https://github.com/you/your-port.']);
    }
    if (mb_strlen($row['body']) < 10) rs_reply(422, ['ok' => false, 'error' => 'Tell us a little about the port.']);
}

try {
    rs_inbox_table();
    $db = rs_db();
    $ip = rs_ip_hash();
    // One message at a time is counted and saved (so a burst can't slip past the limits):
    // at most 5 an hour from one address, and 60 an hour from everyone together.
    $got = (int)$db->query("SELECT GET_LOCK('rs_inbox', 5)")->fetchColumn();
    if ($got !== 1) rs_reply(503, ['ok' => false, 'error' => 'Busy right now. Try again in a moment.']);
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM inbox WHERE ip_hash = ? AND created > (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$ip]);
        $mine = (int)$st->fetchColumn();
        $all = (int)$db->query('SELECT COUNT(*) FROM inbox WHERE created > (NOW() - INTERVAL 1 HOUR)')->fetchColumn();
        if ($mine >= 5 || $all >= 60) {
            $db->query("SELECT RELEASE_LOCK('rs_inbox')");
            rs_reply(429, ['ok' => false, 'error' => 'That\'s a lot of messages at once. Try again in an hour.']);
        }
        $db->prepare('INSERT INTO inbox (kind, app_id, name, contact, repo, version, body, status, ip_hash, created)
                      VALUES (?, ?, ?, ?, ?, ?, ?, \'new\', ?, NOW())')
           ->execute([$row['kind'], $row['app_id'], $row['name'], $row['contact'], $row['repo'], $row['version'], $row['body'], $ip]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('rs_inbox')");
    }
} catch (Throwable $e) {
    rs_reply(500, ['ok' => false, 'error' => 'The message couldn\'t be saved right now. Try again later.']);
}
rs_reply(200, ['ok' => true]);
