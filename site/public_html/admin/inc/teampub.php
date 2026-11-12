<?php
// (4.8) The Team page on recomplauncher.com/team: the people who run RecompLauncher, so a developer
// someone reaches out to can check it's really us. The owner picks who shows from the admin's Team
// page (on the website, title, GitHub, Discord, a line about what they do); nobody shows until ticked.
// Under the team, everyone who has worked on the RecompLauncher repo, from GitHub (bots and Claude left
// out), kept six hours.
declare(strict_types=1);

const RS_TP_REPO = 'elripalda/RecompLauncher';
const RS_TP_SKIP = ['claude', 'copilot', 'dependabot', 'github-actions', 'renovate'];

function rs_tp_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    rs_roles_schema();
    if (rs_setting('teampub_schema', '') === '1') return;
    $db = rs_db();
    $cols = ['pub_show' => 'TINYINT NOT NULL DEFAULT 0', 'pub_name' => "VARCHAR(60) NOT NULL DEFAULT ''", 'pub_title' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'pub_github' => "VARCHAR(40) NOT NULL DEFAULT ''", 'pub_discord' => "VARCHAR(40) NOT NULL DEFAULT ''", 'pub_about' => "VARCHAR(200) NOT NULL DEFAULT ''",
        'pub_order' => 'INT NOT NULL DEFAULT 0'];
    foreach ($cols as $col => $def) {
        if (!$db->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch()) $db->exec("ALTER TABLE users ADD COLUMN $col $def");
    }
    rs_set_setting('teampub_schema', '1');
}

// The people shown on the website, in order.
function rs_tp_members(): array {
    rs_tp_schema();
    require_once __DIR__ . '/adminlink.php';
    rs_al_schema();
    $rows = rs_db()->query("SELECT username, display_name, role, pub_name, pub_title, pub_github, pub_discord, pub_about, account_id FROM users
        WHERE pub_show = 1 AND active = 1 ORDER BY pub_order, FIELD(role, 'owner', 'manager', 'curator', 'gcurator', 'maker'), id")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $gh = (string)$r['pub_github'];
        // (4.8) Merged with a RecompLauncher account: that account's profile picture.
        $acc = (int)($r['account_id'] ?? 0) > 0 ? rs_acct_get((int)$r['account_id']) : null;
        $pic = $acc && empty($acc['banned']) ? rs_acct_avatar_url($acc) : '';
        $out[] = [
            'name' => (string)($r['pub_name'] ?: ($r['display_name'] ?: $r['username'])),
            'title' => (string)($r['pub_title'] ?: (RS_ROLES[$r['role']] ?? '')),
            'github' => $gh,
            'discord' => (string)$r['pub_discord'],
            'about' => (string)$r['pub_about'],
            'avatar' => $pic !== '' ? $pic : ($gh !== '' ? 'https://github.com/' . rawurlencode($gh) . '.png?size=192' : ''),
        ];
    }
    return $out;
}

// Everyone who has worked on the RecompLauncher repo: [{login, avatar, commits}], most commits first.
function rs_tp_people(): array {
    $c = null;
    try { $c = rs_kv_get('teampub:people'); } catch (Throwable $e) { /* no cache table yet */ }
    if ($c && $c['age'] < 6 * 3600) return (array)json_decode($c['v'], true);
    $people = null;
    try {
        $r = rs_http(rs_gh_api() . '/repos/' . RS_TP_REPO . '/contributors?per_page=100', ['github' => true, 'timeout' => 8, 'max' => 2000000]);
        $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
        if (is_array($j)) {
            $people = [];
            foreach ($j as $u) {
                $login = (string)($u['login'] ?? '');
                if ($login === '' || ($u['type'] ?? 'User') !== 'User' || preg_match('/\[bot\]$|-bot$/i', $login) || in_array(strtolower($login), RS_TP_SKIP, true)) continue;
                $av = (string)($u['avatar_url'] ?? '');
                $people[] = ['login' => $login, 'avatar' => preg_match('#^https://avatars\.githubusercontent\.com/#', $av) ? $av . (str_contains($av, '?') ? '&' : '?') . 's=96' : '', 'commits' => (int)($u['contributions'] ?? 0)];
            }
            usort($people, fn($x, $y) => $y['commits'] <=> $x['commits']);
        }
    } catch (Throwable $e) { /* GitHub didn't answer: the last list, below */ }
    if ($people === null) return $c ? (array)json_decode($c['v'], true) : [];
    try { rs_kv_put('teampub:people', json_encode($people, JSON_UNESCAPED_SLASHES)); } catch (Throwable $e) { /* next time */ }
    return $people;
}

// From the admin's Team page.
function rs_tp_save(int $id, array $in): void {
    rs_tp_schema();
    $clean = fn(string $k, int $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
    $gh = ltrim($clean('github', 40), '@');
    $gh = (string)preg_replace('#^https?://(www\.)?github\.com/#i', '', $gh);
    if ($gh !== '' && !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $gh)) throw new RuntimeException('That GitHub name doesn\'t look right: just the name, like elripalda.');
    $dc = ltrim($clean('discord', 40), '@');
    if ($dc !== '' && !preg_match('/^[A-Za-z0-9_.]{2,32}$/', $dc)) throw new RuntimeException('That Discord name doesn\'t look right: just the username, like elripalda.');
    rs_db()->prepare('UPDATE users SET pub_show = ?, pub_name = ?, pub_title = ?, pub_github = ?, pub_discord = ?, pub_about = ?, pub_order = ? WHERE id = ?')
        ->execute([!empty($in['show']) ? 1 : 0, $clean('name', 60), $clean('title', 40), $gh, $dc, $clean('about', 200), max(-99, min(99, (int)($in['order'] ?? 0))), $id]);
}
