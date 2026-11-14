# Working on this repo

Read `docs/backend/README.md` first. It explains how the website, admin and APIs behind recompstore.com and recomplauncher.com work, one short file per area, so you only open the code you need.

**Keep the notes up to date.** Any change to how something works (a table, an endpoint, a cron job, what the launcher reads, how a game moves through the admin) updates its file in `docs/backend/` in the same commit. New area? Add a file and list it in the README.

Other rules:
- No secrets in the repo (keys, passwords, tokens). It's public.
- Automation uses plain rules first (`admin/inc/rules.php`). Anything that calls Claude goes through `rs_ai_json` with its own feature key, so it's counted and stops at the daily budget (see `docs/backend/12-ai-and-rules.md`).
- Website changes deploy right after they're pushed (see `docs/backend/01-layout.md`).
- Writing that players or developers read (patch notes, READMEs, pull requests) is short and plain and never mentions AI.
