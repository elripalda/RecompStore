# Checks: safety, repos and downloads

## VirusTotal (`inc/vt.php`, page `admin/security.php`)

What gets checked on its own:
- a submission's release files, as soon as it arrives
- each PC recomp's and PS5 app's newest release files
- anything pasted into Check a link on the Security page

Files are found by their SHA-256 (GitHub publishes it per release file); only files VirusTotal has never seen are downloaded and sent. The free key allows 4 requests a minute and about 480 a day here, with 100 a day kept for submissions and Check a link. Everything waits its turn in `vt_files`; `vt-run.php` can work the line from the command line.

**Holds:** a new release from a developer who isn't verified is held. The site and launcher keep offering the version before it until the new files are checked and the hold time has passed. The release that got through is saved on the listing as `ok_tag`. Several engines flagging a file holds it until someone approves it on Security.

## ClamAV

Run by Auto-add on the downloaded release, next to the look inside for game files and emulator wrappers. A bad result makes the draft's verdict `skip`.

## Repo watch (`inc/repowatch.php`, run by `inc/repowatch-run.php`)

Every listed game's repo, checked twice a day (cron at 5:23 and 17:23 UTC; a page asks for a run if the last one is over 13 hours old; **Scan now** on the board). GitHub is asked 50 repos at a time (GraphQL); a repo it can't show is asked again on its own (API, then the public page), so a hiccup never reads as a takedown.

States (`RS_WATCH_STATES`): `dmca` (taken down), `blocked`, `gone` (deleted or account removed), `private`, `norel` (releases deleted) are bad; `locked`, `moved` (renamed), `back` (came back) are warnings. Each one is pinned on top of the Submissions board with its options:
- **New repo**: point the listing at its new home (suggestions are shown when it's obvious).
- **Hide from store**: hides it (`watch_hid` is set, so we know why it's hidden).
- **It's fine**: acknowledge it (`ack`), it stays quiet until the state changes.
- **Use emergency backup**: when the game has a backup and it isn't a takedown (see [10-extras.md](10-extras.md)).

Rows: `repo_watch` (store, app_id, state, ack, …) and `repo_watch_log`.

## The download check (`rs_pc_dl_check` in `inc/pc.php`)

Runs in the 30-minute refresh for each PC recomp. It compares the newest release with the listing's Windows file pattern (`asset_win`):
- `stuck`: the newest release has a Windows-looking file that doesn't match the pattern (the developer renamed it), so players still get an older version. `dl_files` holds the candidates and `dl_tag` the release.
- `oldfile`: the matching file looks like an old copy (`…_OLD.zip`) next to a newer one.
- `nofile`: no release has a matching file.
- `gone`: the project page is gone or taken down.

It shows on the listing's edit page as **Download needs a look**, with:
- **Use the new file** (one button per candidate when there are several): sets `asset_win` to the new file with its version as `*` (`rs_pc_pattern`). Publish to put it live.
- **It's fine**: dismisses it (`dl_ack`) until something new comes up. Changes nothing for players.
- **Its releases**: opens the releases page.

## Moved repos

Once a week the refresh reads the README for "this project moved to …" (`rs_pc_moved_check`). A hit shows as **This project may have moved**, with a button to point the listing at the new repo (`rs_pc_switch_repo`) or keep it.

## AI-assisted label (`inc/ai.php`, page `admin/ai.php`)

`ai` is `''` (not decided), `yes` or `no`; `ai_uses` lists what AI helped with (code, art, text, audio); `ai_by` is who said so (`developer` or `store`). The GitHub scan (`rs_ai_scan`) looks for signs (agent files in the repo, commits credited to an AI tool, the README saying so) and only suggests; a person decides. Visitors can hide AI-assisted games everywhere.

## PS5 listing health (`inc/health.php`)

The PS5 side's version of the download check: each listing checked about once a day the way a console would install it. A renamed PS5 download gets its file pattern fixed every morning (`inc/autopilot.php`).
