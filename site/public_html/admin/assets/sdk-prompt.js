// (4.6) The agent prompt and the README badge, for SDK support and a recomp's edit page.
// A button with data-prompt-repo (owner/name), data-prompt-url (its project page), data-prompt-game
// and data-prompt-id (empty when it isn't on RecompLauncher yet) copies the prompt. One with
// data-badge-id copies just the badge.
(function () {
  var BASE = 'https://recomplauncher.com/assets/press/launcher/';
  function badge(id) {
    var href = id ? 'https://recomplauncher.com/game/' + id : 'https://recomplauncher.com';
    return '<a href="' + href + '"><picture><source media="(prefers-color-scheme: dark)" srcset="' + BASE + 'badge-light.svg"><img alt="Get it on RecompLauncher" src="' + BASE + 'badge-dark.svg" height="48"></picture></a>';
  }
  function prompt(repo, url, game, id) {
    return [
      'Add RecompLauncher support to ' + repo + (game ? ' (a PC recomp of ' + game + ')' : '') + ', ready to send as a pull request. The project: ' + url,
      '',
      '1. Read all of https://recomplauncher.com/sdk-files/recomplauncher-agent.md before changing anything. It has the steps, the rules, the full SDK guide with the Recomp Standards, worked examples and the JSON schema. If you can\'t open links, stop and ask me to paste it in.',
      '2. Check the repo\'s open pull requests and issues first. If someone is already adding recomplauncher.json, stop and tell me.',
      '3. Follow the file: recomplauncher.json with every level that fits, shipped in every release (zip and AppImage); the small, safe Recomp Standards fixes (the standard file formats for its console, like CHD, a --game option, --keys where the player\'s own keys are needed); and the badge. If it supports DLSS but its releases leave out nvngx_dlss.dll, add the optional nvidia.dlss input (role runtime, destination nvngx_dlss.dll) and say in the "For the developer" note that it needs RecompLauncher 0.90 Beta 37 or newer. If the release carries several games (a trilogy, a bundle), list them in "games" (id, name, and args when the recomp has an option that starts one game directly).',
      '4. The badge goes near the top of the README. ' + (id
        ? 'This recomp is on RecompLauncher, at https://recomplauncher.com/game/' + id + '. Use exactly:'
        : 'This recomp isn\'t on RecompLauncher yet, so the badge links to the site. Use exactly:'),
      '',
      badge(id),
      '',
      '5. Code changes stay opt-in (the game starts exactly as before without the new arguments), small, in the project\'s style and in their own commit. Never add game files or keys.',
      '6. The pull request description, short and friendly, in this order:',
      '   - What it adds for players.',
      '   - The code changes, listed separately, noting they only apply when the launcher passes the new arguments.',
      '   - "Things that would make your recomp better": from the report, one line each.',
      '   - "For the developer": the report\'s "Make it yours on RecompLauncher" part, two or three sentences, not a sales pitch.' + (id ? '' : ' Mention it can be listed on RecompLauncher once it has a release.'),
      '',
      'When you\'re done, give me the whole report, the pull request description and the branch name.'
    ].join('\n');
  }
  // (4.6) Xarishark's prompt: a pull request that adds the badge to the README of a recomp that already
  // ships its own recomplauncher.json. Its page on RecompLauncher is known here, so it's filled in.
  function badgePr(repo, id, game) {
    var page = 'https://recomplauncher.com/game/' + id;
    return [
      'Open a pull request on the GitHub repo given at the bottom of this prompt that adds the "Get it on RecompLauncher" badge to its README.',
      '',
      'Steps:',
      '1. The project\'s page on RecompLauncher is ' + page + (game ? ' (' + game + ')' : '') + '. Check that it really exists and matches this repo. If it doesn\'t, stop and ask me. Never fall back to the site root link. The badge, as the press page (https://recomplauncher.com/press) gives it for this app:',
      '   [![Get it on RecompLauncher](' + BASE + 'badge-dark.svg)](' + page + ')',
      '2. Read the repo\'s README (the file name may be lowercase or .md/.rst), plus CONTRIBUTING.md if there is one. Follow its conventions. If the repo says not to open PRs like this, stop and tell me.',
      '3. Fork the repo, clone it into the scratchpad directory and make a branch called `readme-recomplauncher-badge`. Never touch my working repo.',
      '4. Add the badge near the top of the README: under the title and nav links, or next to any existing badges. Match the existing style, for example a centered `<p align="center">` block if the README is centered, with HTML if the README is HTML-based. Keep the file\'s line endings. Make no other change.',
      '5. Commit with the message "README: add Get it on RecompLauncher badge". Add no Claude attribution: no Co-Authored-By trailer and no "Generated with Claude Code" line.',
      '6. Push to my fork and open the PR against the default branch. The title is the same as the commit message. The body is short and friendly, and says:',
      '   - the project is on RecompLauncher, so this adds the official badge linking to its page (include the link);',
      '   - a one-line note that nothing else changed;',
      '   - an explicit note: "Feel free to move the badge anywhere in the README you prefer, or drop it entirely if you\'d rather not have it. No hard feelings."',
      '   Do not add a Claude attribution line to the PR body either.',
      '7. Give me the PR link and the badge link you used. Clean up the scratchpad clone when done.',
      '',
      'Repo: https://github.com/' + repo
    ].join('\n');
  }
  function copy(b, text) {
    var label = b.textContent;
    var done = function (ok) { b.textContent = ok ? 'Copied' : 'Couldn\'t copy'; setTimeout(function () { b.textContent = label; }, 2500); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); }); else done(false);
  }
  document.addEventListener('click', function (e) {
    var bp = e.target.closest('[data-badgepr-repo]');
    if (bp) { e.preventDefault(); copy(bp, badgePr(bp.getAttribute('data-badgepr-repo'), bp.getAttribute('data-badgepr-id'), bp.getAttribute('data-badgepr-game') || '')); return; }
    var b = e.target.closest('[data-badge-id]');
    if (b) { e.preventDefault(); copy(b, badge(b.getAttribute('data-badge-id'))); return; }
    b = e.target.closest('[data-prompt-repo]');
    if (!b) return;
    e.preventDefault();
    var repo = b.getAttribute('data-prompt-repo');
    copy(b, prompt(repo, b.getAttribute('data-prompt-url') || 'https://github.com/' + repo, b.getAttribute('data-prompt-game') || '', b.getAttribute('data-prompt-id') || ''));
  });
})();
