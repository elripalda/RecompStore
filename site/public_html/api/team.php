<?php
// (4.8) recomplauncher.com/team: the team (as the owner set it on the admin's Team page) and everyone
// who has worked on RecompLauncher's code (GitHub, kept six hours). Cached ten minutes.
//   GET -> {team: [{name, title, github, discord, about, avatar}], people: [{login, avatar, commits}]}
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/teampub.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$team = [];
$people = [];
try { $team = rs_tp_members(); } catch (Throwable $e) { /* the page says it couldn't load */ }
try { $people = rs_tp_people(); } catch (Throwable $e) { /* the same */ }
// A team member who is also in the contributors list: GitHub's own picture address from it (no redirect).
// A RecompLauncher account's own picture (merged admin login) stays as it is.
foreach ($team as &$m) foreach ($people as $p) if (str_starts_with($m['avatar'], 'https://github.com/') && strcasecmp($m['github'], $p['login']) === 0 && $p['avatar'] !== '') $m['avatar'] = preg_replace('/([?&])s=\d+/', '$1s=192', $p['avatar']);
unset($m);
header('Cache-Control: public, max-age=600, s-maxage=600, stale-while-revalidate=3600');
echo json_encode(['team' => $team, 'people' => $people], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
