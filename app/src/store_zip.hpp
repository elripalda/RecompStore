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
    std::uint32_t csize = 0, local = 0, crc = 0;
    std::uint64_t usize = 0; /* (2.0) a .7z's files can be bigger than a zip's 4 GB */
    bool has_crc = true;     /* (2.0) a .7z may leave a file's CRC-32 out: its size is checked then */
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
/* (1.1.3) Where the game's own folder is in the zip: the folder named after its title ID
 * that holds eboot.bin, as deep as it is ("PPSA64007/" beside "PPSA64008/" in a zip with two
 * games, "ninjas-dawn-ps5-v0.1.4-alpha/PPSA94202/" under a folder of notes), the shallowest
 * if there are several; else top_folder's answer, as before. Names outside it aren't
 * installed. */
std::string app_prefix(const std::vector<Entry> &entries, const std::string &title_id);
/* The file on disk, read back: its size and CRC-32. why says what differed. */
bool file_matches(const std::string &path, std::uint64_t size, std::uint32_t crc, std::string *why = nullptr);
void make_dirs(const std::string &path);

using Progress = std::function<void(const char *step, std::uint64_t done, std::uint64_t total)>;
using Log = std::function<void(const std::string &line)>;
using Stop = std::function<bool()>;
/* Unpacks zip_path (a zip, or (2.0) a .7z) into root as above. On failure error says why (for the player) and
 * changed says whether any old file had already been replaced. keep (1.1.2): the player's
 * own files and folders in root ("Assets", "dbz3.toml"); the zip's copy of one is written
 * only when there's none there yet. */
bool unpack(const std::string &zip_path, const std::string &root, const Progress &progress, const Log &log,
            const Stop &stop, std::string &error, bool &changed, std::size_t &files,
            const std::vector<std::string> *keep = nullptr, const std::string &title_id = std::string());
/* (1.1.2) A name in the game's folder that's one of keep's, or inside one, in any case. */
bool kept(const std::string &name, const std::vector<std::string> &keep);

/* (2.0) A .7z release works wherever a zip does: unpack() and the calls below tell them apart
 * by their first bytes, not their names (store_7z.cpp reads the .7z). */
bool is_7z(const std::string &path);
/* Every file and folder in a zip or a .7z (folders end in "/"): names, sizes, CRC-32s. */
bool list_any(const std::string &path, std::vector<Entry> &out);
/* The file on disk against an entry: its size, and its CRC-32 when the archive has one. */
bool entry_matches(const std::string &path, const Entry &e, std::string *why = nullptr);
/* One file of the game's folder in a zip or a .7z (rel inside app_prefix(entries, title_id),
 * any case: "sce_sys/param.json"), read into out and checked against its size and CRC-32.
 * false when it isn't there, is bigger than max or fails its check. Nothing is written. */
bool read_one(const std::string &archive_path, const std::string &title_id, const std::string &rel, std::string &out,
              std::uint64_t max);

/* (2.0) The last part of an unpack, the same for a zip and a .7z: every <path>.new written
 * (and checked) is swapped in, eboot.bin last, then each is read back against the archive.
 * On failure the .new files left are deleted and error says why. */
struct Written
{
    std::string path;
    std::uint64_t size = 0;
    std::uint32_t crc = 0;
    bool has_crc = true;
};
bool commit(const std::string &root, const std::vector<Written> &written, const Progress &progress, const Log &log,
            std::string &error, bool &changed, std::size_t &files);
/* The file on disk: its size, and its CRC-32 when has_crc. */
bool matches(const std::string &path, std::uint64_t size, bool has_crc, std::uint32_t crc, std::string *why = nullptr);

/* store_7z.cpp: unpack(), list_any() and read_one() for a .7z. */
bool unpack_7z(const std::string &path, const std::string &root, const Progress &progress, const Log &log,
               const Stop &stop, std::string &error, bool &changed, std::size_t &files,
               const std::vector<std::string> *keep, const std::string &title_id);
bool list_7z(const std::string &path, std::vector<Entry> &out);
bool read_one_7z(const std::string &path, const std::string &title_id, const std::string &rel, std::string &out,
                 std::uint64_t max);

/* A game whose PS5 files come as a zip inside its release's bigger zip (a source
 * archive): the one file in zip_path whose name wanted() accepts, written to out as it is
 * (not unpacked), its size and CRC-32 checked as it's written and again read back.
 * name is the name it has in the zip. On failure why says why, for the player; out is
 * never left half written. */
bool take_inner(const std::string &zip_path, const std::function<bool(const std::string &)> &wanted,
                const std::string &out, const Progress &progress, std::string &name, std::string &why);
} // namespace recomp::zip
