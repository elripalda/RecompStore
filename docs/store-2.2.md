# Recomp Store 2.2: Library > On this PS5, the App manager, and the home screen

## Why Open needed fixing
ShadowMountPlus 1.7 changes nothing on the home screen while a PPSA/CUSA title is running
(`scan_deferred_reason: game_active`; uninstall and unmount answer 409 `game_active`), and the store
is one. So an app installed while the store is open can't get its tile until the store closes.

## The launcher (payload/launcher)
`/data/recompstore/launch/job.txt`, one `key=value` a line:

    token=<the store's>
    title=<PPSA12345>          the app to open (mode=refresh: PPSA92000)
    name=<its name>            optional, for the toast
    mode=refresh               optional: refresh the home screen, then open the store again
    do0=forget PPSA12345       optional, up to do15: tile changes waiting for the store to close
    do1=unmount PPSA12345        (forget: /api/v1/games/uninstall; unmount: /api/v1/games/unmount)

Once the store has gone it makes the `do` changes (a busy answer is tried again for 15 s), then:
- open: tries the app; if it isn't on the home screen yet, asks ShadowMountPlus to scan, toasts
  "Adding <name> to your home screen", and tries every 2 s (scanning again every 15 s) for 90 s.
  `status.txt` phase=done, or failed (and the store opens again and says why).
- refresh: scans, toasts, waits for `/api/v1/games` count to settle (10 to 40 s), toasts, writes
  phase=refreshed smp=1|0 changes=N, and opens the store again (a toast says how it went).

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

The ShadowMountPlus rows: Refresh home screen (mode=refresh) and Reload ShadowMountPlus (sends the
player's chosen file to the loader again, which restarts a running one). Settings > General's
Refresh row does the same as the first.
