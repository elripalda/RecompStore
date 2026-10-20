/* Recomp Store 2.1: the public keys signatures are checked against, from Recomp Store Signer's
 * public-keys.txt (tools/make-sig-keys.py). Public halves only. */
#pragma once

/* TEST KEYS from a throwaway signer: tools/build-store.sh refuses to package a release with
 * these. Replace with tools/make-sig-keys.py <the owner's public-keys.txt>. */
#define RS_SIG_TEST_KEYS 1

typedef struct
{
    const char *id;  /* first 8 bytes of SHA-256 of the key, hex */
    const char *pub; /* 32 bytes, hex */
} rs_sig_key;

static const rs_sig_key kRsSigKeys[] = {
    {"93de5519bf286d14", "113a85ca9d480936a422bdcc751addd0f65a05305a63e55158e88211693e4801"}, /* main */
    {"580002efb275a0e1", "26113c68e90ee5a7b519500ba79cd1bf4fb02f8e47c66364032522463c52b979"}, /* backup */
};
