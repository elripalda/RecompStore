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
    $dir = dirname(__DIR__, 3) . '/recompstore-private/json-cache';
    $name = preg_replace('/[^a-z0-9-]+/i', '-', basename($file)) . '-' . $mt . '-' . $sz . '.php';
    $php = "$dir/$name";
    if (!is_file($php)) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $lock = @fopen("$dir/.lock", 'c');
        if ($lock && flock($lock, LOCK_EX)) {
            if (!is_file($php)) {
                $data = json_decode((string)@file_get_contents($file), true);
                if (is_array($data)) {
                    $tmp = "$php." . getmypid() . '.tmp';
                    if (@file_put_contents($tmp, '<?php return ' . var_export($data, true) . ";\n") !== false) @rename($tmp, $php);
                    // Older copies of the same file go.
                    $stem = preg_replace('/[^a-z0-9-]+/i', '-', basename($file)) . '-';
                    foreach (glob("$dir/$stem*.php") ?: [] as $old) if ($old !== $php) @unlink($old);
                }
            }
            flock($lock, LOCK_UN);
        }
        if ($lock) fclose($lock);
    }
    $out = is_file($php) ? @include $php : null;
    if (!is_array($out)) $out = json_decode((string)@file_get_contents($file), true);
    return $mem[$key] = is_array($out) ? $out : null;
}
