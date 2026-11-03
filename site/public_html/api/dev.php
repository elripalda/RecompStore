<?php
// RecompStore website (2.7.2): developer accounts and the developer hub (recompstore.com/hub).
//   GET  a=login [invite]         -> off to GitHub to sign in (public profile only)
//   GET  ?code=&state=            -> back from GitHub: signed in, then /hub
//   GET  a=invite code            -> {ok, name} for an invite link's welcome
//   GET  a=me                     -> {ok, account, profile, apps, stats, changes, betas} (or {ok, account: null})
//   GET  a=logs                   -> {ok, logs}            players' logs for their apps
//   GET  a=file id                -> one log file, as text  (only for their apps)
//   GET  a=zip [app]              -> (2.7.3) every log for their apps (or one app) in one zip
//   POST a=request note           -> asks for a hub (status new -> pending)
//   POST a=profile name about links{}  -> saves their developer page (approved only)
//   POST a=change app fields{} message -> suggests changes to an app page, for the admin to approve
//   POST a=out                    -> signed out
//   (2.7.3) Email and password, for developers without GitHub (the password is only kept as a hash):
//   POST a=signup email name password [invite] -> a confirm link is emailed
//   GET  a=confirm t              -> email confirmed: signed in, then /hub
//   POST a=signin email password  -> signed in
//   POST a=forgot email           -> a link to set a new password is emailed (if there's an account)
//   POST a=reset t password       -> new password set: signed in
//   GET  a=login link=1           -> (signed in with email) adds their GitHub to the account
//   GET  a=repos                  -> {ok, repos} their public GitHub repos, for sending one in
//   GET  a=lookup repo            -> {ok, app{name, titleId, version, asset, blurb}, clash[]} read from its latest release
//   POST a=submit ... (multipart) -> the /submit form, sent in as theirs (api/submit.php)
//   GET  a=ids                    -> (2.7.3) {ok, ids[], max} the title IDs they reserved
//   POST a=id-new | a=id-reserve id | a=id-renew id | a=id-release id  -> reserving title IDs
//   POST a=edit app ... (multipart) -> (verified developers) changes their app page straight away:
//        fields, icon_file, hero_file, logo_file, shot_files[], shot_remove[]; you get an email
//   (3.0) POST a=platforms pc ps5   -> what they make: PC recomps, PS5 apps, or both
//   GET  a=claim-find q              -> apps and recomps by name, to claim one their GitHub doesn't show
//   POST a=claim app note            -> "this one's mine": theirs by GitHub goes on their page at once, else it waits for the admin
//   GET  a=msgs                      -> {ok, msgs} their messages with the RecompStore team (read when fetched)
//   POST a=msg body                  -> a message to the team
//   GET  a=lounge                    -> {ok, posts} the room every approved developer shares
//   POST a=lounge-post body room | a=lounge-delete id   (3.1: room = general, showcase, pc, ps5)
//   GET  a=docs | a=doc slug         -> (3.1) developer docs for what they make
// JSON in for POSTs (a=edit is a form, for the pictures). Changing anything is only from this site's own pages.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_dev_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = $post ? (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') ? $_POST
    : (json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true) ?: [])) : $_GET;
$a = is_string($in['a'] ?? null) ? $in['a'] : (isset($_GET['code']) || isset($_GET['error']) ? 'back' : '');
if ($post) {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) rs_dev_out(403, ['ok' => false, 'error' => 'Do this from recompstore.com.']);
}
$cookie = ['path' => '/api/dev.php', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax'];

try {
    rs_dev_tables();
    $db = rs_db();

    // ---- Signing in with GitHub -----------------------------------------------------------
    if ($a === 'login') {
        if (!rs_dev_ready()) { header('Location: /developers?gh=off'); exit; }
        $invite = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['invite'] ?? '')) ?? '';
        $state = bin2hex(random_bytes(16)) . (!empty($_GET['link']) ? 'L' : '');
        setcookie('rs_dev_state', $state . '|' . $invite, ['expires' => time() + 600] + $cookie);
        header('Location: ' . rs_dev_login_url($state));
        exit;
    }
    if ($a === 'back') {
        $saved = (string)($_COOKIE['rs_dev_state'] ?? '');
        setcookie('rs_dev_state', '', ['expires' => time() - 3600] + $cookie);
        [$state, $invite] = array_pad(explode('|', $saved, 2), 2, '');
        if ($state === '' || !hash_equals($state, (string)($_GET['state'] ?? ''))) { header('Location: /developers?gh=again'); exit; }
        if (isset($_GET['error'])) { header('Location: /developers?gh=no'); exit; }
        try {
            $u = rs_dev_finish((string)$_GET['code']);
            $me = str_ends_with($state, 'L') ? rs_dev_current() : null;
            if ($me && (string)$me['gh_login'] === '') {
                // Adding GitHub to an email account (unless that GitHub already has its own account).
                $other = $db->prepare('SELECT id FROM dev_accounts WHERE gh_id = ?');
                $other->execute([$u['id']]);
                if ($other->fetchColumn()) { header('Location: /hub?gh=taken'); exit; }
                $db->prepare('UPDATE dev_accounts SET gh_id = ?, gh_login = ?, avatar = IF(avatar = \'\', ?, avatar) WHERE id = ?')->execute([$u['id'], $u['login'], $u['avatar'], $me['id']]);
                if ($me['status'] === 'approved') {
                    $prof = rs_dev_profile($me);
                    if ($prof && empty($prof['links']['github'])) { $prof['links']['github'] = 'https://github.com/' . $u['login']; rs_dev_save($prof, $prof['slug']); }
                }
                header('Location: /hub?gh=linked');
                exit;
            }
            $acc = rs_dev_upsert($u, $invite);
        } catch (RuntimeException $e) {
            header('Location: /developers?gh=err');
            exit;
        }
        rs_dev_session_start((int)$acc['id']);
        header('Location: /hub');
        exit;
    }
    if ($a === 'invite') {
        $inv = rs_dev_invite((string)($in['code'] ?? ''));
        rs_dev_out(200, ['ok' => (bool)$inv, 'name' => $inv ? (string)$inv['name'] : '', 'email' => $inv && $inv['used_by'] === null ? (string)$inv['email'] : '', 'used' => $inv ? $inv['used_by'] !== null : false]);
    }
    // ---- Email and password ------------------------------------------------------------------
    $pwOk = function (string $pw): void {
        if (strlen($pw) < 10) rs_dev_out(200, ['ok' => false, 'error' => 'Use at least 10 characters for your password.']);
        if (strlen($pw) > 200) rs_dev_out(200, ['ok' => false, 'error' => 'That password is too long.']);
    };
    $mailOk = function (): void {
        if (!rs_mail_ready()) rs_dev_out(200, ['ok' => false, 'error' => 'Email sign-up isn\'t open yet. Sign in with GitHub instead.']);
    };
    if ($a === 'signup' && $post) {
        $mailOk();
        if (rs_rate_over('devsignup', 5, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'Too many tries. Try again in an hour.']);
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
        $pw = (string)($in['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) rs_dev_out(200, ['ok' => false, 'error' => 'That email address doesn\'t look right.']);
        if (mb_strlen($name) < 2) rs_dev_out(200, ['ok' => false, 'error' => 'Add the name you make apps under.']);
        $pwOk($pw);
        rs_rate_hit('devsignup');
        $have = rs_dev_by_email($email);
        if ($have) {
            // Same answer either way, so nobody can find out who has an account.
            if (!(int)$have['email_ok']) rs_dev_send_link($have, 'confirm');
            else rs_dev_send_link($have, 'reset');
            rs_dev_out(200, ['ok' => true, 'sent' => $email]);
        }
        $db->prepare("INSERT INTO dev_accounts (gh_id, gh_login, name, email, avatar, status, pass_hash, email_ok, created, last_seen) VALUES (NULL, '', ?, ?, '', 'new', ?, 0, NOW(), NOW())")
           ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
        $acc = rs_dev_account((int)$db->lastInsertId());
        $inv = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['invite'] ?? '')) ?? '';
        if ($inv !== '') $db->prepare('UPDATE dev_accounts SET note = ? WHERE id = ?')->execute(['invite:' . $inv, $acc['id']]);
        rs_dev_send_link($acc, 'confirm');
        rs_dev_out(200, ['ok' => true, 'sent' => $email]);
    }
    if ($a === 'confirm') {
        $acc = rs_dev_by_token('confirm', (string)($_GET['t'] ?? ''));
        if (!$acc) { header('Location: /developers?signin=1&mail=old'); exit; }
        $db->prepare('UPDATE dev_accounts SET email_ok = 1, mail_token = NULL, mail_token_until = NULL, last_seen = NOW() WHERE id = ?')->execute([$acc['id']]);
        if (preg_match('/^invite:([A-Za-z0-9]{24})$/', (string)$acc['note'], $m)) {
            $db->prepare("UPDATE dev_accounts SET note = '' WHERE id = ?")->execute([$acc['id']]);
            $acc['note'] = '';
            rs_dev_use_invite($acc, $m[1]);
        }
        rs_dev_session_start((int)$acc['id']);
        header('Location: /hub');
        exit;
    }
    if ($a === 'signin' && $post) {
        if (rs_rate_over('devpw', 8, 900)) rs_dev_out(200, ['ok' => false, 'error' => 'Too many tries. Wait 15 minutes, or set a new password.']);
        $acc = rs_dev_by_email((string)($in['email'] ?? ''));
        $pw = (string)($in['password'] ?? '');
        // Without an account, a check that takes as long, so the time doesn't tell who has one.
        $hashOk = $acc ? password_verify($pw, (string)$acc['pass_hash']) : password_verify($pw, '$2y$10$abcdefghijklmnopqrstuuJ4Vq0lVZtqvO1WkHq2rI5o6sAqyQHCu') && false;
        if (!$acc || !$hashOk) {
            rs_rate_hit('devpw');
            rs_dev_out(200, ['ok' => false, 'error' => 'That email and password don\'t match.']);
        }
        if (!(int)$acc['email_ok']) {
            if (!rs_rate_over('devmail', 4, 3600)) { rs_rate_hit('devmail'); rs_dev_send_link($acc, 'confirm'); }
            rs_dev_out(200, ['ok' => false, 'error' => 'Confirm your email first. We\'ve sent the link again.']);
        }
        if (password_needs_rehash((string)$acc['pass_hash'], PASSWORD_DEFAULT)) $db->prepare('UPDATE dev_accounts SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $acc['id']]);
        rs_dev_session_start((int)$acc['id']);
        rs_dev_out(200, ['ok' => true]);
    }
    if ($a === 'forgot' && $post) {
        $mailOk();
        if (rs_rate_over('devmail', 4, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'Too many tries. Try again in an hour.']);
        rs_rate_hit('devmail');
        $acc = rs_dev_by_email((string)($in['email'] ?? ''));
        if ($acc) rs_dev_send_link($acc, 'reset');
        rs_dev_out(200, ['ok' => true]);
    }
    if ($a === 'reset' && $post) {
        if (rs_rate_over('devpw', 8, 900)) rs_dev_out(200, ['ok' => false, 'error' => 'Too many tries. Wait 15 minutes.']);
        $acc = rs_dev_by_token('reset', (string)($in['t'] ?? ''));
        if (!$acc) { rs_rate_hit('devpw'); rs_dev_out(200, ['ok' => false, 'error' => 'That link has run out. Ask for a new one.']); }
        $pw = (string)($in['password'] ?? '');
        $pwOk($pw);
        $db->prepare('UPDATE dev_accounts SET pass_hash = ?, email_ok = 1, mail_token = NULL, mail_token_until = NULL WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $acc['id']]);
        $db->prepare('DELETE FROM dev_sessions WHERE account_id = ?')->execute([$acc['id']]); // signed out everywhere else
        rs_dev_session_start((int)$acc['id']);
        rs_dev_out(200, ['ok' => true]);
    }
    if ($a === 'out' && $post) {
        rs_dev_session_end();
        rs_dev_out(200, ['ok' => true]);
    }

    // ---- Signed in from here on -------------------------------------------------------------
    $acc = rs_dev_current();
    if (!$acc) rs_dev_out(200, ['ok' => true, 'account' => null, 'ready' => rs_dev_ready(), 'mailReady' => rs_mail_ready()]);
    $db->prepare('UPDATE dev_accounts SET last_seen = NOW() WHERE id = ?')->execute([$acc['id']]);
    $approved = $acc['status'] === 'approved';
    $apps = rs_dev_apps($acc);
    $ids = array_map(fn($g) => (string)$g['id'], $apps);
    $pcApps = $approved ? rs_dev_pc_apps($acc) : [];
    $pcIds = array_map(fn($a) => (string)$a['id'], $pcApps);
    $prof = $approved ? rs_dev_profile($acc) : null;
    $verified = $prof && !empty($prof['verified']);

    // ---- (2.7.3) Sending in a new app, filled in from their GitHub -------------------------
    if ($a === 'repos') {
        if ((string)$acc['gh_login'] === '') rs_dev_out(200, ['ok' => true, 'repos' => [], 'github' => false]);
        $k = 'dev_repos_' . strtolower((string)$acc['gh_login']);
        $c = rs_kv_get($k);
        $list = $c && $c['age'] < 1800 ? json_decode($c['v'], true) : null;
        if (!is_array($list)) {
            $r = rs_http(rs_gh_api() . '/users/' . rawurlencode((string)$acc['gh_login']) . '/repos?per_page=100&sort=pushed&type=owner', ['github' => true, 'timeout' => 12]);
            $list = [];
            foreach ($r['status'] === 200 ? (array)json_decode($r['body'], true) : [] as $x) {
                if (!is_array($x) || !empty($x['fork']) || !empty($x['archived']) || !empty($x['private'])) continue;
                $list[] = ['repo' => (string)$x['full_name'], 'description' => mb_substr((string)($x['description'] ?? ''), 0, 200), 'pushed' => substr((string)($x['pushed_at'] ?? ''), 0, 10)];
            }
            if ($r['status'] === 200) rs_kv_put($k, json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $inStore = [];
        foreach (rs_games(true) as $g) if ((string)$g['repo'] !== '') $inStore[strtolower((string)$g['repo'])] = true;
        foreach ($list as &$x) $x['inStore'] = isset($inStore[strtolower($x['repo'])]);
        unset($x);
        rs_dev_out(200, ['ok' => true, 'github' => true, 'repos' => $list]);
    }
    if ($a === 'lookup') {
        $repo = trim((string)preg_replace(['#^https?://(www\.)?github\.com/#i', '#\.git$#i'], '', (string)($in['repo'] ?? '')), '/ ');
        $repo = implode('/', array_slice(explode('/', $repo), 0, 2));
        if (!rs_repo_ok($repo)) rs_dev_out(200, ['ok' => false, 'error' => 'The GitHub repo goes like github.com/you/your-app.']);
        if (rs_rate_over('devlookup', 40, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of lookups. Fill it in by hand, or try again in a bit.']);
        rs_rate_hit('devlookup');
        $info = rs_gh_repo($repo) ?? [];
        $res = rs_source_repo($repo);
        $app = ($res['ok'] ?? false) ? ($res['apps'][0] ?? null) : null;
        $out = ['repo' => $repo, 'blurb' => mb_substr((string)($info['description'] ?? ''), 0, 160)];
        if ($app) $out += ['name' => (string)$app['name'], 'titleId' => (string)$app['titleId'], 'version' => (string)$app['version'], 'asset' => (string)$app['source']['asset']];
        $clash = [];
        if (!empty($out['titleId'])) foreach (rs_tid_uses($out['titleId'])['uses'] as $u) {
            if ($u['key'] === strtolower($repo) || $u['key'] === 'reserved:' . (int)$acc['id']) continue;
            $clash[] = $u['where'] === 'submission' ? ['where' => 'Sent in', 'name' => 'Another app that was sent in'] : ['where' => rs_tid_where($u['where']), 'name' => $u['name'], 'repo' => $u['repo']];
        }
        rs_dev_out(200, ['ok' => true, 'app' => $out, 'found' => (bool)$app, 'note' => $app ? '' : (string)($res['error'] ?? ''), 'clash' => $clash]);
    }
    if ($a === 'submit' && $post) {
        define('RS_SUB_ACCOUNT', $acc + ['verified' => $verified]);
        require dirname(__DIR__) . '/api/submit.php';
        exit;
    }

    if ($a === 'me') {
        if ($approved && !is_file(RS_PUBLIC . '/developers.json')) rs_dev_write_public();
        $changes = $db->prepare('SELECT id, app_id, status, created, decided FROM dev_changes WHERE account_id = ? ORDER BY id DESC LIMIT 30');
        $changes->execute([$acc['id']]);
        $stats = $approved ? rs_dev_stats($ids) : [];
        $dc = rs_dc_conf();
        rs_dev_out(200, ['ok' => true, 'ready' => true,
            'account' => ['login' => $acc['gh_login'], 'github' => (string)$acc['gh_login'] !== '', 'password' => $acc['pass_hash'] !== null, 'ghReady' => rs_dev_ready(), 'name' => $acc['name'], 'avatar' => $acc['avatar'], 'email' => $acc['email'], 'status' => $acc['status'],
                'note' => (string)$acc['note'], 'invited' => $acc['invite_id'] !== null, 'discord' => $acc['discord_name'], 'slug' => $acc['slug'], 'weekly' => (bool)$acc['weekly'],
                'since' => gmdate('Y-m-d', (int)strtotime((string)$acc['created'] . ' UTC')),
                'platforms' => array_values(array_filter(explode(',', (string)($acc['platforms'] ?? '')))), 'unread' => rs_dev_unread($acc),
                'rooms' => $approved ? rs_dev_rooms_for($acc) : [], 'docs' => $approved ? count(rs_dev_docs_for($acc)) : 0],
            'claims' => $approved ? rs_dev_claim_state($acc, $apps, $pcApps) : null,
            'discordReady' => rs_dc_ready(), 'discordInvite' => preg_match('#^https://#', $dc['invite']) ? $dc['invite'] : '',
            'profile' => $prof ? ['slug' => $prof['slug'], 'name' => $prof['name'], 'about' => $prof['about'], 'links' => (object)$prof['links'], 'avatar' => $prof['avatar'] !== '' ? rs_abs($prof['avatar']) : '', 'verified' => (bool)$prof['verified']] : null,
            'canEdit' => $verified,
            'linkKinds' => RS_DEV_LINKS,
            'apps' => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'icon' => $g['icon'] !== '' ? rs_abs($g['icon']) : '', 'art' => ($g['hero_web'] ?: $g['hero']) !== '' ? rs_abs($g['hero_web'] ?: $g['hero']) : '', 'version' => $g['beta'] ? $g['beta_version'] : $g['version'],
                'logo' => (string)($g['logo'] ?? '') !== '' ? rs_abs($g['logo']) : '',
                'screens' => array_map(fn($s) => rs_abs($s['thumb']), rs_screens_clean($g['screens'] ?? [])),
                'videoUrl' => (string)($g['video'] ?? '') !== '' ? 'https://www.youtube.com/watch?v=' . $g['video'] : '',
                'repo' => $g['repo'], 'beta' => (bool)$g['beta'], 'soon' => (bool)$g['soon'], 'category' => $g['category'],
                
                'fields' => array_intersect_key($g, RS_DEV_CHANGE_FIELDS), 'stats' => $stats[$g['id']] ?? null], $apps),
            'pcApps' => (function () use ($pcApps, $acc): array {
                $fb = rs_pc_feedback_counts();
                $artWaiting = $pcApps ? rs_dev_pc_art_waiting((int)$acc['id']) : [];
                return array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'game' => $a['game'], 'version' => $a['version'], 'repo' => $a['repo'],
                    'cover' => $a['cover'] !== '' ? rs_abs($a['cover']) : '', 'art' => $a['hero'] !== '' ? rs_abs($a['hero']) : '',
                    'logo' => (string)($a['logo'] ?? '') !== '' ? rs_abs($a['logo']) : '', 'icon' => (string)($a['icon'] ?? '') !== '' ? rs_abs($a['icon']) : '',
                    'waiting' => array_map('rs_abs', $artWaiting[$a['id']] ?? []),
                    'fields' => array_intersect_key($a, RS_DEV_PC_FIELDS),
                    'ai' => ['v' => (string)($a['ai'] ?? ''), 'uses' => rs_ai_uses_clean($a['ai_uses'] ?? []), 'note' => (string)($a['ai_note'] ?? ''), 'by' => (string)($a['ai_by'] ?? '')], 
                    'stats' => ['downloads' => (int)$a['downloads'], 'stars' => (int)$a['stars'], 'runs' => (int)($fb[$a['id']]['runs'] ?? 0),
                        'issues' => (int)($fb[$a['id']]['issues'] ?? 0), 'broken' => (int)($fb[$a['id']]['broken'] ?? 0)]], $pcApps);
            })(),
            'changes' => $changes->fetchAll(),
            'submissions' => (function () use ($db, $acc): array {
                try {
                    rs_submission_columns();
                    $st = $db->prepare("SELECT name, repo, status, created FROM inbox WHERE kind = 'submit' AND dev_account = ? ORDER BY id DESC LIMIT 20");
                    $st->execute([$acc['id']]);
                    $live = [];
                    foreach (rs_games(true) as $g) if ((string)$g['repo'] !== '' && !$g['hidden']) $live[strtolower((string)$g['repo'])] = true;
                    return array_map(fn($r) => ['name' => $r['name'], 'repo' => preg_replace('#^https://github\.com/#', '', (string)$r['repo']), 'date' => substr((string)$r['created'], 0, 10),
                        'state' => isset($live[strtolower((string)preg_replace('#^https://github\.com/#', '', (string)$r['repo']))]) ? 'live' : ($r['status'] === 'new' ? 'waiting' : 'seen')], $st->fetchAll());
                } catch (Throwable $e) { return []; }
            })(),
        ]);
    }
    // (3.0) What they make. Any account can say, approved or not.
    if ($a === 'platforms' && $post) {
        $v = rs_dev_platforms_clean(array_keys(array_filter(['pc' => !empty($in['pc']), 'ps5' => !empty($in['ps5'])])));
        if ($v === '') rs_dev_out(200, ['ok' => false, 'error' => 'Pick PC, PS5 or both.']);
        $db->prepare('UPDATE dev_accounts SET platforms = ? WHERE id = ?')->execute([$v, $acc['id']]);
        rs_dev_out(200, ['ok' => true, 'platforms' => explode(',', $v), 'message' => 'Saved.']);
    }
    // (3.0) Messages with the RecompStore team: open to every account, so they can ask before they're in.
    if ($a === 'msgs') {
        $db->prepare("UPDATE dev_messages SET read_dev = 1 WHERE channel = 'dm' AND account_id = ? AND from_admin = 1")->execute([$acc['id']]);
        rs_dev_out(200, ['ok' => true, 'msgs' => rs_dev_dm((int)$acc['id'])]);
    }
    if ($a === 'msg' && $post) {
        if (rs_rate_over('devmsg', 30, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of messages in an hour. Try again a bit later.']);
        $mine = $db->prepare("SELECT COUNT(*) FROM dev_messages WHERE account_id = ? AND from_admin = 0 AND created > NOW() - INTERVAL 1 HOUR");
        $mine->execute([$acc['id']]);
        if ((int)$mine->fetchColumn() >= ($acc['status'] === 'approved' ? 30 : 10)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of messages in an hour. Try again a bit later.']);
        rs_rate_hit('devmsg');
        try { rs_dev_msg_add('dm', (int)$acc['id'], false, (string)($in['body'] ?? '')); } catch (RuntimeException $e) { rs_dev_out(200, ['ok' => false, 'error' => $e->getMessage()]); }
        rs_log('developers', 'message', rs_dev_label($acc) . ' sent a message');
        rs_dev_out(200, ['ok' => true, 'msgs' => rs_dev_dm((int)$acc['id'])]);
    }
    if ($a === 'request' && $post) {
        if (!empty($in['pc']) || !empty($in['ps5'])) {
            $v = rs_dev_platforms_clean(array_keys(array_filter(['pc' => !empty($in['pc']), 'ps5' => !empty($in['ps5'])])));
            $db->prepare('UPDATE dev_accounts SET platforms = ? WHERE id = ?')->execute([$v, $acc['id']]);
        }
        if (!in_array($acc['status'], ['new', 'rejected'], true)) rs_dev_out(200, ['ok' => true]);
        // (3.1g) An application: a linked GitHub, a name and an email, PS5, PC or both, what they're
        // working on, and at least one link to their work. Accounts approved before this keep going as they are.
        $bad = fn(string $m) => rs_dev_out(200, ['ok' => false, 'error' => $m]);
        if ((string)$acc['gh_login'] === '') $bad('Link your GitHub first. It\'s how we see your work.');
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? ''), 0, 80);
        if (mb_strlen($name) < 2) $bad('Add your name.');
        $email = strtolower(trim((string)($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $bad('Add an email address, so we can tell you when you\'re in.');
        $plats = ['ps5' => ['PS5', ['ps5']], 'pc' => ['PC', ['pc']], 'both' => ['PS5 and PC', ['pc', 'ps5']]];
        $plat = (string)($in['platform'] ?? '');
        if (!isset($plats[$plat])) $bad('Pick what you make apps for: PS5, PC or both.');
        $work = mb_substr(trim(str_replace("\r\n", "\n", (string)($in['work'] ?? ''))), 0, 2000);
        if (mb_strlen($work) < 40) $bad('Tell us a bit more about what you\'re working on. A few sentences is plenty.');
        preg_match_all('#https?://[^\s<>"\']+#i', (string)($in['links'] ?? ''), $m);
        $links = array_slice(array_values(array_unique($m[0])), 0, 10);
        if (!$links) $bad('Add at least one link to your work, like a repo, a release or a video.');
        $discord = mb_substr(trim(preg_replace('/[\x00-\x1F]/u', '', (string)($in['discord'] ?? '')) ?? ''), 0, 64);
        $note = 'Makes apps for: ' . $plats[$plat][0] . "\nGitHub: https://github.com/" . $acc['gh_login'] . ($discord !== '' ? "\nDiscord: " . $discord : '')
            . "\n\nWorking on:\n" . $work . "\n\nLinks:\n" . implode("\n", $links);
        $db->prepare("UPDATE dev_accounts SET status = 'pending', note = ?, email = ?, name = ?, platforms = ?, requested = NOW() WHERE id = ?")->execute([$note, $email, $name, rs_dev_platforms_clean($plats[$plat][1]), $acc['id']]);
        rs_dev_notify_admin($acc, 'applied for a developer account' . ":\n\n" . $name . ' <' . $email . ">\n" . $note);
        rs_dev_out(200, ['ok' => true]);
    }
    if (!$approved) rs_dev_out(200, ['ok' => false, 'error' => 'Your developer hub opens once you\'re approved.']);

    if ($a === 'profile' && $post) {
        if (!$prof) rs_dev_out(200, ['ok' => false, 'error' => 'Your developer page isn\'t set up yet.']);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
        if (mb_strlen($name) < 2) rs_dev_out(200, ['ok' => false, 'error' => 'Your name needs at least 2 characters.']);
        $bad = null;
        $links = rs_dev_links_clean(is_array($in['links'] ?? null) ? $in['links'] : [], $bad);
        if ($bad !== null) rs_dev_out(200, ['ok' => false, 'error' => 'The ' . $bad . ' link has to start with https://']);
        if ((string)$acc['gh_login'] !== '') $links['github'] = 'https://github.com/' . $acc['gh_login']; // always theirs
        $prof['name'] = $name;
        $prof['about'] = mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)($in['about'] ?? ''))), 0, 400);
        $prof['links'] = $links;
        rs_dev_save($prof, $prof['slug']);
        $db->prepare('UPDATE dev_accounts SET name = ? WHERE id = ?')->execute([$name, $acc['id']]);
        rs_log('developers', 'hub', rs_dev_label($acc) . ' changed their page');
        rs_dev_out(200, ['ok' => true, 'message' => 'Saved. It\'s on your page now.']);
    }
    if ($a === 'change' && $post) {
        $app = (string)($in['app'] ?? '');
        // (2.8) "pc:<id>" is one of their PC recomps.
        $pc = str_starts_with($app, 'pc:');
        if (!in_array($pc ? substr($app, 3) : $app, $pc ? $pcIds : $ids, true)) rs_dev_out(200, ['ok' => false, 'error' => 'That isn\'t one of your apps.']);
        $g = $pc ? rs_pc_app(substr($app, 3)) : rs_game($app);
        $f = is_array($in['fields'] ?? null) ? $in['fields'] : [];
        $data = [];
        foreach ($pc ? RS_DEV_PC_FIELDS : RS_DEV_CHANGE_FIELDS as $k => $max) {
            if (!array_key_exists($k, $f)) continue;
            $v = mb_substr(trim(str_replace("\r\n", "\n", (string)$f[$k])), 0, $max);
            if ($v !== trim((string)($g[$k] ?? ''))) $data[$k] = $v;
        }
        if (!$data) rs_dev_out(200, ['ok' => false, 'error' => 'Nothing changed.']);
        $open = $db->prepare("SELECT COUNT(*) FROM dev_changes WHERE account_id = ? AND status = 'pending'");
        $open->execute([$acc['id']]);
        if ((int)$open->fetchColumn() >= 10) rs_dev_out(200, ['ok' => false, 'error' => 'You have 10 changes waiting already. They\'ll be looked at soon.']);
        $db->prepare("INSERT INTO dev_changes (account_id, app_id, data, message, status, created) VALUES (?, ?, ?, ?, 'pending', NOW())")
           ->execute([$acc['id'], $app, json_encode($data, JSON_UNESCAPED_UNICODE), mb_substr(trim((string)($in['message'] ?? '')), 0, 1000)]);
        rs_dev_out(200, ['ok' => true, 'message' => 'Sent for review. You\'ll see it here when it\'s approved.']);
    }
    // (2.9) Whether AI helped make one of their apps: their own word, on its page as AI-assisted.
    if ($a === 'ai' && $post) {
        $app = (string)($in['app'] ?? '');
        $pc = str_starts_with($app, 'pc:');
        $id = $pc ? substr($app, 3) : $app;
        if (!$pc || !in_array($id, $pcIds, true)) rs_dev_out(200, ['ok' => false, 'error' => 'That isn\'t one of your recomps.']);
        $v = (string)($in['ai'] ?? '');
        if (!in_array($v, ['yes', 'no'], true)) rs_dev_out(200, ['ok' => false, 'error' => 'Pick yes or no.']);
        if (rs_rate_over('devedit', 40, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of changes in an hour. Try again a bit later.']);
        rs_rate_hit('devedit');
        $x = $pc ? rs_pc_app($id) : rs_game($id);
        $x = array_merge($x, rs_ai_from_submission(['ai' => $v, 'ai_uses' => (array)($in['uses'] ?? []), 'ai_note' => (string)($in['note'] ?? '')], $x));
        if ($pc) { rs_pc_save($x); if (empty($x['hidden'])) rs_pc_publish_one($x['id']); } else rs_save_game($x, null);
        rs_log('developers', 'ai', rs_dev_label($acc) . ' says ' . $x['id'] . ($v === 'yes' ? ' is AI-assisted (' . implode(', ', $x['ai_uses']) . ')' : ' isn\'t AI-assisted'));
        rs_dev_out(200, ['ok' => true, 'ai' => ['v' => $x['ai'], 'uses' => $x['ai_uses'], 'note' => $x['ai_note'], 'by' => $x['ai_by']],
            'message' => $pc ? 'Saved. Its page shows it now.' : 'Saved. It shows on its page with the next store update, usually within a day.']);
    }
    // (2.8) Verified developers change their PC recomps' pages straight away too. (3.8) Pictures
    // (cover, backdrop, logo, icon) wait for you to look at them; the words go live, and you're emailed
    // about each change and can undo it. The GitHub repo isn't theirs to change here.
    if ($a === 'pc-edit' && $post) {
        if (!$verified) rs_dev_out(200, ['ok' => false, 'error' => 'Only verified developers can change their pages straight away. Suggest the change instead.']);
        $id = (string)($in['app'] ?? '');
        if (!in_array($id, $pcIds, true)) rs_dev_out(200, ['ok' => false, 'error' => 'That isn\'t one of your apps.']);
        if (rs_rate_over('devedit', 40, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of changes in an hour. Try again a bit later.']);
        rs_rate_hit('devedit');
        $p = rs_pc_app($id);
        $what = []; $before = [];
        $f = is_array($in['fields'] ?? null) ? $in['fields'] : [];
        foreach (RS_DEV_PC_FIELDS as $k => $max) {
            if (!array_key_exists($k, $f)) continue;
            $v = mb_substr(trim(str_replace("\r\n", "\n", (string)$f[$k])), 0, $max);
            if ($k === 'name' && $v === '') continue; // a page always has a name
            if ($k === 'tags') $v = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 24), explode(',', $v))))), 0, 8));
            if ($v !== trim((string)($p[$k] ?? ''))) { $before[$k] = (string)($p[$k] ?? ''); $p[$k] = $v; $what[$k] = $v; }
        }
        // New pictures: made ready now, put on the page once you approve them.
        $art = [];
        try {
            foreach (RS_DEV_PC_ART as $field => $kind) {
                if (empty($_FILES[$field]['name'])) continue;
                if ((int)($_FILES[$field]['size'] ?? 0) > 15 << 20) throw new RuntimeException('Pictures can be 15 MB at most.');
                $path = rs_dev_pc_art_save($_FILES[$field], $kind, $id);
                if ($path !== '') $art[$kind] = $path;
            }
        } catch (RuntimeException $e) {
            rs_dev_pc_art_drop($art);
            rs_dev_out(200, ['ok' => false, 'error' => $e->getMessage()]);
        }
        if (!$what && !$art) rs_dev_out(200, ['ok' => false, 'error' => 'Nothing changed.']);
        if ($what) {
            if (isset($what['features'])) { $p['features_read'] = true; $p['features_v'] = 2; } // theirs now: the README doesn't write over it
            rs_pc_save($p);
            $live = rs_pc_publish_one($id);
            $db->prepare("INSERT INTO dev_changes (account_id, app_id, data, message, status, created, decided) VALUES (?, ?, ?, '', 'live', NOW(), NOW())")
               ->execute([$acc['id'], 'pc:' . $id, json_encode($what + ['_before' => $before], JSON_UNESCAPED_UNICODE)]);
            rs_log('developers', 'edit', rs_dev_label($acc) . ' changed PC ' . $id . ' (' . implode(', ', array_keys($what)) . ')');
            rs_dev_edit_notify($acc, $p, array_keys($what), true);
        }
        if ($art) {
            // One waiting set per recomp: newer pictures take the place of ones you haven't looked at yet.
            $old = $db->prepare("SELECT id, data FROM dev_changes WHERE account_id = ? AND app_id = ? AND status = 'pending'");
            $old->execute([$acc['id'], 'pc:' . $id]);
            foreach ($old->fetchAll() as $o) {
                $od = json_decode((string)$o['data'], true) ?: [];
                if (!isset($od['art'])) continue;
                $keep = array_diff_key((array)$od['art'], $art);
                rs_dev_pc_art_drop(array_intersect_key((array)$od['art'], $art));
                $art += $keep;
                $db->prepare('DELETE FROM dev_changes WHERE id = ?')->execute([$o['id']]);
            }
            $db->prepare("INSERT INTO dev_changes (account_id, app_id, data, message, status, created) VALUES (?, ?, ?, ?, 'pending', NOW())")
               ->execute([$acc['id'], 'pc:' . $id, json_encode(['art' => $art], JSON_UNESCAPED_SLASHES), mb_substr(trim((string)($in['message'] ?? '')), 0, 1000)]);
            rs_log('developers', 'art', rs_dev_label($acc) . ' sent pictures for PC ' . $id . ' (' . implode(', ', array_keys($art)) . ')');
            rs_dev_edit_notify($acc, $p, array_keys($art), true, true);
        }
        $msg = $what ? ($live ?? false ? 'Saved. It\'s on its page now.' : 'Saved. It shows on its page with RecompStore\'s next update.') : '';
        if ($art) $msg = trim($msg . ' ' . 'Your new pictures are waiting for a quick look, usually within a day.');
        rs_dev_out(200, ['ok' => true, 'message' => $msg, 'fields' => array_intersect_key($p, RS_DEV_PC_FIELDS), 'waiting' => rs_dev_pc_art_waiting((int)$acc['id'])[$id] ?? []]);
    }
    // (2.7.3) Verified developers change their own app pages straight away (you're emailed).
    if ($a === 'edit' && $post) {
        if (!$verified) rs_dev_out(200, ['ok' => false, 'error' => 'Only verified developers can change their pages straight away. Suggest the change instead.']);
        $app = (string)($in['app'] ?? '');
        if (!in_array($app, $ids, true)) rs_dev_out(200, ['ok' => false, 'error' => 'That isn\'t one of your apps.']);
        if (rs_rate_over('devedit', 40, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of changes in an hour. Try again a bit later.']);
        rs_rate_hit('devedit');
        $g = rs_game($app);
        $what = [];
        $f = is_array($in['fields'] ?? null) ? $in['fields'] : [];
        foreach (RS_DEV_CHANGE_FIELDS as $k => $max) {
            if (!array_key_exists($k, $f)) continue;
            $v = mb_substr(trim(str_replace("\r\n", "\n", (string)$f[$k])), 0, $max);
            if ($k === 'video') {
                $id = rs_youtube_id($v);
                if ($v !== '' && $id === '') rs_dev_out(200, ['ok' => false, 'error' => 'Video: paste a YouTube link, or leave it empty.']);
                $v = $id;
            }
            if ($v !== trim((string)($g[$k] ?? ''))) { $g[$k] = $v; $what[$k] = $v; }
        }
        $shots = rs_screens_clean($g['screens'] ?? []);
        $drop = array_map('intval', (array)($in['shot_remove'] ?? []));
        if ($drop) { $shots = array_values(array_filter($shots, fn($s, $i) => !in_array($i, $drop, true), ARRAY_FILTER_USE_BOTH)); $what['screens'] = 1; }
        $sf = $_FILES['shot_files'] ?? null;
        $new = $sf && is_array($sf['name'] ?? null) ? array_values(array_filter(array_keys($sf['name']), fn($i) => (string)$sf['name'][$i] !== '')) : [];
        if (count($shots) + count($new) > RS_SCREENS_MAX) rs_dev_out(200, ['ok' => false, 'error' => 'Screenshots: ' . RS_SCREENS_MAX . ' at most. Remove some first.']);
        try {
            foreach (['icon_file' => 'icon', 'hero_file' => 'hero', 'logo_file' => 'logo'] as $field => $kind) {
                if (empty($_FILES[$field]['name'])) continue;
                $g = array_merge($g, rs_save_image($_FILES[$field], $kind, $g['id']));
                $what[$kind] = 1;
            }
            foreach ($new as $i) {
                $shots[] = rs_save_screen(['name' => $sf['name'][$i], 'tmp_name' => $sf['tmp_name'][$i], 'error' => $sf['error'][$i], 'size' => $sf['size'][$i]], $g['id']);
                $what['screens'] = 1;
            }
        } catch (RuntimeException $e) {
            rs_dev_out(200, ['ok' => false, 'error' => $e->getMessage()]);
        }
        if (!$what) rs_dev_out(200, ['ok' => false, 'error' => 'Nothing changed.']);
        $g['screens'] = $shots;
        rs_save_game($g, null);
        $db->prepare("INSERT INTO dev_changes (account_id, app_id, data, message, status, created, decided) VALUES (?, ?, ?, ?, 'live', NOW(), NOW())")
           ->execute([$acc['id'], $app, json_encode($what, JSON_UNESCAPED_UNICODE), mb_substr(trim((string)($in['message'] ?? '')), 0, 1000)]);
        rs_log('developers', 'edit', rs_dev_label($acc) . ' changed ' . $app . ' (' . implode(', ', array_keys($what)) . ')');
        rs_dev_edit_notify($acc, $g, array_keys($what));
        rs_dev_out(200, ['ok' => true, 'message' => 'Saved. It shows on the store and the site with RecompStore\'s next update.',
            'icon' => $g['icon'] !== '' ? rs_abs($g['icon']) : '', 'art' => ($g['hero_web'] ?: $g['hero']) !== '' ? rs_abs($g['hero_web'] ?: $g['hero']) : '',
            'logo' => (string)($g['logo'] ?? '') !== '' ? rs_abs($g['logo']) : '', 'screens' => array_map(fn($s) => rs_abs($s['thumb']), $shots),
            'fields' => array_intersect_key($g, RS_DEV_CHANGE_FIELDS)]);
    }
    // ---- (2.7.3) Title IDs they reserve ---------------------------------------------------------
    $myIds = function () use ($acc): array {
        rs_res_mark_used();
        return array_map(fn($r) => ['id' => $r['title_id'], 'used' => $r['used_at'] !== null, 'since' => substr((string)$r['created'], 0, 10),
            'until' => $r['used_at'] !== null ? '' : substr((string)$r['expires'], 0, 10)], rs_res_active((int)$acc['id']));
    };
    if ($a === 'ids') rs_dev_out(200, ['ok' => true, 'ids' => $myIds(), 'max' => RS_RES_MAX, 'days' => RS_RES_DAYS]);
    if (in_array($a, ['id-new', 'id-reserve', 'id-renew', 'id-release'], true) && $post) {
        rs_res_table();
        if (rs_rate_over('devid', 30, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'Too many tries. Try again in a bit.']);
        rs_rate_hit('devid');
        $mine = $myIds();
        $open = count(array_filter($mine, fn($r) => !$r['used']));
        $id = strtoupper(trim((string)($in['id'] ?? '')));
        if ($a === 'id-new' || $a === 'id-reserve') {
            if ($open >= RS_RES_MAX) rs_dev_out(200, ['ok' => false, 'error' => 'You have ' . RS_RES_MAX . ' title IDs waiting already. Put an app out with one, or let one go.']);
            if ($a === 'id-new') $id = rs_tid_free();
            elseif (!preg_match('/^[A-Z]{4}\d{5}$/', $id)) rs_dev_out(200, ['ok' => false, 'error' => 'A title ID is four letters and five numbers, like PPSA12345.']);
            elseif (rs_move_block($id)) rs_dev_out(200, ['ok' => false, 'error' => $id . ' is in the block the store keeps for itself. Pick another.']);
            else {
                $uses = array_filter(rs_tid_uses($id)['uses'], fn($u) => !($acc['gh_login'] !== '' && str_starts_with($u['repo'], strtolower((string)$acc['gh_login']) . '/')));
                if ($uses) rs_dev_out(200, ['ok' => false, 'error' => $id . ' is already used: ' . implode(', ', array_map(fn($u) => $u['where'] === 'submission' ? 'an app that was sent in' : $u['name'], $uses)) . '.']);
            }
            try {
                rs_db()->prepare('INSERT INTO title_reservations (title_id, account_id, created, expires) VALUES (?, ?, NOW(), NOW() + INTERVAL ' . RS_RES_DAYS . ' DAY)')->execute([$id, $acc['id']]);
            } catch (PDOException $e) {
                rs_dev_out(200, ['ok' => false, 'error' => $id . ' was just taken. Try again.']);
            }
            rs_log('developers', 'title-id', rs_dev_label($acc) . ' reserved ' . $id);
            rs_dev_out(200, ['ok' => true, 'id' => $id, 'ids' => $myIds(), 'message' => $id . ' is yours. It shows as reserved on the Title ID lookup.']);
        }
        $own = rs_db()->prepare('SELECT * FROM title_reservations WHERE title_id = ? AND account_id = ?');
        $own->execute([$id, $acc['id']]);
        $r = $own->fetch();
        if (!$r) rs_dev_out(200, ['ok' => false, 'error' => 'That isn\'t one of your title IDs.']);
        if ($a === 'id-renew') {
            rs_db()->prepare('UPDATE title_reservations SET expires = NOW() + INTERVAL ' . RS_RES_DAYS . ' DAY WHERE id = ?')->execute([$r['id']]);
            rs_dev_out(200, ['ok' => true, 'ids' => $myIds(), 'message' => $id . ' is kept for another 6 months.']);
        }
        if ($r['used_at'] !== null) rs_dev_out(200, ['ok' => false, 'error' => 'An app is out with ' . $id . ', so it stays yours.']);
        rs_db()->prepare('DELETE FROM title_reservations WHERE id = ?')->execute([$r['id']]);
        rs_log('developers', 'title-id', rs_dev_label($acc) . ' let go of ' . $id);
        rs_dev_out(200, ['ok' => true, 'ids' => $myIds(), 'message' => $id . ' is free again.']);
    }
    // ---- Betas for their apps ------------------------------------------------------------------
    $betaApp = function () use ($in, $apps): array {
        foreach ($apps as $g) if ($g['id'] === (string)($in['app'] ?? '') && rs_has_beta($g)) return rs_game($g['id']);
        rs_dev_out(200, ['ok' => false, 'error' => 'That isn\'t one of your betas.']);
    };
    if ($a === 'betas') {
        $betas = rs_dev_betas($apps);
        foreach ($betas as &$b) $b['releases'] = rs_dev_releases((string)$b['repo']);
        unset($b);
        rs_dev_out(200, ['ok' => true, 'betas' => $betas]);
    }
    if ($a === 'beta-build' && $post) {
        $g = $betaApp();
        $tag = (string)($in['tag'] ?? '');
        $asset = (string)($in['asset'] ?? '');
        $rel = null;
        foreach (rs_dev_releases((string)$g['repo']) as $r) if ($r['tag'] === $tag) foreach ($r['assets'] as $x) if ($x['name'] === $asset) $rel = [$r, $x];
        if (!$rel) rs_dev_out(200, ['ok' => false, 'error' => 'Pick a .zip from one of the app\'s GitHub releases.']);
        $version = mb_substr(trim((string)($in['version'] ?? '')) ?: ltrim($tag, 'vV'), 0, 40);
        [$sha, $bytes] = rs_dev_hash_url($rel[1]['url']);
        $g['beta_file'] = 'gh:' . $tag . '/' . $asset;
        $g['beta_url'] = $rel[1]['url'];
        $g['beta_sha256'] = $sha;
        $g['beta_size'] = $bytes;
        $g['beta_version'] = $version;
        $g['beta_notes'] = mb_substr(trim(str_replace("\r\n", "\n", (string)($in['notes'] ?? ''))), 0, 4000);
        $g['beta_at'] = gmdate('M j, Y');
        rs_save_game($g, null);
        rs_log('developers', 'beta', rs_dev_label($acc) . ' set ' . $g['id'] . ' to ' . $tag . '/' . $asset);
        rs_dev_out(200, ['ok' => true, 'message' => rs_sign_on() && rs_sig_for('beta', $sha) === ''
            ? 'Build set. It goes out to testers once RecompStore signs it (that\'s a manual check, usually within a day).'
            : 'Build set. Testers get it the next time RecompStore checks for updates.']);
    }
    if ($a === 'beta-codes' && $post) {
        $g = $betaApp();
        $n = max(1, min(50, (int)($in['count'] ?? 1)));
        $st = $db->prepare('SELECT COUNT(*) FROM beta_codes WHERE game_id = ?');
        $st->execute([$g['id']]);
        if ((int)$st->fetchColumn() + $n > RS_DEV_BETA_MAX_CODES) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s more codes than one beta can have. Cancel unused ones first.']);
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 100);
        $made = rs_beta_make_codes($g['id'], $n, mb_substr('Dev ' . rs_dev_label($acc) . ($note !== '' ? ': ' . $note : ''), 0, 120));
        rs_dev_out(200, ['ok' => true, 'codes' => array_map('rs_beta_format', $made)]);
    }
    if ($a === 'beta-cancel' && $post) {
        $g = $betaApp();
        $code = preg_replace('/\D/', '', (string)($in['code'] ?? '')) ?? '';
        $up = $db->prepare('UPDATE beta_codes SET revoked = 1 WHERE code = ? AND game_id = ?');
        $up->execute([$code, $g['id']]);
        rs_dev_out(200, ['ok' => $up->rowCount() === 1]);
    }
    if ($a === 'beta-page' && $post) {
        $g = $betaApp();
        $g['beta_public'] = !empty($in['public']);
        $g['beta_discord'] = !empty($in['discord']) && $g['beta_public'];
        $g['beta_limit'] = max(0, min(10000, (int)($in['limit'] ?? 0)));
        $g['beta_notes'] = array_key_exists('notes', $in) ? mb_substr(trim(str_replace("\r\n", "\n", (string)$in['notes'])), 0, 4000) : $g['beta_notes'];
        rs_save_game($g, null);
        rs_dev_out(200, ['ok' => true, 'message' => 'Saved.']);
    }
    // (3.0) Claiming: theirs by GitHub goes on their page now; anything else waits for the admin.
    if ($a === 'claim-find') rs_dev_out(200, ['ok' => true, 'found' => rs_dev_claim_search($acc, (string)($in['q'] ?? ''))]);
    if ($a === 'claim' && $post) {
        $key = (string)($in['app'] ?? '');
        $app = rs_dev_app_by_key($key);
        if (!$app || (!empty($app['hidden']) && empty($app['beta']))) rs_dev_out(200, ['ok' => false, 'error' => 'That app isn\'t in the store.']);
        $key = str_starts_with($key, 'pc:') ? 'pc:' . $app['id'] : (string)$app['id'];
        $owner = rs_dev_app_owner($key, $app);
        if ($owner !== '' && $owner === (string)$acc['slug']) rs_dev_out(200, ['ok' => true, 'message' => 'It\'s on your page already.', 'claims' => rs_dev_claim_state($acc, $apps, $pcApps)]);
        if (rs_rate_over('devclaim', 30, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of claims in an hour. Try again a bit later.']);
        rs_rate_hit('devclaim');
        $open = $db->prepare("SELECT id FROM dev_claims WHERE account_id = ? AND app_id = ? AND status = 'pending'");
        $open->execute([$acc['id'], $key]);
        if ($open->fetch()) rs_dev_out(200, ['ok' => true, 'message' => 'You\'ve claimed it already. We\'ll look at it soon.', 'claims' => rs_dev_claim_state($acc, $apps, $pcApps)]);
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 1000);
        // Theirs by GitHub, and not on another developer's page: straight away. Anything else, we check.
        if (rs_dev_owns_repo($acc, (string)$app['repo']) && $owner === '') {
            rs_dev_claim_apply($acc, $key);
            $db->prepare("INSERT INTO dev_claims (account_id, app_id, note, status, created, decided) VALUES (?, ?, ?, 'approved', NOW(), NOW())")->execute([$acc['id'], $key, $note]);
            rs_log('developers', 'claim', rs_dev_label($acc) . ' claimed ' . $key . ' (their GitHub)');
            $acc2 = rs_dev_account((int)$acc['id']) ?? $acc;
            rs_dev_out(200, ['ok' => true, 'instant' => true, 'message' => str_starts_with($key, 'pc:') ? 'It\'s yours: your name is on its page now.' : 'It\'s yours: your name goes on its page with the next store update.',
                'claims' => rs_dev_claim_state($acc2, rs_dev_apps($acc2), rs_dev_pc_apps($acc2))]);
        }
        $db->prepare("INSERT INTO dev_claims (account_id, app_id, note, status, created) VALUES (?, ?, ?, 'pending', NOW())")->execute([$acc['id'], $key, $note]);
        rs_dev_notify_admin($acc, 'claims ' . ($app['name'] ?? $key) . ' (' . ($app['repo'] ?? '') . ')' . ($note !== '' ? ":\n\n" . $note : ''));
        rs_dev_out(200, ['ok' => true, 'message' => $owner !== '' ? 'Sent. It\'s on another developer\'s page, so we\'ll check and let you know.' : 'Sent. Its GitHub isn\'t yours, so we\'ll check and let you know.', 'claims' => rs_dev_claim_state($acc, $apps, $pcApps)]);
    }
    // (3.0) The developers' lounge.
    // (3.1) Its channels: the ones for what they make.
    $room = (string)($in['room'] ?? 'general');
    if (!in_array($room, rs_dev_rooms_for($acc), true)) {
        if (in_array($a, ['lounge-post', 'lounge-delete'], true)) rs_dev_out(200, ['ok' => false, 'error' => 'That channel isn\'t one of yours.']);
        $room = 'general';
    }
    if ($a === 'lounge') {
        $posts = rs_dev_lounge((int)$acc['id'], $room);
        if ($posts) rs_dev_room_seen($acc, $room, (int)end($posts)['id']);
        rs_dev_out(200, ['ok' => true, 'room' => $room, 'posts' => $posts]);
    }
    // (3.1) Docs: for approved developers, the ones for what they make.
    if ($a === 'docs') {
        rs_dev_out(200, ['ok' => true, 'docs' => array_map(fn($d) => ['slug' => $d['slug'], 'title' => $d['title'], 'summary' => $d['summary'], 'audience' => $d['audience'],
            'minutes' => max(1, (int)round((int)$d['chars'] / 1100)), 'updated' => gmdate('Y-m-d', (int)strtotime((string)$d['updated'] . ' UTC'))], rs_dev_docs_for($acc))]);
    }
    if ($a === 'doc') {
        $slug = (string)($in['slug'] ?? '');
        $ok = array_filter(rs_dev_docs_for($acc), fn($d) => $d['slug'] === $slug);
        if (!$ok) rs_dev_out(200, ['ok' => false, 'error' => 'That doc isn\'t here.']);
        $st = $db->prepare('SELECT slug, title, summary, audience, body, updated FROM dev_docs WHERE slug = ?');
        $st->execute([$slug]);
        $d = $st->fetch();
        rs_dev_out(200, ['ok' => true, 'doc' => ['slug' => $d['slug'], 'title' => $d['title'], 'summary' => $d['summary'], 'audience' => $d['audience'], 'body' => $d['body'],
            'updated' => gmdate('Y-m-d', (int)strtotime((string)$d['updated'] . ' UTC'))]]);
    }
    if ($a === 'lounge-post' && $post) {
        if (rs_rate_over('devlounge', 20, 3600)) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of posts in an hour. Try again a bit later.']);
        $mine = $db->prepare("SELECT COUNT(*) FROM dev_messages WHERE channel = 'lounge' AND account_id = ? AND from_admin = 0 AND created > NOW() - INTERVAL 1 HOUR");
        $mine->execute([$acc['id']]);
        if ((int)$mine->fetchColumn() >= 20) rs_dev_out(200, ['ok' => false, 'error' => 'That\'s a lot of posts in an hour. Try again a bit later.']);
        rs_rate_hit('devlounge');
        try { $nid = rs_dev_msg_add('lounge', (int)$acc['id'], false, (string)($in['body'] ?? ''), $room); } catch (RuntimeException $e) { rs_dev_out(200, ['ok' => false, 'error' => $e->getMessage()]); }
        rs_dev_room_seen($acc, $room, $nid);
        rs_dev_out(200, ['ok' => true, 'room' => $room, 'posts' => rs_dev_lounge((int)$acc['id'], $room)]);
    }
    if ($a === 'lounge-delete' && $post) {
        $db->prepare("UPDATE dev_messages SET deleted = 2 WHERE id = ? AND channel = 'lounge' AND account_id = ? AND from_admin = 0 AND deleted = 0")->execute([(int)($in['id'] ?? 0), $acc['id']]);
        rs_dev_out(200, ['ok' => true, 'room' => $room, 'posts' => rs_dev_lounge((int)$acc['id'], $room)]);
    }
    if ($a === 'weekly' && $post) {
        $db->prepare('UPDATE dev_accounts SET weekly = ? WHERE id = ?')->execute([!empty($in['on']) ? 1 : 0, $acc['id']]);
        rs_dev_out(200, ['ok' => true, 'message' => !empty($in['on']) ? 'You\'ll get the weekly email on Mondays.' : 'No more weekly emails.']);
    }
    if ($a === 'logs') {
        rs_dev_out(200, ['ok' => true, 'logs' => rs_dev_logs($ids)]);
    }
    if ($a === 'zip') {
        $app = (string)($in['app'] ?? '');
        $want = $app !== '' ? array_values(array_intersect($ids, [$app])) : $ids;
        if (!$want) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('No logs to download.'); }
        rs_report_tables(); rs_inbox_category_column(); rs_submission_columns();
        $st = $db->prepare("SELECT * FROM inbox WHERE kind = 'logs' AND app_id IN (" . implode(',', array_fill(0, count($want), '?')) . ') ORDER BY created DESC LIMIT 300');
        $st->execute($want);
        $rows = $st->fetchAll();
        if (!$rows) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('No logs to download.'); }
        @set_time_limit(120);
        [$zip] = rs_log_zip_many($rows, true, 48 << 20);
        $g = $app !== '' ? rs_game($app) : null;
        rs_zip_send($zip, rs_zip_clean(($g ? $g['name'] : 'My apps') . ' logs - ' . gmdate('Y-m-d')));
    }
    if ($a === 'file') {
        $st = $db->prepare("SELECT f.name, f.body, i.app_id FROM inbox_files f JOIN inbox i ON i.id = f.inbox_id WHERE f.id = ? AND i.kind = 'logs'");
        $st->execute([(int)($in['id'] ?? 0)]);
        $f = $st->fetch();
        if (!$f || !in_array((string)$f['app_id'], $ids, true)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Not found.'); }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: ' . (!empty($in['dl']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['name']) . '"');
        header('X-Robots-Tag: noindex');
        echo (string)$f['body'];
        exit;
    }
    rs_dev_out(400, ['ok' => false, 'error' => 'Unknown request.']);
} catch (Throwable $e) {
    rs_dev_out(503, ['ok' => false, 'error' => 'The developer hub isn\'t available right now. Try again later.']);
}
