<?php
// (4.11) Launcher support > Guide: how this section works, in plain words, for whoever runs the PR queue.
// Included by sdk-support.php. Keep it in step with inc/sdk-queue-tab.php and docs/backend/08-sdk-and-pr-queue.md.
declare(strict_types=1);
?>
<section class="card g-card">
  <h2>What this section is for</h2>
  <p>A recomp works best in RecompLauncher when its release comes with a small file, <code>recomplauncher.json</code>. It tells the launcher how to set the game up from the player's own copy (Easy Setup), how to start it, and where its settings, saves and logs are. Without it, players get the basics: install, update and Play.</p>
  <p>This section gets that file to as many games as possible. The drafter (a bot) reads each project and writes the file and a pull request for it. You look it over and send it to the developer, who merges it. If a developer doesn't answer, we can use the file ourselves as <b>our profile</b>.</p>
</section>

<section class="card g-card">
  <h2>The tabs</h2>
  <dl class="g-dl">
    <div><dt>Games</dt><dd>Every game on RecompLauncher with a GitHub project, and whether it ships its own file. Tick some and press <b>Draft PRs now</b> to put them in the queue.</dd></div>
    <div><dt>PR queue</dt><dd>Where each pull request is, from "waiting to be drafted" to "sent". This is where you look drafts over and send them.</dd></div>
    <div><dt>Check a game</dt><dd>One game in detail: what players get right now, what's wrong and how to fix it, and test drafts with different models.</dd></div>
    <div><dt>Guide</dt><dd>This page.</dd></div>
  </dl>
</section>

<section class="card g-card">
  <h2>How a game moves through the PR queue</h2>
  <ol class="g-flow">
    <li><b>Waiting to be drafted.</b> It's been picked but not written yet. Every morning at 7:23 the drafter writes 8 (the ones you queued first, then new and popular games). You can also press <b>Draft now</b>.</li>
    <li><b>Drafting now.</b> It's being written. It takes a few minutes, and the page refreshes by itself.</li>
    <li><b>Ready for you.</b> It's written and waiting for you. <u>Nothing has been sent.</u> Open it and look it over.</li>
    <li><b>Going out.</b> You pressed Send. At most 5 go out an hour, so GitHub doesn't treat us as spam; you can still take it back.</li>
    <li><b>Sent.</b> The pull request is open on the developer's GitHub. It's up to them now.</li>
    <li><b>Developer replied.</b> The developer commented or reviewed and nobody on the team has answered since (checked every hour, hand-marked pull requests and issues too; every conversation, open or closed, is on the Replies tab). Answer on GitHub and it goes back to Sent by itself, or press <b>Mark answered</b> if you answered somewhere else.</li>
    <li><b>Skipped.</b> No pull request for this game. You skipped it, or the drafter did (it already has one, it's archived, or it isn't something it can draft). <b>Put it back</b> undoes it.</li>
  </ol>
  <p class="g-safe"><b>The rule:</b> the drafter only ever writes drafts. A pull request is sent when someone ticks <b>I've looked it over</b> and presses <b>Send pull request</b>, or, while <b>Send green drafts by themselves</b> is on (Settings, at the bottom), when the review marks it green: nothing missing or blocking, made by the new drafter, and no changes to the developer's code. Those go to Going out, 5 an hour. The server refuses to send a file the launcher would reject.</p>
</section>

<section class="card g-card">
  <h2>Looking over a draft</h2>
  <p>Each draft says one of three things at the top:</p>
  <ul class="g-list">
    <li><span class="pill ok">Ready to send</span> The file passes the launcher's rules and gives Easy Setup.</li>
    <li><span class="pill warn">Look before sending</span> It's fine to send, but something is missing (often Easy Setup) or worth a read. Read the yellow box.</li>
    <li><span class="pill bad">Can't send yet</span> The file has a problem the launcher would reject. Fix it under <b>Edit the text and the file</b>, or press <b>Make a new draft</b>. Send stays off until it's fixed.</li>
  </ul>
  <p>Then check, in this order:</p>
  <ol class="g-list">
    <li><b>What this pull request gives players.</b> A tick means it's in this PR, a cross means it isn't, a dash means the game doesn't need it. Easy Setup is the one that matters most.</li>
    <li><b>The drafter's notes.</b> What it wasn't sure of. If it says it guessed something, check it on their GitHub.</li>
    <li><b>The code changes</b>, when there are any. These are small additions to their code, like a <code>--game</code> option or a <code>--prepare</code> setup step. Nobody has built them, so the PR asks the developer to build before merging. If they look wrong or too big, untick <b>Include the code changes</b> and only the file and badge go.</li>
    <li><b>What the drafter found in their project</b> (optional): each feature it looked for, where it found it in their code, and why it did or didn't use it.</li>
  </ol>
  <p>Happy with it? Tick <b>I've looked it over</b>, press <b>Send pull request</b>, and confirm. The confirm box repeats what's in it.</p>
</section>

<section class="card g-card">
  <h2>What every button does</h2>
  <dl class="g-dl">
    <div><dt>Send pull request</dt><dd>Forks their project on our GitHub account, adds the file, the badge and any code changes, and opens the pull request. Only works after <b>I've looked it over</b> is ticked.</dd></div>
    <div><dt>Also use this file on RecompLauncher right away</dt><dd>When it's sent, players get the file straight away as our profile, before the developer merges. Code changes can't come this way, so it uses the version without them. Our older profile stays if the game has one.</dd></div>
    <div><dt>Save my edits</dt><dd>Keeps your changes to the title, description or file. Nothing is sent.</dd></div>
    <div><dt>Make a new draft</dt><dd>Throws this draft away and writes a new one from scratch with the current drafter. It takes a few minutes and costs about $0.34. Use it when a draft is from the old drafter, looks wrong, or the developer changed their project. It doesn't always find more, so read the new one too.</dd></div>
    <div><dt>Don't send one for this game</dt><dd>Moves it to Skipped, and it isn't drafted again (until you press Put it back).</dd></div>
    <div><dt>Draft now</dt><dd>Writes a waiting draft right away instead of at 7:23.</dd></div>
    <div><dt>Take it back</dt><dd>Stops a pull request in Going out before it's sent. It goes back to Ready for you.</dd></div>
    <div><dt>Redraft and update</dt><dd>For one already sent: writes it again with the current drafter, then lets you push that to the same pull request.</dd></div>
    <div><dt>Make new drafts for the old ones</dt><dd>Writes every draft from the old drafter again with the new one, in one go.</dd></div>
  </dl>
</section>

<section class="card g-card">
  <h2>When something goes wrong</h2>
  <dl class="g-dl">
    <div><dt>"Didn't draft" in Waiting</dt><dd>It says why, and what to do. Usually: press Draft now again later (GitHub's hourly limit, or a hiccup). If it fails twice, open <b>Its page</b> and run a test draft with another model.</dd></div>
    <div><dt>No Easy Setup in the draft</dt><dd>The game has no way to be handed the player's copy yet, and the drafter couldn't add one safely. You can still send it (players get settings, saves and logs), or open its page and test with Opus to see if a stronger model finds a way. Some games need a person to do it by hand.</dd></div>
    <div><dt>"Didn't send"</dt><dd>GitHub refused (it already has a RecompLauncher PR, the repo is archived, or our token stopped working). The red box says which.</dd></div>
    <div><dt>"Conflict, needs a look" in Sent</dt><dd>The developer changed their project and the PR couldn't be rebuilt by itself. Press <b>Redraft and update</b>.</dd></div>
    <div><dt>The developer said no</dt><dd>Mark it on the Games tab: pick the game, then Mark it → The developer doesn't want it. If the file is still useful, use it as our profile from the game's page.</dd></div>
  </dl>
</section>

<section class="card g-card">
  <h2>Check a game</h2>
  <p>Pick any game to see what players really get: which file the launcher uses (the developer's own, from their release, or our profile), each feature with a tick or a cross, and the fix for anything missing. It checks the file against the launcher's own rules and against the developer's download, and shows player reports.</p>
  <p><b>Test drafts</b> run the drafter on that one game with the models you tick, side by side, with what each cost. They're never saved to the queue or sent. If you like one, put it in the PR queue, use it as our profile, or send the file to the developer by message or email. A game that isn't on RecompLauncher yet can be looked up from there too.</p>
</section>

<section class="card g-card">
  <h2>What's automatic, and what it costs</h2>
  <ul class="g-list">
    <li><b>Automatic:</b> the 7:23 drafts (8 a day), sending the ones in Going out (5 an hour), and rebuilding sent PRs that conflict. Nothing is ever sent without someone pressing Send.</li>
    <li><b>Cost:</b> about $0.34 a draft with the models set now (Haiku reads the project, Sonnet writes any code changes). The daily run is about $3. Test drafts with Opus are about $1 each. Everything is counted on <a href="ai-usage.php">AI usage</a>, where the models can be changed.</li>
    <li><b>Our profile vs the developer's file:</b> a file in the developer's release always wins over our profile. So if our profile gives a game Easy Setup, make sure the PR's file has it too.</li>
  </ul>
</section>
