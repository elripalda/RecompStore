/* Recomp Store - Xbox 360 disc images (XDVDFS), read straight from the .iso.
 *
 * Ported from the SvR 2011 launcher's disc code: the volume descriptor is
 * looked for at the offsets a full disc image and the usual trimmed images
 * use, then the directory tables are walked into a flat list of files.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <cstdint>
#include <string>
#include <vector>

namespace recomp
{
struct DiscFile
{
    std::string path; /* relative, with '/' */
    std::uint32_t sector = 0, size = 0;
    bool is_dir = false;
};

class Disc
{
public:
    ~Disc() { close(); }
    /* Opens an image and lists its files. On failure err says why, for the player. */
    bool open(const std::string &image, std::string &err);
    void close();
    /* default.xex's title ID (execution info), 0 when it can't be read. */
    std::uint32_t title_id();
    bool read(std::uint64_t off, void *buf, std::size_t n);
    const std::vector<DiscFile> &files() const { return files_; }
    std::uint64_t total() const { return total_; }
    std::size_t file_count() const { return nfiles_; }
    const DiscFile *find(const std::string &path) const;

private:
    bool dir(std::uint32_t sector, std::uint32_t size, const std::string &prefix, int depth);
    int fd_ = -1; /* read with pread: big reads straight from the drive, no stdio buffer */
    std::uint64_t base_ = 0;
    std::vector<DiscFile> files_;
    std::uint64_t total_ = 0;
    std::size_t nfiles_ = 0;
};
} // namespace recomp
