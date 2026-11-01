<?php
// RecompLauncher (3.1g): a download link made on the Launcher beta page.
//   GET k=<token>        -> a small page with the download (or why the link stopped working)
//   GET k=<token>&dl=1   -> the current Windows build, counted against the link
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/betawave.php';
require_once dirname(__DIR__) . '/admin/inc/lshare.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$tok = strtolower((string)($_GET['k'] ?? ''));
$s = rs_lsh_get($tok);
$b = rs_lb_current('windows');
$dead = !$s ? 'This link doesn\'t work.' : rs_lsh_dead($s);
if ($dead === '' && !$b) $dead = 'There\'s nothing to download yet. Try again soon.';

if ($dead === '' && isset($_GET['dl'])) {
    // Counted first, so a link limited to one download can't be shared around in the moment.
    $st = rs_db()->prepare('UPDATE launcher_share SET downloads = downloads + 1 WHERE id = ? AND off = 0 AND expires > UTC_TIMESTAMP() AND (max_dl = 0 OR downloads < max_dl)');
    $st->execute([(int)$s['id']]);
    if ($st->rowCount() === 1) rs_lb_send($b, true);
    $dead = 'This link has been used up.';
}
if ($s && $dead === '') rs_db()->prepare('UPDATE launcher_share SET opens = opens + 1 WHERE id = ?')->execute([(int)$s['id']]);

header('Content-Type: text/html; charset=utf-8');
if ($dead !== '') http_response_code(410);
$hx = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$leftDl = $s && (int)$s['max_dl'] > 0 ? (int)$s['max_dl'] - (int)$s['downloads'] : 0;
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>RecompLauncher</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:#05070d;color:#e8eef7;font:400 16px/1.55 Figtree,system-ui,sans-serif;
  background-image:radial-gradient(60% 50% at 20% 0%,rgba(52,242,232,.16),transparent 70%),radial-gradient(50% 45% at 90% 100%,rgba(55,123,182,.22),transparent 70%)}
.card{width:100%;max-width:460px;padding:32px 28px;border-radius:22px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);backdrop-filter:blur(14px);text-align:center}
.kick{font:600 12px Figtree;letter-spacing:.14em;text-transform:uppercase;color:#7f8ca0}
h1{margin:8px 0 6px;font:700 30px/1.15 Sora,sans-serif;background:linear-gradient(90deg,#34F2E8,#7aa8ff);-webkit-background-clip:text;background-clip:text;color:transparent}
p{margin:0;color:#aab6c6}
.btn{display:block;margin:24px 0 0;padding:15px 18px;border-radius:14px;background:linear-gradient(90deg,#34F2E8,#377BB6);color:#041017;font:600 16px Figtree;text-decoration:none}
.btn:hover{filter:brightness(1.08)}
.sha{margin:16px 0 0;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#7f8ca0;overflow-wrap:anywhere}
.note{margin-top:12px;font-size:13px;color:#7f8ca0}
.meta{margin-top:18px;padding-top:16px;border-top:1px solid rgba(255,255,255,.08);font-size:13px;color:#7f8ca0}
</style></head><body>
<main class="card">
<?php if ($dead !== ''): ?>
  <div class="kick">RecompLauncher</div>
  <h1>Link closed</h1>
  <p><?= $hx($dead) ?> Ask whoever sent it for a new one, or sign up for the beta at recomplauncher.com.</p>
  <a class="btn" href="https://recomplauncher.com/get">Go to recomplauncher.com</a>
<?php else: ?>
  <div class="kick">RecompLauncher<?= (string)$s['label'] !== '' ? ' · For ' . $hx($s['label']) : '' ?></div>
  <h1>Early access</h1>
  <p>The RecompLauncher beta for Windows 10 and 11, 64-bit.</p>
  <a class="btn" href="?k=<?= $hx($tok) ?>&amp;dl=1">Download for Windows · <?= $hx($b['version']) ?></a>
  <div class="sha">SHA-256: <?= $hx($b['sha256']) ?></div>
  <div class="note">Windows may warn you because it's a new app. Click More info, then Run anyway.</div>
  <div class="meta"><?= $hx(rs_human_size((int)$b['size'])) ?> · This link stops working in <?= $hx(rs_lsh_left((int)$s['secs'])) ?><?= $leftDl > 0 ? ' or after ' . $leftDl . ($leftDl === 1 ? ' more download' : ' more downloads') : '' ?>.</div>
<?php endif; ?>
</main>
</body></html>
