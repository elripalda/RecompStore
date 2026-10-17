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
    for (App &a : g_cat.apps) { a.icon_file = local(a.icon_url); a.hero_file = local(a.hero_url); a.logo_file = local(a.logo_url); }
    for (App &a : g_cat.soon) { a.icon_file = local(a.icon_url); a.hero_file = local(a.hero_url); a.logo_file = local(a.logo_url); }
}
} // namespace
void Engine::start(const std::string &) { load(); }
bool Engine::catalog(Catalog &out) const { load(); out = g_cat; return true; }
unsigned Engine::revision() const { return 1; }
bool Engine::online() const { return true; }
bool Engine::loading() const { return false; }
bool Engine::install(const std::string &) { return true; }
bool Engine::bench() { return true; }
bool Engine::remove(const std::string &, bool) { return true; }
static std::string g_smp;
std::string smp_path() { if (const char *e = std::getenv("PREVIEW_SMP")) return e; return g_smp; }
void set_smp_path(const std::string &p) { g_smp = p; }
int refresh_smp(std::string &) { return 2; }
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
    if (g_job_kind == "install") { j.phase = Phase::Working; j.kind = "install"; j.app_id = g_job_app; j.step = "Downloading"; j.done = 312ull << 20; j.total = 476ull << 20; }
    if (g_job_kind == "fetch") { j.phase = Phase::Working; j.kind = "setup"; j.slot = "update"; j.app_id = g_job_app; j.step = "Downloading the update"; j.done = 150ull << 10; j.total = 240ull << 10; }
    if (g_job_kind == "setup") { j.phase = Phase::Working; j.kind = "setup"; j.slot = "game"; j.app_id = g_job_app; j.step = "Copying the game's files"; j.done = 2900ull << 20; j.total = 7300ull << 20; }
    return j;
}
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
    if (const char *e = std::getenv("PREVIEW_INSTALLED"))
        if (id == e || (std::string(e) == "all" && id.rfind("soon", 0) != 0))
        {
            const char *as = std::getenv("PREVIEW_INSTALLED_AS"); /* the version recorded, as an install writes it */
            return as ? as : "1.0";
        }
    return id == "PPSA92011" ? "v1.0" : id == "PPSA92007" ? "v0.9.0" : "";
}
Engine &engine() { static Engine e; return e; }
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
