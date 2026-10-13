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
bool remove_at(const std::string &path, int depth, std::size_t &files, std::string &bad, int &err,
               const std::function<void(std::size_t)> &progress)
{
    /* A file or a link: unlinked, and that's all (a link's target is never touched). */
    if (unlink(path.c_str()) == 0)
    {
        ++files;
        if (progress && files % 64 == 0) progress(files);
        return true;
    }
    const int e = errno;
    if (e == ENOENT || e == ENOTDIR) return true; /* nothing there */
    if (e != EPERM && e != EISDIR)
    {
        bad = path;
        err = e;
        return false;
    }
    /* A folder (unlink refuses those), or something that can't be removed. Opened as a
     * folder only if it really is one and not a link to one. */
    if (depth > 64)
    {
        bad = path;
        err = ELOOP;
        return false;
    }
    int fd = open(path.c_str(), O_RDONLY | O_DIRECTORY | O_NOFOLLOW);
    if (fd < 0 && errno == EINVAL) /* a system that refuses those flags: opendir says if it's a folder */
        fd = open(path.c_str(), O_RDONLY);
    if (fd < 0)
    {
        const int o = errno;
        if (o == ENOENT) return true;
        bad = path;
        err = (o == ENOTDIR || o == ELOOP || o == EMLINK) ? e : o; /* a file or link unlink refused: its error */
        return false;
    }
    close(fd);
    std::vector<std::string> names;
    DIR *d = opendir(path.c_str());
    if (!d)
    {
        bad = path;
        err = errno;
        return false;
    }
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
    for (const std::string &n : names)
        if (!remove_at(path + "/" + n, depth + 1, files, bad, err, progress)) return false;
    if (rmdir(path.c_str()) != 0 && errno != ENOENT)
    {
        bad = path;
        err = errno;
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

bool remove_tree(const std::string &path, std::size_t &files, std::string &bad, int &err,
                 const std::function<void(std::size_t)> &progress)
{
    bad.clear();
    err = 0;
    if (path.empty() || path[0] != '/') { bad = path; err = EINVAL; return false; }
    const bool ok = remove_at(path, 0, files, bad, err, progress);
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
} // namespace recomp::fs
