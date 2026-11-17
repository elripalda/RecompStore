# How games move through the admin

Everything to be listed ends up on the **Submissions** board (`admin/hub.php`, code in `inc/hub.php`, drawer in `inc/hub-drawer.php`, the How to approve tab in `inc/hub-guide.php`). The board is drawn from the database only, so it opens straight away; each card's checks are worked out after the page loads and kept.

## Where new projects come from

| Way in | What starts it | Lands in |
|---|---|---|
| A developer submits it | recompstore.com/submit or recomplauncher.com/submit (`api/submit.php`). A new PC submission starts Auto-add on arrival (setting `sub_autostart`, on by default, Settings > AI usage). | `inbox` → board column **New**, then **Checking** |
| The morning finder | Every morning at 6 am Phoenix, on the first admin page load after it (`rs_auto_morning_maybe` in `inc/autopilot.php`). Searches GitHub and pre-checks each hit. | **Found on GitHub** (`pc_found`, state `new`) |
| The page-load finder | Twice a week, after an admin page has gone (`rs_pc_find_maybe` in `inc/pc.php`). Also the **Find new** button on PC recomps. | **Found on GitHub** |
| Discord suggestions | Links posted in #recomp-suggestions (`rs_pc_find_discord`) | **Found on GitHub** |
| The daily scout | Cron at noon Phoenix (`inc/scout-run.php`). Sorts real recomps from emulators, tools, mods and fan games with the rules in `inc/rules.php` (Claude only when Settings > AI usage turns it on), then sends the picks through Auto-add. | **Auto-add** drafts (`pc_auto`), board column **Checking** / **Ready to publish** |
| Players ask for it | Request a Recomp on RecompLauncher's Coming Soon page (`api/pc.php a=recomp-request`, `inc/requests.php`) | **Requests** tab (`pc_requests`, most asked first) |
| Someone pastes a link | Check a link (top right of the board), the lookup box on Found on GitHub, or the admin search bar (see below) | Found on GitHub, or straight to Auto-add |

## Found on GitHub

`pc_found` rows, one per repo key, with state:
- `new`: waiting for someone. Buttons: **Add** (makes a hidden listing right away and opens it, `rs_pc_add`), **Auto-add** (runs the full checks, below), **Skip** (not a recomp or not good enough).
- `skipped`: hidden from the list. Looking it up again (lookup box or search bar) puts it back to `new`.
- `added`: we have it. A repo already listed never shows in the list; looking it up says "We already have this" and opens its page.

## Requests

One row per project with every player's ask under it. **Check it** runs the rule checks first (`rs_rules_check` in `inc/rules.php`, no AI): already listed → marked `listed`; an emulator, tool or fan game → marked `declined` with the reason; a repo that can't be read stays on the list with the reason; a recomp with no Windows download yet → put on Coming Soon; anything else goes to Auto-add (`rs_auto_start`) and the request is marked `added`. **Already listed** / **Mark done** close it by hand. Players ask for all kinds of things, so it still waits for someone to press Check it.

## Auto-add (Checking → Ready to publish)

`inc/autoadd.php`, page `admin/auto.php`, background run `inc/auto-run.php`. Given a link, the server does what we'd do by hand, one step at a time, each step saved on the draft:

1. Reads the repo and its newest release.
2. Makes the listing, hidden (one already in the catalog is left alone).
3. Downloads the release and looks inside for game files or emulator wrappers; runs ClamAV.
4. Puts the files in line for VirusTotal.
5. Writes the name, game, origin, blurb, page text and tags. By default this is done by rules (`rs_rules_fill` in `inc/rules.php`): the submission's own answers first, then the README's title and first paragraphs, console names for the origin. The page says "Written from the README, without AI". **Write the page with AI** in the drawer's More menu redoes it with Claude (the "on demand" model, Haiku by default). Fills the AI label.
6. Picks the cover, banner and logo (SteamGridDB), game info (IGDB).
7. Works out Easy Setup and launcher settings (the boot profile).

`pc_auto.status`: `queued` → `running` → `review` (shown as **Ready to publish**) or `failed`. Then `published`, `dismissed` or `shelf-*`. `verdict` is what it suggests: `good`, `check` (look it over) or `skip` (a check failed: VirusTotal, ClamAV or the download). A project that's the same game as one already listed (another developer's take) is not held back for it.

**When it fails or needs a look**, the drawer shows **Ask the developer** (their hub message, a prepared email, a GitHub issue, the repo). A failure because the newest release has no Windows download gets **Add to Coming Soon** (`job_soon`: a Coming Soon listing from the submission or repo, the draft dismissed, the submission marked done) and **Check again**. Other failures have Coming Soon under More.

**Approve and replace** (`replace` in `hub.php`, `rs_auto_replace` in `inc/autoadd.php`): when a submission's repo is already a hidden or Coming Soon listing, the submission card says "Listed already" instead of a red check, and its main button is **Approve and replace** (also on a draft that stopped as "Already on RecompLauncher as … (hidden / on Coming Soon)"). The listing keeps its id, address and downloads; the draft runs again on it (`app_id` set, `made` 0 so removing the draft never deletes the listing, kv `auto_replace_<draft id>`), takes the newest release's download patterns, version and size, clears "not compatible", and is filled in from the submission like any draft. It goes out on Publish, which also clears Coming Soon. When the project already has an open draft (an earlier submission of it), the button reads **Open its draft**: the newer submission takes the draft over (`rs_auto_sub_takeover`) and the older one is marked done as sent again; a draft that's ready isn't run again. `rs_auto_start` only hands a draft to a newer submission, so an older one never takes it back (that used to bounce the newer card back to New). A live listing is never replaced this way. A Coming Soon listing doesn't count as live, so a submission for it isn't closed by itself (`rs_sub_close_live`).

**Publish** on a draft (`rs_auto_publish`) shows the listing and publishes it. A file VirusTotal flagged keeps it hidden until someone decides on the Security page.

Minor VirusTotal flags (yellow) put a round check on the card. Clicking it (`vt_ok` in `hub.php`) marks that release fine: kv `vtok:<md5 of repo|tag>` with who and when (`rs_hub_vtok`), logged under security. A draft whose only warning was those flags then reads **Ready to publish** (`rs_hub_job_state`). A new release needs a new look.

The drawer's **Make its recomplauncher.json** opens Launcher support > Check a game for the submission (`?tab=check&sub=<id>`), see `08-sdk-and-pr-queue.md`.

## Submissions (the board)

PC: **New** → (Approve and check) → **Checking** (Auto-add's run) → **Ready to publish** → (Publish) → live, off the board.
**Ask for changes** sends it to **Waiting on the developer**; it comes back to New when they send it again (off the board after 45 days of silence). **Decline** closes it.

**Drafts waiting on the developer (4.13).** A draft in Ready to publish that we've asked its developer about (the drawer's **Asked them, wait**, or sending the VirusTotal note from a submission) moves to Waiting on the developer: kv `hubask:job:<id>` = {at, by, vt, tag} (`rs_hub_ask*` in `inc/hub.php`). One asked about VirusTotal comes back to Ready by itself once the scan no longer flags it; **Back to Ready** (`unasked`) brings it back any time. After 14 days (`RS_HUB_ASK_DAYS`) the card says there's been no answer. Ready to publish lists the oldest first, and a card there over a day old shows its age in red. When VirusTotal flagged it, the drawer's Email and Open an issue links carry the full report (`rs_hub_vt_note`: each flagged file, what flagged it and its report link), and so do the GitHub links on Security's held updates (`rs_pc_contact_links($a, $why, $note)`).
PS5: New → Draft made (hidden, in PS5 games) → the owner signs and publishes the PS5 store → live.

After a PC submission goes live, the developer should get the "you're live" email. The strip on top of the board lists the ones still waiting: **Send**, **Edit** first, **Skip**, or send them all at once. It isn't sent by itself so a person can check the wording and the address first.

**Builds are there** (`rs_hub_check`): the files a developer named are looked for in the newest release. When one is missing but that release still has a build for the same system (an installer swapped for a portable zip), the check says "File name changed" in orange with the new name instead of a red "Files not in the newest release", since Auto-add reads the release itself and takes the new file.

**Sent marks** (4.13): a card shows a mail or chat icon with a green check, top right, once its developer was emailed, messaged in their hub, or marked "Asked them, wait" from the board (`rs_hub_reach_add`, kv `hubreach:<item>`; emails to the submission from before count too, from `mail_sent`). Hover says when and who.

Anything handled leaves the board at once (it's under **Handled**). Always mark a submission done when it's handled (added, duplicate, already listed, declined).

## Why is it hidden?

`rs_pc_hidden_why($a)` gives the reason in one line, shown when you look a repo up:
- **Not compatible yet**: `blocked_why` is filled in (it shows under its own filter).
- **Hidden by the repo watch**: its repo went down (`watch_hid`).
- **Auto-add**: still being checked, waiting for someone to Publish, or failed.
- **Added but never shown**: made by hand or by Add and nobody has ticked **Show it on the site** and published yet (`live_at` empty).
- **Hidden by hand**: it was live before.

## Looking up a repo from the search bar

Paste a GitHub, GitLab, SourceForge or Codeberg link (or `owner/name`) into the admin search bar. `admin/find.php` calls `rs_pc_repo_status()` (no network) and shows a "This repo" group: already listed (live, or hidden and why), on Auto-add, waiting in Found on GitHub, skipped before, or new. For a new or skipped one, the result's link puts it into Found on GitHub (`pc.php?lookup=...&t=<csrf>`).

## The counters in the sidebar

`layout.php` works them out (`$GLOBALS['rs_nav_counts']`):
- **Submissions** = cards on the board that need a person + open flags + repo watch alerts. A submission and its draft count once; a submission whose checks are still running, or whose draft was dismissed or shelved, doesn't count. Player requests have their own count on the Requests tab and aren't in this one.
- **Auto-add** = drafts waiting + requests.
- **Inbox & logs** (`rs_inbox_new_count`) = messages, bug reports, copyright and source notices still `new`. Players' logs (hundreds a week, each carrying the launcher's own crash summary) only count on the Logs tab.
