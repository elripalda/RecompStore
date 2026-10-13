/* Recomp Store installer - puts Recomp Store on a PS5, or updates it.
 *
 * A payload: send RecompStore-Installer.elf to the console's payload loader on
 * port 9021 (etaHEN, elfldr, or any other). It takes the newest Recomp Store
 * release from GitHub (elripalda/RecompStore, the PPSA92000.zip on it) when the
 * console lets a payload use HTTPS, and otherwise the copy of the store built
 * into it (RS_EMBEDDED). It checks it (GitHub's SHA-256, then every file's
 * CRC-32 as it is unpacked and read back), and unpacks it into the store's folder:
 * wherever a folder under /data/homebrew already holds PPSA92000, else
 * /data/homebrew/PPSA92000. Then it asks ShadowMountPlus (its API on
 * 127.0.0.1:10101) to look for new games, so the store shows up on the home
 * screen. A toast says how it went.
 *
 * The store updates itself the same way: it downloads and checks the new zip
 * itself, leaves /data/recompstore/selfupdate/job.txt (token=, mode=update,
 * zip=, sha256=, version=), sends this, and closes once status.txt there
 * says phase=waiting. This waits for the store to be gone, installs, and opens
 * the store again (also when the update failed: the old store is still whole).
 *
 * Nothing outside the store's folder and /data/recompstore is written.
 * What happened goes to /data/recompstore/logs/installer.log.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "porpoise_http.hpp"
#include "store_json.hpp"
#include "store_sha256.hpp"
#include "store_zip.hpp"

#include <arpa/inet.h>
#include <cerrno>
#include <cstdarg>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <csignal>
#include <dirent.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <string>
#include <sys/socket.h>
#include <sys/stat.h>
#include <unistd.h>
#include <vector>

#ifndef RECOMP_DIR
#define RECOMP_DIR "/data/recompstore"
#endif
#ifndef HOMEBREW_DIR
#define HOMEBREW_DIR "/data/homebrew"
#endif
#ifndef RELEASES_URL
#define RELEASES_URL "https://api.github.com/repos/elripalda/RecompStore/releases?per_page=10"
#endif
#define STORE_TITLE "PPSA92000"
#define STORE_ASSET "PPSA92000.zip"
#ifdef RS_EMBEDDED_VERSION
#define INSTALLER_VERSION RS_EMBEDDED_VERSION
#else
#define INSTALLER_VERSION "1.0.2"
#endif

#ifdef RECOMP_DESKTOP_TEST
/* Desktop tests: what the console's system calls would do, written down. */
static int sceSystemServiceGetAppIdOfRunningBigApp() { return std::getenv("STORE_STILL_OPEN") ? 0x60000100 : -1; }
static int sceUserServiceInitialize(void *) { return 0; }
static int sceUserServiceGetForegroundUser(std::uint32_t *u) { *u = 0x10000001; return 0; }
static int sceSystemServiceLaunchApp(const char *id, char **, void *) { std::printf("[launch %s]\n", id); return 0x60000200; }
static int sceNotificationSend(int, bool, const char *json) { std::printf("[toast json] %.120s\n", json); return std::getenv("NO_RICH_TOAST") ? -1 : 0; }
static int sceKernelSendNotificationRequest(int, void *req, std::size_t, int)
{
    std::printf("[toast] %s\n", static_cast<const char *>(req) + 45);
    return 0;
}
#else
extern "C"
{
    int sceSystemServiceGetAppIdOfRunningBigApp(void);
    int sceUserServiceInitialize(void *);
    int sceUserServiceGetForegroundUser(std::uint32_t *user_id);
    int sceSystemServiceLaunchApp(const char *title_id, char **argv, void *ctx);
    int sceKernelSendNotificationRequest(int device, void *request, std::size_t size, int blocking);
    int sceNotificationSend(int user_id, bool logged, const char *payload);
}
#endif

#ifdef RS_EMBEDDED
/* The store's own zip, built into this installer (embed.S): installs with no network. */
extern "C" const unsigned char rs_embedded_zip[];
extern "C" const unsigned char rs_embedded_zip_end[];
#endif

/* porpoise_http.cpp's trace lines go to the installer's log. */
namespace ps5::debug
{
void mark(const char *step) noexcept;
void mark_value(const char *step, long long value) noexcept;
void mark_init(const char *step, bool have_video, int width, int height) noexcept;
}

namespace
{
const std::string kDir = RECOMP_DIR;
const std::string kJobDir = kDir + "/selfupdate";

void log_line(const char *fmt, ...)
{
    char body[1024];
    va_list ap;
    va_start(ap, fmt);
    std::vsnprintf(body, sizeof body, fmt, ap);
    va_end(ap);
    const std::time_t now = std::time(nullptr);
    std::tm tm{};
    localtime_r(&now, &tm);
    char stamp[32];
    std::strftime(stamp, sizeof stamp, "%Y-%m-%d %H:%M:%S", &tm);
    for (const char *name : {"/logs/installer.log", "/logs/store.log"})
        if (std::FILE *f = std::fopen((kDir + name).c_str(), "a"))
        {
            std::fprintf(f, "%s installer: %s\n", stamp, body);
            std::fclose(f);
        }
}

void make_dirs(const std::string &path)
{
    for (std::size_t i = 1; i <= path.size(); ++i)
        if (i == path.size() || path[i] == '/')
            mkdir(path.substr(0, i).c_str(), 0777);
}

bool read_file(const std::string &path, std::string &out)
{
    out.clear();
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return false;
    char buf[8192];
    std::size_t n;
    while ((n = std::fread(buf, 1, sizeof buf, f)) > 0) out.append(buf, n);
    std::fclose(f);
    return true;
}

bool write_file(const std::string &path, const std::string &text)
{
    const std::string tmp = path + ".tmp";
    std::FILE *f = std::fopen(tmp.c_str(), "wb");
    if (!f) return false;
    const bool ok = std::fwrite(text.data(), 1, text.size(), f) == text.size();
    std::fclose(f);
    return ok && std::rename(tmp.c_str(), path.c_str()) == 0;
}

std::string field(const std::string &text, const char *key)
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

/* A PS5 toast (the kernel's own: no extra library, nothing to unload). */
struct KernelToast
{
    std::int32_t type, req_id, priority, msg_id, target_id, user_id, unk1, unk2, app_id, error_num, unk3;
    std::uint8_t use_icon_image_uri;
    char message[1024];
    char icon_uri[1024];
    char unk[1024];
    std::uint8_t pad[3];
} __attribute__((packed));
static_assert(sizeof(KernelToast) == 0xc30, "the kernel toast is 0xc30 bytes");

std::string json_quote(const std::string &s)
{
    std::string o = "\"";
    for (unsigned char c : s)
    {
        if (c == '"' || c == '\\') { o += '\\'; o += char(c); }
        else if (c < 0x20) { char b[8]; std::snprintf(b, sizeof b, "\\u%04x", c); o += b; }
        else o += char(c);
    }
    return o + "\"";
}

void toast(const std::string &text)
{
    /* A system toast, the way Porpoise's notification relay posts one (logged, so it shows). */
    static unsigned count = 0;
    char id[32];
    std::snprintf(id, sizeof id, "%u", 100000000u + (unsigned(std::time(nullptr)) % 800000u) * 1000u + (count++ % 1000u));
    char created[40];
    const std::time_t now = std::time(nullptr);
    std::tm tm{};
    gmtime_r(&now, &tm);
    std::strftime(created, sizeof created, "%Y-%m-%dT%H:%M:%S.000Z", &tm);
    const std::string j = "{\"rawData\":{\"viewTemplateType\":\"InteractiveToastTemplateB\",\"channelType\":\"Downloads\","
                          "\"useCaseId\":\"IDC\",\"toastOverwriteType\":\"No\",\"isImmediate\":true,\"priority\":100,"
                          "\"viewData\":{\"message\":{\"body\":" + json_quote(text) + "}}},\"createdDateTime\":" +
                          json_quote(created) + ",\"localNotificationId\":" + json_quote(id) + "}";
    if (sceNotificationSend(0xFE, true, j.c_str()) == 0)
    {
        log_line("toast: %s", text.c_str());
        return;
    }
    KernelToast req;
    std::memset(&req, 0, sizeof req);
    std::snprintf(req.message, sizeof req.message, "%s", text.c_str());
    sceKernelSendNotificationRequest(0, &req, sizeof req, 0);
    log_line("toast: %s", text.c_str());
}

/* Where the store already is: a folder under the homebrew folder whose param.json
 * says PPSA92000; else a new one named after it. */
std::string store_folder()
{
    if (DIR *d = opendir(HOMEBREW_DIR))
    {
        std::string found;
        while (dirent *e = readdir(d))
        {
            if (e->d_name[0] == '.') continue;
            const std::string dir = std::string(HOMEBREW_DIR) + "/" + e->d_name;
            std::string text;
            recomp::Json pj;
            if (read_file(dir + "/sce_sys/param.json", text) && recomp::Json::parse(text, pj) && pj["titleId"].str() == STORE_TITLE)
            {
                found = dir;
                if (std::string(e->d_name) == STORE_TITLE) break; /* its own name wins */
            }
        }
        closedir(d);
        if (!found.empty()) return found;
    }
    return std::string(HOMEBREW_DIR) + "/" STORE_TITLE;
}

/* ShadowMountPlus 1.7's API: "look for new games now". */
bool smp_rescan()
{
    const int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) return false;
    timeval limit{};
    limit.tv_sec = 2;
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &limit, sizeof limit);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &limit, sizeof limit);
    sockaddr_in sa{};
    sa.sin_family = AF_INET;
    sa.sin_port = htons(10101);
    sa.sin_addr.s_addr = htonl(0x7F000001);
    if (connect(fd, reinterpret_cast<sockaddr *>(&sa), sizeof sa) != 0)
    {
        close(fd);
        return false;
    }
    const char req[] = "POST /api/v1/scan HTTP/1.1\r\nHost: 127.0.0.1:10101\r\nContent-Type: application/json\r\n"
                       "Content-Length: 2\r\nConnection: close\r\n\r\n{}";
#ifdef MSG_NOSIGNAL
    const int flags = MSG_NOSIGNAL;
#else
    const int flags = 0;
#endif
    bool sent = send(fd, req, sizeof req - 1, flags) == ssize_t(sizeof req - 1);
    std::string got;
    char buf[2048];
    for (ssize_t n; sent && (n = recv(fd, buf, sizeof buf, 0)) > 0;)
        got.append(buf, std::size_t(n));
    close(fd);
    const std::size_t open = got.find('{'), end = got.rfind('}');
    recomp::Json j;
    const bool ok = open != std::string::npos && end != std::string::npos && end > open &&
                    recomp::Json::parse(got.substr(open, end - open + 1), j) && j["status"].integer(-1) == 0;
    log_line("ShadowMountPlus %s", ok ? "is running; asked it to look for new games" : got.empty() ? "didn't answer" : "didn't take the request");
    return ok;
}

int open_store()
{
    sceUserServiceInitialize(nullptr);
    struct
    {
        std::uint32_t structsize, user_id, app_opt;
        std::uint64_t crash_report;
        std::uint32_t check_flag;
    } ctx{};
    sceUserServiceGetForegroundUser(&ctx.user_id);
    char id[] = STORE_TITLE;
    char *argv[2] = {id, nullptr};
    int r = -1;
    for (int attempt = 0; attempt < 10 && r < 0; ++attempt)
    {
        r = sceSystemServiceLaunchApp(STORE_TITLE, argv, &ctx);
        if (r < 0) usleep(500 * 1000);
    }
    log_line("opened the store again: 0x%08x", unsigned(r));
    return r;
}

std::string sha256_of(const std::string &path)
{
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return "";
    recomp::Sha256 sha;
    std::vector<std::uint8_t> buf(1 << 16);
    std::size_t n;
    while ((n = std::fread(buf.data(), 1, buf.size(), f)) > 0) sha.update(buf.data(), n);
    std::fclose(f);
    return sha.hex();
}

struct Release
{
    std::string tag, url, sha256;
    std::uint64_t size = 0;
};

/* The newest release with the store's zip: a full release if there is one, else the
 * newest pre-release. */
bool newest_release(porpoise::http::Session &http, Release &out, std::string &why)
{
    std::vector<std::uint8_t> body;
    const int status = http.get(RELEASES_URL, body, {}, 4u << 20);
    log_line("GitHub releases: HTTP %d, %zu bytes (console error 0x%08x)", status, body.size(), unsigned(http.last_error()));
    recomp::Json j;
    if (status != 200 || !recomp::Json::parse(std::string(body.begin(), body.end()), j) || !j.is_array())
    {
        why = status == 403 || status == 429 ? "GitHub is limiting requests right now. Try again in an hour."
                                             : "Couldn't reach GitHub. Check the PS5's internet connection.";
        return false;
    }
    Release stable, pre;
    bool have_stable = false, have_pre = false;
    for (std::size_t i = 0; i < j.size(); ++i) /* GitHub lists the newest first */
    {
        const recomp::Json &r = j[i];
        if (r["draft"].boolean(false)) continue;
        const recomp::Json &assets = r["assets"];
        for (std::size_t k = 0; k < assets.size(); ++k)
        {
            if (assets[k]["name"].str() != STORE_ASSET) continue;
            Release x;
            x.tag = r["tag_name"].str();
            x.url = assets[k]["browser_download_url"].str();
            x.size = std::uint64_t(assets[k]["size"].num(0));
            const std::string digest = assets[k]["digest"].str();
            if (digest.rfind("sha256:", 0) == 0) x.sha256 = digest.substr(7);
            if (!r["prerelease"].boolean(false) && !have_stable) { stable = x; have_stable = true; }
            else if (r["prerelease"].boolean(false) && !have_pre) { pre = x; have_pre = true; }
            break;
        }
    }
    const bool have = have_stable || have_pre;
    if (have) out = have_stable ? stable : pre;
    if (!have || out.url.rfind("https://", 0) != 0)
    {
        why = "There's no Recomp Store release on GitHub yet.";
        return false;
    }
    return true;
}

bool download(porpoise::http::Session &http, const Release &rel, const std::string &path, std::string &why)
{
    const std::string part = path + ".part";
    std::FILE *f = std::fopen(part.c_str(), "wb");
    if (!f)
    {
        why = "Couldn't write to /data/recompstore.";
        return false;
    }
    recomp::Sha256 sha;
    std::uint64_t got = 0, next_log = 4u << 20;
    bool write_ok = true;
    const int status = http.get_stream(rel.url, [&](const std::uint8_t *p, std::size_t n) {
        if (std::fwrite(p, 1, n, f) != n) { write_ok = false; return false; }
        sha.update(p, n);
        got += n;
        if (got >= next_log) { log_line("downloaded %llu KB", (unsigned long long)(got >> 10)); next_log += 4u << 20; }
        return got < (std::uint64_t(512) << 20);
    });
    std::fclose(f);
    const std::string hash = sha.hex();
    log_line("download %s: HTTP %d, %llu bytes, SHA-256 %s (console error 0x%08x)", rel.url.c_str(), status, (unsigned long long)got,
             hash.c_str(), unsigned(http.last_error()));
    if (status != 200 || !write_ok || got == 0 || (rel.size && got != rel.size))
    {
        std::remove(part.c_str());
        why = !write_ok ? "The PS5's storage refused the download. Check its free space." : "The download didn't finish. Try again.";
        return false;
    }
    if (!rel.sha256.empty() && hash != rel.sha256)
    {
        std::remove(part.c_str());
        why = "The download didn't match GitHub's checksum, so nothing was installed. Try again.";
        return false;
    }
    if (std::rename(part.c_str(), path.c_str()) != 0)
    {
        std::remove(part.c_str());
        why = "Couldn't save the download in /data/recompstore. Check the PS5's free space.";
        return false;
    }
    return true;
}
} // namespace

namespace ps5::debug
{
void mark(const char *step) noexcept { log_line("%s", step); }
void mark_value(const char *step, long long value) noexcept { log_line("%s %lld", step, value); }
void mark_init(const char *, bool, int, int) noexcept {}
}

int main()
{
    std::signal(SIGPIPE, SIG_IGN); /* a peer hanging up mustn't end the installer */
    make_dirs(kDir + "/logs");
    make_dirs(kJobDir);
    make_dirs(kDir + "/downloads");
    log_line("Recomp Store installer %s started", INSTALLER_VERSION);

    /* One installer at a time (a lock older than 15 minutes is from one that died). */
    const std::string lock = kJobDir + "/installer.lock";
    int lfd = open(lock.c_str(), O_CREAT | O_EXCL | O_WRONLY, 0666);
    if (lfd < 0)
    {
        struct stat st;
        const long long age = stat(lock.c_str(), &st) == 0 ? (long long)(std::time(nullptr) - st.st_mtime) : -1;
        if (age >= 0 && age < 15 * 60)
        {
            log_line("another installer is already running; this one stands down");
            _exit(3);
        }
        std::remove(lock.c_str());
        lfd = open(lock.c_str(), O_CREAT | O_EXCL | O_WRONLY, 0666);
    }
    if (lfd >= 0) close(lfd);
    auto finish = [&](int code) {
        std::remove(lock.c_str());
        _exit(code);
    };

    /* Sent by the store to update itself? Only a job from the last two minutes counts. */
    std::string job, token;
    bool updating = false;
    {
        struct stat st;
        const std::string jp = kJobDir + "/job.txt";
        if (stat(jp.c_str(), &st) == 0 && read_file(jp, job))
        {
            std::remove(jp.c_str());
            token = field(job, "token");
            const long long age = (long long)(std::time(nullptr) - st.st_mtime);
            updating = field(job, "mode") == "update" && !token.empty() && age >= 0 && age < 120;
        }
        /* The store gave up waiting for this (it started late): do nothing. */
        const std::string cp = kJobDir + "/cancel";
        if (stat(cp.c_str(), &st) == 0)
        {
            const long long age = (long long)(std::time(nullptr) - st.st_mtime);
            std::remove(cp.c_str());
            if (!updating && age >= 0 && age < 120)
            {
                log_line("the store had already given up on this update; nothing done");
                finish(3);
            }
        }
    }
    if (updating)
    {
        write_file(kJobDir + "/status.txt", "token=" + token + "\nphase=waiting\n");
        /* The store closes when it reads "waiting": wait (up to 10 s) until it has. */
        const int store = sceSystemServiceGetAppIdOfRunningBigApp();
        bool gone = false;
        for (int i = 0; i < 100 && !gone; ++i)
        {
            const int now = sceSystemServiceGetAppIdOfRunningBigApp();
            if (now != store || now < 0) gone = true;
            else usleep(100 * 1000);
        }
        if (!gone)
        {
            /* Never over a store that's still open. */
            log_line("the store is still open after 10 s; not updating");
            write_file(kJobDir + "/status.txt", "token=" + token + "\nphase=failed\nerror=The store didn't close.\n");
            toast("Recomp Store didn't close, so it wasn't updated. Try again from Latest Updates.");
            sleep(3);
            finish(2);
        }
        usleep(300 * 1000);
    }
    toast(updating ? "Recomp Store: updating\xE2\x80\xA6" : "Recomp Store: installing\xE2\x80\xA6");

    std::string why, installed, newer;
    bool ok = false, own_copy = false;
    {
        /* Where the store's zip comes from, first that works:
         *  1. the store's own download (an update: the store fetched and checked it),
         *  2. GitHub's newest release (some consoles refuse HTTPS from a payload),
         *  3. the copy built into this installer. */
        std::string zip = kDir + "/downloads/" STORE_ASSET, tag;
        bool have = false, from_store = false;
        if (updating && !field(job, "zip").empty())
        {
            const std::string given = field(job, "zip"), want = field(job, "sha256");
            if (given.rfind(kDir + "/downloads/", 0) == 0 && given.find("..") == std::string::npos && sha256_of(given) == want && !want.empty())
            {
                zip = given;
                tag = field(job, "version");
                have = from_store = true;
                log_line("using the store's download %s (%s)", zip.c_str(), tag.c_str());
            }
            else
                log_line("the store's download %s is missing or doesn't match its checksum", given.c_str());
        }
        if (!have)
        {
            porpoise::http::Session http("installer", "RecompStore-Installer/" INSTALLER_VERSION);
            Release rel;
            if (!http.init())
                why = "The PS5 isn't online, or its network couldn't be set up.";
            else if (newest_release(http, rel, why) && download(http, rel, zip, why))
            {
                tag = rel.tag;
                have = true;
            }
            else
                log_line("GitHub didn't work from here (console error 0x%08x)", unsigned(http.last_error()));
        }
#ifdef RS_EMBEDDED
        /* Never over a newer store than the copy built in (param.json's contentVersion,
         * fixed-width, so it compares as text). */
        if (!have)
        {
            std::string text, cv;
            recomp::Json pj;
            if (read_file(store_folder() + "/sce_sys/param.json", text) && recomp::Json::parse(text, pj))
                cv = pj["contentVersion"].str();
            if (!cv.empty() && cv > RS_EMBEDDED_CONTENT)
            {
                newer = cv;
                log_line("the store on the console (%s) is newer than this installer's copy (%s); nothing changed", cv.c_str(),
                         RS_EMBEDDED_CONTENT);
            }
        }
        if (!have && newer.empty())
        {
            const std::size_t n = std::size_t(rs_embedded_zip_end - rs_embedded_zip);
            std::FILE *f = std::fopen(zip.c_str(), "wb");
            const bool wrote = f && std::fwrite(rs_embedded_zip, 1, n, f) == n;
            if (f) std::fclose(f);
            if (wrote)
            {
                tag = RS_EMBEDDED_VERSION;
                have = own_copy = true;
                why.clear();
                log_line("using the copy built into the installer (%s, %zu KB)", tag.c_str(), n / 1024);
            }
            else
                why = "Couldn't write to /data/recompstore. Check the PS5's free space.";
        }
#endif
        if (have)
        {
            const std::string root = store_folder();
            log_line("installing %s into %s", tag.c_str(), root.c_str());
            std::string error;
            bool changed = false;
            std::size_t files = 0;
            int last_tenth = -1;
            ok = recomp::zip::unpack(
                zip, root,
                [&](const char *step, std::uint64_t d, std::uint64_t n) {
                    const int tenth = n ? int(d * 10 / n) : 0;
                    if (tenth != last_tenth) { last_tenth = tenth; log_line("%s %d%%", step, tenth * 10); }
                },
                [](const std::string &l) { log_line("%s", l.c_str()); }, nullptr, error, changed, files);
            if (ok)
            {
                installed = tag;
                log_line("installed Recomp Store %s: %zu files in %s", tag.c_str(), files, root.c_str());
            }
            else
            {
                why = error.empty() ? "Unpacking the store failed." : error;
                log_line("unpacking failed (%s): %s", changed ? "some files were replaced" : "nothing was changed", why.c_str());
            }
            std::remove(zip.c_str()); /* the download, or this installer's copy of it */
            (void)from_store;
        }
    }

    std::string v = installed;
    if (!v.empty() && (v[0] == 'v' || v[0] == 'V')) v.erase(0, 1);
    if (ok)
    {
        /* Its own copy may not be the newest: the store, which can reach GitHub, updates
         * itself to the newest the first time it opens. */
        const std::string marker = kJobDir + "/autoupdate";
        if (own_copy && !updating)
        {
            write_file(marker, "version=" + installed + "\n");
            log_line("the store updates itself to the newest version when it first opens");
        }
        else
            std::remove(marker.c_str());
        const bool smp = smp_rescan();
        if (updating)
            toast("Recomp Store updated to " + v + ".");
        else
            toast(smp ? "Recomp Store " + v + " is installed. It shows up on your home screen in a few seconds."
                      : "Recomp Store " + v + " is installed. Start ShadowMountPlus to add it to your home screen.");
    }
    else if (!newer.empty())
        toast("Recomp Store is already installed, and it's newer than this installer. Update it from Latest Updates.");
    else
    {
        log_line("failed: %s", why.c_str());
        toast("Recomp Store couldn't be " + std::string(updating ? "updated" : "installed") + ". " + why);
    }
    if (updating)
    {
        write_file(kJobDir + "/status.txt", "token=" + token + "\nphase=" + (ok ? "done" : "failed") + "\nversion=" + v +
                                                "\nerror=" + why + "\n");
        if (ok) usleep(1500 * 1000); /* ShadowMountPlus first, so the store opens its new self */
        open_store();
    }
    sleep(3); /* let the toast go out before the process ends */
    finish(ok ? 0 : 2);
}
