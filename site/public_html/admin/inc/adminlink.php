<?php
// (4.8) An admin login merged with its owner's RecompLauncher account (the player account on the
// website and in the launcher). Merged, the admin login takes the account's username, and the admin
// can be signed into with that account: "Continue as <name>" when the browser is signed in with it,
// or its username or email and password on the admin's login page. The admin login's own password
// still works as a backup, and its two-step code is still asked for when it has one. The Team page
// uses the account's profile picture. Owners merge and unmerge from the Players page.
declare(strict_types=1);

function rs_al_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    rs_roles_schema();
    if (rs_setting('adminlink_schema', '') === '1') return;
    $db = rs_db();
    if (!$db->query("SHOW COLUMNS FROM users LIKE 'account_id'")->fetch()) {
        $db->exec('ALTER TABLE users ADD COLUMN account_id INT NULL');
        $db->exec('ALTER TABLE users ADD UNIQUE KEY account_id (account_id)');
    }
    rs_set_setting('adminlink_schema', '1');
}

// The admin login merged with player account $accId (only a login that's switched on, unless $any).
function rs_al_admin_for(int $accId, bool $any = false): ?array {
    if ($accId <= 0) return null;
    rs_al_schema();
    $st = rs_db()->prepare('SELECT id, username, display_name, role, active, pass_hash, totp_secret, account_id FROM users WHERE account_id = ?' . ($any ? '' : ' AND active = 1'));
    $st->execute([$accId]);
    return $st->fetch() ?: null;
}

// The player account an admin login is merged with, or null.
function rs_al_account_for(int $uid): ?array {
    rs_al_schema();
    $st = rs_db()->prepare('SELECT account_id FROM users WHERE id = ?');
    $st->execute([$uid]);
    $id = (int)$st->fetchColumn();
    return $id > 0 ? rs_acct_get($id) : null;
}

// Every admin login with the account it's merged with (for the Players page's picker).
function rs_al_admins(): array {
    rs_al_schema();
    return rs_db()->query("SELECT id, username, display_name, role, active, account_id FROM users ORDER BY FIELD(role, 'owner', 'manager', 'curator', 'gcurator', 'maker'), username")->fetchAll();
}

// Merges admin login $uid with player account $accId. -> the admin login's new username.
function rs_al_merge(int $uid, int $accId): string {
    rs_al_schema();
    $acc = rs_acct_get($accId);
    if (!$acc) throw new RuntimeException('That account is gone.');
    if (!empty($acc['banned'])) throw new RuntimeException('That account is closed. Reopen it first.');
    $name = trim((string)($acc['username'] ?? ''));
    if ($name === '') throw new RuntimeException('That account hasn\'t picked a username yet.');
    if (mb_strlen($name) > 64) throw new RuntimeException('That username is too long for an admin login.');
    $u = rs_team_get($uid);
    if (!$u) throw new RuntimeException('That admin login is gone.');
    if ((int)($u['account_id'] ?? 0) === $accId) return (string)$u['username'];
    if ((int)($u['account_id'] ?? 0) > 0) throw new RuntimeException((string)$u['username'] . ' is already merged with another account. Unmerge it first.');
    $other = rs_al_admin_for($accId, true);
    if ($other) throw new RuntimeException('This account is already merged with the admin login ' . $other['username'] . '.');
    $st = rs_db()->prepare('SELECT username FROM users WHERE username = ? AND id <> ?');
    $st->execute([$name, $uid]);
    if ($st->fetchColumn() !== false) throw new RuntimeException('Another admin login is already called ' . $name . '.');
    rs_db()->prepare('UPDATE users SET account_id = ?, username = ? WHERE id = ?')->execute([$accId, $name, $uid]);
    return $name;
}

// Unmerges admin login $uid: it keeps its current username and its own password.
function rs_al_unmerge(int $uid): void {
    rs_al_schema();
    rs_db()->prepare('UPDATE users SET account_id = NULL WHERE id = ?')->execute([$uid]);
}
