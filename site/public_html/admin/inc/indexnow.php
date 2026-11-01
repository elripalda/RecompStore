<?php
// RecompStore admin (2.7.1): IndexNow. When a catalog goes out, the pages that changed (new apps,
// new versions, new names or blurbs) are sent to IndexNow, which Bing, Yandex, Naver, Seznam and
// Yep share, so they look at them within minutes instead of on their next crawl. Google doesn't
// take IndexNow; it reads sitemap.xml, where each app's page is dated by its newest version.
// The key is public on purpose: /<key>.txt proves the site sent it.
declare(strict_types=1);

const RS_INDEXNOW_KEY = 'fa377c80c32f5ed1ed9f2e23d06a85f6';

// The addresses to send: every app page that's new or changed since $before, and the pages that
// list them. [] when nothing changed.
function rs_indexnow_urls(array $before, array $after): array {
    $apps = fn(array $c) => array_merge((array)($c['apps'] ?? []), (array)($c['newerStoreApps'] ?? []), (array)($c['v2Apps'] ?? []));
    $was = [];
    foreach ($apps($before) as $a) if (is_array($a) && isset($a['id'])) $was[(string)$a['id']] = $a;
    $base = 'https://recompstore.com';
    $urls = [];
    $cats = [];
    foreach ($apps($after) as $a) {
        if (!is_array($a) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($a['id'] ?? ''))) continue;
        $id = (string)$a['id'];
        $old = $was[$id] ?? null;
        $changed = $old === null;
        foreach (['name', 'version', 'blurb', 'description', 'news', 'category', 'author', 'released'] as $k) $changed = $changed || ($old[$k] ?? null) !== ($a[$k] ?? null);
        if (!$changed) continue;
        $urls[] = $base . '/game/' . rawurlencode($id);
        $cats[(string)($a['category'] ?? 'games') ?: 'games'] = true;
    }
    if (!$urls) return [];
    foreach (array_keys($cats) as $c) if (in_array($c, ['games', 'emulators', 'apps', 'tools'], true)) $urls[] = $base . '/' . $c;
    $urls[] = $base . '/';
    return array_slice(array_values(array_unique($urls)), 0, 10000);
}

// Sends them; never stops a publish (a failure only goes in the change log).
function rs_indexnow_after_publish(array $before, array $after): void {
    try {
        $urls = rs_indexnow_urls($before, $after);
        if (!$urls || !function_exists('curl_init')) return;
        $body = json_encode(['host' => 'recompstore.com', 'key' => RS_INDEXNOW_KEY,
            'keyLocation' => 'https://recompstore.com/' . RS_INDEXNOW_KEY . '.txt', 'urlList' => $urls], JSON_UNESCAPED_SLASHES);
        $c = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'RecompStore-site', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        curl_exec($c);
        $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if (function_exists('rs_log')) rs_log('publish', 'indexnow', count($urls) . ' pages sent, HTTP ' . $status);
    } catch (Throwable $e) {
    }
}
