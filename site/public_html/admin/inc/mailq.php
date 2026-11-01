<?php
// (3.1) Emails wait in a line and go out at a pace the mailbox allows (Hostinger: 1,000 a day per
// mailbox, then slower). Account confirmations and password resets go first. Nobody is turned away
// because an email couldn't go out this second: it goes out a little later.
// Sent from page views (a few at a time, at most every 20 seconds), so no cron job is needed.
declare(strict_types=1);

function rs_mailq_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS mail_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        to_addr VARCHAR(190) NOT NULL,
        subject VARCHAR(250) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        opt TEXT NOT NULL,
        prio TINYINT NOT NULL DEFAULT 5,
        tries TINYINT NOT NULL DEFAULT 0,
        next_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        err VARCHAR(300) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        KEY due (sent_at, prio, next_at)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Into the line. $now: try this one straight away (it stays in line if that doesn't work).
// -> true when it went out already.
function rs_mailq_add(string $to, string $subject, string $text, array $opt = [], int $prio = 5, bool $now = false): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That isn\'t an email address: ' . $to);
    rs_mailq_tables();
    $db = rs_db();
    // The same email to the same person still waiting (a second "send it again"): only the newest stays.
    $db->prepare('DELETE FROM mail_queue WHERE sent_at IS NULL AND to_addr = ? AND subject = ?')->execute([$to, $subject]);
    $db->prepare('INSERT INTO mail_queue (to_addr, subject, body, opt, prio, next_at, created) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([$to, $subject, $text, json_encode($opt), $prio]);
    $id = (int)$db->lastInsertId();
    if ($now && !rs_mailq_paused()) return rs_mailq_send_one($id);
    return false;
}

// After a refusal from the mail server, nothing is tried for a few minutes.
function rs_mailq_paused(): bool { return time() < (int)rs_setting('mailq_pause_until', '0'); }

function rs_mailq_send_one(int $id): bool {
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM mail_queue WHERE id = ? AND sent_at IS NULL');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) return false;
    try {
        rs_mail_send((string)$m['to_addr'], (string)$m['subject'], (string)$m['body'], json_decode((string)$m['opt'], true) ?: []);
        $db->prepare('UPDATE mail_queue SET sent_at = UTC_TIMESTAMP(), err = \'\' WHERE id = ?')->execute([$id]);
        return true;
    } catch (Throwable $e) {
        $tries = (int)$m['tries'] + 1;
        // 2, 4, 8 … minutes, at most an hour; given up after a day of trying.
        $wait = min(60, 2 ** min($tries, 6));
        $db->prepare('UPDATE mail_queue SET tries = ?, err = ?, next_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), sent_at = IF(? >= 30, \'1970-01-01\', NULL) WHERE id = ?')
            ->execute([min($tries, 99), mb_substr($e->getMessage(), 0, 300), $wait, $tries, $id]);
        rs_set_setting('mailq_pause_until', (string)(time() + 180));
        error_log('mailq: ' . $e->getMessage());
        return false;
    }
}

// A few from the front of the line. Called after page views; only one at a time runs.
function rs_mailq_run(int $max = 6): int {
    rs_mailq_tables();
    if (rs_mailq_paused()) return 0;
    $last = (int)rs_setting('mailq_ran_at', '0');
    if (time() - $last < 20) return 0;
    $db = rs_db();
    $got = (int)$db->query("SELECT GET_LOCK('rs_mailq', 0)")->fetchColumn();
    if ($got !== 1) return 0;
    $sent = 0;
    try {
        rs_set_setting('mailq_ran_at', (string)time());
        $ids = $db->query('SELECT id FROM mail_queue WHERE sent_at IS NULL AND next_at <= UTC_TIMESTAMP() ORDER BY prio ASC, id ASC LIMIT ' . max(1, min(20, $max)))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if (!rs_mailq_send_one((int)$id)) break;
            $sent++;
        }
        // Sent ones are kept two weeks for the admin's numbers.
        if (random_int(1, 50) === 1) $db->exec('DELETE FROM mail_queue WHERE sent_at IS NOT NULL AND sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)');
    } finally {
        $db->query("SELECT RELEASE_LOCK('rs_mailq')");
    }
    return $sent;
}

// Runs the line once this request's answer has gone to the visitor.
function rs_mailq_after(): void {
    static $set = false;
    if ($set) return;
    $set = true;
    // (3.1g) At most one queue run every 20 seconds, decided without the database.
    $stamp = sys_get_temp_dir() . '/rs-mailq-' . substr(md5(__DIR__), 0, 8);
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 20) return;
    @touch($stamp);
    register_shutdown_function(function () {
        try {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            rs_mailq_run();
        } catch (Throwable $e) { error_log('mailq run: ' . $e->getMessage()); }
    });
}

// For the admin: waiting, sent today, the newest error.
function rs_mailq_stats(): array {
    rs_mailq_tables();
    $db = rs_db();
    return [
        'waiting' => (int)$db->query('SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL')->fetchColumn(),
        'today' => (int)$db->query('SELECT COUNT(*) FROM mail_queue WHERE sent_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) AND sent_at > \'1971-01-01\'')->fetchColumn(),
        'failed' => (int)$db->query('SELECT COUNT(*) FROM mail_queue WHERE sent_at = \'1970-01-01\'')->fetchColumn(),
        'error' => (string)($db->query('SELECT err FROM mail_queue WHERE err <> \'\' ORDER BY id DESC LIMIT 1')->fetchColumn() ?: ''),
        'paused' => rs_mailq_paused(),
    ];
}
