<?php
// Recomp Store website (2.0): a custom source, turned into catalog entries the PS5 store reads
// like its own catalog. A source is a GitHub repo, or a list (a JSON file at an https address
// naming several repos). Each repo's newest PS5 build is found on its releases; a zip is read
// on GitHub (its directory and the app's param.json and icon) without downloading it.
//   GET  ?repo=owner/name[&asset=pattern]   GET ?list=<https url>
//   POST a=report value why [install token]  -> the admin's Inbox (Sources)
// The contract: docs/api-2.0.md, section 3b.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = $post ? $_POST : $_GET;
if ($post && str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $j = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
    $in = is_array($j) ? $j : [];
}
$s = function (string $k, int $max) use ($in): string {
    $v = isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
    return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($v, 'UTF-8')) ?? '', 0, $max);
};

try {
    $db = rs_db();
} catch (Throwable $e) {
    rs_out(503, ['ok' => false, 'error' => 'recompstore.com can\'t read sources right now. Try again later.']);
}

if ($post && $s('a', 16) === 'report') {
    $value = $s('value', 500);
    $why = $s('why', 2000);
    $repo = rtrim((string)preg_replace('#^https://(www\.)?github\.com/#i', '', $value), '/');
    if (rs_repo_ok($repo)) $link = 'https://github.com/' . $repo;
    elseif (preg_match('#^https://[^\s<>"]+$#', $value)) $link = $value;
    else rs_out(422, ['ok' => false, 'error' => 'That isn\'t a source Recomp Store knows.']);
    if (mb_strlen($why) < 5) rs_out(422, ['ok' => false, 'error' => 'Say a few words about what\'s wrong with it.']);
    if (rs_rate_over('srcreport', 5, 3600)) rs_out(429, ['ok' => false, 'error' => 'That\'s a lot of reports at once. Try again in an hour.']);
    rs_rate_hit('srcreport');
    $console = rs_console($db, $s('install', 16), $s('token', 32)) ?? '';
    try {
        rs_inbox_table();
        $db->prepare('INSERT INTO inbox (kind, app_id, name, contact, repo, version, body, status, ip_hash, created)
                      VALUES (\'source\', \'\', ?, ?, ?, \'\', ?, \'new\', ?, NOW())')
           ->execute([mb_substr($link, 0, 120), $console, mb_substr($link, 0, 255), $why, rs_ip_hash()]);
    } catch (Throwable $e) {
        rs_out(500, ['ok' => false, 'error' => 'The report couldn\'t be saved right now. Try again later.']);
    }
    rs_out(200, ['ok' => true, 'message' => 'Thanks. We\'ll look at it.']);
}

$repo = rtrim((string)preg_replace('#^https://(www\.)?github\.com/#i', '', $s('repo', 220)), '/');
$list = $s('list', 500);
@set_time_limit(150);

if ($repo !== '') {
    if (!rs_repo_ok($repo)) rs_out(422, ['ok' => false, 'error' => 'Enter a GitHub repo as owner/name.']);
    if (rs_source_blocked('repo', $repo)) rs_out(200, ['ok' => false, 'blocked' => true, 'error' => 'This source was taken down.']);
    $asset = $s('asset', 120);
    if ($asset !== '' && !preg_match('/^[^\/\\\\]+\.(zip|7z)$/i', $asset)) rs_out(422, ['ok' => false, 'error' => 'The file name has to end in .zip or .7z.']);
    $res = rs_source_repo($repo, $asset);
    if (!($res['ok'] ?? false)) rs_out(200, ['ok' => false, 'error' => (string)($res['error'] ?? 'It couldn\'t be read.')]);
    $info = rs_gh_repo($repo) ?? [];
    rs_out(200, ['ok' => true,
        'source' => ['kind' => 'repo', 'value' => $repo, 'name' => $repo, 'author' => (string)($info['owner']['login'] ?? explode('/', $repo)[0])],
        'apps' => $res['apps'], 'errors' => []]);
}

if ($list !== '') {
    if (!rs_list_url_ok($list)) rs_out(422, ['ok' => false, 'error' => 'A list has to be at an https:// address.']);
    if (rs_source_blocked('list', $list)) rs_out(200, ['ok' => false, 'blocked' => true, 'error' => 'This source was taken down.']);
    // The whole list, cached 15 minutes; repos not read yet when time runs short are read next time.
    $key = 'list:' . $list;
    $c = rs_kv_get($key);
    // A repo the admin took down since: left out of a cached copy too.
    $unblocked = function (array $res): array {
        $keep = [];
        foreach ($res['apps'] as $app) {
            if (rs_source_blocked('repo', (string)($app['source']['repo'] ?? ''))) $res['errors'][] = ['repo' => $app['source']['repo'], 'error' => 'This source was taken down.'];
            else $keep[] = $app;
        }
        $res['apps'] = $keep;
        return $res;
    };
    if ($c && $c['age'] < 900) {
        $j = json_decode($c['v'], true);
        if (is_array($j)) rs_out(200, $unblocked($j));
    }
    $res = rs_source_list($list, microtime(true) + 60);
    if (!$res['ok']) rs_out(200, ['ok' => false, 'error' => $res['error']]);
    // Partly read: not cached, so the rest comes on the next ask.
    $partial = false;
    foreach ($res['errors'] as $e) if (str_starts_with($e['error'], 'Still being checked')) $partial = true;
    if (!$partial) rs_kv_put($key, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rs_out(200, $unblocked($res));
}

rs_out(400, ['ok' => false, 'error' => 'Ask for a repo (owner/name) or a list (an https:// address).']);
