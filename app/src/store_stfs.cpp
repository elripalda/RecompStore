/* Recomp Store - Xbox 360 content packages (STFS). See store_stfs.hpp.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#include "store_stfs.hpp"
#include "store_zip.hpp"

#include <algorithm>
#include <cctype>
#include <cerrno>
#include <cstdio>
#include <cstring>
#include <fcntl.h>
#include <map>
#include <sys/stat.h>
#include <unistd.h>

namespace recomp::stfs
{
namespace
{
constexpr std::uint32_t kBlock = 0x1000, kEnd = 0xFFFFFF;
constexpr std::uint32_t kPerLevel[3] = {170, 28900, 4913000};

std::uint32_t be32(const std::uint8_t *p) { return std::uint32_t(p[0]) << 24 | std::uint32_t(p[1]) << 16 | std::uint32_t(p[2]) << 8 | p[3]; }
std::uint32_t le24(const std::uint8_t *p) { return std::uint32_t(p[0]) | std::uint32_t(p[1]) << 8 | std::uint32_t(p[2]) << 16; }

bool pread_all(int fd, void *buf, std::size_t n, std::uint64_t at)
{
    auto *p = static_cast<std::uint8_t *>(buf);
    while (n)
    {
        const ssize_t r = ::pread(fd, p, n, off_t(at));
        if (r < 0 && errno == EINTR) continue;
        if (r <= 0) return false;
        p += r;
        n -= std::size_t(r);
        at += std::uint64_t(r);
    }
    return true;
}

std::uint32_t hash_block_number(std::uint32_t block)
{
    if (block < kPerLevel[0]) return 0;
    std::uint32_t n = (block / kPerLevel[0]) * (kPerLevel[0] + 1);
    n += (block / kPerLevel[1]) + 1;
    if (block < kPerLevel[1]) return n;
    return n + 1;
}

std::string lower(std::string s)
{
    for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
    return s;
}
} // namespace

std::uint64_t Package::offset_of(std::uint32_t block) const
{
    std::uint64_t b = block;
    for (std::uint32_t base : kPerLevel)
    {
        b += (std::uint64_t(block) + base) / base;
        if (block < base) break;
    }
    return base_ + (b << 12);
}

bool Package::next_block(std::uint32_t block, std::uint32_t &next) const
{
    const std::uint64_t at = base_ + (std::uint64_t(hash_block_number(block)) << 12);
    if (at != hash_at_)
    {
        hash_.resize(kBlock);
        if (at + kBlock > size_ || !pread_all(fd_, hash_.data(), kBlock, at))
        {
            hash_at_ = ~std::uint64_t(0);
            return false;
        }
        hash_at_ = at;
    }
    next = be32(&hash_[(block % kPerLevel[0]) * 0x18 + 0x14]) & 0xFFFFFF;
    return true;
}

bool Package::open(const std::string &path, std::string &why)
{
    close();
    fd_ = ::open(path.c_str(), O_RDONLY);
    if (fd_ < 0)
    {
        why = "That file couldn't be opened.";
        return false;
    }
    struct stat st;
    if (fstat(fd_, &st) != 0 || st.st_size < 0x1000)
    {
        why = "That file is too small to be a content package.";
        return false;
    }
    size_ = std::uint64_t(st.st_size);
    std::vector<std::uint8_t> h(0x1000);
    if (!pread_all(fd_, h.data(), h.size(), 0))
    {
        why = "That file couldn't be read.";
        return false;
    }
    if (std::memcmp(h.data(), "LIVE", 4) != 0 && std::memcmp(h.data(), "PIRS", 4) != 0 && std::memcmp(h.data(), "CON ", 4) != 0)
    {
        why = "That isn't an Xbox 360 content package (a Title Update or DLC file from the console).";
        return false;
    }
    const std::uint32_t header_size = be32(&h[0x340]);
    content_type_ = be32(&h[0x344]);
    title_id_ = be32(&h[0x360]);
    for (int i = 0; i < 0x80; i += 2) /* UTF-16BE: the first language's name; ASCII is all a folder needs */
    {
        const std::uint16_t c = std::uint16_t(h[0x411 + i] << 8 | h[0x412 + i]);
        if (c == 0) break;
        display_name_ += c < 0x80 ? char(c) : '_';
    }
    if (be32(&h[0x3A9]) != 0)
    {
        why = "That package is stored the way game installs are (SVOD), which isn't supported here.";
        return false;
    }
    const std::uint8_t *vd = &h[0x379];
    if (vd[0] != 0x24 || !(vd[2] & 1))
    {
        why = "That package isn't a read-only one (as Xbox Live delivers them), so it can't be read here.";
        return false;
    }
    base_ = ((std::uint64_t(header_size) + kBlock - 1) / kBlock) * kBlock;
    const std::uint32_t table_blocks = std::uint32_t(vd[3]) | std::uint32_t(vd[4]) << 8;
    std::uint32_t table = le24(&vd[5]);

    std::map<std::uint32_t, std::string> dirs; /* entry index -> its path with a trailing / */
    std::uint32_t index = 0;
    std::vector<std::uint8_t> block(kBlock);
    for (std::uint32_t i = 0; i < table_blocks && table != kEnd; ++i)
    {
        const std::uint64_t at = offset_of(table);
        if (at + kBlock > size_ || !pread_all(fd_, block.data(), kBlock, at))
        {
            why = "The package's file list is cut short: the file may be incomplete.";
            return false;
        }
        for (std::uint32_t j = 0; j < kBlock / 0x40; ++j)
        {
            const std::uint8_t *e = &block[j * 0x40];
            if (e[0] == 0) break;
            const std::uint8_t flags = e[0x28];
            const std::size_t len = std::min<std::size_t>(flags & 0x3F, 40);
            const std::uint16_t parent = std::uint16_t(e[0x32] << 8 | e[0x33]);
            auto it = dirs.find(parent);
            const std::string base = it == dirs.end() ? std::string() : it->second;
            const std::string name = base + std::string(reinterpret_cast<const char *>(e), len);
            if (flags & 0x80)
                dirs[index] = name + "/";
            else
            {
                Entry en;
                en.name = name;
                en.size = be32(&e[0x34]);
                en.block = le24(&e[0x2F]);
                en.blocks = le24(&e[0x2C]);
                en.contiguous = (flags & 0x40) != 0;
                entries_.push_back(en);
            }
            ++index;
        }
        if (!next_block(table, table))
        {
            why = "The package's file list is damaged.";
            return false;
        }
    }
    if (entries_.empty())
    {
        why = "That package has no files in it.";
        return false;
    }
    return true;
}

void Package::close()
{
    if (fd_ >= 0) ::close(fd_);
    fd_ = -1;
    hash_at_ = ~std::uint64_t(0);
    entries_.clear();
    display_name_.clear();
}

const Entry *Package::find(const std::string &name) const
{
    const std::string want = lower(name);
    for (const Entry &e : entries_)
        if (lower(e.name) == want) return &e;
    return nullptr;
}

bool Package::read(const Entry &e, const std::function<bool(const std::uint8_t *, std::size_t)> &sink) const
{
    std::uint64_t left = e.size;
    std::uint32_t block = e.block, used = 0;
    std::vector<std::uint8_t> buf;
    while (left > 0)
    {
        if (block == kEnd || used >= e.blocks) return false;
        /* A run: the blocks the chain names next that also lie next to each other in the
         * package (at most 170, up to what the file still needs), read at once. */
        const std::uint64_t at = offset_of(block);
        std::uint32_t run = 1, last = block, after = kEnd;
        for (;;)
        {
            if (!next_block(last, after)) return false;
            if (run >= 170 || used + run >= e.blocks || std::uint64_t(run) * kBlock >= left || after == kEnd ||
                offset_of(after) != at + std::uint64_t(run) * kBlock)
                break;
            last = after;
            ++run;
        }
        const std::size_t n = std::size_t(std::min<std::uint64_t>(left, std::uint64_t(run) * kBlock));
        if (at + n > size_) return false;
        buf.resize(n);
        if (!pread_all(fd_, buf.data(), n, at)) return false;
        if (!sink(buf.data(), n)) return false;
        left -= n;
        used += run;
        block = after;
    }
    return true;
}

bool Package::read_small(const std::string &name, std::string &out, std::size_t limit) const
{
    out.clear();
    const Entry *e = find(name);
    if (!e || e->size > limit) return false;
    return read(*e, [&](const std::uint8_t *p, std::size_t n) {
        out.append(reinterpret_cast<const char *>(p), n);
        return true;
    });
}

bool looks_like(const std::string &path)
{
    const int fd = ::open(path.c_str(), O_RDONLY);
    if (fd < 0) return false;
    char m[4] = {};
    const bool ok = ::pread(fd, m, 4, 0) == 4 &&
                    (std::memcmp(m, "LIVE", 4) == 0 || std::memcmp(m, "PIRS", 4) == 0 || std::memcmp(m, "CON ", 4) == 0);
    ::close(fd);
    return ok;
}

std::string folder_name(const Package &p, const std::string &fallback)
{
    std::string out;
    for (char c : p.display_name())
        out += (std::isalnum(static_cast<unsigned char>(c)) || std::strchr(" &'.,()-_!", c)) ? c : '_';
    while (!out.empty() && (out.back() == ' ' || out.back() == '.')) out.pop_back();
    while (!out.empty() && (out[0] == ' ' || out[0] == '.')) out.erase(0, 1);
    return out.empty() || !zip::safe_name(out) ? fallback : out;
}

bool unpack(const std::string &package, const std::string &target, const Progress &progress, const Log &log,
            const Stop &stop, std::string &error, std::size_t &files)
{
    files = 0;
    Package p;
    if (!p.open(package, error)) return false;
    std::uint64_t total = 0, done = 0;
    for (const Entry &e : p.entries())
    {
        if (!zip::safe_name(e.name))
        {
            error = "The package has a file with an unsafe name, so nothing was set up.";
            return false;
        }
        total += e.size;
    }
    zip::make_dirs(target);
    if (progress) progress(0, total);
    for (const Entry &e : p.entries())
    {
        /* A port's own files may share the folder: never written over. */
        std::string top = lower(e.name.substr(0, e.name.find('/')));
        if (top == "eboot.bin" || top == "sce_sys" || top == "sce_module" || top == "recompstore.json")
        {
            if (log) log("stfs: skipped " + e.name + " (the port's own name)");
            done += e.size;
            continue;
        }
        const std::string path = target + "/" + e.name;
        struct stat st;
        if (stat(path.c_str(), &st) == 0 && std::uint64_t(st.st_size) == e.size)
        {
            done += e.size; /* already there, whole */
            ++files;
            if (progress) progress(done, total);
            continue;
        }
        const std::size_t slash = path.find_last_of('/');
        if (slash != std::string::npos) zip::make_dirs(path.substr(0, slash));
        const std::string part = path + ".part";
        const int fd = ::open(part.c_str(), O_CREAT | O_TRUNC | O_WRONLY, 0666);
        if (fd < 0)
        {
            error = "Couldn't write " + e.name + " (errno " + std::to_string(errno) + ").";
            return false;
        }
        bool write_ok = true, stopped = false;
        const bool read_ok = p.read(e, [&](const std::uint8_t *data, std::size_t n) {
            if (stop && stop()) { stopped = true; return false; }
            while (n)
            {
                const ssize_t w = ::write(fd, data, n);
                if (w < 0 && errno == EINTR) continue;
                if (w <= 0) { write_ok = false; return false; }
                data += w;
                n -= std::size_t(w);
                done += std::uint64_t(w);
            }
            if (progress) progress(done, total);
            return true;
        });
        const bool closed = ::close(fd) == 0;
        chmod(part.c_str(), 0777);
        if (stopped)
        {
            std::remove(part.c_str());
            error = "Stopped.";
            return false;
        }
        if (!read_ok || !write_ok || !closed || stat(part.c_str(), &st) != 0 || std::uint64_t(st.st_size) != e.size)
        {
            std::remove(part.c_str());
            error = !write_ok || !closed ? "Writing " + e.name + " failed. Check the PS5's free space."
                                         : e.name + " couldn't be read from the package: it may be incomplete.";
            if (log) log("stfs: " + e.name + ": " + error);
            return false;
        }
        if (std::rename(part.c_str(), path.c_str()) != 0)
        {
            std::remove(part.c_str());
            error = "Couldn't put " + e.name + " in place.";
            return false;
        }
        ++files;
    }
    if (log) log("stfs: " + std::to_string(files) + " files from " + package + " into " + target);
    return true;
}
} // namespace recomp::stfs
