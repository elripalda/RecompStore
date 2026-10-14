/* Recomp Store - reading Xbox 360 content packages (STFS: LIVE, PIRS and CON), the way a
 * Title Update or a piece of DLC comes off the console, and unpacking one into a folder.
 *
 * The layout follows Xenia's xcontent_container_device (Ben Vanik, BSD) as Unleashed
 * Recompiled's installer reads it (xcontent_file_system.cpp, GPL-3.0-or-later). Only the
 * read-only packages Xbox Live delivers are read. A file's blocks are always found by
 * following the package's own chain (its hash tables, read 170 entries at a time), and
 * blocks that lie next to each other in the package are read in one go.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <cstdint>
#include <functional>
#include <string>
#include <vector>

namespace recomp::stfs
{
/* Content types in the header: a Title Update, DLC, an Xbox Live Arcade game. */
constexpr std::uint32_t kTitleUpdate = 0x000B0000, kMarketplace = 0x00000002, kArcade = 0x000D0000;

struct Entry
{
    std::string name; /* "folder/file" inside the package */
    std::uint64_t size = 0;
    std::uint32_t block = 0, blocks = 0;
    bool contiguous = false;
};

class Package
{
public:
    ~Package() { close(); }
    /* false and why when it isn't a package this can read. */
    bool open(const std::string &path, std::string &why);
    void close();

    std::uint32_t title_id() const { return title_id_; }
    std::uint32_t content_type() const { return content_type_; }
    const std::string &display_name() const { return display_name_; }
    const std::vector<Entry> &entries() const { return entries_; }
    const Entry *find(const std::string &name) const; /* case-insensitive */

    /* A file's bytes, in order, a piece at a time. sink may return false to stop. */
    bool read(const Entry &e, const std::function<bool(const std::uint8_t *, std::size_t)> &sink) const;
    bool read_small(const std::string &name, std::string &out, std::size_t limit = 1 << 20) const;

private:
    std::uint64_t offset_of(std::uint32_t block) const;
    bool next_block(std::uint32_t block, std::uint32_t &next) const;

    /* The hash block last read (its block number): the chain is read 170 entries at a time. */
    mutable std::vector<std::uint8_t> hash_;
    mutable std::uint64_t hash_at_ = ~std::uint64_t(0);

    int fd_ = -1;
    std::uint64_t size_ = 0, base_ = 0;
    std::uint32_t title_id_ = 0, content_type_ = 0;
    std::string display_name_;
    std::vector<Entry> entries_;
};

/* The first four bytes say LIVE, PIRS or "CON ". */
bool looks_like(const std::string &path);

using Progress = std::function<void(std::uint64_t done, std::uint64_t total)>;
using Log = std::function<void(const std::string &line)>;
using Stop = std::function<bool()>;
/* Every file in the package into target (made if needed), each written as <name>.part,
 * checked for its size, then renamed into place. Files already there at their full size
 * are kept, so a stopped unpack carries on. */
bool unpack(const std::string &package, const std::string &target, const Progress &progress, const Log &log,
            const Stop &stop, std::string &error, std::size_t &files);
/* A folder name for a package's contents (its display name, made safe), e.g. for DLC. */
std::string folder_name(const Package &p, const std::string &fallback);
} // namespace recomp::stfs
