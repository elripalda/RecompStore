# Recomp Store 2.0: how the PS5 store and recompstore.com talk

The store version that speaks all of this is 1.9.1 and newer (the 2.0 previews, then 2.0).
Everything is HTTPS on recompstore.com. Store requests are form POSTs (application/x-www-form-urlencoded),
answers are JSON objects with `ok` (true/false) and, when false, `error` (words for the player, plain,
short, no codes). Every store request also carries `store` (its version, like 1.9.1).

## 0. A console's identity (exists since 1.1.0, extended)

A store install is known by `install` (16 lowercase hex) and `token` (32 lowercase hex, the site keeps
only its SHA-256), in the `beta_installs` table. Until now a console got them on its first beta code.
From 2.0 the store asks for them at first launch:

`POST /api/link.php  a=hello  [install, token]  store`
- valid install+token: `{ok:true, install, token, tester}` (the same ones back; last_seen and store_version updated)
- none or unknown: a new install is made: `{ok:true, install, token, new:true, tester:false}`
- `tester` (true|false): the admin marked this console as a tester (section 6).
- rate-limited per IP: at most 20 new installs per IP per hour (its own `rate_tries` table, kind `hello`;
  beta redeem keeps `beta_tries`). Over the limit: HTTP 429 `{ok:false, error}`.

The store saves them in /data/recompstore/beta.json (same file as before).

## 1. Linking a phone or PC to a console

Tables (made on first use, like the beta tables):
- `link_codes`: code CHAR(8) PK, kind ('ps5' | 'web'), install_id CHAR(16) NULL, device_id CHAR(16) NULL,
  created DATETIME, expires DATETIME, used DATETIME NULL.
- `link_devices`: device_id CHAR(16) PK, token_hash CHAR(64), install_id CHAR(16) NULL, label VARCHAR(60),
  created, last_seen.
- `link_queue`: id INT AI PK, install_id CHAR(16), device_id CHAR(16), kind VARCHAR(16), data TEXT (JSON),
  created, taken DATETIME NULL. INDEX(install_id, taken).
- `link_state`: install_id CHAR(16) PK, state MEDIUMTEXT (JSON the store pushed), updated.

A device (a phone or PC browser) is a cookie `rs_device` = device_id + "." + token (token 32 hex; site
keeps its hash), HttpOnly, Secure, SameSite=Lax, 400 days, Path=/.  One device links to one console.

### 1a. The PS5 shows a code, the phone scans it (the main way)
`POST /api/link.php  a=code  install token store`
→ `{ok, code:"K7Q4X2", url:"https://recompstore.com/l/K7Q4X2", expires:600}`
Code: 6 characters from `ACDEFGHJKMNPQRTUVWXY34679` (no look-alikes), valid 10 minutes, one use.
A new code replaces the console's unused one. The store shows it as `K7Q-4X2` and as a QR code of `url`.

Phone: `https://recompstore.com/l/K7Q4X2` (rewrite to the site; the SPA shows "Link this phone to your PS5"
with one button) → `POST /api/link.php a=claim code=K7Q4X2 [label]` (JSON or form; sets the cookie)
→ `{ok, console:{label:"PS5", store:"1.9.1"}}`. A PC types the code at recompstore.com/#/link.
Wrong or old code: `{ok:false, error:"That code didn't work. Make a new one on your PS5."}` (rate-limited).

### 1b. The website makes a code, the PS5 types it (the other way)
Phone: `POST /api/link.php a=webcode` (cookie made if none) → `{ok, code:"12345678", expires:600}`,
shown as `12-345-678`. The store's existing "Enter a code" keypad takes it: the store sends it to
`/api/beta.php a=redeem` as it does a beta code; beta.php, when the code isn't a beta code but an unused
web link code, links that device to this install and answers
`{ok:true, linked:true, install, [token], message:"Your phone is linked. You can send things to this PS5 from recompstore.com now."}`
(no `game`; `install` always, `token` only when the console had no ID yet and one was made, as with a beta
code; "phone" is the device's label, like "iPhone" or "Windows PC"). Web codes and beta codes never collide (beta codes are made avoiding live web codes and the
other way round).

### 1c. The store asks what's new
`POST /api/link.php  a=poll  install token store`
→ `{ok, devices:[{id, label, since:"2026-10-19", seen:"2026-10-19T19:02:00Z"}], actions:[{id, kind, data, from}]}`
Actions are handed out once (marked taken). The store calls it every 3 s while its Link screen is open,
every 15 s while the store is open and the console has a device, and once at start.
Action kinds and data:
- `install`  `{app:"PPSA92011"}`  install this app (a catalog app, or a custom source's app by its id)
- `source`   `{repo:"owner/name"}` or `{list:"https://..."}`  add a custom source (the store asks to confirm)
- `refresh`  `{}`  read the catalog, betas and sources again (sent after the phone redeems a beta code)
- `report`   `{report:"R7K2QX"}` the phone finished a bug report (the store just says thanks)

`POST /api/link.php  a=unlink  install token device=<id>` → `{ok}` (the store's Linked devices).

### 1d. The phone's side (cookie)
- `GET /api/link.php?a=mine` → `{ok, linked:false}` or
  `{ok, linked:true, device:{id,label}, console:{id, label, store, seen}, state:{...the store's last state...}, state_at}`
  (`console.id`: 10 hex, the same for a console every time, so the page sees a relink to another PS5;
  `state_at`: when the store last sent its state, ISO, or "" if never. Not linked but with a cookie:
  `{ok, linked:false, device:{id,label}}`.)
- `POST /api/link.php a=send kind=install|source data=<json>` → `{ok, message:"Sent. Your PS5 picks it up in a few seconds while Recomp Store is open."}`
  (validated: install needs an app id `^[A-Za-z0-9_.-]{3,48}$`; source a repo `owner/name` or an https list URL;
  `data` may be a JSON object in a JSON body or a JSON string in a form; a blocked source is refused; at most
  20 actions waiting per console)
- `POST /api/link.php a=redeem code=<8 digits>` → redeems a beta code for the linked console directly
  (same rules as beta.php's redeem), queues `refresh`, answers like beta.php.
- `POST /api/link.php a=forget` → unlinks this device (`{ok}`), cookie cleared.

### 1e. The store's state, for My PS5
`POST /api/link.php  a=state  install token store  state=<json ≤ 64 KB>` → `{ok}`
state = `{apps:[{id, name, version, update:"1.2.0"|"" , category}], sources:[{kind, value, name}], free_gb:123.4}`
The store sends it after an install, update, delete or source change, and at start, at most once a minute.

## 2. Bug reports finished on a phone

`/api/logs.php` (exists) answers, in addition: `report:"R7K2QX", url:"https://recompstore.com/r/R7K2QX"`
(6 chars, same alphabet; valid 7 days; tied to the inbox item). The store shows the QR and the code
after "Sent".
Phone: `https://recompstore.com/r/R7K2QX` → form: what happened (required), what you did before it,
up to 4 pictures (PNG/JPEG/WebP, ≤ 8 MB each), optional contact →
`POST /api/report.php a=finish code=R7K2QX` (multipart: `what`, `before`, `contact`, `pictures[]`) → `{ok, message}`.
The page first asks `GET /api/report.php?a=info&code=R7K2QX` → `{ok, app:{id,name}, version, sent, finished}`. The text and pictures join that
inbox item (pictures stored under a non-public folder, served to the admin only). The admin Inbox shows
them with the logs. If the phone is linked to that console, queue `report`.

## 3. GitHub, through the site (rate limits, and custom sources)

### 3a. A repo's releases, cached
`GET /api/gh.php?repo=owner/name` → the same JSON array GitHub's `/repos/{repo}/releases?per_page=30` gives,
trimmed to: tag_name, name, published_at, prerelease, draft, body (≤ 20 KB), html_url,
assets[{name, size, browser_download_url, digest, download_count}]. Cached 15 minutes per repo (a DB table
`gh_cache`: repo PK, status INT, body MEDIUMTEXT, fetched DATETIME); GitHub's 404 → HTTP 404 with
`{"message":"Not Found"}`. Uses the admin's GitHub token (Settings, optional) when set. If GitHub can't be
reached and there's a cached copy, the cached copy (any age), with header `X-RS-Stale: 1`. With no copy:
GitHub's rate limit → HTTP 429, anything else → HTTP 502 (both `{"message":"..."}`). A repo that isn't
`owner/name` → HTTP 400. Every answer has `X-RS-Cache-Age: <seconds>`. Repo info (stars) is cached 1 hour.
The store uses this first for every release lookup and patch notes, and GitHub directly only when the
site doesn't answer.

### 3b. A custom source, turned into catalog entries
`GET /api/source.php?repo=owner/name[&asset=pattern]` and `GET /api/source.php?list=<https url>`
→ catalog-shaped JSON the store reads with parse_catalog:
```
{ ok:true,
  source: { kind:"repo"|"list", value:"owner/name"|url, name:"owner/name" | the list's name, author },
  apps: [ { id, titleId, name, author, blurb, description, version, released, size,
            source:{type:"github", repo, asset}, icon, hero, category, tags, minStore:"1.9.1",
            custom:true, sourceName, stars, downloads } ],
  errors: [ { repo, error } ] }
```
- `id`: the app's title ID when it was found (zip: param.json read from the release by Range requests),
  else `gh-` + first 10 hex of sha1(lowercase repo). `titleId`: "" when not known yet (the store learns it
  on install).
- `asset`: the release file: the list's pattern, else the newest release's `.zip` or `.7z` whose name looks
  like a PS5 build (prefers ps5/prospero/PPSA in its name; never pc/win/windows/linux/mac/source/src/debug
  symbols); a zip is confirmed by its directory having `<folder>/eboot.bin` and `sce_sys/param.json`.
  The pattern returned is the exact name with its version parts turned into `*` when the name has the tag in it.
- `name`: param.json's titleName (zip), else the list's name, else the repo name. `icon`: the release's
  sce_sys/icon0.png (zip), cached in /media/gh/<sha1>.png, else the owner's GitHub avatar, cached the same way
  (.png or .jpg).
- One zip with several app folders (each with eboot.bin + sce_sys/param.json at the same depth) gives one
  entry per app (at most 8), all with the same `source`. A list's `name` is used only when its repo has one app.
- Also in each entry: `kind:"community"`, `sha256` when GitHub gives the file's digest, `category` "apps"
  unless the list says otherwise (one of section 5's four), `released` (the release date).
- A `.7z` can't be looked into remotely: it's taken by its name only (a ps5/prospero/PPSA name, or the only
  candidate, or the list's pattern), so its `titleId` is "".
- Errors for a repo: not found / private; no releases; "only has a .pkg" (not supported yet); no PS5 build.
- A list: JSON ≤ 256 KB at an https URL:
  `{ name, author, apps:[ {repo, asset?, name?, category?, tags?, description?} ] }` (≤ 100 apps).
  Only GitHub repos; anything else in it is skipped with an error.
- Blocked: the admin's list (Settings: blocked sources, one repo or URL per line) → `{ok:false, blocked:true,
  error:"This source was taken down."}`. The store then drops it.
- Cached 15 minutes per source (a failure 5 minutes; in the `kv_cache` table), refreshed lazily. A list
  reads its repos for up to 60 s; repos not read in time get the error "Still being checked..." and that
  partial answer isn't cached.
`POST /api/source.php a=report  value=<repo or url>  why=<text>  [install token]` → inbox item kind `source`.

## 4. Installs counted, and the charts

`POST /api/stats.php  a=installed  install token store  app version` and `a=removed install token store app`
→ `{ok}`. Table `app_installs`: install_id, app_id, version, first_at, last_at, removed_at; PK(install_id, app_id).
Counts are per console (a reinstall isn't a new install). No IP or anything else is kept.

`GET /api/charts.php` → cached 10 minutes:
```
{ ok, built: "<ISO time it was made>",
  top:      [ {id, installs} ]   (all time, consoles that have it now)
  trending: [ {id, installs} ]   (new installs in the last 7 days)
  new:      [ id ]               (newest first by when the admin first published it)
  updated:  [ id ]               (newest release first, from the GitHub cache; an app with no known release
                                   date counts from when it was first published)
  stats:    { "<id>": {installs, stars, downloads} }
  community:[ {id, installs} ] }
```
Only ids in the published catalog (or published custom-source apps it has seen installed ≥ 3 times, listed
under `community`). GitHub stars and release-asset download counts come from the repos' cache, refreshed
lazily (at most 10 stale repos per charts rebuild).
Catalog sections (admin Discover): new types `top`, `trending`, `new`, `updated`, each
`{type, title, layout}` (layout "row" | "grid" | "wide"), read by 1.9.1+ only (older stores leave them out).

## 5. Catalog fields new in 2.0 (from the admin)
`category` ("games" | "emulators" | "apps" | "tools"; anything else is "games"), `tags` [≤ 6 short], `canMove` (false only when set),
Discover section types `category`, `top`, `trending`, `new`, `updated`. A `.7z` download makes `minStore` 1.9.1,
and so does a category other than "games". Where such entries are published: section 6.

## 6. Rollout: what 1.x and the public see, testers

### 6a. catalog.json: 1.x stores never see 2.0 entries
1.1.x reads only `apps`, `newerStoreApps`, `featured` and `comingSoon`. From now on:
- An app whose `category` isn't "games", or whose `minStore` is 1.9.1 or newer (a `.7z`, a category...), is in
  **`v2Apps`** and never in `apps` / `newerStoreApps`. Same entry shape. Every non-games entry has
  `minStore` ≥ 1.9.1. Each list is in the admin's order; `order` runs across all of them.
- A Coming soon entry whose category isn't "games" is in **`comingSoonV2`** (with its `category`), not `comingSoon`.
- `featured`: only ids in `apps` / `newerStoreApps`. **`featuredV2`**: the admin's whole featured list, in
  order (any list). The 2.0 store reads `featuredV2` when it's there.
- Discover sections are as before; a `games` section or a spotlight may name ids in `v2Apps` (1.x skips ids
  it doesn't have, and section types it doesn't know).
- The 2.0 store lists `apps` + `newerStoreApps` + `v2Apps` (and `comingSoon` + `comingSoonV2`).
- **`site.v2`** (true|false): whether the website shows its 2.0 side to everyone (6d). Not for the store.

### 6b. Testers-only apps
A per-app admin setting, Visible to: Everyone (default) / Testers only. A testers-only app (or Coming soon
entry) is left out of catalog.json entirely, and so out of the website, link cards, `/api/ids.php` names
(listed as "An app in testing"), and the charts (`top`, `trending`, `new`, `updated`, `stats`, `community`).
`/api/source.php` only reads GitHub, never the catalog, so it has nothing of them to leave out.

### 6c. Testers, and the test catalog
`beta_installs.tester` (0/1), set in admin Settings, Testers: consoles listed by their **Console ID**, the
first 8 characters of `install` as they are (lowercase hex, e.g. `563a56c0`); testers first, then the 50
seen most recently, or search by the start of the ID. `link.php a=hello` answers `tester` (section 0).

`POST /api/catalog.php  a=test  install token store`
- a tester → HTTP 200, the same JSON as catalog.json, built the same way (same `v2Apps` / `comingSoonV2` /
  `featuredV2` split) but made on request from the admin's apps **as saved** (no Publish needed; not cached),
  with the testers-only apps in: each of those entries has `"testing": true` (other entries don't have the
  field). `featured`, `featuredV2` and Discover sections may name them.
- not a tester → HTTP 403 `{ok:false, tester:false, error:"This console isn't a tester."}`; unknown console or
  wrong token → HTTP 403 `{ok:false, tester:false, error}`. Anything but a POST with a=test → HTTP 400.
  The store then reads catalog.json as everyone does.
- last_seen and store_version are updated, as with every store request.

### 6d. The website's 2.0 side
Admin Settings, "Recomp Store 2.0 on the website": Only me (preview, the default) / Everyone. Saving rewrites
catalog.json's `site.v2` at once, without publishing anything else that's waiting.
- `site.v2` false: visitors see the site as before 2.0: `apps` + `newerStoreApps` + `comingSoon` only, no
  category buttons or pages, no chart rows or ranks, no category sections, no "On consoles", no My PS5 in
  the menu, no Title IDs link or page, and a game's page has just "Install with Recomp Store". Link cards
  (`/game/<id>`) and `/api/ids.php` names leave out `v2Apps` / `comingSoonV2`.
- The full 2.0 side shows when `site.v2` is true; or to the logged-in admin (`GET /admin/me.php` →
  `{ok, admin}`; under /admin because the admin's session cookie only goes there), with a "Preview: Only you
  see 2.0" ribbon; or to a browser linked to a PS5 (it has 2.0), without the ribbon.
- `/l/<code>`, `/r/<code>`, `#/link`, `#/my-ps5` and every API work for everyone either way.

### 6e. Admin Import
Admin, Import: a JSON file or pasted JSON `{apps:[{id?, name, author, category, tags, blurb, description,
repo, asset, icon, hero, canMove?}]}` (≤ 300; a bare array works too). A preview shows each as new, needs
something (a title ID `^[A-Z]{4}\d{5}$` or a .zip/.7z name), already here by title ID or repo (skipped),
twice in the file (skipped), or can't go in (no name, no repo, an ID kept by the store or taken). Title ID
and zip name can be read from the repo's newest release (the zip's param.json, as source.php does; not for
a .7z) and typed in the preview; category can be changed. Imported: `kind:"community"`, Testers only,
category as given ("emulator" → "emulators", missing → "apps"), no `needs`; pictures on GitHub are copied
here (icon 512, banner 3840/1920), others linked as given. Not published: testers get them at once (6c).

### 6f. Additions from the store side
- The store reads `/api/ids.php` with each catalog refresh (kept in ids.json): the title ID guard never gives a
  moved app an ID listed there (`store` and `taken`), nor one in the catalog, nor one on the console.
- Two apps were already out with IDs in the move block before it was kept: PPSA97358 (XPSemu) and PPSA97510
  (PSXS5). The admin lets them be listed (`RS_MOVE_BLOCK_IN_USE`); the store skips them too.
- Import with "Look things up on GitHub" ticked also fills an empty description from the repo's own GitHub
  description (one cached question per repo). The Prospero catalog import file (site/import/, made by
  site/tools/prospero-import.py) has no descriptions on purpose, so each comes from the developer's page.
- A custom source's app: the store reads its title ID from the release when the site couldn't (an id that
  starts `gh-`), picks the app folder whose param.json has the entry's `titleId` when a release has several,
  and keeps what it learned in /data/recompstore/titles.json (the install's own record follows if the site
  later gives the app by its title ID).
- Advanced mode: new apps can install to a drive's homebrew folder (/mnt/ext1, /mnt/ext0, /mnt/usbN, as
  ShadowMountPlus scans them). The store finds apps in all of them; updates go where an app is.
- Pictures: `POST /api/art.php` with `paths=` (newline-separated `/media/...` paths, up to 24) answers, for each
  path in order, a line `<path> <size>` and then that many bytes (0: not on the site; -1: past the answer's 12 MB
  cap, ask on its own). The store asks for icons first, then logos, then banners, 24 to a request, and falls back
  to one request each when the site has no art.php. A job the player starts (an install) goes before the rest.
- After an install or a game setup the store asks ShadowMountPlus to rescan at once and again 12 and 30 seconds
  later: ShadowMountPlus passes over a folder changed in the last 10 seconds (its `stability_wait_seconds`).
  An app that won't open has it asked again from its page.
- The Prospero import leaves games out (`--with-games` keeps them): a game goes in by hand once it's tested with
  the store's ISO/ROM setup.
- (2.0.1) A Title Update slot can be `"raw": true` (kind stfs, not multiple): the package is copied into the slot's
  folder as it is, named `name` (its own name when empty), instead of unpacked, and the slot is set up once that file
  is there. For a port whose own installer reads the update file (GTA IV reads TU_1A581VI_000000K000000.0000000000205
  from its install folder). The admin makes such a game need Recomp Store 2.0.1.
- Beta zips from Cloudflare R2 (site only, no store change): with R2 set in admin Settings (account, bucket, an
  Object Read & Write token's key pair) and a game's beta zip copied there from its Beta card (`admin/r2.php`:
  uploads `<id>/<beta_file>`, then checks size, MD5 and two ranged reads through a signed link), beta.php's list
  gives that tester a SigV4 presigned GET link good for 12 hours instead of `a=file`. The store's 4 MB Range
  pieces work on it unchanged. The copy only counts while it matches the game's current `beta_file` and size, so
  a new beta zip goes back to this site until it's copied again. Unticking "Testers download beta zips from R2"
  sends everyone back to this site.
