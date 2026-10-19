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
/* (1.1.0) Beta codes: redeeming one, and the beta games this console has. */
constexpr const char *kBetaUrl = "https://recompstore.com/api/beta.php";
/* (1.1.0) Send logs: a game page's logs, to the admin's inbox. */
constexpr const char *kLogsUrl = "https://recompstore.com/api/logs.php";
/* (2.0) The site's side of a linked phone, custom sources, the GitHub proxy and the charts. */
constexpr const char *kLinkUrl = "https://recompstore.com/api/link.php";
constexpr const char *kSourceUrl = "https://recompstore.com/api/source.php";
constexpr const char *kGhUrl = "https://recompstore.com/api/gh.php";
constexpr const char *kStatsUrl = "https://recompstore.com/api/stats.php";
constexpr const char *kChartsUrl = "https://recompstore.com/api/charts.php";
/* (2.0) Every title ID the site knows is taken (the catalog's, and apps outside it): the title ID
 * guard never gives one of them to an app it moves. */
constexpr const char *kIdsUrl = "https://recompstore.com/api/ids.php";
/* (2.0) The catalog with the testers-only apps in it, for a console the admin marked as a tester. */
constexpr const char *kTestCatalogUrl = "https://recompstore.com/api/catalog.php";

/* (2.0) A phone or PC linked to this console (Settings > Link a phone). */
struct LinkDevice
{
    std::string id, label, since, seen;
};
struct LinkInfo
{
    int code_state = 0; /* 0 none yet, 1 asking for one, 2 here it is, 3 the site couldn't give one */
    std::string code, url, error;
    int expires_in = 0; /* seconds the code is good for */
    std::vector<LinkDevice> devices;
    bool known = false; /* the site has answered this session */
};
/* Something a linked phone sent: install an app, add a source, read things again, a report done. */
struct LinkAction
{
    std::string kind, app, repo, list, report;
};

/* (1.1.2) A file a disc must have: its path on the disc, its exact size and, when has_crc,
 * its CRC-32. */
struct DiscWant
{
    std::string path;
    std::uint64_t size = 0;
    std::uint32_t crc = 0;
    bool has_crc = false;
};

struct Slot
{
    std::string key, label, kind /* iso, stfs, image, file, arcade */, to /* folder under data_dir; "." = in it */, title_id;
    /* (1.1.2) kind "iso": only these folders or files of the disc are copied, each with its
     * path on the disc ("DBZ3": Budokai 3 from the Budokai HD Collection, without Budokai 1 and
     * the collection's menu). Empty: the whole disc. */
    std::vector<std::string> only;
    /* (1.1.2) kind "iso": files the disc must have, at these sizes and CRC-32s. A disc
     * without them is refused before anything is copied, with wrong (the admin's words) as
     * the reason; once they're all in the slot's folder at their sizes, it's set up. */
    std::vector<DiscWant> files;
    std::string wrong;
    /* kind "image": a GameCube or Wii disc image copied as it is (.iso, .gcm, .ciso), and the
     * disc's ID it has to be (GZLE01), "" for any. */
    std::string disc_id;
    /* kind "arcade" (1.0.7): an Xbox Live Arcade game's own package, the file from the
     * console's 000D0000 folder, unpacked into the slot's folder (title_id: the game's, or ""
     * for any); set up once default.xex is there. */
    /* kind "file" (1.0.6): one of the player's own files copied into the game's folder.
     *   ext     the extensions it may have, space-separated (".z64 .n64 .v64"); "" any
     *   pick    the name it must have (a pattern, any case: "pak0.pk3"); "" any
     *   also    more files from the same folder copied with it ("pak?.pk3", any case)
     *   name    the name it is given in the game's folder ("DIABDAT.MPQ"); "" its own
     *   sha1    the SHA-1s it may have, space-separated (after n64); "" any
     *   n64     a Nintendo 64 ROM: a .n64 or .v64 byte order is turned into .z64's
     *   need    how many of pick + also must be there to call it set up (default 1)
     *   made    a file the game makes from it: there, the slot is set up too ("sm64.o2r") */
    std::string ext, pick, also, name, sha1, made;
    /* (1.0.8) ready: a file in the slot's folder whose being there means it's set up
     * ("Default.xexp"), any case; "" for the kind's own test. update_id and update_sha1: a
     * Title Update (kind "stfs") the store can download itself: the package's content ID
     * (its header's, as XboxUnity lists it) and the whole file's SHA-1, both lowercase. */
    std::string ready, update_id, update_sha1;
    bool n64 = false;
    int need = 1;
    bool required = true, multiple = false;
};
/* The slot kinds this store can set up. */
bool slot_kind_known(const std::string &kind);
/* One plain name, for a file in a game's folder: no "/" or "\\", not "." or "..", not
 * hidden, no control characters (1.0.6: a slot's name, made and key). */
bool plain_leaf(const std::string &n);
/* A path spelled plainly: relative, or absolute under /data/; no empty, "." or ".."
 * parts, no "\\", no control characters (1.0.6: a game's save folders). */
bool plain_path(const std::string &p);

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
    std::vector<std::string> assets; /* its files' names */
};

/* A release file's name against the catalog's: the same, or for a name with * (any run
 * of characters) or ? (any one), matching it. "WindWaker-PS5-*.zip" matches each
 * release's "WindWaker-PS5-v1.0.3.zip". */
bool asset_matches(const std::string &pattern, const std::string &name);

struct App
{
    std::string id, name, kind /* original, community */, author;
    std::string blurb, description, news, credits;
    std::string version, released, size, needs;
    int build = 0;
    std::string source /* github, url */, repo, asset /* may hold * and ? */, url, sha256;
    /* Where the game's own zip is inside that download, when the release only has a bigger
     * zip (a source archive), its whole name in that zip: The Simpsons Game's is a star then
     * /prospero/builder/Assets/payload.zip (the star stands for the source folder's
     * versioned name). "" = the download is the game's zip. May hold * and ?, as asset
     * does. Needs Recomp Store 1.0.4. */
    std::string inside;
    /* The oldest Recomp Store that can install it ("" for any): an older one is told to
     * update first. Stores before 1.0.3 never see such a game (the site lists it apart). */
    std::string min_store;
    int order = 0; /* where the admin lists it */
    std::string icon_url, hero_url, hero_web_url;
    int banner_x = 50, banner_y = 30, banner_zoom = 100;
    /* A logo to show instead of the name (PNG with transparency), and where it sits on
     * the featured banner and on the game's page. */
    std::string logo_url;
    LogoPlace logo_banner, logo_page;
    bool has_setup = false;
    std::string data_dir;
    std::vector<Slot> slots;
    /* Where the game keeps saves and settings (1.0.6): folders in its own folder
     * ("userdata"), or whole paths under /data. Delete can leave them. */
    std::vector<std::string> saves;
    /* A Coming soon entry (1.0.6): not installable; date is what it says instead of a
     * version ("Fall 2026", or empty). */
    bool soon = false;
    std::string date;
    /* (1.1.0) A game in testing, unlocked on this console with a code (Settings > Redeem a
     * code). It comes from recompstore.com's beta list, not the catalog, downloads from the
     * site (source "url"), and shows in the Library, not in Discover. */
    bool beta = false;
    /* (1.1.0) The game's log files for Send logs: patterns inside its folder (like crash_*.txt),
     * set in the admin. Empty: the store looks for them itself (game_log_files). */
    std::vector<std::string> logs;
    /* (1.1.2) The player's own files and folders in the game's folder ("Assets",
     * "user_data", "dbz3.toml"): an update never writes over them. A release's copy of one
     * is written only when the player has none yet. */
    std::vector<std::string> keep;
    /* (1.1.2) A download from a fixed link (a beta game's, from the site): its exact size,
     * so its progress shows and a cut-off download is caught. 0: not known. */
    std::uint64_t bytes = 0;
    /* (2.0) Which part of the store it's in: "games", "emulators", "apps" or "tools",
     * and up to 6 short labels shown on its page ("Recomp", "Port", "Homebrew"). */
    std::string category = "games";
    std::vector<std::string> tags;
    /* (2.0) false: the app expects its own title ID's folder, so the title ID guard won't
     * install it under a new ID (it may only be installed over a clash, or not at all). */
    bool can_move = true;
    /* (2.0) An app from a source the player added (Settings > Sources), not the catalog:
     * not checked by Recomp Store. source_name / source_value: which source (as the site
     * named it, and the repo or list address the player added). title_id: its title ID when
     * the site could read it from the release, "" until the store reads it on install.
     * stars and downloads: its repo's GitHub numbers (-1 when not known). */
    bool custom = false;
    std::string source_name, source_value, title_id;
    long long stars = -1, downloads = -1;
    /* (2.0) Only consoles the admin marked as testers see it (the test catalog): a badge says so. */
    bool testing = false;
    /* Filled by the engine: where its art was saved, empty until it is. */
    std::string icon_file, hero_file, logo_file;
    /* (2.0) data_dir and saves as the catalog says them, before the engine points them at
     * where the game is (a new title ID's folder, a drive): worked out from these each time. */
    std::string data_dir_src;
    std::vector<std::string> saves_src;
    bool src_set = false;
};

/* A part of Discover, under the featured banner (1.0.6), set up in the admin. */
struct Section
{
    std::string type;   /* "games", "spotlight" (one game as a still banner), "soon", or (2.0)
                           "category": every app in one part of the store (category); "top",
                           "trending", "new", "updated": the charts' lists (Charts), in order */
    std::string category;
    std::string title;  /* above it; a spotlight's kicker */
    std::string layout; /* "row" (sideways), "grid" (every game, rows of six) or "wide" */
    bool all = false;   /* "games": every game in the store, in the admin's order */
    std::vector<std::string> ids; /* "games": these, in this order; "spotlight": the first */
};

struct Catalog
{
    std::vector<App> apps;
    std::vector<App> soon; /* Coming soon */
    std::vector<Section> sections; /* empty: the store's own (Latest games, Coming soon) */
    std::vector<std::string> featured;
    std::string tagline, discord, kofi, payload;
    std::string store_version, store_url, store_sha256;
    int store_build = 0;
    std::string updated;
};

bool parse_catalog(const std::string &text, Catalog &out);

/* (2.0) A custom source the player added: a GitHub repo, or a list of repos at an https
 * address. recompstore.com reads it (api/source.php) and answers with its apps, catalog-shaped. */
struct Source
{
    std::string kind;         /* "repo" or "list" */
    std::string value;        /* "owner/name", or the list's address */
    std::string name, author; /* as the site named it ("" until it has) */
    int state = 0;            /* 0 not read yet, 1 reading, 2 read, 3 couldn't be read, 4 taken down */
    std::string error;        /* 3, 4: why, in the site's words */
    std::size_t apps = 0;
    std::vector<std::string> problems; /* repos in it that couldn't be used: "owner/name: why" */
};

/* (2.0) The charts (recompstore.com/api/charts.php): app IDs in order, and each app's numbers. */
struct ChartStat
{
    long long installs = 0, stars = -1, downloads = -1;
};
struct Charts
{
    std::vector<std::string> top, trending, fresh /* "new" */, updated;
    std::vector<std::pair<std::string, ChartStat>> stats;
    bool have = false;
    /* The ids of a Discover section of one of the charts' types ("top", "trending", "new", "updated"). */
    const std::vector<std::string> *list(const std::string &type) const
    {
        return type == "top" ? &top : type == "trending" ? &trending : type == "new" ? &fresh : type == "updated" ? &updated : nullptr;
    }
    const ChartStat *stat(const std::string &id) const
    {
        for (const auto &kv : stats)
            if (kv.first == id) return &kv.second;
        return nullptr;
    }
};
/* (2.0) What the player typed for a source, made plain: a repo ("owner/name", from its github.com
 * address too) or a list's https address. false and why when it's neither. */
bool source_from_text(const std::string &text, std::string &kind, std::string &value, std::string &why);
/* True when a game needs a newer Recomp Store than this one. */
bool store_too_old(const App &a);
/* True when version a is newer than b ("v1.0.10" > "1.0.9"). */
bool version_newer(const std::string &a, const std::string &b);
/* Where a version's numbers start: 1 for "v1.0", 2 for "V.1.1.1", 0 for "1.0". */
std::size_t version_start(const std::string &v);

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
    /* (2.0) The title ID guard: an install that stopped before writing anything, because
     * another app on this PS5 already has the game's title ID. clash_with: that app's name
     * ("" when it can't be told); clash_pkg: it's installed as a package (so this one can
     * only go under a new ID); clash_new: the ID this one can have instead. The download is
     * kept, so whichever the player picks (resolve_clash) doesn't download it again. */
    bool clash = false, clash_pkg = false;
    std::string clash_with, clash_new;
    std::string clash_tid; /* (2.0) the title ID that clashed (a custom app's, as its release says) */
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
    /* (2.0) After an install stopped on a clash (JobState::clash): 1 install it under its own
     * new title ID (clash_new; its updates go there from then on), 2 install it over the
     * other app's folder (an older copy of the same app), 0 neither (the kept download goes). */
    bool resolve_clash(const std::string &app_id, int choice);
    /* (2.0) Refresh: the catalog and this console's beta games read again, every installed
     * game's updates looked for, and the apps on the console found again (one installed
     * while the store was open shows up), without closing the store. */
    void refresh();
    /* Copy a game's files out of the player's own disc image into its data folder.
     * Files already there at the right size are kept (a stopped setup carries on). */
    bool setup(const std::string &app_id, const std::string &slot_key, const std::string &image);
    /* (1.0.8) The same, for a Title Update the store downloads itself (slot_fetchable). */
    bool fetch_update(const std::string &app_id, const std::string &slot_key);
    /* Settings > Diagnostics: write a test file on the console's storage each way the store
     * can, and copy game files the fastest way from then on. */
    bool bench();
    /* Delete an installed game's folder and everything in it (the port, game files from
     * the disc, saves, mods), as the player confirmed. Only ever a folder under a
     * homebrew folder, never the store itself. */
    /* keep_saves: the game's save folders stay (App::saves). */
    bool remove(const std::string &app_id, bool keep_saves = false);
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

    /* The newest release on GitHub that has the game's zip, as last read ("" before the
     * store has looked, or for a game from a fixed link). Updates come from it. */
    std::string newest_version(const App &a) const;
    /* Settings > Check for updates: the store's own and every installed game's, now.
     * update_check: 0 not asked, 1 checking, 2 done, 3 GitHub couldn't be reached. */
    void check_updates_now();
    int update_check() const;

    /* Every release's patch notes from a game's GitHub repo, newest first. want_notes
     * asks for them (fetched in the background, kept on disk for when the console is
     * offline); notes: 1 here they are, 0 still coming, -1 none to show. */
    void want_notes(const std::string &repo);
    int notes(const std::string &repo, std::vector<Release> &out) const;

    /* (1.1.0) Settings > Redeem a code: an 8-digit code for a beta game, checked with
     * recompstore.com. redeem_state: 0 nothing asked, 1 checking, 2 unlocked, 3 refused or
     * it couldn't ask; message says what happened (the site's own words), app_id is the
     * game it unlocked. redeem_ack: seen, back to 0. The console keeps the ID and token the
     * site gave it on its first code, so its beta games stay for as long as the store does. */
    bool redeem(const std::string &code);
    int redeem_state(std::string &message, std::string &app_id) const;
    void redeem_ack();

    /* (1.1.0) Send logs, on a game's page: the store's log and the game's own log files
     * (game_log_files), with the player's message, to recompstore.com, where they land in the
     * admin's inbox. logs_state: 0 nothing asked, 1 sending, 2 sent, 3 they didn't go;
     * message: the site's words, or why. */
    bool send_logs(const std::string &app_id, const std::string &message);
    int logs_state(std::string &message) const;
    void logs_ack();
    /* (2.0) The report the last logs started on the site, to finish on a phone: its code and
     * address ("" when the site didn't give one). */
    std::string logs_report(std::string &url) const;

    /* (2.0) Link a phone. While Settings > Link a phone is open the store asks the site
     * every few seconds what's new (a phone that scanned the code, things sent); with a
     * device linked, every 15 s; else now and then. link_new_code asks for a fresh code;
     * link_unlink lets a device go; take_link_action hands the screens what a phone sent,
     * once each. */
    void link_screen(bool open);
    void link_new_code();
    LinkInfo link_info() const;
    void link_unlink(const std::string &device_id);
    bool take_link_action(LinkAction &out);
    /* (2.0) This console's ID on recompstore.com ("" until the site gave it one). */
    std::string install_id() const;

    /* (2.0) Custom sources (Settings > Sources), kept in /data/recompstore/sources.json.
     * add_source: what the player typed (source_from_text); false and why when it isn't a
     * source, or it's there already. A source is read straight away, then with each catalog
     * refresh; its apps join the store's (after the catalog's own, which win on a shared ID),
     * marked custom. remove_source takes it and its apps out (installed ones stay installed). */
    std::vector<Source> sources() const;
    bool add_source(const std::string &text, std::string &why);
    void remove_source(const std::string &value);
    /* Report a source (it's broken, or shouldn't be there) to recompstore.com's inbox.
     * source_report_state as logs_state: 0 nothing asked, 1 sending, 2 sent, 3 didn't go. */
    bool report_source(const std::string &value, const std::string &why);
    int source_report_state(std::string &message) const;
    void source_report_ack();
    /* (2.0) The charts, as last read with the catalog (have false before). */
    Charts charts() const;
    /* (2.0) A console the admin marked as a tester (the site says so): it reads the test
     * catalog, with the testers-only apps in it, unless test_view is off (Settings), when it
     * sees what everyone sees. */
    bool tester() const;
    bool test_view() const;
    void set_test_view(bool on);
    /* (2.0) Advanced mode: where new apps install. The console's homebrew folder, or one on a
     * drive ShadowMountPlus scans (the M.2 SSD, extended storage, a USB drive), as Place{label,
     * path to its homebrew folder}; only drives that are connected. Updates always go where an
     * app already is. Kept in /data/recompstore/install-root.txt. */
    std::vector<struct Place> install_places() const;
    std::string install_root() const;
    void set_install_root(const std::string &homebrew_folder);

    /* The version installed: "" when it isn't installed, "?" when it's on the console but
     * the store didn't install it. */
    std::string installed(const std::string &app_id) const;
    /* Take over a game that was installed by hand: the store records its version (its
     * recompstore.json, or param.json's contentVersion) and handles its updates from now. */
    bool adopt(const std::string &app_id, std::string &version_out);
};

/* (1.1.0) A log file Send logs takes: where it is, and its name as sent (store.log, or its
 * path inside the game's folder). */
struct LogFile
{
    std::string path, name;
    std::uint64_t size = 0;
    long long mtime = 0;
};
/* The files Send logs takes for a game: the store's log first, then the game's own, newest
 * first: those its catalog entry names (App::logs), else any .log file and any .txt file with
 * log, crash or trace in its name, in its folder (4 folders deep at most); 8 of the game's. */
std::vector<LogFile> game_log_files(const App &a);

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
/* A GameCube or Wii disc image's ID (GZLE01), "" when the file isn't one. */
std::string disc_image_id(const std::string &path);
/* Where a slot's files go: the game's data folder plus the slot's own folder. */
std::string slot_target(const App &a, const Slot &s);
/* (1.0.8) A slot whose Title Update the store can download itself. */
bool slot_fetchable(const Slot &s);
/* (1.0.8) A file the store downloaded itself (a Title Update, in its own folder). */
bool store_downloaded(const std::string &path);
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
/* (2.0) The title ID guard. The title ID an app is installed under: its own, or the one it
 * was given because its own was taken on this PS5 (moved_id: that one, "" when it has its
 * own). Kept in /data/recompstore/moves.json and in the marker in the app's folder. */
std::string installed_id(const std::string &app_id);
std::string moved_id(const std::string &app_id);
/* (2.0) An app's own title ID: its ID, or for a custom source's app the one its release's
 * param.json said when it was installed (until then its ID, which may be "gh-..."). */
std::string own_id(const std::string &app_id);
/* (2.0) When the store last installed or updated an app (its record's time), 0 if never. */
long long installed_time(const std::string &app_id);
/* (2.0) The file the store leaves in every app folder it installs: which app it is, the
 * title ID it's under and where it came from, so the guard knows its own installs. */
constexpr const char *kMarker = "recompstore-install.json";

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
