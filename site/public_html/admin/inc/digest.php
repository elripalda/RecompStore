<?php
// (4.8) The weekly digest: one email to the owner every Monday morning (cron runs inc/digest-run.php at
// 15:07 UTC, 8:07 am Phoenix) with the week in short: what went live, what got updated, repos that went
// down or got renamed, updates VirusTotal held, new submissions and what players asked for most.
// Settings: digest_on ('1'/'0', on unless turned off) and digest_to (an address; the store's mailbox
// when empty). The Overview links to its page (digest.php), which shows it and can send it now.
declare(strict_types=1);
require_once __DIR__ . '/pc.php';

function rs_digest_on(): bool { return rs_setting('digest_on', '1') !== '0'; }
function rs_digest_to(): string {
    $to = trim((string)rs_setting('digest_to', ''));
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : (function_exists('rs_mail_get') ? rs_mail_get('mail_from') : '');
}

// The week up to now (or up to $until). -> ['subject', 'text', 'parts' => [[heading, lines]], 'since', 'until']
function rs_digest_build(int $until = 0): array {
    $until = $until ?: time();
    $since = $until - 7 * 86400;
    $db = rs_db();
    $inWeek = fn(string $d): bool => $d !== '' && ($t = strtotime($d)) && $t >= $since && $t <= $until;
    $parts = [];
    $apps = rs_pc_apps();
    $live = array_values(array_filter($apps, fn($a) => empty($a['hidden']) && empty($a['soon'])));

    // Went live: the day each listing went out (live_at), newest first.
    $new = array_values(array_filter($live, fn($a) => $inWeek((string)($a['live_at'] ?? ''))));
    usort($new, fn($x, $y) => strcmp((string)$y['live_at'], (string)$x['live_at']));
    $lines = array_map(fn($a) => $a['name'] . ($a['game'] !== '' && $a['game'] !== $a['name'] ? ' (' . $a['game'] . ')' : ''), array_slice($new, 0, 15));
    if (count($new) > 15) $lines[] = 'and ' . (count($new) - 15) . ' more';
    $parts[] = [count($new) . ' new on RecompLauncher', $lines ?: ['Nothing new went live this week.']];

    // Updated: a release out this week for a game that wasn't new this week, most downloaded first.
    $newIds = array_flip(array_column($new, 'id'));
    $upd = array_values(array_filter($live, fn($a) => !isset($newIds[$a['id']]) && $inWeek((string)$a['released'])));
    usort($upd, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
    $lines = array_map(fn($a) => $a['name'] . ($a['version'] !== '' ? ' ' . $a['version'] : ''), array_slice($upd, 0, 10));
    if (count($upd) > 10) $lines[] = 'and ' . (count($upd) - 10) . ' more';
    $parts[] = [count($upd) . ' updated', $lines ?: ['No updates this week.']];

    // Repo watch: what went down or came back this week, and what's still open.
    $lines = [];
    try {
        require_once __DIR__ . '/repowatch.php';
        rs_watch_tables();
        $st = $db->prepare('SELECT store, name, repo, state FROM repo_watch_log WHERE at >= ? ORDER BY id DESC LIMIT 200');
        $st->execute([gmdate('Y-m-d H:i:s', $since)]);
        $down = []; $fixed = []; $back = [];
        foreach ($st->fetchAll() as $r) {
            $lv = rs_watch_level((string)$r['state']);
            if ($r['state'] === 'fixed') $fixed[$r['name']] = true;
            elseif ($lv === 'bad') $down[$r['name']] = rs_watch_label((string)$r['state']);
            elseif ($r['state'] === 'ok') $back[$r['name']] = true;
        }
        foreach (array_slice($down, 0, 12, true) as $n => $l) $lines[] = $n . ': ' . strtolower($l);
        if ($fixed) $lines[] = count($fixed) . ' renamed repo' . (count($fixed) === 1 ? ' was' : 's were') . ' followed on their own';
        if ($back) $lines[] = count($back) . ' came back up or got a new repo';
        $open = rs_watch_count(['pc', 'ps5']);
        $lines[] = $open ? $open . ' still need a look on the Repo watch tab' : 'Nothing waiting on the Repo watch tab';
    } catch (Throwable $e) { $lines[] = 'The repo watch couldn\'t be read.'; }
    $parts[] = ['Repos', $lines];

    // Security: updates held for their virus check, and downloads flagged.
    $held = array_values(array_filter($apps, fn($a) => !empty($a['pending']['tag'])));
    $flagged = function_exists('rs_vt_need_count') ? (int)rs_vt_need_count() : 0;
    $lines = [];
    foreach (array_slice($held, 0, 8) as $a) $lines[] = $a['name'] . ' ' . (string)($a['pending']['version'] ?? $a['pending']['tag']) . ' is waiting for its check';
    if (count($held) > 8) $lines[] = 'and ' . (count($held) - 8) . ' more held';
    $lines[] = $flagged ? $flagged . ' download' . ($flagged === 1 ? ' is' : 's are') . ' flagged on the Security page' : 'Nothing flagged on the Security page';
    $parts[] = ['Security', $lines];

    // Submissions and players' requests.
    $lines = [];
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM inbox WHERE kind = 'submit' AND created >= ?");
        $st->execute([gmdate('Y-m-d H:i:s', $since)]);
        $subs = (int)$st->fetchColumn();
        $waiting = (int)$db->query("SELECT COUNT(*) FROM inbox WHERE kind = 'submit' AND status = 'new'")->fetchColumn();
        $lines[] = $subs . ' new submission' . ($subs === 1 ? '' : 's') . ' this week, ' . $waiting . ' waiting on New';
    } catch (Throwable $e) { /* no inbox yet */ }
    try {
        foreach ($db->query("SELECT url, asks FROM pc_requests WHERE status = 'new' ORDER BY asks DESC, updated DESC LIMIT 5")->fetchAll() as $r)
            $lines[] = 'Asked for ' . (int)$r['asks'] . ' time' . ((int)$r['asks'] === 1 ? '' : 's') . ': ' . preg_replace('#^https?://(www\.)?#', '', (string)$r['url']);
    } catch (Throwable $e) { /* no requests yet */ }
    $parts[] = ['Submissions and requests', $lines ?: ['Nothing new.']];

    // The catalog's size.
    $parts[] = ['RecompLauncher now', [number_format(count($live)) . ' games out, ' . number_format(count(array_filter($apps, fn($a) => empty($a['hidden']) && !empty($a['soon'])))) . ' on Coming Soon']];

    $range = gmdate('M j', $since) . ' to ' . gmdate('M j', $until);
    $text = 'Your week on RecompLauncher (' . $range . ")\n";
    foreach ($parts as [$h, $ls]) $text .= "\n" . strtoupper($h) . "\n" . implode("\n", array_map(fn($l) => '- ' . $l, $ls)) . "\n";
    $text .= "\nOpen the admin: " . rs_abs('/admin/hub.php') . "\n";
    return ['subject' => 'Your week on RecompLauncher: ' . count($new) . ' new, ' . count($upd) . ' updated', 'text' => $text, 'parts' => $parts, 'since' => $since, 'until' => $until];
}

// Sends it (once a week unless $force). -> where it went, or '' when it didn't go.
function rs_digest_send(bool $force = false): string {
    if (!$force && !rs_digest_on()) return '';
    $last = rs_kv_get('digest_sent');
    if (!$force && $last && (int)$last['v'] > time() - 6 * 86400) return '';
    $to = rs_digest_to();
    if ($to === '' || !function_exists('rs_mail_ready') || !rs_mail_ready()) throw new RuntimeException('The mailbox isn\'t set up, so the digest can\'t go out.');
    $d = rs_digest_build();
    rs_mail_send($to, $d['subject'], $d['text']);
    rs_kv_put('digest_sent', (string)time());
    rs_log('digest', 'sent', $to);
    return $to;
}
