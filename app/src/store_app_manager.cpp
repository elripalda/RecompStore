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
#include <dirent.h>
#include <sys/stat.h>
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
/* (2.2.2) The grid: tiles as My Apps' (seven a row), two lines under each. */
const float kTileS = 216, kTileGap = 32, kConPitch = 216 + 108;
const int kPerRow = 7;
const float kRowTop = 62; /* the list starts this far under the row of views */

std::string lower_name(std::string s)
{
    for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
    return s;
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

/* (2.2.2) Where a homebrew folder comes in the list of drives: the console's, the M.2 SSD's, extended
 * storage's, then the USB drives'. */
static int root_rank(const std::string &root)
{
    if (root == "/data/homebrew") return 0;
    if (root.rfind("/mnt/ext1", 0) == 0) return 1;
    if (root.rfind("/mnt/ext0", 0) == 0) return 2;
    if (root.rfind("/mnt/usb", 0) == 0 && root.size() > 8) return 3 + (root[8] - '0');
    return 20;
}

std::vector<const ConsoleApp *> Store::console_list() const
{
    std::vector<const ConsoleApp *> out;
    for (const ConsoleApp &c : console_)
        if (console_match(c)) out.push_back(&c);
    auto by_name = [](const ConsoleApp *a, const ConsoleApp *b) { return lower_name(a->name) < lower_name(b->name); };
    std::stable_sort(out.begin(), out.end(), by_name);
    if (prefs_.con_sort == 1)
        std::stable_sort(out.begin(), out.end(), [](const ConsoleApp *a, const ConsoleApp *b) { return a->size > b->size; });
    else if (prefs_.con_sort == 2)
        std::stable_sort(out.begin(), out.end(), [](const ConsoleApp *a, const ConsoleApp *b) { return a->mtime > b->mtime; });
    else if (prefs_.con_sort == 3)
        std::stable_sort(out.begin(), out.end(), [](const ConsoleApp *a, const ConsoleApp *b) { return root_rank(a->root) < root_rank(b->root); });
    return out;
}

bool Store::console_match(const ConsoleApp &c) const
{
    const std::string &f = prefs_.con_filter;
    if (f == "store") return c.store || !c.store_id.empty();
    if (f == "other") return !c.store && c.store_id.empty();
    if (f.rfind("/", 0) == 0) return c.root == f;
    return true;
}

std::vector<std::string> Store::console_filters() const
{
    std::vector<std::string> out{"all", "store", "other"};
    std::vector<std::string> roots;
    for (const ConsoleApp &c : console_)
        if (std::find(roots.begin(), roots.end(), c.root) == roots.end()) roots.push_back(c.root);
    std::stable_sort(roots.begin(), roots.end(), [](const std::string &a, const std::string &b) { return root_rank(a) < root_rank(b); });
    if (roots.size() > 1 || (prefs_.con_filter.rfind("/", 0) == 0))
        for (const std::string &r : roots) out.push_back(r);
    if (prefs_.con_filter.rfind("/", 0) == 0 && std::find(out.begin(), out.end(), prefs_.con_filter) == out.end())
        out.push_back(prefs_.con_filter); /* a drive that's gone since: still there to move off */
    return out;
}

std::string Store::filter_label(const std::string &f) const
{
    if (f == "store") return "From Recomp Store";
    if (f == "other") return "Not from the store";
    if (f.rfind("/", 0) == 0)
    {
        for (const ConsoleApp &c : console_)
            if (c.root == f) return c.place;
        return f.rfind("/mnt/usb", 0) == 0 && f.size() > 8 ? std::string("USB drive ") + char(f[8] + 1) : f;
    }
    return "All";
}

/* The row over the list: \x01 marks the view that's on, \x02 a button that deletes. */
std::vector<std::string> Store::console_chips() const
{
    if (con_select_)
    {
        long long all = 0;
        for (const ConsoleApp &c : console_)
            if (std::find(con_marked_.begin(), con_marked_.end(), c.folder) != con_marked_.end() && c.size > 0) all += c.size;
        std::size_t can = 0;
        for (const ConsoleApp *c : console_list()) can += c->store ? 0 : 1;
        const std::string del = con_marked_.empty() ? std::string("Delete")
                                                    : "Delete " + std::to_string(con_marked_.size()) + "  \xC2\xB7  " + size_text(std::uint64_t(all));
        return {"\x02" + del, con_marked_.size() >= can && can > 0 ? "Select none" : "Select all", "Done"};
    }
    const char *sorts[4] = {"Name", "Biggest", "Newest", "Location"};
    return {std::string(prefs_.con_view == 0 ? "\x01" : "") + "Grid", std::string(prefs_.con_view == 1 ? "\x01" : "") + "List",
            std::string("Sort: ") + sorts[std::clamp(prefs_.con_sort, 0, 3)], "Show: " + filter_label(prefs_.con_filter), "Select", "Storage",
            "Refresh home screen", "Reload ShadowMountPlus"};
}

void Store::press_console_chip(const std::string &chip0)
{
    const std::string chip = !chip0.empty() && (chip0[0] == '\x01' || chip0[0] == '\x02') ? chip0.substr(1) : chip0;
    if (chip == "Grid" || chip == "List")
    {
        prefs_.con_view = chip == "Grid" ? 0 : 1;
        save_prefs();
        play(int(Sfx::Select));
    }
    else if (chip.rfind("Sort: ", 0) == 0)
    {
        prefs_.con_sort = (prefs_.con_sort + 1) % 4;
        col_ = 0;
        lscroll_ = lscroll_to_ = 0;
        save_prefs();
        play(int(Sfx::Select));
    }
    else if (chip.rfind("Show: ", 0) == 0)
    {
        const auto fs = console_filters();
        const auto at = std::find(fs.begin(), fs.end(), prefs_.con_filter);
        prefs_.con_filter = at == fs.end() || at + 1 == fs.end() ? fs.front() : *(at + 1);
        col_ = 0;
        lscroll_ = lscroll_to_ = 0;
        save_prefs();
        play(int(Sfx::Select));
    }
    else if (chip == "Select")
    {
        con_select_ = true;
        con_marked_.clear();
        con_chip_ = 2; /* Done, while nothing's marked */
        lib_note_.clear();
        play(int(Sfx::Select));
    }
    else if (chip == "Storage")
        open_panel(PanelStorage);
    else if (chip == "Refresh home screen")
        start_refresh_home(true);
    else if (chip == "Reload ShadowMountPlus")
        start_reload_smp();
    else if (chip.rfind("Delete", 0) == 0)
    {
        if (con_marked_.empty()) { toast("Mark the apps to delete first"); play(int(Sfx::Error)); return; }
        if (engine().job().phase == Phase::Working) { lib_note_ = "Wait for the current job to finish first."; lib_note_bad_ = true; play(int(Sfx::Error)); return; }
        open_panel(PanelDelete);
    }
    else if (chip == "Select all" || chip == "Select none")
    {
        con_marked_.clear();
        if (chip == "Select all")
            for (const ConsoleApp *c : console_list())
                if (!c->store) con_marked_.push_back(c->folder);
        play(int(Sfx::Select));
    }
    else if (chip == "Done")
    {
        con_select_ = false;
        con_marked_.clear();
        con_chip_ = 4;
        play(int(Sfx::Back));
    }
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
    if (engine().storage_lost())
    {
        note = kStorageLost;
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    std::string why;
    /* 2 running: it has a look queued for when the store closes; 1 not running: the launcher
     * starts it once the store has closed (2.2.1: never from here); 0 neither. */
    const int r = refresh_smp(why);
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

void Store::start_reload_smp(bool from_lib)
{
    std::string &note = from_lib ? lib_note_ : settings_note_;
    bool &bad = from_lib ? lib_note_bad_ : settings_note_bad_;
    if (engine().storage_lost())
    {
        note = kStorageLost;
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    if (smp_path().empty())
    {
        note = "Choose your ShadowMountPlus file in Settings > ShadowMountPlus first.";
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    if (engine().job().phase == Phase::Working)
    {
        note = "Wait for the current job to finish first.";
        bad = true;
        play(int(Sfx::Error));
        return;
    }
    /* (2.2.1) A new copy replaces the running one, whose exit takes /data away from every app
     * that's open: the launcher sends it once the store has closed, then opens the store again. */
    std::string why;
    if (!engine().reload_smp(why))
    {
        note = "Couldn't reload ShadowMountPlus. " + why;
        bad = true;
        play(int(Sfx::Error));
        return;
    }
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
    if (engine().storage_lost())
    {
        /* (2.2.1) Nothing here can be done now: only up, to the views. */
        col_ = 0;
        con_on_row_ = false;
        if (u) { lib_bar_ = true; play(int(Sfx::Move)); }
        return true;
    }
    sync_console();
    const auto list = console_list();
    const int n = int(list.size());
    if (con_select_ && pressed(pad::BtnCircle))
    {
        press_console_chip("Done");
        return true;
    }
    if (con_on_row_)
    {
        /* (2.2.2) The row: views, sort, filter, Select, Storage, ShadowMountPlus's two. */
        const auto chips = console_chips();
        const int c = std::clamp(con_chip_ + moves, 0, int(chips.size()) - 1);
        if (c != con_chip_) { con_chip_ = c; play(int(Sfx::Move)); }
        if (u) { con_on_row_ = false; lib_bar_ = true; play(int(Sfx::Move)); return true; }
        if (d && n > 0) { con_on_row_ = false; col_ = std::clamp(col_, 0, n - 1); play(int(Sfx::Move)); return true; }
        if (pressed(pad::BtnCross)) press_console_chip(chips[std::size_t(con_chip_)]);
        else if (pressed(pad::BtnSquare) && !con_select_) press_console_chip(prefs_.con_view == 0 ? "List" : "Grid");
        return true;
    }
    if (n == 0)
    {
        if (u) { con_on_row_ = true; play(int(Sfx::Move)); }
        return true;
    }
    const bool grid = prefs_.con_view == 0;
    const int per = grid ? kPerRow : 1;
    col_ = std::clamp(col_, 0, n - 1);
    int to = col_;
    if (grid && moves) to = std::clamp(col_ + moves, 0, n - 1);
    if (d) to = std::min(n - 1, col_ + per);
    if (u)
    {
        if (col_ < per) { con_on_row_ = true; play(int(Sfx::Move)); return true; }
        to = col_ - per;
    }
    if (to != col_) { col_ = to; play(int(Sfx::Move)); }
    if (pressed(pad::BtnSquare) && !con_select_)
    {
        prefs_.con_view = 1 - prefs_.con_view; /* Square: grid or list */
        save_prefs();
        toast(prefs_.con_view == 0 ? "Grid view" : "List view");
        play(int(Sfx::Select));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    const ConsoleApp &c = *list[std::size_t(col_)];
    if (con_select_)
    {
        /* Marked to delete together; never the store itself. */
        if (c.store) { toast("Recomp Store can't be deleted from here"); play(int(Sfx::Error)); return true; }
        auto at = std::find(con_marked_.begin(), con_marked_.end(), c.folder);
        if (at != con_marked_.end()) con_marked_.erase(at);
        else con_marked_.push_back(c.folder);
        con_chip_ = 0; /* Delete, up there */
        play(int(Sfx::Select));
        return true;
    }
    open_manage(c);
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

void Store::draw_console_tile(Gfx &gfx, const ConsoleApp &c, float x, float y, float size, float focus)
{
    /* (2.2.2) As a Discover tile: the icon, lifted when focused; its name, title ID and size under it. */
    const float s = 1 + 0.06f * focus;
    const float w = size * s;
    const float tx = x + size / 2 - w / 2, ty = y + size / 2 - w / 2 - 10 * focus;
    const float R = 28 * s;
    gfx.panel(tx + 8, ty + 22 + 8 * focus, w - 16, w - 16, {0, 0, 0, 0}, 1.f, R, {0, 0, 0, 0.25f + 0.25f * focus}, 0, 24 + 12 * focus);
    if (Texture *t = console_icon(c))
        gfx.image(t, tx, ty, w, w, {}, R);
    else
    {
        gfx.panel(tx, ty, w, w, C(0x15223A), 1.f, R, kRim, 1.25f);
        if (c.app)
        {
            std::string first = c.name.substr(0, 1);
            for (char &ch : first)
                if (ch >= 'a' && ch <= 'z') ch = char(ch - 'a' + 'A'); /* (not toupper: one more import) */
            gfx.text_mid(Font::ExtraBold, 72 * s, tx + w / 2, ty + w / 2, C(kCyan, 0.9f), Align::Center, first);
        }
        else if (folder_icon_)
            gfx.image(folder_icon_, tx + w * 0.3f, ty + w * 0.3f, w * 0.4f, w * 0.4f, C(kMuted));
    }
    ring(gfx, tx, ty, w, w, R, focus);
    if (c.store || !c.store_id.empty())
    {
        const char *tag = c.store ? "THIS STORE" : "RECOMP STORE";
        const float bw = gfx.measure(Font::Bold, 12, tag, 1) + 18;
        gfx.panel(tx + 12, ty + 12, bw, 24, C(kMidnight, 0.82f), 1.f, 12, C(kCyan, 0.6f), 1.f);
        gfx.text_mid(Font::Bold, 12, tx + 12 + bw / 2, ty + 24, C(kCyan), Align::Center, tag, 1);
    }
    if (c.root != "/data/homebrew")
    {
        /* Where it is, when it isn't the console's own storage. */
        const std::string where = c.place;
        const float bw = gfx.measure(Font::Bold, 13, where) + 20;
        gfx.panel(tx + 12, ty + w - 12 - 26, bw, 26, C(kMidnight, 0.82f), 1.f, 13, kRim, 1.f);
        gfx.text_mid(Font::Bold, 13, tx + 12 + bw / 2, ty + w - 12 - 13, C(kFrost), Align::Center, where);
    }
    if (con_select_ && !c.store)
    {
        const bool marked = std::find(con_marked_.begin(), con_marked_.end(), c.folder) != con_marked_.end();
        const float r = 17, cx = tx + w - 14 - r, cy = ty + 14 + r;
        gfx.panel(cx - r, cy - r, r * 2, r * 2, marked ? C(0xE5484D) : C(kMidnight, 0.7f), 1.f, r, marked ? C(0xFFFFFF, 0.9f) : C(kFrost, 0.8f), 2.f);
        if (marked) draw_check(gfx, cx, cy, 20, C(0xFFFFFF));
    }
    const JobState job = engine().job();
    const bool busy = job.phase == Phase::Working &&
                      (job.app_id == c.folder || (!c.store_id.empty() && job.kind == "delete" && job.app_id == c.store_id));
    if (busy)
    {
        gfx.panel(tx, ty, w, w, C(kMidnight, 0.6f), 1.f, R);
        gfx.text_mid(Font::Bold, 18, tx + w / 2, ty + w / 2, C(kFrost), Align::Center, "Working\xE2\x80\xA6");
    }
    gfx.text_mid(Font::SemiBold, 21, x, y + size + 14 + 13, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, size + 20, c.name));
    std::string line = c.app ? (c.title_id.empty() ? std::string("No title ID") : c.title_id) : std::string("Not an app");
    line += "  \xC2\xB7  " + (c.size >= 0 ? size_text(std::uint64_t(c.size)) : std::string("\xE2\x80\xA6"));
    gfx.text_mid(Font::Regular, 17, x, y + size + 14 + 26 + 4 + 11, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, size + 20, line));
}

void Store::draw_console(Gfx &gfx, float slide)
{
    if (engine().storage_lost())
    {
        /* (2.2.1) Not "nothing here": the store can't see the console's storage any more. */
        const auto lines = wrap(gfx, Font::SemiBold, 22, 1728 - 80, kStorageLost, 3);
        const float nh = 44 + 32 * float(lines.size());
        glass(gfx, 96, kContent + slide, 1728, nh, 24, C(0xE5484D, 0.14f), false);
        draw_lines(gfx, Font::SemiBold, 22, 136, kContent + slide + 22, 32, C(0xFFB4A8), lines);
        return;
    }
    sync_console();
    const auto list = console_list();
    /* (2.2.2) The row: views and actions, and ShadowMountPlus as last asked on its right. */
    {
        std::string ver;
        const int smp = engine().smp_state(ver);
        std::string right;
        if (con_select_) right = con_marked_.empty() ? "Cross marks an app" : std::to_string(con_marked_.size()) + " marked";
        else if (smp == 1) right = "\x03ShadowMountPlus " + (ver.empty() ? std::string() : ver + " ") + "is running";
        else if (smp == 0) right = "\x04ShadowMountPlus isn't running";
        draw_chip_row(gfx, console_chips(), con_chip_, con_on_row_ && !lib_bar_, kContent + slide, right);
    }
    float top = kContent + kRowTop;
    if (!lib_note_.empty())
    {
        /* What the last thing done here did, in full (the bar only has room for a line of it). */
        const auto lines = wrap(gfx, Font::SemiBold, 19, 1728 - 60, lib_note_, 2);
        const float nh = 26 + 28 * float(lines.size());
        glass(gfx, 96, top + slide, 1728, nh, 20, lib_note_bad_ ? C(0xE5484D, 0.12f) : C(kGreen, 0.10f), false);
        draw_lines(gfx, Font::SemiBold, 19, 126, top + slide + 13, 28, lib_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), lines);
        top += nh + 14;
    }
    const int n = int(list.size());
    const bool grid = prefs_.con_view == 0;
    const float pitch = grid ? kConPitch : kRowPitch;
    const int rows = grid ? (n + kPerRow - 1) / kPerRow : n;
    const int fr = grid ? std::clamp(col_, 0, std::max(0, n - 1)) / kPerRow : col_;
    const float view_h = kBottom - top;
    const float total_h = float(rows) * pitch;
    keep_shown(lscroll_, lscroll_to_, float(fr) * pitch, float(fr) * pitch + (grid ? pitch - 30 : kRowH + 8), view_h, total_h);
    /* Room above the first row for its focus ring, unless the list is scrolled (then a sliver of
     * the row above would show under the note). */
    const float above = lscroll_ > 0.5f ? 0.f : 12.f;
    gfx.clip(0, top - above, gfx.design_w(), kBottom - top + above);
    const bool lit = !lib_bar_ && !con_on_row_;
    for (int i = 0; i < n; ++i)
    {
        float &f = focus_anim_[std::min(i, 511)];
        f = approach(f, i == col_ && lit ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
        const float y = top + (grid ? 14 : 8) + float(grid ? i / kPerRow : i) * pitch - lscroll_ + slide;
        if (y > kBottom || y + pitch < top - 20) continue;
        if (grid)
            draw_console_tile(gfx, *list[std::size_t(i)], 96 + float(i % kPerRow) * (kTileS + kTileGap), y, kTileS, ease_out(f));
        else
        {
            draw_console_row(gfx, *list[std::size_t(i)], 96, y, 1728, f);
            const ConsoleApp &c = *list[std::size_t(i)];
            if (con_select_ && !c.store)
            {
                /* Marked or not, on the icon's corner. */
                const bool marked = std::find(con_marked_.begin(), con_marked_.end(), c.folder) != con_marked_.end();
                const float r = 15, cx = 96 + 20 + 84 - 6, cy = y + (kRowH - 84) / 2 + 6;
                gfx.panel(cx - r, cy - r, r * 2, r * 2, marked ? C(0xE5484D) : C(kMidnight, 0.75f), 1.f, r,
                          marked ? C(0xFFFFFF, 0.9f) : C(kFrost, 0.8f), 2.f);
                if (marked) draw_check(gfx, cx, cy, 18, C(0xFFFFFF));
            }
        }
    }
    if (list.empty())
        gfx.text_mid(Font::Regular, 20, 126, top + 30 + slide, C(kMuted), Align::Left,
                     console_looking_ ? "Looking in your homebrew folders\xE2\x80\xA6"
                     : console_.empty() ? "Nothing in your homebrew folders." : "Nothing here. Show: All lists everything.");
    gfx.unclip();
    edge_fade(gfx, top - above, kBottom, lscroll_ > 0.5f); /* (2.2.3) */
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
    const bool mine = job.kind == "mdelete" || job.kind == "mmove" || job.kind == "mretitle" || job.kind == "mdeletes" /* (2.2.2) */ ||
                      (mng_job_ && job.kind == "delete");
    if (!mine || (job.phase != Phase::Done && job.phase != Phase::Failed)) return false;
    mng_job_ = false;
    if (job.kind == "mdeletes")
    {
        /* (2.2.2) Several at once: Select ends, and the note says how it went. */
        con_select_ = false;
        con_marked_.clear();
    }
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
        lib_note_ = engine().storage_lost() ? kStorageLost : job.error; /* (2.2.1) */
        lib_note_bad_ = true;
        play(int(Sfx::Error));
    }
    mng_delete_name_.clear();
    engine().acknowledge();
    engine().look_console();
    return true;
}
/* ---- (2.2.3) My Apps > Drives ------------------------------------------------------------------ */

bool Store::update_drives(float dt, int moves, bool u, bool d)
{
    (void)dt;
    (void)moves;
    const auto dr = engine().drives();
    const int n = int(dr.size());
    if (n == 0)
    {
        if (u) { lib_bar_ = true; play(int(Sfx::Move)); }
        return true;
    }
    drv_sel_ = std::clamp(drv_sel_, 0, n - 1);
    if (u)
    {
        if (drv_sel_ == 0) { lib_bar_ = true; play(int(Sfx::Move)); return true; }
        --drv_sel_;
        play(int(Sfx::Move));
    }
    if (d && drv_sel_ + 1 < n) { ++drv_sel_; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCross))
    {
        /* Its apps, in On this PS5 (where they can be moved and deleted). */
        prefs_.con_filter = dr[std::size_t(drv_sel_)].homebrew;
        save_prefs();
        lib_view_ = 3;
        col_ = 0;
        lscroll_ = lscroll_to_ = 0;
        lib_view_t_ = 0;
        console_rev_ = ~0u;
        engine().look_console();
        play(int(Sfx::Select));
    }
    return true;
}

void Store::draw_drives(Gfx &gfx, float slide)
{
    const auto dr = engine().drives();
    const float top = kContent + slide;
    if (dr.empty())
    {
        gfx.text_mid(Font::Regular, 20, 96, top + 30, C(kMuted), Align::Left, "Looking at your drives\xE2\x80\xA6");
        return;
    }
    drv_sel_ = std::clamp(drv_sel_, 0, int(dr.size()) - 1);
    /* The drives, down the left: each one's name, where it is, and a bar of what's used. */
    const float lx = 96, lw = 660, ch = 132, gap = 16;
    for (int i = 0; i < int(dr.size()); ++i)
    {
        const DriveSpace &s = dr[std::size_t(i)];
        const float y = top + float(i) * (ch + gap);
        if (y > kBottom - ch) break;
        const bool on = i == drv_sel_ && !lib_bar_;
        float &f = focus_anim_[std::min(400 + i, 511)];
        f = approach(f, on ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
        glass(gfx, lx, y, lw, ch, 26, {1, 1, 1, 0.07f + 0.05f * f}, true);
        ring(gfx, lx, y, lw, ch, 26, f);
        gfx.text_mid(Font::Bold, 24, lx + 28, y + 34, C(kFrost), Align::Left, s.label);
        gfx.text_mid(Font::Regular, 16, lx + lw - 28, y + 34, C(kMuted), Align::Right, s.mount);
        const double used = s.total > s.free ? double(s.total - s.free) : 0, total = double(std::max<std::uint64_t>(1, s.total));
        const float bx = lx + 28, bw = lw - 56, by = y + 64;
        gfx.panel(bx, by, bw, 12, C(kFrost, 0.10f), 1.f, 6);
        /* (2.2.4) What's known to be on it: its apps, and for the one shown, its top folders. More
         * than the drive's size means the size is wrong: "Size unknown", no bar. */
        std::uint64_t listed = 0, folders = 0;
        for (const ConsoleApp &c : console_)
            if (c.root == s.homebrew && c.size > 0) listed += std::uint64_t(c.size);
        if (i == drv_sel_)
            for (const FolderSize &fs_ : engine().drive_folders(s.mount, nullptr))
                if (fs_.size > 0) folders += std::uint64_t(fs_.size);
        const bool sized = s.sized && s.total && std::max(listed, folders) <= s.total;
        const float k = sized ? float(std::clamp(used / total, 0.0, 1.0)) : 0.f;
        if (k > 0) gfx.panel(bx, by, std::max(12.f, bw * k), 12, C(k > 0.92f ? 0xFF9A8A : kCyan), 1.f, 6);
        gfx.text_mid(Font::Regular, 17, bx, y + 102, C(0xB9C4D3), Align::Left,
                     sized ? size_text(s.free) + " free of " + size_text(s.total) : std::string("Size unknown"));
    }
    /* The focused drive's top folders and what each takes: to see what's using the space. */
    const DriveSpace &s = dr[std::size_t(drv_sel_)];
    const float rx = lx + lw + 40, rw = 1824 - rx, rh = kBottom - top - 10;
    glass(gfx, rx, top, rw, rh, 30, C(kMidnight, 0.55f), true);
    gfx.text_mid(Font::Bold, 15, rx + 32, top + 34, C(kCyan), Align::Left, "WHAT'S ON IT", 2);
    gfx.text_mid(Font::Regular, 16, rx + rw - 32, top + 34, C(kMuted), Align::Right, "Look only \xC2\xB7 apps move and delete in On this PS5");
    bool looking = false;
    auto items = engine().drive_folders(s.mount, &looking);
    std::stable_sort(items.begin(), items.end(), [](const FolderSize &a, const FolderSize &b) {
        if ((a.size < 0) != (b.size < 0)) return a.size >= 0;
        return a.size > b.size;
    });
    float y = top + 70;
    const float row = 44;
    long long biggest = 1;
    for (const FolderSize &f : items) biggest = std::max(biggest, f.size);
    if (items.empty())
        gfx.text_mid(Font::Regular, 19, rx + 32, y + 10, C(kMuted), Align::Left, looking ? "Looking\xE2\x80\xA6" : "Nothing at its top.");
    for (const FolderSize &f : items)
    {
        if (y + row > top + rh - 20) break;
        if (f.dir)
            gfx.image(folder_icon_, rx + 32, y + 8, 26, 26, {}, 6);
        else
        {
            gfx.panel(rx + 37, y + 9, 17, 24, C(kFrost, 0.16f), 1.f, 4); /* a file: a little page */
            gfx.panel(rx + 41, y + 17, 9, 2, C(kFrost, 0.45f), 1.f, 1);
            gfx.panel(rx + 41, y + 22, 9, 2, C(kFrost, 0.45f), 1.f, 1);
        }
        gfx.text_mid(Font::SemiBold, 19, rx + 72, y + row / 2, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 19, rw * 0.45f, f.name));
        const std::string sz = f.size < 0 ? "\xE2\x80\xA6" : size_text(std::uint64_t(f.size));
        gfx.text_mid(Font::Regular, 18, rx + rw - 32, y + row / 2, C(0xB9C4D3), Align::Right, sz);
        if (f.size > 0)
        {
            const float bw = (rw * 0.30f) * float(double(f.size) / double(biggest));
            gfx.panel(rx + rw - 32 - 130 - rw * 0.30f, y + row / 2 - 4, std::max(6.f, bw), 8, C(kCyan, 0.55f), 1.f, 4);
        }
        y += row;
    }
}

/* A USB drive plugged in or taken out while the store is open: said, and looked at again. */
void Store::watch_usb()
{
    /* (2.2.3) The engine's own thread looks (every 2 s): only its answer is read here. */
    bool known = false;
    const unsigned mask = engine().usb_seen(&known);
    if (!known) return;
    if (!usb_known_) { usb_known_ = true; usb_mask_ = mask; return; }
    if (mask == usb_mask_) return;
    for (int i = 0; i < 8; ++i)
    {
        const bool was = usb_mask_ & (1u << i), now = mask & (1u << i);
        if (was != now) toast(std::string("USB drive ") + std::to_string(i + 1) + (now ? " connected" : " removed"));
    }
    usb_mask_ = mask;
    engine().look_console();
}

} // namespace recomp
