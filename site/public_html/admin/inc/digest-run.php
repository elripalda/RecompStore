<?php
// (4.8) The weekly digest's run: php digest-run.php [now]. Cron starts it on Mondays at 15:07 UTC
// (8:07 am Phoenix) as www-data (/etc/cron.d/recompstore-repowatch); "now" sends it even when it went
// out this week.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/digest.php';
try {
    $to = rs_digest_send(($argv[1] ?? '') === 'now');
    echo gmdate('c'), $to !== '' ? ' digest sent to ' . $to : ' digest not due (or turned off)', "\n";
} catch (Throwable $e) {
    echo gmdate('c'), ' digest failed: ', $e->getMessage(), "\n";
}
