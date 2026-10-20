# Recomp Store 2.2: Library > On this PS5, the App manager, and the home screen

## Why Open needed fixing
ShadowMountPlus 1.7 changes nothing on the home screen while a PPSA/CUSA title is running
(`scan_deferred_reason: game_active`; uninstall and unmount answer 409 `game_active`), and the store
is one. So an app installed while the store is open can't get its tile until the store closes.

## The launcher (payload/launcher)
`/data/recompstore/launch/job.txt`, one `key=value` a line:

    token=<the store's>
    title=<PPSA12345>          the app to open (mode=refresh or reload_smp: PPSA92000)
    name=<its name>            optional, for the toast
    mode=refresh               optional: refresh the home screen, then open the store again
    mode=reload_smp            (2.2.1) send ShadowMountPlus again, then as refresh
    path=<file>                (2.2.1) the player's ShadowMountPlus .elf (Settings), when there is one
    do0=forget PPSA12345       optional, up to do15: tile changes waiting for the store to close
    do1=unmount PPSA12345        (forget: /api/v1/games/uninstall; unmount: /api/v1/games/unmount)

Once the store has gone it makes the `do` changes (a busy answer is tried again for 15 s), then:
- open: tries the app; if it isn't on the home screen yet, asks ShadowMountPlus to scan, toasts
  "Adding <name> to your home screen", and tries every 2 s (scanning again every 15 s) for 90 s.
  `status.txt` phase=done, or failed (and the store opens again and says why).
- refresh: scans, toasts, waits for `/api/v1/games` count to settle (10 to 40 s), toasts, writes
  phase=refreshed smp=1|0 started=1|0 changes=N, and opens the store again (a toast says how it went).
- reload_smp (2.2.1): sends `path` to the loader on 9021, waits for the running copy to stop answering
  `/api/v1/version` (20 s at most) and the new one to answer (45 s from the send), makes the `do`
  changes, scans, toasts "ShadowMountPlus reloaded" (or that it didn't restart, or didn't come back),
  writes phase=refreshed reload=1 smp=1|0 restarted=1|0, and opens the store again.
- In open and refresh, when nothing answers on 10101 and the job has `path`, the launcher starts
  ShadowMountPlus first (started=1).

## Why the store never sends ShadowMountPlus itself (2.2.1)
Ruben's 2.2.0 log: Reload ShadowMountPlus, with 1.7beta5 already running, and the store at once showed
"0 folders on this PS5", couldn't write its own folder, and the file picker couldn't open `/data`,
until the store was opened again. ShadowMountPlus's source says why:
- A new copy finds the running one by name and sends it SIGTERM, then waits for it to exit
  (`main.c`: `wait_for_existing_instance_exit`).
- The running one's shutdown stops its game lifecycle watcher, which ends with
  `sm_fakelib_game_shutdown()` -> `cleanup_fakelib_mount()`: every sandbox layer it mounted into the
  running title, `/data` (writable nullfs) and each `/mnt/usbN`, `/mnt/extN` alias included, is
  unmounted, and the directories it made are removed (`sm_fakelib.c`). Its `shutdown_title_mounts()`
  also takes down the `/system_ex/app` stacks; the new copy's startup sync puts those back.
- The new copy's watcher only registers NOTE_FORK/NOTE_EXEC on SceSysCore.elf and mounts the sandbox
  aliases at a title's sandbox-ready or exec (`sm_game_lifecycle.c`, `sm_shellcore_service.c`). Nothing
  walks the titles already running, so an open store never gets `/data` back.
So ShadowMountPlus is only ever sent by the launcher, after the store's exit (which already let go of
its aliases). Starting one that isn't answering from inside the store isn't safe either: nothing on
10101 doesn't mean nothing is running (it opens its API last, after its startup sync; `api_enabled=0`;
another `api_port`; a version without the API), and a copy that is running would be replaced the
same way. `refresh_smp()` now only asks a running one; when nothing answers it returns 1 and the
launcher starts it on the next Open or Refresh home screen.

If the store loses its folder anyway (ShadowMountPlus restarted from elsewhere), `Engine::storage_lost()`
notices: the folder opened and took a file at start (`data_works_now`), and now `opendir` fails with
EACCES/ENOENT/EPERM (looked at every 2 s from the screen, and whenever a write into it fails). The
store then says once, in a box: "Recomp Store lost access to the console's storage (ShadowMountPlus
restarted). Close Recomp Store and open it again." On this PS5 shows that instead of its list, the
list and the installed apps aren't read again (no "0 folders"), and the launcher handoffs refuse with
the same text.

The store keeps the waiting changes in `/data/recompstore/home-todo.txt` and hands them to the
launcher with any Open or Refresh home screen; they're cleared once the launcher has started.

## Library > On this PS5 (store_app_manager.cpp, Engine::console_apps)
Every folder in `/data/homebrew` and the drives' homebrew folders (M.2, extended storage, USB), with
its param.json's title ID, name, version and icon, where it is, its size (worked out on a thread of
its own), and whether Recomp Store installed it (its marker and install record). Square sorts by size.

App manager (`Engine::manage`), jobs on the worker:
- `mdelete`: one folder directly in a homebrew folder (never PPSA92000, never one the store installed:
  those go through `remove()`, from the sheet or the app's page). The copier deletes it (it can act
  as a game's own user), checked gone after; the tile is forgotten now or queued.
- `mmove`: copied whole to another homebrew folder (copier kind `tree`, or `fs::copy_tree` in the
  store), every file checked at its size, then the old folder deleted. Refused when the place has a
  folder of that name or too little room (statvfs). Queues `unmount <id>` so ShadowMountPlus finds it
  in its new place.
- `mretitle`: the next free ID in the title ID guard's block (PPSA97xxx), written into param.json
  (titleId, contentId, conceptId), the folder renamed to it; a store install is remembered in
  moves.json so its updates follow. Queues `forget <old id>`.

The ShadowMountPlus rows: Refresh home screen (mode=refresh) and Reload ShadowMountPlus
(mode=reload_smp: the store closes, the launcher sends the player's chosen file again, which restarts
a running one, and opens the store once it answers). Settings > General's Refresh row does the same
as the first.

## 2.2.2 (build 36)
Everything below reads the 2.2 catalog fields in docs/site-2.2.md. A catalog without them (`tabs`,
`smp`, `whats_new`, a chart section's `category`, the charts' `also`) behaves as 2.2.1 did.

### Library
- Views in this order: My Apps, On this PS5, Updates, Betas.
- My Apps has a row under the views: All or Favorites, Grid or List.
- On this PS5 is a grid by default (Square, or the row, switches to a list). The row has Sort (Name,
  Biggest, Newest, Location), Show (All, From Recomp Store, Not from the store, then each drive),
  Select, Storage, Refresh home screen and Reload ShadowMountPlus. ShadowMountPlus's state sits at its
  right end. The view, sort and filter are kept in prefs.json (`consoleView`, `consoleSort`,
  `consoleFilter`).
- Select marks apps (never Recomp Store itself); Delete asks once, listing them and their total size.
  `Engine::manage_many` is one job, kind `mdeletes`: each folder goes as Delete does, the store's own
  installs through `run_delete` (their records go too), the rest through `run_manage_delete`. PPSA92000
  is dropped before the job starts and `manageable()` refuses it again.

### The download queue (Engine::queue_*)
- Install, Update, Update all, Versions, phone installs and auto-update add to it; it's kept in
  `/data/recompstore/queue.txt` (`id<TAB>version<TAB>root` a line) and carries on after a restart.
- `queue_pump()` starts the next one only when nothing else is going and the last result has been
  taken; the screens pump it each frame unless a question (title ID clash, download place moved,
  Delete) is open, or the store is closing.
- A clash or a moved download place on an app whose page isn't open stops only that app: it waits
  in Downloads as "Needs your answer" with the download kept, and Cross there asks the question.
- `cancel_job()` stops an install while it downloads (`JobState::cancelled`); the partial zip goes.
- Downloads (Options, or the pill at the top right) lists what's going, what waits (Remove) and what
  finished. App pages say "Queued (3rd)".

### Where a new app goes
- With more than one place, Install asks where. Places are ShadowMountPlus's `/api/v1/storage`
  `destinations` that are homebrew folders (a scanned drive root stands for its homebrew folder), else
  every connected drive's. Each shows its free space; the last one picked comes first.
- `Engine::install(id, version, root)` (and `resolve_clash`, which gets the root back from the job):
  root must be one of `homebrew_roots()`. It only applies to an app not on the console yet;
  updates go where the app is.
- Before downloading: room for the zip twice on a drive (three times plus 512 MB on the console's
  storage, which holds the download), refused with the numbers.

### Storage
- `Engine::drives()`: looked at with the console's folders. ShadowMountPlus's storage when it answers
  (`available_bytes` is what's free; read-only destinations are left out), else statvfs.
- The storage view: a bar a drive (apps, the download cache, everything else, free) and the biggest
  folders. Settings > Storage has Clear download cache and the same bars.
- `Engine::clear_downloads()` deletes the files in `/data/recompstore/downloads`, never a folder, and
  never what's in use: the job's app, anything queued, the store's own update while it's downloaded
  or waiting for its installer, ShadowMountPlus while it's fetched. Each file is checked gone; what
  stayed is logged.

### ShadowMountPlus (Settings > ShadowMountPlus)
- Shows the running version (`/api/v1/version`) beside the one the catalog offers, and warns when
  it's older than `smp.min` (compared as docs/site-2.2.md says: `smp_version_cmp`) or doesn't answer.
- Get ShadowMountPlus: job `smpget`. Only `https://github.com/drakmor/ShadowMountPlus/releases/download/`
  (the parser and the job both check), at most 64 MB; the `elf` entry taken out of the zip; its size
  and SHA-256 checked against the catalog; saved as `/data/recompstore/payloads/shadowmountplus.elf`,
  read back and checked again, then made the chosen file. Then Reload hands it to the launcher
  (mode=reload_smp). The store never sends it itself. A loader that starts ShadowMountPlus at boot
  keeps its own copy: the player updates that one.

### Tabs, Discover, What's new
- Tabs: the player's (Customize > Tabs: on or off, hold Square to move, Back to the default), else
  the catalog's `tabs`, else all four. A source can be pinned as a tab from its sheet in Settings >
  Sources. A part of the store without a tab still opens from Discover's buttons.
- Chart sections with `category` show only that part of the store's apps, in the chart's order.
- Category icons are the logo's cyan unless Customize > Icon colors is Colorful.
- What's new (`whats_new`) shows once, on the store version it names, not on a store installed for
  the first time; seen versions are kept in prefs.json (`seenNews`). Settings > About opens it any
  time, from GitHub's release notes when the catalog has none. The body is drawn as
  docs/site-2.2.md section 5 says.

### Favorites, auto-update, an app's page
- Pins are favorites now: Square anywhere, or the star on an app's page. My Apps can show only
  them; Discover has a Favorites row (Customize turns it off).
- Auto-update: Settings > General's "Update everything automatically", or Auto-update on one app's
  page. After each check (at start, every few hours, Refresh), `Engine::auto_update_picks` gives the
  installed apps with a newer version that may update on their own (not kept at an older version, not
  a beta without a signature, not one whose download place moved), and they join the queue.
- Under an app's page: More like this (same part of the store, most labels in common), People also
  installed (the charts' `also`, signed-catalog apps only) and More from its author.

### The Discover header
The picture fades in over the frosted art in thin slices on whole pixels, and the wash fades out over
it, so the frost under both sides is the same and the join can't show, still or mid-turn. A turn
crossfades from the slide before (not from midnight), its words out before the new ones come in.
