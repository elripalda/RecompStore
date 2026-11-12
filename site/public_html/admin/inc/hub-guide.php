<?php
// (4.8) The Submissions hub's How to approve tab: the flow, the rules, and how each part of the hub works.
// Written for a game curator's first week; everyone else can skim it.
declare(strict_types=1);
$pcFlow = [['1', 'New', 'A developer sends it in. The hub checks it right away: is it theirs, are the builds there, is it safe, is it listed already, is its page filled in.', 'You: look it over'],
    ['2', 'Checking', 'After you approve, the site downloads it, looks inside for game files, runs ClamAV and VirusTotal, picks the art and writes its page.', 'Automatic, a few minutes'],
    ['3', 'Ready to publish', 'Everything is filled in and its listing is made, still hidden. Read its page in the drawer.', 'You: one more look, then Publish'],
    ['4', 'Live', 'It\'s on RecompLauncher and leaves the board. Send the developer the "you\'re live" email that opens next.', 'Done']];
$ps5Flow = [['1', 'New', 'A developer sends a PS5 app in. The same checks run: theirs, builds, safe, not listed, its page and its title ID.', 'You: look it over'],
    ['2', 'Draft made', 'Approve adds it to PS5 games as a hidden draft, with its icon and pictures.', 'Automatic'],
    ['3', 'Signed and published', $owner . ' gives it its real title ID if it needs one, signs it and publishes the PS5 store.', $owner],
    ['4', 'Live', 'It\'s on RecompStore and leaves the board.', 'Done']];
$flow = $store === 'ps5' ? $ps5Flow : $pcFlow;
$doc = [
    ['The board', [
        'Each column is a stage. Cards move left to right on their own as things happen, and anything handled leaves the board right away (it\'s under Handled).',
        'Click a card to open it. The drawer has its checks, what they sent, the conversation and the buttons.',
        'The line on each card is the most important thing about it: green is ready, orange needs a look, red stops it, blue is still being checked.',
        'The badge next to a name means a verified developer: someone we know, with a developer account linked to their GitHub. Use the Verified developers filter to see only them.',
        'Check a link (top right) runs the same checks on any GitHub, GitLab or SourceForge project, as if it had been submitted.',
    ]],
    ['The buttons', [
        'Approve and check: sends it to Checking. Nothing goes live yet.',
        'Ask for changes: writes to them with what to fix and a link that opens their submission again. It waits under "Waiting on the developer" and comes back to New when they send it.',
        'Decline: for things that can\'t be fixed. They get an email if you tick it.',
        'Publish (on Ready to publish): puts it live on RecompLauncher. Click twice to be sure.',
        'Run again: starts the checks over (for one that stopped or that the developer updated).',
        'Remove: takes the draft off the board. If it came from a submission, the submission goes back to New.',
        'The ••• menu has Mark done without an email (duplicates, spam), Move to the other store, and more.',
    ]],
    ['Talking to developers', [
        'Note for the team: only the admin team sees it. Use notes to leave context for whoever looks next.',
        'Message: goes to the developer. If they have a developer account it lands in their hub (and you can email them that it\'s there); otherwise it\'s an email.',
        'Their answers show in the drawer\'s conversation, and every developer conversation is on the Messages tab.',
        'Keep it short and friendly. Say what to change, not how we found out.',
    ]],
    ['The other tabs', [
        'Requests: what players asked for from RecompLauncher\'s Coming Soon page, most asked first. Check it runs the checks on that project; Already listed and Mark done close it.',
        'Found on GitHub: projects the finder and the daily scout came across. Check it or Skip.',
        'Developer requests: changes developers made to their own pages and projects they say are theirs. Approve what\'s right, turn down what isn\'t.',
        'Security: VirusTotal results for every download, and updates held until they\'re checked.',
        'Flags: your questions for ' . $owner . ' and the answers.',
    ]],
];
?>
<div class="sh-guide">
  <section class="sh-flow">
    <?php foreach ($flow as $i => [$n, $t, $dsc, $who]): ?>
    <div class="sh-fstep<?= $i === 3 ? ' live' : '' ?>"><span class="sh-fn"><?= $n ?></span><b><?= h($t) ?></b><p><?= h($dsc) ?></p><small><?= h($who) ?></small></div>
    <?php if ($i < 3): ?><span class="sh-farrow" aria-hidden="true">→</span><?php endif; ?>
    <?php endforeach; ?>
  </section>
  <div class="sh-rules">
    <section class="card sh-rule ok"><h3><?= HUB_IC['check'] ?>Approve when</h3><ul><li>It's a PC build of a real console or arcade game: a recomp, decomp or port<?= $store === 'ps5' ? ' (or a homebrew app made for PS5)' : '' ?></li><li>The person who sent it owns the repo, or it's their team's</li><li>Its newest release has the files they named</li><li>It asks players for their own copy of the game</li></ul></section>
    <section class="card sh-rule warn"><h3><?= HUB_IC['warn'] ?>Ask for changes when</h3><ul><li>The files don't match the release, or there's no release yet</li><li>No screenshot of it running, or no icon</li><li>No email to reach them</li><li>The description is empty or copied from somewhere else</li></ul></section>
    <section class="card sh-rule bad"><h3><?= HUB_IC['x'] ?>Decline when</h3><ul><li>It includes game files, ROMs or keys</li><li>It's not a game (a tool, a launcher, a mod manager)</li><li>It's a copy of another project passed off as theirs</li><li>VirusTotal flags it and the developer can't explain why</li></ul></section>
    <section class="card sh-rule flag"><h3><?= HUB_IC['flag'] ?>Flag for <?= h($owner) ?> when</h3><ul><li>You're not sure, or it doesn't fit these rules</li><li>A developer is upset or asks for something special</li><li>Anything about money, sponsors or copyright notices</li><li>Something on the site looks broken</li></ul></section>
  </div>
  <h2 class="sh-h2">Using the hub</h2>
  <div class="sh-doc">
    <?php foreach ($doc as [$t, $lines]): ?>
    <section class="card"><h3><?= h($t) ?></h3><ul><?php foreach ($lines as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul></section>
    <?php endforeach; ?>
  </div>
</div>
