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
require_once __DIR__ . '/inc/repowatch.php';
rs_hub_tables();
if (!function_exists('rs_sub_fix_key')) {
    // (3.6) The key in a "fix and send it again" link for submission <id>.
    function rs_sub_fix_key(int $id): string { return substr(hash_hmac('sha256', "sub-fix|$id", rs_mail_key()), 0, 24); }
}

$me = rs_me();
$stores = $me['stores_list'];
$store = in_array($_REQUEST['store'] ?? '', $stores, true) ? (string)$_REQUEST['store'] : (in_array($_SESSION['hub_store'] ?? '', $stores, true) ? (string)$_SESSION['hub_store'] : $stores[0]);
$_SESSION['hub_store'] = $store;
$tabs = ['board', 'watch', 'requests', 'found', 'flags', 'handled', 'dismissed', 'guide'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? (string)$_GET['tab'] : 'board';
if (in_array($tab, ['requests', 'found', 'dismissed'], true) && !rs_has_store('pc')) $tab = 'board';
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
    'chev' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
    'hid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 5.1A9.9 9.9 0 0 1 12 5c5 0 9 4.5 10 7a12.6 12.6 0 0 1-3.2 4.3M6.6 6.6C4.3 8 2.7 10.2 2 12c1 2.5 5 7 10 7a9.6 9.6 0 0 0 4.4-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>',
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
// (4.8) Developers whose recomp went live without the "you're live" email: Send (the usual words), Edit
// first (the drawer), or Skip.
function hub_live_strip(): string {
    $q = rs_hub_live_queue();
    if (!$q) return '';
    $ready = rs_mail_ready();
    $n = count($q);
    $withMail = count(array_filter($q, fn($x) => $x['to'] !== ''));
    // (4.9) Folds away (remembered per browser), and all of them can go at once.
    $o = '<details class="sh-lq sh-fold" data-keep="lq" open><summary><span class="sh-lq-ic">' . HUB_IC['msg'] . '</span><div><b>' . $n . ' ' . ($n === 1 ? 'developer hasn\'t' : 'developers haven\'t') . ' heard they\'re live</b><small>Their recomp went live from a submission. Send the usual email, or edit it first.</small></div>'
        . ($ready && $withMail > 1 ? '<button type="button" class="small primary" data-act="live_all" data-confirm="Click again to email all ' . $withMail . '">Send to all ' . $withMail . '</button>' : '')
        . '<span class="sh-fold-ic">' . HUB_IC['chev'] . '</span></summary><div class="sh-lq-list">';
    foreach (array_slice($q, 0, 6) as $x) {
        $o .= '<div class="sh-lq-row" data-item="sub:' . (int)$x['sub'] . '">' . hub_tile($x['name'], $x['icon']) . '<div class="sh-lq-t"><b>' . h($x['name']) . '</b><span class="muted">' . h($x['to'] !== '' ? $x['to'] : 'No email address left') . ' · live ' . h(rs_hub_ago_long($x['at'])) . '</span></div>'
            . '<div class="sh-rowc-acts">' . ($ready && $x['to'] !== '' ? '<button type="button" class="small primary" data-act="live_quick">Send</button>' : '') . '<button type="button" class="small" data-open-item="live:' . (int)$x['sub'] . '">' . ($ready && $x['to'] !== '' ? 'Edit first' : 'Copy the message') . '</button><button type="button" class="small" data-act="live_skip">Skip</button></div></div>';
    }
    if ($n > 6) $o .= '<p class="sh-empty">And ' . ($n - 6) . ' more after these.</p>';
    return $o . '</div></details>';
}
// (4.9) Listings the repo watch hid, at the bottom of the board: a new repo when the developer moves
// it, then back on the store.
function hub_hidden_list(): string {
    $h = rs_hub_hidden_apps();
    if (!$h) return '';
    $n = count($h);
    $o = '<details class="sh-hid sh-fold" data-keep="hid"><summary><span class="sh-hid-ic">' . HUB_IC['hid'] . '</span><div><b>' . $n . ' hidden from the store</b><small>Hidden by the repo watch. When the developer moves it, give it the new repo, then put it back.</small></div><span class="sh-fold-ic">' . HUB_IC['chev'] . '</span></summary><div class="sh-wlist">';
    foreach ($h as $x) {
        $at = $x['at'] !== '' ? (int)strtotime($x['at']) : 0;
        $why = isset(RS_WATCH_STATES[$x['why']]) ? rs_watch_label($x['why']) : '';
        $o .= '<article class="sh-wrow sh-w-hidden" data-item="w:pc:' . h($x['id']) . '">' . hub_tile($x['name'], $x['icon'])
            . '<div class="sh-wrow-main"><div class="sh-wrow-t"><b>' . h($x['name']) . '</b>' . ($why !== '' ? '<span class="sh-wpill sh-bad">' . h($why) . '</span>' : '') . '<span class="sh-wpill sh-wait">Hidden</span></div>'
            . ($x['repo'] !== '' ? '<a class="sh-link" href="' . h(rs_host_web($x['host'], $x['repo'])) . '" target="_blank" rel="noopener noreferrer">' . h($x['key']) . ' ' . HUB_IC['ext'] . '</a>' : '')
            . '<small class="muted">' . ($at ? 'Hidden ' . h(rs_date('M j, g:i a', $at)) : 'Hidden') . '</small>'
            . '<form class="sh-row sh-wmove" data-form="watch_move" hidden><input type="hidden" name="item" value="w:pc:' . h($x['id']) . '"><input name="to" class="mono" placeholder="github.com/owner/name, gitlab.com/group/project or sourceforge.net/projects/name" required><button class="primary">Point it there</button><button type="button" data-watch-move>Cancel</button></form></div>'
            . '<div class="sh-rowc-acts"><button type="button" class="small" data-watch-move>New repo</button><button type="button" class="small primary" data-act="watch_unhide" data-confirm="Click again to put it back">Put it back</button>' . (rs_can_page('pc.php') ? '<a class="btn small" href="pc.php?edit=' . h(rawurlencode($x['id'])) . '#edit">Open</a>' : '') . '</div></article>';
    }
    return $o . '</div></details>';
}
function hub_board_html(string $store, array $b): string {
    $o = ($store === 'pc' ? hub_live_strip() : '') . '<div class="sh-board cols-' . count(RS_HUB_COLS[$store]) . '">';
    foreach (RS_HUB_COLS[$store] as $k => [$t, $sub]) {
        $cards = $b['cols'][$k];
        // (4.8) Drafts from the finder, requests and links that Claude and VirusTotal both passed go live
        // together; submissions stay one by one so the developer gets the "you're live" email.
        $good = $k === 'ready' && $store === 'pc' ? count(array_filter($cards, fn($c) => str_starts_with((string)$c['key'], 'job:') && $c['src'] !== 'Submission' && $c['note'][0] === 'ok')) : 0;
        $listed = $k === 'check' && $store === 'pc' ? count(rs_hub_listed_failed()) : 0;
        $more = (int)($b['more'][$k] ?? 0);
        $o .= '<section class="sh-col sh-col-' . $k . '"><header><b>' . h($t) . '</b><span class="count">' . (count($cards) + $more) . '</span><small>' . h($sub) . '</small>'
            . ($good ? '<div class="sh-row sh-col-act"><button type="button" class="small" data-act="publish_good" data-confirm="Click again to put ' . $good . ' live" title="Drafts from the finder, player requests and links that Claude and VirusTotal both passed. Submissions publish one by one so the developer can get the email.">' . HUB_IC['check'] . 'Publish ' . $good . ' that look good</button></div>' : '')
            . ($listed ? '<div class="sh-row sh-col-act"><button type="button" class="small" data-act="dismiss_listed" data-confirm="Click again to dismiss ' . $listed . '" title="Drafts that stopped because the recomp is already on RecompLauncher (hidden). They go under Dismissed, where you can put any back.">Dismiss ' . $listed . ' already listed</button></div>' : '')
            . '</header><div class="sh-cards">';
        foreach ($cards as $c) $o .= hub_card($c);
        if ($more) $o .= '<p class="sh-empty sh-more-line">And ' . $more . ' more in line. They start on their own, a few at a time.</p>';
        if (!$cards) $o .= '<p class="sh-empty">' . h(['new' => 'Nothing new. Submissions show up here as developers send them.', 'check' => 'Nothing being checked.', 'ready' => 'Nothing waiting to go live.', 'back' => 'Nobody\'s fixing anything.', 'draft' => 'No PS5 drafts waiting.'][$k]) . '</p>';
        $o .= '</div></section>';
    }
    return $o . '</div>' . ($store === 'pc' ? hub_hidden_list() : '');
}
// (4.8) Repo watch: one listing whose repo needs a look, with what can be done about it.
function hub_watch_row(array $f, bool $tagStore = false): string {
    $web = rs_host_web($f['host'], $f['repo']);
    $since = rs_hub_ts($f['since']);
    $acts = [];
    if ($f['hidden'] && $f['hid']) $acts[] = '<button type="button" class="small primary" data-act="watch_unhide">Put it back on the store</button>';
    if ($f['state'] === 'moved' && $f['link'] !== '') $acts[] = '<button type="button" class="small primary" data-act="watch_move" data-to="' . h($f['link']) . '">Point it to ' . h(mb_strimwidth($f['link'], 0, 40, '…')) . '</button>';
    $acts[] = '<button type="button" class="small" data-watch-move>New repo</button>';
    if (!$f['hidden']) $acts[] = '<button type="button" class="small sh-w-hide" data-act="watch_hide" data-confirm="Click again to hide it">Hide from store</button>';
    $acts[] = '<button type="button" class="small" data-act="watch_check">Check again</button>';
    if (!$f['acked']) $acts[] = '<button type="button" class="small" data-act="watch_ok">It\'s fine</button>';
    return '<article class="sh-wrow sh-w-' . h($f['level']) . '" data-item="w:' . h($f['store']) . ':' . h($f['id']) . '">'
        . hub_tile($f['name'], $f['icon'])
        . '<div class="sh-wrow-main"><div class="sh-wrow-t"><b>' . h($f['name']) . '</b>'
        . ($tagStore ? '<span class="sh-src">' . h(RS_STORES[$f['store']] ?? $f['store']) . '</span>' : '')
        . '<span class="sh-wpill sh-' . h($f['level']) . '">' . h($f['label']) . '</span>' . ($f['hidden'] ? '<span class="sh-wpill sh-wait">Hidden</span>' : '') . '</div>'
        . '<a class="sh-link" href="' . h($web) . '" target="_blank" rel="noopener noreferrer">' . h(rs_repo_key($f['host'], $f['repo'])) . ' ' . HUB_IC['ext'] . '</a>'
        . '<p>' . h($f['why']) . ($f['state'] === 'dmca' && $f['link'] !== '' ? ' <a href="' . h($f['link']) . '" target="_blank" rel="noopener noreferrer">Read the notice</a>' : '') . '</p>'
        . '<small class="muted">' . ($since ? 'Since ' . h(rs_date('M j, g:i a', $since)) . ' · ' : '') . 'checked ' . h(rs_hub_ago_long(rs_hub_ts($f['checked']))) . '</small>'
        . ($f['homes'] ? '<div class="sh-whomes"><span>Possible new home' . (count($f['homes']) > 1 ? 's' : '') . ':</span>' . implode('', array_map(fn($x) => '<button type="button" class="small" data-act="watch_move" data-to="' . h($x['repo']) . '" title="' . h(($x['via'] === 'fork' ? 'A fork noted while the repo was up' : 'A copy with the same name') . ($x['pushed'] !== '' ? ', last updated ' . $x['pushed'] : '') . '. Check it\'s the same project first.') . '">' . h($x['repo']) . ($x['stars'] ? ' · ★' . (int)$x['stars'] : '') . '</button>', $f['homes'])) . '</div>' : '')
        . '<form class="sh-row sh-wmove" data-form="watch_move" hidden><input type="hidden" name="item" value="w:' . h($f['store']) . ':' . h($f['id']) . '">'
        . '<input name="to" class="mono" placeholder="' . ($f['store'] === 'pc' ? 'github.com/owner/name, gitlab.com/group/project or sourceforge.net/projects/name' : 'github.com/owner/name') . '" required>'
        . '<button class="primary">Point it there</button><button type="button" data-watch-move>Cancel</button></form></div>'
        . '<div class="sh-rowc-acts">' . implode('', $acts) . '</div></article>';
}
// The strip on top of a store's board: what needs a look, or one line saying all's well.
function hub_watch_strip(string $store): string {
    $last = rs_watch_last();
    $at = (int)($last['at'] ?? 0);
    $flags = rs_watch_flags([$store]);
    $c = rs_watch_counts([$store]);
    $ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/></svg>';
    if (!$at) return '<div class="sh-watch-ok">' . HUB_IC['spin'] . '<span><b>Repo watch</b> · The first scan is running. Anything that\'s down shows up here.</span><a href="hub.php?tab=watch">Report</a></div>';
    $when = 'checked ' . rs_hub_ago_long($at);
    if (!$flags) return '<div class="sh-watch-ok">' . HUB_IC['check'] . '<span><b>Repo watch</b> · ' . number_format($c['ok']) . ' repos up' . ($c['unk'] ? ', ' . $c['unk'] . ' couldn\'t be checked' : '') . ' · ' . h($when) . '</span><a href="hub.php?tab=watch">Report</a></div>';
    $n = count($flags);
    $o = '<details class="sh-watch sh-fold" data-keep="watch" open><summary><span class="sh-watch-ic">' . $ic . '</span><div><b>' . $n . ' ' . ($n === 1 ? 'repo needs' : 'repos need') . ' a look</b><small>Repo watch checks every listing twice a day · ' . h($when) . '</small></div>'
        . '<a class="btn small" href="hub.php?tab=watch">Full report</a><span class="sh-fold-ic">' . HUB_IC['chev'] . '</span></summary><div class="sh-wlist">';
    foreach (array_slice($flags, 0, 4) as $f) $o .= hub_watch_row($f);
    if ($n > 4) $o .= '<p class="sh-empty sh-wmore">And ' . ($n - 4) . ' more in the <a href="hub.php?tab=watch">full report</a>.</p>';
    return $o . '</div></details>';
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
                // (4.9) "Publish anyway" on something VirusTotal flagged (a false positive): managers and the owner.
                $force = ($_POST['to'] ?? '') === 'force';
                if ($force && !rs_hub_can_manage()) throw new RuntimeException('Only managers and ' . $owner . ' can publish something VirusTotal flagged. Flag it for ' . $owner . ' instead.');
                $name = rs_auto_publish((int)$j['id'], $force);
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
            // (4.9) Its page edited right in the drawer.
            case 'page_save': {
                $j = $needJob();
                @set_time_limit(150);
                [, $res['msg']] = rs_hub_page_save($j, $_POST, $_FILES);
                $res['refresh'] = true;
                break;
            }
            // (4.9) Off the board into Dismissed, and back.
            case 'dismiss':
            case 'undismiss': {
                $j = $needJob();
                rs_auto_shelve((int)$j['id'], $act === 'dismiss');
                rs_log('hub', $act, rs_hub_job_path((string)$j['repo_key']));
                if ($act === 'dismiss') { $res['msg'] = 'Dismissed. It\'s under Dismissed if you want it back.'; $res['close'] = true; }
                else { $res['msg'] = 'Back on the board.'; $res['refresh'] = true; }
                break;
            }
            case 'dismiss_listed': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Auto-add drafts are for the PC store.');
                $n = 0;
                foreach (rs_hub_listed_failed() as $jid) { try { rs_auto_shelve($jid, true); $n++; } catch (RuntimeException $e) { /* still running */ } }
                if (!$n) throw new RuntimeException('Nothing to dismiss.');
                rs_log('hub', 'dismiss', $n . ' already listed');
                $res['msg'] = $n . ' dismissed. They\'re under Dismissed if you want any back.';
                break;
            }
            // (4.9) Every waiting "you're live" email at once.
            case 'live_all': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('These are for the PC store.');
                [$sent, $none, $cant] = rs_hub_live_all();
                $left = ($none ? ' ' . $none . ' left no email address: copy the message or skip them.' : '') . ($cant ? ' ' . $cant . ' couldn\'t be written (the listing points at another repo now): open them with Edit first.' : '');
                if (!$sent) throw new RuntimeException(trim($left) !== '' ? 'Nothing went out.' . $left : 'Nobody is waiting for the email.');
                $res['msg'] = $sent . ' ' . ($sent === 1 ? 'email is' : 'emails are') . ' on the way (they go out over the next couple of minutes).' . $left;
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
            case 'live_quick':
            case 'live_skip': {
                [$id, $r, $d] = $needSub();
                if ($act === 'live_skip') {
                    $d['live_skip'] = gmdate('c');
                    rs_hub_sub_save($id, $d);
                    $res['msg'] = 'Skipped. It won\'t ask about this one again.';
                    break;
                }
                $lm = rs_hub_live_mail($id);
                if (!$lm || $lm['to'] === '') throw new RuntimeException('There\'s no email address to send it to.');
                if (!rs_mail_ready()) throw new RuntimeException('The mailbox isn\'t set up, so no email can go out.');
                $mailOut($lm['to'], $lm['subject'], $lm['body'], $id);
                $d['live_mail'] = gmdate('c');
                rs_hub_sub_save($id, $d);
                $res['msg'] = 'Sent to ' . $lm['to'] . '.';
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
            // (4.8) Bulk select: several drafts at once.
            case 'bulk': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Auto-add drafts are for the PC store.');
                $do = (string)($_POST['do'] ?? '');
                $ids = [];
                foreach (explode(',', (string)($_POST['items'] ?? '')) as $it) if (preg_match('/^job:(\d+)$/', trim($it), $m)) $ids[] = (int)$m[1];
                if (!$ids) throw new RuntimeException('Pick some drafts first.');
                @set_time_limit(600);
                ignore_user_abort(true);
                [$doneN, $skipN] = rs_hub_bulk($do, $ids);
                $verb = ['publish' => 'live on RecompLauncher', 'again' => 'running again', 'remove' => 'removed'][$do] ?? 'done';
                $res['msg'] = ($doneN ? count($doneN) . ' ' . $verb . '.' : 'Nothing changed.')
                    . ($skipN ? ' Skipped ' . count($skipN) . ': ' . implode('; ', array_map(fn($n, $w) => $n . ' (' . $w . ')', array_slice(array_keys($skipN), 0, 4), array_slice(array_values($skipN), 0, 4))) . (count($skipN) > 4 ? ' and more' : '') . '.' : '');
                $res['ok'] = (bool)$doneN || !$skipN;
                $res['bulk'] = true;
                break;
            }
            // (4.8) Repo watch
            case 'watch_hide':
            case 'watch_unhide':
            case 'watch_move':
            case 'watch_ok':
            case 'watch_check': {
                [$wStore] = rs_watch_item($item);
                if (!rs_hub_store_ok($wStore)) throw new RuntimeException('That listing is in a store you don\'t work on.');
                @set_time_limit(120);
                if ($act === 'watch_hide') $res['msg'] = rs_watch_hide($item);
                elseif ($act === 'watch_unhide') $res['msg'] = rs_watch_unhide($item);
                elseif ($act === 'watch_move') $res['msg'] = rs_watch_move($item, (string)($_POST['to'] ?? ''));
                elseif ($act === 'watch_ok') $res['msg'] = rs_watch_ack($item);
                else {
                    [, $wId, $wx] = rs_watch_item($item);
                    $row = rs_watch_check_one($wStore, $wId);
                    $res['msg'] = !$row ? $wx['name'] . ' isn\'t watched anymore.' : ($row['state'] === 'ok' ? $wx['name'] . '\'s repo is up.' : ($row['state'] === 'unk' || $row['err'] !== '' ? 'GitHub didn\'t give a clear answer. It\'s checked again with the next scan.' : $wx['name'] . ': ' . $row['why']));
                }
                // (4.9) The board is drawn again too: Hidden from the store is at its bottom.
                $res['watch'] = hub_watch_strip($store);
                break;
            }
            case 'watch_merge':
            case 'watch_dupe_ok': {
                if (!rs_hub_store_ok('pc')) throw new RuntimeException('Duplicates are checked on the PC store.');
                if (!preg_match('/^dupe:([A-Za-z0-9_.-]{1,64})\|([A-Za-z0-9_.-]{1,64})$/', $item, $m)) throw new RuntimeException('That pair isn\'t on the list anymore.');
                if ($act === 'watch_dupe_ok') $res['msg'] = rs_watch_dupe_ok($m[1], $m[2]);
                else {
                    $keep = (string)($_POST['to'] ?? '');
                    if (!in_array($keep, [$m[1], $m[2]], true)) throw new RuntimeException('Pick which one to keep.');
                    $res['msg'] = rs_watch_merge($keep, $keep === $m[1] ? $m[2] : $m[1]);
                }
                break;
            }
            case 'gone_page': {
                if (!rs_hub_can_manage()) throw new RuntimeException('Only managers and the owner can change this.');
                $on = rs_setting('pc_gone_page', '') !== '1';
                rs_set_setting('pc_gone_page', $on ? '1' : '');
                rs_watch_pc_out();
                rs_log('watch', 'gone-page', $on ? 'on' : 'off');
                $res['msg'] = $on ? 'On. Old links to removed recomps show what happened now.' : 'Off. Old links say the page isn\'t here again.';
                break;
            }
            case 'watch_scan': {
                $res['msg'] = rs_watch_launch() ? 'Scanning every repo now. It takes a few minutes; this page shows it when it\'s done.' : 'A scan is already running.';
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
rs_head('Submissions', true, ['assets/hub.css', 'assets/artpick.css']);
?>
<div class="sh-head">
  <div><h1>Submissions</h1><p class="muted"><?= h(['board' => 'New recomps and apps, from first look to live.', 'watch' => 'Every listing\'s repo, checked twice a day. Anything taken down, deleted, made private or emptied of its releases shows up here and on top of the board.', 'requests' => 'What players asked for from RecompLauncher\'s Coming Soon page, most asked first.', 'found' => 'Projects the finder and the daily scout came across on GitHub, GitLab and Discord.', 'flags' => 'Questions for ' . $owner . ', and the answers.', 'handled' => 'Everything that left the board: live, declined, sent back or marked done.', 'dismissed' => 'Drafts you took off the board with Dismiss. Put one back to finish it, or remove it for good.', 'guide' => 'How a submission goes from New to live, and when to approve, ask for changes, decline or flag.'][$tab]) ?></p></div>
  <?php if (count($stores) > 1 && in_array($tab, ['board', 'handled'], true)): ?>
  <div class="sh-store" role="tablist" aria-label="Store"><?php foreach ($stores as $s): ?><a role="tab" href="hub.php?store=<?= $s ?><?= $tab !== 'board' ? '&amp;tab=' . $tab : '' ?>"<?= $s === $store ? ' class="on" aria-selected="true"' : '' ?>><?= RS_STORES[$s] ?><?= $counts[$s] ? ' <span class="count">' . $counts[$s] . '</span>' : '' ?></a><?php endforeach; ?></div>
  <?php elseif (in_array($tab, ['board', 'handled'], true)): ?><span class="sh-store-one"><?= RS_STORES[$store] ?> store</span><?php endif; ?>
</div>
<div class="sh" data-store="<?= h($store) ?>" data-tab="<?= h($tab) ?>" data-csrf="<?= h($csrf) ?>">
<?php if ($tab === 'board'): $b = rs_hub_board($store); rs_watch_maybe(); ?>
  <div data-watch><?= hub_watch_strip($store) ?></div>
  <div class="sh-tools">
    <div class="sh-search"><input type="search" placeholder="Find a recomp, developer or repo" aria-label="Find on the board" data-find></div>
    <div class="sh-filt" role="group" aria-label="Show"><button type="button" class="on" data-filt="all">Everything</button><button type="button" data-filt="ver"><?= HUB_IC['ver'] ?>Verified developers</button><button type="button" data-filt="look">Needs a look</button><button type="button" data-filt="flag">Flagged</button></div>
    <?php if ($store === 'pc'): ?><div class="sh-tools-r"><button type="button" class="sh-selbtn" data-select-toggle><?= HUB_IC['check'] ?>Select</button><button type="button" class="sh-add" data-open-add><?= HUB_IC['plus'] ?>Check a link</button></div><?php endif; ?>
  </div>
  <?php if ($store === 'pc'): ?><form class="sh-addbox card" data-addbox hidden>
    <label>Links to check <span class="muted small">GitHub, GitLab or SourceForge, one per line. Each one is checked like a submission and lands on Checking.</span>
      <textarea name="links" rows="2" class="mono" spellcheck="false" placeholder="https://github.com/owner/name" required></textarea></label>
    <div class="sh-row"><button class="primary">Check them</button><button type="button" data-close-add>Cancel</button></div>
  </form><?php endif; ?>
  <div data-board><?= hub_board_html($store, $b) ?></div>
<?php elseif ($tab === 'watch'):
    rs_watch_maybe();
    $wStores = array_values(array_intersect($stores, ['pc', 'ps5']));
    $wl = rs_watch_last(); $wc = rs_watch_counts($wStores); $wf = rs_watch_flags($wStores, true);
    $wNeed = array_values(array_filter($wf, fn($f) => !$f['acked']));
    $wHid = array_values(array_filter($wf, fn($f) => $f['acked'] && $f['hid']));
    $wFine = array_values(array_filter($wf, fn($f) => $f['acked'] && !$f['hid']));
    $wLog = rs_watch_log($wStores, 40);
    $wRun = rs_watch_running();
    $wTag = count($wStores) > 1;
    // The next cron run: 5:23 or 17:23 UTC, whichever comes first.
    $wNext = 0;
    foreach ([0, 1] as $d) foreach (['05:23', '17:23'] as $hm) { $t = strtotime(gmdate('Y-m-d', time() + $d * 86400) . ' ' . $hm . ' UTC'); if ($t > time() && (!$wNext || $t < $wNext)) $wNext = $t; } ?>
  <div class="sh-wstats">
    <div class="card"><span>Watched</span><b><?= number_format($wc['all']) ?></b><small>PC recomps<?= $wTag ? ' and PS5 apps' : '' ?> with a repo</small></div>
    <div class="card"><span>Up</span><b class="ok"><?= number_format($wc['ok']) ?></b><small>Answered fine</small></div>
    <div class="card"><span>Need a look</span><b class="<?= $wNeed ? 'bad' : '' ?>"><?= count($wNeed) ?></b><small><?= count($wHid) + count($wFine) ? (count($wHid) + count($wFine)) . ' more hidden or marked fine' : 'Down, taken down or emptied' ?></small></div>
    <div class="card"><span>Couldn't check</span><b class="<?= $wc['unk'] ? 'warn' : '' ?>"><?= number_format($wc['unk']) ?></b><small>Asked again next scan</small></div>
  </div>
  <?php // (4.8) Removed-game pages: what an old link to a hidden recomp shows (off until turned on; preview first).
    $wGoneOn = rs_setting('pc_gone_page', '') === '1'; $wGoneEx = '';
    if (in_array('pc', $wStores, true)) foreach (rs_pc_apps() as $gx) if (!empty($gx['hidden']) && empty($gx['soon']) && ((string)($gx['watch_hid'] ?? '') !== '' || (string)($gx['replaced_by'] ?? '') !== '' || (string)($gx['live_at'] ?? '') !== '')) { $wGoneEx = (string)$gx['id']; break; } ?>
  <div class="sh-wcards<?= in_array('pc', $wStores, true) ? '' : ' one' ?>">
  <section class="card sh-wscan">
    <p><?php if ($wRun): ?><?= HUB_IC['spin'] ?> Scanning now. Reload in a few minutes to see it.<?php elseif ($wl): ?>Last scan <?= h(rs_hub_ago_long((int)$wl['at'])) ?> (<?= h(rs_date('M j, g:i a', (int)$wl['at'])) ?>), took <?= (int)$wl['took'] ?> s. <?php else: ?>No scan yet. <?php endif; ?>Next one around <?= h(rs_date('g:i a', $wNext)) ?>.<?= !empty($wl['auth']) ? ' <b class="bad">GitHub turned the site\'s token down, so most repos couldn\'t be checked. Check the token in Settings.</b>' : '' ?></p>
    <div class="sh-row"><button type="button" data-act="watch_scan"<?= $wRun ? ' disabled' : '' ?>>Scan now</button></div>
  </section>
  <?php if (in_array('pc', $wStores, true)): ?><section class="card sh-wscan sh-wgone">
    <p><b>Removed-game page<?= $wGoneOn ? ' · on' : ' · off' ?></b><br><span class="muted">When someone opens the link of a recomp that was taken off, they see what happened and others like it instead of "That page isn't here". A listing that moved sends them to its new page.</span></p>
    <div class="sh-row"><?php if ($wGoneEx !== ''): ?><a class="btn small" href="https://recomplauncher.com/game/<?= rawurlencode($wGoneEx) ?>?peek=gone" target="_blank" rel="noopener">Preview it</a><?php endif; ?><?php if (rs_hub_can_manage()): ?><button type="button" data-act="gone_page"<?= $wGoneOn ? '' : ' class="primary"' ?>><?= $wGoneOn ? 'Turn it off' : 'Turn it on' ?></button><?php endif; ?></div>
  </section><?php endif; ?>
  </div>
  <h2 class="sh-h2">Need a look <span class="count"><?= count($wNeed) ?></span></h2>
  <?php if (!$wNeed): ?><section class="card sh-none"><p class="muted"><?= $wl ? 'Nothing. Every repo answered fine on the last scan.' : 'The first scan hasn\'t finished yet.' ?></p></section>
  <?php else: ?><div class="sh-wlist"><?php foreach ($wNeed as $f) echo hub_watch_row($f, $wTag); ?></div><?php endif; ?>
  <?php if ($wHid): ?><h2 class="sh-h2">Hidden by the watch <span class="count"><?= count($wHid) ?></span></h2>
  <div class="sh-wlist"><?php foreach ($wHid as $f) echo hub_watch_row($f, $wTag); ?></div><?php endif; ?>
  <?php if ($wFine): ?><h2 class="sh-h2">Marked fine <span class="count"><?= count($wFine) ?></span></h2>
  <div class="sh-wlist"><?php foreach ($wFine as $f) echo hub_watch_row($f, $wTag); ?></div><?php endif; ?>
  <?php $wDup = in_array('pc', $wStores, true) ? rs_watch_dupes() : []; if ($wDup): ?><h2 class="sh-h2">Possible duplicates <span class="count"><?= count($wDup) ?></span></h2>
  <div class="sh-wlist"><?php foreach ($wDup as [$da, $db, $dwhy, $dk]): ?>
    <article class="sh-wdup" data-item="dupe:<?= h($da['id']) ?>|<?= h($db['id']) ?>">
      <div class="sh-wdup-pair"><?php foreach ([$da, $db] as $dx): ?><div class="sh-wdup-one"><?= hub_tile((string)$dx['name'], (string)$dx['icon']) ?><div><b><?= h((string)$dx['name']) ?></b><a class="sh-link" href="<?= h(rs_host_web(rs_pc_host($dx), (string)$dx['repo'])) ?>" target="_blank" rel="noopener noreferrer"><?= h(rs_pc_key($dx)) ?> <?= HUB_IC['ext'] ?></a><small class="muted"><?= number_format((int)$dx['downloads']) ?> downloads · ★<?= (int)$dx['stars'] ?><?= (string)$dx['version'] !== '' ? ' · ' . h((string)$dx['version']) : '' ?></small></div></div><?php endforeach; ?></div>
      <p class="muted"><?= h($dwhy) ?> Keeping one hides the other, and the launcher moves players who have it over.</p>
      <div class="sh-rowc-acts"><button type="button" class="small" data-act="watch_merge" data-to="<?= h($da['id']) ?>" data-confirm="Click again to keep it">Keep <?= h(mb_strimwidth((string)$da['name'], 0, 22, '…')) ?></button><button type="button" class="small" data-act="watch_merge" data-to="<?= h($db['id']) ?>" data-confirm="Click again to keep it">Keep <?= h(mb_strimwidth((string)$db['name'], 0, 22, '…')) ?></button><button type="button" class="small" data-act="watch_dupe_ok">Not the same</button><a class="btn small" href="pc.php?edit=<?= rawurlencode((string)$da['id']) ?>">Open in PC recomps</a></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
  <h2 class="sh-h2">What changed</h2>
  <?php if (!$wLog): ?><section class="card sh-none"><p class="muted">Nothing yet. Every time a repo goes down or comes back, it's listed here.</p></section>
  <?php else: ?><div class="sh-wlog card"><?php foreach ($wLog as $r): $lv = rs_watch_level((string)$r['state']); ?>
    <div class="sh-wlog-r"><span class="muted"><?= h(rs_date('M j, g:i a', rs_hub_ts((string)$r['at']))) ?></span><b><?= h((string)$r['name']) ?><?= $wTag ? ' <span class="sh-src">' . h(RS_STORES[$r['store']] ?? $r['store']) . '</span>' : '' ?></b>
      <span class="sh-wpill sh-<?= $lv !== '' ? h($lv) : 'ok' ?>"><?= h($lv !== '' || $r['state'] === 'fixed' ? rs_watch_label((string)$r['state']) : 'Up again') ?></span><span class="mono muted"><?= h((string)$r['repo']) ?></span></div>
  <?php endforeach; ?></div><?php endif; ?>
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
<?php elseif ($tab === 'dismissed'):
    $shelf = rs_hub_shelved(); ?>
  <?php if (!$shelf): ?><section class="card sh-none"><p class="muted">Nothing dismissed. Drafts you dismiss from the board wait here, and Put back puts them on the board again.</p></section>
  <?php else: ?>
  <div class="sh-tools"><div class="sh-search"><input type="search" placeholder="Find a recomp or repo" aria-label="Find" data-find-rows></div></div>
  <div class="sh-hlist">
    <?php foreach ($shelf as $j): $ja = (string)$j['app_id'] !== '' ? rs_pc_app((string)$j['app_id']) : null; $jn = (string)($ja['name'] ?? rs_hub_job_path((string)$j['repo_key']));
      $jWhy = trim((string)strtok(trim((string)$j['notes']), "\n")); $jWas = substr((string)$j['status'], 6) === 'review' ? 'Was ready to publish' : 'Didn\'t finish'; ?>
    <div class="sh-drow" data-item="job:<?= (int)$j['id'] ?>" data-q="<?= h(mb_strtolower($jn . ' ' . $j['repo_key'])) ?>">
      <?= hub_tile($jn, (string)($ja['icon'] ?? '')) ?><span class="sh-hrow-t"><b><?= h($jn) ?></b><span class="muted small"><?= h((string)$j['repo_key']) ?></span></span>
      <span class="sh-note sh-wait"><i></i><span><?= h($jWas . ($jWhy !== '' ? ': ' . mb_strimwidth($jWhy, 0, 90, '…') : '')) ?></span></span>
      <span class="sh-when"><?= h(rs_date('M j', rs_hub_ts((string)$j['updated']))) ?></span>
      <span class="sh-rowc-acts"><button type="button" class="small" data-open-item="job:<?= (int)$j['id'] ?>">Open</button><button type="button" class="small primary" data-act="undismiss">Put back</button></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php else: require __DIR__ . '/inc/hub-guide.php'; endif; ?>
</div>
<div class="sh-selbar" data-selbar hidden role="region" aria-label="Selected drafts">
  <b data-seln>0 selected</b>
  <button type="button" class="small linkish" data-select-col="ready">All in Ready</button>
  <span class="sh-selbar-acts"><button type="button" class="primary" data-bulk="publish" data-confirm="Click again to publish">Publish</button><button type="button" data-bulk="again">Run again</button><button type="button" class="sh-w-hide" data-bulk="remove" data-confirm="Click again to remove">Remove</button><button type="button" data-select-toggle>Done</button></span>
</div>
<div class="sh-scrim" data-scrim hidden></div>
<aside class="sh-drawer" data-drawer hidden aria-label="Details"><div data-drawer-body></div></aside>
<div class="sh-toast" data-toast role="status" hidden></div>
<div data-artpick-page hidden></div>
<script src="assets/artpick.js?v=<?= (int)@filemtime(__DIR__ . '/assets/artpick.js') ?>"></script>
<script src="assets/hub.js?v=<?= (int)@filemtime(__DIR__ . '/assets/hub.js') ?>"></script>
<?php rs_foot();
