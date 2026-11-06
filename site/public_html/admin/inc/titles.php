<?php
// (3.0) One game, many versions. A title is the game itself (Jet Set Radio Future); every PC recomp
// and PS5 app built from it is a version under it, with its own page, downloads and player reports.
// Its page on the site shows the others to pick from, and lists show the game once.
declare(strict_types=1);

function rs_titles_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS titles (
        id VARCHAR(64) PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        updated DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// pick: '' (the best-rated one), or 'pc:<id>' / 'ps5:<id>' to pin one as Recommended.
function rs_title_defaults(): array {
    return ['id' => '', 'name' => '', 'origin' => 'other', 'pick' => '', 'pc' => [], 'ps5' => []];
}

function rs_titles(): array {
    rs_titles_table();
    $out = [];
    foreach (rs_db()->query('SELECT id, data FROM titles ORDER BY id')->fetchAll() as $r) {
        $t = array_merge(rs_title_defaults(), json_decode((string)$r['data'], true) ?: []);
        $t['id'] = (string)$r['id'];
        $t['pc'] = array_values(array_unique(array_map('strval', (array)$t['pc'])));
        $t['ps5'] = array_values(array_unique(array_map('strval', (array)$t['ps5'])));
        $out[$t['id']] = $t;
    }
    uasort($out, fn($x, $y) => strcasecmp($x['name'], $y['name']));
    return $out;
}

function rs_title(string $id): ?array { return rs_titles()[$id] ?? null; }

function rs_title_save(array $t): void {
    rs_titles_table();
    $data = $t; unset($data['id']);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    rs_db()->prepare('INSERT INTO titles (id, data, updated) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), updated = NOW()')
        ->execute([$t['id'], $json]);
}

function rs_title_delete(string $id): void {
    rs_titles_table();
    rs_db()->prepare('DELETE FROM titles WHERE id = ?')->execute([$id]);
}

// A free address for a new title, from its name.
function rs_title_new_id(string $name): string {
    $base = rs_pc_slug($name);
    $have = rs_titles();
    $id = $base; $n = 2;
    while (isset($have[$id])) $id = substr($base, 0, 56) . '-' . $n++;
    return $id;
}

// Puts a version under a title, taking it out of any other title first (a version has one game).
function rs_title_add_member(string $titleId, string $kind, string $id): void {
    if (!in_array($kind, ['pc', 'ps5'], true) || $id === '') return;
    foreach (rs_titles() as $t) {
        if ($t['id'] === $titleId || !in_array($id, $t[$kind], true)) continue;
        $t[$kind] = array_values(array_diff($t[$kind], [$id]));
        if ($t['pick'] === "$kind:$id") $t['pick'] = '';
        rs_title_save($t);
    }
    $t = rs_title($titleId);
    if (!$t) return;
    if (!in_array($id, $t[$kind], true)) $t[$kind][] = $id;
    rs_title_save($t);
}

function rs_title_remove_member(string $titleId, string $kind, string $id): void {
    $t = rs_title($titleId);
    if (!$t || !in_array($kind, ['pc', 'ps5'], true)) return;
    $t[$kind] = array_values(array_diff($t[$kind], [$id]));
    if ($t['pick'] === "$kind:$id") $t['pick'] = '';
    rs_title_save($t);
}

// The game a version belongs to, if any.
function rs_title_of(string $kind, string $id): ?array {
    foreach (rs_titles() as $t) if (in_array($id, $t[$kind] ?? [], true)) return $t;
    return null;
}

// (3.1) Player scores per PC recomp: [app => [score, n]] (see play.php). Before that existed:
// the last 60 days of RecompLauncher's reports, turned into the same shape.
function rs_title_recent_counts(): array {
    if (function_exists('rs_play_scores')) {
        $out = [];
        foreach (rs_play_scores()['pc'] ?? [] as $id => $x) $out[$id] = ['score' => (float)$x['score'], 'n' => (int)$x['n']];
        return $out;
    }
    rs_pc_tables();
    $c = [];
    foreach (rs_db()->query("SELECT app, verdict, COUNT(*) n FROM pc_feedback WHERE hidden = 0 AND created > (NOW() - INTERVAL 60 DAY) GROUP BY app, verdict")->fetchAll() as $r)
        $c[(string)$r['app']][(string)$r['verdict']] = (int)$r['n'];
    $out = [];
    foreach ($c as $id => $v) { $n = array_sum($v); $out[$id] = ['score' => $n ? 100 * (($v['runs'] ?? 0) + 0.5 * ($v['issues'] ?? 0)) / $n : 0, 'n' => $n]; }
    return $out;
}

// Which version is Recommended: the pinned one, else the PC recomp players rate best
// (3 reports or more), else the most downloaded.
// $pcApps: id => app as listed. $ps5Ids: the PS5 members that are shown.
function rs_title_pick(array $t, array $pcApps, array $ps5Ids, array $recent): string {
    $pcIds = array_values(array_filter($t['pc'], fn($id) => isset($pcApps[$id])));
    if ($t['pick'] !== '') {
        [$k, $id] = array_pad(explode(':', $t['pick'], 2), 2, '');
        if (($k === 'pc' && in_array($id, $pcIds, true)) || ($k === 'ps5' && in_array($id, $ps5Ids, true))) return $t['pick'];
    }
    $best = ''; $bestScore = -1.0;
    foreach ($pcIds as $id) {
        $c = $recent[$id] ?? ['score' => 0, 'n' => 0];
        if ($c['n'] < 3) continue;
        if ($c['score'] > $bestScore) { $best = $id; $bestScore = $c['score']; }
    }
    if ($best !== '') return "pc:$best";
    usort($pcIds, fn($x, $y) => (int)$pcApps[$y]['downloads'] <=> (int)$pcApps[$x]['downloads']);
    if ($pcIds) return 'pc:' . $pcIds[0];
    return $ps5Ids ? 'ps5:' . $ps5Ids[0] : '';
}

// pc-catalog.json's titles (games with two versions or more), and missing art filled in from
// another version of the same game. $apps: the catalog's apps, changed in place.
function rs_title_catalog(array &$apps): array {
    $byId = [];
    foreach ($apps as $i => $a) $byId[$a['id']] = $i;
    $listed = [];
    foreach ($apps as $a) $listed[$a['id']] = $a;
    $ps5 = [];
    foreach (rs_games(false) as $g) $ps5[$g['id']] = $g;
    $recent = rs_title_recent_counts();
    $out = [];
    foreach (rs_titles() as $t) {
        $pcIds = array_values(array_filter($t['pc'], fn($id) => isset($byId[$id])));
        $ps5Ids = array_values(array_filter($t['ps5'], fn($id) => isset($ps5[$id])));
        if (count($pcIds) + count($ps5Ids) < 2) continue;
        // Art: a version without some picture takes it from the first one that has it.
        foreach (['cover', 'hero', 'icon', 'logo'] as $k) {
            $donor = '';
            foreach ($pcIds as $id) if (($apps[$byId[$id]][$k] ?? '') !== '') { $donor = $apps[$byId[$id]][$k]; break; }
            if ($donor === '') continue;
            foreach ($pcIds as $id) if (($apps[$byId[$id]][$k] ?? '') === '') $apps[$byId[$id]][$k] = $donor;
        }
        $icon = '';
        foreach ($pcIds as $id) if ($apps[$byId[$id]]['icon'] !== '') { $icon = $apps[$byId[$id]]['icon']; break; }
        foreach ($pcIds as $id) $apps[$byId[$id]]['title'] = $t['id'];
        $out[] = [
            'id' => $t['id'], 'name' => $t['name'], 'origin' => $t['origin'],
            'pick' => rs_title_pick($t, array_intersect_key($listed, array_flip($pcIds)), $ps5Ids, $recent),
            'pc' => $pcIds,
            'ps5' => array_map(fn($id) => [
                'id' => $id, 'name' => (string)$ps5[$id]['name'], 'author' => (string)$ps5[$id]['author'],
                'version' => (string)$ps5[$id]['version'], 'released' => (string)$ps5[$id]['released'],
                'icon' => $ps5[$id]['icon'] !== '' ? rs_abs((string)$ps5[$id]['icon']) : $icon,
            ], $ps5Ids),
        ];
    }
    return $out;
}

// Versions that look like the same game and aren't under one yet: PC recomps with the same game
// from the same console (4.1),
// and PS5 apps named like one of them. -> [[name, origin, pc => [...ids], ps5 => [...ids]]]
function rs_title_suggestions(): array {
    $norm = function (string $s): string {
        $s = strtolower($s);
        $s = preg_replace('/\b(recomp(iled|ilation)?|pc port|port|decomp(ilation)?|for pc|the|ps5)\b/', ' ', $s) ?? $s;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
    };
    $taken = ['pc' => [], 'ps5' => []];
    foreach (rs_titles() as $t) { foreach ($t['pc'] as $id) $taken['pc'][$id] = 1; foreach ($t['ps5'] as $id) $taken['ps5'][$id] = 1; }
    $groups = [];
    foreach (rs_pc_apps() as $a) {
        if (isset($taken['pc'][$a['id']])) continue;
        $key = $norm($a['game'] !== '' ? $a['game'] : $a['name']);
        if ($key === '') continue;
        // (4.1) The same name on another console is another game (Ratchet & Clank on PS2 and its PS4 remake).
        $key .= '|' . $a['origin'];
        $groups[$key] ??= ['name' => $a['game'] !== '' ? $a['game'] : $a['name'], 'origin' => $a['origin'], 'pc' => [], 'ps5' => []];
        $groups[$key]['pc'][] = $a['id'];
    }
    foreach (rs_games() as $g) {
        if (isset($taken['ps5'][$g['id']])) continue;
        $key = $norm((string)$g['name']);
        foreach ($groups as $k => $x) if (strstr($k, '|', true) === $key) { $groups[$k]['ps5'][] = $g['id']; break; }
    }
    return array_values(array_filter($groups, fn($x) => count($x['pc']) + count($x['ps5']) >= 2));
}

// A PS5 version with no pictures gets them from a PC version of the same game: the icon and logo
// as they are, the backdrop cropped to 16:9. -> how many it filled.
function rs_title_ps5_art(array $t): int {
    $pc = array_values(array_filter(array_map('rs_pc_app', $t['pc'])));
    $donor = null;
    foreach ($pc as $a) if ($a['icon'] !== '' && $a['hero'] !== '') { $donor = $a; break; }
    if (!$donor) throw new RuntimeException('None of its PC versions has an icon and a backdrop to copy.');
    $file = fn(string $p) => RS_MEDIA . '/' . basename(parse_url($p, PHP_URL_PATH) ?: '');
    $fake = fn(string $path) => ['tmp_name' => $path, 'name' => basename($path), 'error' => UPLOAD_ERR_OK, 'size' => (int)@filesize($path)];
    $done = 0;
    foreach ($t['ps5'] as $id) {
        $g = rs_game($id);
        if (!$g) continue;
        $changed = false;
        if ($g['icon'] === '' && is_file($file($donor['icon']))) { $g = array_merge($g, rs_save_image($fake($file($donor['icon'])), 'icon', $g['id'])); $changed = true; }
        if ($g['logo'] === '' && $donor['logo'] !== '' && is_file($file($donor['logo']))) { $g = array_merge($g, rs_save_image($fake($file($donor['logo'])), 'logo', $g['id'])); $changed = true; }
        if ($g['hero'] === '' && is_file($file($donor['hero']))) {
            [$src, $w, $h] = rs_image_open($fake($file($donor['hero'])));
            $k = max(1920 / $w, 1080 / $h);
            $sw = (int)round(1920 / $k); $sh = (int)round(1080 / $k);
            $dst = imagecreatetruecolor(1920, 1080);
            imagecopyresampled($dst, $src, 0, 0, intdiv($w - $sw, 2), intdiv($h - $sh, 2), 1920, 1080, $sw, $sh);
            $tmp = tempnam(sys_get_temp_dir(), 'rsh');
            imagejpeg($dst, $tmp, 90);
            $g = array_merge($g, rs_save_image($fake($tmp), 'hero', $g['id']));
            @unlink($tmp);
            $changed = true;
        }
        if ($changed) { rs_save_game($g); $done++; }
    }
    return $done;
}
