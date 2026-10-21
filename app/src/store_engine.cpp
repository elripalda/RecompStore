/* Recomp Store - the catalog, downloads and installs.
 * The zip reading and SHA-256 come from Porpoise's updater (porpoise_update.cpp).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_engine.hpp"
#include "store_backup.hpp"
#include "store_fs.hpp"

#include "porpoise_http.hpp"
#include "store_disc.hpp"
#include "store_json.hpp"
#include "store_zip.hpp"
#include "store_stfs.hpp"
#include "store_sha1.hpp"
#include "store_sha256.hpp"
#include "rs_sig.h"
#include "title_threads.hpp"
#include "ui_app_common.hpp"
#include "porpoise_paths.hpp"

#include <algorithm>
#include <atomic>
#include <sched.h>
#include <cctype>
#include <dirent.h>
#include <cerrno>
#include <ctime>
#include <cstdio>
#include <cstdarg>
#include <cstddef>
#include <cstdlib>
#include <map>
#include <memory>
#include <cstring>
#include <mutex>
#include <set>
#include <condition_variable>
#include <fcntl.h>
#include <pthread.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <sys/stat.h>
#include <sys/statvfs.h>
#include <unistd.h>


namespace recomp
{
void slog(const char *fmt, ...);
void load_queue(); /* (2.2.2) */
bool data_lost(int err);
void data_trouble(const std::string &path, int err);
static bool title_id_ok(const std::string &id);
static bool custom_owns(const std::string &dir, const std::string &repo);
namespace
{
void scan_homebrew();
/* SHA-256: store_sha256.hpp. */

/* ---- Files ---------------------------------------------------------------------- */
bool read_file(const std::string &path, std::string &out)
{
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return false;
    out.clear();
    char buf[16384];
    std::size_t n;
    while ((n = std::fread(buf, 1, sizeof buf, f)) > 0) out.append(buf, n);
    std::fclose(f);
    return true;
}

bool read_bytes(const std::string &path, std::vector<std::uint8_t> &out)
{
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return false;
    out.clear();
    struct stat st;
    if (stat(path.c_str(), &st) == 0 && st.st_size > 0) out.reserve(std::size_t(st.st_size)); /* a ROM: one buffer, not twice its size while it grows */
    std::uint8_t buf[65536];
    std::size_t n;
    while ((n = std::fread(buf, 1, sizeof buf, f)) > 0) out.insert(out.end(), buf, buf + n);
    std::fclose(f);
    return true;
}

bool write_file(const std::string &path, const void *data, std::size_t n)
{
    /* (2.0) Its own temporary file each time: two threads saving one file (sources.json,
     * beta.json) never write into the same one; the last rename wins, whole. */
    static std::atomic<unsigned> serial{0};
    const std::string tmp = path + ".part" + std::to_string(++serial);
    std::FILE *f = std::fopen(tmp.c_str(), "wb");
    if (!f)
    {
        data_trouble(path, errno); /* (2.2.1) the store's own folder gone? */
        return false;
    }
    const bool ok = std::fwrite(data, 1, n, f) == n;
    std::fflush(f);
    fsync(fileno(f));
    std::fclose(f);
    if (!ok || std::rename(tmp.c_str(), path.c_str()) != 0)
    {
        std::remove(tmp.c_str());
        return false;
    }
    return true;
}

void make_dirs(const std::string &path)
{
    for (std::size_t i = 1; i <= path.size(); ++i)
        if (i == path.size() || path[i] == '/')
        {
            const std::string d = path.substr(0, i);
            if (mkdir(d.c_str(), 0777) == 0) chmod(d.c_str(), 0777);
        }
}

bool exists(const std::string &path)
{
    struct stat st;
    return stat(path.c_str(), &st) == 0;
}

/* A name from a zip that can only land inside the folder: no absolute paths, no "..". */
bool safe_name(const std::string &n)
{
    if (n.empty() || n[0] == '/' || n[0] == '\\' || n.find('\\') != std::string::npos || n.find(':') != std::string::npos)
        return false;
    std::size_t at = 0;
    while (at <= n.size())
    {
        std::size_t end = n.find('/', at);
        if (end == std::string::npos) end = n.size();
        const std::string part = n.substr(at, end - at);
        if (part == "..") return false;
        at = end + 1;
    }
    return true;
}

std::string version_key(std::string v)
{
    /* "v1.0", "V.1.0", "1.0" and "1.0.0" are the same version. */
    v.erase(0, version_start(v));
    while (v.size() > 2 && v.compare(v.size() - 2, 2, ".0") == 0 && std::count(v.begin(), v.end(), '.') > 0)
        v.erase(v.size() - 2);
    return v;
}

std::string art_name(const std::string &url)
{
    unsigned long long hsh = 1469598103934665603ULL;
    for (unsigned char c : url) hsh = (hsh ^ c) * 1099511628211ULL;
    char b[32];
    std::snprintf(b, sizeof b, "%016llx", hsh);
    const std::size_t dot = url.find_last_of('.');
    std::string ext = dot == std::string::npos ? ".img" : url.substr(dot);
    if (ext.size() > 5) ext = ".img";
    return std::string(b) + ext;
}

/* ---- State shared with the screens ---------------------------------------------- */
struct Shared
{
    mutable std::mutex m;
    std::string data;
    Catalog catalog;
    bool have_catalog = false, online = false, loading = true;
    /* (2.1) Why the last catalog from the site wasn't taken (its signature), "" when it was. */
    std::string catalog_note;
    unsigned revision = 0;
    JobState job;
    std::string pending_install; /* asked for, not yet started */
    int pending_resolve = 0;     /* (2.0) with it: the answer to a title ID clash (resolve_clash) */
    std::string pending_version; /* (2.1) with it: the version the player picked, "" the newest */
    /* (2.1) Apps kept at an older version the player picked (installed/<id>.hold): that version. */
    std::map<std::string, std::string> holds;
    std::string pending_setup_app, pending_setup_slot, pending_setup_image;
    bool pending_setup_fetch = false; /* (1.0.8) the slot's Title Update, downloaded first */
    bool pending_bench = false;
    std::string pending_delete;
    bool pending_delete_keep = false; /* the game's saves stay */
    bool started = false;
    /* A newer Recomp Store on GitHub ("" when this is the newest), and its download once
     * the store has fetched and checked it for the installer. */
    std::string store_update;
    bool pending_store_dl = false;
    /* Installed from a copy built into an installer that couldn't reach GitHub: the store
     * updates itself to the newest as soon as it has found it (selfupdate/autoupdate). */
    bool auto_update = false;
    /* Settings > Check for updates: asked (the worker checks everything now), and how it went. */
    bool force_check = false;
    bool rescan = false; /* (2.0) Refresh: the console's apps looked for again too */
    int update_check = 0;
    std::string store_zip, store_zip_sha, store_zip_ver;
    /* Patch notes by repo, and when each was last asked of GitHub (mono seconds). */
    std::map<std::string, std::vector<Release>> notes;
    std::map<std::string, double> notes_at;
    std::set<std::string> notes_failed, notes_wanted;
    /* (1.1.0) Beta codes: this console's ID and token on recompstore.com (beta.json), the
     * beta games it has (beta-apps.json, the site's last list), and a code being redeemed
     * (redeem: 0 none, 1 checking, 2 unlocked, 3 refused). */
    std::string beta_install, beta_token;
    std::vector<App> beta_apps;
    std::string pending_redeem;
    int redeem = 0;
    std::string redeem_msg, redeem_app;
    /* (1.1.0) Send logs: the game and message waiting to go, and how it went (logs: 0 none,
     * 1 sending, 2 sent, 3 didn't go). */
    bool pending_logs = false;
    std::string pending_logs_app, pending_logs_msg;
    std::string pending_logs_extra; /* (2.3.0) &category=...&feedback=...&build=... */
    int logs = 0;
    std::string logs_msg;
    std::string logs_report, logs_report_url; /* (2.0) the report to finish on a phone */
    /* (2.0) Link a phone: whether its screen is open, a code asked for, a poll wanted now,
     * devices to let go, what the site last said, and what phones sent (for the screens). */
    bool link_open = false, link_want_code = false, link_poll_now = false, link_hello = false;
    std::vector<std::string> link_unlinks;
    LinkInfo link;
    double link_code_until = 0;
    std::vector<LinkAction> link_actions;
    /* (2.0) Custom sources (sources.json, in the order added), each one's apps as the site
     * last gave them (sources/<key>.json), the ones to read now, and a report being sent
     * (src_report as logs). */
    std::vector<Source> sources;
    std::map<std::string, std::vector<App>> source_apps; /* by Source::value */
    std::set<std::string> sources_due;
    bool pending_src_report = false;
    std::string src_report_value, src_report_why, src_report_msg;
    int src_report = 0;
    /* (2.0) The charts; whether the admin marked this console as a tester, and whether it
     * looks at the test catalog (testview: on unless the player turned it off). */
    Charts charts;
    bool tester = false, test_view = true;
    /* (2.0) Install counts for the charts, to tell the site: "+id version" or "-id". */
    std::vector<std::string> stats_due;
    /* (2.0) When to ask ShadowMountPlus again to look for new games (monotonic seconds). */
    std::vector<long long> smp_scan_at;
    /* (2.0) Art still to fetch, left for a job that came first. */
    bool art_more = false;
    /* (2.0) Title IDs the site says are taken (ids.json): never given to a moved app. */
    std::set<std::string> taken_ids;
    /* (2.0) The custom sources' apps in the catalog now, and each one's repo: whose installs
     * they can call theirs (Engine::installed). */
    std::map<std::string, std::string> custom_repos;
    /* (2.2) Library > On this PS5: every folder in the homebrew folders as last looked, whether
     * a look is going (and another asked for while it went), and ShadowMountPlus as last asked. */
    std::vector<ConsoleApp> console;
    unsigned console_rev = 0;
    bool console_looking = false, console_again = false;
    int smp_state = -1;
    std::string smp_version;
    /* (2.2) An App manager job asked for (Engine::manage), not yet started. */
    std::string pending_manage, pending_manage_folder, pending_manage_dest;
    /* (2.2.2) With pending_install: the homebrew folder picked for it. The install's download to
     * stop (cancel_job). The download queue (queue.txt) and the app it started. Folders to delete
     * at once (manage_many), ShadowMountPlus to get (get_smp). The drives as last looked, and the
     * homebrew folders ShadowMountPlus says can be installed to (smp_dests_known: it said). */
    std::string pending_root;
    bool cancel_wanted = false;
    std::vector<QueueItem> queue;
    std::string queue_now;
    std::vector<std::string> pending_mdeletes;
    bool pending_smpget = false;
    std::vector<DriveSpace> drives;
    std::vector<std::string> smp_dests;
    bool smp_dests_known = false;
    unsigned games_checks = 0; /* (2.2.2) how many times the installed apps' updates were looked for */
    /* (2.3.0) A job of 2.3.0's (store_engine_23.inc): "backup", "restore", "mod"; its arguments. And
     * the app whose saves are backed up before a delete or a move. */
    std::string pending_task;
    std::vector<std::string> pending_task_args;
    std::string pending_backup_first;
};
Shared g;

/* ---- The log: trace.txt, a file that lasts between launches, and the last lines for
 * Settings > Diagnostics. ------------------------------------------------------------ */
struct LogState
{
    std::mutex m;
    std::vector<std::string> lines; /* the newest last */
    std::string file;
    std::map<std::string, std::string> stats;
};
LogState &logs()
{
    static LogState *l = new LogState();
    return *l;
}
} // namespace

void slog(const char *fmt, ...)
{
    char body[1024];
    va_list ap;
    va_start(ap, fmt);
    std::vsnprintf(body, sizeof body, fmt, ap);
    va_end(ap);
    std::string line = body;
    while (!line.empty() && (line.back() == '\n' || line.back() == '\r')) line.pop_back();
    const std::time_t now = std::time(nullptr);
    std::tm tm{};
    localtime_r(&now, &tm);
    char stamp[16];
    std::strftime(stamp, sizeof stamp, "%H:%M:%S", &tm);
    std::fprintf(stderr, "[store] %s\n", line.c_str());
    LogState &L = logs();
    std::lock_guard<std::mutex> lk(L.m);
    L.lines.push_back(std::string(stamp) + "  " + line);
    if (L.lines.size() > 300) L.lines.erase(L.lines.begin(), L.lines.begin() + 100);
    if (!L.file.empty())
        if (std::FILE *f = std::fopen(L.file.c_str(), "a"))
        {
            char day[32];
            std::strftime(day, sizeof day, "%Y-%m-%d %H:%M:%S", &tm);
            std::fprintf(f, "%s  %s\n", day, line.c_str());
            std::fclose(f);
        }
}

/* ---- (2.2.1) The store's own folder, gone mid-session ---------------------------------
 * On a console where ShadowMountPlus gives the store /data (a writable nullfs alias in its
 * sandbox), a second ShadowMountPlus started while the store is open stops the first, whose exit
 * removes the aliases of every app that's open; the new one only makes them for apps started
 * after it. The store then can't open its own folder until it's opened again. Nothing it does
 * from here can work, so it says so once, rather than "0 folders" or "couldn't write". */
namespace
{
std::atomic<int> g_data_state{0}; /* 0 not known to work, 1 worked when the store started, 2 lost since */
std::atomic<long long> g_data_look_ms{0};

bool data_gone_now()
{
    DIR *d = opendir(g.data.c_str());
    if (d)
    {
        closedir(d);
        return false;
    }
    return errno == EACCES || errno == ENOENT || errno == EPERM;
}

/* At the start: the folder opens, and a file can be written there. */
void data_works_now(const std::string &dir)
{
    const std::string probe = dir + "/.write-test";
    std::FILE *f = std::fopen(probe.c_str(), "wb");
    if (!f) return;
    std::fclose(f);
    std::remove(probe.c_str());
    if (DIR *d = opendir(dir.c_str()))
    {
        closedir(d);
        g_data_state = 1;
    }
}
} // namespace

/* err 0: a look, every 2 seconds at most; -1: a look now; else the errno of something the store
 * just failed to write or open in its folder (only EACCES, ENOENT, EPERM count). */
bool data_lost(int err)
{
    const int st = g_data_state.load();
    if (st != 1) return st == 2;
    if (err > 0 && err != EACCES && err != ENOENT && err != EPERM) return false;
    timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    const long long now = (long long)ts.tv_sec * 1000 + ts.tv_nsec / 1000000;
    if (err == 0 && now - g_data_look_ms.load() < 2000) return false;
    g_data_look_ms = now;
    bool gone = data_gone_now();
    const int why = errno;
    if (!gone && err > 0)
    {
        /* It opens, but nothing can be written there either. */
        const std::string probe = g.data + "/.write-test";
        std::FILE *f = std::fopen(probe.c_str(), "wb");
        if (f)
        {
            std::fclose(f);
            std::remove(probe.c_str());
        }
        else
            gone = errno == EACCES || errno == ENOENT || errno == EPERM;
    }
    int was = 1;
    if (gone && g_data_state.compare_exchange_strong(was, 2))
        slog("the store can't open its folder %s any more (errno %d): ShadowMountPlus restarted while it was open? "
             "It needs to be closed and opened again", g.data.c_str(), why);
    return gone;
}

void data_trouble(const std::string &path, int err)
{
    if (g_data_state.load() == 1 && !g.data.empty() && path.compare(0, g.data.size(), g.data) == 0) data_lost(err > 0 ? err : -1);
}

bool Engine::storage_lost() const { return data_lost(0); }

void set_stat(const std::string &key, const std::string &value)
{
    LogState &L = logs();
    std::lock_guard<std::mutex> lk(L.m);
    L.stats[key] = value;
}

std::string any_case_path(const std::string &dir, const std::string &rel);
bool at_size_any_case(const std::string &dir, const std::string &rel, std::uint64_t size);
/* (2.2) The App manager's jobs (Engine::manage), run by the worker; defined with it below. */
void run_manage(const std::string &kind, const std::string &folder, const std::string &dest);
void run_manage_many(const std::vector<std::string> &folders); /* (2.2.2) */
void run_get_smp();                                         /* (2.2.2) */
void run_task(const std::string &task, const std::vector<std::string> &args); /* (2.3.0) store_engine_23.inc */
bool run_backup_first(const std::string &app_id);                            /* (2.3.0) */

namespace
{
/* (2.2.4) The real space where path is (below, with ShadowMountPlus's storage figures). */
bool real_space(const std::string &path, std::uint64_t &total, std::uint64_t &avail, bool &from_smp, bool may_ask = true);

void set_job(const std::string &step, std::uint64_t done, std::uint64_t total)
{
    std::lock_guard<std::mutex> l(g.m);
    g.job.step = step;
    g.job.done = done;
    g.job.total = total;
}

void fail_job(const std::string &why)
{
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Failed;
    g.job.error = why;
    slog("%s failed: %s\n", g.job.kind.c_str(), why.c_str());
}

/* ---- The catalog ------------------------------------------------------------------ */
void load_beta();
void merge_beta(Catalog &c);
void apply_moves(Catalog &c);
void load_sources();
bool parse_charts(const std::string &text, Charts &out);
bool parse_ids(const std::string &text, std::set<std::string> &out);
std::string json_text(const std::string &s);
void adopt_renamed(Catalog &c);

void load_cached()
{
    load_beta();
    load_sources();
    std::string text;
    Catalog c;
    {
        /* (2.0) The charts and the taken title IDs, as last read. */
        Charts ch;
        if (read_file(g.data + "/charts.json", text) && parse_charts(text, ch))
        {
            std::lock_guard<std::mutex> l(g.m);
            g.charts = ch;
        }
        std::set<std::string> ids;
        if (read_file(g.data + "/ids.json", text) && parse_ids(text, ids))
        {
            std::lock_guard<std::mutex> l(g.m);
            g.taken_ids.swap(ids);
        }
    }
    /* (2.0) A tester's console: the test catalog it last read, when it has one. */
    bool test = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        test = g.tester && g.test_view;
    }
    if ((test && read_file(g.data + "/catalog-test.json", text) && parse_catalog(text, c)) ||
        (read_file(g.data + "/catalog.json", text) && parse_catalog(text, c)))
    {
        std::lock_guard<std::mutex> l(g.m);
        g.catalog = c;
        merge_beta(g.catalog);
        g.have_catalog = true;
        ++g.revision;
    }
}

void attach_art(Catalog &c)
{
    for (std::vector<App> *list : {&c.apps, &c.soon})
        for (App &a : *list)
        {
            if (!a.icon_url.empty() && exists(g.data + "/art/" + art_name(a.icon_url)))
                a.icon_file = g.data + "/art/" + art_name(a.icon_url);
            if (!a.hero_url.empty() && exists(g.data + "/art/" + art_name(a.hero_url)))
                a.hero_file = g.data + "/art/" + art_name(a.hero_url);
            if (!a.logo_url.empty() && exists(g.data + "/art/" + art_name(a.logo_url)))
                a.logo_file = g.data + "/art/" + art_name(a.logo_url);
        }
}

std::string beta_form(const char *action, const std::string &more);

/* (2.0) The catalog this console saved last, the test one or everyone's: straight away when
 * it stops (or starts) being a tester or the player switches, before the site is asked. */
void use_cached_catalog(bool test)
{
    std::string text;
    Catalog c;
    if (!((test && read_file(g.data + "/catalog-test.json", text) && parse_catalog(text, c)) ||
          (!test && read_file(g.data + "/catalog.json", text) && parse_catalog(text, c))))
        return;
    std::lock_guard<std::mutex> l(g.m);
    g.catalog = c;
    merge_beta(g.catalog);
    attach_art(g.catalog);
    g.have_catalog = true;
    ++g.revision;
}

/* ---- (2.1) Signatures from Recomp Store Signer (rs_sig.h) ------------------------------ */
std::string sha256_hex(const std::string &bytes)
{
    Sha256 h;
    h.update(reinterpret_cast<const std::uint8_t *>(bytes.data()), bytes.size());
    return h.hex();
}

/* "" when sigtext is a good signature of the given kind for bytes with this SHA-256, made with
 * one of the keys built into the store; otherwise what's wrong, in words. */
std::string sig_problem(const std::string &sigtext, const char *kind, const std::string &sha_hex, std::int64_t *signed_at = nullptr)
{
    if (sigtext.empty()) return "it isn't signed";
    rs_sig s;
    if (!rs_sig_parse(sigtext.data(), sigtext.size(), &s)) return "its signature is damaged";
    if (!rs_sig_check(&s, kind, sha_hex.c_str())) return "its signature doesn't match it";
    if (signed_at) *signed_at = s.signed_unix;
    return "";
}

/* When a saved signature was made (0: none). */
std::int64_t saved_sig_time(const std::string &path)
{
    std::string text;
    rs_sig s;
    if (!read_file(path, text) || !rs_sig_parse(text.data(), text.size(), &s)) return 0;
    return s.signed_unix;
}

/* (2.1) A catalog and its signature from the site. "" when they check out and aren't older than
 * the one this console already has; the reason otherwise. The catalog changes between the two
 * requests now and then (a publish), so a mismatch is asked again once. */
std::string fetch_signed_catalog(porpoise::http::Session &http, bool test, std::vector<std::uint8_t> &body, std::string &sigtext, int &status)
{
    std::string why;
    for (int attempt = 0; attempt < 2; ++attempt)
    {
        body.clear();
        std::vector<std::uint8_t> sb;
        int sst = 0;
        if (test)
        {
            const std::string form = beta_form("test", "");
            status = http.request(kTestCatalogUrl, &form, body, 4u << 20);
            if (status != 200) return "HTTP " + std::to_string(status);
            const std::string sform = beta_form("testsig", "");
            sst = http.request(kTestCatalogUrl, &sform, sb, 64u << 10);
        }
        else
        {
            status = http.get(kCatalogUrl, body, {}, 4u << 20);
            if (status != 200) return "HTTP " + std::to_string(status);
            sst = http.get(kCatalogSigUrl, sb, {}, 64u << 10);
        }
        sigtext.assign(sb.begin(), sb.end());
        if (sst != 200) sigtext.clear();
        std::int64_t when = 0;
        why = sig_problem(sigtext, test ? "catalog-test" : "catalog", sha256_hex(std::string(body.begin(), body.end())), &when);
        if (why.empty())
        {
            const std::int64_t had = saved_sig_time(g.data + (test ? "/catalog-test.json.sig" : "/catalog.json.sig"));
            if (when < had) return "it's older than the one this PS5 already has";
            return "";
        }
    }
    return why;
}

void refresh_catalog(porpoise::http::Session &http)
{
    bool test = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        test = g.tester && g.test_view && !g.beta_install.empty();
    }
    std::vector<std::uint8_t> body;
    std::string sigtext, why;
    Catalog c;
    int status = 0;
    bool from_test = false;
    if (test)
    {
        /* (2.0) A tester's console: the catalog with the testers-only apps in it. If the site
         * won't give it (no longer a tester), the one everyone gets. (2.1) Signed, like it. */
        why = fetch_signed_catalog(http, true, body, sigtext, status);
        from_test = why.empty() && parse_catalog(std::string(body.begin(), body.end()), c);
        if (!from_test) slog("catalog: the test catalog didn't come (%s); reading the public one", why.empty() ? "unreadable" : why.c_str());
        if (status == 403)
        {
            /* Not a tester any more: everyone's catalog from here on, the saved one first. */
            std::remove((g.data + "/tester").c_str());
            {
                std::lock_guard<std::mutex> l(g.m);
                g.tester = false;
                ++g.revision;
            }
            use_cached_catalog(false);
        }
    }
    if (!from_test) why = fetch_signed_catalog(http, false, body, sigtext, status);
    const std::string text(body.begin(), body.end());
    if (status == 200 && why.empty() && (from_test || parse_catalog(text, c)))
    {
        const std::string base = g.data + (from_test ? "/catalog-test.json" : "/catalog.json");
        write_file(base, text.data(), text.size());
        write_file(base + ".sig", sigtext.data(), sigtext.size());
        std::lock_guard<std::mutex> l(g.m);
        g.catalog = c;
        merge_beta(g.catalog);
        attach_art(g.catalog);
        g.have_catalog = true;
        g.online = true;
        g.catalog_note.clear();
        ++g.revision;
    }
    else if (status == 200)
    {
        /* (2.1) The site answered, but not with a catalog signed on the owner's PC: the one this
         * console trusted last stays. */
        std::lock_guard<std::mutex> l(g.m);
        g.online = true;
        g.catalog_note = "The store's list from recompstore.com couldn't be checked (" + (why.empty() ? std::string("it couldn't be read") : why) +
                         "), so the last one it trusted is shown.";
        ++g.revision;
    }
    slog("catalog%s: HTTP %d, %zu bytes, %zu games%s%s\n", from_test ? " (test)" : "", status, body.size(), c.apps.size(),
         why.empty() ? "" : ", not taken: ", why.c_str());
}

/* ---- Beta games (1.1.0) ------------------------------------------------------------- */
bool lower_hex(const std::string &s, std::size_t n)
{
    if (s.size() != n) return false;
    for (const char c : s)
        if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'))) return false;
    return true;
}

/* The catalog with this console's beta games in it, after its own games. A beta the
 * catalog has itself (it's out for everyone now) is the catalog's. g.m held. */
void merge_beta(Catalog &c)
{
    c.apps.erase(std::remove_if(c.apps.begin(), c.apps.end(), [](const App &a) { return a.beta; }), c.apps.end());
    for (const App &b : g.beta_apps)
    {
        bool there = false;
        for (const App &a : c.apps) there = there || a.id == b.id;
        for (const App &a : c.soon) there = there || a.id == b.id;
        if (!there) c.apps.push_back(b);
    }
    /* (2.0) The custom sources' apps, after the catalog's: an ID the catalog (or a source
     * before) has is theirs. */
    c.apps.erase(std::remove_if(c.apps.begin(), c.apps.end(), [](const App &a) { return a.custom; }), c.apps.end());
    std::set<std::string> ids;
    for (const App &a : c.apps) ids.insert(a.id);
    for (const App &a : c.soon) ids.insert(a.id);
    int order = 100000;
    std::size_t custom = 0;
    for (const Source &s : g.sources)
    {
        auto it = g.source_apps.find(s.value);
        if (it == g.source_apps.end()) continue;
        for (App a : it->second)
            if (custom < 300 && ids.insert(a.id).second) /* 300 in all, from every source */
            {
                ++custom;
                a.order = order++;
                c.apps.push_back(a);
            }
    }
    adopt_renamed(c);
    apply_moves(c); /* (2.0) games under a new title ID: their folders follow */
    g.custom_repos.clear();
    for (const App &a : c.apps)
        if (a.custom) g.custom_repos[a.id] = a.repo;
}

/* What the store kept: its ID and token on the site, and the beta games it had. */
void load_beta()
{
    std::string text;
    Json j;
    if (read_file(g.data + "/beta.json", text) && Json::parse(text, j))
    {
        const std::string install = j["install"].str(), token = j["token"].str();
        if (lower_hex(install, 16) && lower_hex(token, 32))
        {
            std::lock_guard<std::mutex> l(g.m);
            g.beta_install = install;
            g.beta_token = token;
        }
    }
    Catalog c;
    if (read_file(g.data + "/beta-apps.json", text) && parse_catalog(text, c))
    {
        for (App &a : c.apps) a.beta = true;
        std::lock_guard<std::mutex> l(g.m);
        g.beta_apps = c.apps;
    }
}

void set_beta(std::vector<App> apps)
{
    for (App &a : apps) a.beta = true;
    std::lock_guard<std::mutex> l(g.m);
    g.beta_apps = std::move(apps);
    merge_beta(g.catalog);
    attach_art(g.catalog);
    ++g.revision;
}

/* A value in a form: letters, digits and -._ as they are, the rest %XX. */
std::string form_value(const std::string &v)
{
    static const char *hex = "0123456789ABCDEF";
    std::string out;
    for (const unsigned char c : v)
        if (std::isalnum(c) || c == '-' || c == '.' || c == '_') out += char(c);
        else { out += '%'; out += hex[c >> 4]; out += hex[c & 15]; }
    return out;
}

/* A request to the site's beta API: what to do, plus this console's ID and token (none
 * before its first code) and the store's version (the admin sees which testers updated). */
std::string beta_form(const char *action, const std::string &more)
{
    std::string install, token;
    {
        std::lock_guard<std::mutex> l(g.m);
        install = g.beta_install;
        token = g.beta_token;
    }
    return std::string("a=") + action + more + "&install=" + form_value(install) + "&token=" + form_value(token) +
           "&store=" + form_value(kVersion);
}

/* The beta games this console has: at start, with each catalog refresh, and after a code.
 * Kept for when the console is offline. A console the site doesn't know has none; a code
 * that was cancelled takes its game off the list. */
void refresh_beta(porpoise::http::Session &http)
{
    {
        std::lock_guard<std::mutex> l(g.m);
        if (g.beta_install.empty()) return;
    }
    const std::string form = beta_form("list", "");
    std::vector<std::uint8_t> body;
    const int status = http.request(kBetaUrl, &form, body, 4u << 20);
    const std::string text(body.begin(), body.end());
    Json j;
    Catalog c;
    if (status == 200 && Json::parse(text, j) && j["ok"].boolean(false) && parse_catalog(text, c))
    {
        write_file(g.data + "/beta-apps.json", text.data(), text.size());
        set_beta(c.apps);
    }
    else if (status == 403)
    {
        std::remove((g.data + "/beta-apps.json").c_str());
        set_beta({});
    }
    slog("beta: list HTTP %d, %zu games\n", status, c.apps.size());
}

void redeem_done(int state, const std::string &message, const std::string &app)
{
    std::lock_guard<std::mutex> l(g.m);
    g.redeem = state;
    g.redeem_msg = message;
    g.redeem_app = app;
    ++g.revision;
}

/* Settings > Redeem a code: the site checks it, ties it to this console (making the
 * console an ID and token on its first code), and its game joins the beta list. */
void run_redeem(const std::string &code)
{
    porpoise::http::Session http("beta", "RecompStore/1.1");
    if (!http.init()) return redeem_done(3, "Your PS5 isn't online. Connect it and try again.", "");
    const std::string form = beta_form("redeem", "&code=" + form_value(code));
    std::vector<std::uint8_t> body;
    const int status = http.request(kBetaUrl, &form, body, 64u << 10);
    Json j;
    const bool answered = Json::parse(std::string(body.begin(), body.end()), j) && j.is_object();
    const bool ok = answered && j["ok"].boolean(false);
    const std::string id = ok ? j["game"]["id"].str() : std::string();
    slog("beta: redeem HTTP %d, %s%s\n", status, !answered ? "no answer" : ok ? "unlocked " : "refused", id.c_str());
    if (!answered)
        return redeem_done(3, status < 0 ? "recompstore.com didn't answer. Check your connection and try again."
                                         : "recompstore.com can't check codes right now. Try again in a moment.", "");
    if (!ok) return redeem_done(3, j["error"].str("That code didn't work."), "");
    if (j["linked"].boolean(false))
    {
        /* (2.0) A code the website made: a phone is linked to this console now. */
        {
            std::lock_guard<std::mutex> l(g.m);
            g.link_poll_now = true;
        }
        return redeem_done(2, j["message"].str("Your phone is linked."), "");
    }
    const std::string install = j["install"].str(), token = j["token"].str();
    if (lower_hex(install, 16) && lower_hex(token, 32))
    {
        const std::string text = "{\"install\":\"" + install + "\",\"token\":\"" + token + "\"}\n";
        if (!write_file(g.data + "/beta.json", text.data(), text.size()))
            slog("beta: couldn't save this console's ID in %s/beta.json\n", g.data.c_str());
        std::lock_guard<std::mutex> l(g.m);
        g.beta_install = install;
        g.beta_token = token;
    }
    refresh_beta(http);
    std::string message = j["message"].str();
    if (message.empty()) message = j["game"]["name"].str("The game") + " is unlocked. It's in your Library.";
    redeem_done(2, message, id);
}

/* ---- Send logs (1.1.0) ------------------------------------------------------------- */
/* A file's end, as text: at most max bytes, from the start of a line. */
std::string file_tail(const std::string &path, std::uint64_t size, std::uint64_t max)
{
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return std::string();
    const bool cut = size > max;
    if (cut) std::fseek(f, long(size - max), SEEK_SET);
    std::string out;
    out.resize(std::size_t(std::min(size, max)));
    out.resize(std::fread(out.data(), 1, out.size(), f));
    std::fclose(f);
    if (cut)
    {
        const std::size_t nl = out.find('\n');
        if (nl != std::string::npos && nl < 4096) out.erase(0, nl + 1);
        out = "[the first " + std::to_string((size - out.size()) / 1024) + " KB are left out]\n" + out;
    }
    return out;
}

void logs_done(int state, const std::string &message)
{
    std::lock_guard<std::mutex> l(g.m);
    g.logs = state;
    g.logs_msg = message;
    ++g.revision;
}

/* The store's log and the game's own log files, with the player's message and what's
 * installed, to recompstore.com/api/logs.php: the admin's inbox, under Logs. */
void run_send_logs(const std::string &id, const std::string &message, const std::string &extra)
{
    App a;
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const App &x : g.catalog.apps)
            if (x.id == id) a = x;
    }
    if (a.id.empty()) a.id = id;
    std::string form = beta_form("send", "&app=" + form_value(id) + "&version=" + form_value(engine().installed(id)) +
                                             "&message=" + form_value(message) + extra);
    std::uint64_t total = 0;
    std::size_t n = 0;
    for (const LogFile &f : game_log_files(a))
    {
        /* The store's log: its last 200 KB; each of the game's: its last 128 KB; 640 KB in all. */
        const std::uint64_t room = (n == 0 ? 200u : 128u) << 10;
        if (total + std::min(f.size, room) > (640u << 10)) break;
        const std::string text = file_tail(f.path, f.size, room);
        total += text.size();
        ++n;
        form += "&name%5B%5D=" + form_value(f.name) + "&text%5B%5D=" + form_value(text);
    }
    {
        std::lock_guard<std::mutex> l(g.m);
        g.logs_report.clear();
        g.logs_report_url.clear();
    }
    porpoise::http::Session http("logs", "RecompStore/1.1");
    if (!http.init()) return logs_done(3, "Your PS5 isn't online. Connect it and try again.");
    std::vector<std::uint8_t> body;
    const int status = http.request(kLogsUrl, &form, body, 64u << 10);
    Json j;
    const bool answered = Json::parse(std::string(body.begin(), body.end()), j) && j.is_object();
    const bool ok = answered && j["ok"].boolean(false);
    slog("logs: sent %zu files (%llu KB) for %s: HTTP %d, %s\n", n, (unsigned long long)(total >> 10), id.c_str(), status,
         ok ? "ok" : answered ? "refused" : "no answer");
    if (!answered)
        return logs_done(3, status < 0 ? "recompstore.com didn't answer. Check your connection and try again."
                                       : "recompstore.com couldn't take them right now. Try again in a moment.");
    if (!ok) return logs_done(3, j["error"].str("They didn't go. Try again in a moment."));
    {
        /* (2.0) The site's report for them, to finish on a phone (with pictures). */
        std::lock_guard<std::mutex> l(g.m);
        g.logs_report = j["report"].str();
        g.logs_report_url = j["url"].str();
        if (g.logs_report.size() > 12 || g.logs_report_url.rfind("https://", 0) != 0) g.logs_report.clear(), g.logs_report_url.clear();
    }
    logs_done(2, j["message"].str("Sent. Thanks for helping."));
}

/* ---- Link a phone (2.0) ---------------------------------------------------------------
 * A thread of its own, so the screens never wait on it. The console's HTTP is one session
 * at a time across the store, so it opens one for a short burst of requests and lets it go
 * straight after (during an install it simply waits its turn). */
double mono_s();
std::string sources_state_json();

/* (2.3.0) Defined in store_engine_23.inc: the phone's requests and how they went, whether the state
 * should go now, and the store's favorites and auto-update choices (the screens keep those). */
std::string link_requests_json();
bool link_push_wanted();
void link_push_clear();
void phone_prefs(std::vector<std::string> &favs, std::vector<std::string> &autos, bool &all);

/* What the site keeps for My PS5 (docs/store-2.3.md, 5): the apps on this console (the store's and
 * other homebrew), what's newer, the drives, the queue and the phone's requests. */
std::string link_state_json()
{
    Catalog c;
    std::vector<ConsoleApp> con;
    std::vector<QueueItem> queue;
    JobState job;
    {
        std::lock_guard<std::mutex> l(g.m);
        c = g.catalog;
        con = g.console;
        queue = g.queue;
        job = g.job;
    }
    std::vector<std::string> favs, autos;
    bool auto_all = false;
    phone_prefs(favs, autos, auto_all);
    auto esc = [](const std::string &v) {
        std::string o;
        for (const unsigned char ch : v)
            if (ch == '"' || ch == '\\') { o += '\\'; o += char(ch); }
            else if (ch >= 0x20) o += char(ch);
        return o;
    };
    auto has = [](const std::vector<std::string> &v, const std::string &x) { return std::find(v.begin(), v.end(), x) != v.end(); };
    std::string apps;
    std::size_t n = 0;
    std::set<std::string> listed; /* folders already in the list */
    for (const App &a : c.apps)
    {
        if (n >= 200) break;
        std::string v = engine().installed(a.id);
        if (v.empty()) continue;
        std::string want = engine().newest_version(a);
        if (want.empty() || (!a.version.empty() && version_newer(a.version, want))) want = a.version;
        want.erase(0, version_start(want));
        const bool update = v != "?" && !want.empty() && version_key(v) != version_key(want) && !version_newer(v, want);
        v.erase(0, version_start(v));
        const ConsoleApp *ca = nullptr;
        const std::string folder = app_folder(a.id);
        for (const ConsoleApp &x : con)
            if (x.folder == folder) ca = &x;
        listed.insert(folder);
        char size[32];
        std::snprintf(size, sizeof size, "%lld", ca ? ca->size : -1LL);
        apps += std::string(apps.empty() ? "" : ",") + "{\"id\":\"" + esc(a.id) + "\",\"name\":\"" + esc(a.name) + "\",\"version\":\"" +
                esc(v == "?" ? std::string() : v) + "\",\"update\":\"" + esc(update ? want : std::string()) + "\",\"category\":\"" +
                esc(a.category) + "\",\"size\":" + size + ",\"where\":\"" + esc(ca ? ca->place : std::string()) + "\",\"root\":\"" +
                esc(ca ? ca->root : std::string()) + "\",\"folder\":\"" + esc(folder) + "\",\"fav\":" + (has(favs, a.id) ? "true" : "false") +
                ",\"auto\":" + (auto_all || has(autos, a.id) ? "true" : "false") + ",\"store\":true}";
        ++n;
    }
    for (const ConsoleApp &x : con)
    {
        if (n >= 200) break;
        if (!x.app || x.store || listed.count(x.folder)) continue;
        char size[32];
        std::snprintf(size, sizeof size, "%lld", x.size);
        apps += std::string(apps.empty() ? "" : ",") + "{\"id\":\"\",\"name\":\"" + esc(x.name.empty() ? x.title_id : x.name) +
                "\",\"version\":\"" + esc(x.version) + "\",\"update\":\"\",\"category\":\"\",\"size\":" + size + ",\"where\":\"" +
                esc(x.place) + "\",\"root\":\"" + esc(x.root) + "\",\"folder\":\"" + esc(x.folder) + "\",\"fav\":false,\"auto\":false,\"store\":false}";
        ++n;
    }
    std::string drives;
    for (const DriveSpace &d : engine().drives())
    {
        char nums[96];
        std::snprintf(nums, sizeof nums, "\"total\":%llu,\"free\":%llu,\"sized\":%s", (unsigned long long)d.total, (unsigned long long)d.free,
                      d.sized && d.total ? "true" : "false");
        drives += std::string(drives.empty() ? "" : ",") + "{\"label\":\"" + esc(d.label) + "\",\"root\":\"" + esc(d.homebrew) + "\"," + nums + "}";
    }
    std::string waiting, names;
    auto name_of = [&](const std::string &id) {
        for (const App &a : c.apps)
            if (a.id == id) return a.name;
        return id;
    };
    for (const QueueItem &q : queue)
    {
        waiting += std::string(waiting.empty() ? "\"" : ",\"") + esc(q.id) + "\"";
        names += std::string(names.empty() ? "\"" : ",\"") + esc(q.id) + "\":\"" + esc(name_of(q.id)) + "\"";
    }
    if (job.phase == Phase::Working && !job.app_id.empty() && names.find("\"" + esc(job.app_id) + "\":") == std::string::npos)
        names += std::string(names.empty() ? "\"" : ",\"") + esc(job.app_id) + "\":\"" + esc(name_of(job.app_id)) + "\"";
    const bool going = job.phase == Phase::Working && (job.kind == "install" || job.kind == "update") && !job.app_id.empty();
    char qnums[96];
    std::snprintf(qnums, sizeof qnums, "\"done\":%llu,\"total\":%llu", (unsigned long long)job.done, (unsigned long long)job.total);
    const std::string q = "{\"now\":\"" + esc(going ? job.app_id : std::string()) + "\",\"step\":\"" + esc(going ? job.step : std::string()) + "\"," +
                          qnums + ",\"waiting\":[" + waiting + "],\"names\":{" + names + "}}";
    double free_gb = 0;
    {
        /* (2.3.0) The real figure (real_space), else none. */
        std::uint64_t total = 0, avail = 0;
        bool smp = false;
        if (real_space("/data", total, avail, smp)) free_gb = double(avail) / 1073741824.0;
    }
    char tail[64];
    std::snprintf(tail, sizeof tail, "],\"free_gb\":%.1f}", free_gb);
    return "{\"v\":2,\"store\":\"" + std::string(kVersion) + "\",\"apps\":[" + apps + "],\"drives\":[" + drives + "],\"queue\":" + q +
           ",\"requests\":[" + link_requests_json() + "],\"sources\":[" + sources_state_json() + tail;
}

/* The site's answer to a link request, as JSON; false when it didn't answer with one. */
bool link_ask(porpoise::http::Session &http, const char *action, const std::string &more, Json &j, int *status = nullptr)
{
    const std::string form = beta_form(action, more);
    std::vector<std::uint8_t> body;
    const int st = http.request(kLinkUrl, &form, body, 256u << 10);
    if (status) *status = st;
    return Json::parse(std::string(body.begin(), body.end()), j) && j.is_object();
}

/* This console's ID and token, made by the site the first time (beta.json, as beta codes
 * have used since 1.1.0). */
bool link_hello(porpoise::http::Session &http)
{
    Json j;
    int st = 0;
    if (!link_ask(http, "hello", "", j, &st) || !j["ok"].boolean(false))
    {
        slog("link: hello HTTP %d, %s", st, j["error"].str("no answer").c_str());
        return false;
    }
    const std::string install = j["install"].str(), token = j["token"].str();
    if (!lower_hex(install, 16) || !lower_hex(token, 32)) return false;
    bool changed = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        changed = install != g.beta_install || token != g.beta_token;
        g.beta_install = install;
        g.beta_token = token;
    }
    if (changed)
    {
        const std::string text = "{\"install\":\"" + install + "\",\"token\":\"" + token + "\"}\n";
        if (!write_file(g.data + "/beta.json", text.data(), text.size()))
            slog("link: couldn't save this console's ID in %s/beta.json", g.data.c_str());
        slog("link: this console is %s on recompstore.com%s", install.c_str(), j["new"].boolean(false) ? " (new)" : "");
    }
    /* (2.0) A console the admin marked as a tester reads the test catalog (the next refresh,
     * straight away). */
    const bool tester = j["tester"].boolean(false);
    bool flip = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        flip = tester != g.tester;
        g.tester = tester;
        if (flip && g.test_view) g.force_check = true;
        if (flip) ++g.revision;
    }
    if (flip)
    {
        if (tester) write_file(g.data + "/tester", "1\n", 2);
        else std::remove((g.data + "/tester").c_str());
        bool view = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            view = g.test_view;
        }
        if (view) use_cached_catalog(tester);
        slog("link: %s", tester ? "this console is a tester: it reads the test catalog" : "this console isn't a tester (any more)");
    }
    return true;
}

/* (2.0) Installs and deletes, counted for the charts (one per console and app). */
void link_stats(porpoise::http::Session &http)
{
    std::vector<std::string> due;
    {
        std::lock_guard<std::mutex> l(g.m);
        due.swap(g.stats_due);
    }
    for (const std::string &d : due)
    {
        if (d.size() < 2) continue;
        const bool add = d[0] == '+';
        const std::size_t sp = d.find(' ');
        const std::string app = d.substr(1, sp == std::string::npos ? std::string::npos : sp - 1);
        const std::string ver = sp == std::string::npos ? std::string() : d.substr(sp + 1);
        const std::string form = beta_form(add ? "installed" : "removed", "&app=" + form_value(app) + (add ? "&version=" + form_value(ver) : ""));
        std::vector<std::uint8_t> body;
        const int st = http.request(kStatsUrl, &form, body, 16u << 10);
        if (st != 200) slog("stats: %s %s: HTTP %d", add ? "installed" : "removed", app.c_str(), st);
    }
}

void link_poll(porpoise::http::Session &http)
{
    Json j;
    int st = 0;
    if (!link_ask(http, "poll", "", j, &st) || !j["ok"].boolean(false))
    {
        slog("link: poll HTTP %d, %s", st, j["error"].str("no answer").c_str());
        return;
    }
    std::vector<LinkDevice> devices;
    for (std::size_t i = 0; i < j["devices"].size() && devices.size() < 16; ++i)
    {
        const Json &d = j["devices"][i];
        LinkDevice x;
        x.id = d["id"].str().substr(0, 32);
        x.label = d["label"].str("A phone").substr(0, 60);
        x.since = d["since"].str().substr(0, 20);
        x.seen = d["seen"].str().substr(0, 32);
        if (!x.id.empty()) devices.push_back(x);
    }
    std::vector<LinkAction> actions;
    for (std::size_t i = 0; i < j["actions"].size() && actions.size() < 16; ++i)
    {
        const Json &a = j["actions"][i];
        LinkAction x;
        x.kind = a["kind"].str();
        x.app = a["data"]["app"].str().substr(0, 64);
        x.repo = a["data"]["repo"].str().substr(0, 140);
        x.list = a["data"]["list"].str().substr(0, 500);
        x.report = a["data"]["report"].str().substr(0, 16);
        /* (2.3.0) Managing the PS5 from the phone (docs/store-2.3.md, 5). */
        x.id = a["id"].integer(0);
        x.folder = a["data"]["folder"].str().substr(0, 200);
        x.to = a["data"]["to"].str().substr(0, 200);
        x.text = a["data"]["text"].str().substr(0, 200);
        if (a["data"]["on"].type() == Json::Type::Bool) x.on = a["data"]["on"].boolean(false) ? 1 : 0;
        else if (a["data"]["on"].type() == Json::Type::Number) x.on = a["data"]["on"].integer(0) ? 1 : 0;
        if (x.kind == "install" || x.kind == "source" || x.kind == "refresh" || x.kind == "report" || x.kind == "cancel" ||
            x.kind == "favorite" || x.kind == "autoupdate" || x.kind == "delete" || x.kind == "move" || x.kind == "text")
            actions.push_back(x);
    }
    std::lock_guard<std::mutex> l(g.m);
    bool changed = devices.size() != g.link.devices.size();
    for (std::size_t i = 0; !changed && i < devices.size(); ++i) changed = devices[i].id != g.link.devices[i].id;
    g.link.devices = devices;
    g.link.known = true;
    for (const LinkAction &a : actions)
    {
        slog("link: from a phone: %s %s%s%s", a.kind.c_str(), a.app.c_str(), a.repo.c_str(), a.list.c_str());
        if (a.kind == "refresh") g.force_check = true;
        else g.link_actions.push_back(a);
    }
    if (changed || !actions.empty()) ++g.revision;
}

void link_code(porpoise::http::Session &http)
{
    Json j;
    int st = 0;
    const bool answered = link_ask(http, "code", "", j, &st);
    std::lock_guard<std::mutex> l(g.m);
    if (answered && j["ok"].boolean(false) && !j["code"].str().empty() && j["code"].str().size() <= 8)
    {
        g.link.code_state = 2;
        g.link.code = j["code"].str();
        g.link.url = j["url"].str();
        if (g.link.url.rfind("https://", 0) != 0) g.link.url = "https://recompstore.com/l/" + g.link.code;
        g.link.error.clear();
        g.link_code_until = mono_s() + double(std::clamp<long long>(j["expires"].integer(600), 30, 3600));
    }
    else
    {
        g.link.code_state = 3;
        g.link.error = answered ? j["error"].str("The site couldn't make a code. Try again in a moment.")
                                : "recompstore.com didn't answer. Check your connection and try again.";
    }
    ++g.revision;
    slog("link: code HTTP %d, %s", st, g.link.code_state == 2 ? "got one" : g.link.error.c_str());
}

void *link_worker(void *)
{
    bool hello_ok = false;
    double next_try = 0, next_poll = 0, last_push = -1e9;
    std::string last_state;
    for (;;)
    {
        usleep(200000);
        const double now = mono_s();
        bool open = false, want_code = false, poll_now = false;
        std::vector<std::string> unlinks;
        std::size_t devices = 0;
        {
            std::lock_guard<std::mutex> l(g.m);
            open = g.link_open;
            want_code = g.link_want_code;
            poll_now = g.link_poll_now;
            unlinks.swap(g.link_unlinks);
            devices = g.link.devices.size();
            if (g.link_hello) hello_ok = false; /* asked again (Refresh) */
            g.link_hello = false;
            /* A code that ran out: a new one while the screen shows it. */
            if (open && g.link.code_state == 2 && now > g.link_code_until - 5) want_code = true;
        }
        bool stats = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            stats = !g.stats_due.empty();
        }
        const double interval = open ? 3 : devices > 0 ? 15 : 300;
        /* (2.3.0) Every minute, or a few seconds after something a phone asked for happened. */
        const bool push_due = devices > 0 && (now - last_push >= 60 || (link_push_wanted() && now - last_push >= 3));
        if (now < next_try || (hello_ok && !want_code && !poll_now && unlinks.empty() && now < next_poll && !push_due && !stats))
            continue;
        porpoise::http::Session http("link", "RecompStore/2.0");
        if (!http.init())
        {
            next_try = now + 30;
            continue;
        }
        if (!hello_ok)
        {
            hello_ok = link_hello(http);
            if (!hello_ok)
            {
                next_try = now + 60;
                continue;
            }
            next_poll = 0;
        }
        if (want_code)
        {
            {
                std::lock_guard<std::mutex> l(g.m);
                g.link_want_code = false;
                if (g.link.code_state != 2) g.link.code_state = 1;
            }
            link_code(http);
        }
        for (const std::string &d : unlinks)
        {
            Json j;
            link_ask(http, "unlink", "&device=" + form_value(d), j);
            slog("link: unlinked %s: %s", d.c_str(), j["ok"].boolean(false) ? "ok" : j["error"].str("no answer").c_str());
            poll_now = true;
        }
        if (poll_now || mono_s() >= next_poll)
        {
            {
                std::lock_guard<std::mutex> l(g.m);
                g.link_poll_now = false;
            }
            link_poll(http);
            next_poll = mono_s() + interval;
        }
        if (stats) link_stats(http);
        if (push_due)
        {
            link_push_clear();
            const std::string state = link_state_json();
            if (state != last_state && state.size() < (60u << 10))
            {
                Json j;
                link_ask(http, "state", "&state=" + form_value(state), j);
                last_state = state;
            }
            last_push = mono_s();
        }
    }
    return nullptr;
}

/* ---- Custom sources (2.0) -------------------------------------------------------------
 * A GitHub repo or a list of them the player added. recompstore.com reads it (source.php)
 * and answers with its apps, shaped like the catalog's; they join the store's after the
 * catalog's own, marked custom. Kept in sources.json, each one's last answer in sources/. */
bool repo_text_ok(const std::string &r)
{
    const std::size_t slash = r.find('/');
    if (slash == std::string::npos || slash == 0 || slash > 100 || r.size() - slash - 1 == 0 || r.size() - slash - 1 > 100 ||
        r.find('/', slash + 1) != std::string::npos || r.find("..") != std::string::npos)
        return false;
    for (const unsigned char c : r)
        if (!(std::isalnum(c) || c == '_' || c == '.' || c == '-' || c == '/')) return false;
    return true;
}

/* An app ID a source may use: a title ID or "gh-" and hex from the site, never the store's own. */
bool source_id_ok(const std::string &id)
{
    if (id.size() < 3 || id.size() > 48 || id == "PPSA92000" || id.find("..") != std::string::npos) return false;
    for (const unsigned char c : id)
        if (!(std::isalnum(c) || c == '_' || c == '.' || c == '-')) return false;
    return true;
}

std::string source_file(const std::string &value)
{
    unsigned long long h = 1469598103934665603ULL;
    for (const unsigned char c : value) h = (h ^ c) * 1099511628211ULL;
    char b[24];
    std::snprintf(b, sizeof b, "%016llx", h);
    return g.data + "/sources/" + b + ".json";
}

/* A source's apps from the site's answer, made safe whatever it says: GitHub releases only,
 * nothing that reaches outside the app's own folder (no saves, kept files or disc slots). */
std::vector<App> source_apps_from(const Catalog &c, const Source &s)
{
    std::vector<App> out;
    for (App a : c.apps)
    {
        if (!source_id_ok(a.id) || a.source != "github" || !repo_text_ok(a.repo) || a.asset.empty() ||
            a.asset.find('/') != std::string::npos || a.title_id == "PPSA92000")
            continue;
        bool twice = false;
        for (const App &o : out) twice = twice || o.id == a.id;
        if (twice) continue;
        a.custom = true;
        a.beta = a.soon = a.testing = a.has_setup = false;
        a.kind = "community";
        a.source_value = s.value;
        if (a.source_name.empty()) a.source_name = s.name.empty() ? s.value : s.name;
        a.url.clear();
        a.sha256.clear(); /* GitHub's own digest for the file is checked */
        a.inside.clear();
        a.bytes = 0;
        a.saves.clear();
        a.keep.clear();
        a.logs.clear();
        a.slots.clear();
        a.data_dir.clear();
        a.logo_url.clear();
        a.hero_web_url.clear();
        a.news.clear();
        /* Pictures only from the site (it keeps a copy of each app's icon): never fetched from
         * anywhere a source names. */
        for (std::string *u : {&a.icon_url, &a.hero_url})
            if (u->rfind("https://recompstore.com/media/", 0) != 0 || u->size() > 300 || u->find("..") != std::string::npos) u->clear();
        if (!a.title_id.empty() && a.title_id != a.id && a.id.rfind("gh-", 0) != 0) a.title_id = a.id;
        out.push_back(a);
        if (out.size() >= 100) break;
    }
    return out;
}

void save_sources()
{
    std::string text = "[";
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const Source &s : g.sources)
            text += std::string(text.size() > 1 ? ",\n " : "") + "{\"kind\":" + json_text(s.kind) + ",\"value\":" + json_text(s.value) +
                    ",\"name\":" + json_text(s.name) + ",\"author\":" + json_text(s.author) + "}";
    }
    text += "]\n";
    if (!write_file(g.data + "/sources.json", text.data(), text.size())) slog("sources: couldn't save sources.json");
}

/* At start: the sources, and the apps each had the last time the site was asked. */
void load_sources()
{
    std::string text;
    Json j;
    std::vector<Source> list;
    std::map<std::string, std::vector<App>> apps;
    if (read_file(g.data + "/sources.json", text) && Json::parse(text, j) && j.is_array())
        for (std::size_t i = 0; i < j.size() && list.size() < 30; ++i)
        {
            Source s;
            s.kind = j[i]["kind"].str();
            s.value = j[i]["value"].str();
            s.name = j[i]["name"].str().substr(0, 80);
            s.author = j[i]["author"].str().substr(0, 60);
            if ((s.kind == "repo" && !repo_text_ok(s.value)) || (s.kind == "list" && s.value.rfind("https://", 0) != 0) ||
                (s.kind != "repo" && s.kind != "list"))
                continue;
            std::string cached;
            Catalog c;
            if (read_file(source_file(s.value), cached) && parse_catalog(cached, c))
            {
                apps[s.value] = source_apps_from(c, s);
                s.apps = apps[s.value].size();
                s.state = 2;
            }
            list.push_back(s);
        }
    std::lock_guard<std::mutex> l(g.m);
    g.sources = list;
    g.source_apps = apps;
    for (const Source &s : list) g.sources_due.insert(s.value);
}

/* One source, read through the site. Its apps change only when the site answered: one it
 * couldn't read keeps the apps it had (a repo that's down for a moment), one taken down
 * loses them. */
void fetch_source(porpoise::http::Session &http, const std::string &value)
{
    Source s;
    {
        std::lock_guard<std::mutex> l(g.m);
        bool found = false;
        for (Source &x : g.sources)
            if (x.value == value)
            {
                if (x.state != 2) x.state = 1;
                s = x;
                found = true;
            }
        if (!found) return;
        ++g.revision;
    }
    const std::string url = std::string(kSourceUrl) + (s.kind == "repo" ? "?repo=" : "?list=") + form_value(s.value);
    std::vector<std::uint8_t> body;
    const int st = http.get(url, body, {}, 4u << 20);
    const std::string text(body.begin(), body.end());
    Json j;
    const bool answered = Json::parse(text, j) && j.is_object();
    Catalog c;
    std::vector<App> apps;
    bool keep_apps = true, drop = false;
    if (!answered)
    {
        s.error = st < 0 ? "recompstore.com didn't answer. It's tried again with the next refresh."
                         : "recompstore.com couldn't read it right now. It's tried again with the next refresh.";
        if (s.state != 2) s.state = 3;
    }
    else if (!j["ok"].boolean(false))
    {
        s.error = j["error"].str("It couldn't be read.").substr(0, 200);
        s.state = j["blocked"].boolean(false) ? 4 : 3;
        drop = s.state == 4;
        keep_apps = !drop;
    }
    else if (parse_catalog(text, c))
    {
        s.name = j["source"]["name"].str(s.name.empty() ? s.value : s.name).substr(0, 80);
        s.author = j["source"]["author"].str(s.author).substr(0, 60);
        s.problems.clear();
        for (std::size_t i = 0; i < j["errors"].size() && s.problems.size() < 20; ++i)
            s.problems.push_back((j["errors"][i]["repo"].str() + ": " + j["errors"][i]["error"].str()).substr(0, 200));
        apps = source_apps_from(c, s);
        s.apps = apps.size();
        s.state = 2;
        s.error.clear();
        keep_apps = false;
        make_dirs(g.data + "/sources");
        write_file(source_file(s.value), text.data(), text.size());
    }
    else
    {
        s.error = "recompstore.com's answer couldn't be read.";
        if (s.state != 2) s.state = 3;
    }
    if (drop) std::remove(source_file(s.value).c_str());
    if (s.state == 2 && !keep_apps)
        slog("source %s: HTTP %d, %zu apps, %zu problems", s.value.c_str(), st, apps.size(), s.problems.size());
    else
        slog("source %s: HTTP %d, %s", s.value.c_str(), st, s.error.c_str());
    {
        std::lock_guard<std::mutex> l(g.m);
        for (Source &x : g.sources)
            if (x.value == value)
            {
                if (keep_apps) s.apps = g.source_apps.count(value) ? g.source_apps[value].size() : 0;
                x = s;
                if (!keep_apps) g.source_apps[value] = apps;
                if (drop) g.source_apps.erase(value);
            }
        merge_beta(g.catalog);
        attach_art(g.catalog);
        ++g.revision;
    }
    save_sources();
}

/* What the site keeps for My PS5: the sources, as {kind, value, name}. */
std::string sources_state_json()
{
    std::lock_guard<std::mutex> l(g.m);
    std::string out;
    for (const Source &s : g.sources)
        out += std::string(out.empty() ? "" : ",") + "{\"kind\":" + json_text(s.kind) + ",\"value\":" + json_text(s.value) +
               ",\"name\":" + json_text(s.name.empty() ? s.value : s.name) + "}";
    return out;
}

void run_report_source(const std::string &value, const std::string &why)
{
    auto done = [](int state, const std::string &msg) {
        std::lock_guard<std::mutex> l(g.m);
        g.src_report = state;
        g.src_report_msg = msg;
        ++g.revision;
    };
    porpoise::http::Session http("source", "RecompStore/2.0");
    if (!http.init()) return done(3, "Your PS5 isn't online. Connect it and try again.");
    const std::string form = beta_form("report", "&value=" + form_value(value) + "&why=" + form_value(why));
    std::vector<std::uint8_t> body;
    const int st = http.request(kSourceUrl, &form, body, 64u << 10);
    Json j;
    const bool answered = Json::parse(std::string(body.begin(), body.end()), j) && j.is_object();
    slog("source report %s: HTTP %d", value.c_str(), st);
    if (!answered) return done(3, "recompstore.com didn't answer. Try again in a moment.");
    if (!j["ok"].boolean(false)) return done(3, j["error"].str("It didn't go. Try again in a moment."));
    done(2, j["message"].str("Thanks. We'll look at it."));
}

/* ---- The charts (2.0) ------------------------------------------------------------------ */
/* (2.2.2) A list of app IDs, at most `most`, plain ones only. */
static void ids_into(const Json &a, std::vector<std::string> &v, std::size_t most)
{
    for (std::size_t i = 0; i < a.size() && v.size() < most; ++i)
    {
        const std::string id = a[i].is_object() ? a[i]["id"].str() : a[i].str();
        if (!id.empty() && id.size() <= 48 && id.find('/') == std::string::npos) v.push_back(id);
    }
}

bool parse_charts(const std::string &text, Charts &out)
{
    Json j;
    if (!Json::parse(text, j) || !j.is_object() || !j["ok"].boolean(false)) return false;
    out = Charts();
    auto ids = [&](const Json &a, std::vector<std::string> &v) {
        for (std::size_t i = 0; i < a.size() && v.size() < 50; ++i)
        {
            const std::string id = a[i].is_object() ? a[i]["id"].str() : a[i].str();
            if (!id.empty() && id.size() <= 48) v.push_back(id);
        }
    };
    ids(j["top"], out.top);
    ids(j["trending"], out.trending);
    ids(j["new"], out.fresh);
    ids(j["updated"], out.updated);
    for (const auto &kv : j["stats"].members())
    {
        if (out.stats.size() >= 500) break;
        ChartStat c;
        c.installs = kv.second["installs"].integer(0);
        c.stars = kv.second["stars"].integer(-1);
        c.downloads = kv.second["downloads"].integer(-1);
        out.stats.push_back({kv.first, c});
    }
    /* (2.2.2) People also installed. */
    for (const auto &kv : j["also"].members())
    {
        if (out.also.size() >= 500 || kv.first.empty() || kv.first.size() > 48) break;
        std::vector<std::string> ids;
        ids_into(kv.second, ids, 8);
        if (!ids.empty()) out.also.push_back({kv.first, ids});
    }
    /* (2.3.0) Likes, most first. */
    for (const auto &kv : j["likes"].members())
    {
        if (out.likes.size() >= 2000 || kv.first.empty() || kv.first.size() > 48) break;
        const long long n = kv.second.integer(0);
        if (n > 0) out.likes.push_back({kv.first, n});
    }
    std::stable_sort(out.likes.begin(), out.likes.end(), [](const auto &a, const auto &b) { return a.second > b.second; });
    out.have = true;
    return true;
}

/* (2.0) The site's list of taken title IDs (api/ids.php): every app in the store and the ones the
 * admin knows of outside it. */
bool parse_ids(const std::string &text, std::set<std::string> &out)
{
    Json j;
    if (!Json::parse(text, j) || !j.is_object() || !j["ok"].boolean(false)) return false;
    out.clear();
    for (const char *list : {"store", "taken"})
        for (std::size_t i = 0; i < j[list].size() && out.size() < 5000; ++i)
        {
            const std::string id = j[list][i]["id"].str();
            if (title_id_ok(id)) out.insert(id);
        }
    return true;
}

void refresh_ids(porpoise::http::Session &http)
{
    std::vector<std::uint8_t> body;
    const int st = http.get(kIdsUrl, body, {}, 1u << 20);
    const std::string text(body.begin(), body.end());
    std::set<std::string> ids;
    if (st == 200 && parse_ids(text, ids))
    {
        write_file(g.data + "/ids.json", text.data(), text.size());
        std::lock_guard<std::mutex> l(g.m);
        g.taken_ids.swap(ids);
    }
}

void refresh_charts(porpoise::http::Session &http)
{
    std::vector<std::uint8_t> body;
    const int st = http.get(kChartsUrl, body, {}, 1u << 20);
    const std::string text(body.begin(), body.end());
    Charts c;
    const bool ok = st == 200 && parse_charts(text, c);
    if (ok)
    {
        write_file(g.data + "/charts.json", text.data(), text.size());
        std::lock_guard<std::mutex> l(g.m);
        g.charts = c;
        ++g.revision;
    }
    slog("charts: HTTP %d%s", st, ok ? "" : " (not read)");
}

/* (2.0) Something the player asked for is waiting: the art can wait for it. */
bool job_waiting()
{
    std::lock_guard<std::mutex> l(g.m);
    return !g.pending_install.empty() || !g.pending_setup_app.empty() || !g.pending_delete.empty() || g.pending_store_dl ||
           g.pending_bench || !g.pending_redeem.empty() || g.pending_logs || !g.sources_due.empty() || g.pending_src_report ||
           !g.pending_manage.empty() /* (2.2) */ || !g.pending_task.empty() /* (2.3.0) */;
}

/* (2.0) Pictures on recompstore.com/media, up to 24 in one request (api/art.php). On a PS5
 * each request costs about as much as a small picture, so fetching them one at a time kept
 * a new store's icons coming for a minute. The answer: for each picture, a line with its
 * path and size, then its bytes (size 0: not on the site; -1: over the answer's size cap,
 * ask again). Pictures saved go in saved; those to fetch on their own, in alone. false when
 * the site didn't answer with them (a site from before 2.0). */
const std::string kSite = "https://recompstore.com";
constexpr std::size_t kArtBatch = 24;

bool fetch_art_batch(porpoise::http::Session &http, const std::vector<std::string> &urls, std::size_t &saved,
                     std::vector<std::string> &alone)
{
    saved = 0;
    std::map<std::string, std::string> left; /* path -> address */
    std::string paths;
    for (const std::string &u : urls)
    {
        const std::string p = u.substr(kSite.size());
        left[p] = u;
        paths += (paths.empty() ? "" : "\n") + p;
    }
    const std::string form = "paths=" + form_value(paths);
    std::vector<std::uint8_t> body;
    const int st = http.request(std::string(kSite) + "/api/art.php", &form, body, 24u << 20);
    if (st != 200)
    {
        slog("art: api/art.php answered HTTP %d; one request each", st);
        return false;
    }
    std::size_t at = 0;
    while (at < body.size() && !left.empty())
    {
        std::size_t nl = at;
        while (nl < body.size() && body[nl] != '\n' && nl - at < 400) ++nl;
        if (nl >= body.size() || body[nl] != '\n') break;
        const std::string line(reinterpret_cast<const char *>(body.data()) + at, nl - at);
        at = nl + 1;
        const std::size_t sp = line.rfind(' ');
        const auto it = sp == std::string::npos ? left.end() : left.find(line.substr(0, sp));
        if (it == left.end()) break; /* not a picture asked for: the rest can't be trusted */
        const long long n = std::strtoll(line.c_str() + sp + 1, nullptr, 10);
        if (n > 0 && std::uint64_t(n) > body.size() - at) break;
        if (n > 0 && write_file(g.data + "/art/" + art_name(it->second), body.data() + at, std::size_t(n))) ++saved;
        else if (n == 0) slog("art %s: not on the site", it->second.c_str());
        else if (n < 0) alone.push_back(it->second);
        if (n > 0) at += std::size_t(n);
        left.erase(it);
    }
    for (const auto &kv : left) alone.push_back(kv.second); /* the answer stopped short */
    slog("art: %zu of %zu pictures in one request (%zu KB)", saved, urls.size(), body.size() / 1024);
    return true;
}

/* budget (2.2.3): 0 everything; else at most that many requests (the quick thread, during a job). */
void fetch_art(porpoise::http::Session &http, int budget)
{
    static std::mutex one; /* the worker and the quick thread never both at once */
    std::lock_guard<std::mutex> lk(one);
    Catalog c;
    {
        std::lock_guard<std::mutex> l(g.m);
        c = g.catalog;
        g.art_more = false;
    }
    make_dirs(g.data + "/art");
    /* (2.0) Icons first, every app's (each tile shows one), then logos, then the big banners. */
    std::vector<std::string> want;
    std::set<std::string> seen, single;
    const auto add = [&](const std::string &u) {
        if (!u.empty() && seen.insert(u).second && !exists(g.data + "/art/" + art_name(u))) want.push_back(u);
    };
    for (const std::string App::*field : {&App::icon_url, &App::logo_url, &App::hero_url})
    {
        for (const App &a : c.apps) add(a.*field);
        for (const App &a : c.soon) add(a.*field);
    }
    const auto shown = [] {
        std::lock_guard<std::mutex> l(g.m);
        attach_art(g.catalog);
        ++g.revision;
    };
    const std::string media = kSite + "/media/";
    bool batches = true;
    int left = budget;
    for (std::size_t i = 0; i < want.size();)
    {
        if (budget > 0)
        {
            /* The quick thread: a few requests, then the rest later (the worker's, or the next turn). */
            if (left-- <= 0)
            {
                std::lock_guard<std::mutex> l(g.m);
                g.art_more = true;
                return;
            }
        }
        else if (job_waiting())
        {
            /* An install the player chose doesn't wait for pictures: the rest after it. */
            slog("art: %zu left for after the job waiting", want.size() - i);
            std::lock_guard<std::mutex> l(g.m);
            g.art_more = true;
            return;
        }
        const auto batchable = [&](const std::string &u) { return batches && u.rfind(media, 0) == 0 && !single.count(u); };
        if (batchable(want[i]))
        {
            std::vector<std::string> batch, alone;
            const std::size_t from = i;
            while (i < want.size() && batch.size() < kArtBatch && batchable(want[i])) batch.push_back(want[i++]);
            std::size_t saved = 0;
            if (!fetch_art_batch(http, batch, saved, alone))
            {
                batches = false;
                i = from;
                continue;
            }
            for (const std::string &u : alone)
                if (single.insert(u).second) want.push_back(u); /* on its own, after the rest */
            if (saved) shown();
            continue;
        }
        const std::string &u = want[i++];
        std::vector<std::uint8_t> body;
        const int status = http.get(u, body, {}, 32u << 20);
        const bool saved = status == 200 && !body.empty() && write_file(g.data + "/art/" + art_name(u), body.data(), body.size());
        slog("art %s: HTTP %d, %zu bytes%s\n", u.c_str(), status, body.size(), saved ? "" : " (not saved)");
        if (saved) shown();
    }
}

/* ---- Installing --------------------------------------------------------------------- */
struct Target
{
    std::string url, sha256, version;
    std::uint64_t size = 0;
    std::string newest; /* (2.1) the newest release's tag with the app's file, whichever was picked */
};

void remember_releases(const std::string &repo, const std::string &text);

/* (2.0) A repo's releases (GitHub's /releases?per_page=30): through recompstore.com first,
 * which asks GitHub once for every PS5 and keeps a copy when GitHub is down; from GitHub
 * itself when the site doesn't answer with them. The HTTP status, as GitHub's. */
int github_releases(porpoise::http::Session &http, const std::string &repo, std::vector<std::uint8_t> &body)
{
    body.clear();
    const int st = http.get(std::string(kGhUrl) + "?repo=" + form_value(repo), body, {}, 8u << 20);
    Json j;
    if (st == 200 && Json::parse(std::string(body.begin(), body.end()), j) && j.is_array()) return 200;
    slog("github %s: recompstore.com answered HTTP %d; asking GitHub", repo.c_str(), st);
    body.clear();
    return http.get("https://api.github.com/repos/" + repo + "/releases?per_page=30", body, {}, 8u << 20);
}

std::string lower_ascii(std::string s);

/* (2.1) A GitHub app's file only comes from its own repo's releases, whatever the release list
 * (from recompstore.com or GitHub) says. GitHub's own redirect to its file host follows. */
bool from_own_repo(const std::string &url, const std::string &repo)
{
    const std::string want = "https://github.com/" + lower_ascii(repo) + "/releases/download/";
    if (repo.empty() || repo.find("..") != std::string::npos || url.size() <= want.size()) return false;
    if (lower_ascii(url.substr(0, want.size())) != want) return false;
    return url.find("..", want.size()) == std::string::npos && url.find_first_of("?#\\", want.size()) == std::string::npos;
}

/* (2.1) want: a release's tag the player picked (Versions), "" for the newest. */
bool resolve(porpoise::http::Session &http, const App &a, Target &t, std::string &err, const std::string &want = "")
{
    if (a.source != "github")
    {
        t.url = a.url;
        t.sha256 = a.sha256;
        t.version = a.version;
        t.size = a.bytes; /* (1.1.2) a beta's, from the site; 0 when it isn't said */
        if (t.url.empty()) { err = "This game has no download link yet."; return false; }
        /* (2.1) A beta needs its signature from the owner's PC; a fixed link, its checksum in
         * the signed catalog. */
        if (a.beta && a.sig.empty()) { err = "This beta isn't signed yet, so it can't be installed. Try again later."; return false; }
        if (!a.beta && t.sha256.empty()) { err = "This download has no checksum in the store's list, so it can't be checked."; return false; }
        return true;
    }
    /* The newest release that has the game's zip. Pre-releases count: many ports ship as
     * one before they call a version final. */
    std::vector<std::uint8_t> body;
    const int status = github_releases(http, a.repo, body);
    Json list;
    if (status != 200 || !Json::parse(std::string(body.begin(), body.end()), list) || !list.is_array())
    {
        err = status == 404 ? "The store can't find this game's GitHub page. It may be private."
                            : "GitHub didn't answer. Try again in a moment.";
        return false;
    }
    const Json *pick = nullptr;
    for (std::size_t r = 0; r < list.size() && !pick; ++r)
    {
        if (list[r]["draft"].boolean()) continue;
        const Json &assets = list[r]["assets"];
        for (std::size_t i = 0; i < assets.size(); ++i)
            if (asset_matches(a.asset, assets[i]["name"].str()))
            {
                /* (2.1) The newest release with the app's file is noted; a version picked takes
                 * only the release with that tag. */
                if (t.newest.empty()) t.newest = list[r]["tag_name"].str();
                if (!want.empty() && list[r]["tag_name"].str() != want) break;
                pick = &list[r];
                t.url = assets[i]["browser_download_url"].str();
                t.size = static_cast<std::uint64_t>(assets[i]["size"].integer());
                const std::string digest = assets[i]["digest"].str();
                if (digest.rfind("sha256:", 0) == 0) t.sha256 = digest.substr(7);
                break;
            }
    }
    if (!pick && !want.empty() && !t.newest.empty())
    {
        err = "Version " + want + " isn't on GitHub any more, so nothing was installed. Pick another one.";
        return false;
    }
    if (!pick)
    {
        err = list.size() == 0 ? "This game has no release on GitHub yet."
                               : "None of this game's GitHub releases has " + a.asset + ".";
        return false;
    }
    if (!from_own_repo(t.url, a.repo))
    {
        slog("resolve %s: refused %s (not github.com/%s's releases)", a.id.c_str(), t.url.c_str(), a.repo.c_str());
        err = "This download isn't on " + a.repo + "'s own GitHub releases, so it wasn't installed.";
        return false;
    }
    remember_releases(a.repo, std::string(body.begin(), body.end()));
    const Json &rel = *pick;
    if (!a.sha256.empty() && version_key(rel["tag_name"].str()) == version_key(a.version))
        t.sha256 = a.sha256; /* the catalog's own hash for the release it names */
    t.version = rel["tag_name"].str();
    if (t.url.empty()) { err = "The newest release on GitHub has no " + a.asset + "."; return false; }
    return true;
}

/* (1.1.2) A download from the site whose size the site says (a beta game's): in 4 MB
 * pieces, each asked for with a Range header and given two minutes. In 1.1.1 a beta
 * download from recompstore.com, asked for in one request, sat at Downloading on a PS5 with
 * no error and nothing arriving. A piece that comes back short or not at all is asked for
 * again from where it stopped, on a fresh connection; five tries in a row that bring nothing
 * new give up. 200 once all of it is here. */
double mono_s();
bool cancel_now();
/* (2.2.3) The one HTTP session handed to whoever is waiting for it (Send logs, patch notes, the
 * pictures), then taken back: a long download no longer holds everything else up. false when the
 * network couldn't be set up again. */
bool hand_session_over(porpoise::http::Session &http)
{
    if (!porpoise::http::Session::others_waiting()) return true;
    http.term();
    /* Until the one waiting has taken it (it's busy then, and init() below waits for it). */
    for (int i = 0; i < 30 && porpoise::http::Session::others_waiting(); ++i) usleep(10000);
    static unsigned told = 0;
    if (told++ < 20) slog("download: let another request go first");
    return http.init();
}

/* (2.2.3) Whether a GitHub download can go on from part way (a Range request answered with the
 * part, through GitHub's redirect): 0 not known yet, 1 yes, -1 no (it then never hands over). */
std::atomic<int> g_gh_ranges{0};

int download_in_parts(porpoise::http::Session &http, const std::string &url, std::uint64_t size,
                      const std::function<bool(const std::uint8_t *, std::size_t)> &take, std::uint64_t &got,
                      const bool &write_ok)
{
    constexpr std::uint64_t kPart = 4ull << 20;
    int idle = 0;
    while (got < size)
    {
        if (!hand_session_over(http)) return -1; /* (2.2.3) between pieces */
        const std::uint64_t from = got, to = std::min(size, from + kPart) - 1;
        const double t0 = mono_s();
        const int st = http.get_part(
            url, from, to,
            [&](const std::uint8_t *p, std::size_t n) {
                if (got + n > size) n = std::size_t(size - got); /* never past the end the site said */
                return n > 0 && take(p, n) && got < size;
            },
            120);
        if (!write_ok || cancel_now()) return -1; /* (2.2.2) cancel_now: the player stopped it */
        if (got == size || (st == 206 && got > to)) continue; /* the piece, whole */
        const bool moved = got > from;
        slog("download: bytes %llu-%llu: HTTP %d (error %#x) after %.0f s, %llu bytes came; %s", (unsigned long long)from,
             (unsigned long long)to, st, unsigned(http.last_error()), mono_s() - t0, (unsigned long long)(got - from),
             moved ? "asking for the rest" : idle + 1 >= 5 ? "giving up" : "asking again");
        if (moved)
            idle = 0;
        else if (++idle >= 5)
            return st == 200 || st == 206 ? -1 : st;
        /* A fresh connection for the next try, after a moment. */
        http.term();
        usleep(useconds_t(400000) * useconds_t(idle + 1));
        if (!http.init()) return -1;
    }
    return 200;
}

enum class Copier : int { Done, Failed, Unavailable };
Copier run_copier(const std::string &kind, const std::string &image, const std::string &target, bool *changed = nullptr,
                  const std::string &extra = "");

/* (2.0) The title ID guard (below, with the games already on the console). */
struct Clash
{
    bool found = false, pkg = false;
    std::string with; /* the other app's name, "" when it can't be told */
};
Clash find_clash(const App &a, const std::string &tid, const std::string &incoming_param);
std::string free_move_id();
void remember_own(const std::string &app_id, const std::string &tid);
std::string lower_text(std::string s);
std::string tid_folder(const std::string &tid);
/* (2.2.2) The same, with root in place of the usual homebrew folder for an app not on the console yet. */
std::string tid_folder_in(const std::string &tid, const std::string &root);
std::vector<std::string> homebrew_roots();
int forget_tile(const std::string &tid); /* (2.2) with the App manager */
std::string place_name(const std::string &root);
std::string size_words(std::uint64_t b);
/* (2.2.4) The filesystem path is on: its size and the bytes free for new files, from
 * ShadowMountPlus when it says (from_smp), else the drive's own statvfs; false when they can't
 * be told (Console storage without ShadowMountPlus). may_ask false: only what's known already
 * (the screens), never a request. */
/* real_space: declared at the top of this part, before link_state_json. */
/* (2.2.2) The bytes free for new files where path is (false when it can't be told). */
bool free_bytes(const std::string &path, std::uint64_t &out)
{
#ifdef RECOMP_ENGINE_TEST
    /* The tests' small drives: E222_FREE="/mnt/usb0=1000,/data=1000". */
    if (const char *t = std::getenv("E222_FREE"))
    {
        const std::string spec = std::string(t) + ",";
        for (std::size_t at = 0, c; (c = spec.find(',', at)) != std::string::npos; at = c + 1)
        {
            const std::string one = spec.substr(at, c - at);
            const std::size_t eq = one.find('=');
            if (eq != std::string::npos && path.rfind(one.substr(0, eq), 0) == 0)
            {
                out = std::strtoull(one.c_str() + eq + 1, nullptr, 10);
                return true;
            }
        }
    }
#endif
    /* (2.2.4) The real figures (real_space): never the sandbox's own for /data. */
    std::uint64_t total = 0;
    bool smp = false;
    return real_space(path, total, out, smp);
}
/* (2.2.2) The player stopped the install that's downloading (Engine::cancel_job). */
bool cancel_now()
{
    std::lock_guard<std::mutex> l(g.m);
    return g.cancel_wanted;
}

/* (2.0) The drive a folder is on: "/mnt/usb0", "/mnt/ext1", or "/data" for the console's own. */
std::string drive_of(const std::string &path)
{
    if (path.rfind("/mnt/", 0) != 0) return "/data";
    const std::size_t end = path.find('/', 5);
    return end == std::string::npos ? path : path.substr(0, end);
}

/* A drive that's there: an extended storage or M.2 folder that opens, a USB port with something in it. */
bool drive_ready(const std::string &drive)
{
    if (drive == "/data") return true;
    DIR *d = opendir(drive.c_str());
    if (!d) return false;
    bool any = false;
    while (dirent *e = readdir(d))
        if (std::strcmp(e->d_name, ".") && std::strcmp(e->d_name, "..")) { any = true; break; }
    closedir(d);
    return any || drive.rfind("/mnt/usb", 0) != 0;
}

/* (2.0) A custom app's release: the folder in it that's a PS5 app (eboot.bin with
 * sce_sys/param.json beside it, the shallowest), as the name app_prefix finds it by (hint: its
 * last part, "" at the top), and the title ID its param.json says. */
bool learn_title(const std::string &archive, const std::string &want, std::string &tid, std::string &hint, std::string &param,
                 std::string &why)
{
    std::vector<zip::Entry> entries;
    if (!zip::list_any(archive, entries))
    {
        why = "The download couldn't be read. Try installing it again.";
        return false;
    }
    std::set<std::string> names;
    for (const zip::Entry &e : entries) names.insert(lower_text(e.name));
    /* Every folder that's a PS5 app, the shallowest first. */
    std::vector<std::string> apps;
    for (const zip::Entry &e : entries)
    {
        const std::string n = lower_text(e.name);
        if (n.size() < 9 || n.compare(n.size() - 9, 9, "eboot.bin") != 0) continue;
        const std::string prefix = e.name.substr(0, e.name.size() - 9);
        if (!prefix.empty() && prefix.back() != '/') continue;
        if (names.count(lower_text(prefix) + "sce_sys/param.json") && apps.size() < 16) apps.push_back(prefix);
    }
    std::stable_sort(apps.begin(), apps.end(), [](const std::string &x, const std::string &y) {
        return std::count(x.begin(), x.end(), '/') < std::count(y.begin(), y.end(), '/');
    });
    if (apps.empty())
    {
        why = "This release isn't a PS5 app: it has no eboot.bin with an sce_sys/param.json beside it.";
        return false;
    }
    /* A release with several apps in it: the one whose title ID the source named, else the
     * shallowest. */
    Json pj;
    bool read_any = false;
    for (std::size_t i = 0; i < apps.size(); ++i)
    {
        std::string h = apps[i].empty() ? std::string() : apps[i].substr(0, apps[i].size() - 1);
        h = h.substr(h.find_last_of('/') + 1);
        std::string text;
        Json j;
        if (!zip::read_one(archive, h, "sce_sys/param.json", text, 1u << 20) || !Json::parse(text, j) || !j.is_object()) continue;
        if (want.empty() || j["titleId"].str() == want)
        {
            pj = j;
            hint = h;
            param = text;
            read_any = true;
            break;
        }
    }
    if (!read_any)
    {
        /* The app the source named isn't in this release (it was dropped from it): never
         * another app in its place. */
        why = want.empty() ? "The store couldn't read this release's sce_sys/param.json."
                           : "This release doesn't have the app with title ID " + want + " any more, so nothing was installed.";
        return false;
    }
    for (const unsigned char ch : hint)
        if (ch < 0x20 || ch == 0x7F || ch == '\\')
        {
            why = "This release's folder name has characters the store can't use, so it wasn't installed.";
            return false;
        }
    tid = pj["titleId"].str();
    if (!title_id_ok(tid))
    {
        why = "This release's param.json has no title ID a PS5 app can have (like PPSA12345).";
        return false;
    }
    if (tid == "PPSA92000")
    {
        why = "This release says it's Recomp Store itself (PPSA92000), so it wasn't installed.";
        return false;
    }
    return true;
}
void remember_move(const std::string &app_id, const std::string &new_id);
void forget_move(const std::string &app_id);
bool retitle(const std::string &folder, const std::string &from, const std::string &to, std::string &why);
void write_marker(const App &a, const std::string &folder, const std::string &title_id, const std::string &version);
void apply_moves(Catalog &c);
void scan_homebrew();
std::string field_of(const std::string &text, const char *key);

/* answer (2.0): 0 a plain install; after a title ID clash, 1 under its own new title ID,
 * 2 over the other app's folder; (2.1) 3 from its new GitHub repo (JobState::source_moved).
 * version (2.1): the one the player picked (Engine::install), "" for the newest. */
void run_install(const std::string &id, int answer, const std::string &version, const std::string &want_root)
{
    porpoise::http::Session http("store-install", "RecompStore/0.1");
    App a;
    bool found = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const App &x : g.catalog.apps)
            if (x.id == id) { a = x; found = true; }
        g.job = JobState();
        g.job.phase = Phase::Working;
        g.job.kind = "install";
        g.job.app_id = id;
        g.job.step = "Finding the newest release";
        g.job.version = version; /* (2.1) */
        g.job.root = want_root;  /* (2.2.2) */
        g.cancel_wanted = false;
    }
    if (!found) return fail_job("That game isn't in the catalog.");
    if (store_too_old(a))
        return fail_job(a.name + " needs Recomp Store " + a.min_store + " or newer. Update the store from the Library first (Updates).");
    /* (2.1) Where it downloads from changed: an app the store installed from one GitHub repo
     * whose catalog entry names another now. Nothing is downloaded until the player says the new
     * place is fine (resolve_clash 3, this once). Not asked for a beta, a fixed link or an app
     * from a source the player added. */
    if (answer != 3 && a.source == "github" && !a.beta && !a.custom && !a.repo.empty())
    {
        std::string text;
        Json mk;
        if (read_file(app_folder(a.id) + "/" + kMarker, text) && Json::parse(text, mk) && mk.is_object() && mk["app"].str() == a.id)
        {
            const std::string src = mk["source"].str();
            if (src.rfind("github:", 0) == 0 && src.size() > 7 && lower_text(src.substr(7)) != lower_text(a.repo))
            {
                slog("install %s: stopped before downloading: it came from github.com/%s, the catalog says github.com/%s now",
                     a.id.c_str(), src.c_str() + 7, a.repo.c_str());
                std::lock_guard<std::mutex> l(g.m);
                g.job.phase = Phase::Failed;
                g.job.source_moved = true;
                g.job.source_old = src.substr(7);
                g.job.source_new = a.repo;
                g.job.error = "Where " + a.name + " downloads from changed, so it wasn't updated.";
                return;
            }
        }
    }
    /* (2.0) Keep both: the game gets its own new title ID before anything is fetched, so the
     * install below goes to that ID's folder (and every update after it). */
    /* The move made here is taken back if the install stops before writing anything (a
     * download that didn't finish): the game isn't left under a new ID with nothing there. */
    struct MoveUndo
    {
        std::string id;
        bool armed = false;
        ~MoveUndo() { if (armed) forget_move(id); }
    } undo{a.id};
    if (answer == 1 && moved_id(a.id).empty())
    {
        if (!a.can_move) return fail_job(a.name + " has to keep its own title ID, so it can't be installed under a new one.");
        const std::string fresh = free_move_id();
        if (fresh.empty()) return fail_job("The store couldn't find a free title ID to give " + a.name + ".");
        remember_move(a.id, fresh);
        undo.armed = true;
        std::lock_guard<std::mutex> l(g.m);
        for (App &x : g.catalog.apps)
            if (x.id == a.id) a = x; /* its data folder now under the new ID */
    }
    /* (2.1) An earlier beta build the player picked: its own download, checksum, size and
     * signature, checked below as the current build's are. */
    bool older = false;
    if (!version.empty() && a.beta)
    {
        const BetaBuild *b = nullptr;
        for (const BetaBuild &x : a.beta_builds)
            if (x.file.empty() ? x.version == version : x.file == version) { b = &x; break; }
        if (!b) return fail_job("That build of " + a.name + " isn't there any more. Pick another one.");
        const BetaBuild pick = *b;
        a.url = pick.url;
        a.sha256 = pick.sha256;
        a.bytes = pick.bytes;
        a.sig = pick.sig;
        a.version = pick.version;
        older = true;
        slog("install %s: the earlier beta build %s (%s)", a.id.c_str(), pick.version.c_str(), pick.file.c_str());
    }
    if (!http.init()) return fail_job("Your PS5 isn't online.");

    Target t;
    std::string err;
    if (!resolve(http, a, t, err, a.source == "github" ? version : std::string())) return fail_job(err);
    if (!version.empty() && a.source == "github")
    {
        /* (2.1) A release the player picked: older than the newest, the app is kept at it. */
        older = !t.newest.empty() && t.version != t.newest;
        slog("install %s: version %s picked (the newest is %s)", a.id.c_str(), t.version.c_str(), t.newest.c_str());
    }

    /* (2.0) The download kept from an install that stopped on a title ID clash: taken as it
     * is when it's the same file, rather than downloaded twice. */
    make_dirs(g.data + "/downloads");
    const std::string held = g.data + "/downloads/" + a.id + ".held", held_info = held + ".txt";
    bool reused = false, reused_inner = false;
    {
        std::string info;
        struct stat hs;
        if (answer != 0 && read_file(held_info, info) && stat(held.c_str(), &hs) == 0 && field_of(info, "url") == t.url &&
            (t.size == 0 || field_of(info, "inner") == "1" || std::uint64_t(hs.st_size) == t.size))
        {
            reused = true;
            reused_inner = field_of(info, "inner") == "1";
            slog("install %s: using the download kept from before (%lld bytes)", a.id.c_str(), (long long)hs.st_size);
        }
        else
            std::remove(held.c_str());
        std::remove(held_info.c_str());
    }

    /* (2.0) Where it goes: where it already is, else the homebrew folder the player chose (the
     * console's own, or a drive's). A drive that isn't there now stops it before the download. */
    /* (2.2.2) A new app goes to the homebrew folder the player picked for it, if they did. */
    const std::string dest = tid_folder_in(installed_id(a.id), want_root), drive = drive_of(dest);
    if (drive != "/data" && !drive_ready(drive))
        return fail_job(a.name + " installs to " + dest.substr(0, dest.find_last_of('/')) +
                        ", and that drive isn't connected. Connect it, or pick another place in Settings > Customize.");
    /* Room for the zip and its unpacked files on disk, with some to spare: on the console for
     * the download, and where it unpacks. */
    /* (2.2.2) Said with the numbers, and before anything is downloaded. */
    const std::uint64_t data_need = t.size * (drive == "/data" ? 3 : 1) + (512ull << 20);
    std::uint64_t room = 0;
    if (!reused && t.size && free_bytes("/data", room) && room < data_need)
    {
        slog("install %s: refused before downloading: %llu bytes free on /data, %llu needed", a.id.c_str(), (unsigned long long)room,
             (unsigned long long)data_need);
        return fail_job("Not enough space on Console storage for " + a.name + ": it needs about " + size_words(data_need) + " and " +
                        size_words(room) + " is free. Clear the download cache in Settings > Storage, or make some room.");
    }
    if (!reused && drive != "/data" && t.size && free_bytes(drive, room) && room < t.size * 2 + (64ull << 20))
    {
        slog("install %s: refused before downloading: %llu bytes free on %s, %llu needed", a.id.c_str(), (unsigned long long)room,
             drive.c_str(), (unsigned long long)(t.size * 2 + (64ull << 20)));
        return fail_job("Not enough space on " + place_name(dest.substr(0, dest.find_last_of('/'))) + " for " + a.name + ": it needs about " +
                        size_words(t.size * 2 + (64ull << 20)) + " and " + size_words(room) + " is free. Pick another place, or make some room.");
    }

    /* Download to the store's own folder, checking the checksum as it arrives. */
    const std::string zip_path = g.data + "/downloads/" + a.id + ".zip";
    const std::string inner_path = g.data + "/downloads/" + a.id + ".inner.zip";
    std::remove(zip_path.c_str());
    Sha256 sha;
    std::uint64_t got = 0;
    bool write_ok = true, cancelled = false;
    int status = 200;
    if (reused)
    {
        struct stat hs;
        if (stat(held.c_str(), &hs) == 0) got = std::uint64_t(hs.st_size);
        if (std::rename(held.c_str(), (reused_inner ? inner_path : zip_path).c_str()) != 0) status = -1;
        t.size = got; /* checked when it was downloaded; (2.0) a kept inner zip is its own size */
    }
    else
    {
        std::FILE *out = std::fopen(zip_path.c_str(), "wb");
        if (!out) return fail_job("The store couldn't save the download on your PS5.");
        set_job("Downloading", 0, t.size);
        timespec dl0;
        clock_gettime(CLOCK_MONOTONIC, &dl0);
        /* (2.2.3) While one long request streams, another thread waiting for the session (Send logs,
         * patch notes, pictures) gets it between pieces: the stream stops, the session goes over, and
         * the rest comes in parts. Progress is told about 10 times a second, not every read. */
        bool streaming = false, hand_over = false;
        double told = 0;
        std::uint64_t since_yield = 0;
        auto take = [&](const std::uint8_t *p, std::size_t n) {
            if (cancel_now()) { cancelled = true; return false; } /* (2.2.2) the player stopped it */
            if (std::fwrite(p, 1, n, out) != n) { write_ok = false; return false; }
            sha.update(p, n);
            got += n;
            const double now = mono_s();
            if (now - told >= 0.1 || got == t.size)
            {
                told = now;
                set_job("Downloading", got, t.size);
            }
            if ((since_yield += n) >= (1u << 20))
            {
                since_yield = 0;
                sched_yield(); /* the screens first */
            }
            if (streaming && g_gh_ranges.load() == 1 && t.size > got && porpoise::http::Session::others_waiting())
            {
                hand_over = true;
                return false;
            }
            return true;
        };
        const bool in_parts = a.source != "github" && t.size > 0;
        slog("download %s: started%s", a.id.c_str(), in_parts ? " (in parts)" : "");
        if (!in_parts && t.size > (8u << 20) && g_gh_ranges.load() == 0)
        {
            /* Can it go on from part way? One byte asked for, through GitHub's redirect. */
            const int pst = http.get_part(t.url, 1, 1, [](const std::uint8_t *, std::size_t) { return false; }, 30);
            g_gh_ranges = pst == 206 ? 1 : -1;
            slog("download: a part of a GitHub file: HTTP %d (%s)", pst,
                 pst == 206 ? "other requests can go first during downloads" : "downloads keep the connection to the end");
        }
        streaming = !in_parts;
        status = in_parts ? download_in_parts(http, t.url, t.size, take, got, write_ok) : http.get_stream(t.url, take);
        streaming = false;
        while (hand_over && !cancelled && write_ok && got < t.size)
        {
            hand_over = false;
            status = hand_session_over(http) ? download_in_parts(http, t.url, t.size, take, got, write_ok) : -1;
        }
        set_job("Downloading", got, t.size);
        std::fclose(out);
        timespec dl1;
        clock_gettime(CLOCK_MONOTONIC, &dl1);
        const double secs = double(dl1.tv_sec - dl0.tv_sec) + double(dl1.tv_nsec - dl0.tv_nsec) / 1e9;
        slog("download %s: HTTP %d, %llu bytes (expected %llu) in %.0f s, %.1f MB/s", t.url.c_str(), status,
             (unsigned long long)got, (unsigned long long)t.size, secs, secs > 0 ? double(got) / 1048576.0 / secs : 0.0);
        char st[128];
        std::snprintf(st, sizeof st, "%.0f MB in %.0f s \xC2\xB7 %.1f MB/s", double(got) / 1048576.0, secs,
                      secs > 0 ? double(got) / 1048576.0 / secs : 0.0);
        set_stat("Last download", st);
    }
    struct ZipFile
    {
        std::string path;
        std::FILE *f = nullptr;
        ~ZipFile() { if (f) std::fclose(f); std::remove(path.c_str()); } /* the store's own download, not the game */
    } zf{zip_path}, kept_inner{reused_inner ? inner_path : std::string()}; /* (2.0) a kept inner zip goes too */
    if (cancelled || cancel_now())
    {
        /* (2.2.2) Stopped by the player: nothing was written but the download, which goes. */
        slog("install %s: cancelled while downloading (%llu bytes came)", a.id.c_str(), (unsigned long long)got);
        std::lock_guard<std::mutex> l(g.m);
        g.cancel_wanted = false;
        g.job.phase = Phase::Failed;
        g.job.cancelled = true;
        g.job.error = "Cancelled. Nothing was installed.";
        return;
    }
    if (!write_ok) return fail_job("Your PS5 ran out of room while downloading.");
    if (status != 200 || got == 0 || (t.size && got != t.size))
        return fail_job("The download didn't finish. Check your connection and try again.");

    set_job("Checking the download", got, got);
    const std::string got_hex = reused ? std::string() : sha.hex(); /* (finishes the hash: once) */
    if (!reused && !t.sha256.empty())
    {
        std::string want = t.sha256;
        for (char &c : want) c = char(std::tolower(static_cast<unsigned char>(c)));
        if (got_hex != want) return fail_job("The download doesn't match its checksum, so it wasn't installed.");
    }
    if (!reused && a.beta)
    {
        /* (2.1) Signed on the owner's PC: a beta zip swapped on the website or R2 fails here. */
        const std::string why = sig_problem(a.sig, "beta", got_hex);
        if (!why.empty())
        {
            slog("download %s: beta refused, %s", a.id.c_str(), why.c_str());
            return fail_job("This beta zip couldn't be checked (" + why + "), so it wasn't installed.");
        }
    }
    /* A release that only has a bigger zip (a source archive): the game's own zip is taken
     * out of it, the big one deleted, and the game's zip installed as any other. */
    std::string install_zip = zip_path;
    struct InnerFile
    {
        std::string path;
        ~InnerFile() { if (!path.empty()) std::remove(path.c_str()); }
    } inner;
    if (reused_inner)
    {
        inner.path = inner_path;
        install_zip = inner_path;
    }
    else if (!a.inside.empty())
    {
        set_job("Taking out the game's files", 0, 0);
        const std::string pattern = a.inside;
        std::string name, why;
        inner.path = g.data + "/downloads/" + a.id + ".inner.zip";
        /* As many folders deep as the pattern: its * never reaches into a deeper copy. */
        const auto depth = [](const std::string &s) { return std::count(s.begin(), s.end(), '/'); };
        if (!zip::take_inner(
                zip_path, [&](const std::string &n) { return depth(n) == depth(pattern) && asset_matches(pattern, n); },
                inner.path,
                [](const char *step, std::uint64_t d, std::uint64_t n) { set_job(step, d, n); }, name, why))
        {
            slog("install %s: taking %s out of the download failed: %s", a.id.c_str(), pattern.c_str(), why.c_str());
            return fail_job(why);
        }
        struct stat st;
        slog("install %s: took %s out of the download (%lld bytes)", a.id.c_str(), name.c_str(),
             stat(inner.path.c_str(), &st) == 0 ? (long long)st.st_size : -1LL);
        std::remove(zip_path.c_str()); /* its room back before unpacking */
        install_zip = inner.path;
    }

    /* (2.0) A custom source's app: its title ID is the one its release's param.json says (an
     * ID like "gh-..." is only the store's name for it), and its folder in the archive is the
     * one that's a PS5 app. A catalog app's folder is named after its ID, as before. */
    std::string own = a.id, hint = a.id, learned_param;
    if (a.custom)
    {
        set_job("Reading the release", 0, 0);
        std::string why;
        const std::string want = !a.title_id.empty() ? a.title_id : a.id.rfind("gh-", 0) != 0 ? a.id : std::string();
        if (!learn_title(install_zip, want, own, hint, learned_param, why))
        {
            slog("install %s: %s", a.id.c_str(), why.c_str());
            return fail_job(why);
        }
        slog("install %s: a PS5 app with title ID %s, in %s", a.id.c_str(), own.c_str(), hint.empty() ? "the top of the archive" : hint.c_str());
        if (moved_id(a.id).empty() && answer != 2)
        {
            /* The catalog's own app, or another source's, with this title ID: that's the one. */
            std::lock_guard<std::mutex> l(g.m);
            for (const App &x : g.catalog.apps)
                if (x.id != a.id && !x.custom && x.id == own && exists(g.data + "/installed/" + x.id + ".txt"))
                    slog("install %s: %s from the catalog is installed with the same title ID", a.id.c_str(), x.id.c_str());
        }
    }

    /* (2.0) The title ID guard: another app on this PS5 with this game's title ID stops the
     * install before anything is written. The download is kept and the player picks: keep
     * both (this one under a new ID), install over it (an older copy of the same app), or
     * neither. A game already under its new ID, or going over by choice, isn't asked again. */
    if (answer != 2 && moved_id(a.id).empty())
    {
        std::string incoming = learned_param; /* a custom app's, read already */
        if (incoming.empty() && !zip::read_one(install_zip, hint, "sce_sys/param.json", incoming, 1u << 20)) incoming.clear();
        const Clash c = find_clash(a, own, incoming);
        if (c.found)
        {
            const bool is_inner = install_zip != zip_path;
            std::remove(held.c_str());
            if (std::rename(install_zip.c_str(), held.c_str()) == 0)
            {
                const std::string info = "url=" + t.url + "\ninner=" + (is_inner ? "1" : "0") + "\n";
                write_file(held_info, info.data(), info.size());
            }
            const std::string fresh = a.can_move ? free_move_id() : std::string();
            slog("install %s: stopped before writing anything: title ID %s is %s on this console (%s)%s%s", a.id.c_str(),
                 own.c_str(), c.pkg ? "an installed package's" : "another app's folder", c.with.empty() ? "?" : c.with.c_str(),
                 fresh.empty() ? "" : "; it can go under ", fresh.c_str());
            std::lock_guard<std::mutex> l(g.m);
            g.job.phase = Phase::Failed;
            g.job.clash = true;
            g.job.clash_pkg = c.pkg;
            g.job.clash_with = c.with;
            g.job.clash_new = fresh;
            g.job.clash_tid = own;
            g.job.error = (c.with.empty() ? std::string("Another app") : c.with) + " already uses " + a.name + "'s title ID (" +
                          own + ") on your PS5, so nothing was installed.";
            return;
        }
    }

    /* Unpacking: by the copier payload at the drive's full speed (the PS5 holds an app's
     * writes back after about a gigabyte), or here when there's no payload loader. */
    /* Where it already is, if it's on the console: under its new title ID when it was moved,
     * else its own (a custom app's, as just read). */
    const std::string under = moved_id(a.id).empty() ? own : moved_id(a.id);
    const std::string root = tid_folder_in(under, want_root); /* (2.2.2) where it already is, else where the player picked */
    const std::string record = g.data + "/installed/" + a.id + ".txt";
    /* (2.1.1) Already on the console: an update, which ShadowMountPlus doesn't need to add. */
    const bool was_there = !fs::gone(root + "/eboot.bin");
    make_dirs(root.substr(0, root.find_last_of('/'))); /* (2.0) a drive's homebrew folder, the first time */
    undo.armed = false; /* writing starts: a move made for it stays, so trying again goes to the same place */
    std::size_t nfiles = 0;
    {
        bool changed = false;
        /* (1.1.2) The player's own files an update leaves alone. */
        std::string keep_job;
        for (std::size_t k = 0; k < a.keep.size(); ++k) keep_job += "keep" + std::to_string(k) + "=" + a.keep[k] + "\n";
        keep_job += "app=" + hint + "\n"; /* (1.1.3) the folder of the zip that's this game's */
        const Copier c = run_copier("unzip", install_zip, root, &changed, keep_job);
        if (c == Copier::Failed)
        {
            if (changed) std::remove(record.c_str()); /* half updated: offer the install again */
            return;
        }
        if (c == Copier::Unavailable)
        {
            std::string error;
            if (!zip::unpack(
                    install_zip, root, [](const char *step, std::uint64_t d, std::uint64_t n) { set_job(step, d, n); },
                    [](const std::string &l) { slog("%s", l.c_str()); }, nullptr, error, changed, nfiles, &a.keep, hint))
            {
                if (changed) std::remove(record.c_str());
                return fail_job(error);
            }
        }
        else
        {
            /* The store checks the copier's work itself: every file read back against the
             * download, (2.0) a zip or a .7z. */
            std::vector<zip::Entry> entries;
            const bool listed = zip::list_any(install_zip, entries);
            if (!listed)
            {
                std::remove(record.c_str());
                return fail_job("The store couldn't read the download back to check the install. Install it again.");
            }
            const std::string strip = zip::app_prefix(entries, hint);
            std::uint64_t i = 0;
            for (const zip::Entry &e : entries)
            {
                set_job("Checking the installed files", ++i, entries.size());
                if (e.name.compare(0, strip.size(), strip) != 0) continue; /* (1.1.3) not the game's folder */
                const std::string name = e.name.substr(strip.size());
                if (name.empty() || name.back() == '/') continue;
                if (zip::kept(name, a.keep)) continue; /* the player's own, as the copier left it */
                std::string why;
                if (!zip::safe_name(name) || !zip::entry_matches(root + "/" + name, e, &why))
                {
                    slog("install check %s: %s", name.c_str(), why.c_str());
                    std::remove(record.c_str());
                    return fail_job(name + " doesn't match the download after installing. Install it again.");
                }
                ++nfiles;
            }
            slog("install: the store checked all %zu files against the download", nfiles);
        }
    }

    /* (2.0) A game under a new title ID: its param.json says so (the release's says its own),
     * after every install and update. Then the marker that says whose folder this is. */
    const std::string tid = under;
    if (tid != own)
    {
        std::string why;
        if (!retitle(root, own, tid, why))
        {
            slog("install %s: giving it title ID %s failed: %s", a.id.c_str(), tid.c_str(), why.c_str());
            std::remove(record.c_str());
            return fail_job("The store couldn't give " + a.name + " its new title ID (" + tid + "). Install it again.");
        }
    }
    const std::string ver = t.version.empty() ? a.version : t.version;
    if (a.custom) remember_own(a.id, own); /* its folder is found by this from now on */
    write_marker(a, root, tid, ver);
    make_dirs(g.data + "/installed");
    write_file(g.data + "/installed/" + a.id + ".txt", ver.data(), ver.size());
    {
        /* (2.1) An older version the player picked: kept at it (Update All leaves it) until a
         * newer one is installed, which lets it go. */
        const std::string hold = g.data + "/installed/" + a.id + ".hold";
        if (older) write_file(hold, ver.data(), ver.size());
        else std::remove(hold.c_str());
        std::lock_guard<std::mutex> l(g.m);
        if (older) g.holds[a.id] = ver;
        else g.holds.erase(a.id);
    }
    slog("installed %s %s: %zu files in %s%s", a.id.c_str(), ver.c_str(), nfiles, root.c_str(),
         tid != a.id ? (" as " + tid).c_str() : "");
    {
        /* (2.0) Counted for the charts (the link thread tells the site). */
        std::lock_guard<std::mutex> l(g.m);
        g.stats_due.push_back("+" + a.id + " " + ver);
    }
    scan_homebrew();
    {
        /* (2.0) Its data folders follow it, where it is now. */
        std::lock_guard<std::mutex> l(g.m);
        apply_moves(g.catalog);
        ++g.revision;
    }
    std::string smp_why;
    const int home = refresh_smp(smp_why);
    if (home == 0) slog("install: ShadowMountPlus: %s", smp_why.c_str());
    smp_scan_later();
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.home = home;
    g.job.step = "Installed";
    g.job.installed_version = ver;
    g.job.done = g.job.total;
    /* (2.1.1) An app left open in the background keeps running the old version: Open only
     * brings it back. */
    if (was_there)
        g.job.note = "Updated to " + ver + ". If " + a.name + " is still open in the background, close it first "
                     "(Options > Close Application on its tile), or Open brings back the old version.";
}

/* ---- How files are written: the speed test (Settings > Diagnostics) tries each of
 * these on the console's own storage and the copy uses the fastest. ----------------- */
struct WriteMode
{
    const char *name;
    std::size_t chunk;
    std::uint64_t sync_every;
    bool presize, reserve, direct;
};
const WriteMode kModes[] = {
    {"16 MB writes", 16u << 20, 0, false, false, false},
    {"16 MB writes, saved every 64 MB", 16u << 20, 64ull << 20, false, false, false},
    {"1 MB writes", 1u << 20, 0, false, false, false},
    {"Size set first", 16u << 20, 0, true, false, false},
    {"Direct writes", 16u << 20, 0, false, false, true},
    {"Direct writes, size set first", 16u << 20, 0, true, false, true},
};
constexpr int kModeCount = int(sizeof kModes / sizeof kModes[0]);
int g_write_mode = 0;

double mono_s()
{
    timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return double(ts.tv_sec) + double(ts.tv_nsec) / 1e9;
}

/* A buffer that direct writes accept: 4 KB aligned. */
/* From the app's own malloc (the one the title wraps; aligned_alloc goes to libc's small
 * heap on the PS5 and fails for big buffers), aligned by hand. */
struct Aligned
{
    std::uint8_t *p = nullptr;
    std::size_t n = 0;
    void *raw = nullptr;
    explicit Aligned(std::size_t size) : n(size)
    {
        raw = std::malloc(size + 4096);
        if (raw)
            p = reinterpret_cast<std::uint8_t *>((reinterpret_cast<std::uintptr_t>(raw) + 4095) & ~std::uintptr_t(4095));
        else
            slog("couldn't get %zu MB of memory", size >> 20);
    }
    ~Aligned() { std::free(raw); }
    Aligned(const Aligned &) = delete;
    Aligned &operator=(const Aligned &) = delete;
};

struct OutFile
{
    int fd = -1;
    const WriteMode *mode = nullptr;
    std::uint64_t since_sync = 0, size = 0, written = 0;
    bool direct = false;
    double sync_s = 0;

    bool open_for(const std::string &path, std::uint64_t total, const WriteMode &m)
    {
        mode = &m;
        size = total;
        direct = m.direct;
        fd = ::open(path.c_str(), O_WRONLY | O_CREAT | O_TRUNC | (m.direct ? O_DIRECT : 0), 0777);
        if (fd < 0 && m.direct)
        {
            direct = false; /* this storage refuses direct writes: plain ones */
            fd = ::open(path.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0777);
        }
        if (fd < 0) return false;
        if (total && m.presize) ftruncate(fd, off_t(total));
        return true;
    }
    bool write(const std::uint8_t *p, std::size_t n)
    {
        /* Direct writes take whole 4 KB pieces: the last odd piece goes plain. */
        if (direct && (n % 4096) != 0)
        {
            const int fl = fcntl(fd, F_GETFL);
            fcntl(fd, F_SETFL, fl & ~O_DIRECT);
            direct = false;
        }
        std::size_t at = 0;
        while (at < n)
        {
            const ssize_t w = ::write(fd, p + at, n - at);
            if (w < 0 && errno == EINTR) continue;
            if (w <= 0) return false;
            at += std::size_t(w);
        }
        written += n;
        since_sync += n;
        if (mode->sync_every && since_sync >= mode->sync_every)
        {
            const double t0 = mono_s();
            fsync(fd);
            sync_s += mono_s() - t0;
            since_sync = 0;
        }
        return true;
    }
    bool close_file()
    {
        if (fd < 0) return true;
        if (mode->presize && written < size) ftruncate(fd, off_t(written)); /* a stopped copy: no padding left */
        const bool ok = ::close(fd) == 0;
        fd = -1;
        return ok;
    }
};

/* ---- Game files from the player's disc image ------------------------------------- */
std::string fmt_mb(std::uint64_t b)
{
    char t[32];
    if (b >= (1ull << 30)) std::snprintf(t, sizeof t, "%.1f GB", double(b) / double(1ull << 30));
    else std::snprintf(t, sizeof t, "%llu MB", (unsigned long long)((b + (1u << 19)) >> 20));
    return t;
}

/* ---- The copier payload: the setup's copy done outside the app ------------------- *
 * The PS5 lets an app write only about a gigabyte quickly before it slows it to a few
 * MB/s (measured on the console: 200 MB/s, then 2). Payloads aren't held back - an FTP
 * payload writes the same drive at 100 MB/s - so the store sends its copier to the
 * payload loader on port 9021 (etaHEN, elfldr: every loader listens there) and follows
 * its progress file. With no loader, or no copier in the app, the store copies itself. */
std::string field_of(const std::string &text, const char *key)
{
    const std::string k = std::string(key) + "=";
    std::size_t at = 0;
    while (at < text.size())
    {
        std::size_t end = text.find('\n', at);
        if (end == std::string::npos) end = text.size();
        if (text.compare(at, k.size(), k) == 0) return text.substr(at + k.size(), end - at - k.size());
        at = end + 1;
    }
    return "";
}

#ifdef MSG_NOSIGNAL
constexpr int kNoSignal = MSG_NOSIGNAL;
#else
constexpr int kNoSignal = 0;
#endif

bool send_to_loader(const std::string &elf, int &err)
{
    err = 0;
    const int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) { err = errno; return false; }
    timeval limit{};
    limit.tv_sec = 3;
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &limit, sizeof limit);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &limit, sizeof limit);
#ifdef SO_NOSIGPIPE
    {
        const int one = 1; /* a loader that hangs up mustn't end the store */
        setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &one, sizeof one);
    }
#endif
    sockaddr_in sa{};
    sa.sin_family = AF_INET;
    sa.sin_port = htons(9021);
    sa.sin_addr.s_addr = htonl(0x7F000001);
    if (connect(fd, reinterpret_cast<sockaddr *>(&sa), sizeof sa) != 0)
    {
        err = errno;
        close(fd);
        return false;
    }
    std::size_t at = 0;
    while (at < elf.size())
    {
        const ssize_t n = send(fd, elf.data() + at, elf.size() - at, kNoSignal);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) { err = errno; close(fd); return false; }
        at += std::size_t(n);
    }
    shutdown(fd, SHUT_WR);
    close(fd);
    return true;
}

/* kind "setup": copy a disc image's files into target. kind "unzip": unpack the store's
 * verified download (image) into the game's folder (target). kind "delete": delete the
 * paths in extra (path0=, path1=, ...). *changed (unzip) says whether an old file had
 * been replaced before a failure. */
Copier run_copier(const std::string &kind, const std::string &image, const std::string &target, bool *changed,
                  const std::string &extra)
{
    const bool unzip = kind == "unzip", del = kind == "delete", tree = kind == "tree" /* (2.2) Move */;
    const bool bk = kind == "backup", rs = kind == "restore"; /* (2.3.0) save backups */
    const char *what = unzip ? "install" : del ? "delete" : tree ? "move" : bk ? "backup" : rs ? "restore" : "setup";
    const char *fallback = unzip ? "installing in the store" : del ? "deleting in the store" : bk ? "backing up in the store"
                           : rs ? "restoring in the store" : "copying in the store";
    if (changed) *changed = false;
    const std::string elf_path = std::string(PORPOISE_APP) + "/assets/payloads/recompstore-copier.elf";
    std::string elf;
    if (!read_file(elf_path, elf) || elf.size() < 64 || elf.compare(0, 4, "\x7F" "ELF") != 0)
    {
        slog("%s: the app has no copier payload (%s); %s", what, elf_path.c_str(), fallback);
        return Copier::Unavailable;
    }
    if (g.data != "/data/recompstore" && std::getenv("RECOMP_TEST_COPIER") == nullptr)
    {
        slog("%s: the store's folder is %s, not /data/recompstore; %s", what, g.data.c_str(), fallback);
        return Copier::Unavailable;
    }
    const std::string jd = g.data + "/copyjob";
    make_dirs(jd);
    /* A copier still working from before (the store was closed during a setup or install). */
    {
        struct stat st;
        std::string last;
        /* One that has said it's done or failed is only letting go (a set of files is
         * copied one after another). */
        const bool finished = read_file(jd + "/status.txt", last) &&
                              (last.find("\nphase=done\n") != std::string::npos || last.find("\nphase=failed\n") != std::string::npos);
        if (!finished && exists(jd + "/running") && stat((jd + "/status.txt").c_str(), &st) == 0 && std::time(nullptr) - st.st_mtime < 10)
        {
            fail_job("A game setup or install is still running from before. Wait for it to finish, then try again.");
            return Copier::Failed;
        }
    }
    std::remove((jd + "/running").c_str());
    std::remove((jd + "/cancel").c_str());
    std::remove((jd + "/status.txt").c_str());
    char token[48];
    std::snprintf(token, sizeof token, "%lld-%d", (long long)std::time(nullptr), int(mono_s() * 1000) % 100000);
    const std::string job = std::string("token=") + token + "\nkind=" + kind + "\nimage=" + image + "\ntarget=" + target + "\n" + extra;
    if (!write_file(jd + "/job.txt", job.data(), job.size()))
    {
        slog("%s: couldn't write the copier's job; %s", what, fallback);
        return Copier::Unavailable;
    }
    int err = 0;
    if (!send_to_loader(elf, err))
    {
        slog("%s: no payload loader answered on port 9021 (errno %d); %s, which is slower", what, err, fallback);
        return Copier::Unavailable;
    }
    slog("%s: sent the copier (%zu KB) to the payload loader on port 9021", what, elf.size() / 1024);
    set_job(unzip ? "Installing" : del ? "Deleting" : "Starting the copy", 0, 0);

    const double sent = mono_s();
    double last_change = sent;
    std::string last;
    bool started = false;
    for (;;)
    {
        usleep(250000);
        std::string st;
        if (read_file(jd + "/status.txt", st) && field_of(st, "token") == token)
        {
            if (!started) slog("%s: the copier started after %.1f s", what, mono_s() - sent);
            started = true;
            if (st != last)
            {
                last = st;
                last_change = mono_s();
            }
            const std::string phase = field_of(st, "phase");
            const std::uint64_t done = std::strtoull(field_of(st, "done").c_str(), nullptr, 10);
            const std::uint64_t total = std::strtoull(field_of(st, "total").c_str(), nullptr, 10);
            if (phase == "copying")
                set_job(tree ? "Copying" : "Copying the game's files", done, total);
            else if (phase == "installing")
                set_job("Installing", done, total);
            else if (phase == "deleting")
                set_job("Deleting", 0, 0);
            else if (phase == "backingup")
                set_job("Backing up your saves", done, total);
            else if (phase == "restoring")
                set_job("Restoring your saves", done, total);
            else if (phase == "checking")
                set_job(unzip ? "Checking the installed files" : "Checking the copied files", unzip ? done : total, total);
            else if (phase == "done")
            {
                const double secs = std::strtod(field_of(st, "seconds").c_str(), nullptr);
                if (unzip || del || tree || bk || rs)
                    slog("%s: the copier finished: %s files in %.1f s", what, field_of(st, "files").c_str(), secs);
                else
                {
                    char line[160];
                    std::snprintf(line, sizeof line, "%.0f MB in %.0f s \xC2\xB7 %.1f MB/s (copier payload)", double(total) / 1048576.0, secs,
                                  secs > 0 ? double(total) / 1048576.0 / secs : 0.0);
                    set_stat("Last disc setup", line);
                    slog("setup: the copier finished: %s", line);
                }
                return Copier::Done;
            }
            else if (phase == "failed")
            {
                if (changed) *changed = field_of(st, "changed") == "1";
                const std::string why = field_of(st, "error");
                fail_job(why.empty() ? std::string("The copier stopped with an error.") : why);
                return Copier::Failed;
            }
            else if (!unzip && !del && !tree && !bk && !rs)
                set_job("Reading the disc image", 0, 0);
        }
        if (!started && mono_s() - sent > 15)
        {
            /* A copier that starts late must not work beside the store: tell it to stop. */
            const char stop[] = "stop\n";
            write_file(jd + "/cancel", stop, sizeof stop - 1);
            slog("%s: the payload loader took the copier but it didn't start within 15 s; %s instead", what, fallback);
            return Copier::Unavailable;
        }
        if (started && mono_s() - last_change > 30)
        {
            if (changed) *changed = true; /* can't tell how far it got */
            fail_job(unzip ? "The copier stopped answering. Install the game again."
                     : bk ? "The copier stopped answering. Back up again."
                     : rs ? "The copier stopped answering. Check the game's saves before trying again."
                     : tree ? "The copier stopped answering. Check both places before trying again."
                     : del ? "The copier stopped answering. Press Delete again to finish."
                           : "The copier stopped answering. Set up again to carry on where it stopped.");
            return Copier::Failed;
        }
    }
}

/* A Title Update (one package), DLC (every package of the game's in the chosen file's
 * folder) or, kind "arcade" (1.0.7), an Xbox Live Arcade game's own package, unpacked where
 * the port reads them: an update's or an Arcade game's files into the slot's folder, each
 * piece of DLC into a folder of its own named after it. By the copier payload at the drive's
 * full speed, or here when there's no loader. */
bool copy_file_in(const std::string &src, const std::string &target, std::uint64_t size);
bool port_name(const std::string &leaf);
std::string lower_ascii(std::string s);

/* (2.0.1) A Title Update copied into the game's folder as it is, under the name its port looks
 * for (slot.raw): GTA IV's own installer reads TU_1A581VI_000000K000000.0000000000205 from its
 * install folder. Already checked by run_setup_stfs: the game's, and a Title Update. */
void run_setup_stfs_raw(const App &a, const Slot &slot, const std::string &image)
{
    const std::string id = a.id;
    const std::string own = image.substr(image.find_last_of('/') + 1);
    const std::string want = slot.name.empty() ? own : slot.name;
    if (!plain_leaf(want) || port_name(want))
        return fail_job("The store's listing for " + a.name + " names this file oddly, so it wasn't set up. The listing needs fixing.");
    if (!exists(app_folder(id) + "/eboot.bin")) return fail_job("Install " + a.name + " first.");
    struct stat src;
    if (stat(image.c_str(), &src) != 0 || !S_ISREG(src.st_mode)) return fail_job("The Title Update can't be read.");
    const std::uint64_t size = std::uint64_t(src.st_size);
    const std::string target = slot_target(a, slot), dst = target + "/" + want;
    make_dirs(target);
    auto done = [&](const std::string &note) {
        make_dirs(g.data + "/setup");
        write_file(g.data + "/setup/" + id + "." + slot.key + ".txt", image.data(), image.size());
        std::lock_guard<std::mutex> l(g.m);
        g.job.phase = Phase::Done;
        g.job.step = "The Title Update is ready";
        g.job.note = note;
        g.job.done = g.job.total;
    };
    struct stat ds;
    if (stat(dst.c_str(), &ds) == 0 && ds.st_dev == src.st_dev && ds.st_ino == src.st_ino)
        return done("The Title Update is already in place."); /* chosen from where it goes */
    std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
    if (free_bytes(target, fs_free) && fs_free < size + (16ull << 20))
        return fail_job("There isn't room for the Title Update.");
    /* Copied under its own name, then renamed: never over a different file of the game's. */
    if (own != want && exists(target + "/" + own))
        return fail_job("The game's folder already has a file called " + own + ", so the Title Update wasn't copied over it.");
    set_job("Copying the Title Update", 0, size);
    if (!copy_file_in(image, target, size)) return;
    if (own != want && std::rename((target + "/" + own).c_str(), dst.c_str()) != 0)
    {
        std::remove((target + "/" + own).c_str());
        return fail_job("The Title Update couldn't be named " + want + " in the game's folder.");
    }
    slog("setup %s: %s -> %s (%s, as it is)", id.c_str(), image.c_str(), dst.c_str(), fmt_mb(size).c_str());
    done(std::string("The Title Update is in place") + (slot.to.empty() || slot.to == "." ? "." : ", in the game's " + slot.to + " folder."));
}

void run_setup_stfs(const App &a, const Slot &slot, const std::string &image)
{
    const std::string id = a.id;
    const bool arcade = slot.kind == "arcade";
    if (arcade && !exists(app_folder(id) + "/eboot.bin")) return fail_job("Install " + a.name + " first.");
    stfs::Package first;
    std::string err;
    if (!first.open(image, err)) return fail_job(err);
    const bool several = slot.multiple && !arcade;
    char tid[16];
    std::snprintf(tid, sizeof tid, "%08X", first.title_id());
    slog("setup %s: %s is a package for title %s, type %08X, \"%s\", %zu files", id.c_str(), image.c_str(), tid,
         first.content_type(), first.display_name().c_str(), first.entries().size());
    if (!slot.title_id.empty() && std::strtoul(slot.title_id.c_str(), nullptr, 16) != first.title_id())
        return fail_job(std::string("This package is for a different game (title ID ") + tid + ").");
    if (arcade && first.content_type() != stfs::kArcade)
        return fail_job(std::string(first.content_type() == stfs::kTitleUpdate || first.content_type() == stfs::kMarketplace
                                        ? "That's a Title Update or DLC, not the game."
                                        : "That isn't an Xbox Live Arcade game.") +
                        " Choose " + a.name + "'s own file, from its 000D0000 folder on the console.");
    if (!arcade && !several && first.content_type() != stfs::kTitleUpdate)
        return fail_job("That isn't a Title Update. It's the file whose name starts with TU_.");
    if (slot.raw && !arcade && !several)
    {
        first.close();
        run_setup_stfs_raw(a, slot, image);
        return;
    }
    if (several && first.content_type() != stfs::kMarketplace)
        return fail_job("That isn't DLC. Choose one of the game's downloadable content files.");
    std::size_t arcade_files = 0;
    if (arcade)
    {
        /* A game: its default.xex at the top (in any case), where the port and slot_ready look. */
        const stfs::Entry *xex = first.find("default.xex");
        if (!xex || xex->name.find('/') != std::string::npos)
            return fail_job("That package has no default.xex, so it isn't a game " + a.name + " can use.");
        /* Room for the game's files, less the ones already there whole (an unpack that stopped
         * carries on from them). */
        const std::string base = slot_target(a, slot);
        make_dirs(base);
        std::uint64_t need = 0;
        for (const stfs::Entry &e : first.entries())
        {
            struct stat st;
            if (stat((base + "/" + e.name).c_str(), &st) != 0 || std::uint64_t(st.st_size) != e.size) need += e.size;
        }
        arcade_files = first.entries().size();
        std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
        if (free_bytes(base, fs_free) && fs_free < need + (64ull << 20))
            return fail_job("There isn't room for the game's files: they need " + fmt_mb(need) + " free.");
    }

    /* The packages: this one, and for DLC every other one of the same game beside it. */
    std::vector<std::pair<std::string, std::string>> work; /* package, target */
    const std::string base = slot_target(a, slot);
    auto add = [&](const std::string &path, const stfs::Package &p) {
        std::string leaf = path.substr(path.find_last_of('/') + 1);
        work.push_back({path, several ? base + "/" + stfs::folder_name(p, leaf) : base});
    };
    add(image, first);
    if (several)
    {
        const std::string dir = image.substr(0, image.find_last_of('/'));
        if (DIR *d = opendir(dir.c_str()))
        {
            std::vector<std::string> others;
            while (dirent *e = readdir(d))
                if (e->d_name[0] != '.') others.push_back(dir + "/" + e->d_name);
            closedir(d);
            std::sort(others.begin(), others.end());
            for (const std::string &o : others)
            {
                if (o == image || !stfs::looks_like(o)) continue;
                stfs::Package p;
                std::string why;
                if (p.open(o, why) && p.title_id() == first.title_id() && p.content_type() == stfs::kMarketplace)
                    add(o, p);
            }
        }
    }
    first.close();
    slog("setup %s: %zu package(s) to set up", id.c_str(), work.size());

    std::size_t n = 0;
    for (const auto &w : work)
    {
        ++n;
        make_dirs(w.second);
        const Copier c = run_copier("stfs", w.first, w.second);
        if (c == Copier::Failed)
            return; /* run_copier said why */
        if (c == Copier::Unavailable)
        {
            std::string error;
            std::size_t files = 0;
            const std::string step = work.size() > 1 ? "Setting up " + std::to_string(n) + " of " + std::to_string(work.size()) : "Setting up";
            if (!stfs::unpack(
                    w.first, w.second, [&](std::uint64_t d, std::uint64_t t) { set_job(step, d, t); },
                    [](const std::string &l) { slog("%s", l.c_str()); }, nullptr, error, files))
                return fail_job(error);
        }
        slog("setup %s: %s -> %s done", id.c_str(), w.first.c_str(), w.second.c_str());
    }
    make_dirs(g.data + "/setup");
    write_file(g.data + "/setup/" + id + "." + slot.key + ".txt", image.data(), image.size());
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.step = arcade    ? "The game's files are ready"
                 : several ? std::to_string(work.size()) + (work.size() == 1 ? " pack is ready" : " packs are ready")
                           : "The Title Update is ready";
    g.job.note = arcade    ? "Unpacked " + std::to_string(arcade_files) + (arcade_files == 1 ? " file" : " files") + " from your Xbox Live Arcade game."
                 : several ? (work.size() == 1 ? "Your DLC is set up: 1 package." : "Your DLC is set up: " + std::to_string(work.size()) + " packages.")
                           : "The Title Update is set up.";
    g.job.done = g.job.total;
}

/* A GameCube or Wii disc image, copied as it is into the game's folder, where the port
 * reads it (Wind Waker). Checked first: a disc image, and the disc the slot names. By the
 * copier payload at the drive's full speed, or here without a loader. */
void run_setup_image(const App &a, const Slot &slot, const std::string &image)
{
    const std::string id = a.id;
    const std::string got = disc_image_id(image);
    slog("setup %s: %s is disc %s (wants %s)", id.c_str(), image.c_str(), got.empty() ? "?" : got.c_str(),
         slot.disc_id.empty() ? "any" : slot.disc_id.c_str());
    if (got.empty())
        return fail_job("That isn't a GameCube or Wii disc image. Choose your own " + a.name + " disc (.iso, .gcm or .ciso).");
    std::string want_id = slot.disc_id;
    for (char &c : want_id) if (c >= 'a' && c <= 'z') c = char(c - 'a' + 'A'); /* gzle01 is GZLE01 */
    if (!want_id.empty() && got != want_id)
        return fail_job("That's a different disc (" + got + "). " + a.name + " needs " + want_id + ".");
    const std::string target = slot_target(a, slot);
    if (!exists(app_folder(id) + "/eboot.bin")) return fail_job("Install " + a.name + " first.");
    make_dirs(target);
    const std::string leaf = image.substr(image.find_last_of('/') + 1), dst = target + "/" + leaf;
    struct stat src;
    if (stat(image.c_str(), &src) != 0) return fail_job("The disc image can't be read.");
    const std::uint64_t size = std::uint64_t(src.st_size);

    /* The right disc already in the folder (the one chosen, or another copy of it): nothing
     * to copy. Another disc there may be what the game finds first: said afterwards. */
    std::string already, other;
    std::vector<FsEntry> here;
    if (list_dir(target, here, "image"))
        for (const FsEntry &e : here)
        {
            if (e.is_dir) continue;
            const std::string hid = disc_image_id(e.path);
            if (hid == got && (e.path == image || (e.name == leaf && e.size == size))) already = e.path;
            else if (hid != got && e.name != leaf) other = e.name;
        }
    auto finish = [&](const std::string &note) {
        make_dirs(g.data + "/setup");
        write_file(g.data + "/setup/" + id + "." + slot.key + ".txt", image.data(), image.size());
        std::string n = note;
        if (!other.empty())
            n += " There's also " + other + " in the game's folder, which isn't this disc: if the game won't start, delete it over FTP.";
        std::lock_guard<std::mutex> l(g.m);
        g.job.phase = Phase::Done;
        g.job.step = "The disc image is ready";
        g.job.note = n;
        g.job.done = g.job.total;
    };
    if (!already.empty())
    {
        slog("setup %s: %s is already in the game's folder", id.c_str(), already.c_str());
        return finish("Your disc image is already in the game's folder.");
    }
    std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
    if (free_bytes(target, fs_free) && fs_free < size + (64ull << 20))
        return fail_job("There isn't room for the disc image: it needs " + fmt_mb(size) + " free.");

    const Copier c = run_copier("copy", image, target);
    if (c == Copier::Failed) return; /* run_copier said why */
    if (c == Copier::Unavailable)
    {
        /* Here, a piece at a time, into <name>.part, then renamed into place. */
        std::FILE *in = std::fopen(image.c_str(), "rb");
        const std::string part = dst + ".store-part"; /* not the copier's .part: one that starts late removes its own */
        std::FILE *out = in ? std::fopen(part.c_str(), "wb") : nullptr;
        if (!in || !out)
        {
            if (in) std::fclose(in);
            return fail_job(in ? "The game's folder can't be written to." : "The disc image can't be read.");
        }
        std::vector<char> buf(8u << 20);
        std::uint64_t done = 0;
        bool ok = true;
        for (;;)
        {
            const std::size_t n = std::fread(buf.data(), 1, buf.size(), in);
            if (n == 0) break; /* the end, or a read that failed: the size check below says which */
            if (std::fwrite(buf.data(), 1, n, out) != n) { ok = false; break; }
            done += n;
            set_job("Copying the game's files", done, size);
        }
        std::fclose(in);
        ok = std::fclose(out) == 0 && ok && done == size;
        if (!ok || std::rename(part.c_str(), dst.c_str()) != 0)
        {
            std::remove(part.c_str());
            return fail_job("Copying the disc image failed. Is the storage full?");
        }
        chmod(dst.c_str(), 0777);
    }
    struct stat st;
    if (stat(dst.c_str(), &st) != 0 || std::uint64_t(st.st_size) != size)
        return fail_job("The disc image didn't copy whole. Set it up again.");
    slog("setup %s: %s -> %s (%s)", id.c_str(), image.c_str(), dst.c_str(), fmt_mb(size).c_str());
    finish("Your disc image is in place.");
}

/* ---- Kind "file" (1.0.6): one of the player's own files into the game's folder -------- *
 * A ROM (Super Mario 64), a game's data file (Diablo's DIABDAT.MPQ), or a set of them from
 * one folder (Quake III's pak0.pk3 to pak8.pk3). What it must be is the slot's: extensions,
 * a name, SHA-1s; a Nintendo 64 ROM in another byte order is turned into a .z64. */
std::string lower_ascii(std::string s)
{
    for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
    return s;
}

std::vector<std::string> words(const std::string &s)
{
    std::vector<std::string> out;
    std::size_t at = 0;
    while (at < s.size())
    {
        const std::size_t sp = s.find_first_of(" ,", at);
        const std::size_t end = sp == std::string::npos ? s.size() : sp;
        if (end > at) out.push_back(s.substr(at, end - at));
        at = end + 1;
    }
    return out;
}

/* name against a pattern (* and ?), in any case. */
bool name_like(const std::string &pattern, const std::string &name)
{
    return asset_matches(lower_ascii(pattern), lower_ascii(name));
}

bool file_ext_ok(const Slot &s, const std::string &name)
{
    const std::vector<std::string> exts = words(s.ext);
    if (exts.empty()) return true;
    const std::string low = lower_ascii(name);
    for (std::string e : exts)
    {
        e = lower_ascii(e);
        if (!e.empty() && e[0] != '.') e = "." + e;
        if (low.size() > e.size() && low.compare(low.size() - e.size(), e.size(), e) == 0) return true;
    }
    return false;
}

/* A Nintendo 64 ROM's byte order from its first word: 0 .z64 (as the console reads it),
 * 1 .v64 (pairs swapped), 2 .n64 (fours reversed); -1 not a ROM. */
int n64_order(const std::uint8_t *p, std::size_t n)
{
    if (n < 4096) return -1;
    if (p[0] == 0x80 && p[1] == 0x37 && p[2] == 0x12 && p[3] == 0x40) return 0;
    if (p[0] == 0x37 && p[1] == 0x80 && p[2] == 0x40 && p[3] == 0x12) return 1;
    if (p[0] == 0x40 && p[1] == 0x12 && p[2] == 0x37 && p[3] == 0x80) return 2;
    return -1;
}

void n64_to_z64(std::vector<std::uint8_t> &d, int order)
{
    if (order == 1)
        for (std::size_t i = 0; i + 1 < d.size(); i += 2) std::swap(d[i], d[i + 1]);
    else if (order == 2)
        for (std::size_t i = 0; i + 3 < d.size(); i += 4)
        {
            std::swap(d[i], d[i + 3]);
            std::swap(d[i + 1], d[i + 2]);
        }
}

/* A name the store won't write into a game's folder (the port's own). */
bool port_name(const std::string &leaf)
{
    const std::string low = lower_ascii(leaf);
    return low == "eboot.bin" || low == "sce_sys" || low == "sce_module" || low == "recompstore.json" || low == kMarker;
}

/* src into the folder target under its own name: by the copier, else here. size is
 * src's. false: it failed, and fail_job said why. */
bool copy_file_in(const std::string &src, const std::string &target, std::uint64_t size)
{
    const std::string dst = target + "/" + src.substr(src.find_last_of('/') + 1);
    const Copier c = run_copier("copy", src, target);
    if (c == Copier::Failed) return false; /* run_copier said why */
    if (c == Copier::Unavailable)
    {
        std::FILE *in = std::fopen(src.c_str(), "rb");
        const std::string part = dst + ".store-part";
        std::FILE *out = in ? std::fopen(part.c_str(), "wb") : nullptr;
        if (!in || !out)
        {
            if (in) std::fclose(in);
            fail_job(in ? "The game's folder can't be written to." : "Your file can't be read.");
            return false;
        }
        std::vector<char> buf(8u << 20);
        std::uint64_t done = 0;
        bool ok = true;
        for (;;)
        {
            const std::size_t n = std::fread(buf.data(), 1, buf.size(), in);
            if (n == 0) break;
            if (std::fwrite(buf.data(), 1, n, out) != n) { ok = false; break; }
            done += n;
            set_job("Copying your files", done, size);
        }
        std::fclose(in);
        ok = std::fclose(out) == 0 && ok && done == size;
        if (!ok || std::rename(part.c_str(), dst.c_str()) != 0)
        {
            std::remove(part.c_str());
            fail_job("Copying your file failed. Is the storage full?");
            return false;
        }
        chmod(dst.c_str(), 0777);
    }
    struct stat st;
    if (stat(dst.c_str(), &st) != 0 || std::uint64_t(st.st_size) != size)
    {
        fail_job("Your file didn't copy whole. Set it up again.");
        return false;
    }
    return true;
}

/* A file named like a pattern, spelled as the pattern is ("PAK1.PK3" for "pak?.pk3" is
 * pak1.pk3: a port looks for its files by their exact name). A pattern with * leaves the
 * name as it is. */
std::string spelled_like(const std::string &pattern, const std::string &name)
{
    if (pattern.find('*') != std::string::npos || pattern.size() != name.size() || !name_like(pattern, name)) return name;
    std::string out = name;
    for (std::size_t i = 0; i < out.size(); ++i)
        if (pattern[i] != '?') out[i] = pattern[i];
    return out;
}

/* How many of the slot's files are in its folder: the picked one (its name, or any file
 * with its extensions) and the ones that go with it. A slot that says none of those: the
 * file it was last set up from. Never the port's own files. */
int files_in_place(const App &a, const Slot &s)
{
    std::vector<FsEntry> here;
    if (!list_dir(slot_target(a, s), here, "*")) return 0;
    const std::vector<std::string> also = words(s.also);
    std::string chosen;
    if (s.name.empty() && s.pick.empty() && s.ext.empty() && !s.n64)
    {
        chosen = slot_source(a, s);
        chosen = chosen.substr(chosen.find_last_of('/') + 1);
        if (chosen.empty()) return 0;
    }
    int n = 0;
    for (const FsEntry &e : here)
    {
        if (e.is_dir || port_name(e.name)) continue;
        bool hit = !chosen.empty()   ? e.name == chosen
                   : !s.name.empty() ? lower_ascii(e.name) == lower_ascii(s.name)
                                     : (s.pick.empty() || name_like(s.pick, e.name)) && file_ext_ok(s, e.name);
        if (s.n64 && s.name.empty()) hit = lower_ascii(e.name).size() > 4 && lower_ascii(e.name).rfind(".z64") == e.name.size() - 4;
        for (const std::string &p : also) hit = hit || name_like(p, e.name);
        n += hit ? 1 : 0;
    }
    return n;
}

void run_setup_file(const App &a, const Slot &slot, const std::string &file)
{
    const std::string id = a.id;
    if (!exists(app_folder(id) + "/eboot.bin")) return fail_job("Install " + a.name + " first.");
    if (!slot.name.empty() && !plain_leaf(slot.name))
        return fail_job("The store's listing for " + a.name + " names this file oddly, so it wasn't set up. The listing needs fixing.");
    const std::string leaf = file.substr(file.find_last_of('/') + 1);
    /* What to choose, in words: pak0.pk3; your own Super Mario 64 ROM (.z64, .n64 or .v64);
     * a .mpq file. */
    std::string exts;
    {
        const std::vector<std::string> e = words(slot.ext);
        for (std::size_t i = 0; i < e.size(); ++i)
            exts += (i == 0 ? "" : i + 1 == e.size() ? " or " : ", ") + (e[i][0] == '.' ? e[i] : "." + e[i]);
    }
    const std::string what = !slot.pick.empty() ? slot.pick
                             : slot.n64         ? "your own " + a.name + " ROM (" + (exts.empty() ? std::string(".z64, .n64 or .v64") : exts) + ")"
                             : !exts.empty()    ? "a " + exts + " file"
                                                : "your file";
    struct stat src;
    if (stat(file.c_str(), &src) != 0 || !S_ISREG(src.st_mode)) return fail_job("Your file can't be read.");
    if (!file_ext_ok(slot, leaf) || (!slot.pick.empty() && !name_like(slot.pick, leaf)))
        return fail_job("That isn't the file " + a.name + " needs here. Choose " + what + ".");
    const std::string target = slot_target(a, slot);
    make_dirs(target);
    const std::uint64_t size = std::uint64_t(src.st_size);
    auto finish = [&](const std::string &step, const std::string &note) {
        make_dirs(g.data + "/setup");
        write_file(g.data + "/setup/" + id + "." + slot.key + ".txt", file.data(), file.size());
        std::lock_guard<std::mutex> l(g.m);
        g.job.phase = Phase::Done;
        g.job.step = step;
        g.job.note = note;
        g.job.done = g.job.total;
    };
    const std::vector<std::string> sums = words(lower_ascii(slot.sha1));
    auto sum_ok = [&](const std::string &h) {
        if (sums.empty()) return true;
        for (const std::string &x : sums)
            if (x == h) return true;
        return false;
    };

    if (slot.n64)
    {
        /* A ROM: read whole (a Nintendo 64 cartridge is 64 MB at most), put in .z64's byte
         * order, checked, and written here as a .z64. */
        if (size > (128ull << 20)) return fail_job("That's too big to be a Nintendo 64 ROM.");
        std::vector<std::uint8_t> rom;
        if (!read_bytes(file, rom) || rom.size() != size) return fail_job("Your ROM can't be read.");
        const int order = n64_order(rom.data(), rom.size());
        if (order < 0) return fail_job("That isn't a Nintendo 64 ROM. Choose your own " + a.name + " ROM (.z64, .n64 or .v64).");
        n64_to_z64(rom, order);
        set_job("Checking your ROM", 0, 0);
        Sha1 h;
        h.update(rom.data(), rom.size());
        const std::string hex = h.hex();
        slog("setup %s: %s, a %s ROM, SHA-1 %s", id.c_str(), file.c_str(), order == 0 ? ".z64" : order == 1 ? ".v64" : ".n64", hex.c_str());
        if (!sum_ok(hex))
            return fail_job("That ROM isn't one " + a.name + " takes: it has to be the original game, not a hack or another "
                            "release. (Its SHA-1 is " + hex + ".)");
        std::string out = slot.name;
        if (out.empty()) out = leaf.substr(0, leaf.find_last_of('.')) + ".z64";
        if (port_name(out)) return fail_job("The store won't write " + out + " into the game's folder.");
        const std::string dst = target + "/" + out;
        struct stat ds;
        if (stat(dst.c_str(), &ds) == 0 && std::uint64_t(ds.st_size) == rom.size())
        {
            /* The same ROM already there: told by its SHA-1, read through, not a second copy in memory. */
            Sha1 dh;
            std::vector<std::uint8_t> buf(4u << 20);
            bool ok = false;
            if (std::FILE *f = std::fopen(dst.c_str(), "rb"))
            {
                std::size_t n, got = 0;
                while ((n = std::fread(buf.data(), 1, buf.size(), f)) > 0) { dh.update(buf.data(), n); got += n; }
                std::fclose(f);
                ok = got == rom.size();
            }
            if (ok && dh.hex() == hex)
                return finish("Your ROM is ready", "Your ROM is already in the game's folder.");
        }
        std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
        if (free_bytes(target, fs_free) && fs_free < size + (16ull << 20))
            return fail_job("There isn't room for the ROM.");
        if (!write_file(dst, rom.data(), rom.size())) /* written beside it, then renamed into place */
            return fail_job("Writing your ROM into the game's folder failed. Is the storage full?");
        chmod(dst.c_str(), 0777);
        slog("setup %s: wrote %s (%s)", id.c_str(), dst.c_str(), fmt_mb(size).c_str());
        return finish("Your ROM is ready", order == 0 ? "Your ROM is in place." : "Your ROM is in place, turned into a .z64.");
    }

    /* The file, and the ones from its folder that go with it. */
    std::vector<std::pair<std::string, std::uint64_t>> files{{file, size}};
    const std::vector<std::string> also = words(slot.also);
    if (!also.empty())
    {
        const std::string dir = file.substr(0, file.find_last_of('/'));
        std::vector<FsEntry> there;
        if (list_dir(dir, there, "*"))
            for (const FsEntry &e : there)
            {
                if (e.is_dir || e.path == file) continue;
                for (const std::string &p : also)
                    if (name_like(p, e.name) && !port_name(e.name))
                    {
                        files.push_back({e.path, e.size});
                        break;
                    }
            }
    }
    if (!sums.empty())
    {
        /* Its SHA-1, read through before anything is copied. */
        const int fd = open(file.c_str(), O_RDONLY);
        if (fd < 0) return fail_job("Your file can't be read.");
        Sha1 h;
        std::vector<std::uint8_t> buf(4u << 20);
        std::uint64_t done = 0;
        bool ok = true;
        while (done < size)
        {
            const ssize_t n = pread(fd, buf.data(), std::size_t(std::min<std::uint64_t>(buf.size(), size - done)), off_t(done));
            if (n < 0 && errno == EINTR) continue;
            if (n <= 0) { ok = false; break; }
            h.update(buf.data(), std::size_t(n));
            done += std::uint64_t(n);
            set_job("Checking your file", done, size);
        }
        close(fd);
        if (!ok) return fail_job("Your file can't be read.");
        const std::string hex = h.hex();
        slog("setup %s: %s, SHA-1 %s", id.c_str(), file.c_str(), hex.c_str());
        if (!sum_ok(hex)) return fail_job("That file isn't one " + a.name + " takes. (Its SHA-1 is " + hex + ".)");
    }
    std::uint64_t total = 0;
    for (const auto &f : files) total += f.second;
    std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
    if (free_bytes(target, fs_free) && fs_free < total + (64ull << 20))
        return fail_job("There isn't room for your files: they need " + fmt_mb(total) + " free.");
    int copied = 0;
    for (std::size_t i = 0; i < files.size(); ++i)
    {
        const std::string &path = files[i].first;
        const std::string own = path.substr(path.find_last_of('/') + 1);
        std::string want = own;
        if (i == 0 && !slot.name.empty()) want = slot.name;
        else if (i == 0 && !slot.pick.empty()) want = spelled_like(slot.pick, own);
        else
            for (const std::string &p : also)
                if (name_like(p, own)) { want = spelled_like(p, own); break; }
        if (port_name(own) || port_name(want)) return fail_job("The store won't write " + want + " into the game's folder.");
        /* Copied under its own name, then renamed: never over a different file of the game's. */
        if (lower_ascii(own) != lower_ascii(want) && exists(target + "/" + own))
            return fail_job("The game's folder already has a file called " + own + ", so yours wasn't copied over it. Rename yours to " + want + " and choose it again.");
        struct stat st;
        /* Already there (same size). Not for the one its SHA-1 was checked for: that one is copied, so it's the right one. */
        if (!(i == 0 && !sums.empty()) && stat((target + "/" + want).c_str(), &st) == 0 && std::uint64_t(st.st_size) == files[i].second)
        {
            slog("setup %s: %s is already in the game's folder", id.c_str(), want.c_str());
            continue;
        }
        set_job("Copying your files", 0, files[i].second);
        if (!copy_file_in(path, target, files[i].second)) return;
        if (want != own && std::rename((target + "/" + own).c_str(), (target + "/" + want).c_str()) != 0)
            return fail_job("Your file couldn't be named " + want + " in the game's folder.");
        slog("setup %s: %s -> %s/%s (%s)", id.c_str(), path.c_str(), target.c_str(), want.c_str(), fmt_mb(files[i].second).c_str());
        ++copied;
    }
    const int have = files_in_place(a, slot);
    std::string note = copied == 0                        ? "Your files are already in the game's folder."
                       : copied == 1 && files.size() == 1 ? "Your file is in place."
                       : copied == 1                      ? "1 file copied into the game's folder; the others were there already."
                                                          : std::to_string(copied) + " files copied into the game's folder.";
    if (have < slot.need)
        note += " " + a.name + " needs " + std::to_string(slot.need) + " of these files and has " + std::to_string(have) +
                ": put the rest beside the one you chose, then set it up again.";
    finish(have < slot.need ? "Some files are missing" : "Your files are ready", note);
}

/* ---- (1.0.8) A Title Update the store downloads itself -------------------------------- *
 * From XboxUnity's archive of Xbox 360 Title Updates: the update the game's listing names
 * (by its content ID) is looked up under the game's title ID, downloaded into the store's
 * own updates folder, and used only if the whole file has the SHA-1 the listing gives.
 * Then it is set up like one the player chose. HTTPS first, plain HTTP if the console can't
 * make the secure connection: the SHA-1 is what makes the file trusted either way. */
constexpr const char *kUpdateLookup = "xboxunity.net/Resources/Lib/TitleUpdateInfo.php?titleid=";
constexpr const char *kUpdateFile = "xboxunity.net/Resources/Lib/TitleUpdate.php?tuid=";

std::string updates_folder()
{
    std::lock_guard<std::mutex> l(g.m);
    return g.data + "/updates";
}

/* A file's SHA-1, read through (lowercase hex), or "" when it can't be read. */
std::string file_sha1(const std::string &path)
{
    const int fd = open(path.c_str(), O_RDONLY);
    if (fd < 0) return "";
    Sha1 h;
    std::vector<std::uint8_t> buf(1u << 20);
    std::uint64_t at = 0;
    for (;;)
    {
        const ssize_t n = pread(fd, buf.data(), buf.size(), off_t(at));
        if (n < 0 && errno == EINTR) continue;
        if (n < 0) { close(fd); return ""; }
        if (n == 0) break;
        h.update(buf.data(), std::size_t(n));
        at += std::uint64_t(n);
    }
    close(fd);
    return h.hex();
}

/* The slot's Title Update in path, downloaded if it isn't already. false: fail_job said why. */
bool fetch_title_update(const App &a, const Slot &slot, std::string &path)
{
    if (!exists(app_folder(a.id) + "/eboot.bin"))
    {
        fail_job("Install " + a.name + " first.");
        return false;
    }
    std::string tid = slot.title_id;
    for (char &c : tid) if (c >= 'a' && c <= 'z') c = char(c - 'a' + 'A'); /* (no toupper: not among the proven imports) */
    const std::string dir = updates_folder() + "/" + tid;
    path = dir + "/" + slot.update_id;
    if (exists(path) && file_sha1(path) == slot.update_sha1)
    {
        slog("update %s: %s is already here", a.id.c_str(), path.c_str());
        return true;
    }
    const std::string own = "Choose it from your own files instead.";
    porpoise::http::Session http("store-update", "RecompStore/1.0");
    if (!http.init())
    {
        fail_job("Your PS5 isn't online, so the update can't be downloaded. " + own);
        return false;
    }

    /* Which of the game's updates it is. */
    set_job("Finding the update", 0, 0);
    std::vector<std::uint8_t> body;
    std::string scheme = "https://";
    int status = http.get(scheme + kUpdateLookup + tid, body, {}, 1u << 20);
    if (status < 0)
    {
        slog("update %s: HTTPS failed (%d), trying HTTP", a.id.c_str(), http.last_error());
        scheme = "http://";
        status = http.get(scheme + kUpdateLookup + tid, body, {}, 1u << 20);
    }
    Json j;
    if (status != 200 || !Json::parse(std::string(body.begin(), body.end()), j))
    {
        slog("update %s: the update list answered HTTP %d", a.id.c_str(), status);
        fail_job("The update couldn't be found online just now. Try again later, or " + std::string("choose it from your own files."));
        return false;
    }
    std::string tuid;
    std::uint64_t expect = 0;
    const Json &media = j["MediaIDS"];
    for (std::size_t m = 0; m < media.size() && tuid.empty(); ++m)
    {
        const Json &ups = media[m]["Updates"];
        for (std::size_t u = 0; u < ups.size(); ++u)
        {
            std::string h = ups[u]["hash"].str();
            for (char &c : h) c = char(std::tolower(static_cast<unsigned char>(c)));
            if (h != slot.update_id) continue;
            tuid = ups[u]["TitleUpdateID"].str();
            expect = std::uint64_t(std::strtoul(ups[u]["Size"].str().c_str(), nullptr, 10)) * 1024;
            break;
        }
    }
    bool digits = !tuid.empty() && tuid.size() < 12;
    for (char c : tuid) digits = digits && c >= '0' && c <= '9';
    if (!digits)
    {
        slog("update %s: %s isn't in the list for %s", a.id.c_str(), slot.update_id.c_str(), tid.c_str());
        fail_job("The update this game needs isn't available online. " + own);
        return false;
    }

    /* Room for it, then the file itself, into <id>.part and renamed once it checks out. */
    make_dirs(dir);
    std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
    if (free_bytes(dir, fs_free) && fs_free < expect + (64ull << 20))
    {
        fail_job("There isn't room for the update: it needs " + fmt_mb(expect) + " free.");
        return false;
    }
    const std::string part = path + ".part";
    std::FILE *out = std::fopen(part.c_str(), "wb");
    if (!out)
    {
        fail_job("The store couldn't save the update on your PS5.");
        return false;
    }
    Sha1 sha;
    std::uint64_t got = 0;
    bool write_ok = true, too_big = false;
    const std::uint64_t cap = expect ? expect + (1u << 20) : (8ull << 30);
    set_job("Downloading the update", 0, expect);
    status = http.get_stream(scheme + kUpdateFile + tuid, [&](const std::uint8_t *p, std::size_t n) {
        if (got + n > cap) { too_big = true; return false; }
        if (std::fwrite(p, 1, n, out) != n) { write_ok = false; return false; }
        sha.update(p, n);
        got += n;
        set_job("Downloading the update", got, expect ? expect : got);
        return true;
    });
    const bool closed = std::fclose(out) == 0;
    const std::string hex = sha.hex();
    slog("update %s: HTTP %d, %llu bytes, SHA-1 %s (want %s)", a.id.c_str(), status, (unsigned long long)got, hex.c_str(),
         slot.update_sha1.c_str());
    if (!write_ok || !closed)
    {
        std::remove(part.c_str());
        fail_job("Your PS5 ran out of room while downloading the update.");
        return false;
    }
    if (status != 200 || too_big || got == 0)
    {
        std::remove(part.c_str());
        fail_job("The update didn't download. Check your connection and try again, or " + std::string("choose it from your own files."));
        return false;
    }
    if (hex != slot.update_sha1)
    {
        std::remove(part.c_str());
        fail_job("The downloaded update isn't the one this game needs, so it wasn't used. " + own);
        return false;
    }
    if (std::rename(part.c_str(), path.c_str()) != 0)
    {
        std::remove(part.c_str());
        fail_job("The store couldn't save the update on your PS5.");
        return false;
    }
    chmod(path.c_str(), 0666);
    return true;
}

void run_setup(const std::string &id, const std::string &slot_key, const std::string &image, bool fetch)
{
    App a;
    Slot slot;
    bool found = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const App &x : g.catalog.apps)
            if (x.id == id)
                for (const Slot &s : x.slots)
                    if (s.key == slot_key) { a = x; slot = s; found = true; }
    }
    set_job(slot.kind == "stfs" || slot.kind == "arcade" ? "Reading the package" : slot.kind == "file" ? "Reading your file" : "Reading the disc image", 0, 0);
    if (!found) return fail_job("This game doesn't take that file.");
    if (store_too_old(a))
        return fail_job(a.name + " needs Recomp Store " + a.min_store + " or newer. Update the store from the Library first (Updates).");
    if (fetch)
    {
        if (!slot_fetchable(slot)) return fail_job("The store can't download this update. Choose it from your own files instead.");
        std::string path;
        if (!fetch_title_update(a, slot, path)) return; /* fetch_title_update said why */
        set_job("Reading the package", 0, 0);
        return run_setup_stfs(a, slot, path);
    }
    if (slot.kind == "stfs" || slot.kind == "arcade") return run_setup_stfs(a, slot, image);
    if (slot.kind == "image") return run_setup_image(a, slot, image);
    if (slot.kind == "file") return run_setup_file(a, slot, image);
    if (slot.kind != "iso") return fail_job("Setting up " + slot.label + " isn't something this version of the store can do.");

    Disc disc;
    std::string err;
    if (!disc.open(image, err)) return fail_job(err);
    /* (1.1.1) A game disc: an Xbox 360 one has default.xex, an original Xbox one default.xbe
     * (Def Jam: Fight for NY). The copier payload checks the same. */
    if (!disc.find("default.xex") && !disc.find("default.xbe"))
        return fail_job("This disc image has no default.xex or default.xbe: it isn't a game disc, or it's the wrong one.");
    if (!slot.title_id.empty())
    {
        const std::uint32_t want = std::uint32_t(std::strtoul(slot.title_id.c_str(), nullptr, 16));
        const std::uint32_t got = disc.title_id();
        slog("setup %s: disc title ID %08X, want %08X\n", id.c_str(), got, want);
        if (got == 0) return fail_job("This disc image's default.xex couldn't be read. The image may be incomplete.");
        if (got != want)
        {
            char t[160];
            std::snprintf(t, sizeof t, "This is a different game's disc (title ID %08X). %s needs its own.", got, a.name.c_str());
            return fail_job(t);
        }
    }

    /* (1.1.2) The files the disc must have, at their sizes and CRC-32s; without them it's
     * another disc (another region's, or another game's), refused before anything is copied. */
    if (!slot.files.empty())
    {
        const std::string wrong = slot.wrong.empty() ? "This isn't the disc " + a.name + " needs." : slot.wrong;
        for (const DiscWant &w : slot.files)
        {
            const DiscFile *f = disc.find(w.path);
            if (!f || f->size != w.size)
            {
                slog("setup %s: %s on the disc: %s, want %llu bytes", id.c_str(), w.path.c_str(),
                     f ? (std::to_string(f->size) + " bytes").c_str() : "not there", (unsigned long long)w.size);
                return fail_job(wrong);
            }
        }
        std::vector<std::uint8_t> buf(1u << 20);
        for (const DiscWant &w : slot.files)
        {
            if (!w.has_crc) continue;
            const DiscFile *f = disc.find(w.path);
            set_job("Checking the disc", 0, f->size);
            std::uint32_t crc = 0;
            for (std::uint64_t at = 0; at < f->size;)
            {
                const std::size_t n = std::size_t(std::min<std::uint64_t>(buf.size(), f->size - at));
                if (!disc.read(std::uint64_t(f->sector) * 2048 + at, buf.data(), n))
                    return fail_job("The disc image couldn't be read at " + f->path + ". It may be incomplete.");
                crc = zip::crc32(buf.data(), n, crc);
                at += n;
                set_job("Checking the disc", at, f->size);
            }
            if (crc != w.crc)
            {
                slog("setup %s: %s's CRC-32 is %08X, want %08X", id.c_str(), w.path.c_str(), crc, w.crc);
                return fail_job(wrong);
            }
        }
        slog("setup %s: the disc has all %zu files it should", id.c_str(), slot.files.size());
    }

    const std::string target = slot_target(a, slot);
    make_dirs(target);
    /* The port's own files live beside the game's in some folders: never written over. */
    auto protected_name = [&](const std::string &p) {
        std::string top = p.substr(0, p.find('/'));
        for (char &c : top) c = char(std::tolower(static_cast<unsigned char>(c)));
        return top == "eboot.bin" || top == "sce_sys" || top == "sce_module" || top == "recompstore.json";
    };

    std::vector<const DiscFile *> order;
    std::uint64_t total = 0, needed = 0;
    for (const DiscFile &f : disc.files())
    {
        if (f.is_dir) continue;
        if (!slot.only.empty() && !zip::kept(f.path, slot.only)) continue; /* (1.1.2) not this part of the disc */
        if (protected_name(f.path))
        {
            slog("setup: skipped %s (the port's own name)\n", f.path.c_str());
            continue;
        }
        order.push_back(&f);
        total += f.size;
        struct stat st;
        if (stat((target + "/" + f.path).c_str(), &st) != 0 || std::uint64_t(st.st_size) != f.size) needed += f.size;
    }
    /* In the order they lie on the disc: one pass along the image. */
    std::sort(order.begin(), order.end(), [](const DiscFile *x, const DiscFile *y) { return x->sector < y->sector; });

    if (order.empty()) return fail_job("This disc image doesn't have the files " + a.name + " needs.");
    std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
    if (free_bytes(target, fs_free) && fs_free < needed + (256ull << 20))
        return fail_job("There isn't enough free space: the game's files need " + fmt_mb(needed) + ".");
    slog("setup %s from %s: %zu files, %llu bytes, %llu still to copy, into %s\n", id.c_str(),
                 image.c_str(), order.size(), (unsigned long long)total, (unsigned long long)needed, target.c_str());

    /* The check at the end, whichever way the files were copied. */
    auto finish = [&]() {
        set_job("Checking the copied files", total, total);
        for (const DiscFile *f : order)
        {
            struct stat st;
            if (stat((target + "/" + f->path).c_str(), &st) != 0 || std::uint64_t(st.st_size) != f->size)
                return fail_job(f->path + " didn't copy whole. Set up again to finish it.");
        }
        for (const DiscWant &w : slot.files) /* (1.1.2) and every file it must have */
            if (!at_size_any_case(target, w.path, w.size))
                return fail_job(w.path + " didn't copy whole. Set up again to finish it.");
        make_dirs(g.data + "/setup");
        write_file(g.data + "/setup/" + id + "." + slot_key + ".txt", image.data(), image.size());
        slog("setup %s: done", id.c_str());
        std::string smp_why;
        const int home = refresh_smp(smp_why);
        if (home == 0) slog("setup: ShadowMountPlus: %s", smp_why.c_str());
        smp_scan_later();
        std::lock_guard<std::mutex> l(g.m);
        g.job.phase = Phase::Done;
        g.job.home = home;
        g.job.step = "Game files are ready";
        g.job.done = g.job.total;
    };
    if (needed > 0)
    {
        std::string only_job; /* (1.1.2) the part of the disc to copy */
        for (std::size_t k = 0; k < slot.only.size(); ++k) only_job += "only" + std::to_string(k) + "=" + slot.only[k] + "\n";
        const Copier c = run_copier("setup", image, target, nullptr, only_job);
        if (c == Copier::Done) return finish();
        if (c == Copier::Failed) return;
    }

    /* Two threads: one reads the image in 16 MB blocks, in the order the files lie on
     * the disc; this one writes them, each straight to its place. While one block is
     * written the next is read. */
    std::vector<const DiscFile *> todo;
    std::uint64_t done = 0;
    for (const DiscFile *f : order)
    {
        struct stat st;
        if (stat((target + "/" + f->path).c_str(), &st) == 0 && std::uint64_t(st.st_size) == f->size)
            done += f->size; /* already there: a stopped setup carries on */
        else
            todo.push_back(f);
    }
    const WriteMode &wm = kModes[std::clamp(g_write_mode, 0, kModeCount - 1)];
    slog("setup: writing with \"%s\"", wm.name);
    struct Block
    {
        std::unique_ptr<Aligned> buf;
        std::size_t size = 0;
        std::size_t cap = 0;
    };
    struct Pipe
    {
        std::mutex m;
        std::condition_variable cv;
        std::vector<Block> blocks;
        std::vector<int> free_q, full_q;
        bool read_failed = false, stop = false, reader_done = false;
        std::string bad;
        Disc *disc = nullptr;
        const std::vector<const DiscFile *> *files = nullptr;
        double read_s = 0;
    } pipe;
    const std::size_t kBlock = std::max<std::size_t>(wm.chunk, 1u << 20);
    pipe.blocks.resize(3);
    for (int i = 0; i < 3; ++i)
    {
        pipe.blocks[std::size_t(i)].buf = std::make_unique<Aligned>(kBlock);
        pipe.blocks[std::size_t(i)].cap = kBlock;
        if (!pipe.blocks[std::size_t(i)].buf->p) return fail_job("The store ran out of memory for copying.");
        pipe.free_q.push_back(i);
    }
    pipe.disc = &disc;
    pipe.files = &todo;
    auto now_s = [] {
        timespec ts;
        clock_gettime(CLOCK_MONOTONIC, &ts);
        return double(ts.tv_sec) + double(ts.tv_nsec) / 1e9;
    };
    pthread_t reader;
    auto reader_main = [](void *arg) -> void * {
        Pipe &p = *static_cast<Pipe *>(arg);
        for (const DiscFile *f : *p.files)
        {
            std::uint64_t off = std::uint64_t(f->sector) * 2048;
            std::uint32_t left = f->size;
            while (left)
            {
                int bi;
                {
                    std::unique_lock<std::mutex> l(p.m);
                    p.cv.wait(l, [&] { return !p.free_q.empty() || p.stop; });
                    if (p.stop) goto out;
                    bi = p.free_q.back();
                    p.free_q.pop_back();
                }
                Block &b = p.blocks[std::size_t(bi)];
                b.size = std::min<std::size_t>(left, b.cap);
                timespec t0, t1;
                clock_gettime(CLOCK_MONOTONIC, &t0);
                const bool ok = p.disc->read(off, b.buf->p, b.size);
                clock_gettime(CLOCK_MONOTONIC, &t1);
                std::lock_guard<std::mutex> l(p.m);
                p.read_s += double(t1.tv_sec - t0.tv_sec) + double(t1.tv_nsec - t0.tv_nsec) / 1e9;
                if (!ok)
                {
                    p.read_failed = true;
                    p.bad = f->path;
                    p.cv.notify_all();
                    goto out;
                }
                p.full_q.insert(p.full_q.begin(), bi);
                p.cv.notify_all();
                off += b.size;
                left -= std::uint32_t(b.size);
            }
        }
    out:
        std::lock_guard<std::mutex> l(p.m);
        p.reader_done = true;
        p.cv.notify_all();
        return nullptr;
    };
    if (create_title_thread(&reader, reader_main, &pipe) != 0)
        return fail_job("The store couldn't start copying.");
    auto finish_reader = [&] {
        {
            std::lock_guard<std::mutex> l(pipe.m);
            pipe.stop = true;
            pipe.cv.notify_all();
        }
        pthread_join(reader, nullptr);
    };

    std::set<std::string> made;
    const double t_start = now_s();
    double write_s = 0, open_s = 0, close_s = 0, sync_s = 0, wait_s = 0, last_log = t_start;
    std::uint64_t copied = 0;
    std::string err_msg;
    for (const DiscFile *f : todo)
    {
        const std::string dst = target + "/" + f->path;
        const std::string dir = dst.substr(0, dst.find_last_of('/'));
        double t0 = now_s();
        if (made.insert(dir).second) make_dirs(dir);
        OutFile out;
        if (!out.open_for(dst, f->size, wm)) { err_msg = "Couldn't create " + f->path + " on your PS5."; break; }
        open_s += now_s() - t0;
        std::uint32_t left = f->size;
        bool ok = true;
        while (left && ok)
        {
            int bi = -1;
            {
                const double w0 = now_s();
                std::unique_lock<std::mutex> l(pipe.m);
                pipe.cv.wait(l, [&] { return !pipe.full_q.empty() || pipe.read_failed || pipe.reader_done; });
                wait_s += now_s() - w0;
                if (pipe.full_q.empty())
                {
                    err_msg = "The disc image couldn't be read at " + (pipe.bad.empty() ? f->path : pipe.bad) + ". It may be incomplete.";
                    ok = false;
                    break;
                }
                bi = pipe.full_q.back();
                pipe.full_q.pop_back();
            }
            Block &b = pipe.blocks[std::size_t(bi)];
            const double w0 = now_s();
            if (!out.write(b.buf->p, b.size))
            {
                ok = false;
                err_msg = "Writing " + f->path + " failed. Is your PS5's storage full?";
            }
            write_s += now_s() - w0;
            left -= std::uint32_t(b.size);
            done += b.size;
            copied += b.size;
            {
                std::lock_guard<std::mutex> l(pipe.m);
                pipe.free_q.push_back(bi);
                pipe.cv.notify_all();
            }
            set_job("Copying the game's files", done, total);
            const double t = now_s();
            if (t - last_log > 5)
            {
                last_log = t;
                double rs;
                {
                    std::lock_guard<std::mutex> l(pipe.m);
                    rs = pipe.read_s;
                }
                slog("setup: %.0f MB in %.0f s (%.1f MB/s); reading %.1f s, writing %.1f s (saving %.1f s), opening %.1f s, closing %.1f s, waiting %.1f s",
                     double(copied) / 1048576.0, t - t_start, double(copied) / 1048576.0 / (t - t_start), rs, write_s,
                     sync_s + out.sync_s, open_s, close_s, wait_s);
            }
        }
        sync_s += out.sync_s;
        t0 = now_s();
        out.close_file();
        const double c = now_s() - t0;
        close_s += c;
        if (c > 1.0) slog("setup: closing %s took %.1f s", f->path.c_str(), c);
        if (!ok)
        {
            std::remove(dst.c_str()); /* half a file: copied again next time */
            break;
        }
    }
    finish_reader();
    if (!err_msg.empty()) return fail_job(err_msg);
    set_job("Checking the copied files", total, total);
    {
        const double t0 = now_s();
        sync(); /* once, at the end */
        slog("setup: final save to disk took %.1f s", now_s() - t0);
    }
    {
        const double t = now_s() - t_start;
        slog("setup: copied %.0f MB in %.0f s (%.1f MB/s); reading took %.0f s, writing %.0f s", double(copied) / 1048576.0, t,
             t > 0 ? double(copied) / 1048576.0 / t : 0.0, pipe.read_s, write_s);
        char st[160];
        std::snprintf(st, sizeof st, "%.0f MB in %.0f s \xC2\xB7 %.1f MB/s (reading %.0f s, writing %.0f s)", double(copied) / 1048576.0, t,
                      t > 0 ? double(copied) / 1048576.0 / t : 0.0, pipe.read_s, write_s);
        set_stat("Last disc setup", st);
    }
    for (const DiscFile *f : todo) chmod((target + "/" + f->path).c_str(), 0777);

    finish();
}

void run_bench()
{
    const std::string dir = g.data;
    const std::string path = dir + "/speedtest.tmp";
    std::uint64_t fs_free = 0; /* (2.2.4) the real figures */
    if (free_bytes(dir, fs_free) && fs_free < (1ull << 30))
        return fail_job("The speed test needs 1 GB free on the console.");
    constexpr std::uint64_t kSize = 512ull << 20;
    Aligned buf(16u << 20);
    if (!buf.p) return fail_job("The store ran out of memory for the speed test.");
    for (std::size_t i = 0; i < buf.n; ++i) buf.p[i] = std::uint8_t(i * 2654435761u >> 13);
    slog("speed test: writing up to %llu MB to %s each way (stopping after 15 s)", (unsigned long long)(kSize >> 20), dir.c_str());
    double best = 0;
    int best_i = 0;
    for (int i = 0; i < kModeCount; ++i)
    {
        const WriteMode &m = kModes[i];
        set_job(std::string("Testing: ") + m.name, std::uint64_t(i), std::uint64_t(kModeCount));
        std::remove(path.c_str());
        OutFile out;
        const double t0 = mono_s();
        if (!out.open_for(path, kSize, m)) { slog("speed test %s: can't create the file", m.name); continue; }
        bool ok = true;
        std::uint64_t n = 0;
        double first = 0;
        while (n < kSize && ok && mono_s() - t0 < 15)
        {
            const std::size_t c = std::min<std::size_t>(m.chunk, std::size_t(kSize - n));
            ok = out.write(buf.p, c);
            n += c;
            if (!first && n >= (128ull << 20)) first = mono_s() - t0;
        }
        const double tw = mono_s() - t0;
        fsync(out.fd);
        out.close_file();
        const double total = mono_s() - t0;
        const double rate = total > 0 ? double(n) / 1048576.0 / total : 0;
        slog("speed test %s: %.0f MB, %.1f MB/s with saving (writes %.1f s, saving %.1f s%s)%s", m.name, double(n) / 1048576.0, rate,
             tw, total - tw, first > 0 ? "" : "", ok ? "" : " - a write failed");
        char st[96];
        std::snprintf(st, sizeof st, "%.1f MB/s", rate);
        set_stat(std::string("Write: ") + m.name, st);
        if (ok && rate > best) { best = rate; best_i = i; }
        std::remove(path.c_str()); /* the test's own file */
    }
    g_write_mode = best_i;
    {
        const std::string v = kModes[best_i].name;
        write_file(dir + "/write_mode.txt", v.data(), v.size());
    }
    char st[128];
    std::snprintf(st, sizeof st, "%s \xC2\xB7 %.1f MB/s", kModes[best_i].name, best);
    set_stat("Copies now use", st);
    slog("speed test: fastest is \"%s\" at %.1f MB/s; game setups use it from now on", kModes[best_i].name, best);
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.step = "Speed test done";
}

/* ---- ShadowMountPlus ------------------------------------------------------------- */
std::string smp_file() { return g.data + "/shadowmountplus.txt"; }

/* ---- Deleting a game -------------------------------------------------------------- */
/* The game's folder, and the game files the store set up for it outside that folder
 * (the setup's data folder, e.g. Unleashed's ressources: the disc's files, the update,
 * the DLC and what the port builds from them; saves live beside it and stay). By the
 * copier payload, which wrote those files, or here without a loader - never with
 * lstat(), which fails on the PS5 - and checked afterwards: anything still there is a
 * failure that says so, never "Deleted". */
void run_delete(const std::string &id, bool keep_saves)
{
    set_job("Deleting", 0, 0);
    const std::string folder = app_folder(id);
    /* Only a game's own folder inside a homebrew folder: never the homebrew folder itself,
     * never the store, never anything with ".." in it. */
    const std::size_t hb = folder.find("/homebrew/");
    const bool place_ok = folder.rfind("/data/homebrew/", 0) == 0 || (folder.rfind("/mnt/", 0) == 0 && hb != std::string::npos);
    const std::string leaf = hb == std::string::npos ? "" : folder.substr(hb + 10);
    if (!place_ok || leaf.empty() || leaf.find('/') != std::string::npos || folder.find("..") != std::string::npos ||
        id == "PPSA92000" || leaf == "PPSA92000" || !fs::deletable(folder, g.data))
    {
        slog("delete %s: refused for %s", id.c_str(), folder.c_str());
        return fail_job("The store won't delete that folder.");
    }
    std::vector<std::string> paths{folder};
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const App &x : g.catalog.apps)
            if (x.id == id && x.has_setup)
            {
                auto add = [&](const std::string &t) {
                    const bool inside = t == folder || t.rfind(folder + "/", 0) == 0;
                    bool covered = false;
                    for (const std::string &p : paths)
                        covered = covered || t == p || t.rfind(p + "/", 0) == 0;
                    if (!inside && !covered && t.rfind("/data/", 0) == 0 && t.find("/homebrew") == std::string::npos &&
                        paths.size() < 16 && fs::deletable(t, g.data))
                        paths.push_back(t);
                };
                add(x.data_dir);
                for (const Slot &sl : x.slots) add(slot_target(x, sl));
            }
    }
    /* Keeping the saves: the game's save folders inside what's being deleted stay; one that
     * is a whole folder the game set up (its data folder) isn't deleted at all; one outside
     * stays anyway. Anything the store can't keep as listed: nothing is deleted. */
    std::vector<std::string> keep, whole;
    if (keep_saves)
    {
        std::vector<std::string> saves;
        {
            std::lock_guard<std::mutex> l(g.m);
            for (const App &x : g.catalog.apps)
                if (x.id == id) saves = x.saves;
        }
        int kept = 0;
        bool bad = false;
        for (const std::string &s0 : saves)
        {
            if (!plain_path(s0)) { bad = true; continue; }
            const std::string s = s0[0] == '/' ? s0 : folder + "/" + s0;
            if (s == folder) { bad = true; continue; } /* the whole game: not a save folder */
            bool inside = false;
            for (std::size_t i = 0; i < paths.size(); ++i)
                if (s == paths[i])
                {
                    paths.erase(paths.begin() + std::ptrdiff_t(i)); /* kept whole */
                    whole.push_back(s);
                    break;
                }
                else
                    inside = inside || s.rfind(paths[i] + "/", 0) == 0;
            if (inside && keep.size() >= 16) { bad = true; continue; }
            if (inside) keep.push_back(s);
            ++kept;
        }
        if (bad || kept == 0)
        {
            slog("delete %s: keeping saves asked for, but the listing's save folders can't be kept (%zu listed)", id.c_str(), saves.size());
            return fail_job("The store can't tell where this game keeps its saves, so nothing was deleted. Choose Delete everything to delete it all.");
        }
    }
    for (const std::string &p : paths) slog("delete %s: %s", id.c_str(), p.c_str());
    for (const std::string &k : keep) slog("delete %s: keeping %s", id.c_str(), k.c_str());
    /* ShadowMountPlus lets go of the game first, if it will (it won't while the store is
     * the app on screen; deleting works anyway). */
    const std::string tid = installed_id(id); /* (2.0) the title ID it's under */
    unmount_smp(tid);
    const double t0 = mono_s();
    std::string extra;
    for (std::size_t i = 0; i < paths.size(); ++i) extra += "path" + std::to_string(i) + "=" + paths[i] + "\n";
    for (std::size_t i = 0; i < keep.size(); ++i) extra += "keep" + std::to_string(i) + "=" + keep[i] + "\n";
    std::size_t files = 0;
    const Copier c = run_copier("delete", "", folder, nullptr, extra);
    if (c == Copier::Failed)
    {
        scan_homebrew();
        return; /* run_copier said why */
    }
    if (c == Copier::Unavailable)
    {
        /* The game files first, the game's own folder last (as the copier does). */
        for (std::size_t k = 0; k < paths.size(); ++k)
        {
            const std::size_t i = (k + 1) % paths.size();
            std::size_t n = 0;
            std::string bad;
            int e = 0;
            if (!fs::remove_tree(
                    paths[i], n, bad, e, [&](std::size_t so_far) { set_job("Deleting", files + so_far, 0); }, {},
                    [&](const std::string &line) { slog("delete %s: %s", id.c_str(), line.c_str()); }, &keep))
            {
                slog("delete %s: stopped at %s (errno %d) after %zu files", id.c_str(), bad.c_str(), e, files + n);
                scan_homebrew();
                /* A folder the game made for itself alone: only the copier payload can act as
                 * its owner, and it wasn't there. */
                if (e == EACCES)
                    return fail_job(bad + " couldn't be deleted: the game made it for itself, and only the store's "
                                          "helper payload can remove it. Make sure your payload loader is running "
                                          "(port 9021), then press Delete again.");
                return fail_job(bad + " couldn't be deleted (error " + std::to_string(e) + "). Close the game if it's running, then try again.");
            }
            files += n;
            slog("delete %s: %s, %zu files", id.c_str(), paths[i].c_str(), n);
            if (!fs::holds(paths[i], keep) && !fs::gone(paths[i])) break; /* said below, before the game's own folder goes */
            if (i > 0 && !fs::holds(paths[i], keep)) fs::remove_empty_parents(paths[i], [&](const std::string &up) { slog("delete %s: removed the empty folder %s", id.c_str(), up.c_str()); });
        }
    }
    /* Checked here too, whoever deleted: still there is a failure (in the order deleted). */
    for (std::size_t k = 0; k < paths.size(); ++k)
        if (const std::string &p = paths[(k + 1) % paths.size()];
            fs::holds(p, keep) ? (p == folder && (!fs::gone(p + "/eboot.bin") || !fs::gone(p + "/sce_sys"))) : !fs::gone(p))
        {
            slog("delete %s: %s is still there afterwards", id.c_str(), p.c_str());
            scan_homebrew();
            return fail_job(p + " is still there. Close the game if it's running, then press Delete again.");
        }
    std::remove((g.data + "/installed/" + id + ".txt").c_str());
    std::remove((g.data + "/installed/" + id + ".hold").c_str()); /* (2.1) */
    {
        std::lock_guard<std::mutex> l(g.m);
        g.holds.erase(id);
    }
    if (DIR *d = opendir((g.data + "/setup").c_str()))
    {
        std::vector<std::string> mine;
        while (dirent *e = readdir(d))
            if (std::strncmp(e->d_name, (id + ".").c_str(), id.size() + 1) == 0) mine.push_back(e->d_name);
        closedir(d);
        for (const std::string &n : mine) std::remove((g.data + "/setup/" + n).c_str());
    }
    if (c == Copier::Done)
        slog("deleted %s: %s and %zu more folder(s), by the copier, in %.1f s", id.c_str(), folder.c_str(), paths.size() - 1, mono_s() - t0);
    else
        slog("deleted %s: %s and %zu more folder(s), %zu files in %.1f s", id.c_str(), folder.c_str(), paths.size() - 1, files, mono_s() - t0);
    scan_homebrew();
    /* Another copy of the same game elsewhere in the homebrew folder? Say where. */
    const std::string other = app_folder(id);
    const bool another = other != folder && exists(other + "/eboot.bin");
    if (another) slog("delete %s: another copy is still at %s", id.c_str(), other.c_str());
    /* the folder first, so a scan can't add it back; (2.2) ShadowMountPlus takes no changes while the
     * store is open, so the tile usually goes once it has closed (the launcher sees to it) */
    const int tile = another ? 0 : forget_tile(tid);
    /* (2.0) Gone from its new title ID: installed again, it's checked for a clash afresh. */
    if (!another && !moved_id(id).empty()) forget_move(id);
    if (!another && own_id(id) != id) remember_own(id, id); /* (2.0) a custom app's title ID is read again on its next install */
    std::lock_guard<std::mutex> l(g.m);
    if (!another) g.stats_due.push_back("-" + id); /* (2.0) for the charts */
    g.job.phase = Phase::Done;
    g.job.home = tile;
    g.job.step = "Deleted";
    if (!keep.empty() || !whole.empty())
    {
        std::string where;
        for (const std::string &k : whole)
            if (exists(k)) where += (where.empty() ? "" : ", ") + k;
        for (const std::string &k : keep)
            if (exists(k)) where += (where.empty() ? "" : ", ") + k;
        if (!where.empty())
            g.job.note = "Your saves stayed in " + where + ": install the game again and it picks up where you left off.";
    }
    if (another)
        g.job.note = "Deleted " + folder + ". Another copy of this game is still at " + other + ": press Delete again to remove it too.";
}

/* ---- Recomp Store's own updates ------------------------------------------------------ */
const char *kStoreRepo = "elripalda/RecompStore";

/* 1 once GitHub answered (whether or not there's anything newer), 0 when it couldn't be
 * reached, -1 when it answered with an error. */
int check_store_update(porpoise::http::Session &http)
{
    std::vector<std::uint8_t> body;
    const int status = http.get(std::string("https://api.github.com/repos/") + kStoreRepo + "/releases?per_page=10", body, {}, 4u << 20);
    Json j;
    std::string newest;
    const bool answered = status == 200 && Json::parse(std::string(body.begin(), body.end()), j) && j.is_array();
    if (answered)
        for (std::size_t i = 0; i < j.size() && newest.empty(); ++i)
        {
            const Json &r = j[i];
            if (r["draft"].boolean(false) || r["prerelease"].boolean(false)) continue;
            /* (2.1) Only a release whose zip comes with its signature from the owner's PC. */
            bool zip = false, sig = false;
            for (std::size_t k = 0; k < r["assets"].size(); ++k)
            {
                zip = zip || r["assets"][k]["name"].str() == "PPSA92000.zip";
                sig = sig || r["assets"][k]["name"].str() == "PPSA92000.zip.sig";
            }
            if (zip && sig) newest = r["tag_name"].str();
        }
    const bool newer = !newest.empty() && version_newer(newest, kVersion);
    slog("store: newest release on GitHub: %s (HTTP %d; this is %s)%s", newest.empty() ? "none" : newest.c_str(), status, kVersion,
         newer ? "; an update is ready" : "");
    std::lock_guard<std::mutex> l(g.m);
    if (!answered) return status <= 0 ? 0 : -1;
    g.store_update = newer ? newest : "";
    if (g.auto_update && !newer)
    {
        /* Already the newest: nothing to do after all. */
        g.auto_update = false;
        std::remove((g.data + "/selfupdate/autoupdate").c_str());
        slog("store: installed from an installer's own copy, and it's already the newest");
    }
    return 1;
}

/* The store's own update: the newest full release's PPSA92000.zip, downloaded and checked
 * here (the store can always reach GitHub; a payload may not), for the installer. */
void run_store_download()
{
    {
        std::lock_guard<std::mutex> l(g.m);
        g.job = JobState();
        g.job.phase = Phase::Working;
        g.job.kind = "storeupdate";
        g.job.app_id = "PPSA92000";
        g.job.step = "Finding the newest release";
        g.store_zip.clear();
    }
    porpoise::http::Session http("store-update", "RecompStore/1.0");
    if (!http.init()) return fail_job("Your PS5 isn't online.");
    std::vector<std::uint8_t> body;
    const int status = http.get(std::string("https://api.github.com/repos/") + kStoreRepo + "/releases?per_page=10", body, {}, 4u << 20);
    Json j;
    std::string url, digest, tag, sig_url;
    std::uint64_t size = 0;
    if (status == 200 && Json::parse(std::string(body.begin(), body.end()), j) && j.is_array())
        for (std::size_t i = 0; i < j.size() && url.empty(); ++i)
        {
            const Json &r = j[i];
            if (r["draft"].boolean(false) || r["prerelease"].boolean(false)) continue;
            std::string u, su, dg;
            std::uint64_t sz = 0;
            for (std::size_t k = 0; k < r["assets"].size(); ++k)
            {
                const Json &as = r["assets"][k];
                if (as["name"].str() == "PPSA92000.zip")
                {
                    u = as["browser_download_url"].str();
                    sz = std::uint64_t(as["size"].integer());
                    dg = as["digest"].str();
                }
                else if (as["name"].str() == "PPSA92000.zip.sig")
                    su = as["browser_download_url"].str();
            }
            if (u.empty() || su.empty()) continue; /* (2.1) a release without its signature doesn't count */
            url = u, sig_url = su, size = sz, digest = dg, tag = r["tag_name"].str();
        }
    /* (2.1) Only from the store's own GitHub releases. */
    if (!from_own_repo(url, kStoreRepo) || !from_own_repo(sig_url, kStoreRepo))
        return fail_job(status == 200 ? "There's no signed Recomp Store release to update to." : "GitHub didn't answer. Try again in a moment.");
    std::vector<std::uint8_t> sigb;
    const int sst = http.get(sig_url, sigb, {}, 64u << 10);
    const std::string sigtext(sigb.begin(), sigb.end());
    if (sst != 200) return fail_job("The update's signature didn't download. Try again in a moment.");
    make_dirs(g.data + "/downloads");
    const std::string zip = g.data + "/downloads/PPSA92000.zip", part = zip + ".part";
    std::FILE *out = std::fopen(part.c_str(), "wb");
    if (!out) return fail_job("The store couldn't save the download on your PS5.");
    Sha256 sha;
    std::uint64_t got = 0;
    bool write_ok = true;
    set_job("Downloading", 0, size);
    const int st = http.get_stream(url, [&](const std::uint8_t *p, std::size_t n) {
        if (std::fwrite(p, 1, n, out) != n) { write_ok = false; return false; }
        sha.update(p, n);
        got += n;
        set_job("Downloading", got, size);
        return got < (std::uint64_t(512) << 20);
    });
    std::fclose(out);
    const std::string hash = sha.hex();
    slog("store update: %s: HTTP %d, %llu bytes, SHA-256 %s", url.c_str(), st, (unsigned long long)got, hash.c_str());
    if (!write_ok || st != 200 || got == 0 || (size && got != size))
    {
        std::remove(part.c_str());
        return fail_job(write_ok ? "The download didn't finish. Check your connection and try again." : "Your PS5 ran out of room while downloading.");
    }
    if (digest.rfind("sha256:", 0) == 0 && digest.substr(7) != hash)
    {
        std::remove(part.c_str());
        return fail_job("The download doesn't match its checksum, so nothing changed.");
    }
    {
        /* (2.1) Signed on the owner's PC: an update put on GitHub by anyone else stops here. */
        const std::string why = sig_problem(sigtext, "store", hash);
        if (!why.empty())
        {
            slog("store update: refused, %s", why.c_str());
            std::remove(part.c_str());
            return fail_job("This update couldn't be checked (" + why + "), so nothing changed.");
        }
        if (!write_file(zip + ".sig", sigtext.data(), sigtext.size()))
        {
            std::remove(part.c_str());
            return fail_job("The store couldn't save the download on your PS5.");
        }
    }
    if (std::rename(part.c_str(), zip.c_str()) != 0)
    {
        std::remove(part.c_str());
        return fail_job("The store couldn't save the download on your PS5.");
    }
    std::lock_guard<std::mutex> l(g.m);
    g.store_zip = zip;
    g.store_zip_sha = hash;
    g.store_zip_ver = tag;
    g.job.phase = Phase::Done;
    g.job.step = "Downloaded";
    g.job.installed_version = tag;
}

/* ---- Patch notes from GitHub --------------------------------------------------------- */
std::string notes_file(const std::string &repo)
{
    std::string n = repo;
    for (char &c : n)
        if (!(std::isalnum(static_cast<unsigned char>(c)) || c == '-' || c == '_' || c == '.')) c = '_';
    return g.data + "/notes/" + n + ".json";
}

bool parse_releases(const std::string &text, std::vector<Release> &out)
{
    Json j;
    if (!Json::parse(text, j) || !j.is_array()) return false;
    out.clear();
    for (std::size_t i = 0; i < j.size(); ++i)
    {
        const Json &r = j[i];
        if (r["draft"].boolean(false)) continue;
        Release x;
        x.tag = r["tag_name"].str();
        x.name = r["name"].str();
        x.date = r["published_at"].str().substr(0, 10);
        x.body = r["body"].str();
        x.prerelease = r["prerelease"].boolean(false);
        for (std::size_t k = 0; k < r["assets"].size(); ++k) x.assets.push_back(r["assets"][k]["name"].str());
        if (x.tag.empty() && x.name.empty()) continue;
        out.push_back(x);
    }
    return true;
}

/* A repo's releases as GitHub just gave them (an install asked too): kept as its notes. */
void remember_releases(const std::string &repo, const std::string &text)
{
    std::vector<Release> rel;
    if (!parse_releases(text, rel)) return;
    make_dirs(g.data + "/notes");
    write_file(notes_file(repo), text.data(), text.size());
    std::lock_guard<std::mutex> l(g.m);
    g.notes[repo] = rel;
    g.notes_at[repo] = mono_s();
    g.notes_failed.erase(repo);
}

bool fetch_notes(porpoise::http::Session &http, const std::string &repo)
{
    static std::mutex one; /* (2.2.3) the worker and the quick thread never both at once */
    std::lock_guard<std::mutex> lk(one);
    std::vector<std::uint8_t> body;
    const int status = github_releases(http, repo, body);
    const std::string text(body.begin(), body.end());
    std::vector<Release> rel;
    const bool ok = status == 200 && parse_releases(text, rel);
    if (ok)
    {
        make_dirs(g.data + "/notes");
        write_file(notes_file(repo), text.data(), text.size());
    }
    slog("patch notes %s: HTTP %d, %zu releases", repo.c_str(), status, rel.size());
    std::lock_guard<std::mutex> l(g.m);
    g.notes_at[repo] = mono_s();
    if (ok)
    {
        g.notes[repo] = rel;
        g.notes_failed.erase(repo);
    }
    else if (!g.notes.count(repo))
        g.notes_failed.insert(repo);
    return status > 0; /* GitHub answered, even if only to say no (a repo gone private) */
}

/* The GitHub repos of the games on this console that come from GitHub releases: their
 * releases say when there's an update. */
std::vector<std::string> installed_repos()
{
    std::vector<std::string> out;
    Catalog c;
    {
        std::lock_guard<std::mutex> l(g.m);
        c = g.catalog;
    }
    for (const App &a : c.apps)
        if (a.source == "github" && !a.repo.empty() && exists(app_folder(a.id) + "/eboot.bin") &&
            std::find(out.begin(), out.end(), a.repo) == out.end())
            out.push_back(a.repo);
    return out;
}

/* A repo's releases as last saved, when that was less than half an hour ago (so opening
 * the store again and again doesn't use up GitHub's hourly allowance). */
bool fresh_notes(const std::string &repo, long long max_age)
{
    struct stat st;
    if (stat(notes_file(repo).c_str(), &st) != 0) return false;
    const long long age = (long long)std::time(nullptr) - (long long)st.st_mtime;
    if (age < 0 || age > max_age) return false; /* a date ahead of the clock: stale */
    std::string text;
    std::vector<Release> rel;
    if (!read_file(notes_file(repo), text) || !parse_releases(text, rel)) return false;
    std::lock_guard<std::mutex> l(g.m);
    g.notes[repo] = rel;
    g.notes_at[repo] = mono_s();
    return true;
}

void *worker(void *)
{
    load_cached();
    {
        std::lock_guard<std::mutex> l(g.m);
        attach_art(g.catalog);
    }
    long long next_refresh = 0, next_store_check = 0, next_games_check = 0;
    for (;;)
    {
        timespec ts;
        clock_gettime(CLOCK_MONOTONIC, &ts);
        const long long now = ts.tv_sec;
        bool forced = false, rescan = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            forced = g.force_check;
            g.force_check = false;
            rescan = g.rescan;
            g.rescan = false;
        }
        if (rescan)
        {
            /* (2.0) Refresh: an app installed some other way while the store was open shows up. */
            scan_homebrew();
            std::lock_guard<std::mutex> l(g.m);
            ++g.revision;
        }
        {
            /* (2.0) ShadowMountPlus asked again, once a new folder has settled (smp_scan_later). */
            bool ask = false;
            {
                std::lock_guard<std::mutex> l(g.m);
                for (auto it = g.smp_scan_at.begin(); it != g.smp_scan_at.end();)
                    if (*it <= now) { ask = true; it = g.smp_scan_at.erase(it); }
                    else ++it;
            }
            if (ask) smp_scan_again();
        }
        if (forced)
            next_refresh = next_store_check = next_games_check = 0;
        if (now >= next_refresh)
        {
            /* At start, then every 10 minutes (or a minute after a failed try). */
            bool ok = false, store_ok = true, games_ok = true;
            {
                porpoise::http::Session http("store", "RecompStore/1.0");
                if (http.init())
                {
                    refresh_catalog(http);
                    refresh_beta(http);
                    {
                        /* (2.0) Every custom source, read again, and the charts. */
                        std::vector<std::string> all;
                        {
                            std::lock_guard<std::mutex> l(g.m);
                            for (const Source &x : g.sources) all.push_back(x.value);
                            g.sources_due.clear();
                        }
                        for (const std::string &v : all) fetch_source(http, v);
                        refresh_charts(http);
                        refresh_ids(http);
                    }
                    fetch_art(http, 0);
                    if (now >= next_store_check)
                    {
                        /* At start, then every 6 hours; a minute after GitHub couldn't be reached,
                         * half an hour after it answered with an error. */
                        const int r = check_store_update(http);
                        store_ok = r > 0;
                        next_store_check = now + (r > 0 ? 6 * 3600 : r == 0 ? 60 : 1800);
                    }
                    if (now >= next_games_check)
                    {
                        /* The installed games' releases: at start, then every 6 hours (ten
                         * minutes after a try GitHub didn't answer). */
                        /* Check for updates asks GitHub again, but not about a repo it read
                         * in the last two minutes (GitHub allows 60 questions an hour). */
                        for (const std::string &repo : installed_repos())
                            if (!fresh_notes(repo, forced ? 120 : 1800) && !fetch_notes(http, repo)) games_ok = false;
                        next_games_check = now + (games_ok ? 6 * 3600 : 600);
                        std::lock_guard<std::mutex> l(g.m);
                        ++g.revision; /* the screens show what's newer */
                        if (games_ok) ++g.games_checks; /* (2.2.2) auto-update looks then */
                    }
                    ok = true;
                }
            }
            {
                std::lock_guard<std::mutex> l(g.m);
                g.loading = false;
                if (!ok) g.online = false;
                if (forced) g.update_check = ok && store_ok && games_ok ? 2 : 3;
            }
            next_refresh = now + (ok ? 600 : 60);
            if (next_store_check > now && next_store_check < next_refresh)
                next_refresh = next_store_check; /* the store's own check, retried sooner */
        }
        {
            /* (1.1.0) A code to redeem: before any job waiting, as it takes a moment. */
            std::string code;
            {
                std::lock_guard<std::mutex> l(g.m);
                code.swap(g.pending_redeem);
            }
            if (!code.empty())
            {
                run_redeem(code);
                continue;
            }
            /* (2.2.3) Send logs: the quick thread's now (quick_main), never behind a job. */
            /* (2.0) A source added (read it now, so its apps show), and a report on one. */
            std::vector<std::string> due;
            bool report = false;
            std::string rvalue, rwhy;
            {
                std::lock_guard<std::mutex> l(g.m);
                due.assign(g.sources_due.begin(), g.sources_due.end());
                g.sources_due.clear();
                report = g.pending_src_report;
                g.pending_src_report = false;
                rvalue.swap(g.src_report_value);
                rwhy.swap(g.src_report_why);
            }
            if (report)
            {
                run_report_source(rvalue, rwhy);
                continue;
            }
            if (!due.empty())
            {
                porpoise::http::Session http("source", "RecompStore/2.0");
                if (http.init())
                {
                    for (const std::string &v : due) fetch_source(http, v);
                    fetch_art(http, 0);
                }
                else
                {
                    std::lock_guard<std::mutex> l(g.m);
                    for (Source &x : g.sources)
                        if (x.state == 0 || x.state == 1)
                        {
                            x.state = 3;
                            x.error = "Your PS5 isn't online. It's read once it is.";
                        }
                    ++g.revision;
                }
                continue;
            }
        }
        std::string id, sapp, sslot, simage, picked;
        bool bench = false, sfetch = false;
        std::string del;
        bool store_dl = false, del_keep = false;
        int answer = 0;
        std::string mkind, mfolder, mdest; /* (2.2) */
        std::string want_root;              /* (2.2.2) */
        std::vector<std::string> mdeletes;
        bool smpget = false;
        std::string task, backup_first;     /* (2.3.0) */
        std::vector<std::string> task_args;
        {
            std::lock_guard<std::mutex> l(g.m);
            task.swap(g.pending_task);
            task_args.swap(g.pending_task_args);
            backup_first.swap(g.pending_backup_first);
            want_root.swap(g.pending_root);
            mdeletes.swap(g.pending_mdeletes);
            smpget = g.pending_smpget;
            g.pending_smpget = false;
            mkind.swap(g.pending_manage);
            mfolder.swap(g.pending_manage_folder);
            mdest.swap(g.pending_manage_dest);
            answer = g.pending_resolve;
            g.pending_resolve = 0;
            picked.swap(g.pending_version); /* (2.1) */
            del_keep = g.pending_delete_keep;
            store_dl = g.pending_store_dl;
            g.pending_store_dl = false;
            bench = g.pending_bench;
            g.pending_bench = false;
            del.swap(g.pending_delete);
            id.swap(g.pending_install);
            sapp.swap(g.pending_setup_app);
            sslot.swap(g.pending_setup_slot);
            simage.swap(g.pending_setup_image);
            sfetch = g.pending_setup_fetch;
            g.pending_setup_fetch = false;
        }
        if (!id.empty())
            run_install(id, answer, picked, want_root);
        else if (!sapp.empty())
            run_setup(sapp, sslot, simage, sfetch);
        else if (bench)
            run_bench();
        else if (!task.empty())
            run_task(task, task_args); /* (2.3.0) */
        else if (!del.empty())
        {
            if (backup_first.empty() || run_backup_first(backup_first)) run_delete(del, del_keep);
        }
        else if (!mkind.empty())
        {
            if (backup_first.empty() || run_backup_first(backup_first)) run_manage(mkind, mfolder, mdest); /* (2.2) */
        }
        else if (!mdeletes.empty())
            run_manage_many(mdeletes); /* (2.2.2) */
        else if (smpget)
            run_get_smp(); /* (2.2.2) */
        else if (store_dl)
            run_store_download();
        else
        {
            std::set<std::string> repos;
            bool art = false;
            {
                std::lock_guard<std::mutex> l(g.m);
                repos.swap(g.notes_wanted);
                art = g.art_more;
            }
            if (repos.empty() && art)
            {
                /* (2.0) The pictures left for a job, now it's done. */
                porpoise::http::Session http("art", "RecompStore/2.0");
                if (http.init())
                    fetch_art(http, 0);
                else
                {
                    std::lock_guard<std::mutex> l(g.m);
                    g.art_more = false; /* the next refresh tries again */
                }
            }
            else if (repos.empty())
                usleep(50000);
            else
            {
                porpoise::http::Session http("notes", "RecompStore/1.0");
                const bool up = http.init();
                for (const std::string &r : repos)
                {
                    if (up)
                        fetch_notes(http, r);
                    else
                    {
                        std::lock_guard<std::mutex> l(g.m);
                        g.notes_at[r] = mono_s();
                        if (!g.notes.count(r)) g.notes_failed.insert(r);
                    }
                }
            }
        }
    }
    return nullptr;
}

/* (2.2.3) The quick thread: what mustn't wait for an install. Send logs any time; and while the
 * worker is busy with a job, the patch notes and Versions a page asks for, and a few pictures. Each
 * takes the one HTTP session for a moment; a download hands it over between pieces. */
void *quick_main(void *)
{
    double last_art = 0;
    for (;;)
    {
        usleep(50000);
        std::string lapp, lmsg, lextra;
        bool lsend = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            lsend = g.pending_logs;
            g.pending_logs = false;
            lapp.swap(g.pending_logs_app);
            lmsg.swap(g.pending_logs_msg);
            lextra.swap(g.pending_logs_extra);
        }
        if (lsend)
        {
            run_send_logs(lapp, lmsg, lextra);
            continue;
        }
        bool busy = false, art = false;
        std::set<std::string> repos;
        {
            std::lock_guard<std::mutex> l(g.m);
            busy = g.job.phase == Phase::Working;
            if (busy)
            {
                repos.swap(g.notes_wanted);
                art = g.art_more;
            }
        }
        if (!busy) continue; /* the worker does these itself when it's free */
        if (!repos.empty())
        {
            porpoise::http::Session http("notes-quick", "RecompStore/2.2");
            const bool up = http.init();
            for (const std::string &r : repos)
            {
                if (up)
                    fetch_notes(http, r);
                else
                {
                    std::lock_guard<std::mutex> l(g.m);
                    g.notes_at[r] = mono_s();
                    if (!g.notes.count(r)) g.notes_failed.insert(r);
                }
            }
            continue;
        }
        if (art && mono_s() - last_art > 4)
        {
            last_art = mono_s();
            porpoise::http::Session http("art-quick", "RecompStore/2.2");
            if (http.init()) fetch_art(http, 2);
        }
    }
    return nullptr;
}
} // namespace

/* ---- Games already on the console --------------------------------------------------- */
namespace
{
std::mutex g_fold_m;
std::map<std::string, std::string> g_folders; /* title ID -> folder */
std::string g_install_root = kHomebrew;       /* (2.0) where a new app goes; under g_fold_m */

/* (2.0) The homebrew folders the store looks in for apps, and can install to: the console's own,
 * then the drives' (the ones ShadowMountPlus scans: the M.2 SSD, extended storage, USB). */
std::vector<std::string> homebrew_roots()
{
    std::vector<std::string> r{kHomebrew, "/mnt/ext1/homebrew", "/mnt/ext0/homebrew"};
    for (int i = 0; i < 8; ++i) r.push_back("/mnt/usb" + std::to_string(i) + "/homebrew");
    return r;
}

void scan_homebrew()
{
    std::map<std::string, std::string> found;
    for (const std::string &root : homebrew_roots())
    {
        DIR *d = opendir(root.c_str());
        if (!d) continue;
        std::map<std::string, std::string> here; /* this folder's apps: title ID -> folder */
        while (dirent *e = readdir(d))
        {
            if (e->d_name[0] == '.') continue;
            const std::string dir = root + "/" + e->d_name;
            if (!exists(dir + "/eboot.bin")) continue;
            std::string text;
            Json pj;
            if (read_file(dir + "/sce_sys/param.json", text) && Json::parse(text, pj))
            {
                const std::string tid = pj["titleId"].str();
                /* Two copies of one game: the folder named after its title ID (where the
                 * store installs) wins over any other. */
                if (!tid.empty() && (!here.count(tid) || tid == e->d_name)) here[tid] = dir;
            }
            here[e->d_name] = dir; /* a folder named after a title ID is that game's */
        }
        closedir(d);
        /* (2.0) A drive's apps: what the console's own folder (or a drive before it) doesn't
         * have, or has only under another folder's name. */
        const auto leaf = [](const std::string &p) { return p.substr(p.find_last_of('/') + 1); };
        for (const auto &kv : here)
        {
            auto it = found.find(kv.first);
            if (it == found.end() || (leaf(it->second) != kv.first && leaf(kv.second) == kv.first)) found[kv.first] = kv.second;
        }
    }
    std::string list;
    for (const auto &f : found)
        list += " " + f.first + "=" + (f.second.rfind(std::string(kHomebrew) + "/", 0) == 0 ? f.second.substr(f.second.find_last_of('/') + 1) : f.second);
    if (data_lost(-1))
    {
        /* (2.2.1) Not "nothing installed": the store can't see the console's storage any more. */
        slog("homebrew on this console: not looked at again (the store lost its storage)");
        return;
    }
    slog("homebrew on this console:%s", list.empty() ? " none" : list.c_str());
    std::lock_guard<std::mutex> l(g_fold_m);
    g_folders.swap(found);
}

/* ---- The title ID guard (2.0) --------------------------------------------------------
 * Two apps with one title ID can't both be on a PS5: installing the second writes into the
 * first one's folder, or fights an installed package for the same home screen tile. Before
 * an install writes anything, the store looks for another app with the game's title ID. If
 * there is one, the player can keep both: the game goes under a new ID from a block kept
 * for this (PPSA97001 on), its param.json says so after every install and update, and the
 * store remembers the move (moves.json, and the marker in the game's folder). */
std::mutex g_move_m;
std::map<std::string, std::string> g_moves; /* app ID -> the title ID it's installed under */
/* A custom source's app whose ID isn't its title ID ("gh-..."): the title ID its release's
 * param.json said when it was installed (titles.json). Under g_move_m. */
std::map<std::string, std::string> g_own;

std::string json_text(const std::string &s)
{
    std::string out = "\"";
    for (const unsigned char c : s)
    {
        if (c == '"' || c == '\\') { out += '\\'; out += char(c); }
        else if (c < 0x20) { char b[8]; std::snprintf(b, sizeof b, "\\u%04x", c); out += b; }
        else out += char(c);
    }
    return out + "\"";
}

/* A param.json's name for its app: its default language's titleName, else any. */
std::string param_name(const Json &pj)
{
    const Json &lp = pj["localizedParameters"];
    std::string name = lp[lp["defaultLanguage"].str("en-US").c_str()]["titleName"].str();
    for (std::size_t i = 0; i < lp.members().size() && name.empty(); ++i) name = lp.members()[i].second["titleName"].str();
    return name;
}

std::string lower_text(std::string s)
{
    for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
    return s;
}

std::string title_folder(const std::string &tid)
{
    std::lock_guard<std::mutex> l(g_fold_m);
    auto it = g_folders.find(tid);
    return it != g_folders.end() ? it->second : std::string();
}

bool g_moves_lost = false; /* (2.0) moves.json or titles.json couldn't be read at start */

void load_moves()
{
    std::string text;
    Json j;
    std::map<std::string, std::string> m, own;
    g_moves_lost = (exists(g.data + "/moves.json") && !(read_file(g.data + "/moves.json", text) && Json::parse(text, j) && j.is_object())) ||
                   (exists(g.data + "/titles.json") && !(read_file(g.data + "/titles.json", text) && Json::parse(text, j) && j.is_object())) ||
                   (!exists(g.data + "/moves.json") && exists(g.data + "/installed"));
    if (read_file(g.data + "/moves.json", text) && Json::parse(text, j) && j.is_object())
        for (const auto &kv : j.members())
            if (!kv.first.empty() && kv.second.str().size() == 9) m[kv.first] = kv.second.str();
    if (read_file(g.data + "/titles.json", text) && Json::parse(text, j) && j.is_object())
        for (const auto &kv : j.members())
            if (!kv.first.empty() && title_id_ok(kv.second.str())) own[kv.first] = kv.second.str();
    std::lock_guard<std::mutex> l(g_move_m);
    g_moves.swap(m);
    g_own.swap(own);
}

void save_moves()
{
    std::string text = "{", own = "{";
    {
        std::lock_guard<std::mutex> l(g_move_m);
        for (const auto &kv : g_moves) text += (text.size() > 1 ? "," : "") + json_text(kv.first) + ":" + json_text(kv.second);
        for (const auto &kv : g_own) own += (own.size() > 1 ? "," : "") + json_text(kv.first) + ":" + json_text(kv.second);
    }
    text += "}\n";
    own += "}\n";
    if (!write_file(g.data + "/moves.json", text.data(), text.size())) slog("title ID guard: couldn't save moves.json");
    if (!write_file(g.data + "/titles.json", own.data(), own.size())) slog("title ID guard: couldn't save titles.json");
}

/* (2.0) A custom app's own title ID, read from its release on install. */
void remember_own(const std::string &app_id, const std::string &tid)
{
    {
        std::lock_guard<std::mutex> l(g_move_m);
        if (tid == app_id) g_own.erase(app_id);
        else g_own[app_id] = tid;
    }
    save_moves();
}

/* The install the store made for gh-... (its folder's marker) is this app's: the same repo. */
bool renamed_same(const std::string &old_id, const App &a)
{
    std::string folder;
    {
        std::lock_guard<std::mutex> l(g_move_m);
        auto mv = g_moves.find(old_id);
        auto ow = g_own.find(old_id);
        folder = mv != g_moves.end() ? mv->second : ow != g_own.end() ? ow->second : std::string();
    }
    if (folder.empty()) return false;
    folder = tid_folder(folder);
    std::string text;
    Json mk;
    return read_file(folder + "/" + kMarker, text) && Json::parse(text, mk) && mk["app"].str() == old_id &&
           lower_text(mk["source"].str()) == lower_text("github:" + a.repo);
}

/* A source that gave an app as "gh-..." and later by its title ID (the site could read it
 * now): what the store kept under the old ID follows it. g.m held. */
void adopt_renamed(Catalog &c)
{
    std::map<std::string, std::string> own;
    {
        std::lock_guard<std::mutex> l(g_move_m);
        own = g_own;
    }
    if (own.empty()) return;
    bool changed = false;
    for (const App &a : c.apps)
    {
        if (!a.custom || a.id.rfind("gh-", 0) == 0 || exists(g.data + "/installed/" + a.id + ".txt")) continue;
        for (const auto &kv : own)
            if (kv.second == a.id && kv.first.rfind("gh-", 0) == 0 && exists(g.data + "/installed/" + kv.first + ".txt") &&
                renamed_same(kv.first, a))
            {
                std::rename((g.data + "/installed/" + kv.first + ".txt").c_str(), (g.data + "/installed/" + a.id + ".txt").c_str());
                std::lock_guard<std::mutex> l(g_move_m);
                g_own.erase(kv.first);
                auto mv = g_moves.find(kv.first);
                if (mv != g_moves.end())
                {
                    g_moves[a.id] = mv->second;
                    g_moves.erase(kv.first);
                }
                changed = true;
                slog("sources: %s is %s now; its install record follows it", kv.first.c_str(), a.id.c_str());
                break;
            }
    }
    if (changed) save_moves();
}

/* A data folder or save path under the game's own folder (from) follows the game to where it
 * is (to): its new title ID's folder, or (2.0) a homebrew folder on a drive. */
std::string moved_path(const std::string &p, const std::string &from, const std::string &to)
{
    if (p == from) return to;
    if (p.compare(0, from.size() + 1, from + "/") == 0) return to + p.substr(from.size());
    return p;
}

void apply_moves(Catalog &c)
{
    std::map<std::string, std::string> m, own, folders;
    std::string root;
    {
        std::lock_guard<std::mutex> l(g_move_m);
        m = g_moves;
        own = g_own;
    }
    {
        std::lock_guard<std::mutex> l(g_fold_m);
        folders = g_folders;
        root = g_install_root;
    }
    for (App &a : c.apps)
    {
        /* The catalog's own paths, kept the first time: every later change starts from them. */
        if (!a.src_set)
        {
            a.data_dir_src = a.data_dir;
            a.saves_src = a.saves;
            a.src_set = true;
        }
        a.data_dir = a.data_dir_src;
        a.saves = a.saves_src;
        if (a.data_dir.empty() && a.saves.empty()) continue;
        const std::string own_tid = own.count(a.id) ? own[a.id] : a.id;
        auto mv = m.find(a.id);
        const std::string tid = mv != m.end() ? mv->second : own_tid;
        /* The homebrew folder it's in (or goes to), and its title ID's folder there: a game kept
         * under another folder name keeps its data where the catalog says, as before 2.0. */
        auto f = folders.find(tid);
        const std::string base = f != folders.end() ? f->second.substr(0, f->second.find_last_of('/')) : root;
        const std::string from = std::string(kHomebrew) + "/" + own_tid, to = base + "/" + tid;
        if (from == to) continue;
        a.data_dir = moved_path(a.data_dir, from, to);
        for (std::string &s : a.saves) s = moved_path(s, from, to);
    }
}

void remember_move(const std::string &app_id, const std::string &new_id)
{
    {
        std::lock_guard<std::mutex> l(g_move_m);
        g_moves[app_id] = new_id;
    }
    save_moves();
    slog("title ID guard: %s goes under %s from now on", app_id.c_str(), new_id.c_str());
    std::lock_guard<std::mutex> l(g.m);
    apply_moves(g.catalog);
    ++g.revision;
}

void forget_move(const std::string &app_id)
{
    {
        std::lock_guard<std::mutex> l(g_move_m);
        if (!g_moves.erase(app_id)) return;
    }
    save_moves();
    slog("title ID guard: %s goes under its own title ID again", app_id.c_str());
    std::lock_guard<std::mutex> l(g.m);
    apply_moves(g.catalog);
    ++g.revision;
}

/* A package installed on the console with this title ID (its app.pkg in /user/app), and
 * its name when the system's copy of its param.json can be read. */
bool package_there(const std::string &tid, std::string &name)
{
    if (!exists("/user/app/" + tid + "/app.pkg")) return false;
    for (const std::string &p : {"/user/appmeta/" + tid + "/param.json", "/system_data/priv/appmeta/" + tid + "/param.json"})
    {
        std::string text;
        Json pj;
        if (read_file(p, text) && Json::parse(text, pj) && !param_name(pj).empty())
        {
            name = param_name(pj);
            break;
        }
    }
    return true;
}

Clash find_clash(const App &a, const std::string &tid, const std::string &incoming_param)
{
    Clash c;
    const std::string folder = title_folder(tid);
    if (folder.empty() || !exists(folder + "/eboot.bin"))
    {
        /* Nothing in a homebrew folder: an installed package with the same ID still fights
         * it for the home screen. */
        c.found = package_there(tid, c.with);
        c.pkg = c.found;
        return c;
    }
    /* The store's own install of this game: its marker says so, or (before 2.0) its record.
     * An app from the same GitHub repo is this one too (a custom source's app the catalog
     * has since, or the other way round). */
    std::string text;
    Json mk;
    const bool same_repo = a.source == "github" && !a.repo.empty();
    if (read_file(folder + "/" + kMarker, text) && Json::parse(text, mk) && mk.is_object())
    {
        /* A custom source's app: only an install of its own repo is its own (whatever ID the
         * source gives it, it can't claim another app's folder). */
        if (a.custom)
        {
            if (same_repo && lower_text(mk["source"].str()) == lower_text("github:" + a.repo)) return c;
            c.found = true;
            c.with = mk["name"].str();
            return c;
        }
        if (mk["app"].str() == a.id) return c;
        if (same_repo && lower_text(mk["source"].str()) == lower_text("github:" + a.repo)) return c;
        c.found = true;
        c.with = mk["name"].str();
        return c;
    }
    if (!a.custom && exists(g.data + "/installed/" + a.id + ".txt")) return c;
    /* (2.0) A custom source's app and a folder without its marker: always asked. */
    if (a.custom)
    {
        Json have;
        if (read_file(folder + "/sce_sys/param.json", text) && Json::parse(text, have) && have.is_object()) c.with = param_name(have);
        c.found = true;
        return c;
    }
    /* Put there some other way (by hand, or another store): the same app when its content ID
     * or its name matches the release's; anything else is another app. Without a param.json
     * on either side there's nothing to tell them apart by, so it's taken to be this one. */
    Json have, want;
    if (!read_file(folder + "/sce_sys/param.json", text) || !Json::parse(text, have) || !have.is_object()) return c;
    if (incoming_param.empty() || !Json::parse(incoming_param, want) || !want.is_object()) return c;
    const std::string cid = have["contentId"].str(), name = param_name(have);
    if (!cid.empty() && cid == want["contentId"].str()) return c;
    if (!name.empty() && lower_text(name) == lower_text(param_name(want))) return c;
    c.found = true;
    c.with = name;
    return c;
}

/* The first ID of the block kept for moves that nothing on the console or in the catalog uses. */
std::string free_move_id()
{
    std::set<std::string> used;
    {
        std::lock_guard<std::mutex> l(g_fold_m);
        for (const auto &f : g_folders)
        {
            used.insert(f.first);
            used.insert(f.second.substr(f.second.find_last_of('/') + 1));
        }
    }
    {
        std::lock_guard<std::mutex> l(g_move_m);
        for (const auto &kv : g_moves) used.insert(kv.second);
        for (const auto &kv : g_own) used.insert(kv.second);
    }
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const App &x : g.catalog.apps) used.insert(x.id), used.insert(x.title_id);
        for (const App &x : g.catalog.soon) used.insert(x.id);
        used.insert(g.taken_ids.begin(), g.taken_ids.end()); /* (2.0) every ID the site knows is taken */
    }
    /* Apps already out with an ID in this block (before it was kept), in case the site's list
     * hasn't been read yet. */
    for (const char *known : {"PPSA97358", "PPSA97510"}) used.insert(known);
    for (int n = 1; n <= 999; ++n)
    {
        char id[16];
        std::snprintf(id, sizeof id, "PPSA97%03d", n);
        bool there = exists(std::string("/user/app/") + id);
        for (const std::string &r : homebrew_roots()) there = there || exists(r + "/" + id);
        if (used.count(id) || there) continue;
        return id;
    }
    return "";
}

/* One string value in a JSON text, in place (the rest of the file as it is): false when
 * the key isn't there with a plain string. */
bool set_string(std::string &text, const char *key, const std::function<std::string(const std::string &)> &change)
{
    const std::string k = std::string("\"") + key + "\"";
    std::size_t at = text.find(k);
    if (at == std::string::npos) return false;
    at += k.size();
    while (at < text.size() && std::isspace(static_cast<unsigned char>(text[at]))) ++at;
    if (at >= text.size() || text[at] != ':') return false;
    ++at;
    while (at < text.size() && std::isspace(static_cast<unsigned char>(text[at]))) ++at;
    if (at >= text.size() || text[at] != '"') return false;
    const std::size_t end = text.find('"', at + 1);
    if (end == std::string::npos) return false;
    const std::string old = text.substr(at + 1, end - at - 1);
    if (old.find('\\') != std::string::npos) return false;
    text.replace(at + 1, end - at - 1, change(old));
    return true;
}

bool retitle(const std::string &folder, const std::string &from, const std::string &to, std::string &why)
{
    const std::string path = folder + "/sce_sys/param.json";
    std::string text;
    Json pj;
    if (!read_file(path, text) || !Json::parse(text, pj) || !pj.is_object()) { why = "no readable param.json"; return false; }
    if (pj["titleId"].str() == to) return true;
    if (pj["titleId"].str() != from) { why = "its param.json has title ID " + pj["titleId"].str(); return false; }
    if (!set_string(text, "titleId", [&](const std::string &) { return to; })) { why = "titleId"; return false; }
    /* The ID inside the content ID (UP9000-PPSA93004_00-...), and a concept ID that's the
     * ID's own number. */
    set_string(text, "contentId", [&](std::string v) {
        const std::size_t p = v.find(from);
        if (p != std::string::npos) v.replace(p, from.size(), to);
        return v;
    });
    set_string(text, "conceptId", [&](const std::string &v) { return v == from.substr(4) ? to.substr(4) : v; });
    Json check;
    if (!Json::parse(text, check) || check["titleId"].str() != to) { why = "the changed param.json didn't read back"; return false; }
    const std::string tmp = path + ".new";
    if (!write_file(tmp, text.data(), text.size()) || std::rename(tmp.c_str(), path.c_str()) != 0)
    {
        std::remove(tmp.c_str());
        why = "couldn't write param.json (errno " + std::to_string(errno) + ")";
        return false;
    }
    slog("title ID guard: %s now says %s (was %s)", path.c_str(), to.c_str(), from.c_str());
    return true;
}

void write_marker(const App &a, const std::string &folder, const std::string &title_id, const std::string &version)
{
    const std::string source = a.beta ? std::string("beta") : a.source == "github" ? "github:" + a.repo : a.url;
    /* (2.0) ownId: the title ID its release has (a moved app's titleId is its new one). */
    const std::string text = "{\"app\":" + json_text(a.id) + ",\"name\":" + json_text(a.name) + ",\"titleId\":" + json_text(title_id) +
                             ",\"ownId\":" + json_text(own_id(a.id)) + ",\"source\":" + json_text(source) +
                             ",\"version\":" + json_text(version) + "}\n";
    if (!write_file(folder + "/" + kMarker, text.data(), text.size())) slog("install: couldn't write %s in %s", kMarker, folder.c_str());
    else chmod((folder + "/" + kMarker).c_str(), 0777);
}

/* (2.0) At start, when moves.json or titles.json was lost: what the markers in the store's own
 * installs say (an app the store installed, under a title ID from the move block, or a custom
 * app's own title ID), so a moved app's updates and Delete never land on the other app. */
void rebuild_moves()
{
    if (!g_moves_lost) return;
    std::map<std::string, std::string> folders;
    {
        std::lock_guard<std::mutex> l(g_fold_m);
        folders = g_folders;
    }
    bool changed = false;
    for (const auto &kv : folders)
    {
        std::string text;
        Json mk;
        if (!read_file(kv.second + "/" + kMarker, text) || !Json::parse(text, mk) || !mk.is_object()) continue;
        const std::string app = mk["app"].str(), tid = mk["titleId"].str();
        if (app.empty() || app.size() > 48 || app.find('/') != std::string::npos || tid != kv.first) continue;
        if (!exists(g.data + "/installed/" + app + ".txt")) continue; /* the store's own install, by its record */
        std::string own = mk["ownId"].str();
        if (!title_id_ok(own)) own = title_id_ok(app) ? app : std::string();
        if (!title_id_ok(own)) continue;
        std::lock_guard<std::mutex> l(g_move_m);
        if (app.rfind("gh-", 0) == 0 && !g_own.count(app)) { g_own[app] = own; changed = true; }
        if (tid != own && tid.rfind("PPSA97", 0) == 0 && !g_moves.count(app)) { g_moves[app] = tid; changed = true; }
    }
    if (changed)
    {
        save_moves();
        slog("title ID guard: moves.json rebuilt from the markers in the apps' folders");
    }
}

/* At start: a moved game whose param.json was put back to its own ID (an update stopped
 * halfway) says its new one again. */
void repair_moves()
{
    std::map<std::string, std::string> m, own;
    {
        std::lock_guard<std::mutex> l(g_move_m);
        m = g_moves;
        own = g_own;
    }
    for (const auto &kv : m)
    {
        const std::string folder = tid_folder(kv.second);
        if (!exists(folder + "/eboot.bin")) continue;
        std::string why;
        const std::string from = own.count(kv.first) ? own[kv.first] : kv.first;
        if (!retitle(folder, from, kv.second, why)) slog("title ID guard: %s: %s", folder.c_str(), why.c_str());
    }
}

/* "01.020.000" -> "1.20.0" */
std::string tidy_version(const std::string &v)
{
    std::string out, part;
    auto flush = [&] {
        std::size_t i = 0;
        while (i + 1 < part.size() && part[i] == '0') ++i;
        out += (out.empty() ? "" : ".") + part.substr(i);
        part.clear();
    };
    for (char c : v)
    {
        if (c == '.') flush();
        else part += c;
    }
    flush();
    return out;
}
} // namespace

std::string installed_id(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g_move_m);
    auto it = g_moves.find(app_id);
    if (it != g_moves.end()) return it->second;
    auto own = g_own.find(app_id);
    return own != g_own.end() ? own->second : app_id;
}

std::string own_id(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g_move_m);
    auto own = g_own.find(app_id);
    return own != g_own.end() ? own->second : app_id;
}

std::string moved_id(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g_move_m);
    auto it = g_moves.find(app_id);
    return it != g_moves.end() ? it->second : std::string();
}

long long installed_time(const std::string &app_id)
{
    std::string data;
    {
        std::lock_guard<std::mutex> l(g.m);
        data = g.data;
    }
    struct stat st;
    return stat((data + "/installed/" + app_id + ".txt").c_str(), &st) == 0 ? (long long)st.st_mtime : 0;
}

namespace
{
/* The folder of the app with this title ID: where it was found, else /data/homebrew/<id>. */
std::string tid_folder(const std::string &tid)
{
    std::lock_guard<std::mutex> l(g_fold_m);
    auto it = g_folders.find(tid);
    return it != g_folders.end() ? it->second : g_install_root + "/" + tid; /* (2.0) a new one: where the player chose */
}

std::string tid_folder_in(const std::string &tid, const std::string &root)
{
    std::lock_guard<std::mutex> l(g_fold_m);
    auto it = g_folders.find(tid);
    return it != g_folders.end() ? it->second : (root.empty() ? g_install_root : root) + "/" + tid;
}
} // namespace

std::string app_folder(const std::string &app_id)
{
    return tid_folder(installed_id(app_id)); /* (2.0) a game moved to a new ID lives under it */
}

std::vector<LogFile> game_log_files(const App &a)
{
    std::vector<LogFile> out;
    auto add = [&](const std::string &path, const std::string &name) {
        struct stat st;
        if (stat(path.c_str(), &st) != 0 || !S_ISREG(st.st_mode) || st.st_size <= 0) return;
        LogFile f;
        f.path = path;
        f.name = name;
        f.size = std::uint64_t(st.st_size);
        f.mtime = (long long)st.st_mtime;
        out.push_back(f);
    };
    add(log_path(), "store.log");
    /* The game's folder, a folder at a time (the shallow ones first), never through a link. */
    auto lower = [](std::string v) {
        for (char &c : v) c = char(std::tolower(static_cast<unsigned char>(c)));
        return v;
    };
    std::vector<std::string> pats;
    for (const std::string &p : a.logs) pats.push_back(lower(p));
    std::vector<LogFile> found;
    struct Dir
    {
        std::string rel;
        int depth;
    };
    const std::string root = app_folder(a.id);
    std::vector<Dir> todo{{"", 0}};
    std::size_t seen = 0;
    for (std::size_t i = 0; i < todo.size() && seen < 6000; ++i)
    {
        const Dir d = todo[i];
        DIR *dir = opendir((d.rel.empty() ? root : root + "/" + d.rel).c_str());
        if (!dir) continue;
        while (dirent *e = readdir(dir))
        {
            if (++seen > 6000) break;
            const std::string leaf = e->d_name;
            if (leaf == "." || leaf == ".." || e->d_type == DT_LNK) continue;
            const std::string rel = d.rel.empty() ? leaf : d.rel + "/" + leaf;
            const std::string path = root + "/" + rel;
            bool is_dir = e->d_type == DT_DIR;
            if (e->d_type == DT_UNKNOWN)
            {
                struct stat st;
                is_dir = stat(path.c_str(), &st) == 0 && S_ISDIR(st.st_mode);
            }
            if (is_dir)
            {
                if (d.depth < 4 && leaf != "sce_sys" && leaf != "sce_module") todo.push_back({rel, d.depth + 1});
                continue;
            }
            const std::string lr = lower(rel), ll = lower(leaf);
            bool want = false;
            if (!pats.empty())
                for (const std::string &p : pats) want = want || asset_matches(p, lr);
            else
            {
                const bool txt = ll.size() > 4 && ll.compare(ll.size() - 4, 4, ".txt") == 0;
                const bool log = ll.size() > 4 && ll.compare(ll.size() - 4, 4, ".log") == 0;
                want = log || (txt && (ll.find("log") != std::string::npos || ll.find("crash") != std::string::npos ||
                                       ll.find("trace") != std::string::npos));
            }
            if (!want) continue;
            const std::size_t before = out.size();
            add(path, rel);
            if (out.size() > before) { found.push_back(out.back()); out.pop_back(); }
        }
        closedir(dir);
    }
    std::sort(found.begin(), found.end(), [](const LogFile &x, const LogFile &y) { return x.mtime > y.mtime; });
    if (found.size() > 8) found.resize(8);
    out.insert(out.end(), found.begin(), found.end());
    return out;
}

std::vector<std::string> log_tail(std::size_t n)
{
    LogState &L = logs();
    std::lock_guard<std::mutex> lk(L.m);
    const std::size_t from = L.lines.size() > n ? L.lines.size() - n : 0;
    return std::vector<std::string>(L.lines.begin() + std::ptrdiff_t(from), L.lines.end());
}

std::vector<std::pair<std::string, std::string>> stats()
{
    LogState &L = logs();
    std::lock_guard<std::mutex> lk(L.m);
    return std::vector<std::pair<std::string, std::string>>(L.stats.begin(), L.stats.end());
}

std::string log_path()
{
    LogState &L = logs();
    std::lock_guard<std::mutex> lk(L.m);
    return L.file;
}

bool Engine::adopt(const std::string &app_id, std::string &version_out)
{
    const std::string dir = app_folder(app_id);
    if (!exists(dir + "/eboot.bin")) return false;
    {
        /* (2.0) Never a custom source's app over a folder that isn't its own install. */
        std::string repo;
        bool custom = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            auto it = g.custom_repos.find(app_id);
            custom = it != g.custom_repos.end();
            if (custom) repo = it->second;
        }
        if (custom && !custom_owns(dir, repo)) return false;
    }
    std::string text, v;
    Json j;
    if (read_file(dir + "/recompstore.json", text) && Json::parse(text, j)) v = j["version"].str();
    if (v.empty() && read_file(dir + "/sce_sys/param.json", text) && Json::parse(text, j))
        v = tidy_version(j["contentVersion"].str());
    if (v.empty()) v = "0";
    std::string data;
    {
        std::lock_guard<std::mutex> l(g.m);
        data = g.data;
    }
    make_dirs(data + "/installed");
    if (!write_file(data + "/installed/" + app_id + ".txt", v.data(), v.size())) return false;
    slog("adopted %s in %s, version %s", app_id.c_str(), dir.c_str(), v.c_str());
    version_out = v;
    return true;
}

void Engine::start(const std::string &data_dir)
{
    {
        std::lock_guard<std::mutex> l(g.m);
        if (g.started) return;
        g.started = true;
        g.data = data_dir;
    }
    make_dirs(data_dir + "/art");
    make_dirs(data_dir + "/installed");
    make_dirs(data_dir + "/logs");
    {
        const std::string f = data_dir + "/logs/store.log";
        struct stat st;
        if (stat(f.c_str(), &st) == 0 && st.st_size > (1 << 20))
            std::rename(f.c_str(), (data_dir + "/logs/store.old.log").c_str());
        LogState &L = logs();
        std::lock_guard<std::mutex> lk(L.m);
        L.file = f;
    }
    data_works_now(data_dir); /* (2.2.1) so losing it later can be told apart from never having it */
    slog("Recomp Store %s (build %d) started; data in %s; copier payload %s", kVersion, kBuild, data_dir.c_str(),
         exists(std::string(PORPOISE_APP) + "/assets/payloads/recompstore-copier.elf") ? "included" : "MISSING");
    for (const char *where : {"/data", "/mnt/usb0", "/mnt/usb1", "/mnt/ext0", "/mnt/ext1"})
    {
        struct statvfs fs;
        if (statvfs(where, &fs) == 0 && fs.f_blocks)
            slog("space on %s: %.1f GB free of %.1f GB%s", where, double(fs.f_bavail) * fs.f_frsize / 1073741824.0,
                 double(fs.f_blocks) * fs.f_frsize / 1073741824.0,
                 std::string(where) == "/data" ? " (as the store's sandbox sees it; the real figures come from ShadowMountPlus)" : "");
    }
    {
        /* (2.0) Where new apps go (Settings > Customize, Advanced mode): a homebrew folder the
         * store knows, else the console's own. */
        std::string root;
        const auto roots = homebrew_roots();
        if (read_file(data_dir + "/install-root.txt", root) && std::find(roots.begin(), roots.end(), root) != roots.end())
        {
            std::lock_guard<std::mutex> l(g_fold_m);
            g_install_root = root;
        }
    }
    scan_homebrew();
    load_moves();   /* (2.0) the title ID guard's moves */
    rebuild_moves();
    repair_moves();
    {
        /* (2.0) A download kept for a clash question the store was closed on: the question is gone. */
        if (DIR *d = opendir((data_dir + "/downloads").c_str()))
        {
            std::vector<std::string> held;
            while (dirent *e = readdir(d))
            {
                const std::string n = e->d_name;
                if ((n.size() > 5 && n.compare(n.size() - 5, 5, ".held") == 0) || (n.size() > 9 && n.compare(n.size() - 9, 9, ".held.txt") == 0))
                    held.push_back(n);
            }
            closedir(d);
            for (const std::string &n : held) std::remove((data_dir + "/downloads/" + n).c_str());
        }
    }
    {
        /* (2.1) The apps kept at an older version the player picked (installed/<id>.hold). */
        std::map<std::string, std::string> holds;
        if (DIR *d = opendir((data_dir + "/installed").c_str()))
        {
            while (dirent *e = readdir(d))
            {
                const std::string n = e->d_name;
                if (n.size() > 5 && n.compare(n.size() - 5, 5, ".hold") == 0 && holds.size() < 500) holds[n.substr(0, n.size() - 5)] = "";
            }
            closedir(d);
        }
        for (auto it = holds.begin(); it != holds.end();)
            if (!read_file(data_dir + "/installed/" + it->first + ".hold", it->second) || it->second.empty() || it->second.size() > 64)
                it = holds.erase(it);
            else
                ++it;
        if (!holds.empty()) slog("kept at an older version: %zu app(s)", holds.size());
        std::lock_guard<std::mutex> l(g.m);
        g.holds.swap(holds);
    }
    load_queue(); /* (2.2.2) the download queue, as the store was closed on it */
    {
        /* (2.0) A tester's console, as the site last said (before the threads start, so the
         * link thread's answer can't be overwritten by this). */
        std::lock_guard<std::mutex> l(g.m);
        g.tester = exists(data_dir + "/tester");
        g.test_view = !exists(data_dir + "/testview-off");
    }
    {
        std::string a;
        if (read_file(data_dir + "/selfupdate/autoupdate", a))
        {
            std::lock_guard<std::mutex> l(g.m);
            g.auto_update = true;
            slog("store: installed from an installer's own copy (%s); updating to the newest as soon as GitHub answers",
                 field_of(a, "version").empty() ? "?" : field_of(a, "version").c_str());
        }
    }
    {
        std::string v;
        if (read_file(data_dir + "/write_mode.txt", v) && !v.empty())
            for (int i = 0; i < kModeCount; ++i)
                if (v == kModes[i].name) g_write_mode = i;
        set_stat("Copies now use", std::string(kModes[g_write_mode].name) + (v.empty() ? " (run the speed test to pick the fastest)" : ""));
    }
    pthread_t t;
    create_title_thread(&t, worker, nullptr);
    pthread_detach(t);
    pthread_t qt; /* (2.2.3) */
    create_title_thread(&qt, quick_main, nullptr);
    pthread_detach(qt);
    /* (2.0) Link a phone, beside it. */
    pthread_t lt;
    create_title_thread(&lt, link_worker, nullptr);
    pthread_detach(lt);
}

bool Engine::catalog(Catalog &out) const
{
    std::lock_guard<std::mutex> l(g.m);
    if (!g.have_catalog) return false;
    out = g.catalog;
    return true;
}

unsigned Engine::revision() const { std::lock_guard<std::mutex> l(g.m); return g.revision; }
bool Engine::online() const { std::lock_guard<std::mutex> l(g.m); return g.online; }
std::string Engine::catalog_note() const { std::lock_guard<std::mutex> l(g.m); return g.catalog_note; }
bool Engine::loading() const { std::lock_guard<std::mutex> l(g.m); return g.loading; }

/* (2.2.2) A homebrew folder the player may pick for a new app: one the store knows ("" the usual). */
static bool root_ok(const std::string &root)
{
    if (root.empty()) return true;
    const auto roots = homebrew_roots();
    if (std::find(roots.begin(), roots.end(), root) != roots.end()) return true;
    slog("install: %s isn't a homebrew folder the store installs to; refused", root.c_str());
    return false;
}

bool Engine::install(const std::string &app_id, const std::string &version, const std::string &root)
{
    if (!root_ok(root)) return false;
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty()) return false;
    g.pending_install = app_id;
    g.pending_version = version; /* (2.1) */
    g.pending_root = root;       /* (2.2.2) */
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "install";
    g.job.app_id = app_id;
    g.job.step = "Starting";
    g.job.version = version;
    g.job.root = root;
    if (!version.empty()) slog("install %s: version %s asked for", app_id.c_str(), version.c_str());
    if (!root.empty()) slog("install %s: to %s, if it isn't on the console yet", app_id.c_str(), root.c_str());
    return true;
}

std::string Engine::held(const std::string &app_id) const
{
    std::lock_guard<std::mutex> l(g.m);
    auto it = g.holds.find(app_id);
    return it == g.holds.end() ? std::string() : it->second;
}

bool Engine::resolve_clash(const std::string &app_id, int choice, const std::string &version, const std::string &root)
{
    if (choice != 1 && choice != 2 && choice != 3)
    {
        /* Neither: the download kept for it goes. */
        std::string data;
        {
            std::lock_guard<std::mutex> l(g.m);
            data = g.data;
        }
        std::remove((data + "/downloads/" + app_id + ".held").c_str());
        std::remove((data + "/downloads/" + app_id + ".held.txt").c_str());
        slog("title ID guard: %s not installed (the player's choice)", app_id.c_str());
        return true;
    }
    /* As install(), with the answer set in the same breath (the worker takes both at once). */
    if (!root_ok(root)) return false;
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty()) return false;
    g.pending_install = app_id;
    g.pending_resolve = choice;
    g.pending_version = version; /* (2.1) the one the player picked, again */
    g.pending_root = root;       /* (2.2.2) and the place */
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "install";
    g.job.app_id = app_id;
    g.job.step = "Starting";
    g.job.version = version;
    g.job.root = root;
    if (choice == 3) slog("install %s: updating from its new GitHub repo, as the player said", app_id.c_str());
    else slog("title ID guard: %s %s", app_id.c_str(), choice == 1 ? "goes under its own new title ID" : "goes over the other app");
    return true;
}

void Engine::refresh()
{
    /* The worker does it (reading the console's folders off the drawing thread, so the
     * screen never stutters), first thing on its next turn. */
    std::lock_guard<std::mutex> l(g.m);
    g.force_check = true;
    g.rescan = true;
    g.update_check = 1;
    g.link_hello = true; /* (2.0) and whether it's a tester now, and what phones sent */
    g.link_poll_now = true;
    slog("refresh: asked by the player");
}

bool Engine::redeem(const std::string &code)
{
    std::string digits;
    for (const char c : code)
        if (c >= '0' && c <= '9') digits += c;
    std::lock_guard<std::mutex> l(g.m);
    if (digits.size() != 8 || g.redeem == 1) return false;
    g.pending_redeem = digits;
    g.redeem = 1;
    g.redeem_msg.clear();
    g.redeem_app.clear();
    return true;
}

int Engine::redeem_state(std::string &message, std::string &app_id) const
{
    std::lock_guard<std::mutex> l(g.m);
    message = g.redeem_msg;
    app_id = g.redeem_app;
    return g.redeem;
}

void Engine::redeem_ack()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.redeem == 1) return;
    g.redeem = 0;
    g.redeem_msg.clear();
    g.redeem_app.clear();
}

bool Engine::send_logs(const std::string &app_id, const std::string &message)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.logs == 1) return false;
    g.pending_logs = true;
    g.pending_logs_app = app_id;
    g.pending_logs_msg = message.substr(0, 2000);
    g.logs = 1;
    g.logs_msg.clear();
    return true;
}

std::string Engine::logs_report(std::string &url) const
{
    std::lock_guard<std::mutex> l(g.m);
    url = g.logs_report_url;
    return g.logs_report;
}

void Engine::link_screen(bool open)
{
    std::lock_guard<std::mutex> l(g.m);
    if (open && !g.link_open)
    {
        g.link_poll_now = true;
        /* A code to show straight away, unless the one there is still good for a while. */
        if (g.link.code_state != 2 || mono_s() > g.link_code_until - 60) g.link_want_code = true;
    }
    g.link_open = open;
}

void Engine::link_new_code()
{
    std::lock_guard<std::mutex> l(g.m);
    g.link_want_code = true;
    g.link.code_state = 1;
}

LinkInfo Engine::link_info() const
{
    std::lock_guard<std::mutex> l(g.m);
    LinkInfo i = g.link;
    i.expires_in = i.code_state == 2 ? std::max(0, int(g.link_code_until - mono_s())) : 0;
    return i;
}

void Engine::link_unlink(const std::string &device_id)
{
    std::lock_guard<std::mutex> l(g.m);
    g.link_unlinks.push_back(device_id);
    for (std::size_t i = 0; i < g.link.devices.size(); ++i)
        if (g.link.devices[i].id == device_id)
        {
            g.link.devices.erase(g.link.devices.begin() + std::ptrdiff_t(i));
            break;
        }
    ++g.revision;
}

bool Engine::take_link_action(LinkAction &out)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.link_actions.empty()) return false;
    out = g.link_actions.front();
    g.link_actions.erase(g.link_actions.begin());
    return true;
}

std::string Engine::install_id() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.beta_install;
}

bool source_from_text(const std::string &text, std::string &kind, std::string &value, std::string &why)
{
    std::string t;
    for (const char c : text)
        if (!std::isspace(static_cast<unsigned char>(c))) t += c;
    auto lower = [](std::string v) {
        for (char &c : v) c = char(std::tolower(static_cast<unsigned char>(c)));
        return v;
    };
    std::string rest = t;
    const std::string low = lower(t);
    const bool https = low.rfind("https://", 0) == 0;
    for (const char *p : {"https://", "http://"})
        if (lower(rest).rfind(p, 0) == 0) rest.erase(0, std::strlen(p));
    if (lower(rest).rfind("www.", 0) == 0) rest.erase(0, 4);
    if (lower(rest).rfind("github.com/", 0) == 0)
    {
        /* github.com/owner/name, and anything after it (/releases, .git) */
        rest.erase(0, 11);
        const std::size_t a = rest.find('/');
        std::string owner = rest.substr(0, a), name = a == std::string::npos ? std::string() : rest.substr(a + 1);
        name = name.substr(0, name.find_first_of("/?#"));
        if (name.size() > 4 && lower(name).compare(name.size() - 4, 4, ".git") == 0) name.erase(name.size() - 4);
        if (repo_text_ok(owner + "/" + name))
        {
            kind = "repo";
            value = owner + "/" + name;
            return true;
        }
        why = "That GitHub address doesn't name a repo. It looks like github.com/owner/name.";
        return false;
    }
    if (t.find("://") == std::string::npos && repo_text_ok(t))
    {
        kind = "repo";
        value = t;
        return true;
    }
    if (https && t.size() > 12 && t.size() <= 500 && t.find('.', 8) != std::string::npos)
    {
        kind = "list";
        value = t;
        return true;
    }
    why = t.empty() ? "Type a GitHub repo (owner/name) or a list's https:// address."
          : t.find("://") != std::string::npos && !https ? "A list has to be at an https:// address."
                                                         : "That isn't a GitHub repo (owner/name) or a list's https:// address.";
    return false;
}

std::vector<Source> Engine::sources() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.sources;
}

bool Engine::add_source(const std::string &text, std::string &why)
{
    std::string kind, value;
    if (!source_from_text(text, kind, value, why)) return false;
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const Source &s : g.sources)
        {
            std::string a = s.value, b = value;
            for (char &c : a) c = char(std::tolower(static_cast<unsigned char>(c)));
            for (char &c : b) c = char(std::tolower(static_cast<unsigned char>(c)));
            if (a == b)
            {
                why = "That source is in your list already.";
                return false;
            }
        }
        if (g.sources.size() >= 30)
        {
            why = "That's 30 sources, as many as the store keeps. Remove one first.";
            return false;
        }
        Source s;
        s.kind = kind;
        s.value = value;
        g.sources.push_back(s);
        g.sources_due.insert(value);
        ++g.revision;
    }
    slog("sources: added %s %s", kind.c_str(), value.c_str());
    save_sources();
    return true;
}

void Engine::remove_source(const std::string &value)
{
    {
        std::lock_guard<std::mutex> l(g.m);
        const std::size_t before = g.sources.size();
        g.sources.erase(std::remove_if(g.sources.begin(), g.sources.end(), [&](const Source &s) { return s.value == value; }),
                        g.sources.end());
        if (g.sources.size() == before) return;
        g.source_apps.erase(value);
        g.sources_due.erase(value);
        merge_beta(g.catalog);
        ++g.revision;
    }
    std::remove(source_file(value).c_str());
    slog("sources: removed %s", value.c_str());
    save_sources();
}

bool Engine::report_source(const std::string &value, const std::string &why)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.pending_src_report || g.src_report == 1) return false;
    g.pending_src_report = true;
    g.src_report_value = value;
    g.src_report_why = why;
    g.src_report = 1;
    g.src_report_msg.clear();
    ++g.revision;
    return true;
}

int Engine::source_report_state(std::string &message) const
{
    std::lock_guard<std::mutex> l(g.m);
    message = g.src_report_msg;
    return g.src_report;
}

void Engine::source_report_ack()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.src_report != 1) g.src_report = 0;
}

Charts Engine::charts() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.charts;
}

std::vector<Place> Engine::install_places() const
{
    /* (2.2.2) Where ShadowMountPlus says apps can go, when it said (its /api/v1/storage); else every
     * homebrew folder on a drive that's connected. With each one's free space. */
    std::vector<DriveSpace> known;
    std::vector<std::string> dests;
    bool smp = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        known = g.drives;
        dests = g.smp_dests;
        smp = g.smp_dests_known;
    }
    std::vector<Place> out;
    for (const std::string &root : homebrew_roots())
    {
        const std::string drive = drive_of(root);
        const bool listed = std::find(dests.begin(), dests.end(), root) != dests.end();
        if (root != kHomebrew && (smp ? !listed : false)) continue;
        if (root != kHomebrew && !drive_ready(drive)) continue;
        Place p{place_name(root), root};
        bool from = false;
        for (const DriveSpace &d : known)
            if (d.homebrew == root && d.total) { p.free = d.free; p.total = d.total; from = true; }
        if (!from)
        {
            /* (2.2.4) The real figures, as already known (this is asked by the screens). */
            bool smp = false;
            std::uint64_t total = 0, avail = 0;
#ifdef RECOMP_ENGINE_TEST
            if (std::getenv("E222_FREE") && free_bytes(drive, avail)) total = avail * 4; else
#endif
            if (!real_space(drive, total, avail, smp, false)) total = avail = 0;
            p.free = avail;
            p.total = total;
        }
        out.push_back(p);
    }
    return out;
}

std::string Engine::install_root() const
{
    std::lock_guard<std::mutex> l(g_fold_m);
    return g_install_root;
}

void Engine::set_install_root(const std::string &folder)
{
    const auto roots = homebrew_roots();
    if (std::find(roots.begin(), roots.end(), folder) == roots.end()) return;
    std::string data;
    {
        std::lock_guard<std::mutex> l(g_fold_m);
        if (g_install_root == folder) return;
        g_install_root = folder;
    }
    {
        std::lock_guard<std::mutex> l(g.m);
        data = g.data;
        apply_moves(g.catalog); /* not-yet-installed games' data folders, where they'll go */
        ++g.revision;
    }
    if (folder == kHomebrew) std::remove((data + "/install-root.txt").c_str());
    else write_file(data + "/install-root.txt", folder.data(), folder.size());
    slog("install: new apps go to %s", folder.c_str());
}

bool Engine::tester() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.tester;
}

bool Engine::test_view() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.test_view;
}

void Engine::set_test_view(bool on)
{
    std::string data;
    {
        std::lock_guard<std::mutex> l(g.m);
        if (g.test_view == on) return;
        g.test_view = on;
        g.force_check = true; /* the other catalog, now */
        data = g.data;
    }
    if (on) std::remove((data + "/testview-off").c_str());
    else write_file(data + "/testview-off", "1\n", 2);
    use_cached_catalog(on);
    slog("testing: %s", on ? "showing the test catalog" : "showing what everyone sees");
}

int Engine::logs_state(std::string &message) const
{
    std::lock_guard<std::mutex> l(g.m);
    message = g.logs_msg;
    return g.logs;
}

void Engine::logs_ack()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.logs == 1) return;
    g.logs = 0;
    g.logs_msg.clear();
}

bool Engine::bench()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench) return false;
    g.pending_bench = true;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "bench";
    g.job.step = "Starting the speed test";
    return true;
}

bool Engine::remove(const std::string &app_id, bool keep_saves)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty() || !g.pending_manage.empty())
        return false;
    g.pending_delete = app_id;
    g.pending_delete_keep = keep_saves;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "delete";
    g.job.app_id = app_id;
    g.job.step = "Deleting";
    return true;
}

void Engine::want_notes(const std::string &repo)
{
    if (repo.find('/') == std::string::npos || repo.find("..") != std::string::npos) return;
    std::lock_guard<std::mutex> l(g.m);
    if (!g.notes.count(repo))
    {
        /* From the last time, straight away; GitHub's newer copy follows. */
        std::string text;
        std::vector<Release> rel;
        if (read_file(notes_file(repo), text) && parse_releases(text, rel))
            g.notes[repo] = rel;
    }
    auto at = g.notes_at.find(repo);
    if (at == g.notes_at.end() || mono_s() - at->second > 1800)
    {
        g.notes_at[repo] = mono_s(); /* asked: not again for half an hour */
        g.notes_wanted.insert(repo);
    }
}

int Engine::notes(const std::string &repo, std::vector<Release> &out) const
{
    if (repo.find('/') == std::string::npos || repo.find("..") != std::string::npos) return -1;
    std::lock_guard<std::mutex> l(g.m);
    auto it = g.notes.find(repo);
    if (it != g.notes.end() && !it->second.empty())
    {
        out = it->second;
        return 1;
    }
    if (g.notes_failed.count(repo) || (it != g.notes.end() && it->second.empty()))
        return -1;
    return 0;
}

/* The payloads read and write /data/recompstore: a store that couldn't reach /data
 * (running sandboxed, its folder elsewhere) can't hand them anything. */
static bool payloads_can_reach(std::string &why)
{
    if (g.data == "/data/recompstore" || std::getenv("RECOMP_TEST_COPIER") != nullptr)
        return true;
    slog("the store's folder is %s, not /data/recompstore: the payloads can't see its requests", g.data.c_str());
    why = "Recomp Store doesn't have full access right now. Close it, check that your jailbreak (kstuff) is running, and open it again.";
    return false;
}

/* Four capital letters and five digits, as every PS5 title ID is. */
static bool title_id_ok(const std::string &id)
{
    if (id.size() != 9) return false;
    for (std::size_t i = 0; i < 9; ++i)
        if (i < 4 ? (id[i] < 'A' || id[i] > 'Z') : (id[i] < '0' || id[i] > '9')) return false;
    return true;
}

std::string smp_path()
{
    std::string v;
    read_file(smp_file(), v);
    while (!v.empty() && (v.back() == '\n' || v.back() == '\r')) v.pop_back();
    return v;
}

void set_smp_path(const std::string &path)
{
    write_file(smp_file(), path.data(), path.size());
    slog("ShadowMountPlus payload set to %s", path.c_str());
}

/* (2.2.1) The player's ShadowMountPlus file, checked (an ELF the store can read, on a path the
 * launcher can be given) but not sent: only the launcher sends it, once the store has closed.
 * A second ShadowMountPlus stops the running one, and the running one's exit removes /data and the
 * USB drives from every app that's open (its sandbox aliases: sm_fakelib_game_shutdown in its
 * source); the new one gives them only to apps started after it, so the store would lose its
 * own folder until it's opened again. ShadowMountPlus answering on 10101 doesn't prove it isn't
 * running either: it opens its API last, after mounting everything, and the API can be off. */
static bool smp_file_ok(std::string &path, std::string &why)
{
    path = smp_path();
    if (path.empty())
    {
        why = "Choose your ShadowMountPlus file in Settings first.";
        return false;
    }
    char head[4] = {0};
    struct stat st;
    std::FILE *f = path[0] == '/' && path.size() < 400 && path.find('\n') == std::string::npos ? std::fopen(path.c_str(), "rb") : nullptr;
    const bool got = f && std::fread(head, 1, sizeof head, f) == sizeof head;
    if (f) std::fclose(f);
    if (!got || std::memcmp(head, "\x7F" "ELF", 4) != 0 || stat(path.c_str(), &st) != 0 || st.st_size < 64)
    {
        why = "That ShadowMountPlus file couldn't be read.";
        slog("ShadowMountPlus: can't read %s", path.c_str());
        return false;
    }
    return true;
}

/* ShadowMountPlus 1.7's own API: HTTP on 127.0.0.1:10101, one JSON object POSTed per
 * call (docs/api.md in its source). true when it answered with status 0; *answered
 * says whether anything answered at all. */
/* (2.3.0) Every look for new apps asks ShadowMountPlus to forget its tries too: after two failed
 * tries to register a title that isn't in the system's app list it stops trying, until it restarts
 * or is asked with reset_attempts. */
constexpr const char *kScanBody = "{\"reset_attempts\":true}";
static bool smp_api(const char *route, const std::string &body, std::string &reply, bool *answered = nullptr)
{
    if (answered) *answered = false;
    reply.clear();
    const int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) return false;
    timeval limit{};
    limit.tv_sec = 2;
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &limit, sizeof limit);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &limit, sizeof limit);
#ifdef SO_NOSIGPIPE
    {
        const int one = 1;
        setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &one, sizeof one);
    }
#endif
    sockaddr_in sa{};
    sa.sin_family = AF_INET;
    unsigned short port = 10101;
#ifdef PORPOISE_DESKTOP
    /* Desktop tests: a stand-in on another port, so they never touch a real one on 10101. */
    if (const char *tp = std::getenv("RECOMP_TEST_SMP_PORT"); tp && std::strtol(tp, nullptr, 10) > 0 && std::strtol(tp, nullptr, 10) < 65536)
        port = static_cast<unsigned short>(std::strtol(tp, nullptr, 10));
#endif
    sa.sin_port = htons(port);
    sa.sin_addr.s_addr = htonl(0x7F000001);
    if (connect(fd, reinterpret_cast<sockaddr *>(&sa), sizeof sa) != 0)
    {
        close(fd);
        return false;
    }
    char head[256];
    std::snprintf(head, sizeof head,
                  "POST %s HTTP/1.1\r\nHost: 127.0.0.1:10101\r\nContent-Type: application/json\r\n"
                  "Content-Length: %zu\r\nConnection: close\r\n\r\n",
                  route, body.size());
    const std::string req = head + body;
    std::size_t at = 0;
    while (at < req.size())
    {
        const ssize_t n = send(fd, req.data() + at, req.size() - at, kNoSignal);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) { close(fd); return false; }
        at += std::size_t(n);
    }
    std::string got;
    char buf[4096];
    for (;;)
    {
        const ssize_t n = recv(fd, buf, sizeof buf, 0);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) break;
        got.append(buf, std::size_t(n));
        if (got.size() > 65536) break;
    }
    close(fd);
    if (got.compare(0, 5, "HTTP/") != 0) return false;
    if (answered) *answered = true;
    /* The JSON object in the body (its first '{' to its last '}', whatever the framing). */
    const std::size_t body_at = got.find("\r\n\r\n");
    const std::size_t open_at = body_at == std::string::npos ? std::string::npos : got.find('{', body_at);
    const std::size_t close_at = got.rfind('}');
    if (open_at == std::string::npos || close_at == std::string::npos || close_at < open_at) return false;
    reply = got.substr(open_at, close_at - open_at + 1);
    Json j;
    return Json::parse(reply, j) && j.is_object() && j["status"].type() == Json::Type::Number && j["status"].integer(-1) == 0;
}

int refresh_smp(std::string &why)
{
    std::string reply;
    bool answered = false;
    if (smp_api("/api/v1/scan", kScanBody, reply, &answered))
    {
        slog("ShadowMountPlus is running; asked it to look for new games: %s", reply.c_str());
        return 2;
    }
    if (answered)
    {
        /* Running, but not taking requests (busy). */
        slog("ShadowMountPlus answered but didn't take the rescan: %s", reply.c_str());
        why = "ShadowMountPlus is busy; it finds the game on its next look (every 15 seconds or so).";
        return 0;
    }
    std::string path;
    if (!smp_file_ok(path, why))
    {
        if (smp_path().empty()) why = "ShadowMountPlus isn't running, and no file is chosen to start it.";
        return 0;
    }
    /* (2.2.1) Not sent from here (see smp_file_ok): the launcher starts it once the store has
     * closed, for Open and Refresh home screen. */
    slog("ShadowMountPlus didn't answer on port 10101; the launcher starts it once the store closes");
    why = "ShadowMountPlus isn't running. The store starts it as it closes: press Open, or Refresh home screen.";
    return 1;
}

/* ---- (2.2.4) Free space: the real figures ------------------------------------------------
 * Inside the store's sandbox statvfs("/data") answers a fixed 64 GB with 16 GB free, whatever
 * the console's storage holds (every log since 2.0 says so), so the store can't trust its own
 * call there. ShadowMountPlus runs outside the sandbox: its /api/v1/storage gives the real
 * filesystems, the internal /user one /data lives on and each drive. Asked at most every 15
 * seconds; a drive's own statvfs only when ShadowMountPlus doesn't say, and never /data's. */
namespace
{
struct FsFig
{
    std::string where; /* a mount point, or "dest:" and a destination's path */
    std::uint64_t total = 0, avail = 0;
};
std::mutex g_space_m;
std::vector<FsFig> g_space;
double g_space_at = -1e9;
} // namespace

static void space_refresh(bool force = false)
{
    {
        std::lock_guard<std::mutex> l(g_space_m);
        if (!force && mono_s() - g_space_at < 15) return;
        g_space_at = mono_s(); /* claimed before asking, so callers don't pile up */
    }
    std::string reply;
    bool answered = false;
    Json j;
    std::vector<FsFig> got;
    if (smp_api("/api/v1/storage", "{}", reply, &answered) && Json::parse(reply, j) && j.is_object())
    {
        auto add = [&](const std::string &where, const Json &d) {
            FsFig f;
            f.where = where;
            while (f.where.size() > 1 && f.where.back() == '/') f.where.pop_back();
            const long long t = d["total_bytes"].integer(0);
            const long long a = d["available_bytes"].integer(d["free_bytes"].integer(-1));
            if (t <= 0 || a < 0 || a > t || got.size() >= 48) return;
            f.total = std::uint64_t(t);
            f.avail = std::uint64_t(a);
            got.push_back(f);
        };
        for (std::size_t i = 0; i < j["mounts"].size() && i < 32; ++i) add(j["mounts"][i]["mount_point"].str(), j["mounts"][i]);
        for (std::size_t i = 0; i < j["destinations"].size() && i < 32; ++i) add("dest:" + j["destinations"][i]["path"].str(), j["destinations"][i]);
    }
    std::lock_guard<std::mutex> l(g_space_m);
    if (got.empty() && answered) slog("ShadowMountPlus answered without its storage figures");
    g_space = got;
}

namespace
{
bool real_space(const std::string &path, std::uint64_t &total, std::uint64_t &avail, bool &from_smp, bool may_ask)
{
    from_smp = false;
    total = avail = 0;
    if (may_ask) space_refresh();
    std::vector<FsFig> figs;
    {
        std::lock_guard<std::mutex> l(g_space_m);
        figs = g_space;
    }
    auto pick = [&](const std::string &w) {
        for (const FsFig &f : figs)
            if (f.where == w)
            {
                total = f.total;
                avail = f.avail;
                from_smp = true;
                return true;
            }
        return false;
    };
    if (path == "/data" || path.rfind("/data/", 0) == 0)
        /* /data is on the console's /user filesystem (outside the sandbox). */
        return pick("/user") || pick("dest:/data/homebrew") || pick("/user/data") || pick("/data");
    const std::string drive = drive_of(path);
    if (drive.rfind("/mnt/", 0) == 0 && (pick(drive) || pick("dest:" + drive) || pick("dest:" + drive + "/homebrew"))) return true;
    struct statvfs fs;
    if (statvfs(path.c_str(), &fs) != 0 || !fs.f_blocks) return false;
    total = std::uint64_t(fs.f_blocks) * fs.f_frsize;
    avail = std::uint64_t(fs.f_bavail) * fs.f_frsize;
    return avail <= total;
}
} // namespace

void smp_scan_later()
{
    timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    std::lock_guard<std::mutex> l(g.m);
    g.smp_scan_at.push_back(ts.tv_sec + 12);
    g.smp_scan_at.push_back(ts.tv_sec + 30);
}

/* (2.0) The worker's follow-up: only asks one that's running (never starts a second). */
void smp_scan_again()
{
    std::string reply;
    bool answered = false;
    const bool ok = smp_api("/api/v1/scan", kScanBody, reply, &answered);
    slog("ShadowMountPlus, asked again to look for new games: %s",
         ok ? reply.c_str() : answered ? "it didn't take it" : "it isn't running");
}

bool unmount_smp(const std::string &title_id)
{
    if (!title_id_ok(title_id)) return false;
    std::string reply;
    bool answered = false;
    const bool ok = smp_api("/api/v1/games/unmount", "{\"title_id\":\"" + title_id + "\"}", reply, &answered);
    if (answered) slog("ShadowMountPlus unmount %s: %s", title_id.c_str(), reply.c_str());
    return ok;
}

bool forget_smp(const std::string &title_id)
{
    if (!title_id_ok(title_id)) return false;
    std::string reply;
    bool answered = false;
    const bool ok = smp_api("/api/v1/games/uninstall", "{\"title_id\":\"" + title_id + "\"}", reply, &answered);
    if (ok)
        slog("ShadowMountPlus took %s off the home screen: %s", title_id.c_str(), reply.c_str());
    else if (answered)
        slog("ShadowMountPlus didn't take %s off the home screen: %s", title_id.c_str(), reply.c_str());
    else
        slog("ShadowMountPlus isn't running, so %s's tile stays until it's removed by hand", title_id.c_str());
    return ok;
}

/* ---- (2.2) Library > On this PS5: every app in the homebrew folders, and the App manager --------
 * The list is looked for on its own thread (folders first, then each one's size), so the screens
 * never wait for it. Delete, Move and New title ID are jobs on the worker, as Delete on a game's
 * page is. Each acts only on a folder directly inside a homebrew folder the store knows, never on
 * Recomp Store's own, and checks afterwards that it did what it says. */
namespace
{
std::mutex g_home_m; /* home-todo.txt */

/* A homebrew folder by name, as Customize's "Install new apps to" says it. */
std::string place_name(const std::string &root)
{
    if (root == kHomebrew) return "Console storage";
    if (root == "/mnt/ext1/homebrew") return "M.2 SSD";
    if (root == "/mnt/ext0/homebrew") return "Extended storage";
    if (root.rfind("/mnt/usb", 0) == 0 && root.size() > 8 && root[8] >= '0' && root[8] <= '7')
        return std::string("USB drive ") + char('1' + (root[8] - '0'));
    return root;
}

/* A folder the App manager may change: directly in a homebrew folder the store knows, named
 * plainly, never Recomp Store's own (by its name or its param.json), and one fs::deletable allows. */
bool manageable(const std::string &folder, std::string &root, std::string &leaf, std::string &why)
{
    const std::size_t slash = folder.find_last_of('/');
    root = slash == std::string::npos ? std::string() : folder.substr(0, slash);
    leaf = slash == std::string::npos ? std::string() : folder.substr(slash + 1);
    const auto roots = homebrew_roots();
    bool plain = !leaf.empty() && leaf[0] != '.' && leaf != ".." && std::find(roots.begin(), roots.end(), root) != roots.end();
    for (const char c : leaf)
        plain = plain && static_cast<unsigned char>(c) >= 0x20 && c != 0x7F && c != '\\';
    if (!plain)
    {
        why = "That isn't a folder in a homebrew folder.";
        return false;
    }
    std::string text;
    Json pj;
    const bool has_param = read_file(folder + "/sce_sys/param.json", text) && Json::parse(text, pj) && pj.is_object();
    if (leaf == "PPSA92000" || (has_param && pj["titleId"].str() == "PPSA92000"))
    {
        why = "That's Recomp Store itself.";
        return false;
    }
    if (!fs::deletable(folder, g.data))
    {
        why = "The store won't change that folder.";
        return false;
    }
    return true;
}

/* The store's own install of this folder: its marker and install record say so, or a catalog
 * app the store installed lives here. "" when it was put there some other way. */
std::string store_app_of(const std::string &folder, const std::vector<std::pair<std::string, std::string>> &installed)
{
    std::string text;
    Json mk;
    if (read_file(folder + "/" + kMarker, text) && Json::parse(text, mk) && mk.is_object())
    {
        const std::string app = mk["app"].str();
        if (!app.empty() && app.size() <= 64 && app.find('/') == std::string::npos && exists(g.data + "/installed/" + app + ".txt"))
            return app;
    }
    for (const auto &kv : installed)
        if (kv.second == folder) return kv.first;
    return "";
}

/* Every folder in the homebrew folders, by name. */
std::vector<ConsoleApp> list_console()
{
    /* The catalog's installed apps and where each is (app_folder: the folder scan_homebrew found). */
    std::vector<std::pair<std::string, std::string>> installed;
    {
        std::vector<std::string> ids;
        {
            std::lock_guard<std::mutex> l(g.m);
            for (const App &a : g.catalog.apps)
                if (exists(g.data + "/installed/" + a.id + ".txt")) ids.push_back(a.id);
        }
        for (const std::string &id : ids) installed.push_back({id, app_folder(id)});
    }
    std::vector<ConsoleApp> out;
    for (const std::string &root : homebrew_roots())
    {
        DIR *d = opendir(root.c_str());
        if (!d) continue;
        std::vector<std::string> names;
        while (dirent *e = readdir(d))
            if (e->d_name[0] != '.' && names.size() < 1000) names.push_back(e->d_name);
        closedir(d);
        for (const std::string &n : names)
        {
            const std::string dir = root + "/" + n;
            struct stat st;
            if (stat(dir.c_str(), &st) != 0 || !S_ISDIR(st.st_mode)) continue;
            ConsoleApp c;
            c.folder = dir;
            c.root = root;
            c.mtime = (long long)st.st_mtime;
            c.place = place_name(root);
            std::string text;
            Json pj;
            if (read_file(dir + "/sce_sys/param.json", text) && Json::parse(text, pj) && pj.is_object())
            {
                c.title_id = pj["titleId"].str();
                c.name = param_name(pj);
                std::string v = pj["contentVersion"].str();
                if (v.empty()) v = pj["appVer"].str();
                c.version = v.empty() ? std::string() : tidy_version(v);
                c.app = exists(dir + "/eboot.bin");
            }
            if (!title_id_ok(c.title_id)) c.title_id = title_id_ok(n) && c.app ? n : c.title_id;
            if (c.name.empty()) c.name = n;
            if (exists(dir + "/sce_sys/icon0.png")) c.icon = dir + "/sce_sys/icon0.png";
            c.store = n == "PPSA92000" || c.title_id == "PPSA92000";
            if (!c.store) c.store_id = store_app_of(dir, installed);
            out.push_back(c);
        }
    }
    std::stable_sort(out.begin(), out.end(), [](const ConsoleApp &a, const ConsoleApp &b) {
        const std::string x = lower_text(a.name), y = lower_text(b.name);
        return x != y ? x < y : a.folder < b.folder;
    });
    return out;
}

/* ShadowMountPlus's API, asked what it is (read only: it answers while an app runs). */
int ask_smp_version(std::string &version);
void look_drives(bool smp_running); /* (2.2.2) */

void *console_main(void *)
{
    for (;;)
    {
        bool look = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            look = g.console_again;
            g.console_again = false;
            g.console_looking = look;
        }
        if (!look)
        {
            usleep(150000);
            continue;
        }
        std::vector<ConsoleApp> list = list_console();
        if (data_lost(-1))
        {
            /* (2.2.1) The store lost the console's storage: what it listed before stays, and the
             * Library says why instead of "0 folders". */
            std::lock_guard<std::mutex> l(g.m);
            g.console_looking = false;
            ++g.console_rev;
            continue;
        }
        {
            /* Sizes already known stay while they're worked out again. */
            std::lock_guard<std::mutex> l(g.m);
            for (ConsoleApp &c : list)
                for (const ConsoleApp &o : g.console)
                    if (o.folder == c.folder) c.size = o.size;
            g.console = list;
            ++g.console_rev;
        }
        std::string ver;
        const int smp = ask_smp_version(ver);
        {
            std::lock_guard<std::mutex> l(g.m);
            g.smp_state = smp;
            g.smp_version = ver;
            ++g.console_rev;
        }
        look_drives(smp == 1); /* (2.2.2) */
        for (const ConsoleApp &c : list)
        {
            {
                std::lock_guard<std::mutex> l(g.m);
                if (g.console_again) break; /* asked again: start over */
            }
            std::uint64_t bytes = 0;
            std::size_t files = 0;
            fs::tree_size(c.folder, bytes, files);
            std::lock_guard<std::mutex> l(g.m);
            for (ConsoleApp &o : g.console)
                if (o.folder == c.folder) o.size = (long long)bytes;
            ++g.console_rev;
        }
    }
    return nullptr;
}

/* ---- Home screen changes for later ------------------------------------------------------------
 * ShadowMountPlus takes no changes while an app runs, and the store is one: a tile to take off
 * ("forget" an app's ID) or to point somewhere new ("unmount" it, so the next look finds it where
 * it is now) waits in home-todo.txt for the launcher, which makes it once the store has closed. */
std::vector<std::string> home_todo()
{
    std::lock_guard<std::mutex> l(g_home_m);
    std::string text;
    std::vector<std::string> out;
    if (!read_file(g.data + "/home-todo.txt", text)) return out;
    std::size_t at = 0;
    while (at < text.size() && out.size() < 16)
    {
        std::size_t end = text.find('\n', at);
        if (end == std::string::npos) end = text.size();
        const std::string line = text.substr(at, end - at);
        const std::size_t sp = line.find(' ');
        if (sp != std::string::npos && (line.compare(0, sp, "forget") == 0 || line.compare(0, sp, "unmount") == 0) &&
            title_id_ok(line.substr(sp + 1)))
            out.push_back(line);
        at = end + 1;
    }
    return out;
}

void save_home_todo(const std::vector<std::string> &todo)
{
    std::string text;
    for (const std::string &t : todo) text += t + "\n";
    const std::string path = g.data + "/home-todo.txt";
    if (text.empty()) std::remove(path.c_str());
    else if (!write_file(path, text.data(), text.size())) slog("home screen: couldn't save home-todo.txt");
}

void queue_home(const std::string &what, const std::string &tid)
{
    if (!title_id_ok(tid) || tid == "PPSA92000") return;
    std::vector<std::string> todo = home_todo();
    const std::string line = what + " " + tid;
    std::lock_guard<std::mutex> l(g_home_m);
    if (std::find(todo.begin(), todo.end(), line) != todo.end()) return;
    if (todo.size() >= 16) todo.erase(todo.begin());
    todo.push_back(line);
    save_home_todo(todo);
    slog("home screen: %s %s waits until the store closes (ShadowMountPlus takes no changes while it's open)", what.c_str(), tid.c_str());
}

/* Handed to the launcher, which has started: they're its to make now. */
void home_handed(const std::vector<std::string> &given)
{
    if (given.empty()) return;
    std::vector<std::string> todo = home_todo();
    std::lock_guard<std::mutex> l(g_home_m);
    std::vector<std::string> left;
    for (const std::string &t : todo)
        if (std::find(given.begin(), given.end(), t) == given.end()) left.push_back(t);
    save_home_todo(left);
}

/* A tile off the home screen now if ShadowMountPlus will (it won't while the store is open), else
 * when the store closes: 2 now, 3 later. */
int forget_tile(const std::string &tid)
{
    if (forget_smp(tid)) return 2;
    queue_home("forget", tid);
    return 3;
}

void manage_done(int home, const std::string &note)
{
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.home = home;
    g.job.step = "Done";
    g.job.note = note;
    g.console_again = true;
}

std::string size_words(std::uint64_t b)
{
    char t[32];
    if (b >= (1ull << 30)) std::snprintf(t, sizeof t, "%.1f GB", double(b) / 1073741824.0);
    else if (b >= (1ull << 20)) std::snprintf(t, sizeof t, "%.0f MB", double(b) / 1048576.0);
    else std::snprintf(t, sizeof t, "%.0f KB", double(b) / 1024.0);
    return t;
}

/* The folder's app, by its param.json: its title ID and name ("" when it has none). */
void folder_app(const std::string &folder, std::string &tid, std::string &name)
{
    std::string text;
    Json pj;
    tid.clear();
    name.clear();
    if (read_file(folder + "/sce_sys/param.json", text) && Json::parse(text, pj) && pj.is_object())
    {
        tid = pj["titleId"].str();
        name = param_name(pj);
    }
    if (name.empty()) name = folder.substr(folder.find_last_of('/') + 1);
}

/* Delete: one folder, everything in it, by the copier payload (it can act as a game's own user
 * for the folders a game made for itself), or here without a loader. */
void run_manage_delete(const std::string &folder)
{
    std::string root, leaf, why;
    if (!manageable(folder, root, leaf, why)) { slog("manage delete %s: refused: %s", folder.c_str(), why.c_str()); return fail_job(why); }
    std::string tid, name;
    folder_app(folder, tid, name);
    {
        std::vector<std::pair<std::string, std::string>> none;
        const std::string app = store_app_of(folder, none);
        if (!app.empty())
        {
            slog("manage delete %s: refused: the store installed it as %s", folder.c_str(), app.c_str());
            return fail_job("The store installed " + name + ": delete it from its page, so its game files and saves go with it as they should.");
        }
    }
    const double t0 = mono_s();
    slog("manage delete %s (%s, %s)", folder.c_str(), name.c_str(), tid.c_str());
    if (title_id_ok(tid)) unmount_smp(tid);
    std::size_t files = 0;
    const Copier c = run_copier("delete", "", folder, nullptr, "path0=" + folder + "\n");
    if (c == Copier::Failed)
    {
        scan_homebrew();
        std::lock_guard<std::mutex> l(g.m);
        g.console_again = true;
        return; /* run_copier said why */
    }
    if (c == Copier::Unavailable)
    {
        std::string bad;
        int e = 0;
        if (!fs::remove_tree(folder, files, bad, e, [&](std::size_t n) { set_job("Deleting", n, 0); }, {},
                             [&](const std::string &line) { slog("manage delete: %s", line.c_str()); }))
        {
            slog("manage delete %s: stopped at %s (errno %d) after %zu files", folder.c_str(), bad.c_str(), e, files);
            scan_homebrew();
            {
                std::lock_guard<std::mutex> l(g.m);
                g.console_again = true;
            }
            if (e == EACCES)
                return fail_job(bad + " couldn't be deleted: the app made it for itself, and only the store's helper payload "
                                      "can remove it. Make sure your payload loader is running (port 9021), then try again.");
            return fail_job(bad + " couldn't be deleted (error " + std::to_string(e) + "). Close the app if it's running, then try again.");
        }
    }
    if (!fs::gone(folder))
    {
        slog("manage delete %s: still there afterwards", folder.c_str());
        scan_homebrew();
        {
            std::lock_guard<std::mutex> l(g.m);
            g.console_again = true;
        }
        return fail_job(folder + " is still there. Close the app if it's running, then try again.");
    }
    slog("manage delete %s: gone, in %.1f s", folder.c_str(), mono_s() - t0);
    scan_homebrew();
    /* Its tile, unless another copy of it is still on the console. */
    int home = 0;
    const bool another = title_id_ok(tid) && !title_folder(tid).empty();
    if (title_id_ok(tid) && !another) home = forget_tile(tid);
    std::string note = "Deleted " + name + ".";
    if (another) note += " Another copy is still at " + title_folder(tid) + ".";
    else if (home == 3) note += " Its tile comes off your home screen once the store closes.";
    else if (home == 2) note += " ShadowMountPlus took its tile off your home screen.";
    manage_done(home, note);
}

/* Move: the whole folder copied to another homebrew folder (the copier, at the drive's full
 * speed, or here), every file checked there, then the old folder deleted. The app is only ever
 * whole in one place: a copy that fails goes again, and the old one is deleted only after. */
void run_manage_move(const std::string &folder, const std::string &dest_root)
{
    std::string root, leaf, why;
    if (!manageable(folder, root, leaf, why)) { slog("manage move %s: refused: %s", folder.c_str(), why.c_str()); return fail_job(why); }
    const auto roots = homebrew_roots();
    if (std::find(roots.begin(), roots.end(), dest_root) == roots.end() || dest_root == root)
        return fail_job("Pick another place to move it to.");
    if (!exists(folder + "/eboot.bin")) return fail_job("Only an app can be moved (this folder has no eboot.bin).");
    if (!drive_ready(drive_of(dest_root))) return fail_job(place_name(dest_root) + " isn't connected.");
    const std::string target = dest_root + "/" + leaf;
    if (exists(target)) return fail_job(place_name(dest_root) + " already has a folder called " + leaf + ".");
    std::string tid, name;
    folder_app(folder, tid, name);
    set_job("Working out its size", 0, 0);
    std::uint64_t bytes = 0;
    std::size_t nfiles = 0;
    fs::tree_size(folder, bytes, nfiles);
    {
        const std::string drive = drive_of(dest_root);
        std::uint64_t free_b = 0;
        if (free_bytes(drive, free_b)) /* (2.2.4) the real figures */
        {
            if (free_b < bytes + (64ull << 20))
                return fail_job(place_name(dest_root) + " has " + size_words(free_b) + " free, and " + name + " needs " + size_words(bytes) + ".");
        }
        else
            slog("manage move: couldn't read the free space on %s; trying anyway", drive.c_str());
    }
    make_dirs(dest_root);
    const double t0 = mono_s();
    slog("manage move %s -> %s (%s, %s, %zu files)", folder.c_str(), target.c_str(), name.c_str(), size_words(bytes).c_str(), nfiles);
    if (title_id_ok(tid)) unmount_smp(tid);
    const Copier c = run_copier("tree", folder, target);
    if (c == Copier::Failed)
    {
        std::lock_guard<std::mutex> l(g.m);
        g.console_again = true;
        return; /* run_copier said why; the copier took its half copy away */
    }
    if (c == Copier::Unavailable)
    {
        std::uint64_t done = 0;
        std::size_t files = 0;
        std::string bad;
        int e = 0;
        set_job("Copying", 0, bytes);
        const bool ok = fs::copy_tree(folder, target, done, files, bad, e, [&](std::uint64_t so_far) { set_job("Copying", so_far, bytes); return true; }, {},
                                      [&](const std::string &line) { slog("manage move: %s", line.c_str()); });
        std::string bad2;
        if (!ok || !fs::same_tree(folder, target, bad2))
        {
            std::size_t n = 0;
            std::string b;
            int e2 = 0;
            fs::remove_tree(target, n, b, e2);
            slog("manage move %s: the copy stopped at %s (errno %d)%s", folder.c_str(), ok ? bad2.c_str() : bad.c_str(), e,
                 fs::gone(target) ? "; the half copy is gone" : "; the half copy couldn't be removed");
            std::lock_guard<std::mutex> l(g.m);
            g.console_again = true;
            fail_job(ok ? bad2 + " didn't copy whole. Nothing was moved."
                        : e == ENOSPC ? place_name(dest_root) + " is full. Nothing was moved."
                                      : (ok ? bad2 : bad) + " couldn't be copied (error " + std::to_string(e) + "). Nothing was moved.");
            return;
        }
        slog("manage move: copied %zu files in the store, all checked", files);
    }
    /* Whole in the new place: the old folder goes. */
    set_job("Deleting the old copy", 0, 0);
    std::size_t files = 0;
    const Copier d = run_copier("delete", "", folder, nullptr, "path0=" + folder + "\n");
    bool old_gone = d == Copier::Done;
    if (d == Copier::Unavailable)
    {
        std::string bad;
        int e = 0;
        old_gone = fs::remove_tree(folder, files, bad, e);
    }
    old_gone = old_gone && fs::gone(folder);
    if (d == Copier::Failed || !old_gone)
    {
        {
            std::lock_guard<std::mutex> l(g.m);
            g.job = JobState();
            g.job.kind = "mmove";
            g.job.app_id = folder;
        }
        slog("manage move %s: copied to %s, but the old folder couldn't be deleted", folder.c_str(), target.c_str());
        scan_homebrew();
        std::lock_guard<std::mutex> l(g.m);
        g.console_again = true;
        g.job.phase = Phase::Failed;
        g.job.error = name + " is copied to " + place_name(dest_root) + ", but the old copy in " + place_name(root) +
                      " couldn't be deleted. Close the app if it's running, then delete the old one here.";
        return;
    }
    slog("manage move %s: done in %.1f s", folder.c_str(), mono_s() - t0);
    scan_homebrew();
    {
        std::lock_guard<std::mutex> l(g.m);
        apply_moves(g.catalog); /* a store app's data folders follow it */
        ++g.revision;
    }
    if (title_id_ok(tid)) queue_home("unmount", tid);
    manage_done(title_id_ok(tid) ? 3 : 0, "Moved " + name + " to " + place_name(dest_root) + "." +
                                             (title_id_ok(tid) ? " Its tile opens it from there once the store closes." : ""));
}

/* New title ID: the next one free in the block the title ID guard uses, in its param.json, and
 * its folder renamed to it. A store app keeps getting its updates (moves.json, as a moved app). */
void run_manage_retitle(const std::string &folder)
{
    std::string root, leaf, why;
    if (!manageable(folder, root, leaf, why)) { slog("manage retitle %s: refused: %s", folder.c_str(), why.c_str()); return fail_job(why); }
    std::string old, name;
    folder_app(folder, old, name);
    if (!exists(folder + "/eboot.bin") || !title_id_ok(old)) return fail_job("Only an app with a title ID in its param.json can get a new one.");
    const std::string fresh = free_move_id();
    if (fresh.empty()) return fail_job("There's no free title ID left in the store's block.");
    const std::string target = root + "/" + fresh;
    if (exists(target)) return fail_job(place_name(root) + " already has a folder called " + fresh + ".");
    std::vector<std::pair<std::string, std::string>> none;
    const std::string app = store_app_of(folder, none);
    slog("manage retitle %s: %s -> %s (%s)%s", folder.c_str(), old.c_str(), fresh.c_str(), name.c_str(),
         app.empty() ? "" : (", the store's " + app).c_str());
    unmount_smp(old);
    if (!retitle(folder, old, fresh, why))
    {
        slog("manage retitle %s: %s", folder.c_str(), why.c_str());
        return fail_job("Its param.json couldn't be changed (" + why + "). Nothing changed.");
    }
    if (std::rename(folder.c_str(), target.c_str()) != 0)
    {
        const int e = errno;
        std::string back;
        if (!retitle(folder, fresh, old, back)) slog("manage retitle %s: couldn't put its param.json back: %s", folder.c_str(), back.c_str());
        slog("manage retitle %s: rename to %s failed (errno %d)", folder.c_str(), target.c_str(), e);
        return fail_job("Its folder couldn't be renamed (error " + std::to_string(e) + "). Close the app if it's running. Nothing changed.");
    }
    if (!app.empty())
    {
        /* The store's own install: its updates go to the new ID from now on, and its marker says it. */
        std::string text;
        if (read_file(target + "/" + kMarker, text) && set_string(text, "titleId", [&](const std::string &) { return fresh; }))
            write_file(target + "/" + kMarker, text.data(), text.size());
        scan_homebrew();
        remember_move(app, fresh);
    }
    else
        scan_homebrew();
    const int home = forget_tile(old);
    manage_done(3, name + " is " + fresh + " now. Its new tile shows up once the store closes" +
                       (home == 3 ? ", and the old one goes." : ".") + " Saves it made as " + old + " stay with " + old + ".");
}
} // namespace

void run_manage(const std::string &kind, const std::string &folder, const std::string &dest)
{
    if (kind == "mdelete") run_manage_delete(folder);
    else if (kind == "mmove") run_manage_move(folder, dest);
    else if (kind == "mretitle") run_manage_retitle(folder);
    else fail_job("The store doesn't know that.");
}

std::vector<ConsoleApp> Engine::console_apps(bool *looking) const
{
    std::lock_guard<std::mutex> l(g.m);
    if (looking) *looking = g.console_looking || g.console_again;
    return g.console;
}

unsigned Engine::console_revision() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.console_rev;
}

void Engine::look_console()
{
    static bool started = false;
    std::lock_guard<std::mutex> l(g.m);
    if (!started)
    {
        pthread_t t;
        started = create_title_thread(&t, console_main, nullptr) == 0;
        if (started) pthread_detach(t);
    }
    g.console_again = true;
}

bool Engine::manage(const std::string &kind, const std::string &folder, const std::string &dest)
{
    if (kind != "mdelete" && kind != "mmove" && kind != "mretitle") return false;
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty() || !g.pending_manage.empty())
        return false;
    g.pending_manage = kind;
    g.pending_manage_folder = folder;
    g.pending_manage_dest = dest;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = kind;
    g.job.app_id = folder;
    g.job.step = kind == "mdelete" ? "Deleting" : kind == "mmove" ? "Moving" : "Changing its title ID";
    return true;
}

std::string Engine::free_title_id() const { return free_move_id(); }

int Engine::smp_state(std::string &version) const
{
    std::lock_guard<std::mutex> l(g.m);
    version = g.smp_version;
    return g.smp_state;
}

static bool send_launcher(const std::string &title_id, const std::string &mode, std::string &why);

/* (2.2.1) Through the launcher, with the store closed (smp_file_ok says why). */
bool Engine::reload_smp(std::string &why) { return send_launcher("PPSA92000", "reload_smp", why); }

int Engine::home_waiting() const { return int(home_todo().size()); }

namespace
{
int ask_smp_version(std::string &version)
{
    version.clear();
    std::string reply;
    bool answered = false;
    smp_api("/api/v1/version", "{}", reply, &answered);
    if (!answered) return 0;
    Json j;
    if (Json::parse(reply, j) && j.is_object()) version = j["shadowmount_version"].str();
    return 1;
}
} // namespace

/* Opening a game. An app may not start another app (the system answers "not
 * allowed"), so the launcher payload does it, as Porpoise's home screen tiles do:
 * the store leaves it the title ID, hands it to the payload loader on port 9021 and
 * closes once it has started; it opens the game when the store has gone, or opens
 * the store again if the game won't open. */
static std::string g_open_token, g_open_title;
static std::vector<std::string> g_open_home; /* (2.2) the home screen changes handed to it */

/* (2.2) mode "" opens title_id; "refresh" (title_id the store's) refreshes the home screen and
 * opens the store again. Either way the launcher first makes the home screen changes waiting.
 * (2.2.1) "reload_smp" (title_id the store's) sends ShadowMountPlus again, then as "refresh".
 * Every job carries the player's ShadowMountPlus file (path=) when there is one: the launcher
 * starts it if nothing answers, the store being closed by then. */
static bool send_launcher(const std::string &title_id, const std::string &mode, std::string &why)
{
    if (!title_id_ok(title_id))
    {
        why = "Its title ID isn't one the PS5 can open.";
        return false;
    }
    if (data_lost(-1))
    {
        why = kStorageLost;
        return false;
    }
    std::string smp_file, smp_why;
    const bool smp_ok = smp_file_ok(smp_file, smp_why);
    if (mode == "reload_smp" && !smp_ok)
    {
        why = smp_why;
        return false;
    }
    if (!payloads_can_reach(why))
        return false;
    const std::string elf_path = std::string(PORPOISE_APP) + "/assets/payloads/recompstore-launcher.elf";
    std::string elf;
    if (!read_file(elf_path, elf) || elf.size() < 64 || elf.compare(0, 4, "\x7F" "ELF") != 0)
    {
        slog("open %s: the app has no launcher payload (%s)", title_id.c_str(), elf_path.c_str());
        why = "The store's launcher is missing. Reinstall Recomp Store.";
        return false;
    }
    const std::string jd = g.data + "/launch";
    make_dirs(jd);
    std::remove((jd + "/cancel").c_str());
    std::remove((jd + "/status.txt").c_str());
    char token[48];
    std::snprintf(token, sizeof token, "%lld-%d", (long long)std::time(nullptr), int(mono_s() * 1000) % 100000);
    /* (2.1.1) Its name, for the launcher's "Adding ... to your home screen" toast: printable
     * characters only, on one line. */
    std::string name;
    {
        std::lock_guard<std::mutex> l(g.m);
        for (const App &x : g.catalog.apps)
            if (x.id == title_id || moved_id(x.id) == title_id) { name = x.name; break; }
        if (name.empty()) /* (2.2) an app the store didn't install: its param.json's */
            for (const ConsoleApp &c : g.console)
                if (c.title_id == title_id && c.app) { name = c.name; break; }
    }
    std::string clean;
    for (const char c : name)
        if (static_cast<unsigned char>(c) >= 0x20 && c != 0x7F && clean.size() < 80) clean += c;
    std::string job = std::string("token=") + token + "\ntitle=" + title_id + "\n";
    if (!clean.empty()) job += "name=" + clean + "\n";
    if (!mode.empty()) job += "mode=" + mode + "\n";
    if (smp_ok) job += "path=" + smp_file + "\n"; /* (2.2.1) */
    /* (2.2) Home screen changes ShadowMountPlus couldn't make while the store was open. */
    const std::vector<std::string> todo = home_todo();
    for (std::size_t i = 0; i < todo.size(); ++i) job += "do" + std::to_string(i) + "=" + todo[i] + "\n";
    if (!write_file(jd + "/job.txt", job.data(), job.size()))
    {
        slog("open %s: couldn't write the launcher's job in %s", title_id.c_str(), jd.c_str());
        why = data_lost(-1) ? kStorageLost : "The store couldn't write to its folder.";
        return false;
    }
    {
        struct stat st;
        const std::string mounted = "/system_ex/app/" + title_id;
        slog("open %s: %s %s", title_id.c_str(), mounted.c_str(), stat(mounted.c_str(), &st) == 0 ? "is there" : "isn't there");
    }
    int err = 0;
    if (!send_to_loader(elf, err))
    {
        std::remove((jd + "/job.txt").c_str());
        slog("open %s: no payload loader answered on port 9021 (errno %d)", title_id.c_str(), err);
        why = "Nothing answered on port 9021. Start your payload loader (etaHEN or elfldr), then try again.";
        return false;
    }
    g_open_token = token;
    g_open_title = title_id;
    g_open_home = todo;
    const std::string what = mode == "refresh" ? "refresh home screen" : mode == "reload_smp" ? "reload ShadowMountPlus" : "open " + title_id;
    slog("%s: sent the launcher (%zu KB) to the payload loader%s%s", what.c_str(), elf.size() / 1024,
         todo.empty() ? "" : (" with " + std::to_string(todo.size()) + " home screen change(s)").c_str(),
         smp_ok ? (mode == "reload_smp" ? ("; it sends " + smp_file + " once the store has closed").c_str()
                                        : ("; it starts " + smp_file + " if nothing answers").c_str())
                : "");
    return true;
}

bool open_game(const std::string &title_id, std::string &why) { return send_launcher(title_id, "", why); }

bool refresh_home(std::string &why) { return send_launcher("PPSA92000", "refresh", why); }

bool refresh_result(std::string &what)
{
    const std::string path = g.data + "/launch/status.txt";
    std::string st;
    struct stat info;
    if (stat(path.c_str(), &info) != 0 || !read_file(path, st) || field_of(st, "phase") != "refreshed")
        return false;
    std::rename(path.c_str(), (g.data + "/launch/status.seen.txt").c_str()); /* said once */
    if (std::time(nullptr) - info.st_mtime > 300) return false;
    const bool smp = field_of(st, "smp") == "1";
    const int changes = std::atoi(field_of(st, "changes").c_str());
    if (field_of(st, "reload") == "1")
    {
        /* (2.2.1) Back from Reload ShadowMountPlus. */
        const bool restarted = field_of(st, "restarted") == "1";
        slog("reload ShadowMountPlus: back (%s, %d change(s) made)", restarted ? "it restarted and answers" : smp ? "it answers, but didn't restart" : "it doesn't answer", changes);
        what = restarted && smp ? "ShadowMountPlus reloaded" : smp ? "ShadowMountPlus didn't restart" : "ShadowMountPlus didn't come back";
        return true;
    }
    const bool started = field_of(st, "started") == "1";
    slog("refresh home screen: back (ShadowMountPlus %s, %d change(s) made)", smp ? (started ? "started and answered" : "answered") : "didn't answer", changes);
    what = smp ? (started ? "ShadowMountPlus started. Your home screen is up to date" : "Your home screen is up to date")
               : "ShadowMountPlus didn't answer, so your home screen didn't change";
    return true;
}

std::string Engine::store_update() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.store_update;
}

std::string Engine::newest_version(const App &a) const
{
    if (a.source != "github" || a.repo.empty() || a.asset.empty()) return "";
    std::lock_guard<std::mutex> l(g.m);
    auto it = g.notes.find(a.repo);
    if (it == g.notes.end())
    {
        /* From disk, once a minute at most (this is asked every frame). */
        static std::map<std::string, double> tried;
        auto t = tried.find(a.repo);
        if (t != tried.end() && mono_s() - t->second < 60) return "";
        tried[a.repo] = mono_s();
        std::string text;
        std::vector<Release> rel;
        if (!read_file(notes_file(a.repo), text) || !parse_releases(text, rel)) return "";
        it = g.notes.emplace(a.repo, rel).first;
    }
    for (const Release &r : it->second)
        for (const std::string &n : r.assets)
            if (asset_matches(a.asset, n)) return r.tag;
    return "";
}

void Engine::check_updates_now()
{
    std::lock_guard<std::mutex> l(g.m);
    g.force_check = true;
    g.update_check = 1;
    slog("check for updates: asked");
}

int Engine::update_check() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.update_check;
}

bool Engine::take_auto_update(std::string &version)
{
    std::lock_guard<std::mutex> l(g.m);
    /* Only with nothing else going on, and no result the app hasn't shown yet. */
    if (!g.auto_update || g.store_update.empty() || g.job.phase != Phase::Idle || g.pending_store_dl ||
        !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench || !g.pending_delete.empty() ||
        !g.pending_manage.empty())
        return false;
    g.auto_update = false; /* once: if it doesn't work out, Latest Updates still offers it */
    std::remove((g.data + "/selfupdate/autoupdate").c_str());
    version = g.store_update;
    slog("store: updating itself to %s on its own (it came from an installer's own copy)", version.c_str());
    return true;
}

bool Engine::download_store_update()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || g.pending_store_dl) return false;
    g.pending_store_dl = true;
    return true;
}

/* The store updates itself through its installer payload (as a first install does):
 * it leaves the job, hands the payload to the loader, and closes once it has started. */
static std::string g_update_token;
bool update_store(std::string &why)
{
    if (!payloads_can_reach(why))
        return false;
    const std::string elf_path = std::string(PORPOISE_APP) + "/assets/payloads/RecompStore-Installer.elf";
    std::string elf;
    if (!read_file(elf_path, elf) || elf.size() < 64 || elf.compare(0, 4, "\x7F" "ELF") != 0)
    {
        slog("store update: the app has no installer payload (%s)", elf_path.c_str());
        why = "The store's installer is missing. Send RecompStore-Installer.elf to your PS5 instead.";
        return false;
    }
    const std::string jd = g.data + "/selfupdate";
    make_dirs(jd);
    std::remove((jd + "/status.txt").c_str());
    std::remove((jd + "/cancel").c_str());
    char token[48];
    std::snprintf(token, sizeof token, "%lld-%d", (long long)std::time(nullptr), int(mono_s() * 1000) % 100000);
    std::string zip, zsha, zver;
    {
        std::lock_guard<std::mutex> l(g.m);
        zip = g.store_zip;
        zsha = g.store_zip_sha;
        zver = g.store_zip_ver;
    }
    if (zip.empty() || !exists(zip))
    {
        why = "The update's download is missing. Try again.";
        return false;
    }
    const std::string job = std::string("token=") + token + "\nmode=update\nzip=" + zip + "\nsha256=" + zsha + "\nversion=" + zver + "\n";
    if (!write_file(jd + "/job.txt", job.data(), job.size()))
    {
        why = "The store couldn't write to its folder.";
        return false;
    }
    int err = 0;
    if (!send_to_loader(elf, err))
    {
        std::remove((jd + "/job.txt").c_str());
        slog("store update: no payload loader answered on port 9021 (errno %d)", err);
        why = "Nothing answered on port 9021. Start your payload loader (etaHEN or elfldr), then try again.";
        return false;
    }
    g_update_token = token;
    slog("store update: sent the installer (%zu KB) to the payload loader", elf.size() / 1024);
    return true;
}

int update_store_state()
{
    std::string st;
    if (g_update_token.empty() || !read_file(g.data + "/selfupdate/status.txt", st) || field_of(st, "token") != g_update_token)
        return 0;
    const std::string phase = field_of(st, "phase");
    if (phase == "waiting")
    {
        slog("store update: the installer is running; the store closes so it can update");
        g_update_token.clear();
        return 1;
    }
    if (phase == "failed")
    {
        g_update_token.clear();
        return -1;
    }
    return 0;
}

void update_store_cancel()
{
    /* Taken back, and the installer told to stand down if it starts late. */
    std::remove((g.data + "/selfupdate/job.txt").c_str());
    write_file(g.data + "/selfupdate/cancel", "1", 1);
    slog("store update: the installer didn't start; its job was taken back");
    g_update_token.clear();
}

int open_game_state()
{
    std::string st;
    if (g_open_token.empty() || !read_file(g.data + "/launch/status.txt", st) || field_of(st, "token") != g_open_token)
        return 0;
    const std::string phase = field_of(st, "phase");
    if (phase == "waiting" || phase == "done")
    {
        slog("open %s: the launcher is running; the store closes so it can open the game", g_open_title.c_str());
        g_open_token.clear();
        home_handed(g_open_home); /* (2.2) the launcher makes them now */
        g_open_home.clear();
        return 1;
    }
    if (phase == "failed")
    {
        g_open_token.clear();
        return -1;
    }
    return 0;
}

void open_game_cancel()
{
    const std::string jd = g.data + "/launch";
    write_file(jd + "/cancel", "1", 1);
    std::remove((jd + "/job.txt").c_str());
    slog("open %s: the launcher didn't start; told it to stand down", g_open_title.c_str());
    g_open_token.clear();
}

bool open_game_failure(std::string &title_id, unsigned &result)
{
    const std::string path = g.data + "/launch/status.txt";
    std::string st;
    struct stat info;
    if (stat(path.c_str(), &info) != 0 || !read_file(path, st) || field_of(st, "phase") != "failed")
        return false;
    std::rename(path.c_str(), (g.data + "/launch/status.seen.txt").c_str()); /* said once */
    const long long r = std::strtoll(field_of(st, "result").c_str(), nullptr, 10);
    if (std::time(nullptr) - info.st_mtime > 180 || r == -1)
        return false; /* old, or the store itself gave up */
    title_id = field_of(st, "title");
    result = unsigned(r);
    slog("the launcher couldn't open %s (0x%08x) and opened the store again", title_id.c_str(), result);
    return title_id_ok(title_id);
}

bool Engine::setup(const std::string &app_id, const std::string &slot_key, const std::string &image)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty()) return false;
    g.pending_setup_app = app_id;
    g.pending_setup_slot = slot_key;
    g.pending_setup_image = image;
    g.pending_setup_fetch = false;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "setup";
    g.job.slot = slot_key;
    g.job.app_id = app_id;
    g.job.step = "Reading the disc image";
    return true;
}

bool Engine::fetch_update(const std::string &app_id, const std::string &slot_key)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty()) return false;
    g.pending_setup_app = app_id;
    g.pending_setup_slot = slot_key;
    g.pending_setup_image.clear();
    g.pending_setup_fetch = true;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "setup";
    g.job.slot = slot_key;
    g.job.app_id = app_id;
    g.job.step = "Finding the update";
    return true;
}

std::vector<Place> places()
{
    std::vector<Place> out;
    for (int i = 0; i < 8; ++i)
    {
        const std::string p = "/mnt/usb" + std::to_string(i);
        struct stat st;
        DIR *d = stat(p.c_str(), &st) == 0 ? opendir(p.c_str()) : nullptr;
        if (d)
        {
            /* An empty mount point is a port with nothing in it. */
            bool any = false;
            while (dirent *e = readdir(d))
                if (std::strcmp(e->d_name, ".") && std::strcmp(e->d_name, "..")) { any = true; break; }
            closedir(d);
            if (any) out.push_back({"USB drive " + std::to_string(i + 1), p});
        }
    }
    for (int i = 0; i < 2; ++i)
    {
        const std::string p = "/mnt/ext" + std::to_string(i);
        if (DIR *d = opendir(p.c_str()))
        {
            closedir(d);
            out.push_back({i == 0 ? "Extended storage" : "Extended storage 2", p});
        }
    }
    out.push_back({"Console storage", "/data"});
    /* (2.3.0) What came from a phone (Upload from your phone). */
    {
        std::string data;
        {
            std::lock_guard<std::mutex> l(g.m);
            data = g.data;
        }
        if (!data.empty() && exists(data + "/uploads")) out.insert(out.begin(), Place{"Uploads", data + "/uploads"});
    }
    return out;
}

bool list_dir(const std::string &path, std::vector<FsEntry> &out, const char *ext)
{
    out.clear();
    DIR *d = opendir(path.c_str());
    if (!d) return false;
    std::vector<FsEntry> dirs, isos;
    while (dirent *e = readdir(d))
    {
        const std::string name = e->d_name;
        if (name.empty() || name[0] == '.' || name == "$RECYCLE.BIN" || name == "System Volume Information") continue;
        FsEntry f;
        f.name = name;
        f.path = (path == "/" ? "" : path) + "/" + name;
        struct stat st;
        if (stat(f.path.c_str(), &st) != 0) continue;
        if (S_ISDIR(st.st_mode))
        {
            f.is_dir = true;
            dirs.push_back(f);
        }
        else
        {
            std::string low = name;
            for (char &c : low) c = char(std::tolower(static_cast<unsigned char>(c)));
            const std::size_t el = std::strlen(ext);
            /* "stfs": Xbox 360 content packages, which have no extension: known by their first bytes.
             * "image": GameCube and Wii disc images. */
            auto ends = [&](const char *x) {
                const std::size_t xl = std::strlen(x);
                return low.size() > xl && low.compare(low.size() - xl, xl, x) == 0;
            };
            /* "*": every file. Otherwise one extension or several, space-separated (".z64 .n64"). */
            auto any_ext = [&]() {
                std::size_t at = 0;
                const std::string list = ext;
                while (at < list.size())
                {
                    std::size_t sp = list.find(' ', at);
                    if (sp == std::string::npos) sp = list.size();
                    std::string e = list.substr(at, sp - at);
                    for (char &c : e) c = char(std::tolower(static_cast<unsigned char>(c)));
                    if (!e.empty() && e[0] != '.') e = "." + e; /* "z64" is ".z64", as file_ext_ok reads it */
                    if (!e.empty() && ends(e.c_str())) return true;
                    at = sp + 1;
                }
                return false;
            };
            (void)el;
            const bool want = std::strcmp(ext, "stfs") == 0    ? stfs::looks_like(f.path)
                              : std::strcmp(ext, "image") == 0 ? ends(".iso") || ends(".gcm") || ends(".ciso")
                              : std::strcmp(ext, "*") == 0     ? true
                                                               : any_ext();
            if (want)
            {
                f.size = std::uint64_t(st.st_size);
                isos.push_back(f);
            }
        }
        if (dirs.size() + isos.size() > 2000) break;
    }
    closedir(d);
    auto by_name = [](const FsEntry &x, const FsEntry &y) {
        std::string a = x.name, b = y.name;
        for (char &c : a) c = char(std::tolower(static_cast<unsigned char>(c)));
        for (char &c : b) c = char(std::tolower(static_cast<unsigned char>(c)));
        return a < b;
    };
    std::sort(dirs.begin(), dirs.end(), by_name);
    std::sort(isos.begin(), isos.end(), by_name);
    out = dirs;
    out.insert(out.end(), isos.begin(), isos.end());
    return true;
}

std::string slot_target(const App &a, const Slot &s)
{
    std::string dir = a.data_dir.empty() ? app_folder(a.id) : a.data_dir;
    while (dir.size() > 1 && dir.back() == '/') dir.pop_back();
    if (!s.to.empty() && s.to != "." && safe_name(s.to)) dir += "/" + s.to;
    return dir;
}

bool slot_fetchable(const Slot &s)
{
    if (s.kind != "stfs" || s.multiple || s.title_id.size() != 8 || s.update_id.size() != 40 || s.update_sha1.size() != 40) return false;
    for (char c : s.title_id) /* it names a folder: hex digits only */
        if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F'))) return false;
    return true;
}

bool store_downloaded(const std::string &path)
{
    const std::string dir = updates_folder() + "/";
    return path.size() > dir.size() && path.compare(0, dir.size(), dir) == 0;
}

/* (1.0.8) rel ("Data/GameLogic.dllp") under dir, each part in any case: a disc's own
 * spelling is kept when it is copied ("Default.xex"), and the console's storage tells the
 * cases apart. */
bool exists_any_case(const std::string &dir, const std::string &rel)
{
    return !any_case_path(dir, rel).empty();
}

/* (1.1.2) rel under dir, found as exists_any_case finds it, at exactly size bytes. */
bool at_size_any_case(const std::string &dir, const std::string &rel, std::uint64_t size)
{
    const std::string p = any_case_path(dir, rel);
    struct stat st;
    return !p.empty() && stat(p.c_str(), &st) == 0 && S_ISREG(st.st_mode) && std::uint64_t(st.st_size) == size;
}

/* Where rel is under dir, each part in any case; "" when it isn't there. */
std::string any_case_path(const std::string &dir, const std::string &rel)
{
    std::string at = dir;
    std::size_t from = 0;
    while (from <= rel.size())
    {
        std::size_t end = rel.find('/', from);
        if (end == std::string::npos) end = rel.size();
        const std::string part = rel.substr(from, end - from);
        from = end + 1;
        if (part.empty()) continue;
        if (exists(at + "/" + part)) { at += "/" + part; continue; }
        const std::string want = lower_ascii(part);
        std::string found;
        if (DIR *d = opendir(at.c_str()))
        {
            while (dirent *e = readdir(d))
                if (lower_ascii(e->d_name) == want) { found = e->d_name; break; }
            closedir(d);
        }
        if (found.empty()) return "";
        at += "/" + found;
    }
    return at;
}

/* Raised whenever a job ends (Engine::acknowledge): slot_ready's two-second answers from
 * before it are looked at again, so a slot just set up (or a game just deleted) shows at once. */
std::atomic<unsigned> g_slot_epoch{0};

bool slot_ready(const App &a, const Slot &s)
{
    if (!s.files.empty())
    {
        /* (1.1.2) Every file the slot must have, at its size. Asked every frame, so looked
         * at every two seconds. */
        static std::mutex m;
        static std::map<std::string, std::pair<double, bool>> seen;
        const std::string dir = slot_target(a, s), key = dir + "|" + s.key + "|" + std::to_string(g_slot_epoch.load());
        std::lock_guard<std::mutex> l(m);
        if (seen.size() > 256) seen.clear();
        auto it = seen.find(key);
        if (it != seen.end() && mono_s() - it->second.first < 2.0) return it->second.second;
        bool ok = true;
        for (const DiscWant &w : s.files)
            if (!(ok = at_size_any_case(dir, w.path, w.size))) break;
        seen[key] = {mono_s(), ok};
        return ok;
    }
    if (!s.ready.empty() || s.kind == "iso" || s.kind == "arcade")
    {
        /* The slot's own set-up file, or a disc's or Arcade game's default.xex, in any case.
         * Asked every frame, so looked at every two seconds. */
        static std::mutex m;
        static std::map<std::string, std::pair<double, bool>> seen;
        const std::string dir = slot_target(a, s), file = s.ready.empty() ? std::string("default.xex") : s.ready;
        const std::string key = dir + "|" + file + "|" + std::to_string(g_slot_epoch.load());
        std::lock_guard<std::mutex> l(m);
        if (seen.size() > 256) seen.clear();
        auto it = seen.find(key);
        if (it != seen.end() && mono_s() - it->second.first < 2.0) return it->second.second;
        const bool ok = exists_any_case(dir, file);
        seen[key] = {mono_s(), ok};
        return ok;
    }
    if (s.kind == "image")
    {
        /* The right disc in the folder. Asked every frame, so looked at every two seconds. */
        static std::mutex m;
        static std::map<std::string, std::pair<double, bool>> seen;
        const std::string dir = slot_target(a, s), key = dir + "|" + s.disc_id + "|" + std::to_string(g_slot_epoch.load());
        std::lock_guard<std::mutex> l(m);
        if (seen.size() > 256) seen.clear();
        auto it = seen.find(key);
        if (it != seen.end() && mono_s() - it->second.first < 2.0) return it->second.second;
        bool ok = false;
        std::string want = s.disc_id;
        for (char &c : want) if (c >= 'a' && c <= 'z') c = char(c - 'a' + 'A');
        std::vector<FsEntry> here;
        if (list_dir(dir, here, "image"))
            for (const FsEntry &e : here)
                if (!e.is_dir)
                {
                    const std::string id = disc_image_id(e.path);
                    if (!id.empty() && (want.empty() || id == want)) { ok = true; break; }
                }
        seen[key] = {mono_s(), ok};
        return ok;
    }
    if (s.kind == "file-bad") return false; /* a listing this store won't set up */
    if (s.kind == "file")
    {
        /* The files in place, or what the game made from them ("made"). Looked at every two
         * seconds, as the image's. */
        static std::mutex m;
        static std::map<std::string, std::pair<double, bool>> seen;
        const std::string key = a.id + "|" + s.key + "|" + std::to_string(g_slot_epoch.load());
        std::lock_guard<std::mutex> l(m);
        if (seen.size() > 256) seen.clear();
        auto it = seen.find(key);
        if (it != seen.end() && mono_s() - it->second.first < 2.0) return it->second.second;
        const bool ok = (plain_leaf(s.made) && exists(slot_target(a, s) + "/" + s.made)) || files_in_place(a, s) >= s.need;
        seen[key] = {mono_s(), ok};
        return ok;
    }
    std::vector<FsEntry> any;
    DIR *d = opendir(slot_target(a, s).c_str());
    if (!d) return false;
    bool found = false;
    while (dirent *e = readdir(d))
        if (e->d_name[0] != '.') { found = true; break; }
    closedir(d);
    return found;
}

std::string slot_source(const App &a, const Slot &s)
{
    std::string data, v;
    {
        std::lock_guard<std::mutex> l(g.m);
        data = g.data;
    }
    read_file(data + "/setup/" + a.id + "." + s.key + ".txt", v);
    return v;
}

JobState Engine::job() const { std::lock_guard<std::mutex> l(g.m); return g.job; }

void Engine::acknowledge()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Done || g.job.phase == Phase::Failed) g.job = JobState();
    ++g_slot_epoch;
}

/* (2.0) The folder holds an install of this custom source's app: the marker the store left
 * there names its repo. */
static bool custom_owns(const std::string &dir, const std::string &repo)
{
    std::string text;
    Json mk;
    return read_file(dir + "/" + kMarker, text) && Json::parse(text, mk) && mk.is_object() &&
           lower_text(mk["source"].str()) == lower_text("github:" + repo);
}

std::string Engine::installed(const std::string &app_id) const
{
    std::string data, repo;
    bool custom = false;
    {
        std::lock_guard<std::mutex> l(g.m);
        data = g.data;
        auto it = g.custom_repos.find(app_id);
        custom = it != g.custom_repos.end();
        if (custom) repo = it->second;
    }
    const std::string dir = app_folder(app_id);
    const bool there = exists(dir + "/eboot.bin");
    /* (2.0) A custom source's app is installed only where the store installed it from that repo:
     * another app in the folder its ID points at isn't it. */
    if (there && custom && !custom_owns(dir, repo)) return "";
    std::string v;
    if (there && read_file(data + "/installed/" + app_id + ".txt", v) && !v.empty()) return v;
    return there ? "?" : "";
}

/* ---- (2.2.2) The download queue, the drives, the download cache, deleting several at once,
 * Get ShadowMountPlus ------------------------------------------------------------------------- */
namespace
{
/* queue.txt: one install a line, "id<TAB>version<TAB>root". Written as it changes (g.m held). */
void save_queue_locked()
{
    std::string text;
    for (const QueueItem &q : g.queue) text += q.id + "\t" + q.version + "\t" + q.root + "\n";
    const std::string f = g.data + "/queue.txt";
    if (text.empty()) std::remove(f.c_str());
    else write_file(f, text.data(), text.size());
}

bool queue_text_ok(const std::string &s, std::size_t most)
{
    if (s.size() > most) return false;
    for (const char c : s)
        if (static_cast<unsigned char>(c) < 0x20 || c == 0x7F) return false;
    return true;
}
} // namespace

void load_queue()
{
    std::string text;
    if (!read_file(g.data + "/queue.txt", text)) return;
    const auto roots = homebrew_roots();
    std::vector<QueueItem> q;
    std::size_t at = 0;
    while (at < text.size() && q.size() < 64)
    {
        std::size_t end = text.find('\n', at);
        if (end == std::string::npos) end = text.size();
        const std::string line = text.substr(at, end - at);
        at = end + 1;
        const std::size_t t1 = line.find('\t'), t2 = t1 == std::string::npos ? t1 : line.find('\t', t1 + 1);
        if (t2 == std::string::npos) continue;
        QueueItem it{line.substr(0, t1), line.substr(t1 + 1, t2 - t1 - 1), line.substr(t2 + 1)};
        if (it.id.empty() || it.id.find('/') != std::string::npos || !queue_text_ok(it.id, 64) || !queue_text_ok(it.version, 128) ||
            (!it.root.empty() && std::find(roots.begin(), roots.end(), it.root) == roots.end()))
            continue;
        bool dup = false;
        for (const QueueItem &x : q) dup = dup || x.id == it.id;
        if (!dup) q.push_back(it);
    }
    if (!q.empty()) slog("download queue: %zu waiting from before", q.size());
    std::lock_guard<std::mutex> l(g.m);
    g.queue = q;
}

bool Engine::queue_add(const std::string &app_id, const std::string &version, const std::string &root)
{
    if (app_id.empty() || app_id.find('/') != std::string::npos || !queue_text_ok(app_id, 64) || !queue_text_ok(version, 128) || !root_ok(root))
        return false;
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase != Phase::Idle && g.job.app_id == app_id) return false; /* it's the one going */
    for (const QueueItem &q : g.queue)
        if (q.id == app_id) return false;
    if (g.queue.size() >= 64) return false;
    g.queue.push_back({app_id, version, root});
    save_queue_locked();
    slog("download queue: %s added%s%s (%zu waiting)", app_id.c_str(), version.empty() ? "" : ", version ", version.c_str(), g.queue.size());
    return true;
}

std::vector<QueueItem> Engine::queue() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.queue;
}

bool Engine::queue_remove(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g.m);
    for (auto it = g.queue.begin(); it != g.queue.end(); ++it)
        if (it->id == app_id)
        {
            g.queue.erase(it);
            save_queue_locked();
            slog("download queue: %s taken out (%zu waiting)", app_id.c_str(), g.queue.size());
            return true;
        }
    return false;
}

void Engine::queue_clear()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.queue.empty()) return;
    slog("download queue: cleared (%zu were waiting)", g.queue.size());
    g.queue.clear();
    save_queue_locked();
}

std::string Engine::queue_pump()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase != Phase::Idle || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty() || !g.pending_manage.empty() || !g.pending_mdeletes.empty() || g.pending_smpget || g.pending_store_dl)
        return "";
    g.queue_now.clear();
    if (g.queue.empty()) return "";
    const QueueItem it = g.queue.front();
    g.queue.erase(g.queue.begin());
    save_queue_locked();
    /* As install() does. */
    g.pending_install = it.id;
    g.pending_version = it.version;
    g.pending_root = it.root;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "install";
    g.job.app_id = it.id;
    g.job.step = "Starting";
    g.job.version = it.version;
    g.job.root = it.root;
    g.queue_now = it.id;
    slog("download queue: starting %s (%zu still waiting)", it.id.c_str(), g.queue.size());
    return it.id;
}

std::string Engine::queue_now() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.job.phase != Phase::Idle && g.job.app_id == g.queue_now ? g.queue_now : std::string();
}

bool Engine::cancel_job()
{
    std::lock_guard<std::mutex> l(g.m);
    const bool downloading = g.job.phase == Phase::Working && g.job.kind == "install" &&
                             (g.job.step == "Starting" || g.job.step.rfind("Finding", 0) == 0 || g.job.step.rfind("Downloading", 0) == 0);
    if (!downloading) return false;
    g.cancel_wanted = true;
    slog("install %s: the player cancelled it", g.job.app_id.c_str());
    return true;
}

std::vector<std::string> Engine::auto_update_picks(const std::vector<std::string> &ids, bool all) const
{
    std::vector<App> apps;
    {
        std::lock_guard<std::mutex> l(g.m);
        apps = g.catalog.apps;
    }
    std::vector<std::string> out;
    for (const App &a : apps)
    {
        if (!all && std::find(ids.begin(), ids.end(), a.id) == ids.end()) continue;
        const std::string v = installed(a.id);
        if (v.empty() || v == "?" || store_too_old(a)) continue;
        const std::string h = held(a.id);
        if (!h.empty() && version_key(h) == version_key(v)) continue;  /* kept at an older one */
        if (a.beta && a.sig.empty()) continue;                            /* a beta without a signature */
        std::string want = newest_version(a);
        if (want.empty() || (!a.version.empty() && version_newer(a.version, want))) want = a.version;
        if (want.empty() || version_key(v) == version_key(want) || version_newer(v, want)) continue;
        if (a.source == "github" && !a.beta && !a.custom && !a.repo.empty())
        {
            /* Where it downloads from moved: that's the player's to say, never done on its own. */
            std::string text;
            Json mk;
            if (read_file(app_folder(a.id) + "/" + kMarker, text) && Json::parse(text, mk) && mk.is_object() && mk["app"].str() == a.id)
            {
                const std::string src = mk["source"].str();
                if (src.rfind("github:", 0) == 0 && src.size() > 7 && lower_text(src.substr(7)) != lower_text(a.repo)) continue;
            }
        }
        out.push_back(a.id);
    }
    return out;
}

/* ---- The drives ---- */
namespace
{
void look_drives(bool smp_running)
{
    std::vector<DriveSpace> out;
    std::vector<std::string> dests;
    bool known = false;
    const auto roots = homebrew_roots();
    if (smp_running)
    {
        std::string reply;
        bool answered = false;
        Json j;
        if (smp_api("/api/v1/storage", "{}", reply, &answered) && Json::parse(reply, j) && j["destinations"].is_array())
        {
            known = true;
            for (std::size_t i = 0; i < j["destinations"].size() && i < 32; ++i)
            {
                const Json &d = j["destinations"][i];
                std::string path = d["path"].str();
                while (path.size() > 1 && path.back() == '/') path.pop_back();
                /* A scanned drive root (/mnt/usb0) stands for its homebrew folder. */
                if (std::find(roots.begin(), roots.end(), path) == roots.end() &&
                    std::find(roots.begin(), roots.end(), path + "/homebrew") != roots.end())
                    path += "/homebrew";
                if (std::find(roots.begin(), roots.end(), path) == roots.end() || d["read_only"].boolean(false)) continue;
                if (std::find(dests.begin(), dests.end(), path) != dests.end()) continue;
                dests.push_back(path);
                DriveSpace s;
                s.label = place_name(path);
                s.mount = d["mount_point"].str(drive_of(path));
                s.homebrew = path;
                s.total = std::uint64_t(std::max(0LL, d["total_bytes"].integer(0)));
                s.free = std::uint64_t(std::max(0LL, d["available_bytes"].integer(d["free_bytes"].integer(0))));
                s.from_smp = true;
                out.push_back(s);
            }
        }
        else if (answered)
            slog("ShadowMountPlus didn't give its storage (an older one?): the store looks itself");
    }
    /* The rest: each drive that's there, as the store sees it. */
    for (const std::string &root : roots)
    {
        bool have = false;
        for (const DriveSpace &d : out) have = have || d.homebrew == root;
        const std::string drive = drive_of(root);
        if (have || (root != kHomebrew && !drive_ready(drive))) continue;
        bool same_drive = false;
        for (const DriveSpace &d : out) same_drive = same_drive || drive_of(d.homebrew) == drive;
        if (same_drive) continue;
        /* (2.2.4) The real figures (real_space): Console storage without ShadowMountPlus has
         * none the store can trust, so it says "Size unknown" rather than a wrong bar. */
        DriveSpace s;
        s.label = place_name(root);
        s.mount = drive;
        s.homebrew = root;
        bool smp = false;
        std::uint64_t total = 0, avail = 0;
#ifdef RECOMP_ENGINE_TEST
        if (std::getenv("E222_FREE") && free_bytes(drive, avail)) { total = avail * 4; smp = true; } else
#endif
        if (!real_space(drive, total, avail, smp)) { total = avail = 0; s.sized = false; }
        s.total = total;
        s.free = avail;
        s.from_smp = smp;
        out.push_back(s);
    }
    std::lock_guard<std::mutex> l(g.m);
    g.drives = out;
    g.smp_dests = dests;
    g.smp_dests_known = known;
    ++g.console_rev;
}
} // namespace

unsigned Engine::checks_done() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.games_checks;
}

std::vector<DriveSpace> Engine::drives() const
{
    std::lock_guard<std::mutex> l(g.m);
    return g.drives;
}

/* ---- (2.2.3) What's at the top of each drive, for the Drives view (read-only) ---- */
namespace
{
std::mutex g_dfold_m;
std::map<std::string, std::vector<FolderSize>> g_dfold; /* mount -> its top entries */
std::map<std::string, double> g_dfold_at;               /* mount -> when it was last looked at whole */
std::set<std::string> g_dfold_want;                     /* mounts asked for */
std::set<std::string> g_dfold_looking;
bool g_dfold_started = false;

bool drive_mount_ok(const std::string &m)
{
    if (m == "/data") return true;
    for (const char *p : {"/mnt/ext", "/mnt/usb"})
        if (m.rfind(p, 0) == 0 && m.size() == std::strlen(p) + 1 && m.back() >= '0' && m.back() <= '9') return true;
    return false;
}

void *dfold_main(void *)
{
    for (;;)
    {
        std::string mount;
        {
            std::lock_guard<std::mutex> l(g_dfold_m);
            for (const std::string &m : g_dfold_want)
                if (!g_dfold_at.count(m) || mono_s() - g_dfold_at[m] > 60) { mount = m; break; }
            if (!mount.empty()) g_dfold_looking.insert(mount);
        }
        if (mount.empty()) { usleep(250000); continue; }
        std::vector<FolderSize> top;
        if (DIR *d = opendir(mount.c_str()))
        {
            while (dirent *x = readdir(d))
            {
                if (x->d_name[0] == '.' || top.size() >= 64) continue;
                FolderSize f;
                f.name = x->d_name;
                f.path = mount + "/" + f.name;
                struct stat st;
                if (stat(f.path.c_str(), &st) != 0) continue;
                f.dir = S_ISDIR(st.st_mode);
                if (!f.dir) f.size = (long long)st.st_size;
                top.push_back(f);
            }
            closedir(d);
        }
        std::sort(top.begin(), top.end(), [](const FolderSize &a, const FolderSize &b) { return a.name < b.name; });
        {
            std::lock_guard<std::mutex> l(g_dfold_m);
            g_dfold[mount] = top; /* the names now; the sizes as they come */
        }
        for (std::size_t i = 0; i < top.size(); ++i)
        {
            if (!top[i].dir) continue;
            std::uint64_t bytes = 0;
            std::size_t files = 0;
            fs::tree_size(top[i].path, bytes, files);
            sched_yield();
            std::lock_guard<std::mutex> l(g_dfold_m);
            auto &v = g_dfold[mount];
            for (FolderSize &f : v)
                if (f.path == top[i].path) f.size = (long long)bytes;
        }
        std::lock_guard<std::mutex> l(g_dfold_m);
        g_dfold_at[mount] = mono_s();
        g_dfold_looking.erase(mount);
    }
    return nullptr;
}
} // namespace

std::vector<FolderSize> Engine::drive_folders(const std::string &mount, bool *looking) const
{
    if (!drive_mount_ok(mount)) return {};
    std::lock_guard<std::mutex> l(g_dfold_m);
    g_dfold_want.insert(mount);
    if (!g_dfold_started)
    {
        pthread_t t;
        g_dfold_started = create_title_thread(&t, dfold_main, nullptr) == 0;
        if (g_dfold_started) pthread_detach(t);
    }
    if (looking) *looking = g_dfold_looking.count(mount) || !g_dfold_at.count(mount);
    auto it = g_dfold.find(mount);
    return it == g_dfold.end() ? std::vector<FolderSize>() : it->second;
}

/* ---- The download cache ---- */
/* (2.2.3) The quick local looks, on a thread of their own so the screen never waits on a disk
 * that's busy with a download or an unpack: the USB drives every 2 s, the cache when asked. */
namespace
{
std::atomic<unsigned> g_usb_mask{0};
std::atomic<bool> g_usb_known{false};
std::atomic<bool> g_cache_want{true};
std::atomic<unsigned long long> g_cache_b{0}, g_cache_n{0};
std::atomic<long long> g_cache_ms{-1000000}; /* when it was asked for last (mono ms) */
std::atomic<bool> g_local_started{false};

void *local_main(void *)
{
    double usb_t = -10;
    for (;;)
    {
        if (g_cache_want.exchange(false))
        {
            std::size_t n = 0;
            const std::uint64_t b = Engine::cache_bytes_now(&n);
            g_cache_b = b;
            g_cache_n = n;
        }
        if (mono_s() - usb_t >= 2)
        {
            usb_t = mono_s();
            unsigned mask = 0;
            for (int i = 0; i < 8; ++i)
            {
                const std::string m = "/mnt/usb" + std::to_string(i);
                struct stat st;
                DIR *d = stat(m.c_str(), &st) == 0 && S_ISDIR(st.st_mode) ? opendir(m.c_str()) : nullptr;
                bool any = false;
                if (d)
                {
                    while (dirent *x = readdir(d))
                        if (x->d_name[0] != '.') { any = true; break; }
                    closedir(d);
                }
                if (any) mask |= 1u << i;
            }
            g_usb_mask = mask;
            g_usb_known = true;
        }
        usleep(200000);
    }
    return nullptr;
}

void start_local()
{
    bool was = false;
    if (!g_local_started.compare_exchange_strong(was, true)) return;
    pthread_t t;
    if (create_title_thread(&t, local_main, nullptr) == 0)
        pthread_detach(t);
    else
        g_local_started = false;
}
} // namespace

std::uint64_t Engine::cache_seen(std::size_t *files, bool fresh) const
{
    start_local();
    const long long now = (long long)(mono_s() * 1000);
    if (fresh || now - g_cache_ms.load() > 3000)
    {
        g_cache_ms = now;
        g_cache_want = true;
    }
    if (files) *files = std::size_t(g_cache_n.load());
    return g_cache_b.load();
}

unsigned Engine::usb_seen(bool *known) const
{
    start_local();
    if (known) *known = g_usb_known.load();
    return g_usb_mask.load();
}

std::uint64_t Engine::cache_bytes(std::size_t *files) const
{
    return cache_bytes_now(files);
}

std::uint64_t Engine::cache_bytes_now(std::size_t *files)
{
    std::string dir;
    {
        std::lock_guard<std::mutex> l(g.m);
        dir = g.data + "/downloads";
    }
    std::uint64_t total = 0;
    std::size_t n = 0;
    if (DIR *d = opendir(dir.c_str()))
    {
        while (dirent *e = readdir(d))
        {
            if (e->d_name[0] == '.') continue;
            struct stat st;
            if (stat((dir + "/" + e->d_name).c_str(), &st) == 0 && S_ISREG(st.st_mode))
            {
                total += std::uint64_t(st.st_size);
                ++n;
            }
        }
        closedir(d);
    }
    if (files) *files = n;
    return total;
}

/* (2.3.0) The cache cleared, telling progress (files looked at, of all) as it goes: on a thread of
 * its own from Settings > Storage (Engine::clear_downloads_start). */
bool clear_cache_now(std::uint64_t &freed, std::size_t &files, std::string &why, const std::function<void(std::size_t, std::size_t)> &progress)
{
    freed = 0;
    files = 0;
    why.clear();
    /* What's in use: the job's app (an install's download, a clash's kept one), what the queue is
     * waiting to install, the store's own update while it's downloaded or waiting for its
     * installer, and ShadowMountPlus while it's fetched. */
    std::set<std::string> busy;
    bool store_busy = false, smp_busy = false, mod_job = false;
    std::string dir;
    {
        std::lock_guard<std::mutex> l(g.m);
        dir = g.data + "/downloads";
        if (g.job.phase != Phase::Idle && !g.job.app_id.empty()) busy.insert(g.job.app_id);
        if (!g.pending_install.empty()) busy.insert(g.pending_install);
        for (const QueueItem &q : g.queue) busy.insert(q.id);
        store_busy = !g.store_zip.empty() || g.pending_store_dl || (g.job.phase == Phase::Working && g.job.kind == "storeupdate");
        smp_busy = g.pending_smpget || (g.job.phase == Phase::Working && g.job.kind == "smpget");
        mod_job = !g.pending_task.empty() || (g.job.phase == Phase::Working && g.job.kind == "mod");
    }
    std::vector<std::string> names;
    if (DIR *d = opendir(dir.c_str()))
    {
        while (dirent *e = readdir(d))
            if (e->d_name[0] != '.' && names.size() < 4000) names.push_back(e->d_name);
        closedir(d);
    }
    std::size_t kept = 0, stuck = 0, looked = 0;
    for (const std::string &n : names)
    {
        if (progress) progress(looked, names.size());
        ++looked;
        /* The app a file is for: its name up to the first dot ("PPSA64007.inner.zip"). */
        const std::string id = n.substr(0, n.find('.'));
        const bool smp_file = n.rfind("smp-", 0) == 0;
        /* (2.3.0) A mod being downloaded: "mod-<app>-<mod>.part". */
        const bool mod_busy = n.rfind("mod-", 0) == 0 && mod_job;
        if (busy.count(id) || (id == "PPSA92000" && store_busy) || (smp_file && smp_busy) || mod_busy || n.find('/') != std::string::npos)
        {
            slog("download cache: kept %s (in use)", n.c_str());
            ++kept;
            continue;
        }
        const std::string path = dir + "/" + n;
        struct stat st;
        if (stat(path.c_str(), &st) != 0 || !S_ISREG(st.st_mode)) continue; /* only its files, never a folder */
        std::remove(path.c_str());
        struct stat after;
        if (stat(path.c_str(), &after) == 0)
        {
            slog("download cache: %s is still there afterwards", n.c_str());
            ++stuck;
            continue;
        }
        freed += std::uint64_t(st.st_size);
        ++files;
    }
    slog("download cache: cleared %zu file(s), %llu bytes; %zu kept (in use); %zu couldn't be deleted", files, (unsigned long long)freed,
         kept, stuck);
    if (stuck) why = std::to_string(stuck) + (stuck == 1 ? " file couldn't be deleted." : " files couldn't be deleted.");
    else if (!files && kept) why = "Everything in it is in use right now.";
    if (progress) progress(names.size(), names.size());
    return stuck == 0;
}

bool Engine::clear_downloads(std::uint64_t &freed, std::size_t &files, std::string &why)
{
    return clear_cache_now(freed, files, why, {});
}

/* ---- Deleting several at once ---- */
bool Engine::manage_many(const std::vector<std::string> &folders)
{
    std::vector<std::string> list;
    for (const std::string &f : folders)
    {
        const std::string leaf = f.substr(f.find_last_of('/') + 1);
        if (leaf == "PPSA92000") { slog("manage delete: %s refused: that's Recomp Store itself", f.c_str()); continue; }
        if (std::find(list.begin(), list.end(), f) == list.end() && list.size() < 64) list.push_back(f);
    }
    if (list.empty()) return false;
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty() || !g.pending_manage.empty() || !g.pending_mdeletes.empty())
        return false;
    g.pending_mdeletes = list;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "mdeletes";
    g.job.step = "Deleting";
    g.job.total = list.size();
    return true;
}

void run_manage_many(const std::vector<std::string> &folders)
{
    std::vector<std::pair<std::string, std::string>> installed;
    {
        std::vector<std::string> ids;
        {
            std::lock_guard<std::mutex> l(g.m);
            for (const App &a : g.catalog.apps)
                if (exists(g.data + "/installed/" + a.id + ".txt")) ids.push_back(a.id);
        }
        for (const std::string &id : ids) installed.push_back({id, app_folder(id)});
    }
    slog("manage delete: %zu folders at once", folders.size());
    std::size_t ok = 0;
    int home = 0;
    std::string failed, first_why;
    for (std::size_t i = 0; i < folders.size(); ++i)
    {
        const std::string &folder = folders[i];
        std::string tid, name;
        folder_app(folder, tid, name);
        {
            std::lock_guard<std::mutex> l(g.m);
            g.job.phase = Phase::Working;
            g.job.error.clear();
            g.job.step = "Deleting";
            g.job.batch = std::to_string(i + 1) + " of " + std::to_string(folders.size()) + ": " + name;
            g.job.done = i;
            g.job.total = folders.size();
        }
        std::string root, leaf, why;
        if (!manageable(folder, root, leaf, why))
        {
            slog("manage delete %s: refused: %s", folder.c_str(), why.c_str());
            failed += (failed.empty() ? "" : ", ") + name;
            if (first_why.empty()) first_why = why;
            continue;
        }
        /* The store's own install goes as from its page (its records, its game files). */
        const std::string app = store_app_of(folder, installed);
        if (!app.empty() && app_folder(app) == folder) run_delete(app, false);
        else run_manage_delete(folder);
        std::lock_guard<std::mutex> l(g.m);
        if (g.job.phase == Phase::Done)
        {
            ++ok;
            home = std::max(home, g.job.home);
        }
        else
        {
            failed += (failed.empty() ? "" : ", ") + name;
            if (first_why.empty()) first_why = g.job.error;
        }
    }
    scan_homebrew();
    slog("manage delete: %zu of %zu deleted%s%s", ok, folders.size(), failed.empty() ? "" : "; not: ", failed.c_str());
    std::lock_guard<std::mutex> l(g.m);
    g.console_again = true;
    g.job.done = g.job.total = folders.size();
    g.job.home = home;
    if (ok == 0)
    {
        g.job.phase = Phase::Failed;
        g.job.error = folders.size() == 1 ? first_why : "Nothing was deleted. " + first_why;
        return;
    }
    g.job.phase = Phase::Done;
    g.job.step = "Deleted";
    if (failed.empty())
        g.job.note = (ok == 1 ? std::string("Deleted 1 app.") : "Deleted " + std::to_string(ok) + " apps.") +
                     (home == 3 ? " Their tiles come off your home screen once the store closes." : "");
    else
        g.job.note = "Deleted " + std::to_string(ok) + " of " + std::to_string(folders.size()) + ". Not deleted: " + failed + ". " + first_why;
}

/* ---- Get ShadowMountPlus ---- */
bool Engine::get_smp()
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.catalog.smp_url.empty()) return false;
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty() || !g.pending_manage.empty() || !g.pending_mdeletes.empty() || g.pending_smpget)
        return false;
    g.pending_smpget = true;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "smpget";
    g.job.step = "Downloading ShadowMountPlus";
    return true;
}

/* Only drakmor's own releases on GitHub: https://github.com/drakmor/ShadowMountPlus/releases/download/<tag>/<asset>. */
static bool smp_url_ok(const std::string &url)
{
    const std::string pre = "https://github.com/drakmor/ShadowMountPlus/releases/download/";
    if (url.rfind(pre, 0) != 0) return false;
    const std::string rest = url.substr(pre.size());
    const std::size_t slash = rest.find('/');
    if (slash == std::string::npos || slash == 0 || slash + 1 >= rest.size() || rest.find('/', slash + 1) != std::string::npos) return false;
    if (rest.find("..") != std::string::npos) return false;
    for (const char c : rest)
        if (!(std::isalnum(static_cast<unsigned char>(c)) || c == '.' || c == '_' || c == '-' || c == '/')) return false;
    return true;
}

void run_get_smp()
{
    std::string version, url, elf, want_sha, data;
    std::uint64_t want_size = 0;
    {
        std::lock_guard<std::mutex> l(g.m);
        version = g.catalog.smp_version;
        url = g.catalog.smp_url;
        elf = g.catalog.smp_elf;
        want_sha = g.catalog.smp_sha256;
        want_size = g.catalog.smp_size;
        data = g.data;
    }
    for (char &c : want_sha) c = char(std::tolower(static_cast<unsigned char>(c)));
    slog("ShadowMountPlus %s: getting %s%s%s", version.c_str(), url.c_str(), elf.empty() ? "" : ", payload ", elf.c_str());
    if (!smp_url_ok(url))
    {
        slog("ShadowMountPlus: refused %s: only drakmor's GitHub releases", url.c_str());
        return fail_job("That download isn't from ShadowMountPlus's own GitHub releases, so the store won't get it.");
    }
    if (want_sha.size() != 64 || want_size < 64 || want_size > (64ull << 20) || elf.find("..") != std::string::npos)
        return fail_job("The store's listing for ShadowMountPlus isn't complete. Try again after the site is updated.");
    porpoise::http::Session http("smp-get", "RecompStore/2.2");
    if (!http.init()) return fail_job("Your PS5 isn't online.");
    make_dirs(data + "/downloads");
    make_dirs(data + "/payloads");
    const std::string asset = url.substr(url.find_last_of('/') + 1);
    const std::string dl = data + "/downloads/smp-" + asset;
    struct Gone
    {
        std::string path;
        ~Gone() { if (!path.empty()) std::remove(path.c_str()); }
    } dl_gone{dl};
    std::FILE *out = std::fopen(dl.c_str(), "wb");
    if (!out) return fail_job("The store couldn't save the download on your PS5.");
    std::uint64_t got = 0;
    bool write_ok = true;
    const std::uint64_t most = 64ull << 20;
    set_job("Downloading ShadowMountPlus", 0, elf.empty() ? want_size : 0);
    const int status = http.get_stream(url, [&](const std::uint8_t *p, std::size_t n) {
        if (got + n > most) { write_ok = false; return false; }
        if (std::fwrite(p, 1, n, out) != n) { write_ok = false; return false; }
        got += n;
        set_job("Downloading ShadowMountPlus", got, elf.empty() ? want_size : 0);
        return true;
    });
    std::fclose(out);
    slog("ShadowMountPlus: HTTP %d, %llu bytes", status, (unsigned long long)got);
    if (!write_ok || status != 200 || got == 0) return fail_job("The download didn't finish. Check your connection and try again.");
    /* The payload: the download itself, or taken out of the release's zip. */
    const std::string final_path = data + "/payloads/shadowmountplus.elf", fresh = final_path + ".new";
    std::remove(fresh.c_str());
    struct Gone fresh_gone{fresh};
    if (elf.empty())
    {
        if (std::rename(dl.c_str(), fresh.c_str()) != 0) return fail_job("The store couldn't save ShadowMountPlus on your PS5.");
    }
    else
    {
        set_job("Taking out the payload", 0, 0);
        std::string name, why;
        const std::string want_name = lower_text(elf);
        if (!zip::take_inner(dl, [&](const std::string &n) { return lower_text(n) == want_name; }, fresh,
                             [](const char *, std::uint64_t, std::uint64_t) {}, name, why))
        {
            slog("ShadowMountPlus: taking %s out of %s failed: %s", elf.c_str(), asset.c_str(), why.c_str());
            return fail_job("The release doesn't have " + elf + " where the store's listing says. Try again after the site is updated.");
        }
    }
    /* Checked against the listing before it's kept: its size, its SHA-256, an ELF. */
    std::string bytes;
    set_job("Checking ShadowMountPlus", 0, 0);
    if (!read_file(fresh, bytes) || bytes.size() != want_size)
    {
        slog("ShadowMountPlus: the payload is %zu bytes, the listing says %llu", bytes.size(), (unsigned long long)want_size);
        return fail_job("ShadowMountPlus doesn't match the store's listing (its size), so it wasn't saved.");
    }
    const std::string got_sha = sha256_hex(bytes);
    if (got_sha != want_sha || bytes.compare(0, 4, "\x7F" "ELF") != 0)
    {
        slog("ShadowMountPlus: SHA-256 %s, the listing says %s", got_sha.c_str(), want_sha.c_str());
        return fail_job("ShadowMountPlus doesn't match the store's listing (its checksum), so it wasn't saved.");
    }
    if (std::rename(fresh.c_str(), final_path.c_str()) != 0) return fail_job("The store couldn't save ShadowMountPlus on your PS5.");
    fresh_gone.path.clear();
    std::string back;
    if (!read_file(final_path, back) || sha256_hex(back) != want_sha)
    {
        slog("ShadowMountPlus: %s doesn't read back right", final_path.c_str());
        return fail_job("ShadowMountPlus didn't save properly. Try again.");
    }
    set_smp_path(final_path);
    slog("ShadowMountPlus %s saved as %s (%zu bytes, SHA-256 checked) and chosen", version.c_str(), final_path.c_str(), back.size());
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.step = "Saved";
    g.job.installed_version = version;
    g.job.note = "ShadowMountPlus " + version + " is saved and chosen.";
}

Engine &engine()
{
    static Engine e;
    return e;
}
/* A GameCube or Wii disc image's ID (GZLE01): the first six bytes of an .iso or .gcm, or
 * of the disc inside a .ciso (after its 32 KB header), with the disc's magic word there
 * to prove it. "" for anything else. */
std::string disc_image_id(const std::string &path)
{
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return "";
    unsigned char h[0x20] = {};
    std::size_t n = std::fread(h, 1, sizeof h, f);
    if (n >= 4 && std::memcmp(h, "CISO", 4) == 0)
        n = std::fseek(f, 0x8000, SEEK_SET) == 0 ? std::fread(h, 1, sizeof h, f) : 0;
    std::fclose(f);
    if (n < sizeof h) return "";
    auto be32 = [&](int at) {
        return std::uint32_t(h[at]) << 24 | std::uint32_t(h[at + 1]) << 16 | std::uint32_t(h[at + 2]) << 8 | h[at + 3];
    };
    if (be32(0x1C) != 0xC2339F3Du && be32(0x18) != 0x5D1C9EA3u) return "";
    std::string id(reinterpret_cast<const char *>(h), 6);
    for (char c : id)
        if (!std::isalnum(static_cast<unsigned char>(c))) return "";
    return id;
}
} // namespace recomp

/* (2.3.0) Upload from your phone, save backups, the phone's requests, likes, Restore my apps, mods. */
#include "store_engine_23.inc"
