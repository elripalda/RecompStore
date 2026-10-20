# Recomp Store 2.2: what the site adds to the catalog

Everything in docs/api-2.0.md and docs/api-2.1.md still holds. These fields are new; stores before 2.2
skip them, and the rest of the catalog is unchanged (checked: with none of them set, the only
difference from a 2.1 site's catalog is `smp.min`).

The catalog's new fields (sections 1, 2, 3 and 5) are set in the admin and go out with Publish, so
they're inside the signed catalog (`catalog.json` + `catalog.sig`, and the testers' catalog). Section 4
is in the charts (`/api/charts.php`), which aren't signed. Every admin change is in the change log.

## 1. `tabs`: the store's default category tabs

Admin: Settings > Store tabs (each of the four on or off, up and down to order them, "Back to the
default").

```json
"tabs": ["games", "emulators", "apps", "tools"]
```

- The category tabs the store shows in its top bar, in this order. Only `games`, `emulators`,
  `apps` and `tools` ever appear, each at most once.
- `[]`: no category tabs, only Discover, Library and Settings.
- Absent (never set, or set back to the default): all four, in that order, as before 2.2.
- Discover, Library and Settings are always shown and never listed here.
- A player's own choice in the store's Customize wins over this.

## 2. Discover chart rows for one part of the store

Admin: Discover, on a Top charts, Trending, New or Recently updated section: **Only** (Every part of
the store / Games / Emulators / Apps / Tools). The admin preview and the website honour it.

```json
{"type": "top", "title": "Top emulators", "layout": "row", "category": "emulators"}
```

- Only on the chart types `top`, `trending`, `new` and `updated`.
- `category` is one of `games`, `emulators`, `apps`, `tools`.
- Absent means every part, as before 2.2.
- The chart's own order is kept, filtered to apps whose `category` matches. An app with no
  `category` is `games`.
- The existing `category` section type is unchanged.

## 3. `smp`: the ShadowMountPlus the store offers

Admin: Settings > ShadowMountPlus.

- "Check GitHub" lists drakmor/ShadowMountPlus's releases (the site's GitHub cache, 15 minutes).
- "Offer this one" downloads that release once and works out the payload's SHA-256 and size.
- "Stop offering it".
- "Oldest that works" sets the minimum.

drakmor's releases ship as `ShadowMountPlus_<tag>.zip` with `shadowmountplus.elf` at the top
(.github/release-files.txt). A release with a bare `shadowmount*.elf` asset is taken as it is.

```json
"smp": {
  "version": "1.7-beta5fix1",
  "url": "https://github.com/drakmor/ShadowMountPlus/releases/download/1.7-beta5fix1/ShadowMountPlus_1.7-beta5fix1.zip",
  "asset": "ShadowMountPlus_1.7-beta5fix1.zip",
  "elf": "shadowmountplus.elf",
  "sha256": "e85fd63b705498488f39c09c9aea6f9fa22e0df2e932865de49a8cedf25172d5",
  "size": 2498376,
  "min": "1.7beta5"
}
```

### Fields

| Field | What it is |
| --- | --- |
| `version` | The release's tag. |
| `url` | Always exactly `https://github.com/drakmor/ShadowMountPlus/releases/download/<tag>/<asset>`. Tag and asset are letters, numbers, `.`, `_` and `-` only. The site never publishes any other host or repo, and a store should refuse one anyway. |
| `asset` | The file at `url`. |
| `elf` | The path of the payload inside the zip, for example `shadowmountplus.elf`, or `ShadowMount/shadowmountplus.elf` if a release nests it. `""` when `asset` is the .elf itself. |
| `sha256`, `size` | Of the **.elf** (what's sent to port 9021), not of the zip. |
| `min` | The oldest ShadowMountPlus the store should accept without a warning. Default `1.7beta5`. Older versions pause kstuff while the store is open, and sending them again over a running one drops the open store's storage. |

### When a field is there

- `smp` is always in the catalog, with at least `min`.
- `version`, `url`, `asset`, `elf`, `sha256` and `size` are there only while a version is offered.
- If the saved pin is malformed (a wrong host or repo, a bad hash, `..`), only `min` goes out.

### Before the site saves a pin

- The download comes only from the release's own asset on GitHub (github.com or
  *.githubusercontent.com), up to 64 MB.
- Its size must match what GitHub lists, and so must its SHA-256 when GitHub gives one (the
  asset's `digest`).
- The zip must hold a `shadowmountplus.elf`, or a single .elf.
- That file must start with `\x7FELF`.

### Version strings

ShadowMountPlus reports its version in `/api/v1/version` as `shadowmount_version`.

- That's the build's `git describe --tags`, so it's the release tag itself. Checked in the release
  .elfs: `1.7beta5` says `1.7beta5`, and `1.7-beta5fix1` says `1.7-beta5fix1`.
- A build made between releases says something like `1.7beta5-3-gabc123`.
- A build without git says `unknown`.

drakmor's tags aren't uniform, so compare them loosely: numbers in order, then the beta number,
then fix.

- `1.7-beta5fix1` is newer than `1.7beta5`, and both are newer than `1.7beta4`.
- A version with no beta part (`1.7`) is newer than any `1.7beta`.
- `unknown` can't be compared, so don't warn about it.

## 4. `also` in the charts: people also installed

`GET /api/charts.php` (what the store logs as "charts: HTTP 200") gains `also`:

```json
"also": {
  "PPSA80001": ["PPSA80002", "PPSA80003", "PPSA80004", "PPSA80006"],
  "PPSA80002": ["PPSA80001", "PPSA80003", "PPSA80006"]
}
```

- **The map.** For each app, up to 8 other app ids, most telling first.
  - It's always an object. It's `{}` when no app qualifies.
  - An app with no list has no key.
- **Ids only.** No console ids and no counts.
- **Privacy floor:**
  - An app gets a list only once 5 consoles have it (`app_installs`, `removed_at IS NULL`).
  - Another app is on that list only if at least 3 of those consoles have it too.
- **Ranking.** Installs together beyond what the other app's own popularity would give:
  `together - installs(A) x installs(B) / consoles with anything installed`.
  - Only apps above that line are listed, so an app on nearly every console doesn't top every list.
  - Ties: more installs together first, then the id.
- **Ids it can name.** Only apps in the published `catalog.json`, so never hidden, beta or
  testers-only ones. The charts aren't per console or tester-aware, so neither is this.
  - An app hidden after the map was built drops out at once. The filter runs on every charts build.
- **Cost.**
  - One SQL pass: a self-join of `app_installs` on `install_id`, limited to catalog apps with 5 or
    more installs, `HAVING` 3 or more.
  - It's cached on its own (`kv_cache` `charts_also`) and rebuilt at most once an hour. The charts
    around it are still rebuilt every 10 minutes.
  - 36,000 installs (3,000 consoles x 12 of 120 apps) took 0.65 s on a local MariaDB.

The website's game page shows "People also installed" from it, under the page, as a row of tiles,
when the app has a list and the 2.0 side is on.

## 5. `whats_new`: What's new, written on the site

Admin: Settings > What's new. It has Version, Title (optional) and the text, a live preview drawn
like the store's sheet, Save, and Clear. Saving doesn't publish.

```json
"whats_new": {
  "version": "2.2.2",
  "title": "What's new in 2.2.2",
  "body": "Open waits for a new app's tile now, then opens it.\n\n- Library: On this PS5 lists everything in your homebrew folders\n- Move an app to your M.2 or a USB drive\n\nThanks to everyone who sent logs."
}
```

Only in the catalog once it's written; Clear takes it out again at the next Publish. It's in the testers'
catalog too.

- `version`: digits and dots only, 1 to 4 parts of 1 to 4 digits (`2.2.2`). It's the store version
  the notes are about. Show them to players on that version, or about to update to it.
- `title`: one line, at most 80 characters. It's never empty: the site fills in `What's new in <version>`
  when the admin leaves it blank.
- `body`: plain text, at most 4,000 characters (code points).
  - Line breaks are `\n` only.
  - Tabs become spaces.
  - Control characters and bidirectional-override marks are taken out.
  - Trailing spaces are dropped, and three or more line breaks become two.
  - It's never empty.

How to draw `body` (the admin preview and the website do exactly this):

- Split it into lines.
- A line starting with `- ` is a bullet: the text after `- `. Bullets next to each other make one list.
- Any other line that isn't blank belongs to the paragraph it's in. Lines next to each other make one
  paragraph, with a line break between them.
- A blank line ends the paragraph or list it's in.
- A bullet straight after paragraph lines ends that paragraph, and a paragraph line straight after
  bullets ends that list.
- Nothing else is markup: `#`, `*`, `<b>` and links are shown as typed.

## Website

`assets/site.js` honours a chart section's `category` the same way, shows `also` on game pages, and shows `whats_new` in Get Recomp Store, folded under the steps (index.html loads site.js and site.css as `?v=13`).
