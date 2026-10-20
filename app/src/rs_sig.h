/* Recomp Store 2.1: signatures made by Recomp Store Signer on the owner's PC.
 *
 *   RecompStore signature v1
 *   kind: catalog | catalog-test | store | beta
 *   file: catalog.json
 *   size: 48213
 *   sha256: <64 hex>
 *   signed: 2026-10-20T04:30:00Z
 *   key: <16 hex>
 *   sig: <128 hex>
 *
 * The Ed25519 signature covers "RecompStore signature v1\nkind: K\nsha256: H\nsigned: T\n".
 * The public keys it may be made with are built into the store (rs_sig_keys.h). */
#pragma once

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct
{
    char kind[16];
    char file[128];
    char sha256[65];   /* lower-case hex */
    char signed_at[24]; /* as written, RFC 3339 UTC */
    int64_t signed_unix;
    int64_t size;
    char key[17];
    uint8_t sig[64];
} rs_sig;

/* 1 when the text is a whole signature (not yet checked). */
int rs_sig_parse(const char *text, size_t len, rs_sig *out);

/* 1 when the signature is good, made by one of the built-in keys, of the given kind, for
 * bytes whose SHA-256 is sha256_hex. */
int rs_sig_check(const rs_sig *s, const char *kind, const char *sha256_hex);

#ifdef __cplusplus
}
#endif
