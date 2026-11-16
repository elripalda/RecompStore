# Background jobs

All cron jobs run as `www-data`. Times are UTC unless noted; Phoenix is UTC-7 all year.

| When | What | File |
|---|---|---|
| Every 30 min | The big refresh, below | `pc-refresh.php` (www-data crontab), log `/var/log/recompstore-pc-refresh.log` |
| Every 5 min | Instant Gaming prices, stock and countries | `ig-refresh.php` (`/etc/cron.d/recompstore-ig`) |
| Every 10 min | Discord member counter channel | `dc-counter.php` |
| 5:23 and 17:23 | Repo watch | `inc/repowatch-run.php` (`/etc/cron.d/recompstore-repowatch`) |
| Mondays 15:07 (8:07 am Phoenix) | Weekly digest email to the owner | `inc/digest-run.php` (same cron file) |
| Noon Phoenix | The daily scout (finds recomps, sends picks through Auto-add) | `inc/scout-run.php` (`/etc/cron.d/recompstore-scout`) |
| 14:23 (7:23 am Phoenix) | PR drafts | GitHub Action `.github/workflows/pr-drafts.yml` |

## What the 30-minute refresh does (`pc-refresh.php`, in order)

1. Refreshes PC recomps' GitHub numbers, the download check and the weekly moved check (`rs_pc_refresh_github`).
2. Moves the Home banner's automatic slots on.
3. Coming soon watch (once a day): releases, betas and new repos for Coming soon games.
4. Launcher support: looks for `recomplauncher.json` in recomps not checked this week (up to four minutes a run).
5. Rebuilds sent PRs that now conflict; sends up to 5 waiting PRs an hour; after a PR, makes our profile follow the developer's `recomplauncher.json` in their repo until their release ships it (`rs_prq_sync_repo_files`, see [08](08-sdk-and-pr-queue.md)).
6. Moves Forgejo listings to the next launcher once it's published.
7. Instant Gaming card refresh.
8. **Trending**: builds the new weekly chart once Friday midnight Phoenix has passed (`rs_trend_maybe`), writes `trending.json`.
9. **Emergency backup**: picks games per its settings and starts a copying run in the background when there's something to copy (`rs_bk_maybe`).
10. Discord: once an hour, gives the Developer role to approved developers who linked Discord and don't have it yet (`rs_dc_sync_due`, see [06](06-developers.md)).
11. Docs parity: keeps the published schema in step with the launcher's.

Each step is wrapped so one failing doesn't stop the rest; failures go to the log.

## Background runs started from pages

Long jobs never run inside a web request. A page starts them with `setsid php <script> &` (via `proc_open`) and they write their progress to the database or a log:

- Auto-add: `inc/auto-run.php` (`rs_auto_launch`)
- Repo watch Scan now: `inc/repowatch-run.php now`
- Emergency backup: `inc/backup-run.php` (`rs_bk_launch`); one run at a time (a lock file), 50 minutes at most, the rest next run
- PR drafts: `recompstore-private/prq/draft.py` (`rs_prq_run`), heartbeat every 2 minutes so the page shows it's alive

## Things that run on admin page loads

- The morning finder and pre-checks (6 am Phoenix, first page load after it): `rs_auto_morning_maybe`.
- The twice-weekly finder: `rs_pc_find_maybe`, after the page has been sent.
- VirusTotal's line moves along on page loads too (and `vt-run.php` from the command line).
- A repo watch run if the last one is over 13 hours old.

## Adding a job

Prefer a step in `pc-refresh.php` (wrapped in try/catch, short, with a time budget) over a new cron entry. If it needs its own schedule, add a `*-run.php` next to its code with a lock, list it in the table above, and put the cron line in `/etc/cron.d/`.

## GitHub's hourly limit (4.12)

Every GitHub API call goes through `rs_http` (`inc/connect.php`) with `github => true`:
- **"Changed since?"**: a GET sends the ETag of the copy it has (`gh_etag` table, gzipped bodies, kept 21 days). GitHub's 304 doesn't count against the hour, so the 30-minute refresh re-reading unchanged repos is nearly free.
- **A reserve for people**: what's left this hour is kept in `recompstore-private/gh-rate.json` from GitHub's headers. Background jobs (PHP on the command line) stop asking once fewer than 800 calls are left (`RS_GH_RESERVE`, `rs_gh_low`), so a developer submitting or looking up a repo never finds the hour used up. Auto-add's runner (`auto-run.php`) counts as people (`RS_GH_PEOPLE`).
- **Pauses**: only a real "hour used up" pauses every lookup (until GitHub's reset, at most 15 minutes), GitHub's "slow down" 2 minutes, and a timeout or 5xx 45 seconds. A 403 on one repo no longer pauses the site.
- **Who used it**: calls are counted per function per hour in kv `ghuse:<YmdH>` (UTC).
