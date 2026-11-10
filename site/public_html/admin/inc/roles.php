<?php
// (4.3) The admin team. Every login has a role and the stores it works on:
//   owner    everything (the main admin). Only owners make logins and change settings.
//   manager  curation, plus the Inbox, logs, analytics, player accounts and Mail.
//   curator  the recomps and games, Found on GitHub, developer submissions and messages, Security.
// Stores: PC, PS5 or both. A store someone doesn't work on isn't in their menu, and its pages
// answer "not for you". Pages are checked here on the server, not just hidden in the menu.
declare(strict_types=1);

const RS_ROLES = ['owner' => 'Owner', 'manager' => 'Manager', 'curator' => 'Curator'];
const RS_ROLE_RANK = ['curator' => 1, 'manager' => 2, 'owner' => 3];
const RS_ROLE_ABOUT = [
    'curator' => 'Recomps and games, Found on GitHub, developer submissions and messages, Security, and publishing the catalog.',
    'manager' => 'Everything a curator does, plus the Inbox, logs, analytics, player accounts and Mail.',
    'owner' => 'Everything, including Settings, the launcher\'s builds and the team.',
];
const RS_STORES = ['pc' => 'PC', 'ps5' => 'PS5'];

// Each page: the lowest role that may open it, and the store it belongs to ('' = both).
// A page that isn't listed is for owners only, so a new page is never open by mistake.
const RS_PAGE_ACCESS = [
    'dashboard.php' => ['curator', ''], 'status.php' => ['curator', ''], 'account.php' => ['curator', ''],
    'art-api.php' => ['curator', ''], 'picture.php' => ['curator', ''], 'docs.php' => ['curator', ''],
    // PC
    'pc.php' => ['curator', 'pc'], 'boot.php' => ['curator', 'pc'], 'sdk-support.php' => ['curator', 'pc'], 'achievements.php' => ['curator', 'pc'], 'titles.php' => ['curator', 'pc'], 'pc-banner.php' => ['curator', 'pc'], 'ai.php' => ['curator', 'pc'],
    // PS5
    'index.php' => ['curator', 'ps5'], 'edit.php' => ['curator', 'ps5'], 'discover.php' => ['curator', 'ps5'], 'collections.php' => ['curator', 'ps5'],
    'beta.php' => ['curator', 'ps5'], 'beta-upload.php' => ['curator', 'ps5'], 'feedback.php' => ['curator', 'ps5'], 'import.php' => ['curator', 'ps5'],
    'health.php' => ['curator', 'ps5'], 'icons.php' => ['curator', 'ps5'], 'r2.php' => ['curator', 'ps5'],
    // Developers and Security
    'developers.php' => ['curator', ''], 'submissions.php' => ['curator', ''], 'dev-accounts.php' => ['curator', ''],
    'messages.php' => ['curator', ''], 'community.php' => ['curator', ''], 'security.php' => ['curator', ''],
    // Managers
    'inbox.php' => ['manager', ''], 'log.php' => ['manager', ''], 'log-file.php' => ['manager', ''], 'log-zip.php' => ['manager', ''],
    'analytics.php' => ['manager', ''], 'stats.php' => ['manager', ''], 'app-stats.php' => ['manager', ''], 'launcher-stats.php' => ['manager', 'pc'],
    'accounts.php' => ['manager', ''], 'mail.php' => ['manager', ''], 'email-asset.php' => ['manager', ''],
    // Owners: settings, the launcher's builds and updates, storage, the team
    'settings.php' => ['owner', ''], 'launcher.php' => ['owner', 'pc'], 'launcher-beta.php' => ['owner', 'pc'], 'launcher-upload.php' => ['owner', 'pc'],
    'diag-db.php' => ['owner', ''], 'team.php' => ['owner', ''],
];
// Buttons on shared pages that only owners may press: page => actions.
const RS_OWNER_ACTIONS = [
    'security.php' => ['key', 'forget', 'settings'],
    'status.php' => ['*'],
];

// The users table's team columns, added once.
function rs_roles_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (rs_setting('roles_schema', '') === '2') return;
    $db = rs_db();
    $cols = ['role' => "VARCHAR(12) NOT NULL DEFAULT 'owner'", 'stores' => "VARCHAR(12) NOT NULL DEFAULT 'pc,ps5'",
        'active' => 'TINYINT NOT NULL DEFAULT 1', 'must_change' => 'TINYINT NOT NULL DEFAULT 0', 'theme' => "VARCHAR(10) NOT NULL DEFAULT ''",
        'display_name' => "VARCHAR(80) NOT NULL DEFAULT ''", 'last_login' => 'DATETIME NULL', 'created_by' => 'INT NOT NULL DEFAULT 0',
        'sess_v' => 'INT NOT NULL DEFAULT 0', 'totp_secret' => "VARCHAR(64) NOT NULL DEFAULT ''", 'totp_codes' => 'TEXT NULL', 'totp_last' => 'BIGINT NOT NULL DEFAULT 0'];
    foreach ($cols as $col => $def) {
        if (!$db->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch()) $db->exec("ALTER TABLE users ADD COLUMN $col $def");
    }
    rs_set_setting('roles_schema', '2');
}

// The signed-in team member (cached for the request), or null.
function rs_me(): ?array {
    static $me = false;
    if ($me !== false && ($me === null || (int)$me['id'] === (int)($_SESSION['uid'] ?? 0))) return $me;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) return $me = null;
    rs_roles_schema();
    $st = rs_db()->prepare('SELECT id, username, display_name, role, stores, active, must_change, theme, sess_v, totp_secret, last_login, created FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) return $me = null;
    if (!isset(RS_ROLES[$u['role']])) $u['role'] = 'curator';
    $u['stores_list'] = array_values(array_intersect(array_keys(RS_STORES), explode(',', (string)$u['stores']))) ?: ['pc'];
    return $me = $u;
}
function rs_me_name(): string { $m = rs_me(); return $m ? ((string)$m['display_name'] !== '' ? (string)$m['display_name'] : (string)$m['username']) : ''; }
function rs_is_owner(): bool { return (rs_me()['role'] ?? '') === 'owner'; }
// Whether the signed-in person's role is at least $role.
function rs_role_at_least(string $role): bool {
    $m = rs_me();
    return $m && (RS_ROLE_RANK[$m['role']] ?? 0) >= (RS_ROLE_RANK[$role] ?? 99);
}
// Whether they work on a store ('' = either).
function rs_has_store(string $store): bool {
    if ($store === '') return true;
    $m = rs_me();
    return $m && in_array($store, $m['stores_list'], true);
}
// Whether they may open a page (by its file name, with or without ?query).
function rs_can_page(string $page): bool {
    $page = basename((string)strtok($page, '?'));
    [$role, $store] = RS_PAGE_ACCESS[$page] ?? ['owner', ''];
    return rs_role_at_least($role) && rs_has_store($store);
}

// Called by rs_require_login on every admin page: an active login, the right role and store, and
// a new password first when they were given a temporary one.
function rs_gate(bool $json = false): void {
    $m = rs_me();
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $out = function (int $code, string $why) use ($json): void {
        http_response_code($code);
        if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $why]); exit; }
        rs_gate_page($why);
    };
    if (!$m || !(int)$m['active'] || (int)($_SESSION['sess_v'] ?? 0) !== (int)$m['sess_v']) {
        rs_keep_forget();
        $_SESSION = [];
        if ($json) $out(401, 'Sign in to the admin again.');
        header('Location: login.php');
        exit;
    }
    if ((int)$m['must_change'] && !in_array($page, ['account.php', 'logout.php'], true)) {
        if ($json) $out(403, 'Set your own password first.');
        header('Location: account.php?first=1');
        exit;
    }
    if (!rs_can_page($page)) $out(403, 'That page isn\'t part of your role. Ask the owner if you need it.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset(RS_OWNER_ACTIONS[$page]) && !rs_is_owner()) {
        $acts = RS_OWNER_ACTIONS[$page];
        if (in_array('*', $acts, true) || in_array((string)($_POST['action'] ?? $_POST['act'] ?? ''), $acts, true)) $out(403, 'Only the owner can change that.');
    }
}
// For the few admin endpoints that answer JSON and check the session themselves.
function rs_gate_json(): void {
    rs_session();
    if (empty($_SESSION['uid'])) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Sign in to the admin again.']); exit; }
    rs_gate(true);
}
function rs_gate_page(string $why): void {
    if (!function_exists('rs_head')) require_once __DIR__ . '/layout.php';
    rs_head('Not for your role');
    echo '<section class="card narrow"><h1>Not part of your role</h1><p class="muted">' . h($why) . '</p><p><a class="btn primary" href="dashboard.php">Back to the Dashboard</a></p></section>';
    rs_foot();
    exit;
}

// After a good login: remembered for "sign out everywhere", and when they were last here.
function rs_login_done(int $uid): void {
    rs_roles_schema();
    $st = rs_db()->prepare('SELECT sess_v FROM users WHERE id = ?');
    $st->execute([$uid]);
    $_SESSION['sess_v'] = (int)$st->fetchColumn();
    rs_db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$uid]);
}

// ---- The team (Team page, owners only) ----------------------------------------------------------
function rs_team(): array {
    rs_roles_schema();
    return rs_db()->query('SELECT id, username, display_name, role, stores, active, must_change, totp_secret <> \'\' AS twostep, last_login, created FROM users ORDER BY FIELD(role, \'owner\', \'manager\', \'curator\'), username')->fetchAll();
}
function rs_team_clean_stores(array $in): string {
    $s = array_values(array_intersect(array_keys(RS_STORES), $in));
    if (!$s) throw new RuntimeException('Pick PC, PS5 or both.');
    return implode(',', $s);
}
function rs_team_temp_password(): string {
    $w = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 14; $i++) $p .= $w[random_int(0, strlen($w) - 1)];
    return $p;
}
// -> the temporary password, shown once.
function rs_team_add(string $username, string $name, string $role, array $stores): string {
    rs_roles_schema();
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) throw new RuntimeException('A username is 3 to 40 letters, numbers, dots, dashes or underscores.');
    if (!isset(RS_ROLES[$role])) throw new RuntimeException('Pick a role.');
    $st = rs_db()->prepare('SELECT 1 FROM users WHERE username = ?');
    $st->execute([$username]);
    if ($st->fetch()) throw new RuntimeException('Someone already has that username.');
    $pw = rs_team_temp_password();
    rs_db()->prepare('INSERT INTO users (username, pass_hash, created, role, stores, active, must_change, display_name, created_by) VALUES (?, ?, NOW(), ?, ?, 1, 1, ?, ?)')
        ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role, rs_team_clean_stores($stores), mb_substr(trim($name), 0, 80), (int)(rs_me()['id'] ?? 0)]);
    return $pw;
}
function rs_team_owners(): int { return (int)rs_db()->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND active = 1")->fetchColumn(); }
function rs_team_get(int $id): ?array {
    $st = rs_db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function rs_team_save(int $id, string $name, string $role, array $stores): void {
    $u = rs_team_get($id);
    if (!$u) throw new RuntimeException('That login is gone.');
    if (!isset(RS_ROLES[$role])) throw new RuntimeException('Pick a role.');
    if ($u['role'] === 'owner' && $role !== 'owner' && rs_team_owners() <= 1) throw new RuntimeException('There has to be at least one owner.');
    rs_db()->prepare('UPDATE users SET display_name = ?, role = ?, stores = ? WHERE id = ?')->execute([mb_substr(trim($name), 0, 80), $role, rs_team_clean_stores($stores), $id]);
}
// Signs a login out on every device: its sessions and its "keep me signed in" devices.
function rs_team_signout(int $id): void {
    rs_db()->prepare('UPDATE users SET sess_v = sess_v + 1 WHERE id = ?')->execute([$id]);
    try { rs_keep_table(); rs_db()->prepare('DELETE FROM admin_keep WHERE uid = ?')->execute([$id]); } catch (Throwable $e) { /* none kept */ }
}
function rs_team_active(int $id, bool $on): void {
    $u = rs_team_get($id);
    if (!$u) throw new RuntimeException('That login is gone.');
    if (!$on && (int)$u['id'] === (int)(rs_me()['id'] ?? 0)) throw new RuntimeException('You can\'t switch off your own login.');
    if (!$on && $u['role'] === 'owner' && rs_team_owners() <= 1) throw new RuntimeException('There has to be at least one owner.');
    rs_db()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    if (!$on) rs_team_signout($id);
}
// -> a new temporary password; they set their own at the next sign-in. Two-step stays as it was.
function rs_team_reset(int $id): string {
    if (!rs_team_get($id)) throw new RuntimeException('That login is gone.');
    $pw = rs_team_temp_password();
    rs_db()->prepare('UPDATE users SET pass_hash = ?, must_change = 1 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    rs_team_signout($id);
    return $pw;
}
function rs_team_2fa_off(int $id): void {
    rs_db()->prepare("UPDATE users SET totp_secret = '', totp_codes = NULL, totp_last = 0 WHERE id = ?")->execute([$id]);
    rs_team_signout($id);
}
function rs_team_remove(int $id): void {
    $u = rs_team_get($id);
    if (!$u) return;
    if ((int)$u['id'] === (int)(rs_me()['id'] ?? 0)) throw new RuntimeException('You can\'t remove your own login.');
    if ($u['role'] === 'owner' && rs_team_owners() <= 1) throw new RuntimeException('There has to be at least one owner.');
    rs_team_signout($id);
    rs_db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

// ---- Themes ------------------------------------------------------------------------------------
const RS_THEMES = ['' => 'Midnight', 'oled' => 'OLED black', 'light' => 'Light'];
function rs_theme(): string { $t = (string)(rs_me()['theme'] ?? ''); return isset(RS_THEMES[$t]) ? $t : ''; }
