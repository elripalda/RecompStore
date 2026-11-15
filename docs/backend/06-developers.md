# Developers

Two separate things that work together:

- **Developer pages** (`developers` table, `inc/pages.php`, admin page `admin/developers.php`): a public page per developer (`/dev/<slug>`) with their games, links and a Verified badge. The catalog carries a `developers` list (`slug`, `name`, `verified`, `avatar`) and each game's `dev` slug.
- **Developer accounts** (`dev_accounts` and friends, `inc/devhub.php`, admin page `admin/dev-accounts.php`): a developer signs in with GitHub (public profile only, no access to their repos) and gets the developer hub at recomplauncher.com/dev (same hub as recompstore.com/hub, `api/dev.php`).

## How a game gets linked to a developer

- The game's `dev` field holds the developer's slug. It's set on the game's edit page, matched from the Author name (the developer's name, page name or other names), or by the developer claiming it.
- A developer account's games (`rs_dev_pc_apps`) are the ones whose GitHub repo belongs to them or one of their public orgs, plus any game whose `dev` is their slug. GitLab projects are theirs only through `dev`.
- **Claims** (`dev_claims`): a developer asks for a game that isn't on their GitHub. It waits as `pending` until the team approves it (`rs_dev_claim_apply`). Ones on their own GitHub are theirs straight away.
- Renaming a developer's slug updates every game that pointed at the old one.

## What a developer can do in the hub

- See their games with numbers (downloads, player reports, logs players sent).
- See where each game's newest release stands (`rs_pc_update_status`: out, being scanned, going out, waiting for a look, or no matching file) and press **Check for my new release now** (`api/dev.php a=pc-check`, a few an hour per game), which reads their repo straight away and gives the scan a turn.
- See which launcher features each game has (`rs_pc_feature_list`, built from the catalog's `support` list: Easy Setup, settings, save backups, setup step, checks the player's copy, mods), marked as from their own recomplauncher.json or set up by our profile for now, with a link to the SDK for the rest. The public game page shows the ones it has as **In RecompLauncher**.
- Edit their page and their games' pages. These are **suggested changes** (`dev_changes`, status `pending`) the team approves, except a verified developer's own changes, which go out at once (`rs_pc_publish_one`).
- Run betas for their own games, see the docs (`dev_docs`), message the team.
- Submit a new game (it goes into Submissions like any other).

## Approving developers

Requests come from invites the team emailed (`dev_invites`) or from the developers page. The admin approves them on Developer accounts (`dev_accounts.status`). Approved developers get the Verified developer badge and the Developer role in Discord once they link Discord.

## Messages

`dev_messages` (`admin/messages.php`): each developer has one conversation with the team, shown in their hub; the Lounge is a room every approved developer shares. Messages from the Submissions drawer go here when the developer has an account, otherwise by email. `dev_broadcasts` are announcements shown to every developer.

The admin inbox (shared code in `inc/devmsg.php`):
- **Waiting for reply**: the developer wrote last (`rs_msg_threads`). It goes away when the team answers.
- **Dismissed** (`dev_threads.dismissed`): put away, back in the inbox as soon as either side writes (`rs_dev_msg_add`). The Dismissed tab also lists every developer with no messages yet. "Dismiss all that aren't waiting on you" clears the rest at once.
- **New message** searches every developer account.
- **Live**: `assets/messages.js` polls `messages.php?poll=1` every 5 seconds while the tab is open, sends without reloading, and pings softly when a developer writes. The dev hub polls `a=msgs` / `a=lounge` every 6 seconds.
- **Seen**: `read_dev_at` is set the first time the developer's hub fetches the team's message. Only the team sees it; developers never see when the team read theirs.
- **Files**: up to 4 a message, 15 MB each, 30 MB together: pictures (PNG, JPG, GIF, WebP), videos (MP4, WebM, MOV), text and logs, ZIP and 7z (`RS_MSG_TYPES`, checked by extension and content). Kept in `recompstore-private/msg-files/<yyyymm>/` and listed in `dev_msg_files`; the message keeps a short list in `dev_messages.files`. Served by `admin/msg-file.php` for the team and `api/dev.php?a=msg-file` for the developer (their own conversation, or a Lounge channel they can read). Pictures and videos show in the message; everything else downloads.
- The Lounge reads like a Discord channel in both places: a picture and name once, then that person's next messages under it, days between.

**Dev dashboard** (top bar of the admin): a team member whose Team card has a GitHub username (Team > Manage > On the website) matching a developer account (`rs_msg_my_dev`) gets a button that opens the developer hub signed in as that account (`admin/dev-switch.php`, through the one-time `a=handoff`). The owner sets those GitHub names, so nobody links themselves to someone else's account.

## Emails we send developers on our own

`rs_pc_dev_notify` (`inc/pc.php`) emails the developer (their approved account's address, else the submission's contact) through the mail queue, once per reason (a `kv` key):
- a release held because several VirusTotal engines flagged a file (`vt:<tag>`, from `inc/vt.php`)
- their repo went down, private or lost its releases (`watch:<state>:<month>`, from `inc/repowatch.php`)

Each says what happened and how to fix it, with a link to their hub.

## In the admin

A developer's page in the admin (`developers.php?edit=`) lists **Connected to** them: PC recomps with their `dev` slug or on their approved account's GitHub, and PS5 apps. A game's edit page shows its developer (with Verified) or author at the top.

## Things on the to-do list

- Use the PR drafter's feature check (`found.features`, with "not needed") for games that have no recomplauncher.json yet, so the checklist can say what doesn't apply.

Update this list as they're done.
