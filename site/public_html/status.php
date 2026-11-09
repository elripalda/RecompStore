<?php
// (4.3) recomplauncher.com/status and recompstore.com/status: is it us? Each part of the service, checked
// live (kept a minute, cloud storage five), plus a note from the admin (Settings, Status page) when
// something's going on. Players and helpers in Discord look here before guessing.
//   GET /status             the page
//   GET /status?json=1      the same as JSON: {checked, note, level, parts: [{id, name, state, says}]}
declare(strict_types=1);
require __DIR__ . '/admin/inc/bootstrap.php';

$launcherSite = (bool)preg_match('/(^|\.)recomplauncher\./i', (string)($_SERVER['HTTP_HOST'] ?? ''));

function rs_status_parts(): array {
    $parts = [];
    $add = function (string $id, string $name, string $state, string $says) use (&$parts) { $parts[] = ['id' => $id, 'name' => $name, 'state' => $state, 'says' => $says]; };

    // The website and its database (sign-in, accounts, reports).
    $db = true;
    try { rs_db()->query('SELECT 1')->fetch(); } catch (Throwable $e) { $db = false; }
    $add('site', 'Website', 'ok', 'Answering normally.');
    $add('accounts', 'Sign-in and accounts', $db ? 'ok' : 'down', $db ? 'Signing in and your account work normally.' : 'The database isn\'t answering. Signing in, cloud saves and reports wait until it\'s back.');

    // The game list the launcher and the site read.
    $cat = defined('RS_PUBLIC') ? RS_PUBLIC . '/pc-catalog.json' : '';
    $age = ($cat !== '' && is_file($cat)) ? time() - (int)filemtime($cat) : -1;
    $add('catalog', 'Game list', $age >= 0 ? 'ok' : 'down', $age >= 0 ? 'Last updated ' . rs_status_ago($age) . '.' : 'The game list can\'t be read right now.');

    // Cloud saves: paused on purpose, or the storage itself (a tiny file read back, every five minutes).
    if (rs_setting('rl_saves_paused', '0') === '1') {
        $add('cloud', 'Cloud saves', 'warn', 'Paused for now while we work on them. Saves on your PC are safe, and copies go up once they\'re back.');
    } elseif (!$db) {
        $add('cloud', 'Cloud saves', 'down', 'Waiting on the database.');
    } else {
        $b2 = rs_status_storage();
        $add('cloud', 'Cloud saves', $b2 === true ? 'ok' : ($b2 === null ? 'warn' : 'down'),
            $b2 === true ? 'Working. Saves go up after you play and come down when you press Play on another PC.'
            : ($b2 === null ? 'Not set up.' : 'The storage isn\'t answering right now. Saves on your PC are safe; copies go up once it\'s back.'));
    }

    // Release info and downloads: downloads come straight from each project's own page.
    $hosts = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'sourceforge' => 'SourceForge'];
    $slow = [];
    foreach ($hosts as $via => $n) if (function_exists('rs_gh_cooling') && rs_gh_cooling($via)) $slow[] = $n;
    $add('releases', 'Updates and downloads', $slow ? 'warn' : 'ok', $slow
        ? implode(' and ', $slow) . ' asked us to slow down for a few minutes. New versions may show a little late; downloads still work.'
        : 'Working. Downloads come straight from each developer\'s own release.');

    // The launcher's own newest version.
    $ver = '';
    try {
        $r = rs_gh_releases('elripalda/RecompLauncher', 3600);
        $j = $r['status'] === 200 ? (json_decode((string)$r['body'], true) ?: []) : [];
        foreach ($j as $x) if (empty($x['draft'])) { $ver = (string)($x['tag_name'] ?? ''); break; }
    } catch (Throwable $e) { /* below */ }
    $add('launcher', 'RecompLauncher updates', $ver !== '' ? 'ok' : 'warn', $ver !== '' ? 'Newest: ' . $ver . '.' : 'Couldn\'t look up the newest version just now.');
    return $parts;
}

// Cloud storage: true when a tiny file reads back, false when it doesn't, null when it isn't set up.
function rs_status_storage(): ?bool {
    $k = 'status:storage';
    $kv = rs_kv_get($k);
    if ($kv && $kv['age'] < 300) return $kv['v'] === '1' ? true : ($kv['v'] === '-' ? null : false);
    require_once __DIR__ . '/admin/inc/b2.php';
    if (!function_exists('rs_b2_on') || !rs_b2_on()) { rs_kv_put($k, '-'); return null; }
    $ok = false;
    try {
        [$code] = rs_b2_request('GET', 'status/ping.txt');
        if ($code === 404) {
            $f = tempnam(sys_get_temp_dir(), 'rsping');
            file_put_contents($f, 'ok');
            rs_b2_put('status/ping.txt', $f);
            @unlink($f);
            [$code] = rs_b2_request('GET', 'status/ping.txt');
        }
        $ok = $code === 200;
    } catch (Throwable $e) { $ok = false; }
    rs_kv_put($k, $ok ? '1' : '0');
    return $ok;
}

function rs_status_ago(int $s): string {
    if ($s < 90) return 'just now';
    if ($s < 5400) return round($s / 60) . ' minutes ago';
    if ($s < 172800) return round($s / 3600) . ' hours ago';
    return round($s / 86400) . ' days ago';
}

// One minute between full checks, whoever asks.
$cache = rs_kv_get('status:page');
$data = $cache && $cache['age'] < 60 ? json_decode($cache['v'], true) : null;
if (!is_array($data)) {
    $data = ['checked' => time(), 'parts' => rs_status_parts()];
    rs_kv_put('status:page', json_encode($data));
}
$note = trim((string)rs_setting('status_note', ''));
$level = in_array(rs_setting('status_level', 'warn'), ['ok', 'warn', 'down'], true) ? (string)rs_setting('status_level', 'warn') : 'warn';
$worst = 'ok';
// GitHub asking us to slow down for a few minutes is normal: it shows on its own row only.
foreach ($data['parts'] as $p) if ($p['id'] !== 'releases' && ($p['state'] === 'down' || ($p['state'] === 'warn' && $worst === 'ok'))) $worst = $p['state'];
if ($note !== '' && ($level === 'down' || ($level === 'warn' && $worst === 'ok'))) $worst = $level;

header('Cache-Control: no-store');
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['checked' => gmdate('c', (int)$data['checked']), 'note' => $note, 'level' => $note !== '' ? $level : '', 'overall' => $worst, 'parts' => $data['parts']], JSON_UNESCAPED_SLASHES);
    exit;
}

$brand = $launcherSite ? 'RecompLauncher' : 'RecompStore';
$home = $launcherSite ? 'https://recomplauncher.com/' : 'https://recompstore.com/';
$head = ['ok' => 'Everything\'s working', 'warn' => 'Some things are slow or paused', 'down' => 'Something\'s down'][$worst];
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$discord = trim((string)rs_setting('discord_url', ''));
if (!preg_match('#^https://#', $discord)) $discord = '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Status · <?= $e($brand) ?></title>
<meta name="description" content="Whether <?= $e($brand) ?>'s website, cloud saves, sign-in and downloads are working right now.">
<meta http-equiv="refresh" content="60">
<link rel="icon" href="/assets/logo-512.png">
<link rel="preload" href="/assets/fonts/figtree-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/archivo-wdth.woff2" as="font" type="font/woff2" crossorigin>
<style>
@font-face{font-family:Figtree;font-weight:400;font-display:swap;src:url(/assets/fonts/figtree-400.woff2) format('woff2')}
@font-face{font-family:Figtree;font-weight:600;font-display:swap;src:url(/assets/fonts/figtree-600.woff2) format('woff2')}
@font-face{font-family:Archivo;font-weight:100 900;font-stretch:62% 125%;font-display:swap;src:url(/assets/fonts/archivo-wdth.woff2) format('woff2')}
:root{--midnight:#0B1220;--frost:#F4F8FC;--cyan:#34F2E8;--muted:#a7b3c4;--line:rgba(244,248,252,.14);--card:rgba(244,248,252,.05);--ok:#34d399;--warn:#fbbf24;--down:#f87171}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:radial-gradient(1200px 600px at 50% -200px,rgba(52,242,232,.10),transparent 70%),var(--midnight);color:var(--frost);font:16px/1.55 Figtree,system-ui,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:40px 20px 64px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:36px}
.brand{display:flex;align-items:center;gap:10px;color:var(--frost);text-decoration:none;font-weight:600}
.brand img{width:32px;height:32px;border-radius:8px}
.top a.back{color:var(--muted);text-decoration:none;font-size:14px}
.top a.back:hover{color:var(--frost)}
h1{font-family:Archivo,sans-serif;font-stretch:108%;font-weight:800;letter-spacing:-.6px;line-height:1.08;font-size:clamp(28px,5vw,40px);margin:0 0 8px;display:flex;align-items:center;gap:14px}
.dot{width:14px;height:14px;border-radius:50%;flex:none;box-shadow:0 0 0 5px rgba(255,255,255,.04)}
.ok .dot,.dot.ok{background:var(--ok)}.warn .dot,.dot.warn{background:var(--warn)}.down .dot,.dot.down{background:var(--down)}
.sub{color:var(--muted);margin:0 0 28px}
.note{border:1px solid var(--line);border-left:4px solid var(--warn);background:var(--card);border-radius:12px;padding:14px 16px;margin:0 0 24px}
.note.down{border-left-color:var(--down)}.note.ok{border-left-color:var(--ok)}
.parts{border:1px solid var(--line);border-radius:16px;overflow:hidden;background:var(--card)}
.part{display:grid;grid-template-columns:1fr auto;gap:4px 16px;padding:16px 18px;border-top:1px solid var(--line)}
.part:first-child{border-top:0}
.part b{font-weight:600}
.part .says{grid-column:1/-1;color:var(--muted);font-size:14px}
.state{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600}
.state .dot{width:10px;height:10px;box-shadow:none}
.foot{color:var(--muted);font-size:13px;margin-top:22px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.foot a{color:var(--cyan);text-decoration:none}
@media (max-width:520px){.wrap{padding:28px 16px 48px}.part{grid-template-columns:1fr}.state{order:-1}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <a class="brand" href="<?= $e($home) ?>"><img src="/assets/logo-512.png" alt="" width="32" height="32"><?= $e($brand) ?></a>
    <a class="back" href="<?= $e($home) ?>">Back to the site</a>
  </div>
  <div class="<?= $e($worst) ?>">
    <h1><span class="dot" aria-hidden="true"></span><?= $e($head) ?></h1>
  </div>
  <p class="sub">Live status of the website, cloud saves, sign-in and downloads.</p>
  <?php if ($note !== ''): ?><div class="note <?= $e($level) ?>"><?= nl2br($e($note)) ?></div><?php endif; ?>
  <div class="parts">
    <?php foreach ($data['parts'] as $p): $st = ['ok' => 'Working', 'warn' => 'Slow or paused', 'down' => 'Down'][$p['state']] ?? 'Working'; ?>
    <div class="part">
      <b><?= $e($p['name']) ?></b>
      <span class="state"><span class="dot <?= $e($p['state']) ?>" aria-hidden="true"></span><?= $e($st) ?></span>
      <span class="says"><?= $e($p['says']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="foot">
    <span>Checked <?= $e(rs_status_ago(time() - (int)$data['checked'])) ?>. This page refreshes every minute.</span>
    <?php if ($discord !== ''): ?><span>Something wrong that isn't here? <a href="<?= $e($discord) ?>">Tell us on Discord</a></span><?php endif; ?>
  </div>
</div>
</body>
</html>
