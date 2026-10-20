/* Recomp Store - deleting a folder and everything in it, safely on the PS5 (see the header).
 * SPDX-License-Identifier: GPL-3.0-or-later */
#include "store_fs.hpp"

#include <algorithm>
#include <cerrno>
#include <cstring>
#include <dirent.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>
#include <vector>

#ifndef O_DIRECTORY
#define O_DIRECTORY 0
#endif

namespace recomp::fs
{
namespace
{
struct Ctx
{
    std::size_t &files;
    std::string &bad;
    int &err;
    const std::function<void(std::size_t)> &progress;
    const ActAs &act_as;
    const std::function<void(const std::string &)> &log;
    const std::vector<std::string> *keep;
};

/* The folder as a folder, only if it really is one and not a link to one. */
int open_folder(const std::string &path)
{
    int fd = open(path.c_str(), O_RDONLY | O_DIRECTORY | O_NOFOLLOW);
    if (fd < 0 && errno == EINVAL) /* a system that refuses those flags: opendir says if it's a folder */
        fd = open(path.c_str(), O_RDONLY);
    return fd;
}

/* cur: who this process is acting as here (nullptr: as it started). It is that again
 * whenever this returns. */
bool remove_at(const std::string &path, int depth, const Who *cur, Ctx &c, bool &held)
{
    /* Kept (a save folder): left as it is, and the folders it's in with it. */
    if (c.keep)
        for (const std::string &k : *c.keep)
            if (path == k)
            {
                held = true;
                if (c.log) c.log("kept " + path);
                return true;
            }
    /* A file or a link: unlinked, and that's all (a link's target is never touched). */
    if (unlink(path.c_str()) == 0)
    {
        ++c.files;
        if (c.progress && c.files % 64 == 0) c.progress(c.files);
        return true;
    }
    const int e = errno;
    if (e == ENOENT || e == ENOTDIR) return true; /* nothing there */
    if (e != EPERM && e != EISDIR)
    {
        c.bad = path;
        c.err = e;
        return false;
    }
    /* A folder (unlink refuses those), or something that can't be removed. */
    if (depth > 64)
    {
        c.bad = path;
        c.err = ELOOP;
        return false;
    }
    /* Into a folder this process is refused, as the folder's owner (a game's own). */
    Who owner;
    const Who *here = cur;
    bool switched = false;
    auto become_owner = [&]() -> bool {
        if (!c.act_as || switched) return false;
        struct stat st;
        if (stat(path.c_str(), &st) != 0) return false;
        owner.uid = unsigned(st.st_uid);
        owner.gid = unsigned(st.st_gid);
        if (cur && cur->uid == owner.uid && cur->gid == owner.gid) return false; /* it is already */
        if (!c.act_as(&owner))
        {
            c.act_as(cur); /* a switch that failed part way: back to who it was */
            return false;
        }
        if (c.log) c.log("acting as " + path + "'s owner (uid " + std::to_string(owner.uid) + ") to delete it");
        here = &owner;
        switched = true;
        return true;
    };
    /* Back to who it was for this folder's parent: what removing the folder itself needs. */
    auto back = [&]() -> bool { return !switched || c.act_as(cur); };
    auto fail = [&](const std::string &p, int er) {
        if (!back() && c.log) c.log("couldn't go back from acting as " + path + "'s owner");
        c.bad = p;
        c.err = er;
        return false;
    };

    int fd = open_folder(path);
    int o = fd < 0 ? errno : 0;
    if (fd < 0 && o == EACCES && become_owner())
    {
        fd = open_folder(path);
        o = fd < 0 ? errno : 0;
    }
    if (fd < 0)
    {
        if (o == ENOENT)
        {
            if (back()) return true;
            return fail(path, EPERM);
        }
        return fail(path, (o == ENOTDIR || o == ELOOP || o == EMLINK) ? e : o); /* a file or link unlink refused: its error */
    }
    close(fd);
    std::vector<std::string> names;
    DIR *d = opendir(path.c_str());
    if (!d) return fail(path, errno);
    while (dirent *x = readdir(d))
        if (std::strcmp(x->d_name, ".") != 0 && std::strcmp(x->d_name, "..") != 0) names.push_back(x->d_name);
    closedir(d);
    /* A game's folder: what makes it a game last, so one that couldn't be deleted whole
     * still shows as installed, and Delete can be pressed again. */
    if (depth == 0)
    {
        auto rank = [](const std::string &n) { return n == "eboot.bin" ? 2 : n == "sce_sys" ? 1 : 0; };
        std::stable_sort(names.begin(), names.end(), [&](const std::string &a, const std::string &b) { return rank(a) < rank(b); });
    }
    bool inner_held = false;
    for (const std::string &n : names)
    {
        if (remove_at(path + "/" + n, depth + 1, here, c, inner_held)) continue;
        /* A folder this process could read but not change (its owner's alone to change):
         * once more as its owner. */
        if (c.err == EACCES && become_owner() && remove_at(path + "/" + n, depth + 1, here, c, inner_held))
        {
            c.bad.clear();
            c.err = 0;
            continue;
        }
        return fail(c.bad, c.err);
    }
    if (!back())
    {
        c.bad = path;
        c.err = EPERM;
        if (c.log) c.log("couldn't go back from acting as " + path + "'s owner");
        return false;
    }
    if (inner_held)
    {
        held = true; /* something kept is in it: it stays, emptied of the rest */
        return true;
    }
    if (rmdir(path.c_str()) != 0 && errno != ENOENT)
    {
        c.bad = path;
        c.err = errno;
        return false;
    }
    return true;
}

std::vector<std::string> parts(const std::string &path)
{
    std::vector<std::string> out;
    std::size_t at = 1;
    while (at <= path.size())
    {
        std::size_t end = path.find('/', at);
        if (end == std::string::npos) end = path.size();
        out.push_back(path.substr(at, end - at));
        at = end + 1;
    }
    return out;
}
} // namespace

bool holds(const std::string &path, const std::vector<std::string> &keep)
{
    for (const std::string &k : keep)
        if (k == path || k.rfind(path + "/", 0) == 0) return true;
    return false;
}

bool remove_tree(const std::string &path, std::size_t &files, std::string &bad, int &err,
                 const std::function<void(std::size_t)> &progress, const ActAs &act_as,
                 const std::function<void(const std::string &)> &log, const std::vector<std::string> *keep)
{
    bad.clear();
    err = 0;
    if (path.empty() || path[0] != '/') { bad = path; err = EINVAL; return false; }
    Ctx c{files, bad, err, progress, act_as, log, keep && !keep->empty() ? keep : nullptr};
    bool held = false;
    const bool ok = remove_at(path, 0, nullptr, c, held);
    if (progress) progress(files);
    return ok;
}

bool gone(const std::string &path)
{
    struct stat st;
    if (stat(path.c_str(), &st) == 0) return false;
    return errno == ENOENT || errno == ENOTDIR;
}

bool deletable(const std::string &path, const std::string &store_data, const std::string &root)
{
    const bool on_data = path.rfind(root, 0) == 0, on_mnt = path.rfind("/mnt/", 0) == 0;
    if (!on_data && !on_mnt) return false;
    if (path.find("//") != std::string::npos || path.back() == '/') return false;
    for (const char c : path)
        if (static_cast<unsigned char>(c) < 0x20 || c == 0x7F) return false; /* a job file is one path per line */
    const std::vector<std::string> p = parts(path);
    for (const std::string &s : p)
        if (s.empty() || s == "." || s == "..") return false;
    const std::size_t root_parts = parts(root.substr(0, root.size() - 1)).size(); /* "/data" is 1 */
    if (p.size() < (on_mnt ? 4 : root_parts + 2)) return false;
    if (!store_data.empty() && (path == store_data || path.rfind(store_data + "/", 0) == 0)) return false;
    /* On a drive, only a game's folder in its homebrew folder: nothing of the console's
     * own (an app's sandbox, the apps it installed) or the player's. */
    if (on_mnt && (p[1] == "sandbox" || std::find(p.begin(), p.end(), "homebrew") == p.end())) return false;
    for (std::size_t i = 0; i < p.size(); ++i)
        if (p[i] == "homebrew")
        {
            /* Inside a homebrew folder: a game's own folder, and never the store's. */
            if (i + 2 != p.size() || p.back() == "PPSA92000") return false;
        }
    return true;
}

void remove_empty_parents(const std::string &path, const std::function<void(const std::string &)> &log)
{
    const bool on_mnt = path.rfind("/mnt/", 0) == 0;
    std::string up = path.substr(0, path.find_last_of('/'));
    for (int i = 0; i < 2; ++i)
    {
        const std::size_t n = parts(up).size();
        if (n < (on_mnt ? 3u : 2u) || rmdir(up.c_str()) != 0) return; /* not empty, or as high as it goes */
        if (log) log(up);
        up = up.substr(0, up.find_last_of('/'));
    }
}

/* ---- (2.2) The App manager: a folder's size, a copy of it, and checking the copy -------------- */
namespace
{
/* A folder's entries (not . and ..); false with errno when it can't be listed. */
bool list_names(const std::string &path, std::vector<std::string> &names, int &e)
{
    names.clear();
    DIR *d = opendir(path.c_str());
    if (!d)
    {
        e = errno;
        return false;
    }
    while (dirent *x = readdir(d))
        if (std::strcmp(x->d_name, ".") != 0 && std::strcmp(x->d_name, "..") != 0) names.push_back(x->d_name);
    closedir(d);
    return true;
}

/* A folder this process is refused: listed as its owner (a game's own folder, mode 0700), as
 * remove_tree does. here becomes the owner when it switched; the caller switches back to cur. */
bool list_as_owner(const std::string &path, const Who *cur, Who &owner, const Who *&here, bool &switched,
                   std::vector<std::string> &names, int &e, const ActAs &act_as)
{
    if (list_names(path, names, e)) return true;
    if (e != EACCES || !act_as || switched) return false;
    struct stat st;
    if (stat(path.c_str(), &st) != 0) return false;
    owner.uid = unsigned(st.st_uid);
    owner.gid = unsigned(st.st_gid);
    if (cur && cur->uid == owner.uid && cur->gid == owner.gid) return false;
    if (!act_as(&owner))
    {
        act_as(cur);
        return false;
    }
    switched = true;
    here = &owner;
    return list_names(path, names, e);
}

bool size_at(const std::string &path, int depth, const Who *cur, const ActAs &act_as, std::uint64_t &bytes, std::size_t &files)
{
    struct stat st;
    if (stat(path.c_str(), &st) != 0) return false;
    if (!S_ISDIR(st.st_mode))
    {
        if (S_ISREG(st.st_mode))
        {
            bytes += std::uint64_t(st.st_size);
            ++files;
        }
        return true;
    }
    if (depth > 64) return false;
    std::vector<std::string> names;
    int e = 0;
    Who owner;
    const Who *here = cur;
    bool switched = false;
    if (!list_as_owner(path, cur, owner, here, switched, names, e, act_as))
    {
        if (switched) act_as(cur);
        return false;
    }
    bool ok = true;
    for (const std::string &n : names) ok = size_at(path + "/" + n, depth + 1, here, act_as, bytes, files) && ok;
    if (switched && !act_as(cur)) return false;
    return ok;
}

struct CopyCtx
{
    std::uint64_t &bytes;
    std::size_t &files;
    std::string &bad;
    int &err;
    const std::function<bool(std::uint64_t)> &progress;
    const ActAs &act_as;
    const std::function<void(const std::string &)> &log;
    std::vector<unsigned char> buf;
};

bool copy_file(const std::string &from, const std::string &to, std::uint64_t size, CopyCtx &c)
{
    const int in = open(from.c_str(), O_RDONLY);
    if (in < 0)
    {
        c.bad = from;
        c.err = errno;
        return false;
    }
    const int out = open(to.c_str(), O_WRONLY | O_CREAT | O_EXCL, 0777);
    if (out < 0)
    {
        c.bad = to;
        c.err = errno;
        close(in);
        return false;
    }
    std::uint64_t done = 0;
    bool ok = true;
    for (;;)
    {
        const ssize_t n = pread(in, c.buf.data(), c.buf.size(), off_t(done)); /* pread: what the copier already reads with */
        if (n < 0 && errno == EINTR) continue;
        if (n < 0)
        {
            c.bad = from;
            c.err = errno;
            ok = false;
            break;
        }
        if (n == 0) break;
        std::size_t at = 0;
        while (at < std::size_t(n))
        {
            const ssize_t w = write(out, c.buf.data() + at, std::size_t(n) - at);
            if (w < 0 && errno == EINTR) continue;
            if (w <= 0)
            {
                c.bad = to;
                c.err = w < 0 ? errno : ENOSPC;
                ok = false;
                break;
            }
            at += std::size_t(w);
        }
        if (!ok) break;
        done += std::uint64_t(n);
        c.bytes += std::uint64_t(n);
        if (c.progress && !c.progress(c.bytes))
        {
            c.bad = from;
            c.err = ECANCELED;
            ok = false;
            break;
        }
    }
    close(in);
    if (close(out) != 0 && ok)
    {
        c.bad = to;
        c.err = errno;
        ok = false;
    }
    if (ok && done != size)
    {
        c.bad = from; /* it changed while it was copied */
        c.err = EIO;
        ok = false;
    }
    if (ok)
    {
        chmod(to.c_str(), 0777);
        ++c.files;
    }
    return ok;
}

bool copy_at(const std::string &from, const std::string &to, int depth, const Who *cur, CopyCtx &c)
{
    struct stat st;
    if (stat(from.c_str(), &st) != 0)
    {
        c.bad = from;
        c.err = errno;
        return false;
    }
    if (!S_ISDIR(st.st_mode))
    {
        if (S_ISREG(st.st_mode)) return copy_file(from, to, std::uint64_t(st.st_size), c);
        if (c.log) c.log("left out " + from + " (not a file or a folder)");
        return true;
    }
    if (depth > 64)
    {
        c.bad = from;
        c.err = ELOOP;
        return false;
    }
    std::vector<std::string> names;
    int e = 0;
    Who owner;
    const Who *here = cur;
    bool switched = false;
    auto back = [&]() { return !switched || c.act_as(cur); };
    if (!list_as_owner(from, cur, owner, here, switched, names, e, c.act_as))
    {
        back();
        c.bad = from;
        c.err = e ? e : EACCES;
        return false;
    }
    if (switched && c.log) c.log("reading " + from + " as its owner (uid " + std::to_string(owner.uid) + ")");
    if (mkdir(to.c_str(), 0777) != 0 && !(depth > 0 && errno == EEXIST))
    {
        c.bad = to;
        c.err = errno;
        back();
        return false;
    }
    chmod(to.c_str(), 0777);
    for (const std::string &n : names)
        if (!copy_at(from + "/" + n, to + "/" + n, depth + 1, here, c))
        {
            back();
            return false;
        }
    if (!back())
    {
        c.bad = from;
        c.err = EPERM;
        return false;
    }
    return true;
}

bool same_at(const std::string &from, const std::string &to, int depth, const Who *cur, const ActAs &act_as, std::string &bad)
{
    struct stat a, b;
    if (stat(from.c_str(), &a) != 0)
    {
        bad = from;
        return false;
    }
    if (!S_ISDIR(a.st_mode))
    {
        if (!S_ISREG(a.st_mode)) return true; /* left out by copy_tree */
        if (stat(to.c_str(), &b) != 0 || !S_ISREG(b.st_mode) || b.st_size != a.st_size)
        {
            bad = to;
            return false;
        }
        return true;
    }
    if (depth > 64 || stat(to.c_str(), &b) != 0 || !S_ISDIR(b.st_mode))
    {
        bad = to;
        return false;
    }
    std::vector<std::string> names;
    int e = 0;
    Who owner;
    const Who *here = cur;
    bool switched = false;
    if (!list_as_owner(from, cur, owner, here, switched, names, e, act_as))
    {
        if (switched) act_as(cur);
        bad = from;
        return false;
    }
    bool ok = true;
    for (const std::string &n : names)
        if (!same_at(from + "/" + n, to + "/" + n, depth + 1, here, act_as, bad))
        {
            ok = false;
            break;
        }
    if (switched && !act_as(cur)) ok = false;
    return ok;
}
} // namespace

bool tree_size(const std::string &path, std::uint64_t &bytes, std::size_t &files, const ActAs &act_as)
{
    bytes = 0;
    files = 0;
    if (path.empty() || path[0] != '/') return false;
    return size_at(path, 0, nullptr, act_as, bytes, files);
}

bool copy_tree(const std::string &from, const std::string &to, std::uint64_t &bytes, std::size_t &files, std::string &bad,
               int &err, const std::function<bool(std::uint64_t)> &progress, const ActAs &act_as,
               const std::function<void(const std::string &)> &log)
{
    bytes = 0;
    files = 0;
    bad.clear();
    err = 0;
    if (from.empty() || from[0] != '/' || to.empty() || to[0] != '/' || to == from || to.rfind(from + "/", 0) == 0)
    {
        bad = to;
        err = EINVAL;
        return false;
    }
    struct stat st;
    if (stat(to.c_str(), &st) == 0)
    {
        bad = to;
        err = EEXIST;
        return false;
    }
    CopyCtx c{bytes, files, bad, err, progress, act_as, log, {}};
    c.buf.resize(4u << 20);
    return copy_at(from, to, 0, nullptr, c);
}

bool same_tree(const std::string &from, const std::string &to, std::string &bad, const ActAs &act_as)
{
    bad.clear();
    return same_at(from, to, 0, nullptr, act_as, bad);
}
} // namespace recomp::fs
