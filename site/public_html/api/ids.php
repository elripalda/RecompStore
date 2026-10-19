<?php
// Recomp Store website (2.0): every title ID the store knows is taken, for recompstore.com/#/ids,
// so developers can pick one that won't clash with another app on a PS5. JSON, read-only.
// Hidden games are listed as reserved, betas as in testing (names of either aren't shown).
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
    // (2.0) What only the 2.0 store lists is named once the website's 2.0 side is out for everyone;
    // testers-only apps never are (they aren't in catalog.json).
    $lists = ['apps', 'newerStoreApps', 'comingSoon'];
    if (!empty($cat['site']['v2'])) array_push($lists, 'v2Apps', 'comingSoonV2');
    foreach ($lists as $list)
        foreach (is_array($cat[$list] ?? null) ? $cat[$list] : [] as $a)
            if (is_array($a) && isset($a['id'], $a['name'])) $published[(string)$a['id']] = (string)$a['name'];
    $store = [];
    foreach (rs_games(true) as $g) {
        if (!preg_match('/^[A-Z]{4}\d{5}$/', (string)$g['id'])) continue;
        if (isset($published[$g['id']])) $store[] = ['id' => $g['id'], 'name' => $published[$g['id']], 'testing' => false];
        elseif ($g['beta'] || !empty($g['testers'])) $store[] = ['id' => $g['id'], 'name' => 'An app in testing', 'testing' => true];
        else $store[] = ['id' => $g['id'], 'name' => 'Reserved for an app on its way', 'testing' => true];
    }
    usort($store, fn($a, $b) => strcmp($a['id'], $b['id']));
    $taken = [];
    foreach (rs_taken_ids() as $id => $note) $taken[] = ['id' => $id, 'note' => $note];
    usort($taken, fn($a, $b) => strcmp($a['id'], $b['id']));
    echo json_encode(['ok' => true, 'store' => $store, 'taken' => $taken,
        'block' => ['from' => RS_MOVE_BLOCK[0], 'to' => RS_MOVE_BLOCK[1]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'The list can\'t be read right now.']);
}
