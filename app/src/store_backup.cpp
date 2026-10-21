/* Recomp Store (2.3.0) - save backups (store_backup.hpp).
 * SPDX-License-Identifier: GPL-3.0-or-later */
#include "store_backup.hpp"

#include <algorithm>
#include <cerrno>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <dirent.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>

namespace recomp::backup
{
namespace
{
constexpr std::size_t kChunk = 1u << 20;
constexpr std::uint64_t kZipMax = 0xFFFFFFFFull - (64u << 20); /* room for the directory */
constexpr std::size_t kMaxFiles = 65000;

struct Item
{
    std::string path, name; /* on the console, in the zip */
    std::uint64_t size = 0;
    long long mtime = 0;
    bool as_owner = false;
    fs::Who who;
    std::uint32_t crc = 0;
    std::uint32_t local = 0;
};

void put16(std::vector<std::uint8_t> &b, unsigned v)
{
    b.push_back(std::uint8_t(v));
    b.push_back(std::uint8_t(v >> 8));
}
void put32(std::vector<std::uint8_t> &b, std::uint32_t v)
{
    for (int i = 0; i < 4; ++i) b.push_back(std::uint8_t(v >> (8 * i)));
}
std::uint32_t le32(const std::uint8_t *p) { return std::uint32_t(p[0]) | std::uint32_t(p[1]) << 8 | std::uint32_t(p[2]) << 16 | std::uint32_t(p[3]) << 24; }
unsigned le16(const std::uint8_t *p) { return unsigned(p[0]) | unsigned(p[1]) << 8; }

/* A file time as a zip's (DOS time and date, UTC): the civil date from the days since 1970. */
void dos_time(long long t, unsigned &time, unsigned &date)
{
    if (t < 315532800) t = 315532800; /* 1980-01-01: a zip can't say earlier */
    const long long days = t / 86400, secs = t % 86400;
    long long z = days + 719468;
    const long long era = (z >= 0 ? z : z - 146096) / 146097;
    const unsigned doe = unsigned(z - era * 146097);
    const unsigned yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
    long long y = (long long)yoe + era * 400;
    const unsigned doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    const unsigned mp = (5 * doy + 2) / 153;
    const unsigned d = doy - (153 * mp + 2) / 5 + 1;
    const unsigned m = mp < 10 ? mp + 3 : mp - 9;
    if (m <= 2) ++y;
    if (y > 2107) y = 2107;
    time = unsigned(secs / 3600) << 11 | unsigned((secs / 60) % 60) << 5 | unsigned((secs % 60) / 2);
    date = unsigned(y - 1980) << 9 | m << 5 | d;
}

bool write_all(int fd, const std::uint8_t *p, std::size_t n)
{
    while (n > 0)
    {
        const ssize_t w = ::write(fd, p, n);
        if (w < 0 && errno == EINTR) continue;
        if (w <= 0) return false;
        p += w;
        n -= std::size_t(w);
    }
    return true;
}

bool read_at(int fd, std::uint64_t at, std::uint8_t *p, std::size_t n)
{
    while (n > 0)
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

/* A folder's names (no "." or ".."), as its owner when this process is refused it. */
bool names_of(const std::string &dir, const fs::Who *cur, fs::Who &owner, const fs::Who *&here, bool &switched,
              std::vector<std::string> &out, int &err, const fs::ActAs &act_as)
{
    auto list = [&]() -> bool {
        DIR *d = opendir(dir.c_str());
        if (!d)
        {
            err = errno;
            return false;
        }
        while (dirent *x = readdir(d))
            if (std::strcmp(x->d_name, ".") != 0 && std::strcmp(x->d_name, "..") != 0) out.push_back(x->d_name);
        closedir(d);
        return true;
    };
    if (list()) return true;
    if (err != EACCES || !act_as || switched) return false;
    struct stat st;
    if (stat(dir.c_str(), &st) != 0) return false;
    owner.uid = unsigned(st.st_uid);
    owner.gid = unsigned(st.st_gid);
    if (cur && cur->uid == owner.uid && cur->gid == owner.gid) return false;
    if (!act_as(&owner))
    {
        act_as(cur);
        return false;
    }
    switched = true;
    here = &owner;
    out.clear();
    return list();
}

/* Every file under path (a folder), each named name + "/" + its path inside it. */
bool walk(const std::string &path, const std::string &name, int depth, const fs::Who *cur, const fs::ActAs &act_as,
          std::vector<Item> &items, std::string &why)
{
    if (depth > 48)
    {
        why = path + " is nested too deep to back up.";
        return false;
    }
    std::vector<std::string> names;
    fs::Who owner;
    const fs::Who *here = cur;
    bool switched = false;
    int err = 0;
    if (!names_of(path, cur, owner, here, switched, names, err, act_as))
    {
        if (switched) act_as(cur);
        why = path + " can't be read (error " + std::to_string(err) + ").";
        return false;
    }
    std::sort(names.begin(), names.end());
    bool ok = true;
    for (const std::string &n : names)
    {
        const std::string p = path + "/" + n, zn = name + "/" + n;
        struct stat st;
        if (stat(p.c_str(), &st) != 0) continue; /* gone, or a link to nothing */
        if (S_ISDIR(st.st_mode))
        {
            if (!walk(p, zn, depth + 1, here, act_as, items, why))
            {
                ok = false;
                break;
            }
            continue;
        }
        if (!S_ISREG(st.st_mode)) continue;
        if (n.size() > 7 && (n.compare(n.size() - 7, 7, ".rs-new") == 0 || n.compare(n.size() - 7, 7, ".rs-old") == 0)) continue;
        Item it;
        it.path = p;
        it.name = zn;
        it.size = std::uint64_t(st.st_size);
        it.mtime = (long long)st.st_mtime;
        if (here)
        {
            it.as_owner = true;
            it.who = *here;
        }
        items.push_back(it);
        if (items.size() > kMaxFiles)
        {
            why = "There are too many save files to back up (65,000 at most).";
            ok = false;
            break;
        }
    }
    if (switched && !act_as(cur))
    {
        why = "The copier couldn't go back to itself after reading " + path + ".";
        return false;
    }
    return ok;
}

/* A file opened for reading, as its folder's owner when it has to be (act_as is then still that
 * owner: done() goes back). */
struct Reader
{
    int fd = -1;
    bool switched = false;
    const fs::ActAs *act_as = nullptr;
    bool open_item(const Item &it, const fs::ActAs &as)
    {
        act_as = &as;
        if (it.as_owner && as)
        {
            if (!as(&it.who))
            {
                as(nullptr);
                return false;
            }
            switched = true;
        }
        fd = ::open(it.path.c_str(), O_RDONLY);
        if (fd < 0 && !switched && errno == EACCES && as)
        {
            struct stat st;
            if (stat(it.path.c_str(), &st) == 0)
            {
                fs::Who w{unsigned(st.st_uid), unsigned(st.st_gid)};
                if (as(&w))
                {
                    switched = true;
                    fd = ::open(it.path.c_str(), O_RDONLY);
                }
                else
                    as(nullptr);
            }
        }
        return fd >= 0;
    }
    bool done()
    {
        if (fd >= 0) ::close(fd);
        fd = -1;
        if (switched && act_as && *act_as)
        {
            switched = false;
            return (*act_as)(nullptr);
        }
        return true;
    }
    ~Reader() { done(); }
};

/* A stored entry's data, read through: its CRC-32 against the directory's. */
bool entry_crc(int fd, const zip::Entry &e, std::uint8_t *buf, std::string &why)
{
    std::uint8_t lh[30];
    if (!read_at(fd, e.local, lh, sizeof lh) || le32(lh) != 0x04034b50)
    {
        why = e.name + ": its header isn't right";
        return false;
    }
    const std::uint64_t data = std::uint64_t(e.local) + 30 + le16(lh + 26) + le16(lh + 28);
    std::uint32_t crc = 0;
    std::uint64_t at = 0;
    while (at < e.csize)
    {
        const std::size_t n = std::size_t(std::min<std::uint64_t>(kChunk, e.csize - at));
        if (!read_at(fd, data + at, buf, n))
        {
            why = e.name + " can't be read";
            return false;
        }
        crc = zip::crc32(buf, n, crc);
        at += n;
    }
    if (crc != e.crc)
    {
        why = e.name + " is damaged (its CRC-32 is wrong)";
        return false;
    }
    return true;
}

std::string parent_of(const std::string &p)
{
    const std::size_t s = p.find_last_of('/');
    return s == std::string::npos || s == 0 ? std::string() : p.substr(0, s);
}

/* An entry's file written to dst (0777, as installs are) and read back. */
bool unpack_entry(int fd, const zip::Entry &e, const std::string &dst, std::uint8_t *buf, std::string &why)
{
    const std::string part = dst + ".rs-part";
    std::uint8_t lh[30];
    if (!read_at(fd, e.local, lh, sizeof lh) || le32(lh) != 0x04034b50)
    {
        why = e.name + ": its header isn't right";
        return false;
    }
    const int out = ::open(part.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0777);
    if (out < 0)
    {
        why = dst + " can't be written (error " + std::to_string(errno) + ")";
        return false;
    }
    bool ok = true;
    if (e.method == 0)
    {
        const std::uint64_t data = std::uint64_t(e.local) + 30 + le16(lh + 26) + le16(lh + 28);
        std::uint64_t at = 0;
        while (ok && at < e.csize)
        {
            const std::size_t n = std::size_t(std::min<std::uint64_t>(kChunk, e.csize - at));
            ok = read_at(fd, data + at, buf, n) && write_all(out, buf, n);
            at += n;
        }
    }
    else
    {
        std::vector<std::uint8_t> whole;
        ok = zip::extract(fd, e, whole) && write_all(out, whole.data(), whole.size());
    }
    ok = ::fsync(out) == 0 && ok;
    ok = ::close(out) == 0 && ok;
    if (!ok)
    {
        ::unlink(part.c_str());
        why = dst + " couldn't be written whole. Is the storage full?";
        return false;
    }
    ::chmod(part.c_str(), 0777);
    std::string bad;
    if (!zip::file_matches(part, e.usize, e.crc, &bad) || std::rename(part.c_str(), dst.c_str()) != 0)
    {
        ::unlink(part.c_str());
        why = dst + " didn't read back right" + (bad.empty() ? std::string() : " (" + bad + ")");
        return false;
    }
    return true;
}

/* rename, and again as the folder's owner when the console refuses it. */
bool rename_as(const std::string &from, const std::string &to, const fs::ActAs &act_as)
{
    if (std::rename(from.c_str(), to.c_str()) == 0) return true;
    const int e = errno;
    if ((e != EACCES && e != EPERM) || !act_as) return false;
    struct stat st;
    if (stat(from.c_str(), &st) != 0) return false;
    fs::Who w{unsigned(st.st_uid), unsigned(st.st_gid)};
    if (!act_as(&w))
    {
        act_as(nullptr);
        return false;
    }
    const bool ok = std::rename(from.c_str(), to.c_str()) == 0;
    act_as(nullptr);
    return ok;
}

bool remove_all(const std::string &p, const fs::ActAs &act_as, std::string &bad)
{
    std::size_t n = 0;
    int e = 0;
    return fs::remove_tree(p, n, bad, e, {}, act_as) && fs::gone(p);
}
} // namespace

std::string zip_prefix(const std::string &save)
{
    std::string s = save;
    while (!s.empty() && s.back() == '/') s.pop_back();
    if (s.empty() || s.find('\\') != std::string::npos || s.find("//") != std::string::npos) return "";
    if (s[0] == '/')
    {
        if (s.rfind("/data/", 0) != 0) return "";
        s = s.substr(1);
    }
    if (!zip::safe_name(s) || s == "." || s.rfind("./", 0) == 0 || s.find("/./") != std::string::npos) return "";
    for (const unsigned char c : s)
        if (c < 0x20 || c == 0x7F) return "";
    return s;
}

bool make(const std::string &out, const std::string &manifest, const std::vector<Save> &saves, const fs::ActAs &act_as,
          const Progress &progress, const Log &log, std::string &why, std::size_t &files, std::uint64_t &bytes,
          std::vector<std::string> *missing)
{
    files = 0;
    bytes = 0;
    why.clear();
    auto say = [&](const std::string &l) { if (log) log(l); };
    if (out.size() < 5 || out.compare(out.size() - 4, 4, ".zip") != 0)
    {
        why = "A backup has to be a .zip.";
        return false;
    }
    /* What goes in: each save that's there, its files in order. */
    std::vector<Item> items;
    for (const Save &s : saves)
    {
        if (s.as.empty() || !zip::safe_name(s.as) || s.as == kManifest)
        {
            why = "A save's place in the backup isn't right (" + s.as + ").";
            return false;
        }
        struct stat st;
        if (stat(s.path.c_str(), &st) != 0)
        {
            if (missing) missing->push_back(s.path);
            say("backup: " + s.path + " isn't there (left out)");
            continue;
        }
        if (S_ISDIR(st.st_mode))
        {
            const std::size_t before = items.size();
            if (!walk(s.path, s.as, 0, nullptr, act_as, items, why)) return false;
            say("backup: " + s.path + ": " + std::to_string(items.size() - before) + " files");
        }
        else if (S_ISREG(st.st_mode))
        {
            Item it;
            it.path = s.path;
            it.name = s.as;
            it.size = std::uint64_t(st.st_size);
            it.mtime = (long long)st.st_mtime;
            items.push_back(it);
        }
    }
    if (items.empty())
    {
        why = "There are no saves on the console to back up yet.";
        return false;
    }
    std::uint64_t total = manifest.size();
    for (const Item &it : items)
    {
        if (it.size >= 0xFFFFFFFFull)
        {
            why = it.path + " is over 4 GB: too big for a backup.";
            return false;
        }
        total += it.size + 30 + it.name.size() + 46 + it.name.size();
    }
    if (total > kZipMax)
    {
        why = "These saves are over 4 GB: too big for a backup.";
        return false;
    }
    const std::string part = out + ".part";
    ::unlink(part.c_str());
    const int fd = ::open(part.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0666);
    if (fd < 0)
    {
        why = "The backup can't be written (error " + std::to_string(errno) + ").";
        return false;
    }
    std::uint8_t *buf = static_cast<std::uint8_t *>(std::malloc(kChunk));
    if (!buf)
    {
        ::close(fd);
        ::unlink(part.c_str());
        why = "There isn't enough memory to make the backup.";
        return false;
    }
    std::uint64_t at = 0, done = 0, end_at = 0;
    bool ok = true;
    auto header = [&](const std::string &name, std::uint32_t crc, std::uint32_t size, long long mtime) {
        unsigned t = 0, d = 0;
        dos_time(mtime, t, d);
        std::vector<std::uint8_t> h;
        put32(h, 0x04034b50);
        put16(h, 20);
        put16(h, 0x0800); /* names in UTF-8 */
        put16(h, 0);
        put16(h, t);
        put16(h, d);
        put32(h, crc);
        put32(h, size);
        put32(h, size);
        put16(h, unsigned(name.size()));
        put16(h, 0);
        h.insert(h.end(), name.begin(), name.end());
        const bool w = write_all(fd, h.data(), h.size());
        at += h.size();
        return w;
    };
    /* The manifest first. */
    Item man;
    man.name = kManifest;
    man.size = manifest.size();
    man.crc = zip::crc32(reinterpret_cast<const std::uint8_t *>(manifest.data()), manifest.size());
    man.local = 0;
    man.mtime = (long long)std::time(nullptr);
    ok = header(man.name, man.crc, std::uint32_t(man.size), man.mtime) &&
         write_all(fd, reinterpret_cast<const std::uint8_t *>(manifest.data()), manifest.size());
    at += manifest.size();
    for (Item &it : items)
    {
        if (!ok) break;
        /* Read through once for its CRC-32, then again into the zip: the two must agree. */
        Reader r;
        if (!r.open_item(it, act_as))
        {
            why = it.path + " can't be read (error " + std::to_string(errno) + ").";
            ok = false;
            break;
        }
        std::uint32_t crc = 0;
        std::uint64_t got = 0;
        while (got < it.size)
        {
            const std::size_t n = std::size_t(std::min<std::uint64_t>(kChunk, it.size - got));
            if (!read_at(r.fd, got, buf, n)) break;
            crc = zip::crc32(buf, n, crc);
            got += n;
        }
        if (got != it.size)
        {
            why = it.path + " couldn't be read whole.";
            ok = false;
            break;
        }
        it.crc = crc;
        it.local = std::uint32_t(at);
        if (!header(it.name, it.crc, std::uint32_t(it.size), it.mtime))
        {
            why = "Writing the backup failed. Is the storage full?";
            ok = false;
            break;
        }
        std::uint32_t again = 0;
        got = 0;
        while (ok && got < it.size)
        {
            const std::size_t n = std::size_t(std::min<std::uint64_t>(kChunk, it.size - got));
            if (!read_at(r.fd, got, buf, n))
            {
                why = it.path + " couldn't be read whole.";
                ok = false;
                break;
            }
            again = zip::crc32(buf, n, again);
            if (!write_all(fd, buf, n))
            {
                why = "Writing the backup failed. Is the storage full?";
                ok = false;
                break;
            }
            got += n;
            done += n;
            if (progress) progress("Backing up", done, total);
        }
        at += it.size;
        if (!r.done())
        {
            why = "The copier couldn't go back to itself after reading " + it.path + ".";
            ok = false;
        }
        if (ok && again != it.crc)
        {
            why = it.path + " changed while it was backed up. Close the game and try again.";
            ok = false;
        }
        ++files;
        bytes += it.size;
    }
    /* The directory. */
    if (ok)
    {
        std::vector<std::uint8_t> cd;
        std::vector<Item *> all{&man};
        for (Item &it : items) all.push_back(&it);
        for (const Item *it : all)
        {
            unsigned t = 0, d = 0;
            dos_time(it->mtime, t, d);
            put32(cd, 0x02014b50);
            put16(cd, 0x0314); /* made by: Unix, 2.0 */
            put16(cd, 20);
            put16(cd, 0x0800);
            put16(cd, 0);
            put16(cd, t);
            put16(cd, d);
            put32(cd, it->crc);
            put32(cd, std::uint32_t(it->size));
            put32(cd, std::uint32_t(it->size));
            put16(cd, unsigned(it->name.size()));
            put16(cd, 0);
            put16(cd, 0);
            put16(cd, 0);
            put16(cd, 0);
            put32(cd, 0x81B60000u); /* -rw-rw-rw- */
            put32(cd, it->local);
            cd.insert(cd.end(), it->name.begin(), it->name.end());
        }
        const std::uint32_t cd_at = std::uint32_t(at);
        const std::uint32_t cd_size = std::uint32_t(cd.size());
        put32(cd, 0x06054b50);
        put16(cd, 0);
        put16(cd, 0);
        put16(cd, unsigned(all.size()));
        put16(cd, unsigned(all.size()));
        put32(cd, cd_size);
        put32(cd, cd_at);
        put16(cd, 0);
        ok = write_all(fd, cd.data(), cd.size());
        end_at = at + cd.size();
        if (!ok) why = "Writing the backup failed. Is the storage full?";
    }
    ok = ::fsync(fd) == 0 && ok;
    if (::close(fd) != 0 && ok)
    {
        ok = false;
        why = "Writing the backup failed. Is the storage full?";
    }
    /* Read back: its size, then every file in it against what was read. */
    struct stat fin;
    if (ok && (stat(part.c_str(), &fin) != 0 || std::uint64_t(fin.st_size) != end_at))
    {
        why = "The backup isn't the size it was written at.";
        ok = false;
    }
    if (ok)
    {
        if (progress) progress("Checking the backup", 0, 0);
        std::string text;
        std::vector<zip::Entry> entries;
        std::string bad;
        if (!check(part, text, entries, {}, bad) || entries.size() != items.size() + 1 || text != manifest)
        {
            why = "The backup didn't read back right" + (bad.empty() ? std::string(".") : ": " + bad + ".");
            ok = false;
        }
        for (std::size_t i = 0; ok && i < items.size(); ++i)
            if (entries[i + 1].name != items[i].name || entries[i + 1].crc != items[i].crc || entries[i + 1].usize != items[i].size)
            {
                why = "The backup didn't read back right (" + items[i].name + ").";
                ok = false;
            }
    }
    std::free(buf);
    if (ok && std::rename(part.c_str(), out.c_str()) != 0)
    {
        why = "The backup couldn't be saved (error " + std::to_string(errno) + ").";
        ok = false;
    }
    if (!ok)
    {
        ::unlink(part.c_str());
        say("backup: failed: " + why);
        return false;
    }
    say("backup: " + out + ": " + std::to_string(files) + " files, " + std::to_string(bytes) + " bytes, read back and checked");
    return true;
}

bool check(const std::string &zip, std::string &manifest, std::vector<zip::Entry> &entries, const Progress &progress,
           std::string &why)
{
    manifest.clear();
    entries.clear();
    const int fd = ::open(zip.c_str(), O_RDONLY);
    if (fd < 0)
    {
        why = "it can't be opened";
        return false;
    }
    std::uint8_t *buf = static_cast<std::uint8_t *>(std::malloc(kChunk));
    bool ok = buf != nullptr && zip::list(fd, entries);
    if (!ok) why = buf ? "it isn't a zip" : "no memory";
    std::uint64_t total = 0, done = 0;
    for (const zip::Entry &e : entries) total += e.csize;
    std::size_t man = entries.size();
    for (std::size_t i = 0; ok && i < entries.size(); ++i)
    {
        const zip::Entry &e = entries[i];
        if (e.name == kManifest) man = i;
        if (!zip::safe_name(e.name) || e.name.find("//") != std::string::npos)
        {
            why = "it has a file named " + e.name;
            ok = false;
            break;
        }
        for (const unsigned char c : e.name)
            if (c < 0x20 || c == 0x7F) ok = false;
        if (!ok)
        {
            why = "it has a file with an odd name";
            break;
        }
        if (!e.name.empty() && e.name.back() == '/') continue; /* a folder */
        if (e.method == 0)
            ok = entry_crc(fd, e, buf, why);
        else if (e.method == 8 && e.usize <= (256u << 20))
        {
            std::vector<std::uint8_t> whole;
            ok = zip::extract(fd, e, whole);
            if (!ok) why = e.name + " is damaged";
        }
        else
        {
            why = e.name + " is packed in a way the store can't read";
            ok = false;
        }
        done += e.csize;
        if (progress) progress("Checking the backup", done, total);
    }
    if (ok && man == entries.size())
    {
        why = "it has no " + std::string(kManifest) + ": it isn't a Recomp Store backup";
        ok = false;
    }
    if (ok)
    {
        std::vector<std::uint8_t> m;
        if (entries[man].usize > 65536 || !zip::extract(fd, entries[man], m))
        {
            why = "its " + std::string(kManifest) + " can't be read";
            ok = false;
        }
        else
            manifest.assign(m.begin(), m.end());
    }
    std::free(buf);
    ::close(fd);
    return ok;
}

bool restore(const std::string &zip, const std::vector<zip::Entry> &entries, const std::vector<Save> &saves,
             const fs::ActAs &act_as, const Progress &progress, const Log &log, std::string &why, std::size_t &files)
{
    files = 0;
    auto say = [&](const std::string &l) { if (log) log(l); };
    const int fd = ::open(zip.c_str(), O_RDONLY);
    if (fd < 0)
    {
        why = "The backup can't be opened.";
        return false;
    }
    std::uint8_t *buf = static_cast<std::uint8_t *>(std::malloc(kChunk));
    if (!buf)
    {
        ::close(fd);
        why = "There isn't enough memory to restore.";
        return false;
    }
    std::uint64_t total = 0, done = 0;
    for (const zip::Entry &e : entries) total += e.usize;
    /* What each save gets back, worked out (and checked) before anything changes. */
    struct Plan
    {
        const Save *save;
        std::vector<const zip::Entry *> files;
    };
    std::vector<Plan> plans;
    bool ok = true;
    for (const Save &s : saves)
    {
        if (s.path.empty() || s.as.empty() || !zip::safe_name(s.as) || s.path.find("/../") != std::string::npos || s.path[0] != '/')
        {
            why = "A save's place isn't right (" + s.path + ").";
            ok = false;
            break;
        }
        Plan p{&s, {}};
        for (const zip::Entry &e : entries)
        {
            if (e.name == kManifest || (!e.name.empty() && e.name.back() == '/')) continue;
            if (s.dir)
            {
                if (e.name.size() <= s.as.size() + 1 || e.name.compare(0, s.as.size(), s.as) != 0 || e.name[s.as.size()] != '/') continue;
                const std::string rel = e.name.substr(s.as.size() + 1);
                if (!zip::safe_name(rel) || rel.find("//") != std::string::npos || rel.rfind("./", 0) == 0)
                {
                    why = "The backup has a file named " + e.name + ".";
                    ok = false;
                    break;
                }
            }
            else if (e.name != s.as)
                continue;
            p.files.push_back(&e);
        }
        if (!ok) break;
        if (!p.files.empty()) plans.push_back(p);
        else say("restore: nothing in the backup for " + s.path + " (left as it is)");
    }
    if (ok && plans.empty())
    {
        why = "That backup has none of this game's saves in it.";
        ok = false;
    }
    /* 1. Each one unpacked beside itself. */
    for (const Plan &p : plans)
    {
        if (!ok) break;
        const std::string fresh = p.save->path + ".rs-new";
        std::string bad;
        if (!remove_all(fresh, act_as, bad))
        {
            why = "An old " + fresh + " is in the way and can't be removed.";
            ok = false;
            break;
        }
        const std::string parent = parent_of(p.save->path);
        if (!parent.empty()) zip::make_dirs(parent);
        if (p.save->dir)
        {
            if (::mkdir(fresh.c_str(), 0777) != 0)
            {
                why = fresh + " can't be made (error " + std::to_string(errno) + ").";
                ok = false;
                break;
            }
            ::chmod(fresh.c_str(), 0777);
        }
        for (const zip::Entry *e : p.files)
        {
            const std::string dst = p.save->dir ? fresh + "/" + e->name.substr(p.save->as.size() + 1) : fresh;
            if (p.save->dir) zip::make_dirs(parent_of(dst));
            if (!unpack_entry(fd, *e, dst, buf, why))
            {
                ok = false;
                break;
            }
            done += e->usize;
            ++files;
            if (progress) progress("Restoring", done, total);
        }
    }
    /* 2. Swapped in, one by one; the old ones put back if any swap fails. */
    std::vector<const Plan *> swapped;
    if (ok)
    {
        if (progress) progress("Putting your saves back", total, total);
        for (const Plan &p : plans)
        {
            const std::string &path = p.save->path, fresh = path + ".rs-new", old = path + ".rs-old";
            std::string bad;
            if (!remove_all(old, act_as, bad))
            {
                why = "An old " + old + " is in the way and can't be removed.";
                ok = false;
                break;
            }
            struct stat st;
            const bool had = stat(path.c_str(), &st) == 0;
            if (had && !rename_as(path, old, act_as))
            {
                why = path + " couldn't be moved aside (error " + std::to_string(errno) + ").";
                ok = false;
                break;
            }
            if (std::rename(fresh.c_str(), path.c_str()) != 0)
            {
                why = path + " couldn't be put in place (error " + std::to_string(errno) + ").";
                if (had) rename_as(old, path, act_as);
                ok = false;
                break;
            }
            swapped.push_back(&p);
        }
    }
    /* 3. Checked where they are now. */
    for (const Plan *p : swapped)
    {
        if (!ok) break;
        for (const zip::Entry *e : p->files)
        {
            const std::string dst = p->save->dir ? p->save->path + "/" + e->name.substr(p->save->as.size() + 1) : p->save->path;
            std::string bad;
            if (!zip::file_matches(dst, e->usize, e->crc, &bad))
            {
                why = dst + " isn't right after the restore (" + bad + ").";
                ok = false;
                break;
            }
        }
    }
    if (!ok)
    {
        /* Back as it was: the new ones out, the old ones in. */
        for (const Plan *p : swapped)
        {
            const std::string &path = p->save->path, old = path + ".rs-old";
            std::string bad;
            remove_all(path, act_as, bad);
            struct stat st;
            if (stat(old.c_str(), &st) == 0 && !rename_as(old, path, act_as)) say("restore: couldn't put " + path + " back; it is at " + old);
        }
        for (const Plan &p : plans)
        {
            std::string bad;
            remove_all(p.save->path + ".rs-new", act_as, bad);
        }
        say("restore: failed, put back as it was: " + why);
    }
    else
        for (const Plan &p : plans)
        {
            const std::string old = p.save->path + ".rs-old";
            std::string bad;
            if (!remove_all(old, act_as, bad)) say("restore: the old saves at " + old + " couldn't be deleted (" + bad + ")");
            say("restore: " + p.save->path + ": " + std::to_string(p.files.size()) + " files, checked");
        }
    std::free(buf);
    ::close(fd);
    return ok;
}

bool copy_checked(const std::string &zip, const std::string &dir, const Log &log, std::string &why)
{
    const std::string leaf = zip.substr(zip.find_last_of('/') + 1), dst = dir + "/" + leaf, part = dst + ".part";
    zip::make_dirs(dir);
    struct stat st;
    if (stat(zip.c_str(), &st) != 0)
    {
        why = "The backup isn't there to copy.";
        return false;
    }
    const std::uint64_t size = std::uint64_t(st.st_size);
    const int in = ::open(zip.c_str(), O_RDONLY);
    const int out = in >= 0 ? ::open(part.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0666) : -1;
    if (in < 0 || out < 0)
    {
        if (in >= 0) ::close(in);
        why = in < 0 ? "The backup can't be read." : "The USB drive can't be written to (error " + std::to_string(errno) + ").";
        return false;
    }
    std::uint8_t *buf = static_cast<std::uint8_t *>(std::malloc(kChunk));
    std::uint32_t crc = 0;
    std::uint64_t at = 0;
    bool ok = buf != nullptr;
    while (ok && at < size)
    {
        const std::size_t n = std::size_t(std::min<std::uint64_t>(kChunk, size - at));
        ok = read_at(in, at, buf, n) && write_all(out, buf, n);
        if (ok) crc = zip::crc32(buf, n, crc);
        at += n;
    }
    std::free(buf);
    ::close(in);
    ok = ::fsync(out) == 0 && ok;
    ok = ::close(out) == 0 && ok;
    std::string bad;
    if (!ok || !zip::file_matches(part, size, crc, &bad) || std::rename(part.c_str(), dst.c_str()) != 0)
    {
        ::unlink(part.c_str());
        why = "The copy on the USB drive didn't check out" + (bad.empty() ? std::string(".") : " (" + bad + ").");
        return false;
    }
    if (log) log("backup: copied to " + dst + " and checked");
    return true;
}

std::string json_field(const std::string &json, const std::string &key)
{
    const std::string k = "\"" + key + "\"";
    std::size_t at = json.find(k);
    if (at == std::string::npos) return "";
    at = json.find(':', at + k.size());
    if (at == std::string::npos) return "";
    ++at;
    while (at < json.size() && (json[at] == ' ' || json[at] == '\n' || json[at] == '\t')) ++at;
    if (at >= json.size() || json[at] != '"') return "";
    std::string out;
    for (++at; at < json.size() && json[at] != '"'; ++at)
    {
        if (json[at] == '\\' && at + 1 < json.size()) ++at;
        out += json[at];
    }
    return out;
}
} // namespace recomp::backup
