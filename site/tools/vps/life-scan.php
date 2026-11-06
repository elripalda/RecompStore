<?php
// Read-only: for every listed PC recomp, is its project archived on GitHub, and does its README say it's
// ended, shelved or moved? Prints one line per hit: id | archived | last release | what the README says.
// Run as www-data: sudo -u www-data php life-scan.php > life.txt
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/pc.php';

$re = '/(no longer (?:actively )?(?:maintained|being (?:maintained|developed|updated|worked on)|in (?:active )?development|supported|under (?:active )?development|updated)'
    . '|(?:project|development|repo(?:sitory)?|port|work)\s+(?:is|has been|was)\s+(?:now\s+)?(?:officially\s+)?(?:abandoned|discontinued|archived|shelved|on hold|paused|dead|cancell?ed|ended|frozen|halted|stopped|deprecated|retired|finished|complete)'
    . '|\b(?:unmaintained|abandoned|discontinued)\b'
    . '|(?:indefinite |on )hiatus|put on hold|on indefinite hold'
    . '|(?:won\'t|will not|will no longer) (?:be )?(?:receiv\w+ )?(?:any )?(?:further |more |future )?(?:updates|updated|maintained|developed)'
    . '|development has (?:stopped|ended|ceased|halted)|end of life|superseded by|no further (?:updates|development|releases)'
    . '|(?:moved|migrated|continued) (?:to|at|over to) (?:a new|https?:\/\/|\[))/i';

$n = 0;
foreach (rs_pc_apps(false) as $a) {
    if ($a['repo'] === '' || rs_pc_host($a) === 'gitlab') continue;
    $n++;
    $i = rs_gh_cached($a['repo'] . '#pcinfo', rs_gh_api() . '/repos/' . $a['repo'], 86400, fn(array $j): array => [
        'full_name' => (string)($j['full_name'] ?? ''), 'name' => (string)($j['name'] ?? ''), 'description' => (string)($j['description'] ?? ''),
        'stars' => (int)($j['stargazers_count'] ?? 0), 'topics' => array_values(array_map('strval', (array)($j['topics'] ?? []))),
        'owner' => (string)($j['owner']['login'] ?? ''), 'avatar' => (string)($j['owner']['avatar_url'] ?? ''), 'homepage' => (string)($j['homepage'] ?? ''),
        'archived' => (bool)($j['archived'] ?? false), 'fork' => (bool)($j['fork'] ?? false),
        'issues' => (int)($j['open_issues_count'] ?? 0),
        'license' => is_array($j['license'] ?? null) && ($j['license']['spdx_id'] ?? 'NOASSERTION') !== 'NOASSERTION' ? (string)$j['license']['spdx_id'] : '']);
    $info = json_decode((string)$i['body'], true) ?: [];
    $arch = !empty($info['archived']);
    $text = (string)($info['description'] ?? '') . "\n" . rs_pc_readme($a['repo'])['md'];
    $hits = [];
    if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) foreach (array_slice($m[0], 0, 3) as [$w, $at])
        $hits[] = preg_replace('/\s+/', ' ', substr($text, max(0, $at - 70), strlen($w) + 140));
    if ($arch || $hits) echo $a['id'], ' | ', $arch ? 'ARCHIVED' : '-', ' | ', $a['released'] ?: '?', ' | ', implode(' ## ', $hits), "\n";
}
fwrite(STDERR, "checked $n\n");
