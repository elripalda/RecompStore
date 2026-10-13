/* Recomp Store - unpacking a game's zip into its folder.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_zip.hpp"

#include <algorithm>
#include <cerrno>
#include <cstdio>
#include <cstring>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>

/* Only its zlib decoder is used: the rest of stb_image is unused here. */
#pragma clang diagnostic push
#pragma clang diagnostic ignored "-Wunused-function"
#define STB_IMAGE_IMPLEMENTATION
#define STB_IMAGE_STATIC
#define STBI_ONLY_PNG
#define STBI_NO_STDIO
#include "stb/stb_image.h"
#pragma clang diagnostic pop

namespace recomp::zip
{
namespace
{
std::uint32_t le32(const std::uint8_t *p)
{
    return std::uint32_t(p[0]) | std::uint32_t(p[1]) << 8 | std::uint32_t(p[2]) << 16 | std::uint32_t(p[3]) << 24;
}
std::uint16_t le16(const std::uint8_t *p) { return std::uint16_t(p[0] | p[1] << 8); }

/* pread on the file itself: big reads straight from the drive. */
bool read_at(int fd, std::uint64_t at, void *dst, std::size_t n)
{
    auto *p = static_cast<std::uint8_t *>(dst);
    while (n)
    {
        const ssize_t got = pread(fd, p, n, off_t(at));
        if (got < 0 && errno == EINTR)
            continue;
        if (got <= 0)
            return false;
        p += got;
        at += std::uint64_t(got);
        n -= std::size_t(got);
    }
    return true;
}

/* Every byte (write() may take fewer than asked), synced, closed, left 0777 like an
 * FTP upload so the system can load an eboot.bin or .prx from it. */
bool write_whole(const std::string &path, const std::uint8_t *data, std::size_t n, std::string &why)
{
    const int fd = ::open(path.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0777);
    if (fd < 0)
    {
        why = "open errno " + std::to_string(errno);
        return false;
    }
    std::size_t at = 0;
    while (at < n)
    {
        const ssize_t w = ::write(fd, data + at, n - at);
        if (w < 0 && errno == EINTR)
            continue;
        if (w <= 0)
        {
            why = "write errno " + std::to_string(errno) + " at " + std::to_string(at);
            ::close(fd);
            return false;
        }
        at += std::size_t(w);
    }
    fsync(fd);
    const bool closed = ::close(fd) == 0;
    chmod(path.c_str(), 0777);
    if (!closed)
        why = "close errno " + std::to_string(errno);
    return closed;
}

/* src in dst's place. If renaming over the old file is refused, the old one is moved
 * aside to <dst>.old first and put back if the new one still can't go in, so a failed
 * swap never leaves the file missing. */
bool replace(const std::string &src, const std::string &dst, const Log &log)
{
    if (std::rename(src.c_str(), dst.c_str()) == 0)
        return true;
    const int first = errno;
    const std::string old = dst + ".old";
    struct stat st;
    const bool had = lstat(dst.c_str(), &st) == 0;
    if (had)
    {
        std::remove(old.c_str());
        if (std::rename(dst.c_str(), old.c_str()) != 0)
        {
            if (log)
                log("replace " + dst + ": errno " + std::to_string(first) + ", and it couldn't be moved aside (errno " +
                    std::to_string(errno) + "); left as it was");
            return false;
        }
    }
    if (std::rename(src.c_str(), dst.c_str()) == 0)
    {
        if (had)
            std::remove(old.c_str());
        if (log)
            log("replace " + dst + ": rename over it failed (errno " + std::to_string(first) + "), moved the old one aside first");
        return true;
    }
    const int second = errno;
    if (had && std::rename(old.c_str(), dst.c_str()) != 0 && log)
        log("replace " + dst + ": couldn't put the old file back (errno " + std::to_string(errno) + "); it is at " + old);
    if (log)
        log("replace " + dst + ": errno " + std::to_string(first) + ", then " + std::to_string(second) + "; the old file kept");
    return false;
}
} // namespace

std::uint32_t crc32(const std::uint8_t *p, std::size_t n, std::uint32_t crc)
{
    static std::uint32_t table[256];
    static bool made = false;
    if (!made)
    {
        for (std::uint32_t i = 0; i < 256; ++i)
        {
            std::uint32_t c = i;
            for (int k = 0; k < 8; ++k)
                c = c & 1 ? 0xEDB88320u ^ (c >> 1) : c >> 1;
            table[i] = c;
        }
        made = true;
    }
    crc = ~crc;
    while (n--)
        crc = table[(crc ^ *p++) & 0xFF] ^ (crc >> 8);
    return ~crc;
}

bool list(int fd, std::vector<Entry> &out)
{
    struct stat st;
    if (fstat(fd, &st) != 0)
        return false;
    const std::uint64_t size = std::uint64_t(st.st_size);
    if (size < 22)
        return false;
    const std::size_t tail_n = std::size_t(std::min<std::uint64_t>(size, 70000));
    std::vector<std::uint8_t> tail(tail_n);
    if (!read_at(fd, size - tail_n, tail.data(), tail_n))
        return false;
    std::size_t eocd = std::string::npos;
    for (std::size_t i = tail_n - 22 + 1; i-- > 0;)
        if (le32(&tail[i]) == 0x06054b50)
        {
            eocd = i;
            break;
        }
    if (eocd == std::string::npos)
        return false;
    const unsigned entries = le16(&tail[eocd + 10]);
    const std::uint32_t cd_size = le32(&tail[eocd + 12]), cd_at = le32(&tail[eocd + 16]);
    if (std::uint64_t(cd_at) + cd_size > size)
        return false;
    std::vector<std::uint8_t> cdir(cd_size);
    if (cd_size && !read_at(fd, cd_at, cdir.data(), cd_size))
        return false;
    std::size_t cd = 0;
    for (unsigned e = 0; e < entries; ++e)
    {
        if (cd + 46 > cdir.size() || le32(&cdir[cd]) != 0x02014b50)
            return false;
        Entry en;
        en.method = le16(&cdir[cd + 10]);
        en.crc = le32(&cdir[cd + 16]);
        en.csize = le32(&cdir[cd + 20]);
        en.usize = le32(&cdir[cd + 24]);
        const std::uint16_t nl = le16(&cdir[cd + 28]), xl = le16(&cdir[cd + 30]), cl = le16(&cdir[cd + 32]);
        en.local = le32(&cdir[cd + 42]);
        if (cd + 46 + nl > cdir.size())
            return false;
        en.name.assign(reinterpret_cast<const char *>(&cdir[cd + 46]), nl);
        cd += 46 + std::size_t(nl) + xl + cl;
        out.push_back(en);
    }
    return true;
}

bool extract(int fd, const Entry &en, std::vector<std::uint8_t> &out)
{
    std::uint8_t lh[30];
    if (!read_at(fd, en.local, lh, sizeof lh) || le32(lh) != 0x04034b50)
        return false;
    const std::uint64_t data = std::uint64_t(en.local) + 30 + le16(lh + 26) + le16(lh + 28);
    std::vector<std::uint8_t> packed(en.csize);
    if (en.csize && !read_at(fd, data, packed.data(), en.csize))
        return false;
    if (en.method == 0)
        out.swap(packed);
    else if (en.method == 8)
    {
        out.resize(en.usize);
        /* The whole stream in one call, into a buffer exactly the size the zip records. */
        const int len = en.usize == 0 ? 0
                                      : stbi_zlib_decode_noheader_buffer(reinterpret_cast<char *>(out.data()), int(en.usize),
                                                                         reinterpret_cast<const char *>(packed.data()),
                                                                         int(en.csize));
        if (len != int(en.usize))
            return false;
    }
    else
        return false;
    /* Size and CRC-32 must both match what the zip's directory says. */
    return out.size() == en.usize && crc32(out.data(), out.size()) == en.crc;
}

bool safe_name(const std::string &n)
{
    if (n.empty() || n[0] == '/' || n[0] == '\\' || n.find('\\') != std::string::npos || n.find(':') != std::string::npos)
        return false;
    std::size_t at = 0;
    while (at <= n.size())
    {
        std::size_t end = n.find('/', at);
        if (end == std::string::npos)
            end = n.size();
        if (n.compare(at, end - at, "..") == 0 && end - at == 2)
            return false;
        at = end + 1;
    }
    return true;
}

std::string top_folder(const std::vector<Entry> &entries)
{
    if (entries.empty())
        return "";
    const std::size_t slash = entries[0].name.find('/');
    if (slash == std::string::npos)
        return "";
    const std::string top = entries[0].name.substr(0, slash + 1);
    for (const Entry &e : entries)
        if (e.name.rfind(top, 0) != 0)
            return "";
    return top;
}

bool file_matches(const std::string &path, std::uint64_t size, std::uint32_t crc, std::string *why)
{
    struct stat st;
    if (stat(path.c_str(), &st) != 0)
    {
        if (why)
            *why = "missing";
        return false;
    }
    if (std::uint64_t(st.st_size) != size)
    {
        if (why)
            *why = "size " + std::to_string((long long)st.st_size) + ", want " + std::to_string((unsigned long long)size);
        return false;
    }
    const int fd = ::open(path.c_str(), O_RDONLY);
    if (fd < 0)
    {
        if (why)
            *why = "can't open (errno " + std::to_string(errno) + ")";
        return false;
    }
    std::vector<std::uint8_t> buf(4u << 20);
    std::uint32_t c = 0;
    std::uint64_t at = 0;
    while (at < size)
    {
        const std::size_t n = std::size_t(std::min<std::uint64_t>(buf.size(), size - at));
        if (!read_at(fd, at, buf.data(), n))
        {
            ::close(fd);
            if (why)
                *why = "read failed";
            return false;
        }
        c = crc32(buf.data(), n, c);
        at += n;
    }
    ::close(fd);
    if (c != crc)
    {
        char t[64];
        std::snprintf(t, sizeof t, "CRC %08x, want %08x", c, crc);
        if (why)
            *why = t;
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
            if (mkdir(d.c_str(), 0777) == 0)
                chmod(d.c_str(), 0777);
        }
}

bool unpack(const std::string &zip_path, const std::string &root, const Progress &progress, const Log &log,
            const Stop &stop, std::string &error, bool &changed, std::size_t &files)
{
    changed = false;
    files = 0;
    const int fd = ::open(zip_path.c_str(), O_RDONLY);
    if (fd < 0)
    {
        error = "The download couldn't be opened.";
        return false;
    }
    struct Closer
    {
        int fd;
        ~Closer() { ::close(fd); }
    } closer{fd};
    std::vector<Entry> entries;
    if (!list(fd, entries) || entries.empty())
    {
        error = "The download isn't a zip the store can read.";
        return false;
    }
    /* A single top folder in the zip (PPSA92011/...) is the game's own folder. */
    const std::string strip = top_folder(entries);
    make_dirs(root);

    struct Written
    {
        std::string path;
        std::uint64_t size;
        std::uint32_t crc;
    };
    std::vector<Written> written;
    auto drop_new = [&] {
        for (const Written &w : written)
            std::remove((w.path + ".new").c_str());
    };
    std::uint64_t done = 0, total = 0;
    for (const Entry &e : entries)
        total += e.usize;
    for (const Entry &e : entries)
    {
        const std::string name = e.name.substr(strip.size());
        if (name.empty())
            continue;
        if (!safe_name(name))
        {
            drop_new();
            error = "The zip has a file with an unsafe name, so it wasn't installed.";
            return false;
        }
        const std::string path = root + "/" + name;
        if (name.back() == '/')
        {
            make_dirs(path.substr(0, path.size() - 1));
            continue;
        }
        make_dirs(path.substr(0, path.find_last_of('/')));
        if (progress)
            progress("Installing", done, total);
        if (stop && stop())
        {
            drop_new();
            error = "Stopped. Nothing was changed.";
            return false;
        }
        std::vector<std::uint8_t> data;
        if (!extract(fd, e, data))
        {
            if (log)
                log("extract " + name + " failed (size or CRC)");
            drop_new();
            error = "Unpacking " + name + " failed its check. Nothing was changed.";
            return false;
        }
        std::string why;
        if (!write_whole(path + ".new", data.data(), data.size(), why) || !file_matches(path + ".new", e.usize, e.crc, &why))
        {
            if (log)
                log("write " + name + ".new: " + why);
            std::remove((path + ".new").c_str());
            drop_new();
            error = "Writing " + name + " failed. Nothing was changed.";
            return false;
        }
        written.push_back({path, e.usize, e.crc});
        done += e.usize;
    }

    /* The swap: eboot.bin last, so a half-finished swap never leaves a new program with
     * old files. Then every file is read back and checked against the zip. */
    if (progress)
        progress("Checking the installed files", 0, written.size());
    std::vector<const Written *> order;
    const Written *eboot = nullptr;
    for (const Written &w : written)
        if (w.path == root + "/eboot.bin")
            eboot = &w;
        else
            order.push_back(&w);
    if (eboot)
        order.push_back(eboot);
    for (const Written *w : order)
    {
        if (!replace(w->path + ".new", w->path, log))
        {
            drop_new();
            error = "Couldn't replace " + w->path.substr(root.size() + 1) + ". The game may be half updated: install it again.";
            return false;
        }
        changed = true;
    }
    std::size_t checked = 0;
    for (const Written *w : order)
    {
        std::string why;
        if (!file_matches(w->path, w->size, w->crc, &why))
        {
            if (log)
                log("check " + w->path + ": " + why);
            error = w->path.substr(root.size() + 1) + " doesn't match the download after installing. Install it again.";
            return false;
        }
        if (progress)
            progress("Checking the installed files", ++checked, written.size());
    }
    files = written.size();
    return true;
}
} // namespace recomp::zip
