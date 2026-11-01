---
slug: testing-and-listing
title: Testing and getting listed
summary: Check your file with the launcher's Developer tools, and get onto recomplauncher.com.
audience: pc
sort: 50
---
## Check your file

The launcher has tools for this. In Settings, open Developers and turn on Developer tools.

1. **Add your build.** Use "Add a build" and pick your recomp's folder, the one with your program and `recomplauncher.json`. It shows in your Library as a local build. Nothing is downloaded, and the launcher never updates, moves or deletes that folder.
2. **Read the check.** The build opens on its Developer tab. It reads your file fresh each time and lists what it found: where Play starts, what the setup screen asks for, each settings file and the choice each setting shows right now. Problems are marked in red, things worth a look in yellow, and JSON mistakes show the line and column. It also shows which level your file reaches. No file yet? "Make a starter file" writes one next to your program to begin from.
3. **Try it like a player.** Set it up with your own copy, change a setting, then open your settings file: only the keys you listed should have changed. Use "Change your copy" and check the setup screen turns down a different disc. Press Play: your game should start without your own launcher.
4. **Change the file and press Check again.** No restart needed.

Add `"name"` to your file and the local build goes by that name. A file the launcher can't read is skipped, and the built-in one (if any) is used instead.

## Your releases

The launcher installs from your GitHub releases. How it picks the right file, and how to keep updates from touching players' settings, is in **Releases that install cleanly**.

## Getting on recomplauncher.com

Use **Submit a game** in your hub, or on recomplauncher.com, with your GitHub repo. Recomps are picked by hand, so not everything sent in is listed. Once it's listed, claim it from your hub so it carries your name.

Stuck, or want us to write the file with you? Send it to us in Messages and we'll help.
