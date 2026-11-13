<?php
// RecompStore admin (4.8): the weekly digest. What Monday's email says (the week so far), where it
// goes, on or off, and Send it now.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/digest.php';
rs_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save') {
            $to = trim((string)($_POST['to'] ?? ''));
            if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That isn\'t an email address.');
            rs_set_setting('digest_to', $to);
            rs_set_setting('digest_on', empty($_POST['on']) ? '0' : '1');
            rs_log('digest', 'settings', ($to !== '' ? $to : 'the store\'s mailbox') . (empty($_POST['on']) ? ', off' : ', on'));
            rs_flash(empty($_POST['on']) ? 'Saved. The digest is off.' : 'Saved. It goes out Mondays at 8 am.');
        } elseif ($act === 'send') {
            rs_flash('Sent to ' . rs_digest_send(true) . '.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: digest.php');
    exit;
}

$d = rs_digest_build();
$last = rs_kv_get('digest_sent');
$ready = function_exists('rs_mail_ready') && rs_mail_ready();
rs_head('Weekly digest', true);
?>
<div class="dg-head">
  <div><h1>Weekly digest</h1><p class="muted">Every Monday at 8 am, the week in one email<?= $last ? '. Last one went out ' . h(rs_date('M j, g:i a', (int)$last['v'])) : '' ?>.</p></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button name="action" value="send" class="primary"<?= $ready ? '' : ' disabled title="The mailbox isn\'t set up"' ?>>Send it now</button></form>
</div>
<div class="dg-grid">
  <section class="card dg-mail">
    <p class="muted small">Subject</p>
    <h2><?= h($d['subject']) ?></h2>
    <?php foreach ($d['parts'] as [$heading, $lines]): ?>
    <div class="dg-part"><h3><?= h($heading) ?></h3><ul><?php foreach ($lines as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul></div>
    <?php endforeach; ?>
  </section>
  <form method="post" class="card dg-set">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <h3>Where it goes</h3>
    <label>Send to<input type="email" name="to" value="<?= h((string)rs_setting('digest_to', '')) ?>" placeholder="<?= h(function_exists('rs_mail_get') ? rs_mail_get('mail_from') : '') ?>"></label>
    <p class="muted small">Empty: the store's mailbox.</p>
    <label class="check"><input type="checkbox" name="on" value="1"<?= rs_digest_on() ? ' checked' : '' ?>> Send it every Monday</label>
    <button name="action" value="save">Save</button>
  </form>
</div>
<style>
.dg-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:4px 0 16px}.dg-head h1{margin:0 0 4px}.dg-head p{margin:0}
.dg-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
.dg-mail{margin:0}.dg-mail h2{font-size:18px;margin:2px 0 6px;overflow-wrap:anywhere}.dg-mail>p{margin:0}
.dg-part{padding:12px 0;border-top:1px solid var(--line)}.dg-part:first-of-type{margin-top:10px}
.dg-part h3{font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
.dg-part ul{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:4px;color:var(--soft)}.dg-part li{overflow-wrap:anywhere}
.dg-set{margin:0;display:flex;flex-direction:column;gap:10px}.dg-set h3{margin:0;font-size:14px}.dg-set label{display:flex;flex-direction:column;gap:6px}
.dg-set label.check{flex-direction:row;align-items:center;gap:8px}.dg-set p{margin:-4px 0 0}.dg-set button{align-self:flex-start}
@media (max-width:900px){.dg-grid{grid-template-columns:minmax(0,1fr)}.dg-head{flex-direction:column;align-items:stretch}}
</style>
<?php rs_foot(); ?>
