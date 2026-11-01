<?php
// RecompStore admin (3.3): Status. How the site is doing right now: errors, the database, who's on,
// storage (cloud saves, logs, files) and today's traffic, on one page. The slow numbers (folder
// sizes) are worked out at most every 30 minutes and kept.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/lstats.php';
require_once __DIR__ . '/inc/rl_cloud.php';
require_once __DIR__ . '/inc/mailq.php';
rs_require_login();
session_write_close();

$db = rs_db();
$one = function (string $sql, array $args = []) use ($db) {
    try { $st = $db->prepare($sql); $st->execute($args); return $st->fetchColumn(); } catch (Throwable $e) { return null; }
};
$all = function (string $sql, array $args = []) use ($db): array {
    try { $st = $db->prepare($sql); $st->execute($args); return $st->fetchAll(); } catch (Throwable $e) { return []; }
};
$mb = fn($b) => $b === null ? '–' : ($b >= 1073741824 ? number_format($b / 1073741824, 2) . ' GB' : ($b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(0, (int)round($b / 1024)) . ' KB'));
$n = fn($v) => $v === null ? '–' : number_format((int)$v);
$priv = dirname(RS_PUBLIC) . '/recompstore-private';

// ---- Errors: what the private log says about the last hour and day ----
$errHour = $errDay = $retryHour = 0;
$byWhere = [];
$recent = [];
$logf = $priv . '/db-errors.log';
if (is_file($logf)) {
    $fh = fopen($logf, 'rb');
    $size = filesize($logf);
    fseek($fh, max(0, $size - 1500000));
    $tail = (string)stream_get_contents($fh);
    fclose($fh);
    $hourAgo = gmdate('Y-m-d\TH:i:s', time() - 3600);
    $dayAgo = gmdate('Y-m-d\TH:i:s', time() - 86400);
    foreach (explode("\n", $tail) as $l) {
        if (strlen($l) < 26 || $l[4] !== '-') continue;
        $t = substr($l, 0, 19);
        if ($t < $dayAgo) continue;
        $retry = str_contains($l, ' db-retry-ok ');
        if (!$retry) $errDay++;
        if ($t >= $hourAgo) {
            if ($retry) { $retryHour++; continue; }
            $errHour++;
            $parts = explode(' ', $l, 5);
            $path = preg_replace('/\?.*$/', '', (string)($parts[3] ?? ''));
            $a = preg_match('/[?&]a=([a-z-]+)/', (string)($parts[3] ?? ''), $m) ? '?a=' . $m[1] : '';
            $k = trim(($parts[2] ?? '') . ' ' . $path . $a);
            $byWhere[$k] = ($byWhere[$k] ?? 0) + 1;
        }
        if (!$retry) $recent[] = $l;
    }
    arsort($byWhere);
    $recent = array_slice($recent, -12);
}

// ---- The database ----
$dbVars = [];
foreach ($all("SHOW VARIABLES WHERE Variable_name IN ('max_user_connections')") as $r) $dbVars[$r['Variable_name']] = $r['Value'];
$dbSize = $one('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()');
$tables = $all('SELECT table_name AS t, table_rows AS r, (data_length + index_length) AS b FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY b DESC LIMIT 8');
$t0 = microtime(true);
$one('SELECT 1');
$dbPing = (int)round((microtime(true) - $t0) * 1000);

// ---- Who's on ----
try { $live = rs_ls_live(); } catch (Throwable $e) { $live = ['open' => null, 'playing' => null]; }
$consoles = $one('SELECT COUNT(*) FROM console_days WHERE day = UTC_DATE()');
$visitors = $one('SELECT COUNT(*) FROM site_visitors WHERE day = UTC_DATE()');
$views = $one('SELECT COALESCE(SUM(views), 0) FROM site_pages WHERE day = UTC_DATE()');
$launchersToday = $one('SELECT COUNT(*) FROM lstat_days WHERE day = UTC_DATE()');
$installs = $one("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE kind = 'install' AND day = UTC_DATE()");
$betaDl = $one('SELECT COUNT(*) FROM beta_downloads WHERE day = UTC_DATE()');
$newLaunchers = $one('SELECT COUNT(*) FROM lstat_installs WHERE first_seen >= UTC_DATE()');
$accountsNew = $one('SELECT COUNT(*) FROM accounts WHERE created >= UTC_DATE()');
try { $byOs = rs_ls_by_os(); } catch (Throwable $e) { $byOs = null; }

// ---- Cloud saves ----
rs_rl_tables();
$cs = $all('SELECT COUNT(*) copies, COUNT(DISTINCT account_id) people, COALESCE(SUM(size), 0) bytes FROM cloud_saves')[0] ?? ['copies' => null, 'people' => null, 'bytes' => null];
$cs24 = $all('SELECT COUNT(*) n, COALESCE(SUM(size), 0) bytes FROM cloud_saves WHERE created > (UTC_TIMESTAMP() - INTERVAL 1 DAY)')[0] ?? ['n' => null, 'bytes' => null];
$csBig = $all('SELECT c.account_id, a.username, COUNT(*) copies, COUNT(DISTINCT c.game) games, SUM(c.size) bytes FROM cloud_saves c LEFT JOIN accounts a ON a.id = c.account_id GROUP BY c.account_id ORDER BY bytes DESC LIMIT 8');
$csGames = $all('SELECT game, COUNT(DISTINCT account_id) people, SUM(size) bytes, MAX(size) biggest FROM cloud_saves GROUP BY game ORDER BY bytes DESC LIMIT 8');
$logsBytes = $one('SELECT COALESCE(SUM(size), 0) FROM inbox_files');

// ---- Folder sizes on the server (kept 30 minutes) ----
$sizesFile = $priv . '/status-sizes.json';
$sizes = is_file($sizesFile) ? json_decode((string)file_get_contents($sizesFile), true) : null;
if (!is_array($sizes) || time() - (int)($sizes['at'] ?? 0) > 1800 || isset($_GET['measure'])) {
    @set_time_limit(60);
    $du = function (string $dir): ?int {
        if (!is_dir($dir)) return null;
        $t = 0;
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $t += $f->getSize();
        } catch (Throwable $e) {}
        return $t;
    };
    $sizes = ['at' => time(), 'dirs' => [
        'Cloud saves' => $du($priv . '/cloud-saves'),
        'Store pictures (media)' => $du(RS_PUBLIC . '/media'),
        'Beta builds' => $du(rs_beta_dir()),
        'Report pictures' => $du(rs_report_dir()),
        'Everything private' => $du($priv),
    ]];
    @file_put_contents($sizesFile, json_encode($sizes));
}
$diskFree = @disk_free_space(dirname(RS_PUBLIC));
$diskTotal = @disk_total_space(dirname(RS_PUBLIC));

// ---- Mail ----
try { $mq = rs_mailq_stats(); } catch (Throwable $e) { $mq = ['waiting' => null, 'today' => null, 'failed' => null, 'error' => '', 'paused' => false]; }

$tile = fn(string $label, string $value, string $small = '', string $tone = '') => '<div class="kpi' . ($tone !== '' ? ' st-' . $tone : '') . '"><span>' . h($label) . '</span><strong>' . h($value) . '</strong>' . ($small !== '' ? '<small>' . $small . '</small>' : '') . '</div>';
$errTone = $errHour === 0 ? 'ok' : ($errHour < 30 ? 'warn' : 'bad');

rs_head('Status', true, ['assets/analytics.css', 'assets/status.css']);
?>
<section class="head-row">
  <div><h1>Status</h1><p class="muted">How the site is doing right now. Times are UTC (<?= h(gmdate('g:i a')) ?>). Folder sizes were measured <?= h(rs_date('g:i a', (int)$sizes['at'])) ?>.</p></div>
  <div class="actions"><a class="btn" href="status.php">Refresh</a><a class="btn" href="status.php?measure=1">Measure folders now</a></div>
</section>

<h2 class="st-h">Right now</h2>
<div class="kpis st-4">
  <?= $tile('Errors in the last hour', $n($errHour), $n($errDay) . ' in the last 24 hours' . ($retryHour ? ' · ' . $n($retryHour) . ' got in on a retry' : ''), $errTone) ?>
  <?= $tile('Database answer time', $dbPing . ' ms', 'Limit: ' . h((string)($dbVars['max_user_connections'] ?? '?')) . ' connections at once', $dbPing < 50 ? 'ok' : ($dbPing < 300 ? 'warn' : 'bad')) ?>
  <?= $tile('Launchers open', $n($live['open']), $n($live['playing']) . ' playing a game') ?>
  <?= $tile('Mail waiting', $n($mq['waiting']), $n($mq['today']) . ' sent in 24 hours' . ($mq['paused'] ? ' · paused by the mail server' : '') . ($mq['failed'] ? ' · ' . $n($mq['failed']) . ' gave up' : ''), $mq['paused'] || $mq['failed'] ? 'warn' : '') ?>
</div>

<h2 class="st-h">Today</h2>
<div class="kpis st-4">
  <?= $tile('Website visitors', $n($visitors), $n($views) . ' page views') ?>
  <?= $tile('PS5 consoles', $n($consoles), $n($installs) . ' app installs · ' . $n($betaDl) . ' beta downloads') ?>
  <?= $tile('PCs with RecompLauncher', $n($launchersToday), $n($newLaunchers) . ' new today') ?>
  <?= $tile('New accounts', $n($accountsNew)) ?>
</div>

<?php if ($byOs): ?>
<h2 class="st-h">RecompLauncher by system</h2>
<div class="kpis st-4">
  <?= $tile('Open now on Windows', $n($byOs['windows']['open']), $n($byOs['windows']['today']) . ' today · ' . $n($byOs['windows']['week']) . ' this week') ?>
  <?= $tile('Open now on Linux', $n($byOs['linux']['open']), $n($byOs['linux']['today']) . ' today · ' . $n($byOs['linux']['week']) . ' this week') ?>
  <?= $tile('Windows installs', $n($byOs['windows']['ever']), 'PCs that have ever checked in') ?>
  <?= $tile('Linux installs', $n($byOs['linux']['ever']), 'PCs that have ever checked in' . ($byOs['macos']['ever'] || $byOs['other']['ever'] ? ' · ' . $n($byOs['macos']['ever'] + $byOs['other']['ever']) . ' other' : '')) ?>
</div>

<?php endif; ?>
<h2 class="st-h">Storage</h2>
<div class="kpis st-4">
  <?= $tile('Cloud saves', $mb($cs['bytes']), $n($cs['copies']) . ' copies from ' . $n($cs['people']) . ' people') ?>
  <?= $tile('Cloud saves, last 24 hours', $mb($cs24['bytes']), $n($cs24['n']) . ' uploads kept') ?>
  <?= $tile('Logs in the Inbox', $mb($logsBytes), 'Stored in the database') ?>
  <?= $tile('Database', $mb($dbSize), 'All tables together') ?>
  <?php $i = 0; foreach ($sizes['dirs'] as $label => $bytes): if ($label === 'Cloud saves') continue; if (++$i > 3) break; ?>
  <?= $tile($label, $mb($bytes), 'On the server') ?>
  <?php endforeach; ?>
  <?= $tile('Server disk', $diskTotal ? $mb($diskTotal - $diskFree) : '–', $diskTotal ? $mb($diskFree) . ' free of ' . $mb($diskTotal) : 'The host doesn\'t say') ?>
</div>

<div class="st-grid">
  <section class="card">
    <h2>Errors in the last hour, by where</h2>
    <?php if (!$byWhere): ?><p class="muted">None. Everything got through.</p><?php else: ?>
    <table class="st-table"><thead><tr><th>Where</th><th class="num">Errors</th></tr></thead><tbody>
      <?php foreach (array_slice($byWhere, 0, 10, true) as $k => $c): ?><tr><td><code><?= h($k) ?></code></td><td class="num"><?= $n($c) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Biggest database tables</h2>
    <table class="st-table"><thead><tr><th>Table</th><th class="num">Rows</th><th class="num">Size</th></tr></thead><tbody>
      <?php foreach ($tables as $t): ?><tr><td><code><?= h((string)$t['t']) ?></code></td><td class="num"><?= $n($t['r']) ?></td><td class="num"><?= $mb((int)$t['b']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </section>
  <section class="card">
    <h2>Most cloud save space, by person</h2>
    <?php if (!$csBig): ?><p class="muted">No cloud saves yet.</p><?php else: ?>
    <table class="st-table"><thead><tr><th>Account</th><th class="num">Games</th><th class="num">Copies</th><th class="num">Space</th></tr></thead><tbody>
      <?php foreach ($csBig as $r): ?><tr><td><?= h((string)($r['username'] ?: 'Account ' . $r['account_id'])) ?></td><td class="num"><?= $n($r['games']) ?></td><td class="num"><?= $n($r['copies']) ?></td><td class="num"><?= $mb((int)$r['bytes']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Most cloud save space, by game</h2>
    <?php if (!$csGames): ?><p class="muted">No cloud saves yet.</p><?php else: ?>
    <table class="st-table"><thead><tr><th>Game</th><th class="num">People</th><th class="num">Biggest copy</th><th class="num">Space</th></tr></thead><tbody>
      <?php foreach ($csGames as $r): ?><tr><td><?= h(rs_rl_game_name((string)$r['game'])) ?></td><td class="num"><?= $n($r['people']) ?></td><td class="num"><?= $mb((int)$r['biggest']) ?></td><td class="num"><?= $mb((int)$r['bytes']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Latest errors</h2>
  <?php if (!$recent): ?><p class="muted">None in the last 24 hours.</p><?php else: ?>
  <pre class="st-log"><?php foreach (array_reverse($recent) as $l) echo h(mb_substr($l, 0, 220)), "\n"; ?></pre>
  <?php endif; ?>
  <p class="muted small-note">The full list is on <a href="diag-db.php">the database check</a>.</p>
</section>
<?php
rs_foot();
