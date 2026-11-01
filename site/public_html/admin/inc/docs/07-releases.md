---
slug: releases
title: Releases that install cleanly
summary: How the launcher picks your release files, what updates keep, and how pre-releases become betas.
audience: pc
sort: 15
---
The launcher installs and updates your recomp straight from your GitHub releases. Nothing goes through us, and every release you publish reaches players the next time the launcher checks. Most releases already work. These make sure yours does.

## One file per system

Put the system and the processor in each file's name, and the launcher picks the one for the player's PC:

- `MyRecomp-v1.2.0-Windows-x64.zip`
- `MyRecomp-v1.2.0-Linux-x64.AppImage`
- `MyRecomp-v1.2.0-Windows-arm64.zip` for ARM builds

When your recomp is listed, its entry can name the file outright (`MyRecomp-*-Windows.zip`). If you rename your files later, the launcher falls back to reading the names, so nothing breaks.

## The right kind of file

- **Windows:** a `.zip` of your folder is best, then `.7z`. An installer `.exe` works, but it's the last choice, because it wants to be run as setup.
- **Linux:** an AppImage or a `.tar.gz`. A Flatpak bundle comes last, since it needs Flatpak itself.
- **One folder on top is fine.** A zip with everything inside `MyRecomp-v1.2.0/` is unwrapped, so your program lands in the game's folder either way.
- **Extras stay out of the way.** Files with `debug`, `symbols`, `pdb` or `source` in the name, and `.sha256`, `.sig` and `.asc` files, are never taken for the game.

## Checked on the way in

GitHub keeps a SHA-256 of every file you upload. The launcher checks each download against it before installing anything. You don't have to do anything for this.

## What updates keep

An update unpacks over the game's folder. Files your game made, like saves, settings, caches and mods, stay where they are, and the launcher backs up saves before every update.

That also means a settings file *inside your release* replaces the player's on every update. Ship your defaults another way:

- name them differently (`default.toml`) and copy them on first start, or
- write them when the file doesn't exist yet, or
- let the launcher write them with `config.new` (Level 2).

Put `recomplauncher.json` at the top of the zip, next to your program, and it updates with every release.

## Pre-releases are betas

Mark a test build as a pre-release on GitHub. Players on your normal release never get it by accident: the launcher always installs your latest full release.

When you want players to try it, tell us in Messages and we open it as a beta. It can be open to everyone, by request, or by invite. Players join from the launcher or their account page, play it, and go back to your normal release with one button. A beta can follow your newest pre-release, or one release by its tag.
