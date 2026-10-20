/* Recomp Store 2.0 - Settings > Link a phone (a QR code, the code, the linked devices),
 * Settings > Sources (custom GitHub repos and lists), the sheet over the store both use, what
 * linked phones send, and the short notes at the top right.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_ui.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"
#include "qr/qrcodegen.h"

#include <algorithm>
#include <cmath>
#include <cstdio>
#include <ctime>

namespace recomp
{
using namespace porpoise::ui;
using namespace sui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

namespace
{
const Color kWarm = C(0xFFB4A8), kGood = C(0x9FF0C6);

/* "2026-10-19T19:02:00Z" as seconds since 1970 (UTC), 0 when it isn't one. Worked out here:
 * no timegm on the console. */
long long iso_seconds(const std::string &s)
{
    int y = 0, mo = 0, d = 0, h = 0, mi = 0, se = 0;
    if (std::sscanf(s.c_str(), "%4d-%2d-%2dT%2d:%2d:%2d", &y, &mo, &d, &h, &mi, &se) < 3 || mo < 1 || mo > 12 || d < 1 || d > 31)
        return 0;
    /* Days from the civil date (Howard Hinnant's days_from_civil). */
    const int yy = y - (mo <= 2 ? 1 : 0);
    const int era = (yy >= 0 ? yy : yy - 399) / 400;
    const unsigned yoe = unsigned(yy - era * 400);
    const unsigned doy = unsigned((153 * (mo + (mo > 2 ? -3 : 9)) + 2) / 5 + d - 1);
    const unsigned doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    const long long days = (long long)era * 146097 + (long long)doe - 719468;
    return days * 86400 + h * 3600 + mi * 60 + se;
}

/* How long ago, in words: "just now", "5 minutes ago", "3 hours ago", "2 days ago". */
std::string ago(const std::string &iso)
{
    const long long t = iso_seconds(iso);
    if (!t) return std::string();
    const long long s = (long long)std::time(nullptr) - t;
    if (s < 90) return "just now";
    if (s < 3600) return std::to_string(s / 60) + " minutes ago";
    if (s < 7200) return "an hour ago";
    if (s < 86400) return std::to_string(s / 3600) + " hours ago";
    if (s < 2 * 86400) return "yesterday";
    return std::to_string(s / 86400) + " days ago";
}

/* A six-letter code as the site shows it: K7Q-4X2. */
std::string spaced_code(const std::string &c)
{
    return c.size() == 6 ? c.substr(0, 3) + "-" + c.substr(3) : c;
}

std::string plural(std::size_t n, const char *one, const char *many)
{
    return std::to_string(n) + " " + (n == 1 ? one : many);
}
} // namespace

/* ---- A QR code ------------------------------------------------------------------------ */
void Store::draw_qr(Gfx &gfx, const std::string &text, float x, float y, float size)
{
    if (text != qr_text_)
    {
        if (qr_) gfx.free_texture(qr_);
        qr_ = nullptr;
        qr_text_ = text;
        static std::uint8_t code[qrcodegen_BUFFER_LEN_MAX], temp[qrcodegen_BUFFER_LEN_MAX];
        if (!text.empty() && qrcodegen_encodeText(text.c_str(), temp, code, qrcodegen_Ecc_MEDIUM, qrcodegen_VERSION_MIN,
                                                  qrcodegen_VERSION_MAX, qrcodegen_Mask_AUTO, true))
        {
            /* A few pixels a module, so it stays sharp drawn bigger; the card's white is its quiet zone. */
            const int n = qrcodegen_getSize(code), scale = 12, side = n * scale;
            std::vector<std::uint8_t> px(std::size_t(side) * std::size_t(side) * 4, 255);
            for (int my = 0; my < n; ++my)
                for (int mx = 0; mx < n; ++mx)
                    if (qrcodegen_getModule(code, mx, my))
                        for (int py = my * scale; py < (my + 1) * scale; ++py)
                            for (int pxx = mx * scale; pxx < (mx + 1) * scale; ++pxx)
                            {
                                std::uint8_t *p = &px[(std::size_t(py) * std::size_t(side) + std::size_t(pxx)) * 4];
                                p[0] = 0x0B;
                                p[1] = 0x11;
                                p[2] = 0x1E;
                            }
            qr_ = gfx.texture_rgba(px.data(), side, side);
        }
    }
    gfx.panel(x, y, size, size, {0, 0, 0, 0}, 1.f, 26, C(kCyan, 0.22f), 0, 24);
    gfx.panel(x, y, size, size, C(0xFFFFFF), 1.f, 26);
    const float pad = size * 0.085f;
    if (qr_) gfx.image(qr_, x + pad, y + pad, size - pad * 2, size - pad * 2);
}

/* ---- Settings > Link a phone ------------------------------------------------------------ */
void Store::draw_link(Gfx &gfx, float cx, float top, float cw, float bottom)
{
    const LinkInfo li = engine().link_info();
    float y = top + 48;
    gfx.text_mid(Font::ExtraBold, 38, cx, y + 24, C(kFrost), Align::Left, "Link a phone");
    y += 48 + 20;
    y += draw_lines(gfx, Font::Regular, 21, cx, y, 31.5f, C(0xD9E1EC),
                    wrap(gfx, Font::Regular, 21, std::min(cw, 1080.f),
                         "Link your phone or PC to this PS5, then send it apps to install, sources to add and finished bug "
                         "reports from recompstore.com, and see what's on it from anywhere.", 3)) + 30;

    /* The code: a QR code to scan, and the same code to type. */
    const float qs = 300;
    const bool have = li.code_state == 2 && !li.url.empty();
    if (have)
        draw_qr(gfx, li.url, cx, y, qs);
    else
    {
        glass(gfx, cx, y, qs, qs, 26, C(kFrost, 0.06f), false);
        if (li.code_state == 3)
        {
            draw_lines(gfx, Font::SemiBold, 19, cx + 28, y + qs / 2 - 40, 28, kWarm,
                       wrap(gfx, Font::SemiBold, 19, qs - 56, li.error.empty() ? "The site couldn't make a code." : li.error, 4));
        }
        else
        {
            /* Waiting for one: dots going round. */
            const float ccx = cx + qs / 2, ccy = y + qs / 2;
            for (int i = 0; i < 8; ++i)
            {
                const float ang = float(i) * 0.785398f;
                const float lit = std::fmod(time_ * 1.4f - float(i) / 8.f + 8.f, 1.f);
                gfx.panel(ccx + std::cos(ang) * 18 - 4, ccy + std::sin(ang) * 18 - 4, 8, 8, C(kCyan, 0.25f + 0.75f * lit), 1.f, 4);
            }
            gfx.text_mid(Font::Regular, 18, ccx, ccy + 54, C(kMuted), Align::Center, "Getting a code\xE2\x80\xA6");
        }
    }
    {
        const float tx = cx + qs + 48;
        float ty = y + 8;
        gfx.text_mid(Font::Bold, 16, tx, ty + 10, C(kCyan), Align::Left, "YOUR CODE", 2);
        ty += 30;
        gfx.text_mid(Font::ExtraBold, 68, tx, ty + 40, have ? C(kFrost) : C(kFrost, 0.25f), Align::Left,
                     have ? spaced_code(li.code) : std::string("\xE2\x80\x94\xE2\x80\x94\xE2\x80\x94-\xE2\x80\x94\xE2\x80\x94\xE2\x80\x94"), 3);
        ty += 92;
        if (have)
        {
            const int m = li.expires_in / 60, s = li.expires_in % 60;
            char t[64];
            std::snprintf(t, sizeof t, "Good for %d:%02d. A new one comes when it runs out.", m, s);
            gfx.text_mid(Font::Regular, 18, tx, ty + 12, C(kMuted), Align::Left, t);
        }
        ty += 40;
        ty += draw_lines(gfx, Font::Regular, 20, tx, ty, 30, C(kSoft),
                         wrap(gfx, Font::Regular, 20, cw - qs - 48,
                              "Scan it with your phone's camera, or go to recompstore.com/link on any browser and type it.", 3)) + 22;
        const std::string label = li.code_state == 1 ? "Getting a code\xE2\x80\xA6" : "New code";
        const float bw = gfx.measure(Font::Bold, 20, label) + 56;
        const bool on = in_section_ && link_row_ == 0;
        gfx.panel(tx, ty, bw, 56, on ? C(kFrost) : C(kFrost, 0.1f), 1.f, 18, on ? Color{0, 0, 0, 0} : kRim, on ? 0.f : 1.25f);
        ring(gfx, tx, ty, bw, 56, 18, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 20, tx + bw / 2, ty + 28, on ? C(kMidnight) : C(kFrost), Align::Center, label);
    }
    y += qs + 40;

    /* The phones and PCs linked to this console. */
    gfx.text_mid(Font::Bold, 16, cx, y + 10, C(kCyan), Align::Left, "LINKED TO THIS PS5", 2);
    y += 20 + 14;
    const float rh = 66;
    std::size_t shown = 0;
    for (std::size_t i = 0; i < li.devices.size(); ++i)
    {
        if (y + rh > bottom - 24)
        {
            gfx.text_mid(Font::Regular, 18, cx + 8, y + 16, C(kDim), Align::Left, "and " + plural(li.devices.size() - shown, "more", "more"));
            break;
        }
        const LinkDevice &d = li.devices[i];
        const bool on = in_section_ && link_row_ == int(i) + 1;
        gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
        if (on) ring(gfx, cx - 12, y + 5, cw + 24, rh - 10, 16, 1.f, true);
        gfx.text_mid(Font::SemiBold, 21, cx + 8, y + rh / 2, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, cw * 0.45f, d.label));
        std::string when;
        if (!d.since.empty()) when = "Linked " + long_date(d.since.substr(0, 10));
        const std::string seen = ago(d.seen);
        if (!seen.empty()) when += (when.empty() ? "Seen " : " \xC2\xB7 seen ") + seen;
        if (on && link_armed_ == d.id)
            gfx.text_mid(Font::SemiBold, 19, cx + cw - 8, y + rh / 2, kWarm, Align::Right, "Press X again to unlink it");
        else
            gfx.text_mid(Font::Regular, 18, cx + cw - 8, y + rh / 2, on ? C(kSoft) : C(kMuted), Align::Right,
                         on ? (when.empty() ? std::string("X: Unlink") : when + "  \xC2\xB7  X: Unlink") : when);
        y += rh;
        ++shown;
    }
    if (li.devices.empty())
    {
        gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::Regular, 20, cx + 8, y + 32, C(kDim), Align::Left,
                     li.known || engine().online() ? "Nothing yet." : "Your PS5 isn't online, so this can't be checked.");
        y += 64;
    }
    if (y + 40 < bottom - 10)
        gfx.text_mid(Font::Regular, 17, cx, bottom - 34, C(kDim), Align::Left,
                     "The site made you a code instead? Enter it in Settings > Redeem a code.");
}

bool Store::update_link(float dt, bool u, bool d)
{
    (void)dt;
    const LinkInfo li = engine().link_info();
    const int rows = 1 + int(li.devices.size());
    const int r = std::clamp(link_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, rows - 1);
    if (r != link_row_)
    {
        link_row_ = r;
        link_armed_.clear();
        play(int(Sfx::Move));
    }
    link_row_ = std::clamp(link_row_, 0, rows - 1);
    if (pressed(pad::BtnCross))
    {
        if (link_row_ == 0)
        {
            if (li.code_state == 1) play(int(Sfx::Error));
            else
            {
                engine().link_new_code();
                play(int(Sfx::Select));
            }
        }
        else
        {
            const LinkDevice &dv = li.devices[std::size_t(link_row_ - 1)];
            if (link_armed_ == dv.id)
            {
                engine().link_unlink(dv.id);
                toast("Unlinked " + dv.label);
                link_armed_.clear();
                link_row_ = std::max(0, link_row_ - 1);
                play(int(Sfx::Back));
            }
            else
            {
                link_armed_ = dv.id;
                play(int(Sfx::Select));
            }
        }
    }
    return true;
}

/* ---- Settings > Sources ------------------------------------------------------------------ */
void Store::draw_sources(Gfx &gfx, float cx, float top, float cw, float bottom)
{
    const auto list = engine().sources();
    float y = top + 48;
    gfx.text_mid(Font::ExtraBold, 38, cx, y + 24, C(kFrost), Align::Left, "Sources");
    y += 48 + 20;
    y += draw_lines(gfx, Font::Regular, 21, cx, y, 31.5f, C(0xD9E1EC),
                    wrap(gfx, Font::Regular, 21, std::min(cw, 1080.f),
                         "Add apps from a GitHub repo, or from a list of repos someone made. Their apps show up in the store "
                         "with the others, and update the same way.", 3)) + 16;
    {
        /* Not checked: said once, plainly. */
        const auto warn = wrap(gfx, Font::Regular, 19, std::min(cw, 1080.f) - 64,
                               "Recomp Store doesn't check apps from sources you add. Add only sources you trust.", 2);
        const float wh = 22 + 28.5f * float(warn.size()) + 22;
        gfx.panel(cx, y, std::min(cw, 1080.f), wh, C(kAmber, 0.10f), 1.f, 18, C(kAmber, 0.4f), 1.25f);
        gfx.panel(cx + 24, y + wh / 2 - 9, 18, 18, C(kAmber), 1.f, 9);
        gfx.text_mid(Font::ExtraBold, 14, cx + 33, y + wh / 2, C(kMidnight), Align::Center, "!");
        draw_lines(gfx, Font::Regular, 19, cx + 56, y + 22, 28.5f, C(kFrost), warn);
        y += wh + 26;
    }
    {
        const std::string label = "Add a source";
        const float bw = gfx.measure(Font::Bold, 22, label) + 64;
        const bool on = in_section_ && src_row_ == 0;
        gfx.panel(cx, y, bw, 64, C(kCyan, on ? 1.f : 0.85f), 1.f, 20);
        ring(gfx, cx, y, bw, 64, 20, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 22, cx + bw / 2, y + 32, C(kMidnight), Align::Center, label);
        gfx.text_mid(Font::Regular, 18, cx + bw + 24, y + 32, C(kMuted), Align::Left,
                     fit(gfx, Font::Regular, 18, cw - bw - 24, "Or send one from your phone: recompstore.com, My PS5."));
        y += 64 + 18;
    }
    if (!src_note_.empty())
    {
        gfx.text_mid(Font::SemiBold, 19, cx + 4, y + 13, src_note_bad_ ? kWarm : kGood, Align::Left, fit(gfx, Font::SemiBold, 19, cw - 8, src_note_));
        y += 26 + 14;
    }
    y += 10;
    gfx.text_mid(Font::Bold, 16, cx, y + 10, C(kCyan), Align::Left, "YOUR SOURCES", 2);
    y += 20 + 14;
    const float rh = 82;
    /* As many as fit, the focused one always among them. */
    const int fit_rows = std::max(1, int((bottom - 20 - y) / rh));
    const int focus = in_section_ ? src_row_ - 1 : -1;
    const int first = std::clamp(focus - fit_rows + 1, 0, std::max(0, int(list.size()) - fit_rows));
    for (int i = first; i < int(list.size()) && i < first + fit_rows; ++i)
    {
        const Source &s = list[std::size_t(i)];
        const bool on = focus == i;
        gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
        if (on) ring(gfx, cx - 12, y + 5, cw + 24, rh - 10, 16, 1.f, true);
        const char *kind = s.kind == "list" ? "LIST" : "REPO";
        const float kw = gfx.measure(Font::Bold, 13, kind, 1.5f) + 20;
        gfx.panel(cx + 8, y + 18, kw, 24, C(s.kind == "list" ? 0x9B8CFF : kCyan, 0.18f), 1.f, 8);
        gfx.text_mid(Font::Bold, 13, cx + 8 + kw / 2, y + 30, C(s.kind == "list" ? 0xC9C0FF : kCyan), Align::Center, kind, 1.5f);
        const std::string name = s.name.empty() ? s.value : s.name;
        gfx.text_mid(Font::SemiBold, 21, cx + 8 + kw + 14, y + 30, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, cw * 0.55f, name));
        std::string line;
        Color lc = C(kMuted);
        if (s.state == 0 || s.state == 1) line = "Reading it\xE2\x80\xA6";
        else if (s.state == 4) { line = "Taken down. " + s.error; lc = kWarm; }
        else if (s.state == 3) { line = s.error; lc = kWarm; }
        else
        {
            line = s.apps ? plural(s.apps, "app", "apps") : std::string("No PS5 apps in it");
            if (!s.problems.empty()) line += " \xC2\xB7 " + plural(s.problems.size(), "repo couldn't be used", "repos couldn't be used");
        }
        if (s.name != s.value && !s.name.empty()) line = s.value + "  \xC2\xB7  " + line;
        gfx.text_mid(Font::Regular, 18, cx + 8, y + 60, lc, Align::Left, fit(gfx, Font::Regular, 18, cw - 220, line));
        gfx.text_mid(Font::Regular, 18, cx + cw - 8, y + rh / 2, on ? C(kSoft) : C(kDim), Align::Right, on ? "X: Choices" : "");
        y += rh;
    }
    if (list.empty())
    {
        gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::Regular, 20, cx + 8, y + 32, C(kDim), Align::Left, "None yet.");
    }
}

bool Store::update_sources(float dt, bool u, bool d)
{
    (void)dt;
    const auto list = engine().sources();
    const int rows = 1 + int(list.size());
    const int r = std::clamp(src_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, rows - 1);
    if (r != src_row_) { src_row_ = r; play(int(Sfx::Move)); }
    src_row_ = std::clamp(src_row_, 0, rows - 1);
    if (pressed(pad::BtnCross))
    {
        if (src_row_ == 0)
            open_kb(1);
        else
        {
            sheet_ = true;
            sheet_t_ = 0;
            sheet_kind_ = 2;
            sheet_value_ = list[std::size_t(src_row_ - 1)].value;
            sheet_sel_ = 2; /* Close first */
            play(int(Sfx::Open));
        }
    }
    return true;
}

void Store::report_source(const std::string &value, bool from_page)
{
    src_report_value_ = value;
    src_report_page_ = from_page;
    src_why_.clear();
    open_kb(2);
}

std::string Store::source_line(const App &a) const
{
    const std::string from = a.source_name.empty() ? a.source_value : a.source_name;
    return "From " + (from.empty() ? std::string("a source you added") : from) +
           ". Recomp Store doesn't check apps from sources you add: install only what you trust.";
}

/* ---- The keyboard's modes ------------------------------------------------------------------ */
void Store::open_kb(int mode)
{
    kb_mode_ = mode;
    kb_ = true;
    kb_t_ = 0;
    kb_row_ = 1;
    kb_col_ = 0;
    kb_caps_ = mode != 1 && kb_text().empty();
    play(int(Sfx::Open));
}

void Store::kb_finished()
{
    kb_ = false;
    if (kb_mode_ == 0)
    {
        logs_sel_ = 2; /* on to Send */
        play(int(Sfx::Back));
        return;
    }
    if (kb_mode_ == 1)
    {
        if (src_text_.empty()) { play(int(Sfx::Back)); return; }
        std::string why;
        if (engine().add_source(src_text_, why))
        {
            src_note_ = "Added. Reading it now\xE2\x80\xA6";
            src_note_bad_ = false;
            src_text_.clear();
            src_row_ = int(engine().sources().size()); /* the new one, last */
            play(int(Sfx::Complete));
        }
        else
        {
            src_note_ = why;
            src_note_bad_ = true;
            play(int(Sfx::Error));
        }
        return;
    }
    /* 2: a report on a source. */
    std::string why = src_why_;
    while (!why.empty() && why.back() == ' ') why.pop_back();
    if (why.empty()) { play(int(Sfx::Back)); return; }
    std::string *note = src_report_page_ ? &notice_ : &src_note_;
    bool *bad = src_report_page_ ? &notice_bad_ : &src_note_bad_;
    if (why.size() < 5)
    {
        *note = "Say a few words about what's wrong with it, then send it again.";
        *bad = true;
        play(int(Sfx::Error));
        return;
    }
    if (engine().report_source(src_report_value_, why))
    {
        *note = "Sending your report\xE2\x80\xA6";
        *bad = false;
        src_why_.clear();
        play(int(Sfx::Select));
    }
    else
        play(int(Sfx::Error));
}

/* ---- The sheet over the store --------------------------------------------------------------- */
bool Store::update_sheet(float dt)
{
    sheet_t_ = std::min(1.f, sheet_t_ + dt * 5.f);
    const int buttons = sheet_kind_ == 2 ? 3 : 2;
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const int s = std::clamp(sheet_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, buttons - 1);
    if (s != sheet_sel_) { sheet_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle))
    {
        sheet_ = false;
        play(int(Sfx::Back));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    sheet_ = false;
    if (sheet_kind_ == 1)
    {
        if (sheet_sel_ == 1) { play(int(Sfx::Back)); return true; }
        std::string why;
        if (engine().add_source(sheet_value_, why))
        {
            toast("Added the source your phone sent");
            src_note_ = "Added " + sheet_value_ + ". Reading it now\xE2\x80\xA6";
            src_note_bad_ = false;
            play(int(Sfx::Complete));
        }
        else
        {
            toast(why);
            play(int(Sfx::Error));
        }
    }
    else if (sheet_sel_ == 0)
        report_source(sheet_value_, false);
    else if (sheet_sel_ == 1)
    {
        engine().remove_source(sheet_value_);
        src_note_ = "Removed " + sheet_value_ + ". Apps you installed from it stay installed.";
        src_note_bad_ = false;
        src_row_ = std::max(0, src_row_ - 1);
        play(int(Sfx::Back));
    }
    else
        play(int(Sfx::Back));
    return true;
}

void Store::draw_sheet(Gfx &gfx)
{
    if (!sheet_) return;
    const float k = ease_out(sheet_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 820, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    Source src;
    for (const Source &x : engine().sources())
        if (x.value == sheet_value_) src = x;
    std::string title, value = sheet_value_, body;
    std::vector<std::string> extra;
    if (sheet_kind_ == 1)
    {
        title = "Add this source?";
        body = "Your phone sent it. Recomp Store doesn't check apps from sources you add, so add it only if you trust it.";
    }
    else
    {
        title = src.name.empty() ? sheet_value_ : src.name;
        body = src.state == 2 ? (src.apps ? plural(src.apps, "app", "apps") + " from it are in the store." : std::string("It has no PS5 apps right now."))
               : src.state == 3 || src.state == 4 ? src.error
                                                   : std::string("The store is reading it.");
        for (std::size_t i = 0; i < src.problems.size() && i < 3; ++i) extra.push_back(src.problems[i]);
        if (src.problems.size() > 3) extra.push_back("and " + std::to_string(src.problems.size() - 3) + " more");
    }
    const auto lines = wrap(gfx, Font::Regular, 20, iw, body, 4);
    const float bh = pad + 40 + 16 + 30 + 18 + 30 * float(lines.size()) + (extra.empty() ? 0.f : 14 + 26 * float(extra.size())) + 34 + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 20, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 32, iw, title));
    y += 40 + 16;
    gfx.text_mid(Font::SemiBold, 20, bx + pad, y + 15, C(kCyan), Align::Left, fit(gfx, Font::SemiBold, 20, iw, value));
    y += 30 + 18;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines);
    if (!extra.empty())
    {
        y += 14;
        for (const std::string &e : extra)
        {
            gfx.text_mid(Font::Regular, 17, bx + pad, y + 13, C(kDim), Align::Left, fit(gfx, Font::Regular, 17, iw, e));
            y += 26;
        }
    }
    y += 34;
    std::vector<std::string> labels = sheet_kind_ == 1 ? std::vector<std::string>{"Add it", "Not now"}
                                                       : std::vector<std::string>{"Report it", "Remove", "Close"};
    const float gap = 16, w = (iw - gap * float(labels.size() - 1)) / float(labels.size());
    for (std::size_t i = 0; i < labels.size(); ++i)
    {
        const float x = bx + pad + float(i) * (w + gap);
        const bool on = sheet_sel_ == int(i);
        const bool main = sheet_kind_ == 1 && i == 0, danger = sheet_kind_ == 2 && i == 1;
        Color bg = main ? C(kCyan, on ? 1.f : 0.3f) : danger ? C(0xE5484D, on ? 0.9f : 0.16f) : C(kFrost, on ? 1.f : 0.12f);
        gfx.panel(x, y, w, 64, bg, 1.f, 20);
        ring(gfx, x, y, w, 64, 20, on ? 1.f : 0.f, true);
        const Color fg = danger ? (on ? C(0xFFFFFF) : C(0xFF8A80)) : on ? C(kMidnight) : C(kFrost);
        gfx.text_mid(Font::Bold, 22, x + w / 2, y + 32, fg, Align::Center, labels[i]);
    }
    gfx.set_layer();
}

/* ---- What linked phones send ---------------------------------------------------------------- */
void Store::take_link_actions()
{
    LinkAction a;
    while (engine().take_link_action(a) && link_waiting_.size() < 20) link_waiting_.push_back(a);
    /* How a report on a source went. */
    {
        std::string msg;
        const int st = engine().source_report_state(msg);
        if (st == 2 || st == 3)
        {
            engine().source_report_ack();
            (src_report_page_ ? notice_ : src_note_) = msg;
            (src_report_page_ ? notice_bad_ : src_note_bad_) = st == 3;
            play(int(st == 2 ? Sfx::Complete : Sfx::Error));
        }
    }
    if (link_waiting_.empty() || modal_open() || !booted_) return;
    a = link_waiting_.front();
    link_waiting_.erase(link_waiting_.begin());
    if (a.kind == "source")
    {
        sheet_ = true;
        sheet_t_ = 0;
        sheet_kind_ = 1;
        sheet_value_ = !a.repo.empty() ? a.repo : a.list;
        sheet_sel_ = 0;
        play(int(Sfx::Open));
    }
    else if (a.kind == "report")
    {
        toast("Your report " + spaced_code(a.report) + " is finished. Thanks.");
        play(int(Sfx::Complete));
    }
    else if (a.kind == "install")
    {
        /* By its ID, or a custom app's title ID. */
        const App *app = find(a.app);
        if (!app)
            for (const App &x : catalog_.apps)
                if (x.custom && (x.title_id == a.app || own_id(x.id) == a.app)) app = &x;
        if (!app || app->soon)
        {
            toast("Your phone sent an app this store doesn't have");
            play(int(Sfx::Error));
            return;
        }
        bool update = false;
        state_of(*app, &update);
        const std::string v = engine().installed(app->id);
        if (!v.empty() && v != "?" && !update)
        {
            /* (2.1) Kept at an older version the player picked: updated only from its page. */
            const std::string kept = kept_at(*app);
            toast(!kept.empty() && newer_out(*app) ? app->name + " is kept at " + kept.substr(version_start(kept)) + ". Update it from its page."
                                                   : app->name + " is already installed");
            return;
        }
        if (std::find(queue_.begin(), queue_.end(), app->id) != queue_.end() || queue_now_ == app->id) return;
        queue_.push_back(app->id);
        ++queue_total_;
        queue_fresh_ = true;
        toast((update ? "Updating " : "Installing ") + app->name + ", sent from your phone");
        play(int(Sfx::Install));
        tick_queue();
    }
}

/* ---- A note at the top right --------------------------------------------------------------- */
void Store::draw_toast(Gfx &gfx)
{
    if (toast_.empty() || toast_t_ > 3.2f) return;
    const float in = clamp01(toast_t_ / 0.2f), out = clamp01((3.2f - toast_t_) / 0.4f), a = std::min(in, out);
    const std::string t = fit(gfx, Font::SemiBold, 18, 560, toast_);
    const float w = gfx.measure(Font::SemiBold, 18, t) + 26 + 16 + 24, h = 44, x = 1920 - 96 - w;
    const float cy = (refresh_shown_ ? 76 + 56 : 76) - (1 - in) * 8;
    glass(gfx, x, cy - h / 2, w, h, h / 2, Color{kGlass.r, kGlass.g, kGlass.b, kGlass.a * a}, a > 0.5f);
    gfx.panel(x + 20, cy - 5, 10, 10, C(kCyan, a), 1.f, 5);
    gfx.text_mid(Font::SemiBold, 18, x + 20 + 10 + 14, cy, C(kFrost, a), Align::Left, t);
}
} // namespace recomp
