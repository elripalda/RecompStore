---
slug: level-2
title: "Level 2: settings in the launcher"
summary: Your settings on the game's page and in Big Picture mode, plus upscalers, looks, mods, folders and error messages.
audience: pc
sort: 30
---
With `config` and `settings`, your options show on the game's page and in Big Picture mode, where left and right on a controller change them. You write them once; the launcher draws them.

## `config`: your settings files

Each entry is a file the launcher may edit before every start.

- `id`: the name settings use to point at it. Defaults to `"main"`.
- `format`: `"toml"`, `"ini"` or `"json"`. TOML and INI are edited line by line, so comments, order and keys the launcher doesn't know about stay as they are.
- `file`: where it is (see Paths in Level 1).
- `game_path`: the key that holds the path to the player's copy. Written on every start.
- `ensure`: keys set only when they're missing or empty.
- `always`: keys set on every start. Keep this to what your recomp needs to run from the launcher.
- `new`: keys set only when the file doesn't exist yet. These are your defaults.

Values are written exactly as given, so TOML strings keep their quotes: `"\"xenos\""`.

## `settings`: what the player can change

Each setting is a row with a list of choices.

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

- **In a file.** A choice's `set` lists the keys it writes, in the file named by `in` (default `"main"`). The launcher shows the first choice whose keys all match the file. Keys the file doesn't have yet are read from `defaults`, so put your game's own defaults there.
- **On the command line.** Set `"in": "args"` and give each choice `args` instead of `set`. The launcher remembers the pick and adds those arguments on Play. An empty `args` makes a good first choice, labeled "Game's option" or similar.
- **Only sometimes.** `when` grays a setting out unless the keys it lists match. FIFA's Window size has `"when": { "fullscreen": "false" }`.

Keep labels short and help text to one sentence. Say what it does for the player, not what the key is called.

## Upscalers and DLSS

If your recomp has DLSS, FSR, XeSS or frame generation, expose it as a setting like any other. These need data from inside the game every frame (motion vectors, depth), so the launcher can't add them from outside, but it can give players one clear place to turn them on.

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

### Can't ship NVIDIA's DLSS file?

NVIDIA's `nvngx_dlss.dll` isn't open source, so some projects (GPL ones especially) leave it out of their releases. Ask the launcher for it instead and players don't have to find it:

```json
{
  "inputs": [
    { "id": "dlss", "label": "NVIDIA DLSS", "role": "runtime", "shared": "nvidia.dlss",
      "optional": true, "destination": "nvngx_dlss.dll" }
  ]
}
```

On a PC with an NVIDIA RTX card, the launcher downloads the file straight from NVIDIA's own DLSS repo the first time a game asks, checks it's signed by NVIDIA, keeps one copy for every game that uses it, and puts it at `destination` in your install. If your recomp takes a path instead, leave out `destination` and pass `"--dlss={input:dlss}"` in `start.args`. On other PCs it's skipped, and arguments with `{input:dlss}` are left out. Players can turn it off in Easy Setup. It never replaces a DLSS file your release ships itself.

Coming soon to RecompLauncher. Launchers from before it show an error in Easy Setup for a file with this input, so add it once the version that has it is out.

## `looks`

Players can pick a look for any game on Windows: Sharp, Vivid, Film, CRT or Studio monitor. They run on ReShade, which the launcher puts next to your executable for that start only and takes away when the game closes. Your release isn't changed.

You don't need to do anything for this. Add `looks` only if one of these is true:

- Your recomp can draw with more than one API and the player picks it in your settings file. Name that key in `graphics_key` (and its file in `in`). Values containing `vulkan`, `d3d12`, `d3d11`, `d3d9` or `opengl` are understood.
- It always uses an API the launcher might not spot, such as OpenGL loaded at run time. Set `"graphics": "opengl"`.
- Your game didn't run at the console's usual line count (240 for Nintendo 64, 480 for PlayStation 2). Set `"lines"` for the CRT looks.
- Your recomp has its own filters, or ReShade doesn't work with it. Set `"allowed": false` and a `"reason"` to show the player.

```json
"looks": { "graphics_key": "graphics_api", "in": "main" }
```

## `mods`

The game's page gets a Mods tab with the most popular mods, installed and removed in one click. Thunderstore and GameBanana are supported. Zelda 64: Recompiled, Banjo: Recompiled, Unleashed Recompiled, Marathon Recompiled and the Ship of Harkinian family are built in.

```json
"mods": { "thunderstore": "your-community", "data": "YourRecomp", "gamebanana": 12345, "loader": "nrm" }
```

- `thunderstore` and `data`: your community on thunderstore.io and your data folder's name (in AppData\Local on Windows, ~/.config on Linux). Mods go in its `mods` folder, or in your game's own `mods` folder when `portable.txt` is next to it.
- `gamebanana` and `loader`: your game's id from its GameBanana address and how your game reads mods:
  - `nrm`: `.nrm` files in the same mods folder as above (needs `data`).
  - `otr`: `.otr` and `.o2r` files in a `mods` folder next to your executable.
  - `hmm`: Hedge Mod Manager mods with a `mod.ini`, copied next to your executable and switched on in `ModsDB.ini`. `user` names your folder in AppData\Roaming that holds `cpkredir.ini`.

Only GameBanana files that passed its virus scan and come as a zip, 7z or rar install from the launcher. For the rest, the player gets a link to the mod's page.

## `folders`, `actions` and `exit_codes`

- `folders`: named places the launcher can open or clear. `saves` is what it backs up before every update. When your game crashes, the newest `.log` in `logs` (or in your game's folder) is shown and goes with the report the player can send you.
- `actions`: buttons under the settings. `open` opens one of your folders; `clear` empties one, written as a folder name and a subfolder (`"saves/cache"`). The launcher asks first.
- `exit_codes`: when your game exits with one of these, the player sees your message instead of a crash report.

```json
"exit_codes": { "252968961": "It couldn't find your copy of the game. Pick it again on the game's page." }
```
