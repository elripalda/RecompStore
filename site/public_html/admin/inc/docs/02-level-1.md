---
slug: level-1
title: "Level 1: plays from RecompLauncher"
summary: The player's copy and how your game starts, plus the paths you can use anywhere.
audience: pc
sort: 20
---
With `game` and `start`, installing your recomp ends with it ready to play. What the player sees:

1. They install your recomp. If the launcher already found their copy in one of their game folders, it offers that one. Otherwise they drop the disc image on the setup screen, or pick it in the launcher's file browser.
2. The setup screen reads the disc, checks it's your game, reads the whole image to make sure it's complete, and writes your settings.
3. "Ready to play", with Play and Settings. Play starts your program straight away.

## `game`: the player's copy

- `kind`: `"iso"`, `"folder"` or `"iso_or_folder"`.
- `label`: what to ask for. Name the region or serial if only one works.
- `check`: how to tell it's the right game. Use whichever fits:
  - `file`: a file that should be in the folder, like `"default.xex"`.
  - `xdvdfs`: a file on an Xbox 360 disc image.
  - `iso9660`: a file in the top folder of a PlayStation 2 (or any ISO 9660) disc image.

The same check is how the launcher spots your game in the player's folders, so a disc image named `backup-07.iso` is still found, and the player hears about your recomp when it comes out. Leave `game` out if your recomp finds the game itself.

## `start`

- `program`: paths inside your release, tried in order. The first one that exists runs.
- `args`: always passed. Players can add their own launch options after yours.
- `env` (RecompLauncher 0.9.3 and later): environment variables for your game, as names and values. Paths fill in like everywhere else. Names are letters, digits and `_`; system ones like `PATH` can't be set.

```json
"start": {
  "program": ["MyRecomp.exe"],
  "args": ["--iso", "{game}"],
  "env": { "MYRECOMP_DATA": "{documents}/My Recomp" }
}
```

## What every game is told

From RecompLauncher 0.9.3, every game the launcher starts gets these environment variables, with a `recomplauncher.json` or without:

| Variable | Value |
| --- | --- |
| `RECOMPLAUNCHER` | `1` |
| `RECOMPLAUNCHER_VERSION` | The launcher's version, like `0.9.3` |
| `RECOMPLAUNCHER_MODE` | `bigpicture` when Play was pressed in Big Picture mode, else `desktop` |
| `RECOMPLAUNCHER_PAD` | The controller plugged in: `xbox` or `playstation` |

Use them to fit in: skip your own launcher window, open full screen with your controller menus when the mode is `bigpicture`, and show the right button prompts from the first frame. More in **Controllers and Big Picture mode**.

## Paths

These fill in anywhere a path, an argument or a value is written.

| Placeholder | What it becomes |
| --- | --- |
| `{install}` | Your game's folder |
| `{game}` | The player's copy (the .iso or the folder) |
| `{documents}` | Documents |
| `{local}` | AppData\Local, or ~/.local/share |
| `{config}` | AppData\Roaming, or ~/.config |
| `{home}` | The user's home folder |
| `{pad}` | The controller plugged in: `xbox` or `playstation` |

Where a file can be in more than one place, write a list. The first entry whose `if` exists is used, and an entry without `if` is the fallback:

```json
"file": [
  { "path": "{install}/game.toml", "if": "{install}/portable.txt" },
  { "path": "{documents}/My Game/game.toml" }
]
```

## `saves`

Where your game keeps its saves. The launcher copies them after every session (the newest ten stay on the player's PC), and players who sign in can send them to their account and pick up on another PC. List folders, single files, or files matching a `*` pattern:

```json
"saves": ["{local}/My Game/saves", "{install}/settings.toml"]
```

Without it, the launcher watches which files the game writes while it runs and uses those places. That works for most games, but saying where is better. Only list saves and small settings: game files, programs and archives are never copied, and nothing over 64 MB.

## `games`: several games in one recomp

If your release carries more than one game (a trilogy, a bundle), list them. Players get one download and one update, and each game gets its own tile in the library, with its own playtime, saves and achievements:

```json
"games": [
  { "id": "gow1", "name": "Gears of War", "args": ["--game=gow1"], "title_id": "4D5307D5" },
  { "id": "gow2", "name": "Gears of War 2", "args": ["--game=gow2"], "inputs": ["disc2"] }
]
```

`id` is short (lowercase letters and digits, up to 16) and never changes, since saves and achievements hang on it. `args` are added after `start.args` to start that game directly. If your recomp has no such option yet, leave them out: each tile opens your own menu. `title_id` (Xbox 360) names that game's achievement unlock file. `inputs` lists the setup inputs only that game needs (its own disc). Inputs no game lists are shared by all of them.

Needs RecompLauncher 0.90 Beta 36 or newer. Older launchers ignore the list and show one tile for the whole release.
