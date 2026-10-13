/* Recomp Store - the catalog, downloads and installs.
 * The zip reading and SHA-256 come from Porpoise's updater (porpoise_update.cpp).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_engine.hpp"
#include "store_fs.hpp"

#include "porpoise_http.hpp"
#include "store_disc.hpp"
#include "store_json.hpp"
#include "store_zip.hpp"
#include "store_stfs.hpp"
#include "store_sha256.hpp"
#include "title_threads.hpp"
#include "ui_app_common.hpp"
#include "porpoise_paths.hpp"

#include <algorithm>
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

bool write_file(const std::string &path, const void *data, std::size_t n)
{
    const std::string tmp = path + ".part";
    std::FILE *f = std::fopen(tmp.c_str(), "wb");
    if (!f) return false;
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
    /* "v1.0", "1.0" and "1.0.0" are the same version. */
    if (!v.empty() && (v[0] == 'v' || v[0] == 'V'))
        v.erase(0, 1);
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
    unsigned revision = 0;
    JobState job;
    std::string pending_install; /* asked for, not yet started */
    std::string pending_setup_app, pending_setup_slot, pending_setup_image;
    bool pending_bench = false;
    std::string pending_delete;
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
    int update_check = 0;
    std::string store_zip, store_zip_sha, store_zip_ver;
    /* Patch notes by repo, and when each was last asked of GitHub (mono seconds). */
    std::map<std::string, std::vector<Release>> notes;
    std::map<std::string, double> notes_at;
    std::set<std::string> notes_failed, notes_wanted;
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

void set_stat(const std::string &key, const std::string &value)
{
    LogState &L = logs();
    std::lock_guard<std::mutex> lk(L.m);
    L.stats[key] = value;
}

namespace
{

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
void load_cached()
{
    std::string text;
    Catalog c;
    if (read_file(g.data + "/catalog.json", text) && parse_catalog(text, c))
    {
        std::lock_guard<std::mutex> l(g.m);
        g.catalog = c;
        g.have_catalog = true;
        ++g.revision;
    }
}

void attach_art(Catalog &c)
{
    for (App &a : c.apps)
    {
        if (!a.icon_url.empty() && exists(g.data + "/art/" + art_name(a.icon_url)))
            a.icon_file = g.data + "/art/" + art_name(a.icon_url);
        if (!a.hero_url.empty() && exists(g.data + "/art/" + art_name(a.hero_url)))
            a.hero_file = g.data + "/art/" + art_name(a.hero_url);
        if (!a.logo_url.empty() && exists(g.data + "/art/" + art_name(a.logo_url)))
            a.logo_file = g.data + "/art/" + art_name(a.logo_url);
    }
}

void refresh_catalog(porpoise::http::Session &http)
{
    std::vector<std::uint8_t> body;
    const int status = http.get(kCatalogUrl, body, {}, 4u << 20);
    Catalog c;
    const std::string text(body.begin(), body.end());
    if (status == 200 && parse_catalog(text, c))
    {
        write_file(g.data + "/catalog.json", text.data(), text.size());
        attach_art(c);
        std::lock_guard<std::mutex> l(g.m);
        g.catalog = c;
        g.have_catalog = true;
        g.online = true;
        ++g.revision;
    }
    slog("catalog: HTTP %d, %zu bytes, %zu games\n", status, body.size(), c.apps.size());
}

void fetch_art(porpoise::http::Session &http)
{
    Catalog c;
    {
        std::lock_guard<std::mutex> l(g.m);
        c = g.catalog;
    }
    make_dirs(g.data + "/art");
    for (const App &a : c.apps)
        for (const std::string *u : {&a.icon_url, &a.hero_url, &a.logo_url})
        {
            if (u->empty()) continue;
            const std::string path = g.data + "/art/" + art_name(*u);
            if (exists(path)) continue;
            std::vector<std::uint8_t> body;
            const int status = http.get(*u, body, {}, 32u << 20);
            const bool saved = status == 200 && !body.empty() && write_file(path, body.data(), body.size());
            slog("art %s: HTTP %d, %zu bytes%s\n", u->c_str(), status, body.size(),
                         saved ? "" : " (not saved)");
            if (saved)
            {
                std::lock_guard<std::mutex> l(g.m);
                attach_art(g.catalog);
                ++g.revision;
            }
        }
}

/* ---- Installing --------------------------------------------------------------------- */
struct Target
{
    std::string url, sha256, version;
    std::uint64_t size = 0;
};

void remember_releases(const std::string &repo, const std::string &text);

bool resolve(porpoise::http::Session &http, const App &a, Target &t, std::string &err)
{
    if (a.source != "github")
    {
        t.url = a.url;
        t.sha256 = a.sha256;
        t.version = a.version;
        if (t.url.empty()) { err = "This game has no download link yet."; return false; }
        return true;
    }
    /* The newest release that has the game's zip. Pre-releases count: many ports ship as
     * one before they call a version final. */
    std::vector<std::uint8_t> body;
    const int status = http.get("https://api.github.com/repos/" + a.repo + "/releases?per_page=30", body, {}, 8u << 20);
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
                pick = &list[r];
                t.url = assets[i]["browser_download_url"].str();
                t.size = static_cast<std::uint64_t>(assets[i]["size"].integer());
                const std::string digest = assets[i]["digest"].str();
                if (digest.rfind("sha256:", 0) == 0) t.sha256 = digest.substr(7);
                break;
            }
    }
    if (!pick)
    {
        err = list.size() == 0 ? "This game has no release on GitHub yet."
                               : "None of this game's GitHub releases has " + a.asset + ".";
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

enum class Copier : int { Done, Failed, Unavailable };
Copier run_copier(const std::string &kind, const std::string &image, const std::string &target, bool *changed = nullptr,
                  const std::string &extra = "");

void run_install(const std::string &id)
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
    }
    if (!found) return fail_job("That game isn't in the catalog.");
    if (store_too_old(a))
        return fail_job(a.name + " needs Recomp Store " + a.min_store + " or newer. Update the store from Latest Updates first.");
    if (!http.init()) return fail_job("Your PS5 isn't online.");

    Target t;
    std::string err;
    if (!resolve(http, a, t, err)) return fail_job(err);

    /* Room for the zip and its unpacked files on disk, with some to spare. */
    struct statvfs fs;
    if (statvfs("/data", &fs) == 0 && t.size && std::uint64_t(fs.f_bavail) * fs.f_frsize < t.size * 3 + (512ull << 20))
        return fail_job("There isn't enough free space on your PS5.");

    /* Download to the store's own folder, checking the checksum as it arrives. */
    make_dirs(g.data + "/downloads");
    const std::string zip_path = g.data + "/downloads/" + a.id + ".zip";
    std::remove(zip_path.c_str());
    std::FILE *out = std::fopen(zip_path.c_str(), "wb");
    if (!out) return fail_job("The store couldn't save the download on your PS5.");
    Sha256 sha;
    std::uint64_t got = 0;
    bool write_ok = true;
    set_job("Downloading", 0, t.size);
    timespec dl0;
    clock_gettime(CLOCK_MONOTONIC, &dl0);
    const int status = http.get_stream(t.url, [&](const std::uint8_t *p, std::size_t n) {
        if (std::fwrite(p, 1, n, out) != n) { write_ok = false; return false; }
        sha.update(p, n);
        got += n;
        set_job("Downloading", got, t.size);
        return true;
    });
    std::fclose(out);
    {
        timespec dl1;
        clock_gettime(CLOCK_MONOTONIC, &dl1);
        const double secs = double(dl1.tv_sec - dl0.tv_sec) + double(dl1.tv_nsec - dl0.tv_nsec) / 1e9;
        slog("download %s: HTTP %d, %llu bytes (GitHub says %llu) in %.0f s, %.1f MB/s", t.url.c_str(), status,
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
    } zf{zip_path};
    if (!write_ok) return fail_job("Your PS5 ran out of room while downloading.");
    if (status != 200 || got == 0 || (t.size && got != t.size))
        return fail_job("The download didn't finish. Check your connection and try again.");

    set_job("Checking the download", got, got);
    if (!t.sha256.empty())
    {
        std::string want = t.sha256;
        for (char &c : want) c = char(std::tolower(static_cast<unsigned char>(c)));
        if (sha.hex() != want) return fail_job("The download doesn't match its checksum, so it wasn't installed.");
    }
    /* A release that only has a bigger zip (a source archive): the game's own zip is taken
     * out of it, the big one deleted, and the game's zip installed as any other. */
    std::string install_zip = zip_path;
    struct InnerFile
    {
        std::string path;
        ~InnerFile() { if (!path.empty()) std::remove(path.c_str()); }
    } inner;
    if (!a.inside.empty())
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

    /* Unpacking: by the copier payload at the drive's full speed (the PS5 holds an app's
     * writes back after about a gigabyte), or here when there's no payload loader. */
    const std::string root = app_folder(a.id); /* where it already is, if it's on the console */
    const std::string record = g.data + "/installed/" + a.id + ".txt";
    std::size_t nfiles = 0;
    {
        bool changed = false;
        const Copier c = run_copier("unzip", install_zip, root, &changed);
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
                    [](const std::string &l) { slog("%s", l.c_str()); }, nullptr, error, changed, nfiles))
            {
                if (changed) std::remove(record.c_str());
                return fail_job(error);
            }
        }
        else
        {
            /* The store checks the copier's work itself: every file read back against the zip. */
            std::vector<zip::Entry> entries;
            const int zfd = open(install_zip.c_str(), O_RDONLY);
            const bool listed = zfd >= 0 && zip::list(zfd, entries);
            if (zfd >= 0) close(zfd);
            if (!listed)
            {
                std::remove(record.c_str());
                return fail_job("The store couldn't read the download back to check the install. Install it again.");
            }
            const std::string strip = zip::top_folder(entries);
            std::uint64_t i = 0;
            for (const zip::Entry &e : entries)
            {
                set_job("Checking the installed files", ++i, entries.size());
                const std::string name = e.name.substr(strip.size());
                if (name.empty() || name.back() == '/') continue;
                std::string why;
                if (!zip::safe_name(name) || !zip::file_matches(root + "/" + name, e.usize, e.crc, &why))
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

    make_dirs(g.data + "/installed");
    const std::string ver = t.version.empty() ? a.version : t.version;
    write_file(g.data + "/installed/" + a.id + ".txt", ver.data(), ver.size());
    slog("installed %s %s: %zu files in %s", a.id.c_str(), ver.c_str(), nfiles, root.c_str());
    scan_homebrew();
    std::string smp_why;
    const int home = refresh_smp(smp_why);
    if (home == 0) slog("install: ShadowMountPlus: %s", smp_why.c_str());
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.home = home;
    g.job.step = "Installed";
    g.job.installed_version = ver;
    g.job.done = g.job.total;
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
    const bool unzip = kind == "unzip", del = kind == "delete";
    const char *what = unzip ? "install" : del ? "delete" : "setup";
    const char *fallback = unzip ? "installing in the store" : del ? "deleting in the store" : "copying in the store";
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
        if (exists(jd + "/running") && stat((jd + "/status.txt").c_str(), &st) == 0 && std::time(nullptr) - st.st_mtime < 10)
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
                set_job("Copying the game's files", done, total);
            else if (phase == "installing")
                set_job("Installing", done, total);
            else if (phase == "deleting")
                set_job("Deleting", 0, 0);
            else if (phase == "checking")
                set_job(unzip ? "Checking the installed files" : "Checking the copied files", unzip ? done : total, total);
            else if (phase == "done")
            {
                const double secs = std::strtod(field_of(st, "seconds").c_str(), nullptr);
                if (unzip || del)
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
            else if (!unzip && !del)
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
                     : del ? "The copier stopped answering. Press Delete again to finish."
                           : "The copier stopped answering. Set up again to carry on where it stopped.");
            return Copier::Failed;
        }
    }
}

/* A Title Update (one package) or DLC (every package of the game's in the chosen file's
 * folder), unpacked where the port reads them: the update's files into the slot's folder,
 * each piece of DLC into a folder of its own named after it. By the copier payload at the
 * drive's full speed, or here when there's no loader. */
void run_setup_stfs(const App &a, const Slot &slot, const std::string &image)
{
    const std::string id = a.id;
    stfs::Package first;
    std::string err;
    if (!first.open(image, err)) return fail_job(err);
    const bool several = slot.multiple;
    char tid[16];
    std::snprintf(tid, sizeof tid, "%08X", first.title_id());
    slog("setup %s: %s is a package for title %s, type %08X, \"%s\", %zu files", id.c_str(), image.c_str(), tid,
         first.content_type(), first.display_name().c_str(), first.entries().size());
    if (!slot.title_id.empty() && std::strtoul(slot.title_id.c_str(), nullptr, 16) != first.title_id())
        return fail_job(std::string("This package is for a different game (title ID ") + tid + ").");
    if (!several && first.content_type() != stfs::kTitleUpdate)
        return fail_job("That isn't a Title Update. It's the file whose name starts with TU_.");
    if (several && first.content_type() != stfs::kMarketplace)
        return fail_job("That isn't DLC. Choose one of the game's downloadable content files.");

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
    g.job.step = several ? std::to_string(work.size()) + (work.size() == 1 ? " pack is ready" : " packs are ready")
                         : "The Title Update is ready";
    g.job.note = several ? (work.size() == 1 ? "Your DLC is set up: 1 package." : "Your DLC is set up: " + std::to_string(work.size()) + " packages.")
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
    struct statvfs fs;
    if (statvfs(target.c_str(), &fs) == 0 && std::uint64_t(fs.f_bavail) * fs.f_frsize < size + (64ull << 20))
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

void run_setup(const std::string &id, const std::string &slot_key, const std::string &image)
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
    set_job(slot.kind == "stfs" ? "Reading the package" : "Reading the disc image", 0, 0);
    if (!found) return fail_job("This game doesn't take that file.");
    if (store_too_old(a))
        return fail_job(a.name + " needs Recomp Store " + a.min_store + " or newer. Update the store from Latest Updates first.");
    if (slot.kind == "stfs") return run_setup_stfs(a, slot, image);
    if (slot.kind == "image") return run_setup_image(a, slot, image);
    if (slot.kind != "iso") return fail_job("Setting up " + slot.label + " isn't something this version of the store can do.");

    Disc disc;
    std::string err;
    if (!disc.open(image, err)) return fail_job(err);
    if (!disc.find("default.xex"))
        return fail_job("This disc image has no default.xex: it isn't a game disc, or it's the wrong one.");
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

    struct statvfs fs;
    if (statvfs(target.c_str(), &fs) == 0 && std::uint64_t(fs.f_bavail) * fs.f_frsize < needed + (256ull << 20))
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
        make_dirs(g.data + "/setup");
        write_file(g.data + "/setup/" + id + "." + slot_key + ".txt", image.data(), image.size());
        slog("setup %s: done", id.c_str());
        std::string smp_why;
        const int home = refresh_smp(smp_why);
        if (home == 0) slog("setup: ShadowMountPlus: %s", smp_why.c_str());
        std::lock_guard<std::mutex> l(g.m);
        g.job.phase = Phase::Done;
        g.job.home = home;
        g.job.step = "Game files are ready";
        g.job.done = g.job.total;
    };
    if (needed > 0)
    {
        const Copier c = run_copier("setup", image, target);
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
    struct statvfs fs;
    if (statvfs(dir.c_str(), &fs) == 0 && std::uint64_t(fs.f_bavail) * fs.f_frsize < (1ull << 30))
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
void run_delete(const std::string &id)
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
    for (const std::string &p : paths) slog("delete %s: %s", id.c_str(), p.c_str());
    /* ShadowMountPlus lets go of the game first, if it will (it won't while the store is
     * the app on screen; deleting works anyway). */
    unmount_smp(id);
    const double t0 = mono_s();
    std::string extra;
    for (std::size_t i = 0; i < paths.size(); ++i) extra += "path" + std::to_string(i) + "=" + paths[i] + "\n";
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
            if (!fs::remove_tree(paths[i], n, bad, e, [&](std::size_t so_far) { set_job("Deleting", files + so_far, 0); }))
            {
                slog("delete %s: stopped at %s (errno %d) after %zu files", id.c_str(), bad.c_str(), e, files + n);
                scan_homebrew();
                return fail_job(bad + " couldn't be deleted (error " + std::to_string(e) + "). Close the game if it's running, then try again.");
            }
            files += n;
            slog("delete %s: %s, %zu files", id.c_str(), paths[i].c_str(), n);
            if (!fs::gone(paths[i])) break; /* said below, before the game's own folder goes */
            if (i > 0) fs::remove_empty_parents(paths[i], [&](const std::string &up) { slog("delete %s: removed the empty folder %s", id.c_str(), up.c_str()); });
        }
    }
    /* Checked here too, whoever deleted: still there is a failure (in the order deleted). */
    for (std::size_t k = 0; k < paths.size(); ++k)
        if (const std::string &p = paths[(k + 1) % paths.size()]; !fs::gone(p))
        {
            slog("delete %s: %s is still there afterwards", id.c_str(), p.c_str());
            scan_homebrew();
            return fail_job(p + " is still there. Close the game if it's running, then press Delete again.");
        }
    std::remove((g.data + "/installed/" + id + ".txt").c_str());
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
    const bool tile_gone = !another && forget_smp(id); /* the folder first, so a scan can't add it back */
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.home = tile_gone ? 2 : 0;
    g.job.step = "Deleted";
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
            for (std::size_t k = 0; k < r["assets"].size(); ++k)
                if (r["assets"][k]["name"].str() == "PPSA92000.zip") { newest = r["tag_name"].str(); break; }
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
    std::string url, digest, tag;
    std::uint64_t size = 0;
    if (status == 200 && Json::parse(std::string(body.begin(), body.end()), j) && j.is_array())
        for (std::size_t i = 0; i < j.size() && url.empty(); ++i)
        {
            const Json &r = j[i];
            if (r["draft"].boolean(false) || r["prerelease"].boolean(false)) continue;
            for (std::size_t k = 0; k < r["assets"].size(); ++k)
                if (r["assets"][k]["name"].str() == "PPSA92000.zip")
                {
                    url = r["assets"][k]["browser_download_url"].str();
                    size = std::uint64_t(r["assets"][k]["size"].integer());
                    digest = r["assets"][k]["digest"].str();
                    tag = r["tag_name"].str();
                    break;
                }
        }
    if (url.rfind("https://", 0) != 0)
        return fail_job(status == 200 ? "There's no Recomp Store release to update to." : "GitHub didn't answer. Try again in a moment.");
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
    std::vector<std::uint8_t> body;
    const int status = http.get("https://api.github.com/repos/" + repo + "/releases?per_page=30", body, {}, 8u << 20);
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
        bool forced = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            forced = g.force_check;
            g.force_check = false;
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
                    fetch_art(http);
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
        std::string id, sapp, sslot, simage;
        bool bench = false;
        std::string del;
        bool store_dl = false;
        {
            std::lock_guard<std::mutex> l(g.m);
            store_dl = g.pending_store_dl;
            g.pending_store_dl = false;
            bench = g.pending_bench;
            g.pending_bench = false;
            del.swap(g.pending_delete);
            id.swap(g.pending_install);
            sapp.swap(g.pending_setup_app);
            sslot.swap(g.pending_setup_slot);
            simage.swap(g.pending_setup_image);
        }
        if (!id.empty())
            run_install(id);
        else if (!sapp.empty())
            run_setup(sapp, sslot, simage);
        else if (bench)
            run_bench();
        else if (!del.empty())
            run_delete(del);
        else if (store_dl)
            run_store_download();
        else
        {
            std::set<std::string> repos;
            {
                std::lock_guard<std::mutex> l(g.m);
                repos.swap(g.notes_wanted);
            }
            if (repos.empty())
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
} // namespace

/* ---- Games already on the console --------------------------------------------------- */
namespace
{
std::mutex g_fold_m;
std::map<std::string, std::string> g_folders; /* title ID -> folder */

void scan_homebrew()
{
    std::map<std::string, std::string> found;
    if (DIR *d = opendir(kHomebrew))
    {
        while (dirent *e = readdir(d))
        {
            if (e->d_name[0] == '.') continue;
            const std::string dir = std::string(kHomebrew) + "/" + e->d_name;
            std::string text;
            Json pj;
            if (read_file(dir + "/sce_sys/param.json", text) && Json::parse(text, pj))
            {
                const std::string tid = pj["titleId"].str();
                /* Two copies of one game: the folder named after its title ID (where the
                 * store installs) wins over any other. */
                if (!tid.empty() && exists(dir + "/eboot.bin") && (!found.count(tid) || tid == e->d_name))
                    found[tid] = dir;
            }
            if (!found.count(e->d_name) && exists(dir + "/eboot.bin"))
                found[e->d_name] = dir;
            else if (found.count(e->d_name) && found[e->d_name] != dir && exists(dir + "/eboot.bin"))
                found[e->d_name] = dir; /* a folder named after a title ID is that game's */
        }
        closedir(d);
    }
    std::string list;
    for (const auto &f : found) list += " " + f.first + "=" + f.second.substr(f.second.find_last_of('/') + 1);
    slog("homebrew on this console:%s", list.empty() ? " none" : list.c_str());
    std::lock_guard<std::mutex> l(g_fold_m);
    g_folders.swap(found);
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

std::string app_folder(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g_fold_m);
    auto it = g_folders.find(app_id);
    return it != g_folders.end() ? it->second : std::string(kHomebrew) + "/" + app_id;
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
    slog("Recomp Store %s (build %d) started; data in %s; copier payload %s", kVersion, kBuild, data_dir.c_str(),
         exists(std::string(PORPOISE_APP) + "/assets/payloads/recompstore-copier.elf") ? "included" : "MISSING");
    for (const char *where : {"/data", "/mnt/usb0", "/mnt/usb1", "/mnt/ext0", "/mnt/ext1"})
    {
        struct statvfs fs;
        if (statvfs(where, &fs) == 0 && fs.f_blocks)
            slog("space on %s: %.1f GB free of %.1f GB", where, double(fs.f_bavail) * fs.f_frsize / 1073741824.0,
                 double(fs.f_blocks) * fs.f_frsize / 1073741824.0);
    }
    scan_homebrew();
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
bool Engine::loading() const { std::lock_guard<std::mutex> l(g.m); return g.loading; }

bool Engine::install(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty()) return false;
    g.pending_install = app_id;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "install";
    g.job.app_id = app_id;
    g.job.step = "Starting";
    return true;
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

bool Engine::remove(const std::string &app_id)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty())
        return false;
    g.pending_delete = app_id;
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

static bool start_smp(std::string &why)
{
    const std::string path = smp_path();
    std::string elf;
    if (path.empty()) { why = "Choose your ShadowMountPlus file in Settings first."; return false; }
    if (!read_file(path, elf) || elf.size() < 64 || elf.compare(0, 4, "\x7F" "ELF") != 0)
    {
        why = "That ShadowMountPlus file couldn't be read.";
        slog("ShadowMountPlus: can't read %s", path.c_str());
        return false;
    }
    int err = 0;
    if (!send_to_loader(elf, err))
    {
        why = "No payload loader answered on port 9021.";
        slog("ShadowMountPlus: no payload loader answered on port 9021 (errno %d)", err);
        return false;
    }
    slog("ShadowMountPlus: sent %s (%zu KB) to the payload loader", path.c_str(), elf.size() / 1024);
    return true;
}

/* ShadowMountPlus 1.7's own API: HTTP on 127.0.0.1:10101, one JSON object POSTed per
 * call (docs/api.md in its source). true when it answered with status 0; *answered
 * says whether anything answered at all. */
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
    sa.sin_port = htons(10101);
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
    if (smp_api("/api/v1/scan", "{}", reply, &answered))
    {
        slog("ShadowMountPlus is running; asked it to look for new games: %s", reply.c_str());
        return 2;
    }
    if (answered)
        slog("ShadowMountPlus answered but didn't take the rescan: %s", reply.c_str());
    if (smp_path().empty())
    {
        why = answered ? "ShadowMountPlus didn't take the request." : "ShadowMountPlus isn't running, and no file is chosen to start it.";
        return 0;
    }
    if (answered)
    {
        /* Running, but not taking requests (busy): starting a second copy would only restart it. */
        why = "ShadowMountPlus is busy; it finds the game on its next look (every 15 seconds or so).";
        return 0;
    }
    return start_smp(why) ? 1 : 0;
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

/* Opening a game. An app may not start another app (the system answers "not
 * allowed"), so the launcher payload does it, as Porpoise's home screen tiles do:
 * the store leaves it the title ID, hands it to the payload loader on port 9021 and
 * closes once it has started; it opens the game when the store has gone, or opens
 * the store again if the game won't open. */
static std::string g_open_token, g_open_title;

bool open_game(const std::string &title_id, std::string &why)
{
    if (!title_id_ok(title_id))
    {
        why = "Its title ID isn't one the PS5 can open.";
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
    const std::string job = std::string("token=") + token + "\ntitle=" + title_id + "\n";
    if (!write_file(jd + "/job.txt", job.data(), job.size()))
    {
        slog("open %s: couldn't write the launcher's job in %s", title_id.c_str(), jd.c_str());
        why = "The store couldn't write to its folder.";
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
    slog("open %s: sent the launcher (%zu KB) to the payload loader", title_id.c_str(), elf.size() / 1024);
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
        !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench || !g.pending_delete.empty())
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
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = "setup";
    g.job.slot = slot_key;
    g.job.app_id = app_id;
    g.job.step = "Reading the disc image";
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
            const bool want = std::strcmp(ext, "stfs") == 0    ? stfs::looks_like(f.path)
                              : std::strcmp(ext, "image") == 0 ? ends(".iso") || ends(".gcm") || ends(".ciso")
                                                               : low.size() > el && low.compare(low.size() - el, el, ext) == 0;
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

bool slot_ready(const App &a, const Slot &s)
{
    if (s.kind == "iso") return exists(slot_target(a, s) + "/default.xex");
    if (s.kind == "image")
    {
        /* The right disc in the folder. Asked every frame, so looked at every two seconds. */
        static std::mutex m;
        static std::map<std::string, std::pair<double, bool>> seen;
        const std::string dir = slot_target(a, s), key = dir + "|" + s.disc_id;
        std::lock_guard<std::mutex> l(m);
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
}

std::string Engine::installed(const std::string &app_id) const
{
    std::string data;
    {
        std::lock_guard<std::mutex> l(g.m);
        data = g.data;
    }
    const bool there = exists(app_folder(app_id) + "/eboot.bin");
    std::string v;
    if (there && read_file(data + "/installed/" + app_id + ".txt", v) && !v.empty()) return v;
    return there ? "?" : "";
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
