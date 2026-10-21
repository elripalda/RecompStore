# Recomp Store 2.3.0 (build 38): the contract

What 2.3.0 adds, between the store, its payloads, the site and the admin. Written before the code;
the code follows it. Everything in api-2.0.md, api-2.1.md, site-2.2.md and store-2.2.3.md still
holds. A catalog without the new fields behaves exactly as it did in 2.2.3.

## 0. Rules that hold for all of it

- The store never sends ShadowMountPlus itself (only the launcher does, as before).
- Payloads use only the imports the shipped payloads already use (`payload-proven`), except the
  receiver, which adds five network calls: `accept`, `bind`, `listen`, `getifaddrs`, `freeifaddrs`.
- The store app's own imports stay exactly those of 2.2.3 (`imp16s`).
- Every file operation checks what it's given first, logs what it did (`store.log`), and is
  checked after (sizes, CRC-32s, or that a path is gone).

## 1. Space: the real figures (the drive-size bug)

The store's sandbox sees `/data` as a fixed 64 GB with 16 GB free (`statvfs` in the sandbox lies).
From 2.3.0 the store never uses that figure.

- `real_space(path)` asks ShadowMountPlus `GET 127.0.0.1:10101/api/v1/storage` (at most every 15 s)
  and reads `mounts[]` (`mount_point`, `total_bytes`, `available_bytes`) and `destinations[]`
  (`path`, same fields). An entry with no total, or more free than total, is skipped.
- Console storage (`/data`): the `/user` mount, else the destination `/data/homebrew`, else
  `/user/data` or `/data`. No answer: the size is **unknown**.
- A drive under `/mnt` (`/mnt/ext0`, `/mnt/usb0`, ...): its mount or destination, else its own
  `statvfs` (a drive's own figure is right).
- Used by: Settings > Storage, My Apps > Drives, the install free-space checks (download and unpack),
  setups, Move, the "Where to install" chooser, upload limits, backups and the phone's state.
- Unknown, or the listed folders add up to more than the total: the drive says **Size unknown**
  with a faint bar, and installs aren't refused for space (as before ShadowMountPlus).

## 2. Upload from your phone (the receiver payload)

`assets/payloads/recompstore-receiver.elf`, from `payload/receiver/receiver.c` and its page
(`page.html`, `code.html`, made into `page.c` by `tools/make-receiver-page.py`).

The store starts it only while an upload screen is open: it writes
`/data/recompstore/receiver/job.txt` and hands the payload to the loader on port 9021.

    token=<store's number>   code=<6 random digits>   title=<the page's heading>
    dest=<folder uploads go to, "" none>          ext=<".iso .bin", "" any>
    backups=<the backups folder, read only, "" none>   backup_id=<an app ID: its backups only>
    max=<largest file, bytes>    idle=<seconds before it stops, 30..3600>

The receiver reads the job once and deletes it. It listens on 8380 (or the next free port up to
8389) and writes `receiver/status.txt` atomically:

    token= state=starting|listening|receiving|sending|stopped|failed|locked  ip= port=
    file= bytes= total= speed=<bytes/s>  files=<received>  last=<path of the last one>  error=  at=

It stops when `receiver/stop` appears (the store writes it when the screen closes), after `idle`
seconds with nothing happening, or never starts when the job isn't right.

HTTP (one connection at a time, `Connection: close`):

| Request | What it does |
|---|---|
| `GET /` (no code) | the page that asks for the code |
| `GET /?c=<code>` | the upload page |
| `GET /api/info?c=` | `{ok,title,ext,max,uploads,backup_id,backups:[{id,file,size,at}]}` |
| `GET /api/backup?c=&id=&file=` | one backup zip, read only, from `backups/<id>/` |
| `PUT /api/upload?c=&name=&to=files\|backup` | the body (needs `Content-Length`, no chunks) |

Refused: a wrong code (compared in constant time; 5 wrong ones lock it: `state=locked`), no code
(not counted), addresses outside 10/8, 172.16/12, 192.168/16 and 169.254/16, names with `/ \ ..`,
control characters or `: * ? " < > |`, hidden names, the wrong extension (`to=backup`: `.zip` only),
anything bigger than `max`, empty files, and anywhere outside `dest` and `backups`. An upload is
written as `.<name>.part`, synced, its size checked, then renamed; a name that's there already
becomes `name (2).ext`. A transfer cut off, or stopped by the store, deletes its part.

In the store (`PanelUpload`):
- **A game's file slot**: the file chooser's first row, **Upload from your phone**. Files go to
  `/data/recompstore/uploads/<app id>/`; `ext` is the slot's (`iso`: `.iso`; `file`: its `ext`;
  `image`: `.iso .gcm .ciso`; `stfs` and `arcade`: any). When one arrives, the screen closes and the
  slot's setup starts with it, exactly as if it had been chosen in the chooser.
- **Settings > Storage > Upload a file**: files go to `/data/recompstore/uploads/`; the backups
  are listed for download. The file chooser shows **Uploads** as a place.
- The screen shows the QR code for `http://<ip>:<port>/?c=<code>`, the address and the code as
  text, and what's arriving. No address: it says to find the PS5's IP address in Settings >
  Network > Connection status.
- `max` is the real free space less 512 MB (100 GB when the space is unknown).

## 3. Save backups

Only for an app the store installed whose catalog entry lists `saves`, and only those paths.

- A backup is a **stored zip** (no compression, no zip64: under 4 GB) at
  `/data/recompstore/backups/<app id>/<app id> YYYY-MM-DD HHMMSS.zip` (`... auto.zip` when made
  before a restore). Its first file is `recompstore-backup.json`:
  `{"format":1,"app":id,"name":..,"version":..,"made":"2026-10-20T19:15:00Z","auto":false,
  "saves":[{"save":"userdata","as":"userdata/"}, {"save":"/data/sm64","as":"data/sm64/"}]}`.
  `save` is the path as the catalog says it; `as` is where its files are in the zip.
- Written by the copier (kind `backup`), which can read a game's own 0700 folders as their owner,
  or by the store when there's no loader. Written as `<zip>.part`, synced, read back (every file's
  CRC-32 against what was read), then renamed. A copy to a USB drive goes to
  `/mnt/usbN/RecompStore Backups/<app id>/` and is checked the same way.
- **Restore** (copier kind `restore`, or the store): the zip is checked whole first (manifest app is
  this app, every CRC-32 right, every name inside its `as`, no `..`). The current saves are backed up
  (`auto`) first. Then, per save: unpacked into `<save>.rs-new`, checked, the old one renamed to
  `<save>.rs-old`, the new one renamed in, checked again, the old one deleted. Any failure puts the
  old one back. Restored folders and files are 0777, as installs are.
- On the game's page: **Saves**: Back up now (also to a USB drive when one is in), the backups
  newest first (Restore, Delete), **Send to your phone** (the receiver, `backups` + `backup_id`).
  A zip sent from the phone lands in `backups/<id>/` and is listed like any other; it's checked when
  restored.
- Delete and Move offer **Back up saves first** (on when the game has saves on the console).

## 4. Send logs: what happened

- The store's Send logs box has five choices, in this order: **Bug, Crash, Won't install, Slow,
  Other**, and the message needs at least 3 characters that aren't spaces. Send stays off until both
  are done; the hint says "Pick what happened and write a few words".
- `POST /api/logs.php` takes `category` = `bug` | `crash` | `install` | `slow` | `other`. Anything
  else is kept as `other`; logs from older stores have none ("No category").
- Admin > Inbox: a pill on each log, and filter chips: All, Bug, Crash, Won't install, Slow, Other.

## 5. Manage your PS5 from your phone (Link a phone)

### What the console reports (`a=state`, every minute while a phone is linked, and soon after a change)

    {"v":2,"store":"2.3.0",
     "apps":[{"id","name","version","size":bytes,"where":"Console storage","root":"/data/homebrew",
              "folder":"/data/homebrew/PPSA92011","update":"1.2.0"|"","fav":bool,"auto":bool,
              "store":bool,"category"}],
     "drives":[{"label","root","total","free","sized"}],
     "queue":{"now":id|"","step","done","total","waiting":[ids]},
     "requests":[{"id":n,"kind","app","state":"waiting|done|failed|ignored","text"}],
     "sources":[...], "free_gb":n}

`store:false` apps are other homebrew in the homebrew folders (Move and Delete only). Up to 200 apps;
the whole state stays under 60 KB.

### What a phone can send (`a=send kind=`)

| kind | data | On the PS5 |
|---|---|---|
| `install` | `{app}` | queued (as before); also "Update" |
| `cancel` | `{app}` | taken out of the queue if it's waiting (not the one going) |
| `favorite` | `{app, on}` | its star |
| `autoupdate` | `{app, on}` | its Auto-update switch |
| `delete` | `{app}` or `{folder}` | a **request**: the PS5 asks first |
| `move` | `{app}` or `{folder}`, `{to: root}` | a **request**: the PS5 asks first |
| `text` | `{text}` | typed into the PS5's open search or keyboard (≤ 200 characters) |

`send` answers `{ok, req: <id>}`. Delete and Move put up on the PS5: "Your phone asks to delete
<name> (<size>). Delete / Ignore" (Move: "... to move <name> (<size>) to <drive>. Move / Ignore");
the phone shows "Waiting for you to confirm on the PS5" until `requests[]` says how it went. A
request waits 10 minutes at most on the PS5.

### The site

- `link_queue` takes the new kinds; `data` is checked (app ID `[A-Za-z0-9_.-]{3,48}`, folder
  `^/(data|mnt/(ext|usb)\d)/homebrew/[A-Za-z0-9_.-]{1,64}$`, `on` 0/1, `to` one of the state's roots).
- `link_state` is the last state; it's deleted when the last device is unlinked or forgotten.
- Rate limits: `send` 60 an hour per address (as before), delete/move 20 an hour per device.
- The phone page (My PS5): **Updates** (one tap queues), **Storage** (a bar per drive), **Downloads**
  (cancel a waiting one), and each app: Favorite, Auto-update, Move to, Delete.
- `rs_log` lines for each request sent.

## 6. Leftovers

- My Apps > Drives: Cross on an app moves it (the same Move as On this PS5).
- Clear download cache runs on a thread with progress, and never deletes a zip an install, the queue
  or the store's own update is using.

## 7. Ready to play

On a game's page, a checklist: **Payload loader** (something answers on 127.0.0.1:9021: a
connection opened and closed with nothing sent, at most every 30 s), **ShadowMountPlus** (running,
and at least the catalog's `smp.min`), **Free space** (for the app where it goes), and each required
file slot. The catalog's new `requires` lists more the game needs, ticked in the admin: `loader`,
`smp`, `kstuff`, `etahen`, `internet`, `usb`. What the store can't check shows grey: "Needed: kstuff".
(`needs` already means "You bring", so the list is `requires`.)

## 8. Likes

- A thumbs-up on each app page with its count. `POST /api/likes.php a=like|unlike app=` (the
  console's install and token, as stats). One per console and app.
- Charts gain `likes: {id: n}`; `GET /api/likes.php a=mine` (form) gives `liked: [ids]`.
- Discover section type `liked` ("Most liked"), with an optional `category`.

## 9. Collections

- Admin > Collections: a name, a line about it, and apps in order.
- Catalog `collections: [{id, title, blurb, ids}]`; Discover section type `collection` (`collection`:
  its id) shows it as a row; Cross on its title opens the collection page: its apps and **Install all**
  (asks where to install once, then queues each one not installed).

## 10. Search

- Library: a **Search** chip opens the keyboard; results update as you type (name, author, tags).
- **Type on your phone**: with a phone linked, its My PS5 page has a box that sends `text`.

## 11. Tester feedback (betas)

- A beta's page: **Feedback**: Works / Crashes / Slow / Other, a note (3 characters at least), and
  its logs. Sent as Send logs with `feedback=works|crashes|slow|other` and `build=<version>`.
- Admin > Feedback: each beta's feedback, by build.

## 12. Discord posts

- Admin > Settings > Discord: the webhook (pasted by the owner; only the end of it is shown again,
  like `…/webhooks/1234…abcd`; never logged, never in the catalog), switches for new apps and updates
  (a new version), and **Send a test** (its own one a minute, so a test never holds back a post).
- After a signed publish goes out, the site compares the new catalog with the one before and posts
  one message (embeds, at most 10) for what's new. At most one post a minute; a post that fails is
  logged without the address and never stops the publish (3 s timeout).

## 13. Restore my apps

- The console keeps its list of installed store apps in its linked-phone state, and on each linked
  device's record (`link_apps`: one list per device and console, the 4 newest per device, kept after
  unlinking; a state with no store apps never writes over one), and can save it to a USB drive as
  `RecompStore Backups/my-apps.json`: `{"format":1,"made":..,"apps":[{"id","name","version"}]}`.
- Settings > Restore my apps: from a linked phone (`POST /api/link.php a=myapps` with the console's
  install and token: `{ok, lists: [{from, made, apps}]}`, the lists kept for the devices linked to this
  console now, newest first), from a USB drive, or from a phone upload (a `.json`). It shows what will come back
  and queues each app not installed.

## 14. Mods

- Catalog per app: `modsDir` (default `mods`, inside the game's folder) and
  `mods: [{id, name, author, blurb, repo, asset, sha256, size, target}]`: a GitHub release file of
  `repo`, `asset` being `<tag>/<file>` (so `https://github.com/<repo>/releases/download/<tag>/<file>`
  only), its SHA-256 (needed), and
  `target`, a folder name inside the mods folder.
- The game's page: **Mods**: Install (downloads, checks the SHA-256, unpacks a `.zip` into the
  target or copies the file there), Turn off (renames the target to `<target>.disabled`), Turn on,
  Remove. Only inside the game's own mods folder; names checked; each step checked after.
- Admin: a Mods editor on the app's page.

## 15. ShadowMountPlus's tries (the installer hotfix, in 2.3.0 too)

ShadowMountPlus stops trying to register a title that isn't in the system's app list after two tries
(and stops mounting after two failures), until it restarts or a scan says `{"reset_attempts":true}`.
A store deleted from the home screen never came back with a plain `{}` scan.

- The installer (from hotfix-2.2.3-installer, da5e78f): scans with reset_attempts, checks
  `/api/v1/games/info` until the store is really on the home screen (up to 3 minutes), and says to close
  an open app when the scan is held back, or what to do when it never shows.
- The store: every scan (Refresh, after an install or a move, `smp_scan_again`) sends reset_attempts.
- The launcher: every scan (Open's wait, Refresh home screen, Reload ShadowMountPlus) sends it; Open
  tries once more with a reset scan before it gives up, and Refresh ends with one more reset scan.

## Version

`kVersion "2.3.0"`, build 38. The catalog's new fields set `minStore` 2.3.0 where needed.
