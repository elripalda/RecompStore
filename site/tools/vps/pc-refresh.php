<?php
// (3.5) Keeps the PC recomps' GitHub numbers (version, size, downloads, stars) fresh in the background,
// so Publish PC doesn't have to ask GitHub about every recomp while you wait. Run by cron as www-data:
//   */30 * * * * php /var/www/recompstore/pc-refresh.php >> /var/log/recompstore-pc-refresh.log 2>&1
// It only updates the saved recomps; the site changes when Publish PC is pressed, as before.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$lock = fopen(sys_get_temp_dir() . '/recompstore-pc-refresh.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // the last run is still going
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/pc.php';
$t = microtime(true);
$n = rs_pc_refresh_github(1500, in_array('--all', $argv, true) ? 0 : 1800); // --all: every recomp now
echo gmdate('c') . ' checked ' . $n . ' in ' . round(microtime(true) - $t) . "s\n";
// (4.7) The Home banner's automatic slots move on with what's new and popular, without a Publish.
try { if (rs_pc_banner_patch()) echo gmdate('c') . " banner updated\n"; } catch (Throwable $e) { echo 'banner: ', $e->getMessage(), "\n"; }
// (4.6) Coming soon watch: once a day, releases, betas and new repos for the recomps on Coming soon (Dashboard).
try { $sw = rs_pc_soon_watch(); if ($sw) echo gmdate('c') . ' coming soon: ' . $sw . " new\n"; } catch (Throwable $e) { echo 'coming soon: ', $e->getMessage(), "\n"; }
// SDK support: look for a recomplauncher.json (and the README badge) in the recomps not checked this week, most downloaded
// first, for up to four minutes a run, so the admin table fills in without anyone keeping it open.
$t = microtime(true);
$s = 0;
try {
    rs_cache_tables();
    $seen = [];
    // Checked this week, and with the README badge looked at (checks from before that need doing again).
    foreach (rs_db()->query("SELECT k, v FROM kv_cache WHERE k LIKE 'sdk:%' AND at > NOW() - INTERVAL 7 DAY") as $r)
        if (array_key_exists('badge', (array)json_decode((string)$r['v'], true))) $seen[substr((string)$r['k'], 4)] = true;
    $todo = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== '' && !isset($seen[$a['id']])));
    usort($todo, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
    foreach ($todo as $a) {
        if (microtime(true) - $t > 240) break;
        if (!rs_pc_sdk_due($a)) continue; // the admin page checked it meanwhile
        try { rs_pc_sdk_check($a, true); ++$s; } catch (Throwable $e) { /* next run */ }
    }
    echo gmdate('c') . ' sdk checked ' . $s . ' of ' . count($todo) . ' in ' . round(microtime(true) - $t) . "s\n";
} catch (Throwable $e) { echo gmdate('c') . ' sdk: ' . $e->getMessage() . "\n"; }
// (4.8) Sent SDK pull requests that later conflict are rebuilt on the developer's newest code.
try {
    require_once 'admin/inc/sdk.php';
    require_once 'admin/inc/prq.php';
    $fx = rs_prq_fix_conflicts(60);
    if ($fx['fixed'] || $fx['flagged']) echo gmdate('c') . ' sdk prs: looked at ' . $fx['looked'] . ', re-drafted ' . $fx['fixed'] . ', need a look ' . $fx['flagged'] . "\n";
    // (4.9) Pull requests waiting their turn go out, at most 5 an hour.
    [$ok, $bad] = rs_prq_send_due();
    if ($ok || $bad) echo gmdate('c') . ' sdk prs: sent ' . $ok . ' from the line' . ($bad ? ', ' . $bad . ' back to drafts' : '') . "\n";
} catch (Throwable $e) { echo gmdate('c') . ' sdk prs: ' . $e->getMessage() . "\n"; }
// (4.9) Forgejo listings move up to the next launcher once it's published on GitHub (not while it's a
// draft): forgejo_launcher_min_next, e.g. 0.90.0-beta.44, the first launcher with their Easy Setup.
try {
    $next = trim((string)rs_setting('forgejo_launcher_min_next', ''));
    if ($next !== '') {
        require_once 'admin/inc/betawave.php';
        $g = rs_lb_gh_latest('windows', 600);
        if ($g && version_compare((string)$g['version'], $next, '>=')) {
            rs_set_setting('forgejo_launcher_min', $next);
            rs_set_setting('forgejo_launcher_min_next', '');
            rs_log('pc', 'forgejo', 'Forgejo listings now need launcher ' . $next . ' (it is out)');
            rs_pc_publish();
            echo gmdate('c') . ' forgejo min -> ' . $next . "\n";
        }
    }
} catch (Throwable $e) { echo gmdate('c') . ' forgejo min: ' . $e->getMessage() . "\n"; }
// (4.9) Instant Gaming cards follow the site: price, discount and stock read again every 3 hours.
try {
    require_once 'admin/inc/ig.php';
    [$ok, $bad] = rs_ig_refresh(3 * 3600, 40);
    if ($ok || $bad) echo gmdate('c') . ' instant gaming: read ' . $ok . ($bad ? ', ' . $bad . ' failed' : '') . "\n";
} catch (Throwable $e) { echo gmdate('c') . ' instant gaming: ' . $e->getMessage() . "\n"; }
// (4.6) Docs parity: recomplauncher.com/schema/recomplauncher.json follows the launcher's own schema
// (docs/recomplauncher.schema.json on its main branch), so what editors check against is what the
// launcher accepts. Changed only when it really changed, and only to a schema that looks like ours.
try {
    $r = rs_http(rs_gh_api() . '/repos/elripalda/RecompLauncher/contents/docs/recomplauncher.schema.json?ref=main', ['github' => true, 'timeout' => 10, 'max' => 3000000]);
    $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
    $txt = is_array($j) ? (string)base64_decode((string)($j['content'] ?? ''), true) : '';
    $sch = $txt !== '' ? json_decode($txt, true) : null;
    $f = RS_PUBLIC . '/schema/recomplauncher.json';
    if (is_array($sch) && ($sch['$id'] ?? '') === 'https://recomplauncher.com/schema/recomplauncher.json' && isset($sch['properties']['format'], $sch['properties']['start'])
        && (!is_file($f) || hash_file('sha256', $f) !== hash('sha256', $txt))) {
        if (@file_put_contents($f . '.new', $txt) !== false && @rename($f . '.new', $f)) {
            rs_log('sdk', 'schema', 'synced from the launcher (' . substr((string)($j['sha'] ?? ''), 0, 7) . ')');
            echo gmdate('c') . " schema synced from the launcher\n";
        } else echo gmdate('c') . " schema: couldn't write it\n";
    }
} catch (Throwable $e) { echo gmdate('c') . ' schema: ' . $e->getMessage() . "\n"; }
