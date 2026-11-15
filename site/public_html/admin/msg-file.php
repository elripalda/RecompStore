<?php
// (4.12) A file sent with a message to or from a developer (inc/devmsg.php), for the team.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
if (!rs_can_page('messages.php')) { http_response_code(403); exit('Not for your login.'); }
$f = rs_msg_file_get((string)($_GET['f'] ?? ''));
if (!$f) { http_response_code(404); exit('That file is gone.'); }
session_write_close();
rs_msg_file_send($f);
