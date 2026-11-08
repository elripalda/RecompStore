# Security

## Reporting a problem

If you find a security issue in RecompStore or recompstore.com, please don't post it in Issues. Report it privately here instead:

https://github.com/elripalda/RecompStore/security/advisories/new

Tell me what you found, how to reproduce it, and which version you're on. I'll get back to you as soon as I can and credit you in the release notes once it's fixed, unless you'd rather stay anonymous.

## Supported versions

Fixes go into the latest release of RecompStore. Please update before reporting.

## How downloads are checked

- RecompStore doesn't host any games or game files. Every download comes straight from the developer's own GitHub or GitLab release.
- New submissions and every new release of a listed game are scanned on VirusTotal. Files too big for VirusTotal are scanned with ClamAV.
- A release from a developer who isn't verified is held until its scan is done. Anything flagged by several engines stays held until I check it by hand.
- Releases are checked for files that look like game data, and those don't get listed.
- Players bring their own copy of every game.

If you think a listed download is unsafe, report it the same way, or tell me on Discord.
