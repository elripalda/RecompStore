<?php
// (4.8) The Submissions hub (inc/hub.php): one board per store with everything that comes in to be
// listed, a drawer with each one's checks, what they sent and the conversation, and the tabs beside it
// (players' requests, Found on GitHub, flags for the owner, handled, How to approve). Developers'
// requests, Messages and Security are their own pages under the same tabs.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_inbox_table();
rs_report_tables();
rs_submission_columns();
require_once __DIR__ . '/inc/hub.php';
rs_hub_tables();
if (!function_exists('rs_sub_fix_key')) {
    // (3.6) The key in a "fix and send it again" link for submission <id>.
    function rs_sub_fix_key(int $id): string { return substr(hash_hmac('sha256', "sub-fix|$id", rs_mail_key()), 0, 24); }
}

$me = rs_me();
$stores = $me['stores_list'];
$store = in_array($_REQUEST['store'] ?? '', $stores, true) ? (string)$_REQUEST['store'] : (in_array($_SESSION['hub_store'] ?? '', $stores, true) ? (string)$_SESSION['hub_store'] : $stores[0]);
$_SESSION['hub_store'] = $store;
$tabs = ['board', 'requests', 'found', 'flags', 'handled', 'guide'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? (string)$_GET['tab'] : 'board';
if (in_array($tab, ['requests', 'found'], true) && !rs_has_store('pc')) $tab = 'board';
$isAjax = !empty($_SERVER['HTTP_X_HUB']);
$owner = (function (): string {
    try { $r = rs_db()->query("SELECT display_name, username FROM users WHERE role = 'owner' AND active = 1 ORDER BY id LIMIT 1")->fetch(); return $r ? ((string)$r['display_name'] !== '' ? (string)$r['display_name'] : (string)$r['username']) : 'the owner'; }
    catch (Throwable $e) { return 'the owner'; }
})();
$isOwner = rs_is_owner();

const HUB_IC = [
    'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    'warn' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 7v6M12 17h.01"/></svg>',
    'x' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M7 7l10 10M17 7 7 17"/></svg>',
    'spin' => '<svg class="sh-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="12" cy="12" r="8" stroke-opacity=".25"/><path d="M12 4a8 8 0 0 1 8 8"/></svg>',
    'ver' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.5l2.2 1.6 2.7-.1.9 2.6 2.2 1.6-.8 2.6.8 2.6-2.2 1.6-.9 2.6-2.7-.1L12 21.5l-2.2-1.6-2.7.1-.9-2.6L4 15.8l.8-2.6L4 10.6l2.2-1.6.9-2.6 2.7.1z"/><path d="m8.6 12 2.3 2.3 4.5-4.6" fill="none" stroke="#06121a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'flag' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>',
    'msg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>',
    'plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    'ext' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
    'more' => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>',
];
function hub_hue(string $s): int { return array_sum(array_map('ord', str_split($s !== '' ? $s : '?'))) * 37 % 360; }
function hub_tile(string $name, string $img = '', string $cls = ''): string {
    if ($img !== '') return '<img class="sh-ico' . ($cls !== '' ? ' ' . $cls : '') . '" src="' . h($img) . '" alt="" loading="lazy">';
    $l = mb_strtoupper(mb_substr((string)preg_replace('/^(The|Project) /i', '', trim($name)) ?: '?', 0, 1));
    return '<span class="sh-ico' . ($cls !== '' ? ' ' . $cls : '') . '" style="--h:' . hub_hue($name) . '" aria-hidden="true">' . h($l) . '</span>';
}
function hub_step_icon(string $s): string { return $s === 'run' ? HUB_IC['spin'] : ($s === 'ok' ? HUB_IC['check'] : ($s === 'warn' ? HUB_IC['warn'] : ($s === 'bad' ? HUB_IC['x'] : '–'))); }
function hub_card(array $c): string {
    [$st, $note] = $c['note'];
    $dev = $c['dev'] !== '' ? $c['dev'] : $c['con'];
    return '<button type="button" class="sh-card' . ($c['flag'] ? ' flagged' : '') . '" data-item="' . h($c['key']) . '" data-q="' . h($c['q']) . '" data-v="' . ($c['verified'] ? 1 : 0) . '" data-s="' . h($st) . '" data-f="' . ($c['flag'] ? 1 : 0) . '"' . ($c['pre'] ? ' data-pre="' . (int)substr($c['key'], 4) . '"' : '') . '>'
        . '<span class="sh-card-top">' . hub_tile($c['name'], $c['icon']) . '<span class="sh-card-t"><b>' . h($c['name']) . '</b><span class="sh-by' . ($c['verified'] ? ' ver' : '') . '">'
        . ($c['verified'] ? '<span class="sh-ver" title="Verified developer">' . HUB_IC['ver'] . '</span>' : '') . h($dev) . '</span></span>'
        . ($c['flag'] ? '<span class="sh-flagged" title="Flagged for the owner">' . HUB_IC['flag'] . '</span>' : '') . '</span>'
        . '<span class="sh-note sh-' . h($st) . '">' . ($st === 'run' ? HUB_IC['spin'] : '<i></i>') . '<span>' . h($note) . '</span></span>'
        . '<span class="sh-card-foot"><span class="sh-src">' . h($c['src']) . '</span>' . ($c['dev'] !== '' && $c['con'] !== '' ? '<span class="sh-con">' . h($c['con']) . '</span>' : '')
        . ($c['notes'] ? '<span class="sh-nn" title="Team notes">' . HUB_IC['msg'] . (int)$c['notes'] . '</span>' : '') . '<span class="sh-when">' . h($c['when'] ? rs_hub_ago($c['when']) : '') . '</span></span>'
        . '</button>';
}
function hub_board_html(string $store, array $b): string {
    $o = '<div class="sh-board cols-' . count(RS_HUB_COLS[$store]) . '">';
    foreach (RS_HUB_COLS[$store] as $k => [$t, $sub]) {
        $cards = $b['cols'][$k];
        // (4.8) Drafts from the finder, requests and links that Claude and VirusTotal both passed go live
        // together; submissions stay one by one so the developer gets the "you're live" email.
        $good = $k === 'ready' && $store === 'pc' ? count(array_filter($cards, fn($c) => str_starts_with((string)$c['key'], 'job:') && $c['src'] !== 'Submission' && $c['note'][0] === 'ok')) : 0;
        $more = (int)($b['more'][$k] ?? 0);
        $o .= '<section class="sh-col sh-col-' . $k . '"><header><b>' . h($t) . '</b><span class="count">' . (count($cards) + $more) . '</span><small>' . h($sub) . '</small>'
            . ($good ? '<div class="sh-row sh-col-act"><button type="button" class="small" data-act="publish_good" data-confirm="Click again to put ' . $good . ' live" title="Drafts from the finder, player requests and links that Claude and VirusTotal both passed. Submissions publish one by one so the developer can get the email.">' . HUB_IC['check'] . 'Publish ' . $good . ' that look good</button></div>' : '')
            . '</header><div class="sh-cards">';
        foreach ($cards as $c) $o .= hub_card($c);
        if ($more) $o .= '<p class="sh-empty sh-more-line">And ' . $more . ' more in line. They start on their own, a few at a time.</p>';
        if (!$cards) $o .= '<p class="sh-empty">' . h(['new' => 'Nothing new. Submissions show up here as developers send them.', 'check' => 'Nothing being checked.', 'ready' => 'Nothing waiting to go live.', 'back' => 'Nobody\'s fixing anything.', 'draft' => 'No PS5 drafts waiting.'][$k]) . '</p>';
        $o .= '</div></section>';
    }
    return $o . '</div>';
}
$out = function (array $j) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

// ---- Answers the page asks for -------------------------------------------------------------------
if (isset($_GET['poll'])) { session_write_close(); $out(['sig' => rs_hub_sig()]); }
if (isset($_GET['board'])) {
    session_write_close();
    $b = rs_hub_board($store);
    $out(['html' => hub_board_html($store, $b), 'counts' => rs_hub_counts(), 'flags' => rs_hub_flag_count()]);
}
// A few submissions' checks, worked out now (the board asks after it's drawn).
if (isset($_GET['pre'])) {
    session_write_close();
    @set_time_limit(60);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))))), 0, 6);
    $res = [];
    foreach ($ids as $id) {
        $s = rs_hub_sub($id);
        if (!$s || !rs_hub_store_ok(rs_hub_sub_store($s[1]))) continue;
        try { $c = rs_hub_check($id, $s[1]); $res[$id] = ['note' => $c['note']]; } catch (Throwable $e) { $res[$id] = ['note' => ['warn', 'Couldn\'t check it right now']]; }
    }
    $out(['ok' => true, 'res' => $res]);
}

// ---- What the buttons do --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $item = (string)($_POST['item'] ?? '');
    $res = ['ok' => true, 'msg' => '', 'reload' => true];
    $db = rs_db();
    $needSub = function () use ($item): array {
        if (!preg_match('/^sub:(\d+)$/', $item, $m)) throw new RuntimeException('That isn\'t a submission.');
        $s = rs_hub_sub((int)$m[1]);
        if (!$s) throw new RuntimeException('That submission is gone.');
        if (!rs_hub_store_ok(rs_hub_sub_store($s[1]))) throw new RuntimeException('That submission is for a store you don\'t work on.');
        return [(int)$m[1], $s[0], $s[1]];
    };
    $needJob = function () use ($item): array {
        if (!preg_match('/^job:(\d+)$/', $item, $m)) throw new RuntimeException('That isn\'t an Auto-add draft.');
        if (!rs_hub_store_ok('pc')) throw new RuntimeException('Auto-add drafts are for the PC store.');
        $j = rs_auto_get((int)$m[1]);
        if (!$j) throw new RuntimeException('That draft is gone.');
        return $j;
    };
    // What a note, flag or message is about: [title, store, submission id].
    $about = function (string $it): array {
        if (preg_match('/^sub:(\d+)$/', $it, $m) && ($s = rs_hub_sub((int)$m[1]))) {
            if (!rs_hub_store_ok(rs_hub_sub_store($s[1]))) throw new RuntimeException('That submission is for a store you don\'t work on.');
            return [(string)($s[1]['name'] ?? 'Submission'), rs_hub_sub_store($s[1]), (int)$m[1]];
        }
        if (preg_match('/^job:(\d+)$/', $it, $m) && ($j = rs_auto_get((int)$m[1]))) {
            if (!rs_hub_store_ok('pc')) throw new RuntimeException('Auto-add drafts are for the PC store.');
            $a = $j['app_id'] !== '' ? rs_pc_app($j['app_id']) : null;
            return [(string)($a['name'] ?? rs_hub_job_path((string)$j['repo_key'])), 'pc', (int)$j['sub_id']];
        }
        if ($it === 'general') return ['Something else', '', 0];
        throw new RuntimeException('That isn\'t on the hub anymore.');
    };
    $mailOut = function (string $to, string $subject, string $body, int $subId, string $logNote = '') {
        rs_mail_send($to, $subject, $body . "\n\n" . rs_mail_get('mail_sign'), ['save' => true]);
        rs_mail_log('reply', $to, $subject, $body . $logNote, '', $subId);
    };
    try {
        switch ($act) {
            case 'approve': {
                [$id, $r, $d] = $needSub();
                if ($r['status'] === 'done') throw new RuntimeException('That one was already handled.');
                if (($d['platform'] ?? '') === 'pc') {
                    if ((string)($d['repo'] ?? '') === '') throw new RuntimeException('It has no repo to check.');
                    $jid = rs_auto_start(rs_host_web(rs_host_norm((string)($d['host'] ?? '')), (string)$d['repo']), rs_me_name(), true, $id);
                    $db->prepare("UPDATE inbox SET status = 'read' WHERE id = ? AND status = 'new'")->execute([$id]);
                    rs_log('hub', 'approve', (string)($d['name'] ?? $id));
                    $res['msg'] = 'Approved. It\'s on Checking now: the download, virus scans, art and its page take a few minutes.';
                    $res['open'] = 'job:' . $jid;
                } else {
                    $gid = rs_hub_ps5_apply($id, $r, $d);
                    if (!$isOwner) rs_hub_flag_add('sub:' . $id, (string)$d['name'], 'ps5', 'Approved and added to PS5 games as a hidden draft (' . $gid . ')' . (str_starts_with($gid, 'SUB') ? ' with a placeholder title ID' : '') . '. Ready for you to check, sign and publish.');
                    $res['msg'] = 'Added to PS5 games as a hidden draft (' . $gid . '). It goes out with the next PS5 store update' . ($isOwner ? '.' : ', and ' . $owner . ' got a flag to publish it.');
                    $res['close'] = true;
                }
                break;
            }
            case 'changes':
            case 'decline': {
                [$id, $r, $d] = $needSub();
                $to = rs_inbox_email($r, null);
                $send = $to !== '' && rs_mail_ready() && ($act === 'changes' || !empty($_POST['send']));
                $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
                $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
                $why = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 600);
                if ($why === '') throw new RuntimeException($act === 'changes' ? 'Say what they need to change (the short line at the top).' : 'Say why it\'s declined (the short line at the top).');
                if ($send && ($subject === '' || mb_strlen($body) < 10)) throw new RuntimeException('Write the message first.');
                $fix = $act === 'changes';
                if ($send) {
                    $text = $body;
                    if ($fix) $text .= "\n\n" . 'Fix it here (everything you sent is filled in, and sending it updates the same submission):' . "\n" . 'https://recomplauncher.com/submit?fix=' . $id . '&t=' . rs_sub_fix_key($id);
                    $mailOut($to, $subject, $text, $id, $fix ? "\n\n[Fix link included]" : '');
                }
                $d['declined'] = ['reason' => $why, 'at' => gmdate('c'), 'fix' => $fix, 'by' => rs_me_name()];
                if (!$fix) { $d['turned_down'] = $d['declined']; unset($d['declined']); }
                rs_hub_sub_save($id, $d, 'done');
                rs_log('submission-declined', (string)$id, ($fix ? 'changes: ' : '') . $why);
                $res['msg'] = ($fix ? 'Sent back. It\'s on Waiting on the developer until they send their fix' : 'Declined') . ($send ? ', and the email went to ' . $to . '.' : '. No email went out' . ($to === '' ? ' (they left no email address)' : (rs_mail_ready() ? '' : ' (the mailbox isn\'t set up)')) . ', so let them know another way.');
                $res['close'] = true;
                break;
            }
            case 'done': {
                [$id, $r, $d] = $needSub();
                $why = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
                $d['handled'] = ['reason' => $why !== '' ? $why : 'Marked done', 'at' => gmdate('c'), 'by' => rs_me_name()];
                unset($d['declined']);
                rs_hub_sub_save($id, $d, 'done');
                rs_log('submission-done', (string)$id, $d['handled']['reason']);
                $res['msg'] = 'Marked done. It\'s under Handled.';
                $res['close'] = true;
                break;
            }
            case 'reopen': {
                [$id, $r, $d] = $needSub();
                if (!empty($d['declined'])) $d['was_declined'] = $d['declined'];
                unset($d['declined'], $d['turned_down'], $d['handled']);
                rs_hub_sub_save($id, $d, 'new');
                $res['msg'] = 'Back on New.';
                break;
            }
            case 'stop_wait': {
                [$id, $r, $d] = $needSub();
                if (!empty($d['declined'])) { $d['turned_down'] = $d['declined']; unset($d['declined']); }
                rs_hub_sub_save($id, $d, 'done');
                $res['msg'] = 'Off the board. It\'s under Handled if they come back.';
                $res['close'] = true;
                break;
            }
            case 'move': {
                [$id, $r, $d] = $needSub();
                $d['platform'] = ($d['platform'] ?? '') === 'pc' ? 'ps5' : 'pc';
                if (!rs_hub_store_ok(rs_hub_sub_store($d))) throw new RuntimeException('You don\'t work on the ' . strtoupper(rs_hub_sub_store($d)) . ' store, so flag it for ' . $owner . ' instead.');
                rs_hub_sub_save($id, $d);
                $res['msg'] = 'Moved to the ' . ($d['platform'] === 'pc' ? 'PC' : 'PS5') . ' board.';
                $res['close'] = true;
                break;
            }
            case 'delete': {
                [$id] = $needSub();
                if (!rs_hub_can_manage()) throw new RuntimeException('Only managers and the owner can delete a submission for good. Decline it or mark it done instead.');
                $db->prepare('DELETE FROM inbox_pictures WHERE inbox_id = ?')->execute([$id]);
                $db->prepare("DELETE FROM inbox WHERE id = ? AND kind = 'submit'")->execute([$id]);
                $res['msg'] = 'Deleted.';
                $res['close'] = true;
                break;
            }
            case 'publish': {
                $j = $needJob();
                @set_time_limit(180);
                $name = rs_auto_publish((int)$j['id']);
                $res['msg'] = $name . ' is live on RecompLauncher.';
                if (!empty($j['sub_id'])) $res['open'] = 'live:' . (int)$j['sub_id'];
                else $res['close'] = true;
                break;
            }
            case 'publish_good': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Auto-add drafts are for the PC store.');
                @set_time_limit(600);
                ignore_user_abort(true);
                $names = rs_hub_publish_good();
                if (!$names) throw new RuntimeException('Nothing passed every check without needing a look.');
                $res['msg'] = count($names) . ' live on RecompLauncher: ' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : '') . '.';
                break;
            }
            case 'again': {
                $j = $needJob();
                if (in_array($j['status'], ['queued', 'running'], true) && rs_hub_ts((string)$j['updated']) > time() - RS_AUTO_STUCK) throw new RuntimeException('It\'s still running.');
                rs_auto_again((int)$j['id']);
                $res['msg'] = 'Running it again from the top.';
                break;
            }
            case 'remove': {
                $j = $needJob();
                rs_auto_remove((int)$j['id']);
                $res['msg'] = !empty($j['sub_id']) ? 'Draft removed. The submission is back on New.' : 'Draft removed (and the hidden listing it made).';
                $res['close'] = true;
                break;
            }
            case 'note': {
                [$title] = $about($item);
                rs_hub_note_add(str_starts_with($item, 'job:') && ($j = rs_auto_get((int)substr($item, 4))) && !empty($j['sub_id']) ? 'sub:' . (int)$j['sub_id'] : $item, (string)($_POST['body'] ?? ''));
                $res['msg'] = 'Note saved. Only the team sees it.';
                $res['reload'] = false;
                $res['refresh'] = true;
                break;
            }
            case 'message': {
                [$title, , $subId] = $about($item);
                if (!$subId || !($s = rs_hub_sub($subId))) throw new RuntimeException('There\'s no developer to write to on this one.');
                [$r, $d] = $s;
                $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
                if (mb_strlen($body) < 2) throw new RuntimeException('Write the message first.');
                $acc = !empty($d['account']) ? rs_dev_account((int)$d['account']) : null;
                if ($acc) {
                    rs_dev_msg_add('dm', (int)$acc['id'], true, $body);
                    $mailed = false;
                    if (!empty($_POST['email']) && rs_mail_ready() && filter_var((string)$acc['email'], FILTER_VALIDATE_EMAIL)) {
                        [$html, $text] = rs_mb_email([
                            ['t' => 'text', 'text' => "Hi {name},\n\nThere's a new message for you in your developer hub about " . $title . ":\n\n" . $body],
                            ['t' => 'button', 'label' => 'Reply in my hub', 'url' => 'https://recomplauncher.com/dev?tab=messages', 'style' => 'main'],
                        ], ['name' => $acc['name'] ?: ($acc['gh_login'] ?: 'there')], rs_mail_get('mail_sign'), "You're getting this because you have a developer account on RecompLauncher.");
                        rs_mail_send((string)$acc['email'], 'A message about ' . $title, $text, ['html' => $html, 'save' => true]);
                        $mailed = true;
                    }
                    rs_log('developers', 'message', 'to ' . rs_dev_label($acc) . ' about ' . $title);
                    $res['msg'] = 'Sent to their developer hub' . ($mailed ? ', and emailed.' : '.') . ' Their answer shows here and on Messages.';
                } else {
                    $to = rs_inbox_email($r, null);
                    if ($to === '') throw new RuntimeException('They didn\'t leave an email address and have no developer account, so there\'s no way to write to them from here.');
                    if (!rs_mail_ready()) throw new RuntimeException('The mailbox isn\'t set up yet, so no email can go out.');
                    $mailOut($to, 'About your RecompLauncher submission: ' . $title, $body, $subId);
                    $res['msg'] = 'Emailed to ' . $to . '. Their answer comes to ' . rs_mail_get('mail_from') . '.';
                }
                $res['reload'] = false;
                $res['refresh'] = true;
                break;
            }
            case 'flag': {
                [$title, $st] = $about($item);
                rs_hub_flag_add(str_starts_with($item, 'job:') && ($j = rs_auto_get((int)substr($item, 4))) && !empty($j['sub_id']) ? 'sub:' . (int)$j['sub_id'] : $item, $title, $st, (string)($_POST['body'] ?? ''));
                $res['msg'] = 'Flagged for ' . $owner . '. The answer shows on Flags and on this one.';
                $res['refresh'] = $item !== 'general';
                break;
            }
            case 'flag_answer':
            case 'flag_close':
            case 'flag_open': {
                if (!rs_hub_can_manage()) throw new RuntimeException('Only ' . $owner . ' answers flags.');
                $fid = (int)($_POST['flag'] ?? 0);
                $ans = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
                if ($act === 'flag_answer') {
                    if ($ans === '') throw new RuntimeException('Write the answer first.');
                    $db->prepare('UPDATE hub_flags SET answer = ?, answered_by = ?, answered = UTC_TIMESTAMP(), seen = 0' . (!empty($_POST['close']) ? ", status = 'closed'" : '') . ' WHERE id = ?')->execute([mb_substr($ans, 0, 4000), rs_me_name(), $fid]);
                    $res['msg'] = 'Answered' . (!empty($_POST['close']) ? ' and closed.' : '.');
                } else {
                    $db->prepare('UPDATE hub_flags SET status = ? WHERE id = ?')->execute([$act === 'flag_close' ? 'closed' : 'open', $fid]);
                    $res['msg'] = $act === 'flag_close' ? 'Closed.' : 'Open again.';
                }
                break;
            }
            case 'link': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Auto-add is for the PC store.');
                $links = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($_POST['links'] ?? '')) ?: []))));
                if (!$links) throw new RuntimeException('Paste a link first.');
                $made = []; $bad = [];
                foreach (array_slice($links, 0, 15) as $l) { try { $made[] = rs_auto_start($l, rs_me_name(), false); } catch (RuntimeException $e) { $bad[] = $l . ': ' . $e->getMessage(); } }
                rs_auto_launch_many($made);
                if (!$made) throw new RuntimeException(implode(' ', $bad));
                $res['msg'] = 'Checking ' . count($made) . ($bad ? '. ' . implode(' ', $bad) : '. It\'s on Checking.');
                break;
            }
            case 'req_add':
            case 'req_delete':
            case 'req_mark': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Requests are for the PC store.');
                $rq = rs_req_get((int)($_POST['req'] ?? 0));
                if (!$rq) throw new RuntimeException('That request isn\'t there anymore.');
                if ($act === 'req_delete') {
                    rs_req_delete((int)$rq['id']);
                    $res['msg'] = 'Deleted.';
                } elseif ($act === 'req_add') {
                    rs_auto_start((string)$rq['url'], rs_me_name() . ' · request');
                    rs_req_mark((int)$rq['id'], 'added');
                    $res['msg'] = 'Checking it now. It\'s on the Board under Checking.';
                } else {
                    $to = (string)($_POST['to'] ?? '');
                    if (!isset(RS_REQ_STATUS[$to])) throw new RuntimeException('Pick what happened to it.');
                    rs_req_mark((int)$rq['id'], $to);
                    $res['msg'] = $to === 'new' ? 'Back on the list.' : 'Marked ' . strtolower(RS_REQ_STATUS[$to]) . '.';
                }
                break;
            }
            case 'found_add':
            case 'found_skip':
            case 'found_unskip': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Found on GitHub is for the PC store.');
                $key = (string)($_POST['repo'] ?? '');
                $st = $db->prepare('SELECT repo, data FROM pc_found WHERE repo = ?');
                $st->execute([$key]);
                $f = $st->fetch();
                if (!$f) throw new RuntimeException('That find isn\'t on the list anymore.');
                if ($act === 'found_add') {
                    $fd = json_decode((string)$f['data'], true) ?: [];
                    $h = rs_host_norm((string)($fd['host'] ?? ''));
                    rs_auto_start(rs_host_web($h, $h !== '' && (string)($fd['repo'] ?? '') !== '' ? (string)$fd['repo'] : $key), rs_me_name() . ' · found');
                    $db->prepare("UPDATE pc_found SET state = 'added' WHERE repo = ?")->execute([$key]);
                    $res['msg'] = 'Checking it now. It\'s on the Board under Checking.';
                } else {
                    $db->prepare('UPDATE pc_found SET state = ? WHERE repo = ?')->execute([$act === 'found_skip' ? 'skip' : 'new', $key]);
                    $res['msg'] = $act === 'found_skip' ? 'Skipped.' : 'Back on the list.';
                }
                break;
            }
            case 'found_lookup': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Found on GitHub is for the PC store.');
                $r = rs_pc_find_one((string)($_POST['repo'] ?? ''));
                if (!$r['ok']) throw new RuntimeException($r['why']);
                $res['msg'] = $r['name'] . ' is on the list.';
                break;
            }
            case 'found_look': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Found on GitHub is for the PC store.');
                @set_time_limit(120);
                [$n, $looked] = rs_pc_find(45);
                try { [$dn, $dl] = rs_pc_find_discord(25); $n += $dn; $looked += $dl; } catch (Throwable $e) { /* GitHub's still counted */ }
                $res['msg'] = $n ? 'Found ' . $n . ' new.' : 'Nothing new' . ($looked ? ' (' . $looked . ' looked at)' : '') . '.';
                break;
            }
            case 'scout_now':
            case 'scout_toggle': {
                if (!$isOwner) throw new RuntimeException('Only ' . $owner . ' runs the scout.');
                if ($act === 'scout_now') $res['msg'] = rs_scout_launch() ? 'The scout is running. Its drafts land on Checking.' : 'The scout is already running.';
                else { $on = !rs_scout_on(); rs_kv_put('scout_on', $on ? '1' : '0'); $res['msg'] = $on ? 'The daily scout is on. It runs every day at 12 pm.' : 'The daily scout is off.'; }
                break;
            }
            case 'soon': {
                [$id, $r, $d] = $needSub();
                if ($r['status'] === 'done') throw new RuntimeException('That one was already handled.');
                @set_time_limit(180);
                $a = rs_hub_soon_add($id, $d);
                $d['handled'] = ['reason' => 'Added to Coming Soon', 'at' => gmdate('c'), 'by' => rs_me_name()];
                $d['soon_app'] = (string)$a['id'];
                unset($d['declined']);
                rs_hub_sub_save($id, $d, 'done');
                rs_log('hub', 'soon', (string)$a['name']);
                $res['msg'] = $a['name'] . ' is on Coming Soon now. When they put out a release with files, it shows on the Dashboard.';
                $res['close'] = true;
                break;
            }
            case 'vt_tell': {
                [$title, , $subId] = $about($item);
                if (!$subId || !($s = rs_hub_sub($subId))) throw new RuntimeException('There\'s no developer to write to on this one.');
                [$r, $d] = $s;
                $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
                $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
                if (mb_strlen($body) < 10) throw new RuntimeException('Write the message first.');
                $wantMail = !empty($_POST['via_email']); $wantHub = !empty($_POST['via_hub']);
                if (!$wantMail && !$wantHub) throw new RuntimeException('Tick email, their hub, or both.');
                $acc = !empty($d['account']) ? rs_dev_account((int)$d['account']) : null;
                $to = rs_inbox_email($r, null);
                $went = [];
                if ($wantHub) {
                    if (!$acc) throw new RuntimeException('They don\'t have a developer account, so it can only go by email.');
                    rs_dev_msg_add('dm', (int)$acc['id'], true, $body);
                    rs_log('developers', 'message', 'VirusTotal note to ' . rs_dev_label($acc) . ' about ' . $title);
                    $went[] = 'their developer hub';
                }
                if ($wantMail) {
                    if ($to === '') throw new RuntimeException('They didn\'t leave an email address' . ($went ? ', so it only went to their hub.' : '.'));
                    if (!rs_mail_ready()) throw new RuntimeException('The mailbox isn\'t set up yet, so no email can go out' . ($went ? ' (it went to their hub).' : '.'));
                    $mailOut($to, $subject !== '' ? $subject : 'About your RecompLauncher submission: ' . $title, $body, $subId);
                    $went[] = $to;
                }
                $res['msg'] = 'Sent to ' . implode(' and ', $went) . '. Their answer shows here.';
                $res['reload'] = false;
                $res['refresh'] = true;
                break;
            }
            case 'live_send': {
                [$id, $r, $d] = $needSub();
                $to = rs_inbox_email($r, null);
                if ($to === '') throw new RuntimeException('They didn\'t leave an email address.');
                if (!rs_mail_ready()) throw new RuntimeException('The mailbox isn\'t set up yet, so no email can go out.');
                $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
                $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
                if ($subject === '' || mb_strlen($body) < 10) throw new RuntimeException('Write the message first.');
                $mailOut($to, $subject, $body, $id);
                $d['live_mail'] = gmdate('c');
                rs_hub_sub_save($id, $d);
                $res['msg'] = 'Sent to ' . $to . '.';
                $res['close'] = true;
                break;
            }
            default:
                throw new RuntimeException('That button doesn\'t do anything here.');
        }
    } catch (Throwable $e) {
        $res = ['ok' => false, 'msg' => $e->getMessage()];
    }
    if ($isAjax) $out($res);
    rs_flash($res['msg'] !== '' ? $res['msg'] : 'Done.', $res['ok'] ? 'ok' : 'err');
    header('Location: hub.php?store=' . rawurlencode($store) . ($tab !== 'board' ? '&tab=' . $tab : ''));
    exit;
}

// ---- The drawer -----------------------------------------------------------------------------------
if (isset($_GET['item'])) {
    session_write_close();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    require __DIR__ . '/inc/hub-drawer.php';
    exit;
}

// ---- The page -------------------------------------------------------------------------------------
// (4.7) A PC submission whose project is already live on RecompLauncher is done: closed on sight.
if ($tab === 'board') { try { if ($closed = rs_sub_close_live()) rs_flash($closed . ' ' . ($closed === 1 ? 'submission was' : 'submissions were') . ' already live on RecompLauncher, so ' . ($closed === 1 ? 'it\'s' : 'they\'re') . ' marked done.'); } catch (Throwable $e) { /* next time */ } }
$counts = rs_hub_counts();
$flagN = rs_hub_flag_count();
$csrf = rs_csrf();
rs_head('Submissions', true, ['assets/hub.css']);
?>
<div class="sh-head">
  <div><h1>Submissions</h1><p class="muted"><?= h(['board' => 'New recomps and apps, from first look to live.', 'requests' => 'What players asked for from RecompLauncher\'s Coming Soon page, most asked first.', 'found' => 'Projects the finder and the daily scout came across on GitHub, GitLab and Discord.', 'flags' => 'Questions for ' . $owner . ', and the answers.', 'handled' => 'Everything that left the board: live, declined, sent back or marked done.', 'guide' => 'How a submission goes from New to live, and when to approve, ask for changes, decline or flag.'][$tab]) ?></p></div>
  <?php if (count($stores) > 1 && in_array($tab, ['board', 'handled'], true)): ?>
  <div class="sh-store" role="tablist" aria-label="Store"><?php foreach ($stores as $s): ?><a role="tab" href="hub.php?store=<?= $s ?><?= $tab !== 'board' ? '&amp;tab=' . $tab : '' ?>"<?= $s === $store ? ' class="on" aria-selected="true"' : '' ?>><?= RS_STORES[$s] ?><?= $counts[$s] ? ' <span class="count">' . $counts[$s] . '</span>' : '' ?></a><?php endforeach; ?></div>
  <?php elseif (in_array($tab, ['board', 'handled'], true)): ?><span class="sh-store-one"><?= RS_STORES[$store] ?> store</span><?php endif; ?>
</div>
<div class="sh" data-store="<?= h($store) ?>" data-tab="<?= h($tab) ?>" data-csrf="<?= h($csrf) ?>">
<?php if ($tab === 'board'): $b = rs_hub_board($store); ?>
  <div class="sh-tools">
    <div class="sh-search"><input type="search" placeholder="Find a recomp, developer or repo" aria-label="Find on the board" data-find></div>
    <div class="sh-filt" role="group" aria-label="Show"><button type="button" class="on" data-filt="all">Everything</button><button type="button" data-filt="ver"><?= HUB_IC['ver'] ?>Verified developers</button><button type="button" data-filt="look">Needs a look</button><button type="button" data-filt="flag">Flagged</button></div>
    <?php if ($store === 'pc'): ?><button type="button" class="sh-add" data-open-add><?= HUB_IC['plus'] ?>Check a link</button><?php endif; ?>
  </div>
  <?php if ($store === 'pc'): ?><form class="sh-addbox card" data-addbox hidden>
    <label>Links to check <span class="muted small">GitHub, GitLab or SourceForge, one per line. Each one is checked like a submission and lands on Checking.</span>
      <textarea name="links" rows="2" class="mono" spellcheck="false" placeholder="https://github.com/owner/name" required></textarea></label>
    <div class="sh-row"><button class="primary">Check them</button><button type="button" data-close-add>Cancel</button></div>
  </form><?php endif; ?>
  <div data-board><?= hub_board_html($store, $b) ?></div>
<?php elseif ($tab === 'requests'):
    $handled = !empty($_GET['handled']);
    $reqs = rs_req_list($handled);
    $newN = rs_req_new_count(); ?>
  <nav class="sh-sub"><a href="hub.php?tab=requests"<?= !$handled ? ' class="on"' : '' ?>>New<?= $newN ? ' <span class="count">' . $newN . '</span>' : '' ?></a><a href="hub.php?tab=requests&amp;handled=1"<?= $handled ? ' class="on"' : '' ?>>Handled</a></nav>
  <?php if (!$reqs): ?><section class="card sh-none"><p class="muted"><?= $handled ? 'Nothing handled yet.' : 'No new requests. They show up here as players send them from Coming Soon in RecompLauncher.' ?></p></section>
  <?php else: ?>
  <div class="sh-rows">
    <?php foreach ($reqs as $r): $label = preg_replace('#^https?://(www\.)?#', '', (string)$r['url']); $last = rs_hub_ts((string)$r['updated']); ?>
    <article class="sh-rowc">
      <div class="sh-asks"><b><?= (int)$r['asks'] ?></b><span><?= (int)$r['asks'] === 1 ? 'ask' : 'asks' ?></span></div>
      <div class="sh-rowc-main">
        <a class="sh-link" href="<?= h((string)$r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($label) ?> <?= HUB_IC['ext'] ?></a>
        <?php if ($r['notes']): ?><?php foreach (array_slice($r['notes'], 0, 3) as $n): ?><p class="small">“<?= h($n['note']) ?>” <span class="muted">· <?= h($n['who']) ?></span></p><?php endforeach; ?><?php else: ?><p class="small muted">No notes</p><?php endif; ?>
        <span class="muted small"><?= $handled ? h(RS_REQ_STATUS[$r['status']] ?? $r['status']) . ($r['handled_by'] ? ' by ' . h((string)$r['handled_by']) : '') : 'Last asked ' . h(rs_hub_ago_long($last)) . ', first ' . h(rs_date('M j', rs_hub_ts((string)$r['created']))) ?><?= $r['signed'] ? ' · ' . (int)$r['signed'] . ' signed in' : '' ?></span>
        <details class="sh-who"><summary>Who asked</summary><ul><?php foreach ($r['people'] as $p): ?><li><?= $p['signed'] ? '<b>' . h($p['who']) . '</b>' : h($p['who']) ?> <span class="muted">· <?= h($p['version'] !== '' ? $p['version'] : 'version not sent') ?> · <?= h(rs_date('M j, g:i a', rs_hub_ts($p['at']))) ?></span></li><?php endforeach; ?><?php if ((int)$r['asks'] > count($r['people'])): ?><li class="muted">and <?= (int)$r['asks'] - count($r['people']) ?> more</li><?php endif; ?></ul></details>
      </div>
      <div class="sh-rowc-acts">
        <?php if ($handled): ?><button data-act="req_mark" data-req="<?= (int)$r['id'] ?>" data-to="new">Back to New</button><button class="danger" data-act="req_delete" data-req="<?= (int)$r['id'] ?>" data-confirm="Click again to delete">Delete</button>
        <?php else: ?><button class="primary" data-act="req_add" data-req="<?= (int)$r['id'] ?>">Check it</button><button data-act="req_mark" data-req="<?= (int)$r['id'] ?>" data-to="listed">Already listed</button><button data-act="req_mark" data-req="<?= (int)$r['id'] ?>" data-to="done">Mark done</button><button class="danger" data-act="req_mark" data-req="<?= (int)$r['id'] ?>" data-to="declined" data-confirm="Click again to decline">Decline</button><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php elseif ($tab === 'found'):
    $found = rs_pc_found('new');
    $skipped = rs_pc_found('skip');
    $lastFind = rs_kv_get('pc_find_run');
    $scoutRun = rs_scout_running(); $scoutState = rs_scout_state(); $scoutLast = rs_scout_last(); $scoutOn = rs_scout_on(); ?>
  <div class="sh-found-top">
    <section class="card sh-fbox">
      <h3>Look up a project</h3>
      <form class="sh-row" data-form="found_lookup"><input name="repo" class="mono" placeholder="github.com/owner/repo or gitlab.com/group/project" required><button>Look up</button></form>
      <p class="muted small">Or <button type="button" class="linkish" data-act="found_look">look for new ones now</button>. The finder looks every morning<?= $lastFind ? ', last ' . h(rs_date('M j, g:i a', (int)$lastFind['v'])) : '' ?>.</p>
    </section>
    <section class="card sh-fbox">
      <h3>Daily scout <span class="pill<?= $scoutOn ? ' ok' : '' ?>"><?= $scoutOn ? 'On · every day at 12 pm' : 'Off' ?></span></h3>
      <p class="muted small" role="status"><?php if ($scoutRun): ?><?= HUB_IC['spin'] ?> <?= h((string)($scoutState['phase'] ?? 'Working')) ?><?php elseif ($scoutLast): ?>Last run <?= h(rs_date('M j, g:i a', (int)$scoutLast['at'])) ?>: looked at <?= (int)$scoutLast['looked'] ?>, picked <?= (int)$scoutLast['picked'] ?>. Its picks go straight to Checking.<?php else: ?>Looks on GitHub and the Discord suggestions every day, picks the real recomps and checks them. Its picks go straight to Checking.<?php endif; ?></p>
      <?php if ($isOwner): ?><div class="sh-row"><button data-act="scout_now"<?= $scoutRun ? ' disabled' : '' ?>>Run now</button><button data-act="scout_toggle"><?= $scoutOn ? 'Turn off' : 'Turn on' ?></button></div><?php endif; ?>
    </section>
  </div>
  <?php if (!$found): ?><section class="card sh-none"><p class="muted">Nothing waiting.</p></section>
  <?php else: ?>
  <div class="sh-rows">
    <?php foreach ($found as $f): $fn = rs_auto_find_notes($f); ?>
    <article class="sh-rowc">
      <?= !empty($f['avatar']) ? '<img class="sh-ava" src="' . h($f['avatar']) . '" alt="" loading="lazy">' : hub_tile((string)$f['repo'], '', 'sh-ava') ?>
      <div class="sh-rowc-main">
        <a class="sh-link" href="<?= h(rs_host_web($f['host'], $f['repo'])) ?>" target="_blank" rel="noopener noreferrer"><?= h($f['repo']) ?> <?= HUB_IC['ext'] ?></a>
        <span class="muted small"><?= h(RS_PC_ORIGINS[(string)($f['origin'] ?? '')] ?? 'Console not known') ?> · <?= $f['version'] ? 'Release ' . h((string)$f['version']) . ' (' . h(implode(', ', array_keys(array_filter((array)$f['assets'])))) . ')' : 'No release' ?> · <?= (int)$f['stars'] ?> stars<?= $f['host'] === 'gitlab' ? ' · GitLab' : '' ?></span>
        <?php if ($fn): ?><span class="sh-fnotes"><?php foreach ($fn as [$k, $t]): ?><em class="<?= h($k) ?>"><?= h($t) ?></em><?php endforeach; ?></span><?php endif; ?>
      </div>
      <div class="sh-rowc-acts"><button class="primary" data-act="found_add" data-repo="<?= h($f['key']) ?>">Check it</button><button data-act="found_skip" data-repo="<?= h($f['key']) ?>">Skip</button></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($skipped): ?>
  <details class="card sh-skipped"><summary>Skipped (<?= count($skipped) ?>)</summary>
    <ul><?php foreach (array_slice($skipped, 0, 200) as $f): ?><li><a href="<?= h(rs_host_web($f['host'], $f['repo'])) ?>" target="_blank" rel="noopener noreferrer" class="mono small"><?= h($f['key']) ?></a><button class="small" data-act="found_unskip" data-repo="<?= h($f['key']) ?>">Put back</button></li><?php endforeach; ?></ul>
  </details>
  <?php endif; ?>
<?php elseif ($tab === 'flags'):
    $open = rs_hub_flags('open');
    $closed = rs_hub_flags('closed', 40);
    if (!rs_hub_can_manage()) rs_db()->prepare('UPDATE hub_flags SET seen = 1 WHERE uid = ?')->execute([(int)$me['id']]);
    $flagCard = function (array $f) use ($isOwner): string {
        $mine = (int)$f['uid'] === (int)(rs_me()['id'] ?? 0);
        $o = '<article class="card sh-flagc' . ($f['status'] === 'open' ? '' : ' closed') . '"><header>' . HUB_IC['flag'] . '<div><b>' . h((string)$f['title']) . '</b><span class="muted small">' . h((string)$f['who']) . ' · ' . h(rs_hub_ago_long(rs_hub_ts((string)$f['created']))) . ($f['store'] !== '' ? ' · ' . strtoupper(h((string)$f['store'])) : '') . '</span></div>'
            . ($f['item'] !== 'general' ? '<button type="button" class="small" data-open-item="' . h((string)$f['item']) . '" data-store="' . h((string)$f['store']) . '">Open it</button>' : '') . '</header>'
            . '<p>' . nl2br(h((string)$f['note'])) . '</p>';
        if ($f['answer'] !== null && $f['answer'] !== '') $o .= '<div class="sh-ans"><b>' . h((string)$f['answered_by']) . '</b> · <span class="muted small">' . h(rs_hub_ago_long(rs_hub_ts((string)$f['answered']))) . '</span><p>' . nl2br(h((string)$f['answer'])) . '</p></div>';
        if (rs_hub_can_manage()) {
            if ($f['status'] === 'open') $o .= '<form class="sh-flag-ans" data-form="flag_answer"><input type="hidden" name="flag" value="' . (int)$f['id'] . '"><textarea name="body" rows="2" placeholder="Answer ' . h((string)$f['who']) . '"></textarea><div class="sh-row"><button class="primary" name="close" value="1">Answer and close</button><button>Answer</button><button type="button" data-act="flag_close" data-flag="' . (int)$f['id'] . '">Close</button></div></form>';
            else $o .= '<div class="sh-row"><button type="button" class="small" data-act="flag_open" data-flag="' . (int)$f['id'] . '">Open again</button></div>';
        }
        return $o . '</article>';
    }; ?>
  <section class="card sh-flagnew">
    <h3><?= HUB_IC['flag'] ?>Ask <?= h($owner) ?> about something else</h3>
    <form data-form="flag" class="sh-row"><input type="hidden" name="item" value="general"><input name="body" placeholder="A question that isn't about one submission" required><button class="primary">Flag it</button></form>
  </section>
  <h2 class="sh-h2">Open <span class="count"><?= count($open) ?></span></h2>
  <?php if (!$open): ?><p class="muted">Nothing open.</p><?php else: ?><div class="sh-flaglist"><?php foreach ($open as $f) echo $flagCard($f); ?></div><?php endif; ?>
  <?php if ($closed): ?><h2 class="sh-h2">Closed</h2><div class="sh-flaglist"><?php foreach ($closed as $f) echo $flagCard($f); ?></div><?php endif; ?>
<?php elseif ($tab === 'handled'):
    $rows = rs_db()->query("SELECT id, created, data FROM inbox WHERE kind = 'submit' AND status = 'done' ORDER BY id DESC LIMIT 400")->fetchAll();
    $list = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true);
        if (!is_array($d) || rs_hub_sub_store($d) !== $store) continue;
        $o = !empty($d['declined']) ? ['wait', 'Sent back: ' . (string)$d['declined']['reason']] : (!empty($d['turned_down']) ? ['bad', 'Declined: ' . (string)$d['turned_down']['reason']]
            : (!empty($d['ps5_draft']) ? ['ok', 'Added to PS5 games (' . (string)$d['ps5_draft'] . ')'] : (!empty($d['handled']) ? ['wait', (string)$d['handled']['reason']] : ['ok', 'Live, or added'])));
        $list[] = [$r, $d, $o];
        if (count($list) >= 150) break;
    } ?>
  <div class="sh-tools"><div class="sh-search"><input type="search" placeholder="Find a recomp or developer" aria-label="Find" data-find-rows></div></div>
  <?php if (!$list): ?><section class="card sh-none"><p class="muted">Nothing handled yet.</p></section><?php else: ?>
  <div class="sh-hlist">
    <?php foreach ($list as [$r, $d, $o]): ?>
    <button type="button" class="sh-hrow" data-item="sub:<?= (int)$r['id'] ?>" data-q="<?= h(mb_strtolower(($d['name'] ?? '') . ' ' . ($d['author'] ?? '') . ' ' . ($d['repo'] ?? ''))) ?>">
      <?= hub_tile((string)($d['name'] ?? '?')) ?><span class="sh-hrow-t"><b><?= h((string)($d['name'] ?? 'Untitled')) ?></b><span class="sh-by<?= !empty($d['verified']) ? ' ver' : '' ?>"><?= !empty($d['verified']) ? '<span class="sh-ver">' . HUB_IC['ver'] . '</span>' : '' ?><?= h((string)($d['author'] ?? '')) ?></span></span>
      <span class="sh-note sh-<?= $o[0] ?>"><i></i><span><?= h(mb_strimwidth($o[1], 0, 90, '…')) ?></span></span>
      <span class="sh-when"><?= h(rs_date('M j', rs_hub_ts((string)$r['created']))) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php else: require __DIR__ . '/inc/hub-guide.php'; endif; ?>
</div>
<div class="sh-scrim" data-scrim hidden></div>
<aside class="sh-drawer" data-drawer hidden aria-label="Details"><div data-drawer-body></div></aside>
<div class="sh-toast" data-toast role="status" hidden></div>
<script src="assets/hub.js?v=<?= (int)@filemtime(__DIR__ . '/assets/hub.js') ?>"></script>
<?php rs_foot();
