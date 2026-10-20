<?php
// Recomp Store admin: bug reports, recomp submissions and messages sent from the website, and
// logs players send from the store (1.1.0: a game's page, Send logs). (2.0) A logs report a
// player finished on their phone shows its words and pictures; custom sources people reported.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_inbox_table();
rs_report_tables();
rs_submission_columns();

$show = in_array($_GET['show'] ?? '', ['bug', 'submit', 'message', 'logs', 'source', 'done'], true) ? $_GET['show'] : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $db = rs_db();
    if ($action === 'apply') {
        // (2.1) A submission into the store: a hidden draft with everything the developer sent.
        try {
            $st = $db->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'submit'");
            $st->execute([$id]);
            $r = $st->fetch();
            $d = $r ? json_decode((string)($r['data'] ?? ''), true) : null;
            if (!is_array($d)) throw new RuntimeException('That submission has no details to add (it came from the old form).');
            $tid = (string)($d['title_id'] ?? '');
            $gid = preg_match('/^[A-Z]{4}\d{5}$/', $tid) && !rs_game($tid) && !isset(rs_taken_ids()[$tid]) && !rs_move_block($tid) ? $tid : 'SUB' . $id;
            if (rs_game($gid)) throw new RuntimeException('It\'s already added, as ' . $gid . '.');
            $g = rs_game_defaults();
            $links = [];
            foreach ((array)($d['links'] ?? []) as $u) {
                $host = (string)parse_url((string)$u, PHP_URL_HOST);
                $links[] = ['label' => preg_match('/discord/i', $host) ? 'Discord' : (preg_match('/ko-?fi/i', $host) ? 'Ko-fi' : (preg_match('/patreon/i', $host) ? 'Patreon' : preg_replace('/^www\./', '', $host))), 'url' => (string)$u, 'color' => ''];
            }
            $g = array_merge($g, [
                'id' => $gid, 'name' => (string)$d['name'], 'kind' => 'community', 'author' => (string)$d['author'],
                'category' => rs_category((string)$d['category']), 'source' => 'github', 'repo' => (string)$d['repo'],
                'asset' => (string)($d['asset'] ?? ''), 'version' => (string)($d['version'] ?? ''), 'blurb' => (string)($d['blurb'] ?? ''),
                'description' => (string)($d['description'] ?? ''), 'needs' => (string)($d['needs'] ?? ''), 'credits' => (string)($d['credits'] ?? ''),
                'links' => rs_clean_links($links), 'hidden' => true, 'featured' => false,
            ]);
            $pics = $db->prepare('SELECT file, role, size FROM inbox_pictures WHERE inbox_id = ? ORDER BY id');
            $pics->execute([$id]);
            foreach ($pics->fetchAll() as $p) {
                if (!in_array($p['role'], ['icon', 'hero', 'logo'], true)) continue;
                $src = rs_report_dir() . '/' . basename((string)$p['file']);
                if (!is_file($src)) continue;
                $tmp = tempnam(sys_get_temp_dir(), 'rssub');
                copy($src, $tmp);
                try { $g = array_merge($g, rs_save_image(['error' => UPLOAD_ERR_OK, 'size' => (int)$p['size'], 'tmp_name' => $tmp], (string)$p['role'], $gid)); }
                catch (Throwable $e) { /* a picture that won't convert is left for the admin to add */ }
                finally { @unlink($tmp); }
            }
            rs_save_game($g, null);
            $db->prepare("UPDATE inbox SET status = 'done' WHERE id = ?")->execute([$id]);
            rs_flash('Added ' . $g['name'] . ' as a hidden draft' . ($gid === 'SUB' . $id ? ' with a placeholder title ID: give it its real one' : '') . '. Check it, untick Hidden and Publish when it\'s ready.');
            header('Location: edit.php?id=' . urlencode($gid));
            exit;
        } catch (Throwable $e) {
            rs_flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'done') $db->prepare("UPDATE inbox SET status = 'done' WHERE id = ?")->execute([$id]);
    elseif ($action === 'new') $db->prepare("UPDATE inbox SET status = 'new' WHERE id = ?")->execute([$id]);
    elseif ($action === 'delete') {
        $db->prepare('DELETE FROM inbox_files WHERE inbox_id = ?')->execute([$id]);
        // (2.0) Its phone report and pictures go too.
        $pics = $db->prepare('SELECT file FROM inbox_pictures WHERE inbox_id = ?');
        $pics->execute([$id]);
        foreach ($pics->fetchAll(PDO::FETCH_COLUMN) as $file) @unlink(rs_report_dir() . '/' . basename((string)$file));
        $db->prepare('DELETE FROM inbox_pictures WHERE inbox_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM inbox_reports WHERE inbox_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM report_codes WHERE inbox_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM inbox WHERE id = ?')->execute([$id]);
        rs_flash('Deleted.');
    }
    header('Location: inbox.php?show=' . urlencode($show));
    exit;
}

$where = match ($show) {
    'bug' => "WHERE kind = 'bug' AND status <> 'done'",
    'submit' => "WHERE kind = 'submit' AND status <> 'done'",
    'message' => "WHERE kind = 'message' AND status <> 'done'",
    'logs' => "WHERE kind = 'logs' AND status <> 'done'",
    'source' => "WHERE kind = 'source' AND status <> 'done'",
    'done' => "WHERE status = 'done'",
    default => "WHERE status <> 'done'",
};
$rows = rs_db()->query("SELECT * FROM inbox $where ORDER BY created DESC LIMIT 300")->fetchAll();
$counts = rs_db()->query("SELECT kind, COUNT(*) n FROM inbox WHERE status <> 'done' GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
$names = [];
foreach (rs_games() as $g) $names[$g['id']] = $g['name'];
$names['store'] = 'Recomp Store itself';
// Logs: each message's files (not their text, which opens on its own page).
$files = [];
$logIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['kind'] === 'logs'));
if ($logIds) {
    foreach (rs_db()->query('SELECT id, inbox_id, name, size FROM inbox_files WHERE inbox_id IN (' . implode(',', $logIds) . ') ORDER BY id') as $f) {
        $files[(int)$f['inbox_id']][] = $f;
    }
}
// (2.0) Reports finished on a phone, and their pictures.
$reports = $pictures = [];
if ($logIds) {
    foreach (rs_db()->query('SELECT * FROM inbox_reports WHERE inbox_id IN (' . implode(',', $logIds) . ')') as $r) $reports[(int)$r['inbox_id']] = $r;
    foreach (rs_db()->query('SELECT id, inbox_id, w, h, size FROM inbox_pictures WHERE inbox_id IN (' . implode(',', $logIds) . ') ORDER BY id') as $p) $pictures[(int)$p['inbox_id']][] = $p;
}
$kb = fn(int $b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
// (2.1) Submissions: their pictures by what they're for.
$subIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['kind'] === 'submit'));
$subPics = [];
if ($subIds) foreach (rs_db()->query('SELECT id, inbox_id, role, w, h FROM inbox_pictures WHERE inbox_id IN (' . implode(',', $subIds) . ') ORDER BY id') as $p) $subPics[(int)$p['inbox_id']][] = $p;

rs_head('Inbox');
?>
<section class="head-row">
  <div>
    <h1>Inbox</h1>
    <p class="muted">Bug reports, submissions and messages from the website, and logs sent from the store. Only you see these.</p>
  </div>
</section>
<nav class="tabs">
  <a href="inbox.php" <?= $show === 'all' ? 'aria-current="page"' : '' ?>>Open</a>
  <a href="inbox.php?show=bug" <?= $show === 'bug' ? 'aria-current="page"' : '' ?>>Bug reports<?= !empty($counts['bug']) ? ' <span class="count">' . (int)$counts['bug'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=submit" <?= $show === 'submit' ? 'aria-current="page"' : '' ?>>Submissions<?= !empty($counts['submit']) ? ' <span class="count">' . (int)$counts['submit'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=message" <?= $show === 'message' ? 'aria-current="page"' : '' ?>>Messages<?= !empty($counts['message']) ? ' <span class="count">' . (int)$counts['message'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=logs" <?= $show === 'logs' ? 'aria-current="page"' : '' ?>>Logs<?= !empty($counts['logs']) ? ' <span class="count">' . (int)$counts['logs'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=source" <?= $show === 'source' ? 'aria-current="page"' : '' ?>>Sources<?= !empty($counts['source']) ? ' <span class="count">' . (int)$counts['source'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=done" <?= $show === 'done' ? 'aria-current="page"' : '' ?>>Done</a>
</nav>

<section class="list">
<?php foreach ($rows as $r): $bug = $r['kind'] === 'bug'; $label = ['bug' => 'Bug', 'submit' => 'Submission', 'message' => 'Message', 'logs' => 'Logs', 'source' => 'Source reported'][$r['kind']] ?? $r['kind']; ?>
  <article class="msg<?= $r['status'] === 'new' ? ' unread' : '' ?>">
    <?php if ($r['kind'] === 'logs'): $fs = $files[(int)$r['id']] ?? []; ?>
    <header>
      <span class="tag cyan">Logs</span>
      <strong><?= h($names[$r['app_id']] ?? $r['app_id']) ?></strong>
      <span class="muted"><?= $r['version'] !== '' ? 'version ' . h($r['version']) : 'not installed' ?><?= $r['name'] !== '' ? ' · ' . h($r['name']) : '' ?></span>
      <?php if ($r['contact'] !== ''): ?><span class="tag beta" title="Console <?= h($r['contact']) ?>">Beta tester · <?= h(substr($r['contact'], 0, 6)) ?></span><?php endif; ?>
      <span class="muted when"><?= h(date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <?php $rep = $reports[(int)$r['id']] ?? null; $pics = $pictures[(int)$r['id']] ?? []; ?>
    <?php if (trim($r['body']) !== ''): ?><p class="body"><?= nl2br(h($r['body'])) ?></p><?php elseif (!$rep): ?><p class="body muted">No message, just the logs.</p><?php endif; ?>
    <?php if ($rep): ?>
    <div class="phone-report">
      <span class="kicker">Finished on their phone · <?= h(date('M j, g:i a', strtotime($rep['finished']))) ?></span>
      <p class="body"><strong>What happened:</strong> <?= nl2br(h($rep['what'])) ?></p>
      <?php if (trim($rep['before_text']) !== ''): ?><p class="body"><strong>Right before:</strong> <?= nl2br(h($rep['before_text'])) ?></p><?php endif; ?>
      <?php if ($rep['contact'] !== ''): ?><p class="meta"><?= filter_var($rep['contact'], FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . h($rep['contact']) . '">' . h($rep['contact']) . '</a>' : h($rep['contact']) ?></p><?php endif; ?>
      <?php if ($pics): ?><div class="pics"><?php foreach ($pics as $p): ?><a href="picture.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener"><img src="picture.php?id=<?= (int)$p['id'] ?>" alt="Picture <?= (int)$p['w'] ?>×<?= (int)$p['h'] ?>" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <ul class="log-files">
      <?php foreach ($fs as $f): ?>
        <li><a href="log-file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= h($f['name']) ?></a> <span class="muted"><?= h($kb((int)$f['size'])) ?></span> · <a href="log-file.php?id=<?= (int)$f['id'] ?>&amp;dl=1">Download</a></li>
      <?php endforeach; ?>
      <?php if (!$fs): ?><li class="muted">No files came with it.</li><?php endif; ?>
    </ul>
    <?php elseif ($r['kind'] === 'submit' && is_array($d = json_decode((string)($r['data'] ?? ''), true))): $sp = $subPics[(int)$r['id']] ?? [];
      $byRole = fn($role) => array_values(array_filter($sp, fn($p) => $p['role'] === $role));
      $rel = rs_repo_ok((string)$d['repo']) ? rs_gh_releases((string)$d['repo']) : ['status' => 0, 'body' => ''];
      $rels = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
      $latest = is_array($rels) && $rels ? $rels[0] : null; ?>
    <header>
      <span class="tag cyan">Submission · <?= h(['games' => 'Game port', 'emulators' => 'Emulator', 'apps' => 'App', 'tools' => 'Tool'][$d['category']] ?? 'App') ?></span>
      <strong><?= h($d['name']) ?></strong>
      <span class="muted">by <?= h($d['author']) ?></span>
      <span class="muted when"><?= h(date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <div class="sub">
      <?php if ($i = $byRole('hero')): ?><div class="sub-hero"><img src="picture.php?id=<?= (int)$i[0]['id'] ?>" alt="Backdrop"><?php if ($l = $byRole('logo')): ?><img class="sub-logo" src="picture.php?id=<?= (int)$l[0]['id'] ?>" alt="Logo"><?php endif; ?></div><?php endif; ?>
      <div class="sub-top">
        <?php if ($i = $byRole('icon')): ?><img class="sub-icon" src="picture.php?id=<?= (int)$i[0]['id'] ?>" alt="Icon"><?php endif; ?>
        <dl class="sub-facts">
          <dt>GitHub</dt><dd><a href="https://github.com/<?= h($d['repo']) ?>" target="_blank" rel="noopener noreferrer">github.com/<?= h($d['repo']) ?></a>
            <?php if ($latest): ?><br><span class="muted">Newest release <?= h((string)$latest['tag_name']) ?><?= !empty($latest['published_at']) ? ', ' . h(date('M j, Y', strtotime((string)$latest['published_at']))) : '' ?>: <?= h(implode(', ', array_map(fn($a) => (string)$a['name'], array_slice((array)($latest['assets'] ?? []), 0, 6))) ?: 'no files') ?></span>
            <?php elseif ($rel['status'] === 404): ?><br><span class="warn">GitHub can't find it (private or misspelled?)</span>
            <?php elseif ($rel['status'] === 200): ?><br><span class="warn">No releases yet</span><?php endif; ?></dd>
          <dt>Release file</dt><dd><?= $d['asset'] !== '' ? '<code>' . h($d['asset']) . '</code>' : '<span class="muted">not given</span>' ?></dd>
          <dt>Version</dt><dd><?= h($d['version'] ?: '—') ?></dd>
          <dt>Title ID</dt><dd><?php if ($d['title_id'] === ''): ?><span class="muted">none yet</span><?php else: ?><code><?= h($d['title_id']) ?></code><?= rs_game($d['title_id']) ? ' <span class="warn">already used by ' . h(rs_game($d['title_id'])['name']) . '</span>' : (isset(rs_taken_ids()[$d['title_id']]) ? ' <span class="warn">taken outside the store</span>' : '') ?><?php endif; ?></dd>
          <dt>Contact</dt><dd><?= filter_var($d['contact'], FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . h($d['contact']) . '">' . h($d['contact']) . '</a>' : h($d['contact']) ?></dd>
          <?php if ($d['needs'] !== ''): ?><dt>Players need</dt><dd><?= h($d['needs']) ?></dd><?php endif; ?>
          <?php if ($d['links']): ?><dt>Links</dt><dd><?php foreach ($d['links'] as $u): ?><a href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://(www\.)?#', '', $u)) ?></a><br><?php endforeach; ?></dd><?php endif; ?>
        </dl>
      </div>
      <?php if ($d['blurb'] !== ''): ?><p class="body"><strong><?= h($d['blurb']) ?></strong></p><?php endif; ?>
      <p class="body"><?= nl2br(h($d['description'])) ?></p>
      <?php if ($d['credits'] !== ''): ?><p class="body muted"><strong>Credits:</strong> <?= nl2br(h($d['credits'])) ?></p><?php endif; ?>
      <?php if ($d['notes'] !== ''): ?><p class="body"><strong>Note to you:</strong> <?= nl2br(h($d['notes'])) ?></p><?php endif; ?>
      <?php if ($sh = $byRole('shot')): ?><div class="pics"><?php foreach ($sh as $p): ?><a href="picture.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener"><img src="picture.php?id=<?= (int)$p['id'] ?>" alt="Screenshot <?= (int)$p['w'] ?>×<?= (int)$p['h'] ?>" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php else: ?>
    <header>
      <span class="tag<?= $bug ? '' : ' cyan' ?>"><?= h($label) ?></span>
      <?php if ($r['kind'] === 'source'): ?>
        <strong><?= h($r['repo']) ?></strong>
        <span class="muted">Add it to Blocked sources in Settings to take it down.</span>
      <?php elseif ($bug): ?>
        <strong><?= h($names[$r['app_id']] ?? $r['app_id']) ?></strong>
        <?php if ($r['version'] !== ''): ?><span class="muted">version <?= h($r['version']) ?></span><?php endif; ?>
      <?php else: ?>
        <strong><?= h($r['name']) ?></strong>
      <?php endif; ?>
      <span class="muted when"><?= h(date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <p class="body"><?= nl2br(h($r['body'])) ?></p>
    <p class="meta">
      <?php if ($r['contact'] !== ''): ?>
        <?= filter_var($r['contact'], FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . h($r['contact']) . '">' . h($r['contact']) . '</a>' : h($r['contact']) ?>
      <?php else: ?><span class="muted">No contact left</span><?php endif; ?>
      <?php if ($r['repo'] !== ''): $u = preg_match('#^https://#', $r['repo']) ? $r['repo'] : 'https://' . $r['repo']; ?>
        · <a href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://(www\.)?#', '', $r['repo'])) ?></a>
      <?php endif; ?>
    </p>
    <?php endif; ?>
    <div class="row-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <?php if ($r['kind'] === 'submit' && !empty($r['data']) && $r['status'] !== 'done'): ?><button name="action" value="apply" class="primary">Add to the store as a draft</button><?php endif; ?>
        <?php if ($r['status'] === 'done'): ?><button name="action" value="new">Reopen</button>
        <?php else: ?><button name="action" value="done"<?= $r['kind'] === 'submit' && !empty($r['data']) ? '' : ' class="primary"' ?>>Mark done</button><?php endif; ?>
      </form>
      <form method="post" onsubmit="return confirm('Delete this message for good?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="delete" class="danger">Delete</button></form>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">Nothing here.</p><?php endif; ?>
</section>
<?php
// Opening the inbox counts as reading what's shown.
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['status'] === 'new'));
    if ($ids) rs_db()->exec('UPDATE inbox SET status = \'read\' WHERE id IN (' . implode(',', $ids) . ')');
}
rs_foot();
