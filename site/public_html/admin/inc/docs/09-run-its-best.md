---
slug: run-its-best
title: Make it run its best
summary: Small things that make a recomp feel finished in the launcher. None are required; each one shows.
audience: pc
sort: 44
---
None of these are required. Each one makes your recomp feel finished to the player, and most take minutes.

## Good defaults

Start at the console's own look: its resolution, frame rate and aspect. Let players go up from there in your settings. A first start that just works beats one that asks questions.

## Settings the launcher can show

Keep your options in a TOML, INI or JSON file and describe the ones players care about in `settings` (Level 2): resolution, frame rate, anti-aliasing, V-Sync, window mode, upscaling. They show on the game's page and in Big Picture mode, with your help text, and players never open the file.

## High frame rates, done right

If you unlock the frame rate, say in the help text what it changes, since some games tie physics or cutscenes to it, and keep the console's rate as a choice.

## Saves where the launcher can find them

Say where they are with `saves` (Level 1). Backups and cloud saves then pick up exactly the right files, and nothing else.

## A log, and exit codes that explain themselves

- Write a `.log` in a `logs` folder, or next to your program. It goes with the crash report a player sends, so you get it without asking.
- Explain your exit codes with `exit_codes`. A clear "Your copy is the European release; this one needs the USA disc" saves you a bug report.

## Portable mode

If a `portable.txt` next to your program makes your recomp keep everything in its own folder, mod installs from the launcher follow it.

## Trust the check

With `game` and `dumps`, the launcher has already read and checked the player's copy before your program starts. Your own check can be quick.

## Keep the file in step

Ship `recomplauncher.json` in every release, and update it when you add or rename settings keys. The Developer tab in the launcher shows problems before players do (see **Testing and getting listed**).
