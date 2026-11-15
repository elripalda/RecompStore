# Layout, server and deploys

## The repo

- `site/public_html/` is the web root. Everything the server runs is in here.
  - `admin/` the admin (recompstore.com/admin). Pages are `admin/*.php`; shared code is `admin/inc/*.php`.
  - `admin/inc/bootstrap.php` is loaded by every page: database (`rs_db()`), settings (`rs_setting`, `rs_set_setting`), the small key/value store (`rs_kv_get`, `rs_kv_put`), sessions and CSRF (`rs_csrf`, `rs_check_csrf`), flash messages (`rs_flash`), the change log (`rs_log`).
  - `admin/inc/layout.php` draws the admin's header, sidebar and page tabs (`rs_head`, `rs_foot`). The sidebar sections and their page tabs are listed there; add a new page to it.
  - `api/` small JSON endpoints for the site, the PS5 store and RecompLauncher (see [07-launcher-api.md](07-launcher-api.md)).
  - `assets/site.js`, `assets/site.css`, `index.html` the public site (see [11-website.md](11-website.md)).
  - `page.php` serves the site's pages with the right title and meta; `.htaccess` routes addresses to it.
- `site/tools/vps/` scripts that run on the server from cron (see [09-background-jobs.md](09-background-jobs.md)). They're copied one folder above public_html on the server.
- `tools/prq/draft.py` the PR drafter; `.github/workflows/pr-drafts.yml` runs it daily.
- `app/` the PS5 store app itself (C/C++), `signer/` RecompStore Signer. Not covered here.
- `docs/` older version notes (`store-2.3.md` etc.) and these backend notes.

## Two sites, one codebase

recompstore.com (the PS5 store's site) and recomplauncher.com (RecompLauncher, the PC side) are the same files. The host name decides which side a page shows. The admin is at recompstore.com/admin and covers both. PC recomps and PS5 games are kept apart (their own tables and catalogs), so nothing on the PC side reaches a PS5. Developer accounts, player accounts and titles are shared.

## The server

- A Hostinger VPS: Apache 2.4 (so `.htaccess` rewrites work) with PHP 8.3 FPM, MariaDB. `public_html/.user.ini` raises PHP's upload limits (16 MB a file, 40 MB a request) for message files. Cloudflare sits in front of both domains.
- Web root: `/var/www/recompstore/public_html`. Files run as `www-data`.
- Next to it, outside the web root:
  - `recompstore-config.php` database login and other secrets (read by bootstrap.php)
  - `recompstore-b2.php` the Backblaze B2 login (cloud saves, emergency backup)
  - `recompstore-private/` things that must never be served: `page-cache/`, `cloud-saves/` (old local saves), `prq/` (the server copy of draft.py and its log), `backup/` (temporary files and log for emergency backup), repo watch and auto-add logs
  - the cron scripts from `site/tools/vps/` (`pc-refresh.php` and others)
- Cron files live in `/etc/cron.d/` (`recompstore-repowatch`, `recompstore-scout`, `recompstore-ig`) plus www-data's crontab for pc-refresh and the Discord counter.

## How a change gets deployed

1. Commit and push to the `mac-support` branch of github.com/elripalda/RecompStore.
2. On the server, each changed file is downloaded from `raw.githubusercontent.com/elripalda/RecompStore/<commit>/...`, checked (`php -l` for PHP, `py_compile` for Python), and swapped in: copy to `<file>.new`, copy owner and mode from the old file, then `mv` over it. That way a half-written file is never live.
3. If `assets/site.js` or `site.css` changed, bump `?v=` in `index.html` and clear the page cache of pages still pointing at the old version: `grep -rL 'site.js?v=<new>' recompstore-private/page-cache --include='*.html' | xargs -r rm -f`.
4. Cloudflare may serve the old copy for up to a couple of minutes; Ctrl+F5 to check.

Ruben can't use the VPS web console himself, so whoever has server access runs these steps.

A one-off check on the server is done the same way: a small PHP file in `admin/` that requires `inc/bootstrap.php`, run with `runuser -u www-data -- php file.php`, then deleted.

## The source zip

After each change, a zip of the repo (`git archive HEAD`) goes to Ruben's own copy of the source.
