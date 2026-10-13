/* Recomp Store launcher - opens a game for the store.
 *
 * An app may not start another app itself (the system answers "not allowed"),
 * so the store leaves the game's title ID in /data/recompstore/launch/job.txt:
 *     token=<number the store chose>
 *     title=<PPSA12345>
 * hands this payload to the payload loader on port 9021 (etaHEN or elfldr),
 * and closes once status.txt there says
 *     token=<the same>  title=  phase=waiting
 * This waits for the store to be gone, then opens the game (phase=done). If the
 * game won't open (not on the home screen yet), it opens the store again
 * (phase=failed, result=<the system's answer>), and the store says why.
 * A file called "cancel" there stops it before it opens anything. What
 * happened goes to the store's log, as "launcher:" lines. No notifications.
 *
 * The launch call and its parameters are the ones Porpoise's home launcher
 * uses, which follow shsrv's "launch" command (John Törnblom,
 * ps5-payload-dev/shsrv, bundles/launch/launch.c, GPL-3.0-or-later).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include <stdarg.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
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
    char job[512] = {0};
    FILE *f = fopen(JOB_DIR "/job.txt", "r");
    if (!f)
        return 1;
    const size_t n = fread(job, 1, sizeof job - 1, f);
    fclose(f);
    job[n] = '\0';
    unlink(JOB_DIR "/job.txt"); /* done once: a second copy of this finds nothing */

    char token[64], title[32];
    field(job, "token", token, sizeof token);
    field(job, "title", title, sizeof title);
    if (!token[0] || !title_ok(title))
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
    log_line("opening %s; the store %s", title, gone ? "has closed" : "is still open after 10 s");
    usleep(300 * 1000);

    sceUserServiceInitialize(0);
    const int result = open_title(title, 10);
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
