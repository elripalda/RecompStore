/* Recomp Store - deleting a folder and everything in it, safely on the PS5.
 *
 * The PS5 answers lstat() with an error for every path (seen on the console: every
 * delete found "nothing there" and removed 0 files), so nothing here uses it. Each name
 * is unlinked first: a file or a link goes, and a folder refuses (EPERM on the PS5,
 * EISDIR on a desktop). Only then is it opened as a folder - with O_NOFOLLOW, so a link
 * that couldn't be removed is never followed - emptied, and removed.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <cstddef>
#include <functional>
#include <string>
#include <vector>

namespace recomp::fs
{
/* Who to act as to get into a folder: a game makes its own folders (saves, settings) for
 * itself alone - its own user, mode 0700 - and on the PS5 even root with every capability
 * is refused them (seen on the console: Dusklight's userdata, owner uid 1). Its owner
 * isn't. */
struct Who
{
    unsigned uid = 0, gid = 0;
};
/* Takes on who's credentials, or with nullptr goes back to how this process started; false
 * if it can't. Only the copier payload can (it can change its own credentials). */
using ActAs = std::function<bool(const Who *who)>;

/* path and everything under it. files counts the files and links removed; progress, if
 * set, is told that count now and then. On failure, bad is the path that stayed and err
 * its errno. A path that isn't there is a success. With act_as, a folder that refuses
 * this process (EACCES) is gone into as its owner, and this process is itself again
 * before it returns. log, if set, hears when that happens. */
bool remove_tree(const std::string &path, std::size_t &files, std::string &bad, int &err,
                 const std::function<void(std::size_t)> &progress = {}, const ActAs &act_as = {},
                 const std::function<void(const std::string &)> &log = {}, const std::vector<std::string> *keep = nullptr);
/* With keep: those paths stay, and so do the folders they're in (1.0.6, a game's saves on
 * Delete). True when keep names path itself or anything in it. */
bool holds(const std::string &path, const std::vector<std::string> &keep);

/* Nothing at path any more (stat says it doesn't exist). */
bool gone(const std::string &path);

/* A path the store may delete: on the console's storage or a drive (/data/..., /mnt/...),
 * at least three folders deep, spelled plainly (no "." or ".." parts, no "//", no
 * trailing "/", no control characters), never the store's own folder or data, never a
 * homebrew folder itself, inside one only a game's own folder, and on a drive (/mnt)
 * nothing but a game's folder in its homebrew folder. root replaces "/data/" in tests. */
bool deletable(const std::string &path, const std::string &store_data, const std::string &root = "/data/");

/* After a folder outside the homebrew folder went: its parent folders while they're
 * empty, at most two (rmdir refuses a folder that isn't empty, so saves stay). */
void remove_empty_parents(const std::string &path, const std::function<void(const std::string &)> &log = {});
} // namespace recomp::fs
