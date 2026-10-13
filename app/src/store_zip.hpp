/* Recomp Store - unpacking a game's zip into its folder, shared by the store and its
 * copier payload (the payload does it at the drive's full speed).
 *
 * Every file is written beside its old copy as <name>.new, read back and checked
 * against the zip's size and CRC-32; only when all of them are there and right are
 * they renamed over the old ones, eboot.bin last; then each is read back again.
 * Nothing that isn't in the zip is ever touched or deleted, so a game's own files
 * (a disc's contents, saves, DLC, mods) beside the port stay as they are.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <cstdint>
#include <functional>
#include <string>
#include <vector>

namespace recomp::zip
{
struct Entry
{
    std::string name;
    std::uint16_t method = 0;
    std::uint32_t csize = 0, usize = 0, local = 0, crc = 0;
};

std::uint32_t crc32(const std::uint8_t *p, std::size_t n, std::uint32_t crc = 0);
/* The zip's directory (no zip64: game zips are far below 4 GB). */
bool list(int fd, std::vector<Entry> &out);
/* One file, checked against its size and CRC-32. */
bool extract(int fd, const Entry &en, std::vector<std::uint8_t> &out);
/* A name that can only land inside the folder: no absolute paths, no "..". */
bool safe_name(const std::string &n);
/* The single top folder every name starts with ("PPSA92011/"), or "". */
std::string top_folder(const std::vector<Entry> &entries);
/* The file on disk, read back: its size and CRC-32. why says what differed. */
bool file_matches(const std::string &path, std::uint64_t size, std::uint32_t crc, std::string *why = nullptr);
void make_dirs(const std::string &path);

using Progress = std::function<void(const char *step, std::uint64_t done, std::uint64_t total)>;
using Log = std::function<void(const std::string &line)>;
using Stop = std::function<bool()>;
/* Unpacks zip_path into root as above. On failure error says why (for the player) and
 * changed says whether any old file had already been replaced. */
bool unpack(const std::string &zip_path, const std::string &root, const Progress &progress, const Log &log,
            const Stop &stop, std::string &error, bool &changed, std::size_t &files);
} // namespace recomp::zip
