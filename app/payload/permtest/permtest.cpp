/* Recomp Store - permission test payload (GPL-3.0-or-later).
 *
 * Why Delete is refused a folder a game made itself (Dusklight's userdata): the folder's
 * owner and mode, this payload's own credentials, and whether it can get in - as it was
 * started, as root with every capability, with other auth IDs, and as the folder's owner.
 *
 * It changes nothing that stays: the only thing it writes inside a game's folder is an
 * empty test file, removed straight away. Its report goes to /data/recompstore/permtest.txt
 * (and to the loader's console), and a toast says it's done.
 *
 *   send RecompStore-PermTest.elf to the payload loader on port 9021
 *   (an argument may name another folder to test; the default is Dusklight's userdata) */
#include <cerrno>
#include <cstdarg>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <dirent.h>
#include <fcntl.h>
#include <string>
#include <sys/stat.h>
#include <unistd.h>
#include <vector>

#include <ps5/kernel.h>

extern "C" int sceKernelSendNotificationRequest(int device, void *request, std::size_t size, int blocking);

namespace
{
const char *kReport = "/data/recompstore/permtest.txt";
const char *kDefault = "/data/homebrew/PPSA99305/userdata";

std::string g_report;

void say(const char *fmt, ...)
{
    char line[1024];
    va_list ap;
    va_start(ap, fmt);
    std::vsnprintf(line, sizeof line, fmt, ap);
    va_end(ap);
    std::printf("%s\n", line);
    g_report += line;
    g_report += '\n';
}

struct KernelToast
{
    std::int32_t type, req_id, priority, msg_id, target_id, user_id, unk1, unk2, app_id, error_num, unk3;
    std::uint8_t use_icon_image_uri;
    char message[1024];
    char icon_uri[1024];
    char unk[1024];
    std::uint8_t pad[3];
} __attribute__((packed));
static_assert(sizeof(KernelToast) == 0xc30, "the kernel toast is 0xc30 bytes");

void toast(const std::string &text)
{
    KernelToast req;
    std::memset(&req, 0, sizeof req);
    std::snprintf(req.message, sizeof req.message, "%s", text.c_str());
    sceKernelSendNotificationRequest(0, &req, sizeof req, 0);
}

std::string mode_text(mode_t m)
{
    char t[16];
    std::snprintf(t, sizeof t, "%c%03o", S_ISDIR(m) ? 'd' : S_ISREG(m) ? '-' : '?', unsigned(m & 07777));
    return t;
}

void describe(const std::string &path)
{
    struct stat st;
    if (stat(path.c_str(), &st) != 0)
        say("  %s: stat failed, errno %d", path.c_str(), errno);
    else
        say("  %s: %s owner uid %u gid %u", path.c_str(), mode_text(st.st_mode).c_str(), unsigned(st.st_uid), unsigned(st.st_gid));
}

void creds(const char *when)
{
    const pid_t pid = getpid();
    std::uint8_t caps[16] = {};
    const bool got_caps = kernel_get_ucred_caps(pid, caps) == 0;
    char caps_hex[40] = "?";
    if (got_caps)
        for (int i = 0; i < 16; ++i) std::snprintf(caps_hex + i * 2, 3, "%02x", caps[i]);
    say("creds %s: pid %d uid %u ruid %u svuid %u rgid %u svgid %u authid 0x%016llx caps %s jailed %s", when, int(pid),
        unsigned(kernel_get_ucred_uid(pid)), unsigned(kernel_get_ucred_ruid(pid)), unsigned(kernel_get_ucred_svuid(pid)),
        unsigned(kernel_get_ucred_rgid(pid)), unsigned(kernel_get_ucred_svgid(pid)),
        (unsigned long long)kernel_get_ucred_authid(pid), caps_hex,
        kernel_get_proc_rootdir(pid) == kernel_get_root_vnode() ? "no" : "yes");
}

/* Can it list the folder, and make and remove a file in it (what deleting needs)? */
std::string probe(const std::string &path, const char *label)
{
    std::string verdict;
    int listed = -1, list_err = 0;
    if (DIR *d = opendir(path.c_str()))
    {
        listed = 0;
        while (dirent *e = readdir(d))
            if (std::strcmp(e->d_name, ".") != 0 && std::strcmp(e->d_name, "..") != 0) ++listed;
        closedir(d);
    }
    else
        list_err = errno;
    const std::string test = path + "/.recompstore-permtest";
    int fd = open(test.c_str(), O_WRONLY | O_CREAT | O_EXCL, 0666);
    const int create_err = fd < 0 ? errno : 0;
    int unlink_err = -1;
    if (fd >= 0)
    {
        close(fd);
        unlink_err = unlink(test.c_str()) == 0 ? 0 : errno;
    }
    say("probe %-22s list: %s%s  make a file: %s  remove it: %s", label, listed >= 0 ? "yes, " : "NO, errno ",
        listed >= 0 ? (std::to_string(listed) + " entries").c_str() : std::to_string(list_err).c_str(),
        fd >= 0 ? "yes" : ("NO, errno " + std::to_string(create_err)).c_str(),
        fd < 0 ? "-" : unlink_err == 0 ? "yes" : ("NO, errno " + std::to_string(unlink_err)).c_str());
    /* One folder further in, the way Delete walks it. */
    if (listed > 0)
        if (DIR *d = opendir(path.c_str()))
        {
            while (dirent *e = readdir(d))
            {
                if (std::strcmp(e->d_name, ".") == 0 || std::strcmp(e->d_name, "..") == 0) continue;
                const std::string sub = path + "/" + e->d_name;
                struct stat st;
                if (stat(sub.c_str(), &st) == 0 && S_ISDIR(st.st_mode))
                {
                    DIR *s = opendir(sub.c_str());
                    say("      inside: %s %s %s", e->d_name, mode_text(st.st_mode).c_str(),
                        s ? "listed" : ("NOT listed, errno " + std::to_string(errno)).c_str());
                    if (s) closedir(s);
                }
            }
            closedir(d);
        }
    verdict = listed >= 0 && fd >= 0 && unlink_err == 0 ? "yes" : listed >= 0 ? "list only" : "no";
    return verdict;
}
} // namespace

int main(int argc, char **argv)
{
    const std::string target = argc > 1 && argv[1] && argv[1][0] == '/' ? argv[1] : kDefault;
    const pid_t pid = getpid();
    say("Recomp Store permission test 1 - folder: %s", target.c_str());
    creds("as started");

    struct stat tst;
    if (stat(target.c_str(), &tst) != 0)
    {
        say("The folder isn't there (errno %d): start the game once so it makes it, then run this again.", errno);
    }
    say("the folder and what's around it:");
    describe(target.substr(0, target.find_last_of('/')));
    describe(target);
    const std::string parent = target.substr(0, target.find_last_of('/'));
    if (DIR *d = opendir(parent.c_str()))
    {
        while (dirent *e = readdir(d))
            if (std::strcmp(e->d_name, ".") != 0 && std::strcmp(e->d_name, "..") != 0) describe(parent + "/" + e->d_name);
        closedir(d);
    }

    /* The credentials it started with, to put back at the end. */
    const uid_t uid0 = kernel_get_ucred_uid(pid), ruid0 = kernel_get_ucred_ruid(pid), svuid0 = kernel_get_ucred_svuid(pid);
    const gid_t rgid0 = kernel_get_ucred_rgid(pid), svgid0 = kernel_get_ucred_svgid(pid);
    const std::uint64_t authid0 = kernel_get_ucred_authid(pid);
    std::uint8_t caps0[16] = {};
    kernel_get_ucred_caps(pid, caps0);

    std::vector<std::string> results;
    results.push_back("as started: " + probe(target, "as started"));

    /* Root with every capability (what the payload loader gives a payload). */
    static const std::uint8_t all[16] = {0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff,
                                         0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff};
    const int r1 = kernel_set_ucred_uid(pid, 0) | kernel_set_ucred_ruid(pid, 0) | kernel_set_ucred_svuid(pid, 0) |
                   kernel_set_ucred_rgid(pid, 0) | kernel_set_ucred_svgid(pid, 0) | kernel_set_ucred_caps(pid, all);
    say("set root and every capability: %s", r1 == 0 ? "done" : "FAILED");
    creds("as root");
    results.push_back("root: " + probe(target, "root, all caps"));

    /* Other auth IDs: the system's core, the shell core, and the one ps5 FTP servers use. */
    const struct { const char *name; std::uint64_t id; } ids[] = {
        {"SceSysCore", 0x4800000000000007ull}, {"SceShellCore", 0x4800000000010003ull}, {"ftpsrv's", 0x4801000000000013ull}};
    for (const auto &a : ids)
    {
        const int r = kernel_set_ucred_authid(pid, a.id);
        say("auth ID %s (0x%016llx): %s", a.name, (unsigned long long)a.id, r == 0 ? "set" : "FAILED");
        results.push_back(std::string("root + ") + a.name + ": " + probe(target, a.name));
    }
    kernel_set_ucred_authid(pid, authid0);

    /* As the folder's owner: the game's own user. */
    if (stat(target.c_str(), &tst) == 0)
    {
        const int r = kernel_set_ucred_uid(pid, tst.st_uid) | kernel_set_ucred_ruid(pid, tst.st_uid) |
                      kernel_set_ucred_svuid(pid, tst.st_uid) | kernel_set_ucred_rgid(pid, tst.st_gid) |
                      kernel_set_ucred_svgid(pid, tst.st_gid);
        say("become the folder's owner (uid %u gid %u): %s", unsigned(tst.st_uid), unsigned(tst.st_gid), r == 0 ? "done" : "FAILED");
        creds("as owner");
        results.push_back("owner: " + probe(target, "as the owner"));
    }

    /* Back as it was. */
    kernel_set_ucred_uid(pid, uid0);
    kernel_set_ucred_ruid(pid, ruid0);
    kernel_set_ucred_svuid(pid, svuid0);
    kernel_set_ucred_rgid(pid, rgid0);
    kernel_set_ucred_svgid(pid, svgid0);
    kernel_set_ucred_caps(pid, caps0);
    kernel_set_ucred_authid(pid, authid0);
    creds("put back");

    say("summary:");
    std::string brief;
    for (const std::string &r : results)
    {
        say("  %s", r.c_str());
        brief += (brief.empty() ? "" : " | ") + r;
    }

    mkdir("/data/recompstore", 0777);
    const int fd = open(kReport, O_WRONLY | O_CREAT | O_TRUNC, 0666);
    bool saved = false;
    if (fd >= 0)
    {
        saved = write(fd, g_report.data(), g_report.size()) == ssize_t(g_report.size());
        close(fd);
        chmod(kReport, 0666);
    }
    toast(std::string("Recomp Store permission test done") + (saved ? ": /data/recompstore/permtest.txt" : " (couldn't save the report)") +
          "\n" + brief);
    return 0;
}
