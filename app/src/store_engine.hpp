/* Recomp Store - the catalog, downloads and installs.
 *
 * Everything slow runs on one worker thread: reading the catalog from
 * recompstore.com, fetching the games' art, asking GitHub for a game's newest
 * release, downloading its zip, checking it and unpacking it into
 * /data/homebrew/<title ID>. An install only ever writes the files in the
 * game's zip: it never deletes anything, so a game's own files (a disc's
 * contents, saves, DLC, mods) next to the app are never touched.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <cstdint>
#include <string>
#include <vector>

namespace recomp
{
constexpr const char *kCatalogUrl = "https://recompstore.com/catalog.json";
constexpr const char *kHomebrew = "/data/homebrew";

struct Slot
{
    std::string key, label, kind /* iso, stfs */, to /* folder under data_dir; "." = in it */, title_id;
    bool required = true, multiple = false;
};

/* Where a game's logo sits: nudged x/y (design pixels, from where its name would be)
 * and its size (% of the space the name has). */
struct LogoPlace
{
    int x = 0, y = 0, scale = 100;
};

/* One release of a game on GitHub: its patch notes. */
struct Release
{
    std::string tag, name, date; /* date: YYYY-MM-DD */
    std::string body;            /* as written on GitHub (Markdown) */
    bool prerelease = false;
};

struct App
{
    std::string id, name, kind /* original, community */, author;
    std::string blurb, description, news, credits;
    std::string version, released, size, needs;
    int build = 0;
    std::string source /* github, url */, repo, asset, url, sha256;
    std::string icon_url, hero_url, hero_web_url;
    int banner_x = 50, banner_y = 30, banner_zoom = 100;
    /* A logo to show instead of the name (PNG with transparency), and where it sits on
     * the featured banner and on the game's page. */
    std::string logo_url;
    LogoPlace logo_banner, logo_page;
    bool has_setup = false;
    std::string data_dir;
    std::vector<Slot> slots;
    /* Filled by the engine: where its art was saved, empty until it is. */
    std::string icon_file, hero_file, logo_file;
};

struct Catalog
{
    std::vector<App> apps;
    std::vector<std::string> featured;
    std::string tagline, discord, kofi, payload;
    std::string store_version, store_url, store_sha256;
    int store_build = 0;
    std::string updated;
};

bool parse_catalog(const std::string &text, Catalog &out);

enum class Phase
{
    Idle,
    Working,
    Done,
    Failed,
};

struct JobState
{
    Phase phase = Phase::Idle;
    std::string kind;       /* "install" (the port) or "setup" (game files from a disc image) */
    std::string slot;       /* setup: which of the game's files */
    std::string app_id;     /* the game it is for, "" for the catalog */
    std::string step;       /* "Downloading", "Checking the download", "Installing" */
    std::uint64_t done = 0, total = 0;
    std::string error;      /* Failed: what went wrong, for the player */
    std::string installed_version;
    /* Done: what ShadowMountPlus did about the home screen. 2 it was running and is
     * adding (or, after a delete, took off) the game's tile; 1 the store started it; 0 neither. */
    int home = 0;
    /* Done: what to tell the player, when the job has its own words for it. */
    std::string note;
};

class Engine
{
public:
    /* data_dir: the store's own folder (/data/recompstore): the cached catalog, art and records. */
    void start(const std::string &data_dir);

    /* The catalog as last read (from the cache straight away, then fresh from the site). */
    bool catalog(Catalog &out) const;
    /* Bumped each time the catalog or a piece of art changes, so the screens know to refresh. */
    unsigned revision() const;
    bool online() const;
    bool loading() const;

    /* Download and install a game's newest release (or the catalog's link). One job at a time. */
    bool install(const std::string &app_id);
    /* Copy a game's files out of the player's own disc image into its data folder.
     * Files already there at the right size are kept (a stopped setup carries on). */
    bool setup(const std::string &app_id, const std::string &slot_key, const std::string &image);
    /* Settings > Diagnostics: write a test file on the console's storage each way the store
     * can, and copy game files the fastest way from then on. */
    bool bench();
    /* Delete an installed game's folder and everything in it (the port, game files from
     * the disc, saves, mods), as the player confirmed. Only ever a folder under a
     * homebrew folder, never the store itself. */
    bool remove(const std::string &app_id);
    JobState job() const;
    void acknowledge();

    /* A newer Recomp Store release on GitHub (its tag), or "" when this is the newest. */
    std::string store_update() const;
    /* Download and check that newer store (a job, kind "storeupdate"); when it's done,
     * update_store() hands it to the installer. */
    bool download_store_update();
    /* Once, when this store came from an installer's own copy and a newer one is on
     * GitHub: true and its tag, and the app starts that update right away. */
    bool take_auto_update(std::string &version);

    /* Every release's patch notes from a game's GitHub repo, newest first. want_notes
     * asks for them (fetched in the background, kept on disk for when the console is
     * offline); notes: 1 here they are, 0 still coming, -1 none to show. */
    void want_notes(const std::string &repo);
    int notes(const std::string &repo, std::vector<Release> &out) const;

    /* The version installed: "" when it isn't installed, "?" when it's on the console but
     * the store didn't install it. */
    std::string installed(const std::string &app_id) const;
    /* Take over a game that was installed by hand: the store records its version (its
     * recompstore.json, or param.json's contentVersion) and handles its updates from now. */
    bool adopt(const std::string &app_id, std::string &version_out);
};

/* Where disc images can be: USB drives, extended storage, the console's own storage. */
struct Place
{
    std::string label, path;
};
struct FsEntry
{
    std::string name, path;
    bool is_dir = false;
    std::uint64_t size = 0;
};
std::vector<Place> places();
/* A folder's sub-folders, then its files ending in ext (".iso"), or for ext "stfs" its Xbox 360
 * content packages; false when it can't be read. */
bool list_dir(const std::string &path, std::vector<FsEntry> &out, const char *ext = ".iso");
/* Where a slot's files go: the game's data folder plus the slot's own folder. */
std::string slot_target(const App &a, const Slot &s);
/* True when the slot's files are in place (a disc: its default.xex is there). */
bool slot_ready(const App &a, const Slot &s);
/* The disc image last used for a slot, "" when none. */
std::string slot_source(const App &a, const Slot &s);

/* The log: trace.txt, /data/recompstore/logs/store.log (kept between launches), and the
 * newest lines for Settings > Diagnostics. */
void slog(const char *fmt, ...) __attribute__((format(printf, 1, 2)));
void set_stat(const std::string &key, const std::string &value);
std::vector<std::string> log_tail(std::size_t n);
std::vector<std::pair<std::string, std::string>> stats();
std::string log_path();

/* Where a game lives: the folder under /data/homebrew whose sce_sys/param.json has its
 * title ID (found when the store starts and after each install), else /data/homebrew/<id>. */
std::string app_folder(const std::string &app_id);

/* ShadowMountPlus: the player's own copy of its payload, sent to the loader on port 9021
 * after each install and setup so the game shows up on the home screen. */
std::string smp_path();
void set_smp_path(const std::string &path);
/* 2: it was running (its API on 127.0.0.1:10101 answered) and is looking for new games
 * now; 1: it wasn't, so the player's copy was sent to the loader; 0: neither (why). */
int refresh_smp(std::string &why);
/* After a delete: a running ShadowMountPlus takes the game's tile off the home screen. */
bool forget_smp(const std::string &title_id);
/* Before a delete: a running ShadowMountPlus lets go of the game's folder. */
bool unmount_smp(const std::string &title_id);

/* Opening an installed game. An app may not start another app, so the store hands the
 * launcher payload to the loader on port 9021 and closes; the launcher opens the game
 * once the store has gone (or the store again, if the game won't open).
 * open_game: false and why when it couldn't even ask. open_game_state, each frame
 * after: 1 the launcher is running (close the store now), 0 not yet, -1 it gave up.
 * open_game_cancel: it took too long; it does nothing if it starts later.
 * open_game_failure: when the store opens again because a game wouldn't (once). */
bool open_game(const std::string &title_id, std::string &why);
/* Updating the store itself, once download_store_update() is done: its installer payload
 * (assets/payloads) is handed to the loader with the checked download, waits for the store
 * to close, installs it, and opens the store again. update_store_state, as open_game_state: 1 close now, 0 not yet, -1 it gave up. */
bool update_store(std::string &why);
int update_store_state();
void update_store_cancel();
int open_game_state();
void open_game_cancel();
bool open_game_failure(std::string &title_id, unsigned &result);

Engine &engine();
} // namespace recomp
