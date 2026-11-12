<?php
// (4.6) The PR queue's door for the daily GitHub Action that drafts RecompLauncher pull requests
// (tools/prq/draft.py). Every call carries the key made on SDK support (header X-PRQ-Key).
//   GET  ?a=todo&n=8   the next picks, with what the catalog knows about each
//   POST ?a=draft      one draft (JSON): {id, pick, json, title, body, note} or {id, skip: true, skip_reason}
//   GET  ?a=todo&ids=  (4.7) Draft now: just these (the ones marked Drafting)
//   POST ?a=fail       (4.7) {id, error}: it couldn't be drafted, back to waiting with why
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/pc.php';
require_once dirname(__DIR__) . '/admin/inc/prq.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$out = function (int $code, array $j): never { http_response_code($code); echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; };

if (!rs_prq_key_ok((string)($_SERVER['HTTP_X_PRQ_KEY'] ?? ''))) $out(403, ['ok' => false, 'error' => 'Wrong or missing key.']);
$a = (string)($_GET['a'] ?? '');
try {
    if ($a === 'todo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['ids'] ?? '')))));
        $out(200, ['ok' => true, 'title' => RS_PRQ_TITLE, 'what' => RS_PRQ_WHAT, 'picks' => $ids ? rs_prq_by_ids($ids) : rs_prq_targets((int)($_GET['n'] ?? 8))]);
    }
    if ($a === 'draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string)file_get_contents('php://input', false, null, 0, 400000);
        $d = json_decode($raw, true);
        if (!is_array($d)) $out(400, ['ok' => false, 'error' => 'Send the draft as JSON.']);
        $r = rs_prq_draft_save($d);
        $out($r['ok'] ? 200 : 422, $r);
    }
    if ($a === 'fail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
        if (is_array($d)) rs_prq_fail((string)($d['id'] ?? ''), (string)($d['error'] ?? ''));
        $out(200, ['ok' => true]);
    }
    $out(400, ['ok' => false, 'error' => 'Unknown call.']);
} catch (Throwable $e) {
    rs_errlog('prq api', $e);
    $out(500, ['ok' => false, 'error' => 'Something went wrong here.']);
}
