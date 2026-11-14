# RecompStore / RecompLauncher backend notes

How the website and admin behind recompstore.com and recomplauncher.com work, so you don't have to read the code to find your way around. Each file covers one area and is short enough to read in one go. Start here, then open only the file you need.

| File | What's in it |
|---|---|
| [01-layout.md](01-layout.md) | Where the code lives, the two sites, the server, config, how a change gets deployed |
| [02-data.md](02-data.md) | The database tables by area, the files the site writes, caches |
| [03-pc-catalog.md](03-pc-catalog.md) | A PC recomp's record, Publish, pc-catalog.json and what the launcher reads in it |
| [04-how-games-move.md](04-how-games-move.md) | Found on GitHub, Requests, Submissions, Auto-add, Ready to publish, live, and why something is hidden |
| [05-checks.md](05-checks.md) | VirusTotal, ClamAV, repo watch, the download check, moved repos, AI labels |
| [06-developers.md](06-developers.md) | Developer accounts, claims, the dev hub, changes they suggest, messages |
| [07-launcher-api.md](07-launcher-api.md) | Every endpoint RecompLauncher calls, and what each returns |
| [08-sdk-and-pr-queue.md](08-sdk-and-pr-queue.md) | SDK support, boot profiles, the PR drafter |
| [09-background-jobs.md](09-background-jobs.md) | Cron jobs, the GitHub Action, what runs on page loads |
| [10-extras.md](10-extras.md) | Trending, Emergency backup, Instant Gaming, sponsors, achievements |
| [11-website.md](11-website.md) | The public site: one page app, page.php, caches, Cloudflare |

## The rule: keep these up to date

Any change to how an area works updates its file here **in the same commit**. New table, new endpoint, new cron job, a game moving through the admin differently, a new field in the catalog: write it down in the file for that area. If no file fits, add one and list it above. Keep each file under about 10,000 characters; split it when it grows past that.

Write it plainly, the way you'd explain it to someone joining the team: what it does, where the code is, what to watch out for. No secrets in here (keys, passwords, tokens): this repo is public.

## Words used in these notes

- **Recomp**: a native PC (or PS5) build of a console game. Players bring their own copy.
- **Listing / app**: one recomp as the store has it (`pc_apps` row). Several listings can share a **title** (the game itself).
- **Publish**: writing the catalog files the site and launcher read. Most edits in the admin only go live on the next Publish.
- **Hidden**: the listing exists in the admin but isn't in the published catalog.
- **Owner**: Ruben. Some actions (settings, logins, signing the PS5 store) are owner only.
