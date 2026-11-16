<?php
// (4.13) RecompLauncher playtime sync (launcher PR #251, issue #210). Each signed-in PC sends its own
// total per game; the site keeps one row per account, PC and game, and gives back the sums.
// docs/backend/07-launcher-api.md.
//
// rl_playtime: uid (account id), pc (launcher_devices.id of the token), game (catalog id, or
// "<recomp id>--<game id>" for one game of a recomp that carries several), secs (total seconds on that
// PC), last (unix time last played on that PC), updated (unix time of the last write).
// A PC only ever writes its own rows, and a write never lowers secs or last. Removing a PC keeps its rows;
// deleting the account deletes them (rs_rl_forget). Private to the account: nothing public reads it.
declare(strict_types=1);

const RS_RL_PLAY_MAX_SECS = 20 * 365 * 86400; // more than this is a broken clock or a bad count
const RS_RL_PLAY_MAX_ROWS = 500;              // entries in one send

function rs_rl_play_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS rl_playtime (
        uid INT NOT NULL,
        pc INT NOT NULL,
        game VARCHAR(100) NOT NULL,
        secs BIGINT NOT NULL DEFAULT 0,
        last BIGINT NOT NULL DEFAULT 0,
        updated BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (uid, pc, game),
        KEY uid_game (uid, game)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_rl_play_id_ok(string $g): bool { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $g); }

// This PC's totals. Bad entries are skipped, not the whole send. -> [stored, skipped]
function rs_rl_play_put(int $uid, int $pc, array $games): array {
    rs_rl_play_tables();
    $now = time();
    $rows = [];
    $skipped = 0;
    foreach (array_slice($games, 0, RS_RL_PLAY_MAX_ROWS) as $g) {
        if (!is_array($g)) { ++$skipped; continue; }
        $id = (string)($g['game'] ?? '');
        $secs = $g['secs'] ?? null; $last = $g['last'] ?? 0;
        if (!rs_rl_play_id_ok($id) || !is_numeric($secs) || !is_numeric($last)) { ++$skipped; continue; }
        $secs = (int)$secs; $last = (int)$last;
        if ($secs < 0 || $secs > RS_RL_PLAY_MAX_SECS || $last < 0 || $last > $now + 86400) { ++$skipped; continue; }
        // The same game twice in one send: the larger numbers.
        $rows[$id] = isset($rows[$id]) ? [max($rows[$id][0], $secs), max($rows[$id][1], $last)] : [$secs, $last];
    }
    $skipped += max(0, count($games) - RS_RL_PLAY_MAX_ROWS);
    foreach (array_chunk($rows, 100, true) as $chunk) {
        $sql = []; $args = [];
        foreach ($chunk as $id => [$secs, $last]) { $sql[] = '(?, ?, ?, ?, ?, ?)'; array_push($args, $uid, $pc, (string)$id, $secs, $last, $now); }
        rs_db()->prepare('INSERT INTO rl_playtime (uid, pc, game, secs, last, updated) VALUES ' . implode(', ', $sql)
            . ' ON DUPLICATE KEY UPDATE secs = GREATEST(secs, VALUES(secs)), last = GREATEST(last, VALUES(last)), updated = VALUES(updated)')->execute($args);
    }
    return [count($rows), $skipped];
}

// What the catalog says about ids, kept small and rebuilt when the catalog changes:
// replaced {old id: new id}, titles {title id: [recomp ids]}, multi {recomp id: 1} (carries several
// games), subTitle {"recomp--game": title id} (only when the catalog maps a game to a title).
function rs_rl_play_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    $cat = RS_PUBLIC . '/pc-catalog.json';
    $mt = (int)@filemtime($cat);
    $f = dirname(RS_PUBLIC) . '/recompstore-private/rl-play-map.json';
    $j = json_decode((string)@file_get_contents($f), true);
    if (is_array($j) && (int)($j['mt'] ?? -1) === $mt) return $map = $j;
    $c = $mt ? json_decode((string)file_get_contents($cat), true) : null;
    $map = ['mt' => $mt, 'replaced' => [], 'titles' => [], 'multi' => [], 'subTitle' => [], 'names' => []];
    if (is_array($c)) {
        foreach ((array)($c['replaced'] ?? []) as $old => $new) $map['replaced'][(string)$old] = (string)$new;
        foreach ((array)($c['titles'] ?? []) as $t) if (is_array($t) && (string)($t['id'] ?? '') !== '') $map['titles'][(string)$t['id']] = array_values(array_map('strval', (array)($t['pc'] ?? [])));
        $apps = (array)($c['apps'] ?? []);
        foreach ((array)($c['apps_min'] ?? []) as $g) foreach ((array)($g['apps'] ?? []) as $a) $apps[] = $a;
        foreach ($apps as $a) {
            if (!is_array($a) || (string)($a['id'] ?? '') === '') continue;
            $rid = (string)$a['id'];
            $map['names'][$rid] = (string)($a['name'] ?? $rid);
            $games = array_filter((array)($a['games'] ?? []), fn($g) => is_array($g) && (string)($g['id'] ?? '') !== '');
            if (count($games) < 2) continue;
            $map['multi'][$rid] = 1;
            foreach ($games as $g) {
                $full = $rid . '--' . (string)$g['id'];
                $map['names'][$full] = (string)($g['name'] ?? $full);
                if ((string)($g['title'] ?? '') !== '') $map['subTitle'][$full] = (string)$g['title'];
            }
        }
    }
    @mkdir(dirname($f), 0775, true);
    @file_put_contents($f . '.tmp', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @rename($f . '.tmp', $f);
    return $map;
}

// An id as it counts now: a replaced listing's id becomes the one that replaced it (also for one of its games).
function rs_rl_play_canon(string $id, array $map): string {
    $rep = $map['replaced'];
    if (isset($rep[$id])) return $rep[$id];
    $p = strpos($id, '--');
    if ($p !== false && isset($rep[substr($id, 0, $p)])) return $rep[substr($id, 0, $p)] . substr($id, $p);
    return $id;
}

// The account's totals over all its PCs: [games {id: {secs, last}}, titles {id: {secs, last}}].
function rs_rl_play_totals(int $uid): array {
    rs_rl_play_tables();
    $map = rs_rl_play_map();
    $st = rs_db()->prepare('SELECT game, SUM(secs) s, MAX(last) l FROM rl_playtime WHERE uid = ? GROUP BY game');
    $st->execute([$uid]);
    $games = [];
    foreach ($st->fetchAll() as $r) {
        $id = rs_rl_play_canon((string)$r['game'], $map);
        $g = $games[$id] ?? ['secs' => 0, 'last' => 0];
        $games[$id] = ['secs' => $g['secs'] + (int)$r['s'], 'last' => max($g['last'], (int)$r['l'])];
    }
    // A title: its recomps' rows. A recomp with several games counts only through its games the catalog
    // puts under the title.
    $titles = [];
    $add = function (string $tid, array $g) use (&$titles) {
        $t = $titles[$tid] ?? ['secs' => 0, 'last' => 0];
        $titles[$tid] = ['secs' => $t['secs'] + $g['secs'], 'last' => max($t['last'], $g['last'])];
    };
    foreach ($map['titles'] as $tid => $ids) foreach ($ids as $rid) if (!isset($map['multi'][$rid]) && !str_contains($rid, '--') && isset($games[$rid])) $add((string)$tid, $games[$rid]);
    foreach ($map['subTitle'] as $full => $tid) if (isset($games[$full]) && isset($map['titles'][$tid])) $add((string)$tid, $games[$full]);
    return [$games, $titles];
}

// For the account page: each game with its total and each PC's share, most played first.
function rs_rl_play_list(int $uid): array {
    rs_rl_play_tables();
    rs_rl_tables();
    $map = rs_rl_play_map();
    $names = [];
    $st = rs_db()->prepare('SELECT id, name FROM launcher_devices WHERE account_id = ?');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $d) $names[(int)$d['id']] = (string)$d['name'];
    $st = rs_db()->prepare('SELECT pc, game, secs, last FROM rl_playtime WHERE uid = ?');
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $id = rs_rl_play_canon((string)$r['game'], $map);
        $o = $out[$id] ?? ['game' => $id, 'name' => $map['names'][$id] ?? $id, 'secs' => 0, 'last' => 0, 'pcs' => []];
        $o['secs'] += (int)$r['secs'];
        $o['last'] = max($o['last'], (int)$r['last']);
        $pc = (int)$r['pc'];
        $p = $o['pcs'][$pc] ?? ['pc' => $pc, 'name' => $names[$pc] ?? '', 'removed' => !isset($names[$pc]), 'secs' => 0, 'last' => 0];
        $p['secs'] += (int)$r['secs'];
        $p['last'] = max($p['last'], (int)$r['last']);
        $o['pcs'][$pc] = $p;
        $out[$id] = $o;
    }
    foreach ($out as &$o) { $o['pcs'] = array_values($o['pcs']); usort($o['pcs'], fn($x, $y) => $y['secs'] <=> $x['secs']); }
    unset($o);
    $out = array_values(array_filter($out, fn($o) => $o['secs'] > 0));
    usort($out, fn($x, $y) => $y['secs'] <=> $x['secs']);
    return $out;
}

// Clears a game on every PC (and the ids that count as it), or everything. -> rows removed
function rs_rl_play_clear(int $uid, string $game = ''): int {
    rs_rl_play_tables();
    if ($game === '') {
        $st = rs_db()->prepare('DELETE FROM rl_playtime WHERE uid = ?');
        $st->execute([$uid]);
        return $st->rowCount();
    }
    if (!rs_rl_play_id_ok($game)) return 0;
    $map = rs_rl_play_map();
    $st = rs_db()->prepare('SELECT DISTINCT game FROM rl_playtime WHERE uid = ?');
    $st->execute([$uid]);
    $n = 0;
    $del = rs_db()->prepare('DELETE FROM rl_playtime WHERE uid = ? AND game = ?');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $g) if ($g === $game || rs_rl_play_canon((string)$g, $map) === $game) { $del->execute([$uid, $g]); $n += $del->rowCount(); }
    return $n;
}
