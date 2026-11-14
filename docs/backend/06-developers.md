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
- Edit their page and their games' pages. These are **suggested changes** (`dev_changes`, status `pending`) the team approves, except a verified developer's own changes, which go out at once (`rs_pc_publish_one`).
- Run betas for their own games, see the docs (`dev_docs`), message the team.
- Submit a new game (it goes into Submissions like any other).

## Approving developers

Requests come from invites the team emailed (`dev_invites`) or from the developers page. The admin approves them on Developer accounts (`dev_accounts.status`). Approved developers get the Verified developer badge and the Developer role in Discord once they link Discord.

## Messages

`dev_messages` (`admin/messages.php`): each developer has one conversation with the team, shown in their hub; the Lounge is a room every approved developer shares. Messages from the Submissions drawer go here when the developer has an account, otherwise by email. `dev_broadcasts` are announcements shown to every developer.

## Things on the to-do list

- A developer's admin page should list the games linked to them; a game's edit page should show its developer near the top.
- The hub and game pages should show which launcher features each game supports or is missing (reusing the PR drafter's feature check, with "not needed" where it doesn't apply).
- Emailing a developer when their release gets flagged (repo gone, safety check), with a link to fix it.

Update this list as they're done.
