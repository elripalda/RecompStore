/* Recomp Store - SHA-1 (FIPS 180-4), for telling a player's file is the right one (a
 * game's ROM: its port lists the SHA-1s it takes). Not for security.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <algorithm>
#include <cstddef>
#include <cstdint>
#include <cstring>
#include <string>

namespace recomp
{
struct Sha1
{
    std::uint32_t h[5] = {0x67452301, 0xEFCDAB89, 0x98BADCFE, 0x10325476, 0xC3D2E1F0};
    std::uint8_t buf[64];
    std::uint64_t len = 0;
    std::size_t fill = 0;
    static std::uint32_t rol(std::uint32_t x, int n) { return (x << n) | (x >> (32 - n)); }
    void block(const std::uint8_t *p)
    {
        std::uint32_t w[80];
        for (int i = 0; i < 16; ++i)
            w[i] = std::uint32_t(p[i * 4]) << 24 | std::uint32_t(p[i * 4 + 1]) << 16 | std::uint32_t(p[i * 4 + 2]) << 8 | p[i * 4 + 3];
        for (int i = 16; i < 80; ++i) w[i] = rol(w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16], 1);
        std::uint32_t a = h[0], b = h[1], c = h[2], d = h[3], e = h[4];
        for (int i = 0; i < 80; ++i)
        {
            std::uint32_t f, k;
            if (i < 20) { f = (b & c) | (~b & d); k = 0x5A827999; }
            else if (i < 40) { f = b ^ c ^ d; k = 0x6ED9EBA1; }
            else if (i < 60) { f = (b & c) | (b & d) | (c & d); k = 0x8F1BBCDC; }
            else { f = b ^ c ^ d; k = 0xCA62C1D6; }
            const std::uint32_t t = rol(a, 5) + f + e + k + w[i];
            e = d;
            d = c;
            c = rol(b, 30);
            b = a;
            a = t;
        }
        h[0] += a;
        h[1] += b;
        h[2] += c;
        h[3] += d;
        h[4] += e;
    }
    void update(const std::uint8_t *p, std::size_t n)
    {
        len += n;
        while (n)
        {
            const std::size_t take = std::min<std::size_t>(64 - fill, n);
            std::memcpy(buf + fill, p, take);
            fill += take;
            p += take;
            n -= take;
            if (fill == 64)
            {
                block(buf);
                fill = 0;
            }
        }
    }
    std::string hex()
    {
        const std::uint64_t bits = len * 8;
        const std::uint8_t one = 0x80, zero = 0;
        update(&one, 1);
        while (fill != 56) update(&zero, 1);
        std::uint8_t lenb[8];
        for (int i = 0; i < 8; ++i) lenb[i] = std::uint8_t(bits >> (56 - 8 * i));
        update(lenb, 8);
        static const char *digits = "0123456789abcdef";
        std::string out;
        for (std::uint32_t v : h)
            for (int s = 28; s >= 0; s -= 4) out += digits[(v >> s) & 15];
        return out;
    }
};
} // namespace recomp
