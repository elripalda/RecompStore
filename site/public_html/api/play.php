<?php
// RecompStore website (3.1): player reports and version scores, for PC recomps and PS5 apps.
//   GET  a=list&kind=pc|ps5&id=<version>[&sort=helpful|new][&sys=]  -> {summary, items[], me}
//   GET  a=scores                                                    -> {pc: {id: {...}}, ps5: {...}}
//   GET  a=versions&title=<title id>                                 -> {versions[]: score, badges, fork}
//   POST a=post   (JSON) kind id verdict sys version progress fps res problems{} gpu cpu ram fix note show_name ts website
//   POST a=vote   (JSON) id           a=flag (JSON) id reason       a=delete (JSON) kind id
//   (3.9) a=post also takes specs, and for a PC recomp it can be multipart (the same fields, problems as
//   JSON) with a log file: text, 2 MB, seen only by its developer. PC: Has problems / Won't run need a
//   note of 15 characters or more.
// Posts come from the website (its own address, with the X-RS header).
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/catcache.php';

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function rs_pl_out(int $code, array $body, int $cache = 0): void {
    http_response_code($code);
    // (4.6) The answers that are the same for everyone (scores, versions) are kept by Cloudflare too.
    header('Cache-Control: ' . ($cache > 0 ? 'public, max-age=' . $cache . ', s-maxage=' . max($cache, 300) . ', stale-while-revalidate=3600, stale-if-error=86400' : 'no-store'));
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// A version that exists and is shown: [kind, id, name] or null.
function rs_pl_version(string $kind, string $id): ?array {
    if ($kind === 'pc') {
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $id)) return null;
        // (4.1) From the published catalog (opcache's copy), not every recomp decoded from the database.
        foreach ((array)(rs_json_cached(RS_PC_CATALOG)['apps'] ?? []) as $a) if (($a['id'] ?? '') === $id) return $a;
        return null;
    }
    if ($kind === 'ps5') {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) return null;
        $g = rs_game($id);
        return $g && empty($g['hidden']) ? $g : null;
    }
    return null;
}

function rs_pl_clean(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

try {
    rs_acct_tables();
    rs_play_tables();
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $multi = $post && str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
    $in = $post ? ($multi ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?: [])) : $_GET;
    if ($multi && isset($in['problems']) && is_string($in['problems'])) $in['problems'] = json_decode($in['problems'], true) ?: [];
    $s = fn(string $k, int $max): string => isset($in[$k]) && is_scalar($in[$k]) ? mb_substr(trim((string)$in[$k]), 0, $max) : '';
    $a = $s('a', 10);
    $me = rs_acct_on() ? rs_acct_current() : null;

    if (!$post) {
        if ($a === 'scores') rs_pl_out(200, ['ok' => true] + rs_play_scores(), 120);
        if ($a === 'list') {
            $kind = $s('kind', 4); $id = $s('id', 64);
            if (!rs_pl_version($kind, $id)) rs_pl_out(404, ['ok' => false, 'error' => 'That isn\'t in the store.']);
            $sys = $s('sys', 12);
            $items = rs_play_list($kind, $id, $me, $s('sort', 8) === 'new' ? 'new' : 'helpful', preg_match('/^[a-z0-9]{0,12}$/', $sys) ? $sys : '');
            $sc = rs_play_scores()[$kind][$id] ?? null;
            rs_pl_out(200, ['ok' => true, 'summary' => $sc, 'items' => $items,
                'me' => $me ? ['user' => (string)$me['username'], 'avatar' => rs_acct_avatar_url($me)] : null,
                'turnstile' => !$me && rs_acct_turnstile_ready() ? rs_acct_turnstile_site() : '']);
        }
        if ($a === 'versions') {
            $t = rs_title($s('title', 64));
            if (!$t) rs_pl_out(404, ['ok' => false, 'error' => 'No such game.']);
            $scores = rs_play_scores();
            $pcAll = [];
            foreach ((array)(rs_json_cached(RS_PC_CATALOG)['apps'] ?? []) as $x) $pcAll[$x['id']] = $x;
            $repos = [];
            foreach ($t['pc'] as $id) if (isset($pcAll[$id])) $repos[strtolower((string)$pcAll[$id]['repo'])] = $id;
            $rows = []; $out = [];
            foreach ($t['pc'] as $id) {
                if (!isset($pcAll[$id])) continue;
                $x = $pcAll[$id]; $sc = $scores['pc'][$id] ?? ['score' => 0, 'n' => 0, 'sys' => []];
                $parent = rs_play_fork_of((string)$x['repo']);
                $rows[$id] = ['score' => $sc['score'], 'n' => $sc['n'], 'downloads' => (int)($x['downloads'] ?? 0),
                    'released' => (int)strtotime((string)($x['first'] ?: $x['released'])), 'updated' => (int)strtotime((string)($x['released'] ?? ''))];
                $out[$id] = ['kind' => 'pc', 'id' => $id, 'score' => $sc['n'] ? $sc['score'] : null, 'n' => $sc['n'], 'sys' => (object)($sc['sys'] ?? []),
                    'problems' => (object)($sc['problems'] ?? []), 'trend' => $sc['trend'] ?? 0,
                    'fork' => $parent !== '' ? ['repo' => $parent, 'of' => $repos[strtolower($parent)] ?? null] : null];
            }
            foreach ($t['ps5'] as $id) {
                $sc = $scores['ps5'][$id] ?? ['score' => 0, 'n' => 0];
                $out['ps5:' . $id] = ['kind' => 'ps5', 'id' => $id, 'score' => $sc['n'] ? $sc['score'] : null, 'n' => $sc['n'], 'sys' => (object)[], 'problems' => (object)($sc['problems'] ?? []), 'trend' => $sc['trend'] ?? 0, 'fork' => null];
            }
            foreach (rs_play_badges($rows) as $id => $b) $out[$id]['badges'] = $b;
            foreach ($out as &$o) $o['badges'] ??= [];
            unset($o);
            rs_pl_out(200, ['ok' => true, 'title' => $t['id'], 'versions' => array_values($out)], 300);
        }
        rs_pl_out(400, ['ok' => false, 'error' => 'Unknown request.']);
    }

    // ---- Posting: only from the website itself ----
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
    $ok = ['recompstore.com', 'recomplauncher.com', rs_acct_host(), preg_replace('/recompstore/', 'recomplauncher', rs_acct_host())];
    if ($origin === '' || !in_array($host, $ok, true) || ($_SERVER['HTTP_X_RS'] ?? '') !== '1') rs_pl_out(403, ['ok' => false, 'error' => 'Send it from the website.']);
    if ($origin !== '') { header('Access-Control-Allow-Origin: ' . $origin); header('Access-Control-Allow-Credentials: true'); header('Vary: Origin'); }
    $ip = rs_ip_hash();
    $myWho = rs_play_who($me);

    if ($a === 'post') {
        if ($s('website', 100) !== '') rs_pl_out(200, ['ok' => true]); // the hidden field: a bot
        $kind = $s('kind', 4); $id = $s('id', 64);
        if (!rs_pl_version($kind, $id)) rs_pl_out(404, ['ok' => false, 'error' => 'That isn\'t in the store.']);
        $verdict = $s('verdict', 8);
        if (!isset(RS_PLAY_VERDICTS[$verdict])) rs_pl_out(422, ['ok' => false, 'error' => 'Pick how it plays.', 'field' => 'verdict']);
        $sys = $s('sys', 12);
        if ($kind === 'pc' ? !isset(RS_PLAY_PC_SYS[$sys]) : !isset(RS_PS5_FW[$sys])) rs_pl_out(422, ['ok' => false, 'error' => $kind === 'pc' ? 'Pick what you play it on.' : 'Pick your PS5\'s firmware.', 'field' => 'sys']);
        $pick = function (string $k, array $allowed) use ($s): string { $v = $s($k, 8); return isset($allowed[$v]) ? $v : ''; };
        $problems = [];
        foreach ((is_array($in['problems'] ?? null) ? $in['problems'] : []) as $k => $v) if (isset(RS_PLAY_PROBLEMS[$k])) $problems[$k] = max(1, min(3, (int)$v));
        $r = [
            'verdict' => $verdict, 'sys' => $sys,
            'version' => preg_replace('/[^\w .+()-]/u', '', $s('version', 40)) ?? '',
            'progress' => $pick('progress', RS_PLAY_PROGRESS), 'fps' => $pick('fps', RS_PLAY_FPS), 'res' => $pick('res', RS_PLAY_RES),
            'problems' => $problems,
            'gpu' => rs_pl_clean($s('gpu', 80), 80), 'cpu' => rs_pl_clean($s('cpu', 80), 80), 'ram' => max(0, min(1024, (int)$s('ram', 5))),
            'fix' => rs_pl_clean($s('fix', 600), 600), 'note' => rs_pl_clean($s('note', 1200), 1200),
            'show_name' => !isset($in['show_name']) || !empty($in['show_name']),
        ];
        // (3.9) A bad PC report says what happened, so its developer can do something with it.
        if ($kind === 'pc' && $verdict !== 'runs' && mb_strlen($r['note']) < 15) rs_pl_out(422, ['ok' => false, 'error' => 'Tell the developer what happened and when.', 'field' => 'note']);
        if ($verdict !== 'runs' && !$problems && $r['note'] === '') rs_pl_out(422, ['ok' => false, 'error' => 'Tick what went wrong, or say in a few words.', 'field' => 'problems']);
        $r['specs'] = $kind === 'pc' ? rs_play_specs_clean(isset($in['specs']) && is_string($in['specs']) ? $in['specs'] : '') : '';
        $log = $kind === 'pc' && !empty($_FILES['log']) && (int)($_FILES['log']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['log'] : null;
        if ($log && ($e = rs_play_log_error($log)) !== null) rs_pl_out(422, ['ok' => false, 'error' => $e, 'field' => 'log']);
        foreach (['fix', 'note'] as $k) if ($r[$k] !== '' && ($e = rs_cm_body_error($r[$k] . ' ..'))) rs_pl_out(422, ['ok' => false, 'error' => $e, 'field' => $k]);
        if (rs_acct_try_count('play', $ip, 60) >= 15) rs_pl_out(429, ['ok' => false, 'error' => 'That\'s a lot at once. Try again later.']);
        // Guests pass the bot check (when it's set up).
        if (!$me && rs_acct_turnstile_ready() && !rs_acct_turnstile_ok($s('ts', 2048))) rs_pl_out(422, ['ok' => false, 'error' => 'The check didn\'t go through. Try it again.', 'field' => 'ts']);
        rs_acct_try_add('play', $ip);
        $rid = rs_play_save($kind, $id, $me, $r);
        if ($log && $rid) {
            try { rs_play_log_save($rid, $log); } catch (RuntimeException $e) { rs_pl_out(200, ['ok' => true, 'id' => $rid, 'warning' => 'Your report is up, but the log couldn\'t be kept.']); }
        }
        if ($kind === 'pc' && $verdict !== 'runs') rs_play_dev_ping($id);
        rs_pl_out(200, ['ok' => true, 'id' => $rid]);
    }
    if ($a === 'vote') {
        $rid = (int)$s('id', 12);
        if (rs_acct_try_count('playvote', $ip, 60) >= 60) rs_pl_out(429, ['ok' => false, 'error' => 'Slow down a little.']);
        rs_acct_try_add('playvote', $ip);
        $db = rs_db();
        $st = $db->prepare('SELECT who FROM play_reports WHERE id = ? AND hidden = 0');
        $st->execute([$rid]);
        $owner = $st->fetchColumn();
        if ($owner === false) rs_pl_out(404, ['ok' => false, 'error' => 'That report is gone.']);
        if (hash_equals((string)$owner, $myWho)) rs_pl_out(422, ['ok' => false, 'error' => 'That one\'s yours.']);
        $del = $db->prepare('DELETE FROM play_votes WHERE report_id = ? AND who = ?');
        $del->execute([$rid, $myWho]);
        $on = $del->rowCount() === 0;
        if ($on) $db->prepare('INSERT IGNORE INTO play_votes (report_id, who) VALUES (?, ?)')->execute([$rid, $myWho]);
        $db->prepare('UPDATE play_reports SET helpful = (SELECT COUNT(*) FROM play_votes WHERE report_id = ?) WHERE id = ?')->execute([$rid, $rid]);
        $n = $db->prepare('SELECT helpful FROM play_reports WHERE id = ?');
        $n->execute([$rid]);
        rs_play_scores_forget();
        rs_pl_out(200, ['ok' => true, 'voted' => $on, 'helpful' => (int)$n->fetchColumn()]);
    }
    if ($a === 'flag') {
        $rid = (int)$s('id', 12); $why = $s('reason', 8);
        if (!isset(RS_PLAY_FLAGS[$why])) rs_pl_out(422, ['ok' => false, 'error' => 'Pick a reason.']);
        if (rs_acct_try_count('playflag', $ip, 60) >= 20) rs_pl_out(429, ['ok' => false, 'error' => 'Slow down a little.']);
        rs_acct_try_add('playflag', $ip);
        $db = rs_db();
        $db->prepare('INSERT IGNORE INTO play_flags (report_id, who, reason, created) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([$rid, $myWho, $why]);
        $db->prepare('UPDATE play_reports SET flags = (SELECT COUNT(*) FROM play_flags WHERE report_id = ?) WHERE id = ?')->execute([$rid, $rid]);
        // Three flags put it aside until the admin looks (Accounts > Moderation).
        $db->prepare('UPDATE play_reports SET hidden = 1 WHERE id = ? AND flags >= 3 AND hidden = 0')->execute([$rid]);
        rs_play_scores_forget();
        rs_pl_out(200, ['ok' => true]);
    }
    if ($a === 'delete') {
        $kind = $s('kind', 4); $id = $s('id', 64);
        // (3.9) Its log and the answers on it go with it.
        $old = rs_db()->prepare('SELECT id FROM play_reports WHERE kind = ? AND app = ? AND who = ?');
        $old->execute([$kind, $id, $myWho]);
        if (($oid = (int)$old->fetchColumn()) > 0) {
            @unlink(rs_play_log_path($oid));
            rs_db()->prepare('DELETE FROM play_report_replies WHERE report_id = ?')->execute([$oid]);
        }
        $st = rs_db()->prepare('DELETE FROM play_reports WHERE kind = ? AND app = ? AND who = ?');
        $st->execute([$kind, $id, $myWho]);
        rs_play_scores_forget();
        rs_pl_out(200, ['ok' => true, 'removed' => $st->rowCount() > 0]);
    }
    rs_pl_out(400, ['ok' => false, 'error' => 'Unknown request.']);
} catch (Throwable $e) {
    error_log('play.php: ' . $e->getMessage());
    rs_pl_out(500, ['ok' => false, 'error' => 'Something went wrong here. Try again in a moment.']);
}
