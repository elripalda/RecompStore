#!/usr/bin/env python3
"""Turn the PS5 Homebrew Catalog (the records ProsperoStore installs from,
github.com/blackbearreloaded/ps5-homebrew-catalog, GPL-3.0) into a file for Recomp Store's
admin Import page. Each record is one app with a GitHub release; the import brings them in as
Testers only, so they can be checked on a tester's PS5 before anyone else sees them.

What's taken from the catalog is only what points at the developer's own work: the title ID, the
app's name, the developer, the repo, the release file's name and the icon in the developer's repo.
Descriptions are left empty on purpose: tick "Look things up on GitHub" in the Import page and each
one is filled from the developer's own GitHub page (Prospero's wording and art aren't copied).

  prospero-import.py <catalog checkout> <out.json> [--with-games]

Left out: records with no release yet (reserved IDs), releases that aren't a .zip or .7z
(Recomp Store installs those two), ProsperoStore itself, and games. A game goes into the store by
hand, once it's been tested and installs with the store's ISO/ROM setup; --with-games keeps them."""
import json, glob, os, re, sys

CATEGORY = {"emulators": "emulators", "console-ports": "games", "pc-ports": "games", "homebrew-games": "games",
            "video": "apps", "music": "apps", "streaming": "apps", "ai": "apps", "utilities": "tools", "tools": "tools"}
LABEL = {"console-ports": "Port", "pc-ports": "PC port", "homebrew-games": "Homebrew", "video": "Video", "music": "Music",
         "streaming": "Streaming", "ai": "AI", "utilities": "Utility", "tools": "Tool", "emulators": "Emulator"}
SKIP = {"PPSA99000"}  # ProsperoStore: another store, not an app for this one


def pattern(file, version):
    """The release file's name with its version turned into *, so each newer release matches."""
    name = file
    for v in sorted({version, version.lstrip("vV"), "v" + version.lstrip("vV")}, key=len, reverse=True):
        if v and (len(v) >= 3 or v[0] in "vV") and v in name:
            return name.replace(v, "*", 1)
    # "Mortalfin-1.0.0-beta.5.zip", "PS5SX2-vk-285-177.zip": a version-looking run of numbers.
    m = re.search(r"(?<![A-Za-z0-9])v?\d+(?:[.\-]\d+)+(?:[-.]?(?:beta|alpha|rc)[.\-]?\d*)?(?=[-_.](?:zip|7z|PPSA)|-[A-Za-z])", name)
    if m:
        return name[:m.start()] + "*" + name[m.end():]
    m = re.search(r"(?<=[-_])v\d+(?=\.(zip|7z)$)", name)  # "ProsperoExplorer-v1.zip"
    return name[:m.start()] + "v*" + name[m.end():] if m else name


def main(src, out, with_games=False):
    apps, left = [], []
    for f in sorted(glob.glob(os.path.join(src, "apps", "*.json"))):
        r = json.load(open(f, encoding="utf-8"))
        tid, url, repo = r.get("titleid", ""), r.get("artifact_url") or "", r.get("source_repo") or ""
        file = url.rsplit("/", 1)[-1]
        why = ("ProsperoStore itself" if tid in SKIP else "no release yet" if not url or not repo
               else "not a .zip or .7z (" + file + ")" if not re.search(r"\.(zip|7z)$", file, re.I)
               else "not on GitHub" if not re.match(r"https://github\.com/[^/]+/[^/]+", repo) else "")
        if why:
            left.append({"id": tid, "name": r.get("name", ""), "why": why})
            continue
        cat = r.get("category", "")
        if CATEGORY.get(cat, "apps") == "games" and not with_games:
            left.append({"id": tid, "name": r.get("name", ""), "why": "a game: added by hand once it's tested"})
            continue
        tags =[LABEL[cat]] if cat in LABEL and LABEL[cat] != "Emulator" else []
        apps.append({
            "id": tid, "name": r.get("name", ""), "author": r.get("author", ""),
            "category": CATEGORY.get(cat, "apps"), "tags": tags[:6],
            "blurb": "", "description": "",
            "repo": repo, "asset": pattern(file, str(r.get("version", ""))),
            "icon": r.get("icon_url", ""),
            "from": "PS5 Homebrew Catalog (" + os.path.basename(f) + ")",
        })
    json.dump({"source": "PS5 Homebrew Catalog, github.com/blackbearreloaded/ps5-homebrew-catalog (GPL-3.0)",
               "apps": apps, "leftOut": left}, open(out, "w", encoding="utf-8"), indent=1, ensure_ascii=False)
    print(f"{len(apps)} apps for the import, {len(left)} left out:")
    for x in left:
        print(f"  {x['id']} {x['name']}: {x['why']}")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], "--with-games" in sys.argv[3:])
