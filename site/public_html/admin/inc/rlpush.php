<?php
// RecompLauncher (3.1g): tells launchers to look now, through a push channel on ntfy.sh.
// A message carries no data the launcher trusts: it only makes launchers (0.75 Beta 10 and later)
// read version.json, the catalog or a game's releases from this site straight away instead of on
// their next check. Messages: "launcher", "game <catalog id>", "catalog".
declare(strict_types=1);

const RL_PUSH_TOPIC = 'recomplauncher-16087873f0d60166';
const RL_PUSH_POST = 'https://ntfy.sh/' . RL_PUSH_TOPIC;
const RL_PUSH_SSE = 'https://ntfy.sh/' . RL_PUSH_TOPIC . '/sse';

function rl_push_dir(): string {
    $d = dirname(RS_PUBLIC) . '/recompstore-private';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

// Posts one message. At most once a minute for the same message (ntfy.sh limits how often one
// server posts). Any failure is ignored: launchers then find it on their next normal check.
// -> true when it went out, false when it was skipped or didn't go.
function rl_push(string $msg, bool $once_a_minute = true): bool {
    $msg = trim($msg);
    if ($msg === '' || strlen($msg) > 200) return false;
    $stamp = rl_push_dir() . '/rlpush-' . md5($msg) . '.stamp';
    if ($once_a_minute && is_file($stamp) && time() - (int)@filemtime($stamp) < 60) return false;
    @touch($stamp);
    try {
        $ch = curl_init(RL_PUSH_POST);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $msg,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $code >= 200 && $code < 300;
        @file_put_contents(rl_push_dir() . '/rlpush.log', gmdate('c') . ' ' . $msg . ' ' . $code . "\n", FILE_APPEND | LOCK_EX);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

// The newest release's tag in a releases list (what /api/gh.php hands out), drafts left out.
function rl_push_latest_tag(string $body): string {
    foreach ((array)json_decode($body, true) as $r) {
        if (!is_array($r) || !empty($r['draft'])) continue;
        return (string)($r['tag_name'] ?? '');
    }
    return '';
}

// Puts a fresh releases list into /api/gh.php's copy on disk, so a launcher asking right after a
// push gets the new release.
function rl_push_gh_put(string $repo, string $body): void {
    $dir = dirname(RS_PUBLIC) . '/recompstore-private/gh';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/' . md5(strtolower($repo)) . '.json';
    if (@file_put_contents($f . '.' . getmypid(), $body) !== false) @rename($f . '.' . getmypid(), $f);
}

// Looks for news, at most once a minute, a few seconds at a time (run after a launcher's ping has
// been answered): recomps added to or taken off pc-catalog.json ("catalog"), and new releases of
// the recomps in it, each looked at about every 30 minutes ("game <id>"). What it has seen is kept
// in a file, so it doesn't need the database for that.
function rl_push_watch(int $budget = 6): void {
    $dir = rl_push_dir();
    $stamp = $dir . '/rlpush-watch.stamp';
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 60) return;
    @touch($stamp);
    $cat = json_decode((string)@file_get_contents(RS_PUBLIC . '/pc-catalog.json'), true);
    if (!is_array($cat) || !is_array($cat['apps'] ?? null)) return;
    $sf = $dir . '/rlpush-seen.json';
    $seen = json_decode((string)@file_get_contents($sf), true);
    if (!is_array($seen)) $seen = [];

    $ids = [];
    foreach ($cat['apps'] as $a) if (is_array($a) && (string)($a['id'] ?? '') !== '') $ids[] = (string)$a['id'];
    sort($ids);
    $sig = md5(implode(',', $ids));
    if (($seen['_catalog'] ?? '') !== '' && $seen['_catalog'] !== $sig) rl_push('catalog');
    $seen['_catalog'] = $sig;

    // (3.9) A GitLab project ("host": "gitlab") is asked of GitLab, and its copy kept for /api/gl.php.
    $apps = array_values(array_filter($cat['apps'], fn($a) => is_array($a) && (string)($a['id'] ?? '') !== '' && rs_host_repo_ok((string)($a['host'] ?? ''), (string)($a['repo'] ?? ''))));
    usort($apps, fn($x, $y) => (int)($seen['g'][$x['id']]['at'] ?? 0) <=> (int)($seen['g'][$y['id']]['at'] ?? 0));
    $start = microtime(true);
    foreach ($apps as $a) {
        if (microtime(true) - $start > $budget) break;
        $id = (string)$a['id'];
        if (time() - (int)($seen['g'][$id]['at'] ?? 0) < 1800) break; // oldest first: the rest were looked at more lately
        $seen['g'][$id]['at'] = time();
        $repo = (string)$a['repo'];
        $host = rs_host_norm((string)($a['host'] ?? '')); // (4.3) GitHub, GitLab or SourceForge
        try { $r = rs_host_releases($host, $repo, 1800); } catch (Throwable $e) { continue; }
        if ((int)($r['status'] ?? 0) !== 200) continue;
        $tag = rl_push_latest_tag((string)$r['body']);
        if ($tag === '') continue;
        $was = (string)($seen['g'][$id]['tag'] ?? '');
        $seen['g'][$id]['tag'] = $tag;
        if ($was === '' || $was === $tag) continue;
        // A new release: a fresh copy for /api/gh.php first, then the message.
        try {
            $f = rs_host_releases($host, $repo, 0);
            if ((int)($f['status'] ?? 0) === 200 && empty($f['stale'])) { if ($host !== '') rs_host_disk_put($host, $repo, (string)$f['body']); else rl_push_gh_put($repo, (string)$f['body']); }
        } catch (Throwable $e) { /* the 5-minute copy runs out on its own */ }
        rl_push('game ' . $id);
    }
    if (@file_put_contents($sf . '.tmp', json_encode($seen)) !== false) @rename($sf . '.tmp', $sf);
}
