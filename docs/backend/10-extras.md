# Trending, Emergency backup, Instant Gaming, sponsors, achievements

## Trending (`inc/trending.php`)

RecompLauncher's weekly chart, like a Hot 100. Each recomp is ranked by how many different launchers played it that week (sessions of a minute or more, from `lstat_sessions`), then by hours played. Every version is ranked on its own. Top 50, listed games only (not hidden or Coming soon).

- A chart is dated the Friday that ends its week (midnight Phoenix) and frozen then. `rs_trend_maybe()` (30-minute job) builds it once Friday comes; the first time it also backfills the weeks the play data fully covers.
- `pc_trend` (week, app_id, pos, players, seconds). The column is `pos` because `rank` is a reserved word.
- `rs_trend_chart()` adds each entry's last week, peak, weeks on the chart and movement; `rs_trend_write()` publishes `trending.json`. Play counts stay private.
- The website has a Trending page (`/pc/trending`) and a "Trending this week" row on Home; the launcher has the row only.

## Emergency backup (`inc/backup.php`, page PC recomps > Emergency backup, `api/backup.php`)

A copy of each tracked game's live release on Backblaze B2, next to the cloud saves, in case its repo disappears.

- **Settings** (`bk_cfg`): systems to keep (Windows, Linux, Mac), which games (only picked, most played top N plus picks, or every listed game), back up new updates automatically, a size limit per file.
- **Copying**: `rs_bk_copy()` finds the live release (the one in `ok_tag`, else the one matching the listing's version), downloads each wanted file to `recompstore-private/backup/`, hashes it, uploads it to `backup/<id>/<tag>/<file>` on B2, then deletes the previous copy. Only the latest is kept; the old copy stays until the new one is safely up. A repo that's down is never copied over.
- `pc_backup` row per game: `tracked`, `picked` (`hand` or `auto`), `os` (its own systems, empty = defaults), `status` (`none`, `queued`, `working`, `ok`, `error`), `version`, `tag`, `files` (JSON: os, name, size, sha256, key), `bytes`, `active`, `served`.
- Runs in the background (`inc/backup-run.php`): started by the 30-minute job when a tracked game has a new version or was asked for, or by **Back up now**. Failed copies retry after 6 hours.
- **Switching**: when a repo goes down, the repo watch row offers **Use emergency backup** (never for `dmca` or `blocked`). `rs_bk_activate()` sets `active`, puts the game back on the store if the watch hid it, and rewrites the catalog: the entry gets `backup: true` with the backup's `assets` and `version`. The site and launcher then download through `api/backup.php`. **Back to the repo** undoes it.
- Cost: about $7 per TB a month; all listed games' Windows files are about 105 GB.

## Instant Gaming (`inc/ig.php`, page Sponsors > Instant Gaming, `api/ig.php`)

A card on a game's page with where to buy the original game, through our partner link. A card belongs to a title (every version of the game) or one recomp. Prices, stock and countries are read every 5 minutes (`ig-refresh.php`); the answer is per country and currency. Layout variants (`v`) are tested with shares; views and clicks are counted per variant and round. Two switches: **on** (the website) and **Show in the launcher** (`launcher`, off by default). The launcher shows cards only when both are on.

## Sponsors (`inc/sponsors.php`, `api/spons.php`)

Sponsor spots on recomplauncher.com. Each spot is off until a sponsor is booked for it; a booking has a picture, a link and dates, and counts views and clicks by day.

## Recomp Achievements (`inc/ach.php`, `api/ach.php`)

RetroAchievements-style achievements for PC recomps (recompachievements.com later). Lists in SDK format 1, launcher sessions, unlocks with Ranked rules, Mastered games. Dev only: the API answers test accounts alone (`ach_testers`), and nothing reaches the public catalog until `ach_public` is on.
