/* Recomp Store (2.3.0) - save backups: a game's save folders as one stored zip, and back.
 *
 * Shared by the store and its copier payload (which can read a game's own 0700 folders as
 * their owner). Nothing here uses more than open, pread, write, fsync, close, rename, unlink,
 * stat, mkdir, chmod and the folder calls the payloads already use.
 *
 * A backup is a plain zip anyone can open: stored (no compression), no zip64 (so under 4 GB),
 * recompstore-backup.json first, then each save's files under its own prefix ("userdata/...",
 * "data/sm64/...", or a file's own name). Written beside itself as <zip>.part, synced, every file
 * read back against the CRC-32 taken while it was read, and only then renamed into place.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include "store_fs.hpp"
#include "store_zip.hpp"

#include <cstdint>
#include <functional>
#include <string>
#include <vector>

namespace recomp::backup
{
constexpr const char *kManifest = "recompstore-backup.json";

/* One save: where it is on the console, where it goes in the zip ("userdata/" for a folder,
 * "dbz3.toml" for a file), and whether it's a folder. */
struct Save
{
    std::string path, as;
    bool dir = true;
};

using Progress = std::function<void(const char *step, std::uint64_t done, std::uint64_t total)>;
using Log = std::function<void(const std::string &line)>;

/* Where a save as the catalog says it ("userdata", "/data/sm64/saves") goes in a zip, without
 * the trailing "/" ("userdata", "data/sm64/saves"); "" when it isn't one the store would use. */
std::string zip_prefix(const std::string &save);

/* The backup zip at out (made as out + ".part" first). Saves that aren't on the console are left
 * out (missing tells which); none there at all is a failure. files and bytes: what went in. */
bool make(const std::string &out, const std::string &manifest, const std::vector<Save> &saves, const fs::ActAs &act_as,
          const Progress &progress, const Log &log, std::string &why, std::size_t &files, std::uint64_t &bytes,
          std::vector<std::string> *missing = nullptr);

/* A backup zip read whole: its manifest's text and its files, every one's CRC-32 checked. */
bool check(const std::string &zip, std::string &manifest, std::vector<zip::Entry> &entries, const Progress &progress,
           std::string &why);

/* The saves put back from a checked zip: each one unpacked beside itself (<path>.rs-new),
 * checked, the old one moved aside (<path>.rs-old), the new one renamed in and checked again,
 * then the old one deleted. A failure part way puts back what was there. */
bool restore(const std::string &zip, const std::vector<zip::Entry> &entries, const std::vector<Save> &saves,
             const fs::ActAs &act_as, const Progress &progress, const Log &log, std::string &why, std::size_t &files);

/* A finished zip copied into the folder dir (a USB drive's), under its own name, and checked
 * (size and CRC-32 of the whole file). */
bool copy_checked(const std::string &zip, const std::string &dir, const Log &log, std::string &why);

/* A small JSON value's string field ("app", "made"): no escapes beyond \" and \\. */
std::string json_field(const std::string &json, const std::string &key);
} // namespace recomp::backup
