<?php
// RecompStore for PC (2.8): a recomp's README, and what players say about how it runs.
//   GET  ?a=readme&id=<app>            -> {md, raw, blob, url}   (the developer's README, cached a day)
//   GET  ?a=feedback&id=<app>          -> {counts: {runs, issues, broken}, items: [...]}
//   GET  ?a=counts                     -> {counts: {app: {runs, issues, broken}}}
//   POST a=feedback id verdict sys note version   (one per player and recomp: a new one replaces theirs)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function rs_pc_out(int $code, array $body, int $cache = 0): void {
    http_response_code($code);
    header('Cache-Control: ' . ($cache > 0 ? 'public, max-age=' . $cache : 'no-store'));
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Only recomps on the PC store's published list.
function rs_pc_listed(string $id): ?array {
    if (!preg_match('/^[a-z0-9-]{1,64}$/', $id)) return null;
    $cat = json_decode((string)@file_get_contents(RS_PC_CATALOG), true);
    foreach ((array)($cat['apps'] ?? []) as $a) if (($a['id'] ?? '') === $id) return $a;
    return null;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = $post ? $_POST : $_GET;
$s = fn(string $k, int $max): string => isset($in[$k]) && is_string($in[$k]) ? mb_substr(trim($in[$k]), 0, $max) : '';
$a = $s('a', 16);
try {
    if ($a === 'counts' && !$post) rs_pc_out(200, ['ok' => true, 'counts' => (object)rs_pc_feedback_counts()], 120);
    $app = rs_pc_listed($s('id', 64));
    if ($app === null) rs_pc_out(404, ['ok' => false, 'error' => 'That recomp isn\'t on RecompLauncher.']);
    if ($a === 'readme' && !$post) {
        $r = rs_pc_readme((string)$app['repo']);
        if ($r['status'] !== 200) rs_pc_out($r['status'] === 404 ? 404 : 503, ['ok' => false, 'error' => $r['status'] === 404 ? 'No README on its GitHub.' : 'GitHub didn\'t answer. Try again in a moment.']);
        rs_pc_out(200, ['ok' => true, 'md' => $r['md'], 'raw' => $r['raw'], 'blob' => $r['blob'], 'url' => $r['url']], 600);
    }
    if ($a === 'feedback' && !$post) {
        $c = rs_pc_feedback_counts()[$app['id']] ?? [];
        $items = array_map(fn($x) => ['verdict' => $x['verdict'], 'sys' => $x['sys'], 'version' => $x['version'], 'note' => $x['note'],
            'when' => gmdate('c', strtotime((string)$x['created']))], rs_pc_feedback_list($app['id']));
        rs_pc_out(200, ['ok' => true, 'counts' => ['runs' => $c['runs'] ?? 0, 'issues' => $c['issues'] ?? 0, 'broken' => $c['broken'] ?? 0], 'items' => $items]);
    }
    if ($a === 'feedback' && $post) {
        if ($s('website', 100) !== '') rs_pc_out(200, ['ok' => true]); // the hidden field: a bot
        $verdict = $s('verdict', 8);
        if (!isset(RS_PC_VERDICTS[$verdict])) rs_pc_out(422, ['ok' => false, 'error' => 'Pick how it plays.']);
        $sys = $s('sys', 12);
        if (!isset(RS_PC_SYSTEMS[$sys])) rs_pc_out(422, ['ok' => false, 'error' => 'Pick what you play it on.']);
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s('note', 500)) ?? '';
        $version = preg_replace('/[^\w .+-]/u', '', $s('version', 40)) ?? '';
        if (rs_rate_over('pc-feedback', 12, 3600)) rs_pc_out(429, ['ok' => false, 'error' => 'That\'s a lot at once. Try again later.']);
        rs_rate_hit('pc-feedback');
        rs_pc_tables();
        rs_db()->prepare('INSERT INTO pc_feedback (app, verdict, sys, version, note, ip_hash, created) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), sys = VALUES(sys), version = VALUES(version), note = VALUES(note), created = NOW(), hidden = 0')
            ->execute([$app['id'], $verdict, $sys, $version, $note, rs_ip_hash()]);
        rs_pc_out(200, ['ok' => true]);
    }
    rs_pc_out(400, ['ok' => false, 'error' => 'Unknown request.']);
} catch (Throwable $e) {
    rs_pc_out(500, ['ok' => false, 'error' => 'Something went wrong here. Try again in a moment.']);
}
