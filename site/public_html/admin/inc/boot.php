<?php
// RecompStore admin (4.3): boot profiles. How RecompLauncher starts a recomp, sets it up from the
// player's copy (Easy Setup) and which of its settings it shows. They used to live inside the
// launcher (263 of them, src-tauri/boot/boot-profiles.json + boot-extras.json), so fixing one meant a
// new launcher build. Now the admin's Boot profile field is where they live: the catalog carries it as
// "boot", the launcher's own copy only fills in when the catalog can't be read.
//
// boot-builtin.json and boot-extras.json beside this file are the launcher's copies (as of its main
// branch), so rs_boot_import_plan() can put them into the admin and the catalog gives exactly what the
// launcher works out today: no player sees a change.
declare(strict_types=1);

const RS_BOOT_KINDS = ['portable', 'installer', 'single-exe', 'not-a-game', 'source', 'unknown'];
const RS_BOOT_START_IS = ['game', 'launcher', 'script'];
// files.how: where the player's copy goes. folder / flag / env / setup are an Easy Setup in the launcher.
const RS_BOOT_HOW = [
    'folder' => 'Easy Setup: the launcher puts the copy in a folder of the game',
    'flag' => 'Easy Setup: the launcher passes the copy on the command line',
    'env' => 'Easy Setup: the launcher passes the copy in an environment variable',
    'setup' => 'Easy Setup: the copy goes to the game\'s own setup program',
    'self' => 'The game asks for the copy itself',
    'none' => 'Needs no copy',
    'unknown' => 'Not worked out yet',
];
const RS_BOOT_EASY = ['folder', 'flag', 'env', 'setup'];
const RS_BOOT_TOP = ['kind', 'start', 'start_linux', 'start_macos', 'start_is', 'args', 'setup', 'console', 'installer', 'clone', 'makes',
    'files', 'settings', 'player_note', 'multiplayer', 'server', 'updates', 'requires', 'needs', 'min', 'format'];
const RS_BOOT_FILES = ['how', 'accepts', 'what', 'folder', 'flag', 'env', 'contains', 'extract', 'check_image', 'id', 'ids', 'region', 'n64_id', 'n64_rev', 'update'];
// (4.3) What the PC needs for the setup step, checked on the game page (Beta 28): RecompLauncher's own names.
const RS_BOOT_NEEDS = ['build_tools', 'clang', 'python'];
const RS_BOOT_PLACES = ['{install}/', '{documents}/', '{local}/', '{config}/', '{home}/'];

// The launcher's own profiles, by catalog id.
function rs_boot_builtin(): array {
    static $all = null;
    if ($all === null) $all = json_decode((string)@file_get_contents(__DIR__ . '/boot-builtin.json'), true) ?: [];
    return $all;
}
// The launcher's additions on top of any profile (a game's update file, a fix that has to win).
function rs_boot_extras(): array {
    static $all = null;
    if ($all === null) $all = json_decode((string)@file_get_contents(__DIR__ . '/boot-extras.json'), true) ?: [];
    return $all;
}

// The launcher's merge (boot.rs): adds what `$to` doesn't say; a key written "=name" sets those
// fields even when `$to` has its own.
function rs_boot_merge(array $to, array $add): array {
    foreach ($add as $k => $v) {
        if (is_string($k) && str_starts_with($k, '=')) {
            $name = substr($k, 1);
            if (isset($to[$name]) && is_array($to[$name]) && rs_boot_is_obj($to[$name]) && is_array($v) && rs_boot_is_obj($v)) foreach ($v as $fk => $fv) $to[$name][$fk] = $fv;
            else $to[$name] = $v;
            continue;
        }
        if (!array_key_exists($k, $to)) $to[$k] = $v;
        elseif (is_array($to[$k]) && is_array($v) && rs_boot_is_obj($to[$k]) && rs_boot_is_obj($v)) $to[$k] = rs_boot_merge($to[$k], $v);
    }
    return $to;
}
function rs_boot_is_obj(array $a): bool { return $a === [] || !array_is_list($a); }

function rs_boot_json(array $b): string { return json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

// What the launcher would use for this recomp today: the admin's profile (with the launcher's own
// settings when the admin's says none, as boot.rs does), else the launcher's own.
function rs_boot_effective(array $a): array {
    $own = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $bi = rs_boot_builtin()[$a['id']] ?? null;
    if (is_array($own) && $own) {
        if (!isset($own['settings']) && is_array($bi) && isset($bi['settings'])) $own['settings'] = $bi['settings'];
        $b = $own;
    } else $b = is_array($bi) ? $bi : [];
    if ($b && isset(rs_boot_extras()[$a['id']])) $b = rs_boot_merge($b, rs_boot_extras()[$a['id']]);
    return $b;
}

// The recomps whose admin profile changes when the launcher's own is brought in:
// [[id, name, what, json], ...], what = "fill" (it had none), "settings" (the launcher's settings
// added), "extras" (the launcher's extra fix merged in). What the launcher does for players stays the same.
function rs_boot_import_plan(): array {
    $bi = rs_boot_builtin(); $ex = rs_boot_extras();
    $plan = [];
    foreach (rs_pc_apps() as $a) {
        $id = $a['id'];
        if (!isset($bi[$id]) && !isset($ex[$id])) continue;
        $own = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
        if (is_array($own) && $own && (int)($own['format'] ?? 0) === 1 && isset($own['start'])) continue; // a full launcher profile: left alone
        $what = [];
        if (!is_array($own) || !$own) {
            if (!isset($bi[$id])) continue; // extras alone aren't a profile
            $new = $bi[$id]; $what[] = 'fill';
        } else {
            $new = $own;
            if (!isset($new['settings']) && isset($bi[$id]['settings'])) { $new['settings'] = $bi[$id]['settings']; $what[] = 'settings'; }
        }
        if (isset($ex[$id])) { $m = rs_boot_merge($new, $ex[$id]); if ($m != $new) { $new = $m; $what[] = 'extras'; } }
        if ($what) $plan[] = [$id, (string)$a['name'], implode('+', $what), rs_boot_json($new)];
    }
    return $plan;
}

// The launcher's ids that aren't in the admin at all (removed or renamed recomps).
function rs_boot_orphans(): array {
    $have = array_flip(array_map(fn($a) => $a['id'], rs_pc_apps()));
    return array_values(array_filter(array_keys(rs_boot_builtin()), fn($id) => !isset($have[$id])));
}

// A path inside the game's folder: relative, no "..", no drive.
function rs_boot_rel_ok(string $p): bool {
    $p = str_replace('\\', '/', trim($p));
    return $p !== '' && $p[0] !== '/' && !str_contains($p, ':') && !in_array('..', explode('/', $p), true);
}

// Problems with a profile, the way the launcher would see them: [['err'|'warn', message], ...].
// Errors stop a save; warnings say what the launcher will quietly skip.
function rs_boot_check(array $b): array {
    $out = [];
    $e = function (string $m) use (&$out) { $out[] = ['err', $m]; };
    $w = function (string $m) use (&$out) { $out[] = ['warn', $m]; };
    if (array_is_list($b)) { $e('It has to be one { ... } object.'); return $out; }
    if ((int)($b['format'] ?? 0) === 1) { // a full launcher profile: its own rules, the basics checked
        if (!isset($b['start']['program'])) $e('A launcher profile needs start.program: the program(s) to run.');
        $ids = [];
        foreach ((array)($b['settings'] ?? []) as $i => $st) {
            $n = '"' . ($st['label'] ?? $st['id'] ?? '#' . $i) . '"';
            if (!is_array($st) || ($st['id'] ?? '') === '' || ($st['label'] ?? '') === '') { $e("Setting $n needs an id and a label."); continue; }
            if (isset($ids[$st['id']])) $e('Two settings have the id "' . $st['id'] . '".');
            $ids[$st['id']] = true;
            if (count((array)($st['choices'] ?? [])) < 2 && !isset($st['slider']) && !isset($st['bindings'])) $w("Setting $n has fewer than two choices.");
            foreach ((array)($st['choices'] ?? []) as $ch) foreach ((array)($ch['set'] ?? []) as $v) if (!is_string($v)) { $e("Setting $n: values in set are text, quoted."); break 2; }
            foreach ((array)($st['defaults'] ?? []) as $v) if (!is_string($v)) { $e("Setting $n: defaults are text, quoted."); break; }
        }
        return $out;
    }
    foreach (array_keys($b) as $k) if (!in_array($k, RS_BOOT_TOP, true)) $w("\"$k\" isn't something the launcher reads. Check the spelling.");
    $kind = (string)($b['kind'] ?? '');
    if ($kind !== '' && !in_array($kind, RS_BOOT_KINDS, true)) $w("kind \"$kind\" isn't one the launcher knows (" . implode(', ', RS_BOOT_KINDS) . ').');
    foreach (['start', 'start_linux', 'start_macos', 'setup'] as $k) {
        if (!isset($b[$k])) continue;
        if (!is_string($b[$k])) $e("$k has to be text.");
        elseif (!rs_boot_rel_ok($b[$k])) $e("$k has to be a path inside the game's folder, like bin/Game.exe (no leading /, no .., no C:).");
    }
    if (isset($b['start_is']) && !in_array((string)$b['start_is'], RS_BOOT_START_IS, true)) $w('start_is is game, launcher or script.');
    if ($kind === 'installer' && !isset($b['installer']['then_start'])) $w('An installer kind usually says installer.then_start: the game\'s program in the folder it installs to.');
    if (isset($b['installer']['then_start']) && !rs_boot_rel_ok((string)$b['installer']['then_start'])) $e('installer.then_start has to be a path inside the folder it installs to.');
    if (isset($b['makes']) && (!is_array($b['makes']) || !array_is_list($b['makes']))) $e('makes is a list of paths.');
    foreach ((array)($b['makes'] ?? []) as $m) if (!is_string($m) || !rs_boot_rel_ok($m)) $e('Every path in makes has to be inside the game\'s folder.');
    if (isset($b['requires']) && (!is_array($b['requires']) || !array_is_list($b['requires']) || array_filter($b['requires'], fn($x) => !is_string($x)))) $e('requires is a list of feature names, like ["files.folder", "settings.toml"].');
    if (isset($b['needs']) && (!is_array($b['needs']) || !array_is_list($b['needs']) || array_filter($b['needs'], fn($x) => !is_string($x)))) $e('needs is a list, like ["build_tools", "clang", "python"].');
    foreach ((array)($b['needs'] ?? []) as $x) if (is_string($x) && !in_array($x, RS_BOOT_NEEDS, true)) $w("needs: \"$x\" isn't one the launcher checks yet (it knows " . implode(', ', RS_BOOT_NEEDS) . ').');
    if (isset($b['min']) && !preg_match('/^\d+\.\d+\.\d+(-[a-z]+\.\d+)?$/', (string)$b['min'])) $e('min is a launcher version, like 0.90.0-beta.27.');

    // files: where the player's copy goes.
    $f = $b['files'] ?? null;
    if ($f === null) $w('No files: the launcher doesn\'t know where the player\'s copy goes.');
    elseif (!is_array($f) || array_is_list($f)) $e('files has to be an object.');
    else {
        foreach (array_keys($f) as $k) if (!in_array($k, RS_BOOT_FILES, true)) $w("files.$k isn't something the launcher reads.");
        $how = (string)($f['how'] ?? '');
        if (!isset(RS_BOOT_HOW[$how])) $e('files.how is one of ' . implode(', ', array_keys(RS_BOOT_HOW)) . '.');
        $easy = in_array($how, RS_BOOT_EASY, true);
        if ($easy && trim((string)($b['start'] ?? '')) === '') $w('An Easy Setup (files.how ' . $how . ') needs start, or the launcher won\'t offer it: it has to know what to run with the copy.');
        if ($how === 'folder') {
            $into = trim((string)($f['folder'] ?? ''));
            if ($into === '') $e('files.how folder needs files.folder: where in the game\'s folder the copy goes ("." for the top).');
            elseif (str_contains($into, '..') || str_contains($into, ':') || str_starts_with(str_replace('\\', '/', $into), '/')) $e('files.folder has to be inside the game\'s folder.');
        }
        if ($how === 'flag' && ($fl = trim((string)($f['flag'] ?? ''))) !== '' && $fl[0] !== '-') $e('files.flag starts with - (like --game_data_root). Leave it out to put the copy first on the command line.');
        if ($how === 'env' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string)($f['env'] ?? ''))) $e('files.how env needs files.env: the variable\'s name, like STRIKERS_DATA.');
        if ($easy) {
            $acc = strtolower((string)($f['accepts'] ?? ''));
            if ($acc === '') $w('files.accepts says what the player picks: file, iso, folder, or "folder or iso".');
            elseif (!preg_match('/\b(file|iso|folder)\b/', $acc)) $w('files.accepts is file, iso, folder, or two of them like "folder or iso".');
            if (trim((string)($f['what'] ?? '')) === '') $w('files.what is the line the player reads: what to pick ("Your Xbox 360 disc, as an ISO or extracted").');
        }
        if (isset($f['contains']) && (!is_array($f['contains']) || !array_is_list($f['contains']))) $e('files.contains is a list of names, like ["default.xex"].');
        if (isset($f['extract']) && !in_array((string)$f['extract'], ['xdvdfs', 'iso9660'], true)) $w('files.extract is xdvdfs or iso9660.');
        if (isset($f['update'])) {
            $u = $f['update'];
            if (!is_array($u) || trim((string)($u['what'] ?? '')) === '' || !rs_boot_rel_ok((string)($u['folder'] ?? ''))) $w('files.update needs a folder inside the game\'s and a what line, or the launcher skips it.');
            if (isset($u['sha256']) && !preg_match('/^[0-9a-f]{64}$/', (string)$u['sha256'])) $e('files.update.sha256 is 64 lowercase hex characters.');
        }
    }

    // settings: {config: [{id, format, file: [{path}]}], settings: [{id, label, in, choices | slider | bindings}]}
    if (isset($b['settings'])) {
        $s = $b['settings'];
        if (!is_array($s) || array_is_list($s)) { $e('settings has to be an object with config and settings.'); return $out; }
        $cfgIds = [];
        foreach ((array)($s['config'] ?? []) as $i => $c) {
            $n = 'settings.config[' . $i . ']';
            if (!is_array($c)) { $e("$n has to be an object."); continue; }
            $id = (string)($c['id'] ?? 'main');
            if (isset($cfgIds[$id])) $e("Two settings files are called \"$id\".");
            $cfgIds[$id] = true;
            if (!in_array((string)($c['format'] ?? ''), ['toml', 'ini', 'json'], true)) $e("$n: format is toml, ini or json.");
            $files = $c['file'] ?? [];
            if (!is_array($files) || !$files) $e("$n needs file: [{\"path\": \"{install}/config.toml\"}].");
            foreach ((array)$files as $p) {
                $path = str_replace('\\', '/', trim((string)($p['path'] ?? '')));
                $ok = false; foreach (RS_BOOT_PLACES as $r) if (str_starts_with($path, $r)) $ok = true;
                if (!$ok || in_array('..', explode('/', $path), true)) $e("$n: \"$path\" has to start with " . implode(', ', RS_BOOT_PLACES) . ' and stay inside it.');
            }
        }
        $ids = [];
        foreach ((array)($s['settings'] ?? []) as $i => $st) {
            if (!is_array($st)) { $e("settings.settings[$i] has to be an object."); continue; }
            $label = (string)($st['label'] ?? '');
            $n = '"' . ($label !== '' ? $label : ($st['id'] ?? '#' . $i)) . '"';
            $id = (string)($st['id'] ?? '');
            if ($id === '' || $label === '') $e("Setting $n needs an id and a label.");
            if ($id !== '' && isset($ids[$id])) $e("Two settings have the id \"$id\".");
            $ids[$id] = true;
            $in = (string)($st['in'] ?? 'main');
            if ($in !== 'args' && !isset($cfgIds[$in])) $w("Setting $n is in \"$in\", but no settings file has that id, so the launcher leaves it out.");
            $choices = (array)($st['choices'] ?? []);
            $slider = $st['slider'] ?? null;
            $bind = $st['bindings'] ?? null;
            if (count($choices) < 2 && !is_array($slider) && !is_array($bind)) $w("Setting $n has fewer than two choices, so the launcher leaves it out.");
            foreach ($choices as $ci => $ch) {
                if (!is_array($ch) || trim((string)($ch['label'] ?? '')) === '') { $e("Setting $n: every choice needs a label."); break; }
                if (isset($ch['set']) && (!is_array($ch['set']) || array_is_list($ch['set']) && $ch['set'] !== [])) $e("Setting $n: a choice's set is {\"key\": \"value\"}.");
                foreach ((array)($ch['set'] ?? []) as $v) if (!is_string($v)) { $e("Setting $n: values in set are text (\"true\", \"1.0\"), quoted."); break 2; }
            }
            if (is_array($slider) && !((float)($slider['min'] ?? 0) < (float)($slider['max'] ?? 0))) $e("Setting $n: the slider's min has to be below its max.");
            foreach ((array)($st['defaults'] ?? []) as $v) if (!is_string($v)) { $e("Setting $n: defaults are text, quoted."); break; }
        }
    }
    return $out;
}

// The launcher features a profile uses, by the names in the launcher's docs (for "requires").
function rs_boot_uses(array $b): array {
    $u = [];
    $how = (string)($b['files']['how'] ?? '');
    if (in_array($how, RS_BOOT_EASY, true)) $u[] = 'files.' . $how;
    if (isset($b['files']['update'])) $u[] = 'files.update';
    foreach ((array)($b['settings']['config'] ?? []) as $c) if (is_array($c) && isset($c['format'])) $u[] = 'settings.' . $c['format'];
    foreach ((array)($b['settings']['settings'] ?? []) as $st) { if (isset($st['slider'])) $u[] = 'settings.slider'; if (isset($st['bindings'])) $u[] = 'settings.bindings'; }
    if (($b['kind'] ?? '') === 'installer') $u[] = 'installer';
    if (!empty($b['clone'])) $u[] = 'clone';
    if (!empty($b['makes'])) $u[] = 'makes';
    return array_values(array_unique($u));
}

// A one-line summary for lists: "Easy Setup (folder) · 6 settings · note".
function rs_boot_summary(array $b): string {
    if (!$b) return '';
    if ((int)($b['format'] ?? 0) === 1) { // a full launcher profile: its own shape
        $n = count((array)($b['settings'] ?? []));
        return 'Launcher profile' . (!empty($b['game']) ? ' · Easy Setup (' . (string)($b['game']['kind'] ?? 'copy') . ')' : '') . ($n ? ' · ' . $n . ' setting' . ($n === 1 ? '' : 's') : '');
    }
    $how = (string)($b['files']['how'] ?? '');
    $bits = [in_array($how, RS_BOOT_EASY, true) ? 'Easy Setup (' . $how . ')' : ($how !== '' ? 'copy: ' . $how : 'no files')];
    $n = count((array)($b['settings']['settings'] ?? []));
    if ($n) $bits[] = $n . ' setting' . ($n === 1 ? '' : 's');
    if (!empty($b['player_note'])) $bits[] = 'note';
    if (($b['kind'] ?? '') === 'installer') $bits[] = 'installer';
    return implode(' · ', $bits);
}

// (4.3) How profiles did on players' PCs (Beta 27+, from players who share usage): events of kind
// "profile" with detail {"profile_hash", "step": files|settings|start, "ok", "error"}. Grouped by
// recomp, profile and step, newest profile first, so a broken one stands out.
function rs_boot_results(int $days = 30): array {
    try {
        if (function_exists('rs_rl_more_tables')) rs_rl_more_tables();
        $st = rs_db()->prepare("SELECT game, version, detail, at FROM rl_events WHERE kind = 'profile' AND at > (UTC_TIMESTAMP() - INTERVAL ? DAY) ORDER BY at DESC LIMIT 20000");
        $st->execute([$days]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return []; }
    $by = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['detail'], true);
        if (!is_array($d)) continue;
        $hash = preg_match('/^[0-9a-f]{6,16}$/', (string)($d['profile_hash'] ?? '')) ? (string)$d['profile_hash'] : '?';
        $step = in_array($d['step'] ?? '', ['files', 'settings', 'start'], true) ? (string)$d['step'] : '?';
        $k = $r['game'] . '|' . $hash . '|' . $step;
        $g = &$by[$k];
        $g ??= ['game' => (string)$r['game'], 'hash' => $hash, 'step' => $step, 'ok' => 0, 'bad' => 0, 'errors' => [], 'last' => (string)$r['at'], 'first' => (string)$r['at']];
        if (!empty($d['ok'])) $g['ok']++; else { $g['bad']++; $err = mb_substr(trim((string)($d['error'] ?? '')), 0, 120); if ($err !== '') $g['errors'][$err] = ($g['errors'][$err] ?? 0) + 1; }
        $g['first'] = (string)$r['at'];
        unset($g);
    }
    $out = array_values($by);
    // Failing ones first, then the most played.
    usort($out, fn($a, $b) => [$b['bad'] > 0, $b['bad'] / max(1, $b['ok'] + $b['bad']), $b['ok'] + $b['bad']] <=> [$a['bad'] > 0, $a['bad'] / max(1, $a['ok'] + $a['bad']), $a['ok'] + $a['bad']]);
    return $out;
}
