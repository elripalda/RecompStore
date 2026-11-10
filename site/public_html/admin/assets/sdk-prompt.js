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
      '3. Follow the file: recomplauncher.json with every level that fits, shipped in every release (zip and AppImage); the small, safe Recomp Standards fixes (the standard file formats for its console, like CHD, a --game option, --keys where the player\'s own keys are needed); and the badge.',
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
  function copy(b, text) {
    var label = b.textContent;
    var done = function (ok) { b.textContent = ok ? 'Copied' : 'Couldn\'t copy'; setTimeout(function () { b.textContent = label; }, 2500); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); }); else done(false);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-badge-id]');
    if (b) { e.preventDefault(); copy(b, badge(b.getAttribute('data-badge-id'))); return; }
    b = e.target.closest('[data-prompt-repo]');
    if (!b) return;
    e.preventDefault();
    var repo = b.getAttribute('data-prompt-repo');
    copy(b, prompt(repo, b.getAttribute('data-prompt-url') || 'https://github.com/' + repo, b.getAttribute('data-prompt-game') || '', b.getAttribute('data-prompt-id') || ''));
  });
})();
