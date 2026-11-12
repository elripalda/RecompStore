<?php
// RecompStore admin: bug reports, recomp submissions and messages sent from the website, and
// logs players send from the store (1.1.0: a game's page, Send logs). (2.0) A logs report a
// player finished on their phone shows its words and pictures; custom sources people reported.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_inbox_table();
rs_report_tables();
rs_submission_columns();
if (!function_exists('rs_sub_fix_key')) {
    // (3.6) The key in a "fix and send it again" link for submission <id>.
    function rs_sub_fix_key(int $id): string { return substr(hash_hmac('sha256', "sub-fix|$id", rs_mail_key()), 0, 24); }
}

// (3.3) No "everything" view: the Inbox opens on a category (the first with something open).
$show = in_array($_GET['show'] ?? '', ['bug', 'submit', 'message', 'copyright', 'logs', 'source', 'done'], true) ? $_GET['show'] : '';
require_once __DIR__ . '/inc/logs.php';
rs_inbox_sig_column();
// (3.7) Submissions have their own page under Developers: submissions.php runs this same file.
$SUBS = defined('RS_SUBS_PAGE');
$self = $SUBS ? 'submissions.php' : 'inbox.php';
if (!$SUBS && $show === 'submit') { header('Location: submissions.php' . (($q = (string)($_SERVER['QUERY_STRING'] ?? '')) !== '' ? '?' . $q : '')); exit; }
if ($SUBS && $show !== 'done') $show = 'submit';
// (3.7) One message gone for good, with its files, report and pictures.
function rs_inbox_delete_one(int $id): void {
    $db = rs_db();
    $db->prepare('DELETE FROM inbox_files WHERE inbox_id = ?')->execute([$id]);
    $pics = $db->prepare('SELECT file FROM inbox_pictures WHERE inbox_id = ?');
    $pics->execute([$id]);
    foreach ($pics->fetchAll(PDO::FETCH_COLUMN) as $file) @unlink(rs_report_dir() . '/' . basename((string)$file));
    $db->prepare('DELETE FROM inbox_pictures WHERE inbox_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM inbox_reports WHERE inbox_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM report_codes WHERE inbox_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM inbox WHERE id = ?')->execute([$id]);
}

// (3.6) A PC submission's answers onto its RecompLauncher entry.
function rs_sub_pc_apply(array $a, array $d): array {
    $as = is_array($d['assets'] ?? null) ? $d['assets'] : [];
    if ($as) { $a['asset_win'] = (string)($as['win'] ?? ''); $a['asset_mac'] = (string)($as['mac'] ?? ''); $a['asset_linux'] = (string)($as['linux'] ?? ''); }
    if (isset(RS_PC_ORIGINS[$d['origin'] ?? ''])) $a['origin'] = (string)$d['origin'];
    if (trim((string)($d['disc'] ?? '')) !== '' && stripos((string)$a['needs'], (string)$d['disc']) === false) $a['needs'] = mb_substr(rtrim((string)$a['needs'], '. ') . ' (' . trim((string)$d['disc']) . ')', 0, 200);
    if (($d['setup'] ?? '') === 'yes' && trim((string)($d['setup_steps'] ?? '')) !== '') { $a['setup_required'] = true; $a['setup_steps'] = (string)$d['setup_steps']; }
    $tags = array_values(array_filter(array_map('trim', is_array($a['tags'] ?? null) ? $a['tags'] : explode(',', (string)($a['tags'] ?? '')))));
    foreach ((array)($d['features'] ?? []) as $f) if (!in_array($f, $tags, true)) $tags[] = $f;
    if ($tags) $a['tags'] = is_array($a['tags'] ?? null) ? $tags : implode(', ', $tags);
    return $a;
}
// IGDB's genres to RecompLauncher's kinds.
function rs_sub_genre(array $g): string {
    $s = strtolower(implode(' ', $g));
    foreach (['fighting' => 'fighting', 'racing' => 'racing', 'shooter' => 'shooter', 'sport' => 'sports', 'role-playing' => 'rpg', 'rpg' => 'rpg', 'platform' => 'platformer', 'puzzle' => 'puzzle', 'adventure' => 'adventure', 'hack and slash' => 'action', 'action' => 'action'] as $k => $v)
        if (str_contains($s, $k)) return $v;
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $db = rs_db();
    // (4.7) The picked PC submissions into Auto-add, run one after another in the background.
    if ($action === 'bulk_auto' && $SUBS) {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), fn($i) => $i > 0))), 0, 60);
        $back = (string)($_POST['back'] ?? '');
        if (!str_starts_with($back, $self . '?')) $back = $self . '?show=' . urlencode($show);
        require_once __DIR__ . '/inc/pc.php';
        if (is_file(__DIR__ . '/inc/vt.php')) require_once __DIR__ . '/inc/vt.php';
        require_once __DIR__ . '/inc/autoadd.php';
        $drafts = []; $skipped = 0; $bad = [];
        foreach ($ids as $sid) {
            $st = $db->prepare("SELECT data, status FROM inbox WHERE id = ? AND kind = 'submit'");
            $st->execute([$sid]);
            $r = $st->fetch();
            $d = $r ? json_decode((string)$r['data'], true) : null;
            if (!is_array($d) || ($d['platform'] ?? '') !== 'pc' || (string)($d['repo'] ?? '') === '' || $r['status'] === 'done') { ++$skipped; continue; }
            try {
                $drafts[] = rs_auto_start(rs_host_web(rs_host_norm((string)($d['host'] ?? '')), (string)$d['repo']), rs_me_name(), false, $sid);
                $db->prepare("UPDATE inbox SET status = 'read' WHERE id = ? AND status = 'new'")->execute([$sid]);
            } catch (Throwable $e) { $bad[] = (string)($d['name'] ?? $sid) . ': ' . $e->getMessage(); }
        }
        $drafts = array_values(array_unique($drafts));
        if ($drafts) rs_auto_launch_many($drafts);
        $n = count($drafts);
        rs_flash(($n ? 'Sent ' . $n . ' to Auto-add. They run one after another; watch them here.' : 'Nothing sent.')
            . ($skipped ? ' ' . $skipped . ' skipped (only PC submissions with a repo can go).' : '') . ($bad ? ' ' . implode(' ', $bad) : ''), $n ? 'ok' : 'err');
        header('Location: ' . ($n ? 'auto.php' : $back));
        exit;
    }
    // (3.7) Many at once: the ones picked, or a whole day.
    if (in_array($action, ['bulk_done', 'bulk_new', 'bulk_delete'], true)) {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), fn($i) => $i > 0))), 0, 2000);
        $back = (string)($_POST['back'] ?? '');
        if (!str_starts_with($back, $self . '?')) $back = $self . '?show=' . urlencode($show);
        if ($ids) {
            $own = $db->query('SELECT id FROM inbox WHERE id IN (' . implode(',', $ids) . ') AND ' . ($SUBS ? "kind = 'submit'" : "kind <> 'submit'"))->fetchAll(PDO::FETCH_COLUMN);
            $own = array_map('intval', $own);
            if ($own && $action === 'bulk_delete') { foreach ($own as $x) rs_inbox_delete_one($x); rs_flash(count($own) . ' deleted.'); }
            elseif ($own) {
                $db->exec("UPDATE inbox SET status = '" . ($action === 'bulk_done' ? 'done' : 'read') . "' WHERE id IN (" . implode(',', $own) . ')');
                rs_flash(count($own) . ($action === 'bulk_done' ? ' marked done.' : ' opened again.'));
            }
        }
        header('Location: ' . $back);
        exit;
    }
    // (4.3) The Submissions page only acts on submissions (curators can open it but not the Inbox).
    if ($SUBS && $id > 0) {
        $k = $db->prepare('SELECT kind FROM inbox WHERE id = ?');
        $k->execute([$id]);
        if ($k->fetchColumn() !== 'submit') { rs_flash('That isn\'t a submission.', 'error'); header('Location: submissions.php'); exit; }
    }
    if ($action === 'unlist') {
        // (2.9) A copyright notice: the listing it names comes off at once. A PC recomp leaves
        // RecompLauncher straight away; a PS5 app is hidden and leaves on the next Publish.
        try {
            $st = $db->prepare("SELECT app_id FROM inbox WHERE id = ? AND kind = 'copyright'");
            $st->execute([$id]);
            $app = (string)$st->fetchColumn();
            if ($app === '') throw new RuntimeException('That notice doesn\'t name a listing.');
            if (str_starts_with($app, 'pc:')) {
                rs_pc_tables();
                $a = rs_pc_app(substr($app, 3));
                if (!$a) throw new RuntimeException('That PC recomp isn\'t in the admin.');
                $a['hidden'] = true;
                rs_pc_save($a);
                rs_pc_unlist_one($a['id']);
                rs_flash($a['name'] . ' is hidden and off RecompLauncher now.');
            } else {
                $g = rs_game($app);
                if (!$g) throw new RuntimeException('That app isn\'t in the admin.');
                $g['hidden'] = true;
                rs_save_game($g, $g['id']);
                rs_flash($g['name'] . ' is hidden. Publish (and sign) to take it off the PS5 store and the site.');
            }
            $db->prepare("UPDATE inbox SET status = 'read' WHERE id = ? AND status = 'new'")->execute([$id]);
        } catch (Throwable $e) {
            rs_flash($e->getMessage(), 'err');
        }
        header('Location: ' . $self . '?show=' . urlencode($show) . '#m' . $id);
        exit;
    }
    if ($action === 'to_auto') {
        // (4.7) A PC submission into Auto-add: the full run starts at once (read, files, ClamAV,
        // VirusTotal, Claude's page and boot profile with what the developer wrote, art, game info).
        // Publish on Auto-add marks the submission done and opens its "you're live" email here.
        try {
            $st = $db->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'submit'");
            $st->execute([$id]);
            $r = $st->fetch();
            $d = $r ? json_decode((string)($r['data'] ?? ''), true) : null;
            if (!is_array($d) || ($d['platform'] ?? '') !== 'pc' || (string)($d['repo'] ?? '') === '') throw new RuntimeException('Only a PC submission with a repo can go to Auto-add.');
            require_once __DIR__ . '/inc/pc.php';
            if (is_file(__DIR__ . '/inc/vt.php')) require_once __DIR__ . '/inc/vt.php';
            require_once __DIR__ . '/inc/autoadd.php';
            $host = rs_host_norm((string)($d['host'] ?? ''));
            $draft = rs_auto_start(rs_host_web($host, (string)$d['repo']), rs_me_name(), true, $id);
            $db->prepare("UPDATE inbox SET status = 'read' WHERE id = ? AND status = 'new'")->execute([$id]);
            rs_flash('Sent to Auto-add. It\'s running every check now; you can watch it here.');
            header('Location: auto.php#d-' . $draft);
            exit;
        } catch (Throwable $e) {
            rs_flash($e->getMessage(), 'err');
        }
        header('Location: ' . $self . '?show=' . urlencode($show) . '#m' . $id);
        exit;
    }
    if ($action === 'apply' || $action === 'apply_live') {
        // (2.1) A submission into the store: a hidden draft with everything the developer sent.
        try {
            $st = $db->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'submit'");
            $st->execute([$id]);
            $r = $st->fetch();
            $d = $r ? json_decode((string)($r['data'] ?? ''), true) : null;
            if (!is_array($d)) throw new RuntimeException('That submission has no details to add (it came from the old form).');
            // (2.9) A PC recomp goes to RecompLauncher (the PC page), hidden, with its art; what the
            // submitter wrote takes the place of what was read from GitHub.
            if (($d['platform'] ?? '') === 'pc') {
                rs_pc_tables();
                $subKey = rs_repo_key((string)($d['host'] ?? ''), (string)($d['repo'] ?? '')); // (3.9) gitlab.com/<project> for GitLab
                foreach (rs_pc_apps() as $x) if (strcasecmp(rs_pc_key($x), $subKey) === 0) throw new RuntimeException('It\'s already on RecompLauncher, as ' . $x['id'] . '.');
                $a = rs_pc_add($subKey);
                foreach (['name' => 120, 'blurb' => 160, 'description' => 8000, 'needs' => 200, 'credits' => 1000] as $k => $max) {
                    $v = trim(str_replace("\r\n", "\n", (string)($d[$k] ?? '')));
                    if ($v !== '') $a[$k] = mb_substr($v, 0, $max);
                }
                $a = array_merge($a, rs_ai_from_submission($d, $a));
                // (3.6) What the new form asks: its files per system, the console, the setup steps, what it has.
                $a = rs_sub_pc_apply($a, $d);
                rs_pc_save($a);
                $db->prepare("UPDATE inbox SET status = 'done' WHERE id = ?")->execute([$id]);
                if ($action === 'apply_live') {
                    // Live in one go: shown, its About card and kind from IGDB, the catalog rewritten (seconds,
                    // no GitHub round) and open launchers told. Then the "you're live" email, ready to send.
                    $a['hidden'] = false;
                    $a['live_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    try {
                        $ab = rs_pc_about_fetch($a, 0);
                        if ($ab) { $a['about'] = $ab; if (($a['genre'] ?? '') === '') $a['genre'] = rs_sub_genre((array)($ab['genres'] ?? [])); }
                    } catch (Throwable $e) { /* the About card can be added later */ }
                    rs_pc_save($a);
                    rs_pc_write_catalog();
                    try { require_once __DIR__ . '/inc/rlpush.php'; rl_push('catalog'); } catch (Throwable $e) { /* launchers look again soon */ }
                    rs_log('pc-live', $a['id'], 'from submission ' . $id);
                    rs_flash($a['name'] . ' is live on RecompLauncher. Your "you\'re live" email is ready below: look it over and press Send.');
                    header('Location: ' . $self . '?show=done&live=' . $id . '#m' . $id);
                    exit;
                }
                rs_flash($a['name'] . ' is on RecompLauncher, hidden. Look it over, then show it and Publish.');
                header('Location: pc.php?edit=' . rawurlencode($a['id']) . '#edit');
                exit;
            }
            if (rs_host_norm((string)($d['host'] ?? '')) === 'gitlab') throw new RuntimeException('PS5 apps come from GitHub only for now.'); // (3.9)
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
    } elseif ($action === 'reply') {
        // (2.7.1) An email back to whoever sent it, from the site's mailbox (copy in Sent).
        try {
            $st = $db->prepare('SELECT * FROM inbox WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) throw new RuntimeException('That message is gone.');
            $rep = $db->prepare('SELECT contact FROM inbox_reports WHERE inbox_id = ?');
            $rep->execute([$id]);
            $to = rs_inbox_email($r, $rep->fetch() ?: null);
            if ($to === '') throw new RuntimeException('They didn\'t leave an email address.');
            $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if ($subject === '' || mb_strlen($body) < 2) throw new RuntimeException('Write a subject and a message.');
            if (!rs_mail_ready()) throw new RuntimeException('Set up the mailbox on the Mail page first.');
            $text = $body . "\n\n" . rs_mail_get('mail_sign');
            if (trim((string)$r['body']) !== '') {
                // Their words under yours, like any email reply.
                $text .= "\n\n" . 'On ' . rs_date('M j, Y', strtotime((string)$r['created'])) . ' you wrote:' . "\n" . preg_replace('/^/m', '> ', mb_substr(trim((string)$r['body']), 0, 3000));
            }
            rs_mail_send($to, $subject, $text, ['save' => true]);
            rs_mail_log('reply', $to, $subject, $body, '', $id);
            if (!empty($_POST['done'])) $db->prepare("UPDATE inbox SET status = 'done' WHERE id = ?")->execute([$id]);
            rs_flash('Sent to ' . $to . '. Their answer comes to ' . rs_mail_get('mail_from') . '.');
        } catch (Throwable $e) {
            rs_flash('Not sent: ' . $e->getMessage(), 'err');
        }
        header('Location: ' . $self . '?show=' . urlencode($show) . '#m' . $id);
        exit;
    } elseif ($action === 'decline') {
        // (3.6) Declined, with the reasons in an email you write and send from here. With "let them fix
        // it", the email carries a link that opens their submission again, filled in.
        try {
            $st = $db->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'submit'");
            $st->execute([$id]);
            $r = $st->fetch();
            $d = $r ? json_decode((string)($r['data'] ?? ''), true) : null;
            if (!$r || !is_array($d)) throw new RuntimeException('That submission is gone.');
            // (4.6) No email address (a Discord name, say) or no mailbox: it's still declined and the
            // reason kept; the message is copied from the page and sent by hand.
            $to = rs_inbox_email($r, null);
            $send = $to !== '' && rs_mail_ready();
            $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if ($send && ($subject === '' || mb_strlen($body) < 10)) throw new RuntimeException('Write the message first.');
            $fix = !empty($_POST['fix']);
            if ($send) {
                $text = $body;
                if ($fix) $text .= "\n\n" . 'Fix it here (everything you sent is filled in, and sending it updates the same submission):' . "\n" . 'https://recompstore.com/submit?fix=' . $id . '&t=' . rs_sub_fix_key($id);
                $text .= "\n\n" . rs_mail_get('mail_sign');
                rs_mail_send($to, $subject, $text, ['save' => true]);
                rs_mail_log('reply', $to, $subject, $body . ($fix ? "\n\n[Fix link included]" : ''), '', $id);
            }
            $why = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 600);
            $d['declined'] = ['reason' => $why, 'at' => gmdate('c'), 'fix' => $fix];
            if (!$fix) { $d['turned_down'] = $d['declined']; unset($d['declined']); }
            $db->prepare("UPDATE inbox SET status = 'done', data = ? WHERE id = ?")->execute([json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
            rs_log('submission-declined', (string)$id, $why);
            rs_flash($send ? 'Declined and sent to ' . $to . ($fix ? ', with a link to fix it and send it again.' : '.')
                : 'Declined. No email went out' . ($to === '' ? ' (they left no email address)' : ' (the mailbox isn\'t set up)') . ', so send them the message yourself.');
        } catch (Throwable $e) {
            rs_flash('Not sent: ' . $e->getMessage(), 'err');
        }
        header('Location: ' . $self . '?show=' . urlencode($show) . '#m' . $id);
        exit;
    } elseif ($action === 'done') $db->prepare("UPDATE inbox SET status = 'done' WHERE id = ?")->execute([$id]);
    elseif ($action === 'new') $db->prepare("UPDATE inbox SET status = 'new' WHERE id = ?")->execute([$id]);
    // (3.6) A submission sent to the wrong store: moved to the PS5 side (or back to the PC side).
    elseif ($action === 'to_ps5' || $action === 'to_pc') {
        $st = $db->prepare("SELECT data FROM inbox WHERE id = ? AND kind = 'submit'");
        $st->execute([$id]);
        $d = json_decode((string)$st->fetchColumn(), true);
        if (is_array($d)) {
            $d['platform'] = $action === 'to_pc' ? 'pc' : 'ps5';
            $db->prepare('UPDATE inbox SET data = ? WHERE id = ?')->execute([json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
            rs_flash($action === 'to_pc' ? 'Moved to the PC submissions.' : 'Moved to the PS5 submissions.');
        }
    }
    elseif ($action === 'delete') {
        // (2.0) Its phone report and pictures go too.
        rs_inbox_delete_one($id);
        rs_flash('Deleted.');
    }
    header('Location: ' . $self . '?show=' . urlencode($show));
    exit;
}

$counts = rs_db()->query("SELECT kind, COUNT(*) n FROM inbox WHERE status <> 'done' GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
if ($show === '') {
    $show = 'bug';
    foreach (['bug', 'logs', 'message', 'copyright', 'source'] as $k) if (!empty($counts[$k])) { $show = $k; break; }
}
// (4.7) A PC submission whose project is already live on RecompLauncher is done: closed on sight.
try {
    require_once __DIR__ . '/inc/pc.php';
    require_once __DIR__ . '/inc/autoadd.php';
    if ($closed = rs_sub_close_live()) rs_flash($closed . ' ' . ($closed === 1 ? 'submission was' : 'submissions were') . ' already live on RecompLauncher, so ' . ($closed === 1 ? 'it\'s' : 'they\'re') . ' marked done.');
} catch (Throwable $e) { /* next time */ }
$where = match ($show) {
    'bug' => "WHERE kind = 'bug' AND status <> 'done'",
    'submit' => "WHERE kind = 'submit' AND status <> 'done'",
    'message' => "WHERE kind = 'message' AND status <> 'done'",
    'copyright' => "WHERE kind = 'copyright' AND status <> 'done'",
    'logs' => "WHERE kind = 'logs' AND status <> 'done'",
    'source' => "WHERE kind = 'source' AND status <> 'done'",
    'done' => $SUBS ? "WHERE kind = 'submit' AND status = 'done'" : "WHERE status = 'done' AND kind <> 'submit'",
    default => "WHERE status <> 'done'",
};
// (2.3.0) Logs by what happened: ?show=logs&cat=bug|crash|install|slow|other|none.
rs_inbox_feedback_columns();
$cat = (string)($_GET['cat'] ?? '');
if ($show === 'logs' && (array_key_exists($cat, RS_LOG_CATEGORIES) || $cat === 'none'))
    $where .= ' AND category = ' . rs_db()->quote($cat === 'none' ? '' : $cat);
else $cat = '';
// (3.7) Newest or oldest first.
$sort = ($_GET['sort'] ?? '') === 'old' ? 'old' : '';
$rows = rs_db()->query("SELECT * FROM inbox $where ORDER BY created " . ($sort === 'old' ? 'ASC' : 'DESC') . ' LIMIT 300')->fetchAll();
// (2.8) Which store: PC submissions are the PC store's; logs, bug reports and sources the PS5
// store's; messages belong to both.
$pf = in_array($_GET['pf'] ?? '', ['ps5', 'pc'], true) ? (string)$_GET['pf'] : '';
// (4.3) Someone who works on one store only sees that store's.
$oneStore = count(rs_me()['stores_list'] ?? []) === 1 ? rs_me()['stores_list'][0] : '';
if ($oneStore !== '') $pf = $oneStore;
// (3.1g) Crash reports and feedback from RecompLauncher ("RecompLauncher <version> on windows", app
// "launcher" or a PC recomp's id) are PC's.
$pcIds = (function (): array {
    try { return array_flip(array_map('strval', rs_db()->query('SELECT id FROM pc_apps')->fetchAll(PDO::FETCH_COLUMN))); } catch (Throwable $e) { return []; }
})();
$fromLauncher = fn(array $r): bool => (string)$r['app_id'] === 'launcher' || isset($pcIds[(string)$r['app_id']])
    || preg_match('/^\s*RecompLauncher \S+ on (windows|linux|macos)\b/i', (string)($r['body'] ?? '')) === 1;
$pfOf = function (array $r) use ($fromLauncher): string {
    if ($r['kind'] === 'message') return '';
    if ($r['kind'] === 'copyright') return str_starts_with((string)$r['app_id'], 'pc:') ? 'pc' : '';
    if ($r['kind'] !== 'submit') return $fromLauncher($r) ? 'pc' : 'ps5';
    $d = json_decode((string)($r['data'] ?? ''), true);
    return is_array($d) && ($d['platform'] ?? '') === 'pc' ? 'pc' : 'ps5';
};
if ($pf !== '') $rows = array_values(array_filter($rows, fn($r) => $pfOf($r) === $pf));
// (3.7) Every one on each day (across pages), so a whole day can be marked done at once.
$dayAll = [];
foreach ($rows as $x) $dayAll[rs_date('Y-m-d', strtotime((string)$x['created']))][] = (int)$x['id'];
// (3.3) 40 a page.
$perPage = 40;
$total = count($rows);
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
$here = fn(array $over = []) => $self . '?' . http_build_query(array_filter(array_merge(['show' => $show, 'cat' => $cat, 'pf' => $pf, 'sort' => $sort], $over), fn($x) => $x !== '' && $x !== null && $x !== 1));
// (3.1) Which store each one is for, on every message.
$pfPill = function (array $r) use ($pfOf): string {
    $p = $pfOf($r);
    return $p === 'pc' ? '<span class="tag pf-pc" title="RecompLauncher">PC</span>' : ($p === 'ps5' ? '<span class="tag pf-ps5" title="RecompStore">PS5</span>' : '');
};
$names = rs_admin_app_names();
$names['store'] = 'RecompStore itself';
// (4.1) "launcher" is OpenGOAL Launcher only with its Game line; otherwise it's RecompLauncher's own.
$nameOf = fn(array $r): string => $names[rs_feedback_app((string)$r['app_id'], (string)($r['body'] ?? ''))] ?? (string)$r['app_id'];
// Logs: each message's files (not their text, which opens on its own page).
$files = [];
$logIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['kind'] === 'logs'));
if ($logIds) {
    foreach (rs_db()->query('SELECT id, inbox_id, name, size FROM inbox_files WHERE inbox_id IN (' . implode(',', $logIds) . ') ORDER BY id') as $f) {
        $files[(int)$f['inbox_id']][] = $f;
    }
}
// (2.7.3) What each log says went wrong, and how many logs for that app say the same (last 90 days).
// (3.3) Worked out once per log and kept (inbox.sig), only for the logs on this page.
$sigOf = $sigCount = [];
if ($logIds) {
    foreach ($rows as &$lr) {
        if ($lr['kind'] !== 'logs') continue;
        $s = rs_log_sig_of($lr);
        if ($s !== '') $sigOf[(int)$lr['id']] = [(string)$lr['app_id'], $s];
    }
    unset($lr);
    if ($sigOf) {
        // (3.4) Logs of these apps whose headline isn't worked out yet (since 3.4 changed how), a
        // batch each time the Inbox opens, so "Same problem" counts them too.
        try {
            $apps = array_values(array_unique(array_column($sigOf, 0)));
            $st = rs_db()->prepare("SELECT id, app_id, sig FROM inbox WHERE kind = 'logs' AND sig = '' AND created > (NOW() - INTERVAL 90 DAY) AND app_id IN (" . implode(',', array_fill(0, count($apps), '?')) . ') ORDER BY id DESC LIMIT 150');
            $st->execute($apps);
            foreach ($st->fetchAll() as $old) rs_log_sig_of($old);
        } catch (Throwable $e) {}
        $st = rs_db()->prepare("SELECT app_id, sig, COUNT(*) n FROM inbox WHERE kind = 'logs' AND sig NOT IN ('', '-') AND created > (NOW() - INTERVAL 90 DAY) AND app_id IN (" . implode(',', array_fill(0, count(array_unique(array_column($sigOf, 0))), '?')) . ') GROUP BY app_id, sig');
        $st->execute(array_values(array_unique(array_column($sigOf, 0))));
        foreach ($st->fetchAll() as $c) $sigCount[$c['app_id'] . '|' . $c['sig']] = (int)$c['n'];
    }
}
$logApps = $show === 'logs' ? rs_db()->query("SELECT app_id, COUNT(*) FROM inbox WHERE kind = 'logs' GROUP BY app_id ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
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

// (2.7.1) Emails sent back, under each message.
$replies = [];
if ($rows) {
    rs_mail_tables();
    foreach (rs_db()->query("SELECT inbox_id, to_addr, subject, body, created FROM mail_sent WHERE kind = 'reply' AND inbox_id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ') ORDER BY id') as $m) $replies[(int)$m['inbox_id']][] = $m;
}
$mailReady = rs_mail_ready();
$replySubject = function (array $r) use ($names): string {
    $d = json_decode((string)($r['data'] ?? ''), true);
    return match ($r['kind']) {
        'submit' => 'Your RecompStore submission' . (is_array($d) && ($d['name'] ?? '') !== '' ? ': ' . $d['name'] : ''),
        'bug' => 'Your bug report for ' . $nameOf($r),
        'logs' => 'The logs you sent for ' . $nameOf($r),
        default => 'Re: your message to RecompStore',
    };
};
$liveId = (int)($_GET['live'] ?? 0);
// (4.7) Submissions already sent to Auto-add: [submission id => its draft].
$autoBySub = [];
try {
    foreach (rs_db()->query("SELECT id, sub_id, status FROM pc_auto WHERE sub_id > 0 AND status NOT IN ('removed', 'dismissed') ORDER BY id") as $x) $autoBySub[(int)$x['sub_id']] = $x;
} catch (Throwable $e) { /* Auto-add not used yet */ }
// (3.6) Right after Approve and go live: the "you're live" email, written and ready to send.
$liveMail = function (array $r): ?array {
    $d = json_decode((string)($r['data'] ?? ''), true);
    if (!is_array($d) || ($d['platform'] ?? '') !== 'pc') return null;
    $a = null;
    foreach (rs_pc_apps() as $x) if (strcasecmp(rs_pc_key($x), rs_repo_key((string)($d['host'] ?? ''), (string)($d['repo'] ?? ''))) === 0) $a = $x;
    if (!$a) return null;
    $who = trim((string)($d['author'] ?? ''));
    $url = 'https://recomplauncher.com/game/' . rawurlencode((string)$a['id']);
    $sdk = ($d['rl_quick'] ?? '') !== 'yes' || ($d['rl_settings'] ?? '') !== 'yes';
    return ['subject' => (string)$a['name'] . ' is live on RecompLauncher',
        'body' => 'Hi' . ($who !== '' ? ' ' . $who : '') . ",\n\n" . 'Good news: ' . $a['name'] . ' is now live on RecompLauncher. Here\'s its page:' . "\n" . $url . "\n\n"
            . 'Players can install it from the launcher, and every release you publish on ' . rs_host_name((string)($d['host'] ?? '')) . ' reaches them automatically.'
            . ($sdk ? "\n\n" . 'If you\'d like it to feel even more native, quick install and in-launcher settings come from one small file in your release. The SDK walks you through it:' . "\n" . 'https://recomplauncher.com/sdk' : '')
            . "\n\n" . 'Thanks for sending it in.' . "\n"];
};
$replyHello = function (array $r): string {
    $d = json_decode((string)($r['data'] ?? ''), true);
    $who = is_array($d) && trim((string)($d['author'] ?? '')) !== '' ? trim((string)$d['author']) : trim((string)($r['kind'] === 'message' ? $r['name'] : ''));
    return 'Hi' . ($who !== '' ? ' ' . $who : '') . ",\n\n";
};

rs_head($SUBS ? 'Submissions' : 'Inbox', true, ['assets/inbox.css', 'assets/vt.css']);
?>
<section class="head-row">
  <div>
    <?php if ($SUBS): ?>
    <h1>Submissions</h1>
    <p class="muted">Games, apps and recomps developers sent in for RecompStore and RecompLauncher.</p>
    <?php else: ?>
    <h1>Inbox</h1>
    <p class="muted">Bug reports and messages from the website, and logs sent from the store and the launcher. Submissions are under Developers. Only the admin team sees these.</p>
    <?php endif; ?>
  </div>
</section>
<nav class="tabs">
  <?php $tab = function (string $k, string $label) use ($show, $counts, $self) { return '<a href="' . $self . '?show=' . $k . '"' . ($show === $k ? ' aria-current="page"' : '') . '>' . $label . (!empty($counts[$k]) ? ' <span class="count">' . (int)$counts[$k] . '</span>' : '') . '</a>'; }; ?>
  <?php if ($SUBS): ?>
  <?= $tab('submit', 'Waiting') ?>
  <?php else: ?>
  <?= $tab('bug', 'Bug reports') ?><?= $tab('logs', 'Logs') ?><?= $tab('message', 'Messages') ?><?= $tab('copyright', 'Copyright') ?><?= $tab('source', 'Sources') ?>
  <a href="feedback.php">Feedback</a>
  <?php endif; ?>
  <a href="<?= h($self) ?>?show=done" <?= $show === 'done' ? 'aria-current="page"' : '' ?>>Done</a>
</nav>
<nav class="chips pf-chips" aria-label="Store and order">
  <?php $pfq = fn(string $v) => $self . '?' . http_build_query(array_filter(['show' => $show, 'cat' => $cat, 'pf' => $v, 'sort' => $sort], fn($x) => $x !== '')); ?>
  <?php if ($oneStore === ''): ?>
  <a href="<?= h($pfq('')) ?>" <?= $pf === '' ? 'aria-current="page"' : '' ?>>Both stores</a>
  <a href="<?= h($pfq('ps5')) ?>" <?= $pf === 'ps5' ? 'aria-current="page"' : '' ?>>PS5</a>
  <a href="<?= h($pfq('pc')) ?>" <?= $pf === 'pc' ? 'aria-current="page"' : '' ?>>PC</a>
  <?php endif; ?>
  <span class="chip-gap" aria-hidden="true"></span>
  <a href="<?= h($here(['sort' => '', 'page' => 1])) ?>" <?= $sort === '' ? 'aria-current="page"' : '' ?>>Newest first</a>
  <a href="<?= h($here(['sort' => 'old', 'page' => 1])) ?>" <?= $sort === 'old' ? 'aria-current="page"' : '' ?>>Oldest first</a>
</nav>
<?php if ($show === 'logs'): ?>
<nav class="chips" aria-label="What happened">
  <a href="inbox.php?show=logs" <?= $cat === '' ? 'aria-current="page"' : '' ?>>All</a>
  <?php foreach (RS_LOG_CATEGORIES as $k => $v): ?><a href="inbox.php?show=logs&amp;cat=<?= h($k) ?>" <?= $cat === $k ? 'aria-current="page"' : '' ?>><?= h($v) ?></a><?php endforeach; ?>
  <a href="inbox.php?show=logs&amp;cat=none" <?= $cat === 'none' ? 'aria-current="page"' : '' ?>>No category</a>
</nav>
<form class="logs-all" method="get" action="log-zip.php">
  <input type="hidden" name="all" value="1">
  <label>Download every log for <select name="app"><option value="">all apps</option><?php foreach ($logApps as $aid => $n): ?><option value="<?= h((string)$aid) ?>"><?= h($names[$aid] ?? $aid) ?> (<?= (int)$n ?>)</option><?php endforeach; ?></select></label>
  <label>from <select name="days"><option value="7">the last 7 days</option><option value="30" selected>the last 30 days</option><option value="90">the last 90 days</option><option value="0">any time</option></select></label>
  <button>Download as one zip</button>
</form>
<?php endif; ?>

<?php if ($rows): // (3.7) Pick some, a day, or the whole page: mark them done (or open again), delete, or for logs download them. ?>
<form class="logs-pick" id="logs-pick" method="post">
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="ids" value=""><input type="hidden" name="back" value="<?= h($here(['page' => $page])) ?>">
  <label class="check"><input type="checkbox" data-pick-all> Select all on this page</label>
  <span class="muted" data-pick-count>None picked</span>
  <?php if ($show === 'done'): ?><button name="action" value="bulk_new" class="primary" data-pick-go disabled>Open picked again</button>
  <?php else: ?><button name="action" value="bulk_done" class="primary" data-pick-go disabled>Mark picked done</button><?php endif; ?>
  <?php if ($SUBS && $show !== 'done'): ?><button name="action" value="bulk_auto" data-pick-go disabled title="PC submissions only: each runs every check and waits on Auto-add for you to Publish">Send picked to Auto-add</button><?php endif; ?>
  <button name="action" value="bulk_delete" class="danger" data-pick-go disabled onclick="return confirm('Delete the picked ones for good?')">Delete picked</button>
  <?php if ($show === 'logs'): ?>
  <button formaction="log-zip.php" data-pick-go disabled>Download picked</button>
  <a class="btn" href="log-zip.php?ids=<?= h(implode(',', array_map(fn($r) => (int)$r['id'], $rows))) ?>">Download this page</a>
  <span class="muted small">Downloads are one zip, a folder per person, with their logs and a message.txt</span>
  <?php endif; ?>
</form>
<?php endif; ?>
<section class="list">
<?php $lastDay = ''; foreach ($rows as $r): $bug = $r['kind'] === 'bug';
  // (3.7) A heading for each day: pick that day, or mark all of it done (every page of it).
  $dk = rs_date('Y-m-d', strtotime((string)$r['created']));
  if ($dk !== $lastDay): $lastDay = $dk; $dIds = $dayAll[$dk] ?? []; $dn = count($dIds); ?>
  <div class="day-head">
    <label class="check"><input type="checkbox" data-pick-day="<?= h($dk) ?>"> <?= h($dk === rs_date('Y-m-d', time()) ? 'Today' : ($dk === rs_date('Y-m-d', time() - 86400) ? 'Yesterday' : rs_date('l, M j', strtotime((string)$r['created'])))) ?></label>
    <span class="muted"><?= $dn ?> <?= $dn === 1 ? 'item' : 'items' ?></span>
    <form method="post"<?= $show === 'done' ? '' : ' onsubmit="return confirm(\'Mark all ' . $dn . ' from this day done?\')"' ?>><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="ids" value="<?= h(implode(',', $dIds)) ?>"><input type="hidden" name="back" value="<?= h($here(['page' => $page])) ?>"><button name="action" value="<?= $show === 'done' ? 'bulk_new' : 'bulk_done' ?>"><?= $show === 'done' ? 'Open the whole day again' : ($dn === 1 ? 'Mark it done' : 'Mark all ' . $dn . ' done') ?></button></form>
  </div>
  <?php endif; $label = ['bug' => 'Bug', 'submit' => 'Submission', 'message' => 'Message', 'logs' => 'Logs', 'source' => 'Source reported', 'copyright' => $r['version'] === 'counter' ? 'Counter-notice' : 'Copyright notice'][$r['kind']] ?? $r['kind']; ?>
  <article class="msg<?= $r['status'] === 'new' ? ' unread' : '' ?>" id="m<?= (int)$r['id'] ?>" data-day="<?= h($dk) ?>">
    <?php if ($r['kind'] === 'logs'): $fs = $files[(int)$r['id']] ?? []; ?>
    <header>
      <input type="checkbox" class="log-pick" value="<?= (int)$r['id'] ?>" aria-label="Pick this one">
      <span class="tag cyan">Logs</span><?= $pfPill($r) ?>
      <?php if (($r['feedback'] ?? '') !== ''): ?><span class="tag fb-<?= h($r['feedback']) ?>" title="Tester feedback<?= ($r['build'] ?? '') !== '' ? ' on ' . h($r['build']) : '' ?>">Feedback · <?= h(RS_VERDICTS[$r['feedback']] ?? 'Other') ?></span>
      <?php else: ?><span class="tag cat-<?= h($r['category'] ?? '') ?>"><?= h(RS_LOG_CATEGORIES[$r['category'] ?? ''] ?? 'No category') ?></span><?php endif; ?>
      <strong><?= h($nameOf($r)) ?></strong>
      <?php if (isset($sigOf[(int)$r['id']]) && ($sc = $sigCount[$sigOf[(int)$r['id']][0] . '|' . $sigOf[(int)$r['id']][1]] ?? 0) > 1): ?><span class="tag bad" title="<?= h($sigOf[(int)$r['id']][1]) ?>">Same problem in <?= (int)$sc ?> logs</span><?php endif; ?>
      <span class="muted"><?= $r['version'] !== '' ? 'version ' . h($r['version']) : 'not installed' ?><?= $r['name'] !== '' ? ' · ' . h($r['name']) : '' ?></span>
      <span class="tag who" title="<?= $r['contact'] !== '' ? 'Console ' . h($r['contact']) : 'No console code: named from their address' ?>"><?= h(rs_log_person($r)) ?></span>
      <?php if ($r['contact'] !== ''): ?><span class="tag beta" title="Console <?= h($r['contact']) ?>">Beta tester</span><?php endif; ?>
      <span class="muted when"><?= h(rs_date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <?php $rep = $reports[(int)$r['id']] ?? null; $pics = $pictures[(int)$r['id']] ?? []; ?>
    <?php if (isset($sigOf[(int)$r['id']])): ?><p class="log-sig"><code><?= h($sigOf[(int)$r['id']][1]) ?></code></p><?php endif; ?>
    <?php if (trim($r['body']) !== ''): ?><p class="body"><?= nl2br(h($r['body'])) ?></p><?php elseif (!$rep): ?><p class="body muted">No message, just the logs.</p><?php endif; ?>
    <?php if ($rep): ?>
    <div class="phone-report">
      <span class="kicker">Finished on their phone · <?= h(rs_date('M j, g:i a', strtotime($rep['finished']))) ?></span>
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
      $sHost = rs_host_norm((string)($d['host'] ?? '')); $sKey = rs_repo_key($sHost, (string)$d['repo']); // (3.9) GitHub or GitLab
      $rel = rs_host_repo_ok($sHost, (string)$d['repo']) ? rs_host_releases($sHost, (string)$d['repo'], 7 * 86400) : ['status' => 0, 'body' => '']; // (3.3) a week-old copy is fine here
      $rels = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
      $latest = is_array($rels) && $rels ? $rels[0] : null; ?>
    <header>
      <input type="checkbox" class="log-pick" value="<?= (int)$r['id'] ?>" aria-label="Pick this one">
      <span class="tag cyan">Submission · <?= h(['games' => 'Game port', 'emulators' => 'Emulator', 'apps' => 'App', 'tools' => 'Tool'][$d['category']] ?? 'App') ?></span>
      <strong><?= h($d['name']) ?></strong>
      <span class="muted">by <?= h($d['author']) ?></span>
      <?php if (!empty($d['resubmitted'])): ?><span class="tag cyan" title="<?= h('We asked for: ' . (string)($d['was_declined']['reason'] ?? '')) ?>">Sent again with changes</span><?php endif; ?>
      <?php if (!empty($d['declined'])): ?><span class="tag bad" title="<?= h((string)$d['declined']['reason']) ?>">Declined · waiting for their fix</span><?php elseif (!empty($d['turned_down'])): ?><span class="tag bad" title="<?= h((string)$d['turned_down']['reason']) ?>">Declined</span><?php endif; ?>
      <?php if (($d['platform'] ?? '') === 'pc'): ?><a class="tag pf-pc" href="pc.php" title="A recomp for RecompLauncher, the PC side">PC recomp</a><?php else: ?><a class="tag pf-ps5" href="index.php" title="An app for RecompStore, the PS5 side">PS5 app</a><?php endif; ?>
      <?php if (!empty($d['account'])): ?><a class="tag<?= !empty($d['verified']) ? ' cyan' : '' ?>" href="dev-accounts.php" title="Sent in from their developer hub"><?= !empty($d['verified']) ? 'Verified developer' : 'Developer account' ?> · <?= h((string)($d['account_name'] ?? '')) ?></a><?php endif; ?>
      <?php if (preg_match('/^[A-Z]{4}\d{5}$/', (string)$d['title_id'])): $cl = array_filter(rs_tid_uses((string)$d['title_id'])['uses'], fn($u) => $u['key'] !== strtolower((string)$d['repo']) && $u['where'] !== 'submission'); if ($cl): ?>
      <span class="tag bad" title="<?= h(implode('; ', array_map(fn($u) => rs_tid_where($u['where']) . ': ' . $u['name'], $cl))) ?>">Title ID clash: <?= h((string)$d['title_id']) ?></span><?php endif; endif; ?>
      <span class="muted when"><?= h(rs_date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <div class="sub">
      <?= function_exists('rs_sub_precheck_html') ? rs_sub_precheck_html($d, $latest, (int)$rel['status']) : '' // (3.8) ?>
      <?php if ($i = $byRole('hero')): ?><div class="sub-hero"><img src="picture.php?id=<?= (int)$i[0]['id'] ?>" alt="Backdrop"><?php if ($l = $byRole('logo')): ?><img class="sub-logo" src="picture.php?id=<?= (int)$l[0]['id'] ?>" alt="Logo"><?php endif; ?></div><?php endif; ?>
      <div class="sub-top">
        <?php if ($i = $byRole('icon')): ?><img class="sub-icon" src="picture.php?id=<?= (int)$i[0]['id'] ?>" alt="Icon"><?php endif; ?>
        <dl class="sub-facts">
          <dt><?= h(rs_host_name($sHost)) ?></dt><dd><a href="<?= h(rs_host_web($sHost, (string)$d['repo'])) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://#', '', rs_host_web($sHost, (string)$d['repo']))) ?></a>
            <?php if ($latest): ?><br><span class="muted">Newest release <?= h((string)$latest['tag_name']) ?><?= !empty($latest['published_at']) ? ', ' . h(rs_date('M j, Y', strtotime((string)$latest['published_at']))) : '' ?>: <?= h(implode(', ', array_map(fn($a) => (string)$a['name'], array_slice((array)($latest['assets'] ?? []), 0, 6))) ?: 'no files') ?></span>
            <?php elseif ($rel['status'] === 404): ?><br><span class="warn"><?= h(rs_host_name($sHost)) ?> can't find it (private or misspelled?)</span>
            <?php elseif ($rel['status'] === 200): ?><br><span class="warn">No releases yet</span><?php endif; ?></dd>
          <?php // (3.8) What VirusTotal says about its release files.
          if (function_exists('rs_vt_on') && rs_vt_on() && $latest): $vb = rs_vt_repo_badge($sKey, (string)$latest['tag_name']); ?>
          <dt>Security</dt><dd><?= $vb ? rs_vt_badge_html($vb) : '<span class="vt-badge grey"><span class="vt-dot">…</span>In line for a VirusTotal check</span>' ?></dd>
          <?php endif; ?>
          <?php if (($d['platform'] ?? '') === 'pc' && is_array($d['assets'] ?? null)): // (3.6) its files, checked against the newest release
            $relNames = $latest ? array_map(fn($x) => (string)$x['name'], (array)($latest['assets'] ?? [])) : [];
            $globOk = fn(string $g) => (bool)array_filter($relNames, fn($n) => fnmatch($g, $n, FNM_CASEFOLD)); ?>
          <dt>Runs on</dt><dd><?php foreach ($d['assets'] as $k => $g): ?><?= h(['win' => 'Windows', 'mac' => 'macOS', 'linux' => 'Linux'][$k] ?? $k) ?>: <code><?= h($g) ?></code> <?= $latest ? ($globOk($g) ? '<span class="ok">✓ matches</span>' : '<span class="warn">no match in the newest release</span>') : '' ?><br><?php endforeach; ?></dd>
          <dt>Console</dt><dd><?= h(RS_PC_ORIGINS[$d['origin'] ?? ''] ?? '—') ?><?= ($d['disc'] ?? '') !== '' ? ' · ' . h($d['disc']) : '' ?></dd>
          <dt>RecompLauncher</dt><dd>Quick install: <?= ($d['rl_quick'] ?? '') === 'yes' ? '<span class="ok">yes</span>' : (($d['rl_quick'] ?? '') === 'setup' ? '<span class="warn">needs building or setup first</span>' : 'no') ?> · Launcher settings: <?= ($d['rl_settings'] ?? '') === 'yes' ? '<span class="ok">yes</span>' : 'no' ?><?= ($d['rl_future'] ?? '') !== '' ? ' · Would add them later: ' . h($d['rl_future']) : '' ?></dd>
          <dt>Before first play</dt><dd><?= ($d['setup'] ?? '') === 'yes' ? '<strong>Needs setup</strong><br>' . nl2br(h((string)$d['setup_steps'])) : 'Ready to play' ?></dd>
          <?php if (!empty($d['features'])): ?><dt>Has</dt><dd><?= h(implode(', ', (array)$d['features'])) ?></dd><?php endif; ?>
          <?php else: ?>
          <dt>Release file</dt><dd><?= $d['asset'] !== '' ? '<code>' . h($d['asset']) . '</code>' : '<span class="muted">not given</span>' ?></dd>
          <?php endif; ?>
          <dt>Version</dt><dd><?= h($d['version'] ?: '—') ?></dd>
          <dt>Title ID</dt><dd><?php if ($d['title_id'] === ''): ?><span class="muted">none yet</span><?php else: ?><code><?= h($d['title_id']) ?></code><?= rs_game($d['title_id']) ? ' <span class="warn">already used by ' . h(rs_game($d['title_id'])['name']) . '</span>' : (isset(rs_taken_ids()[$d['title_id']]) ? ' <span class="warn">taken outside the store</span>' : '') ?><?php endif; ?></dd>
          <dt>Contact</dt><dd><?= filter_var($d['contact'], FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . h($d['contact']) . '">' . h($d['contact']) . '</a>' : h($d['contact']) ?></dd>
          <?php if ($d['needs'] !== ''): ?><dt>Players need</dt><dd><?= h($d['needs']) ?></dd><?php endif; ?>
          <?php if ($d['links']): ?><dt>Links</dt><dd><?php foreach ($d['links'] as $u): ?><a href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://(www\.)?#', '', $u)) ?></a><br><?php endforeach; ?></dd><?php endif; ?>
        </dl>
      </div>
      <?php if ($d['blurb'] !== ''): ?><p class="body"><strong><?= h($d['blurb']) ?></strong></p><?php endif; ?>
      <p class="body"><?= nl2br(h($d['description'])) ?></p>
      <?php if (in_array($d['ai'] ?? '', ['yes', 'no'], true)): ?><p class="body"><strong>AI:</strong> <?= ($d['ai'] ?? '') === 'yes' ? 'Yes, for ' . h(implode(', ', array_map(fn($k) => strtolower(RS_AI_USES[$k] ?? $k), rs_ai_uses_clean($d['ai_uses'] ?? [])))) . (($d['ai_note'] ?? '') !== '' ? ' ("' . h($d['ai_note']) . '")' : '') : 'No, they say' ?></p><?php endif; ?>
      <?php if ($d['credits'] !== ''): ?><p class="body muted"><strong>Credits:</strong> <?= nl2br(h($d['credits'])) ?></p><?php endif; ?>
      <?php if ($d['notes'] !== ''): ?><p class="body"><strong>Note to you:</strong> <?= nl2br(h($d['notes'])) ?></p><?php endif; ?>
      <?php if ($sh = $byRole('shot')): ?><div class="pics"><?php foreach ($sh as $p): ?><a href="picture.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener"><img src="picture.php?id=<?= (int)$p['id'] ?>" alt="Screenshot <?= (int)$p['w'] ?>×<?= (int)$p['h'] ?>" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php elseif ($r['kind'] === 'copyright' && is_array($cd = json_decode((string)($r['data'] ?? ''), true))):
      $app = (string)$r['app_id']; $isPc = str_starts_with($app, 'pc:');
      $lst = $app === '' ? null : ($isPc ? rs_pc_app(substr($app, 3)) : rs_game($app)); ?>
    <header>
      <input type="checkbox" class="log-pick" value="<?= (int)$r['id'] ?>" aria-label="Pick this one">
      <span class="tag bad"><?= h($label) ?></span><?= $pfPill($r) ?>
      <strong><?= h($r['name']) ?><?= ($cd['company'] ?? '') !== '' ? ' · for ' . h($cd['company']) : '' ?></strong>
      <?php if ($lst): ?><span class="tag<?= !empty($lst['hidden']) ? '' : ' cyan' ?>"><?= h($lst['name']) ?><?= $isPc ? ' · PC' : ' · PS5' ?><?= !empty($lst['hidden']) ? ' · hidden' : ' · live' ?></span><?php elseif ($app !== ''): ?><span class="muted">Listing <?= h($app) ?> isn't in the admin</span><?php endif; ?>
      <span class="muted when"><?= h(rs_date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <dl class="sub-facts">
      <dt>Listing</dt><dd><a href="<?= h($r['repo']) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https?://(www\.)?#', '', $r['repo'])) ?></a></dd>
      <dt>Email</dt><dd><a href="mailto:<?= h($r['contact']) ?>"><?= h($r['contact']) ?></a></dd>
      <?php if (($cd['address'] ?? '') !== ''): ?><dt>Address, phone</dt><dd><?= nl2br(h($cd['address'])) ?></dd><?php endif; ?>
      <?php if (($cd['work'] ?? '') !== ''): ?><dt>Their work</dt><dd><?= nl2br(h($cd['work'])) ?></dd><?php endif; ?>
      <dt>Statements</dt><dd><?= ($cd['notice'] ?? '') === 'counter' ? (!empty($cd['mistake']) ? 'Mistake, under penalty of perjury ✓' : 'Mistake ✗') . ' · ' . (!empty($cd['consent']) ? 'Consents to jurisdiction ✓' : 'Jurisdiction ✗') : (!empty($cd['good_faith']) ? 'Good faith ✓' : 'Good faith ✗') . ' · ' . (!empty($cd['accurate']) ? 'Accurate, authorized, under penalty of perjury ✓' : 'Accuracy ✗') ?></dd>
      <dt>Signed</dt><dd><?= h((string)($cd['signature'] ?? '')) ?></dd>
    </dl>
    <?php if (($cd['details'] ?? '') !== ''): ?><p class="body"><?= nl2br(h($cd['details'])) ?></p><?php endif; ?>
    <?php if ($lst && empty($lst['hidden']) && ($cd['notice'] ?? '') !== 'counter'): ?>
    <form method="post" class="row-actions" onsubmit="return confirm('Hide <?= h(addslashes($lst['name'])) ?> now?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="unlist" class="danger">Hide this listing now</button><span class="muted">Then let the developer know, and reply to the sender.</span></form>
    <?php endif; ?>
    <?php else: ?>
    <header>
      <input type="checkbox" class="log-pick" value="<?= (int)$r['id'] ?>" aria-label="Pick this one">
      <span class="tag<?= $bug ? '' : ' cyan' ?>"><?= h($label) ?></span><?= $pfPill($r) ?>
      <?php if ($r['kind'] === 'source'): ?>
        <strong><?= h($r['repo']) ?></strong>
        <span class="muted">Add it to Blocked sources in Settings to take it down.</span>
      <?php elseif ($bug): ?>
        <strong><?= h($nameOf($r)) ?></strong>
        <?php if ($r['version'] !== ''): ?><span class="muted">version <?= h($r['version']) ?></span><?php endif; ?>
      <?php else: ?>
        <strong><?= h($r['name']) ?></strong>
      <?php endif; ?>
      <span class="muted when"><?= h(rs_date('M j, Y g:i a', strtotime($r['created']))) ?></span>
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
    <?php $to = rs_inbox_email($r, $reports[(int)$r['id']] ?? null); ?>
    <?php foreach ($replies[(int)$r['id']] ?? [] as $m): ?>
    <div class="sent-reply"><span class="kicker">You replied · <?= h(rs_date('M j, Y g:i a', strtotime($m['created']))) ?> · <?= h($m['to_addr']) ?></span><p class="body"><strong><?= h($m['subject']) ?></strong><br><?= nl2br(h((string)$m['body'])) ?></p></div>
    <?php endforeach; ?>
    <?php if ($to !== ''): ?>
    <?php $lm = $liveId === (int)$r['id'] ? $liveMail($r) : null; ?>
    <details class="reply"<?= $lm ? ' open' : '' ?>><summary><?= $lm ? 'Send the "you\'re live" email' : 'Reply by email' ?> · <?= h($to) ?></summary>
      <?php if (!$mailReady): ?><p class="muted">Set up the mailbox on the <a href="mail.php#setup">Mail page</a> first.</p>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="reply">
        <label>Subject<input name="subject" maxlength="200" value="<?= h($lm ? $lm['subject'] : $replySubject($r)) ?>" required></label>
        <label>Message<textarea name="body" rows="<?= $lm ? 12 : 7 ?>" required><?= h($lm ? $lm['body'] : $replyHello($r)) ?></textarea><small>Your signature and their message go under it. It's sent from <?= h(rs_mail_get('mail_from')) ?>, with a copy in Sent.</small></label>
        <label class="check"><input type="checkbox" name="done" value="1"<?= $r['status'] === 'done' ? '' : ' checked' ?>> Mark it done</label>
        <button class="primary">Send</button>
      </form>
      <?php endif; ?>
    </details>
    <?php endif; ?>
    <?php if ($r['kind'] === 'submit' && is_array($d ?? null) && !empty($r['data']) && $r['status'] !== 'done'):
      $canMail = $to !== '' && $mailReady; // (4.6) otherwise: declined here, the message sent by hand
      $who = trim((string)($d['contact'] ?? '')) !== '' ? trim((string)$d['contact']) : trim((string)($r['contact'] ?? ''));
      $fixUrl = 'https://recompstore.com/submit?fix=' . (int)$r['id'] . '&t=' . rs_sub_fix_key((int)$r['id']);
      $isPc = ($d['platform'] ?? '') === 'pc'; $where = $isPc ? 'RecompLauncher' : 'RecompStore';
      $presets = [
        ['No release yet', 'There\'s no GitHub release with a build players can download yet. Publish one with your files attached.'],
        ['Files don\'t match', 'The release files you named don\'t match the files on your latest GitHub release. Check the name for each system, with * where the version changes.'],
        ['Missing information', 'Some information is missing. Add a full description and what players need to play it.'],
        ['No screenshot', 'We need at least one screenshot of it running, in game, in your build.'],
        ['Not enough GitHub history', 'There isn\'t much history on the GitHub project yet. We list projects with some history and activity behind them, so send it again once it has grown.'],
        ['Doesn\'t run', 'We couldn\'t get it running from the release. Check that the release has everything it needs.'],
        ['Game files included', 'The release looks like it includes game files or other copyrighted content. Releases can\'t include any: players bring their own copy.'],
        ['Already listed', 'It\'s already listed on ' . $where . '.'],
        ['Not a fit', $isPc ? 'It isn\'t a recomp or native port of a console game, so it isn\'t a fit for RecompLauncher.' : 'It isn\'t a fit for RecompStore.'],
      ];
      // (4.6) Virus scan: what VirusTotal flagged on its newest release, file by file, with the report
      // links and what to do about it. Offered (and ticked) only when something was flagged.
      $vtWhy = '';
      if (function_exists('rs_vt_release') && !empty($latest) && isset($sKey)) {
          try {
              [$vRows] = rs_vt_release($sKey, (string)$latest['tag_name']);
              $vLines = [];
              foreach ((array)$vRows as $f) {
                  $n = (int)$f['malicious'] + (int)$f['suspicious'];
                  if (($f['status'] ?? '') !== 'done' || $n === 0) continue;
                  $by = trim((string)($f['flagged_by'] ?? ''));
                  $vLines[] = '- ' . $f['name'] . ': flagged by ' . $n . ' of ' . (int)$f['engines'] . ' engines' . ($by !== '' ? ' (' . $by . ')' : '') . (rs_vt_link($f) !== '' ? "\n  " . rs_vt_link($f) : '');
              }
              if ($vLines) $vtWhy = 'VirusTotal flagged your release, which is more than we currently allow:' . "\n\n" . implode("\n", $vLines) . "\n\n"
                  . 'These are often false positives, especially on unsigned programs. You can ask the vendors named above to recheck it (most have a false-positive form on their website), or change whatever is setting them off. Once the scan comes back clean, you\'re more than welcome to send it again, and we\'ll get it on the site as soon as possible.';
          } catch (Throwable $e) { $vtWhy = ''; }
      } ?>
    <details class="reply decline" data-decline id="dec-<?= (int)$r['id'] ?>">
      <summary>Decline · tell them why</summary>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="decline"><input type="hidden" name="reason" value="">
        <?php if ($vtWhy !== ''): ?><label class="check dec-vt"><input type="checkbox" data-why="<?= h($vtWhy) ?>" data-short="Virus scan" data-block="1" checked> <strong>Virus scan</strong> <span class="muted">— lists what VirusTotal flagged, with the report links</span></label><?php endif; ?>
        <div class="dec-presets"><?php foreach ($presets as [$k, $v]): ?><label class="check"><input type="checkbox" data-why="<?= h($v) ?>" data-short="<?= h($k) ?>"> <?= h($k) ?></label><?php endforeach; ?></div>
        <label>Anything else <span class="muted">(your own words, optional)</span><textarea data-own rows="2" maxlength="1000" placeholder="For example: the Linux build crashes on start."></textarea></label>
        <label class="check"><input type="checkbox" name="fix" value="1" checked> Let them fix it and send it again (adds a link that opens their submission, filled in)</label>
        <label>Subject<input name="subject" maxlength="200" value="<?= h('About your ' . $where . ' submission: ' . (string)$d['name']) ?>" required></label>
        <label>Message<textarea name="body" rows="10" required data-hello="<?= h('Hi' . (trim((string)($d['author'] ?? '')) !== '' ? ' ' . trim((string)$d['author']) : '') . ',') ?>" data-name="<?= h((string)$d['name']) ?>" data-where="<?= h($where) ?>"<?= $canMail ? '' : ' data-fixurl="' . h($fixUrl) . '"' ?>></textarea><?php if ($canMail): ?><small>Built from what you tick above, and yours to change. The fix link and your signature go under it. Sent from <?= h(rs_mail_get('mail_from')) ?>.</small><?php else: ?><small class="warn"><?= $to === '' ? 'They left no email address' . ($who !== '' ? ' (contact: ' . h($who) . ')' : '') : 'The mailbox isn\'t set up' ?>, so nothing is emailed. Copy the message (the fix link is in it) and send it to them yourself.</small><?php endif; ?></label>
        <?php if ($canMail): ?><button class="danger">Decline and send</button>
        <?php else: ?><button type="button" data-copy-decline>Copy message</button> <button class="danger">Decline</button><?php endif; ?>
      </form>
    </details>
    <?php endif; ?>
    <div class="row-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <?php if ($r['kind'] === 'submit' && !empty($r['data']) && $r['status'] !== 'done'): ?><?php if (($d['platform'] ?? '') === 'pc' && !empty($d['assets'])): ?><button name="action" value="apply_live" class="primary" onclick="return confirm('Put <?= h(addslashes((string)$d['name'])) ?> live on RecompLauncher now?')">Approve and go live</button><?php endif; ?><button name="action" value="apply"<?= ($d['platform'] ?? '') === 'pc' && !empty($d['assets']) ? '' : ' class="primary"' ?>><?= ($d['platform'] ?? '') === 'pc' ? 'Add to RecompLauncher, hidden' : 'Add to the store as a draft' ?></button><?php endif; ?>
        <?php if ($r['kind'] === 'submit' && !empty($r['data']) && $r['status'] !== 'done' && ($d['platform'] ?? '') === 'pc' && (string)($d['repo'] ?? '') !== ''):
            $onAuto = $autoBySub[(int)$r['id']] ?? null; ?>
          <?php if ($onAuto): ?><a class="btn" href="auto.php#d-<?= (int)$onAuto['id'] ?>">On Auto-add<?= $onAuto['status'] === 'review' ? ': ready to look at' : (in_array($onAuto['status'], ['queued', 'running'], true) ? ': running' : '') ?></a>
          <?php else: ?><button name="action" value="to_auto" title="Runs every check and writes the listing, then waits on Auto-add for you to Publish">Send to Auto-add</button><?php endif; ?>
        <?php endif; ?>
        <?php if ($r['kind'] === 'submit' && !empty($r['data']) && $r['status'] !== 'done'): ?><button name="action" value="<?= ($d['platform'] ?? '') === 'pc' ? 'to_ps5' : 'to_pc' ?>"><?= ($d['platform'] ?? '') === 'pc' ? 'Move to PS5' : 'Move to PC' ?></button><?php endif; ?>
        <?php if ($r['status'] === 'done'): ?><button name="action" value="new">Reopen</button>
        <?php else: ?><button name="action" value="done"<?= $r['kind'] === 'submit' && !empty($r['data']) ? '' : ' class="primary"' ?>>Mark done</button><?php endif; ?>
        <?php if ($r['kind'] === 'submit' && !empty($r['data']) && $r['status'] !== 'done'): ?><button type="button" class="danger" data-open-decline="<?= (int)$r['id'] ?>">Decline</button><?php endif; ?>
      </form>
      <form method="post" onsubmit="return confirm('Delete this message for good?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="delete" class="danger">Delete</button></form>
      <?php if ($r['kind'] === 'logs'): /* (2.6) everything they sent, in one zip */ ?><a class="btn zip-dl" href="log-zip.php?id=<?= (int)$r['id'] ?>" download title="Every log, their message as message.txt, and any pictures">Download as zip</a><?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">Nothing here.</p><?php endif; ?>
</section>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a class="btn" href="<?= h($here(['page' => $page - 1])) ?>">Newer</a><?php endif; ?>
  <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> in all</span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= h($here(['page' => $page + 1])) ?>">Older</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php
// Opening the inbox counts as reading what's shown.
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['status'] === 'new'));
    if ($ids) rs_db()->exec('UPDATE inbox SET status = \'read\' WHERE id IN (' . implode(',', $ids) . ')');
}
?>
<style>
.decline summary{color:var(--bad)}
.dec-presets{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px 16px;margin:10px 0 12px}
@media (max-width:820px){.dec-presets{grid-template-columns:1fr}}
.dec-vt{margin:10px 0 0}
</style>
<script>
// (3.6) Decline: the reasons you tick become the email, which stays yours to change before Send.
document.querySelectorAll('[data-decline]').forEach((box) => {
  const f = box.querySelector('form'), body = f.querySelector('textarea[name=body]'), own = f.querySelector('[data-own]'), fix = f.querySelector('input[name=fix]');
  let touched = false;
  body.addEventListener('input', () => { touched = true; });
  const build = () => {
    const picked = [...f.querySelectorAll('[data-why]:checked')];
    f.reason.value = [...picked.map((c) => c.dataset.short), own.value.trim()].filter(Boolean).join(' · ');
    if (touched) return;
    // One-line reasons become a list; a longer one (the virus scan) is its own paragraph.
    const lines = picked.filter((c) => !c.dataset.block).map((c) => '- ' + c.dataset.why);
    if (own.value.trim()) lines.push('- ' + own.value.trim());
    const parts = [...picked.filter((c) => c.dataset.block).map((c) => c.dataset.why)];
    if (lines.length) parts.push(lines.join('\n'));
    body.value = body.dataset.hello + '\n\n' + 'Thanks for sending in ' + body.dataset.name + '. Before it can go on ' + body.dataset.where + ', '
      + (parts.length ? 'it needs a few changes:\n\n' + parts.join('\n\n') : 'it needs a few changes.')
      + (fix.checked ? (body.dataset.fixurl ? '\n\nOpen your submission with everything filled in, fix what\'s needed and send it again here:\n' + body.dataset.fixurl : '\n\nUse the link below to open your submission with everything filled in, fix what\'s needed and send it again.') : '')
      + '\n\nThanks again, and feel free to reply with any questions.';
  };
  f.addEventListener('change', build);
  own.addEventListener('input', build);
  build();
});
// (4.6) No email: copy the message to send it by hand (Discord and so on).
document.querySelectorAll('[data-copy-decline]').forEach((b) => b.addEventListener('click', async () => {
  const t = b.closest('form').querySelector('textarea[name=body]').value;
  try { await navigator.clipboard.writeText(t); b.textContent = 'Copied'; } catch (e) { b.closest('form').querySelector('textarea[name=body]').select(); }
  setTimeout(() => { b.textContent = 'Copy message'; }, 1600);
}));
// (4.6) The Decline button next to Mark done opens the decline box and brings it into view.
document.querySelectorAll('[data-open-decline]').forEach((b) => b.addEventListener('click', () => {
  const box = document.getElementById('dec-' + b.dataset.openDecline);
  if (!box) return;
  box.open = true;
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}));
</script>
<?php
rs_foot(['assets/inbox.js']);
