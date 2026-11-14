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
4. SDK support: looks for `recomplauncher.json` in recomps not checked this week (up to four minutes a run).
5. Rebuilds sent PRs that now conflict; sends up to 5 waiting PRs an hour.
6. Moves Forgejo listings to the next launcher once it's published.
7. Instant Gaming card refresh.
8. **Trending**: builds the new weekly chart once Friday midnight Phoenix has passed (`rs_trend_maybe`), writes `trending.json`.
9. **Emergency backup**: picks games per its settings and starts a copying run in the background when there's something to copy (`rs_bk_maybe`).
10. Docs parity: keeps the published schema in step with the launcher's.

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
