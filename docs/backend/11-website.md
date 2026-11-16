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

## Player scores

`rs_play_scores()` (inc/play.php, kept 5 minutes in `recompstore-private/play-scores.json`) gives each version:
- `score`, the main number;
- `all`, every report;
- `cur`, the release out now on its own: `{version, score, n, verdicts}`, matched on the report's version against the listing's `version` / `ok_tag` (`rs_play_ver_key`, so "v0.1.1" = "0.1.1").

Once the latest release has 3 reports (and older ones exist), `score` blends it: 55% latest at 3 reports, rising 5% a report to 75%. Older reports never go away; they just count less, like an app store's "this version" rating. The game page shows This version / All versions under the score and a filter for the latest version's reports. `api/pc.php?a=feedback` (RecompLauncher's "% plays great") also gives `latest: {version, counts}`.

## Tiles

A game's tile shows two flags at most (4.11): Easy Setup (`ez` in `pc-catalog-web.json`, from the listing's `support.easy_setup`) and its versions count. With both, they shrink to icons. Great on Deck, handhelds, Complete, Ended, On hold and Setup Required show on the game's page, not on tiles. RecompLauncher's cards follow the same rule from Beta 51.

## Pictures

Everything in `/media`. `api/thumb.php?f=<file>&w=<width>` makes and keeps smaller copies (widths 160, 256, 320, 400, 640, 960; anything else fails). `.htaccess` serves an existing thumbnail straight from disk without running PHP.

## Gzip

`.htaccess` serves `pc-catalog.json`, `pc-catalog-web.json`, `catalog.json` and `developers.json` from their pre-made `.gz` copies.

## Rules for the site's look

UI layouts are even and clean: no row with a single leftover item, boxes side by side the same size. Pages work on phones (check at 390 px wide). Redesigns get a few design samples first, matching the existing look.

## A release's games (4.13)

A release that carries several games (COGBox, Athelas) gets a page per game at `/pc/game/<release>--<game>` (`pcSubOf`, `pcChild` in `site.js`; `page.php` fills its title and preview too). It shows the game's own name, art and IGDB card with the release's details, and a "Part of {name} · {n} games" pill to the release. Install, README, reports, counts and the account buttons are the release's. The release's "Includes {n} games" cards open these pages, search lists each game (with the same "Part of" line), and a title's versions list shows a release's copy with that mark. Route patterns allow ids up to 100 characters.
