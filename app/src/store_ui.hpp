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

/* (2.2.3) &amp; &lt; &gt; &quot; &#39; &nbsp; &#NNN; &#xHH; in a release's words, as the characters. */
inline std::string html_entities(const std::string &in)
{
    std::string o;
    for (std::size_t i = 0; i < in.size();)
    {
        if (in[i] == '&')
        {
            const std::size_t semi = in.find(';', i);
            if (semi != std::string::npos && semi - i <= 10)
            {
                const std::string e = in.substr(i + 1, semi - i - 1);
                std::string r;
                if (e == "amp") r = "&";
                else if (e == "lt") r = "<";
                else if (e == "gt") r = ">";
                else if (e == "quot") r = "\"";
                else if (e == "apos" || e == "#39") r = "'";
                else if (e == "nbsp") r = " ";
                else if (e.size() > 1 && e[0] == '#')
                {
                    const bool hex = e[1] == 'x' || e[1] == 'X';
                    char *end = nullptr;
                    const unsigned long cp = std::strtoul(e.c_str() + (hex ? 2 : 1), &end, hex ? 16 : 10);
                    if (end && *end == '\0' && cp > 0 && cp < 0x110000 && !(cp >= 0xD800 && cp < 0xE000))
                    {
                        if (cp < 0x80) r += char(cp < 0x20 ? ' ' : cp);
                        else if (cp < 0x800) { r += char(0xC0 | (cp >> 6)); r += char(0x80 | (cp & 0x3F)); }
                        else if (cp < 0x10000) { r += char(0xE0 | (cp >> 12)); r += char(0x80 | ((cp >> 6) & 0x3F)); r += char(0x80 | (cp & 0x3F)); }
                        else { r += char(0xF0 | (cp >> 18)); r += char(0x80 | ((cp >> 12) & 0x3F)); r += char(0x80 | ((cp >> 6) & 0x3F)); r += char(0x80 | (cp & 0x3F)); }
                    }
                }
                if (!r.empty())
                {
                    o += r;
                    i = semi + 1;
                    continue;
                }
            }
        }
        o += in[i++];
    }
    return o;
}

/* (2.2.3) A release's notes with its HTML made plain: GitHub's release bodies mix Markdown and
 * HTML, and a tag can run over several lines. Pictures become "[Image: what it shows]" (or go,
 * with no alt), links keep their words, <br> a new line, <p> <div> a paragraph, <li> a bullet,
 * headings a "## " line, comments go, any other tag goes and its words stay. */
inline std::string notes_text(const std::string &in)
{
    auto lower = [](std::string v) {
        for (char &c : v) c = char(std::tolower(static_cast<unsigned char>(c)));
        return v;
    };
    auto attr = [&](const std::string &tag, const char *name) {
        const std::string lt = lower(tag), key = std::string(name) + "=";
        for (std::size_t p = lt.find(key); p != std::string::npos; p = lt.find(key, p + 1))
        {
            if (p > 0 && !std::isspace(static_cast<unsigned char>(lt[p - 1]))) continue;
            std::size_t v = p + key.size();
            if (v >= tag.size()) return std::string();
            const char q = tag[v];
            if (q == '"' || q == '\'')
            {
                const std::size_t e = tag.find(q, v + 1);
                return html_entities(tag.substr(v + 1, e == std::string::npos ? std::string::npos : e - v - 1));
            }
            std::size_t e = v;
            while (e < tag.size() && !std::isspace(static_cast<unsigned char>(tag[e])) && tag[e] != '>' && tag[e] != '/') ++e;
            return html_entities(tag.substr(v, e - v));
        }
        return std::string();
    };
    std::string o;
    for (std::size_t i = 0; i < in.size();)
    {
        if (in.compare(i, 4, "<!--") == 0)
        {
            const std::size_t e = in.find("-->", i + 4);
            i = e == std::string::npos ? in.size() : e + 3;
            continue;
        }
        if (in[i] == '<' && i + 1 < in.size() && (std::isalpha(static_cast<unsigned char>(in[i + 1])) || in[i + 1] == '/'))
        {
            const std::size_t gt = in.find('>', i);
            if (gt != std::string::npos && gt - i < 4000)
            {
                const std::string tag = in.substr(i + 1, gt - i - 1);
                const bool closing = !tag.empty() && tag[0] == '/';
                std::size_t n = closing ? 1 : 0;
                std::string name;
                while (n < tag.size() && std::isalnum(static_cast<unsigned char>(tag[n]))) name += char(std::tolower(static_cast<unsigned char>(tag[n++])));
                if (name == "img")
                {
                    const std::string alt = attr(tag, "alt");
                    o += alt.empty() ? std::string("[Image]") : "[Image: " + alt + "]";
                }
                else if (name == "br" || name == "hr") o += "\n";
                else if (name == "p" || name == "div" || name == "details" || name == "table" || name == "tr" || name == "ul" ||
                         name == "ol" || name == "blockquote" || name == "section" || name == "pre") o += "\n\n";
                else if (name == "li") o += closing ? "\n" : "\n- ";
                else if (name.size() == 2 && name[0] == 'h' && name[1] >= '1' && name[1] <= '6') o += closing ? "\n\n" : "\n\n## ";
                else if (name == "summary") o += closing ? "\n" : "\n";
                else if (name == "td" || name == "th") o += " ";
                /* a, b, strong, em, span, code, sup, picture, source, anything else: the tag goes, its words stay */
                i = gt + 1;
                continue;
            }
        }
        o += in[i++];
    }
    o = html_entities(o);
    /* No more than one blank line in a row. */
    std::string t;
    int nl = 0;
    for (const char c : o)
    {
        if (c == '\r') continue;
        if (c == '\n') { if (++nl > 2) continue; }
        else nl = 0;
        t += c;
    }
    /* Nor any at its start or end. */
    std::size_t a = 0, b = t.size();
    while (a < b && (t[a] == '\n' || t[a] == ' ' || t[a] == '\t')) ++a;
    while (b > a && (t[b - 1] == '\n' || t[b - 1] == ' ' || t[b - 1] == '\t')) --b;
    return t.substr(a, b - a);
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
                    const std::string words = in.substr(open + 1, cb - open - 1);
                    if (in[i] == '[') s += words; /* a link: its words */
                    else if (!words.empty()) s += "[Image: " + words + "]"; /* (2.2.3) a picture: what it shows */
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
    /* (2.2.3) A long bare address: its site and "\xE2\x80\xA6" (the whole of it only ran out of the box). */
    {
        std::string t;
        for (std::size_t i = 0; i < s.size();)
        {
            const bool word_start = i == 0 || s[i - 1] == ' ' || s[i - 1] == '(';
            if (word_start && (s.compare(i, 7, "http://") == 0 || s.compare(i, 8, "https://") == 0))
            {
                std::size_t e = i;
                while (e < s.size() && s[e] != ' ' && s[e] != ')') ++e;
                const std::string url = s.substr(i, e - i);
                if (url.size() > 48)
                {
                    const std::size_t hs = url.find("://") + 3, he = url.find('/', hs);
                    t += url.substr(hs, he == std::string::npos ? std::string::npos : he - hs) + "/\xE2\x80\xA6";
                }
                else
                    t += url;
                i = e;
                continue;
            }
            t += s[i++];
        }
        s.swap(t);
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
    /* (2.1) Only a date written that way: a beta build's ("Oct 19, 2026") is shown as it is. */
    for (int i : {0, 1, 2, 3, 5, 6, 8, 9})
        if (d[std::size_t(i)] < '0' || d[std::size_t(i)] > '9') return d;
    if (d[4] != '-' || d[7] != '-') return d;
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
/* (2.2.2) A check mark, size across, centred on (cx, cy): two rounded bars (the fonts have no ✓). */
inline void draw_check(porpoise::ui::Gfx &gfx, float cx, float cy, float size, Color c)
{
    const float t = size * 0.15f;
    const float pts[3][2] = {{cx - 0.34f * size, cy + 0.02f * size}, {cx - 0.10f * size, cy + 0.26f * size}, {cx + 0.36f * size, cy - 0.24f * size}};
    for (int b = 0; b < 2; ++b)
    {
        const float px = pts[b][0], py = pts[b][1], qx = pts[b + 1][0], qy = pts[b + 1][1];
        const float len = std::sqrt((qx - px) * (qx - px) + (qy - py) * (qy - py));
        const float dx = (qx - px) / len, dy = (qy - py) / len, nx = -dy * t / 2, ny = dx * t / 2, ex = dx * t / 2, ey = dy * t / 2;
        const porpoise::ui::Corner k[4] = {{px - nx - ex, py - ny - ey, 1}, {qx - nx + ex, qy - ny + ey, 1},
                                           {qx + nx + ex, qy + ny + ey, 1}, {px + nx - ex, py + ny - ey, 1}};
        gfx.panel3d(k, len + t, t, 0, c, 1.f, t / 2, {}, 0, 0, 0);
    }
}
} // namespace recomp::sui
