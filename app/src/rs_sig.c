/* Recomp Store 2.1: checks signatures from Recomp Store Signer (rs_sig.h). Ed25519 from
 * Monocypher 4.0.2 (CC0), vendored beside this file. */
#include "rs_sig.h"
#include "rs_sig_keys.h"
#include "monocypher/monocypher-ed25519.h"

#include <string.h>

static int hexval(char c)
{
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

static int unhex(const char *s, size_t n, uint8_t *out)
{
    for (size_t i = 0; i < n; ++i)
    {
        const int a = hexval(s[2 * i]), b = hexval(s[2 * i + 1]);
        if (a < 0 || b < 0) return 0;
        out[i] = (uint8_t)(a * 16 + b);
    }
    return 1;
}

static int is_hex(const char *s, size_t n)
{
    for (size_t i = 0; i < n; ++i)
        if (hexval(s[i]) < 0) return 0;
    return 1;
}

/* Copies a field value (to the end of its line, \r dropped) into out. */
static void copy_value(const char *v, const char *end, char *out, size_t cap)
{
    size_t n = 0;
    while (v < end && *v != '\n' && *v != '\r' && n + 1 < cap) out[n++] = *v++;
    out[n] = 0;
}

static int64_t days_from_civil(int64_t y, unsigned m, unsigned d)
{
    y -= m <= 2;
    const int64_t era = (y >= 0 ? y : y - 399) / 400;
    const unsigned yoe = (unsigned)(y - era * 400);
    const unsigned doy = (153 * (m + (m > 2 ? -3 : 9)) + 2) / 5 + d - 1;
    const unsigned doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    return era * 146097 + (int64_t)doe - 719468;
}

/* "2026-10-20T04:30:00Z" only (what the signer writes). */
static int parse_time(const char *t, int64_t *out)
{
    if (strlen(t) != 20 || t[4] != '-' || t[7] != '-' || t[10] != 'T' || t[13] != ':' || t[16] != ':' || t[19] != 'Z') return 0;
    for (int i = 0; i < 19; ++i)
        if (i != 4 && i != 7 && i != 10 && i != 13 && i != 16 && (t[i] < '0' || t[i] > '9')) return 0;
    const int y = (t[0] - '0') * 1000 + (t[1] - '0') * 100 + (t[2] - '0') * 10 + (t[3] - '0');
    const int mo = (t[5] - '0') * 10 + (t[6] - '0'), d = (t[8] - '0') * 10 + (t[9] - '0');
    const int h = (t[11] - '0') * 10 + (t[12] - '0'), mi = (t[14] - '0') * 10 + (t[15] - '0'), s = (t[17] - '0') * 10 + (t[18] - '0');
    if (mo < 1 || mo > 12 || d < 1 || d > 31 || h > 23 || mi > 59 || s > 60) return 0;
    *out = days_from_civil(y, (unsigned)mo, (unsigned)d) * 86400 + h * 3600 + mi * 60 + s;
    return 1;
}

static const char kHeader[] = "RecompStore signature v1";

int rs_sig_parse(const char *text, size_t len, rs_sig *out)
{
    memset(out, 0, sizeof *out);
    const char *p = text, *end = text + len;
    const size_t hl = sizeof kHeader - 1;
    if (len < hl || memcmp(p, kHeader, hl) != 0) return 0;
    char sig_hex[130] = {0};
    char size_s[24] = {0};
    while (p < end)
    {
        const char *line = p;
        while (p < end && *p != '\n') ++p;
        const char *eol = p;
        if (p < end) ++p;
        const char *colon = line;
        while (colon < eol && *colon != ':') ++colon;
        if (colon >= eol || colon + 2 > eol || colon[1] != ' ') continue;
        const size_t kl = (size_t)(colon - line);
        const char *v = colon + 2;
        if (kl == 4 && !memcmp(line, "kind", 4)) copy_value(v, eol, out->kind, sizeof out->kind);
        else if (kl == 4 && !memcmp(line, "file", 4)) copy_value(v, eol, out->file, sizeof out->file);
        else if (kl == 4 && !memcmp(line, "size", 4)) copy_value(v, eol, size_s, sizeof size_s);
        else if (kl == 6 && !memcmp(line, "sha256", 6)) copy_value(v, eol, out->sha256, sizeof out->sha256);
        else if (kl == 6 && !memcmp(line, "signed", 6)) copy_value(v, eol, out->signed_at, sizeof out->signed_at);
        else if (kl == 3 && !memcmp(line, "key", 3)) copy_value(v, eol, out->key, sizeof out->key);
        else if (kl == 3 && !memcmp(line, "sig", 3)) copy_value(v, eol, sig_hex, sizeof sig_hex);
    }
    for (char *c = out->sha256; *c; ++c)
        if (*c >= 'A' && *c <= 'F') *c = (char)(*c - 'A' + 'a');
    for (const char *c = size_s; *c; ++c)
        if (*c >= '0' && *c <= '9') out->size = out->size * 10 + (*c - '0');
    if (!out->kind[0] || strlen(out->sha256) != 64 || !is_hex(out->sha256, 64)) return 0;
    if (strlen(out->key) != 16 || !is_hex(out->key, 16)) return 0;
    if (strlen(sig_hex) != 128 || !unhex(sig_hex, 64, out->sig)) return 0;
    if (!parse_time(out->signed_at, &out->signed_unix)) return 0;
    return 1;
}

int rs_sig_check(const rs_sig *s, const char *kind, const char *sha256_hex)
{
    if (!s || !kind || !sha256_hex || strcmp(s->kind, kind) != 0) return 0;
    if (strlen(sha256_hex) != 64) return 0;
    for (int i = 0; i < 64; ++i)
    {
        char c = sha256_hex[i];
        if (c >= 'A' && c <= 'F') c = (char)(c - 'A' + 'a');
        if (c != s->sha256[i]) return 0;
    }
    /* The signed message: the header, kind, hash and time, one per line. */
    char msg[200];
    size_t n = 0;
    const char *parts[] = {kHeader, "\nkind: ", s->kind, "\nsha256: ", s->sha256, "\nsigned: ", s->signed_at, "\n"};
    for (size_t i = 0; i < sizeof parts / sizeof parts[0]; ++i)
    {
        const size_t l = strlen(parts[i]);
        if (n + l >= sizeof msg) return 0;
        memcpy(msg + n, parts[i], l);
        n += l;
    }
    for (size_t k = 0; k < sizeof kRsSigKeys / sizeof kRsSigKeys[0]; ++k)
    {
        if (strcmp(kRsSigKeys[k].id, s->key) != 0) continue;
        uint8_t pub[32];
        if (strlen(kRsSigKeys[k].pub) != 64 || !unhex(kRsSigKeys[k].pub, 32, pub)) continue;
        if (crypto_ed25519_check(s->sig, pub, (const uint8_t *)msg, n) == 0) return 1;
    }
    return 0;
}
