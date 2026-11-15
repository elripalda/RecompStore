<?php
// (4.11) Launcher support > Check a game: what the launcher really gets for one recomp, feature by feature,
// what's wrong and how to fix it.
//
// The launcher uses, in this order (profile::find in the launcher): the recomplauncher.json inside the
// release it installed, then our profile from the catalog (the listing's "boot" field). A file that's only
// in the developer's repo is never read, so it's reported as a problem with the fix.
// The json is checked against the launcher's own schema (schema/recomplauncher.json, copied from the
// launcher by the 30-minute job), and the paths it names against the release download's file list.
declare(strict_types=1);
require_once __DIR__ . '/pc.php';
require_once __DIR__ . '/prq.php'; // (4.11) rs_prq_same_file

// ---- A small JSON Schema (draft-07) check: the rules the launcher's schema uses -------------------------
// The document is decoded as objects (json_decode without assoc), so {} and [] stay apart.
function rs_json_schema_errors($doc, array $schema, int $max = 20): array {
    $errs = [];
    rs_json_schema_walk($doc, $schema, $schema, '', $errs, $max);
    return array_slice($errs, 0, $max);
}
function rs_json_schema_type($v): string {
    return match (true) { $v === null => 'null', is_bool($v) => 'boolean', is_int($v) => 'integer', is_float($v) => 'number', is_string($v) => 'string', is_array($v) => 'array', default => 'object' };
}
function rs_json_schema_walk($v, $s, array $root, string $path, array &$errs, int $max): void {
    if (count($errs) >= $max || $s === true || !is_array($s)) return;
    if ($s === false) { $errs[] = [$path, 'isn\'t allowed here']; return; }
    $at = $path === '' ? '(top)' : $path;
    if (isset($s['$ref']) && is_string($s['$ref']) && str_starts_with($s['$ref'], '#/')) {
        $t = $root;
        foreach (explode('/', substr($s['$ref'], 2)) as $k) $t = is_array($t) ? ($t[str_replace(['~1', '~0'], ['/', '~'], $k)] ?? null) : null;
        if (is_array($t)) rs_json_schema_walk($v, $t, $root, $path, $errs, $max);
        return; // (draft-07: siblings of $ref are ignored)
    }
    $type = rs_json_schema_type($v);
    if (isset($s['type'])) {
        $want = (array)$s['type'];
        $ok = in_array($type, $want, true) || ($type === 'integer' && in_array('number', $want, true)) || ($type === 'number' && in_array('integer', $want, true) && floor((float)$v) == $v);
        if (!$ok) { $errs[] = [$at, 'should be ' . implode(' or ', $want) . ', not ' . $type]; return; }
    }
    if (array_key_exists('const', $s) && json_encode($v) !== json_encode($s['const'])) $errs[] = [$at, 'should be ' . json_encode($s['const'])];
    if (isset($s['enum']) && is_array($s['enum']) && !in_array(json_encode($v), array_map('json_encode', $s['enum']), true))
        $errs[] = [$at, 'should be one of ' . implode(', ', array_map(fn($x) => json_encode($x), array_slice($s['enum'], 0, 8)))];
    if ($type === 'string') {
        if (isset($s['minLength']) && mb_strlen($v) < (int)$s['minLength']) $errs[] = [$at, 'is too short'];
        if (isset($s['pattern']) && @preg_match('~' . str_replace('~', '\~', (string)$s['pattern']) . '~u', $v) === 0) $errs[] = [$at, 'doesn\'t have the right form'];
    }
    if ($type === 'integer' || $type === 'number') {
        if (isset($s['minimum']) && $v < $s['minimum']) $errs[] = [$at, 'should be at least ' . $s['minimum']];
        if (isset($s['maximum']) && $v > $s['maximum']) $errs[] = [$at, 'should be at most ' . $s['maximum']];
    }
    if ($type === 'array') {
        if (isset($s['minItems']) && count($v) < (int)$s['minItems']) $errs[] = [$at, 'needs at least ' . (int)$s['minItems'] . ' ' . ((int)$s['minItems'] === 1 ? 'entry' : 'entries')];
        if (isset($s['maxItems']) && count($v) > (int)$s['maxItems']) $errs[] = [$at, 'has more than ' . (int)$s['maxItems'] . ' entries'];
        if (!empty($s['uniqueItems']) && count(array_unique(array_map('json_encode', $v))) !== count($v)) $errs[] = [$at, 'has the same entry twice'];
        if (isset($s['items']) && is_array($s['items']) && !array_is_list($s['items']))
            foreach ($v as $i => $x) rs_json_schema_walk($x, $s['items'], $root, $path . '/' . $i, $errs, $max);
    }
    if ($type === 'object') {
        $props = is_array($s['properties'] ?? null) ? $s['properties'] : [];
        foreach ((array)($s['required'] ?? []) as $r) if (!property_exists($v, (string)$r)) $errs[] = [$at, 'needs "' . $r . '"'];
        foreach (get_object_vars($v) as $k => $x) {
            if (isset($s['propertyNames']) && is_array($s['propertyNames'])) rs_json_schema_walk((string)$k, $s['propertyNames'], $root, $path . '/' . $k, $errs, $max);
            if (array_key_exists($k, $props)) rs_json_schema_walk($x, $props[$k], $root, $path . '/' . $k, $errs, $max);
            elseif (array_key_exists('additionalProperties', $s)) {
                if ($s['additionalProperties'] === false) $errs[] = [$path . '/' . $k, 'isn\'t something the launcher knows (a typo, or for a newer launcher)'];
                elseif (is_array($s['additionalProperties'])) rs_json_schema_walk($x, $s['additionalProperties'], $root, $path . '/' . $k, $errs, $max);
            }
        }
    }
    foreach ((array)($s['allOf'] ?? []) as $sub) rs_json_schema_walk($v, $sub, $root, $path, $errs, $max);
    if (isset($s['anyOf']) && is_array($s['anyOf'])) {
        $best = null;
        foreach ($s['anyOf'] as $sub) { $e = []; rs_json_schema_walk($v, $sub, $root, $path, $e, $max); if (!$e) { $best = []; break; } if ($best === null || count($e) < count($best)) $best = $e; }
        if ($best) foreach ($best as $e) $errs[] = $e;
    }
    if (isset($s['not'])) { $e = []; rs_json_schema_walk($v, $s['not'], $root, $path, $e, $max); if (!$e) $errs[] = [$at, 'has a combination that isn\'t allowed']; }
    if (isset($s['if'])) {
        $e = []; rs_json_schema_walk($v, $s['if'], $root, $path, $e, $max);
        if (!$e && isset($s['then'])) rs_json_schema_walk($v, $s['then'], $root, $path, $errs, $max);
        elseif ($e && isset($s['else'])) rs_json_schema_walk($v, $s['else'], $root, $path, $errs, $max);
    }
}
function rs_sdk_schema(): ?array {
    static $s = false;
    if ($s === false) { $f = RS_PUBLIC . '/schema/recomplauncher.json'; $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; }
    return is_array($s) ? $s : null;
}

// ---- The check ------------------------------------------------------------------------------------------
const RS_SDK_HEALTH_FEATS = [
    'install' => 'Installs and updates',
    'start' => 'Starts the right program',
    'easy' => 'Easy Setup from the player\'s copy',
    'prepare' => 'Setup step',
    'settings' => 'Settings in the launcher',
    'saves' => 'Save backups',
    'logs' => 'Logs with crash reports',
    'verify' => 'Checks the player\'s copy',
    'ships' => 'The file reaches players',
    'badge' => 'Badge in the README',
];

// -> ['at', 'source' (release|ours|ours_old|none), 'docs' => [where => text], 'release' => [...], 'schema' => [where => errors],
//     'feats' => [key => ['state' => ok|warn|bad|off, 'how', 'fix']], 'players' => [...]]
// The file names in the Windows download of the release players install (null when it isn't a zip or
// can't be read). Used before following a developer's file: its start.program has to be in there.
function rs_sdk_release_names(array $a): ?array {
    try {
        $rel = (string)$a['repo'] !== '' ? rs_pc_release_for($a) : null;
        $pat = trim((string)$a['asset_win']);
        foreach ($rel ? (array)$rel['assets'] : [] as $f) {
            if ($pat === '' || !rs_pattern_matches($pat, (string)$f['name'])) continue;
            if (!preg_match('/\.zip$/i', (string)$f['name'])) return null;
            $z = rs_zip_directory((string)$f['browser_download_url'], (int)$f['size']);
            return !empty($z['entries']) ? array_keys((array)$z['entries']) : null;
        }
    } catch (Throwable $e) { /* unknown */ }
    return null;
}

// Whether a start.program list names something in the download: true / false, or null when it can't be told.
function rs_sdk_program_in(array $prog, ?array $names): ?bool {
    if ($names === null) return null;
    $known = false;
    foreach ($prog as $p) {
        $p = (string)$p;
        if ($p === '' || str_contains($p, '{')) continue;
        $known = true;
        $p = ltrim(str_replace('\\', '/', $p), './');
        foreach ($names as $n) if (strcasecmp($n, $p) === 0 || preg_match('#^[^/]+/' . preg_quote($p, '#') . '$#i', $n)) return true;
    }
    return $known ? false : null;
}

function rs_sdk_health(array $a, bool $fresh = false): array {
    $k = 'sdkhealth:' . $a['id'];
    if (!$fresh && ($c = rs_kv_get($k)) && $c['age'] < 6 * 3600 && is_array($x = json_decode($c['v'], true))) return $x;
    $out = ['at' => time(), 'source' => 'none', 'docs' => [], 'release' => [], 'schema' => [], 'feats' => [], 'players' => []];
    $host = rs_pc_host($a);
    $repo = (string)$a['repo'];
    // The release players install, and what's in its Windows download.
    $names = null;
    try {
        $rel = $repo !== '' ? rs_pc_release_for($a) : null;
        if ($rel) {
            $out['release'] = ['tag' => (string)($rel['tag_name'] ?? ''), 'url' => (string)($rel['html_url'] ?? '')];
            $pat = trim((string)$a['asset_win']);
            foreach ((array)$rel['assets'] as $f) {
                if ($pat === '' || !rs_pattern_matches($pat, (string)$f['name'])) continue;
                $out['release']['file'] = (string)$f['name'];
                if (!preg_match('/\.zip$/i', (string)$f['name'])) break;
                $z = rs_zip_directory((string)$f['browser_download_url'], (int)$f['size']);
                if (!empty($z['entries'])) {
                    $names = array_keys((array)$z['entries']);
                    $out['release']['count'] = count($names);
                    foreach ((array)$z['entries'] as $n => $en) if (preg_match('#^([^/]+/)?recomplauncher\.json$#i', (string)$n)) {
                        $txt = rs_zip_file((string)$f['browser_download_url'], $en, 400000);
                        if (is_string($txt)) $out['docs']['release'] = $txt;
                        break;
                    }
                }
                break;
            }
        }
    } catch (Throwable $e) { $out['release']['error'] = 'The release couldn\'t be read just now.'; }
    // The developer's repo (not used by the launcher, but it's what they'd put in the release).
    if ($repo !== '') { try { [$st, $txt] = rs_sdk_repo_file($host, $repo); if ($st === 200 && $txt !== '') $out['docs']['repo'] = $txt; } catch (Throwable $e) { } }
    // Our profile from the catalog.
    $boot = trim((string)($a['boot'] ?? ''));
    $bj = $boot !== '' ? json_decode($boot, true) : null;
    if (is_array($bj) && (int)($bj['format'] ?? 0) === 1 && isset($bj['start'])) $out['docs']['ours'] = $boot;
    elseif (is_array($bj) && $bj) $out['source'] = 'ours_old';
    if (isset($out['docs']['release'])) $out['source'] = 'release';
    elseif (isset($out['docs']['ours'])) $out['source'] = 'ours';
    // Schema problems, for each file there is.
    $schema = rs_sdk_schema();
    foreach ($out['docs'] as $where => $txt) {
        $obj = json_decode($txt);
        if (!is_object($obj)) { $out['schema'][$where] = [['(top)', 'isn\'t valid JSON']]; continue; }
        $out['schema'][$where] = $schema ? rs_json_schema_errors($obj, $schema) : [];
    }
    // What players get, from the file the launcher uses.
    $use = in_array($out['source'], ['release', 'ours'], true) ? json_decode($out['docs'][$out['source']], true) : null;
    $out['feats'] = rs_sdk_health_feats($a, is_array($use) ? $use : null, $out, $names);
    // What players say: reports and logs of the last 60 days.
    try {
        $st = rs_db()->prepare("SELECT verdict, COUNT(*) n FROM play_reports WHERE kind = 'pc' AND app = ? AND hidden = 0 AND created > NOW() - INTERVAL 60 DAY GROUP BY verdict");
        $st->execute([$a['id']]);
        $out['players'] = ['reports' => array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR))];
        $st = rs_db()->prepare("SELECT COUNT(*) n, SUM(category = 'crash') c FROM inbox WHERE kind = 'logs' AND app_id = ? AND created > NOW() - INTERVAL 60 DAY");
        $st->execute([$a['id']]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['players']['logs'] = (int)($r['n'] ?? 0);
        $out['players']['crashes'] = (int)($r['c'] ?? 0);
    } catch (Throwable $e) { /* tables not there */ }
    rs_kv_put($k, (string)json_encode($out));
    return $out;
}

function rs_sdk_health_feats(array $a, ?array $j, array $h, ?array $names): array {
    $f = [];
    $set = function (string $k, string $state, string $how = '', string $fix = '') use (&$f) { $f[$k] = ['state' => $state, 'how' => $how, 'fix' => $fix]; };
    $inZip = function (string $p) use ($names): ?bool {
        if ($names === null || $p === '' || str_contains($p, '{')) return null;
        $p = ltrim(str_replace('\\', '/', $p), './');
        foreach ($names as $n) if (strcasecmp($n, $p) === 0 || preg_match('#^[^/]+/' . preg_quote($p, '#') . '$#i', $n)) return true;
        return false;
    };
    $rel = $h['release'];
    // Installs and updates: a release with the listing's Windows file.
    if (trim((string)$a['asset_win']) === '') $set('install', 'warn', 'No Windows file pattern on the listing.', 'Set the Windows file on its edit page (PC recomps).');
    elseif (!empty($rel['file'])) $set('install', 'ok', $rel['file'] . ' in ' . ($rel['tag'] ?? 'the newest release'));
    elseif (!empty($rel['error'])) $set('install', 'warn', $rel['error']);
    else $set('install', 'bad', 'No release has a file matching ' . $a['asset_win'] . '.', 'Look at Download needs a look on its edit page.');
    if ($j === null) {
        // Old-style profile or nothing: what the catalog says it supports.
        $sup = rs_pc_support($a);
        $how = $h['source'] === 'ours_old' ? 'From our older profile.' : '';
        $set('start', $h['source'] === 'ours_old' ? 'ok' : 'warn', $how ?: 'The launcher guesses the program to start.', $how ? '' : 'Draft it, then Use as our profile, or ask the developer for a recomplauncher.json.');
        foreach (['easy' => 'easy_setup', 'settings' => 'settings', 'saves' => 'saves', 'prepare' => 'prepare', 'verify' => 'verified'] as $key => $s) {
            if (!empty($sup[$s])) $set($key, 'ok', $how);
            elseif ($key === 'prepare') $set($key, 'off', 'None, or not known without a recomplauncher.json.');
            else $set($key, 'bad', 'Not set up.', 'Comes with a recomplauncher.json: Draft it.');
        }
        $set('logs', 'bad', 'Not set up.', 'Comes with a recomplauncher.json: Draft it.');
    } else {
        $prog = (array)($j['start']['program'] ?? []);
        $hits = array_filter(array_map($inZip, array_map('strval', $prog)), fn($x) => $x !== null);
        if (!$prog) $set('start', 'bad', 'No "start.program".', 'Add the program to start, from the top of the release.');
        elseif ($hits && !in_array(true, $hits, true) && isset($j['prepare'])) $set('start', 'ok', implode(', ', $prog) . ', made by its setup step (it isn\'t in the download itself)');
        elseif ($hits && !in_array(true, $hits, true)) $set('start', 'bad', implode(', ', $prog) . ' isn\'t in the download.', 'The paths start at the top of the release zip. Fix the name, or the release.');
        else $set('start', 'ok', implode(', ', $prog) . ($hits ? ', found in the download' : ''));
        if (rs_sdk_easy($j)) {
            $args = implode(' ', array_merge(array_map('strval', (array)($j['start']['args'] ?? [])), array_map('strval', (array)($j['prepare']['args'] ?? []))));
            $set('easy', 'ok', 'The player picks ' . (str_contains(json_encode($j), '"iso"') ? 'their disc image' : 'their copy') . ' once' . ($args !== '' ? ' (' . mb_substr($args, 0, 80) . ')' : '') . '.');
        } else $set('easy', 'bad', 'The game can\'t be handed the player\'s copy.', 'Needs an option like --game <path>. Draft it: the drafter looks for one, or adds it in the pull request.');
        if (isset($j['prepare'])) {
            $pp = (array)($j['prepare']['program'] ?? []);
            $ph = array_filter(array_map($inZip, array_map('strval', $pp)), fn($x) => $x !== null);
            $set('prepare', $ph && !in_array(true, $ph, true) ? 'bad' : 'ok', (string)($j['prepare']['label'] ?? 'Runs ' . implode(', ', $pp)) . ($ph && !in_array(true, $ph, true) ? ': ' . implode(', ', $pp) . ' isn\'t in the download' : ''));
        } elseif (!empty($a['setup_required'])) $set('prepare', 'bad', 'The listing says it needs setting up first, but the file has no setup step.', 'Add a "prepare" step (the drafter can add a --prepare mode).');
        else $set('prepare', 'off', 'Not needed: it plays from the player\'s copy.');
        $labels = array_values(array_filter(array_map(fn($s) => is_array($s) ? (string)($s['label'] ?? '') : '', (array)($j['settings'] ?? []))));
        $cfgOk = true;
        foreach ((array)($j['config'] ?? []) as $c) if (!is_array($c) || empty($c['format']) || empty($c['file'])) $cfgOk = false;
        if ($labels) $set('settings', $cfgOk ? 'ok' : 'warn', implode(', ', array_slice($labels, 0, 5)) . (count($labels) > 5 ? ' and ' . (count($labels) - 5) . ' more' : ''), $cfgOk ? '' : 'A "config" entry needs "format" and "file".');
        else $set('settings', 'bad', 'None.', 'Describe its settings file in "config" and "settings" (Draft it finds the keys).');
        $saves = array_values(array_filter(array_merge((array)($j['saves'] ?? []), array_map(fn($x) => is_array($x) ? (string)($x['path'] ?? '') : (string)$x, (array)($j['folders']['saves'] ?? []))), 'strlen'));
        $saves ? $set('saves', 'ok', implode(', ', array_slice(array_map('strval', $saves), 0, 2))) : $set('saves', 'bad', 'Where saves go isn\'t in the file.', 'Add "saves" (or folders.saves).');
        $logs = array_values(array_filter(array_map(fn($x) => is_array($x) ? (string)($x['path'] ?? '') : (string)$x, (array)($j['folders']['logs'] ?? [])), 'strlen'));
        $logs ? $set('logs', 'ok', implode(', ', array_slice($logs, 0, 2))) : $set('logs', 'bad', 'No log file in the file.', 'Add folders.logs, or ask the developer for a log file.');
        $g = is_array($j['game'] ?? null) ? $j['game'] : [];
        if (!empty($g['dumps']) || !empty($g['check'])) $set('verify', 'ok', !empty($g['dumps']) ? count((array)$g['dumps']) . ' known good copies' : 'Checks it\'s the right game');
        else $set('verify', rs_sdk_easy($j) ? 'warn' : 'off', rs_sdk_easy($j) ? 'Any file is taken.' : 'Nothing to check.', rs_sdk_easy($j) ? 'Add game.check (a file, serial or ID) or game.dumps (size and CRC32) when the README names them.' : '');
    }
    // Does the file reach players?
    $d = $h['docs'];
    if (isset($d['release'])) $set('ships', 'ok', 'In the release, so it comes with every install.');
    elseif (isset($d['repo']) && isset($d['ours'])) {
        // (4.11) Our copy follows theirs (rs_prq_sync_repo_files) until their release ships it.
        $sy = rs_kv_get('prqsync:' . $a['id']); $sy = $sy ? (json_decode($sy['v'], true) ?: []) : [];
        $same = is_array($r1 = json_decode($d['repo'], true)) && is_array($r2 = json_decode($d['ours'], true)) && rs_prq_same_file($r1, $r2);
        if (($sy['state'] ?? '') === 'bad') $set('ships', 'bad', 'In their repo, not in the release, and their file fails the launcher\'s rules (' . ($sy['why'] ?? '') . '), so players keep our older copy.', 'Tell the developer what to fix.');
        elseif ($same) $set('ships', 'warn', 'In their repo, not in the release. Our copy matches theirs and follows their changes (checked every 6 hours).', 'Ask the developer to copy recomplauncher.json into each release zip, next to the program; then theirs is used directly.');
        else $set('ships', 'warn', 'In their repo, not in the release. Players get our copy, which differs from theirs.', 'If we sent them a pull request, our copy follows theirs within 6 hours. Otherwise ask them to put it in their release.');
    }
    elseif (isset($d['repo'])) $set('ships', 'bad', 'In their repo, not in the release, so the launcher never reads it.', 'Use as our profile now, and ask the developer to copy it into each release zip.');
    elseif (isset($d['ours'])) $set('ships', 'warn', 'Players get our profile. The developer has no recomplauncher.json yet.', 'Send them a pull request, or the file.');
    else $set('ships', 'bad', 'No recomplauncher.json anywhere.', 'Draft it, then send the pull request or use it as our profile.');
    $sdk = rs_kv_get('sdk:' . $a['id']);
    $sk = $sdk ? (json_decode($sdk['v'], true) ?: []) : [];
    if (array_key_exists('badge', $sk)) $set('badge', $sk['badge'] ? 'ok' : 'warn', $sk['badge'] ? 'In the README.' : 'Not in the README.', $sk['badge'] ? '' : 'The pull request adds it.');
    else $set('badge', 'off', 'Not checked yet.');
    return $f;
}
