# PC recomps: the listing, Publish and the catalog

Code: `admin/inc/pc.php` (the biggest file), the page is `admin/pc.php` (PC recomps).

## A listing

Each PC recomp is one row in `pc_apps`; all its fields are a JSON object in `data`. The defaults are in `rs_pc_defaults()`. The ones that matter most:

- `id` the address (`/game/<id>`), lower case with dashes. Never changes once live.
- `name`, `game` (the original game), `origin` (console), `genre`, `tags`, `blurb`, `adds` (what this version adds), `description`
- `repo`, `host` (`''` GitHub, `gitlab`, `sourceforge`, `forgejo`): where its releases come from. `rs_pc_key($a)` gives the repo key everything else files it under (`owner/name`, or `gitlab.com/group/project`).
- `asset_win`, `asset_linux`, `asset_mac`: which release file is the download for each system. A pattern: `*` stands for the part that changes each release (`MyRecomp-*-win-x64.zip`). `rs_pc_pattern($name, $tag)` makes one from a file name.
- `version`, `released`, `size`, `downloads`, `stars`: from GitHub, kept fresh by the 30-minute job. `downloads` adds `dl_carry` (set on the edit page, "Downloads from before a move"): the count a recomp had before its releases were lost with a deleted repo or account.
- `icon`, `cover`, `hero`, `logo`: pictures in `/media`.
- `dev`: the developer's slug (see [06-developers.md](06-developers.md)); `author`: repo owner.
- `hidden`, `soon` (Coming soon), `featured`, `live_at` (when it first went live), `status` (`complete`, `paused`, `ended`)
- `boot`: the boot profile (how the launcher starts it and does Easy Setup), see [08-sdk-and-pr-queue.md](08-sdk-and-pr-queue.md).
- `ai`, `ai_uses`: the AI-assisted label.
- Set by checks: `ok_tag` (the release VirusTotal let out), `dl_state` / `dl_why` / `dl_files` / `dl_tag` (the download check), `moved_to` (README says it moved), `watch_hid` (hidden by the repo watch), `blocked_why` (hidden as "not compatible yet").

## Publish

Most edits only reach the site on Publish (the Publish PC button on PC recomps).

- `rs_pc_publish()`: refreshes GitHub numbers for up to 20 seconds, groups versions into titles, builds the catalog (`rs_pc_catalog()`), writes the files, sends "Notify me" emails for games that came out, and posts new games to Discord.
- `rs_pc_write_catalog()`: writes the files from what's saved, without asking GitHub. Used when something changes automatically (a repo watch hide, switching a game to its emergency backup).
- `rs_pc_publish_one($id)`: updates one game's entry in the published catalog. A verified developer's own change goes out this way straight away.
- `rs_pc_unlist_one($id)`: takes one game out at once (a copyright notice) without a full Publish.

`rs_pc_write_files()` writes, in order: `pc-catalog.json` (+ gzip), `pc-catalog-web.json` (+ gzip), each game's own file in `recompstore-private/pc-app/`, and the opcache copy.

## What's in pc-catalog.json

Top level: `catalogVersion`, `platform`, `updated`, `apps`, `origins`, `developers` (`[{slug, name, verified, avatar}]`), `titles`, `banner`/`banners`, `replaced`, `apps_min` (grouped by title), `boot_all`.

Each entry in `apps` is built in `rs_pc_catalog()` from the listing. Hidden listings aren't in it. Fields the launcher relies on:
- `id`, `name`, `game`, `version`, `released`, `repo`, `host`, `assets` (`{windows, linux, macos}` file patterns), `systems`
- `boot` (start, setup, settings), `files`, `needs`, `features`, `copy_ids`
- `cover`, `hero`, `logo`, `icon`, `tag`, `blurb`, `players`
- `dev` when a developer is linked
- `backup: true` when the game is switched to its emergency backup; then `assets` and `version` are the backup's (see [10-extras.md](10-extras.md))

If you add a field the launcher should read: add it in `rs_pc_catalog()`, decide whether the website's light copy needs it (`rs_pc_web_copy()` strips the heavy ones), and note it in [07-launcher-api.md](07-launcher-api.md).

## Titles (one game, many versions)

`inc/titles.php`. A title is the game; every listing built from it is a version under it. `rs_titles_autogroup()` groups new ones on Publish. The site shows a game once in lists and lets you pick the version on its page. Trending and Emergency backup work per listing (each version on its own).

**Recommended** (`titles[].pick`, read by both the website and the launcher): the version pinned on Admin > Games with versions, else the best score out of 100 from `rs_title_rec_scores()`:
- players' rating 45 (from 3 reports, else 50);
- downloads 30, against the game's most downloaded version (1/100 of it or less scores 0);
- launcher features from the catalog's `support`: Easy Setup 12, settings 8, saves 3, copy check 2. A feature from our profile instead of the developer's own file counts 70%.

A version rated under 50 from 5 reports or more is only picked when every version is. Versions not out yet are left out. The weights are the `RS_REC_*` constants. The admin page shows each version's score. `titles[].why` gives the reason in a few words ("Easy Setup · Most downloaded · 76% from players"; empty when pinned). The website shows it on the Recommended tag, and the launcher in its version notes. Changes go out on the next Publish (or catalog write).

## The 30-minute refresh

`pc-refresh.php` (cron) calls `rs_pc_refresh_github()`: for each listing not checked in the last 30 minutes, reads its repo and newest release, updates version, size, downloads, stars and features, and runs the download check (`rs_pc_dl_check`) and the weekly "moved?" check. It saves the listing; the site changes on the next Publish. See [09-background-jobs.md](09-background-jobs.md) for everything else that job does.
