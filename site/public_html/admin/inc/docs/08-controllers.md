---
slug: controllers
title: Controllers and Big Picture mode
summary: Fit in with players on a controller, the couch or a Steam Deck, and why SDL3 is the way to go.
audience: pc
sort: 42
---
Big Picture mode is the launcher's full-screen view for a controller, the couch and the Steam Deck. Players pick a game, set it up and press Play without touching a mouse. The best thing your recomp can do is carry on the same way.

## Use SDL3 for input if you can

SDL3's gamepad support knows hundreds of controllers, including Xbox, DualSense and DualShock, Switch Pro, 8BitDo and the Steam Deck, and names their buttons the same way. It also gives you gyro, touchpads, rumble and lights where the pad has them.

Recomps on SDL2 work fine. SDL3 is where controller support is going, and it's what we'll build further controller features on, so it's the one to pick for anything new.

## Playable start to finish on a controller

Menus, settings, the "pick your copy" step if you still have one, and quitting. A player in Big Picture mode may have no keyboard in reach.

## Know where you were started from

From RecompLauncher 0.9.3, every game gets `RECOMPLAUNCHER_MODE` and `RECOMPLAUNCHER_PAD` (see **Level 1**).

- **`RECOMPLAUNCHER_MODE`:** start full screen in `bigpicture`, and as your players expect in `desktop`.
- **`RECOMPLAUNCHER_PAD` or `{pad}`:** default your button prompts to the player's controller, `xbox` or `playstation`, so the first screen already shows the right buttons.
- **`RECOMPLAUNCHER`:** when it's `1`, skip your own launcher window. The launcher has already checked the player's copy and written your settings.

## Quit cleanly

When your game closes, the launcher backs up the save straight away. A "Quit" in your menu means a controller player never needs a keyboard to get back to their library.

## Background input

If your game can read controllers while it isn't in front (`SDL_JOYSTICK_ALLOW_BACKGROUND_EVENTS` in SDL), keep it off by default. The launcher's own overlay uses the controller too.
