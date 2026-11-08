<?php
// RecompStore (2.7.2): developer accounts. A developer signs in with GitHub (public profile only:
// no scopes, so no access to their repositories), asks for a developer hub (or follows an invite
// the admin emailed them), and the admin approves them. Approved, they get a Verified developer
// page they manage themselves, numbers and player logs for their apps, a way to suggest changes to
// their app pages (the admin approves those too), and betas for their own apps.
// Their apps are the ones whose GitHub repo belongs to them or to one of their public orgs.
declare(strict_types=1);

const RS_DEV_COOKIE = 'rs_dev';
const RS_DEV_DAYS = 30;
// The links a developer can put on their page (on top of RS_DEV_LINKS), and the ones that are for
// supporting them (the first one set becomes the Support button on their apps' pages).
const RS_DEV_SUPPORT = ['kofi', 'patreon', 'paypal', 'support'];

function rs_dev_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS dev_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gh_id BIGINT NOT NULL,
        gh_login VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL DEFAULT \'\',
        email VARCHAR(190) NOT NULL DEFAULT \'\',
        avatar VARCHAR(255) NOT NULL DEFAULT \'\',
        status VARCHAR(10) NOT NULL DEFAULT \'new\',
        slug VARCHAR(40) NOT NULL DEFAULT \'\',
        note TEXT NULL,
        invite_id INT NULL,
        discord_id VARCHAR(24) NOT NULL DEFAULT \'\',
        discord_name VARCHAR(64) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        requested DATETIME NULL,
        decided DATETIME NULL,
        last_seen DATETIME NOT NULL,
        UNIQUE KEY (gh_id), INDEX (status)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS dev_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code CHAR(24) NOT NULL,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(120) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        used_by INT NULL,
        used_at DATETIME NULL,
        UNIQUE KEY (code), INDEX (email)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS dev_sessions (
        token_hash CHAR(64) PRIMARY KEY,
        account_id INT NOT NULL,
        created DATETIME NOT NULL,
        expires DATETIME NOT NULL,
        INDEX (account_id)
    ) CHARACTER SET utf8mb4');
    // (2.7.3) Accounts made with an email and a password (no GitHub): gh_id empty; the password only
    // as a hash; the email confirmed before they can sign in; a reset link when they forget it.
    if (!$db->query("SHOW COLUMNS FROM dev_accounts LIKE 'pass_hash'")->fetch()) {
        $db->exec("ALTER TABLE dev_accounts MODIFY gh_id BIGINT NULL, MODIFY gh_login VARCHAR(64) NOT NULL DEFAULT '',
            ADD COLUMN pass_hash VARCHAR(255) NULL, ADD COLUMN email_ok TINYINT NOT NULL DEFAULT 0, ADD COLUMN mail_token CHAR(64) NULL,
            ADD COLUMN mail_token_until DATETIME NULL, ADD INDEX (email)");
    }
    // (2.7.3) Invites can come with their developer page set up (slug), and, when you tick it,
    // approve them the moment they accept (approve = 1). Otherwise you approve them as before.
    if (!$db->query("SHOW COLUMNS FROM dev_invites LIKE 'approve'")->fetch()) {
        $db->exec("ALTER TABLE dev_invites ADD COLUMN slug VARCHAR(40) NOT NULL DEFAULT '', ADD COLUMN approve TINYINT NOT NULL DEFAULT 0");
    }
    // Set up now, sent later: held invites aren't emailed until you press Send.
    if (!$db->query("SHOW COLUMNS FROM dev_invites LIKE 'held'")->fetch()) $db->exec('ALTER TABLE dev_invites ADD COLUMN held TINYINT NOT NULL DEFAULT 0');
    // (2.7.3) The weekly email: on unless they turn it off in their hub.
    if (!$db->query("SHOW COLUMNS FROM dev_accounts LIKE 'weekly'")->fetch()) $db->exec('ALTER TABLE dev_accounts ADD COLUMN weekly TINYINT NOT NULL DEFAULT 1');
    // Changes a developer suggests for one of their app pages, waiting for the admin.
    $db->exec('CREATE TABLE IF NOT EXISTS dev_changes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        app_id VARCHAR(64) NOT NULL,
        data MEDIUMTEXT NOT NULL,
        message TEXT NULL,
        status VARCHAR(10) NOT NULL DEFAULT \'pending\',
        created DATETIME NOT NULL,
        decided DATETIME NULL,
        INDEX (status), INDEX (account_id)
    ) CHARACTER SET utf8mb4');
    // (3.0) What they make: "pc", "ps5" or both ("pc,ps5"); empty until they say.
    if (!$db->query("SHOW COLUMNS FROM dev_accounts LIKE 'platforms'")->fetch()) {
        $db->exec("ALTER TABLE dev_accounts ADD COLUMN platforms VARCHAR(12) NOT NULL DEFAULT '', ADD COLUMN lounge_seen INT NOT NULL DEFAULT 0");
    }
    // (3.0) Recomps and apps a developer says are theirs: theirs by GitHub goes through at once; the
    // rest wait for the admin.
    $db->exec('CREATE TABLE IF NOT EXISTS dev_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        app_id VARCHAR(80) NOT NULL,
        note TEXT NULL,
        status VARCHAR(10) NOT NULL DEFAULT \'pending\',
        created DATETIME NOT NULL,
        decided DATETIME NULL,
        INDEX (account_id), INDEX (status)
    ) CHARACTER SET utf8mb4');
    // (3.0) Messages: "dm" is one developer and the RecompStore team (account_id = the developer);
    // "lounge" is the room every approved developer shares (account_id = who wrote it, 0 = the team).
    $db->exec('CREATE TABLE IF NOT EXISTS dev_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel VARCHAR(8) NOT NULL,
        account_id INT NOT NULL,
        from_admin TINYINT NOT NULL DEFAULT 0,
        body TEXT NOT NULL,
        created DATETIME NOT NULL,
        read_dev TINYINT NOT NULL DEFAULT 0,
        read_admin TINYINT NOT NULL DEFAULT 0,
        deleted TINYINT NOT NULL DEFAULT 0,
        INDEX (channel, account_id), INDEX (created)
    ) CHARACTER SET utf8mb4');
    // (3.1) Lounge channels, and where each developer has read up to in each.
    if (!$db->query("SHOW COLUMNS FROM dev_messages LIKE 'room'")->fetch()) {
        $db->exec("ALTER TABLE dev_messages ADD COLUMN room VARCHAR(16) NOT NULL DEFAULT 'general'");
    }
    if (!$db->query("SHOW COLUMNS FROM dev_accounts LIKE 'lounge_rooms'")->fetch()) {
        $db->exec("ALTER TABLE dev_accounts ADD COLUMN lounge_rooms VARCHAR(255) NOT NULL DEFAULT ''");
    }
    // (3.1) Developer docs: Markdown pages for approved developers, for PC, PS5 or everyone.
    $db->exec('CREATE TABLE IF NOT EXISTS dev_docs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(80) NOT NULL UNIQUE,
        title VARCHAR(160) NOT NULL,
        summary VARCHAR(300) NOT NULL DEFAULT \'\',
        audience VARCHAR(8) NOT NULL DEFAULT \'all\',
        body MEDIUMTEXT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        published TINYINT NOT NULL DEFAULT 0,
        updated DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    // (3.1) Messages you sent to many developers at once.
    $db->exec('CREATE TABLE IF NOT EXISTS dev_broadcasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        audience VARCHAR(16) NOT NULL,
        body TEXT NOT NULL,
        sent_to INT NOT NULL DEFAULT 0,
        emailed INT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
    rs_dev_docs_seed();
}

// ---- GitHub sign-in --------------------------------------------------------------------------

function rs_gh_web(): string {
    $e = getenv('RS_GH_WEB');
    return rtrim(is_string($e) && $e !== '' ? $e : 'https://github.com', '/');
}

function rs_gh_oauth(): array {
    return ['client' => trim((string)rs_setting('gh_client_id', '')), 'secret' => rs_secret_get('gh_client_secret')];
}

function rs_dev_ready(): bool {
    $c = rs_gh_oauth();
    return $c['client'] !== '' && $c['secret'] !== '';
}

function rs_dev_redirect(): string {
    return rs_abs('/api/dev.php');
}

// -> [status, decoded JSON]
function rs_dev_call(string $method, string $url, array $o = []): array {
    $c = curl_init($url);
    $headers = ['User-Agent: RecompStore-site', 'Accept: application/json'];
    if (!empty($o['bearer'])) $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15]);
    if (isset($o['form'])) curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($o['form']));
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) curl_setopt($c, CURLOPT_CAINFO, $ca);
    $raw = curl_exec($c);
    $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return [$status, json_decode(is_string($raw) ? $raw : '', true)];
}

// No scope: GitHub only hands over the public profile.
function rs_dev_login_url(string $state): string {
    return rs_gh_web() . '/login/oauth/authorize?' . http_build_query([
        'client_id' => rs_gh_oauth()['client'], 'redirect_uri' => rs_dev_redirect(), 'state' => $state, 'scope' => '', 'allow_signup' => 'false',
    ]);
}

// Back from GitHub with ?code=: who they are. -> ['id', 'login', 'name', 'avatar', 'email']
function rs_dev_finish(string $code): array {
    $c = rs_gh_oauth();
    [$st, $tok] = rs_dev_call('POST', rs_gh_web() . '/login/oauth/access_token', ['form' => [
        'client_id' => $c['client'], 'client_secret' => $c['secret'], 'code' => $code, 'redirect_uri' => rs_dev_redirect(),
    ]]);
    $access = is_array($tok) ? (string)($tok['access_token'] ?? '') : '';
    if ($st !== 200 || $access === '') throw new RuntimeException('GitHub didn\'t sign you in. Try again.');
    [$st, $u] = rs_dev_call('GET', rs_gh_api() . '/user', ['bearer' => $access]);
    if ($st !== 200 || !is_array($u) || empty($u['id']) || empty($u['login'])) throw new RuntimeException('GitHub didn\'t say who you are. Try again.');
    return ['id' => (int)$u['id'], 'login' => (string)$u['login'], 'name' => (string)($u['name'] ?? '') ?: (string)$u['login'],
        'avatar' => (string)($u['avatar_url'] ?? ''), 'email' => filter_var((string)($u['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$u['email'] : ''];
}

// The account for a GitHub user, made or brought up to date. An invite they followed is used up.
function rs_dev_upsert(array $u, string $invite = ''): array {
    rs_dev_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM dev_accounts WHERE gh_id = ?');
    $st->execute([$u['id']]);
    $acc = $st->fetch();
    if ($acc) {
        $db->prepare('UPDATE dev_accounts SET gh_login = ?, avatar = ?, last_seen = NOW() WHERE id = ?')->execute([$u['login'], $u['avatar'], $acc['id']]);
    } else {
        $db->prepare('INSERT INTO dev_accounts (gh_id, gh_login, name, email, avatar, status, created, last_seen) VALUES (?, ?, ?, ?, ?, \'new\', NOW(), NOW())')
           ->execute([$u['id'], $u['login'], mb_substr($u['name'], 0, 120), $u['email'], $u['avatar']]);
        $st->execute([$u['id']]);
        $acc = $st->fetch();
    }
    return $invite !== '' ? rs_dev_use_invite($acc, $invite) : $acc;
}

// Following an invite: it's used up, and their request goes straight to the admin with the email
// the invite went to. (An email account does this once its email is confirmed.)
function rs_dev_use_invite(array $acc, string $invite): array {
    if ($acc['invite_id'] !== null) return $acc;
    $inv = rs_dev_invite($invite);
    if (!$inv || $inv['used_by'] !== null) return $acc;
    $db = rs_db();
    $db->prepare('UPDATE dev_invites SET used_by = ?, used_at = NOW() WHERE id = ? AND used_by IS NULL')->execute([$acc['id'], $inv['id']]);
    $db->prepare("UPDATE dev_accounts SET invite_id = ?, email = IF(email = '', ?, email), status = IF(status = 'new', 'pending', status), requested = IF(requested IS NULL, NOW(), requested) WHERE id = ?")
       ->execute([$inv['id'], $inv['email'], $acc['id']]);
    // (2.7.3) The page you set up for them is theirs: they see their apps while you look it over.
    if ((string)($inv['slug'] ?? '') !== '' && isset(rs_developers()[$inv['slug']]))
        $db->prepare("UPDATE dev_accounts SET slug = ? WHERE id = ? AND slug = ''")->execute([$inv['slug'], $acc['id']]);
    $acc = rs_dev_account((int)$acc['id']);
    if (!empty($inv['approve'])) {
        // (2.7.3) Your invite was the approval: their page is set up and their hub opens now.
        try {
            $acc = rs_dev_approve((int)$acc['id'], (string)($inv['slug'] ?? ''));
            rs_log('developers', 'approved', rs_dev_label($acc) . ' -> ' . $acc['slug'] . ' (invite)');
            rs_dev_notify_admin($acc, 'accepted your invite. Their hub is open and their page is live at /dev/' . $acc['slug']);
            return $acc;
        } catch (Throwable $e) { /* falls back to waiting for you */ }
    }
    rs_dev_notify_admin($acc, 'accepted their invite' . ((string)($inv['slug'] ?? '') !== '' ? ' (their page: /dev/' . $inv['slug'] . ')' : '') . '. Look it over and approve it');
    return $acc;
}

function rs_dev_account(int $id): ?array {
    rs_dev_tables();
    $st = rs_db()->prepare('SELECT * FROM dev_accounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// How an account is named in the admin's log and in code notes: @login, or their email.
function rs_dev_label(array $acc): string {
    return (string)$acc['gh_login'] !== '' ? '@' . $acc['gh_login'] : (string)$acc['email'];
}

// ---- Sessions ---------------------------------------------------------------------------------

function rs_dev_session_start(int $accountId): void {
    rs_dev_tables();
    $token = bin2hex(random_bytes(24));
    rs_db()->prepare('INSERT INTO dev_sessions (token_hash, account_id, created, expires) VALUES (?, ?, NOW(), NOW() + INTERVAL ' . RS_DEV_DAYS . ' DAY)')
        ->execute([hash('sha256', $token), $accountId]);
    setcookie(RS_DEV_COOKIE, $token, ['expires' => time() + RS_DEV_DAYS * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

function rs_dev_current(): ?array {
    $t = (string)($_COOKIE[RS_DEV_COOKIE] ?? '');
    if (!preg_match('/^[0-9a-f]{48}$/', $t)) return null;
    rs_dev_tables();
    $st = rs_db()->prepare('SELECT a.* FROM dev_sessions s JOIN dev_accounts a ON a.id = s.account_id WHERE s.token_hash = ? AND s.expires > NOW()');
    $st->execute([hash('sha256', $t)]);
    $a = $st->fetch();
    return $a ?: null;
}

function rs_dev_session_end(): void {
    $t = (string)($_COOKIE[RS_DEV_COOKIE] ?? '');
    if (preg_match('/^[0-9a-f]{48}$/', $t)) { rs_dev_tables(); rs_db()->prepare('DELETE FROM dev_sessions WHERE token_hash = ?')->execute([hash('sha256', $t)]); }
    setcookie(RS_DEV_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

// ---- Invites ----------------------------------------------------------------------------------

function rs_dev_invite(string $code): ?array {
    if (!preg_match('/^[A-Za-z0-9]{24}$/', $code)) return null;
    rs_dev_tables();
    $st = rs_db()->prepare('SELECT * FROM dev_invites WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

// The personal invite link for an email address (the unused one they already have, or a new one).
// Used by the email builder's {invite}.
function rs_dev_invite_url(string $email, string $name = '', ?string $slug = null, ?bool $approve = null): string {
    rs_dev_tables();
    $db = rs_db();
    $email = strtolower(trim($email));
    $st = $db->prepare('SELECT code FROM dev_invites WHERE email = ? AND used_by IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$email]);
    $code = $st->fetchColumn();
    if (!$code) {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < 24; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
        $db->prepare('INSERT INTO dev_invites (code, email, name, created, slug, approve) VALUES (?, ?, ?, NOW(), ?, ?)')
           ->execute([$code, $email, mb_substr($name, 0, 120), (string)$slug, $approve === true ? 1 : 0]);
    } elseif ($slug !== null || $approve !== null) {
        // Sent again with a page or approval picked: the unused invite takes them.
        $db->prepare('UPDATE dev_invites SET slug = ?, approve = ?, name = IF(? <> \'\', ?, name) WHERE code = ?')
           ->execute([(string)$slug, $approve === true ? 1 : 0, $name, mb_substr($name, 0, 120), $code]);
    }
    return 'https://recompstore.com/developers?invite=' . $code;
}

// ---- What a developer has -------------------------------------------------------------------

// Their GitHub login and public orgs, lowercase: the owners whose repos count as theirs.
function rs_dev_owners(array $acc): array {
    if ((string)$acc['gh_login'] === '') return [];
    $owners = [strtolower((string)$acc['gh_login'])];
    $cached = rs_kv_get('dev_orgs_' . $acc['gh_id']);
    if ($cached && $cached['age'] < 86400) {
        $orgs = json_decode($cached['v'], true);
    } else {
        $r = rs_http(rs_gh_api() . '/users/' . rawurlencode((string)$acc['gh_login']) . '/orgs', ['github' => true, 'timeout' => 8]);
        $orgs = $r['status'] === 200 ? array_values(array_map(fn($o) => (string)($o['login'] ?? ''), (array)json_decode($r['body'], true))) : [];
        rs_kv_put('dev_orgs_' . $acc['gh_id'], json_encode($orgs));
    }
    foreach ((array)$orgs as $o) if ($o !== '') $owners[] = strtolower($o);
    return array_values(array_unique($owners));
}

// Their apps from the admin's games: the repo's owner is them or one of their orgs, or the app is
// already on their developer page. Hidden drafts left out unless they're a beta.
function rs_dev_apps(array $acc): array {
    $owners = rs_dev_owners($acc);
    $devs = rs_developers();
    $out = [];
    foreach (rs_games() as $g) {
        $owner = strtolower((string)strtok((string)$g['repo'], '/'));
        $mine = ($owner !== '' && in_array($owner, $owners, true)) || ($acc['slug'] !== '' && rs_dev_for($g, $devs) === $acc['slug']);
        if ($mine && (!$g['hidden'] || $g['beta'])) $out[] = $g;
    }
    return $out;
}

// (2.8) Their PC recomps: the repo's owner is them or one of their orgs, or it's on their page.
// What they can change on its page; a verified developer's change goes out straight away.
const RS_DEV_PC_FIELDS = ['name' => 120, 'blurb' => 160, 'description' => 8000, 'features' => 1200, 'media' => 5000, 'needs' => 200, 'credits' => 1000,
    'tags' => 300, 'setup_steps' => 2000, 'report_url' => 300, // (3.9) report_url: where they want bugs reported (https)
    'status' => 8, 'status_note' => 200]; // (4.0) their project's standing: '' active, paused (on hold) or ended, and a line about it

// (4.0) A value for one of RS_DEV_PC_FIELDS that isn't plain text: null when it's fine, else what's wrong.
function rs_dev_pc_field_error(string $k, string $v): ?string {
    if ($k === 'status' && $v !== '' && !in_array($v, RS_PC_STATUSES, true)) return 'Pick Active, On hold or Ended.';
    if ($k === 'status_note' && preg_match('/[\r\n]/', $v)) return 'The status note is one line.';
    return null;
}
// (3.8) Their PC recomp's pictures: new ones wait for you to look at them before they go on its page.
const RS_DEV_PC_ART = ['cover_file' => 'cover', 'hero_file' => 'hero', 'logo_file' => 'logo', 'icon_file' => 'icon'];

// A picture they sent for a PC recomp, made ready (sized, saved under /media) but not on its page yet.
function rs_dev_pc_art_save(array $file, string $kind, string $id): string {
    if ($kind === 'cover') return (string)(rs_pc_media_file((string)$file['tmp_name'], $id)['cover'] ?? '');
    $out = rs_save_image($file, $kind, 'pc-' . rs_pc_slug($id));
    return (string)($kind === 'hero' ? ($out['hero_web'] ?? $out['hero'] ?? '') : ($out[$kind] ?? ''));
}

// Pictures waiting for a look, by PC recomp id: ['id' => ['cover' => '/media/..', ...]].
function rs_dev_pc_art_waiting(int $accountId): array {
    $st = rs_db()->prepare("SELECT app_id, data FROM dev_changes WHERE account_id = ? AND status = 'pending' AND app_id LIKE 'pc:%' ORDER BY id");
    $st->execute([$accountId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        foreach ((array)($d['art'] ?? []) as $k => $v) $out[substr((string)$r['app_id'], 3)][$k] = (string)$v;
    }
    return $out;
}

// Pictures that were never used (turned down, or replaced before you looked): their files go.
function rs_dev_pc_art_drop(array $art): void {
    foreach ($art as $path) {
        $path = (string)$path;
        if (preg_match('#^/media/pc-[A-Za-z0-9_.-]+$#', $path)) @unlink(RS_MEDIA . '/' . basename($path));
    }
}
function rs_dev_pc_apps(array $acc): array {
    $owners = rs_dev_owners($acc);
    $out = [];
    foreach (rs_pc_apps(false) as $a) {
        // (3.9) A GitLab project's owner isn't a GitHub login: those are theirs only through the page (dev).
        $owner = rs_pc_host($a) === 'gitlab' ? '' : strtolower((string)strtok((string)$a['repo'], '/'));
        if (($owner !== '' && in_array($owner, $owners, true)) || ((string)$acc['slug'] !== '' && $a['dev'] === $acc['slug'])) $out[] = $a;
    }
    return $out;
}

// Numbers for their apps: installs now, new installs this week, GitHub downloads and stars, likes.
function rs_dev_stats(array $ids): array {
    if (!$ids) return [];
    $out = array_fill_keys($ids, ['installs' => 0, 'week' => 0, 'downloads' => 0, 'stars' => 0, 'likes' => 0]);
    try {
        $ch = rs_charts();
        foreach ((array)($ch['stats'] ?? []) as $id => $s) if (isset($out[$id])) { $out[$id]['installs'] = (int)($s['installs'] ?? 0); $out[$id]['downloads'] = (int)($s['downloads'] ?? 0); $out[$id]['stars'] = (int)($s['stars'] ?? 0); }
        foreach ((array)($ch['trending'] ?? []) as $t) if (isset($out[$t['id']])) $out[$t['id']]['week'] = (int)$t['installs'];
    } catch (Throwable $e) { /* no numbers yet */ }
    foreach (rs_likes_counts() as $id => $n) if (isset($out[$id])) $out[$id]['likes'] = (int)$n;
    // (2.7.3) New installs a day for the last 30 days, oldest first.
    try {
        rs_stats_tables();
        $db = rs_db();
        $in = implode(',', array_map(fn($x) => $db->quote((string)$x), $ids));
        $days = [];
        for ($i = 29; $i >= 0; $i--) $days[gmdate('Y-m-d', time() - $i * 86400)] = 0;
        foreach ($ids as $id) $out[$id]['days'] = $days;
        foreach ($db->query("SELECT app_id, DATE(first_at) d, COUNT(*) n FROM app_installs WHERE app_id IN ($in) AND first_at > (NOW() - INTERVAL 30 DAY) GROUP BY app_id, DATE(first_at)") as $r)
            if (isset($out[$r['app_id']]['days'][$r['d']])) $out[$r['app_id']]['days'][$r['d']] = (int)$r['n'];
        foreach ($ids as $id) $out[$id]['days'] = array_values($out[$id]['days']);
    } catch (Throwable $e) { /* no series */ }
    return $out;
}

// The logs players sent for their apps (newest first): what happened, the version and the files.
function rs_dev_logs(array $ids, int $limit = 100): array {
    if (!$ids) return [];
    rs_report_tables();
    rs_inbox_category_column();
    $db = rs_db();
    $in = implode(',', array_map(fn($x) => $db->quote((string)$x), $ids));
    $rows = $db->query("SELECT i.id, i.app_id, i.version, i.body, i.category, i.created, r.what, r.before_text FROM inbox i LEFT JOIN inbox_reports r ON r.inbox_id = i.id
        WHERE i.kind = 'logs' AND i.app_id IN ($in) ORDER BY i.created DESC LIMIT " . (int)$limit)->fetchAll();
    if (!$rows) return [];
    $files = $sigs = [];
    $appOf = [];
    foreach ($rows as $r) $appOf[(int)$r['id']] = (string)$r['app_id'];
    foreach ($db->query('SELECT id, inbox_id, name, size, RIGHT(body, 65536) AS tail FROM inbox_files WHERE inbox_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ') ORDER BY id') as $f) {
        $files[(int)$f['inbox_id']][] = ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'size' => (int)$f['size']];
        // (2.7.3) What went wrong, the same for every player, so one problem shows once.
        $s = rs_log_signature((string)$f['tail'], $appOf[(int)$f['inbox_id']] ?? '', (string)$f['name']);
        if ($s !== '') $sigs[(int)$f['inbox_id']] = $s;
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'app' => (string)$r['app_id'], 'version' => (string)$r['version'], 'category' => (string)$r['category'],
        'message' => trim((string)$r['body']), 'what' => trim((string)($r['what'] ?? '')), 'before' => trim((string)($r['before_text'] ?? '')),
        'at' => gmdate('c', (int)strtotime((string)$r['created'] . ' UTC')), 'files' => $files[(int)$r['id']] ?? [], 'sig' => $sigs[(int)$r['id']] ?? '',
    ], $rows);
}

// Their developer page (the developers table), made when the admin approves them.
function rs_dev_profile(array $acc): ?array {
    if ($acc['slug'] === '') return null;
    return rs_developers()[$acc['slug']] ?? null;
}

// Approving: their developer page (an existing one that matches their name or GitHub, else a new
// one), Verified, linked to their account.
function rs_dev_approve(int $id, string $pick = ''): array {
    rs_dev_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM dev_accounts WHERE id = ?');
    $st->execute([$id]);
    $acc = $st->fetch();
    if (!$acc) throw new RuntimeException('That account is gone.');
    $devs = rs_developers();
    $slug = $acc['slug'] !== '' && isset($devs[$acc['slug']]) ? $acc['slug'] : '';
    if ($slug === '' && $pick !== '' && isset($devs[$pick])) $slug = $pick; // the admin chose their page
    if ($slug === '') {
        $keys = [rs_dev_key((string)$acc['gh_login']), rs_dev_key((string)$acc['name'])];
        foreach ($devs as $s => $d) {
            $names = array_merge([$d['name'], $s, (string)($d['links']['github'] ?? '')], preg_split('/\s*,\s*/', (string)$d['aliases'], -1, PREG_SPLIT_NO_EMPTY));
            foreach ($names as $n) { $k = rs_dev_key(preg_replace('#^https://github\.com/#i', '', (string)$n) ?? ''); if ($k !== '' && in_array($k, $keys, true)) { $slug = $s; break 2; } }
        }
    }
    $d = $slug !== '' ? $devs[$slug] : array_merge(rs_dev_defaults(), ['name' => $acc['name'] ?: $acc['gh_login']]);
    if ($slug === '') {
        $from = (string)$acc['gh_login'] !== '' ? (string)$acc['gh_login'] : ((string)$acc['name'] !== '' ? (string)$acc['name'] : strtok((string)$acc['email'], '@'));
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($from)), '-') ?: 'dev';
        $slug = substr($base, 0, 40);
        for ($n = 2; isset($devs[$slug]); $n++) $slug = substr($base, 0, 36) . '-' . $n;
        $d['slug'] = $slug;
    }
    $d['verified'] = true;
    if ($d['avatar'] === '' && preg_match('#^https://#', (string)$acc['avatar'])) $d['avatar'] = (string)$acc['avatar'];
    if (empty($d['links']['github']) && (string)$acc['gh_login'] !== '') $d['links']['github'] = 'https://github.com/' . $acc['gh_login'];
    rs_dev_save($d, isset($devs[$slug]) ? $slug : null);
    $db->prepare("UPDATE dev_accounts SET status = 'approved', slug = ?, decided = NOW() WHERE id = ?")->execute([$slug, $id]);
    // (3.0) The Lounge so far isn't new to them.
    try { $db->prepare("UPDATE dev_accounts SET lounge_seen = (SELECT COALESCE(MAX(id), 0) FROM dev_messages WHERE channel = 'lounge') WHERE id = ? AND lounge_seen = 0")->execute([$id]); } catch (Throwable $e) { /* no messages yet */ }
    $acc['slug'] = $slug;
    $acc['status'] = 'approved';
    rs_dev_discord_role($acc);
    return $acc;
}

// The Developer role on Discord, when they've linked Discord and the site's bot can give it.
// -> true when they have the role now (or there's no role to give). (4.1) Says when Discord says no.
function rs_dev_discord_role(array $acc): bool {
    $role = trim((string)rs_setting('dc_dev_role', ''));
    $c = rs_dc_conf();
    if ($acc['status'] !== 'approved' || $acc['discord_id'] === '' || $role === '' || $c['bot'] === '' || $c['guild'] === '') return true;
    [$st, , $raw] = rs_dc_call('PUT', '/guilds/' . rawurlencode($c['guild']) . '/members/' . rawurlencode((string)$acc['discord_id']) . '/roles/' . rawurlencode($role), ['bot' => $c['bot']]);
    if ($st === 204) return true;
    rs_log('discord', 'dev-role', ($acc['gh_login'] ?: $acc['name']) . ': Discord answered ' . ($st ?: 'nothing') . ' ' . mb_substr((string)$raw, 0, 160));
    return false;
}

// A note to the admin's mailbox when someone asks for a hub (best effort).
function rs_dev_notify_admin(array $acc, string $what): void {
    try {
        if (!rs_mail_ready()) return;
        $to = rs_mail_get('mail_from');
        rs_mail_send($to, 'Developer request: ' . ($acc['name'] ?: $acc['gh_login']), ($acc['name'] ?: $acc['gh_login']) . ' (' . ((string)$acc['gh_login'] !== '' ? 'github.com/' . $acc['gh_login'] : $acc['email']) . ') ' . $what . ".\n\nApprove or turn it down on the Developers page:\n" . rs_abs('/admin/developers.php#accounts'));
    } catch (Throwable $e) { /* the admin page shows it anyway */ }
}

// The fields a developer may suggest for their app's page.
const RS_DEV_CHANGE_FIELDS = ['blurb' => 160, 'description' => 6000, 'news' => 2000, 'needs' => 160, 'credits' => 1000, 'video' => 200];

// Requests and app changes waiting for the admin (the count by Developers in the menu).
function rs_dev_waiting(): int {
    try {
        rs_dev_tables();
        return (int)rs_db()->query("SELECT (SELECT COUNT(*) FROM dev_accounts WHERE status = 'pending') + (SELECT COUNT(*) FROM dev_changes WHERE status = 'pending') + (SELECT COUNT(*) FROM dev_claims WHERE status = 'pending')")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ---- Developer betas ----------------------------------------------------------------------------
// A beta app of theirs takes its build from one of their GitHub releases (usually a pre-release).
// The site downloads it once to take its SHA-256, the admin's signer signs that (as for every beta),
// and testers' stores download it straight from GitHub. Codes: ones they make and hand out
// themselves, and (if they turn it on) codes through Discord on recompstore.com/beta.

const RS_DEV_BETA_MAX_CODES = 1000;

// The releases of a repo a developer can pick from: [{tag, name, pre, date, assets: [{name, size, url}]}]
function rs_dev_releases(string $repo): array {
    if (!rs_repo_ok($repo)) return [];
    $r = rs_gh_releases($repo, 300);
    $list = $r['status'] === 200 ? json_decode((string)$r['body'], true) : [];
    $out = [];
    foreach (is_array($list) ? $list : [] as $x) {
        if (!empty($x['draft'])) continue;
        $assets = [];
        foreach ((array)($x['assets'] ?? []) as $a) if (preg_match('/\.(zip)$/i', (string)$a['name'])) $assets[] = ['name' => (string)$a['name'], 'size' => (int)$a['size'], 'url' => (string)$a['browser_download_url']];
        if ($assets) $out[] = ['tag' => (string)$x['tag_name'], 'name' => (string)($x['name'] ?? '') ?: (string)$x['tag_name'], 'pre' => !empty($x['prerelease']), 'date' => substr((string)($x['published_at'] ?? ''), 0, 10), 'assets' => $assets];
        if (count($out) >= 15) break;
    }
    return $out;
}

// Downloads a file only to take its SHA-256 (nothing is kept). -> [sha256, bytes]
function rs_dev_hash_url(string $url, int $max = 2147483647): array {
    $h = hash_init('sha256');
    $n = 0;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 280,
        CURLOPT_USERAGENT => 'RecompStore-site', CURLOPT_FAILONERROR => true,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($h, &$n, $max) { $n += strlen($chunk); if ($n > $max) return 0; hash_update($h, $chunk); return strlen($chunk); }]);
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) curl_setopt($c, CURLOPT_CAINFO, $ca);
    @set_time_limit(300);
    $ok = curl_exec($c);
    $err = curl_error($c);
    curl_close($c);
    if (!$ok) throw new RuntimeException('The file couldn\'t be downloaded from GitHub (' . ($err ?: 'no answer') . '). Try again in a moment.');
    return [hash_final($h), $n];
}

// Their beta apps as the hub shows them.
function rs_dev_betas(array $apps): array {
    rs_dc_tables();
    $db = rs_db();
    $out = [];
    foreach ($apps as $g) {
        if (!rs_has_beta($g)) continue;
        $st = $db->prepare('SELECT code, note, created, install_id, redeemed, revoked FROM beta_codes WHERE game_id = ? ORDER BY created DESC LIMIT 500');
        $st->execute([$g['id']]);
        $codes = array_map(fn($r) => ['code' => rs_beta_format((string)$r['code']), 'note' => (string)$r['note'], 'made' => substr((string)$r['created'], 0, 10),
            'used' => $r['install_id'] !== null, 'when' => $r['redeemed'] ? substr((string)$r['redeemed'], 0, 10) : '', 'off' => (int)$r['revoked'] === 1], $st->fetchAll());
        $signed = preg_match('/^[0-9a-f]{64}$/', (string)$g['beta_sha256']) && rs_sig_for('beta', (string)$g['beta_sha256']) !== '';
        $out[] = ['id' => $g['id'], 'name' => $g['name'], 'icon' => $g['icon'] !== '' ? rs_abs($g['icon']) : '', 'repo' => $g['repo'],
            'build' => $g['beta_file'] !== '' ? ['file' => (string)$g['beta_file'], 'version' => (string)$g['beta_version'], 'size' => rs_human_size(max(1, (int)$g['beta_size'])), 'at' => (string)$g['beta_at'], 'signed' => $signed || !rs_sign_on()] : null,
            'notes' => (string)$g['beta_notes'], 'public' => !empty($g['beta_public']), 'discord' => !empty($g['beta_discord']), 'limit' => (int)($g['beta_limit'] ?? 0),
            'discordTaken' => rs_dc_taken($g['id']), 'codes' => $codes];
    }
    return $out;
}

// ---- (2.7.3) Email and password accounts -------------------------------------------------------

function rs_dev_by_email(string $email): ?array {
    rs_dev_tables();
    $st = rs_db()->prepare('SELECT * FROM dev_accounts WHERE email = ? AND pass_hash IS NOT NULL ORDER BY id LIMIT 1');
    $st->execute([strtolower(trim($email))]);
    return $st->fetch() ?: null;
}

// A one-time link for confirming the email or setting a new password (valid 2 days / 2 hours).
function rs_dev_mail_link(int $id, string $kind): string {
    $token = bin2hex(random_bytes(32));
    rs_db()->prepare('UPDATE dev_accounts SET mail_token = ?, mail_token_until = NOW() + INTERVAL ' . ($kind === 'reset' ? '2 HOUR' : '2 DAY') . ' WHERE id = ?')
        ->execute([hash('sha256', $kind . '|' . $token), $id]);
    return rs_abs($kind === 'reset' ? '/developers?reset=' . $token : '/api/dev.php?a=confirm&t=' . $token);
}

function rs_dev_by_token(string $kind, string $token): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    rs_dev_tables();
    $st = rs_db()->prepare('SELECT * FROM dev_accounts WHERE mail_token = ? AND mail_token_until > NOW()');
    $st->execute([hash('sha256', $kind . '|' . $token)]);
    return $st->fetch() ?: null;
}

function rs_dev_send_link(array $acc, string $kind): void {
    $url = rs_dev_mail_link((int)$acc['id'], $kind);
    $blocks = $kind === 'reset'
        ? [['t' => 'text', 'text' => "Hi {name},\n\nSomeone (hopefully you) asked to set a new password for your RecompStore developer account. The link works for 2 hours."],
           ['t' => 'button', 'label' => 'Set a new password', 'url' => $url, 'style' => 'main'],
           ['t' => 'text', 'text' => "Didn't ask for this? Ignore it and nothing changes."]]
        : [['t' => 'text', 'text' => "Hi {name},\n\nConfirm your email to finish setting up your RecompStore developer account."],
           ['t' => 'button', 'label' => 'Confirm my email', 'url' => $url, 'style' => 'main'],
           ['t' => 'text', 'text' => "Didn't sign up? Ignore this and nothing happens."]];
    [$html, $text] = rs_mb_email($blocks, ['name' => $acc['name'] ?: 'there'], rs_mail_get('mail_sign'), '');
    rs_mail_send((string)$acc['email'], $kind === 'reset' ? 'Set a new password for RecompStore' : 'Confirm your email for RecompStore', $text, ['html' => $html]);
}

// ---- (2.7.3) The weekly email to developers ------------------------------------------------------
// Mondays: each approved developer with an email gets their apps' week: on PS5s now, new installs,
// GitHub downloads and the logs players sent. Started by any visit to the charts (the site and every
// console load them), after the answer has gone out, at most once a Monday.

function rs_dev_weekly_maybe(): void {
    if (gmdate('N') !== '1' || rs_setting('dev_weekly', '1') !== '1') return;
    $last = rs_kv_get('dev_weekly_at');
    if ($last && $last['age'] < 2 * 86400) return;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        ignore_user_abort(true);
        try {
            $db = rs_db();
            if ((int)$db->query("SELECT GET_LOCK('rs_dev_weekly', 0)")->fetchColumn() !== 1) return;
            $last = rs_kv_get('dev_weekly_at');
            if (!$last || $last['age'] >= 2 * 86400) {
                rs_kv_put('dev_weekly_at', gmdate('c'));
                @set_time_limit(300);
                rs_dev_weekly_send();
            }
            $db->query("SELECT RELEASE_LOCK('rs_dev_weekly')");
        } catch (Throwable $e) { /* next time */ }
    });
}

// The week for one account: [blocks, subject] or null when they have no apps listed.
function rs_dev_weekly_for(array $acc): ?array {
    $apps = array_values(array_filter(rs_dev_apps($acc), fn($g) => !$g['hidden'] && !$g['soon'] && !$g['beta']));
    if (!$apps) return null;
    $ids = array_map(fn($g) => (string)$g['id'], $apps);
    $stats = rs_dev_stats($ids);
    $logs = [];
    try {
        rs_report_tables();
        $db = rs_db();
        $in = implode(',', array_map(fn($x) => $db->quote($x), $ids));
        foreach ($db->query("SELECT app_id, COUNT(*) n FROM inbox WHERE kind = 'logs' AND app_id IN ($in) AND created > (NOW() - INTERVAL 7 DAY) GROUP BY app_id") as $r) $logs[$r['app_id']] = (int)$r['n'];
    } catch (Throwable $e) { /* no logs */ }
    $n = fn($v) => number_format((int)$v);
    $lines = [];
    $tot = ['installs' => 0, 'week' => 0, 'logs' => 0];
    foreach ($apps as $g) {
        $st = $stats[$g['id']] ?? [];
        $tot['installs'] += (int)($st['installs'] ?? 0);
        $tot['week'] += (int)($st['week'] ?? 0);
        $tot['logs'] += $logs[$g['id']] ?? 0;
        $bits = [$n($st['installs'] ?? 0) . ' on PS5s', '+' . $n($st['week'] ?? 0) . ' new this week'];
        if (!empty($st['downloads'])) $bits[] = $n($st['downloads']) . ' GitHub downloads';
        if (!empty($logs[$g['id']])) $bits[] = $logs[$g['id']] . ' new ' . ($logs[$g['id']] === 1 ? 'log' : 'logs');
        $lines[] = '- **' . $g['name'] . '**: ' . implode(' · ', $bits);
    }
    $blocks = [
        ['t' => 'heading', 'size' => 'big', 'text' => 'Your week on RecompStore'],
        ['t' => 'text', 'text' => "Hi {name},\n\nHere's how your apps did in the last 7 days.\n\n" . implode("\n", $lines)],
    ];
    if ($tot['logs']) $blocks[] = ['t' => 'text', 'text' => 'Players sent **' . $tot['logs'] . ' ' . ($tot['logs'] === 1 ? 'log' : 'logs') . '** this week. Read them in the Logs tab of your hub.'];
    $blocks[] = ['t' => 'button', 'label' => 'Open my developer hub', 'url' => 'https://recompstore.com/hub', 'style' => 'main'];
    $subject = 'Your week: +' . $n($tot['week']) . ' new ' . ($tot['week'] === 1 ? 'install' : 'installs') . ($tot['logs'] ? ', ' . $tot['logs'] . ' new ' . ($tot['logs'] === 1 ? 'log' : 'logs') : '');
    return [$blocks, $subject];
}

// Sends this week's email to everyone who gets it. Returns [sent, skipped, errors].
function rs_dev_weekly_send(?int $only = null): array {
    rs_dev_tables();
    if (!rs_mail_ready()) return [0, 0, ['The mailbox isn\'t set up.']];
    $sql = "SELECT * FROM dev_accounts WHERE status = 'approved' AND email <> ''" . ($only ? ' AND id = ' . (int)$only : ' AND weekly = 1');
    $sent = 0; $skipped = 0; $err = [];
    foreach (rs_db()->query($sql)->fetchAll() as $acc) {
        $w = rs_dev_weekly_for($acc);
        if (!$w) { $skipped++; continue; }
        try {
            [$html, $text] = rs_mb_email($w[0], ['name' => $acc['name'] ?: 'there'], rs_mail_get('mail_sign'),
                "You're getting this weekly email because you have a developer hub on RecompStore. Turn it off in your hub: https://recompstore.com/hub");
            rs_mail_send((string)$acc['email'], $w[1], $text, ['html' => $html]);
            rs_mail_log('weekly', (string)$acc['email'], $w[1], null, '');
            $sent++;
        } catch (Throwable $e) {
            $err[] = $acc['email'] . ': ' . $e->getMessage();
            try { rs_mail_log('weekly', (string)$acc['email'], $w[1], null, $e->getMessage()); } catch (Throwable $e2) { /* fine */ }
        }
    }
    rs_log('developers', 'weekly email', $sent . ' sent' . ($err ? ', ' . count($err) . ' failed' : ''));
    return [$sent, $skipped, $err];
}

// ---- (2.7.3) Setting a developer up before you invite them ---------------------------------------
// Their developer page, made or updated: the one you picked, else one matching their name or GitHub,
// else a new one. Their GitHub picture and link when the page has none, and their GitHub name as
// another name, so their apps (by that author) land on it. Not Verified until you approve them.
function rs_dev_presetup(string $name, string $gh, string $pick = ''): array {
    $devs = rs_developers();
    $gh = trim($gh);
    if ($gh !== '' && !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $gh)) throw new RuntimeException('That isn\'t a GitHub username.');
    $user = null;
    if ($gh !== '') {
        $r = rs_http(rs_gh_api() . '/users/' . rawurlencode($gh), ['github' => true, 'timeout' => 10]);
        $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($j) || empty($j['login'])) throw new RuntimeException('GitHub doesn\'t know ' . $gh . '.');
        $user = ['login' => (string)$j['login'], 'name' => (string)($j['name'] ?? ''), 'avatar' => (string)($j['avatar_url'] ?? '')];
    }
    $slug = $pick !== '' && isset($devs[$pick]) ? $pick : '';
    if ($slug === '') {
        $keys = array_filter([rs_dev_key($name), $user ? rs_dev_key($user['login']) : '']);
        foreach ($devs as $s => $d) {
            $names = array_merge([$d['name'], $s, preg_replace('#^https://github\.com/#i', '', (string)($d['links']['github'] ?? ''))], preg_split('/\s*,\s*/', (string)$d['aliases'], -1, PREG_SPLIT_NO_EMPTY));
            foreach ($names as $n) if (($k = rs_dev_key((string)$n)) !== '' && in_array($k, $keys, true)) { $slug = $s; break 2; }
        }
    }
    $new = $slug === '';
    // A new page is named as on their GitHub (the name players know), else the name you typed.
    $d = $new ? array_merge(rs_dev_defaults(), ['name' => $user ? ($user['name'] ?: $user['login']) : $name]) : $devs[$slug];
    if ($d['name'] === '') throw new RuntimeException('Give them a name or a GitHub username.');
    if ($new) {
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($user ? $user['login'] : $d['name'])), '-') ?: 'dev';
        $slug = substr($base, 0, 40);
        for ($n = 2; isset($devs[$slug]); $n++) $slug = substr($base, 0, 36) . '-' . $n;
        $d['slug'] = $slug;
    }
    if ($user) {
        if ($d['avatar'] === '' && preg_match('#^https://#', $user['avatar'])) $d['avatar'] = $user['avatar'];
        if (empty($d['links']['github'])) $d['links']['github'] = 'https://github.com/' . $user['login'];
        $al = preg_split('/\s*,\s*/', (string)$d['aliases'], -1, PREG_SPLIT_NO_EMPTY);
        $have = array_map('rs_dev_key', array_merge($al, [$d['name'], $slug]));
        if (!in_array(rs_dev_key($user['login']), $have, true)) $al[] = $user['login'];
        // The author names on the games from their GitHub repos, so those apps land on the page too.
        foreach (rs_games() as $g) {
            $owner = strtolower((string)strtok((string)$g['repo'], '/'));
            $au = trim((string)($g['author'] ?? ''));
            if ($owner === strtolower($user['login']) && $au !== '' && !in_array(rs_dev_key($au), array_map('rs_dev_key', $al), true) && !in_array(rs_dev_key($au), $have, true)) $al[] = $au;
        }
        $d['aliases'] = implode(', ', array_slice(array_unique($al), 0, 12));
    }
    rs_dev_save($d, $new ? null : $slug);
    $apps = 0;
    $devs = rs_developers();
    foreach (rs_games() as $g) if (rs_dev_for($g, $devs) === $slug) $apps++;
    return ['slug' => $slug, 'name' => $d['name'], 'new' => $new, 'apps' => $apps];
}

// The invite email for one invite (the Developer invite template, with their link).
function rs_dev_invite_send(array $inv): void {
    $url = 'https://recompstore.com/developers?invite=' . $inv['code'];
    $t = rs_mb_templates()['invite'];
    $foot = 'This invite is just for you. Not interested? Ignore it and nothing happens.';
    [$html, $text] = rs_mb_email($t['blocks'], ['name' => (string)$inv['name'] !== '' ? (string)$inv['name'] : 'there', 'invite' => $url], rs_mail_get('mail_sign'), $foot);
    rs_mail_send((string)$inv['email'], $t['subject'], $text, ['html' => $html, 'footer' => $foot, 'save' => true]);
    rs_mail_log('invite', (string)$inv['email'], $t['subject'], null, '');
    rs_db()->prepare('UPDATE dev_invites SET held = 0 WHERE id = ?')->execute([(int)$inv['id']]);
}

// (2.7.3) An email to you when a verified developer changes one of their app pages.
function rs_dev_edit_notify(array $acc, array $g, array $what, bool $pc = false, bool $waiting = false): void {
    try {
        if (!rs_mail_ready()) return;
        $names = ['blurb' => 'one-line summary', 'description' => 'description', 'news' => 'what\'s new', 'needs' => 'what players need',
            'credits' => 'credits', 'video' => 'video', 'icon' => 'icon', 'hero' => 'backdrop', 'logo' => 'logo', 'screens' => 'screenshots',
            'name' => 'name', 'features' => 'what\'s in it', 'media' => 'links around the web', 'tags' => 'tags', 'setup_steps' => 'setup steps', 'cover' => 'cover', 'report_url' => 'bug report link'];
        $list = implode(', ', array_map(fn($k) => $names[$k] ?? $k, $what));
        $who = $acc['name'] ?: rs_dev_label($acc);
        $edit = $pc ? '/admin/pc.php?edit=' . rawurlencode((string)$g['id']) . '#edit' : '/admin/edit.php?id=' . rawurlencode((string)$g['id']);
        rs_mail_send(rs_mail_get('mail_from'), $waiting ? $who . ' sent new pictures for ' . $g['name'] : $who . ' changed ' . $g['name'],
            ($waiting ? $who . ' sent a new ' . $list . ' for ' . $g['name'] . ". They wait for you: approve or turn them down here:\n" . rs_abs('/admin/dev-accounts.php#changes')
                : $who . ' changed the ' . $list . ' on ' . $g['name'] . ".\n\nSee it in the admin: " . rs_abs($edit)
                . "\nUndo it, send them a note, or see all recent changes: " . rs_abs('/admin/dev-accounts.php#edits'))
            . ($pc || $waiting ? '' : "\n\nIt goes out to the store and the site with your next Publish."));
    } catch (Throwable $e) { /* the change is saved either way */ }
}

// ---- (3.0) What they make, claiming, and messages -------------------------------------------------

const RS_DEV_PLATFORMS = ['pc' => 'PC', 'ps5' => 'PS5'];
const RS_DEV_MSG_MAX = 4000;

// "pc", "ps5", "pc,ps5" or "" from a list or a string; only the known ones, in that order.
function rs_dev_platforms_clean($v): string {
    $list = is_array($v) ? $v : preg_split('/\s*,\s*/', (string)$v, -1, PREG_SPLIT_NO_EMPTY);
    $list = array_map(fn($x) => strtolower(trim((string)$x)), (array)$list);
    return implode(',', array_values(array_filter(array_keys(RS_DEV_PLATFORMS), fn($k) => in_array($k, $list, true))));
}

function rs_dev_platform_labels(string $v): array {
    return array_values(array_map(fn($k) => RS_DEV_PLATFORMS[$k], array_filter(explode(',', $v), fn($k) => isset(RS_DEV_PLATFORMS[$k]))));
}

// An app by its key: "pc:<id>" for a PC recomp, else a PS5 app's title ID.
function rs_dev_app_by_key(string $key): ?array {
    return str_starts_with($key, 'pc:') ? rs_pc_app(substr($key, 3)) : rs_game($key);
}

// Whose developer page an app or recomp is on now ('' for nobody's).
function rs_dev_app_owner(string $key, ?array $app = null): string {
    $app = $app ?? rs_dev_app_by_key($key);
    if (!$app) return '';
    $devs = rs_developers();
    if (!str_starts_with($key, 'pc:')) return rs_dev_for($app, $devs);
    $d = (string)($app['dev'] ?? '');
    return $d !== '' && isset($devs[$d]) ? $d : '';
}

// Is it theirs by GitHub (the repo's owner is them or one of their organizations)?
// (3.9) Never for a GitLab project ($host 'gitlab'): there's no GitLab sign-in yet.
function rs_dev_owns_repo(array $acc, string $repo, string $host = ''): bool {
    if (rs_host_norm($host) === 'gitlab') return false;
    $owner = strtolower((string)strtok($repo, '/'));
    return $owner !== '' && in_array($owner, rs_dev_owners($acc), true);
}

// Put an app on their developer page. A PC recomp's page changes at once; a PS5 app's with the next
// Publish.
function rs_dev_claim_apply(array $acc, string $key): array {
    if ((string)$acc['slug'] === '') throw new RuntimeException('Their developer page isn\'t set up yet.');
    $a = rs_dev_app_by_key($key);
    if (!$a) throw new RuntimeException('That app isn\'t in the store anymore.');
    $a['dev'] = (string)$acc['slug'];
    if (str_starts_with($key, 'pc:')) { rs_pc_save($a); if (empty($a['hidden'])) rs_pc_publish_one($a['id']); }
    else rs_save_game($a, null);
    rs_dev_write_public();
    return $a;
}

// What they could claim: their GitHub's apps and recomps that aren't on their page yet, and the
// claims they've made.
function rs_dev_claim_state(array $acc, array $apps, array $pcApps): array {
    $slug = (string)$acc['slug'];
    $devs = rs_developers();
    $mine = fn(array $a, bool $pc) => $slug !== '' && ($pc ? (string)($a['dev'] ?? '') : rs_dev_for($a, $devs)) === $slug;
    $out = [];
    foreach ($pcApps as $a) if (!$mine($a, true) && empty($a['hidden'])) $out[] = ['key' => 'pc:' . $a['id'], 'name' => $a['name'], 'repo' => $a['repo'], 'pc' => true,
        'pic' => $a['cover'] !== '' ? rs_abs($a['cover']) : ''] + (rs_pc_host($a) === 'gitlab' ? ['host' => 'gitlab'] : []); // (3.9)
    foreach ($apps as $g) if (!$mine($g, false) && !$g['hidden']) $out[] = ['key' => $g['id'], 'name' => $g['name'], 'repo' => $g['repo'], 'pc' => false,
        'pic' => $g['icon'] !== '' ? rs_abs($g['icon']) : ''];
    $st = rs_db()->prepare('SELECT id, app_id, status, created FROM dev_claims WHERE account_id = ? ORDER BY id DESC LIMIT 40');
    $st->execute([$acc['id']]);
    $claims = [];
    foreach ($st->fetchAll() as $c) {
        $a = rs_dev_app_by_key((string)$c['app_id']);
        $claims[] = ['key' => $c['app_id'], 'name' => $a['name'] ?? $c['app_id'], 'status' => $c['status'], 'date' => substr((string)$c['created'], 0, 10), 'pc' => str_starts_with((string)$c['app_id'], 'pc:')];
    }
    return ['ready' => $out, 'claims' => $claims];
}

// Apps and recomps by name, for claiming one their GitHub doesn't show (a fork, someone else's
// account, a port they made for another platform).
function rs_dev_claim_search(array $acc, string $q): array {
    $q = mb_strtolower(trim($q));
    if (mb_strlen($q) < 2) return [];
    $slug = (string)$acc['slug'];
    $hit = fn(array $a) => str_contains(mb_strtolower((string)$a['name'] . ' ' . (string)($a['game'] ?? '') . ' ' . (string)$a['repo']), $q);
    $out = [];
    foreach (rs_pc_apps(false) as $a) if ($hit($a) && (string)($a['dev'] ?? '') !== $slug) $out[] = ['key' => 'pc:' . $a['id'], 'name' => $a['name'], 'repo' => $a['repo'], 'pc' => true, 'pic' => $a['cover'] !== '' ? rs_abs($a['cover']) : ''] + (rs_pc_host($a) === 'gitlab' ? ['host' => 'gitlab'] : []);
    foreach (rs_games(false) as $g) if ($hit($g) && (string)($g['dev'] ?? '') !== $slug) $out[] = ['key' => $g['id'], 'name' => $g['name'], 'repo' => $g['repo'], 'pc' => false, 'pic' => $g['icon'] !== '' ? rs_abs($g['icon']) : ''];
    return array_slice($out, 0, 20);
}

// Messages ------------------------------------------------------------------------------------------

function rs_dev_msg_row(array $r, array $names): array {
    $who = (int)$r['from_admin'] ? null : ($names[(int)$r['account_id']] ?? null);
    return ['id' => (int)$r['id'], 'body' => $r['deleted'] ? '' : (string)$r['body'], 'deleted' => (bool)$r['deleted'], 'team' => (bool)$r['from_admin'],
        'who' => $who ? $who['name'] : 'RecompStore', 'pic' => $who ? $who['pic'] : '', 'slug' => $who ? $who['slug'] : '', 'verified' => $who ? $who['verified'] : true,
        'mine' => false, 'at' => gmdate('c', (int)strtotime((string)$r['created'] . ' UTC'))];
}

// Names and pictures of developers, by account.
function rs_dev_msg_names(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $devs = rs_developers();
    $st = rs_db()->prepare('SELECT id, gh_login, name, email, avatar, slug FROM dev_accounts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = $devs[(string)$r['slug']] ?? null;
        $out[(int)$r['id']] = ['name' => $d['name'] ?? ($r['name'] ?: ($r['gh_login'] ?: 'Developer')), 'pic' => ($d && $d['avatar'] !== '') ? rs_abs((string)$d['avatar']) : (string)$r['avatar'],
            'slug' => $d ? (string)$r['slug'] : '', 'verified' => (bool)($d['verified'] ?? false)];
    }
    return $out;
}

function rs_dev_dm(int $accId, int $limit = 200): array {
    $st = rs_db()->prepare("SELECT * FROM dev_messages WHERE channel = 'dm' AND account_id = ? ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$accId]);
    $rows = array_reverse($st->fetchAll());
    $names = rs_dev_msg_names([$accId]);
    return array_map(fn($r) => array_merge(rs_dev_msg_row($r, $names), ['mine' => !(int)$r['from_admin']]), $rows);
}

function rs_dev_lounge(int $meId, string $room = 'general', int $limit = 150): array {
    $st = rs_db()->prepare("SELECT * FROM dev_messages WHERE channel = 'lounge' AND room = ? AND deleted = 0 ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$room]);
    $rows = array_reverse($st->fetchAll());
    $names = rs_dev_msg_names(array_map(fn($r) => (int)$r['account_id'], $rows));
    return array_map(fn($r) => array_merge(rs_dev_msg_row($r, $names), ['mine' => $meId > 0 && !(int)$r['from_admin'] && (int)$r['account_id'] === $meId]), $rows);
}

function rs_dev_msg_add(string $channel, int $accId, bool $fromAdmin, string $body, string $room = 'general'): int {
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') throw new RuntimeException('Write something first.');
    if (mb_strlen($body) > RS_DEV_MSG_MAX) throw new RuntimeException('That\'s too long. Keep it under ' . RS_DEV_MSG_MAX . ' characters.');
    $db = rs_db();
    $db->prepare('INSERT INTO dev_messages (channel, room, account_id, from_admin, body, created, read_dev, read_admin) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)')
       ->execute([$channel, $channel === 'lounge' ? $room : 'general', $accId, $fromAdmin ? 1 : 0, $body, $fromAdmin ? 0 : 1, $fromAdmin ? 1 : 0]);
    return (int)$db->lastInsertId();
}

// Unread for a developer: the team's messages to them, and lounge posts since they last looked.
function rs_dev_unread(array $acc): array {
    $db = rs_db();
    $st = $db->prepare("SELECT COUNT(*) FROM dev_messages WHERE channel = 'dm' AND account_id = ? AND from_admin = 1 AND read_dev = 0 AND deleted = 0");
    $st->execute([$acc['id']]);
    $dm = (int)$st->fetchColumn();
    $rooms = [];
    if ($acc['status'] === 'approved') {
        $seen = rs_dev_rooms_seen($acc);
        $st = $db->prepare("SELECT COUNT(*) FROM dev_messages WHERE channel = 'lounge' AND room = ? AND deleted = 0 AND id > ? AND NOT (from_admin = 0 AND account_id = ?)");
        foreach (rs_dev_rooms_for($acc) as $r) {
            $st->execute([$r, $seen[$r] ?? (int)($acc['lounge_seen'] ?? 0), $acc['id']]);
            $rooms[$r] = (int)$st->fetchColumn();
        }
    }
    return ['dm' => $dm, 'lounge' => array_sum($rooms), 'rooms' => (object)$rooms];
}

// ---- (3.1) Lounge channels ------------------------------------------------------------------------
// Everyone sees General and Showcase; PC recomps and PS5 apps show to the developers who make them
// (and to everyone who hasn't said yet).
const RS_DEV_ROOMS = ['general' => 'general', 'showcase' => 'showcase', 'pc' => 'pc-recomps', 'ps5' => 'ps5-apps'];

function rs_dev_rooms_for(array $acc): array {
    $p = array_filter(explode(',', (string)($acc['platforms'] ?? '')));
    return array_values(array_filter(array_keys(RS_DEV_ROOMS), fn($r) => !in_array($r, ['pc', 'ps5'], true) || !$p || in_array($r, $p, true)));
}

function rs_dev_rooms_seen(array $acc): array {
    $j = json_decode((string)($acc['lounge_rooms'] ?? ''), true);
    return is_array($j) ? array_map('intval', $j) : [];
}

function rs_dev_room_seen(array $acc, string $room, int $last): void {
    if (!array_key_exists($room, RS_DEV_ROOMS) || $last <= 0) return;
    // One key at a time in the database, so a poll and a post at once can't undo each other.
    $path = '$.' . $room;
    try {
        rs_db()->prepare("UPDATE dev_accounts SET lounge_rooms = JSON_SET(COALESCE(NULLIF(lounge_rooms, ''), '{}'), '$path',
            GREATEST(COALESCE(CAST(JSON_VALUE(NULLIF(lounge_rooms, ''), '$path') AS SIGNED), 0), ?)) WHERE id = ?")->execute([$last, $acc['id']]);
    } catch (Throwable $e) {
        // A database without JSON functions: the same, in PHP.
        $seen = rs_dev_rooms_seen($acc);
        if ($last <= ($seen[$room] ?? 0)) return;
        $seen[$room] = $last;
        rs_db()->prepare('UPDATE dev_accounts SET lounge_rooms = ? WHERE id = ?')->execute([json_encode($seen), $acc['id']]);
    }
}

// (3.1) Who a message to many goes to: approved developers (and waiting ones, if asked) who make
// PC recomps, PS5 apps, or anything. PC and PS5 include those who haven't said yet.
function rs_dev_broadcast_list(string $aud, bool $waiting): array {
    $rows = rs_db()->query("SELECT * FROM dev_accounts WHERE status IN ('approved'" . ($waiting ? ", 'pending'" : '') . ") ORDER BY id")->fetchAll();
    if ($aud === 'all') return $rows;
    return array_values(array_filter($rows, function ($r) use ($aud) {
        $p = array_filter(explode(',', (string)($r['platforms'] ?? '')));
        return !$p || in_array($aud, $p, true);
    }));
}

// ---- (3.1) Developer docs -------------------------------------------------------------------------
const RS_DEV_DOC_AUDIENCES = ['pc' => 'PC · RecompLauncher', 'ps5' => 'PS5 · RecompStore', 'all' => 'Everyone'];

function rs_dev_doc_slug(string $v): string {
    return substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-'), 0, 80);
}

// The docs a developer can read: published, for everyone or for what they make (all of them when
// they haven't said). $all for the admin's preview.
function rs_dev_docs_for(array $acc, bool $all = false): array {
    $p = array_filter(explode(',', (string)($acc['platforms'] ?? '')));
    $rows = rs_db()->query('SELECT id, slug, title, summary, audience, sort, published, updated, CHAR_LENGTH(body) AS chars FROM dev_docs ORDER BY FIELD(audience, \'pc\', \'ps5\', \'all\'), sort, id')->fetchAll();
    return array_values(array_filter($rows, fn($d) => $all || ((int)$d['published'] && ($d['audience'] === 'all' || !$p || in_array($d['audience'], $p, true)))));
}

// The first docs, from admin/inc/docs/*.md, the first time the table exists.
function rs_dev_docs_seed(): void {
    // Once: docs you delete later stay deleted.
    if (rs_setting('dev_docs_seeded', '') === '1') return;
    $db = rs_db();
    if ((int)$db->query('SELECT COUNT(*) FROM dev_docs')->fetchColumn() > 0) { rs_set_setting('dev_docs_seeded', '1'); return; }
    $files = glob(__DIR__ . '/docs/*.md') ?: [];
    sort($files);
    $ins = $db->prepare('INSERT IGNORE INTO dev_docs (slug, title, summary, audience, body, sort, published, updated) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    foreach ($files as $f) {
        $raw = (string)file_get_contents($f);
        if (!preg_match('/^---\n(.*?)\n---\n(.*)$/s', $raw, $m)) continue;
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) if (preg_match('/^(\w+):\s*(.*)$/', $line, $mm)) $meta[$mm[1]] = trim(trim($mm[2]), '"');
        $ins->execute([rs_dev_doc_slug($meta['slug'] ?? basename($f, '.md')), $meta['title'] ?? basename($f, '.md'), $meta['summary'] ?? '',
            in_array($meta['audience'] ?? '', ['pc', 'ps5', 'all'], true) ? $meta['audience'] : 'all', ltrim($m[2]), (int)($meta['sort'] ?? 0), ($meta['published'] ?? '1') === '1' ? 1 : 0]);
    }
    rs_set_setting('dev_docs_seeded', '1');
}

// For the admin's menu: developers' messages not read yet, and claims waiting.
function rs_dev_admin_unread(): int {
    try {
        rs_dev_tables();
        return (int)rs_db()->query("SELECT COUNT(*) FROM dev_messages WHERE channel = 'dm' AND from_admin = 0 AND read_admin = 0 AND deleted = 0")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
function rs_dev_claims_waiting(): int {
    try {
        rs_dev_tables();
        return (int)rs_db()->query("SELECT COUNT(*) FROM dev_claims WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

