<?php
// (3.5) Read-only check: which PC recomps' newest GitHub release has no file matching what the
// catalog tells the launcher to download (per system). Uses the cached GitHub answers.
//   sudo -u www-data php /var/www/recompstore/pc-asset-check.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/pc.php';
$bad = [];
foreach (rs_pc_apps(false) as $a) {
    if ($a['repo'] === '' || !empty($a['soon'])) continue;
    $rel = rs_gh_releases($a['repo'], 86400);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    $latest = null;
    foreach ($list as $r) if (empty($r['draft']) && empty($r['prerelease'])) { $latest = $r; break; }
    if (!$latest && $list) $latest = $list[0];
    if (!$latest) continue;
    $names = array_map(fn($x) => (string)$x['name'], (array)$latest['assets']);
    foreach (['win' => 'asset_win', 'linux' => 'asset_linux', 'mac' => 'asset_mac'] as $sys => $k) {
        $p = trim((string)$a[$k]);
        if ($p === '') continue;
        $hit = '';
        foreach ($names as $n) if (rs_pattern_matches($p, $n)) { $hit = $n; break; }
        $why = $hit === '' ? 'nomatch' : (preg_match('/\.(rar)$/i', $hit) ? 'rar' : '');
        if ($why === '') continue;
        $older = '';
        if ($why === 'nomatch') foreach ($list as $r) { foreach ((array)$r['assets'] as $x) if (rs_pattern_matches($p, (string)$x['name'])) { $older = (string)$r['tag_name']; break 2; } }
        $bad[] = sprintf('%-4s %-6s %-40s new=%s older=%s has=%s', $sys, $why, substr($a['id'], 0, 40), $latest['tag_name'], $older ?: '-', substr(implode(',', $names), 0, 70));
    }
}
echo count($bad) . " problems\n" . implode("\n", $bad) . "\n";
