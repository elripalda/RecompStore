<?php
// (4.12) Opens the developer dashboard (recomplauncher.com/dev) signed in as the team member's own
// developer account: the one whose GitHub is on their Team card (On the website > GitHub).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
$acc = rs_msg_my_dev();
if (!$acc) {
    rs_flash('No developer account is linked to your login yet. Put your GitHub username on your Team card (Team > Manage > On the website), the same one your developer account signs in with.', 'err');
    header('Location: ' . (rs_can_page('team.php') ? 'team.php' : 'index.php'));
    exit;
}
rs_log('team', 'dev dashboard', rs_me_name() . ' opened the developer dashboard as ' . rs_dev_label($acc));
header('Location: https://recomplauncher.com/api/dev.php?' . http_build_query(['a' => 'handoff', 't' => rs_dev_handoff_new((int)$acc['id'])]));
