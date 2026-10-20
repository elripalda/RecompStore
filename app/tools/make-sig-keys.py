#!/usr/bin/env python3
"""Writes app/src/rs_sig_keys.h from Recomp Store Signer's public-keys.txt (public halves only).

    tools/make-sig-keys.py <public-keys.txt> app/src/rs_sig_keys.h [--test]

--test marks the header as throwaway keys, which tools/build-store.sh refuses to release.
"""
import hashlib
import re
import sys

src, out = sys.argv[1], sys.argv[2]
test = '--test' in sys.argv[3:]
text = open(src, encoding='utf-8').read()
keys = re.findall(r'^(main|backup): ([0-9a-f]{64})', text, re.M)
if len(keys) < 2:
    sys.exit('public-keys.txt needs a main and a backup key')
rows = []
for name, pub in keys:
    kid = hashlib.sha256(bytes.fromhex(pub)).hexdigest()[:16]
    rows.append('    {"%s", "%s"}, /* %s */' % (kid, pub, name))
head = ("/* Recomp Store 2.1: the public keys signatures are checked against, from Recomp Store Signer's\n"
        " * public-keys.txt (tools/make-sig-keys.py). Public halves only. */\n"
        "#pragma once\n\n")
if test:
    head += ("/* TEST KEYS from a throwaway signer: tools/build-store.sh refuses to package a release with\n"
             " * these. */\n#define RS_SIG_TEST_KEYS 1\n\n")
body = ("typedef struct\n{\n"
        "    const char *id;  /* first 8 bytes of SHA-256 of the key, hex */\n"
        "    const char *pub; /* 32 bytes, hex */\n"
        "} rs_sig_key;\n\n"
        "static const rs_sig_key kRsSigKeys[] = {\n" + "\n".join(rows) + "\n};\n")
open(out, 'w').write(head + body)
print('wrote', out, [k[0] for k in keys], '(TEST)' if test else '')
