/* Recomp Store copier - sets a game up from the player's own disc image.
 *
 * A payload: the store sends it to the console's payload loader on port 9021
 * (etaHEN or elfldr), so it runs as its own process. The PS5 lets an app like
 * the store write only about a gigabyte quickly before slowing it to a few
 * MB/s; a payload writes at the drive's full speed, as an FTP server does.
 *
 * The store leaves the job in /data/recompstore/copyjob/job.txt:
 *     token=<number the store chose>
 *     image=<the .iso>
 *     target=<the folder its files go in>
 * and this writes its progress, at least twice a second, to status.txt there:
 *     token=  phase=copying|done|failed  done=  total=  error=  seconds=
 * A file called "cancel" in that folder stops it. Files already in place at
 * their full size are kept, so a stopped setup carries on. It writes only
 * inside the target folder, never the port's own eboot.bin, sce_sys or
 * sce_module, and shows no notifications.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_disc.hpp"
#include "store_zip.hpp"
#include "store_stfs.hpp"

#include <algorithm>
#include <cerrno>
#include <cstdarg>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <fcntl.h>
#include <pthread.h>
#include <set>
#include <string>
#include <sys/stat.h>
#include <unistd.h>
#include <vector>

#ifndef RECOMP_DIR
#define RECOMP_DIR "/data/recompstore"
#endif

namespace
{
const std::string kDir = RECOMP_DIR;
const std::string kJobDir = kDir + "/copyjob";
const std::string kLog = kDir + "/logs/store.log";

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
    if (std::FILE *f = std::fopen(kLog.c_str(), "a"))
    {
        std::fprintf(f, "%s  copier: %s\n", stamp, body);
        std::fclose(f);
    }
}

double now_s()
{
    timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return double(ts.tv_sec) + double(ts.tv_nsec) / 1e9;
}

bool read_text(const std::string &path, std::string &out)
{
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f)
        return false;
    out.clear();
    char buf[4096];
    std::size_t n;
    while ((n = std::fread(buf, 1, sizeof buf, f)) > 0)
        out.append(buf, n);
    std::fclose(f);
    return true;
}

std::string field(const std::string &text, const char *key)
{
    const std::string k = std::string(key) + "=";
    std::size_t at = 0;
    while (at < text.size())
    {
        std::size_t end = text.find('\n', at);
        if (end == std::string::npos)
            end = text.size();
        if (text.compare(at, k.size(), k) == 0)
            return text.substr(at + k.size(), end - at - k.size());
        at = end + 1;
    }
    return "";
}

bool exists(const std::string &p)
{
    struct stat st;
    return stat(p.c_str(), &st) == 0;
}

void make_dirs(const std::string &path)
{
    for (std::size_t i = 1; i <= path.size(); ++i)
        if (i == path.size() || path[i] == '/')
        {
            const std::string d = path.substr(0, i);
            if (mkdir(d.c_str(), 0777) == 0)
                chmod(d.c_str(), 0777);
        }
}

struct Status
{
    std::string token, phase = "starting", error;
    unsigned long long done = 0, total = 0;
    double seconds = 0;
    double last_write = 0;
    int changed = 0;          /* unzip: some old file had already been replaced */
    unsigned long long files = 0;
};
Status g_status;

/* Whole, at once: written beside, then renamed over, so the store never reads half. */
void write_status(bool force = false)
{
    const double t = now_s();
    if (!force && t - g_status.last_write < 0.4)
        return;
    g_status.last_write = t;
    char text[2048];
    std::snprintf(text, sizeof text, "token=%s\nphase=%s\ndone=%llu\ntotal=%llu\nseconds=%.1f\nchanged=%d\nfiles=%llu\nerror=%s\n",
                  g_status.token.c_str(), g_status.phase.c_str(), g_status.done, g_status.total, g_status.seconds,
                  g_status.changed, g_status.files, g_status.error.c_str());
    const std::string tmp = kJobDir + "/status.tmp", fin = kJobDir + "/status.txt";
    if (std::FILE *f = std::fopen(tmp.c_str(), "wb"))
    {
        std::fputs(text, f);
        std::fclose(f);
        std::rename(tmp.c_str(), fin.c_str());
    }
}

int fail(const std::string &why)
{
    g_status.phase = "failed";
    g_status.error = why;
    write_status(true);
    log_line("failed: %s", why.c_str());
    unlink((kJobDir + "/running").c_str());
    return 1;
}

bool cancelled() { return exists(kJobDir + "/cancel"); }

/* ---- The copy: a reader thread fills 16 MB blocks in disc order, this thread writes them. */
constexpr std::size_t kBlock = 16u << 20;
constexpr int kBlocks = 3;

struct Pipe
{
    pthread_mutex_t m = PTHREAD_MUTEX_INITIALIZER;
    pthread_cond_t cv = PTHREAD_COND_INITIALIZER;
    std::uint8_t *data[kBlocks] = {};
    std::size_t size[kBlocks] = {};
    std::vector<int> free_q, full_q; /* full_q: oldest first */
    bool stop = false, reader_done = false, read_failed = false;
    std::string bad;
    double read_s = 0;
    recomp::Disc *disc = nullptr;
    const std::vector<const recomp::DiscFile *> *files = nullptr;
};

void *reader_main(void *arg)
{
    Pipe &p = *static_cast<Pipe *>(arg);
    for (const recomp::DiscFile *f : *p.files)
    {
        std::uint64_t off = std::uint64_t(f->sector) * 2048;
        std::uint32_t left = f->size;
        while (left)
        {
            pthread_mutex_lock(&p.m);
            while (p.free_q.empty() && !p.stop)
                pthread_cond_wait(&p.cv, &p.m);
            if (p.stop)
            {
                pthread_mutex_unlock(&p.m);
                goto out;
            }
            const int bi = p.free_q.back();
            p.free_q.pop_back();
            pthread_mutex_unlock(&p.m);

            const std::size_t n = std::min<std::size_t>(left, kBlock);
            const double t0 = now_s();
            const bool ok = p.disc->read(off, p.data[bi], n);
            const double dt = now_s() - t0;

            pthread_mutex_lock(&p.m);
            p.read_s += dt;
            if (!ok)
            {
                p.read_failed = true;
                p.bad = f->path;
                p.free_q.push_back(bi);
                pthread_cond_broadcast(&p.cv);
                pthread_mutex_unlock(&p.m);
                goto out;
            }
            p.size[bi] = n;
            p.full_q.push_back(bi);
            pthread_cond_broadcast(&p.cv);
            pthread_mutex_unlock(&p.m);
            off += n;
            left -= std::uint32_t(n);
        }
    }
out:
    pthread_mutex_lock(&p.m);
    p.reader_done = true;
    pthread_cond_broadcast(&p.cv);
    pthread_mutex_unlock(&p.m);
    return nullptr;
}

bool write_all(int fd, const std::uint8_t *data, std::size_t n)
{
    std::size_t at = 0;
    while (at < n)
    {
        const ssize_t w = write(fd, data + at, n - at);
        if (w < 0 && errno == EINTR)
            continue;
        if (w <= 0)
            return false;
        at += std::size_t(w);
    }
    return true;
}

bool protected_name(const std::string &p)
{
    std::string top = p.substr(0, p.find('/'));
    for (char &c : top)
        c = char(std::tolower(static_cast<unsigned char>(c)));
    return top == "eboot.bin" || top == "sce_sys" || top == "sce_module" || top == "recompstore.json";
}
} // namespace

/* ---- An install: the store's verified download unpacked into the game's folder,
 * by the same code the store uses (store_zip.cpp). -------------------------------- */
int unzip_job(const std::string &zip_path, const std::string &root)
{
    /* Only the store's own downloads, into a game's folder. */
    const std::string downloads = kDir + "/downloads/";
    if (zip_path.rfind(downloads, 0) != 0 || zip_path.find("/../") != std::string::npos)
        return fail("The copier only unpacks the store's own downloads.");
#ifdef RECOMP_TEST_ROOT
    const bool game_folder = root.rfind(RECOMP_TEST_ROOT, 0) == 0;
#else
    const bool game_folder = root.rfind("/data/homebrew/", 0) == 0 || root.rfind("/mnt/", 0) == 0;
#endif
    if (!game_folder || root.find("/../") != std::string::npos)
        return fail("The copier only installs into a game's folder.");
    log_line("install started (pid %d): %s -> %s", int(getpid()), zip_path.c_str(), root.c_str());
    const double t0 = now_s();
    g_status.phase = "installing";
    write_status(true);
    std::string error;
    bool changed = false;
    std::size_t files = 0;
    const bool ok = recomp::zip::unpack(
        zip_path, root,
        [](const char *step, std::uint64_t done, std::uint64_t total) {
            g_status.phase = std::strncmp(step, "Checking", 8) == 0 ? "checking" : "installing";
            g_status.done = done;
            g_status.total = total;
            write_status();
        },
        [](const std::string &line) { log_line("%s", line.c_str()); }, [] { return cancelled(); }, error, changed,
        files);
    g_status.changed = changed ? 1 : 0;
    g_status.seconds = now_s() - t0;
    if (!ok)
        return fail(error);
    g_status.files = files;
    g_status.phase = "done";
    write_status(true);
    log_line("install done: %zu files in %.1f s", files, now_s() - t0);
    unlink((kJobDir + "/running").c_str());
    return 0;
}

/* kind "stfs": a Title Update or DLC package (an Xbox 360 content package) unpacked into
 * the folder the game reads it from. */
int stfs_job(const std::string &package, const std::string &target)
{
    log_line("package started (pid %d): %s -> %s", int(getpid()), package.c_str(), target.c_str());
    const double t0 = now_s();
    g_status.phase = "copying";
    write_status(true);
    std::string error;
    std::size_t files = 0;
    const bool ok = recomp::stfs::unpack(
        package, target,
        [](std::uint64_t done, std::uint64_t total) {
            g_status.phase = "copying";
            g_status.done = done;
            g_status.total = total;
            write_status();
        },
        [](const std::string &line) { log_line("%s", line.c_str()); }, [] { return cancelled(); }, error, files);
    g_status.seconds = now_s() - t0;
    if (!ok)
        return fail(error);
    g_status.files = files;
    g_status.phase = "done";
    write_status(true);
    log_line("package done: %zu files in %.1f s", files, now_s() - t0);
    unlink((kJobDir + "/running").c_str());
    return 0;
}

int main()
{
    make_dirs(kDir + "/logs");
    std::string job;
    if (!read_text(kJobDir + "/job.txt", job))
    {
        log_line("no job in %s", kJobDir.c_str());
        return 1;
    }
    g_status.token = field(job, "token");
    const std::string kind = field(job, "kind").empty() ? std::string("setup") : field(job, "kind");
    const std::string image = field(job, "image"), target = field(job, "target");
    if (cancelled())
    {
        log_line("the store took this setup over before the copier started; leaving it to the store");
        return 0;
    }

    /* One copier at a time: a second one leaves the first to it. */
    const std::string lock = kJobDir + "/running";
    int lfd = open(lock.c_str(), O_WRONLY | O_CREAT | O_EXCL, 0666);
    if (lfd < 0)
    {
        struct stat st;
        const bool stale = stat((kJobDir + "/status.txt").c_str(), &st) != 0 || std::time(nullptr) - st.st_mtime > 20;
        if (!stale)
        {
            log_line("another copier is already working; this one leaves it to that");
            return 1;
        }
        unlink(lock.c_str());
        lfd = open(lock.c_str(), O_WRONLY | O_CREAT | O_EXCL, 0666);
    }
    if (lfd >= 0)
    {
        char pid[32];
        const int n = std::snprintf(pid, sizeof pid, "%d\n", int(getpid()));
        write_all(lfd, reinterpret_cast<const std::uint8_t *>(pid), std::size_t(n));
        close(lfd);
    }
    write_status(true);

    /* Only into a game's folder on the console's storage or a drive. */
#ifdef RECOMP_TEST_ROOT
    const bool allowed_root = target.rfind(RECOMP_TEST_ROOT, 0) == 0; /* the desktop test only */
#else
    const bool allowed_root = target.rfind("/data/", 0) == 0 || target.rfind("/mnt/", 0) == 0;
#endif
    if (target.size() < 8 || !allowed_root ||
        target.find("/../") != std::string::npos || target.find("/./") != std::string::npos)
        return fail("The game's folder isn't somewhere the store sets games up.");
    if (kind == "unzip")
        return unzip_job(image, target);
    if (kind == "stfs")
        return stfs_job(image, target);
    if (kind != "setup")
        return fail("The copier doesn't know the job \"" + kind + "\".");
    log_line("started (pid %d): %s -> %s", int(getpid()), image.c_str(), target.c_str());

    recomp::Disc disc;
    std::string err;
    g_status.phase = "reading";
    write_status(true);
    if (!disc.open(image, err))
        return fail(err);
    if (!disc.find("default.xex"))
        return fail("This disc image has no default.xex: it isn't a game disc, or it's the wrong one.");

    std::vector<const recomp::DiscFile *> order, todo;
    unsigned long long total = 0, done = 0;
    for (const recomp::DiscFile &f : disc.files())
        if (!f.is_dir && !protected_name(f.path))
        {
            order.push_back(&f);
            total += f.size;
        }
    std::sort(order.begin(), order.end(),
              [](const recomp::DiscFile *x, const recomp::DiscFile *y) { return x->sector < y->sector; });
    for (const recomp::DiscFile *f : order)
    {
        struct stat st;
        if (stat((target + "/" + f->path).c_str(), &st) == 0 && (unsigned long long)st.st_size == f->size)
            done += f->size; /* already there: a stopped setup carries on */
        else
            todo.push_back(f);
    }
    g_status.total = total;
    g_status.done = done;
    g_status.phase = "copying";
    write_status(true);
    log_line("%zu files, %llu MB, %llu MB still to copy", order.size(), total >> 20, (total - done) >> 20);

    Pipe pipe;
    for (int i = 0; i < kBlocks; ++i)
    {
        pipe.data[i] = static_cast<std::uint8_t *>(std::malloc(kBlock));
        if (!pipe.data[i])
            return fail("The copier couldn't get memory to copy with.");
        pipe.free_q.push_back(i);
    }
    pipe.disc = &disc;
    pipe.files = &todo;
    pthread_t reader;
    if (pthread_create(&reader, nullptr, reader_main, &pipe) != 0)
        return fail("The copier couldn't start reading.");

    const double t_start = now_s();
    double write_s = 0, last_log = t_start;
    unsigned long long copied = 0;
    std::string error;
    std::set<std::string> made;
    for (const recomp::DiscFile *f : todo)
    {
        const std::string dst = target + "/" + f->path;
        const std::string dir = dst.substr(0, dst.find_last_of('/'));
        if (made.insert(dir).second)
            make_dirs(dir);
        const int fd = open(dst.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0777);
        if (fd < 0)
        {
            error = "Couldn't create " + f->path + ".";
            break;
        }
        std::uint32_t left = f->size;
        bool ok = true;
        while (left && ok)
        {
            pthread_mutex_lock(&pipe.m);
            while (pipe.full_q.empty() && !pipe.read_failed && !pipe.reader_done)
                pthread_cond_wait(&pipe.cv, &pipe.m);
            if (pipe.full_q.empty())
            {
                error = "The disc image couldn't be read at " + (pipe.bad.empty() ? f->path : pipe.bad) + ". It may be incomplete.";
                pthread_mutex_unlock(&pipe.m);
                ok = false;
                break;
            }
            const int bi = pipe.full_q.front();
            pipe.full_q.erase(pipe.full_q.begin());
            const std::size_t n = pipe.size[bi];
            pthread_mutex_unlock(&pipe.m);

            const double w0 = now_s();
#ifdef RECOMP_TEST_SLOW
            usleep(RECOMP_TEST_SLOW); /* the desktop test only: a slow drive */
#endif
            if (!write_all(fd, pipe.data[bi], n))
            {
                error = "Writing " + f->path + " failed. Is the storage full?";
                ok = false;
            }
            write_s += now_s() - w0;

            pthread_mutex_lock(&pipe.m);
            pipe.free_q.push_back(bi);
            pthread_cond_broadcast(&pipe.cv);
            pthread_mutex_unlock(&pipe.m);
            if (!ok)
                break;
            left -= std::uint32_t(n);
            done += n;
            copied += n;
            g_status.done = done;
            g_status.seconds = now_s() - t_start;
            write_status();
            const double t = now_s();
            if (t - last_log > 5)
            {
                last_log = t;
                log_line("%llu MB in %.0f s (%.1f MB/s); reading %.1f s, writing %.1f s", copied >> 20, t - t_start,
                         double(copied) / 1048576.0 / (t - t_start), pipe.read_s, write_s);
            }
            if (cancelled())
            {
                error = "Stopped.";
                ok = false;
            }
        }
        close(fd);
        if (!ok)
        {
            unlink(dst.c_str()); /* half a file: copied again next time */
            break;
        }
        chmod(dst.c_str(), 0777);
        if (!error.empty())
            break;
    }
    pthread_mutex_lock(&pipe.m);
    pipe.stop = true;
    pthread_cond_broadcast(&pipe.cv);
    pthread_mutex_unlock(&pipe.m);
    pthread_join(reader, nullptr);
    for (int i = 0; i < kBlocks; ++i)
        std::free(pipe.data[i]);
    if (!error.empty())
        return fail(error);

    /* Every file there at its size. */
    g_status.phase = "checking";
    write_status(true);
    sync();
    for (const recomp::DiscFile *f : order)
    {
        struct stat st;
        if (stat((target + "/" + f->path).c_str(), &st) != 0 || (unsigned long long)st.st_size != f->size)
            return fail(f->path + " didn't copy whole. Set up again to finish it.");
    }
    const double secs = now_s() - t_start;
    g_status.seconds = secs;
    g_status.done = total;
    g_status.phase = "done";
    write_status(true);
    log_line("done: copied %llu MB in %.1f s (%.1f MB/s); reading %.1f s, writing %.1f s", copied >> 20, secs,
             secs > 0 ? double(copied) / 1048576.0 / secs : 0.0, pipe.read_s, write_s);
    unlink(lock.c_str());
    return 0;
}
