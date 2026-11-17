# Checks: safety, repos and downloads

## VirusTotal (`inc/vt.php`, page `admin/security.php`)

What gets checked on its own:
- a submission's release files, as soon as it arrives
- each PC recomp's and PS5 app's newest release files
- anything pasted into Check a link on the Security page

Files are found by their SHA-256 (GitHub publishes it per release file); only files VirusTotal has never seen are downloaded and sent. The free key allows 4 requests a minute and about 480 a day here, with 100 a day (over all keys) kept for submissions and Check a link. Everything waits its turn in `vt_files`; `vt-run.php` can work the line from the command line.

**Keys** (4.13, Security > VirusTotal keys): the owner's main key (secret `vt_key`) plus keys team members add with **+ Add a key** (secret `vt_keys`, a JSON list of id, owner user id, name, label, key; tested before it's saved, at most 10). Each key belongs to a team member or to nobody (**Backup only**); the owner picks whose it is when adding it and can change it later from the list. Held updates on Security say when their check starts if they're waiting for their day, and how long they've waited otherwise. Each key keeps its own count (kv `vt_calls` for the main key, `vt_calls:<id>` for the others). Order (`rs_vt_keys`): the signed-in member's own key first, so what runs while they work uses theirs; then the main key; then the others as a backup when the main key's day is used up. A big file's upload link and its upload use the same key. Only the owner or whoever added a key can remove it.

**Updates** (4.13, Xarishark): VirusTotal first, always. An update only falls back to ClamAV as the last resort, after waiting 6 hours for VirusTotal (`RS_VT_CLAM_AFTER`); a ClamAV result still counts as checked and players see Verified, just without the report link. One update per game is checked a day: when one goes out (`vt_out_at` on the listing), the next one's files wait until a day after (`rs_vt_update_after`, the `next_at` of its rows). A newer release replacing the one waiting takes its place, and the old one's queued files are marked `superseded`. The scan endpoint the launcher reads (`api/pc.php?a=scan`) gives each VirusTotal-checked file its report `link`.

**Current versions** (4.13, `rs_vt_backfill`, each discover turn): a listed game whose current version (`ok_tag`) was never checked has its main file added at priority 5, 15 listings a turn, from the releases already cached. VirusTotal first (a lookup, then an upload while the day's 60 backfill uploads last), ClamAV when VirusTotal can't.

**Developers** (hub, `rs_pc_update_status`): the progress box says when an update's scan starts if it's waiting for its day. **Check for my new release now** (`api/dev.php` `pc-check`) reads the repo and queues the scan, then runs the scan's turn after the answer has gone out (`rs_vt_after`); running it inside the request used to time out on big downloads.

**Holds:** a new release from a developer who isn't verified waits until its files are checked, then goes out at once (`rs_vt_promote`, run after every scan turn) and the catalog entry is republished (`rs_pc_publish_one`). There's no extra waiting period any more (`vt_hold_mins` is 0; the setting can add one). Files VirusTotal can't take (over 650 MB, or a scan that keeps erroring) count as clean, so they don't hold a release. A release isn't let out unchecked any more (4.13; it was after 12 hours): it waits for VirusTotal, or for someone to let it out on Security. The release that got through is saved on the listing as `ok_tag`. Several engines flagging a file holds it until someone approves it on Security, and the developer is emailed once about it (see [06-developers.md](06-developers.md)).

**Reaching the developer:** each held update on Security, and the **Download needs a look** box, has "Ask the developer" buttons (`rs_pc_contact_links`): **Message** (their hub, when they have an approved account), **Email** (a prepared mailto with their account or submission address; a person sends it), **Open an issue** (prefilled on GitHub) and **Its repo** (for a pull request).

Flagged files (every row under **Needs you**, and flagged updates on hold) get **Email <name>** and **Message in their hub** instead, when we have an approved account or an email (account first, then the listing's or submission's address). Each opens a message written from the scan (`rs_vt_contact` in `inc/vt.php`, rules, no AI): the file, how many engines flagged it and which, the VirusTotal link, what it means for the release (on hold, held back from the store), and what usually clears it (build on GitHub Actions, report the false positive, sign the exe). It can be edited; nothing goes out until someone presses Send (`contact-mail`: sent from the site's mailbox with the signature and logged; `contact-msg`: a hub message). Held updates still show the GitHub links next to it.

## ClamAV

Run by Auto-add on the downloaded release, next to the look inside for game files and emulator wrappers. A bad result makes the draft's verdict `skip`.

## Repo watch (`inc/repowatch.php`, run by `inc/repowatch-run.php`)

Every listed game's repo, checked twice a day (cron at 5:23 and 17:23 UTC; a page asks for a run if the last one is over 13 hours old; **Scan now** on the board). GitHub is asked 50 repos at a time (GraphQL); a repo it can't show is asked again on its own (API, then the public page), so a hiccup never reads as a takedown.

States (`RS_WATCH_STATES`): `dmca` (taken down), `blocked`, `gone` (deleted or account removed), `private`, `norel` (releases deleted) are bad; `locked`, `moved` (renamed), `back` (came back) are warnings. Each one is pinned on top of the Submissions board with its options:
- **New repo**: point the listing at its new home (suggestions are shown when it's obvious).
- **Hide from store**: hides it (`watch_hid` is set, so we know why it's hidden).
- **It's fine**: acknowledge it (`ack`), it stays quiet until the state changes.
- **Use emergency backup**: when the game has a backup and it isn't a takedown (see [10-extras.md](10-extras.md)).

When a PC listing's repo goes from fine to `gone`, `private` or `norel`, its developer is emailed (once a month per state at most).

The **New repo** suggestions show as the repo (opens it on GitHub) plus **Use this repo**, which needs a second click to switch.

Rows: `repo_watch` (store, app_id, state, ack, …) and `repo_watch_log`.

## The download check (`rs_pc_dl_check` in `inc/pc.php`)

Runs in the 30-minute refresh for each PC recomp. It compares the newest release with the listing's Windows file pattern (`asset_win`):
- `stuck`: the newest release has a Windows-looking file that doesn't match the pattern (the developer renamed it), so players still get an older version. `dl_files` holds the candidates and `dl_tag` the release.
- `oldfile`: the matching file looks like an old copy (`…_OLD.zip`) next to a newer one.
- `nofile`: no release has a matching file.
- `gone`: the project page is gone or taken down.

**Renamed files are followed on their own:** when the check finds `stuck`, the refresh picks the new file (`rs_pc_dl_pick`: a zip over an installer, then the name closest to the old one), sets `asset_win` with its version as `*` and logs it. Only a real rename is followed: the listing must already have a Windows file and the new name must start the same way (a co-op build or a different project's file is left for a person). The same refresh then sees the new release, which is checked like any other before it goes out.

Whatever is left shows on the listing's edit page as **Download needs a look**, with:
- **Use the new file** (one button per candidate when there are several): sets `asset_win` to the new file with its version as `*` (`rs_pc_pattern`). Publish to put it live.
- **It's fine**: dismisses it (`dl_ack`) until something new comes up. Changes nothing for players.
- **Its releases**: opens the releases page.

## Moved repos

Once a week the refresh reads the README for "this project moved to …" (`rs_pc_moved_check`). A hit shows as **This project may have moved**, with a button to point the listing at the new repo (`rs_pc_switch_repo`) or keep it.

## AI-assisted label (`inc/ai.php`, page `admin/ai.php`)

`ai` is `''` (not decided), `yes` or `no`; `ai_uses` lists what AI helped with (code, art, text, audio); `ai_by` is who said so (`developer` or `store`). The GitHub scan (`rs_ai_scan`) looks for signs (agent files in the repo, commits credited to an AI tool, the README saying so) and only suggests; a person decides. Visitors can hide AI-assisted games everywhere.

## PS5 listing health (`inc/health.php`)

The PS5 side's version of the download check: each listing checked about once a day the way a console would install it. A renamed PS5 download gets its file pattern fixed every morning (`inc/autopilot.php`).

## Security page: emergencies (4.13)

- **Hide from store** on a Needs you row pulls the listing off the store at once (`sec-hide`: `hidden` plus `sec_hid` = when, who, the flagged tag and repo, why; the catalog is rewritten and launchers are told). It marks that release's files seen, and the listing shows under **Hidden for security** with **Put back in the store** (`sec-unhide`). `rs_pc_hidden_why` says why on the PC list.
- **Sent to the developer**: emails and hub messages sent from the contact panel, and presses on **Open an issue**, are remembered per flagged release (kv `vt_reach:<hash of repo|tag>`, `rs_vt_reach_*`). They show as green "Email sent · 2 h ago · ruben" marks, the buttons turn into **Email again** / **Message again**, and sending again asks first.
- **Needs you** and **Hidden for security** fold up; the page remembers which are folded (per browser).
