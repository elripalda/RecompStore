<?php
// RecompStore admin (2.6): Icons. Each listing's icon next to the one in its newest release on
// GitHub (the app's own sce_sys/icon0.png), so an app whose developer changed its icon gets the
// new one. GitHub is asked fresh each time, one app at a time from the page, and nothing changes
// until you pick which ones to take.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

// The icon in a listing's newest release: ['ok' => true, 'icon' => '/media/gh/...'] or why not.
function rs_icon_from_release(array $g, bool $fresh): array {
    if ($g['source'] !== 'github' || !rs_repo_ok((string)$g['repo'])) return ['ok' => false, 'why' => 'Not from GitHub'];
    if (preg_match('/\.7z$/i', (string)$g['asset'])) return ['ok' => false, 'why' => 'A .7z can\'t be looked into'];
    $res = rs_source_repo((string)$g['repo'], (string)$g['asset'], $fresh);
    if (!($res['ok'] ?? false) || empty($res['apps'])) return ['ok' => false, 'why' => (string)($res['error'] ?? 'Nothing found on GitHub')];
    // The app in the zip with this listing's title ID, else the first.
    $want = rs_zip_id($g) ?: (string)$g['id'];
    $app = $res['apps'][0];
    foreach ($res['apps'] as $a) if (($a['titleId'] ?? '') === $want) { $app = $a; break; }
    $p = (string)parse_url((string)($app['icon'] ?? ''), PHP_URL_PATH);
    if (!preg_match('#^/media/gh/[0-9a-f]{40}\.(png|jpg)$#', $p) || !is_file(RS_PUBLIC . $p)) return ['ok' => false, 'why' => 'Its release has no icon0.png'];
    // The owner's GitHub picture stands in when a zip has no icon: never that.
    $av = rs_db()->prepare("SELECT COUNT(*) FROM kv_cache WHERE k LIKE 'avatar:%' AND v = ?");
    $av->execute([$p]);
    if ((int)$av->fetchColumn()) return ['ok' => false, 'why' => 'Its release has no icon0.png'];
    return ['ok' => true, 'icon' => $p, 'version' => (string)($app['version'] ?? '')];
}

// 16x16 colours of a picture (its center square, as icons are cut), for telling two icons apart.
function rs_icon_print(string $file): ?array {
    $bytes = @file_get_contents($file);
    $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
    $dst = imagecreatetruecolor(16, 16);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 0, 0, 0));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 16, 16, $side, $side);
    $out = [];
    for ($y = 0; $y < 16; ++$y) for ($x = 0; $x < 16; ++$x) {
        $c = imagecolorat($dst, $x, $y);
        $out[] = ($c >> 16) & 255; $out[] = ($c >> 8) & 255; $out[] = $c & 255;
    }
    return $out;
}

// Whether the listing's icon and the release's look different (null: the listing's can't be read).
function rs_icon_differs(string $current, string $fromRelease): ?bool {
    $p = (string)parse_url($current, PHP_URL_PATH);
    if ($current === '' ) return true;
    if (!str_starts_with($p, '/media/') || !is_file(RS_PUBLIC . $p)) return null;
    $a = rs_icon_print(RS_PUBLIC . $p);
    $b = rs_icon_print(RS_PUBLIC . $fromRelease);
    if ($a === null || $b === null) return null;
    $d = 0;
    foreach ($a as $i => $v) $d += abs($v - $b[$i]);
    return $d / count($a) > 10; // out of 255: a resize or a re-save stays well under
}

// One listing, checked: the page asks for each in turn.
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    @set_time_limit(60);
    $g = rs_game((string)$_GET['check']);
    if (!$g) { echo json_encode(['state' => 'err', 'why' => 'Not in the store']); exit; }
    $r = rs_icon_from_release($g, true);
    if (!$r['ok']) { echo json_encode(['state' => 'skip', 'why' => $r['why']]); exit; }
    $diff = rs_icon_differs((string)$g['icon'], $r['icon']);
    echo json_encode(['state' => $diff === false ? 'same' : 'new', 'icon' => $r['icon'], 'version' => $r['version'],
        'why' => $diff === null ? 'The listing\'s icon isn\'t on this site, so it can\'t be compared' : ''], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    @set_time_limit(120);
    $done = [];
    $failed = [];
    foreach (array_slice(array_map('strval', (array)($_POST['ids'] ?? [])), 0, 200) as $id) {
        $g = rs_game($id);
        if (!$g) continue;
        $r = rs_icon_from_release($g, false);
        if (!$r['ok']) { $failed[] = ($g['name'] ?: $id) . ' (' . $r['why'] . ')'; continue; }
        try {
            $file = RS_PUBLIC . $r['icon'];
            $saved = rs_save_image(['error' => UPLOAD_ERR_OK, 'size' => (int)filesize($file), 'tmp_name' => $file], 'icon', $g['id']);
            $old = (string)$g['icon'];
            $g['icon'] = $saved['icon'];
            rs_save_game($g);
            rs_log('change', 'icons.php ' . mb_substr($g['id'], 0, 40), 'icon ' . $old . ' -> ' . $g['icon'] . ' (from ' . $g['repo'] . ')');
            $done[] = $g['name'] ?: $id;
        } catch (Throwable $e) {
            $failed[] = ($g['name'] ?: $id) . ' (' . $e->getMessage() . ')';
        }
    }
    if ($done) rs_flash('New ' . (count($done) === 1 ? 'icon' : 'icons') . ' for ' . implode(', ', $done) . '. Press Publish to send ' . (count($done) === 1 ? 'it' : 'them') . ' out.'
        . ($failed ? ' Not changed: ' . implode('; ', $failed) . '.' : ''), 'ok');
    else rs_flash($failed ? 'Nothing changed: ' . implode('; ', $failed) . '.' : 'Pick at least one icon to take.', 'err');
    header('Location: icons.php');
    exit;
}

$games = array_values(array_filter(rs_games(true), fn($g) => $g['source'] === 'github' && (string)$g['repo'] !== ''));
$only = isset($_GET['id']) ? (string)$_GET['id'] : '';
if ($only !== '') $games = array_values(array_filter($games, fn($g) => $g['id'] === $only));

rs_head('Icons');
?>
<section class="head-row">
  <div>
    <h1>Icons</h1>
    <p class="muted">Each listing's icon next to the one inside its newest release on GitHub, asked fresh. New icons from other developers come ticked. Your own listings (RecompStore) are left unticked, so the icons you made stay unless you tick them. Then press Publish.</p>
  </div>
  <div class="actions"><?php if ($only !== ''): ?><a class="btn" href="icons.php">Check every listing</a><?php endif; ?><button type="button" class="btn" data-ic-again>Check again</button></div>
</section>
<form method="post" class="card ic-card" data-ic>
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <p class="muted small-note" data-ic-status>Asking GitHub…</p>
  <ul class="ic-list">
  <?php foreach ($games as $g): ?>
    <li data-ic-row="<?= h($g['id']) ?>" data-own="<?= $g['kind'] === 'original' ? '1' : '0' ?>" class="wait">
      <label class="ic-pick"><input type="checkbox" name="ids[]" value="<?= h($g['id']) ?>" disabled></label>
      <img class="ic-now" src="<?= h((string)$g['icon']) ?>" alt="Now" loading="lazy" <?= $g['icon'] ? '' : 'hidden' ?>>
      <span class="ic-arrow" aria-hidden="true">→</span>
      <img class="ic-new" alt="In its release" hidden>
      <span class="ic-text"><a href="edit.php?id=<?= h(rawurlencode($g['id'])) ?>"><strong><?= h($g['name'] ?: $g['id']) ?></strong></a><small><?= h($g['id']) ?> · <?= h((string)$g['repo']) ?><?= $g['hidden'] ? ' · hidden' : '' ?><?= !empty($g['testers']) ? ' · testers only' : '' ?></small><small class="ic-why" data-ic-why>Waiting…</small></span>
    </li>
  <?php endforeach; ?>
  </ul>
  <div class="actions"><button class="btn primary" data-ic-take disabled>Take the ticked icons</button><button type="button" class="link" data-ic-all>Tick every new one</button><button type="button" class="link" data-ic-none>Untick all</button></div>
</form>
<?php rs_foot(['assets/icons.js']); ?>
