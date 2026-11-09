<?php
// (3.8) Things the admin does on its own:
//  - every morning (6 am Phoenix): looks on GitHub for new PC recomps and pre-checks each one;
//  - every morning: a PS5 app whose download was renamed gets its file pattern fixed;
//  - all the time: a new version of a game already listed goes onto that game's Versions page;
//  - on each submission: one line that says whether it's ready to approve, or what's wrong.
declare(strict_types=1);

// ---- Mornings ------------------------------------------------------------------------------------

// The latest 6 am in Phoenix (UTC-7, no daylight saving), as a timestamp.
function rs_auto_morning(): int {
    $t = gmmktime(13, 0, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
    return time() >= $t ? $t : $t - 86400;
}

// Called after a page view (api/charts.php): once a morning, the finder, the PS5 file fix and the
// grouping run after the visitor already has their answer.
function rs_auto_morning_maybe(): void {
    try {
        $last = rs_kv_get('auto_morning');
        if ($last && (int)$last['v'] >= rs_auto_morning()) return;
        rs_kv_put('auto_morning', (string)time());
    } catch (Throwable $e) { return; }
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(300);
        try { rs_auto_morning_run(); } catch (Throwable $e) { error_log('morning: ' . $e->getMessage()); }
    });
}

function rs_auto_morning_run(): array {
    $out = ['found' => 0, 'ps5_fixed' => [], 'grouped' => 0];
    try { [$out['found']] = rs_pc_find(120); } catch (Throwable $e) { /* tomorrow */ }
    try { $out['found'] += rs_pc_find_discord(40)[0]; } catch (Throwable $e) { /* tomorrow */ } // (4.1)
    rs_auto_prevet();
    try { $out['ps5_fixed'] = rs_ps5_heal(); } catch (Throwable $e) { /* tomorrow */ }
    try { $out['grouped'] = rs_titles_autogroup(); } catch (Throwable $e) { /* next time */ }
    rs_kv_put('auto_morning_last', (string)json_encode($out + ['at' => gmdate('c')]));
    return $out;
}

// ---- Pre-checked GitHub finds --------------------------------------------------------------------

// A find's game name, the way lists compare them.
function rs_auto_norm(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/\b(recomp(iled|ilation)?|pc port|port|decomp(ilation)?|for pc|native|the|ps5|remaster(ed)?)\b/', ' ', $s) ?? $s;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
}

// What's already listed, by game: [norm => [names]].
function rs_auto_listed_games(): array {
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    foreach (rs_pc_apps() as $a) { $k = rs_auto_norm($a['game'] !== '' ? $a['game'] : $a['name']); if ($k !== '') $out[$k][] = $a['name']; }
    foreach (rs_games() as $g) { $k = rs_auto_norm((string)$g['name']); if ($k !== '') $out[$k][] = $g['name'] . ' (PS5)'; }
    return $out;
}

// Each new find gets its VirusTotal check queued (once). The list shows the rest when drawn.
function rs_auto_prevet(): void {
    if (!function_exists('rs_vt_on') || !rs_vt_on()) return;
    foreach (rs_pc_found('new') as $f) {
        if (strtotime((string)($f['found_at'] ?? $f['found'] ?? '')) < time() - 3 * 86400 && !empty($f['found_at'])) continue;
        $list = rs_vt_peek_releases((string)($f['key'] ?? $f['repo'])); // (3.9) gitlab.com/<project> for GitLab
        $r = rs_vt_newest($list, false) ?? rs_vt_newest($list);
        if (!$r) continue;
        $pats = array_filter([(string)($f['assets']['win'] ?? ''), (string)($f['assets']['linux'] ?? ''), (string)($f['assets']['mac'] ?? '')]);
        foreach (rs_vt_pick((array)$r['assets'], $pats) as $x) rs_vt_add((string)($f['key'] ?? $f['repo']), (string)$r['tag_name'], $x, 'finder', 'found', 4);
    }
}

// The notes the Found list shows for one find. -> [[kind ok|warn|bad, text]]
function rs_auto_find_notes(array $f): array {
    $notes = [];
    $game = (string)($f['game'] ?? '');
    $k = rs_auto_norm($game !== '' ? $game : (string)($f['name'] ?? ''));
    $listed = rs_auto_listed_games();
    if ($k !== '' && isset($listed[$k])) $notes[] = ['ok', 'Another version of ' . $listed[$k][0] . ': goes on its Versions page'];
    if (!empty($f['flags']['gamefiles'])) $notes[] = ['bad', 'Has a file that looks like game data'];
    if (!empty($f['flags']['huge'])) $notes[] = ['warn', 'Has a file over 3 GB'];
    if (!empty($f['flags']['setup_kit'])) $notes[] = ['warn', 'A setup kit that builds it from your copy: goes in as Setup required'];
    if (!empty($f['flags']['installer_only'])) $notes[] = ['warn', 'Only an installer for Windows'];
    if (!empty($f['archived'])) $notes[] = ['warn', 'Archived'];
    if (!empty($f['fork'])) $notes[] = ['warn', 'A fork'];
    $age = !empty($f['released']) ? (time() - (int)strtotime((string)$f['released'])) / 86400 : 999;
    if ($age <= 14) $notes[] = ['ok', 'New release this fortnight'];
    elseif ($age > 365) $notes[] = ['warn', 'No release in over a year'];
    return $notes;
}

// ---- One game, one listing ----------------------------------------------------------------------

// PC recomps of the same game from the same console go under one title: into the one it already
// has, or a new one when there are two. -> how many were placed.
function rs_titles_autogroup(): int {
    $titles = rs_titles();
    $taken = [];
    $byKey = [];
    foreach ($titles as $t) {
        foreach ($t['pc'] as $id) $taken[$id] = $t['id'];
        $byKey[rs_auto_norm($t['name']) . '|' . $t['origin']] = $t['id'];
    }
    $loose = [];
    foreach (rs_pc_apps() as $a) {
        if (isset($taken[$a['id']]) || $a['origin'] === 'other' || $a['origin'] === '') continue;
        $k = rs_auto_norm($a['game'] !== '' ? $a['game'] : $a['name']);
        if ($k === '') continue;
        $loose[$k . '|' . $a['origin']][] = $a;
    }
    $n = 0;
    foreach ($loose as $key => $apps) {
        if (isset($byKey[$key])) {
            foreach ($apps as $a) { rs_title_add_member($byKey[$key], 'pc', $a['id']); rs_log('versions', $a['id'], 'added to ' . $byKey[$key]); $n++; }
            continue;
        }
        if (count($apps) < 2) continue;
        $name = $apps[0]['game'] !== '' ? $apps[0]['game'] : $apps[0]['name'];
        $t = rs_title_defaults();
        $t['id'] = rs_title_new_id($name);
        $t['name'] = $name;
        $t['origin'] = $apps[0]['origin'];
        $t['pc'] = array_map(fn($a) => $a['id'], $apps);
        rs_title_save($t);
        rs_log('versions', $t['id'], 'grouped ' . implode(', ', $t['pc']));
        $n += count($apps);
    }
    return $n;
}

// ---- PS5: a renamed download -------------------------------------------------------------------

// A PS5 app whose newest release has no file matching its pattern, but exactly one zip or 7z that
// looks like it: the pattern follows the new name. -> [[id, old, new]]
function rs_ps5_heal(): array {
    $fixed = [];
    foreach (rs_games() as $g) {
        if (($g['source'] ?? '') !== 'github' || !rs_repo_ok((string)$g['repo']) || (string)$g['asset'] === '' || (string)($g['inside'] ?? '') !== '') continue;
        $rel = rs_gh_releases((string)$g['repo'], 3600);
        $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
        $newest = null;
        foreach ($list as $r) if (empty($r['draft'])) { $newest = $r; break; }
        if (!$newest) continue;
        $names = array_map(fn($x) => (string)$x['name'], (array)$newest['assets']);
        $hit = false;
        foreach ($names as $n) if (rs_pattern_matches((string)$g['asset'], $n)) { $hit = true; break; }
        if ($hit) continue;
        $zips = array_values(array_filter($names, fn($n) => preg_match('/\.(zip|7z)$/i', $n) && !preg_match('/(source|src|usb|debug|symbols|windows|win64|linux|mac|android|sha256)/i', $n)));
        if (count($zips) !== 1) continue;
        // It has to look like the same download: the part of the old pattern before the first *.
        $stem = strtolower((string)preg_replace('/[*?].*$/', '', (string)$g['asset']));
        if ($stem !== '' && strlen($stem) >= 3 && !str_starts_with(strtolower($zips[0]), substr($stem, 0, max(3, min(strlen($stem), 12))))) continue;
        $new = rs_pc_pattern($zips[0], (string)$newest['tag_name']);
        if ($new === '' || $new === $g['asset']) continue;
        $old = (string)$g['asset'];
        $g['asset'] = $new;
        rs_save_game($g);
        rs_log('ps5-asset', $g['id'], "$old -> $new");
        $fixed[] = [$g['id'], $old, $new];
        // Out at once when the catalog isn't signed; else with the next Publish.
        if (!rs_sign_on()) {
            $cat = json_decode((string)@file_get_contents(RS_CATALOG), true);
            if (is_array($cat)) {
                $hitCat = false;
                foreach (['apps', 'newerStoreApps', 'v2Apps'] as $list2) foreach ((array)($cat[$list2] ?? []) as $i => $e)
                    if (($e['id'] ?? '') === $g['id'] && is_array($e['source'] ?? null) && ($e['source']['type'] ?? '') === 'github') { $cat[$list2][$i]['source']['asset'] = $new; $hitCat = true; }
                if ($hitCat) rs_write_catalog($cat);
            }
        }
    }
    if ($fixed) rs_kv_put('ps5_heal_waiting', (string)json_encode(rs_sign_on() ? $fixed : []));
    return $fixed;
}

// ---- Submissions: ready or not ------------------------------------------------------------------

// -> [kind ready|check|stop, [[ok|warn|bad, text]]]
function rs_sub_precheck(array $d, ?array $latest, int $relStatus): array {
    $items = [];
    $pc = ($d['platform'] ?? '') === 'pc';
    $host = rs_host_norm((string)($d['host'] ?? '')); // (3.9)
    if ($relStatus === 404) $items[] = ['bad', rs_host_name($host) . ' can\'t find the ' . ($host === 'gitlab' ? 'project' : 'repo')];
    elseif (!$latest) $items[] = ['bad', 'No release to download yet'];
    else {
        $names = array_map(fn($x) => (string)$x['name'], (array)($latest['assets'] ?? []));
        $pats = $pc ? array_values((array)($d['assets'] ?? [])) : array_filter([(string)($d['asset'] ?? '')]);
        $miss = array_filter($pats, fn($p) => !array_filter($names, fn($n) => rs_pattern_matches((string)$p, $n)));
        if (!$pats) $items[] = ['warn', 'No release file named'];
        elseif ($miss) $items[] = ['bad', 'Release file not in the newest release: ' . implode(', ', $miss)];
        else $items[] = ['ok', 'Release files match'];
        if (!empty($latest['prerelease'])) $items[] = ['warn', 'Newest release is a pre-release'];
        if (function_exists('rs_vt_on') && rs_vt_on()) {
            [$rows, $v] = rs_vt_release(rs_repo_key($host, (string)$d['repo']), (string)$latest['tag_name']);
            if (!$rows || $v === 'grey') $items[] = ['warn', 'VirusTotal still checking'];
            elseif ($v === 'red') $items[] = ['bad', 'Flagged by VirusTotal'];
            elseif ($v === 'yellow') $items[] = ['ok', 'VirusTotal: a minor flag (common for unsigned programs)'];
            else $items[] = ['ok', 'VirusTotal: clean'];
        }
    }
    $repo = strtolower(rs_repo_key($host, (string)($d['repo'] ?? '')));
    foreach (rs_pc_apps() as $a) if (strtolower(rs_pc_key($a)) === $repo) { $items[] = ['bad', 'Already on RecompLauncher as ' . $a['name']]; break; }
    foreach (rs_games() as $g) if (strtolower((string)$g['repo']) === $repo) { $items[] = ['bad', 'Already on RecompStore as ' . $g['name']]; break; }
    $k = rs_auto_norm((string)($d['name'] ?? ''));
    $listed = rs_auto_listed_games();
    if ($k !== '' && isset($listed[$k])) $items[] = ['ok', 'Another version of ' . $listed[$k][0] . ': it goes on its Versions page'];
    if (!$pc && preg_match('/^[A-Z]{4}\d{5}$/', (string)($d['title_id'] ?? '')) && rs_game((string)$d['title_id'])) $items[] = ['bad', 'Title ID already used by ' . rs_game((string)$d['title_id'])['name']];
    if ($pc && ($d['ai'] ?? '') === 'yes') $items[] = ['warn', 'AI-assisted (shown on its page)'];
    $kind = array_filter($items, fn($x) => $x[0] === 'bad') ? 'stop' : (array_filter($items, fn($x) => $x[0] === 'warn') ? 'check' : 'ready');
    return [$kind, $items];
}

function rs_sub_precheck_html(array $d, ?array $latest, int $relStatus): string {
    [$kind, $items] = rs_sub_precheck($d, $latest, $relStatus);
    $head = ['ready' => 'Ready to approve', 'check' => 'Looks good, with a note', 'stop' => 'Needs a look before approving'][$kind];
    $icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'];
    $out = '<div class="pre-check ' . $kind . '"><strong>' . h($head) . '</strong><ul>';
    foreach ($items as [$k, $t]) $out .= '<li class="' . $k . '"><span>' . $icon[$k] . '</span>' . h($t) . '</li>';
    return $out . '</ul></div>';
}
