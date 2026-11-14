# Data: tables, files and caches

Tables create themselves the first time their code runs (`CREATE TABLE IF NOT EXISTS` in each `*_tables()` function), so there's no migration step. Add a column the same way: an `ALTER TABLE` guarded by a check, inside that area's `*_tables()`.

## Settings and caches

- `settings` (k, v): every admin setting. Read with `rs_setting('key', default)`, write with `rs_set_setting`. Settings that are a group of values are stored as one JSON value (for example `bk_cfg` for Emergency backup, the Instant Gaming config).
- `kv_cache` (k, v, at): small things that change often: "last run" times, "running" flags, counters, cached answers. `rs_kv_get($k)` returns `['v' => ..., 'age' => seconds]` or null; `rs_kv_put($k, $v)`.
- `gh_cache`: GitHub, GitLab, SourceForge and Forgejo answers, cached (`rs_host_releases($host, $repo, $maxAge)`). Most code reads through this, so a page never waits on GitHub twice.
- `rate_tries`: rate limits (`rs_rate_over`, `rs_rate_hit`).
- `admin_log`: the change log. Every POST in the admin is logged by `rs_check_csrf`, and code adds its own lines with `rs_log(area, item, text)`.

## Tables by area

| Area | Tables | Notes |
|---|---|---|
| PC recomps | `pc_apps` | One row per listing: `id`, `data` (JSON with every field), `sort`. Read with `rs_pc_apps($withHidden)` / `rs_pc_app($id)`, save with `rs_pc_save($a)`. See [03-pc-catalog.md](03-pc-catalog.md). |
| AI usage | `ai_usage` | Calls and tokens per day, feature and model. See [12-ai-and-rules.md](12-ai-and-rules.md). |
| Finding recomps | `pc_found`, `pc_scout`, `pc_auto`, `pc_requests`, `pc_request_asks` | Found on GitHub (state `new`, `skipped`, `added`), the daily scout, Auto-add drafts, players' requests. See [04-how-games-move.md](04-how-games-move.md). |
| Submissions | `inbox`, `inbox_files`, `inbox_pictures`, `inbox_reports`, `hub_flags`, `hub_notes` | Everything sent in (submissions, bug reports), flags for the owner, team notes on cards. |
| Titles | `titles`, `title_reservations` | One game, many versions. |
| Checks | `vt_files`, `repo_watch`, `repo_watch_log`, `listing_health` | VirusTotal, the repo watch, PS5 listing health. See [05-checks.md](05-checks.md). |
| Developers | `developers`, `dev_accounts`, `dev_sessions`, `dev_claims`, `dev_changes`, `dev_messages`, `dev_invites`, `dev_docs`, `dev_broadcasts` | See [06-developers.md](06-developers.md). |
| Player accounts | `accounts`, `account_sessions`, `account_tokens`, `account_tries`, `account_saved`, `account_names_held`, `acct_deleted_days` | Sign-in with Google, Discord, GitHub or email. |
| Launcher | `launcher_devices`, `launcher_builds`, `launcher_links`, `launcher_share`, `launcher_sends`, `launcher_beta`, `launcher_beta_wave`, `cloud_saves`, `rl_events`, `rl_installs`, `rl_launch_dl` | PCs signed in, builds, beta waves, cloud saves (files on B2). |
| Launcher stats | `lstat_installs`, `lstat_sessions`, `lstat_days`, `lstat_totals` | Anonymous: an install is a keyed hash. Play sessions per game feed Trending. Sessions are kept 90 days. |
| Player reports and comments | `play_reports`, `play_votes`, `play_flags`, `play_report_replies`, `comments`, `comment_votes`, `comment_reports`, `pc_feedback`, `ps5_feedback` | |
| SDK and PRs | `pc_prq` | The PR queue. See [08-sdk-and-pr-queue.md](08-sdk-and-pr-queue.md). |
| Extras | `pc_trend`, `pc_backup`, `ig_listings`, `ig_offers`, `ig_days`, `sponsors`, `sponsor_days`, `ach_*` | See [10-extras.md](10-extras.md). |
| Mail | `mail_queue`, `mail_sent`, `mail_list`, `mail_blasts`, `player_mail`, `player_mail_sent` | Emails wait in `mail_queue` and go out at the mailbox's pace (Hostinger allows about 1,000 a day). |
| Betas (PS5 and PC) | `beta_*`, `pc_betas`, `pc_beta_members`, `pc_notify` | `pc_notify` is "Notify me" on Coming soon games, emailed on Publish. |
| PS5 store | `found_apps`, `app_installs`, `app_events`, `app_published`, `console_days`, `link_*`, `report_codes`, `signatures`, `likes`, `translations`, `site_pages`, `site_visitors` | |
| Team | `team_notes`, `admin_keep` | |

## Files the site writes

In `public_html/` (served):
- `pc-catalog.json` (+ `.gz`) the full PC catalog the launcher reads.
- `pc-catalog-web.json` (+ `.gz`) the website's lighter copy (no descriptions, boot profiles or file lists; a game's page asks for those one game at a time).
- `catalog.json` the PS5 store's catalog (signed by RecompStore Signer).
- `trending.json` the weekly chart.
- `media/` every picture (covers, backdrops, logos, icons, screenshots); `media/t/<width>/` thumbnails made by `api/thumb.php`.

In `recompstore-private/` (not served):
- `pc-app/<id>.json` each recomp's catalog entry on its own, the answer to `api/pc.php?a=app`.
- `page-cache/` finished site pages, 5 minutes each.
- `backup/`, `prq/`, logs.

## Caches to know about

- **opcache copy of the catalog** (`inc/catcache.php`): the PC catalog is turned into a PHP file once per Publish so the API doesn't decode 4.8 MB of JSON on every request.
- **Page cache**: 5 minutes on disk (`page.php`), then Cloudflare keeps pages 2 minutes. After changing site.js or site.css, bump `?v=` in index.html and clear stale pages (see [01-layout.md](01-layout.md)).
- **GitHub cache**: `gh_cache`, usually 15 minutes to an hour.
- **jcache** (`inc/jcache.php`): short-lived copies of JSON answers that are the same for everyone, and the last good answer if the database is briefly down.
