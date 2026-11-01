---
slug: level-3
title: "Level 3: full setup"
summary: Run your own setup step without a window, and give clean dumps a verified badge.
audience: pc
sort: 40
---
## `prepare`: your own setup step

If your own launcher does work before the first start, like pulling fonts or menu art from the disc or building a cache, give the launcher a way to run it without a window:

```json
"prepare": {
  "program": ["yourgame.exe"],
  "args": ["--prepare", "--game", "{game}"],
  "label": "Menu art and fonts from your disc",
  "timeout": 300
}
```

It runs on the setup screen as "Getting the extras", after the copy is checked and before the settings are written. It runs again whenever the player changes their copy.

- Don't open a window.
- Print lines like `progress 0.4 Unpacking menu art` as you go. The number runs from 0 to 1, and the text after it shows under the step.
- Exit with 0 when it worked. Anything else shows as a failed setup, with your exit code.
- Write what you make to the same place your own launcher would, so both stay in step.

## `game.dumps`: known good copies

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

The launcher reads every byte of the player's copy anyway, to make sure it's complete. With `dumps` it also compares the CRC32 and shows "Matches the verified USA disc". Take the values from Redump. A copy that doesn't match still works; it just doesn't get the badge.
