---
slug: reference
title: recomplauncher.json reference
summary: Every field on one page, with the level it belongs to.
audience: pc
sort: 60
---
Only `format` is required. Everything else is optional, and the launcher ignores fields it doesn't know.

| Field | Level | What it holds |
| --- | --- | --- |
| `$schema` | Any | `https://recomplauncher.com/schema/recomplauncher.json`, for your editor |
| `format` | Any | Always `1` |
| `repo` | Any | Your GitHub repo (owner/name). Only for files built into the launcher |
| `name` | Any | The name a local build goes by in the Developer tools |
| `game` | 1 | The player's copy: `kind`, `label`, `check`, `dumps` |
| `start` | 1 | What to run: `program`, `args`, `env` |
| `saves` | 1 | Where your saves are, for backups and cloud saves |
| `games` | 1 | Several games in one recomp: `id`, `name`, `args`, `title_id`, `inputs` (Beta 36 and newer) |
| `config` | 2 | Settings files: `id`, `format`, `file`, `game_path`, `ensure`, `always`, `new` |
| `settings` | 2 | Options for the player: `id`, `label`, `help`, `in`, `choices`, `defaults`, `when` |
| `looks` | 2 | `graphics`, `graphics_key`, `in`, `lines`, `allowed`, `reason` |
| `mods` | 2 | `thunderstore`, `data`, `gamebanana`, `loader`, `user` |
| `folders` | 2 | Named places; `saves` and `logs` are used by the launcher |
| `actions` | 2 | Buttons: `id`, `label`, `help`, `open` or `clear` |
| `exit_codes` | 2 | Exit code (as a string) to the message shown |
| `prepare` | 3 | Your setup step: `program`, `args`, `label`, `timeout` |
| `inputs` | 3 | Named discs, DLC and updates; shared Switch/Wii U keys; NVIDIA's DLSS file (`"shared": "nvidia.dlss"`, coming soon) |

The full schema, with every value checked, is at https://recomplauncher.com/schema/recomplauncher.json.
