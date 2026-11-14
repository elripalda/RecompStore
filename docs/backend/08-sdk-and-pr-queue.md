# SDK support, boot profiles and the PR drafter

## recomplauncher.json and the SDK

A developer can ship a `recomplauncher.json` in their repo (and release zips) that tells the launcher how to start the game, where saves, settings and logs are, its setup step and its settings. The schema is published at recomplauncher.com/schema/recomplauncher.json (copied from the launcher's own `docs/recomplauncher.schema.json` by the 30-minute job). The developer guide and the AI agent guide are in `public_html/sdk-files/` (`guide.md`, `agent.php`, `examples/`).

To do: the agent guide is about 72,000 characters, and developers' AI tools stop reading at about 40,000. It needs splitting into short files (one per topic plus an index), ideally in the RecompLauncher-SDK repo, with the SDK page's buttons and prompts pointing there.

**Easy Setup** (`rs_sdk_easy` in PHP, `sdk_easy` in draft.py): a game has it when its json has a `start` and a `game` entry, or a required input with role `game` or `disc`. That means the launcher asks the player for their own copy once and passes it along, straight to the game (a `--rompath` style flag) or to the setup step first. The launcher uses the same rule for its badge.

## SDK support page (`admin/sdk-support.php`, data in `inc/sdk.php`)

Every listed recomp with a repo, whether it ships its own `recomplauncher.json` (and the README badge), and marks set by hand: pull request open (`pr`), developer won't support it (`no`), PRs closed. The 30-minute job looks for the file in recomps not checked this week, most downloaded first. Tabs: the list, the PR queue, Look up.

## Boot profiles (`inc/boot.php`, page `admin/boot.php`)

How RecompLauncher starts a recomp, does Easy Setup from the player's copy, and which settings it shows. They used to live inside the launcher (263 of them); now each listing's `boot` field holds its profile and the catalog carries it. The launcher's own copy is only used when the catalog can't be read. A developer's own `recomplauncher.json` wins over our profile.

## The PR queue (`inc/prq.php`, `api/prq.php`, `tools/prq/draft.py`)

Pull requests that add RecompLauncher support (recomplauncher.json and the README badge) to recomps that don't have it.

1. **Picks**: `api/prq.php` hands out the next ones: anything queued by hand first, then half newest recomps, half most downloaded console ports, all with no file yet.
2. **Drafting**: `draft.py` reads the project (README, up to 900 of its file paths, the newest releases, the file names inside the Windows zip read from the zip's index with ranged requests, the files that look like they handle the command line and paths, up to 4 docs or sample configs about its settings or command line such as `docs/CONFIG.md`, and up to 5 release/packaging scripts), drafts the json and a report with Claude (Sonnet 5.5 by default, `PRQ_MODEL` to change), checks the json against the schema, and posts the draft back. The aim is the PR a person would send by hand: Easy Setup from the player's copy, a setup step that builds or imports the game when it needs one (with `start` on the game it makes, never on the builder), settings, saves, logs, and the file shipped in the release.
   - It runs daily from the GitHub Action (`.github/workflows/pr-drafts.yml` on main, 7:23 am Phoenix, which checks out `tools/prq` from the `mac-support` branch so both drafters always run the same file) and on the server when someone asks for drafts on SDK support (`rs_prq_run` starts `recompstore-private/prq/draft.py`, three at a time, log in `run.log`).
   - **Code step** (`code_todo`): runs when something is missing, and asks only for that: `game` (no way to receive the player's copy: use an existing option, or add `--game <path>`), `prepare` (the project has a setup or build step and the json has no `prepare`: add a `--prepare` mode or subcommand that does it without a window, calling the function its own screen calls, printing `progress <0-1> <text>`), `packaging` (the release zip has no `recomplauncher.json`: one copy line in the release or packaging script; never a `.github/workflows` file, since the PR token can't push those, so it says which step should copy it instead). It may ask once for up to 6 more files from the tree to follow the path to the function that does the work. Edits are find/replace on files it read whole, each with a `why` used as its commit message on Send.
   - **Found**: the draft carries what the project already has, feature by feature (`found.features`: `what` (always Game location, Setup or build step, Saves, Start straight into the game, Fullscreen or borderless, Settings file, Logs, Mods or packs, Release packaging), `has`, `how`, `where` as file:line, `used` by the json, `hook` (the launcher can drive it: a flag, environment variable, settings key or fixed path, including places the setup makes inside the install folder; a feature only in the game's own menus has none and gets no tick), `added` by this PR, `why` the json doesn't use one it has, and for missing ones `needed` and `why`), plus every command-line option it reads.
   - GitHub's API limit is shared across the account's tokens; `gh_room()` waits for it to reset.
3. **Review** on SDK support: each draft shows its newest release's files (the ones the launcher downloads per system marked), the Features cards (a tick only when the json uses it or the PR adds it) with what the PR does with each ("Feature added with this PR", "Flag exists, added to json", "Flag exists, not in json", "In-game only, nothing to pair with the json", "Not needed", "Missing, not in this PR"), the options list, the json and the PR text. Edit, **Send** or skip. **Draft them all again** redrafts the whole queue.
4. **Send** forks the repo with the saved GitHub token, commits the json and the badge on a branch, and opens the PR. At most 5 go out an hour; the rest wait in line (30-minute job). PRs that later conflict are rebuilt on the developer's newest code.

Rules for the PR text (in draft.py's prompt): plain and friendly, no mention of AI, tools or models, and it never claims the code was built or tested. A project that already has a RecompLauncher PR (ours or anyone's, open or closed) is skipped.

The PR body follows the hand-made ones: What it adds for players, Not in this pull request yet, The code changes, Things that would make your recomp better, For the developer, What's RecompLauncher?. The first two lists are worked out from the json itself, never from the model's claims: installs and updates, Easy Setup (with a setup step, through an existing option, an option added here, not needed, or missing), settings, saves, logs, exit codes, mods, the badge, and whether `recomplauncher.json` reaches the release.
