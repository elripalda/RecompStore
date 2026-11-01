<?php
// RecompStore website (3.0): player reports for PS5 apps (RecompLauncher's are in api/pc.php).
//   GET  a=counts            -> {counts: {app: {runs, issues, broken}}}
//   GET  a=list&id=<app>     -> {counts, items[]}
//   POST a=send id verdict fw version note website(left empty)   (one per person and app: a new one replaces theirs)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function rs_rp_out(int $code, array $body, int $cache = 0): void {
    http_response_code($code);
    header('Cache-Control: ' . ($cache > 0 ? 'public, max-age=' . $cache : 'no-store'));
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    rs_acct_tables();
    rs_ps5fb_tables();
    if (!rs_acct_on()) rs_rp_out(404, ['ok' => false, 'error' => 'Not open yet.']);
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $in = $post ? $_POST : $_GET;
    $s = fn(string $k, int $max): string => isset($in[$k]) && is_string($in[$k]) ? mb_substr(trim($in[$k]), 0, $max) : '';
    $a = $s('a', 10);
    if ($a === 'counts' && !$post) rs_rp_out(200, ['ok' => true, 'counts' => (object)rs_ps5fb_counts()], 120);
    $id = $s('id', 32);
    $g = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) ? rs_game($id) : null;
    if (!$g || !empty($g['hidden'])) rs_rp_out(404, ['ok' => false, 'error' => 'That app isn\'t in RecompStore.']);
    if ($a === 'list' && !$post) {
        $c = rs_ps5fb_counts()[$id] ?? [];
        $items = array_map(fn($x) => ['verdict' => $x['verdict'], 'fw' => $x['fw'], 'version' => $x['version'], 'note' => $x['note'], 'user' => $x['username'],
            'when' => gmdate('c', strtotime((string)$x['created'] . ' UTC'))], rs_ps5fb_list($id));
        rs_rp_out(200, ['ok' => true, 'counts' => ['runs' => $c['runs'] ?? 0, 'issues' => $c['issues'] ?? 0, 'broken' => $c['broken'] ?? 0], 'items' => $items]);
    }
    if ($a === 'send' && $post) {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST))) !== rs_acct_host()) rs_rp_out(403, ['ok' => false, 'error' => 'Send it from the website.']);
        if ($s('website', 100) !== '') rs_rp_out(200, ['ok' => true]); // the hidden field: a bot
        $verdict = $s('verdict', 8);
        if (!isset(RS_PS5_VERDICTS[$verdict])) rs_rp_out(422, ['ok' => false, 'error' => 'Pick how it plays.']);
        $fw = $s('fw', 6);
        if (!isset(RS_PS5_FW[$fw])) rs_rp_out(422, ['ok' => false, 'error' => 'Pick your firmware.']);
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s('note', 500)) ?? '';
        if ($note !== '' && ($e = rs_cm_body_error($note . ' ..'))) rs_rp_out(422, ['ok' => false, 'error' => $e]);
        $version = preg_replace('/[^\w .+-]/u', '', $s('version', 40)) ?? '';
        $ip = rs_ip_hash();
        if (rs_acct_try_count('ps5fb', $ip, 60) >= 12) rs_rp_out(429, ['ok' => false, 'error' => 'That\'s a lot at once. Try again later.']);
        rs_acct_try_add('ps5fb', $ip);
        $me = rs_acct_current();
        $who = $me ? hash('sha256', 'acct|' . $me['id']) : $ip;
        rs_db()->prepare('INSERT INTO ps5_feedback (app, verdict, fw, version, note, who, account_id, created) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), fw = VALUES(fw), version = VALUES(version), note = VALUES(note), account_id = VALUES(account_id), created = UTC_TIMESTAMP(), hidden = 0')
            ->execute([$id, $verdict, $fw, $version, $note, $who, $me ? (int)$me['id'] : null]);
        rs_rp_out(200, ['ok' => true]);
    }
    rs_rp_out(400, ['ok' => false, 'error' => 'Unknown request.']);
} catch (Throwable $e) {
    error_log('reports.php: ' . $e->getMessage());
    rs_rp_out(500, ['ok' => false, 'error' => 'Something went wrong here. Try again in a moment.']);
}
