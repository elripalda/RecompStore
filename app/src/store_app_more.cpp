/* Recomp Store 2.2.2 - the download queue and its sheet, where to install a new app, the storage
 * view and the download cache, What's new, the tabs, deleting several apps at once, Settings >
 * ShadowMountPlus and Storage, auto-update, and the rows under an app's page.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_ui.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"
#include "ui_app_common.hpp"

#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstdlib>

namespace recomp
{
using namespace porpoise::ui;
using namespace sui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

namespace
{
constexpr const char *kStoreRepo = "elripalda/RecompStore";

std::string lower_ascii(std::string s)
{
    for (char &c : s)
        if (c >= 'A' && c <= 'Z') c = char(c - 'A' + 'a');
    return s;
}

/* "476 MB", "1.2 GB": what the catalog says an app's download is, in bytes (0 when it doesn't say). */
std::uint64_t size_of_text(const std::string &s)
{
    char *end = nullptr;
    const double v = std::strtod(s.c_str(), &end);
    if (!end || end == s.c_str() || v <= 0) return 0;
    std::string unit = lower_ascii(std::string(end));
    while (!unit.empty() && unit[0] == ' ') unit.erase(0, 1);
    const double mul = unit.rfind("gb", 0) == 0 ? 1073741824.0 : unit.rfind("mb", 0) == 0 ? 1048576.0 : unit.rfind("kb", 0) == 0 ? 1024.0 : 0;
    return std::uint64_t(v * mul);
}

/* A drive's size: TB once it's that big. */
std::string space_text(std::uint64_t b)
{
    if (b >= (1000ull << 30))
    {
        char t[32];
        std::snprintf(t, sizeof t, "%.2f TB", double(b) / double(1ull << 40));
        return t;
    }
    return size_text(b);
}

/* A sheet over the store: its box, centred, the title and a line under it. Returns the y its
 * contents start at. */
float sheet_box(Store *, Gfx &gfx, float k, float bw, float bh, float &bx, float &by)
{
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    bx = (W - bw) / 2;
    by = std::max(60.f, (H - bh) / 2);
    return by;
}
} // namespace

/* ---- The download queue -------------------------------------------------------------------- */

std::string Store::ordinal(int n)
{
    const int t = n % 100;
    const char *s = t >= 11 && t <= 13 ? "th" : n % 10 == 1 ? "st" : n % 10 == 2 ? "nd" : n % 10 == 3 ? "rd" : "th";
    return std::to_string(n) + s;
}

int Store::queue_place(const std::string &id) const
{
    const JobState j = engine().job();
    const bool going = j.phase != Phase::Idle && j.kind == "install";
    if (going && j.app_id == id) return 1;
    const auto q = engine().queue();
    for (std::size_t i = 0; i < q.size(); ++i)
        if (q[i].id == id) return int(i) + 1 + (j.phase != Phase::Idle ? 1 : 0);
    return 0;
}

void Store::finished(const std::string &id, int state, const std::string &text)
{
    finished_.erase(std::remove_if(finished_.begin(), finished_.end(), [&](const Finished &f) { return f.id == id; }), finished_.end());
    finished_.insert(finished_.begin(), Finished{id, state, text, JobState()});
    if (finished_.size() > 12) finished_.pop_back();
}

bool Store::enqueue(const App &a, const std::string &version, const std::string &root, bool quiet)
{
    if (!freed_)
    {
        notice_ = "Recomp Store can't write to /data. Start etaHEN, then open the store again.";
        notice_bad_ = true;
        play(int(Sfx::Error));
        return false;
    }
    const bool busy = engine().job().phase != Phase::Idle || !engine().queue().empty();
    if (!engine().queue_add(a.id, version, root))
    {
        if (!quiet) toast(a.name + " is in Downloads already");
        play(int(Sfx::Error));
        return false;
    }
    ++queue_total_;
    finished_.erase(std::remove_if(finished_.begin(), finished_.end(), [&](const Finished &f) { return f.id == a.id; }), finished_.end());
    notice_.clear();
    if (busy) toast("Queued " + a.name + " (" + ordinal(queue_place(a.id)) + ")");
    play(int(Sfx::Install));
    tick_queue(); /* at once, when nothing else is going */
    return true;
}

bool Store::downloads_shown() const
{
    const JobState j = engine().job();
    if (j.phase == Phase::Working && j.kind == "install") return true;
    if (!engine().queue().empty()) return true;
    for (const Finished &f : finished_)
        if (f.state == 2) return true;
    return false;
}

void Store::draw_downloads(Gfx &gfx)
{
    /* The pill at the top right: what's downloading, how far, how many wait. Options opens the list.
     * (2.2.3) Even room round everything, the name cut short with an ellipsis, how far it is as a
     * fill inside the pill's own shape, the count of the rest in its own badge. */
    dl_pill_x_ = 1920 - 96;
    if (!downloads_shown() || page_t_ > 0.5f) return;
    const JobState j = engine().job();
    const auto q = engine().queue();
    std::string name, right;
    float frac = -1;
    if (j.phase == Phase::Working && j.kind == "install")
    {
        const App *a = find(j.app_id);
        name = a ? a->name : j.app_id;
        const bool getting = j.step.empty() || j.step == "Downloading";
        if (getting && j.total)
        {
            frac = clamp01(float(double(j.done) / double(j.total)));
            right = std::to_string(int(frac * 100)) + "%";
        }
        else if (getting)
            right = j.done ? size_text(j.done) : std::string("Starting");
        else
        {
            frac = 1;
            right = j.step == "Installing" ? "Installing" : "Checking";
        }
    }
    else if (!q.empty())
    {
        const App *a = find(q.front().id);
        name = a ? a->name : q.front().id;
        right = "Next";
    }
    else
        name = "Needs your answer";
    const int waiting = int(q.size()) - (j.phase == Phase::Working ? 0 : (q.empty() ? 0 : 1));
    const std::string more = waiting > 0 ? "+" + std::to_string(waiting) : std::string();
    const float h = 48, cy = 76, top = cy - h / 2;
    const float pad_l = 7, dot = 34, gap = 10, pad_r = 20;
    const float right_w = right.empty() ? 0 : gfx.measure(Font::SemiBold, 17, right);
    const float more_w = more.empty() ? 0 : gfx.measure(Font::Bold, 14, more) + 18;
    const float fixed = pad_l + dot + gap + (right.empty() ? 0 : gap + right_w) + (more.empty() ? 0 : gap + more_w) + pad_r;
    const std::string shown = fit(gfx, Font::SemiBold, 17, std::max(64.f, 360 - fixed), name);
    const float name_w = gfx.measure(Font::SemiBold, 17, shown);
    const float w = fixed + name_w;
    const float x = 1920 - 96 - w;
    dl_pill_x_ = x;
    glass(gfx, x, top, w, h, h / 2, kGlass, true);
    if (frac > 0)
    {
        /* how far: the pill filling with cyan from the left, inside its own rounded shape */
        gfx.push_clip(x, top, w * frac, h);
        gfx.panel(x, top, w, h, C(kCyan, 0.20f), 1.f, h / 2);
        gfx.pop_clip();
    }
    /* a cyan dot with an arrow pointing down */
    gfx.panel(x + pad_l, cy - dot / 2, dot, dot, C(kCyan, 0.22f), 1.f, dot / 2, C(kCyan, 0.6f), 1.f);
    gfx.glyph(Glyph::Arrow, x + pad_l + dot / 2, cy + 1, 12, C(kCyan), 3.14159265f);
    float tx = x + pad_l + dot + gap;
    gfx.text_mid(Font::SemiBold, 17, tx, cy, C(kFrost), Align::Left, shown);
    tx += name_w;
    if (!right.empty())
    {
        tx += gap;
        gfx.text_mid(Font::SemiBold, 17, tx, cy, C(kCyan), Align::Left, right);
        tx += right_w;
    }
    if (!more.empty())
    {
        tx += gap;
        gfx.panel(tx, cy - 12, more_w, 24, C(kFrost, 0.16f), 1.f, 12);
        gfx.text_mid(Font::Bold, 14, tx + more_w / 2, cy, C(kFrost), Align::Center, more);
    }
}

/* ---- The sheets ---------------------------------------------------------------------------- */

void Store::open_panel(int kind)
{
    panel_ = kind;
    panel_t_ = 0;
    panel_sel_ = 0;
    panel_top_ = 0;
    if (kind == PanelPlace)
    {
        places_t_ = -1; /* looked for again now */
        place_list_ = install_places_now();
        const std::string want = !prefs_.last_root.empty() ? prefs_.last_root : engine().install_root();
        bool found = false;
        for (int i = 0; i < int(place_list_.size()); ++i)
            if (place_list_[std::size_t(i)].path == want) { panel_sel_ = i; found = true; }
        if (!found)
            for (int i = 0; i < int(place_list_.size()); ++i)
                if (place_list_[std::size_t(i)].path == engine().install_root()) panel_sel_ = i;
    }
    else if (kind == PanelStorage)
    {
        engine().look_console(); /* the drives and the folders' sizes, looked at again */
        sync_console();
        cache_look(true);
    }
    else if (kind == PanelNews)
    {
        if (catalog_.whats_new_version.empty() || !whats_new_due(kVersion, catalog_.whats_new_version, {}, false))
            engine().want_notes(kStoreRepo); /* GitHub's notes, when the site has none for this version */
    }
    else if (kind == PanelTabs)
        build_tab_rows();
    else if (kind == PanelQueue)
    {
        /* The open app's row, when it's in the list. */
        const auto q = engine().queue();
        const JobState j = engine().job();
        int row = j.phase == Phase::Working && j.kind == "install" ? 1 : 0;
        for (const QueueItem &it : q)
        {
            if (page_open_ && it.id == page_id_) panel_sel_ = row;
            ++row;
        }
    }
    play(int(Sfx::Open));
}

bool Store::update_panel(float dt)
{
    panel_t_ = std::min(1.f, panel_t_ + dt * 5.f);
    switch (panel_)
    {
    case PanelQueue: return update_queue_panel(dt);
    case PanelPlace: return update_place_panel(dt);
    case PanelStorage: return update_storage_panel(dt);
    case PanelNews: return update_news_panel(dt);
    case PanelTabs: return update_tabs_panel(dt);
    case PanelDelete: return update_delete_panel(dt);
    default: panel_ = 0; return true;
    }
}

void Store::draw_panel(Gfx &gfx)
{
    if (!panel_) return;
    const float k = ease_out(panel_t_);
    switch (panel_)
    {
    case PanelQueue: draw_queue_panel(gfx, k); break;
    case PanelPlace: draw_place_panel(gfx, k); break;
    case PanelStorage: draw_storage_panel(gfx, k); break;
    case PanelNews: draw_news_panel(gfx, k); break;
    case PanelTabs: draw_tabs_panel(gfx, k); break;
    case PanelDelete: draw_delete_panel(gfx, k); break;
    default: break;
    }
    gfx.set_layer();
}

std::string Store::panel_cross_hint() const
{
    switch (panel_)
    {
    case PanelQueue: return "Choose";
    case PanelPlace: return "Install here";
    case PanelNews: return "Got it";
    case PanelTabs: return "On or off";
    default: return "Choose";
    }
}

namespace
{
/* Downloads' rows: the one going, those waiting, then what finished this session. */
struct QRow
{
    std::string id;
    int kind = 0; /* 0 going, 1 waiting, 2 finished */
    int fin = -1; /* kind 2: its place in finished_ */
};
} // namespace

static std::vector<QRow> queue_rows(const JobState &j, const std::vector<QueueItem> &q, const std::vector<std::string> &done_ids)
{
    std::vector<QRow> rows;
    const bool going = j.phase == Phase::Working && j.kind == "install";
    if (going) rows.push_back({j.app_id, 0, -1});
    for (const QueueItem &it : q)
        if (!going || it.id != j.app_id) rows.push_back({it.id, 1, -1});
    for (std::size_t i = 0; i < done_ids.size(); ++i)
    {
        bool there = false;
        for (const QRow &r : rows) there = there || r.id == done_ids[i];
        if (!there) rows.push_back({done_ids[i], 2, int(i)});
    }
    return rows;
}

bool Store::update_queue_panel(float dt)
{
    std::vector<std::string> done_ids;
    for (const Finished &f : finished_) done_ids.push_back(f.id);
    const JobState j = engine().job();
    const auto rows = queue_rows(j, engine().queue(), done_ids);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    const int n = int(rows.size());
    const int s = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, std::max(0, n - 1));
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle) || pressed(pad::BtnOptions) || (n == 0 && pressed(pad::BtnCross)))
    {
        panel_ = 0;
        play(int(Sfx::Back));
        return true;
    }
    if (!pressed(pad::BtnCross) || n == 0) return true;
    const QRow &r = rows[std::size_t(panel_sel_)];
    const App *a = find(r.id);
    const std::string name = a ? a->name : r.id;
    if (r.kind == 0)
    {
        if (engine().cancel_job()) { toast("Cancelling " + name + "\xE2\x80\xA6"); play(int(Sfx::Back)); }
        else { toast(name + " is past its download: it finishes now"); play(int(Sfx::Error)); }
    }
    else if (r.kind == 1)
    {
        if (engine().queue_remove(r.id))
        {
            queue_total_ = std::max(0, queue_total_ - 1);
            toast("Took " + name + " out of Downloads");
            play(int(Sfx::Back));
        }
    }
    else if (r.fin >= 0 && r.fin < int(finished_.size()))
    {
        const Finished f = finished_[std::size_t(r.fin)];
        panel_ = 0;
        if (!a) { play(int(Sfx::Error)); return true; }
        open(f.id);
        if (f.state == 2)
        {
            /* The question it stopped on, asked now over its page (its download was kept). */
            finished_.erase(finished_.begin() + r.fin);
            if (f.job.clash)
            {
                clash_ = true;
                clash_t_ = 0;
                clash_job_ = f.job;
                clash_sel_ = 0;
            }
            else
            {
                moved_ = true;
                moved_t_ = 0;
                moved_job_ = f.job;
                moved_sel_ = 1;
            }
        }
    }
    return true;
}

void Store::draw_queue_panel(Gfx &gfx, float k)
{
    std::vector<std::string> done_ids;
    for (const Finished &f : finished_) done_ids.push_back(f.id);
    const JobState j = engine().job();
    const auto q = engine().queue();
    const auto rows = queue_rows(j, q, done_ids);
    const int n = int(rows.size());
    const int shown = std::min(n, 7);
    const float bw = 1040, pad = 48, row_h = 96;
    const float bh = pad + 44 + 12 + 28 + 26 + (n == 0 ? 60.f : row_h * float(shown) + 8) + pad;
    float bx, by;
    sheet_box(this, gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, "Downloads");
    y += 44 + 12;
    std::string sub;
    const int waiting = int(q.size());
    if (j.phase == Phase::Working && j.kind == "install")
    {
        const App *a = find(j.app_id);
        sub = (a ? a->name : j.app_id) + " is " + (j.step.rfind("Download", 0) == 0 ? "downloading" : "installing");
        if (waiting) sub += ", " + std::to_string(waiting) + " waiting";
        sub += ". One at a time.";
    }
    else if (waiting)
        sub = std::to_string(waiting) + " waiting. One at a time.";
    else
        sub = "Nothing downloading.";
    gfx.text_mid(Font::Regular, 19, bx + pad, y + 13, C(kSoft), Align::Left, sub);
    y += 28 + 26;
    if (n == 0)
    {
        gfx.text_mid(Font::Regular, 20, bx + pad, y + 24, C(kMuted), Align::Left, "Install, Update and Update all add apps here.");
        return;
    }
    if (panel_sel_ < panel_top_) panel_top_ = panel_sel_;
    if (panel_sel_ >= panel_top_ + shown) panel_top_ = panel_sel_ - shown + 1;
    for (int i = panel_top_; i < n && i < panel_top_ + shown; ++i)
    {
        const QRow &r = rows[std::size_t(i)];
        const App *a = find(r.id);
        const bool on = i == panel_sel_;
        const float ry = y + float(i - panel_top_) * row_h;
        if (on) gfx.panel(bx + pad - 16, ry, bw - pad * 2 + 32, row_h - 8, C(kFrost, 0.08f), 1.f, 20);
        ring(gfx, bx + pad - 16, ry, bw - pad * 2 + 32, row_h - 8, 20, on ? 1.f : 0.f, true);
        const float is = 64, ix = bx + pad, iy = ry + (row_h - 8 - is) / 2;
        if (a)
        {
            Art &t = art(*a);
            if (t.icon) gfx.image(t.icon, ix, iy, is, is, {}, 16);
            else draw_no_icon(gfx, *a, ix, iy, is, 16);
        }
        std::string line, button;
        Color lc = C(kSoft);
        float frac = -1;
        if (r.kind == 0)
        {
            if (j.total) frac = clamp01(float(double(j.done) / double(j.total)));
            line = j.step + (j.total && j.step.rfind("Download", 0) == 0 ? "  " + size_text(j.done) + " of " + size_text(j.total)
                             : frac >= 0 ? "  " + std::to_string(int(frac * 100)) + "%" : std::string("\xE2\x80\xA6"));
            lc = C(kCyan);
            button = "Cancel";
        }
        else if (r.kind == 1)
        {
            const int place = i + 1;
            line = "Waiting, " + ordinal(place) + " in line";
            if (const App *wa = a; wa && !inst_v(wa->id).empty()) line += "  \xC2\xB7  an update";
            button = "Remove";
        }
        else
        {
            const Finished &f = finished_[std::size_t(r.fin)];
            line = f.text;
            lc = f.state == 0 ? C(0x9FF0C6) : f.state == 2 ? C(kAmber) : C(0xFFB4A8);
            button = f.state == 2 ? "Answer" : "Open";
        }
        const float tx = ix + is + 22;
        const float btw = gfx.measure(Font::Bold, 18, button) + 40;
        const float room = bw - pad * 2 - (tx - ix) - btw - 24;
        gfx.text_mid(Font::ExtraBold, 22, tx, ry + (row_h - 8) / 2 - 14, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 22, room, a ? a->name : r.id));
        gfx.text_mid(Font::Regular, 17, tx, ry + (row_h - 8) / 2 + 16, lc, Align::Left, fit(gfx, Font::Regular, 17, room, line));
        if (frac >= 0)
        {
            const float px = tx, pw = room, py = ry + row_h - 8 - 12;
            gfx.panel(px, py, pw, 4, C(kFrost, 0.12f), 1.f, 2);
            gfx.panel(px, py, std::max(4.f, pw * frac), 4, C(kCyan), 1.f, 2);
        }
        const float bx2 = bx + bw - pad - btw, by2 = ry + (row_h - 8) / 2 - 22;
        const bool danger = button == "Cancel" || button == "Remove";
        gfx.panel(bx2, by2, btw, 44, on ? (danger ? C(0xE5484D, 0.85f) : C(kFrost)) : C(kFrost, 0.1f), 1.f, 15);
        gfx.text_mid(Font::Bold, 18, bx2 + btw / 2, by2 + 22, on ? (danger ? C(0xFFFFFF) : C(kMidnight)) : C(kFrost), Align::Center, button);
    }
    if (panel_top_ + shown < n)
        gfx.glyph(Glyph::Arrow, bx + bw / 2, by + bh - 26, 13, C(kFrost, 0.5f), 3.14159265f);
}

/* ---- Where to install ---------------------------------------------------------------------- */

void Store::start_install(const App &a, const std::string &version)
{
    places_t_ = -1;
    const auto places = install_places_now();
    if (places.size() < 2)
    {
        enqueue(a, version, ""); /* one place: straight in, as before */
        return;
    }
    place_app_ = a.id;
    place_version_ = version;
    open_panel(PanelPlace);
}

/* Room an app needs where it goes: a drive holds the download twice over while it unpacks; the
 * console's own storage the download, its unpacked files and what's taken out of it. */
static std::uint64_t room_needed(const App &a, const std::string &root)
{
    const std::uint64_t size = a.bytes ? a.bytes : size_of_text(a.size);
    if (!size) return 0;
    return root == kHomebrew ? size * 3 + (512ull << 20) : size * 2 + (64ull << 20);
}

bool Store::update_place_panel(float dt)
{
    const App *a = find(place_app_);
    const int n = int(place_list_.size());
    if (!a || n == 0) { panel_ = 0; return true; }
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    const int s = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n);
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && panel_sel_ == n))
    {
        panel_ = 0;
        play(int(Sfx::Back));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    const Place &p = place_list_[std::size_t(panel_sel_)];
    const std::uint64_t need = room_needed(*a, p.path);
    if (need && p.free && p.free < need)
    {
        toast("Not enough room on " + p.label);
        play(int(Sfx::Error));
        return true;
    }
    prefs_.last_root = p.path; /* picked first next time */
    save_prefs();
    panel_ = 0;
    enqueue(*a, place_version_, p.path);
    return true;
}

void Store::draw_place_panel(Gfx &gfx, float k)
{
    const App *a = find(place_app_);
    if (!a) return;
    const int n = int(place_list_.size());
    const float bw = 900, pad = 48, row_h = 92;
    const float bh = pad + 44 + 12 + 30 + 24 + row_h * float(n) + 12 + 64 + pad;
    float bx, by;
    sheet_box(this, gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 20, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 32, bw - pad * 2, "Install " + a->name + " to"));
    y += 44 + 12;
    const std::uint64_t size = a->bytes ? a->bytes : size_of_text(a->size);
    gfx.text_mid(Font::Regular, 19, bx + pad, y + 14, C(kSoft), Align::Left,
                 (size ? "The download is " + size_text(size) + ". " : std::string()) + "ShadowMountPlus finds it in any of these.");
    y += 30 + 24;
    for (int i = 0; i < n; ++i)
    {
        const Place &p = place_list_[std::size_t(i)];
        const bool on = i == panel_sel_;
        const float ry = y + float(i) * row_h;
        const std::uint64_t need = room_needed(*a, p.path);
        const bool tight = need && p.free && p.free < need;
        gfx.panel(bx + pad, ry, bw - pad * 2, row_h - 12, on ? C(kFrost, tight ? 0.10f : 0.14f) : C(kFrost, 0.05f), 1.f, 20);
        ring(gfx, bx + pad, ry, bw - pad * 2, row_h - 12, 20, on ? 1.f : 0.f, true);
        const float tx = bx + pad + 26;
        gfx.text_mid(Font::Bold, 22, tx, ry + 28, tight ? C(kMuted) : C(kFrost), Align::Left, p.label);
        if (p.path == engine().install_root())
        {
            const float lw = gfx.measure(Font::Bold, 22, p.label);
            gfx.text_mid(Font::SemiBold, 15, tx + lw + 14, ry + 29, C(kCyan), Align::Left, "Settings' choice");
        }
        std::string free_line = p.total ? space_text(p.free) + " free of " + space_text(p.total) : p.free ? space_text(p.free) + " free" : "Free space unknown";
        if (tight) free_line += "  \xC2\xB7  not enough room";
        gfx.text_mid(Font::Regular, 17, tx, ry + 58, tight ? C(0xFFB4A8) : C(kSoft), Align::Left, free_line);
        if (p.total)
        {
            /* how full it is */
            const float bw2 = 260, bx2 = bx + bw - pad - 26 - bw2, by2 = ry + (row_h - 12) / 2 - 4;
            const float used = clamp01(1.f - float(double(p.free) / double(p.total)));
            gfx.panel(bx2, by2, bw2, 8, C(kFrost, 0.12f), 1.f, 4);
            gfx.panel(bx2, by2, std::max(8.f, bw2 * used), 8, used > 0.9f ? C(0xFF8A80) : C(kCyan), 1.f, 4);
        }
    }
    y += row_h * float(n) + 12;
    const bool on = panel_sel_ == n;
    const float cw = 220;
    gfx.panel(bx + pad, y, cw, 64, on ? C(kFrost) : C(kFrost, 0.12f), 1.f, 20);
    ring(gfx, bx + pad, y, cw, 64, 20, on ? 1.f : 0.f, true);
    gfx.text_mid(Font::Bold, 22, bx + pad + cw / 2, y + 32, on ? C(kMidnight) : C(kFrost), Align::Center, "Cancel");
}

/* ---- Storage ------------------------------------------------------------------------------- */

void Store::cache_look(bool now)
{
    /* (2.2.3) As the engine's own thread last saw it: never a wait on the disk here. */
    cache_now_ = engine().cache_seen(&cache_files_, now);
    cache_t_ = time_;
}

void Store::clear_cache(std::string &note, bool &bad)
{
    std::uint64_t freed = 0;
    std::size_t files = 0;
    std::string why;
    const bool ok = engine().clear_downloads(freed, files, why);
    if (files)
        note = "Cleared " + size_text(freed) + " from the download cache." + (why.empty() ? std::string() : " " + why);
    else
        note = why.empty() ? std::string("The download cache is empty already.") : why;
    bad = !ok;
    cache_look(true);
    play(int(ok ? Sfx::Complete : Sfx::Error));
}

void Store::draw_drive_bars(Gfx &gfx, float x, float y0, float w, bool compact)
{
    /* Each drive: a bar of apps, the download cache and everything else, and how much is free. */
    const auto drives = engine().drives();
    float y = y0;
    if (drives.empty())
    {
        gfx.text_mid(Font::Regular, 19, x, y + 14, C(kMuted), Align::Left, "Looking at your drives\xE2\x80\xA6");
        return;
    }
    for (const DriveSpace &d : drives)
    {
        if (!d.total) continue;
        std::uint64_t apps = 0;
        for (const ConsoleApp &c : console_)
            if (c.root == d.homebrew && c.size > 0) apps += std::uint64_t(c.size);
        const std::uint64_t cache = d.homebrew == kHomebrew ? cache_now_ : 0;
        const std::uint64_t used = d.total > d.free ? d.total - d.free : 0;
        const std::uint64_t other = used > apps + cache ? used - apps - cache : 0;
        gfx.text_mid(Font::Bold, compact ? 19.f : 21.f, x, y + 12, C(kFrost), Align::Left, d.label);
        gfx.text_mid(Font::Regular, compact ? 16.f : 18.f, x + w, y + 12, C(kSoft), Align::Right,
                     space_text(d.free) + " free of " + space_text(d.total));
        const float by = y + (compact ? 30 : 34), bh = compact ? 12.f : 16.f;
        gfx.panel(x, by, w, bh, C(kFrost, 0.10f), 1.f, bh / 2);
        float sx = x;
        const auto seg = [&](std::uint64_t b, Color c) {
            const float sw = w * float(double(b) / double(d.total));
            if (sw < 0.5f) return;
            gfx.push_clip(sx, by, std::min(sw, x + w - sx), bh);
            gfx.panel(x, by, w, bh, c, 1.f, bh / 2);
            gfx.pop_clip();
            sx += sw;
        };
        seg(apps, C(kCyan));
        seg(cache, C(kAmber));
        seg(other, C(0x7C8AA0));
        y += compact ? 64 : 76;
    }
    {
        /* what the colours are */
        float lx = x;
        const std::pair<const char *, std::uint32_t> keys[4] = {{"Apps", kCyan}, {"Download cache", kAmber}, {"Everything else", 0x7C8AA0}, {"Free", 0x2A3549}};
        for (const auto &kv : keys)
        {
            gfx.panel(lx, y + 4, 14, 14, C(kv.second), 1.f, 4, kv.second == 0x2A3549 ? kRim : Color{}, 1.f);
            gfx.text_mid(Font::Regular, compact ? 16.f : 17.f, lx + 22, y + 11, C(kSoft), Align::Left, kv.first);
            lx += 22 + gfx.measure(Font::Regular, compact ? 16.f : 17.f, kv.first) + 32;
        }
    }
}

bool Store::update_storage_panel(float dt)
{
    sync_console();
    cache_look(false);
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const int s = std::clamp(panel_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, 1);
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && panel_sel_ == 1))
    {
        panel_ = 0;
        play(int(Sfx::Back));
        return true;
    }
    if (pressed(pad::BtnCross))
    {
        clear_cache(lib_note_, lib_note_bad_);
        toast(lib_note_);
    }
    return true;
}

void Store::draw_storage_panel(Gfx &gfx, float k)
{
    const auto drives = engine().drives();
    std::vector<const ConsoleApp *> big;
    for (const ConsoleApp &c : console_)
        if (c.size > 0) big.push_back(&c);
    std::stable_sort(big.begin(), big.end(), [](const ConsoleApp *a, const ConsoleApp *b) { return a->size > b->size; });
    if (big.size() > 6) big.resize(6);
    int nd = 0;
    for (const DriveSpace &d : drives) nd += d.total ? 1 : 0;
    const float bw = 1100, pad = 48;
    const float bh = pad + 44 + 20 + float(std::max(1, nd)) * 76 + 30 + 24 + 30 + 14 + 44 * float(std::max<std::size_t>(1, big.size())) + 24 + 64 + pad;
    float bx, by;
    sheet_box(this, gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, "Storage");
    y += 44 + 20;
    draw_drive_bars(gfx, bx + pad, y, bw - pad * 2, false);
    y += float(std::max(1, nd)) * 76 + 30 + 24;
    gfx.text_mid(Font::Bold, 15, bx + pad, y + 10, C(kCyan), Align::Left, "THE BIGGEST FOLDERS", 2);
    y += 30 + 14;
    if (big.empty())
        gfx.text_mid(Font::Regular, 18, bx + pad, y + 18, C(kMuted), Align::Left, console_looking_ ? "Working out the sizes\xE2\x80\xA6" : "Nothing yet.");
    for (const ConsoleApp *c : big)
    {
        gfx.panel(bx + pad, y, bw - pad * 2, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::SemiBold, 19, bx + pad + 4, y + 22, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 19, 520, c->name));
        gfx.text_mid(Font::Regular, 17, bx + pad + 560, y + 22, C(kMuted), Align::Left, c->place);
        gfx.text_mid(Font::SemiBold, 19, bx + bw - pad - 4, y + 22, C(kFrost), Align::Right, size_text(std::uint64_t(c->size)));
        y += 44;
    }
    if (big.empty()) y += 44;
    y += 24;
    const std::string clear = cache_now_ ? "Clear download cache  \xC2\xB7  " + size_text(cache_now_) : std::string("Download cache is empty");
    const float w1 = gfx.measure(Font::Bold, 21, clear) + 60, w2 = 180;
    for (int i = 0; i < 2; ++i)
    {
        const float x = i == 0 ? bx + pad : bx + pad + w1 + 16, w = i == 0 ? w1 : w2;
        const bool on = panel_sel_ == i;
        gfx.panel(x, y, w, 64, on ? C(kFrost) : C(kFrost, 0.12f), 1.f, 20);
        ring(gfx, x, y, w, 64, 20, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 21, x + w / 2, y + 32, on ? C(kMidnight) : C(kFrost), Align::Center, i == 0 ? clear : std::string("Close"));
    }
}

/* ---- What's new ---------------------------------------------------------------------------- */

void Store::check_news()
{
    if (news_checked_ || !booted_ || catalog_.apps.empty() || engine().loading() || modal_open() || opening_ || store_updating_ ||
        page_open_ || enter_t_ < 1)
        return;
    news_checked_ = true;
    const std::string v = catalog_.whats_new_version;
    if (v.empty()) return;
    const bool due = whats_new_due(kVersion, v, prefs_.seen_news, fresh_);
    if (!due && !(fresh_ && whats_new_due(kVersion, v, prefs_.seen_news, false))) return;
    prefs_.seen_news.push_back(v); /* once */
    if (prefs_.seen_news.size() > 32) prefs_.seen_news.erase(prefs_.seen_news.begin());
    save_prefs();
    if (due) open_panel(PanelNews);
}

std::vector<NewsPiece> Store::news_now(std::string &title, int &state) const
{
    /* The site's notes for this version, else this version's release on GitHub. */
    if (!catalog_.whats_new_version.empty() && whats_new_due(kVersion, catalog_.whats_new_version, {}, false))
    {
        title = catalog_.whats_new_title.empty() ? std::string("What's new in ") + kVersion : catalog_.whats_new_title;
        state = 1;
        return news_pieces(catalog_.whats_new_body);
    }
    title = std::string("What's new in ") + kVersion;
    std::vector<Release> rel;
    state = engine().notes(kStoreRepo, rel);
    if (state <= 0) return {};
    for (const Release &r : rel)
    {
        if (!whats_new_due(kVersion, r.tag, {}, false)) continue;
        /* GitHub's Markdown, made plain: headings are lines, "* " is a bullet as "- " is. */
        std::string body;
        std::size_t at = 0;
        while (at <= r.body.size())
        {
            std::size_t end = r.body.find('\n', at);
            if (end == std::string::npos) end = r.body.size();
            std::string line = r.body.substr(at, end - at);
            at = end + 1;
            if (!line.empty() && line.back() == '\r') line.pop_back();
            std::size_t lead = 0;
            while (lead < line.size() && line[lead] == ' ') ++lead;
            line.erase(0, lead);
            if (line.rfind("* ", 0) == 0) line = "- " + line.substr(2);
            while (!line.empty() && line[0] == '#') line.erase(0, 1);
            if (!line.empty() && line[0] == ' ' && line.rfind("- ", 0) != 0) line.erase(0, 1);
            body += (line.rfind("- ", 0) == 0 ? "- " + md_inline(line.substr(2)) : md_inline(line)) + "\n";
            if (end == r.body.size()) break;
        }
        state = 1;
        return news_pieces(body);
    }
    state = -1;
    return {};
}

bool Store::update_news_panel(float dt)
{
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    panel_top_ = std::max(0, panel_top_ + (d ? 3 : 0) - (u ? 3 : 0));
    if (pressed(pad::BtnCircle) || pressed(pad::BtnCross))
    {
        panel_ = 0;
        play(int(Sfx::Back));
    }
    return true;
}

void Store::draw_news_panel(Gfx &gfx, float k)
{
    std::string title;
    int state = 0;
    const auto pieces = news_now(title, state);
    const float bw = 1000, pad = 56, iw = bw - pad * 2;
    /* The words laid out first: each line, and whether it's a bullet's first line. */
    struct L
    {
        std::string text;
        int kind; /* 0 text, 1 a bullet's first line, 2 a bullet's next line, 3 a gap */
    };
    std::vector<L> lines;
    for (const NewsPiece &p : pieces)
    {
        if (p.kind == 2) { lines.push_back({"", 3}); continue; }
        const auto w = wrap(gfx, Font::Regular, 21, p.kind == 1 ? iw - 30 : iw, p.text, 12);
        for (std::size_t i = 0; i < w.size(); ++i) lines.push_back({w[i], p.kind == 1 ? (i ? 2 : 1) : 0});
    }
    const float lh = 32, gap = 14;
    const int most = 18;
    const int nlines = int(lines.size());
    panel_top_ = std::clamp(panel_top_, 0, std::max(0, nlines - most));
    float body_h = 0;
    for (int i = panel_top_; i < nlines && i < panel_top_ + most; ++i) body_h += lines[std::size_t(i)].kind == 3 ? gap : lh;
    if (lines.empty()) body_h = lh;
    const float bh = pad + 46 + 16 + 26 + 24 + body_h + 34 + 64 + pad;
    float bx, by;
    sheet_box(this, gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 36, bx + pad, y + 22, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 36, iw, title));
    y += 46 + 16;
    gfx.text_mid(Font::Bold, 15, bx + pad, y + 10, C(kCyan), Align::Left,
                 std::string("RECOMP STORE ") + kVersion + " \xC2\xB7 BUILD " + std::to_string(kBuild), 2);
    y += 26 + 24;
    if (lines.empty())
        gfx.text_mid(Font::Regular, 21, bx + pad, y + 15, C(kMuted), Align::Left,
                     state == 0 ? "Getting the notes from GitHub\xE2\x80\xA6" : "No notes for this version yet.");
    for (int i = panel_top_; i < nlines && i < panel_top_ + most; ++i)
    {
        const L &l = lines[std::size_t(i)];
        if (l.kind == 3) { y += gap; continue; }
        if (l.kind == 1) gfx.panel(bx + pad + 6, y + 15 - 4, 8, 8, C(kCyan), 1.f, 4);
        gfx.text_mid(Font::Regular, 21, bx + pad + (l.kind ? 30.f : 0.f), y + 15, C(kBody), Align::Left, l.text);
        y += lh;
    }
    if (panel_top_ + most < nlines) gfx.glyph(Glyph::Arrow, bx + bw / 2, y + 12, 13, C(kFrost, 0.5f), 3.14159265f);
    y += 34;
    gfx.panel(bx + pad, y, 200, 64, C(kFrost), 1.f, 20);
    ring(gfx, bx + pad, y, 200, 64, 20, 1.f, true);
    gfx.text_mid(Font::Bold, 22, bx + pad + 100, y + 32, C(kMidnight), Align::Center, "Got it");
}

/* ---- Tabs ---------------------------------------------------------------------------------- */

std::vector<std::string> Store::shown_tab_entries() const
{
    std::vector<std::string> out;
    if (prefs_.tabs_custom) return prefs_.tabs;
    /* (2.2.3) None by default: Library has every part; the catalog may suggest some. */
    out = catalog_.tabs_set ? catalog_.tabs : std::vector<std::string>{};
    for (const std::string &v : prefs_.source_tabs) out.push_back("source:" + v);
    return out;
}

std::string Store::tab_entry_name(const std::string &e) const
{
    if (e == "games") return "Games";
    if (e == "emulators") return "Emulators";
    if (e == "apps") return "Apps";
    if (e == "tools") return "Tools";
    const std::string v = e.rfind("source:", 0) == 0 ? e.substr(7) : e;
    for (const Source &s : engine().sources())
        if (s.value == v) return s.name.empty() ? v : s.name;
    return v;
}

bool Store::source_pinned(const std::string &value) const
{
    const auto e = shown_tab_entries();
    return std::find(e.begin(), e.end(), "source:" + value) != e.end();
}

void Store::pin_source(const std::string &value, bool on)
{
    const std::string e = "source:" + value;
    if (prefs_.tabs_custom)
    {
        prefs_.tabs.erase(std::remove(prefs_.tabs.begin(), prefs_.tabs.end(), e), prefs_.tabs.end());
        if (on) prefs_.tabs.push_back(e);
    }
    else
    {
        prefs_.source_tabs.erase(std::remove(prefs_.source_tabs.begin(), prefs_.source_tabs.end(), value), prefs_.source_tabs.end());
        if (on) prefs_.source_tabs.push_back(value);
    }
    save_prefs();
}

void Store::build_tab_rows()
{
    tab_rows_.clear();
    for (const std::string &e : shown_tab_entries()) tab_rows_.push_back({e, true});
    for (const char *c : {"games", "emulators", "apps", "tools"})
    {
        bool there = false;
        for (const TabRow &r : tab_rows_) there = there || r.entry == c;
        if (!there) tab_rows_.push_back({c, false});
    }
}

void Store::apply_tab_rows()
{
    prefs_.tabs_custom = true;
    prefs_.tabs.clear();
    for (const TabRow &r : tab_rows_)
        if (r.on) prefs_.tabs.push_back(r.entry);
    prefs_.source_tabs.clear();
    save_prefs();
}

bool Store::update_tabs_panel(float dt)
{
    const int n = int(tab_rows_.size()); /* and Back to the default after them */
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (pressed(pad::BtnCircle))
    {
        panel_ = 0;
        play(int(Sfx::Back));
        return true;
    }
    const bool moving = (held_ & pad::BtnSquare) != 0 && panel_sel_ < n;
    if (moving && (u || d))
    {
        /* Square held: the row moves with the focus. */
        const int to = panel_sel_ + (d ? 1 : -1);
        if (to >= 0 && to < n)
        {
            std::swap(tab_rows_[std::size_t(panel_sel_)], tab_rows_[std::size_t(to)]);
            panel_sel_ = to;
            apply_tab_rows();
            play(int(Sfx::Move));
        }
        return true;
    }
    const int s = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n);
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    if (!pressed(pad::BtnCross)) return true;
    if (panel_sel_ == n)
    {
        /* Back to the default: the catalog's tabs; pinned sources stay. */
        std::vector<std::string> keep;
        for (const TabRow &r : tab_rows_)
            if (r.on && r.entry.rfind("source:", 0) == 0) keep.push_back(r.entry.substr(7));
        prefs_.tabs_custom = false;
        prefs_.tabs.clear();
        prefs_.source_tabs = keep;
        save_prefs();
        build_tab_rows();
        toast("Tabs are back to the default");
        play(int(Sfx::Select));
        return true;
    }
    TabRow &r = tab_rows_[std::size_t(panel_sel_)];
    r.on = !r.on;
    apply_tab_rows();
    play(int(Sfx::Select));
    return true;
}

void Store::draw_tabs_panel(Gfx &gfx, float k)
{
    const int n = int(tab_rows_.size());
    const float bw = 760, pad = 48, row_h = 70;
    const float bh = pad + 44 + 12 + 56 + 20 + row_h * float(n + 1) + pad;
    float bx, by;
    sheet_box(this, gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, "Tabs");
    y += 44 + 12;
    draw_lines(gfx, Font::Regular, 19, bx + pad, y, 28, C(kSoft),
               {"Discover, Library and Settings always stay.", "To move a tab, hold Square and press Up or Down."});
    y += 56 + 20;
    const bool moving = (held_ & pad::BtnSquare) != 0;
    for (int i = 0; i <= n; ++i)
    {
        const bool on = i == panel_sel_;
        const float ry = y + float(i) * row_h;
        if (on) gfx.panel(bx + pad - 14, ry, bw - pad * 2 + 28, row_h - 8, C(kFrost, moving && i < n ? 0.16f : 0.08f), 1.f, 18);
        ring(gfx, bx + pad - 14, ry, bw - pad * 2 + 28, row_h - 8, 18, on ? 1.f : 0.f, true);
        const float cy = ry + (row_h - 8) / 2;
        if (i == n)
        {
            gfx.text_mid(Font::SemiBold, 21, bx + pad + 4, cy, C(prefs_.tabs_custom ? kCyan : kMuted), Align::Left, "Back to the default");
            continue;
        }
        const TabRow &r = tab_rows_[std::size_t(i)];
        const bool src = r.entry.rfind("source:", 0) == 0;
        gfx.text_mid(Font::SemiBold, 21, bx + pad + 4, cy, r.on ? C(kFrost) : C(kMuted), Align::Left,
                     fit(gfx, Font::SemiBold, 21, bw - pad * 2 - 200, tab_entry_name(r.entry)));
        if (src)
        {
            const float nw = gfx.measure(Font::SemiBold, 21, fit(gfx, Font::SemiBold, 21, bw - pad * 2 - 200, tab_entry_name(r.entry)));
            gfx.text_mid(Font::SemiBold, 15, bx + pad + 4 + nw + 12, cy + 1, C(kAmber), Align::Left, "Source");
        }
        if (on && moving)
        {
            gfx.glyph(Glyph::Arrow, bx + bw - pad - 110, cy - 8, 10, C(kFrost, 0.8f), 0);
            gfx.glyph(Glyph::Arrow, bx + bw - pad - 110, cy + 8, 10, C(kFrost, 0.8f), 3.14159265f);
        }
        /* on or off, as Settings' switches */
        const float sx = bx + bw - pad - 74, sy = cy - 21;
        gfx.panel(sx, sy, 74, 42, r.on ? C(kCyan) : C(kFrost, 0.22f), 1.f, 21);
        gfx.panel(r.on ? sx + 36 : sx + 4, sy + 4, 34, 34, C(0xFFFFFF), 1.f, 17);
    }
}

/* ---- On this PS5's row, My Apps' row ----------------------------------------------------- */

std::vector<std::string> Store::my_chips() const
{
    return {std::string(prefs_.my_filter == 0 ? "\x01" : "") + "All", std::string(prefs_.my_filter == 1 ? "\x01" : "") + "Favorites",
            std::string(prefs_.lib_view == 0 ? "\x01" : "") + "Grid", std::string(prefs_.lib_view == 1 ? "\x01" : "") + "List"};
}

void Store::draw_chip_row(Gfx &gfx, const std::vector<std::string> &chips, int focused, bool on, float y, const std::string &right)
{
    float x = 96;
    const float h = 46;
    for (int i = 0; i < int(chips.size()); ++i)
    {
        std::string label = chips[std::size_t(i)];
        const bool active = !label.empty() && label[0] == '\x01', danger = !label.empty() && label[0] == '\x02';
        if (active || danger) label.erase(0, 1);
        const float w = gfx.measure(active ? Font::Bold : Font::SemiBold, 18, label) + 40;
        if (active) gfx.panel(x, y, w, h, C(kFrost), 0.92f, h / 2);
        else if (danger) gfx.panel(x, y, w, h, C(0xE5484D, on && i == focused ? 0.9f : 0.22f), 1.f, h / 2, C(0xE5484D, 0.6f), 1.f);
        else glass(gfx, x, y, w, h, h / 2, kGlass, false);
        if (on && i == focused) ring(gfx, x, y, w, h, h / 2, 1.f, true);
        gfx.text_mid(active ? Font::Bold : Font::SemiBold, 18, x + w / 2, y + h / 2,
                     active ? C(kMidnight) : danger ? C(0xFFFFFF) : C(kFrost), Align::Center, label);
        x += w + (i == 1 && chips.size() > 3 && label != "Select all" ? 22.f : 10.f); /* a little room after the views */
    }
    if (!right.empty())
    {
        std::string t = right;
        Color c = C(kMuted);
        float dot = 0;
        if (t[0] == '\x03' || t[0] == '\x04')
        {
            c = t[0] == '\x03' ? C(0x9FF0C6) : C(0xFFB4A8);
            dot = 1;
            t.erase(0, 1);
        }
        const float room = 1824 - x - 24;
        t = fit(gfx, Font::SemiBold, 17, std::max(60.f, room - 22), t);
        const float tw = gfx.measure(Font::SemiBold, 17, t);
        gfx.text_mid(Font::SemiBold, 17, 1824, y + h / 2, c, Align::Right, t);
        if (dot > 0) gfx.panel(1824 - tw - 22, y + h / 2 - 6, 12, 12, Color{c.r, c.g, c.b, 1}, 1.f, 6);
    }
}

bool Store::update_delete_panel(float dt)
{
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const int s = std::clamp(panel_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, 1);
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && panel_sel_ == 0))
    {
        panel_ = 0;
        play(int(Sfx::Back));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    panel_ = 0;
    /* Never the store: it can't be marked, and the engine refuses it again. */
    std::vector<std::string> folders;
    for (const std::string &f : con_marked_)
        for (const ConsoleApp &c : console_)
            if (c.folder == f && !c.store) folders.push_back(f);
    if (folders.empty() || !engine().manage_many(folders))
    {
        lib_note_ = "The store is busy. Try again in a moment.";
        lib_note_bad_ = true;
        play(int(Sfx::Error));
        return true;
    }
    lib_note_ = "Deleting " + (folders.size() == 1 ? std::string("1 app") : std::to_string(folders.size()) + " apps") + "\xE2\x80\xA6";
    lib_note_bad_ = false;
    mng_job_ = true;
    play(int(Sfx::Install));
    return true;
}

void Store::draw_delete_panel(Gfx &gfx, float k)
{
    std::vector<const ConsoleApp *> picked;
    long long all = 0;
    bool sized = true, saves = false;
    for (const std::string &f : con_marked_)
        for (const ConsoleApp &c : console_)
            if (c.folder == f && !c.store)
            {
                picked.push_back(&c);
                if (c.size >= 0) all += c.size;
                else sized = false;
                if (!c.store_id.empty()) saves = true;
            }
    const int shown = std::min(int(picked.size()), 8);
    const float bw = 900, pad = 48, iw = bw - pad * 2;
    const std::string body = std::string("Everything in their folders goes, saves kept there too. Their tiles come off your home screen once the store closes.") +
                             (saves ? " Apps from Recomp Store go as from their page." : "");
    const auto lines = wrap(gfx, Font::Regular, 20, iw, body, 4);
    const float bh = pad + 44 + 14 + 30 + 18 + 40 * float(shown) + (int(picked.size()) > shown ? 34.f : 0.f) + 18 + 30 * float(lines.size()) + 34 + 64 + pad;
    float bx, by;
    sheet_box(this, gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    const std::string title = "Delete " + (picked.size() == 1 ? std::string("1 app") : std::to_string(picked.size()) + " apps") + "?";
    gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 20, C(kFrost), Align::Left, title);
    y += 44 + 14;
    gfx.text_mid(Font::SemiBold, 20, bx + pad, y + 15, C(kCyan), Align::Left, (sized ? "" : "At least ") + size_text(std::uint64_t(all)) + " in all");
    y += 30 + 18;
    for (int i = 0; i < shown; ++i)
    {
        const ConsoleApp &c = *picked[std::size_t(i)];
        gfx.panel(bx + pad, y, iw, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::SemiBold, 19, bx + pad + 4, y + 20, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 19, iw * 0.55f, c.name));
        gfx.text_mid(Font::Regular, 17, bx + pad + iw * 0.6f, y + 20, C(kMuted), Align::Left, c.place);
        gfx.text_mid(Font::SemiBold, 19, bx + pad + iw - 4, y + 20, C(kFrost), Align::Right, c.size >= 0 ? size_text(std::uint64_t(c.size)) : std::string("\xE2\x80\xA6"));
        y += 40;
    }
    if (int(picked.size()) > shown)
    {
        gfx.text_mid(Font::Regular, 18, bx + pad + 4, y + 16, C(kMuted), Align::Left, "and " + std::to_string(picked.size() - std::size_t(shown)) + " more");
        y += 34;
    }
    y += 18;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines);
    y += 34;
    const float gap = 16, w = (iw - gap) / 2;
    for (int i = 0; i < 2; ++i)
    {
        const float x = bx + pad + float(i) * (w + gap);
        const bool on = panel_sel_ == i;
        const Color bg = i == 1 ? C(0xE5484D, on ? 0.9f : 0.16f) : C(kFrost, on ? 1.f : 0.12f);
        gfx.panel(x, y, w, 64, bg, 1.f, 20);
        ring(gfx, x, y, w, 64, 20, on ? 1.f : 0.f, true);
        const Color fg = i == 1 ? (on ? C(0xFFFFFF) : C(0xFF8A80)) : on ? C(kMidnight) : C(kFrost);
        gfx.text_mid(Font::Bold, 22, x + w / 2, y + 32, fg, Align::Center, i == 0 ? std::string("Cancel") : "Delete " + std::to_string(picked.size()));
    }
}

/* ---- Settings > ShadowMountPlus ------------------------------------------------------------ */

std::string Store::smp_warning() const
{
    std::string ver;
    const int st = engine().smp_state(ver);
    if (st == 0)
        return smp_path().empty() ? "ShadowMountPlus isn't answering, and no file is chosen. Get it below."
                                  : "ShadowMountPlus isn't answering. Reload starts your file.";
    if (st == 1 && !catalog_.smp_min.empty() && smp_version_cmp(ver, catalog_.smp_min) == -1)
        return "Your ShadowMountPlus (" + ver + ") is older than " + catalog_.smp_min + ", which the store needs. Get a newer one below.";
    return "";
}

std::vector<std::string> Store::smp_rows() const
{
    std::vector<std::string> r;
    if (!catalog_.smp_url.empty()) r.push_back("get");
    r.push_back("file");
    r.push_back("reload");
    r.push_back("refresh");
    return r;
}

bool Store::update_smp_section(float dt, bool u, bool d)
{
    (void)dt;
    const auto rows = smp_rows();
    const int s = std::clamp(smp_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, int(rows.size()) - 1);
    if (s != smp_row_) { smp_row_ = s; play(int(Sfx::Move)); }
    smp_row_ = std::clamp(smp_row_, 0, int(rows.size()) - 1);
    if (!pressed(pad::BtnCross)) return true;
    const std::string &row = rows[std::size_t(smp_row_)];
    if (row == "get")
    {
        if (!freed_ || engine().job().phase == Phase::Working || !engine().get_smp())
        {
            settings_note_ = !freed_ ? "Recomp Store can't write to /data. Start etaHEN, then open the store again."
                                     : "Wait for the current job to finish first.";
            settings_note_bad_ = true;
            play(int(Sfx::Error));
            return true;
        }
        settings_note_ = "Getting ShadowMountPlus " + catalog_.smp_version + " from GitHub\xE2\x80\xA6";
        settings_note_bad_ = false;
        play(int(Sfx::Install));
    }
    else if (row == "file")
        open_smp_picker();
    else if (row == "reload")
        start_reload_smp(false);
    else
        start_refresh_home(false);
    return true;
}

void Store::draw_smp_section(Gfx &gfx, float cx, float top, float cw, float bottom)
{
    gfx.text_mid(Font::ExtraBold, 38, cx, top + 48 + 24, C(kFrost), Align::Left, "ShadowMountPlus");
    float y = top + 48 + 48 + 22;
    /* What's running, and what the store offers. */
    {
        std::string ver;
        const int st = engine().smp_state(ver);
        const std::string warn = smp_warning();
        const float ch = 104 + (warn.empty() ? 0.f : 44.f);
        glass(gfx, cx, y, cw, ch, 24, warn.empty() ? kGlass : C(kAmber, 0.10f), false);
        const float dx = cx + 30, dy = y + 36;
        gfx.panel(dx, dy - 7, 14, 14, !warn.empty() && st == 1 ? C(kAmber) : st == 1 ? C(kGreen) : st == 0 ? C(0xFF8A80) : C(kMuted), 1.f, 7);
        const std::string running = st == 1 ? "Running" + (ver.empty() ? std::string() : " " + ver) : st == 0 ? std::string("Not answering")
                                                                                                               : std::string("Looking\xE2\x80\xA6");
        gfx.text_mid(Font::Bold, 23, dx + 26, dy, C(kFrost), Align::Left, running);
        std::string offer = catalog_.smp_url.empty() ? std::string("Recomp Store doesn't offer a download right now.")
                                                     : "Recomp Store offers " + catalog_.smp_version + ", from drakmor's GitHub releases.";
        if (!catalog_.smp_url.empty() && st == 1 && smp_version_cmp(ver, catalog_.smp_version) == 0) offer = "That's the one Recomp Store offers.";
        gfx.text_mid(Font::Regular, 19, dx + 26, dy + 36, C(kSoft), Align::Left, fit(gfx, Font::Regular, 19, cw - 80, offer));
        if (!warn.empty())
            gfx.text_mid(Font::SemiBold, 19, dx, dy + 80, C(0xFFD9A0), Align::Left, fit(gfx, Font::SemiBold, 19, cw - 60, warn));
        y += ch + 22;
    }
    const auto rows = smp_rows();
    const JobState job = engine().job();
    for (int i = 0; i < int(rows.size()); ++i)
    {
        const std::string &r = rows[std::size_t(i)];
        std::string title, help, value;
        Color vc = C(kFrost);
        if (r == "get")
        {
            title = "Get ShadowMountPlus " + catalog_.smp_version;
            help = "Downloaded, checked and chosen as your file, then loaded: the store closes for a moment.";
            const bool going = job.phase == Phase::Working && job.kind == "smpget";
            value = going ? (job.total ? std::to_string(int(100.0 * double(job.done) / double(job.total))) + "%" : job.step + "\xE2\x80\xA6") : "Get";
            vc = going ? C(kMuted) : C(kCyan);
        }
        else if (r == "file")
        {
            const std::string smp = smp_path();
            title = "Your ShadowMountPlus file";
            help = "What Reload and Refresh home screen start when it isn't running.";
            value = smp.empty() ? std::string("Choose its file\xE2\x80\xA6") : fit(gfx, Font::Regular, 19, 380, base_name(smp));
            if (smp.empty()) vc = C(kCyan);
        }
        else if (r == "reload")
        {
            title = "Reload ShadowMountPlus";
            help = "Sends your file again. The store closes for a moment while it restarts.";
            value = opening_ ? "Closing\xE2\x80\xA6" : "Reload";
        }
        else
        {
            title = "Refresh home screen";
            help = "The store closes for a moment while ShadowMountPlus adds new apps, then opens again.";
            value = opening_ ? "Closing\xE2\x80\xA6" : "Refresh";
        }
        gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
        if (in_section_ && smp_row_ == i) ring(gfx, cx - 12, y + 6, cw + 24, 82, 18, 1.f, true);
        gfx.text_mid(Font::SemiBold, 22, cx + 8, y + 20 + 13, C(kFrost), Align::Left, title);
        gfx.text_mid(Font::Regular, 17, cx + 8, y + 20 + 26 + 6 + 10, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, cw - 420, help));
        gfx.text_mid(Font::SemiBold, 19, cx + cw - 8, y + 47, vc, Align::Right, value);
        y += 94;
    }
    y += 10;
    if (!settings_note_.empty() && y + 30 < bottom)
    {
        const auto l = wrap(gfx, Font::SemiBold, 18, cw - 16, settings_note_, 2);
        y += draw_lines(gfx, Font::SemiBold, 18, cx + 8, y, 27, settings_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), l) + 12;
    }
    if (y + 30 < bottom)
        draw_lines(gfx, Font::Regular, 17, cx + 8, y, 25, C(kDim),
                   wrap(gfx, Font::Regular, 17, cw - 16,
                        "If your jailbreak loads ShadowMountPlus at boot, update that copy too.", 2));
}

/* ---- Settings > Storage -------------------------------------------------------------------- */

bool Store::update_storage_section(float dt, bool u, bool d)
{
    (void)dt;
    cache_look(false);
    sync_console();
    const int s = std::clamp(sto_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, 1);
    if (s != sto_row_) { sto_row_ = s; play(int(Sfx::Move)); }
    if (!pressed(pad::BtnCross)) return true;
    if (sto_row_ == 0) clear_cache(settings_note_, settings_note_bad_);
    else open_panel(PanelStorage);
    return true;
}

void Store::draw_storage_section(Gfx &gfx, float cx, float top, float cw, float bottom)
{
    if (!console_asked_) sync_console(); /* the folders' sizes, for the bars */
    cache_look(false);
    gfx.text_mid(Font::ExtraBold, 38, cx, top + 48 + 24, C(kFrost), Align::Left, "Storage");
    float y = top + 48 + 48 + 22;
    const struct
    {
        const char *title, *help;
        std::string value;
    } rows[2] = {
        {"Clear download cache", "Downloads the store keeps while it installs. What an install or the queue is using stays.",
         cache_now_ ? size_text(cache_now_) + (cache_files_ == 1 ? " in 1 file" : " in " + std::to_string(cache_files_) + " files") : std::string("Empty")},
        {"See what's using space", "Each drive, the apps on it, and the biggest folders.", "Open"},
    };
    for (int i = 0; i < 2; ++i)
    {
        gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
        if (in_section_ && sto_row_ == i) ring(gfx, cx - 12, y + 6, cw + 24, 82, 18, 1.f, true);
        gfx.text_mid(Font::SemiBold, 22, cx + 8, y + 20 + 13, C(kFrost), Align::Left, rows[i].title);
        gfx.text_mid(Font::Regular, 17, cx + 8, y + 20 + 26 + 6 + 10, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, cw - 420, rows[i].help));
        gfx.text_mid(Font::SemiBold, 19, cx + cw - 8, y + 47, i == 0 && cache_now_ ? C(kAmber) : C(kFrost), Align::Right, rows[i].value);
        y += 94;
    }
    if (!settings_note_.empty())
    {
        gfx.text_mid(Font::SemiBold, 18, cx + 8, y + 16, settings_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), Align::Left,
                     fit(gfx, Font::SemiBold, 18, cw - 16, settings_note_));
        y += 40;
    }
    y += 24;
    if (y + 80 < bottom) draw_drive_bars(gfx, cx + 8, y, cw - 16, true);
}

/* ---- Auto-update --------------------------------------------------------------------------- */

bool Store::auto_on(const std::string &id) const
{
    return std::find(prefs_.auto_ids.begin(), prefs_.auto_ids.end(), id) != prefs_.auto_ids.end();
}

void Store::toggle_auto(const std::string &id)
{
    const App *a = find(id);
    if (!a) return;
    auto it = std::find(prefs_.auto_ids.begin(), prefs_.auto_ids.end(), id);
    if (it != prefs_.auto_ids.end())
    {
        prefs_.auto_ids.erase(it);
        toast(prefs_.auto_all ? "Off for " + a->name + ", but Update everything automatically is on" : "Auto-update off for " + a->name);
    }
    else
    {
        prefs_.auto_ids.push_back(id);
        toast("Auto-update on for " + a->name);
        auto_checks_ = 0; /* looked at now */
    }
    save_prefs();
    play(int(Sfx::Select));
}

void Store::tick_auto_update()
{
    if (!booted_ || catalog_.apps.empty() || !freed_) return;
    const unsigned n = engine().checks_done();
    if (n == 0 || n == auto_checks_) return;
    auto_checks_ = n;
    if (!prefs_.auto_all && prefs_.auto_ids.empty()) return;
    int added = 0;
    std::string first;
    for (const std::string &id : engine().auto_update_picks(prefs_.auto_ids, prefs_.auto_all))
    {
        if (queue_place(id) || !engine().queue_add(id)) continue;
        ++added;
        if (first.empty())
            if (const App *a = find(id)) first = a->name;
    }
    if (!added) return;
    queue_total_ += added;
    toast(added == 1 ? "Updating " + first + " on its own" : "Updating " + std::to_string(added) + " apps on their own");
}

/* ---- The rows under an app's page ---------------------------------------------------------- */

std::vector<Store::RelRow> Store::related_rows(const App &a) const
{
    const std::string key = a.id + "#" + std::to_string(revision_) + "#" + std::to_string(charts_.also.size());
    if (key == rel_key_) return rel_cache_;
    rel_key_ = key;
    rel_cache_.clear();
    if (a.soon) return rel_cache_;
    auto ok = [&](const App &x) { return x.id != a.id && !x.beta && !x.soon; };
    /* More from its author. */
    std::vector<const App *> from;
    const std::string author = lower_ascii(a.author);
    if (!author.empty())
        for (const App &x : catalog_.apps)
            if (ok(x) && lower_ascii(x.author) == author && from.size() < 14) from.push_back(&x);
    /* More like this: the same part of the store, the most labels in common first, then the same
     * GitHub owner; what's installed already after the rest. Not the author's again. */
    const std::string owner = a.repo.empty() ? std::string() : lower_ascii(a.repo.substr(0, a.repo.find('/')));
    struct Score
    {
        const App *app;
        int score;
        bool inst;
    };
    std::vector<Score> like;
    for (const App &x : catalog_.apps)
    {
        if (!ok(x) || x.category != a.category || std::find(from.begin(), from.end(), &x) != from.end()) continue;
        int score = 0;
        for (const std::string &t : x.tags)
            if (std::find(a.tags.begin(), a.tags.end(), t) != a.tags.end()) score += 2;
        if (!owner.empty() && lower_ascii(x.repo).rfind(owner + "/", 0) == 0) score += 1;
        like.push_back({&x, score, !inst_v(x.id).empty()});
    }
    std::stable_sort(like.begin(), like.end(), [](const Score &p, const Score &q) {
        if (p.inst != q.inst) return !p.inst;
        return p.score > q.score;
    });
    RelRow r1{"More like this", {}};
    for (const Score &s : like)
        if (r1.apps.size() < 14) r1.apps.push_back(s.app);
    /* People also installed (the charts): apps of the signed catalog only. */
    RelRow r2{"People also installed", {}};
    if (const std::vector<std::string> *also = charts_.also_for(a.id))
        for (const std::string &id : *also)
            for (const App &x : catalog_.apps)
                if (x.id == id && ok(x) && !x.custom && r2.apps.size() < 8) { r2.apps.push_back(&x); break; }
    RelRow r3{"More from " + a.author, from};
    for (RelRow *r : {&r1, &r2, &r3})
        if (!r->apps.empty()) rel_cache_.push_back(*r);
    return rel_cache_;
}

float Store::draw_related(Gfx &gfx, const App &a, float top)
{
    const auto rows = related_rows(a);
    const float tile = 216, gap = 32, pitch = 352;
    const int shown = 7;
    float y = top;
    for (int ri = 0; ri < int(rows.size()); ++ri)
    {
        const RelRow &row = rows[std::size_t(ri)];
        gfx.text_mid(Font::ExtraBold, 26, 96, y + 16, C(kFrost), Align::Left, row.title);
        const int n = int(row.apps.size());
        const bool on_row = ri == page_rel_;
        const int col = on_row ? std::clamp(page_rel_col_, 0, n - 1) : 0;
        const int first = std::clamp(col - shown + 2, 0, std::max(0, n - shown));
        const float ty = y + 56;
        for (int k = first; k < n && k < first + shown; ++k)
        {
            float &f = rel_focus_[ri * 100 + k];
            f = approach(f, on_row && k == col ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
            draw_tile(gfx, *row.apps[std::size_t(k)], 96 + float(k - first) * (tile + gap), ty, tile, ease_out(f));
        }
        if (first > 0) gfx.glyph(Glyph::Arrow, 60, ty + tile / 2, 16, C(kFrost, 0.55f), -1.5708f);
        if (first + shown < n) gfx.glyph(Glyph::Arrow, 1920 - 60, ty + tile / 2, 16, C(kFrost, 0.55f), 1.5708f);
        y += pitch;
    }
    return y - top;
}

bool Store::update_related(float dt, const App &a, bool l, bool r, bool u, bool d)
{
    (void)dt;
    const auto rows = related_rows(a);
    if (rows.empty()) { page_rel_ = -1; return true; }
    page_rel_ = std::clamp(page_rel_, 0, int(rows.size()) - 1);
    const int n = int(rows[std::size_t(page_rel_)].apps.size());
    page_rel_col_ = std::clamp(page_rel_col_, 0, n - 1);
    const int c = std::clamp(page_rel_col_ + (r ? 1 : 0) - (l ? 1 : 0), 0, n - 1);
    if (c != page_rel_col_) { page_rel_col_ = c; play(int(Sfx::Move)); }
    if (u)
    {
        if (page_rel_ == 0) page_rel_ = -1; /* back up to the page */
        else --page_rel_;
        play(int(Sfx::Move));
        return true;
    }
    if (d && page_rel_ + 1 < int(rows.size()))
    {
        ++page_rel_;
        play(int(Sfx::Move));
        return true;
    }
    const App *pick = rows[std::size_t(page_rel_)].apps[std::size_t(page_rel_col_)];
    if (pressed(pad::BtnCross) && pick)
    {
        /* Its page; Circle comes back to this one. */
        page_back_.push_back(a.id);
        if (page_back_.size() > 16) page_back_.erase(page_back_.begin());
        open(pick->id);
        return true;
    }
    if (pressed(pad::BtnSquare) && pick && !pick->soon) toggle_pin(pick->id);
    return true;
}
} // namespace recomp
