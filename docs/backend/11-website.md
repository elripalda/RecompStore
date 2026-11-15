# The public website

## One page app

recompstore.com and recomplauncher.com are a single page (`index.html`) drawn by `assets/site.js` (about 650 KB) with `assets/site.css`. `route()` in site.js reads the address and draws the page; the PC side's addresses are matched by `PC_PATH_RE` and the `PATHS` table. Adding a page: add its path to both, add it to the `.htaccess` rewrite rules, and give it a title and description in `page.php`.

`index.html` loads `site.js?v=N` and `site.css?v=N`. Bump N with every change to either file, then clear stale page-cache files (see [01-layout.md](01-layout.md)).

## page.php: titles, meta and the page cache

Every address goes through `page.php` (via `.htaccess`), which returns `index.html` with that page's own title, description, language links and structured data filled in (`inc/seo.php`), plus link-preview pictures for games (`inc/og.php`). Search engines and Discord previews read this; the script then draws the page as usual.

- Each finished page is kept on disk 5 minutes in `recompstore-private/page-cache/`. Every visitor gets the same page (the language is in the address; signing in happens in the browser).
- Cloudflare keeps pages 2 minutes and shows the last good copy if the server is busy or down.
- Maintenance mode (Settings) takes either side down with a 503 and the admin's message (`inc/maint.php`).

## What the site reads

- `pc-catalog-web.json` for lists, rows, tiles and search (the light copy). A game's page asks for its full entry with `api/pc.php?a=app&id=`.
- `trending.json` for the Trending row and page (waits up to 1.5 s for it, then draws without).
- `api/ig.php` for the Instant Gaming card, `api/spons.php` for sponsor spots, `api/notes.php` for team notes.
- `api/backup.php` for the download buttons of a game switched to its emergency backup.
- Accounts: `api/account.php`, `api/oauth.php`; comments, likes, player reports: `api/comments.php`, `api/play.php`.
- Posting needs an account (4.11, Admin > Accounts > Rules, setting `post_need_acct`, on by default): comments, website player reports (`api/play.php`), PC feedback (`api/pc.php?a=feedback`, which RecompLauncher uses) and the older launcher reports (`api/launcher.php?a=report`) answer 401 when signed out, via `rs_post_need_acct()`. Players can post as Anonymous: `comments.anon`, and `play_reports.show_name` = 0 for reports. Nobody else sees the name. The admin sees the account on Accounts (feedback list and Moderation) and can close it there. A closed account can't sign in or post from the site or the launcher.

## Pictures

Everything in `/media`. `api/thumb.php?f=<file>&w=<width>` makes and keeps smaller copies (widths 160, 256, 320, 400, 640, 960; anything else fails). `.htaccess` serves an existing thumbnail straight from disk without running PHP.

## Gzip

`.htaccess` serves `pc-catalog.json`, `pc-catalog-web.json`, `catalog.json` and `developers.json` from their pre-made `.gz` copies.

## Rules for the site's look

UI layouts are even and clean: no row with a single leftover item, boxes side by side the same size. Pages work on phones (check at 390 px wide). Redesigns get a few design samples first, matching the existing look.
