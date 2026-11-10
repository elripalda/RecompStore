<?php
// (4.1) A big JSON file (the PC catalog, a few MB) read as a PHP array that opcache keeps in shared
// memory: the first request after the file changes writes it out as PHP once, and every request after
// that includes it at almost no cost, instead of decoding the JSON again (about 0.1 s of CPU each time).
declare(strict_types=1);

function rs_json_cached(string $file): ?array {
    clearstatcache(true, $file);
    $mt = @filemtime($file); $sz = @filesize($file);
    if ($mt === false || $sz === false) return null;
    static $mem = [];
    $key = $file . '|' . $mt . '|' . $sz;
    if (isset($mem[$key])) return $mem[$key];
    // (4.6) A big file is just decoded. As PHP, the PC catalog came to 6.7 MB of code: compiling it into
    // opcache took long enough on a busy server that the worker doing it held opcache's lock while every
    // other PHP worker queued behind it (requests timed out, the admin waited 15 seconds and more).
    if ($sz > 1000000) {
        $out = json_decode((string)@file_get_contents($file), true);
        return $mem[$key] = is_array($out) ? $out : null;
    }
    $dir = dirname(__DIR__, 3) . '/recompstore-private/json-cache';
    $name = preg_replace('/[^a-z0-9-]+/i', '-', basename($file)) . '-' . $mt . '-' . $sz . '.php';
    $php = "$dir/$name";
    if (!is_file($php)) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // (4.4) One request builds the new copy; the others don't wait in line for it (that held every
        // PHP worker for seconds after each publish): they use the copy before it while it's written.
        $lock = @fopen("$dir/.lock", 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            if (!is_file($php)) rs_json_cache_build($file, $dir, $php);
            flock($lock, LOCK_UN);
        } elseif (!is_file($php)) {
            $stem = preg_replace('/[^a-z0-9-]+/i', '-', basename($file)) . '-';
            $older = glob("$dir/$stem*.php") ?: [];
            usort($older, fn($x, $y) => (int)@filemtime($y) <=> (int)@filemtime($x));
            foreach ($older as $o) {
                $prev = @include $o;
                if (is_array($prev)) { if ($lock) fclose($lock); return $prev; } // not kept in $mem: the next call looks for the new one
            }
        }
        if ($lock) fclose($lock);
    }
    $out = is_file($php) ? @include $php : null;
    if (!is_array($out)) $out = json_decode((string)@file_get_contents($file), true);
    return $mem[$key] = is_array($out) ? $out : null;
}

// Writes $file's JSON out as PHP at $php (under the caller's lock), and drops older copies of it.
function rs_json_cache_build(string $file, string $dir, string $php): void {
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return;
    $tmp = "$php." . getmypid() . '.tmp';
    if (@file_put_contents($tmp, '<?php return ' . var_export($data, true) . ";\n") === false || !@rename($tmp, $php)) { @unlink($tmp); return; }
    if (function_exists('opcache_compile_file')) @opcache_compile_file($php);
    $stem = preg_replace('/[^a-z0-9-]+/i', '-', basename($file)) . '-';
    foreach (glob("$dir/$stem*.php") ?: [] as $old) if ($old !== $php) @unlink($old);
}
