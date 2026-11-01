<?php
// (2.9) Maintenance mode: the PS5 side (recompstore.com) and the PC side (recomplauncher.com) can each
// be taken down from Settings. Visitors then get only the logo and the admin's message, with a 503 so
// search engines keep the pages for when it's back. The admin's preview link lets one browser through.
// Only the website's pages: the catalogs and the API the apps read keep working.
// Needs nothing else of the admin's (no database), so it costs the pages nothing when it's off.
declare(strict_types=1);

const RS_MAINT_COOKIE = 'rs_preview';

function rs_maint_path(): string {
    $pub = dirname(__DIR__, 2);
    $outside = dirname($pub) . '/recompstore-maint.json';
    if (is_file($outside)) return $outside;
    $inside = __DIR__ . '/maint.json';
    if (is_file($inside)) return $inside;
    return is_writable(dirname($pub)) ? $outside : $inside;
}

function rs_maint_state(): array {
    $s = json_decode((string)@file_get_contents(rs_maint_path()), true);
    $s = is_array($s) ? $s : [];
    foreach (['ps5', 'pc'] as $k) {
        $x = is_array($s[$k] ?? null) ? $s[$k] : [];
        $s[$k] = ['on' => !empty($x['on']), 'msg' => (string)($x['msg'] ?? ''), 'since' => (int)($x['since'] ?? 0)];
    }
    $s['token'] = is_string($s['token'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $s['token']) ? $s['token'] : '';
    return $s;
}

function rs_maint_save(array $s): void {
    if ($s['token'] === '') $s['token'] = bin2hex(random_bytes(16));
    $p = rs_maint_path();
    $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($p . '.tmp', $json . "\n") === false || !rename($p . '.tmp', $p)) {
        throw new RuntimeException('Could not save maintenance mode. Check that the folder above public_html is writable.');
    }
}

// Called first thing by the site's pages. $side is 'ps5' or 'pc'. Returns only when the page should show.
function rs_maint_gate(string $side): void {
    $s = rs_maint_state();
    $token = $s['token'];
    // The admin's preview link (?preview=<token>) lets this browser see the real site for 12 hours.
    $q = is_string($_GET['preview'] ?? null) ? $_GET['preview'] : '';
    if ($token !== '' && $q !== '') {
        if (hash_equals($token, $q)) {
            setcookie(RS_MAINT_COOKIE, $token, ['expires' => time() + 12 * 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
            $_COOKIE[RS_MAINT_COOKIE] = $token;
        }
        $u = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        header('Location: ' . $u, true, 302);
        header('Cache-Control: no-store');
        exit;
    }
    if (empty($s[$side]['on'])) return;
    $c = is_string($_COOKIE[RS_MAINT_COOKIE] ?? null) ? $_COOKIE[RS_MAINT_COOKIE] : '';
    if ($token !== '' && $c !== '' && hash_equals($token, $c)) {
        // Seen through the preview: the real page, with a ribbon saying it's down for everyone else.
        ob_start(function (string $html) use ($side) {
            $bar = '<div style="position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:2147483000;background:#f5b83d;color:#0b1220;'
                . 'font:700 14px/1.3 Figtree,system-ui,sans-serif;padding:9px 16px;border-radius:999px;box-shadow:0 8px 24px rgba(0,0,0,.45);white-space:nowrap">'
                . ($side === 'pc' ? 'RecompLauncher' : 'RecompStore') . ' is in maintenance mode. Only you can see this.</div>';
            return stripos($html, '</body>') !== false ? preg_replace('~</body>~i', $bar . '</body>', $html, 1) : $html;
        });
        header('Cache-Control: no-store');
        return;
    }
    rs_maint_page($side, $s[$side]['msg']);
    exit;
}

// The page visitors get: the logo and the message, nothing else.
function rs_maint_page(string $side, string $msg): void {
    $pc = $side === 'pc';
    $name = $pc ? 'RecompLauncher' : 'RecompStore';
    $logo = $pc ? '/assets/press/launcher/logo-horizontal-white.svg' : '/assets/press/logo-horizontal-white.svg';
    // Both logos at the same letter size: RecompLauncher's is the wider one.
    $w = $pc ? 412 : 340;
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // The message: plain text, its line breaks kept, and web addresses made into links.
    $body = '';
    foreach (preg_split('/\R{2,}/', trim($msg)) ?: [] as $para) {
        if (trim($para) === '') continue;
        $html = preg_replace_callback('~https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~i', fn($m) => '<a href="' . $m[0] . '" rel="noopener">' . $m[0] . '</a>', $e(trim($para)));
        $body .= '<p>' . nl2br((string)$html, false) . '</p>';
    }
    http_response_code(503);
    header('Retry-After: 3600');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $name . '</title>'
        . '<link rel="icon" href="' . ($pc ? '/assets/press/launcher/mark.svg' : '/assets/mark.svg') . '" type="image/svg+xml">'
        . '<style>@font-face{font-family:Figtree;font-weight:500;font-display:swap;src:url(/assets/fonts/figtree-500.woff2) format("woff2")}'
        . 'html{background:#05080d}body{margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;'
        . 'background:radial-gradient(120% 90% at 50% 115%,#0b2233 0%,#05080d 62%);color:#c7d0dc;font:500 18px/1.6 Figtree,system-ui,sans-serif;text-align:center}'
        . 'main{padding:40px 24px;max-width:560px}img{display:block;max-width:80vw;height:auto;margin:0 auto}'
        . '.msg{margin-top:34px}.msg p{margin:0 0 14px}a{color:#34f2e8;text-decoration:underline;text-underline-offset:3px;word-break:break-word}</style></head>'
        . '<body><main><img src="' . $logo . '" alt="' . $name . '" width="' . $w . '" height="49">'
        . ($body !== '' ? '<div class="msg">' . $body . '</div>' : '') . '</main></body></html>';
}
