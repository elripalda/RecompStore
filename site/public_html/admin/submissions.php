<?php
// (3.7) Submissions. (4.8) They're on the Submissions hub now (hub.php); this address sends you there.
// ?legacy=1 still opens the old list (owners and store curators), just in case.
declare(strict_types=1);
if (empty($_GET['legacy']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: hub.php' . (($_GET['show'] ?? '') === 'done' ? '?tab=handled' : ''));
    exit;
}
define('RS_SUBS_PAGE', true);
require __DIR__ . '/inbox.php';
