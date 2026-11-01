---
slug: start-here
title: Start here
summary: What RecompLauncher does for your recomp, the three levels, and the smallest file that works.
audience: pc
sort: 10
---
One file in your release, `recomplauncher.json`, lets RecompLauncher set your recomp up for the player. They link their own copy of the game, watch it get checked and set up, change your settings on the game's page or in Big Picture mode, and press Play. They never open a config file or your own launcher.

Your recomp doesn't have to change. The launcher only does what a player would do by hand: it edits your settings file, passes command-line arguments and starts your executable.

## Why it's worth ten minutes

- **One-click installs.** Players press Install, and the launcher picks the right file from your latest GitHub release, checks it and unpacks it. Updates come the same way, and saves, settings and mods are left alone.
- **Their copy, found for them.** The launcher scans players' game folders and tells them when a game they own has your recomp, so you reach people who didn't know it existed.
- **Your settings, without a config file.** Resolution, frame rate, upscaling and the rest show on the game's page and in Big Picture mode, so players change them with a mouse or a controller.
- **Fewer support questions.** The setup screen checks the player's copy before the first start, and crash reports arrive with your game's log attached.
- **A page of your own.** Your recomp's page on recomplauncher.com, with its art, your words and your players' reports, editable from your developer hub.

## The three levels

Each level builds on the last, and you can stop at any of them. Level 1 takes about ten minutes.

| Level | What players get | What you add |
| --- | --- | --- |
| 1. Plays from RecompLauncher | Their copy is found or dropped in, checked and set up; Play starts the game directly | `game`, `start` |
| 2. Settings in the launcher | Your settings on the game's page and in Big Picture mode, plus looks, mods, folders and friendly error messages | `config`, `settings`, and optionally `looks`, `mods`, `folders`, `actions`, `exit_codes` |
| 3. Full setup | Your own setup step on the setup screen, and a verified badge for clean dumps | `prepare`, `game.dumps` |

Without a file, your recomp still installs, updates and starts from the launcher. The file is what makes it one click.

## Where the file goes

Put `recomplauncher.json` at the top of your release zip, next to your executable. It ships with every release, so it's always in step with your build.

The launcher uses the first file it finds:

1. **Your release.** The best place, for the reason above.
2. **The catalog.** If you can't ship it, send it to us and we'll add it to your entry on recomplauncher.com.
3. **Built in.** A few files are built into the launcher and matched by GitHub repo. Two good examples: 2014 FIFA World Cup (Xbox 360, a settings file with eight settings) and NBA Street Vol. 2 (PS2, settings passed on the command line).

## The smallest useful file

```json
{
  "$schema": "https://recomplauncher.com/schema/recomplauncher.json",
  "format": 1,
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

The `$schema` line lets your editor complete fields and flag mistakes as you type. `format` is always `1`.

Next: **Releases that install cleanly** covers how the launcher picks and installs your files, and **Level 1** explains each part of this file.
