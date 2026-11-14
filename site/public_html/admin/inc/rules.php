<?php
// (4.10) Rules: what Claude used to work out, done with plain code. Fast, free and the same answer every time.
//   - rs_rules_kind(): is it a recomp/decomp/port, or an emulator, tool, mod, fan game or a project with no build?
//   - rs_rules_fill(): a page for a listing from the developer's submission or the README (name, game, console,
//     summary, description, genre, tags), shaped like Claude's answer so Auto-add uses it the same way.
//   - rs_rules_check(): everything we can tell about a repo before anyone spends time or money on it, with what
//     to do next (Auto-add, Coming soon, ask the developer, decline).
// Claude is only used when a person asks for it ("Write it with AI" on a draft), or when Settings say so.
declare(strict_types=1);
require_once __DIR__ . '/pc.php';

// Words that point one way or the other, matched on the name, description, topics and README.
const RS_RULES_YES = '/\b(re-?comp(iled|ilation)?|static(ally)? recomp|de-?comp(ilation|iled)?|native (pc|windows|linux) (port|build|version)|pc port|reverse[- ]engineer(ed|ing)|n64recomp|xenonrecomp|rexglue|ps2recomp|bring your own|your own (copy|rom|iso|dump|disc)|legally obtained|own copy of the game)\b/i';
const RS_RULES_EMU = '/\b(emulator|emulation core|libretro core|retroarch core)\b/i';
const RS_RULES_TOOL = '/\b(mod manager|modloader|mod loader|launcher for|frontend|front-end|texture pack|save editor|randomi[sz]er|trainer|cheat|toolkit|disassembler|decompiler tool|sdk for|library for|translation patch|rom hack)\b/i';
const RS_RULES_FAN = '/\b(fan[- ]game|fan[- ]made game|fangame|remake from scratch|inspired by|clone of|engine recreation)\b/i';
const RS_RULES_GAMEFILE = '/\.(z64|n64|v64|iso|xex|xiso|god|cue|chd|gcm|rvz|wbfs|nsp|xci|nds|3ds|cia|gba|gbc|gb|sfc|smc|nes|md|gen|psarc|pkg|sfo|rpx|wud|wux|bin)$/i';

// Console names to our keys (longest first so "Nintendo 64" wins over "Nintendo").
function rs_rules_origin(string $text): string {
    static $map = null;
    if ($map === null) {
        $map = ['nintendo switch 2' => 'switch2', 'switch 2' => 'switch2', 'xbox 360' => 'x360', 'xbox360' => 'x360', 'x360' => 'x360', 'xbla' => 'x360',
            'playstation 5' => 'ps5', 'playstation 4' => 'ps4', 'playstation 3' => 'ps3', 'playstation 2' => 'ps2', 'ps5' => 'ps5', 'ps4' => 'ps4', 'ps3' => 'ps3', 'ps2' => 'ps2',
            'playstation portable' => 'psp', 'psp' => 'psp', 'ps1' => 'ps1', 'psx' => 'ps1', 'playstation' => 'ps1',
            'nintendo 64' => 'n64', 'n64' => 'n64', 'gamecube' => 'gc', 'game cube' => 'gc', 'wii u' => 'wiiu', 'wiiu' => 'wiiu', 'wii' => 'wii',
            'super nintendo' => 'snes', 'super famicom' => 'snes', 'snes' => 'snes', 'game boy advance' => 'gba', 'gba' => 'gba', 'game boy color' => 'gbc', 'gbc' => 'gbc',
            'game boy' => 'gb', 'nintendo 3ds' => '3ds', '3ds' => '3ds', 'nintendo ds' => 'ds', 'nds' => 'ds', 'virtual boy' => 'vb', 'nintendo switch' => 'switch', 'switch' => 'switch',
            'nes' => 'nes', 'famicom' => 'nes', 'dreamcast' => 'dc', 'saturn' => 'saturn', 'mega drive' => 'genesis', 'genesis' => 'genesis', '32x' => 's32x', 'original xbox' => 'xbox', 'xbox' => 'xbox'];
    }
    $t = mb_strtolower($text);
    $best = ''; $at = PHP_INT_MAX;
    foreach ($map as $k => $v) {
        if (!isset(RS_PC_ORIGINS[$v])) continue;
        if (preg_match('/(?<![a-z0-9])' . preg_quote($k, '/') . '(?![a-z0-9])/', $t, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $at) { $at = $m[0][1]; $best = $v; }
    }
    return $best;
}

// The README as plain text paragraphs (no badges, images, tables, code or headings).
function rs_rules_paragraphs(string $md): array {
    $md = preg_replace('/```.*?```/s', '', $md);
    $md = preg_replace('/<!--.*?-->/s', '', (string)$md);
    $out = [];
    foreach (preg_split('/\n\s*\n/', (string)$md) as $p) {
        $p = trim($p);
        if ($p === '' || preg_match('/^(#|\||!\[|<img|<p align|\[!\[|>\s*\[!|-{3,}|={3,})/i', $p)) continue;
        $p = preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]+)\]\([^)]*\)/', '/<[^>]+>/', '/[*_`]{1,3}/'], ['', '$1', '', ''], $p);
        $p = trim(preg_replace('/\s+/', ' ', (string)$p));
        if (mb_strlen($p) < 40 || preg_match('/^(table of contents|contents|license|credits|building|build instructions)\b/i', $p)) continue;
        $out[] = $p;
        if (count($out) >= 6) break;
    }
    return $out;
}

// The first heading of the README, when it reads like a name.
function rs_rules_title(string $md): string {
    if (preg_match('/^\s*#\s+(.{3,90})$/m', $md, $m)) {
        $t = trim(preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/<[^>]+>/', '/[*_`]/'], '', $m[1]));
        if ($t !== '' && mb_strlen($t) <= 80) return $t;
    }
    return '';
}

// Recomp, emulator, tool, mod, fan game? -> ['kind' => recomp|emulator|tool|fan|unsure, 'why' => line]
function rs_rules_kind(string $name, string $desc, string $readme, array $topics = []): array {
    $head = $name . ' ' . $desc . ' ' . implode(' ', $topics);
    $all = $head . ' ' . mb_substr($readme, 0, 6000);
    $yes = preg_match(RS_RULES_YES, $all, $my);
    if (preg_match(RS_RULES_EMU, $head, $m) && !$yes) return ['kind' => 'emulator', 'why' => 'Says it\'s an ' . strtolower($m[0]) . ', not a recomp'];
    if (preg_match(RS_RULES_TOOL, $head, $m) && !$yes) return ['kind' => 'tool', 'why' => 'Reads like a tool or mod (' . strtolower($m[0]) . '), not a game'];
    if (preg_match(RS_RULES_FAN, $head, $m) && !$yes) return ['kind' => 'fan', 'why' => 'Reads like a fan game (' . strtolower($m[0]) . ')'];
    if ($yes) return ['kind' => 'recomp', 'why' => 'Says it\'s a recomp, decomp or native port ("' . mb_strimwidth(trim($my[0]), 0, 40, '…') . '")'];
    if (preg_match(RS_RULES_EMU, $all)) return ['kind' => 'unsure', 'why' => 'Mentions an emulator; could be a wrapper'];
    return ['kind' => 'unsure', 'why' => 'Doesn\'t say what it is in so many words'];
}

// A page from the submission (the developer's own words come first) and the README. Shaped like Claude's answer.
function rs_rules_fill(array $a, array $f, string $readme, array $names = [], array $sub = []): array {
    $paras = rs_rules_paragraphs($readme);
    $desc = (string)($f['description'] ?? '');
    $title = trim((string)($sub['name'] ?? '')) ?: rs_rules_title($readme);
    if ($title === '') $title = ucwords(trim(preg_replace('/[-_]+/', ' ', (string)preg_replace('/(^|[-_])(pc|port|recomp|decomp)$/i', '', basename((string)$a['repo'])))));
    $game = trim((string)($sub['game'] ?? ''));
    if ($game === '') $game = trim((string)($f['game'] ?? ''));
    if ($game === '') $game = trim((string)preg_replace('/\s*[:\-–(]\s*(pc|native|recomp\w*|decomp\w*|port|re)\b.*$/i', '', $title));
    $origin = (string)($sub['origin'] ?? '');
    if (!isset(RS_PC_ORIGINS[$origin])) $origin = rs_rules_origin($title . ' ' . $desc . ' ' . implode(' ', array_slice($paras, 0, 3)));
    if (!isset(RS_PC_ORIGINS[$origin]) && isset(RS_PC_ORIGINS[(string)($f['origin'] ?? '')])) $origin = (string)$f['origin'];
    $blurb = trim((string)($sub['blurb'] ?? '')) ?: mb_strimwidth(trim($desc) ?: (string)($paras[0] ?? ''), 0, 158, '…');
    $long = trim((string)($sub['description'] ?? '')) ?: implode("\n\n", array_slice($paras, 0, 3));
    $kind = rs_rules_kind($title, $desc, $readme, (array)($f['topics'] ?? []));
    $concerns = [];
    $gameFiles = array_values(array_filter($names, fn($n) => preg_match(RS_RULES_GAMEFILE, (string)$n) && !preg_match('/(^|\/)(lib|bin|data\/shaders?)\//i', (string)$n)));
    if ($gameFiles) $concerns[] = 'Ships what looks like game data: ' . implode(', ', array_slice(array_map('basename', $gameFiles), 0, 3));
    if ($kind['kind'] !== 'recomp') $concerns[] = $kind['why'];
    $tags = [];
    foreach (['widescreen' => 'Widescreen', '60 ?fps|high frame ?rate|120 ?fps' => 'High FPS', 'mods?\b' => 'Mods', 'controller|gamepad' => 'Controller', 'ray[- ]?trac' => 'Ray tracing', 'online|multiplayer|netplay' => 'Online', 'linux' => 'Linux', 'mac(os)?\b' => 'macOS'] as $rx => $t)
        if (preg_match('/\b(' . $rx . ')/i', $readme)) $tags[] = $t;
    return [
        'name' => mb_substr($title, 0, 120), 'game' => mb_substr($game, 0, 160), 'origin' => $origin, 'blurb' => $blurb, 'description' => mb_substr($long, 0, 8000),
        'tags' => $tags, 'features' => [], 'verdict' => $concerns ? 'check' : 'good', 'concerns' => $concerns,
        'notes' => 'Written from the ' . ($sub ? 'submission and the ' : '') . 'README, without AI. ' . $kind['why'] . '.',
    ];
}

// Everything we can tell about a repo before spending time on it. -> ['ok', 'verdict', 'reasons' => [[level, text]], 'options' => [...], ...]
// verdict: 'go' (Auto-add it), 'soon' (no build yet: Coming soon), 'skip' (not for us), 'listed' (we have it), 'look' (a person decides).
function rs_rules_check(string $link): array {
    $l = rs_repo_from_link($link);
    if (!$l) return ['ok' => false, 'verdict' => 'look', 'reasons' => [['bad', 'That isn\'t a repo link we can read.']], 'options' => []];
    $host = rs_host_norm((string)$l['host']);
    $key = rs_repo_key($host, (string)$l['repo']);
    $out = ['ok' => true, 'key' => $key, 'link' => rs_host_web($host, (string)$l['repo']), 'reasons' => [], 'options' => [], 'verdict' => 'go', 'name' => basename((string)$l['repo'])];
    foreach (rs_pc_apps(true) as $x) if (strcasecmp(rs_pc_key($x), $key) === 0)
        return $out + ['app' => $x['id']] + ['verdict' => 'listed', 'reasons' => [['ok', 'Already on RecompLauncher as ' . $x['name'] . (!empty($x['hidden']) ? ' (hidden: ' . rs_pc_hidden_why($x) . ')' : '')]]];
    $f = rs_pc_read_repo((string)$l['repo'], $host);
    if (empty($f['ok'])) return ['ok' => false, 'verdict' => 'look', 'key' => $key, 'link' => $out['link'], 'reasons' => [['bad', (string)($f['why'] ?? 'The repo couldn\'t be read.')]], 'options' => ['contact']];
    $out['name'] = (string)($f['name'] ?? $out['name']);
    $files = array_filter([(string)($f['assets']['win'] ?? ''), (string)($f['assets']['linux'] ?? ''), (string)($f['assets']['mac'] ?? '')]);
    $rd = rs_pc_readme((string)$f['repo'], $host, 21600);
    $kind = rs_rules_kind((string)$f['repo'], (string)($f['description'] ?? ''), (string)($rd['md'] ?? ''), (array)($f['topics'] ?? []));
    $out['kind'] = $kind['kind'];
    $out['reasons'][] = [$kind['kind'] === 'recomp' ? 'ok' : ($kind['kind'] === 'unsure' ? 'warn' : 'bad'), $kind['why']];
    if (!$files) {
        $out['verdict'] = 'soon';
        $out['reasons'][] = ['warn', ($f['version'] ?? '') !== '' ? 'Its newest release (' . $f['version'] . ') has no download for Windows, Linux or macOS' : 'No release with a download yet'];
    } else {
        $per = [];
        foreach (['win' => 'Windows', 'linux' => 'Linux', 'mac' => 'Mac'] as $k => $n) if ((string)($f['assets'][$k] ?? '') !== '') $per[] = $n;
        $out['reasons'][] = ['ok', 'Release ' . ($f['version'] ?: '?') . ' has a ' . implode(', ', $per) . ' download'];
    }
    if (!empty($f['archived'])) $out['reasons'][] = ['warn', 'Archived on GitHub (the developer closed it)'];
    if (!empty($f['fork'])) $out['reasons'][] = ['warn', 'A fork of another repo'];
    if (in_array($kind['kind'], ['emulator', 'tool', 'fan'], true)) $out['verdict'] = 'skip';
    elseif ($out['verdict'] === 'go' && $kind['kind'] === 'unsure') $out['verdict'] = 'look';
    $out['options'] = match ($out['verdict']) {
        'go' => ['autoadd', 'contact'],
        'look' => ['autoadd', 'soon', 'contact', 'decline'],
        'soon' => ['soon', 'contact', 'decline'],
        'skip' => ['decline', 'autoadd', 'contact'],
        default => [],
    };
    return $out;
}
