LZMA SDK (C) - the part Recomp Store uses to read .7z releases (store_7z.cpp).

From Igor Pavlov's 7-Zip, https://github.com/ip7z/7zip, folder C/,
version 26.04 (commit 9128b80e3a471108678e2b3ec3c985861c8d7b0f).
These files are in the public domain, as each one's header says.

Only the decoding side is here: the 7z archive reader (7zArcIn, 7zDec, 7zStream,
7zBuf), LZMA and LZMA2, the branch filters (Bra, Bra86, BraIA64, Bcj2), Delta and
PPMd (Ppmd7, Ppmd7Dec).

One change: 7zDec.c defines Z7_PPMD_SUPPORT, so a PPMd archive can be decoded
(in memory). 7zCrc.c and CpuArch.c aren't used: store_7z.cpp gives the SDK its
CrcCalc and CrcUpdate from the store's own CRC-32.
