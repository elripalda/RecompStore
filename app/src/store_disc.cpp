/* Recomp Store - Xbox 360 disc images (XDVDFS).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_disc.hpp"

#include <cctype>
#include <cstring>
#include <fcntl.h>
#include <sys/types.h>
#include <unistd.h>
#include <cerrno>

namespace recomp
{
namespace
{
std::uint32_t be32(const std::uint8_t *p)
{
    return std::uint32_t(p[0]) << 24 | std::uint32_t(p[1]) << 16 | std::uint32_t(p[2]) << 8 | p[3];
}
std::uint32_t le32(const std::uint8_t *p)
{
    return std::uint32_t(p[0]) | std::uint32_t(p[1]) << 8 | std::uint32_t(p[2]) << 16 | std::uint32_t(p[3]) << 24;
}
} // namespace

bool Disc::read(std::uint64_t off, void *buf, std::size_t n)
{
    if (fd_ < 0)
        return false;
    auto *p = static_cast<std::uint8_t *>(buf);
    std::uint64_t at = base_ + off;
    while (n)
    {
        const ssize_t got = pread(fd_, p, n, off_t(at));
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

/* One directory table, entry by entry in the order they are stored:
 *   u16 left, u16 right, u32 sector, u32 size, u8 attributes, u8 name length, name
 * 4-byte aligned; 0xFFFF where an entry would start pads to the next sector. */
bool Disc::dir(std::uint32_t sector, std::uint32_t size, const std::string &prefix, int depth)
{
    if (depth > 32 || size > (16u << 20) || files_.size() > 1000000)
        return false;
    std::vector<std::uint8_t> buf(size);
    if (size && !read(std::uint64_t(sector) * 2048, buf.data(), size))
        return false;
    std::uint32_t off = 0;
    while (off + 14 <= size)
    {
        const std::uint8_t *e = &buf[off];
        const std::uint16_t left = std::uint16_t(e[0] | e[1] << 8);
        const std::uint8_t attr = e[12], nlen = e[13];
        if (left == 0xFFFF || nlen == 0)
        {
            off = (off / 2048 + 1) * 2048; /* padding to the next sector */
            continue;
        }
        if (off + 14 + nlen > size)
            return false;
        const std::string name(reinterpret_cast<const char *>(e + 14), nlen);
        if (name == "." || name == ".." || name.find_first_of("/\\:") != std::string::npos ||
            name.find('\0') != std::string::npos)
            return false; /* unsafe or broken */
        DiscFile df;
        df.path = prefix.empty() ? name : prefix + "/" + name;
        df.sector = le32(e + 4);
        df.size = le32(e + 8);
        df.is_dir = (attr & 0x10) != 0;
        files_.push_back(df);
        if (df.is_dir)
        {
            if (df.size && !dir(df.sector, df.size, df.path, depth + 1))
                return false;
        }
        else
        {
            ++nfiles_;
            total_ += df.size;
        }
        off = (off + 14 + nlen + 3) & ~3u;
    }
    return true;
}

bool Disc::open(const std::string &image, std::string &err)
{
    close();
    fd_ = ::open(image.c_str(), O_RDONLY);
    if (fd_ < 0)
    {
        err = "The disc image couldn't be opened.";
        return false;
    }
    /* A full disc image has the game partition further in; trimmed (XGD) images start with it. */
    static const std::uint64_t bases[] = {0, 0x18300000ull, 0xFD90000ull, 0x2080000ull};
    for (std::uint64_t b : bases)
    {
        base_ = b;
        std::uint8_t vd[2048];
        if (read(32 * 2048, vd, sizeof vd) && std::memcmp(vd, "MICROSOFT*XBOX*MEDIA", 20) == 0)
        {
            if (!dir(le32(vd + 20), le32(vd + 24), "", 0))
            {
                err = "The disc image is damaged: its file list couldn't be read.";
                close();
                return false;
            }
            return true;
        }
    }
    err = "This isn't an Xbox 360 disc image.";
    close();
    return false;
}

void Disc::close()
{
    if (fd_ >= 0)
        ::close(fd_);
    fd_ = -1;
    base_ = 0;
    files_.clear();
    total_ = 0;
    nfiles_ = 0;
}

const DiscFile *Disc::find(const std::string &path) const
{
    for (const DiscFile &f : files_)
    {
        if (f.is_dir || f.path.size() != path.size())
            continue;
        bool same = true;
        for (std::size_t i = 0; i < path.size() && same; ++i)
            same = std::tolower(static_cast<unsigned char>(f.path[i])) == std::tolower(static_cast<unsigned char>(path[i]));
        if (same)
            return &f;
    }
    return nullptr;
}

/* The title ID in default.xex's execution info (optional header 0x00040006). */
std::uint32_t Disc::title_id()
{
    const DiscFile *x = find("default.xex");
    if (!x || x->size < 24)
        return 0;
    const std::uint64_t base = std::uint64_t(x->sector) * 2048;
    std::uint8_t hdr[24];
    if (!read(base, hdr, 24) || std::memcmp(hdr, "XEX2", 4) != 0)
        return 0;
    const std::uint32_t n = be32(hdr + 20);
    if (n == 0 || n > 64 || 24 + n * 8 > x->size)
        return 0;
    std::uint8_t opt[64 * 8];
    if (!read(base + 24, opt, n * 8))
        return 0;
    for (std::uint32_t i = 0; i < n; ++i)
        if (be32(opt + i * 8) == 0x00040006u)
        {
            const std::uint32_t at = be32(opt + i * 8 + 4);
            std::uint8_t exe[16];
            if (at + 16 > x->size || !read(base + at, exe, 16))
                return 0;
            return be32(exe + 12);
        }
    return 0;
}
} // namespace recomp
