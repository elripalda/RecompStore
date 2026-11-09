<?php
// RecompStore admin (3.1g): the RecompLauncher beta in one place. Builds (upload, label, make
// current: the launcher's update check offers the current one), a preview of the beta page as an
// invited player sees it, and the invite emails, sent in waves.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/betawave.php';
require_once __DIR__ . '/inc/lshare.php';
require_once __DIR__ . '/inc/rl_launch.php';
rs_require_login();
rs_lbw_tables();
rs_lb_tables();
$db = rs_db();
// The launcher's own update file follows the current build; refreshed each time this page opens.
if (rs_lb_current('windows')) { try { rs_lb_publish_json(); } catch (Throwable $e) { error_log('version.json: ' . $e->getMessage()); } }

// Your own copy of a build (nothing counted).
if (isset($_GET['dl'])) {
    $b = rs_lb_get((int)$_GET['dl']);
    if (!$b) { rs_flash('That build is gone.', 'err'); header('Location: launcher-beta.php'); exit; }
    rs_lb_send($b, false);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'launcher-beta.php';
    // A wave going out, 40 at a time, or another try at the ones that didn't go (JSON for the page).
    if ($act === 'lbw-step' || $act === 'lbw-retry') {
        header('Content-Type: application/json');
        @set_time_limit(180);
        // (3.1g) Let go of the admin sign-in while this batch emails, so other admin pages don't wait for it.
        session_write_close();
        try {
            if (!rs_mail_ready()) throw new RuntimeException('Set up sending on the Mail page first.');
            [$sent, $failed, $left] = $act === 'lbw-step' ? rs_lbw_step(25) : rs_lbw_retry((int)($_POST['wave'] ?? 0), 25);
            echo json_encode(['sent' => $sent, 'failed' => $failed, 'left' => $left]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    try {
        if ($act === 'gh-check') {
            // (3.6) Ask GitHub now instead of waiting for the next check (every 2 minutes on its own).
            $was = (string)(json_decode((string)@file_get_contents(RS_PUBLIC . '/launcher/version.json'), true)['version'] ?? '');
            $g = rs_lb_gh_latest('windows', 0);
            if (!$g) throw new RuntimeException('GitHub didn\'t answer, or has no release with a Windows installer. Try again in a minute.');
            rs_lb_publish_json();
            $now = (string)(json_decode((string)@file_get_contents(RS_PUBLIC . '/launcher/version.json'), true)['version'] ?? '');
            rs_flash($now !== $was ? 'Found ' . rs_lb_semver_name($now) . ' on GitHub. Launchers that are open were told to update.' : 'Up to date: ' . rs_lb_semver_name($now) . ' is the newest on GitHub.');
            $back = 'launcher-beta.php#github';
        } elseif ($act === 'min-save') {
            // (4.3) The oldest Windows and Linux launcher that may keep running without updating.
            $min = trim((string)($_POST['lb_min'] ?? ''));
            if ($min !== '' && !rs_lb_is_semver($min)) throw new RuntimeException('Write the minimum version like 0.90.0-beta.20, or leave it empty.');
            rs_set_setting('lb_min', $min);
            rs_lb_publish_json();
            rl_push('launcher');
            rs_log('launcher-min', 'windows+linux', $min === '' ? 'none' : $min);
            rs_flash($min === '' ? 'No minimum version now.' : 'Launchers older than ' . rs_lb_semver_name($min) . ' are told they have to update.');
            $back = 'launcher-beta.php#min';
        } elseif ($act === 'mac-save') {
            // (4.2) The Mac build's own settings: whether the Get page offers it, and its optional
            // "min" and "notes" in version.json. Windows and Linux aren't touched.
            $min = trim((string)($_POST['mac_min'] ?? ''));
            if ($min !== '' && !rs_lb_is_semver($min)) throw new RuntimeException('Write the minimum version like 0.90.0-beta.24, or leave it empty.');
            rs_set_setting('lb_mac_show', !empty($_POST['mac_show']) ? '1' : '0');
            rs_set_setting('lb_mac_min', $min);
            rs_set_setting('lb_mac_notes', mb_substr(trim(str_replace("\r", '', (string)($_POST['mac_notes'] ?? ''))), 0, 4000));
            rs_lb_publish_json();
            rs_flash('Mac saved.');
            $back = 'launcher-beta.php#mac';
        } elseif ($act === 'push') {
            // (3.1g) Tell launchers to look now, by hand.
            $kind = (string)($_POST['kind'] ?? '');
            $gid = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($_POST['game'] ?? '')) ?? '';
            if ($kind === 'game' && $gid === '') throw new RuntimeException('Pick the game.');
            $msg = $kind === 'launcher' ? 'launcher' : ($kind === 'catalog' ? 'catalog' : ($kind === 'game' ? 'game ' . $gid : ''));
            if ($msg === '') throw new RuntimeException('Pick what to push.');
            if ($kind === 'game') {
                foreach ((array)(json_decode((string)@file_get_contents(RS_PUBLIC . '/pc-catalog.json'), true)['apps'] ?? []) as $a) {
                    if ((string)($a['id'] ?? '') !== $gid || !rs_repo_ok((string)($a['repo'] ?? ''))) continue;
                    try { $f = rs_gh_releases((string)$a['repo'], 0); if ((int)($f['status'] ?? 0) === 200 && empty($f['stale'])) rl_push_gh_put((string)$a['repo'], (string)$f['body']); } catch (Throwable $e) { /* the copy runs out on its own */ }
                }
            }
            $sent = rl_push($msg);
            rs_log('launcher-push', $msg, $sent ? 'sent' : 'not sent');
            if ($sent) rs_flash('Pushed "' . $msg . '". Launchers that listen look within a few seconds.');
            else rs_flash('That one already went out in the last minute, or ntfy.sh didn\'t take it. Launchers still find it on their next check.', 'err');
            $back .= '#push';
        } elseif ($act === 'share-make') {
            $max = !empty($_POST['limit']) ? (int)($_POST['max'] ?? 0) : 0;
            $l = rs_lsh_make((string)($_POST['label'] ?? ''), (string)($_POST['time'] ?? '24h'), $max);
            rs_log('launcher-share', $l['token'], 'made');
            $_SESSION['lsh_new'] = $l['url'];
            rs_flash('Link made. Copy it below.');
            $back .= '#share';
        } elseif ($act === 'share-off') {
            rs_lsh_tables();
            $db->prepare('UPDATE launcher_share SET off = 1 WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            rs_flash('That link is off. It shows "This link was turned off" now.');
            $back .= '#share';
        } elseif ($act === 'current') {
            rs_lb_make_current((int)($_POST['id'] ?? 0));
            $b = rs_lb_get((int)$_POST['id']);
            rs_log('launcher-build', (string)$b['version'], 'made current');
            rs_flash($b['version'] . ' is the current ' . (RS_LB_PLATFORMS[$b['platform']] ?? '') . ' build. Invite links download it, and the launcher offers it as the update.');
            $back .= '#builds';
        } elseif ($act === 'build-edit') {
            $id = (int)($_POST['id'] ?? 0);
            $v = mb_substr(trim((string)($_POST['version'] ?? '')), 0, 40);
            if ($v === '') throw new RuntimeException('A build needs a version.');
            $db->prepare('UPDATE launcher_builds SET version = ?, notes = ? WHERE id = ?')->execute([$v, mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['notes'] ?? ''))), 0, 4000), $id]);
            rs_flash('Saved.');
            $back .= '#builds';
        } elseif ($act === 'build-remove') {
            $b = rs_lb_get((int)($_POST['id'] ?? 0));
            if ($b && (int)$b['live'] === 1) throw new RuntimeException('That one is current. Make another build current first.');
            if ($b) {
                @unlink(rs_lb_path($b));
                @rmdir(dirname(rs_lb_path($b)));
                $db->prepare('DELETE FROM launcher_builds WHERE id = ?')->execute([(int)$b['id']]);
                rs_log('launcher-build', (string)$b['version'], 'removed');
                rs_flash('Build removed.');
            }
            $back .= '#builds';
        } elseif ($act === 'lbw-start') {
            $wave = (int)($_POST['wave'] ?? 0);
            $size = (int)($_POST['size'] ?? 0);
            if ($wave < 1 || $wave > 99) throw new RuntimeException('Pick a wave.');
            if ($size < 1) throw new RuntimeException('Say how many people get this wave.');
            if (!rs_mail_ready()) throw new RuntimeException('Set up sending on the Mail page first.');
            rs_set_setting('lbw_wave', (string)$wave);
            rs_set_setting('lbw_size', (string)$size);
            rs_log('beta-wave', (string)$wave, 'started, ' . $size . ' people');
            rs_flash(rs_lbw_name($wave) . ' is going out to the next ' . number_format($size) . ' people. Keep this page open.');
            $back = 'launcher-beta.php?lbwgo=1#waves';
        } elseif ($act === 'lbw-test') {
            $to = rs_lbw_test(max(1, (int)($_POST['wave'] ?? 1)), strtolower(trim((string)($_POST['to'] ?? ''))));
            rs_flash('Test sent to ' . $to . '. Its link opens the beta page like a real invite.');
            $back .= '#waves';
        } elseif ($act === 'lbw-link') {
            $u = trim((string)($_POST['url'] ?? ''));
            if ($u !== '' && !preg_match('#^https://\S+$#i', $u)) throw new RuntimeException('The link has to start with https://');
            rs_set_setting('lbw_download_url', $u);
            rs_flash($u !== '' ? 'Saved. Used only while there\'s no current Windows build.' : 'Cleared.');
            $back .= '#builds';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$builds = rs_lb_all();
$cur = rs_lb_current('windows');
$lbw = rs_lbw_stats();
$lbwCur = (int)rs_setting('lbw_wave', '0');
$lbwSize = (int)rs_setting('lbw_size', '0');
$lbwCurN = isset($lbw['waves'][$lbwCur]) ? $lbw['waves'][$lbwCur]['sent'] + $lbw['waves'][$lbwCur]['failed'] : 0;
$lbwLeft = $lbwCur ? max(0, min($lbwSize - $lbwCurN, $lbw['left'])) : 0;
$lbwNext = $lbwLeft > 0 ? $lbwCur : (($lbw['waves'] ? max(array_keys($lbw['waves'])) : 0) + 1);
$invited = array_sum(array_column($lbw['waves'], 'sent'));
$preview = rs_lbw_link(rs_lbw_test_token());
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$r2 = rs_r2() !== null;

rs_head('Launcher beta', true, ['assets/accounts.css']);
?>
<section class="head-row">
  <div>
    <h1>RecompLauncher beta</h1>
    <p class="muted">Builds, the beta page, and the invite emails. The current build is what invite links download and what the launcher offers as an update.</p>
  </div>
</section>

<?php
// (3.6) The launcher's releases on GitHub: what launchers are offered, and downloads per beta.
$ghl = rs_lb_gh_list();
$ghNew = $ghl[0] ?? null;
$ghTotal = array_sum(array_column($ghl, 'downloads'));
$ghWin = array_sum(array_column($ghl, 'windows'));
$ghApp = array_sum(array_column($ghl, 'appimage'));
$ghDeb = array_sum(array_column($ghl, 'deb'));
$ghMac = array_sum(array_column($ghl, 'macos'));
$siteDl = rs_rl_launch_downloads();
$feedNow = json_decode((string)@file_get_contents(RS_PUBLIC . '/launcher/version.json'), true) ?: [];
?>
<section class="card" id="github">
  <div class="gh-head">
    <div>
      <h2>Releases on GitHub</h2>
      <p class="muted small">Launchers update to the newest release on <a href="https://github.com/<?= h(RS_LB_GH_REPO) ?>/releases" target="_blank" rel="noopener">GitHub</a> that has a build for their system (Windows installer, Linux AppImage, Mac .dmg for Apple Silicon). The server checks every 2 minutes on its own; Check now does it right away and tells open launchers.</p>
    </div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="gh-check"><button class="primary">Check GitHub now</button></form>
  </div>
  <div class="lb-stats gh-stats">
    <div class="card"><span class="muted small">Launchers are offered</span><b><?= h(rs_lb_semver_name((string)($feedNow['version'] ?? '')) ?: 'Nothing yet') ?></b><span class="muted small"><?= ($feedNow['source'] ?? '') === 'github' ? 'from GitHub' : 'from this server' ?><?= $ghNew && ($feedNow['version'] ?? '') !== $ghNew['version'] ? ' · GitHub has ' . h(rs_lb_semver_name($ghNew['version'])) . ', press Check now' : '' ?></span></div>
    <div class="card"><span class="muted small">Macs are offered</span><b><?= h(rs_lb_semver_name((string)($feedNow['macos']['version'] ?? '')) ?: 'Nothing yet') ?></b><span class="muted small">Apple Silicon · <?= rs_lb_mac_shown() ? 'on the Get page' : 'hidden on the Get page' ?></span></div>
    <div class="card"><span class="muted small">Windows downloads on GitHub</span><b><?= number_format($ghWin) ?></b><span class="muted small">the installer, every beta (updates included)</span></div>
    <div class="card"><span class="muted small">Linux downloads on GitHub</span><b><?= number_format($ghApp + $ghDeb) ?></b><span class="muted small"><?= number_format($ghApp) ?> AppImage · <?= number_format($ghDeb) ?> .deb</span></div>
    <div class="card"><span class="muted small">Mac downloads on GitHub</span><b><?= number_format($ghMac) ?></b><span class="muted small">the Apple Silicon .dmg, every beta</span></div>
    <div class="card"><span class="muted small">Downloads from the website</span><b><?= number_format($siteDl['total']) ?></b><span class="muted small"><?= number_format($siteDl['today']) ?> today, from the Download button on /get</span></div>
  </div>
  <?php if ($ghl): ?>
  <table class="gh-table">
    <thead><tr><th>Version</th><th>Published</th><th class="num">Windows</th><th class="num">Linux</th><th class="num">Mac</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($ghl, 0, 12) as $i => $r): ?>
      <tr><td><a href="<?= h($r['page']) ?>" target="_blank" rel="noopener"><?= h(rs_lb_semver_name($r['version'])) ?></a><?= $i === 0 ? ' <span class="tag cyan">Newest</span>' : '' ?></td><td class="muted"><?= $r['published'] !== '' ? h(gmdate('M j, g:i a', strtotime($r['published']) + (int)date('Z'))) : '' ?></td><td class="num"><?= number_format($r['windows']) ?></td><td class="num"><?= $r['linux'] ? number_format($r['linux']) . ' <span class="muted small">(' . number_format($r['appimage']) . ' + ' . number_format($r['deb']) . ' .deb)</span>' : '–' ?></td><td class="num"><?= !empty($r['macos']) ? number_format($r['macos']) : '–' ?></td><td class="num"><?= number_format($r['downloads']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="muted">GitHub didn't answer just now. Reload in a minute.</p><?php endif; ?>
</section>

<?php $macNow = is_array($feedNow['macos'] ?? null) ? $feedNow['macos'] : null; ?>
<section class="card" id="min">
  <h2>Minimum version</h2>
  <p class="muted small">Windows and Linux launchers older than this are told they have to update before they keep going. Leave it empty to let every version run. The Mac build has its own, below.</p>
  <form method="post" class="grid2"><?= $csrf ?><input type="hidden" name="action" value="min-save">
    <label>Oldest launcher that may run<input name="lb_min" class="mono" maxlength="40" placeholder="0.90.0-beta.20" value="<?= h((string)rs_setting('lb_min', '')) ?>"><small>Written like 0.90.0-beta.20.</small></label>
    <div class="actions" style="align-self:end"><button class="primary">Save minimum</button></div>
  </form>
</section>

<section class="card" id="mac">
  <h2>Mac (Apple Silicon)</h2>
  <p class="muted small">The newest release on GitHub with an <code>_aarch64.dmg</code>, on its own: publishing a Windows or Linux build never changes it. M1 and newer only, not notarized by Apple. Mac updates are manual, so the launcher opens this link.</p>
  <?php if ($macNow): ?>
  <dl class="mac-facts">
    <div><dt>Version</dt><dd><?= h(rs_lb_semver_name((string)($macNow['version'] ?? ''))) ?> <span class="muted small mono"><?= h((string)($macNow['version'] ?? '')) ?></span></dd></div>
    <div><dt>Size</dt><dd><?= !empty($macNow['size']) ? h(rs_human_size((int)$macNow['size'])) . ' <span class="muted small">(' . number_format((int)$macNow['size']) . ' bytes)</span>' : '–' ?></dd></div>
    <div class="wide"><dt>Download</dt><dd class="mono small"><a href="<?= h((string)$macNow['url']) ?>" target="_blank" rel="noopener"><?= h((string)$macNow['url']) ?></a></dd></div>
    <div class="wide"><dt>SHA-256</dt><dd class="mono small"><?= h((string)($macNow['sha256'] ?? '')) ?: '<span class="muted">GitHub didn\'t give one</span>' ?></dd></div>
  </dl>
  <?php else: ?><p class="muted">No Mac build on GitHub yet. It shows up here once a release has an <code>_aarch64.dmg</code>.</p><?php endif; ?>
  <form method="post" class="grid2"><?= $csrf ?><input type="hidden" name="action" value="mac-save">
    <label>Minimum version <span class="muted">(optional)</span><input name="mac_min" class="mono" maxlength="40" placeholder="0.90.0-beta.24" value="<?= h((string)rs_setting('lb_mac_min', '')) ?>"><small>Mac launchers older than this are told they have to update. Its own, not the Windows one.</small></label>
    <label>Notes <span class="muted">(optional)</span><textarea name="mac_notes" rows="2" maxlength="4000" placeholder="Shown with the Mac update."><?= h((string)rs_setting('lb_mac_notes', '')) ?></textarea></label>
    <label class="check span2"><input type="checkbox" name="mac_show" value="1"<?= rs_lb_mac_shown() ? ' checked' : '' ?>> Show the Mac download on recomplauncher.com/get</label>
    <div class="actions span2"><button class="primary">Save Mac</button></div>
  </form>
</section>

<section class="lb-stats">
  <div class="card"><span class="muted small">Current Windows build</span><b><?= $cur ? h((string)$cur['version']) : 'None yet' ?></b><span class="muted small"><?= $cur ? h(rs_human_size((int)$cur['size'])) . ' · ' . number_format((int)$cur['downloads']) . ' downloads' : 'Upload one below' ?></span></div>
  <div class="card"><span class="muted small">Invited</span><b><?= number_format($invited) ?></b><span class="muted small"><?= count($lbw['waves']) ?> <?= count($lbw['waves']) === 1 ? 'wave' : 'waves' ?> so far</span></div>
  <div class="card"><span class="muted small">Still waiting for an invite</span><b><?= number_format($lbw['left']) ?></b><span class="muted small">on the beta list</span></div>
</section>

<section class="card" id="preview">
  <h2>Preview</h2>
  <p class="muted">See the beta page exactly as an invited player does, download included. Your link works like an invite but isn't counted.</p>
  <div class="actions">
    <a class="btn primary" href="<?= h($preview) ?>" target="_blank" rel="noopener">Open my beta page</a>
    <?php if ($cur): ?><a class="btn" href="launcher-beta.php?dl=<?= (int)$cur['id'] ?>">Download <?= h((string)$cur['version']) ?></a><?php endif; ?>
    <a class="btn" href="https://github.com/elripalda/RecompLauncher" target="_blank" rel="noopener">GitHub</a>
  </div>
</section>

<section class="card" id="builds">
  <h2>Builds</h2>
  <p class="muted">Drop the installer here. Name it with its version; tick "Make it current" to put it out: invite links switch to it, and anyone with the launcher gets it as an update the next time it checks (when it opens, and every so often while it's open).<?= $r2 ? ' It\'s copied to Cloudflare R2, and downloads come from there.' : '' ?></p>
  <form class="lb-up" data-lb-up>
    <label class="lb-drop">Installer<input type="file" name="file" accept=".exe,.msi,.zip,.7z,.AppImage,.dmg,.gz" required><small>.exe, .msi, .zip, .7z, .AppImage, .dmg or .tar.gz</small></label>
    <div class="grid2">
      <label>Version<input name="version" maxlength="40" placeholder="0.5 Beta 1" required></label>
      <label>Platform<select name="platform"><?php foreach (RS_LB_PLATFORMS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
      <label class="span2">What's new<textarea name="notes" rows="3" maxlength="4000" placeholder="Shown in the launcher's update prompt."></textarea></label>
    </div>
    <label class="check"><input type="checkbox" name="current" value="1" checked> Make it current (invite links and updates use it)</label>
    <div class="actions"><button class="primary" type="submit">Upload build</button><span class="small muted" data-lb-status></span></div>
    <div class="lb-bar" hidden><i></i></div>
  </form>
  <?php if ($builds): ?>
  <table class="ac-table" style="margin-top:16px"><thead><tr><th>Version</th><th>Platform</th><th>Size</th><th>Added</th><th>Downloads</th><th></th></tr></thead><tbody>
  <?php foreach ($builds as $b): ?>
    <tr<?= (int)$b['live'] ? '' : ' class="dim"' ?>>
      <td><b><?= h((string)$b['version']) ?></b> <?= (int)$b['live'] ? '<span class="pill cyan">Current</span>' : '' ?><?= (string)$b['notes'] !== '' ? '<br><span class="small muted">' . h(mb_strimwidth((string)$b['notes'], 0, 90, '…')) . '</span>' : '' ?></td>
      <td><?= h(RS_LB_PLATFORMS[$b['platform']] ?? (string)$b['platform']) ?></td>
      <td><?= h(rs_human_size((int)$b['size'])) ?></td>
      <td class="small"><?= h(rs_date('M j, g:i a', strtotime((string)$b['created'] . ' UTC'))) ?></td>
      <td><?= number_format((int)$b['downloads']) ?></td>
      <td class="ac-acts">
        <a class="btn" href="launcher-beta.php?dl=<?= (int)$b['id'] ?>">Download</a>
        <?php if (!(int)$b['live']): ?>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="current"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button>Make current</button></form>
        <form method="post" onsubmit="return confirm('Remove this build for good?')"><?= $csrf ?><input type="hidden" name="action" value="build-remove"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="danger">Remove</button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <details style="margin-top:14px"><summary class="muted">Or use a link instead of an upload</summary>
    <form method="post" class="actions" style="margin-top:10px"><?= $csrf ?><input type="hidden" name="action" value="lbw-link">
      <input name="url" type="url" value="<?= h((string)rs_setting('lbw_download_url', '')) ?>" placeholder="https://github.com/elripalda/RecompLauncher/releases/…" style="flex:1;min-width:260px">
      <button>Save link</button></form>
    <p class="small muted">Invite links use it only while there's no current Windows build.</p>
  </details>
  <p class="small muted">Update check for the launcher: <code><?= h(rs_abs('/api/launcher-update.php')) ?>?platform=windows</code></p>
</section>

<section class="card" id="push">
  <h2>Push to launchers</h2>
  <p class="muted">Tells launchers (0.75 Beta 10 and newer) to look now instead of on their next check. It happens by itself when a new launcher version is published, when a recomp gets a new release, and when recomps are added or taken off. This is for doing it by hand.</p>
  <?php $pushGames = (array)(json_decode((string)@file_get_contents(RS_PUBLIC . '/pc-catalog.json'), true)['apps'] ?? []); usort($pushGames, fn($x, $y) => strnatcasecmp((string)($x['name'] ?? ''), (string)($y['name'] ?? ''))); ?>
  <form method="post" class="lsh-form"><?= $csrf ?><input type="hidden" name="action" value="push">
    <label>What<select name="kind" data-push-kind><option value="launcher">Launcher update</option><option value="game">Game update</option><option value="catalog">Catalog</option></select></label>
    <label>Game<select name="game" data-push-game disabled><option value="">Pick the game</option><?php foreach ($pushGames as $g): if ((string)($g['id'] ?? '') === '') continue; ?><option value="<?= h((string)$g['id']) ?>"><?= h((string)($g['name'] ?? $g['id'])) ?></option><?php endforeach; ?></select></label>
    <div class="actions" style="grid-column:auto"><button class="primary">Push</button></div>
  </form>
</section>

<section class="card" id="share">
  <h2>Share links</h2>
  <p class="muted">A download link for someone who isn't on the beta list. It opens a small page with the current build and stops working when its time runs out, after the number of downloads you set, or when you turn it off.</p>
  <?php $lshNew = (string)($_SESSION['lsh_new'] ?? ''); unset($_SESSION['lsh_new']); if ($lshNew !== ''): ?>
  <div class="lsh-new"><code data-lsh-url><?= h($lshNew) ?></code><button type="button" class="primary" data-lsh-copy>Copy link</button></div>
  <?php endif; ?>
  <form method="post" class="lsh-form"><?= $csrf ?><input type="hidden" name="action" value="share-make">
    <label>Who it's for<input name="label" maxlength="80" placeholder="Optional, like a name or a site"></label>
    <label>Lasts<select name="time"><?php foreach (RS_LS_SHARE_TIMES as $k => [$t]): ?><option value="<?= $k ?>"<?= $k === '24h' ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Downloads<span class="lsh-max"><label class="check"><input type="checkbox" name="limit" value="1" checked> Up to</label><input name="max" type="number" min="1" max="100000" value="1"></span></label>
    <div class="actions"><button class="primary"<?= $cur ? '' : ' disabled title="Upload a build first"' ?>>Make a link</button></div>
  </form>
  <?php $lsh = rs_lsh_all(); if ($lsh): ?>
  <table class="ac-table" style="margin-top:14px"><thead><tr><th>For</th><th>Ends</th><th>Downloads</th><th>Opened</th><th></th></tr></thead><tbody>
  <?php foreach ($lsh as $l): $dead = rs_lsh_dead($l); ?>
    <tr<?= $dead !== '' ? ' class="dim"' : '' ?>><td><?= (string)$l['label'] !== '' ? h((string)$l['label']) : '<span class="muted">No name</span>' ?><?= $dead !== '' ? ' <span class="pill">' . ((int)$l['off'] ? 'Off' : ((int)$l['gone'] ? 'Expired' : 'Used up')) . '</span>' : ' <span class="pill cyan">Working</span>' ?></td>
      <td class="small"><?= h(rs_date('M j, g:i a', strtotime((string)$l['expires'] . ' UTC'))) ?></td>
      <td><?= number_format((int)$l['downloads']) ?><?= (int)$l['max_dl'] > 0 ? ' of ' . number_format((int)$l['max_dl']) : '' ?></td>
      <td><?= number_format((int)$l['opens']) ?></td>
      <td class="ac-acts"><?php if ($dead === ''): ?><button type="button" data-lsh-copy="<?= h(rs_lsh_url((string)$l['token'])) ?>">Copy</button>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="share-off"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="danger">Turn off</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card" id="waves">
  <h2>Invites</h2>
  <p class="muted">Each wave emails the earliest sign-ups who haven't had an invite, in the Beta Pass design. Nobody is ever invited twice. Each person gets their own link to recomplauncher.com/get, where the sign-up box becomes their download.</p>
  <?php if ($lbwLeft > 0): ?>
  <div class="lbw-run">
    <p><b><?= h(rs_lbw_name($lbwCur)) ?></b>: <?= number_format($lbwCurN) ?> of <?= number_format(min($lbwSize, $lbwCurN + $lbw['left'])) ?> invited. <span class="small muted" data-lbw-log><?= number_format($lbwLeft) ?> to go</span></p>
    <form method="post" class="actions"><?= $csrf ?><button type="button" class="primary" data-lbw-go>Keep sending</button></form>
  </div>
  <?php elseif ($lbw['left'] > 0): ?>
  <form method="post" class="grid2" onsubmit="return confirm('Send <?= h(rs_lbw_name($lbwNext)) ?> to ' + this.querySelector('[name=size]').value + ' people?')"><?= $csrf ?><input type="hidden" name="action" value="lbw-start"><input type="hidden" name="wave" value="<?= $lbwNext ?>">
    <label>Next wave<input value="<?= h(rs_lbw_name($lbwNext)) ?>" disabled></label>
    <label>How many people<input name="size" type="number" min="1" max="<?= $lbw['left'] ?>" value="<?= min(4000, $lbw['left']) ?>" required><small>The earliest who haven't had an invite.</small></label>
    <div class="actions span2"><button class="primary"<?= $cur || rs_setting('lbw_download_url', '') !== '' ? '' : ' disabled title="Upload a build first"' ?>>Send <?= h(rs_lbw_name($lbwNext)) ?></button><?php if (!$cur && rs_setting('lbw_download_url', '') === ''): ?><span class="small muted">Upload a build first, so the links have something to download.</span><?php endif; ?></div>
  </form>
  <?php else: ?><p class="muted">Everyone on the beta list has been invited.</p><?php endif; ?>
  <form method="post" class="actions" style="margin-top:12px"><?= $csrf ?><input type="hidden" name="action" value="lbw-test"><input type="hidden" name="wave" value="<?= $lbwNext ?>">
    <input name="to" type="email" placeholder="<?= h(rs_mail_get('mail_from')) ?>" aria-label="Send the test to">
    <button>Send me a test email</button></form>
  <?php if ($lbw['waves']): ?>
  <table class="ac-table" style="margin-top:14px"><thead><tr><th>Wave</th><th>Invited</th><th>Didn't go</th><th>Opened their link</th><th>Downloads</th><th></th></tr></thead><tbody>
  <?php foreach ($lbw['waves'] as $wn => $w): ?>
    <tr><td><?= h(rs_lbw_name($wn)) ?></td><td><?= number_format($w['sent']) ?></td><td><?= number_format($w['failed']) ?></td><td><?= number_format($w['opened']) ?></td><td><?= number_format($w['downloads']) ?></td>
      <td class="ac-acts"><?php if ($w['failed']): ?><form method="post"><?= $csrf ?><button type="button" data-lbw-retry="<?= (int)$wn ?>">Try those again</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<style>
.lb-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.lb-stats .card{display:flex;flex-direction:column;gap:4px;margin:0}
.gh-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.gh-head h2{margin:0 0 4px}
.gh-stats{margin:16px 0;grid-template-columns:repeat(3,minmax(0,1fr))}
@media (max-width:1000px){.gh-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
.mac-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:14px 0 18px}.mac-facts div{padding:12px 14px;border-radius:12px;background:rgba(var(--ink-rgb),.05);min-width:0}.mac-facts .wide{grid-column:span 2}
.mac-facts dt{font-size:13px;color:var(--muted);margin:0 0 4px}.mac-facts dd{margin:0;overflow-wrap:anywhere}
.gh-table{width:100%;border-collapse:collapse}
.gh-table th,.gh-table td{text-align:left;padding:8px 10px;border-bottom:1px solid rgba(var(--ink-rgb),.08)}
.gh-table .num{text-align:right;font-variant-numeric:tabular-nums}
.lb-stats b{font-size:26px;line-height:1.15}
.lb-up{display:grid;gap:12px;margin-top:12px}
.lb-drop{display:flex;flex-direction:column;gap:6px;padding:18px;border:1.5px dashed var(--line);border-radius:14px}
.lb-bar{height:8px;border-radius:99px;background:var(--line);overflow:hidden}
.lb-bar i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--cyan),#377BB6);transition:width .2s}
.lsh-new{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0;padding:12px 14px;border-radius:12px;border:1px solid var(--line)}
.lsh-new code{flex:1 1 260px;overflow-wrap:anywhere;font-size:13px}
.lsh-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px;align-items:end}
.lsh-form .actions{grid-column:1/-1}
.lsh-max{display:flex;gap:10px;align-items:center}.lsh-max input[type=number]{width:90px}
@media (max-width:720px){.lb-stats{grid-template-columns:1fr}.lsh-form{grid-template-columns:1fr}}
</style>
<script>
(() => {
  const CSRF = <?= json_encode(rs_csrf()) ?>;
  // Builds: in pieces, so any size gets through.
  const f = document.querySelector('[data-lb-up]');
  if (f) f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = f.file.files[0], st = f.querySelector('[data-lb-status]'), bar = f.querySelector('.lb-bar'), fill = bar.querySelector('i'), btn = f.querySelector('button[type=submit]');
    if (!file) return;
    const call = async (a, body, q = '') => {
      const r = await fetch('launcher-upload.php?a=' + a + q, { method: 'POST', headers: { 'X-CSRF': CSRF }, body });
      const j = await r.json().catch(() => ({ ok: false, error: 'The server answered oddly (HTTP ' + r.status + ').' }));
      if (!j.ok) throw new Error(j.error || 'Something went wrong.');
      return j;
    };
    btn.disabled = true; bar.hidden = false; fill.style.width = '0';
    try {
      const s = await call('start', JSON.stringify({ name: file.name, size: file.size }));
      let off = 0;
      while (off < file.size) {
        const piece = file.slice(off, off + s.chunk);
        let tries = 0, r;
        for (;;) {
          try { r = await call('chunk', piece, '&token=' + s.token + '&offset=' + off); break; }
          catch (err) { if (++tries >= 4) throw err; await new Promise((ok) => setTimeout(ok, 1500 * tries)); }
        }
        off = r.received;
        fill.style.width = (100 * off / file.size).toFixed(1) + '%';
        st.textContent = 'Uploading… ' + Math.round(100 * off / file.size) + '%';
      }
      st.textContent = 'Checking it' + <?= json_encode($r2 ? ' and copying it to Cloudflare R2…' : '…') ?>;
      const done = await call('finish', JSON.stringify({ token: s.token, version: f.version.value, platform: f.platform.value, notes: f.notes.value, current: f.current.checked }));
      st.textContent = done.note || 'Done.';
      setTimeout(() => location.assign('launcher-beta.php#builds'), done.note ? 4000 : 600);
    } catch (err) {
      st.textContent = err.message; btn.disabled = false;
    }
  });

  // Invites: 40 at a time while this page is open. A slow or dropped batch is tried again by
  // itself (nobody gets it twice: each batch only takes people who haven't had one). Stops when
  // the wave is done, when you press Stop, or if a batch mostly fails.
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const run = async (b, action, extra) => {
    if (b.dataset.run === '1') { b.dataset.run = ''; b.textContent = 'Stopping after this batch…'; return; }
    b.dataset.run = '1'; const was = b.textContent; b.textContent = 'Stop';
    const log = document.querySelector('[data-lbw-log]') || b.parentElement;
    let total = 0, bad = 0, miss = 0, left = null;
    const say = (t) => { log.textContent = t; };
    window.onbeforeunload = () => 'Invites are still going out.';
    while (b.dataset.run === '1') {
      say('Sending the next 25…' + (total ? ' ' + total + ' sent so far' + (left !== null ? ', ' + left + ' to go' : '') + '.' : ''));
      const fd = new FormData(); fd.append('action', action); fd.append('csrf', CSRF);
      for (const k in extra) fd.append(k, extra[k]);
      let j = null;
      try {
        const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 150000);
        const r = await fetch('launcher-beta.php', { method: 'POST', body: fd, signal: ctl.signal, credentials: 'same-origin' });
        clearTimeout(t);
        const txt = await r.text();
        try { j = JSON.parse(txt); } catch (e) { j = { error: 'busy', status: r.status }; }
      } catch (e) { j = { error: 'busy' }; }
      if (j.error) {
        const wait = /still going out/.test(j.error) || j.error === 'busy';
        if (wait && ++miss <= 6) { say('The server was slow, trying again in ' + (miss * 5) + ' seconds… (' + total + ' sent so far)'); await sleep(miss * 5000); continue; }
        say((j.error === 'busy' ? "Couldn't reach the server" : j.error) + '. ' + total + ' sent. Press Keep sending to carry on.'); break;
      }
      miss = 0; total += j.sent; bad += j.failed; left = j.left;
      say(total + ' sent' + (bad ? ', ' + bad + " didn't go" : '') + ', ' + j.left + ' to go');
      if (!j.left || (j.sent + j.failed) === 0) { say(total + ' sent. Done.'); window.onbeforeunload = null; setTimeout(() => location.reload(), 1500); return; }
      if (j.failed > j.sent) { say(log.textContent + ". Stopped: most of that batch didn't go out. Check the sending service, then carry on."); break; }
      if (action === 'lbw-retry') { window.onbeforeunload = null; setTimeout(() => location.reload(), 1500); return; }
    }
    window.onbeforeunload = null;
    b.dataset.run = ''; b.textContent = was;
  };
  const go = document.querySelector('[data-lbw-go]');
  if (go) {
    go.addEventListener('click', () => run(go, 'lbw-step', {}));
    if (/[?&]lbwgo=1/.test(location.search)) { history.replaceState(null, '', 'launcher-beta.php#waves'); run(go, 'lbw-step', {}); }
  }
  const pk = document.querySelector('[data-push-kind]'), pg = document.querySelector('[data-push-game]');
  if (pk && pg) pk.addEventListener('change', () => { pg.disabled = pk.value !== 'game'; });
  document.querySelectorAll('[data-lsh-copy]').forEach((b) => b.addEventListener('click', async () => {
    const u = b.dataset.lshCopy || (document.querySelector('[data-lsh-url]') || {}).textContent || '';
    try { await navigator.clipboard.writeText(u.trim()); const w = b.textContent; b.textContent = 'Copied'; setTimeout(() => { b.textContent = w; }, 1500); } catch (e) { prompt('Copy this link:', u.trim()); }
  }));
  document.querySelectorAll('[data-lbw-retry]').forEach((b) => b.addEventListener('click', () => run(b, 'lbw-retry', { wave: b.dataset.lbwRetry })));
})();
</script>
<?php rs_foot();
