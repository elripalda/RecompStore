<?php
// (4.11) The media editor's and Cover Labels' calls (admin/assets/media/admin-media.js):
//   POST a=upload    listing, kind (label|scan), file   -> {url}   PNG/JPEG/WebP up to 10 MB, kept as is
//   GET  a=sgdb      listing, shape (tall|square|wide)  -> [{url, thumb, w, h, author}] (url: our proxy below)
//   GET  a=sgdb-image id, listing                       -> the picture, saved into /media the first time
//   GET  a=img       u                                  -> a listing's picture from elsewhere, for the canvas
//   POST a=save      {listing, media, publish?}          -> {ok, media}   media null clears it
//   POST a=publish                                      -> {ok}   the catalog, written once (after Auto-fill)
//   POST a=labels-upload console, file                  -> {url}   owner only; PNG/WebP up to 5 MB, 3000 px wide
//   POST a=labels-save {labels}                         -> {ok}    owner only
// Everything needs a signed-in team member who can edit PC listings, and the CSRF token (X-CSRF).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pc.php';
require_once __DIR__ . '/inc/mediadesign.php';
rs_require_login();
rs_pc_tables();

$out = function (int $code, $j): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$a = (string)($_GET['a'] ?? $_POST['a'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$body = [];
if ($isPost && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
if ($isPost) {
    $t = (string)($_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? $body['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $t)) $out(400, ['error' => 'This page expired. Reload it and try again.']);
}
$listing = function (string $id) use ($out): array {
    $l = rs_pc_app($id);
    if (!$l) $out(404, ['error' => 'That listing isn\'t there.']);
    return $l;
};

try {
    if ($a === 'img' && !$isPost) {
        // A listing's own picture that lives elsewhere, passed through so the editor's canvas can read it.
        $u = (string)($_GET['u'] ?? '');
        if (!preg_match('#^https://#', $u)) $out(400, ['error' => 'Not a picture link.']);
        $r = rs_http($u, ['max' => 25 << 20, 'timeout' => 20]);
        $info = $r['status'] === 200 ? @getimagesizefromstring((string)$r['body']) : false;
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) $out(404, ['error' => 'No picture there.']);
        header('Content-Type: ' . $info['mime']);
        header('Cache-Control: private, max-age=86400');
        echo $r['body'];
        exit;
    }
    if ($a === 'sgdb-image' && !$isPost) {
        $p = rs_md_sgdb_file((int)($_GET['id'] ?? 0), (string)($_GET['listing'] ?? ''));
        header('Location: ' . $p, true, 302);
        exit;
    }
    if ($a === 'sgdb' && !$isPost) {
        $l = $listing((string)($_GET['listing'] ?? ''));
        $shape = (string)($_GET['shape'] ?? 'tall');
        if (!isset(RS_MD_SHAPES[$shape])) $out(400, ['error' => 'Pick tall, square or wide.']);
        if (rs_sgdb_key() === '') $out(400, ['error' => 'Add the SteamGridDB API key in Settings first.']);
        $gid = rs_md_sgdb_game($l);
        if (!$gid) $out(404, ['error' => 'SteamGridDB doesn\'t know "' . ($l['game'] ?: $l['name']) . '".']);
        $list = [];
        foreach ((array)(rs_sgdb('/grids/game/' . $gid . '?dimensions=' . RS_MD_SHAPES[$shape] . '&nsfw=false&humor=false') ?? []) as $x) {
            $iid = (int)($x['id'] ?? 0); $url = (string)($x['url'] ?? '');
            if (!$iid || $url === '' || preg_match('/\.(ico|gif)$/i', $url) || str_contains((string)($x['mime'] ?? ''), 'webm')) continue;
            rs_kv_put('sgdbimg:' . $iid, $url);
            $list[] = ['url' => 'media-api.php?a=sgdb-image&id=' . $iid . '&listing=' . rawurlencode($l['id']), 'thumb' => (string)($x['thumb'] ?? $url),
                'w' => (int)($x['width'] ?? 0), 'h' => (int)($x['height'] ?? 0), 'author' => (string)($x['author']['name'] ?? '')];
            if (count($list) >= 60) break;
        }
        $out(200, $list);
    }
    if (!$isPost) $out(405, ['error' => 'Not like that.']);

    if ($a === 'upload') {
        $l = $listing((string)($_POST['listing'] ?? ''));
        $kind = (string)($_POST['kind'] ?? '') === 'scan' ? 'scan' : 'label';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $out(400, ['error' => 'The file didn\'t arrive.']);
        $url = rs_md_store_bytes((string)file_get_contents($f['tmp_name']), 'pc-' . rs_pc_slug($l['id']) . '-' . $kind, 10 << 20);
        rs_log('pc', $l['id'], 'media ' . $kind . ' uploaded');
        $out(200, ['url' => $url]);
    }
    if ($a === 'save') {
        $l = $listing((string)($body['listing'] ?? ''));
        $m = rs_md_clean(is_array($body['media'] ?? null) ? $body['media'] : null);
        if ($m) $l['media_design'] = $m; else unset($l['media_design']);
        rs_pc_save($l);
        // One game saved from its page goes out now; Auto-fill writes the catalog once at the end.
        if (($body['publish'] ?? true) !== false && empty($l['hidden'])) rs_pc_publish_one($l['id']);
        rs_log('pc', $l['id'], $m ? 'media design saved' : 'media design back to automatic');
        $out(200, ['ok' => true, 'media' => $m]);
    }
    if ($a === 'publish') {
        @set_time_limit(300);
        rs_pc_write_catalog();
        rs_log('publish', 'pc-catalog', 'after media Auto-fill');
        $out(200, ['ok' => true]);
    }
    if ($a === 'labels-upload' || $a === 'labels-save') {
        if (!rs_is_owner()) $out(403, ['error' => 'Only the owner can change Cover Labels.']);
        if ($a === 'labels-upload') {
            $con = (string)($_POST['console'] ?? '');
            if (!preg_match('/^[a-z0-9_-]{1,24}$/', $con)) $out(400, ['error' => 'Which console?']);
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $out(400, ['error' => 'The file didn\'t arrive.']);
            $out(200, ['url' => rs_md_store_bytes((string)file_get_contents($f['tmp_name']), 'label-' . $con, 5 << 20, ['png', 'webp'], 3000)]);
        }
        $labels = rs_md_labels_clean(is_array($body['labels'] ?? null) ? $body['labels'] : []);
        rs_set_setting('cover_labels', (string)json_encode((object)$labels, JSON_UNESCAPED_SLASHES));
        @set_time_limit(300);
        rs_pc_write_catalog();
        rs_log('settings', 'cover_labels', count($labels) . ' consoles');
        $out(200, ['ok' => true, 'labels' => (object)$labels]);
    }
    $out(400, ['error' => 'Unknown call.']);
} catch (Throwable $e) {
    $out(400, ['error' => $e->getMessage()]);
}
