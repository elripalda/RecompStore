#!/usr/bin/env python3
"""Daily drafts for the RecompLauncher PR queue (SDK support > PR queue on recompstore.com).

For each pick the site hands out (half just-added recomps, half popular console ports, anything queued
by hand first), it reads the project on GitHub, drafts recomplauncher.json and the pull request's
wording, checks the file against the published schema and posts the draft back for a person to look
over and send. Nothing is sent to the project from here.

Environment:
  RS_PRQ_KEY         the drafter key made on SDK support (required)
  ANTHROPIC_API_KEY  the model's API key (required)
  GITHUB_TOKEN       read access to public repos (the Action's own token is enough)
  PRQ_COUNT          how many to draft this run (default 8)
  PRQ_MODEL          the model (default claude-sonnet-5-5)
  RS_SITE            default https://recompstore.com
"""
import io
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
import zipfile

SITE = os.environ.get("RS_SITE", "https://recompstore.com").rstrip("/")
KEY = os.environ.get("RS_PRQ_KEY", "")
AKEY = os.environ.get("ANTHROPIC_API_KEY", "")
GHT = os.environ.get("GITHUB_TOKEN", "")
COUNT = int(os.environ.get("PRQ_COUNT", "8") or 8)
MODEL = os.environ.get("PRQ_MODEL", "claude-sonnet-5-5")
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


def site(method, a, data=None):
    st, b = http(f"{SITE}/api/prq.php?a={a}" + (f"&n={COUNT}" if a == "todo" else ""), data=data,
                 headers={"X-PRQ-Key": KEY, "Accept": "application/json"}, method=method, timeout=60)
    try:
        return st, json.loads(b)
    except ValueError:
        return st, {"ok": False, "error": b[:300]}


def pattern_re(p):
    return re.compile("^" + re.escape(p).replace(r"\*", ".*").replace(r"\?", ".") + "$", re.I)


def zip_listing(url, size):
    """The file names inside a release zip (up to 120 MB), so the start program is a real path."""
    if not url or size > (120 << 20):
        return []
    st, b = http(url, headers={"Accept": "application/octet-stream"}, timeout=180, raw=True, limit=125 << 20)
    if st != 200 or not b:
        return []
    try:
        with zipfile.ZipFile(io.BytesIO(b)) as z:
            return [n for n in z.namelist() if not n.endswith("/")][:400]
    except zipfile.BadZipFile:
        return []


def context(p):
    repo = p["repo"]
    info = gh(f"/repos/{repo}") or {}
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
    # What's inside the Windows download (else the first zip) of the newest release.
    inside, inside_of = [], ""
    if rels:
        assets = rels[0].get("assets", [])
        pat = p.get("assets", {}).get("win", "")
        pick = None
        if pat:
            rx = pattern_re(pat)
            pick = next((a for a in assets if rx.match(a["name"])), None)
        if not pick:
            pick = next((a for a in assets if a["name"].lower().endswith(".zip") and "source" not in a["name"].lower()), None)
        if pick and pick["name"].lower().endswith(".zip"):
            inside, inside_of = zip_listing(pick.get("browser_download_url"), pick.get("size", 0)), pick["name"]
    open_prs = gh(f"/repos/{repo}/pulls?state=open&per_page=50") or []
    return {
        "info": {"description": info.get("description"), "archived": info.get("archived"), "default_branch": branch,
                 "has_issues": info.get("has_issues"), "stars": info.get("stargazers_count"), "language": info.get("language")},
        "readme": readme[:24000], "contributing": contributing,
        "files": paths[:500], "files_truncated": len(paths) > 500 or bool(tree.get("truncated")),
        "releases": releases, "inside_download": {"file": inside_of, "names": inside},
        "open_pull_requests": [{"title": x.get("title"), "body": (x.get("body") or "")[:300]} for x in open_prs[:30]],
    }


SYSTEM = """You prepare pull requests that add RecompLauncher support to PC recomps and decomps on GitHub.
For one project you get what's known about it (README, files, releases, what's inside its Windows download,
open pull requests, and what RecompLauncher's catalog already knows). You write its recomplauncher.json,
following the SDK guide below exactly, plus a few plain lines for the pull request.

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

Answer with one JSON object and nothing else:
{"skip": false, "skip_reason": "",
 "recomplauncher_json": { ... the file ... },
 "players": ["what it adds for players, one short line each, 3 to 6 lines"],
 "better": ["small concrete things that would make the recomp work better with the launcher, with a size: Small, Medium or Large. 0 to 4 lines"],
 "notes": "for the person reviewing: anything you weren't sure about, in a sentence or two (or empty)"}

THE SDK GUIDE:
"""


def ask(spec, p, ctx, fix=None):
    user = {"project": {k: p[k] for k in ("repo", "name", "game", "console", "page", "assets", "needs", "setup_required", "setup_steps", "our_profile")}, "github": ctx}
    msgs = [{"role": "user", "content": "Draft RecompLauncher support for this project.\n\n" + json.dumps(user, ensure_ascii=False)}]
    if fix:
        msgs += [{"role": "assistant", "content": fix["prev"]},
                 {"role": "user", "content": "That recomplauncher.json doesn't pass the schema:\n" + fix["errors"] + "\nFix it and answer with the whole JSON object again."}]
    body = {"model": MODEL, "max_tokens": 6000,
            "system": [{"type": "text", "text": SYSTEM + spec, "cache_control": {"type": "ephemeral"}}],
            "messages": msgs}
    for attempt in range(3):
        st, b = http("https://api.anthropic.com/v1/messages", data=body, timeout=240,
                     headers={"x-api-key": AKEY, "anthropic-version": "2023-06-01"})
        if st == 200:
            j = json.loads(b)
            text = "".join(c.get("text", "") for c in j.get("content", []) if c.get("type") == "text")
            m = re.search(r"\{.*\}", text, re.S)
            if not m:
                raise RuntimeError("no JSON in the answer")
            return json.loads(m.group(0)), m.group(0)
        if st in (429, 500, 502, 503, 529):
            time.sleep(20 * (attempt + 1))
            continue
        raise RuntimeError(f"model call failed ({st}): {b[:300]}")
    raise RuntimeError("model busy, try the next run")


def schema_errors(validator, doc):
    if validator is None:
        return ""
    errs = sorted(validator.iter_errors(doc), key=lambda e: list(e.path))
    return "\n".join(f"- {'/'.join(map(str, e.path)) or '(top)'}: {e.message}" for e in errs[:15])


def body_text(p, what, d):
    lines = [f"Hi! This adds [RecompLauncher](https://recomplauncher.com) support, so players can install and update {p['name']} from the launcher. It's already listed at {p['page']}.", "",
             "**What's RecompLauncher?**", what, "",
             "**What it adds for players**"]
    lines += [f"- {x.strip()}" for x in d.get("players", []) if x.strip()]
    lines += ["", "**Code changes**",
              "- None. This only adds `recomplauncher.json` and the \"Get it on RecompLauncher\" badge in the README. If your release zips don't already include files from the repo root, putting `recomplauncher.json` in them keeps the launcher in step with each release."]
    better = [x.strip() for x in d.get("better", []) if x.strip()]
    if better:
        lines += ["", "**Things that would make your recomp better**"] + [f"- {x}" for x in better]
    lines += ["", "**For the developer**",
              "A free developer account at https://recompstore.com/hub (sign in with GitHub) lets you claim the recomp on RecompLauncher. Its page then shows it as yours, with the Verified developer badge once approved, and you can edit its page and art, see stats and read player reports with their logs.",
              "", "Feel free to change anything here, move or drop the badge, or close this if it's not for you. No hard feelings."]
    return "\n".join(lines)


def main():
    if not KEY or not AKEY:
        sys.exit("Set RS_PRQ_KEY and ANTHROPIC_API_KEY.")
    st, todo = site("GET", "todo")
    if st != 200 or not todo.get("ok"):
        sys.exit(f"The site said no ({st}): {todo.get('error')}")
    picks = todo.get("picks", [])
    print(f"{len(picks)} to draft")
    if not picks:
        return
    st, spec = http(SPEC_URL, timeout=60)
    if st != 200 or len(spec) < 2000:
        sys.exit("Couldn't read the SDK guide.")
    validator = None
    try:
        import jsonschema  # noqa: F401
        st, sch = http(SCHEMA_URL, timeout=60)
        if st == 200:
            validator = jsonschema.Draft202012Validator(json.loads(sch)) if "2020-12" in sch else jsonschema.Draft7Validator(json.loads(sch))
    except Exception as e:
        print("schema check off:", e)
    done = 0
    for p in picks:
        try:
            ctx = context(p)
            d, raw = ask(spec, p, ctx)
            if d.get("skip"):
                site("POST", "draft", {"id": p["id"], "pick": p["pick"], "skip": True, "skip_reason": d.get("skip_reason", "")})
                print(f"skip {p['repo']}: {d.get('skip_reason')}")
                continue
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
            st, r = site("POST", "draft", {"id": p["id"], "pick": p["pick"], "json": doc, "title": todo.get("title"),
                                            "body": body_text(p, todo.get("what", ""), d), "note": note})
            print(("drafted " if r.get("ok") else "not saved ") + p["repo"] + ("" if r.get("ok") else f": {r.get('error')}"))
            done += 1 if r.get("ok") else 0
        except Exception as e:
            print(f"error {p['repo']}: {e}")
    print(f"{done} drafts saved")


if __name__ == "__main__":
    main()
