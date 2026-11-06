#!/usr/bin/env python3
"""Read-only check: for each "id|repo|asset-pattern|start" line, find the newest non-prerelease
release asset matching the pattern (via the store's own /api/gh.php cache) and list the .exe/.bat
files near the top of the zip by reading only its central directory (HTTP range requests).
Prints one line per game: OK <path> / MISSING plus what it found. Changes nothing."""
import fnmatch, json, struct, sys, urllib.request

UA = {"User-Agent": "RecompStore-ziplist"}

def get(url, rng=None):
    h = dict(UA)
    if rng:
        h["Range"] = rng
    with urllib.request.urlopen(urllib.request.Request(url, headers=h), timeout=60) as r:
        return r.read(), r.headers

def zip_names(url):
    _, hd = get(url, "bytes=0-0")
    size = int(hd.get("Content-Range", "/0").split("/")[-1])
    tail, _ = get(url, "bytes=%d-%d" % (max(0, size - 70000), size - 1))
    i = tail.rfind(b"PK\x05\x06")
    if i < 0:
        return None
    cd_size, cd_off = struct.unpack("<II", tail[i + 12:i + 20])
    if cd_off == 0xFFFFFFFF:  # zip64
        j = tail.rfind(b"PK\x06\x06")
        cd_size, cd_off = struct.unpack("<QQ", tail[j + 40:j + 56])
    cd, _ = get(url, "bytes=%d-%d" % (cd_off, cd_off + cd_size - 1))
    names, p = [], 0
    while p + 46 <= len(cd) and cd[p:p + 4] == b"PK\x01\x02":
        n, e, c = struct.unpack("<HHH", cd[p + 28:p + 34])
        names.append(cd[p + 46:p + 46 + n].decode("utf-8", "replace"))
        p += 46 + n + e + c
    return names

for line in sys.stdin:
    line = line.strip()
    if not line:
        continue
    gid, repo, pat, start = line.split("|")
    try:
        rels, _ = get("http://127.0.0.1/api/gh.php?repo=" + repo)
        rels = json.loads(rels)
        url = None
        for r in rels:
            if r.get("draft") or r.get("prerelease"):
                continue
            for a in r.get("assets", []):
                if fnmatch.fnmatch(a["name"], pat):
                    url = a["browser_download_url"]
                    break
            if url:
                break
        if not url:
            print(gid, "NO-ASSET")
            continue
        names = zip_names(url)
        if names is None:
            print(gid, "NOT-A-ZIP")
            continue
        tops = {n.split("/")[0] for n in names if n.strip("/")}
        root = (list(tops)[0] + "/") if len(tops) == 1 and any("/" in n for n in names) else ""
        rel = [n[len(root):] for n in names if n.startswith(root)]
        hit = [n for n in rel if n.lower() == start.lower()]
        exes = [n for n in rel if n.lower().endswith((".exe", ".bat")) and n.count("/") <= 1][:6]
        print(gid, ("OK " + hit[0]) if hit else "MISSING", "| root=" + (root or "-"), "| exes:", ", ".join(exes))
    except Exception as ex:
        print(gid, "ERR", type(ex).__name__, str(ex)[:80])
