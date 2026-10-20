<?php
// Recomp Store admin (2.0.1): a beta game's zip copied from the beta folder to Cloudflare R2,
// then checked there (its size and MD5, and a piece read back through a tester's kind of link).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();

$id = (string)($_GET['id'] ?? '');
$back = 'edit.php?id=' . urlencode($id) . '#beta';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
rs_check_csrf();
$g = rs_game($id);
$c = rs_r2();
if (!$g || !$g['beta'] || $g['beta_file'] === '') { rs_flash('This game has no beta zip yet.', 'err'); header('Location: ' . $back); exit; }
if (!$c) { rs_flash('Set up Cloudflare R2 in Settings first.', 'err'); header('Location: ' . $back); exit; }
$file = rs_beta_dir() . '/' . basename((string)$g['beta_file']);
if (!is_file($file)) { rs_flash('The beta zip ' . $g['beta_file'] . ' isn\'t in the beta folder.', 'err'); header('Location: ' . $back); exit; }

@set_time_limit(0);
ignore_user_abort(true);
$size = (int)filesize($file);
$md5 = md5_file($file);
$key = $g['id'] . '/' . basename((string)$g['beta_file']);
$error = '';
$t0 = microtime(true);
if (!rs_r2_put_file($c, $key, $file, $error)) { rs_flash('The zip didn\'t go up to R2. ' . $error, 'err'); header('Location: ' . $back); exit; }
$secs = microtime(true) - $t0;

// What R2 has now: the whole zip (its size, and the MD5 it worked out), and its first and last
// bytes through a link like a tester's.
$h = rs_r2_head($c, $key);
$link = rs_r2_presign($c, 'GET', $key, 600);
$fh = fopen($file, 'rb');
$headLocal = (string)fread($fh, 4096);
fseek($fh, max(0, $size - 4096));
$tailLocal = (string)fread($fh, 4096);
fclose($fh);
$a = rs_http($link, ['range' => [0, min($size, 4096) - 1], 'timeout' => 30, 'max' => 8192]);
$b = rs_http($link, ['range' => [max(0, $size - 4096), $size - 1], 'timeout' => 30, 'max' => 8192]);
$problems = [];
if ($h['status'] !== 200 || $h['size'] !== $size) $problems[] = 'R2 has ' . ($h['status'] === 200 ? $h['size'] . ' bytes' : 'nothing (HTTP ' . $h['status'] . ')') . ' where the zip is ' . $size;
if ($h['etag'] !== '' && !str_contains($h['etag'], '-') && $h['etag'] !== $md5) $problems[] = 'its MD5 on R2 isn\'t the zip\'s';
if ($a['status'] !== 206 || $a['body'] !== $headLocal) $problems[] = 'a tester\'s link to its start answered HTTP ' . $a['status'];
if ($b['status'] !== 206 || $b['body'] !== $tailLocal) $problems[] = 'a tester\'s link to its end answered HTTP ' . $b['status'];
if ($problems) { rs_flash('The zip went up, but the check found: ' . implode('; ', $problems) . '. Testers keep downloading it from this site.', 'err'); header('Location: ' . $back); exit; }

rs_set_setting('r2_beta_' . $g['id'], json_encode(['file' => $g['beta_file'], 'key' => $key, 'size' => $size, 'md5' => $md5, 'at' => time()]));
rs_flash('Copied to Cloudflare R2 (' . rs_human_size($size) . ' in ' . max(1, (int)round($secs)) . ' s) and checked. Testers download ' . $g['beta_file'] . ' from R2 from their next refresh.');
header('Location: ' . $back);
