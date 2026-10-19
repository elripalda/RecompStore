/* Recomp Store - what every screen file shares: the brand's colours, easing, and small
 * text helpers. (2.0: the screens are split over store_app*.cpp.)
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include "store_engine.hpp"
#include "ui_gfx.hpp"

#include <algorithm>
#include <cctype>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <string>

namespace recomp::sui
{
using porpoise::ui::Color;
using porpoise::ui::Texture;
inline Color C(std::uint32_t hex, float a = 1) { return porpoise::ui::rgba(hex, a); }
inline constexpr std::uint32_t kMidnight = 0x0B1220, kFrost = 0xF4F8FC, kCyan = 0x35D6F4, kMuted = 0xAAB6C6, kGreen = 0x5FE3A1,
                    kSoft = 0xCDD6E2, kDim = 0x95A2B4, kBody = 0xE1E8F1, kAmber = 0xFFC857 /* (1.1.0) beta */;
inline const Color kGlass = {1, 1, 1, 0.08f}, kRim = {1, 1, 1, 0.16f}, kLine = {1, 1, 1, 0.12f};

inline float clamp01(float t) { return std::clamp(t, 0.f, 1.f); }
inline float ease_out(float t)
{
    t = clamp01(t);
    return 1 - (1 - t) * (1 - t) * (1 - t);
}
/* cubic-bezier(.2,.9,.25,1.15)-like: a little past, then back. */
inline float ease_back(float t)
{
    t = clamp01(t);
    const float c = 1.4f;
    return 1 + (c + 1) * std::pow(t - 1, 3.f) + c * std::pow(t - 1, 2.f);
}
inline float approach(float cur, float target, float dt, float rate) { return cur + (target - cur) * std::min(1.f, dt * rate); }

inline std::string version_key(std::string v)
{
    /* "v1.0", "V.1.0", "1.0" and "1.0.0" are the same version. */
    v.erase(0, version_start(v));
    while (v.size() > 2 && v.compare(v.size() - 2, 2, ".0") == 0 && std::count(v.begin(), v.end(), '.') > 0)
        v.erase(v.size() - 2);
    return v;
}

/* object-fit: cover with a focus point and zoom: the part of the picture a box shows. */
inline void cover_uv(const Texture *t, float bw, float bh, float fx, float fy, float zoom, float uv[4])
{
    const float tw = float(std::max(1, t->width)), th = float(std::max(1, t->height));
    const float scale = std::max(bw / tw, bh / th) * std::max(1.f, zoom);
    const float vw = bw / scale / tw, vh = bh / scale / th; /* visible part, 0..1 */
    const float u0 = (1 - vw) * clamp01(fx), v0 = (1 - vh) * clamp01(fy);
    uv[0] = u0;
    uv[1] = v0;
    uv[2] = u0 + vw;
    uv[3] = v0 + vh;
}

/* Patch notes are Markdown: the words without its marks (bold, code, links, pictures, tags). */
inline std::string md_inline(const std::string &in)
{
    std::string s;
    for (std::size_t i = 0; i < in.size();)
    {
        if (in.compare(i, 2, "**") == 0 || in.compare(i, 2, "__") == 0) { i += 2; continue; }
        if (in[i] == '`') { ++i; continue; }
        if ((in[i] == '[' || (in[i] == '!' && i + 1 < in.size() && in[i + 1] == '[')))
        {
            const std::size_t open = in[i] == '!' ? i + 1 : i;
            const std::size_t cb = in.find(']', open);
            if (cb != std::string::npos && cb + 1 < in.size() && in[cb + 1] == '(')
            {
                const std::size_t cp = in.find(')', cb + 1);
                if (cp != std::string::npos)
                {
                    if (in[i] == '[') s += in.substr(open + 1, cb - open - 1); /* a link: its words; a picture: nothing */
                    i = cp + 1;
                    continue;
                }
            }
        }
        if (in[i] == '<' && i + 1 < in.size() && (std::isalpha(static_cast<unsigned char>(in[i + 1])) || in[i + 1] == '/'))
        {
            const std::size_t gt = in.find('>', i);
            if (gt != std::string::npos && gt - i < 120) { i = gt + 1; continue; }
        }
        s += in[i++];
    }
    while (!s.empty() && (s.back() == ' ' || s.back() == '\t')) s.pop_back();
    std::size_t lead = 0;
    while (lead < s.size() && (s[lead] == ' ' || s[lead] == '\t')) ++lead;
    return s.substr(lead);
}

/* "2026-10-12" -> "October 12, 2026". */
inline std::string long_date(const std::string &d)
{
    static const char *months[12] = {"January", "February", "March", "April", "May", "June", "July",
                                     "August", "September", "October", "November", "December"};
    if (d.size() < 10) return d;
    const int m = std::atoi(d.substr(5, 2).c_str()), day = std::atoi(d.substr(8, 2).c_str());
    if (m < 1 || m > 12 || day < 1) return d;
    return std::string(months[m - 1]) + " " + std::to_string(day) + ", " + d.substr(0, 4);
}

inline std::string base_name(const std::string &p)
{
    const std::size_t s = p.find_last_of('/');
    return s == std::string::npos ? p : p.substr(s + 1);
}

inline std::string size_text(std::uint64_t b)
{
    char t[32];
    if (b >= (1ull << 30))
        std::snprintf(t, sizeof t, "%.1f GB", double(b) / double(1ull << 30));
    else if (b >= (1u << 20))
        std::snprintf(t, sizeof t, "%llu MB", (unsigned long long)((b + (1u << 19)) >> 20));
    else
        std::snprintf(t, sizeof t, "%llu KB", (unsigned long long)((b + 512) >> 10));
    return t;
}

/* (2.0) The parts of the store: 0 games, 1 emulators, 2 apps, 3 tools; and each one's colour. */
inline int category_index(const std::string &c) { return c == "emulators" ? 1 : c == "apps" ? 2 : c == "tools" ? 3 : 0; }
inline std::uint32_t category_tint(int i)
{
    const std::uint32_t t[4] = {kCyan, 0x9B8CFF, kGreen, kAmber};
    return t[std::clamp(i, 0, 3)];
}
} // namespace recomp::sui
