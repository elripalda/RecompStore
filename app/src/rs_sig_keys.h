/* Recomp Store 2.1: the public keys signatures are checked against, from Recomp Store Signer's
 * public-keys.txt (tools/make-sig-keys.py). Public halves only. */
#pragma once

typedef struct
{
    const char *id;  /* first 8 bytes of SHA-256 of the key, hex */
    const char *pub; /* 32 bytes, hex */
} rs_sig_key;

static const rs_sig_key kRsSigKeys[] = {
    {"f4c7aaa055452c70", "a7dcee379a0036340485a6ef4add505c7ec3df32983cadfc5600225bd67992a1"}, /* main */
    {"d999124bca15295d", "f29564a6a3a2ba1036744bfd6e8dbf30d23ca5105914482d95093c3ce836c660"}, /* backup */
};
