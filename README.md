<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://recompstore.com/assets/press/logo-horizontal-white.svg">
    <img alt="RecompStore" src="https://recompstore.com/assets/press/logo-horizontal-dark.svg" width="420">
  </picture>
</p>

# RecompStore

A homebrew app store for jailbroken PS5. It installs and updates native PS5 ports of recompiled games, emulators, apps and tools, and sets each game up from your own disc image.

**No game files are hosted here or in any repository the store links to.** Every game needs your own legally obtained copy, which the store sets up for you from your disc image.

Website: [recompstore.com](https://recompstore.com) · Report a bug: [recompstore.com/report](https://recompstore.com/report) · Submit your recomp: [recompstore.com/submit](https://recompstore.com/submit)

## What you need

- A jailbroken PS5 with **kstuff**
- A payload loader on port 9021, such as **etaHEN** or **elfldr**
- **ShadowMountPlus 1.7 Beta 5** or newer, which puts homebrew on the home screen

## Install

1. Download `RecompStore-Installer.elf` from the [latest release](https://github.com/elripalda/RecompStore/releases/latest).
2. Send it to your PS5's payload loader on port 9021, using any payload sender or, from a computer on the same network:
   ```
   nc -N <your PS5's IP> 9021 < RecompStore-Installer.elf
   ```
3. A notification tells you when it's done. If ShadowMountPlus is running, RecompStore shows up on your home screen a few seconds later. If it isn't running, start it.

The installer downloads the newest release's `PPSA92000.zip` from this repository and checks it against GitHub's SHA-256 (offline, it uses the copy built into it). It unpacks it into `/data/homebrew/PPSA92000`, or into the folder where the store already is, and checks every file as it is written. You only do this once: after that the store updates itself from **Latest Updates**.

To install by hand instead, unzip `PPSA92000.zip` into `/data/homebrew/` over FTP and let ShadowMountPlus pick it up.

## What it does

- **Games, Emulators, Apps and Tools**, each with its own tab. Your Library has everything installed, waiting updates and betas.
- **Installs and updates** from GitHub releases (.zip or .7z), keeping your game files, saves, DLC and mods when a port updates. In Advanced mode, new apps can go to the M.2 SSD, extended storage or a USB drive.
- **Never writes over another app.** If something else already uses an app's title ID, the store asks first, and can install it under a new ID so both work.
- **Sources you add.** A GitHub repo or a list of them, from Settings > Sources. Their apps are marked as not checked by the store.
- **Link a phone.** Scan the QR code in Settings to send installs and sources to your PS5 from recompstore.com, and see what's on it.
- **Sets games up from your own files.** Pick your disc image, your Xbox Live Arcade game's file, a Title Update or DLC, or a ROM on the game's page, and the store puts what the port needs where it expects it, at the drive's full speed.
- **Opens games**, refreshes ShadowMountPlus so new games appear on the home screen, and deletes a game (after asking) when you're done with it.
- **Shows every release's patch notes** from each game's GitHub repository.
- **Beta games.** A code from Settings > Redeem a code unlocks a game in testing on that console; it shows up in the Library.
- **Send logs** from a game's page: the store's log and the game's own, with a message, go to the admin's Inbox.

## Repository layout

| Folder | What it is |
|---|---|
| `app/` | The PS5 app (title ID PPSA92000): `src/` has the store (`store_*`) on the Porpoise foundation (`porpoise_*`, `ui_*`) |
| `app/payload/` | The payloads the store hands to the loader: `copier` (game setup and installs at full drive speed), `launcher` (opens a game), `installer` (`RecompStore-Installer.elf`) |
| `site/public_html/` | recompstore.com: the website, its catalog admin (`admin/`), and the bug report and submission inbox (`api/inbox.php`) |

## Building

The app builds on Linux or WSL with Mihawk's PS5 payload SDK fork and PS5_Vulkan (RADV):

```
cd app
PS5_VULKAN_DIR=../PS5_Vulkan bash tools/build-store.sh
```

That builds the three payloads into `app/assets/payloads/`, then the title into `app/dist/PPSA92000/`.

## Releasing a new version

1. Raise `kVersion`, the `kVersionMajor/Minor/Patch` numbers and `kBuild` in `app/src/ui_app_common.hpp`, then build.
2. Zip `app/dist/PPSA92000` (the folder itself, so the zip's top folder is `PPSA92000/`) as `PPSA92000.zip`.
3. Make a GitHub release tagged `vX.Y.Z` (not marked as a pre-release) and attach `PPSA92000.zip` and `app/payload/installer/RecompStore-Installer.elf`.

Every copy of the store checks this repository's releases when it starts, and every six hours after that. It offers the update at the top of **Latest Updates**.

## The website

`site/public_html` runs on any host with PHP 8 and MySQL. Upload it, open `/admin/setup.php` once to connect the database and make your admin login, then add games and press **Publish** to write the `catalog.json` that the store and the site read. Bug reports, recomp submissions, messages from the Contact page and logs sent from the store land in the admin's **Inbox**. Under **Beta**, make codes for a game marked Beta; its zip stays on the site, outside `public_html`, and only consoles that redeemed a code can download it.

## License and credits

RecompStore is free software under the [GNU General Public License v3.0 or later](LICENSE).

- Made by [elripalda](https://www.elripalda.com). Support it on [Ko-fi](https://ko-fi.com/ripalda).
- Built on [Porpoise](https://github.com/elripalda/Porpoise-Dolphin-Emulator-for-PS5), which builds on Mihawk's PS5 RetroArch port, PS5_Vulkan, and ps5-native-app-boilerplate by BlackBearReloaded.
- The PS5 payload SDK and the launch call follow John Törnblom's work (ps5-payload-dev).
- Home screen integration works with [ShadowMountPlus](https://github.com/drakmor/ShadowMountPlus) by drakmor.
- Uses stb by Sean Barrett. Fonts are under the SIL Open Font License; their license files are in `app/assets/fonts`.
- Each game's own credits are on its page in the store and in its repository.

RecompStore and the games listed in it aren't affiliated with or endorsed by Sony Interactive Entertainment, Microsoft, or any game's publisher or developer. All trademarks belong to their owners.
