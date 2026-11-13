<?php
// RecompStore website (2.1): a developer submits a game port, emulator, app or tool for the store,
// with its GitHub repo (or, (3.9), a PC recomp's GitLab project), the details for its page and, if they want, its pictures. It lands in the
// admin's Inbox under Submissions, where it can be added to the store as a hidden draft.
//   POST multipart: kind, name, author, contact, repo, asset, title_id, version, blurb, description,
//   needs, credits, link1..3, notes, rights=1, website (honeypot), icon, hero, logo, shots[] (up to 4)
//   -> {ok, message} or {ok:false, error}
declare(strict_types=1);
require_once dirname(__DIR__) . '/admin/inc/bootstrap.php';
// (2.7.3) From a developer's hub, api/dev.php (a=submit) runs this with RS_SUB_ACCOUNT set: the
// submission is theirs, shown in their hub and marked in the Inbox.

header('Cache-Control: no-store');
if (!function_exists('rs_sub_fix_key')) {
    // (3.6) The key in a "fix and send it again" link for submission <id>.
    function rs_sub_fix_key(int $id): string { return substr(hash_hmac('sha256', "sub-fix|$id", rs_mail_key()), 0, 24); }
}
header('X-Content-Type-Options: nosniff');

function rs_sub_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// (3.6) A submission sent back for changes: the link in our email opens it again, filled in.
//   GET fix=<id>&t=<key> -> {ok, data, reason, shots}
$fixRow = null;
$fixIn = (int)($_GET['fix'] ?? $_POST['fix'] ?? 0);
if ($fixIn > 0) {
    $key = (string)($_GET['t'] ?? $_POST['t'] ?? '');
    $st = rs_db()->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'submit'");
    $st->execute([$fixIn]);
    $fixRow = $st->fetch() ?: null;
    $fd = $fixRow ? json_decode((string)$fixRow['data'], true) : null;
    if (!$fixRow || !is_array($fd) || empty($fd['declined']) || !hash_equals(rs_sub_fix_key($fixIn), $key))
        rs_sub_out(404, ['ok' => false, 'error' => 'That link has run out or was already used. Send it again from the start.']);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $sh = rs_db()->prepare("SELECT COUNT(*) FROM inbox_pictures WHERE inbox_id = ? AND role = 'shot'");
        $sh->execute([$fixIn]);
        $out = array_diff_key($fd, array_flip(['account', 'account_name', 'verified', 'declined', 'declined_at', 'resubmitted']));
        $ic = rs_db()->prepare("SELECT COUNT(*) FROM inbox_pictures WHERE inbox_id = ? AND role = 'icon'");
        $ic->execute([$fixIn]);
        rs_sub_out(200, ['ok' => true, 'data' => $out, 'reason' => (string)($fd['declined']['reason'] ?? ''), 'shots' => (int)$sh->fetchColumn(), 'icon' => (int)$ic->fetchColumn() > 0]);
    }
}
// (4.8) Submit > Easy Setup, "Check my repo": is recomplauncher.json in the project yet?
//   GET a=sdk-check&repo=<owner/name or link>&asset=<Windows file pattern> -> {ok, has, tag, easy, settings, dumps}
if (($_GET['a'] ?? '') === 'sdk-check') {
    $in = trim((string)($_GET['repo'] ?? ''));
    $host = '';
    if (preg_match('#^(?:https?://)?(?:www\.)?gitlab\.com/(.+)$#i', $in, $gm)) { $host = 'gitlab'; $in = preg_replace('#/-(/.*)?$#', '', $gm[1]); }
    $repo = trim((string)preg_replace(['#^(?:https?://)?(?:www\.)?github\.com/#i', '#[?\#].*$#', '#\.git$#i'], '', $in), '/');
    $ok = $host === 'gitlab' ? (bool)preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}(/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}){1,5}$#', $repo) : rs_repo_ok($repo);
    // (4.9) A project on a Forgejo server we read (Longshot's git.magicbean.dev): the same check.
    if (!$ok && ($fl = rs_repo_from_link(trim((string)($_GET['repo'] ?? '')))) && $fl['host'] === 'forgejo') { $host = 'forgejo'; $repo = $fl['repo']; $ok = true; }
    if (!$ok) rs_sub_out(200, ['ok' => false, 'error' => 'Add your project\'s link on the first step.']);
    if (rs_rate_over('sdkprobe', 20, 600)) rs_sub_out(200, ['ok' => false, 'error' => 'That\'s a lot of checks. Wait a few minutes and try again.']);
    rs_rate_hit('sdkprobe');
    try { $r = rs_pc_sdk_probe($repo, $host, mb_substr(trim((string)($_GET['asset'] ?? '')), 0, 160)); }
    catch (Throwable $e) { rs_sub_out(200, ['ok' => false, 'error' => 'We couldn\'t reach the project just now. Try again in a minute.']); }
    rs_sub_out(200, ['ok' => true] + $r);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_sub_out(405, ['ok' => false, 'error' => 'Send the form.']);
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . $host, PHP_URL_HOST)) rs_sub_out(403, ['ok' => false, 'error' => 'Send it from the website.']);
$subAcc = defined('RS_SUB_ACCOUNT') ? RS_SUB_ACCOUNT : null;
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) rs_sub_out(413, ['ok' => false, 'error' => 'The pictures are too big together. Send smaller ones.']);

$s = function (string $k, int $max): string {
    $v = isset($_POST[$k]) && is_string($_POST[$k]) ? trim(str_replace("\r\n", "\n", $_POST[$k])) : '';
    return mb_substr(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $v) ?? '', 0, $max);
};
if ($s('website', 200) !== '') rs_sub_out(200, ['ok' => true, 'message' => 'Thanks! It\'s in.']); // a bot

$kind = $s('kind', 16);
$kinds = ['games' => 'game port', 'emulators' => 'emulator', 'apps' => 'app', 'tools' => 'tool'];
$d = [
    'category' => isset($kinds[$kind]) ? $kind : '',
    'name' => $s('name', 120), 'author' => $s('author', 80), 'contact' => $s('contact', 200),
    'repo' => rtrim((string)preg_replace('#^https?://(www\.)?github\.com/#i', '', $s('repo', 255)), '/'),
    'asset' => $s('asset', 160), 'title_id' => strtoupper($s('title_id', 16)), 'version' => $s('version', 40),
    'blurb' => $s('blurb', 160), 'description' => $s('description', 6000), 'needs' => $s('needs', 160),
    'credits' => $s('credits', 1000), 'notes' => $s('notes', 2000), 'links' => [],
];
// (2.8) A PC recomp (recompstore.com/pc): always a game, no title ID or PS5 release file.
if ($s('platform', 8) === 'pc') { $d['platform'] = 'pc'; $d['category'] = 'games'; $d['title_id'] = ''; $d['asset'] = ''; }
// (3.6) A PC recomp says where it runs (and its file on each), the game, whether it works with
// RecompLauncher's SDK, whether it needs steps before the first play, and what it has: everything
// the admin's Approve needs to put it live in one click.
if (($d['platform'] ?? '') === 'pc') {
    $d['assets'] = [];
    foreach (['win', 'mac', 'linux'] as $k) {
        if (($_POST['sys_' . $k] ?? '') !== '1') continue;
        $pat = $s('asset_' . $k, 160);
        if ($pat === '' || !preg_match('/^[A-Za-z0-9 ._*?+()\[\]~-]{1,160}$/', $pat)) rs_sub_out(422, ['ok' => false, 'error' => 'Name the release file for each system, like MyRecomp-*-Windows-x64.zip.']);
        $d['assets'][$k] = $pat;
    }
    if (!$d['assets']) rs_sub_out(422, ['ok' => false, 'error' => 'Switch on at least one system it runs on.']);
    $og = $s('origin', 16);
    $d['origin'] = isset(RS_PC_ORIGINS[$og]) ? $og : '';
    if ($d['origin'] === '') rs_sub_out(422, ['ok' => false, 'error' => 'Pick the console the game is from.']);
    $d['disc'] = $s('disc', 120);
    $yn = fn(string $k, array $ok) => in_array($v = $s($k, 8), $ok, true) ? $v : '';
    $d['rl_quick'] = $yn('rl_quick', ['yes', 'no', 'setup']);
    $d['rl_settings'] = $yn('rl_settings', ['yes', 'no']);
    $d['rl_future'] = $d['rl_quick'] !== 'yes' && $d['rl_settings'] === 'no' ? $yn('rl_future', ['yes', 'maybe', 'no']) : '';
    if ($d['rl_quick'] === '' || $d['rl_settings'] === '') rs_sub_out(422, ['ok' => false, 'error' => 'Answer the two Works with RecompLauncher questions.']);
    // "It needs to be built or set up first" is quick install's third answer: its steps become Set Up.
    $d['setup'] = $d['rl_quick'] === 'setup' ? 'yes' : 'no';
    $d['setup_steps'] = $d['setup'] === 'yes' ? $s('setup_steps', 2000) : '';
    if ($d['setup'] === 'yes' && trim($d['setup_steps']) === '') rs_sub_out(422, ['ok' => false, 'error' => 'Write the steps players need to follow.']);
    // (4.7) More to pick from; they become tags on its listing.
    $d['features'] = array_values(array_intersect(['Online', 'Multiplayer', 'Co-op', 'Split-screen', 'Widescreen', 'Ultrawide', '4K', '60 FPS', '120 FPS', 'High FPS', 'HDR', 'Upscaling',
        'Mods', 'Texture packs', 'Controller', 'Keyboard and mouse', 'Gyro', 'Cheats'], array_map('strval', (array)($_POST['features'] ?? []))));
    // (4.7) Recomp Achievements: recommended, not required.
    $d['rl_ach'] = $yn('rl_ach', ['yes', 'planned', 'no']);
    if ($d['version'] === '') rs_sub_out(422, ['ok' => false, 'error' => 'Add the version.']);
    if (mb_strlen($d['needs']) < 3) rs_sub_out(422, ['ok' => false, 'error' => 'Say what players need, like their own copy of the game.']);
}
// (2.9) Whether AI helped make it, in the developer's own words: shown on its page as AI-assisted.
$aiIn = $s('platform', 8) === 'pc' ? $s('ai', 4) : '';
$d['ai'] = in_array($aiIn, ['yes', 'no'], true) ? $aiIn : '';
$d['ai_uses'] = $d['ai'] === 'yes' ? (rs_ai_uses_clean((array)($_POST['ai_uses'] ?? [])) ?: ['code']) : [];
$d['ai_note'] = $d['ai'] === 'yes' ? $s('ai_note', 200) : '';
if ($subAcc) { $d['account'] = (int)$subAcc['id']; $d['account_name'] = rs_dev_label($subAcc); $d['verified'] = !empty($subAcc['verified']); }
foreach (['link1', 'link2', 'link3'] as $k) {
    $l = $s($k, 300);
    if ($l === '') continue;
    if (!preg_match('#^https://[^\s<>"]{3,290}$#', $l)) rs_sub_out(422, ['ok' => false, 'error' => 'Links have to start with https://']);
    $d['links'][] = $l;
}
$d['repo'] = preg_replace('#\.git$#', '', $d['repo']);
// (3.9) A gitlab.com project link (group/project or group/sub/project): PC recomps only for now.
$repoHost = '';
if (preg_match('#^(https?://)?(www\.)?gitlab\.com/#i', $d['repo'])) {
    $gl = rs_repo_from_link($d['repo']);
    if (!$gl) rs_sub_out(422, ['ok' => false, 'error' => 'The GitLab project goes like gitlab.com/you/your-app.']);
    if (($d['platform'] ?? '') !== 'pc') rs_sub_out(422, ['ok' => false, 'error' => 'PS5 apps need a GitHub repo for now. GitLab works for PC recomps.']);
    $repoHost = 'gitlab';
    $d['repo'] = $gl['repo'];
    $d['host'] = 'gitlab';
}
if ($d['category'] === '') rs_sub_out(422, ['ok' => false, 'error' => 'Pick what it is: a game port, an emulator, an app or a tool.']);
if (mb_strlen($d['name']) < 2) rs_sub_out(422, ['ok' => false, 'error' => 'Give it a name.']);
if (mb_strlen($d['author']) < 2) rs_sub_out(422, ['ok' => false, 'error' => 'Say who made it (your name or handle).']);
// (4.6) An email address is required: it's how we tell them it's live, or what to change.
if ($subAcc && !filter_var($d['contact'], FILTER_VALIDATE_EMAIL) && filter_var((string)($subAcc['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $d['contact'] = (string)$subAcc['email'];
if (!filter_var($d['contact'], FILTER_VALIDATE_EMAIL)) rs_sub_out(422, ['ok' => false, 'error' => 'Leave a real email address, so we can reach you.']);
if ($repoHost === '' && !preg_match('#^[A-Za-z0-9_.-]{1,39}/[A-Za-z0-9_.-]{1,100}$#', $d['repo'])) rs_sub_out(422, ['ok' => false, 'error' => 'The GitHub repo goes like github.com/you/your-app.']);
// (4.6) A GitHub project comes from the GitHub account it belongs to (theirs, or one of their public
// organizations), signed in to a developer account. GitLab projects are checked by hand in the admin.
if ($repoHost === '') {
    $ghOwner = strtolower((string)strtok($d['repo'], '/'));
    if (!$subAcc || (string)($subAcc['gh_login'] ?? '') === '') rs_sub_out(403, ['ok' => false, 'error' => 'Sign in with GitHub first (a free developer account at ' . (($d['platform'] ?? '') === 'pc' ? 'recomplauncher.com/dev' : 'recompstore.com/developers') . '), so we can confirm the project is yours.']);
    if (!in_array($ghOwner, rs_dev_owners($subAcc), true)) rs_sub_out(403, ['ok' => false, 'error' => 'That repo belongs to ' . $ghOwner . ', not your GitHub (@' . $subAcc['gh_login'] . ') or one of its organizations. Send it from the GitHub account it belongs to. (If it\'s under an organization, make your membership public on GitHub.)']);
}
if ($d['title_id'] !== '' && !preg_match('/^[A-Z]{4}\d{5}$/', $d['title_id'])) rs_sub_out(422, ['ok' => false, 'error' => 'A title ID is four letters and five numbers, like PPSA12345. Leave it empty if you don\'t have one yet.']);
if ($d['asset'] !== '' && !preg_match('/\.(zip|7z)$/i', $d['asset'])) rs_sub_out(422, ['ok' => false, 'error' => 'The release file is the .zip or .7z on your GitHub release, like MyApp-*.zip.']);
if ($d['ai'] === '' && ($d['platform'] ?? '') === 'pc') rs_sub_out(422, ['ok' => false, 'error' => 'Say whether AI tools helped make it (yes or no).']);
if (mb_strlen($d['description']) < 20) rs_sub_out(422, ['ok' => false, 'error' => 'Tell players a bit more about it in the description.']);
if (($_POST['rights'] ?? '') !== '1') rs_sub_out(422, ['ok' => false, 'error' => 'Tick the box to confirm you made it or may submit it.']);

// Pictures, all optional: an icon, a backdrop, a logo and up to 4 screenshots. PNG, JPEG or WebP,
// 8 MB each, checked to really be pictures.
$pics = [];
$take = function (string $role, string $tmp, int $err, int $size) use (&$pics) {
    if ($err === UPLOAD_ERR_NO_FILE) return;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $size > (8 << 20)) rs_sub_out(422, ['ok' => false, 'error' => 'Each picture can be 8 MB at most.']);
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) rs_sub_out(422, ['ok' => false, 'error' => 'A picture didn\'t arrive. Try again.']);
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($types[$mime]) || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
        || $info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) rs_sub_out(422, ['ok' => false, 'error' => 'Pictures have to be PNG, JPEG or WebP.']);
    $pics[] = ['role' => $role, 'tmp' => $tmp, 'mime' => $mime, 'ext' => $types[$mime], 'size' => $size, 'w' => (int)$info[0], 'h' => (int)$info[1]];
};
// (3.6) A PC recomp needs at least one screenshot of it running.
if (($d['platform'] ?? '') === 'pc') {
    $sf = $_FILES['shots'] ?? null;
    $has = false;
    if (is_array($sf) && is_array($sf['error'] ?? null)) foreach ($sf['error'] as $er) if ((int)$er !== UPLOAD_ERR_NO_FILE) $has = true;
    if (!$has && $fixRow) { $sh = rs_db()->prepare("SELECT COUNT(*) FROM inbox_pictures WHERE inbox_id = ? AND role = 'shot'"); $sh->execute([(int)$fixRow['id']]); $has = (int)$sh->fetchColumn() > 0; }
    if (!$has) rs_sub_out(422, ['ok' => false, 'error' => 'Add at least one screenshot of it running.']);
    // (4.7) And an icon.
    $if = $_FILES['icon'] ?? null;
    $hasIcon = is_array($if) && !is_array($if['error'] ?? null) && (int)$if['error'] !== UPLOAD_ERR_NO_FILE;
    if (!$hasIcon && $fixRow) { $ic = rs_db()->prepare("SELECT COUNT(*) FROM inbox_pictures WHERE inbox_id = ? AND role = 'icon'"); $ic->execute([(int)$fixRow['id']]); $hasIcon = (int)$ic->fetchColumn() > 0; }
    if (!$hasIcon) rs_sub_out(422, ['ok' => false, 'error' => 'Add an icon: square, 512×512 or bigger.']);
}
foreach (['icon', 'hero', 'logo'] as $role) {
    $f = $_FILES[$role] ?? null;
    if (is_array($f) && !is_array($f['name'] ?? null)) $take($role, (string)$f['tmp_name'], (int)$f['error'], (int)$f['size']);
}
$f = $_FILES['shots'] ?? null;
if (is_array($f) && is_array($f['name'] ?? null)) {
    $n = 0;
    foreach ($f['name'] as $i => $_) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (++$n > 4) rs_sub_out(422, ['ok' => false, 'error' => 'Four screenshots at most.']);
        $take('shot', (string)$f['tmp_name'][$i], (int)$f['error'][$i], (int)$f['size'][$i]);
    }
}

try {
    $db = rs_db();
    rs_inbox_table();
    rs_report_tables();
    rs_submission_columns();
    // Limits: 5 an hour from one address, 60 an hour from everyone.
    if (!$db->query("SELECT GET_LOCK('rs_inbox', 5)")->fetchColumn()) rs_sub_out(503, ['ok' => false, 'error' => 'Busy. Try again in a moment.']);
    $ip = rs_ip_hash();
    $mine = $db->prepare('SELECT COUNT(*) FROM inbox WHERE ip_hash = ? AND created > (NOW() - INTERVAL 1 HOUR)');
    $mine->execute([$ip]);
    $all = (int)$db->query('SELECT COUNT(*) FROM inbox WHERE created > (NOW() - INTERVAL 1 HOUR)')->fetchColumn();
    if ((int)$mine->fetchColumn() >= 5 || $all >= 60) { $db->query("SELECT RELEASE_LOCK('rs_inbox')"); rs_sub_out(429, ['ok' => false, 'error' => 'Lots of messages right now. Try again in an hour.']); }

    $dir = $pics ? rs_report_dir_ready() : '';
    $db->beginTransaction();
    if ($fixRow) {
        // (3.6) Fixed and sent again: the same submission, back in the Inbox as new, with its pictures.
        $id = (int)$fixRow['id'];
        $d['resubmitted'] = gmdate('c');
        $d['was_declined'] = (json_decode((string)$fixRow['data'], true) ?: [])['declined'] ?? null;
        if (empty($d['account']) && !empty($fixRow['dev_account'])) $d['account'] = (int)$fixRow['dev_account'];
        $db->prepare("UPDATE inbox SET app_id = ?, name = ?, contact = ?, repo = ?, version = ?, body = ?, status = 'new', data = ? WHERE id = ?")
           ->execute([$d['title_id'], $d['name'], $d['contact'], rs_host_web($repoHost, $d['repo']), $d['version'], $d['description'],
               json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
        // New pictures replace the old ones in the same role.
        $roles = array_values(array_unique(array_column($pics, 'role')));
        if ($roles) {
            $in = implode(',', array_fill(0, count($roles), '?'));
            $db->prepare("DELETE FROM inbox_pictures WHERE inbox_id = ? AND role IN ($in)")->execute(array_merge([$id], $roles));
        }
    } else {
    $db->prepare('INSERT INTO inbox (kind, app_id, name, contact, repo, version, body, status, ip_hash, created, data, dev_account) VALUES (\'submit\', ?, ?, ?, ?, ?, ?, \'new\', ?, NOW(), ?, ?)')
       ->execute([$d['title_id'], $d['name'], $d['contact'], rs_host_web($repoHost, $d['repo']), $d['version'], $d['description'], $ip,
           json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $subAcc ? (int)$subAcc['id'] : null]);
    $id = (int)$db->lastInsertId();
    }
    $put = $db->prepare('INSERT INTO inbox_pictures (inbox_id, file, mime, size, w, h, created, role) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)');
    foreach ($pics as $k => $p) {
        $file = 'sub' . $id . '-' . $p['role'] . '-' . $k . '-' . bin2hex(random_bytes(6)) . '.' . $p['ext'];
        if (!move_uploaded_file($p['tmp'], $dir . '/' . $file)) throw new RuntimeException('A picture couldn\'t be saved.');
        $put->execute([$id, $file, $p['mime'], $p['size'], $p['w'], $p['h'], $p['role']]);
    }
    $db->commit();
    $db->query("SELECT RELEASE_LOCK('rs_inbox')");
    // (3.8) A confirmation to the developer: it arrived, how long a review takes, and where to ask.
    try {
        if (filter_var($d['contact'], FILTER_VALIDATE_EMAIL) && function_exists('rs_mailq_add') && rs_mail_ready()) {
            $pc = ($d['platform'] ?? '') === 'pc';
            $store = $pc ? 'RecompLauncher' : 'RecompStore';
            $invite = trim((string)rs_setting('discord_url', ''));
            $txt = 'Hi ' . ($d['author'] !== '' ? $d['author'] : 'there') . ",\n\n"
                . 'Thanks for sending ' . $d['name'] . ' to ' . $store . '. It came through' . ($fixRow ? ' with your changes' : '') . ' and it\'s in line for review.' . "\n\n"
                . 'Every submission is looked at by hand. It can take 24 to 48 hours, but most get an answer within a day. We\'ll write to you at this address either way.' . "\n\n"
                . ($subAcc ? 'You can follow it in your developer hub: ' . ($pc ? 'https://recomplauncher.com/dev' : 'https://recompstore.com/hub') . "\n\n"
                    : 'Tip: a free developer account lets you follow your submission, update your listing yourself and answer players. Make one here: ' . ($pc ? 'https://recomplauncher.com/dev?signup=1' : 'https://recompstore.com/developers?signup=1') . "\n\n")
                . ($invite !== '' ? 'Questions in the meantime? Come say hi on our Discord: ' . $invite . "\n\n" : '')
                . 'Ripalda' . "\n" . $store;
            rs_mailq_add($d['contact'], 'We got your submission: ' . $d['name'], $txt, ['kind' => 'submit-confirm'], 2, true);
        }
    } catch (Throwable $e) { /* the submission is in either way */ }
    if ($subAcc) {
        try {
            if (rs_mail_ready()) rs_mail_send(rs_mail_get('mail_from'), 'New app from ' . $d['account_name'] . ': ' . $d['name'],
                $d['account_name'] . ($d['verified'] ? ' (verified developer)' : '') . ' sent in ' . $d['name'] . ' (' . preg_replace('#^https://#', '', rs_host_web($repoHost, $d['repo'])) . ') from their developer hub.'
                . "\n\nSee it in Submissions: " . rs_abs('/admin/hub.php#sub:' . $id));
        } catch (Throwable $e) { /* it's in the Inbox either way */ }
        rs_sub_out(200, ['ok' => true, 'hub' => true, 'message' => 'Thanks! ' . $d['name'] . ' is in. Reviews take 24 to 48 hours, usually less than a day. You can follow it in your developer hub, and you\'ll hear back at ' . $d['contact'] . '.']);
    }
    if ($fixRow) rs_sub_out(200, ['ok' => true, 'message' => 'Thanks! Your changes to ' . $d['name'] . ' are in, and it\'s back in line for review. You\'ll hear back at ' . $d['contact'] . '.']);
    rs_sub_out(200, ['ok' => true, 'message' => 'Thanks! ' . $d['name'] . ' is in. Reviews take 24 to 48 hours, usually less than a day. ' . (filter_var($d['contact'], FILTER_VALIDATE_EMAIL) ? 'We sent a confirmation to ' . $d['contact'] . ', and you\'ll hear back there.' : 'You\'ll hear back at ' . $d['contact'] . '.')]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    rs_sub_out(503, ['ok' => false, 'error' => 'It didn\'t go through. Try again in a moment.']);
}
