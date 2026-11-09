<?php
// (4.3) One-off, from the server's shell: recomps whose newest release has a .rar or .7z (Windows) or a
// .dmg, .rar or .7z (Mac) that the catalog left empty, now that RecompLauncher installs those.
//   php admin/inc/cli/archive-fix.php          lists what would change
//   php admin/inc/cli/archive-fix.php --apply  changes it (hidden "no Windows download" ones come back)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$apply = in_array('--apply', $argv, true);
$noWin = '/(no windows download|no windows build|windows download matched)/i';
$out = ['win' => 0, 'mac' => 0, 'back' => 0];
foreach (rs_pc_apps() as $a) {
    if ((string)$a['repo'] === '' || rs_pc_host($a) !== '') continue;
    $needWin = trim((string)$a['asset_win']) === '';
    $needMac = trim((string)$a['asset_mac']) === '';
    if (!$needWin && !$needMac) continue;
    $rel = rs_host_releases('', (string)$a['repo'], 86400);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    $latest = null;
    foreach ($list as $x) if (empty($x['draft']) && empty($x['prerelease'])) { $latest = $x; break; }
    if (!$latest && $list) $latest = $list[0];
    if (!$latest) continue;
    $flags = [];
    $f = rs_pc_assets((array)$latest['assets'], $flags);
    $tag = (string)$latest['tag_name'];
    $ch = [];
    if ($needWin && preg_match('/\.(rar|7z)$/i', $f['win']) && !preg_match('/installer|setup/i', $f['win'])) { $a['asset_win'] = rs_pc_pattern($f['win'], $tag); $ch[] = 'win ' . $a['asset_win']; ++$out['win']; }
    if ($needMac && preg_match('/\.(dmg|rar|7z)$/i', $f['mac']) && rs_pc_mac_why($p = rs_pc_pattern($f['mac'], $tag)) === '') { $a['asset_mac'] = $p; $ch[] = 'mac ' . $p; ++$out['mac']; }
    if (!$ch) continue;
    $back = $a['hidden'] && trim((string)($a['blocked_why'] ?? '')) !== '' && preg_match($noWin, (string)$a['blocked_why']) && $a['asset_win'] !== '';
    if ($back) { $a['hidden'] = false; $a['blocked_why'] = ''; $ch[] = 'listed again'; ++$out['back']; }
    echo $a['id'], ': ', implode(' | ', $ch), "\n";
    if ($apply) { rs_pc_save($a); rs_log('change', 'pc ' . $a['id'], 'archive downloads: ' . implode(' | ', $ch)); }
}
echo ($apply ? 'Changed' : 'Would change') . ": {$out['win']} Windows, {$out['mac']} Mac, {$out['back']} listed again\n";
