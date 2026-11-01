<?php
// RecompStore website (2.0): every title ID the store knows is taken, for recompstore.com/ids,
// so developers can pick one that won't clash with another app on a PS5. JSON, read-only.
// Hidden games are listed as reserved, betas as in testing (names of either aren't shown).
// (2.7.3) Each app with its developer, icon and page; IDs developers reserved in their hub; PS5
// apps found on GitHub and in Prospero Store's catalog that aren't in the store.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

try {
    // Names only of what's published (catalog.json); anything else in the admin, saved but not
    // out yet, is listed without its name.
    $published = [];
    $cat = json_decode((string)@file_get_contents(dirname(__DIR__) . '/catalog.json'), true);
    $devNames = [];
    foreach (is_array($cat['developers'] ?? null) ? $cat['developers'] : [] as $d)
        if (is_array($d) && isset($d['slug'], $d['name'])) $devNames[(string)$d['slug']] = (string)$d['name'];
    // (2.0) What only the 2.0 store lists is named once the website's 2.0 side is out for everyone;
    // testers-only apps never are (they aren't in catalog.json).
    $lists = ['apps', 'newerStoreApps', 'comingSoon'];
    if (!empty($cat['site']['v2'])) array_push($lists, 'v2Apps', 'comingSoonV2');
    foreach ($lists as $list)
        foreach (is_array($cat[$list] ?? null) ? $cat[$list] : [] as $a)
            if (is_array($a) && isset($a['id'], $a['name']) && !isset($published[(string)$a['id']]))
                $published[(string)$a['id']] = ['name' => (string)$a['name'], 'icon' => (string)($a['icon'] ?? ''),
                    'by' => (string)(($a['dev'] ?? '') !== '' && isset($devNames[$a['dev']]) ? $devNames[$a['dev']] : ($a['author'] ?? '')),
                    'cat' => (string)($a['category'] ?? ''), 'soon' => str_starts_with($list, 'comingSoon')];
    $store = [];
    foreach (rs_games(true) as $g) {
        if (!preg_match('/^[A-Z]{4}\d{5}$/', (string)$g['id'])) continue;
        if (isset($published[$g['id']])) $store[] = ['id' => $g['id'], 'testing' => false] + $published[$g['id']];
        elseif ($g['beta'] || !empty($g['testers'])) $store[] = ['id' => $g['id'], 'name' => 'An app in testing', 'testing' => true];
        else $store[] = ['id' => $g['id'], 'name' => 'Reserved for an app on its way', 'testing' => true];
    }
    usort($store, fn($a, $b) => strcmp($a['id'], $b['id']));
    $taken = [];
    foreach (rs_taken_ids() as $id => $note) $taken[] = ['id' => $id, 'note' => $note];
    usort($taken, fn($a, $b) => strcmp($a['id'], $b['id']));
    // (2.7.3) PS5 apps on GitHub that aren't in the store (the title ID radar's finds).
    $gh = [];
    try {
        rs_health_tables();
        // PS5 title IDs only (PPSA): a made-up one isn't on any console. Not the ones you marked Not interested.
        foreach (rs_db()->query("SELECT repo, title_id, name, via FROM found_apps WHERE title_id LIKE 'PPSA%' AND repo <> '' AND state IN ('new', 'added') ORDER BY title_id")->fetchAll() as $r)
            if (!isset($published[$r['title_id']]) && !in_array($r['title_id'], array_column($store, 'id'), true))
                $gh[] = ['id' => (string)$r['title_id'], 'name' => (string)$r['name'], 'repo' => (string)$r['repo']];
    } catch (Throwable $e) { /* none yet */ }
    $gh = array_values(array_column($gh, null, 'id'));
    // (2.7.3) Held by developers for apps on their way.
    $reserved = [];
    foreach (rs_res_active() as $r)
        if ($r['used_at'] === null && !in_array($r['title_id'], array_column($store, 'id'), true))
            $reserved[] = ['id' => (string)$r['title_id'], 'by' => (string)$r['who'], 'slug' => isset($devNames[$r['slug']]) ? (string)$r['slug'] : ''];
    echo json_encode(['ok' => true, 'store' => $store, 'reserved' => $reserved, 'github' => $gh, 'taken' => $taken,
        'block' => ['from' => RS_MOVE_BLOCK[0], 'to' => RS_MOVE_BLOCK[1]], 'checked' => (string)(rs_kv_get('found_run')['v'] ?? '')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'The list can\'t be read right now.']);
}
