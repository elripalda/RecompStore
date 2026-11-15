#!/usr/bin/env python3
"""Daily drafts for the RecompLauncher PR queue (SDK support > PR queue in the admin).

For each pick the site hands out (half just-added recomps, half popular console ports, anything queued
by hand first), it reads the project on GitHub, drafts recomplauncher.json and the pull request's
wording, checks the file against the published schema and posts the draft back for a person to look
over and send. Nothing is sent to the project from here.

Environment:
  RS_PRQ_KEY         the drafter key made on SDK support (required)
  ANTHROPIC_API_KEY  the model's API key (required)
  GITHUB_TOKEN       read access to public repos (the Action's own token is enough)
  PRQ_COUNT          how many to draft this run (default 8)
  PRQ_MODEL          the model (default claude-sonnet-5-5; the site's Settings > AI usage choice when unset)
  PRQ_MODEL_CODE     (4.11) the model for the code step (default: PRQ_MODEL)
  PRQ_DRY            (4.11) a folder: test run. Each draft is written there as <id>__<model>__<time>.json
                     and nothing is saved to the queue or sent anywhere (Launcher support > Check a game)
  PRQ_WORKERS        how many to draft side by side (default 3)
  RS_SITE            default https://recompstore.com
"""
import io
import json
import os
import re
import struct
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor
import urllib.error
import urllib.parse
import urllib.request
import zipfile

SITE = os.environ.get("RS_SITE", "https://recompstore.com").rstrip("/")
KEY = os.environ.get("RS_PRQ_KEY", "")
AKEY = os.environ.get("ANTHROPIC_API_KEY", "")
GHT = os.environ.get("GITHUB_TOKEN", "")
COUNT = int(os.environ.get("PRQ_COUNT", "8") or 8)
MODEL = os.environ.get("PRQ_MODEL", "claude-sonnet-5-5")
CODE_MODEL = os.environ.get("PRQ_MODEL_CODE", "")  # (4.11) '' = the same as MODEL
DRY = os.environ.get("PRQ_DRY", "")
# (4.10) Tokens used per project, sent back with its draft so the site can count the cost (Settings > AI usage).
# (4.11) Kept per model, since the code step can use another one. Haiku costs more per token once a single
# prompt is over 100,000 tokens, so those calls are counted as "<model>:long".
USAGE = {}
USAGE_LOCK = threading.Lock()
TOK = ("input_tokens", "output_tokens", "cache_read_input_tokens", "cache_creation_input_tokens")


def note_usage(pid, j, model=None):
    u = (j or {}).get("usage") or {}
    m = model or MODEL
    if "haiku" in m and sum(int(u.get(k) or 0) for k in ("input_tokens", "cache_read_input_tokens", "cache_creation_input_tokens")) > 100000:
        m += ":long"
    with USAGE_LOCK:
        t = USAGE.setdefault(str(pid), {}).setdefault(m, {"model": m, "calls": 0, **{k: 0 for k in TOK}})
        t["calls"] += 1
        for k in TOK:
            t[k] += int(u.get(k) or 0)


def take_usage(pid):
    """-> [{model, calls, tokens...}] for this project (one per model it used), or None."""
    with USAGE_LOCK:
        u = USAGE.pop(str(pid), None)
    return list(u.values()) if u else None
IDS = os.environ.get("PRQ_IDS", "")  # Draft now on SDK support: just these recomps
WORKERS = max(1, min(6, int(os.environ.get("PRQ_WORKERS", "3") or 3)))  # drafts made side by side
SPEC_URL = "https://recomplauncher.com/sdk-files/recomplauncher-agent.md"
SCHEMA_URL = "https://recomplauncher.com/schema/recomplauncher.json"
UA = "RecompStore-PR-Drafter"


def http(url, data=None, headers=None, method=None, timeout=60, raw=False, limit=80 << 20):
    h = {"User-Agent": UA}
    h.update(headers or {})
    body = None
    if data is not None:
        body = json.dumps(data).encode() if not isinstance(data, bytes) else data
        h.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=body, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            b = r.read(limit)
            return r.status, (b if raw else b.decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:
        return e.code, (b"" if raw else e.read().decode("utf-8", "replace"))
    except Exception as e:  # network trouble: the caller decides
        return 0, (b"" if raw else str(e))


def gh(path, accept="application/vnd.github+json"):
    h = {"Accept": accept}
    if GHT:
        h["Authorization"] = "Bearer " + GHT
    st, b = http("https://api.github.com" + path, headers=h, timeout=30)
    if st != 200:
        return None
    if accept.endswith("raw"):
        return b
    try:
        return json.loads(b)
    except ValueError:
        return None


GH_LOCK = threading.Lock()


def gh_room(need=150):
    """(4.10) Wait for GitHub's hourly limit when it's nearly used up (the account's other tools share it),
    rather than failing the draft. Asking for the limit doesn't count against it."""
    with GH_LOCK:
        for _ in range(3):
            h = {"Accept": "application/vnd.github+json"}
            if GHT:
                h["Authorization"] = "Bearer " + GHT
            st, b = http("https://api.github.com/rate_limit", headers=h, timeout=20)
            try:
                core = json.loads(b)["resources"]["core"] if st == 200 else {}
            except (ValueError, KeyError, TypeError):
                core = {}
            left, reset = int(core.get("remaining", need)), int(core.get("reset", 0))
            wait = reset - time.time() + 5
            if left >= need or wait <= 0:
                return
            if wait > 1900:
                return  # (more than half an hour: try anyway and say why if it fails)
            print(time.strftime("%H:%M:%S"), f"GitHub's hourly limit is used up ({left} left): waiting {int(wait)} s for it to reset")
            time.sleep(wait)


def site(method, a, data=None):
    if DRY and a in ("draft", "fail", "touch"):
        return dry_save(a, data or {})
    st, b = http(f"{SITE}/api/prq.php?a={a}" + (f"&n={COUNT}" + (f"&ids={urllib.parse.quote(IDS)}" if IDS else "") + ("&dry=1" if DRY else "") if a == "todo" else ""), data=data,
                 headers={"X-PRQ-Key": KEY, "Accept": "application/json"}, method=method, timeout=60)
    try:
        return st, json.loads(b)
    except ValueError:
        return st, {"ok": False, "error": b[:300]}


DRY_STARTED = {}


def dry_file(pid):
    key = re.sub(r"[^a-z0-9-]+", "", MODEL.replace("claude-", "").lower())
    if CODE_MODEL and CODE_MODEL != MODEL:
        key += "+" + re.sub(r"[^a-z0-9-]+", "", CODE_MODEL.replace("claude-", "").lower())
    stamp = DRY_STARTED.setdefault(str(pid), time.strftime("%Y%m%d-%H%M%S", time.gmtime()))
    return os.path.join(DRY, re.sub(r"[^A-Za-z0-9_.-]+", "-", str(pid)) + "__" + key + "__" + stamp + ".json")


def dry_mark(pid):
    """(4.11) A test run: the file says "running" until the draft is in, so the page can show it."""
    with open(dry_file(pid), "w", encoding="utf-8") as f:
        json.dump({"id": pid, "status": "running", "model": MODEL, "code_model": CODE_MODEL or MODEL, "started": time.time()}, f)


def dry_save(a, data):
    """(4.11) A test run keeps the draft in a file instead of the queue. Its cost still goes on Settings > AI usage."""
    if a == "touch":
        return 200, {"ok": True}
    pid = str(data.get("id", ""))
    out = {"id": pid, "status": "skipped" if data.get("skip") else ("failed" if a == "fail" else "done"), "model": MODEL, "code_model": CODE_MODEL or MODEL,
           "started": None, "finished": time.time(), "draft": data}
    try:
        with open(dry_file(pid), encoding="utf-8") as f:
            out["started"] = json.load(f).get("started")
    except (OSError, ValueError):
        pass
    with open(dry_file(pid), "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    if data.get("usage"):
        http(f"{SITE}/api/prq.php?a=usage", data={"usage": data["usage"]}, headers={"X-PRQ-Key": KEY, "Accept": "application/json"}, method="POST", timeout=30)
    print(("tested " if a == "draft" else "test failed ") + pid + " -> " + os.path.basename(dry_file(pid)))
    return 200, {"ok": True}


def pattern_re(p):
    return re.compile("^" + re.escape(p).replace(r"\*", ".*").replace(r"\?", ".") + "$", re.I)


def zip_listing(url, size):
    """The file names inside a release zip, so the start program is a real path. Read from the zip's own index
    with two ranged requests (any size); the whole download only when that fails and it's up to 120 MB."""
    if not url:
        return []
    names = zip_index_names(url, size)
    if not names and size <= (120 << 20):
        st, b = http(url, headers={"Accept": "application/octet-stream"}, timeout=180, raw=True, limit=125 << 20)
        try:
            with zipfile.ZipFile(io.BytesIO(b)) as z:
                names = z.namelist() if st == 200 and b else []
        except zipfile.BadZipFile:
            names = []
    # The top of the zip matters most (the program, recomplauncher.json): shallowest first.
    files = [n for n in names if not n.endswith("/")]
    return sorted(files, key=lambda n: (n.count("/"), n))[:400]


def zip_index_names(url, size):
    """(4.11) The names in a zip's central directory: its last MB for the end record, then the directory itself
    if it starts earlier. [] for zip64, a directory over 16 MB, or a server that ignores ranges."""
    if size <= 22:
        return []
    tail = min(size, 1 << 20)
    st, b = http(url, headers={"Range": f"bytes={size - tail}-{size - 1}"}, timeout=60, raw=True, limit=tail + 1024)
    if st != 206 or not b:
        return []
    at = b.rfind(b"PK\x05\x06")
    if at < 0 or len(b) - at < 22:
        return []
    cd_size, cd_off = struct.unpack_from("<II", b, at + 12)
    if cd_off == 0xFFFFFFFF or cd_size > (16 << 20):
        return []
    start = size - tail
    cd = b[cd_off - start:cd_off - start + cd_size] if cd_off >= start else b""
    if cd_off < start:
        st, cd = http(url, headers={"Range": f"bytes={cd_off}-{cd_off + cd_size - 1}"}, timeout=60, raw=True, limit=cd_size + 1024)
        if st != 206:
            return []
    names, i = [], 0
    while i + 46 <= len(cd) and cd[i:i + 4] == b"PK\x01\x02":
        n, extra, comment = struct.unpack_from("<HHH", cd, i + 28)
        names.append(cd[i + 46:i + 46 + n].decode("utf-8", "replace"))
        i += 46 + n + extra + comment
    return names


# (4.9) Where a project reads its command line and its paths, so the draft can use the options it
# already has (whatever they're called) for the game's location, saves and starting straight in.
SRC_EXT = (".c", ".cc", ".cpp", ".cxx", ".h", ".hpp", ".rs", ".py", ".cs", ".go", ".zig", ".java", ".kt", ".js", ".ts", ".lua")
HINT_NAME = re.compile(r"(^|/)[^/]*(main|app|args?|argv|cli|cmd|command.?line|options?|launch|config|settings|paths?|program|entry|platform|window|startup|boot|engine|game|init|loader|import|rom|iso|disc|dump|extract|setup|install|picker|dialog|menu|gui|frontend)[^/]*$", re.I)
SKIP_DIR = re.compile(r"(^|/)(third_?party|extern(al)?|vendor|deps|submodules?|tests?|docs?|tools?/|sdk/|codegen|generator)", re.I)
OPT_LINE = re.compile(r"""(["'](-{1,2}|/)[A-Za-z][\w-]{1,30}["']|\bargv\b|\bargc\b|getopt|cxxopts|CLI11|clap::|argparse|add_argument|parse_args|env::args|GetCommandLine|CommandLineToArgv|FULLSCREEN|borderless|fullscreen|windowed|save_?dir|saves?_?(path|folder)|user_?(dir|folder|path)|game_?(path|dir)|rom_?path|iso_?path|romfs|exefs|data_?path|OpenFileDialog|GetOpenFileName|IFileOpenDialog|SDL_ShowOpenFileDialog|tinyfd_\w+|NFD_\w+|FileDialog|QFileDialog|rfd::|\.(iso|xci|nsp|nup|wbfs|rvz|gcm|z64|n64|v64|sfc|smc|gba|nds|xex|cso|chd)\b|main\.dol)""", re.I)


def cli_hints(repo, branch, paths):
    cands = [p for p in paths if p.lower().endswith(SRC_EXT) and HINT_NAME.search(p) and not SKIP_DIR.search(p)]
    cands.sort(key=lambda p: (0 if re.search(r"(^|/)(main|program)\.", p, re.I) else 1 if re.search(r"launch|arg|cli|option|import|picker|dialog|rom|iso|dump|setup", p.rsplit("/", 1)[-1], re.I) else 2, p.count("/"), len(p)))
    out, total = [], 0
    for path in cands[:20]:
        raw = gh(f"/repos/{repo}/contents/{urllib.parse.quote(path)}?ref={urllib.parse.quote(branch)}", "application/vnd.github.raw")
        if not raw:
            continue
        keep = [f"{i + 1}: {l.strip()[:200]}" for i, l in enumerate(raw.splitlines()) if OPT_LINE.search(l)]
        if keep:
            chunk = "\n".join(keep[:70])
            out.append({"file": path, "lines": chunk})
            total += len(chunk)
            if total > 26000:
                break
    return out


# What the model sees of the repo's file list: big repos (thousands of files) still fit, minus folders that
# never hold the command line, the setup or the packaging.
NOISE_DIR = re.compile(r"(^|/)(third_?party|extern(al)?|vendor|deps|submodules?|node_modules|\.git|assets?|res(ources)?|data|images?|fonts?|locales?|translations?|tests?/fixtures)/", re.I)


def listed_files(paths, cap=900):
    keep = [x for x in paths if not NOISE_DIR.search(x)]
    return keep[:cap], len(keep) > cap or len(keep) < len(paths)


def windows_asset(assets, pattern):
    """The release file the launcher would take on Windows: the listing's own pattern, else a Windows x64 zip
    by its name, else any Windows zip. None when there's no Windows zip (an installer .exe can't be listed)."""
    if pattern:
        rx = pattern_re(pattern)
        hit = next((a for a in assets if rx.match(a["name"])), None)
        if hit:
            return hit
    zips = [a for a in assets if a["name"].lower().endswith(".zip") and "source" not in a["name"].lower()]
    win = [a for a in zips if re.search(r"win", a["name"], re.I)]
    x64 = [a for a in win if re.search(r"x64|x86_64|amd64", a["name"], re.I)]
    return (x64 or win or [None])[0]


# (4.11) Where the release is made, so the code step can copy recomplauncher.json into it: the launcher reads
# the file from the release (top of the zip, AppImage root), never from a GitHub repo.
PACK_NAME = re.compile(r"(^|/)(Makefile|CMakeLists\.txt|[^/]*(package|packaging|release|dist|bundle|appimage|installer)[^/]*\.(sh|py|ps1|bat|cmd|cmake|mk|yml|yaml)|build\.(sh|py|ps1|bat)|AppRun)$", re.I)


def packaging_files(repo, branch, paths):
    """-> {path: whole text} for up to 5 release/packaging scripts: release-named ones first, workflows last."""
    cands = [x for x in paths if PACK_NAME.search(x) and not NOISE_DIR.search(x) and not re.search(r"(^|/)tests?/|_test\.|test_", x, re.I)]
    cands.sort(key=lambda x: (x.startswith(".github/"), not re.search(r"release|dist|package|packaging|appimage", x, re.I), x.count("/"), len(x)))
    out = {}
    for path in cands[:8]:
        raw = gh(f"/repos/{repo}/contents/{urllib.parse.quote(path)}?ref={urllib.parse.quote(branch)}", "application/vnd.github.raw")
        if raw and len(raw) < 200000:
            out[path] = raw
        if len(out) >= 5:
            break
    return out


def context(p):
    repo = p["repo"]
    info = gh(f"/repos/{repo}")
    if not info and http(f"https://api.github.com/repos/{repo}", headers={"Accept": "application/vnd.github+json", **({"Authorization": "Bearer " + GHT} if GHT else {})}, timeout=20)[0] == 404:
        raise RuntimeError("GitHub says this repo doesn't exist anymore (or it's private now).")
    if not info:
        # (4.9) No repo means GitHub refused (its hourly limit, usually): no draft from nothing.
        raise RuntimeError("Couldn't read the repo on GitHub (its hourly limit may be used up). Try again later.")
    branch = info.get("default_branch", "main")
    readme = gh(f"/repos/{repo}/readme", "application/vnd.github.raw") or ""
    contributing = ""
    for name in ("CONTRIBUTING.md", ".github/CONTRIBUTING.md", "docs/CONTRIBUTING.md"):
        c = gh(f"/repos/{repo}/contents/{name}", "application/vnd.github.raw")
        if c:
            contributing = c[:4000]
            break
    tree = gh(f"/repos/{repo}/git/trees/{branch}?recursive=1") or {}
    paths = [t["path"] for t in tree.get("tree", []) if t.get("type") == "blob"]
    rels = gh(f"/repos/{repo}/releases?per_page=3") or []
    releases = []
    for r in rels:
        if r.get("draft"):
            continue
        releases.append({"tag": r.get("tag_name"), "prerelease": r.get("prerelease"), "published": (r.get("published_at") or "")[:10],
                         "notes": (r.get("body") or "")[:1500],
                         "assets": [{"name": a["name"], "size_mb": round(a.get("size", 0) / 1048576, 1)} for a in r.get("assets", [])]})
    # What's inside the Windows download of the newest release (by name: the first zip was often the Mac one).
    inside, inside_of = [], ""
    if rels:
        pick = windows_asset(rels[0].get("assets", []), p.get("assets", {}).get("win", ""))
        if pick and pick["name"].lower().endswith(".zip"):
            inside, inside_of = zip_listing(pick.get("browser_download_url"), pick.get("size", 0)), pick["name"]
    ships_json = any(n.rsplit("/", 1)[-1].lower() == "recomplauncher.json" for n in inside)
    packaging = packaging_files(repo, branch, paths)
    files, files_cut = listed_files(paths)
    # (4.10) Docs about its settings and command line (runtime/docs/CONFIG.md, docs/usage.md...): the key
    # names and flags often live there, not in the README, and without them a feature can't go in the json.
    config_docs = {}
    doc_rx = re.compile(r"(^|/)[^/]*(config|settings|options|usage|command.?line|cli|args|launch|controls|keybind)[^/]*\.(md|txt|ini|toml|ya?ml)$", re.I)
    for path in sorted((x for x in paths if doc_rx.search(x) and not re.search(r"(^|/)(third_?party|extern(al)?|vendor|deps|node_modules)/", x, re.I)), key=lambda x: x.count("/"))[:4]:
        c = gh(f"/repos/{repo}/contents/{urllib.parse.quote(path)}?ref={urllib.parse.quote(branch)}", "application/vnd.github.raw")
        if c:
            config_docs[path] = c[:8000]
    open_prs = gh(f"/repos/{repo}/pulls?state=open&per_page=50") or []
    return {
        "info": {"description": info.get("description"), "archived": info.get("archived"), "default_branch": branch,
                 "has_issues": info.get("has_issues"), "stars": info.get("stargazers_count"), "language": info.get("language")},
        "readme": readme[:24000], "contributing": contributing,
        "files": files, "files_truncated": files_cut or bool(tree.get("truncated")),
        "releases": releases, "inside_download": {"file": inside_of, "names": inside, "has_recomplauncher_json": ships_json},
        "windows_download_on_listing": bool(p.get("assets", {}).get("win")),
        "open_pull_requests": [{"title": x.get("title"), "body": (x.get("body") or "")[:300]} for x in open_prs[:30]],
        "command_line": cli_hints(repo, branch, paths),
        "config_docs": config_docs,
        "packaging": {k: v[:6000] for k, v in packaging.items()},
        # (underscored: for this script only, never sent to the model)
        "_branch": branch, "_paths": paths, "_packaging": packaging, "_ships_json": ships_json,
    }


SYSTEM = """You prepare pull requests that add RecompLauncher support to PC recomps and decomps on GitHub.
For one project you get what's known about it: its README and CONTRIBUTING, its file list, its newest releases,
the file names inside its Windows download ("inside_download"), lines from its source where it reads its command
line and paths ("command_line"), docs and sample files about its settings or command line ("config_docs"), its
release and packaging scripts ("packaging"), open pull requests, and what RecompLauncher's catalog already knows
("project"). You write its recomplauncher.json, following the SDK guide below exactly, plus a report for the team.

The goal is the pull request a careful developer would send by hand: the launcher installs the recomp, sets it up
from the player's own copy, starts it straight into the game, knows its settings, saves and logs, and the file
ships in every release. This step describes what the project has today. A second step reads your report and adds
what's missing to the project's code and packaging (a --game option, a --prepare mode, the line that puts
recomplauncher.json in the release), so be exact about where things are.

Rules:
- Only describe what the project really has. Every path, argument, folder and file name must come from its
  README, files, releases or the download's contents. Leave a field out rather than guess. Never invent features.
- Never mark anything as working that you can't see. If quick setup (Easy Setup) can't be described honestly from
  what's there, leave those parts out and say so in "better".
- No game files, keys or copyrighted content, ever.
- Skip the project (skip: true) when: it's archived; an open pull request already adds recomplauncher.json or
  RecompLauncher support; its README or CONTRIBUTING says it doesn't want outside, automated or AI-made pull
  requests; or it isn't a playable PC build of a game.
- The lines for the pull request are short, friendly and plain, like a person wrote them. No emoji, no hype,
  no "As an AI", no mention of tools or models.
- Don't write code here. This step only describes what the project has today.

What every draft looks for, under whatever names the project uses. Check the README, "command_line", "config_docs",
"packaging", the file list and the download's contents:
1. The game's location: an option or settings key that points the recomp at the player's copy (a file or a folder),
   like --game, --iso, --rom, --romfs, --path, a plain path argument, or a key in its settings file. When it has one,
   use it: "start.args" with {game}, or "config.game_path" for a settings key, so the launcher passes the copy in.
   Describe the copy in "game" ("kind": "iso" for a single disc image or ROM file, "folder" for an extracted game,
   "iso_or_folder" when it takes either) with a "check" when the README names a file, serial or ID, and "dumps"
   when it gives a size and CRC32.
2. A setup step. Some projects can't play straight from the player's copy: they import, extract or build the game
   first, in their own setup screen, installer or builder (a "Builder" that recompiles the game on the player's PC
   is one). Find the command that does it without a window, if there is one (a subcommand, a flag, a script). With
   one, use "prepare" with {game}, make the game inside {install}, and point "start" at the program it makes, so Play
   starts the game and not the setup tool. Without one, say so: the second step can add a --prepare mode. Never point
   "start" at a setup tool, builder or installer when the game it makes can be started directly.
3. Where saves go: the real save folder or files (for "saves" and folders.saves), and any option that sets the save
   or user folder (like --user, --savedir, --portable). Record where the saves really are. Don't move them.
4. Starting straight into the game, borderless or fullscreen: options or settings that skip a built-in launcher,
   picker or menu, or that set windowed, borderless or fullscreen. When it has a display mode, offer it in "settings"
   ("in": "args", or its settings file key), and start straight into the game when there's an option for it. A
   settings key that skips its own launcher or menu (like SkipLauncher = 1) goes in that config's "always".
5. Its settings file: the file, its format and the real key names (README, "config_docs", sample or dev config
   files in the repo). Describe the settings players care about in "settings": window mode, resolution or scale,
   aspect ratio and widescreen, frame rate, V-Sync, upscaling.
6. Logs: a log file or folder ("folders.logs"), so crash reports carry it.
7. Release packaging: the script or workflow that makes the release zip or AppImage, and whether
   recomplauncher.json ends up next to the program ("inside_download" says if the current release has it). The
   launcher reads the file from the release (the top of the zip, or the AppImage root), never from a GitHub repo, so
   this matters as much as the file itself. Say where (file:line) a copy line would go.

Places the setup makes count. A file or folder that only exists after setup is still a fixed place when you can
see where it's made: once the setup makes the game inside {install}, its saves and settings are {install}/... paths
and go in the json. Mark "hook": false only when a thing truly lives in the game's own menus or GUI, with nothing
the launcher can pass, write or point at.

Shape mistakes to avoid (the schema checks them):
- "saves" is a list of path strings; each "folders" entry is a list of {"path": ...} objects.
- Each "config" entry needs "format" (toml, ini or json) and "file". With no known file location, leave it out.
- A setting needs "choices" (two or more), a "slider" or "bindings". Values are written exactly as given (TOML
  strings keep their quotes), and keys inside an INI or TOML section are written Section/Key.
- Always "format": 1 and "$schema": "https://recomplauncher.com/schema/recomplauncher.json". Leave "repo" out.
- "requires": the launcher features the file uses, from the guide's list, so older launchers leave out what they
  can't do instead of failing.

For each of 1, 2 (when the project needs a setup step), 4 and 7 the project doesn't have, add one "better" line
asking for it by name with a size, like
"Small: a --game <path> option, so launchers and Steam shortcuts can start it with the player's copy",
"Medium: a --prepare mode that builds the game from the player's copy without the window, printing progress lines",
"Small: --fullscreen and --borderless options that start straight into the game", or
"Small: copy recomplauncher.json next to the exe in the release zip, so the launcher reads it from each release".
After those, other "better" lines only when they're true for this project and you can see it:
release file names without the system and processor ("MyRecomp-1.2.0-windows-x64.zip"), no Linux or Steam Deck
build, no log file, not reading RECOMPLAUNCHER_MODE (full screen in Big Picture mode) or RECOMPLAUNCHER_PAD (the
player's button prompts), no way to quit from the game's own menu with a controller, and achievements through
Recomp Achievements (https://github.com/elripalda/RecompAchievements). Never claim something exists without seeing
it in the README, the code lines or the files. In "notes", say what you found and where (file and line).

Answer with one JSON object and nothing else:
{"skip": false, "skip_reason": "",
 "recomplauncher_json": { ... the file ... },
 "better": ["concrete things that would make the recomp work better with the launcher, each starting with its size: Small, Medium or Large. The asks above come first. 0 to 7 lines"],
 "found": {"features": [{"what": "Game location", "has": true, "hook": true, "how": "--dump <path>: the extracted dump folder", "where": "src/main.c:120", "used": true},
                        {"what": "Setup or build step", "has": true, "hook": false, "how": "the Builder window's Build button, no command without the window", "where": "cmd/builder/build.go:47", "used": false, "why": "no way to run it without its window; the code step can add --prepare"},
                        {"what": "Release packaging", "has": true, "hook": true, "how": "make dist zips build/ without recomplauncher.json", "where": "Makefile:88", "used": false, "why": "a copy line is needed there"},
                        {"what": "Logs", "has": false, "needed": true, "why": "it writes no log file"}],
           "options": [{"flag": "--dump <path>", "does": "where it reads the game's files", "where": "src/main.c:120"}]},
 "notes": "for the person reviewing: anything you weren't sure about, in a sentence or two (or empty)"}

"found" is for the team, and the second step works from it, so be thorough there:
- "features": one entry each, with exactly these names, for Game location, Setup or build step, Saves, Start
  straight into the game, Fullscreen or borderless, Settings file, Logs, Mods or packs, and Release packaging, plus
  any other launcher-relevant thing it has (an online mode, a built-in launcher or picker). "has": true, false or
  null when you couldn't tell.
  "how": the flag, settings key, file or folder, and what it takes. "where": file:line (or the README section)
  you saw it in. "used": whether your recomplauncher.json uses it.
  "hook": whether the launcher can actually drive it: a command-line flag, an environment variable, a settings
  file key it can write, or a fixed folder or file it can point at. A feature that only lives in the game's own
  menus or its own GUI (fullscreen in the in-game settings, say) has "has": true but "hook": false, because
  the launcher can't do anything with it. What we care about is what can be paired with the json.
  The point of the pull request is to wire up every hook the project has: when "hook" is true and the SDK guide
  has a field for it, put it in your recomplauncher.json. Leaving a hooked feature out of the json should be
  rare, and then "why" says exactly what's missing (no field in the SDK for it, or the key's value unknown).
  When "has" is true but "used" is false, give "why": one short line on why your recomplauncher.json doesn't
  use it (the launcher has no field for it, the key names weren't anywhere you could read, it's only in an
  in-game menu...). Before leaving one out, check "config_docs": the key names and flags are often there.
  If what you need is there, use it.
  When "has" is false, also give "needed" (true or false) and "why" (one short line). "needed": false when this
  project simply doesn't need it, like a setup or prepare step for a game that reads the ROM or disc file
  directly, or a game location for one that ships everything it needs. "why" says why it isn't needed, or, when
  it is needed, why this pull request doesn't add it (a bigger change than a small option, say).
- "options": every command-line option the project reads that you can see, with what it does and where.
Only list what you actually saw. Empty lists are fine.

THE SDK GUIDE:
"""


def ask(spec, p, ctx, fix=None):
    github = {k: v for k, v in ctx.items() if not k.startswith("_")}
    user = {"project": {k: p[k] for k in ("repo", "name", "game", "console", "page", "assets", "needs", "setup_required", "setup_steps", "our_profile")}, "github": github}
    ask_more = ""
    if p.get("pick") == "asked":
        ask_more = ("\n\nThe team picked this one by hand, so draft it even if its setup is unusual (a Setup program, a build "
                    "on the player's PC). Cover what you can verify, like saves, logs, folders and the start program when it's known, "
                    "and put what you couldn't confirm in notes. Only skip it if it's archived, already has an open RecompLauncher "
                    "pull request, or its README or CONTRIBUTING says no outside pull requests.")
    if p.get("pick") == "redo":
        ask_more = ("\n\nThis updates a pull request we already opened on this repo: draft the file again from scratch with the three "
                    "checks. That pull request of ours is not a reason to skip. Only skip it if the repo is archived or its README or "
                    "CONTRIBUTING says no outside pull requests.")
    msgs = [{"role": "user", "content": "Draft RecompLauncher support for this project." + ask_more + "\n\n" + json.dumps(user, ensure_ascii=False)}]
    if fix:
        msgs += [{"role": "assistant", "content": fix["prev"]},
                 {"role": "user", "content": "That recomplauncher.json doesn't pass the schema:\n" + fix["errors"] + "\nFix it and answer with the whole JSON object again."}]
    body = {"model": MODEL, "max_tokens": 16000,
            "system": [{"type": "text", "text": SYSTEM + spec, "cache_control": {"type": "ephemeral"}}],
            "messages": msgs}
    for attempt in range(3):
        st, b = http("https://api.anthropic.com/v1/messages", data=body, timeout=240,
                     headers={"x-api-key": AKEY, "anthropic-version": "2023-06-01"})
        if st == 200:
            j = json.loads(b)
            note_usage(p.get("id"), j)
            text = "".join(c.get("text", "") for c in j.get("content", []) if c.get("type") == "text")
            m = re.search(r"\{.*\}", text, re.S)
            if not m:
                raise RuntimeError("no JSON in the answer")
            try:
                return json.loads(m.group(0)), m.group(0)
            except ValueError as e:
                # (4.9) A slip in the JSON (an unescaped quote or backslash): asked once to send it again.
                if fix and fix.get("json_retry"):
                    raise
                return ask(spec, p, ctx, {"prev": m.group(0), "errors": f"- it isn't valid JSON ({e}). Escape quotes and backslashes inside strings.", "json_retry": True})
        if st in (429, 500, 502, 503, 529):
            time.sleep(20 * (attempt + 1))
            continue
        raise RuntimeError(f"model call failed ({st}): {b[:300]}")
    raise RuntimeError("model busy, try the next run")


# ---- (4.9) The code pass: what the project itself needs, added to it --------------------------------------
# Xarishark: the pull requests should do what the hand-made ones do: --game <path> (Easy Setup), a --prepare mode
# when the game has to be set up or built first, and recomplauncher.json copied into the release. Only as exact
# find/replace edits to files we've read whole, and only when nothing changes without the new options.
CODE_SYSTEM = """You make the code and packaging changes that let a launcher set up and start a PC recomp or decomp with
the player's own copy of the game. You get the project's recomplauncher.json draft, the report of what it already
has ("found"), what's asked of you ("todo"), the file list ("tree"), and the text of the files where it reads its
command line, picks or builds the game, and makes its release.

The todo can ask for:
- "game": a --game <path> option for the player's copy (a file or a folder, whatever the project reads today). It
  must feed the exact same path variable or setting the project's own picker or default location fills in, so
  everything after that works unchanged. When the project picks the copy in its own dialog (a file picker, an
  import screen), --game hands the same path to the code the dialog calls, skipping the dialog.
- "prepare": a --prepare mode that does the project's setup step without a window: the same import, extract or
  build its setup screen or builder does, by calling the same function that screen calls, with the copy from
  --game. In a program built around subcommands, a subcommand (like "build --rom <path>") fits better than a flag:
  follow the project's own pattern. It prints lines like "progress 0.4 Unpacking files" (a number from 0 to 1,
  then a few words) as it goes, exits 0 when the game is ready, and exits non-zero with one line on stderr when it
  isn't. Keep the project's own default for where the game is made, or use an output folder option it has already.
- "packaging": one line in the release or packaging script (a Makefile dist target, a package or build script)
  that copies recomplauncher.json from the repo root next to the program in the release zip, and to the AppDir root
  for an AppImage. Never edit files under .github/workflows (they can't be sent): if the release is only made in a
  workflow, make no edit and say in "packaging.ask" which step should copy it.
Then, only when it's a line or two: --saves <folder> (saves already go through one folder variable), --fullscreen
and --borderless (the window is made in one place with an existing fullscreen setting).

Before adding an option, check whether it already exists under another name: a flag like --dump, --rom, --iso,
--data, --game_data_root, a plain path argument (argv[1]), a subcommand, an environment variable or a settings key.
If it does, don't add it again: use it in the recomplauncher.json and describe it under "existing". Only use an
option that takes the player's own copy (the file or folder they have), not a folder the project builds itself.

Finding the right place: follow the path from the command line or the setup screen to the function that does the
work. When a file you need isn't in "files" (the function a button calls, the build routine, the packaging
script), answer first with {"need_files": ["path", ...]}: at most 6 paths from "tree", and nothing else. You get
them and answer again. You can ask for files once.

Rules:
- The smallest change that works, in the project's own style and language, reusing its argument parsing and the
  functions its own screens call. A new function, flag or subcommand inside an existing file is fine. No new files,
  no new dependencies, no reformatting, no renames.
- Without the new options the program starts and behaves exactly as it does now, and its own screens work as before.
- Each edit is {"path", "find", "replace", "why"}: "find" is text copied exactly from one of the files you were given
  (whitespace and all), long enough to appear only once in that file; "replace" is what it becomes; "why" is a short
  commit line, like "Add --prepare to build the game without the window".
- Never touch game data, save formats, gameplay or graphics. Build files only for the packaging line.
- Update the recomplauncher.json so the launcher uses what you added: for --game, "start.args" with "{game}" and a
  "game" section saying what copy to pick and how to check it; for --prepare, a "prepare" step with "{game}" and a
  "label", and "start" pointing at the program the setup makes (not the setup tool). Keep everything else as it was.
- Skip a todo item when you can't see clearly where it goes, and say why in "notes". An edit you aren't sure of is
  worse than none.

Answer with one JSON object and nothing else:
{"edits": [{"path": "", "find": "", "replace": "", "why": ""}],
 "adds": ["--game <path>", "--prepare", ...],
 "explain": ["what each change does, one short plain line each, for the pull request"],
 "packaging": {"done": false, "how": "when done: what copies it, like 'make dist copies it next to Game.exe'", "ask": "when not done: which file or step should copy it"},
 "existing": {"how": "the option it already has, e.g. --dump <path>, and what it takes", "where": "file:line"},
 "recomplauncher_json": { ... the whole file, updated ... },
 "notes": "for the reviewer: anything you're unsure of (or empty)"}
"existing" is only for an option the project already has that the file now uses (else leave it out)."""


def code_files(repo, ctx):
    """The files to read: where the command line is read or the game is picked (main first), up to 4."""
    branch = ctx.get("_branch", "main")
    names = [h["file"] for h in ctx.get("command_line", [])]
    def score(h):
        t = h["lines"]
        return (0 if re.search(r"\bmain\s*\(|\bargv\b|args\(\)|CommandLine", t) else 1 if re.search(r"FileDialog|GetOpenFileName|tinyfd_|NFD_|rfd::|main\.dol|\.(iso|xci|nsp|z64|n64|xex)\b", t, re.I) else 2,
                # (4.11) the game's own setup, builder or launcher before a toolchain's or a dev tool's main
                0 if re.search(r"setup|install|builder|launch|import|frontend", h["file"], re.I) else 1,
                0 if re.search(r"(^|/)(main|program)\.", h["file"], re.I) else 1)
    names = [h["file"] for h in sorted(ctx.get("command_line", []), key=score)]
    out = read_files(repo, branch, names[:10], 5)
    # The release scripts too, so the packaging line can go in (already read whole by context()).
    for path, raw in list(ctx.get("_packaging", {}).items())[:3]:
        out.setdefault(path, raw)
    return out


def read_files(repo, branch, paths, most):
    """-> {path: whole text} for up to `most` of these files (very large ones left out)."""
    out = {}
    for path in paths:
        raw = gh(f"/repos/{repo}/contents/{urllib.parse.quote(path)}?ref={urllib.parse.quote(branch)}", "application/vnd.github.raw")
        if not raw or len(raw) > 600000:
            continue
        out[path] = raw
        if len(out) >= most:
            break
    return out


def code_todo(doc, d, ctx):
    """What the code pass is asked for: "game", "prepare" and "packaging", each only when it's missing."""
    found = {str(x.get("what", "")).lower(): x for x in ((d.get("found") or {}).get("features") or []) if isinstance(x, dict)}
    todo = []
    game = found.get("game location") or {}
    if not has_game_option(doc) and game.get("needed") is not False:
        todo.append("game")
    setup = found.get("setup or build step") or {}
    if setup.get("has") and setup.get("needed") is not False and not isinstance(doc.get("prepare"), dict):
        todo.append("prepare")
    if not ctx.get("_ships_json"):
        todo.append("packaging")
    return todo


def shown(files):
    """What the model reads: a whole file when it's small, else the parts around its command line and paths
    (edits are still checked against the whole file)."""
    view = {}
    for path, raw in files.items():
        if len(raw) <= 50000:
            view[path] = raw
            continue
        lines = raw.splitlines()
        keep = set()
        for i, l in enumerate(lines):
            if OPT_LINE.search(l) or re.search(r"\bmain\s*\(", l):
                keep.update(range(max(0, i - 40), min(len(lines), i + 60)))
        part, last = [], -2
        for i in sorted(keep):
            if i != last + 1:
                part.append(f"... (line {i + 1})")
            part.append(lines[i])
            last = i
        view[path] = "\n".join(part)[:60000]
    return view


def has_game_option(doc):
    # (4.10) The player's copy handed over anywhere the launcher passes it: the start or setup step's arguments or
    # environment ("{game}", or "{input:<id>}" for a copy described in "inputs"), or a settings key (config.game_path).
    parts = []
    for step in ("start", "prepare"):
        st = doc.get(step) if isinstance(doc.get(step), dict) else {}
        parts += [str(a) for a in (st.get("args") or [])]
        if isinstance(st.get("env"), dict):
            parts += [str(v) for v in st["env"].values()]
    text = " ".join(parts)
    cfg = doc.get("config") or []
    return "{game}" in text or "{input:" in text or any(isinstance(c, dict) and c.get("game_path") for c in cfg)


def sdk_easy(doc):
    """Easy Setup the way the site and the launcher count it (admin/inc/pc.php rs_sdk_easy): "start", plus the
    player's copy in the "game" part or in a required "inputs" entry whose role is game or disc (none means game)."""
    if not isinstance(doc, dict) or "start" not in doc:
        return False
    if doc.get("game"):
        return True
    for i in doc.get("inputs") or []:
        if isinstance(i, dict) and not i.get("optional") and not i.get("shared") and str(i.get("role") or "game") in ("game", "disc"):
            return True
    return False


def check_edits(edits, files):
    """Every edit applies once to a file we read. -> (new contents by path, errors)"""
    new, errs = dict(files), []
    for i, e in enumerate(edits):
        path, find, rep_ = e.get("path"), e.get("find"), e.get("replace")
        if isinstance(path, str) and path.startswith(".github/workflows/"):
            # (the PR token can't push workflow files, so the send would fail)
            errs.append(f"- edit {i + 1}: workflow files can't be changed here; leave it out and say in packaging.ask which step should copy the file")
            continue
        if path not in new or not isinstance(find, str) or not isinstance(rep_, str) or not find:
            errs.append(f"- edit {i + 1}: its path isn't one of the files given, or find/replace is missing")
            continue
        n = new[path].count(find)
        if n != 1:
            errs.append(f"- edit {i + 1} ({path}): its find text appears {n} times; it must appear exactly once")
            continue
        new[path] = new[path].replace(find, rep_, 1)
    return {k: v for k, v in new.items() if v != files.get(k)}, errs


def code_pass(spec, p, ctx, doc, draft, validator):
    """-> {"edits", "adds", "explain", "packaging", "json", "existing", "notes"} or None when there's nothing to do."""
    todo = code_todo(doc, draft, ctx)
    if not todo:
        return None
    if todo == ["packaging"]:
        # (4.11) Only the release copy line is missing: no model call when the release is only made by a workflow
        # (those files can't be sent), and only the packaging scripts to read when there's a script to edit.
        scripts = {k: v for k, v in (ctx.get("_packaging") or {}).items() if not k.startswith(".github/")}
        if not scripts:
            flows = [k for k in (ctx.get("_packaging") or {}) if k.startswith(".github/workflows/")]
            where = f" in {flows[0]}" if flows else " in the release workflow"
            return {"edits": [], "packaging": {"done": False, "ask": "the release is made by a GitHub workflow, so the step that zips the release" + where + " should copy recomplauncher.json next to the program"}, "notes": ""}
        files = dict(list(scripts.items())[:3])
    else:
        files = code_files(p["repo"], ctx)
    if not files:
        print(f"code pass {p['repo']}: no source files to read")
        return None
    user = {"project": {k: p[k] for k in ("repo", "name", "game", "console")}, "todo": todo, "recomplauncher_json": doc,
            "found": draft.get("found") or {}, "readme": ctx.get("readme", "")[:12000], "tree": ctx.get("files", []), "files": shown(files),
            "note": "Long files are shown in parts (lines marked '... (line N)'); copy find text only from what's shown."}
    msgs = [{"role": "user", "content": "Make what the todo asks for, or use what the project already has.\n\n" + json.dumps(user, ensure_ascii=False)}]
    asked_files = False
    for attempt in range(3):
        body = {"model": CODE_MODEL or MODEL, "max_tokens": 16000, "system": [{"type": "text", "text": CODE_SYSTEM + "\n\nTHE SDK GUIDE:\n" + spec, "cache_control": {"type": "ephemeral"}}], "messages": msgs}
        for wait in (0, 20, 40, 60):  # (the model busy or rate-limited: a few drafts run side by side)
            if wait:
                time.sleep(wait)
            st, b = http("https://api.anthropic.com/v1/messages", data=body, timeout=300, headers={"x-api-key": AKEY, "anthropic-version": "2023-06-01"})
            if st not in (429, 500, 502, 503, 529):
                break
        if st != 200:
            print(f"code pass {p['repo']}: model call failed ({st})")
            return None
        jj = json.loads(b)
        note_usage(p.get("id"), jj, CODE_MODEL or MODEL)
        text = "".join(c.get("text", "") for c in jj.get("content", []) if c.get("type") == "text")
        m = re.search(r"\{.*\}", text, re.S)
        try:
            d = json.loads(m.group(0)) if m else None
        except ValueError:
            d = None
        if not isinstance(d, dict):
            print(f"code pass {p['repo']}: no JSON in the answer")
            return None
        want = [x for x in (d.get("need_files") or []) if isinstance(x, str)]
        if want and not asked_files:
            # (4.11) Following the path from the screen to the function that does the work: the files it asked for.
            asked_files = True
            known = set(ctx.get("_paths") or [])
            more = read_files(p["repo"], ctx.get("_branch", "main"), [x for x in want[:6] if x in known and x not in files], 6)
            files.update(more)
            print(f"code pass {p['repo']}: read {len(more)} more file(s): {', '.join(more)[:200]}")
            msgs += [{"role": "assistant", "content": m.group(0)},
                     {"role": "user", "content": "Here they are (paths not in the tree were left out). Now answer with the whole JSON object; no more files.\n\n" + json.dumps({"files": shown(more)}, ensure_ascii=False)}]
            continue
        edits = [e for e in (d.get("edits") or []) if isinstance(e, dict)]
        new_doc = d.get("recomplauncher_json") if isinstance(d.get("recomplauncher_json"), dict) else None
        existing = d.get("existing") if isinstance(d.get("existing"), dict) and d["existing"].get("how") else None
        pack = d.get("packaging") if isinstance(d.get("packaging"), dict) else {}
        notes = (d.get("notes") or "").strip()
        if not edits:
            # (4.10) No code needed when it already reads the game's location: the file uses that option.
            if existing and new_doc is not None and has_game_option(new_doc):
                serr = schema_errors(validator, new_doc)
                if not serr:
                    print(f"code pass {p['repo']}: uses its own {existing.get('how', '')[:80]}")
                    return {"edits": [], "adds": [], "explain": [], "packaging": pack, "json": new_doc, "existing": existing, "notes": notes}
                if attempt < 2:
                    msgs += [{"role": "assistant", "content": m.group(0)}, {"role": "user", "content": "The recomplauncher.json doesn't pass the schema:\n" + serr + "\nFix it and answer with the whole JSON object again."}]
                    continue
            print(f"code pass {p['repo']}: no edits ({notes[:200]})")
            return {"edits": [], "packaging": pack, "notes": notes} if notes or pack.get("ask") else None
        changed, errs = check_edits(edits, files)
        if new_doc is not None:
            serr = schema_errors(validator, new_doc)
            if serr:
                errs.append("- the recomplauncher.json doesn't pass the schema:\n" + serr)
        if not errs and changed and new_doc is not None:
            return {"edits": edits, "adds": d.get("adds") or [], "explain": d.get("explain") or [], "packaging": pack, "json": new_doc, "existing": existing, "notes": notes}
        print(f"code pass {p['repo']}: try {attempt + 1}: " + "; ".join(errs)[:300])
        msgs += [{"role": "assistant", "content": m.group(0)}, {"role": "user", "content": "These don't work:\n" + "\n".join(errs) + "\nFix them and answer with the whole JSON object again."}]
    return None


def schema_errors(validator, doc):
    if validator is None:
        return ""
    errs = sorted(validator.iter_errors(doc), key=lambda e: list(e.path))
    return "\n".join(f"- {'/'.join(map(str, e.path)) or '(top)'}: {e.message}" for e in errs[:15])


def earlier_pr(repo):
    """A pull request on the repo, open or closed, that mentions RecompLauncher (its link, or "")."""
    q = urllib.parse.quote(f"repo:{repo} is:pr recomplauncher in:title,body")
    j = gh(f"/search/issues?q={q}&per_page=5") or {}
    items = j.get("items") or [] if isinstance(j, dict) else []
    return items[0].get("html_url", "") if items else ""


def first_path(v):
    """The first folder or file named in a saves/folders list ("" when there's none)."""
    for x in v if isinstance(v, list) else [v]:
        if isinstance(x, str) and x.strip():
            return x.strip()
        if isinstance(x, dict) and str(x.get("path", "")).strip():
            return str(x["path"]).strip()
    return ""


def short_how(h):
    """The option itself out of the drafter's description of it, for the checklist ("" when there's no short name)."""
    h = str(h or "").strip()
    m = re.search(r"(?:^|(?<=[\s`(\[]))(--?[A-Za-z][\w-]*(?:[ =](?:<[^>]+>|\{[^}]+\}|FILE|PATH|DIR|FOLDER))?)", h)
    if m:
        return f"`{m.group(1)}`"
    m = re.search(r"\b([A-Z][A-Z0-9_]{2,})\b[^.]*environment", h)
    if m:
        return f"`{m.group(1)}` environment variable"
    h = h.split(":")[0].strip()
    return f"`{h}`" if 0 < len(h) <= 50 else ""


def feature_list(doc, d, code=None, plain=None, ctx=None):
    """(4.11) What the pull request sets up, worked out from the json itself (never from the model's claims):
    -> (what players get, what isn't in it yet), one plain line each."""
    doc = doc if isinstance(doc, dict) else {}
    plain = plain if isinstance(plain, dict) else doc
    ctx = ctx or {}
    found = {str(x.get("what", "")).lower(): x for x in ((d.get("found") or {}).get("features") or []) if isinstance(x, dict)}
    game = found.get("game location") or {}
    edits = bool(code and code.get("edits"))
    have, missing = ["Installs and updates from your GitHub releases."], []
    # Easy Setup: through a setup step, straight to the game, or not needed at all.
    if sdk_easy(doc) and isinstance(doc.get("prepare"), dict):
        here = " (the new `--prepare` mode, see The code changes)" if edits and not isinstance(plain.get("prepare"), dict) else ""
        have.append(f"Easy Setup: the player picks their own copy once, and the launcher checks it, runs the setup with a progress bar{here} and starts the game. No setup window.")
    elif sdk_easy(doc):
        if edits and has_game_option(doc) and not has_game_option(plain):
            how = "the new `--game` option (see The code changes)"
        else:
            how = short_how((code or {}).get("existing", {}).get("how", "") if code and code.get("existing") else game.get("how", ""))
            how = f"your existing {how}" if how else ""
        have.append("Easy Setup: the player picks their own copy once, and the launcher checks it and hands it to the game" + (f" through {how}" if how else "") + ".")
    elif game.get("has") is False and game.get("needed") is False:
        why = str(game.get("why", "")).strip().rstrip(".")
        have.append("Nothing to set up: Play starts the game" + (f" ({why[:1].lower() + why[1:]})" if why else "") + ".")
    else:
        big = bool(code and code.get("notes"))  # (the code step looked and said why it left it)
        missing.append("Easy Setup: the game needs a way to receive the player's copy, like a `--game <path>` option"
                       + (", and here that's a bigger change than fits in this one" if big else "") + ".")
    labels = [str(x.get("label")) for x in (doc.get("settings") or []) if isinstance(x, dict) and x.get("label")]
    if labels:
        have.append("Its settings on the game's page in the launcher and in Big Picture mode: " + ", ".join(labels[:6]) + (" and more" if len(labels) > 6 else "") + ".")
    else:
        missing.append("Settings in the launcher: we couldn't find a settings file the launcher can edit.")
    folders = doc.get("folders") if isinstance(doc.get("folders"), dict) else {}
    saves = first_path(doc.get("saves")) or first_path(folders.get("saves"))
    if saves:
        have.append(f"Save backups and cloud saves, from `{saves}`.")
    else:
        missing.append("Save backups and cloud saves: we couldn't tell where saves go.")
    logs = first_path(folders.get("logs"))
    if logs:
        have.append(f"The newest log goes with crash reports, from `{logs}`.")
    else:
        missing.append("Logs in crash reports: we couldn't find a log file.")
    if doc.get("exit_codes"):
        have.append("A clear message for its known exit codes instead of a crash report.")
    if doc.get("mods"):
        have.append("A Mods tab that points players to its mods.")
    have.append("A \"Get it on RecompLauncher\" badge in the README.")
    # The file only reaches players from the release, so say plainly whether this PR gets it there.
    pack = (code or {}).get("packaging") or {}
    if ctx.get("_ships_json"):
        pass
    elif edits and pack.get("done") and str(pack.get("how", "")).strip():
        have.append("`recomplauncher.json` ships in every release: " + str(pack["how"]).strip().rstrip(".") + ".")
    else:
        ask_ = str(pack.get("ask", "")).strip().rstrip(".")
        missing.append("`recomplauncher.json` in the release: the launcher reads it from the release (next to the program, or the AppImage root), not from the repo, so it needs copying in when the release is made"
                       + (f". {ask_[:1].upper() + ask_[1:]}" if ask_ else "") + ".")
    return have, missing


def body_text(p, what, d, code=None, doc=None, plain=None, ctx=None):
    """The pull request's description, in the shape of the hand-made ones."""
    doc = doc if doc is not None else d.get("recomplauncher_json")
    have, missing = feature_list(doc, d, code, plain, ctx)
    lines = [f"Hi! This makes {p['name']} installable from [RecompLauncher](https://recomplauncher.com), where it's already listed: {p['page']}.", "",
             "## What it adds for players"] + [f"- {x}" for x in have]
    if missing:
        lines += ["", "## Not in this pull request yet"] + [f"- {x}" for x in missing]
    if code and code.get("edits"):
        lines += ["", "## The code changes", ""]
        if code.get("adds"):
            lines += ["They're in their own commits and only apply when the launcher passes the new arguments. Without them the game starts and behaves exactly as before.", ""]
        lines += [f"- {x.strip()}" for x in code.get("explain", []) if str(x).strip()]
        lines += ["", "We haven't built it on our side, so a quick build before merging would be great. Happy to adjust anything."]
    better = [x.strip() for x in d.get("better", []) if isinstance(x, str) and x.strip()]
    if better:
        lines += ["", "## Things that would make your recomp better"] + [f"- {x}" for x in better]
    lines += ["", "## For the developer",
              "A free developer account at https://recomplauncher.com/dev (sign in with GitHub) lets you claim the recomp on RecompLauncher. Its page then shows it as yours, with the Verified developer badge once approved, and you can edit its page and art, see stats and read player reports with their logs.",
              "", "If you'd rather not have the badge, a plain link to its RecompLauncher page somewhere in the README works too.",
              "", "## What's RecompLauncher?", what,
              "", "Feel free to change anything here, or close this if it's not for you. No hard feelings."]
    return "\n".join(lines)


def merge_found(d, code, doc=None, plain=None):
    """The team's report of what the project already has, with what the code step found or added."""
    f = d.get("found") if isinstance(d.get("found"), dict) else {}
    feats = [x for x in (f.get("features") or []) if isinstance(x, dict) and x.get("what")]
    opts = [x for x in (f.get("options") or []) if isinstance(x, dict) and x.get("flag")]
    doc = doc if isinstance(doc, dict) else {}
    plain = plain if isinstance(plain, dict) else doc
    rows = []
    edits = bool(code and code.get("edits"))
    adds = ", ".join(str(a) for a in (code or {}).get("adds") or [])
    ex = (code or {}).get("existing") or {}
    if ex or (edits and has_game_option(doc) and not has_game_option(plain)):
        rows.append({"what": "Game location", "has": True, "used": True, "added": not ex,
                     "how": ex.get("how") or ("Added in this pull request: " + adds), "where": ex.get("where", "")})
    if edits and isinstance(doc.get("prepare"), dict) and not isinstance(plain.get("prepare"), dict):
        rows.append({"what": "Setup or build step", "has": True, "used": True, "added": True, "how": "Added in this pull request: " + adds})
    pack = (code or {}).get("packaging") or {}
    if edits and pack.get("done"):
        rows.append({"what": "Release packaging", "has": True, "used": True, "added": True, "how": str(pack.get("how", ""))})
    names = {r["what"].lower() for r in rows}
    feats = rows + [x for x in feats if str(x.get("what", "")).lower() not in names]
    return {"features": feats[:16], "options": opts[:40]}


def draft_one(p, todo, spec, validator):
    """One pick, start to end. -> 1 when a draft was saved."""
    try:
        if DRY:
            dry_mark(p["id"])
        gh_room()
        # Already had one (ours, Xarishark's or anyone's, open or closed): never a second.
        had = "" if p.get("pick") == "redo" else earlier_pr(p["repo"])  # a redraft updates our own
        if had:
            site("POST", "draft", {"id": p["id"], "pick": p["pick"], "skip": True, "skip_reason": "It already has a RecompLauncher pull request: " + had})
            print(f"skip {p['repo']}: already has {had}")
            return 0
        ctx = context(p)
        d, raw = ask(spec, p, ctx)
        if d.get("skip"):
            site("POST", "draft", {"id": p["id"], "pick": p["pick"], "skip": True, "skip_reason": d.get("skip_reason", ""), "usage": take_usage(p["id"])})
            print(f"skip {p['repo']}: {d.get('skip_reason')}")
            return 0
        doc = d.get("recomplauncher_json")
        if not isinstance(doc, dict):
            raise RuntimeError("no recomplauncher.json in the answer")
        errs = schema_errors(validator, doc)
        if errs:
            d, raw = ask(spec, p, ctx, {"prev": raw, "errors": errs})
            doc = d.get("recomplauncher_json") if isinstance(d.get("recomplauncher_json"), dict) else doc
            errs = schema_errors(validator, doc)
        note = (d.get("notes") or "").strip()
        if errs:
            note = (note + "\n\nStill doesn't pass the schema:\n" + errs).strip()
        code = None
        try:
            code = None if errs else code_pass(spec, p, ctx, doc, d, validator)
        except Exception as e:  # the draft goes out without code changes
            print(f"code pass {p['repo']}: {e}")
        final = code["json"] if code and code.get("json") else doc
        what = todo.get("what", "")
        payload = {"id": p["id"], "pick": p["pick"], "json": final, "title": todo.get("title"),
                   "body": body_text(p, what, d, code, final, doc, ctx), "note": note, "found": merge_found(d, code, final, doc)}
        if code and code.get("edits"):
            payload["code"] = {"edits": code["edits"], "adds": code["adds"], "explain": code["explain"], "packaging": code.get("packaging") or {}, "plain": doc}
            payload["body_plain"] = body_text(p, what, d, None, doc, doc, ctx)
            if code["notes"]:
                payload["note"] = (note + "\n\nCode changes: " + code["notes"]).strip()
        elif code and code.get("existing"):
            payload["note"] = (note + "\n\nGame location: uses its own " + str(code["existing"].get("how", "")) + (" (" + str(code["existing"]["where"]) + ")" if code["existing"].get("where") else "") + ", so no code change.").strip()
        elif code and code.get("notes"):
            payload["note"] = (note + "\n\nNo code changes: " + code["notes"]).strip()
        ask_ = str(((code or {}).get("packaging") or {}).get("ask", "")).strip()
        if ask_ and not (code and code.get("edits") and (code.get("packaging") or {}).get("done")):
            payload["note"] = (payload["note"] + "\n\nRelease packaging: " + ask_).strip()
        payload["usage"] = take_usage(p["id"])
        st, r = site("POST", "draft", payload)
        print(("drafted " if r.get("ok") else "not saved ") + p["repo"] + ("" if r.get("ok") else f": {r.get('error')}"))
        return 1 if r.get("ok") else 0
    except Exception as e:
        print(f"error {p['repo']}: {e}")
        site("POST", "fail", {"id": p["id"], "error": str(e)[:300], "usage": take_usage(p["id"])})
        return 0


def main():
    sys.stdout.reconfigure(line_buffering=True)  # (the log shows each draft as it's done)
    if not KEY or not AKEY:
        sys.exit("Set RS_PRQ_KEY and ANTHROPIC_API_KEY.")
    st, todo = site("GET", "todo")
    if st != 200 or not todo.get("ok"):
        sys.exit(f"The site said no ({st}): {todo.get('error')}")
    picks = todo.get("picks", [])
    # (4.10) The site says which model (Settings > AI usage) and whether today's budget has room.
    global MODEL, CODE_MODEL
    if not os.environ.get("PRQ_MODEL") and todo.get("model"):
        MODEL = todo["model"]
    if not os.environ.get("PRQ_MODEL_CODE") and todo.get("code_model"):
        CODE_MODEL = todo["code_model"]
    if todo.get("budget_ok") is False:
        print("today's AI budget is used up; nothing drafted")
        return
    print(time.strftime("%Y-%m-%d %H:%M:%S"), f"{len(picks)} to draft, {min(WORKERS, max(1, len(picks)))} at a time")
    if not picks:
        return
    st, spec = http(SPEC_URL, timeout=60)
    if st != 200 or len(spec) < 2000:
        for p in picks:
            site("POST", "fail", {"id": p["id"], "error": "Couldn't read the SDK guide. Try again in a minute."})
        sys.exit("Couldn't read the SDK guide.")
    validator = None
    try:
        import jsonschema  # noqa: F401
        st, sch = http(SCHEMA_URL, timeout=60)
        if st == 200:
            validator = jsonschema.Draft202012Validator(json.loads(sch)) if "2020-12" in sch else jsonschema.Draft7Validator(json.loads(sch))
    except Exception as e:
        print("schema check off:", e)
    # (4.10) Draft now: the rows waiting in this run keep saying Drafting (not "didn't finish") while it works.
    left, lock, stop = {p["id"] for p in picks}, threading.Lock(), threading.Event()
    def beat():
        while not stop.wait(120):
            with lock:
                ids = sorted(left)
            if ids and IDS:
                site("POST", "touch", {"ids": ids})
    threading.Thread(target=beat, daemon=True).start()
    def one(p):
        try:
            return draft_one(p, todo, spec, validator)
        finally:
            with lock:
                left.discard(p["id"])
    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        done = sum(pool.map(one, picks))
    stop.set()
    print(time.strftime("%Y-%m-%d %H:%M:%S"), f"{done} drafts saved")


if __name__ == "__main__":
    main()
