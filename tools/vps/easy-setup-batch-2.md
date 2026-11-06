# Easy Setup, batch 2 (Nov 5, 2026)

Same rules as batch 1: a game only got a setup profile when its README says exactly where your copy goes, and the program name was checked against the real release zip. None of these had a profile before.

## N64

| Game | Program | Where the player's copy goes |
|---|---|---|
| Pokemon Snap (Snap64) | Snap64Recomp.exe | ROM next to the exe, USA |
| Perfect Dark | pd.i686.exe | `data/`, USA v1.1 |
| Castlevania: Legacy of Darkness | LodRecomp.exe | ROM next to the exe, USA |
| Rush 2: Extreme Racing USA | Rush2Recompiled.exe | ROM next to the exe, USA only |
| Shadows of the Empire | Shadows of the Empire.exe | ROM next to the exe, USA v1.2 |
| GoldenEye 007 PC Port | ge007.x86_64.exe | `data/`, USA only for now |
| WinBack | WinBackRecompiled.exe | `--rom <file>` |
| Army Men: Sarge's Heroes | ArmyMenSargesHeroesRecompiled.exe | `roms/`, USA only |

## Xbox, Xbox 360, PS1, PS2, GameCube

| Game | Program | Where the player's copy goes |
|---|---|---|
| SmackDown vs. Raw 2007 / 2008 / 2009 / 2010 | svr2007.exe … svr2010.exe | .iso next to the exe (checked as an Xbox disc) |
| WWE '13 | WWE13 Launcher.exe | `WWE 13/` (title update added in its own launcher) |
| WWE 2K14 | WWE 2K14 Launcher.exe | `WWE 2K14/` (same) |
| WWE 2K15 | WWE 2K15 Launcher.exe | `game/` (title update 4 in its own launcher) |
| Ace Combat 6 | ac6recomp.exe | `assets/`, USA only |
| The Darkness | Launch.cmd | `Darkness/` |
| Dead or Alive Xtreme 2 | DOAX2 Recomp Launcher.exe | `extracted game files here/` |
| Summit Strike | summitstrike.exe | `game/` |
| Harry Potter: Philosopher's Stone | hpps.exe | `game/`, PAL build |
| Ghost Recon 2 | ghostrecon2.exe | `game/` |
| Silent Hill (decomp) | SilentHillPC.exe | `gamedata/`, .bin, any region |
| Eternal Darkness | play.bat | `dump/`, Europe GEDP01 only |
| Guitar Hero Classic | GHClassic.exe | `PUT_DISC_HERE/`, Guitar Hero II USA |

Xbox and 360 discs can be picked as an .iso or an extracted folder; the launcher unpacks the .iso.

## SNES, Genesis, CD-i, Atari ST, arcade

| Game | Program | Where the player's copy goes |
|---|---|---|
| Illusion of Gaia (babel) | babel-recomp.exe | `rom.sfc` next to the exe, USA |
| Super Metroid Plus | Play.exe | `games/Super Metroid.sfc` (unheadered) |
| X-Men 2: Clone Wars | XMen2.exe | ROM on the command line |
| Flintstones/Jetsons Timewarp, Laser Lords, Zelda's Adventure | timewarp_hd.exe, laserlords_hd.exe, zelda.exe | `rom/` (.cue + .bin) |
| Dogs of War | dowport.exe | `original/` (.st) |
| Initial D Arcade Stage 3 | Initial D Arcade Stage 3 Recompiled.exe | `game files/` (gds-0033.chd + 317-0384-com.pic) |

## Old PC games

| Game | Program | Where the player's copy goes |
|---|---|---|
| Wolfenstein 3D (wolf3d-x64) | wolf3d.exe | `data/` (v1.4, full or shareware) |
| Test Drive Enhanced | testdrive-enhanced.exe | `Game/` |
| Test Drive II | TD2.exe | `Game/` |
| Test Drive III Enhanced | Test Drive III Enhanced.exe | `Game/` |
| Grand Prix Circuit | GrandPrixCircuit.exe | `gprix2/` |
| Future Cop: LAPD | fcop.exe | `game_files/` (USA or German) |

## Looked at and left as they are

- They ask for your copy in their own window: Rocky, Trials, Crackdown 2, JSRF, Space Ranger, Proving Ground, Alone in the Dark Reborn.
- They build or patch first: Xalabaias Adventure and Super Ryu World (patch + rebuild), Bomberman Party Edition, Masters of Teras Kasi, Skate 2, Amped 2, SSX 3, Rampage, Conker, Cybermage, Por-Go, Settlers remake.
- Mods or installers that go into an existing install: Perfect Dark Zero, BBLIT, NFS II SE, Open Annihilation, Giants.
- Not clear enough to guess: BLACK PC, CannonBall DX (which MAME set), Urban, Xash3D, OpenJK, BStone, doukutsu-rs, Namco System 22 (one zip, many games).
- Fuzion Frenzy: the README says the Windows build has never been run on Windows.

Note: Silent Hill's store download is pinned to a file named `..._OLD.zip`. Worth pointing it at the current build.
