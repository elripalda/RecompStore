<?php
// (4.6) SDK support's data: every listed recomp with a repo, whether it ships its own recomplauncher.json,
// the marks (pull request open, won't support it, pull requests closed). Shared by sdk-support.php and
// the PR queue (inc/prq.php, api/prq.php).
declare(strict_types=1);


// Recomps taken off the list by hand: a pull request is already open for it ('pr'), or its developer
// said they don't want to support RecompLauncher ('no'). [id => [kind, url, note, who, at]]
function rs_sdk_marks(): array { return json_decode((string)rs_setting('sdk_marks', '{}'), true) ?: []; }
function rs_sdk_marks_save(array $m): void { rs_set_setting('sdk_marks', (string)json_encode($m, JSON_UNESCAPED_SLASHES)); }
// A GitHub link has to be on this recomp's own repo: github.com/<owner>/<repo>/(pull|issues|discussions)/<n>.
function rs_sdk_link(array $a, string $url, bool $prOnly): array {
    $url = trim($url);
    if (!preg_match('#^https?://(?:www\.)?github\.com/([\w.-]+)/([\w.-]+)/(pull|issues|discussions)/(\d+)#i', $url, $m)) return [null, $prOnly ? 'Paste the pull request\'s GitHub link, like github.com/owner/repo/pull/12.' : 'Paste a GitHub link to the pull request, issue or discussion.'];
    if (strcasecmp($m[1] . '/' . $m[2], (string)$a['repo']) !== 0) return [null, 'That link is on ' . $m[1] . '/' . $m[2] . ', not on ' . $a['repo'] . '. Check you pasted the right one.'];
    if ($prOnly && strtolower($m[3]) !== 'pull') return [null, 'That\'s an issue or discussion, not a pull request.'];
    $kind = strtolower($m[3]) === 'pull' ? 'pulls' : (strtolower($m[3]) === 'issues' ? 'issues' : '');
    if ($kind !== '') {
        $r = rs_http(rs_gh_api() . '/repos/' . $a['repo'] . '/' . $kind . '/' . $m[4], ['github' => true, 'timeout' => 10, 'max' => 2000000]);
        if ($r['status'] === 404) return [null, 'GitHub says #' . $m[4] . ' doesn\'t exist on ' . $a['repo'] . '.'];
    }
    return ['https://github.com/' . $a['repo'] . '/' . strtolower($m[3]) . '/' . $m[4], ''];
}
// A pull request's state on GitHub (open, merged or closed), kept an hour.
function rs_sdk_pr_state(array $a, string $url): string {
    if (!preg_match('#/pull/(\d+)$#', $url, $m)) return '';
    $k = 'sdkpr:' . $a['id'] . ':' . $m[1];
    if (($c = rs_kv_get($k)) && $c['age'] < 3600) return $c['v'];
    $r = rs_http(rs_gh_api() . '/repos/' . $a['repo'] . '/pulls/' . $m[1], ['github' => true, 'timeout' => 8, 'max' => 2000000]);
    $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
    $st = is_array($j) ? (!empty($j['merged_at']) ? 'merged' : (string)($j['state'] ?? '')) : '';
    if ($st !== '') rs_kv_put($k, $st);
    return $st;
}

// Every listed recomp with a repo, most downloaded first.
function rs_sdk_apps(): array {
    $apps = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== ''));
    usort($apps, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
    return $apps;
}
// All the checks in one query instead of one per recomp. [id => [v, age, t]]
function rs_sdk_checks(): array {
    $out = [];
    try {
        rs_cache_tables();
        foreach (rs_db()->query("SELECT k, v, TIMESTAMPDIFF(SECOND, at, NOW()) AS age, UNIX_TIMESTAMP(at) AS t FROM kv_cache WHERE k LIKE 'sdk:%'") as $kr)
            $out[substr((string)$kr['k'], 4)] = ['v' => json_decode((string)$kr['v'], true), 'age' => (int)$kr['age'], 't' => (int)$kr['t']];
    } catch (Throwable $e) { rs_errlog('sdk checks', $e); }
    return $out;
}
// One recomp's row: its status, the README badge, what Easy Setup runs on today, and whether it's
// due a check (never checked, older than a week, or checked before the badge was looked at).
function rs_sdk_row(array $a, int $rank, ?array $c, array $marks): array {
    $r = $c && is_array($c['v']) ? $c['v'] : null;
    $got = $r ? (string)($r['has'] ?? 'unknown') : 'waiting';
    $mk = $marks[$a['id']] ?? null;
    // A pull request that got merged and shipped: it's done, whatever the mark says.
    if (in_array($got, ['release', 'repo'], true)) $state = 'ships';
    elseif ($mk) $state = in_array($mk['kind'], ['pr', 'locked'], true) ? $mk['kind'] : 'no';
    elseif ($got === 'none') $state = 'missing';
    else $state = 'unknown';
    $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $now = is_array($boot) && $boot ? ((int)($boot['format'] ?? 0) === 1 && isset($boot['start']) ? 'json' : 'profile') : (!empty($a['setup_required']) ? 'steps' : 'none');
    return ['a' => $a, 'rank' => $rank, 'state' => $state, 'waiting' => $got === 'waiting', 'm' => $mk, 'now' => $now,
        'kind' => in_array((string)$a['origin'], ['pc', 'other'], true) ? 'pc' : 'console',
        'pr' => $state === 'pr' ? rs_sdk_pr_state($a, (string)$mk['url']) : '',
        'badge' => $r && array_key_exists('badge', $r) ? (bool)$r['badge'] : null,
        // Which badges (checks from before the list only knew the RecompLauncher one)
        'badges' => $r ? (array)($r['badges'] ?? (!empty($r['badge']) ? ['launcher'] : [])) : [],
        'dlss' => !empty($r['dlss']),
        'due' => !$r || $c['age'] >= 7 * 86400 || !array_key_exists('badge', $r)];
}
// Every row, the counts per status, and the ones due a check (most downloaded first).
function rs_sdk_all(): array {
    $apps = rs_sdk_apps();
    $checked = rs_sdk_checks();
    $marks = rs_sdk_marks();
    $rows = [];
    $count = ['all' => 0, 'missing' => 0, 'ships' => 0, 'pr' => 0, 'no' => 0, 'locked' => 0, 'unknown' => 0, 'badge' => 0, 'todo' => 0];
    $due = [];
    foreach ($apps as $i => $a) {
        $row = rs_sdk_row($a, $i + 1, $checked[$a['id']] ?? null, $marks);
        $row['t'] = (int)($checked[$a['id']]['t'] ?? 0);
        $rows[$a['id']] = $row;
        ++$count['all'];
        ++$count[$row['state']];
        if ($row['badge']) ++$count['badge'];
        if ($row['due']) { ++$count['todo']; $due[] = $a['id']; }
    }
    return [$rows, $count, $due];
}
