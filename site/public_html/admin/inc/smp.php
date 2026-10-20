<?php
// Recomp Store website (2.2): the ShadowMountPlus the store offers. The admin picks one of
// drakmor/ShadowMountPlus's releases in Settings; the site downloads it once to work out the
// .elf's SHA-256 and size, and the catalog (signed like everything else) carries it as "smp".
// The store downloads it from GitHub itself and checks it against these.
declare(strict_types=1);

const RS_SMP_REPO = 'drakmor/ShadowMountPlus';
// Older ones pause kstuff while the store is open, and sending one again over a running one takes
// the open store's storage away.
const RS_SMP_MIN_DEFAULT = '1.7beta5';

// A release tag or a version as the admin types it: letters, numbers, dots, dashes, underscores.
function rs_smp_version_ok(string $v): bool {
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $v);
}

// Only ever drakmor's own release downloads.
function rs_smp_url_ok(string $url): bool {
    return (bool)preg_match('#^https://github\.com/drakmor/ShadowMountPlus/releases/download/[A-Za-z0-9][A-Za-z0-9._-]{0,63}/[A-Za-z0-9][A-Za-z0-9._-]{0,127}$#', $url);
}

function rs_smp_min(): string {
    $m = trim((string)rs_setting('smp_min', RS_SMP_MIN_DEFAULT));
    return rs_smp_version_ok($m) ? $m : RS_SMP_MIN_DEFAULT;
}

// The version picked in Settings, checked again as it's read; null when none is.
function rs_smp_pin(): ?array {
    $p = json_decode((string)rs_setting('smp_pin', ''), true);
    if (!is_array($p)) return null;
    $version = (string)($p['version'] ?? '');
    $url = (string)($p['url'] ?? '');
    $asset = (string)($p['asset'] ?? '');
    $elf = (string)($p['elf'] ?? '');
    $sha = strtolower((string)($p['sha256'] ?? ''));
    $size = (int)($p['size'] ?? 0);
    if (!rs_smp_version_ok($version) || !rs_smp_url_ok($url) || !preg_match('/^[0-9a-f]{64}$/', $sha) || $size < 64 || $size > (64 << 20)) return null;
    if ($asset === '' || !str_ends_with($url, '/' . $asset) || ($elf !== '' && !preg_match('#^[A-Za-z0-9._/ -]{1,200}$#', $elf)) || str_contains($elf, '..')) return null;
    return ['version' => $version, 'url' => $url, 'asset' => $asset, 'elf' => $elf, 'sha256' => $sha, 'size' => $size,
            'published' => (string)($p['published'] ?? ''), 'at' => (int)($p['at'] ?? 0)];
}

// The catalog's "smp": always the minimum; the rest once a version is picked.
function rs_smp_catalog(): array {
    $p = rs_smp_pin();
    $out = [];
    if ($p) $out = ['version' => $p['version'], 'url' => $p['url'], 'asset' => $p['asset'], 'elf' => $p['elf'], 'sha256' => $p['sha256'], 'size' => $p['size']];
    $out['min'] = rs_smp_min();
    return $out;
}

// drakmor's releases (newest first, drafts left out), from the site's GitHub cache: 15 minutes,
// or asked again now. -> [ok, releases, message]
function rs_smp_releases(bool $fresh = false): array {
    $r = rs_gh_releases(RS_SMP_REPO, $fresh ? 0 : 900);
    if ($r['status'] !== 200) {
        $j = json_decode($r['body'], true);
        return [false, [], is_array($j) && isset($j['message']) ? (string)$j['message'] : 'GitHub didn\'t answer. Try again in a moment.'];
    }
    $list = json_decode($r['body'], true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || !rs_smp_version_ok((string)($rel['tag_name'] ?? ''))) continue;
        $out[] = $rel;
    }
    return [true, $out, $r['stale'] ? 'GitHub didn\'t answer, so this is the list from ' . rs_ago((int)$r['age']) . '.' : ''];
}

function rs_ago(int $s): string {
    if ($s < 120) return 'a minute ago';
    if ($s < 7200) return intdiv($s, 60) . ' minutes ago';
    if ($s < 172800) return intdiv($s, 3600) . ' hours ago';
    return intdiv($s, 86400) . ' days ago';
}

// Which of a release's files carries the payload: a .elf named for it, else its zip (the .elf
// inside, found when it's downloaded). null when it has neither.
function rs_smp_asset(array $rel): ?array {
    $zip = null;
    foreach ((array)($rel['assets'] ?? []) as $a) {
        $n = (string)($a['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $n)) continue;
        if (preg_match('/^shadowmount.*\.elf$/i', $n)) return $a + ['kind' => 'elf'];
        if (!$zip && preg_match('/^shadowmount.*\.zip$/i', $n)) $zip = $a + ['kind' => 'zip'];
    }
    return $zip;
}

// The payload inside a release zip: the entry named shadowmountplus.elf (or the only .elf), the one
// nearest the top. -> [path, bytes] or null.
function rs_smp_from_zip(string $zipBytes, string &$why): ?array {
    if (!class_exists('ZipArchive')) { $why = 'This server\'s PHP can\'t open zip files.'; return null; }
    $tmp = tempnam(sys_get_temp_dir(), 'smp');
    if ($tmp === false || file_put_contents($tmp, $zipBytes) === false) { $why = 'The download couldn\'t be saved for a moment to look inside it.'; return null; }
    try {
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) { $why = 'The release\'s zip couldn\'t be opened.'; return null; }
        $named = null; $elves = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string)$z->getNameIndex($i);
            if (str_ends_with($n, '/') || str_contains($n, '..') || str_starts_with($n, '/')) continue;
            if (strtolower(basename($n)) === 'shadowmountplus.elf' && ($named === null || substr_count($n, '/') < substr_count($named, '/'))) $named = $n;
            if (str_ends_with(strtolower($n), '.elf')) $elves[] = $n;
        }
        $pick = $named ?? (count($elves) === 1 ? $elves[0] : null);
        if ($pick === null) { $z->close(); $why = 'The release\'s zip has no shadowmountplus.elf.'; return null; }
        $st = $z->statName($pick);
        if (!$st || (int)$st['size'] > (64 << 20)) { $z->close(); $why = 'The .elf in the zip is too big to be ShadowMountPlus.'; return null; }
        $bytes = $z->getFromName($pick);
        $z->close();
        if (!is_string($bytes)) { $why = 'The .elf couldn\'t be read from the zip.'; return null; }
        return [$pick, $bytes];
    } finally {
        @unlink($tmp);
    }
}

// Downloads one release's payload and works out what the store checks. -> the pin, or null with $why.
function rs_smp_fetch(string $tag, string &$why): ?array {
    if (!rs_smp_version_ok($tag)) { $why = 'That isn\'t a release of ShadowMountPlus.'; return null; }
    [$ok, $list, $msg] = rs_smp_releases(false);
    $rel = null;
    foreach ($list as $r) if ((string)$r['tag_name'] === $tag) $rel = $r;
    if (!$rel) {
        [$ok, $list, $msg] = rs_smp_releases(true);
        foreach ($list as $r) if ((string)$r['tag_name'] === $tag) $rel = $r;
    }
    if (!$rel) { $why = $ok ? 'GitHub doesn\'t list ' . $tag . ' anymore.' : $msg; return null; }
    $a = rs_smp_asset($rel);
    if (!$a) { $why = $tag . ' has no ShadowMountPlus .elf or zip to download.'; return null; }
    $from = (string)($a['browser_download_url'] ?? '');
    if (!rs_gh_allowed($from)) { $why = 'GitHub listed a download that isn\'t on GitHub.'; return null; }
    $r = rs_http($from, ['max' => 64 << 20, 'timeout' => 90, 'github_only' => true]);
    if ($r['status'] !== 200 || $r['body'] === '') { $why = 'The download from GitHub didn\'t work' . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : ($r['status'] ? ' (HTTP ' . $r['status'] . ')' : '')) . '. Try again in a moment.'; return null; }
    if ((int)($a['size'] ?? 0) > 0 && strlen($r['body']) !== (int)$a['size']) { $why = 'The download was ' . strlen($r['body']) . ' bytes, not the ' . (int)$a['size'] . ' GitHub lists. Try again.'; return null; }
    if (isset($a['digest']) && preg_match('/^sha256:([0-9a-f]{64})$/', (string)$a['digest'], $m) && !hash_equals($m[1], hash('sha256', $r['body']))) {
        $why = 'The download doesn\'t match the checksum GitHub lists for it. Try again.';
        return null;
    }
    $elf = '';
    $bytes = $r['body'];
    if ($a['kind'] === 'zip') {
        $got = rs_smp_from_zip($r['body'], $why);
        if (!$got) return null;
        [$elf, $bytes] = $got;
    }
    if (strlen($bytes) < 64 || substr($bytes, 0, 4) !== "\x7FELF") { $why = 'What came isn\'t a payload (.elf).'; return null; }
    $asset = (string)$a['name'];
    return [
        'version' => $tag,
        'url' => 'https://github.com/' . RS_SMP_REPO . '/releases/download/' . $tag . '/' . $asset,
        'asset' => $asset, 'elf' => $elf, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
        'published' => (string)($rel['published_at'] ?? ''), 'at' => time(),
    ];
}
