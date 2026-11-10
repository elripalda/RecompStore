RecompLauncher can set your recomp up for the player. They drop in their disc image, watch it get
checked and set up, change your video settings on the game's page, and press Play. They never
open a config file or your own launcher.

All of it comes from one file you write, `recomplauncher.json`. Your recomp doesn't have to
change: the launcher only does what a player would do by hand. It edits your settings file,
passes command-line arguments and starts your executable.

There are three levels. Each one builds on the last, and you can stop at any of them.

| Level | What players get | What you add |
| --- | --- | --- |
| 1. Plays from RecompLauncher | Drop in a disc, it's checked and set up, Play starts the game directly | `game` and `start` |
| 2. Settings in the launcher | Your video and gameplay settings on the game's page and in Big Picture mode | `config` and `settings` |
| 3. Full setup | Your own setup step on the setup screen, and a "verified" badge for clean dumps | `prepare` and `dumps` |

Level 1 takes about ten minutes. Before any of them, your releases need to install cleanly, which
most already do: see **Releases that install cleanly**.

## Releases that install cleanly

The launcher installs and updates your recomp straight from your GitHub releases. Nothing goes
through us, and every release you publish reaches players the next time the launcher checks.

- **One file per system.** Put the system and the processor in the name:
  `MyRecomp-v1.2.0-Windows-x64.zip`, `MyRecomp-v1.2.0-Linux-x64.AppImage`. The launcher picks the
  file for the player's PC from those words. Builds for ARM say `arm64`.
- **Zips on Windows.** A `.zip` (or `.7z`) of your folder is best. An installer `.exe` works, but
  it's the last choice, because it wants to be run as setup. On Linux, an AppImage or a `.tar.gz`.
- **One folder on top is fine.** A zip with everything inside `MyRecomp-v1.2.0/` is unwrapped, so
  your program lands in the game's folder either way.
- **Keep extras out of the way.** Files with `debug`, `symbols`, `pdb` or `source` in the name, and
  `.sha256`, `.sig` and `.asc` files, are never taken for the game.
- **Checked on the way in.** GitHub keeps a SHA-256 of every file you upload, and the launcher
  checks the download against it before installing. You don't have to do anything.
- **Updates keep what the game made.** An update unpacks over the game's folder, so saves,
  settings and caches your game wrote stay. That also means a settings file *inside your release*
  replaces the player's on every update. Ship defaults instead (`default.toml`, or write them on
  first start), or let the launcher write them with `config.new`.
- **`recomplauncher.json` at the top of the zip**, next to your program.
- **Pre-releases are betas.** Mark a test build as a pre-release on GitHub. Normal players stay on
  your latest full release, and we can open the pre-release as a beta in the launcher, where
  players join it with one button and go back just as easily. See **Betas**.

## Where the file goes

The launcher uses the first one it finds:

1. **Your release.** Put `recomplauncher.json` at the top of your release zip, next to your
   executable. This is the best place, because it ships with every release.
2. **The catalog.** If you can't ship it, send it to us and we'll add it to your entry on
   recomplauncher.com.

Two examples are worth reading:

- [fifawc14.json](https://recomplauncher.com/sdk-files/examples/fifawc14.json): 2014 FIFA World Cup (Xbox 360), with a settings file
  and eight settings
- [streetball.json](https://recomplauncher.com/sdk-files/examples/streetball.json): NBA Street Vol. 2 (PS2), with settings passed on
  the command line

Add `"$schema": "https://recomplauncher.com/schema/recomplauncher.json"` at the top and your
editor will complete fields and flag mistakes as you type. The schema is also in this folder:
[recomplauncher.json schema](https://recomplauncher.com/schema/recomplauncher.json).

## Level 1: plays from RecompLauncher

```json
{
  "$schema": "https://recomplauncher.com/schema/recomplauncher.json",
  "format": 1,
  "repo": "you/your-recomp",
  "game": {
    "kind": "iso",
    "label": "Your disc image (.iso) of the USA release, serial SLUS-20651.",
    "check": { "iso9660": "SLUS_206.51" }
  },
  "start": {
    "program": ["bin/game.exe", "game.exe"],
    "args": ["--iso", "{game}"]
  }
}
```

What the player sees:

1. They install your recomp. If the launcher already found their copy in one of their game
   folders, it offers that one. Otherwise they drop the disc image on the setup screen, or pick
   it or its folder.
2. The setup screen reads the disc, checks it's your game, reads the whole image to make sure
   it's complete, and writes your settings.
3. "Ready to play", with Play and Settings. Play starts `start.program` straight away.

### `game`: the player's copy

- `kind`: `"iso"`, `"folder"` or `"iso_or_folder"`.
- `label`: what to ask for. Name the region or serial if only one works.
- `check`: how to tell it's the right game. Use whichever fits:
  - `file`: a file that should be in the folder, like `"default.xex"`.
  - `xdvdfs`: a file on an Xbox 360 disc image.
  - `iso9660`: a file in the top folder of a PlayStation 2 (or any ISO 9660) disc image.

The same check is how the launcher finds copies in the player's folders, so a disc image named
`backup-07.iso` is still found.

Leave `game` out if your recomp finds the game itself.

### `start`

- `program`: paths inside your release, tried in order. The first one that exists runs.
- `args`: always passed. Players can add their own launch options after yours.
- `env`: environment variables for your game, written as names and values. Paths fill in like
  everywhere else. Names are letters, digits and `_`; system ones like `PATH` can't be set.

```json
"start": {
  "program": ["MyRecomp.exe"],
  "args": ["--iso", "{game}"],
  "env": { "MYRECOMP_DATA": "{documents}/My Recomp" }
}
```

Every game the launcher starts also gets these, whether it has a `recomplauncher.json` or not:

| | |
| --- | --- |
| `RECOMPLAUNCHER` | `1` |
| `RECOMPLAUNCHER_VERSION` | The launcher's version, like `0.75.0-beta.1` |
| `RECOMPLAUNCHER_MODE` | `bigpicture` when Play was pressed in Big Picture mode, else `desktop` |
| `RECOMPLAUNCHER_PAD` | The controller plugged in: `xbox` or `playstation` |

Use them to fit in: skip your own launcher window, open full screen with your controller menus
when the mode is `bigpicture`, and show the right button glyphs from the first frame.

### Paths

These fill in anywhere a path, an argument or a value is written:

| | |
| --- | --- |
| `{install}` | Your game's folder |
| `{game}` | The player's copy (the .iso or the folder) |
| `{documents}` | Documents |
| `{local}` | AppData\Local, or ~/.local/share |
| `{config}` | AppData\Roaming, or ~/.config |
| `{home}` | The user's home folder |
| `{pad}` | The controller plugged in: `xbox` or `playstation` |

Where a file can be in more than one place, write a list. The first entry whose `if` exists is
used, and an entry without `if` is the fallback:

```json
"file": [
  { "path": "{install}/game.toml", "if": "{install}/portable.txt" },
  { "path": "{documents}/My Game/game.toml" }
]
```

### `saves`

Where your game keeps its saves. The launcher copies them after every session (the newest ten stay
on the player's PC), and players who sign in can send them to their account and pick up on another
PC. List folders, single files, or files matching a `*` pattern:

```json
"saves": ["{local}/My Game/saves", "{install}/settings.toml"]
```

Without it, the launcher watches which files the game writes while it runs, and uses those places.
That works for most games, but saying where is better. Only list saves and small settings: game
files, programs and archives are never copied, and nothing over 64 MB.

## Level 2: settings in the launcher

### `config`: your settings files

Each entry is a file the launcher may edit before every start.

- `id`: a name settings use to point at it. Defaults to `"main"`.
- `format`: `"toml"`, `"ini"` or `"json"`. TOML and INI are edited line by line, so comments,
  order and keys the launcher doesn't know about stay as they are.
- `file`: where it is (see Paths).
- `game_path`: the key that holds the path to the player's copy. Written on every start.
- `ensure`: keys set only when they're missing or empty.
- `always`: keys set on every start. Keep this to what your recomp needs to run from the
  launcher.
- `new`: keys set only when the file doesn't exist yet. These are your defaults.

Values are written exactly as given, so TOML strings keep their quotes: `"\"xenos\""`.

### `settings`: what the player can change

Each setting is a row with a list of choices. On a controller, left and right move through them.

```json
{
  "id": "resolution",
  "label": "Resolution",
  "help": "What the game draws at. 720p is the console's own.",
  "choices": [
    { "label": "720p", "set": { "resolution_scale": "1" } },
    { "label": "1440p", "set": { "resolution_scale": "2" } }
  ],
  "defaults": { "resolution_scale": "1" }
}
```

- **Settings in a file.** A choice's `set` lists the keys it writes, in the file named by `in`
  (default `"main"`). The launcher shows the first choice whose keys all match the file. Keys
  the file doesn't have yet are read from `defaults`, so put your game's own defaults there.
- **Settings on the command line.** Set `"in": "args"` and give each choice `args` instead of
  `set`. The launcher remembers the pick and adds those arguments on Play. An empty `args` makes
  a good first choice, labelled "Game's option" or similar.
- **Settings that only apply sometimes.** `when` greys a setting out unless the keys it lists
  match. FIFA's Window size has `"when": { "fullscreen": "false" }`.

Keep labels short and help text to a sentence. Say what it does for the player, not what the
key is called.

### Upscalers and DLSS

If your recomp has DLSS, FSR, XeSS or frame generation, expose it as a setting like any other.
These need data from inside the game for every frame (motion vectors, depth), so the launcher
can't add them from outside. It can give players one clear place to turn them on.

```json
{
  "id": "upscaler",
  "label": "Upscaling",
  "help": "DLSS needs an NVIDIA RTX card. FSR works on any card.",
  "choices": [
    { "label": "Off", "set": { "upscaler": "\"none\"" } },
    { "label": "FSR", "set": { "upscaler": "\"fsr\"" } },
    { "label": "DLSS", "set": { "upscaler": "\"dlss\"" } }
  ],
  "defaults": { "upscaler": "\"none\"" }
}
```

DLSS ships through NVIDIA's Streamline SDK, which is free to use. If you're adding it, the
setting above is all the launcher needs.

### Looks

Players can pick a look for any game on Windows: Sharp, Vivid, Film, CRT or Studio monitor. They
run on ReShade, which the launcher downloads from reshade.me the first time. When the game starts
with a look, the launcher puts ReShade next to your executable as `dxgi.dll` (or adds it as a
Vulkan layer for that one process) and takes it away again when the game closes. ReShade's own
settings and log stay in the launcher's folder. Your release isn't changed.

You don't need to do anything for this. The launcher works out the graphics API from your
executable. Add `looks` only if one of these is true:

```json
"looks": {
  "graphics_key": "graphics_api",
  "in": "main"
}
```

- Your recomp can draw with more than one API and the player picks it in your settings file. Name
  that key in `graphics_key`. Values containing `vulkan`, `d3d12`, `d3d11`, `d3d9` or `opengl` are
  understood.
- It always uses one API the launcher might not spot, such as OpenGL through a library that's
  loaded at run time. Set `"graphics": "opengl"`.
- The CRT looks use the console's usual line count (240 for the Nintendo 64, 480 for the PlayStation
  2). If your game ran at a different one, set `"lines"`.
- Your recomp has its own filters, or ReShade doesn't work with it. Set `"allowed": false` and a
  `"reason"` to show the player.

The looks are made for ordinary (SDR) pictures. When a game outputs HDR they leave the picture
alone.

### `mods`

The game's page has a Mods tab when the launcher knows where your mods live. It lists the most
popular ones, installs them with a click and takes them out again. Thunderstore and GameBanana are
supported. Zelda 64: Recompiled, Banjo: Recompiled, Unleashed Recompiled, Marathon Recompiled and
the Ship of Harkinian family are built in, so leave this out for those.

```json
"mods": {
  "thunderstore": "your-community",
  "data": "YourRecomp",
  "gamebanana": 12345,
  "loader": "nrm"
}
```

- `thunderstore` and `data`: your community on thunderstore.io and the name of your data folder
  (in `AppData\Local` on Windows, `~/.config` on Linux). Mods go in its `mods` folder, or in the
  game's own `mods` folder when `portable.txt` is next to it.
- `gamebanana` and `loader`: your game's id from its GameBanana address and how your game reads
  mods. `nrm` puts `.nrm` files in the same mods folder as above (it needs `data`). `otr` puts
  `.otr` and `.o2r` files in a `mods` folder next to your executable. `hmm` is for Hedge Mod Manager
  mods with a `mod.ini`: they're copied next to your executable and switched on in `ModsDB.ini`, and
  `user` names your folder in `AppData\Roaming` that holds `cpkredir.ini`.

Only files that passed GameBanana's virus scan, and come as a zip, 7z or rar, can be installed from
the launcher. For the rest, the player gets a link to the mod's page.

### `folders`, `actions` and `exit_codes`

- `folders`: named places the launcher can open or clear. When your game crashes, the newest
  `.log` in `logs` (or in your game's folder) is shown and goes with the report the player can
  send.
- `actions`: buttons under the settings. `open` opens one of your folders. `clear` empties one,
  written as a folder name and a subfolder (`"saves/cache"`). The launcher asks first.
- `exit_codes`: when your game exits with one of these, the player sees your message instead of
  a crash report.

```json
"exit_codes": { "252968961": "It couldn't find your copy of the game. Pick it again on the game's page." }
```

## Level 3: full setup

### `prepare`: your own setup step

If your own launcher does work before the first start, like pulling fonts or menu art from the
disc or building a cache, give the launcher a way to run it without a window:

```json
"prepare": {
  "program": ["yourgame.exe"],
  "args": ["--prepare", "--game", "{game}"],
  "label": "Menu art and fonts from your disc",
  "timeout": 300
}
```

It runs on the setup screen as "Getting the extras", after the copy is checked and before the
settings are written. It runs again whenever the player changes their copy.

- Don't open a window.
- Print lines like `progress 0.4 Unpacking menu art` as you go. The number is from 0 to 1 and the
  text after it shows under the step.
- Exit with 0 when it worked. Anything else shows as a failed setup, with your exit code.
- Write what you make to the same place your launcher would, so both stay in step.

### `game.dumps`: known good copies

```json
"game": {
  "kind": "iso",
  "check": { "xdvdfs": "default.xex" },
  "dumps": [
    { "name": "USA", "size": 7838695424, "crc32": "1a2b3c4d" },
    { "name": "Europe", "size": 7838695424, "crc32": "5e6f7a8b" }
  ]
}
```

The launcher reads every byte of the player's copy anyway, to make sure it's complete. With
`dumps` it also compares the CRC32 and shows "Matches the verified USA disc". Take the values
from Redump. A copy that doesn't match still works; the player just doesn't get the badge.

## Controllers and Big Picture mode

Big Picture mode is the launcher's full-screen view for a controller, the couch and the Steam Deck.
Players pick a game, set it up and press Play without touching a mouse, so the best thing your
recomp can do is carry on the same way.

- **Use SDL3 for input if you can.** SDL3's gamepad support knows hundreds of controllers (Xbox,
  DualSense and DualShock, Switch Pro, 8BitDo, the Steam Deck) and names their buttons the same
  way, with gyro, touchpads, rumble and lights where the pad has them. Recomps on SDL2 work fine;
  SDL3 is where controller support is going, and it's what we'll build further features on.
- **Be playable start to finish on a controller.** Menus, settings, the "pick your copy" step if
  you still have one, and quitting. A player in Big Picture mode may have no keyboard in reach.
- **Quit cleanly.** When your game closes, the launcher backs up the save straight away. A "Quit"
  in your menu means a controller player never needs a keyboard to get back to their library.
- **Read `RECOMPLAUNCHER_MODE`.** Starting full screen in `bigpicture` and windowed in `desktop` is
  what most players expect.
- **Use `{pad}` or `RECOMPLAUNCHER_PAD`** to default your button prompts to the player's
  controller.
- **Leave background input to the player.** If your game reads controllers while it's not in
  front (SDL's `SDL_JOYSTICK_ALLOW_BACKGROUND_EVENTS`), keep it off by default: the launcher's own
  overlay uses the controller too.

## Make it run its best

Things that make a recomp feel finished in the launcher. None are required; each one shows.

- **Good defaults.** Start at the console's own look (resolution, frame rate, aspect) and let
  players go up from there in your settings. A first start that just works beats one that asks.
- **Settings the launcher can show.** Keep your options in a TOML, INI or JSON file and describe the
  ones players care about in `settings`: resolution, frame rate, anti-aliasing, V-Sync, window mode,
  upscaling. They show on the game's page and in Big Picture mode, with your help text.
- **High frame rates done right.** If you unlock the frame rate, say in the help text what it
  changes (some games tie physics or cutscenes to it) and keep the console's rate as an option.
- **Say where saves go** with `saves`, so backups and cloud saves pick up exactly the right files.
- **Write a log.** A `.log` in a `logs` folder (or next to your program) goes with the crash
  report a player sends, so you get it without asking.
- **Explain your exit codes** with `exit_codes`. A clear "Your copy is the European release; this
  needs the USA one" saves you a bug report.
- **Support `portable.txt`.** If a `portable.txt` next to your program makes your recomp keep
  everything in its own folder, mod installs from the launcher follow it.
- **Check the copy, then trust it.** With `game` and `dumps`, the launcher has already checked the
  player's copy before your program starts, so your own check can be quick.
- **Ship a `recomplauncher.json` in every release**, and keep it in step with your settings when
  you add or rename keys. The Developer tab (below) shows the problems before players do.

## Betas

Publish a test build as a GitHub pre-release. Players on your normal release never get it by
accident: the launcher always installs your latest full release. When you want players to try the
pre-release, tell us and we open it as a beta. It can be open to everyone, by request, or by
invite. Players join from the launcher or their account page, play it, and go back to your normal
release with one button. You can also name one release by its tag instead of "the newest
pre-release".

## Achievements

Recomp Achievements is RecompLauncher's achievement system: achievements pop up as players earn them, games can be Beaten and Mastered, and Ranked points go on the leaderboards. It's separate from `recomplauncher.json`: a small C library in your recomp reports unlocks to the launcher, using the game's own achievements (Xbox 360), rules on console memory, or events you fire from your code.

Everything you need is in the [Recomp Achievements repository](https://github.com/elripalda/RecompAchievements). Xbox 360 recomps on a recent ReXGlue build may already work without any change.

## Recomp Standards

What makes a recomp easy to play, wherever players get it from. Nothing here is required to be listed on RecompLauncher. These are the things players trip over most, and most of them are small changes.

### The basics

**Never ship game files.** No ROMs, disc images, extracted assets or keys. Players bring their own copy.

**Accept the formats people actually have.** Most backups are packaged files, not the extracted folders a recomp is often first written for. Support the standard packaged formats where they apply:

| Console | Accept |
| --- | --- |
| PS1 | CHD, BIN/CUE, ISO |
| PS2 | CHD, ISO |
| PSP | CHD, ISO, CSO |
| Dreamcast | CHD, GDI |
| Saturn, Sega CD / Mega CD | CHD, BIN/CUE |
| TurboGrafx-CD / PC Engine CD, Neo Geo CD | CHD, BIN/CUE |
| 3DO, Amiga CD32, Philips CD-i | CHD, BIN/CUE |
| GameCube, Wii | RVZ, ISO, GCZ (and WBFS for Wii) |
| N64 | .z64, .n64 and .v64 (any byte order), zipped |
| NES, SNES, GB, GBA, DS | the ROM, or zipped |
| Switch, Switch 2 | NSP, XCI and NUP, not only extracted exefs/romfs |
| Xbox | ISO (XISO), or an extracted folder |
| Xbox 360 | ISO, or an extracted folder with default.xex |

CHD is the format most disc-based backups come in, and the one players ask for most. Reading it is a small library ([libchdr](https://github.com/rtissera/libchdr), BSD licensed).

**Check the copy, then say what's wrong.** If you know the good dumps (from [Redump](http://redump.org) or [No-Intro](https://no-intro.org)), check against them. When a file is wrong, say why in one line ("This is the PAL version. The recomp needs the USA one.") instead of crashing later.

**Let the game's location be passed in.** A `--game <path>` option (a file or a folder) means launchers, scripts and Steam shortcuts can start your recomp without anyone moving files around. Keep your own picker for everyone else.

### Releases

- **Tag every release with a version** (`v1.2.0`) and attach the builds to the GitHub release, not just to the README or Actions.
- **One file per platform, with a name that says what it is**, the same way every time: `yourgame-1.2.0-windows-x64.zip`, `-linux-x86_64.AppImage`, `-macos-arm64.zip`.
- **Build for Linux and Mac too** when you can. A lot of players are on Steam Deck.
- **Say what changed** in the release notes, even in one line.

### On the player's PC

- **Portable when it can be.** If the folder next to the exe is writable, keep saves, settings and logs there. Otherwise use the usual per-user folder, and say where in the README.
- **Settings in a file a person can read**: TOML, INI or JSON, with every option written out and commented. Don't hide settings in the registry.
- **Logs in a known place**, kept for the last few runs, so players can send them when something breaks.
- **Controllers through SDL**, with buttons that can be remapped and a sensible default for Xbox, PlayStation and Switch pads.
- **No admin rights, no installer required.** Unzip and play.
- **Don't download anything at first start** without asking.

### Extras that players notice

- **recomplauncher.json (this guide)**: one-click setup and your settings on the game's page in RecompLauncher.
- **[Recomp Achievements](https://github.com/elripalda/RecompAchievements)**: achievements, Beaten and Mastered, leaderboards.
- **A "Get it on RecompLauncher" badge** in your README, from the [press kit](https://recomplauncher.com/press).
- **A short "How to play" at the top of the README**: what copy of the game you need, and where to put it.

## Get listed

Recomps on recomplauncher.com are in the launcher. If yours isn't there yet, [submit it](https://recomplauncher.com/submit?platform=pc)
with its GitHub repo, and say which levels it supports. A free [developer account](https://recomplauncher.com/developers?signup=1)
gets it reviewed faster. Already listed and adding `recomplauncher.json`? Nothing to do: the next
release you publish carries it.

## Checking your file

The launcher has tools for this. In Settings, open Developers and turn on Developer tools.

1. **Add your build.** Use "Add a build" and pick your recomp's folder, the one with your program
   and `recomplauncher.json`. It shows in your Library as a local build. Nothing is downloaded, and
   the launcher never updates, moves or deletes that folder.
2. **Read the check.** The build opens on its Developer tab. It reads your file fresh each time
   and lists what it found: where Play starts, what the setup screen asks for, each settings file
   and the choice each setting shows right now. Problems are marked in red, things worth a look in
   yellow. JSON mistakes show the line and column. It also shows which level your file reaches.
   No file yet? "Make a starter file" writes one next to your program to begin from.
3. **Try it like a player.** Set it up with your own disc, change a setting, then open your
   settings file: only the keys you listed should have changed. Press Play: your game should start
   without your own launcher.
4. **Change the file and press Check again.** No restart needed.

Add `"name"` to your file and the local build goes by that name.

If you'd rather check a release the way players get it, install it from Discover and open its
page. A file the launcher can't read is skipped, and the built-in one (if any) is used instead.

Stuck, or want us to write the file with you? Open an issue, or send us your file.
