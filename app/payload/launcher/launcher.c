/* Recomp Store launcher - opens a game for the store.
 *
 * An app may not start another app itself (the system answers "not allowed"),
 * so the store leaves the game's title ID in /data/recompstore/launch/job.txt:
 *     token=<number the store chose>
 *     title=<PPSA12345>
 * hands this payload to the payload loader on port 9021 (etaHEN or elfldr),
 * and closes once status.txt there says
 *     token=<the same>  title=  phase=waiting
 * This waits for the store to be gone, then opens the game (phase=done).
 *
 * (2.1.1) A game installed a moment ago isn't on the home screen yet:
 * ShadowMountPlus 1.7 adds nothing while an app is open, and the store is one.
 * So when it won't open, this asks ShadowMountPlus to look now that the store
 * has closed, says so in a toast, and tries again every two seconds for up to
 * 90 seconds. Only if it still won't open does it open the store again
 * (phase=failed, result=<the system's answer>), and the store says why. The
 * job may carry the game's name (name=) for the toast.
 *
 * (2.2) ShadowMountPlus takes no changes at all while an app runs, so the
 * store hands over what's waiting for it: do0=, do1=, ... each "forget
 * <title ID>" (take its tile off: the app was deleted, or given a new ID) or
 * "unmount <title ID>" (it was moved: the next look finds it where it is now).
 * They're made first, once the store has gone. And mode=refresh (title= the
 * store's own) only refreshes the home screen: the changes, a look for apps,
 * a wait for it, then the store opens again (phase=refreshed smp=1|0
 * changes=<how many were made>).
 *
 * (2.2.1) ShadowMountPlus is only ever sent from here, once the store has
 * closed. A second copy of it stops the running one, and the running one's exit
 * takes /data and the USB drives away from every app that's open (its sandbox
 * aliases, removed in sm_fakelib_game_shutdown); the new copy only gives them
 * to apps started after it. Sent while the store was open, the store lost its
 * own folder until it was opened again. So the job may carry path=, the
 * player's ShadowMountPlus file: mode=reload_smp sends it whether or not one
 * is running, waits for the running one to stop answering and the new one to
 * answer on 10101 (45 s at most), asks it to look for apps, then opens the
 * store again (phase=refreshed reload=1 smp=1|0 restarted=1|0). In the other
 * modes it's sent only when nothing answers on 10101 (started=1).
 *
 * A file called "cancel" there stops it before it opens anything. What
 * happened goes to the store's log, as "launcher:" lines.
 *
 * The launch call and its parameters are the ones Porpoise's home launcher
 * uses, which follow shsrv's "launch" command (John Törnblom,
 * ps5-payload-dev/shsrv, bundles/launch/launch.c, GPL-3.0-or-later).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include <arpa/inet.h>
#include <errno.h>
#include <netinet/in.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

#ifndef RECOMP_DIR
#define RECOMP_DIR "/data/recompstore"
#endif
#define JOB_DIR RECOMP_DIR "/launch"
#define STORE_TITLE "PPSA92000"

typedef struct app_launch_ctx
{
    uint32_t structsize;
    uint32_t user_id;
    uint32_t app_opt;
    uint64_t crash_report;
    uint32_t check_flag;
} app_launch_ctx_t;

int sceUserServiceInitialize(void *);
int sceUserServiceGetForegroundUser(uint32_t *user_id);
int sceSystemServiceLaunchApp(const char *title_id, char **argv, app_launch_ctx_t *ctx);
int sceSystemServiceGetAppIdOfRunningBigApp(void);
int sceKernelSendNotificationRequest(int device, void *request, size_t size, int blocking);
int sceNotificationSend(int user_id, bool logged, const char *payload);

/* The kernel's plain toast (the installer's fallback too). */
typedef struct
{
    int32_t type, req_id, priority, msg_id, target_id, user_id, unk1, unk2, app_id, error_num, unk3;
    uint8_t use_icon_image_uri;
    char message[1024];
    char icon_uri[1024];
    char unk[1024];
    uint8_t pad[3];
} __attribute__((packed)) kernel_toast_t;
_Static_assert(sizeof(kernel_toast_t) == 0xc30, "the kernel toast is 0xc30 bytes");

static void log_line(const char *fmt, ...)
{
    char body[512];
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(body, sizeof body, fmt, ap);
    va_end(ap);
    time_t now = time(NULL);
    struct tm tm;
    localtime_r(&now, &tm);
    char stamp[32];
    strftime(stamp, sizeof stamp, "%Y-%m-%d %H:%M:%S", &tm);
    FILE *f = fopen(RECOMP_DIR "/logs/store.log", "a");
    if (f)
    {
        fprintf(f, "%s launcher: %s\n", stamp, body);
        fclose(f);
    }
}

static char g_title[32];
static void set_status(const char *token, const char *phase, long result)
{
    FILE *f = fopen(JOB_DIR "/status.tmp", "w");
    if (!f)
        return;
    fprintf(f, "token=%s\ntitle=%s\nphase=%s\nresult=%ld\n", token, g_title, phase, result);
    fclose(f);
    rename(JOB_DIR "/status.tmp", JOB_DIR "/status.txt");
}

/* Four capital letters and five digits: nothing else is ever opened. */
static int title_ok(const char *s)
{
    if (strlen(s) != 9)
        return 0;
    for (int i = 0; i < 4; ++i)
        if (s[i] < 'A' || s[i] > 'Z')
            return 0;
    for (int i = 4; i < 9; ++i)
        if (s[i] < '0' || s[i] > '9')
            return 0;
    return 1;
}

static void field(const char *text, const char *key, char *out, size_t size)
{
    out[0] = '\0';
    const size_t k = strlen(key);
    for (const char *line = text; *line;)
    {
        const char *end = strchr(line, '\n');
        const size_t len = end ? (size_t)(end - line) : strlen(line);
        if (len > k && strncmp(line, key, k) == 0 && line[k] == '=')
        {
            size_t n = len - k - 1;
            if (n >= size)
                n = size - 1;
            memcpy(out, line + k + 1, n);
            out[n] = '\0';
            return;
        }
        if (!end)
            break;
        line = end + 1;
    }
}

static int cancelled(void)
{
    struct stat st;
    return stat(JOB_DIR "/cancel", &st) == 0;
}

/* A system toast, as the installer posts one: the rich kind, else the kernel's plain one. */
static void toast(const char *text)
{
    static unsigned count = 0;
    char quoted[600];
    size_t o = 0;
    for (const unsigned char *p = (const unsigned char *)text; *p && o + 8 < sizeof quoted; ++p)
    {
        if (*p == '"' || *p == '\\') { quoted[o++] = '\\'; quoted[o++] = (char)*p; }
        else if (*p < 0x20) quoted[o++] = ' ';
        else quoted[o++] = (char)*p;
    }
    quoted[o] = '\0';
    char created[40];
    const time_t now = time(NULL);
    struct tm tm;
    gmtime_r(&now, &tm);
    strftime(created, sizeof created, "%Y-%m-%dT%H:%M:%S.000Z", &tm);
    char json[1200];
    snprintf(json, sizeof json,
             "{\"rawData\":{\"viewTemplateType\":\"InteractiveToastTemplateB\",\"channelType\":\"Downloads\","
             "\"useCaseId\":\"IDC\",\"toastOverwriteType\":\"No\",\"isImmediate\":true,\"priority\":100,"
             "\"viewData\":{\"message\":{\"body\":\"%s\"}}},\"createdDateTime\":\"%s\",\"localNotificationId\":\"%u\"}",
             quoted, created, 200000000u + ((unsigned)now % 800000u) * 1000u + (count++ % 1000u));
    if (sceNotificationSend(0xFE, true, json) != 0)
    {
        kernel_toast_t req;
        memset(&req, 0, sizeof req);
        snprintf(req.message, sizeof req.message, "%s", text);
        sceKernelSendNotificationRequest(0, &req, sizeof req, 0);
    }
    log_line("toast: %s", text);
}

/* (2.2) strstr and atoi, written out: the launcher imports nothing it didn't already. */
static const char *find_text(const char *hay, const char *needle)
{
    const size_t n = strlen(needle);
    for (; hay && *hay; ++hay)
        if (strncmp(hay, needle, n) == 0)
            return hay;
    return NULL;
}

static int read_int(const char *s)
{
    while (*s == ' ' || *s == '\t')
        ++s;
    const int neg = *s == '-';
    if (*s == '-' || *s == '+')
        ++s;
    int v = 0;
    while (*s >= '0' && *s <= '9' && v < 100000000)
        v = v * 10 + (*s++ - '0');
    return neg ? -v : v;
}

/* ShadowMountPlus 1.7's API (HTTP on 127.0.0.1:10101): one JSON object POSTed to route. The
 * HTTP status it answered with (its body, the JSON, in out), or -1 when nothing answered. */
static int smp_post(const char *route, const char *body, char *out, size_t out_size)
{
    if (out_size)
        out[0] = '\0';
    const int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0)
        return -1;
    struct timeval limit = {3, 0};
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &limit, sizeof limit);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &limit, sizeof limit);
    struct sockaddr_in sa;
    memset(&sa, 0, sizeof sa);
    sa.sin_family = AF_INET;
    sa.sin_port = htons(10101);
    sa.sin_addr.s_addr = htonl(0x7F000001);
    if (connect(fd, (struct sockaddr *)&sa, sizeof sa) != 0)
    {
        close(fd);
        return -1;
    }
    char req[512];
    const int n = snprintf(req, sizeof req,
                           "POST %s HTTP/1.1\r\nHost: 127.0.0.1:10101\r\nContent-Type: application/json\r\n"
                           "Content-Length: %zu\r\nConnection: close\r\n\r\n%s",
                           route, strlen(body), body);
    size_t at = 0;
    while (n > 0 && at < (size_t)n)
    {
        const ssize_t w = send(fd, req + at, (size_t)n - at, 0);
        if (w < 0 && errno == EINTR)
            continue;
        if (w <= 0)
            break;
        at += (size_t)w;
    }
    static char got[256 * 1024];
    size_t have = 0;
    for (;;)
    {
        const ssize_t r = recv(fd, got + have, sizeof got - 1 - have, 0);
        if (r < 0 && errno == EINTR)
            continue;
        if (r <= 0)
            break;
        have += (size_t)r;
        if (have >= sizeof got - 1)
            break;
    }
    close(fd);
    got[have] = '\0';
    if (have < 12 || strncmp(got, "HTTP/", 5) != 0)
        return -1;
    const char *sp = strchr(got, ' ');
    const int status = sp ? read_int(sp + 1) : 0;
    const char *json = find_text(got, "\r\n\r\n");
    if (out_size)
        snprintf(out, out_size, "%s", json ? json + 4 : got);
    return status;
}

/* A rescan, now that no app is open: true when ShadowMountPlus answered at all. (2.3.0) It asks
 * ShadowMountPlus to forget its tries too ("reset_attempts"): after two failed tries to register a
 * title that isn't in the system's app list (a store deleted from the home screen), it stops trying
 * until it's restarted or asked this way. */
static bool smp_scan(void)
{
    char reply[600];
    const int status = smp_post("/api/v1/scan", "{\"reset_attempts\":true}", reply, sizeof reply);
    if (status < 0)
        return false;
    log_line("asked ShadowMountPlus to look for new games: %.300s", reply);
    return true;
}

/* (2.2) How many games ShadowMountPlus knows ("count" in /api/v1/games), -1 when it can't say. */
static int smp_count(void)
{
    static char reply[256 * 1024];
    if (smp_post("/api/v1/games", "{}", reply, sizeof reply) != 200)
        return -1;
    const char *c = find_text(reply, "\"count\"");
    if (!c)
        return -1;
    c = strchr(c, ':');
    return c ? read_int(c + 1) : -1;
}

/* (2.2) The home screen changes the store handed over (do0=, do1=, ...), made now that it has
 * closed: a change ShadowMountPlus is still too busy for (the store's exit can take it a moment)
 * is tried again for up to 15 seconds. How many were made; *asked: ShadowMountPlus answered. */
static int make_changes(const char *job, bool *asked)
{
    int made = 0;
    *asked = false;
    for (int i = 0; i < 16; ++i)
    {
        char key[8], line[64];
        snprintf(key, sizeof key, "do%d", i);
        field(job, key, line, sizeof line);
        if (!line[0])
            break;
        const char *sp = strchr(line, ' ');
        if (!sp || !title_ok(sp + 1))
            continue;
        const bool forget = strncmp(line, "forget ", 7) == 0, unmount = strncmp(line, "unmount ", 8) == 0;
        if (!forget && !unmount)
            continue;
        char body[64], reply[600];
        snprintf(body, sizeof body, "{\"title_id\":\"%s\"}", sp + 1);
        const char *route = forget ? "/api/v1/games/uninstall" : "/api/v1/games/unmount";
        int status = -1;
        for (int tries = 0; tries < 15; ++tries)
        {
            status = smp_post(route, body, reply, sizeof reply);
            const bool busy = status == 409 || status == 503 || find_text(reply, "busy") || find_text(reply, "game_active");
            if (status < 0 || status == 200 || status == 404 || !busy)
                break;
            sleep(1);
        }
        if (status >= 0)
            *asked = true;
        log_line("%s %s: %s %.200s", forget ? "taking the tile off for" : "letting go of the old place of", sp + 1,
                 status < 0 ? "ShadowMountPlus didn't answer" : status == 200 ? "done" : "not done:", status == 200 ? "" : reply);
        if (status == 200 || status == 404)
            ++made; /* 404: there was nothing to change */
    }
    return made;
}
/* (2.2.1) Whether ShadowMountPlus answers on 10101 (its version). */
static bool smp_up(void)
{
    char reply[512];
    return smp_post("/api/v1/version", "{}", reply, sizeof reply) == 200;
}

/* (2.2.1) The player's ShadowMountPlus file, sent to the payload loader on port 9021 as the store
 * sends this launcher: an ELF of 64 bytes to 64 MB, read whole, then written to the socket. */
static bool send_elf(const char *path)
{
    struct stat st;
    if (path[0] != '/' || stat(path, &st) != 0 || st.st_size < 64 || st.st_size > 64 * 1024 * 1024)
    {
        log_line("ShadowMountPlus: %s isn't there, or isn't a payload", path);
        return false;
    }
    const size_t size = (size_t)st.st_size;
    unsigned char *elf = malloc(size);
    if (!elf)
        return false;
    FILE *f = fopen(path, "rb");
    size_t got = 0;
    if (f)
    {
        got = fread(elf, 1, size, f);
        fclose(f);
    }
    if (got != size || elf[0] != 0x7F || elf[1] != 'E' || elf[2] != 'L' || elf[3] != 'F')
    {
        log_line("ShadowMountPlus: %s couldn't be read as a payload", path);
        free(elf);
        return false;
    }
    const int fd = socket(AF_INET, SOCK_STREAM, 0);
    bool sent = false;
    if (fd >= 0)
    {
        struct timeval limit = {10, 0};
        setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &limit, sizeof limit);
        struct sockaddr_in sa;
        memset(&sa, 0, sizeof sa);
        sa.sin_family = AF_INET;
        sa.sin_port = htons(9021);
        sa.sin_addr.s_addr = htonl(0x7F000001);
        if (connect(fd, (struct sockaddr *)&sa, sizeof sa) == 0)
        {
            size_t at = 0;
            while (at < size)
            {
                const ssize_t w = send(fd, elf + at, size - at, 0);
                if (w < 0 && errno == EINTR)
                    continue;
                if (w <= 0)
                    break;
                at += (size_t)w;
            }
            sent = at == size;
        }
        close(fd);
    }
    free(elf);
    log_line(sent ? "ShadowMountPlus: sent %s (%u KB) to the payload loader" : "ShadowMountPlus: no payload loader took %s on port 9021",
             path, (unsigned)(size / 1024));
    return sent;
}

/* (2.2.1) Sends ShadowMountPlus and waits for it: 2 a new copy answers (the old one, if one was
 * answering, stopped first), 1 the old one never stopped (it still answers, so nothing changed),
 * 0 nothing answers 45 seconds on, -1 it couldn't be sent. */
static int smp_start(const char *path, bool was_up)
{
    if (!send_elf(path))
        return -1;
    const time_t sent = time(NULL);
    if (was_up)
    {
        /* The running copy answers until it has stopped (the new one asks it to). */
        bool down = false;
        while (time(NULL) - sent < 20 && !down)
        {
            down = !smp_up();
            if (!down)
                usleep(250 * 1000);
        }
        if (!down)
        {
            log_line("ShadowMountPlus: the running copy still answers 20 s on; it wasn't replaced");
            return 1;
        }
        log_line("ShadowMountPlus: the running copy stopped after %ld s", (long)(time(NULL) - sent));
    }
    while (time(NULL) - sent < 45)
    {
        if (smp_up())
        {
            log_line("ShadowMountPlus: answering again %ld s after it was sent", (long)(time(NULL) - sent));
            return 2;
        }
        usleep(500 * 1000);
    }
    log_line("ShadowMountPlus: nothing answered on port 10101 in 45 s");
    return 0;
}

/* The system's answer: 0 or more, it opened. Tries for a few seconds, as the
 * store may still be closing. */
static int open_title(const char *title, int attempts)
{
    app_launch_ctx_t ctx;
    memset(&ctx, 0, sizeof ctx);
    const int user = sceUserServiceGetForegroundUser(&ctx.user_id);
    log_line("foreground user %u (result %d)", (unsigned)ctx.user_id, user);
    char id[16];
    snprintf(id, sizeof id, "%s", title);
    char *argv[2] = {id, NULL};
    int result = -1;
    for (int attempt = 0; attempt < attempts; ++attempt)
    {
        result = sceSystemServiceLaunchApp(title, argv, &ctx);
        if (result >= 0)
            break;
        log_line("opening %s: the system answered 0x%08x (try %d)", title, (unsigned)result, attempt + 1);
        usleep(500 * 1000);
    }
    return result;
}

int main(void)
{
    char job[4096] = {0};
    FILE *f = fopen(JOB_DIR "/job.txt", "r");
    if (!f)
        return 1;
    const size_t n = fread(job, 1, sizeof job - 1, f);
    fclose(f);
    job[n] = '\0';
    unlink(JOB_DIR "/job.txt"); /* done once: a second copy of this finds nothing */

    char token[64], title[32], name[96], mode[16], path[512];
    field(job, "token", token, sizeof token);
    field(job, "title", title, sizeof title);
    field(job, "name", name, sizeof name);
    field(job, "mode", mode, sizeof mode);
    field(job, "path", path, sizeof path); /* (2.2.1) the player's ShadowMountPlus file */
    const bool reload = strcmp(mode, "reload_smp") == 0;
    const bool refresh = strcmp(mode, "refresh") == 0 || reload;
    if (!token[0] || !title_ok(title) || (reload && (path[0] != '/' || strcmp(title, STORE_TITLE) != 0)))
    {
        log_line("the job was not a game to open; nothing done");
        return 1;
    }
    if (cancelled())
    {
        log_line("the store had already given up on opening %s; nothing done", title);
        return 1;
    }
    snprintf(g_title, sizeof g_title, "%s", title);
    set_status(token, "waiting", 0);

    /* The store closes once it reads "waiting": wait (up to 10 s) until it has. */
    const int store = sceSystemServiceGetAppIdOfRunningBigApp();
    int gone = 0;
    for (int i = 0; i < 100 && !gone; ++i)
    {
        if (cancelled())
        {
            log_line("the store gave up on opening %s; nothing done", title);
            set_status(token, "failed", -1);
            return 1;
        }
        const int now = sceSystemServiceGetAppIdOfRunningBigApp();
        if (now != store || now < 0)
            gone = 1;
        else
            usleep(100 * 1000);
    }
    if (!gone && path[0])
    {
        /* (2.2.1) ShadowMountPlus may be sent below: never while the store is still open. */
        for (int i = 0; i < 200 && !gone && !cancelled(); ++i)
        {
            const int now = sceSystemServiceGetAppIdOfRunningBigApp();
            if (now != store || now < 0)
                gone = 1;
            else
                usleep(100 * 1000);
        }
    }
    log_line("%s; the store %s", reload ? "reloading ShadowMountPlus" : refresh ? "refreshing the home screen" : "opening the app",
             gone ? "has closed" : "is still open");
    usleep(300 * 1000);
    sceUserServiceInitialize(0);

    /* (2.2.1) ShadowMountPlus: sent again (Reload), or started when nothing answers and the
     * store gave its file. Only once the store is gone: its exit already let go of the store's
     * aliases, so the old copy's teardown takes nothing away from it. */
    const bool was_up = smp_up();
    int sent = -2; /* -2 not sent; else smp_start()'s answer */
    if (path[0] && gone && (reload || !was_up))
    {
        log_line("ShadowMountPlus %s; sending %s", was_up ? "is running" : "doesn't answer on port 10101", path);
        if (!reload)
            toast("Starting ShadowMountPlus\xE2\x80\xA6");
        sent = smp_start(path, was_up);
    }
    else if (reload)
        log_line("the store is still open, so ShadowMountPlus wasn't sent (it would take the store's storage away)");

    /* (2.2) What ShadowMountPlus couldn't do while the store was open. */
    bool asked = false;
    const int changes = make_changes(job, &asked);

    if (reload)
    {
        /* (2.2.1) Reload ShadowMountPlus: a look for apps with the new copy, then the store again. */
        const bool back = (sent == 2 || smp_up()) && smp_scan();
        if (sent == 2)
            toast("ShadowMountPlus reloaded.");
        else if (sent == 1)
            toast("ShadowMountPlus didn't restart. The copy that was running still is.");
        else if (sent == -2)
            toast("Recomp Store didn't close, so ShadowMountPlus wasn't reloaded.");
        else if (sent == -1)
            toast("ShadowMountPlus couldn't be sent to your payload loader.");
        else
            toast("ShadowMountPlus didn't come back. Start it from your payload loader, then open Recomp Store.");
        FILE *st = fopen(JOB_DIR "/status.tmp", "w");
        if (st)
        {
            fprintf(st, "token=%s\ntitle=%s\nphase=refreshed\nreload=1\nsmp=%d\nrestarted=%d\nchanges=%d\nresult=0\n", token, g_title,
                    back ? 1 : 0, sent == 2 ? 1 : 0, changes);
            fclose(st);
            rename(JOB_DIR "/status.tmp", JOB_DIR "/status.txt");
        }
        /* Its tile is mounted again once ShadowMountPlus answers (it starts its API last). */
        const int again = open_title(STORE_TITLE, 10);
        log_line("store opened again: 0x%08x", (unsigned)again);
        return 0;
    }

    if (refresh)
    {
        /* Refresh home screen: a look for apps, a wait while it adds them (it says nothing when
         * it's done: the count of games it knows settles), then the store again. */
        const bool smp = smp_scan() || asked;
        if (smp)
        {
            toast("Refreshing your home screen\xE2\x80\xA6");
            const time_t start = time(NULL);
            int last = smp_count(), same = 0;
            while (time(NULL) - start < 40)
            {
                sleep(3);
                const int c = smp_count();
                same = c == last ? same + 1 : 0;
                last = c;
                if (time(NULL) - start >= 10 && same >= 3)
                    break;
            }
            /* (2.3.0) Once more with ShadowMountPlus's tries reset, for a title it had given up on. */
            if (smp_scan())
            {
                const int before = last;
                for (int i = 0; i < 4; ++i)
                {
                    sleep(2);
                    const int c = smp_count();
                    if (c >= 0) last = c;
                }
                if (last != before) log_line("the last look added %d more", last - before);
            }
            log_line("home screen refreshed in %ld s (%d games, %d change(s) made)", (long)(time(NULL) - start), last, changes);
            toast("Your home screen is up to date.");
        }
        else
            log_line("ShadowMountPlus didn't answer on port 10101; nothing to refresh");
        FILE *st = fopen(JOB_DIR "/status.tmp", "w");
        if (st)
        {
            fprintf(st, "token=%s\ntitle=%s\nphase=refreshed\nsmp=%d\nstarted=%d\nchanges=%d\nresult=0\n", token, g_title, smp ? 1 : 0,
                    sent == 2 ? 1 : 0, changes);
            fclose(st);
            rename(JOB_DIR "/status.tmp", JOB_DIR "/status.txt");
        }
        const int back = open_title(STORE_TITLE, 6);
        log_line("store opened again: 0x%08x", (unsigned)back);
        return 0;
    }

    if (changes > 0)
        smp_scan(); /* a moved app: found where it is now */
    int result = open_title(title, 4);
    if (result < 0 && !cancelled())
    {
        /* (2.1.1) Not on the home screen yet: ShadowMountPlus can add it now that the store
         * has closed. Asked now and every 15 seconds; tried every 2 seconds, for 90 at most. */
        if (smp_scan())
        {
            char msg[200];
            snprintf(msg, sizeof msg, "Adding %s to your home screen. It opens in a moment.", name[0] ? name : title);
            toast(msg);
            const time_t start = time(NULL);
            time_t asked_at = start;
            while (result < 0 && time(NULL) - start < 90 && !cancelled())
            {
                sleep(2);
                if (time(NULL) - asked_at >= 15)
                {
                    asked_at = time(NULL);
                    (void)smp_scan();
                }
                app_launch_ctx_t ctx;
                memset(&ctx, 0, sizeof ctx);
                sceUserServiceGetForegroundUser(&ctx.user_id);
                char id[16];
                snprintf(id, sizeof id, "%s", title);
                char *argv[2] = {id, NULL};
                result = sceSystemServiceLaunchApp(title, argv, &ctx);
            }
            log_line("%s %s after %ld s (0x%08x)", result >= 0 ? "opened" : "still couldn't open", title,
                     (long)(time(NULL) - start), (unsigned)result);
            if (result < 0 && !cancelled() && smp_scan())
            {
                /* (2.3.0) Once more, with its tries forgotten, before giving up. */
                log_line("asked once more for %s, with ShadowMountPlus's tries reset", title);
                const time_t again = time(NULL);
                while (result < 0 && time(NULL) - again < 20 && !cancelled())
                {
                    sleep(2);
                    app_launch_ctx_t ctx;
                    memset(&ctx, 0, sizeof ctx);
                    sceUserServiceGetForegroundUser(&ctx.user_id);
                    char id[16];
                    snprintf(id, sizeof id, "%s", title);
                    char *argv[2] = {id, NULL};
                    result = sceSystemServiceLaunchApp(title, argv, &ctx);
                }
                log_line("%s %s on the last try (0x%08x)", result >= 0 ? "opened" : "still couldn't open", title, (unsigned)result);
            }
        }
        else
            log_line("ShadowMountPlus didn't answer on port 10101, so %s can't be added to the home screen from here", title);
    }
    if (result >= 0)
    {
        log_line("opened %s (0x%08x)", title, (unsigned)result);
        set_status(token, "done", result);
        return 0;
    }
    log_line("couldn't open %s (0x%08x); opening the store again", title, (unsigned)result);
    set_status(token, "failed", result);
    const int back = open_title(STORE_TITLE, 6);
    log_line("store opened again: 0x%08x", (unsigned)back);
    return 2;
}
