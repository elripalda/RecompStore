<?php
// (4.13) Launcher support > Replies, Check now: reads every open pull request and issue we sent (and ones
// closed in the last two weeks). Started by rs_prq_reply_launch; never from the web.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/pc.php';
require_once __DIR__ . '/sdk.php';
require_once __DIR__ . '/prq.php';
@set_time_limit(600);
try { rs_prq_reply_scan(480, true); } catch (Throwable $e) { error_log('prq replies: ' . $e->getMessage()); }
