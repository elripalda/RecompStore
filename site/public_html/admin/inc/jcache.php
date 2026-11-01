<?php
// (3.1g) A short-lived copy of a JSON answer that's the same for everyone who asks (the launch info,
// a=me for signed-out visitors). Busy pages then don't each open a database connection, and when the
// database is briefly out of reach, the last good answer (up to an hour old) goes out instead of an error.
declare(strict_types=1);

function rs_json_cache(string $name, int $fresh, int $stale = 3600): void {
    $f = sys_get_temp_dir() . '/rs-jc-' . preg_replace('/[^a-z0-9-]/', '', $name) . '-' . substr(md5(__DIR__), 0, 8) . '.json';
    $age = is_file($f) ? time() - (int)@filemtime($f) : PHP_INT_MAX;
    if ($age < $fresh && ($hit = @file_get_contents($f)) !== false && $hit !== '') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo $hit;
        exit;
    }
    ob_start(function (string $out) use ($f, $age, $stale): string {
        $j = json_decode($out, true);
        if (http_response_code() === 200 && is_array($j) && !empty($j['ok'])) {
            $t = $f . '.' . getmypid();
            if (@file_put_contents($t, $out) !== false) @rename($t, $f);
            return $out;
        }
        if ($age < $stale && ($old = @file_get_contents($f)) !== false && $old !== '') { http_response_code(200); return $old; }
        return $out;
    });
}
