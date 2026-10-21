/* Recomp Store preview: the engine and sound without a network or a console. */
#include "store_engine.hpp"
#include "store_json.hpp"
#include "porpoise_sound.hpp"
#include <cstdio>
#include <dirent.h>
#include <sys/stat.h>
#include <algorithm>
#include <cstdlib>
#include <string>

/* (2.2.3) PREVIEW_PERF: how often the screens ask the engine things, and what the PS5's file calls
 * would cost (PREVIEW_IO_US microseconds each, spun: installed() is a stat and a file read there). */
#include <atomic>
#include <chrono>
#include <map>
#include <mutex>
std::map<std::string, long> g_perf_calls;
std::mutex g_perf_m;
void perf_count(const char *what) { std::lock_guard<std::mutex> l(g_perf_m); ++g_perf_calls[what]; }
void perf_cost(const char *what)
{
    perf_count(what);
    static const long us = std::getenv("PREVIEW_IO_US") ? std::atol(std::getenv("PREVIEW_IO_US")) : 0;
    if (us <= 0) return;
    const auto until = std::chrono::steady_clock::now() + std::chrono::microseconds(us);
    while (std::chrono::steady_clock::now() < until) {}
}

namespace recomp
{
namespace
{
Catalog g_cat;
bool g_loaded = false;
std::string local(const std::string &url)
{
    const char *root = std::getenv("PREVIEW_SITE");
    const std::string pre = "https://recompstore.com";
    if (!root || url.rfind(pre, 0) != 0) return "";
    return std::string(root) + url.substr(pre.size());
}
void load()
{
    if (g_loaded) return;
    g_loaded = true;
    std::string text;
    if (const char *p = std::getenv("PREVIEW_CATALOG"))
        if (std::FILE *f = std::fopen(p, "rb"))
        {
            char b[8192]; std::size_t n;
            while ((n = std::fread(b, 1, sizeof b, f)) > 0) text.append(b, n);
            std::fclose(f);
        }
    parse_catalog(text, g_cat);
    /* (2.0) PREVIEW_CUSTOM: a source.php answer whose apps join as a custom source's. */
    if (const char *p = std::getenv("PREVIEW_CUSTOM"))
        if (std::FILE *f = std::fopen(p, "rb"))
        {
            std::string t;
            char b[8192]; std::size_t n;
            while ((n = std::fread(b, 1, sizeof b, f)) > 0) t.append(b, n);
            std::fclose(f);
            Catalog c;
            Json j;
            if (parse_catalog(t, c) && Json::parse(t, j))
                for (App a : c.apps)
                {
                    a.custom = true;
                    a.source_value = j["source"]["value"].str();
                    if (a.source_name.empty()) a.source_name = j["source"]["name"].str();
                    a.order = 100000 + int(g_cat.apps.size());
                    g_cat.apps.push_back(a);
                }
        }
    for (App &a : g_cat.apps) { a.icon_file = local(a.icon_url); a.hero_file = local(a.hero_url); a.logo_file = local(a.logo_url); }
    for (App &a : g_cat.soon) { a.icon_file = local(a.icon_url); a.hero_file = local(a.hero_url); a.logo_file = local(a.logo_url); }
}
} // namespace
void Engine::start(const std::string &) { load(); }
bool Engine::catalog(Catalog &out) const { load(); out = g_cat; return true; }
unsigned Engine::revision() const { return 1; }
bool Engine::online() const { return true; }
std::string Engine::catalog_note() const { return ""; }
bool Engine::loading() const { return false; }
bool Engine::install(const std::string &, const std::string &, const std::string &) { return true; }
/* (2.2.2) The download queue, in memory; PREVIEW_QUEUE: "PPSA64008,PPSA94202" waiting at the start. */
static std::vector<QueueItem> g_queue;
static bool g_queue_read = false;
static void queue_read()
{
    if (g_queue_read) return;
    g_queue_read = true;
    if (const char *e = std::getenv("PREVIEW_QUEUE"))
    {
        std::string s = std::string(e) + ",";
        for (std::size_t at = 0, c; (c = s.find(',', at)) != std::string::npos; at = c + 1)
            if (c > at) g_queue.push_back({s.substr(at, c - at), "", ""});
    }
}
bool Engine::queue_add(const std::string &id, const std::string &v, const std::string &root)
{
    queue_read();
    for (const QueueItem &q : g_queue) if (q.id == id) return false;
    g_queue.push_back({id, v, root});
    return true;
}
std::vector<QueueItem> Engine::queue() const { perf_count("queue"); queue_read(); return g_queue; }
bool Engine::queue_remove(const std::string &id)
{
    queue_read();
    for (auto it = g_queue.begin(); it != g_queue.end(); ++it) if (it->id == id) { g_queue.erase(it); return true; }
    return false;
}
void Engine::queue_clear() { g_queue.clear(); }
std::string Engine::queue_pump() { return ""; } /* the screens stay as set up */
bool Engine::cancel_job() { return true; }
std::vector<std::string> Engine::auto_update_picks(const std::vector<std::string> &, bool) const { return {}; }
unsigned Engine::checks_done() const { return 0; }
/* (2.2.3) The Drives view: what's at a drive's top, made up (some sizes still being worked out). */
std::vector<FolderSize> Engine::drive_folders(const std::string &mount, bool *looking) const
{
    if (looking) *looking = false;
    std::vector<FolderSize> v;
    auto add = [&](const char *n, long long mb, bool dir = true) { FolderSize f; f.name = n; f.path = mount + "/" + n; f.size = mb < 0 ? -1 : mb << 20; f.dir = dir; v.push_back(f); };
    if (mount == "/data")
    {
        add("homebrew", 61440); add("recompstore", 2310); add("etaHEN", 140); add("roms", 18200); add("PCSX2", 920);
        add("shadowmount", 35); add("payloads", 12); add("Jellyfin", -1); add("game-backups", 104000); add("notes.txt", 0, false);
    }
    else { add("homebrew", 9216); add("Games", 210000); add("Movies", -1); }
    return v;
}
std::vector<DriveSpace> Engine::drives() const
{
    std::vector<DriveSpace> d;
    DriveSpace c{"Console storage", "/data", "/data/homebrew", 825ull << 30, 92ull << 30, true};
    if (const char *f = std::getenv("PREVIEW_DATA_FREE_GB")) c.free = std::uint64_t(std::atoll(f)) << 30;
    d.push_back(c);
    d.push_back({"M.2 SSD", "/mnt/ext1", "/mnt/ext1/homebrew", 1863ull << 30, 1210ull << 30, true});
    d.push_back({"USB drive 1", "/mnt/usb0", "/mnt/usb0/homebrew", 931ull << 30, 702ull << 30, true});
    return d;
}
std::uint64_t Engine::cache_seen(std::size_t *files, bool) const { perf_count("cache_seen"); return cache_bytes_now(files); }
unsigned Engine::usb_seen(bool *known) const { if (known) *known = true; return getenv("PREVIEW_USB") ? 1u : 0u; }
std::uint64_t Engine::cache_bytes(std::size_t *files) const { return cache_bytes_now(files); }
std::uint64_t Engine::cache_bytes_now(std::size_t *files)
{
    const char *e = std::getenv("PREVIEW_CACHE_MB");
    if (files) *files = e && std::atoi(e) == 0 ? 0 : 3;
    return std::uint64_t(e ? std::atoll(e) : 1290) << 20;
}
bool Engine::clear_downloads(std::uint64_t &freed, std::size_t &files, std::string &why) { freed = 1290ull << 20; files = 3; why.clear(); return true; }
bool Engine::manage_many(const std::vector<std::string> &) { return true; }
bool Engine::get_smp() { return true; }
/* (2.1) PREVIEW_HELD: apps kept at an older version, "PPSA92011=1.0.0,..." (as PREVIEW_INSTALLED). */
std::string Engine::held(const std::string &id) const
{
    const char *e = std::getenv("PREVIEW_HELD");
    if (!e) return "";
    const std::string list = std::string(",") + e + ",";
    const std::size_t at = list.find("," + id + "=");
    if (at == std::string::npos) return "";
    const std::size_t v = at + id.size() + 2;
    return list.substr(v, list.find(',', v) - v);
}
bool Engine::bench() { return true; }
bool Engine::remove(const std::string &, bool) { return true; }
static std::string g_smp;
std::string smp_path() { if (const char *e = std::getenv("PREVIEW_SMP")) return e; return g_smp; }
void set_smp_path(const std::string &p) { g_smp = p; }
int refresh_smp(std::string &) { return 2; }
void smp_scan_later() {}
void smp_scan_again() {}
bool forget_smp(const std::string &) { return true; }
bool open_game(const std::string &, std::string &) { return true; }
int open_game_state() { return 0; }
void open_game_cancel() {}
bool open_game_failure(std::string &, unsigned &) { return false; }
bool Engine::setup(const std::string &, const std::string &, const std::string &) { return true; }
bool Engine::fetch_update(const std::string &, const std::string &) { return true; }
bool slot_fetchable(const Slot &s)
{
    return s.kind == "stfs" && !s.multiple && s.title_id.size() == 8 && s.update_id.size() == 40 && s.update_sha1.size() == 40;
}
bool store_downloaded(const std::string &path) { return path.rfind("/data/recompstore/updates/", 0) == 0; }
static std::string g_job_kind, g_job_app;
void preview_job(const char *kind, const char *app) { g_job_kind = kind ? kind : ""; g_job_app = app ? app : ""; }
JobState Engine::job() const
{
    JobState j;
    if (g_job_kind == "smpget") { j.phase = Phase::Working; j.kind = "smpget"; j.step = "Downloading ShadowMountPlus"; j.done = 900ull << 10; j.total = 2440ull << 10; }
    if (g_job_kind == "install") { j.phase = Phase::Working; j.kind = "install"; j.app_id = g_job_app; j.step = "Downloading"; j.done = 312ull << 20; j.total = 476ull << 20; }
    if (g_job_kind == "fetch") { j.phase = Phase::Working; j.kind = "setup"; j.slot = "update"; j.app_id = g_job_app; j.step = "Downloading the update"; j.done = 150ull << 10; j.total = 240ull << 10; }
    if (g_job_kind == "setup") { j.phase = Phase::Working; j.kind = "setup"; j.slot = "game"; j.app_id = g_job_app; j.step = "Copying the game's files"; j.done = 2900ull << 20; j.total = 7300ull << 20; }
    if (g_job_kind == "mmove") { j.phase = Phase::Working; j.kind = "mmove"; j.app_id = g_job_app; j.step = "Copying"; j.done = 640ull << 20; j.total = 1810ull << 20; }
    return j;
}
/* (2.2) Library > On this PS5: a console with the store's apps, others, and a folder left behind.
 * PREVIEW_CONSOLE_ICON: a real param.json's icon0.png for the PS5SX2 row. */
std::vector<ConsoleApp> Engine::console_apps(bool *looking) const
{
    perf_count("console_apps");
    load();
    if (looking) *looking = false;
    std::vector<ConsoleApp> v;
    auto add = [&](const char *folder, const char *tid, const char *name, const char *ver, const char *store_id, long long mb, bool app,
                   const std::string &icon) {
        ConsoleApp c;
        c.folder = folder;
        c.root = c.folder.substr(0, c.folder.find_last_of('/'));
        c.place = c.root == "/data/homebrew" ? "Console storage" : c.root == "/mnt/ext1/homebrew" ? "M.2 SSD" : "USB drive 1";
        c.title_id = tid;
        c.name = name;
        c.version = ver;
        c.store_id = store_id;
        c.size = mb < 0 ? -1 : mb << 20;
        c.app = app;
        c.icon = icon;
        c.store = std::string(tid) == "PPSA92000";
        c.mtime = 1760000000 + (long long)(v.size() * 7919 % 97) * 3600; /* (2.2.2) Newest */
        v.push_back(c);
    };
    auto icon_of = [&](const char *id) { for (const App &a : g_cat.apps) if (a.id == id) return a.icon_file; return std::string(); };
    const char *real = std::getenv("PREVIEW_CONSOLE_ICON");
    add("/data/homebrew/PPSA92000", "PPSA92000", "Recomp Store", "2.2.1", "", 52, true, "/home/claude/recompstore/app/assets/brand/logo-512.png");
    add("/data/homebrew/PPSA92011", "PPSA92011", "WWE SmackDown vs. Raw 2011", "1.0.0", "PPSA92011", 7420, true, icon_of("PPSA92011"));
    add("/data/homebrew/PPSA99203", "PPSA99203", "PS5SX2", "2.15", "", 61, true, real ? real : "");
    add("/data/homebrew/PPSA64007", "PPSA64007", "Perfect Dark", "2.0.0", "PPSA64007", 214, true, icon_of("PPSA64007"));
    add("/mnt/ext1/homebrew/PPSA99008", "PPSA99008", "ProsperoEden", "1.5", "", 43, true, "");
    add("/mnt/usb0/homebrew/PPSA99251", "PPSA99251", "PS5SX2 (God of War)", "2.15", "", 47, true, real ? real : "");
    add("/data/homebrew/old-port-backup", "", "old-port-backup", "", "", 3210, false, "");
    add("/data/homebrew/PPSA64008", "PPSA64008", "GoldenEye 007", "2.0.0", "PPSA64008", -1, true, icon_of("PPSA64008"));
    add("/mnt/ext1/homebrew/PPSA77003", "PPSA77003", "RetroArch", "1.19", "PPSA77003", 1290, true, icon_of("PPSA77003"));
    add("/data/homebrew/PPSA94202", "PPSA94202", "Sonic Unleashed Recompiled", "0.1.4", "PPSA94202", 9120, true, icon_of("PPSA94202"));
    add("/mnt/usb0/homebrew/PPSA99412", "PPSA99412", "ItemzFlow", "1.12", "", 38, true, "");
    /* (2.2.3) PREVIEW_CONSOLE_N more folders, for the speed test. */
    if (const char *n = std::getenv("PREVIEW_CONSOLE_N"))
        for (int i = 0; i < std::atoi(n); ++i)
        {
            static std::vector<std::string> keep;
            if (keep.size() < 400) { keep.push_back("/data/homebrew/PPSA9" + std::to_string(1000 + i)); keep.push_back("PPSA9" + std::to_string(1000 + i)); keep.push_back("Homebrew app " + std::to_string(i + 1)); }
            const std::size_t k = std::size_t(i) * 3;
            if (k + 2 < keep.size()) add(keep[k].c_str(), keep[k + 1].c_str(), keep[k + 2].c_str(), "1.0", "", 20 + i * 13, true, "");
        }
    return v;
}
unsigned Engine::console_revision() const { return 1; }
void Engine::look_console() {}
bool Engine::manage(const std::string &, const std::string &, const std::string &) { return true; }
std::string Engine::free_title_id() const { return "PPSA97003"; }
int Engine::smp_state(std::string &v) const
{
    const char *e = std::getenv("PREVIEW_SMP_STATE");
    const char *ver = std::getenv("PREVIEW_SMP_VERSION");
    const int st = e ? std::atoi(e) : 1;
    v = st == 1 ? (ver ? ver : "1.7beta5") : "";
    return st;
}
bool Engine::reload_smp(std::string &) { return true; }
static bool g_lost = false;
void preview_storage_lost(bool lost) { g_lost = lost; }
bool Engine::storage_lost() const { return g_lost; }
int Engine::home_waiting() const { const char *e = std::getenv("PREVIEW_HOME_WAITING"); return e ? std::atoi(e) : 0; }
bool refresh_home(std::string &) { return true; }
bool refresh_result(std::string &) { return false; }
std::vector<Place> places()
{
    std::vector<Place> out;
    if (const char *u = std::getenv("PREVIEW_USB")) out.push_back({"USB drive 1", u});
    out.push_back({"Extended storage", "/mnt/ext0"});
    out.push_back({"Console storage", "/data"});
    return out;
}
bool list_dir(const std::string &path, std::vector<FsEntry> &out, const char *)
{
    out.clear();
    DIR *d = opendir(path.c_str());
    if (!d) return false;
    std::vector<FsEntry> dirs, isos;
    while (dirent *e = readdir(d))
    {
        std::string n = e->d_name;
        if (n.empty() || n[0] == '.') continue;
        FsEntry f; f.name = n; f.path = path + "/" + n;
        struct stat st; if (stat(f.path.c_str(), &st) != 0) continue;
        if (S_ISDIR(st.st_mode)) { f.is_dir = true; dirs.push_back(f); }
        else if ((n.size() > 4 && (n.substr(n.size() - 4) == ".iso" || n.substr(n.size() - 4) == ".ISO" || n.substr(n.size() - 4) == ".gcm")) ||
                 (n.size() > 5 && n.substr(n.size() - 5) == ".ciso")) { f.size = n.find("Wind") != std::string::npos ? 1459978240ull : 7834566656ull; isos.push_back(f); }
    }
    closedir(d);
    auto by = [](const FsEntry &a, const FsEntry &b) { return a.name < b.name; };
    std::sort(dirs.begin(), dirs.end(), by); std::sort(isos.begin(), isos.end(), by);
    out = dirs; out.insert(out.end(), isos.begin(), isos.end());
    return true;
}
std::vector<std::string> log_tail(std::size_t n)
{
    std::vector<std::string> v = {"18:02:11  Recomp Store 0.1 started; data in /data/recompstore", "18:02:11  space on /data: 612.4 GB free of 825.0 GB",
        "18:02:11  homebrew on this console: PPSA92011=PPSA92011 PPSA99902=UnleashedRecomp", "18:02:12  catalog: HTTP 200, 9123 bytes, 3 games",
        "18:05:40  setup PPSA92011: disc title ID 5451085D, want 5451085D", "18:05:40  setup PPSA92011 from /mnt/usb0/Games/SmackDown vs Raw 2011.iso: 412 files, 5690000000 bytes",
        "18:05:45  setup: 1312 MB in 5 s (262.4 MB/s); reading took 4 s, writing 2 s", "18:06:02  setup: copied 5427 MB in 22 s (246.7 MB/s)", "18:06:02  setup PPSA92011: done"};
    if (v.size() > n) v.erase(v.begin(), v.end() - std::ptrdiff_t(n));
    return v;
}
std::vector<std::pair<std::string, std::string>> stats() { return {{"Last disc setup", "5427 MB in 22 s \xC2\xB7 246.7 MB/s (reading 18 s, writing 9 s)"}, {"Last download", "476 MB in 41 s \xC2\xB7 11.6 MB/s"}}; }
std::string log_path() { return "/data/recompstore/logs/store.log"; }
std::string app_folder(const std::string &id) { return "/data/homebrew/" + id; }
bool Engine::adopt(const std::string &, std::string &v) { v = "1.0.0"; return true; }
void Engine::want_notes(const std::string &) {}
std::string Engine::store_update() const { const char *v = std::getenv("PREVIEW_STORE_UPDATE"); return v ? v : ""; }
bool update_store(std::string &) { return true; }
bool Engine::download_store_update() { return true; }
bool Engine::take_auto_update(std::string &) { return false; }
std::string Engine::newest_version(const App &a) const
{
    if (const char *e = std::getenv("PREVIEW_NEWEST"))
    {
        const char *for_id = std::getenv("PREVIEW_NEWEST_ID"); /* the game it's for (default PPSA99309) */
        if (a.id == (for_id ? for_id : "PPSA99309")) return e;
    }
    return "";
}
void Engine::check_updates_now() {}
int Engine::update_check() const { const char *e = std::getenv("PREVIEW_CHECK"); return e ? std::atoi(e) : 0; }
int update_store_state() { return 0; }
void update_store_cancel() {}
/* PREVIEW_NOTES: a GitHub releases answer (JSON) shown as every game's patch notes. */
int Engine::notes(const std::string &, std::vector<Release> &out) const
{
    const char *p = std::getenv("PREVIEW_NOTES");
    if (!p) return -1;
    std::string text;
    if (std::FILE *f = std::fopen(p, "rb"))
    {
        char b[8192]; std::size_t n;
        while ((n = std::fread(b, 1, sizeof b, f)) > 0) text.append(b, n);
        std::fclose(f);
    }
    Json j;
    if (!Json::parse(text, j)) return -1;
    out.clear();
    for (std::size_t i = 0; i < j.size(); ++i)
    {
        Release r;
        r.tag = j[i]["tag_name"].str(); r.name = j[i]["name"].str(); r.date = j[i]["published_at"].str().substr(0, 10);
        r.body = j[i]["body"].str(); r.prerelease = j[i]["prerelease"].boolean(false);
        for (std::size_t k = 0; k < j[i]["assets"].size(); ++k) r.assets.push_back(j[i]["assets"][k]["name"].str()); /* (2.1) Versions */
        out.push_back(r);
    }
    return out.empty() ? -1 : 1;
}
std::string slot_target(const App &a, const Slot &s) { return a.data_dir + "/" + s.to; }
bool slot_ready(const App &a, const Slot &) { const char *r = std::getenv("PREVIEW_READY"); return r && a.id == r; }
std::string slot_source(const App &a, const Slot &s)
{
    const char *r = std::getenv("PREVIEW_READY");
    if (!r || a.id != r) return "";
    return s.kind == "stfs" ? "/data/recompstore/updates/415608B2/d8b9fb4dc4bb36e0b07c4842bde4cb5612e9b5e7" : "/mnt/usb0/Games/SmackDown vs Raw 2011.iso";
}
void Engine::acknowledge() {}
/* (1.1.0) PREVIEW_REDEEM: the state a code is in (1 checking). */
bool Engine::redeem(const std::string &) { return true; }
/* (2.0) The title ID guard and Refresh. PREVIEW_MOVED: the ID SvR 2011 shows as moved to. */
bool Engine::resolve_clash(const std::string &, int, const std::string &, const std::string &) { return true; }
void Engine::refresh() {}
std::string moved_id(const std::string &id) { const char *m = std::getenv("PREVIEW_MOVED"); return m && id == "PPSA92011" ? m : ""; }
std::string installed_id(const std::string &id) { const std::string m = moved_id(id); return m.empty() ? id : m; }
long long installed_time(const std::string &id) { perf_cost("installed_time"); long long h = 0; for (char c : id) h = h * 31 + c; return 1700000000 + (h & 0xFFFF); }
int Engine::redeem_state(std::string &m, std::string &a) const { m.clear(); a.clear(); const char *e = std::getenv("PREVIEW_REDEEM"); return e ? std::atoi(e) : 0; }
void Engine::redeem_ack() {}
/* (1.1.0) Send logs: PREVIEW_LOGS the state (1 sending). */
bool Engine::send_logs(const std::string &, const std::string &) { return true; }
int Engine::logs_state(std::string &m) const { m.clear(); const char *e = std::getenv("PREVIEW_LOGS"); return e ? std::atoi(e) : 0; }
void Engine::logs_ack() {}
std::vector<LogFile> game_log_files(const App &a)
{
    std::vector<LogFile> out{{"/data/recompstore/logs/store.log", "store.log", 212000, 0}};
    if (a.id == "PPSA92011")
    {
        out.push_back({"", "UserData/crashes/crash_2026-10-17_141502.txt", 3400, 0});
        out.push_back({"", "UserData/rexglue.log", 88000, 0});
    }
    return out;
}
std::string Engine::installed(const std::string &id) const
{
    perf_cost("installed");
    if (const char *e = std::getenv("PREVIEW_INSTALLED"))
    {
        /* (2.0) A list: "PPSA92011=1.0,PPSA69421=0.9" (each one's recorded version). */
        const std::string list = std::string(",") + e + ",";
        const std::size_t at = list.find("," + id + "=");
        if (at != std::string::npos)
        {
            const std::size_t v = at + id.size() + 2;
            return list.substr(v, list.find(',', v) - v);
        }
    }
    if (const char *e = std::getenv("PREVIEW_INSTALLED"))
        if (id == e || (std::string(e) == "all" && id.rfind("soon", 0) != 0))
        {
            const char *as = std::getenv("PREVIEW_INSTALLED_AS"); /* the version recorded, as an install writes it */
            return as ? as : "1.0";
        }
    return id == "PPSA92011" ? "v1.0" : id == "PPSA92007" ? "v0.9.0" : "";
}
/* (2.0) Link a phone. PREVIEW_LINK: the code's state (0 none, 1 asking, 2 here, 3 refused);
 * PREVIEW_DEVICES: how many phones are linked. */
void Engine::link_screen(bool) {}
void Engine::link_new_code() {}
LinkInfo Engine::link_info() const
{
    LinkInfo i;
    const char *st = std::getenv("PREVIEW_LINK");
    i.code_state = st ? std::atoi(st) : 2;
    if (i.code_state == 2) { i.code = "K7Q4X2"; i.url = "https://recompstore.com/l/K7Q4X2"; i.expires_in = 541; }
    if (i.code_state == 3) i.error = "recompstore.com didn't answer. Check your connection and try again.";
    const char *nd = std::getenv("PREVIEW_DEVICES");
    const int n = nd ? std::atoi(nd) : 0;
    const char *labels[] = {"iPhone \xC2\xB7 Safari", "Windows PC \xC2\xB7 Chrome", "Android \xC2\xB7 Chrome", "iPad \xC2\xB7 Safari", "Mac \xC2\xB7 Firefox"};
    const char *seen[] = {"2026-10-19T19:01:00Z", "2026-10-19T12:30:00Z", "2026-10-16T09:00:00Z", "2026-10-19T19:03:00Z", "2026-10-02T09:00:00Z"};
    for (int k = 0; k < n && k < 5; ++k) i.devices.push_back({"d" + std::to_string(k), labels[k], "2026-10-1" + std::to_string(k + 2), seen[k]});
    i.known = true;
    return i;
}
void Engine::link_unlink(const std::string &) {}
bool Engine::take_link_action(LinkAction &) { return false; }
std::string Engine::install_id() const { return "3fa9c1d2e8b04a17"; }
std::string Engine::logs_report(std::string &url) const
{
    const char *r = std::getenv("PREVIEW_REPORT");
    if (!r) { url.clear(); return ""; }
    url = std::string("https://recompstore.com/r/") + r;
    return r;
}
/* (2.0) Sources: PREVIEW_SOURCES, a JSON list of {kind, value, name, state, apps, error, problems}. */
std::vector<Source> Engine::sources() const
{
    std::vector<Source> out;
    const char *p = std::getenv("PREVIEW_SOURCES");
    if (!p) return out;
    std::string text;
    if (std::FILE *f = std::fopen(p, "rb"))
    {
        char b[8192]; std::size_t n;
        while ((n = std::fread(b, 1, sizeof b, f)) > 0) text.append(b, n);
        std::fclose(f);
    }
    Json j;
    if (!Json::parse(text, j)) return out;
    for (std::size_t i = 0; i < j.size(); ++i)
    {
        Source s;
        s.kind = j[i]["kind"].str("repo"); s.value = j[i]["value"].str(); s.name = j[i]["name"].str();
        s.state = int(j[i]["state"].integer(2)); s.apps = std::size_t(j[i]["apps"].integer(0)); s.error = j[i]["error"].str();
        for (std::size_t k = 0; k < j[i]["problems"].size(); ++k) s.problems.push_back(j[i]["problems"][k].str());
        out.push_back(s);
    }
    return out;
}
bool Engine::add_source(const std::string &, std::string &) { return true; }
void Engine::remove_source(const std::string &) {}
bool Engine::report_source(const std::string &, const std::string &) { return true; }
int Engine::source_report_state(std::string &m) const { m.clear(); return 0; }
void Engine::source_report_ack() {}
/* (2.0) Charts: PREVIEW_CHARTS, a charts.php answer. */
Charts Engine::charts() const
{
    Charts c;
    const char *p = std::getenv("PREVIEW_CHARTS");
    if (!p) return c;
    std::string text;
    if (std::FILE *f = std::fopen(p, "rb"))
    {
        char b[8192]; std::size_t n;
        while ((n = std::fread(b, 1, sizeof b, f)) > 0) text.append(b, n);
        std::fclose(f);
    }
    Json j;
    if (!Json::parse(text, j)) return c;
    auto ids = [&](const Json &a, std::vector<std::string> &v) {
        for (std::size_t i = 0; i < a.size(); ++i) v.push_back(a[i].is_object() ? a[i]["id"].str() : a[i].str());
    };
    ids(j["top"], c.top); ids(j["trending"], c.trending); ids(j["new"], c.fresh); ids(j["updated"], c.updated);
    for (const auto &kv : j["stats"].members()) /* (2.1) a part of the store sorted by installs */
    {
        ChartStat st;
        st.installs = kv.second["installs"].integer(0);
        st.stars = kv.second["stars"].integer(-1);
        c.stats.push_back({kv.first, st});
    }
    for (const auto &kv : j["likes"].members()) c.likes.push_back({kv.first, kv.second.integer(0)}); /* (2.3.0) */
    std::stable_sort(c.likes.begin(), c.likes.end(), [](const auto &a, const auto &b) { return a.second > b.second; });
    for (const auto &kv : j["also"].members()) /* (2.2.2) people also installed */
    {
        std::vector<std::string> v;
        ids(kv.second, v);
        if (!v.empty()) c.also.push_back({kv.first, v});
    }
    c.have = true;
    return c;
}
bool Engine::tester() const { return std::getenv("PREVIEW_TESTER") != nullptr; }
bool Engine::test_view() const { return true; }
void Engine::set_test_view(bool) {}
std::string own_id(const std::string &id) { return id; }
static std::string g_root = "/data/homebrew";
std::vector<Place> Engine::install_places() const
{
    /* PREVIEW_USB_FREE_MB: a nearly full USB drive. */
    std::uint64_t usb_free = 702ull << 30;
    if (const char *f = std::getenv("PREVIEW_USB_FREE_MB")) usb_free = std::uint64_t(std::atoll(f)) << 20;
    return {{"Console storage", "/data/homebrew", 92ull << 30, 825ull << 30}, {"M.2 SSD", "/mnt/ext1/homebrew", 1210ull << 30, 1863ull << 30},
            {"USB drive 1", "/mnt/usb0/homebrew", usb_free, 931ull << 30}};
}
std::string Engine::install_root() const { if (const char *r = std::getenv("PREVIEW_ROOT")) return r; return g_root; }
void Engine::set_install_root(const std::string &r) { g_root = r; }
Engine &engine() { static Engine e; return e; }
/* (2.3.0) */
bool Engine::start_receiver(const ReceiverJob &, std::string &code, std::string &why) { code = "482913"; why.clear(); return true; }
void Engine::stop_receiver() {}
ReceiverStatus Engine::receiver_status() const { return ReceiverStatus(); }
bool Engine::space_of(const std::string &, std::uint64_t &t, std::uint64_t &a) { t = 825ull << 30; a = 92ull << 30; return true; }
std::vector<Backup> Engine::backups(const std::string &) const { return {}; }
bool Engine::saves_here(const std::string &) const { return true; }
bool Engine::backup_saves(const std::string &, bool) { return true; }
bool Engine::restore_saves(const std::string &, const std::string &) { return true; }
bool Engine::delete_backup(const std::string &, const std::string &, std::string &) { return true; }
std::string Engine::backups_dir() const { return "/data/recompstore/backups"; }
std::string Engine::usb_drive() const { return getenv("PREVIEW_USB") ? "/mnt/usb0" : ""; }
bool Engine::remove(const std::string &, bool, bool) { return true; }
bool Engine::manage(const std::string &, const std::string &, const std::string &, const std::string &) { return true; }
bool Engine::clear_downloads_start() { return true; }
int Engine::clear_state(std::uint64_t &f, std::size_t &d, std::size_t &t, std::string &w) const { f = 0; d = t = 0; w.clear(); return 0; }
void Engine::clear_ack() {}
int Engine::loader_seen() { return 1; }
void Engine::like(const std::string &, bool) {}
bool Engine::liked(const std::string &id) const { return id == "PPSA92011"; }
long long Engine::like_count(const std::string &id) const { return id == "PPSA92011" ? 128 : 0; }
void Engine::link_result(long long, const std::string &, const std::string &, const std::string &, const std::string &) {}
void Engine::link_push_soon() {}
void Engine::set_phone_prefs(const std::vector<std::string> &, const std::vector<std::string> &, bool) {}
bool Engine::read_my_apps(const std::string &, MyAppsList &, std::string &why) { why = "preview"; return false; }
bool Engine::send_logs(const std::string &, const std::string &, const std::string &, const std::string &, const std::string &) { return true; }
int Engine::my_apps_lists(std::vector<MyAppsList> &out, std::string &why) const { out.clear(); why.clear(); return 0; }
bool Engine::save_my_apps(const std::string &, std::string &) const { return true; }
void Engine::ask_my_apps() {}
std::string Engine::my_apps_json() const { return "{}"; }
int Engine::mod_state(const App &, const App::Mod &m) const { return m.id == "hd" ? 1 : m.id == "fix" ? 2 : 0; }
bool Engine::mod_job(const std::string &, const std::string &, const std::string &) { return true; }
} // namespace recomp

namespace porpoise::pad { std::int32_t user_of(int) { return 1; } }
namespace porpoise::sound
{
bool load(const std::string &) { return true; }
void play(Effect) {}
void set_music(bool, float) {}
void set_effects(bool, float) {}
void pump() {}
}
