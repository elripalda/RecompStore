# What RecompLauncher calls

All on https://recomplauncher.com (the same server as recompstore.com). Each endpoint's file starts with a comment listing its calls and answers; that comment is the full reference. This is the map.

## Static files (read often, cheap)

| File | What it is | Caching |
|---|---|---|
| `/pc-catalog.json` | Every listed recomp, with boot profiles, file patterns, developers, titles (see [03-pc-catalog.md](03-pc-catalog.md)). About 4.8 MB, sent gzipped when asked (`Accept-Encoding: gzip`). | `max-age=60, s-maxage=300`, ETag |
| `/pc-catalog-web.json` | The website's lighter copy. The launcher uses the full one. | same |
| `/trending.json` | The weekly chart: `{week, from, to, next, first, entries: [{id, rank, last, peak, weeks, move}]}`. `move` is `up`, `down`, `same`, `new`, `re`. New one each Friday. | `no-cache` + ETag, CORS open |
| `/schema/recomplauncher.json` | The schema for developers' `recomplauncher.json`, kept in step with the launcher's own (30-minute job). | |
| `/media/...`, `/api/thumb.php?f=<file>&w=<width>` | Pictures; thumbnails at widths 160, 256, 320, 400, 640, 960 only (others fail). | long |

## Endpoints

| Endpoint | Used for |
|---|---|
| `api/launcher-update.php?platform=windows\|linux\|macos` | The launcher's update check: `{version, notes, size, sha256, url, published}`. Builds are uploaded on the Launcher page (`admin/launcher-upload.php`). |
| `api/launcher.php` | Signing a PC in with a short code (`link-start`, `link-poll`), the account (`me`), cloud saves (`saves`, `save-put`, `save-get`, `save-delete`; files on Backblaze B2), playtime (`play`, below), games sent from the website (`inbox`), betas, the saved library, reports. The PC's token goes in `X-RL-Token` or `Authorization: Bearer`. |
| `api/launcher-ping.php` | Anonymous usage: `open`, `heartbeat`, `game_start`, `game_stop`, `close`. Feeds launcher stats and Trending. |
| `api/pc.php` | `a=app&id=` one game's full entry; `a=readme`; `a=feedback` (player reports, read and post); `a=counts`; `a=gone&id=` why a game was taken off and what replaced it; `a=notify` (Notify me); `a=recomp-request` (Request a Recomp); `a=scan&id=&tag=` how a release's files were checked (each file's verdict, scanner, engines, and its VirusTotal report `link`; a ClamAV-only file shows as `grey`, still checking, since 4.13). |
| `api/gh.php`, `api/gl.php`, `api/sf.php`, `api/fj.php` | A project's releases from GitHub, GitLab, SourceForge or a Forgejo server, through this site, cached, all in GitHub's JSON shape. |
| `api/ig.php` | Instant Gaming cards: `{on, launcher, round, v, all, cc, cur, l: {id: {t, p, price, old, pct, off}}}`. Show cards only when `on` and `launcher` are both true. `?go=<id>&v=<variant>` redirects to the store (counts the click); `?e=view&id=&v=&r=` counts a view. |
| `api/backup.php` | Emergency backup, only for games the catalog marks `backup: true`: `?id=` gives `{version, tag, files: [{name, os, size, sha256, url}]}`; `?get=<id>&f=<name>` redirects to the file on Backblaze (15-minute link, 40 an hour per address). |
| `api/ach.php` | Recomp Achievements (dev only for now; test accounts). |
| `api/spons.php` | Sponsor spots running today. |
| `api/play.php` | Player reports and version scores. |

## Playtime sync (4.13, launcher PR #251)

Code: `admin/inc/rl_playtime.php`, calls in `api/launcher.php`. Table `rl_playtime` (uid, pc, game, secs, last, updated; key uid + pc + game): each signed-in PC's own total per game. `game` is the catalog id, or `<recomp id>--<game id>` for one game of a recomp that carries several.

- **`POST a=play`** (token) `{games: [{game, secs, last}]}`, up to 500 (body up to 256 KB). Each goes into this PC's row keeping the larger of stored and sent for secs and last, so a late or repeated send never lowers anything. Skipped, not refused: secs over 20 years, last more than a day in the future, bad ids. Ids not in the catalog are kept. Answers `{ok: true}`.
- **`GET a=play`** (token) `{games: {id: {secs, last}}, titles: {id: {secs, last}}}`: summed over the account's PCs, `last` the latest. A replaced listing (the catalog's `replaced`) counts as the one that replaced it, its games too. A title is the sum of its recomps (the catalog's `titles[].pc`); a recomp that carries several games counts toward a title only through its games the catalog maps to it (`games[].title`, set when a title lists `<release>--<game>`). The catalog part is kept in `recompstore-private/rl-play-map.json`, rebuilt when `pc-catalog.json` changes.
- Limited per PC (its token): 120 sends and 120 reads an hour (`rl-play-put`, `rl-play-get`).
- The account page (cookie): `GET a=playtime` (each game's total and each PC's share; a removed PC shows as one) and `POST a=playtime-clear {game?}` (that game, or everything, on every PC; the next full send from a PC brings its own numbers back). The Playtime card is on the account page.
- Removing a PC keeps its rows; deleting the account deletes them (`rs_rl_forget`). The data download includes them. Private to the account: nothing public or community-wide reads this table.
- Save copies' `played` (`save-put`) is separate: it only labels copies in the launcher's save picker and isn't counted here.

## Telling launchers to look now

`inc/rlpush.php` posts to a push channel on ntfy.sh. The message carries no data the launcher trusts; it only makes launchers (0.75 Beta 10 and later) read `version.json`, the catalog or a game's releases straight away instead of on their next check.

## When you change what the launcher reads

1. Add or change it here (and in the endpoint's own top comment).
2. Keep old launchers working: add fields, don't rename or remove ones they read.
3. Note it in the next launcher prompt so the launcher side gets built.
