/* Recomp Store 2.2 - Library > On this PS5: every app in the console's homebrew folders and the
 * drives', whether the store put it there or not, with ShadowMountPlus's two rows at the top
 * (Refresh home screen, Reload ShadowMountPlus), and the App manager over it: Open, Move to
 * another homebrew folder, a new title ID, Delete.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_ui.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"

#include <algorithm>
#include <cmath>
#include <cstdio>

namespace recomp
{
using namespace porpoise::ui;
using namespace sui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

namespace
{
const float kContent = 316, kBottom = 1000;
const float kRowH = 120, kRowPitch = 136;
const int kTopRows = 2; /* Refresh home screen, Reload ShadowMountPlus */

std::string lower_name(std::string s)
{
    for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
    return s;
}

std::string count_words(std::size_t n, const char *one, const char *many)
{
    return std::to_string(n) + " " + (n == 1 ? one : many);
}

void keep_shown(float &scroll, float &to, float y0, float y1, float view_h, float total)
{
    if (y1 - to > view_h) to = y1 - view_h;
    if (y0 < to) to = y0;
    to = std::clamp(to, 0.f, std::max(0.f, total - view_h));
    scroll = approach(scroll, to, 1.f / 60.f, 12.f);
    if (std::fabs(scroll - to) < 0.5f) scroll = to;
}
} // namespace

/* ---- The list ------------------------------------------------------------------------------ */

void Store::sync_console()
{
    if (!console_asked_)
    {
        console_asked_ = true; /* the first time it's shown: looked for now */
        engine().look_console();
    }
    const unsigned rev = engine().console_revision();
    if (rev == console_rev_) return;
    console_rev_ = rev;
    console_ = engine().console_apps(&console_looking_);
}

std::vector<const ConsoleApp *> Store::console_list() const
{
    std::vector<const ConsoleApp *> out;
    for (const ConsoleApp &c : console_) out.push_back(&c);
    if (con_sort_ == 1)
        std::stable_sort(out.begin(), out.end(), [](const ConsoleApp *a, const ConsoleApp *b) { return a->size > b->size; });
    else
        std::stable_sort(out.begin(), out.end(), [](const ConsoleApp *a, const ConsoleApp *b) { return lower_name(a->name) < lower_name(b->name); });
    return out;
}

Texture *Store::console_icon(const ConsoleApp &c)
{
    if (c.icon.empty() || !gfx_) return nullptr;
    auto it = con_icons_.find(c.icon);
    if (it == con_icons_.end()) it = con_icons_.emplace(c.icon, std::make_pair<Texture *, bool>(nullptr, true)).first;
    if (!it->second.first && it->second.second)
        it->second.first = gfx_->texture_file_async(c.icon, &it->second.second, int(std::ceil(272 * px_scale_)));
    return it->second.first;
}

/* The app the store knows this folder as (its page, its saves), when it's in the catalog. */
static const App *catalog_app(const std::vector<App> &apps, const ConsoleApp &c)
{
    if (c.store_id.empty()) return nullptr;
    for (const App &a : apps)
        if (a.id == c.store_id) return &a;
    return nullptr;
}

void Store::start_refresh_home(bool from_lib)
{
    std::string &note = from_lib ? lib_note_ : settings_note_;
    bool &bad = from_lib ? lib_note_bad_ : settings_note_bad_;
    if (engine().job().phase == Phase::Working)
    {
        note = "Wait for the current job to finish first.";
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    std::string why;
    const int r = refresh_smp(why); /* running: it has a look queued for when the store closes; else started */
    if (r == 1)
    {
        note = "Started ShadowMountPlus. Give it a few seconds, then refresh again.";
        bad = false;
        play(int(Sfx::Complete));
        engine().look_console();
        return;
    }
    if (r == 0)
    {
        note = why;
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    if (!refresh_home(why))
    {
        note = "Couldn't refresh your home screen. " + why;
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    /* The launcher does it once the store has closed, then opens the store again. */
    opening_ = true;
    opening_lib_ = from_lib;
    opening_t_ = 0;
    opening_poll_ = 0;
    note = "Closing the store for a moment\xE2\x80\xA6";
    bad = false;
    play(int(Sfx::Select));
}

bool Store::update_console(float dt, int moves, bool u, bool d)
{
    (void)dt;
    (void)moves;
    sync_console();
    const auto list = console_list();
    const int n = kTopRows + int(list.size());
    col_ = std::clamp(col_, 0, n - 1);
    if (u && col_ == 0) { lib_bar_ = true; play(int(Sfx::Move)); return true; }
    const int to = std::clamp(col_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n - 1);
    if (to != col_) { col_ = to; play(int(Sfx::Move)); }
    if (pressed(pad::BtnSquare))
    {
        con_sort_ = 1 - con_sort_;
        col_ = std::min(col_, kTopRows - 1 + (list.empty() ? 0 : 1));
        lscroll_to_ = 0;
        toast(con_sort_ ? "Biggest first" : "By name");
        play(int(Sfx::Select));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    if (col_ == 0)
    {
        start_refresh_home(true);
        return true;
    }
    if (col_ == 1)
    {
        std::string why;
        if (smp_path().empty())
        {
            lib_note_ = "Choose your ShadowMountPlus file in Settings > General first.";
            lib_note_bad_ = true;
            play(int(Sfx::Error));
        }
        else if (engine().reload_smp(why))
        {
            lib_note_ = "Sent " + base_name(smp_path()) + " to your payload loader. It's back in a few seconds.";
            lib_note_bad_ = false;
            play(int(Sfx::Complete));
        }
        else
        {
            lib_note_ = why;
            lib_note_bad_ = true;
            play(int(Sfx::Error));
        }
        return true;
    }
    open_manage(*list[std::size_t(col_ - kTopRows)]);
    return true;
}

void Store::draw_console_row(Gfx &gfx, const ConsoleApp &c, float x0, float y0, float w0, float focus)
{
    const float k = ease_out(focus);
    const float s = 1 + 0.012f * k;
    const float w = w0 * s, h = kRowH * s, x = x0 + (w0 - w) / 2, y = y0 + (kRowH - h) / 2;
    glass(gfx, x, y, w, h, 26, {1, 1, 1, 0.07f + 0.05f * k}, true);
    ring(gfx, x, y, w, h, 26, k);
    const float is = 84 * s, ix = x + 20, iy = y + (h - is) / 2;
    if (Texture *t = console_icon(c))
        gfx.image(t, ix, iy, is, is, {}, 20);
    else
    {
        gfx.panel(ix, iy, is, is, C(0x15223A), 1.f, 20, kRim, 1.25f);
        if (c.app)
        {
            std::string first = c.name.substr(0, 1);
            for (char &ch : first)
                if (ch >= 'a' && ch <= 'z') ch = char(ch - 'a' + 'A'); /* (not toupper: one more import) */
            gfx.text_mid(Font::ExtraBold, 34, ix + is / 2, iy + is / 2, C(kCyan, 0.9f), Align::Center, first);
        }
        else if (folder_icon_)
            gfx.image(folder_icon_, ix + is * 0.25f, iy + is * 0.25f, is * 0.5f, is * 0.5f, C(kMuted));
    }
    /* What's going on with it now: the App manager's job, or a delete from it. */
    const JobState job = engine().job();
    const bool busy = job.phase == Phase::Working &&
                      (job.app_id == c.folder || (!c.store_id.empty() && job.kind == "delete" && job.app_id == c.store_id));
    const std::string button = c.store ? "" : busy ? std::string("Working\xE2\x80\xA6") : std::string("Manage");
    const float bw = button.empty() ? 0 : gfx.measure(Font::Bold, 19, button) + 52;
    const float tx = ix + is + 24, room = w - (tx - x) - bw - 56;
    const std::string name = fit(gfx, Font::ExtraBold, 24, room * 0.62f, c.name);
    gfx.text_mid(Font::ExtraBold, 24, tx, y + h / 2 - 16, C(kFrost), Align::Left, name);
    float cx = tx + gfx.measure(Font::ExtraBold, 24, name) + 12;
    if (!c.version.empty())
    {
        gfx.text_mid(Font::SemiBold, 18, cx, y + h / 2 - 15, C(kCyan), Align::Left, c.version);
        cx += gfx.measure(Font::SemiBold, 18, c.version) + 12;
    }
    /* Whose it is: the store's, or put there some other way (nothing then). */
    if (c.store || !c.store_id.empty())
    {
        const char *tag = c.store ? "THIS STORE" : "RECOMP STORE";
        const float tw = gfx.measure(Font::Bold, 13, tag, 1) + 20;
        gfx.panel(cx, y + h / 2 - 28, tw, 24, C(kCyan, 0.16f), 1.f, 12, C(kCyan, 0.5f), 1.f);
        gfx.text_mid(Font::Bold, 13, cx + tw / 2, y + h / 2 - 16, C(kCyan), Align::Center, tag, 1);
    }
    std::string line;
    if (busy)
        line = job.total ? job.step + "  " + std::to_string(int(100.0 * double(job.done) / double(job.total))) + "%" : job.step + "\xE2\x80\xA6";
    else
    {
        line = c.app ? (c.title_id.empty() ? std::string("No title ID") : c.title_id) : std::string("Not an app: no eboot.bin");
        line += "  \xC2\xB7  " + c.place;
        line += "  \xC2\xB7  " + (c.size >= 0 ? size_text(std::uint64_t(c.size)) : std::string("Size\xE2\x80\xA6"));
        const std::string leaf = base_name(c.folder);
        if (leaf != c.title_id) line += "  \xC2\xB7  " + leaf;
    }
    gfx.text_mid(Font::Regular, 18, tx, y + h / 2 + 18, busy ? C(kCyan) : C(kSoft), Align::Left, fit(gfx, Font::Regular, 18, room, line));
    if (bw > 0)
    {
        gfx.panel(x + w - 28 - bw, y + h / 2 - 26, bw, 52, busy ? C(kFrost, 0.08f) : C(kFrost, 0.14f), 1.f, 17);
        gfx.text_mid(Font::Bold, 19, x + w - 28 - bw / 2, y + h / 2, busy ? C(kMuted) : C(kFrost), Align::Center, button);
    }
}

void Store::draw_console(Gfx &gfx, float slide)
{
    sync_console();
    const auto list = console_list();
    float top = kContent;
    if (!lib_note_.empty())
    {
        /* What the last thing done here did, in full (the bar only has room for a line of it). */
        const auto lines = wrap(gfx, Font::SemiBold, 19, 1728 - 60, lib_note_, 2);
        const float nh = 26 + 28 * float(lines.size());
        glass(gfx, 96, top + slide, 1728, nh, 20, lib_note_bad_ ? C(0xE5484D, 0.12f) : C(kGreen, 0.10f), false);
        draw_lines(gfx, Font::SemiBold, 19, 126, top + slide + 13, 28, lib_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), lines);
        top += nh + 14;
    }
    const int n = kTopRows + int(list.size());
    const float view_h = kBottom - top;
    float total_h = float(n) * kRowPitch;
    keep_shown(lscroll_, lscroll_to_, float(col_) * kRowPitch, float(col_) * kRowPitch + kRowH + 8, view_h, total_h);
    /* Room above the first row for its focus ring, unless the list is scrolled (then a sliver of
     * the row above would show under the note). */
    const float above = lscroll_ > 0.5f ? 0.f : 12.f;
    gfx.clip(0, top - above, gfx.design_w(), kBottom - top + above);
    std::string ver;
    const int smp = engine().smp_state(ver);
    const int waiting = engine().home_waiting();
    const JobState job = engine().job();
    for (int i = 0; i < n; ++i)
    {
        float &f = focus_anim_[std::min(i, 511)];
        f = approach(f, i == col_ && !lib_bar_ ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
        const float y = top + 8 + float(i) * kRowPitch - lscroll_ + slide;
        if (y > kBottom || y + kRowH < top - 20) continue;
        if (i >= kTopRows)
        {
            draw_console_row(gfx, *list[std::size_t(i - kTopRows)], 96, y, 1728, f);
            continue;
        }
        /* ShadowMountPlus's rows. */
        const float k = ease_out(f), s = 1 + 0.012f * k;
        const float w = 1728 * s, h = kRowH * s, x = 96 + (1728 - w) / 2, yy = y + (kRowH - h) / 2;
        glass(gfx, x, yy, w, h, 26, i == 0 ? Color{0.21f, 0.84f, 0.96f, 0.10f + 0.05f * k} : Color{1, 1, 1, 0.07f + 0.05f * k}, true);
        ring(gfx, x, yy, w, h, 26, k);
        const float is = 84 * s, ix = x + 20, iy = yy + (h - is) / 2;
        gfx.panel(ix, iy, is, is, i == 0 ? C(kCyan, 0.16f) : C(kFrost, 0.08f), 1.f, 20, i == 0 ? C(kCyan, 0.5f) : kRim, 1.25f);
        if (Texture *ic = cat_icon_[i == 0 ? 2 : 3]) /* the home screen's apps; a tool */
            gfx.image(ic, ix + is * 0.22f, iy + is * 0.22f, is * 0.56f, is * 0.56f, i == 0 ? C(kCyan) : C(kFrost, 0.85f));
        std::string title, line, button;
        if (i == 0)
        {
            title = "Refresh home screen";
            if (smp == 1)
                line = "The store closes for a moment while ShadowMountPlus " + (ver.empty() ? std::string() : ver + " ") +
                       "adds new apps" + (waiting ? " and makes " + count_words(std::size_t(waiting), "change", "changes") + " waiting for it" : std::string()) +
                       ", then opens again.";
            else if (smp == 0)
                line = "ShadowMountPlus isn't running. Refresh starts it, or reload it below.";
            else
                line = "Looking for ShadowMountPlus\xE2\x80\xA6";
            button = opening_ ? "Closing\xE2\x80\xA6" : "Refresh";
        }
        else
        {
            title = "Reload ShadowMountPlus";
            const std::string file = smp_path();
            line = file.empty() ? std::string("Choose your ShadowMountPlus file in Settings > General first.")
                                : "Sends " + base_name(file) + " to your payload loader again. A running one restarts with it.";
            button = "Reload";
        }
        const float tx = ix + is + 24;
        const float bw = gfx.measure(Font::Bold, 19, button) + 52;
        gfx.text_mid(Font::ExtraBold, 24, tx, yy + h / 2 - 16, C(kFrost), Align::Left, title);
        if (i == 0 && smp >= 0)
        {
            /* Running or not, as a dot after the title. */
            const float dx = tx + gfx.measure(Font::ExtraBold, 24, title) + 16;
            gfx.panel(dx, yy + h / 2 - 22, 12, 12, smp == 1 ? C(kGreen) : C(0xFF8A80), 1.f, 6);
            gfx.text_mid(Font::SemiBold, 16, dx + 20, yy + h / 2 - 16, smp == 1 ? C(0x9FF0C6) : C(0xFFB4A8), Align::Left,
                         smp == 1 ? "Running" : "Not running");
        }
        gfx.text_mid(Font::Regular, 18, tx, yy + h / 2 + 18, C(kSoft), Align::Left, fit(gfx, Font::Regular, 18, w - (tx - x) - bw - 70, line));
        const bool dim = (i == 0 && (opening_ || job.phase == Phase::Working)) || (i == 1 && smp_path().empty());
        gfx.panel(x + w - 28 - bw, yy + h / 2 - 26, bw, 52, dim ? C(kFrost, 0.08f) : i == 0 ? C(kCyan) : C(kFrost, 0.14f), 1.f, 17);
        gfx.text_mid(Font::Bold, 19, x + w - 28 - bw / 2, yy + h / 2, dim ? C(kMuted) : i == 0 ? C(kMidnight) : C(kFrost), Align::Center, button);
    }
    if (list.empty())
    {
        const float y = top + 8 + float(kTopRows) * kRowPitch - lscroll_ + slide;
        gfx.text_mid(Font::Regular, 20, 126, y + 30, C(kMuted), Align::Left,
                     console_looking_ ? "Looking in your homebrew folders\xE2\x80\xA6" : "Nothing in your homebrew folders.");
    }
    gfx.unclip();
    if (total_h - lscroll_ > view_h + 8) draw_more(gfx);
}

/* ---- The App manager ------------------------------------------------------------------------ */

void Store::open_manage(const ConsoleApp &c)
{
    if (engine().job().phase == Phase::Working)
    {
        lib_note_ = "Wait for the current job to finish first.";
        lib_note_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    mng_ = true;
    mng_t_ = 0;
    mng_stage_ = 0;
    mng_sel_ = 0;
    mng_app_ = c;
    mng_fresh_.clear();
    mng_places_.clear();
    play(int(Sfx::Open));
}

/* The buttons of the sheet's stage, left to right (Move's: top to bottom). */
std::vector<std::string> Store::manage_buttons() const
{
    const ConsoleApp &c = mng_app_;
    switch (mng_stage_)
    {
    case 0:
        if (c.store) return {"Close"};
        if (!c.app) return {"Delete", "Close"};
        return {"Open", "Move", "New ID", "Delete"};
    case 1:
    {
        std::vector<std::string> b;
        for (const Place &p : mng_places_) b.push_back(p.label);
        b.push_back(mng_places_.empty() ? "Close" : "Cancel");
        return b;
    }
    case 2:
        return {"Give it " + mng_fresh_, "Cancel"};
    default:
    {
        const App *a = catalog_app(catalog_.apps, c);
        if (a && !a->saves.empty() && app_folder(a->id) == c.folder) return {"Cancel", "Keep my saves", "Delete everything"};
        return {"Cancel", "Delete"};
    }
    }
}

bool Store::update_manage(float dt)
{
    mng_t_ = std::min(1.f, mng_t_ + dt * 5.f);
    const auto buttons = manage_buttons();
    const int nb = int(buttons.size());
    const bool vertical = mng_stage_ == 1;
    const bool back = nav(vertical ? pad::BtnUp : pad::BtnLeft, vertical ? nav_up_ : nav_left_, dt);
    const bool fwd = nav(vertical ? pad::BtnDown : pad::BtnRight, vertical ? nav_down_ : nav_right_, dt);
    const int s = std::clamp(mng_sel_ + (fwd ? 1 : 0) - (back ? 1 : 0), 0, nb - 1);
    if (s != mng_sel_) { mng_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle))
    {
        if (mng_stage_ == 0) mng_ = false;
        else { mng_stage_ = 0; mng_sel_ = 0; }
        play(int(Sfx::Back));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    const ConsoleApp c = mng_app_;
    const std::string pick = buttons[std::size_t(mng_sel_)];
    auto busy = [&]() {
        if (engine().job().phase != Phase::Working) return false;
        lib_note_ = "Wait for the current job to finish first.";
        lib_note_bad_ = true;
        mng_ = false;
        play(int(Sfx::Error));
        return true;
    };
    if (mng_stage_ == 0)
    {
        if (pick == "Close") { mng_ = false; play(int(Sfx::Back)); return true; }
        if (pick == "Open")
        {
            mng_ = false;
            std::string why;
            if (!open_game(c.title_id, why))
            {
                lib_note_ = "Couldn't open " + c.name + ". " + why;
                lib_note_bad_ = true;
                play(int(Sfx::Error));
                return true;
            }
            opening_ = true;
            opening_lib_ = true;
            opening_t_ = 0;
            opening_poll_ = 0;
            lib_note_ = "Opening " + c.name + "\xE2\x80\xA6";
            lib_note_bad_ = false;
            play(int(Sfx::Select));
            return true;
        }
        if (pick == "Move")
        {
            mng_places_.clear();
            places_t_ = -1; /* the drives looked for again now */
            for (const Place &p : install_places_now())
                if (p.path != c.root) mng_places_.push_back(p);
            mng_stage_ = 1;
        }
        else if (pick == "New ID")
        {
            mng_fresh_ = engine().free_title_id();
            if (mng_fresh_.empty())
            {
                mng_ = false;
                lib_note_ = "There's no free title ID left to give it.";
                lib_note_bad_ = true;
                play(int(Sfx::Error));
                return true;
            }
            mng_stage_ = 2;
        }
        else
            mng_stage_ = 3;
        mng_sel_ = mng_stage_ == 1 ? 0 : mng_stage_ == 2 ? 1 : 0; /* the question: Cancel first */
        mng_t_ = 0.6f;
        play(int(Sfx::Select));
        return true;
    }
    if (pick == "Cancel" || pick == "Close")
    {
        mng_stage_ = 0;
        mng_sel_ = 0;
        if (pick == "Close") mng_ = false;
        play(int(Sfx::Back));
        return true;
    }
    if (busy()) return true;
    mng_ = false;
    bool started = false;
    if (mng_stage_ == 1)
    {
        const Place &to = mng_places_[std::size_t(mng_sel_)];
        started = engine().manage("mmove", c.folder, to.path);
        lib_note_ = "Moving " + c.name + " to " + to.label + "\xE2\x80\xA6";
    }
    else if (mng_stage_ == 2)
    {
        started = engine().manage("mretitle", c.folder);
        lib_note_ = "Giving " + c.name + " " + mng_fresh_ + "\xE2\x80\xA6";
    }
    else
    {
        const App *a = catalog_app(catalog_.apps, c);
        if (a && app_folder(a->id) == c.folder)
        {
            /* The store's own install: its game files and records go with it, as from its page. */
            started = engine().remove(a->id, pick == "Keep my saves");
            mng_delete_name_ = c.name;
        }
        else
            started = engine().manage("mdelete", c.folder);
        lib_note_ = "Deleting " + c.name + "\xE2\x80\xA6";
    }
    lib_note_bad_ = false;
    if (started)
    {
        mng_job_ = true;
        play(int(Sfx::Install));
    }
    else
    {
        lib_note_ = "The store is busy. Try again in a moment.";
        lib_note_bad_ = true;
        play(int(Sfx::Error));
    }
    return true;
}

void Store::draw_manage(Gfx &gfx)
{
    if (!mng_) return;
    const float k = ease_out(mng_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const ConsoleApp &c = mng_app_;
    const float bw = 900, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const std::string size = c.size >= 0 ? size_text(std::uint64_t(c.size)) : std::string("its size isn't worked out yet");
    const App *a = catalog_app(catalog_.apps, c);
    std::string title, value, body;
    switch (mng_stage_)
    {
    case 0:
        title = c.name;
        value = (c.title_id.empty() ? std::string("No title ID") : c.title_id) + "  \xC2\xB7  " + c.folder;
        if (c.store)
            body = "This is Recomp Store itself. It isn't moved, renamed or deleted from here.";
        else if (!c.app)
            body = "This folder has no eboot.bin, so it isn't an app: something left behind. " + c.place + ", " + size + ".";
        else
            body = (c.version.empty() ? std::string() : "Version " + c.version + ". ") + c.place + ", " + size + ". " +
                   (c.store_id.empty() ? "Put there some other way than Recomp Store." : "Installed by Recomp Store.");
        break;
    case 1:
        title = "Move " + c.name;
        value = "From " + c.place + "  \xC2\xB7  " + size;
        body = mng_places_.empty() ? "There's nowhere else to move it. Connect a USB drive or an M.2 SSD, then try again."
                                   : "It's copied there, every file is checked, then the copy here is deleted. Its tile follows it once the store closes.";
        break;
    case 2:
        title = "Give " + c.name + " a new title ID?";
        value = "From " + c.title_id + " to " + mng_fresh_;
        body = "Its param.json and its folder change to " + mng_fresh_ + ", and its tile changes once the store closes. Saves it made as " +
               c.title_id + " stay with " + c.title_id + ".";
        if (a) body += " Recomp Store keeps updating it under the new ID.";
        break;
    default:
        title = "Delete " + c.name + "?";
        value = c.folder;
        body = "Everything in its folder goes (" + size + "), saves kept there too. Its tile comes off your home screen once the store closes.";
        if (a && !a->saves.empty() && app_folder(a->id) == c.folder) body = "Everything in its folder goes (" + size + "). Keep my saves leaves its save folders. Its tile comes off your home screen once the store closes.";
        break;
    }
    const auto lines = wrap(gfx, Font::Regular, 20, iw, body, 4);
    const auto buttons = manage_buttons();
    const bool vertical = mng_stage_ == 1;
    const float btn_h = vertical ? 64.f * float(buttons.size()) + 12.f * float(buttons.size() - 1) : 64.f;
    const float bh = pad + 40 + 16 + 30 + 18 + 30 * float(lines.size()) + 34 + btn_h + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 20, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 32, iw, title));
    y += 40 + 16;
    gfx.text_mid(Font::SemiBold, 20, bx + pad, y + 15, C(kCyan), Align::Left, fit(gfx, Font::SemiBold, 20, iw, value));
    y += 30 + 18;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines);
    y += 34;
    const float gap = vertical ? 12.f : 16.f;
    const float w = vertical ? iw : (iw - gap * float(buttons.size() - 1)) / float(buttons.size());
    for (std::size_t i = 0; i < buttons.size(); ++i)
    {
        const float x = vertical ? bx + pad : bx + pad + float(i) * (w + gap);
        const float yy = vertical ? y + float(i) * (64 + gap) : y;
        const bool on = mng_sel_ == int(i);
        const std::string &label = buttons[i];
        const bool danger = label == "Delete" || label == "Delete everything";
        const bool main = (mng_stage_ == 2 && i == 0) || (mng_stage_ == 1 && label != "Cancel" && label != "Close");
        const Color bg = main ? C(kCyan, on ? 1.f : 0.3f) : danger ? C(0xE5484D, on ? 0.9f : 0.16f) : C(kFrost, on ? 1.f : 0.12f);
        gfx.panel(x, yy, w, 64, bg, 1.f, 20);
        ring(gfx, x, yy, w, 64, 20, on ? 1.f : 0.f, true);
        const Color fg = danger ? (on ? C(0xFFFFFF) : C(0xFF8A80)) : on ? C(kMidnight) : C(kFrost);
        gfx.text_mid(Font::Bold, 22, x + w / 2, yy + 32, fg, Align::Center, fit(gfx, Font::Bold, 22, w - 24, label));
    }
    gfx.set_layer();
}

/* A job the App manager started, done: what it did, in the Library. */
bool Store::take_manage_result(const JobState &job)
{
    const bool mine = job.kind == "mdelete" || job.kind == "mmove" || job.kind == "mretitle" || (mng_job_ && job.kind == "delete");
    if (!mine || (job.phase != Phase::Done && job.phase != Phase::Failed)) return false;
    mng_job_ = false;
    if (job.phase == Phase::Done)
    {
        if (!job.note.empty() && job.kind != "delete")
            lib_note_ = job.note;
        else
        {
            const std::string name = mng_delete_name_.empty() ? std::string("it") : mng_delete_name_;
            lib_note_ = !job.note.empty() ? "Deleted " + name + ". " + job.note
                        : job.home == 3 ? "Deleted " + name + ". Its tile comes off your home screen once the store closes."
                        : job.home == 2 ? "Deleted " + name + ". ShadowMountPlus took its tile off your home screen."
                                        : "Deleted " + name + ".";
        }
        lib_note_bad_ = false;
        play(int(Sfx::Complete));
    }
    else
    {
        lib_note_ = job.error;
        lib_note_bad_ = true;
        play(int(Sfx::Error));
    }
    mng_delete_name_.clear();
    engine().acknowledge();
    engine().look_console();
    return true;
}
} // namespace recomp
