<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_session();
rs_keep_forget();
$_SESSION = [];
session_destroy();
header('Location: login.php');
