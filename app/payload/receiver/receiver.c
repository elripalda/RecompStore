/* Recomp Store receiver (2.3.0) - files from a phone on the same Wi-Fi, straight to the PS5.
 *
 * The store starts it (hands it to the payload loader on port 9021) only while an Upload or
 * "Back up to phone" screen is open, and leaves its job in /data/recompstore/receiver/job.txt:
 *     token=<number>   code=<6 digits>   title=<what the phone page says>
 *     dest=<folder uploads go to>        ext=<".iso .bin" or empty: any>
 *     backups=<the backups folder, served read only; empty: none>
 *     backup_id=<an app: its backups only, and an uploaded backup goes to backups/<id>/>
 *     max=<largest upload, bytes>        idle=<seconds with nothing happening before it stops>
 * It listens on one port (8380, or the next free up to 8389) and serves one small page at
 * http://<console>:<port>/?c=<code>. Uploads are PUT bodies written straight to disk under a
 * hidden temporary name, renamed when complete; downloads are plain GETs of backup zips.
 *
 * What it refuses: any request without the code (5 wrong codes and it stops answering), any
 * address that isn't on a home network (10/8, 172.16/12, 192.168/16, 169.254/16), names with
 * / \ .. or control characters, anything outside dest and the backups folder, uploads bigger
 * than max, and a second transfer while one runs (it serves one connection at a time).
 * It stops after `idle` seconds with nothing happening, or when the store writes
 * receiver/stop. What it's doing goes to receiver/status.txt (the store shows it) and the
 * store's log as "receiver:" lines.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <ifaddrs.h>
#include <netinet/in.h>
#include <stdarg.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

/* Plain string helpers of its own, so the payload imports only what the store's other payloads
 * already do, plus the network calls it can't do without (bind, listen, accept, getifaddrs). */
static char *r_strchr(const char *s, int c)
{
    for (;; ++s)
    {
        if (*s == (char)c) return (char *)s;
        if (!*s) return NULL;
    }
}
static char *r_strrchr(const char *s, int c)
{
    const char *hit = NULL;
    for (;; ++s)
    {
        if (*s == (char)c) hit = s;
        if (!*s) return (char *)hit;
    }
}
static char *r_strstr(const char *h, const char *n)
{
    const size_t k = strlen(n);
    for (; *h; ++h)
        if (strncmp(h, n, k) == 0) return (char *)h; /* stops at h's end */
    return k ? NULL : (char *)h;
}
static int r_strncasecmp(const char *a, const char *b, size_t n)
{
    for (size_t i = 0; i < n; ++i)
    {
        int x = (unsigned char)a[i], y = (unsigned char)b[i];
        if (x >= 'A' && x <= 'Z') x += 32;
        if (y >= 'A' && y <= 'Z') y += 32;
        if (x != y || !x) return x - y;
    }
    return 0;
}
#define strchr r_strchr
#define strrchr r_strrchr
#define strstr r_strstr
#define strncasecmp r_strncasecmp

#ifndef RECOMP_DIR
#define RECOMP_DIR "/data/recompstore"
#endif
#define JOB_DIR RECOMP_DIR "/receiver"
#define PORT_FIRST 8380
#define PORT_LAST 8389
#define BAD_TRIES 5

static char g_token[64], g_code[16], g_title[160], g_dest[512], g_ext[200], g_backups[512], g_backup_id[64];
static unsigned long long g_max = 0;
static long g_idle = 600;
static char g_ip[32] = "";
static int g_port = 0;
static int g_bad = 0;
static time_t g_last = 0;
/* status */
static char g_state[24] = "starting", g_file[160] = "", g_error[240] = "", g_lastfile[600] = "";
static unsigned long long g_bytes = 0, g_total = 0, g_speed = 0;
static int g_files = 0;
static int g_need_drain = 0; /* a file's body is still coming on the connection (its upload was refused) */

static void log_line(const char *fmt, ...)
{
    char body[600];
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
        fprintf(f, "%s receiver: %s\n", stamp, body);
        fclose(f);
    }
}

static void write_status(void)
{
    FILE *f = fopen(JOB_DIR "/status.tmp", "w");
    if (!f) return;
    fprintf(f, "token=%s\nstate=%s\nip=%s\nport=%d\nfile=%s\nbytes=%llu\ntotal=%llu\nspeed=%llu\nfiles=%d\nlast=%s\nerror=%s\nat=%lld\n",
            g_token, g_state, g_ip, g_port, g_file, g_bytes, g_total, g_speed, g_files, g_lastfile, g_error, (long long)time(NULL));
    fclose(f);
    rename(JOB_DIR "/status.tmp", JOB_DIR "/status.txt");
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
            if (n >= size) n = size - 1;
            memcpy(out, line + k + 1, n);
            out[n] = '\0';
            return;
        }
        if (!end) break;
        line = end + 1;
    }
}

static int stop_asked(void)
{
    struct stat st;
    return stat(JOB_DIR "/stop", &st) == 0;
}

/* ---- Names and places ---- */

/* A folder this may write into or read from: absolute, no "..", no "//", printable. */
static int folder_ok(const char *p)
{
    if (p[0] != '/' || strstr(p, "..") || strstr(p, "//") || strlen(p) < 6) return 0;
    for (const unsigned char *c = (const unsigned char *)p; *c; ++c)
        if (*c < 0x20 || *c == 0x7F || *c == '\\') return 0;
    return strncmp(p, "/data/", 6) == 0 || strncmp(p, "/mnt/usb", 8) == 0 || strncmp(p, "/mnt/ext", 8) == 0
#ifdef RECEIVER_DESKTOP
           || strncmp(p, "/tmp/", 5) == 0
#endif
        ;
}

/* A file's own name, from what the phone sent: no folders, nothing hidden, nothing odd. */
static int name_ok(const char *n)
{
    const size_t len = strlen(n);
    if (len == 0 || len > 120 || n[0] == '.' || n[0] == ' ') return 0;
    if (strstr(n, "..")) return 0;
    for (const unsigned char *c = (const unsigned char *)n; *c; ++c)
        if (*c < 0x20 || *c == 0x7F || *c == '/' || *c == '\\' || *c == ':' || *c == '*' || *c == '?' || *c == '"' || *c == '<' ||
            *c == '>' || *c == '|')
            return 0;
    return 1;
}

/* An app's ID as the store uses them (a title ID, or a custom source's "gh-..."). */
static int id_ok(const char *id)
{
    const size_t len = strlen(id);
    if (len < 3 || len > 48 || strstr(id, "..")) return 0;
    for (const unsigned char *c = (const unsigned char *)id; *c; ++c)
        if (!((*c >= 'a' && *c <= 'z') || (*c >= 'A' && *c <= 'Z') || (*c >= '0' && *c <= '9') || *c == '_' || *c == '.' || *c == '-'))
            return 0;
    return 1;
}

static int ends_with_ci(const char *s, const char *tail)
{
    const size_t a = strlen(s), b = strlen(tail);
    if (b > a) return 0;
    for (size_t i = 0; i < b; ++i)
    {
        char x = s[a - b + i], y = tail[i];
        if (x >= 'A' && x <= 'Z') x = (char)(x - 'A' + 'a');
        if (y >= 'A' && y <= 'Z') y = (char)(y - 'A' + 'a');
        if (x != y) return 0;
    }
    return 1;
}

/* The job's extensions (".iso .bin"), or any when there are none. */
static int ext_ok(const char *name, const char *list)
{
    if (!list[0]) return 1;
    char one[32];
    for (const char *p = list; *p;)
    {
        while (*p == ' ' || *p == ',') ++p;
        size_t n = 0;
        while (p[n] && p[n] != ' ' && p[n] != ',') ++n;
        if (n > 0 && n < sizeof one)
        {
            memcpy(one, p, n);
            one[n] = '\0';
            if (ends_with_ci(name, one)) return 1;
        }
        p += n;
    }
    return 0;
}

static void mkdirs(const char *path)
{
    char tmp[600];
    snprintf(tmp, sizeof tmp, "%s", path);
    for (char *p = tmp + 1; *p; ++p)
        if (*p == '/')
        {
            *p = '\0';
            mkdir(tmp, 0777);
            *p = '/';
        }
    mkdir(tmp, 0777);
}

/* ---- Who may ask ---- */

static int address_ok(const struct sockaddr_in *a)
{
    const uint32_t ip = ntohl(a->sin_addr.s_addr);
    if ((ip >> 24) == 10) return 1;
    if ((ip >> 20) == ((172u << 4) | 1u)) return 1; /* 172.16/12 */
    if ((ip >> 16) == ((192u << 8) | 168u)) return 1;
    if ((ip >> 16) == ((169u << 8) | 254u)) return 1;
#ifdef RECEIVER_DESKTOP
    if ((ip >> 24) == 127) return 1;
#endif
    return 0;
}

/* The code, compared in the same time whatever it's given. */
static int code_ok(const char *given)
{
    const size_t a = strlen(given), b = strlen(g_code);
    unsigned diff = (unsigned)(a ^ b);
    for (size_t i = 0; i < b; ++i) diff |= (unsigned)(unsigned char)(i < a ? given[i] : 0) ^ (unsigned char)g_code[i];
    return diff == 0 && b == 6;
}

/* ---- HTTP, one request at a time ---- */

static int send_all(int fd, const void *buf, size_t n)
{
    const char *p = (const char *)buf;
    while (n > 0)
    {
        const ssize_t w = send(fd, p, n, 0);
        if (w < 0 && errno == EINTR) continue;
        if (w <= 0) return 0;
        p += w;
        n -= (size_t)w;
    }
    return 1;
}

static void reply(int fd, int status, const char *type, const char *body, size_t len, const char *extra)
{
    const char *word = status == 200 ? "OK" : status == 400 ? "Bad Request" : status == 403 ? "Forbidden" : status == 404 ? "Not Found"
                       : status == 409 ? "Conflict" : status == 413 ? "Payload Too Large" : status == 507 ? "Insufficient Storage" : "Error";
    char head[700];
    const int h = snprintf(head, sizeof head,
                           "HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %zu\r\nCache-Control: no-store\r\n"
                           "X-Content-Type-Options: nosniff\r\nReferrer-Policy: no-referrer\r\nConnection: close\r\n%s\r\n",
                           status, word, type, len, extra ? extra : "");
    if (h > 0 && send_all(fd, head, (size_t)h) && len) send_all(fd, body, len);
}

static void reply_json(int fd, int status, const char *json) { reply(fd, status, "application/json; charset=utf-8", json, strlen(json), NULL); }

static void json_error(int fd, int status, const char *msg)
{
    char b[400];
    snprintf(b, sizeof b, "{\"ok\":false,\"error\":\"%s\"}", msg);
    reply_json(fd, status, b);
}

/* A query parameter, %-decoded. */
static void query(const char *q, const char *key, char *out, size_t size)
{
    out[0] = '\0';
    const size_t k = strlen(key);
    for (const char *p = q; p && *p;)
    {
        const char *amp = strchr(p, '&');
        const size_t len = amp ? (size_t)(amp - p) : strlen(p);
        if (len > k && strncmp(p, key, k) == 0 && p[k] == '=')
        {
            size_t o = 0;
            for (size_t i = k + 1; i < len && o + 1 < size; ++i)
            {
                char c = p[i];
                if (c == '+') c = ' ';
                else if (c == '%' && i + 2 < len)
                {
                    int v = 0;
                    for (int d = 1; d <= 2; ++d)
                    {
                        const char h = p[i + (size_t)d];
                        v = v * 16 + (h >= '0' && h <= '9' ? h - '0' : h >= 'a' && h <= 'f' ? h - 'a' + 10 : h >= 'A' && h <= 'F' ? h - 'A' + 10 : 0);
                    }
                    c = (char)v;
                    i += 2;
                }
                out[o++] = c;
            }
            out[o] = '\0';
            return;
        }
        if (!amp) break;
        p = amp + 1;
    }
}

static void json_str(char *out, size_t size, const char *s)
{
    size_t o = 0;
    for (const unsigned char *c = (const unsigned char *)s; *c && o + 7 < size; ++c)
    {
        if (*c == '"' || *c == '\\') { out[o++] = '\\'; out[o++] = (char)*c; }
        else if (*c < 0x20) { o += (size_t)snprintf(out + o, size - o, "\\u%04x", *c); }
        else out[o++] = (char)*c;
    }
    out[o] = '\0';
}

extern const char kPage[];
extern const char kCodePage[];

/* GET /api/info: what the page shows. */
static void send_info(int fd)
{
    char *b = malloc(65536);
    if (!b) return;
    char t[400], d[200];
    json_str(t, sizeof t, g_title);
    json_str(d, sizeof d, g_ext);
    size_t o = (size_t)snprintf(b, 65536, "{\"ok\":true,\"title\":\"%s\",\"ext\":\"%s\",\"max\":%llu,\"uploads\":%s,\"backup_id\":\"%s\",\"backups\":[",
                                t, d, g_max, g_dest[0] ? "true" : "false", g_backup_id);
    int n = 0;
    if (g_backups[0])
    {
        DIR *top = opendir(g_backups);
        struct dirent *e;
        while (top && (e = readdir(top)) && o < 60000 && n < 300)
        {
            if (!id_ok(e->d_name) || (g_backup_id[0] && strcmp(e->d_name, g_backup_id) != 0)) continue;
            char dir[700];
            snprintf(dir, sizeof dir, "%s/%s", g_backups, e->d_name);
            DIR *in = opendir(dir);
            struct dirent *f;
            while (in && (f = readdir(in)) && o < 60000 && n < 300)
            {
                if (!name_ok(f->d_name) || !ends_with_ci(f->d_name, ".zip")) continue;
                char path[900];
                snprintf(path, sizeof path, "%s/%s", dir, f->d_name);
                struct stat st;
                if (stat(path, &st) != 0 || !S_ISREG(st.st_mode)) continue;
                char nm[300];
                json_str(nm, sizeof nm, f->d_name);
                o += (size_t)snprintf(b + o, 65536 - o, "%s{\"id\":\"%s\",\"file\":\"%s\",\"size\":%llu,\"at\":%lld}", n ? "," : "", e->d_name, nm,
                                      (unsigned long long)st.st_size, (long long)st.st_mtime);
                ++n;
            }
            if (in) closedir(in);
        }
        if (top) closedir(top);
    }
    snprintf(b + o, 65536 - o, "]}");
    reply_json(fd, 200, b);
    free(b);
}

/* GET /api/backup?id=&file=: one backup zip, read only, from inside the backups folder. */
static void send_backup(int fd, const char *q)
{
    char id[64], file[160];
    query(q, "id", id, sizeof id);
    query(q, "file", file, sizeof file);
    if (!g_backups[0] || !id_ok(id) || !name_ok(file) || !ends_with_ci(file, ".zip") || (g_backup_id[0] && strcmp(id, g_backup_id) != 0))
    {
        log_line("refused a download: %s/%s", id, file);
        return json_error(fd, 403, "That isn't a backup this page offers.");
    }
    char path[900];
    snprintf(path, sizeof path, "%s/%s/%s", g_backups, id, file);
    const int in = open(path, O_RDONLY);
    struct stat st;
    if (in < 0 || fstat(in, &st) != 0 || !S_ISREG(st.st_mode))
    {
        if (in >= 0) close(in);
        return json_error(fd, 404, "That backup isn't there any more.");
    }
    snprintf(g_state, sizeof g_state, "sending");
    snprintf(g_file, sizeof g_file, "%s", file);
    g_bytes = 0;
    g_total = (unsigned long long)st.st_size;
    write_status();
    char extra[400];
    snprintf(extra, sizeof extra, "Content-Disposition: attachment; filename=\"%s\"\r\n", file);
    char head[900];
    const int h = snprintf(head, sizeof head,
                           "HTTP/1.1 200 OK\r\nContent-Type: application/zip\r\nContent-Length: %llu\r\nCache-Control: no-store\r\n%sConnection: close\r\n\r\n",
                           (unsigned long long)st.st_size, extra);
    int ok = h > 0 && send_all(fd, head, (size_t)h);
    const size_t cap = 1u << 20;
    char *buf = ok ? malloc(cap) : NULL;
    unsigned long long off = 0;
    struct timespec t0;
    clock_gettime(CLOCK_MONOTONIC, &t0);
    time_t shown = 0;
    while (ok && buf && off < (unsigned long long)st.st_size)
    {
        const ssize_t r = pread(in, buf, cap, (off_t)off);
        if (r <= 0) { ok = 0; break; }
        if (!send_all(fd, buf, (size_t)r)) { ok = 0; break; }
        off += (unsigned long long)r;
        g_bytes = off;
        g_last = time(NULL);
        if (g_last != shown)
        {
            shown = g_last;
            struct timespec t1;
            clock_gettime(CLOCK_MONOTONIC, &t1);
            const double s = (double)(t1.tv_sec - t0.tv_sec) + (double)(t1.tv_nsec - t0.tv_nsec) / 1e9;
            g_speed = s > 0.2 ? (unsigned long long)((double)off / s) : 0;
            write_status();
        }
    }
    free(buf);
    close(in);
    log_line("%s %s/%s (%llu of %llu bytes)", ok ? "sent" : "stopped sending", id, file, off, (unsigned long long)st.st_size);
    snprintf(g_state, sizeof g_state, "listening");
    g_file[0] = '\0';
    write_status();
}

/* PUT /api/upload?name=&to=files|backup: the body, straight to disk. */
static void take_upload(int fd, const char *q, unsigned long long len, const char *already, size_t have)
{
    char name[160], to[16];
    query(q, "name", name, sizeof name);
    query(q, "to", to, sizeof to);
    const int backup = strcmp(to, "backup") == 0;
    /* Only the file's own name (a phone may send a path). */
    const char *base = name;
    for (const char *p = name; *p; ++p)
        if (*p == '/' || *p == '\\') base = p + 1;
    char dir[700];
    if (backup)
    {
        if (!g_backups[0] || !g_backup_id[0] || !id_ok(g_backup_id)) return json_error(fd, 403, "Backups can't be sent to this screen.");
        snprintf(dir, sizeof dir, "%s/%s", g_backups, g_backup_id);
    }
    else
    {
        if (!g_dest[0]) return json_error(fd, 403, "This screen doesn't take files.");
        snprintf(dir, sizeof dir, "%s", g_dest);
    }
    if (!name_ok(base) || base != name)
    {
        log_line("refused a file name: %s", name);
        return json_error(fd, 400, "That file name can't be used. Rename it and try again.");
    }
    if (backup ? !ends_with_ci(base, ".zip") : !ext_ok(base, g_ext))
    {
        char m[300];
        snprintf(m, sizeof m, "This screen takes %s files only.", backup ? ".zip" : g_ext);
        return json_error(fd, 400, m);
    }
    if (len == 0) return json_error(fd, 400, "That file is empty.");
    if (len > g_max)
    {
        log_line("refused %s: %llu bytes, %llu allowed", base, len, g_max);
        return json_error(fd, 507, "There isn't room for that file on your PS5.");
    }
    if (!folder_ok(dir)) return json_error(fd, 403, "The PS5 can't take files there.");
    mkdirs(dir);
    char part[900], final[900];
    snprintf(part, sizeof part, "%s/.%s.part", dir, base);
    snprintf(final, sizeof final, "%s/%s", dir, base);
    struct stat st;
    for (int i = 2; stat(final, &st) == 0 && i < 100; ++i)
    {
        /* Never over a file that's there: "name (2).iso". */
        const char *dot = strrchr(base, '.');
        const size_t stem = dot && dot != base ? (size_t)(dot - base) : strlen(base);
        snprintf(final, sizeof final, "%s/%.*s (%d)%s", dir, (int)stem, base, i, dot && dot != base ? dot : "");
    }
    unlink(part);
    const int out = open(part, O_WRONLY | O_CREAT | O_TRUNC, 0666);
    if (out < 0)
    {
        log_line("couldn't write %s (errno %d)", part, errno);
        return json_error(fd, 500, "The PS5 couldn't write that file.");
    }
    snprintf(g_state, sizeof g_state, "receiving");
    snprintf(g_file, sizeof g_file, "%s", base);
    g_bytes = 0;
    g_total = len;
    g_error[0] = '\0';
    write_status();
    log_line("receiving %s (%llu bytes) into %s", base, len, dir);
    unsigned long long got = 0;
    int ok = 1;
    if (have > len) have = (size_t)len;
    if (have)
    {
        ok = write(out, already, have) == (ssize_t)have;
        got = have;
    }
    const size_t cap = 1u << 20;
    char *buf = malloc(cap);
    struct timespec t0;
    clock_gettime(CLOCK_MONOTONIC, &t0);
    time_t shown = 0;
    while (ok && buf && got < len)
    {
        const size_t want = len - got < cap ? (size_t)(len - got) : cap;
        const ssize_t r = recv(fd, buf, want, 0);
        if (r < 0 && errno == EINTR) continue;
        if (r <= 0) { ok = 0; break; }
        size_t done = 0;
        while (done < (size_t)r)
        {
            const ssize_t w = write(out, buf + done, (size_t)r - done);
            if (w <= 0) { ok = 0; break; }
            done += (size_t)w;
        }
        got += (unsigned long long)r;
        g_bytes = got;
        g_last = time(NULL);
        if (g_last != shown)
        {
            shown = g_last;
            struct timespec t1;
            clock_gettime(CLOCK_MONOTONIC, &t1);
            const double s = (double)(t1.tv_sec - t0.tv_sec) + (double)(t1.tv_nsec - t0.tv_nsec) / 1e9;
            g_speed = s > 0.2 ? (unsigned long long)((double)got / s) : 0;
            write_status();
            if (stop_asked()) { ok = 0; break; }
        }
    }
    free(buf);
    if (ok && fsync(out) != 0) ok = 0;
    close(out);
    struct stat done_st;
    if (ok && (stat(part, &done_st) != 0 || (unsigned long long)done_st.st_size != len)) ok = 0;
    if (ok && rename(part, final) != 0) ok = 0;
    if (!ok)
    {
        unlink(part);
        snprintf(g_state, sizeof g_state, "listening");
        snprintf(g_error, sizeof g_error, "%s didn't arrive whole. Try again.", base);
        g_file[0] = '\0';
        write_status();
        log_line("%s stopped at %llu of %llu bytes; the part was deleted", base, got, len);
        return json_error(fd, 500, "The file didn't arrive whole. Try again.");
    }
    ++g_files;
    g_need_drain = 0;
    snprintf(g_lastfile, sizeof g_lastfile, "%s", final);
    snprintf(g_state, sizeof g_state, "listening");
    g_file[0] = '\0';
    write_status();
    log_line("received %s: %llu bytes, checked, saved as %s", base, len, final);
    char nm[400], b[600];
    const char *leaf = strrchr(final, '/');
    json_str(nm, sizeof nm, leaf ? leaf + 1 : final);
    snprintf(b, sizeof b, "{\"ok\":true,\"name\":\"%s\",\"size\":%llu}", nm, len);
    reply_json(fd, 200, b);
}

static void serve(int fd)
{
    struct timeval tv = {30, 0};
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof tv);
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof tv);
    char req[8193];
    size_t n = 0;
    char *end = NULL;
    while (n < sizeof req - 1)
    {
        const ssize_t r = recv(fd, req + n, sizeof req - 1 - n, 0);
        if (r < 0 && errno == EINTR) continue;
        if (r <= 0) return;
        n += (size_t)r;
        req[n] = '\0';
        if ((end = strstr(req, "\r\n\r\n"))) break;
    }
    if (!end) return json_error(fd, 400, "That request was too long.");
    *end = '\0';
    const char *body = end + 4;
    const size_t have = n - (size_t)(body - req);
    char method[8] = "", target[2048] = "";
    {
        /* "PUT /api/upload?... HTTP/1.1" */
        size_t i = 0, m = 0, t = 0;
        while (req[i] && req[i] != ' ' && m + 1 < sizeof method) method[m++] = req[i++];
        method[m] = '\0';
        while (req[i] == ' ') ++i;
        while (req[i] && req[i] != ' ' && req[i] != '\r' && t + 1 < sizeof target) target[t++] = req[i++];
        target[t] = '\0';
        if (!m || !t || target[0] != '/') return json_error(fd, 400, "Bad request.");
    }
    char *q = strchr(target, '?');
    if (q) *q++ = '\0';
    unsigned long long len = 0;
    int has_len = 0, chunked = 0;
    for (char *line = strstr(req, "\r\n"); line; line = strstr(line + 2, "\r\n"))
    {
        const char *h = line + 2;
        if (strncasecmp(h, "Content-Length:", 15) == 0) { len = strtoull(h + 15, NULL, 10); has_len = 1; }
        if (strncasecmp(h, "Transfer-Encoding:", 18) == 0 && strstr(h, "chunked")) chunked = 1;
    }
    g_last = time(NULL);
    g_need_drain = strcmp(method, "PUT") == 0 && (chunked || (has_len && len > have));
    if (g_bad >= BAD_TRIES) return reply(fd, 403, "text/plain; charset=utf-8", "Locked. Start a new upload on your PS5.\n", 40, NULL);
    char code[16];
    query(q ? q : "", "c", code, sizeof code);
    const int page = strcmp(target, "/") == 0 || strcmp(target, "/index.html") == 0;
    if (page && !code[0]) return reply(fd, 200, "text/html; charset=utf-8", kCodePage, strlen(kCodePage), NULL);
    /* No code at all (a browser's own /favicon.ico) isn't a guess: it's refused, not counted. */
    if (!code[0]) return json_error(fd, 403, "This needs the code on your TV.");
    if (!code_ok(code))
    {
        ++g_bad;
        log_line("a wrong code (%d of %d)", g_bad, BAD_TRIES);
        if (g_bad >= BAD_TRIES)
        {
            snprintf(g_state, sizeof g_state, "locked");
            snprintf(g_error, sizeof g_error, "Too many wrong codes. Close this screen and open it again for a new code.");
            write_status();
        }
        if (page) return reply(fd, 403, "text/html; charset=utf-8", kCodePage, strlen(kCodePage), NULL);
        return json_error(fd, 403, "That code isn't the one on your TV.");
    }
    if (page) return reply(fd, 200, "text/html; charset=utf-8", kPage, strlen(kPage), NULL);
    if (strcmp(method, "GET") == 0 && strcmp(target, "/api/info") == 0) return send_info(fd);
    if (strcmp(method, "GET") == 0 && strcmp(target, "/api/backup") == 0) return send_backup(fd, q ? q : "");
    if (strcmp(method, "PUT") == 0 && strcmp(target, "/api/upload") == 0)
    {
        if (chunked || !has_len) return json_error(fd, 400, "Send the file with its size.");
        return take_upload(fd, q ? q : "", len, body, have);
    }
    return json_error(fd, 404, "Not here.");
}

/* What's still coming on a connection a reply refused (the rest of a file it won't take), read
 * and dropped for a moment before it's closed: closing with it unread would reset the connection,
 * and the phone would never see why. At most 16 MB or 2 seconds. */
static void drain(int fd)
{
    struct timeval tv = {0, 200000};
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof tv);
    char buf[65536];
    unsigned long long got = 0;
    struct timespec t0, t1;
    clock_gettime(CLOCK_MONOTONIC, &t0);
    for (;;)
    {
        const ssize_t r = recv(fd, buf, sizeof buf, 0);
        if (r <= 0) break;
        got += (unsigned long long)r;
        clock_gettime(CLOCK_MONOTONIC, &t1);
        if (got > (16u << 20) || t1.tv_sec - t0.tv_sec >= 2) break;
    }
}

/* The console's address on the home network, for the QR code. */
static void find_ip(void)
{
    struct ifaddrs *all = NULL;
    if (getifaddrs(&all) != 0) return;
    int best = 0;
    for (struct ifaddrs *i = all; i; i = i->ifa_next)
    {
        if (!i->ifa_addr || i->ifa_addr->sa_family != AF_INET) continue;
        const struct sockaddr_in *a = (const struct sockaddr_in *)i->ifa_addr;
        const uint32_t ip = ntohl(a->sin_addr.s_addr);
        if ((ip >> 24) == 127 || ip == 0) continue;
        const int score = (ip >> 16) == ((192u << 8) | 168u) ? 3 : (ip >> 24) == 10 ? 2 : address_ok(a) ? 1 : 0;
        if (score > best)
        {
            best = score;
            snprintf(g_ip, sizeof g_ip, "%u.%u.%u.%u", ip >> 24, (ip >> 16) & 255, (ip >> 8) & 255, ip & 255);
        }
    }
    freeifaddrs(all);
}

int main(void)
{
    char job[4096] = {0};
    FILE *f = fopen(JOB_DIR "/job.txt", "r");
    if (!f) return 1;
    const size_t n = fread(job, 1, sizeof job - 1, f);
    fclose(f);
    job[n] = '\0';
    unlink(JOB_DIR "/job.txt"); /* done once: a second copy of this finds nothing */
    char num[32];
    field(job, "token", g_token, sizeof g_token);
    field(job, "code", g_code, sizeof g_code);
    field(job, "title", g_title, sizeof g_title);
    field(job, "dest", g_dest, sizeof g_dest);
    field(job, "ext", g_ext, sizeof g_ext);
    field(job, "backups", g_backups, sizeof g_backups);
    field(job, "backup_id", g_backup_id, sizeof g_backup_id);
    field(job, "max", num, sizeof num);
    g_max = strtoull(num, NULL, 10);
    field(job, "idle", num, sizeof num);
    g_idle = strtol(num, NULL, 10);
    if (g_idle < 30 || g_idle > 3600) g_idle = 600;
    if (!g_token[0] || strlen(g_code) != 6 || (g_dest[0] && !folder_ok(g_dest)) || (g_backups[0] && !folder_ok(g_backups)) ||
        (g_backup_id[0] && !id_ok(g_backup_id)) || (!g_dest[0] && !g_backups[0]))
    {
        log_line("the job wasn't one it can do; nothing started");
        snprintf(g_state, sizeof g_state, "failed");
        snprintf(g_error, sizeof g_error, "The store's request wasn't right. Close the screen and try again.");
        write_status();
        return 1;
    }
    if (g_dest[0]) mkdirs(g_dest);
    find_ip();
    const int s = socket(AF_INET, SOCK_STREAM, 0);
    const int one = 1;
    setsockopt(s, SOL_SOCKET, SO_REUSEADDR, &one, sizeof one);
    struct sockaddr_in a;
    for (g_port = PORT_FIRST; g_port <= PORT_LAST; ++g_port)
    {
        memset(&a, 0, sizeof a);
        a.sin_family = AF_INET;
        a.sin_port = htons((uint16_t)g_port);
        a.sin_addr.s_addr = htonl(INADDR_ANY);
        if (bind(s, (struct sockaddr *)&a, sizeof a) == 0) break;
    }
    if (s < 0 || g_port > PORT_LAST || listen(s, 8) != 0)
    {
        log_line("no port free between %d and %d (errno %d)", PORT_FIRST, PORT_LAST, errno);
        snprintf(g_state, sizeof g_state, "failed");
        snprintf(g_error, sizeof g_error, "The PS5 couldn't open a port for your phone. Close the screen and try again.");
        g_port = 0;
        write_status();
        return 1;
    }
    struct timeval tv = {1, 0};
    setsockopt(s, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof tv); /* accept() wakes every second */
    snprintf(g_state, sizeof g_state, "listening");
    g_last = time(NULL);
    write_status();
    log_line("listening on %s:%d for \"%s\" (uploads to %s, backups %s, up to %llu bytes, stops after %ld s idle)", g_ip[0] ? g_ip : "?", g_port,
             g_title, g_dest[0] ? g_dest : "none", g_backups[0] ? g_backups : "none", g_max, g_idle);
    const char *why = "stopped";
    for (;;)
    {
        if (stop_asked()) { why = "the store closed the screen"; break; }
        if (time(NULL) - g_last > g_idle) { why = "nothing happened for a while"; break; }
        struct sockaddr_in from;
        socklen_t fl = sizeof from;
        const int c = accept(s, (struct sockaddr *)&from, &fl);
        if (c < 0) continue;
        if (!address_ok(&from))
        {
            const uint32_t ip = ntohl(from.sin_addr.s_addr);
            log_line("refused %u.%u.%u.%u: not on a home network", ip >> 24, (ip >> 16) & 255, (ip >> 8) & 255, ip & 255);
            close(c);
            continue;
        }
        g_need_drain = 0;
        serve(c);
        if (g_need_drain) drain(c);
        close(c);
    }
    close(s);
    snprintf(g_state, sizeof g_state, "stopped");
    write_status();
    log_line("stopped: %s (%d file%s received)", why, g_files, g_files == 1 ? "" : "s");
    return 0;
}
