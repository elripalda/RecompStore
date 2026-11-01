<?php
// RecompLauncher website (3.1g): a beta invite's own link.
//   GET t=<token>        -> {ok, wave, name, ready, version}   (the Get page asks this to show the download)
//   GET t=<token>&dl=1   -> the current Windows build (counted), or back to the Get page if there isn't one yet
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/betawave.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$t = strtolower((string)($_GET['t'] ?? ''));
$inv = null;
try { $inv = rs_lbw_check($t); } catch (Throwable $e) { error_log('beta-access: ' . $e->getMessage()); }
$build = null;
try { $build = rs_lb_current('windows'); } catch (Throwable $e) { error_log('beta-access: ' . $e->getMessage()); }
$url = trim((string)rs_setting('lbw_download_url', ''));
$ready = $build !== null || ($url !== '' && preg_match('#^https://#i', $url));

if (!empty($_GET['dl'])) {
    if (!$inv) { header('Location: https://recomplauncher.com/get', true, 302); exit; }
    if (!$ready) { header('Location: https://recomplauncher.com/get?beta=' . $t, true, 302); exit; }
    if (!$inv['test']) rs_db()->prepare('UPDATE launcher_beta_wave SET downloads = downloads + 1, opened_at = COALESCE(opened_at, UTC_TIMESTAMP()) WHERE beta_id = ?')->execute([$inv['beta_id']]);
    if ($build) rs_lb_send($build, !$inv['test']);
    header('Location: ' . $url, true, 302);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
if (!$inv) { echo json_encode(['ok' => false]); exit; }
if (!$inv['test']) rs_db()->prepare('UPDATE launcher_beta_wave SET opened_at = COALESCE(opened_at, UTC_TIMESTAMP()) WHERE beta_id = ?')->execute([$inv['beta_id']]);
echo json_encode(['ok' => true, 'wave' => $inv['wave'], 'name' => rs_lbw_name($inv['wave']), 'ready' => (bool)$ready,
    'version' => $build ? (string)$build['version'] : (string)rs_setting('lbw_version', ''),
    'size' => $build ? rs_human_size((int)$build['size']) : '',
    // Shown under the download so people can check they got the real file.
    'sha256' => $build ? strtolower((string)$build['sha256']) : '5ebf452f57cc99abd2507f843a4cfc05dfb3d5ca1a30ec8a72e5e68959a8f81a',
    'github' => 'https://github.com/elripalda/RecompLauncher'], JSON_UNESCAPED_SLASHES);
