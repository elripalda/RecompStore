/* Recomp Store 2.0 - the tab bar's order, the Library (My Apps, Updates, Betas), the
 * category tabs (Games, Emulators, Apps & Tools), pins, Update All, and Settings >
 * Customize with what it keeps (prefs.json).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_json.hpp"
#include "store_ui.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"
#include "ui_app_common.hpp"

#include <algorithm>
#include <cmath>
#include <cstdio>
#include <map>

namespace recomp
{
using namespace porpoise::ui;
using namespace sui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

namespace
{
const float kTop = 236;          /* the Library's and a category's bar of views or labels */
const float kContent = 316;      /* where their lists start */
const float kBottom = 1000;      /* what's shown of them ends here (the hints are below) */
const float kTileS = 216, kTileGap = 32, kTilePitch = 216 + 100;
const int kPerRow = 7;
const float kRowH = 120, kRowPitch = 136;

std::string lower(std::string s)
{
    for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
    return s;
}

/* Scrolled just enough to keep [y0, y1) (in the list's own space) inside the view. */
void keep_in_view(float &scroll, float &to, float y0, float y1, float view_h, float total)
{
    if (y1 - to > view_h) to = y1 - view_h;
    if (y0 < to) to = y0;
    to = std::clamp(to, 0.f, std::max(0.f, total - view_h));
    scroll = approach(scroll, to, 1.f / 60.f, 12.f);
    if (std::fabs(scroll - to) < 0.5f) scroll = to;
}
} // namespace

/* ---- Tabs ------------------------------------------------------------------------- */

std::string Store::tab_name(int kind) const
{
    if (kind >= kSourceTab)
    {
        /* (2.2.2) A source pinned as a tab: its name as the site gave it. */
        const std::size_t i = std::size_t(kind - kSourceTab);
        return i < src_tabs_.size() ? tab_entry_name("source:" + src_tabs_[i]) : std::string("Source");
    }
    switch (kind)
    {
    case Discover: return "Discover";
    case Games: return "Games";
    case Emulators: return "Emulators";
    case Apps: return "Apps";
    case Tools: return "Tools";
    case Library: return "My Apps";   /* (2.2.3) */
    case AllApps: return "Library";   /* (2.2.3) the whole store */
    default: return "Settings";
    }
}

const char *Store::category_of(int kind)
{
    return kind == Games ? "games" : kind == Emulators ? "emulators" : kind == Apps ? "apps" : kind == Tools ? "tools" : "";
}

std::vector<int> Store::tab_order() const
{
    /* A category gets its own tab once the store has something in it; Games only when
     * there's more than games (else it would be Discover again). (2.2.2) Which ones, in what
     * order: the player's (Customize > Tabs), else the catalog's ("tabs"), else all four. A
     * source pinned as a tab comes after them. */
    bool games = false, emus = false, apps = false, tools = false;
    for (const App &a : catalog_.apps)
    {
        if (a.beta) continue;
        games = games || a.category == "games";
        emus = emus || a.category == "emulators";
        apps = apps || a.category == "apps";
        tools = tools || a.category == "tools";
    }
    std::vector<int> out{Discover};
    const bool chosen = prefs_.tabs_custom || catalog_.tabs_set;
    src_tabs_.clear();
    const auto sources = engine().sources();
    for (const std::string &e : shown_tab_entries())
    {
        if (e == "games" && games && (chosen || emus || apps || tools)) out.push_back(Games);
        else if (e == "emulators" && emus) out.push_back(Emulators);
        else if (e == "apps" && apps) out.push_back(Apps);
        else if (e == "tools" && tools) out.push_back(Tools);
        else if (e.rfind("source:", 0) == 0)
        {
            const std::string v = e.substr(7);
            bool known = false;
            for (const Source &s : sources) known = known || s.value == v;
            if (known && src_tabs_.size() < 8)
            {
                out.push_back(kSourceTab + int(src_tabs_.size()));
                src_tabs_.push_back(v);
            }
        }
    }
    out.push_back(AllApps); /* (2.2.3) Library: everything in the store */
    out.push_back(Library); /* My Apps */
    out.push_back(Settings);
    return out;
}

std::vector<int> Store::settings_order() const
{
    return {SecGeneral, SecCustomize, SecStorage, SecSmp, SecSources, SecLink, SecRedeem, SecAdding, SecAbout};
}

void Store::go_tab(int kind, int dir)
{
    tab_dir_ = dir;
    tab_ = kind;
    tab_t_ = 0;
    col_ = 0;
    row_ = kind == Discover && has_header() ? 1 : 0; /* (2.1) under the header, in whichever style */
    dscroll_ = dscroll_to_ = uscroll_ = uscroll_to_ = lscroll_ = lscroll_to_ = cscroll_ = cscroll_to_ = 0;
    in_section_ = false;
    lib_bar_ = false;
    cat_chip_ = 0;
    cat_on_chips_ = false;
    side_tab_ = false; /* (2.2.2) */
    con_on_row_ = my_on_row_ = con_select_ = false;
    con_marked_.clear();
    play(int(Sfx::Tab));
}

/* ---- What the player keeps -------------------------------------------------------- */

void Store::load_prefs()
{
    prefs_loaded_ = true;
    Prefs p;
    if (load_prefs_file(data_ + "/prefs.json", p))
    {
        prefs_ = p;
        sounds_on_ = p.sounds;
    }
}

void Store::save_prefs()
{
    prefs_.sounds = sounds_on_;
    prefs_.last_version = kVersion; /* (2.2.2) */
    save_prefs_file(data_ + "/prefs.json", prefs_);
}

bool Store::pinned(const std::string &id) const
{
    return std::find(prefs_.pins.begin(), prefs_.pins.end(), id) != prefs_.pins.end();
}

void Store::toggle_pin(const std::string &id)
{
    const App *a = find(id);
    if (!a) return;
    auto it = std::find(prefs_.pins.begin(), prefs_.pins.end(), id);
    /* (2.2.2) Pins are favorites now. */
    if (it != prefs_.pins.end())
    {
        prefs_.pins.erase(it);
        toast("Removed " + a->name + " from favorites");
    }
    else
    {
        prefs_.pins.insert(prefs_.pins.begin(), id);
        if (prefs_.pins.size() > 64) prefs_.pins.pop_back();
        toast("Added " + a->name + " to favorites");
    }
    save_prefs();
    play(int(Sfx::Select));
}

/* ---- The Library's lists ------------------------------------------------------------ */

std::vector<const App *> Store::library_apps() const
{
    std::vector<const App *> pins, rest;
    for (const App &a : catalog_.apps)
    {
        const std::string v = inst_v(a.id);
        if (v.empty()) continue;
        if (prefs_.my_filter == 1 && !pinned(a.id)) continue; /* (2.2.2) Favorites */
        (pinned(a.id) ? pins : rest).push_back(&a);
    }
    std::sort(pins.begin(), pins.end(), [&](const App *x, const App *y) {
        return std::find(prefs_.pins.begin(), prefs_.pins.end(), x->id) < std::find(prefs_.pins.begin(), prefs_.pins.end(), y->id);
    });
    auto by_name = [](const App *x, const App *y) { return lower(x->name) < lower(y->name); };
    if (prefs_.lib_order == 1)
    {
        /* Each app's time once, not once per comparison (a file's date each). */
        std::map<const App *, long long> when;
        for (const App *x : rest) when[x] = inst_time(x->id);
        std::stable_sort(rest.begin(), rest.end(), [&](const App *x, const App *y) {
            const long long tx = when[x], ty = when[y];
            return tx != ty ? tx > ty : by_name(x, y);
        });
    }
    else if (prefs_.lib_order == 2)
    {
        std::stable_sort(rest.begin(), rest.end(), by_name);
        std::stable_partition(rest.begin(), rest.end(), [&](const App *x) {
            bool up = false;
            state_of(*x, &up);
            return up;
        });
    }
    else
        std::stable_sort(rest.begin(), rest.end(), by_name);
    pins.insert(pins.end(), rest.begin(), rest.end());
    return pins;
}

std::vector<const App *> Store::update_apps() const
{
    std::vector<const App *> out;
    for (const App &a : catalog_.apps)
    {
        bool up = false;
        state_of(a, &up);
        if (up) out.push_back(&a);
    }
    return out;
}

std::vector<const App *> Store::beta_apps() const
{
    std::vector<const App *> out;
    for (const App &a : catalog_.apps)
        if (a.beta) out.push_back(&a);
    return out;
}

std::vector<int> Store::library_views() const
{
    /* (2.2.3) Installed, Updates, On this PS5, Drives, then Betas (when there are some). */
    std::vector<int> v{0, 1, 3, 4};
    if (!beta_apps().empty()) v.push_back(2);
    return v;
}

/* (2.2.2) An app in a tab: its part of the store, or for a source's tab, from that source. */
static bool in_tab(const App &a, const std::string &cat, const std::string &source)
{
    if (a.beta) return false;
    return source.empty() ? a.category == cat : a.custom && a.source_value == source;
}

std::vector<std::string> Store::category_chips(int kind) const
{
    std::vector<std::string> out{"All"};
    if (kind == AllApps)
    {
        /* (2.2.3) Library: the parts of the store that have something in them. */
        bool has[4] = {};
        const char *cats[4] = {"games", "emulators", "apps", "tools"};
        for (const App &a : catalog_.apps)
            for (int i = 0; i < 4; ++i)
                if (!a.beta && a.category == cats[i]) has[i] = true;
        const char *names[4] = {"Games", "Emulators", "Apps", "Tools"};
        for (int i = 0; i < 4; ++i)
            if (has[i]) out.push_back(names[i]);
        return out;
    }
    const std::string cat = category_of(kind);
    const std::string src = kind >= kSourceTab && std::size_t(kind - kSourceTab) < src_tabs_.size() ? src_tabs_[std::size_t(kind - kSourceTab)] : "";
    for (const std::vector<App> *list : {&catalog_.apps, &catalog_.soon})
        for (const App &a : *list)
        {
            if (!in_tab(a, cat, src)) continue;
            for (const std::string &t : a.tags)
                if (out.size() < 10 && std::find(out.begin(), out.end(), t) == out.end()) out.push_back(t);
        }
    return out;
}

std::vector<const App *> Store::category_apps(int kind) const
{
    if (kind == AllApps)
    {
        /* (2.2.3) Library: every app in the store (or one part), sorted as the player chose; Coming
         * Soon after them. */
        const auto chips = category_chips(kind);
        const std::string label = cat_chip_ > 0 && cat_chip_ < int(chips.size()) ? chips[std::size_t(cat_chip_)] : "";
        std::string cat = label;
        for (char &c : cat) c = char(std::tolower(static_cast<unsigned char>(c)));
        std::vector<const App *> out, soon;
        for (const App &a : catalog_.apps)
            if (!a.beta && (cat.empty() || a.category == cat) && search_match(a)) out.push_back(&a); /* (2.3.0) Search */
        static const char *modes[4] = {"az", "installs", "new", "updated"};
        sort_by(modes[std::clamp(prefs_.all_sort, 0, 3)], out);
        for (const App &a : catalog_.soon)
            if ((cat.empty() || a.category == cat) && search_match(a)) out.push_back(&a);
        return out;
    }
    const std::string cat = category_of(kind);
    const std::string src = kind >= kSourceTab && std::size_t(kind - kSourceTab) < src_tabs_.size() ? src_tabs_[std::size_t(kind - kSourceTab)] : "";
    const auto chips = category_chips(kind);
    const std::string tag = cat_chip_ > 0 && cat_chip_ < int(chips.size()) ? chips[std::size_t(cat_chip_)] : "";
    std::vector<const App *> out;
    bool sorted = false;
    for (const std::vector<App> *list : {&catalog_.apps, &catalog_.soon})
    {
        if (list == &catalog_.soon && !sorted)
        {
            if (src.empty()) sort_apps(cat, out); /* (2.1) the apps as the catalog sorts this part of the store; Coming soon after them */
            sorted = true;
        }
        for (const App &a : *list)
            if (in_tab(a, cat, src) && (tag.empty() || std::find(a.tags.begin(), a.tags.end(), tag) != a.tags.end()))
                out.push_back(&a);
    }
    return out;
}

void Store::sort_apps(const std::string &category, std::vector<const App *> &v) const
{
    /* (2.1) "manual" leaves the admin's order (the catalog's, as the apps come). The others keep
     * it for what they can't tell apart. */
    sort_by(catalog_.cat_sort[std::clamp(category_index(category), 0, 3)], v);
}

/* (2.2.3) The same, by a sort's name: "manual", "az", "installs", "new", "updated". */
void Store::sort_by(const std::string &mode, std::vector<const App *> &v) const
{
    if (mode == "manual" || v.size() < 2)
        return;
    std::map<const App *, std::string> names;
    for (const App *a : v) names[a] = lower(a->name);
    if (mode == "az")
    {
        std::stable_sort(v.begin(), v.end(), [&](const App *x, const App *y) { return names[x] < names[y]; });
        return;
    }
    if (mode == "installs")
    {
        /* Most installed first (the charts' count of consoles), then by name. */
        std::map<const App *, long long> n;
        for (const App *a : v)
        {
            const ChartStat *st = charts_.stat(a->id);
            n[a] = st ? st->installs : 0;
        }
        std::stable_sort(v.begin(), v.end(), [&](const App *x, const App *y) { return n[x] != n[y] ? n[x] > n[y] : names[x] < names[y]; });
        return;
    }
    /* "new", "updated": the charts' list in its order first, the rest after in the admin's. */
    const std::vector<std::string> &list = mode == "new" ? charts_.fresh : charts_.updated;
    std::map<const App *, std::size_t> at;
    for (const App *a : v) at[a] = std::size_t(std::find(list.begin(), list.end(), a->id) - list.begin());
    std::stable_sort(v.begin(), v.end(), [&](const App *x, const App *y) { return at[x] < at[y]; });
}

/* ---- Update All --------------------------------------------------------------------- */

void Store::start_update_all()
{
    if (!engine().queue().empty() || !queue_now_.empty())
    {
        /* Pressed again while it runs: stop after the one that's going. */
        engine().queue_clear();
        lib_note_ = "Stopping after this one.";
        lib_note_bad_ = false;
        play(int(Sfx::Back));
        return;
    }
    if (!freed_)
    {
        lib_note_ = "Recomp Store can't write to /data. Start etaHEN, then open the store again.";
        lib_note_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    /* (2.2.2) Into the download queue, after anything already in it. */
    if (queue_total_ == 0) queue_failed_.clear();
    int added = 0;
    for (const App *a : update_apps())
        if (!queue_place(a->id) && engine().queue_add(a->id))
            ++added;
    if (!added)
    {
        play(int(Sfx::Error));
        return;
    }
    queue_total_ += added;
    lib_note_.clear();
    play(int(Sfx::Install));
    tick_queue();
}

void Store::tick_queue()
{
    /* (2.2.2) The engine keeps the queue; this starts the next one whenever nothing is going, and
     * says how a run of them went. */
    const JobState j = engine().job();
    if (!queue_now_.empty() && j.phase == Phase::Idle)
    {
        /* The one going: its result was taken by the screens (Done or Failed, acknowledged). */
        ++queue_done_;
        queue_now_.clear();
    }
    if (clash_ || moved_ || confirm_ || mng_ || panel_ == PanelDelete || opening_ || store_updating_ || !booted_ || catalog_.apps.empty())
        return; /* a question on screen (or the store closing): the rest waits for it */
    if (j.phase != Phase::Idle) return;
    if (engine().queue().empty())
    {
        if (queue_total_ > 0 && queue_now_.empty())
        {
            if (queue_total_ < 2)
            {
                queue_total_ = queue_done_ = 0;
                queue_failed_.clear();
                queue_fresh_ = false;
                return; /* one on its own: its page or Downloads said how it went */
            }
            const int ok = queue_done_ - int(queue_failed_.size());
            const std::string verb = queue_fresh_ ? "Installed or updated " : "Updated ";
            if (queue_failed_.empty())
                lib_note_ = verb + (ok == 1 ? "1 app." : std::to_string(ok) + " apps.");
            else
            {
                std::string names;
                for (std::size_t i = 0; i < queue_failed_.size() && i < 3; ++i)
                {
                    const App *a = find(queue_failed_[i]);
                    names += (i ? ", " : "") + (a ? a->name : queue_failed_[i]);
                }
                lib_note_ = verb + std::to_string(ok) + " of " + std::to_string(queue_done_) + ". Didn't finish: " + names +
                            ". Open it to see why.";
            }
            lib_note_bad_ = !queue_failed_.empty();
            play(int(queue_failed_.empty() ? Sfx::Complete : Sfx::Error));
        }
        queue_total_ = queue_done_ = 0;
        queue_failed_.clear();
        queue_fresh_ = false;
        return;
    }
    const std::string id = engine().queue_pump();
    if (!id.empty())
    {
        queue_now_ = id;
        if (queue_total_ <= queue_done_) queue_total_ = queue_done_ + 1; /* from before the store was opened */
    }
}

/* ---- Drawing pieces ----------------------------------------------------------------- */

void Store::draw_tab_header(Gfx &gfx, const std::string &title, const std::string &count)
{
    gfx.text_mid(Font::ExtraBold, 48, 96, 160 + 30, C(kFrost), Align::Left, title, -1);
    if (!count.empty())
        gfx.text_mid(Font::Regular, 21, 96 + gfx.measure(Font::ExtraBold, 48, title, -1) + 18, 160 + 36, C(kMuted), Align::Left, count);
}

void Store::draw_pin(Gfx &gfx, float x, float y)
{
    /* A small glass dot with a star: pinned (first in the Library, and on Discover). */
    const float r = 17;
    gfx.panel(x - r, y - r, r * 2, r * 2, C(kMidnight, 0.72f), 1.f, r, C(kCyan, 0.85f), 1.5f, 6);
    gfx.glyph(Glyph::Star, x, y + 0.5f, 17, C(kCyan));
}

void Store::draw_app_row(Gfx &gfx, const App &a, float x0, float y0, float w0, float focus, const std::string &line,
                         const std::string &button, int style)
{
    const float k = ease_out(focus);
    const float s = 1 + 0.012f * k;
    const float w = w0 * s, h = kRowH * s, x = x0 + (w0 - w) / 2, y = y0 + (kRowH - h) / 2;
    glass(gfx, x, y, w, h, 26, {1, 1, 1, 0.07f + 0.05f * k}, true);
    ring(gfx, x, y, w, h, 26, k);
    Art &t = art(a);
    const float is = 84 * s, ix = x + 20, iy = y + (h - is) / 2;
    if (t.icon)
        gfx.image(t.icon, ix, iy, is, is, {}, 20);
    else
        draw_no_icon(gfx, a, ix, iy, is, 20);
    if (pinned(a.id)) draw_pin(gfx, ix + is - 10, iy + is - 10);
    const float tx = ix + is + 24;
    const float bw = button.empty() ? 0 : gfx.measure(Font::Bold, 19, button) + 52;
    const float room = w - (tx - x) - bw - 56;
    const std::string name = fit(gfx, Font::ExtraBold, 24, room * 0.7f, a.name);
    gfx.text_mid(Font::ExtraBold, 24, tx, y + h / 2 - 16, C(kFrost), Align::Left, name);
    const std::string ver = version_of(a);
    float cx = tx + gfx.measure(Font::ExtraBold, 24, name) + 12;
    if (!ver.empty())
        gfx.text_mid(Font::SemiBold, 18, cx, y + h / 2 - 15, C(kCyan), Align::Left, ver);
    if (a.beta)
    {
        cx += (ver.empty() ? 0 : gfx.measure(Font::SemiBold, 18, ver) + 12);
        const float bwid = gfx.measure(Font::Bold, 13, "BETA", 1) + 20;
        gfx.panel(cx, y + h / 2 - 28, bwid, 24, C(kAmber), 1.f, 12);
        gfx.text_mid(Font::Bold, 13, cx + bwid / 2, y + h / 2 - 16, C(kMidnight), Align::Center, "BETA", 1);
    }
    gfx.text_mid(Font::Regular, 18, tx, y + h / 2 + 18, C(kSoft), Align::Left, fit(gfx, Font::Regular, 18, room, line));
    if (bw > 0)
    {
        /* 0 an update (cyan), 1 done (green), 2 plain, 3 working (dim) */
        const Color bg = style == 0 ? C(kCyan) : style == 1 ? C(kGreen, 0.18f) : style == 3 ? C(kFrost, 0.08f) : C(kFrost, 0.14f);
        const Color fg = style == 0 ? C(kMidnight) : style == 1 ? C(0x9FF0C6) : style == 3 ? C(kMuted) : C(kFrost);
        gfx.panel(x + w - 28 - bw, y + h / 2 - 26, bw, 52, bg, 1.f, 17);
        gfx.text_mid(Font::Bold, 19, x + w - 28 - bw / 2, y + h / 2, fg, Align::Center, button);
    }
}

/* ---- The Library ---------------------------------------------------------------------- */

namespace
{
/* The rows of Updates: Update all (when there are two or more), the store itself, the games. */
struct UpdateRow
{
    int kind; /* 0 Update all, 1 the store, 2 a game */
    const App *app;
};
} // namespace

bool Store::update_library(float dt, int moves, bool u, bool d)
{
    (void)dt;
    const auto views = library_views();
    if (std::find(views.begin(), views.end(), lib_view_) == views.end()) lib_view_ = 0;
    const int vi = int(std::find(views.begin(), views.end(), lib_view_) - views.begin());
    auto switch_view = [&](int step) {
        const int n = int(views.size());
        const int to = views[std::size_t((vi + step + n) % n)];
        if (to == lib_view_) return;
        lib_view_ = to;
        col_ = 0;
        lscroll_ = lscroll_to_ = 0;
        lib_view_t_ = 0;
        if (to == 3 || to == 4) engine().look_console(); /* (2.2) looked for again each time it's shown; (2.2.3) the drives too */
        play(int(Sfx::Tab));
    };
    if (pressed(pad::BtnL2)) { switch_view(-1); return true; }
    if (pressed(pad::BtnR2)) { switch_view(1); return true; }
    if (lib_bar_)
    {
        if (moves) switch_view(moves);
        if (d || pressed(pad::BtnCross))
        {
            lib_bar_ = false;
            /* (2.2.2) My Apps and On this PS5: their row of views first. */
            if (lib_view_ == 0) { my_on_row_ = true; my_chip_ = prefs_.my_filter; }
            if (lib_view_ == 3 && !engine().storage_lost()) { con_on_row_ = true; con_chip_ = 0; }
            play(int(Sfx::Move));
        }
        return true;
    }
    if (lib_view_ == 3)
        return update_console(dt, moves, u, d); /* (2.2) */
    if (lib_view_ == 4)
        return update_drives(dt, moves, u, d); /* (2.2.3) */
    if (lib_view_ == 0)
    {
        const auto list = library_apps();
        const int n = int(list.size());
        const bool grid = prefs_.lib_view == 0;
        const int per = grid ? kPerRow : 1;
        if (my_on_row_)
        {
            /* (2.2.2) All, Favorites; Grid, List. */
            const int nc = int(my_chips().size());
            const int c = std::clamp(my_chip_ + moves, 0, nc - 1);
            if (c != my_chip_) { my_chip_ = c; play(int(Sfx::Move)); }
            if (u) { my_on_row_ = false; lib_bar_ = true; play(int(Sfx::Move)); return true; }
            if (d && n > 0) { my_on_row_ = false; play(int(Sfx::Move)); return true; }
            if (pressed(pad::BtnCross))
            {
                if (my_chip_ <= 1 && prefs_.my_filter != my_chip_)
                {
                    prefs_.my_filter = my_chip_;
                    col_ = 0;
                    lscroll_ = lscroll_to_ = 0;
                }
                else if (my_chip_ >= 2) prefs_.lib_view = my_chip_ - 2;
                save_prefs();
                play(int(Sfx::Select));
            }
            return true;
        }
        if (n == 0)
        {
            if (u) { my_on_row_ = true; my_chip_ = prefs_.my_filter; play(int(Sfx::Move)); }
            return true;
        }
        col_ = std::clamp(col_, 0, n - 1);
        int to = col_;
        if (grid && moves) to = std::clamp(col_ + moves, 0, n - 1);
        if (d) to = std::min(n - 1, col_ + per);
        if (u)
        {
            if (col_ < per) { my_on_row_ = true; my_chip_ = prefs_.my_filter; play(int(Sfx::Move)); return true; }
            to = col_ - per;
        }
        if (to != col_) { col_ = to; play(int(Sfx::Move)); }
        focus_id_ = list[std::size_t(col_)]->id;
        if (pressed(pad::BtnCross)) open(focus_id_);
        else if (pressed(pad::BtnSquare)) toggle_pin(focus_id_);
        return true;
    }
    if (lib_view_ == 1)
    {
        std::vector<UpdateRow> rows;
        const auto ups = update_apps();
        if (ups.size() >= 2 || !engine().queue().empty() || !queue_now_.empty()) rows.push_back({0, nullptr});
        if (!engine().store_update().empty()) rows.push_back({1, nullptr});
        for (const App *a : ups) rows.push_back({2, a});
        const int n = int(rows.size());
        if (n == 0)
        {
            if (u) { lib_bar_ = true; play(int(Sfx::Move)); }
            return true;
        }
        col_ = std::clamp(col_, 0, n - 1);
        if (u && col_ == 0) { lib_bar_ = true; play(int(Sfx::Move)); return true; }
        const int to = std::clamp(col_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n - 1);
        if (to != col_) { col_ = to; play(int(Sfx::Move)); }
        const UpdateRow &r = rows[std::size_t(col_)];
        if (r.app) focus_id_ = r.app->id;
        if (pressed(pad::BtnCross))
        {
            if (r.kind == 0) start_update_all();
            else if (r.kind == 1) start_store_update();
            else if (!freed_) { lib_note_ = "Recomp Store can't write to /data. Start etaHEN, then open the store again."; lib_note_bad_ = true; play(int(Sfx::Error)); }
            else if (queue_place(r.app->id)) play(int(Sfx::Error));
            else if (enqueue(*r.app, "", "")) lib_note_.clear(); /* (2.2.2) after whatever is going */
        }
        else if (pressed(pad::BtnSquare) && r.app)
            open(r.app->id); /* What's new and the rest, on its page */
        return true;
    }
    /* Betas: each beta game, then Enter a code. */
    const auto list = beta_apps();
    const int n = int(list.size()) + 1;
    col_ = std::clamp(col_, 0, n - 1);
    if (u && col_ == 0) { lib_bar_ = true; play(int(Sfx::Move)); return true; }
    const int to = std::clamp(col_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n - 1);
    if (to != col_) { col_ = to; play(int(Sfx::Move)); }
    if (col_ < int(list.size())) focus_id_ = list[std::size_t(col_)]->id;
    if (pressed(pad::BtnCross))
    {
        if (col_ < int(list.size())) open(list[std::size_t(col_)]->id);
        else open_keypad();
    }
    return true;
}

void Store::draw_library2(Gfx &gfx)
{
    const auto views = library_views();
    const auto mine = library_apps();
    const auto ups = update_apps();
    const int store_up = engine().store_update().empty() ? 0 : 1;
    lib_view_t_ = std::min(1.f, lib_view_t_ + 1.f / 60.f * 4.f);
    if (lib_view_ == 3)
    {
        /* (2.2) Everything in the homebrew folders, and what it all takes. */
        sync_console();
        long long all = 0;
        bool sized = true;
        for (const ConsoleApp &c : console_)
        {
            if (c.size >= 0) all += c.size;
            else sized = false;
        }
        std::string count = console_.size() == 1 ? "1 folder on this PS5" : std::to_string(console_.size()) + " folders on this PS5";
        if (prefs_.con_filter != "all" && !console_.empty())
            count = std::to_string(console_list().size()) + " of " + count; /* (2.2.2) Show: a part of them */
        if (!console_.empty()) count += "  \xC2\xB7  " + std::string(sized ? "" : "at least ") + size_text(std::uint64_t(all));
        if (engine().storage_lost()) count = "Can't see the console's storage"; /* (2.2.1) not "0 folders" */
        draw_tab_header(gfx, "My Apps", count);
    }
    else if (lib_view_ == 4)
    {
        const auto dr = engine().drives();
        draw_tab_header(gfx, "My Apps", dr.size() == 1 ? "1 drive" : std::to_string(dr.size()) + " drives");
    }
    else
        draw_tab_header(gfx, "My Apps", mine.size() == 1 ? "1 app installed" : std::to_string(mine.size()) + " apps installed");

    /* The views, as a glass bar: (2.2.2) My Apps, On this PS5, Updates (with its count), Betas. */
    {
        const char *names[5] = {"Installed", "Updates", "Betas", "On this PS5", "Drives"}; /* (2.2.3) */
        float widths[5] = {0, 0, 0, 0, 0}, total = 12;
        const int badge = int(ups.size()) + store_up;
        for (int v : views)
        {
            widths[v] = gfx.measure(Font::SemiBold, 19, names[v]) + 48;
            if (v == 1 && badge > 0) widths[v] += 10 + gfx.measure(Font::Bold, 14, std::to_string(badge)) + 16;
            total += widths[v] + 4;
        }
        const float bx = 96, by = kTop, bh = 52;
        glass(gfx, bx, by, total, bh, bh / 2, kGlass, true);
        float x = bx + 6;
        for (int v : views)
        {
            const bool on = v == lib_view_;
            if (on)
            {
                gfx.panel(x, by + 5, widths[v], bh - 10, C(kFrost), 0.92f, (bh - 10) / 2);
                if (lib_bar_) ring(gfx, x, by + 5, widths[v], bh - 10, (bh - 10) / 2, 1.f, true);
            }
            const Color fg = on ? C(kMidnight) : C(kFrost);
            float lx = x + 24;
            gfx.text_mid(on ? Font::Bold : Font::SemiBold, 19, lx, by + bh / 2, fg, Align::Left, names[v]);
            if (v == 1 && badge > 0)
            {
                lx += gfx.measure(on ? Font::Bold : Font::SemiBold, 19, names[v]) + 10;
                const std::string b = std::to_string(badge);
                const float bw = gfx.measure(Font::Bold, 14, b) + 16;
                gfx.panel(lx, by + bh / 2 - 11, bw, 22, C(kCyan), 1.f, 11);
                gfx.text_mid(Font::Bold, 14, lx + bw / 2, by + bh / 2, C(kMidnight), Align::Center, b);
            }
            x += widths[v] + 4;
        }
        /* L2 and R2 change the view: their keycaps beside the bar. */
        float kx = bx + total + 18;
        for (const char *cap : {"L2", "R2"})
        {
            const float cw = gfx.measure(Font::Bold, 15, cap) + 22;
            glass(gfx, kx, by + bh / 2 - 17, cw, 34, 11, kGlass, false);
            gfx.text_mid(Font::Bold, 15, kx + cw / 2, by + bh / 2, C(0xE8EEF6), Align::Center, cap);
            kx += cw + 8;
        }
        if (!lib_note_.empty() && lib_view_ != 3) /* (2.2) On this PS5 shows it whole, over its list */
            gfx.text_mid(Font::SemiBold, 18, kx + 18, by + bh / 2, lib_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), Align::Left,
                         fit(gfx, Font::SemiBold, 18, 1824 - kx - 18, lib_note_));
    }

    const float fade = ease_out(lib_view_t_), slide = (1 - fade) * 24;
    const float view_h = kBottom - kContent;
    if (lib_view_ == 3)
    {
        draw_console(gfx, slide); /* (2.2) */
        return;
    }
    if (lib_view_ == 4)
    {
        draw_drives(gfx, slide); /* (2.2.3) */
        return;
    }
    if (lib_view_ == 0)
    {
        /* (2.2.2) All or Favorites, Grid or List. */
        draw_chip_row(gfx, my_chips(), my_chip_, my_on_row_ && !lib_bar_, kContent + slide);
    }
    const float ctop = lib_view_ == 0 ? kContent + 62 : kContent; /* (2.2.2) under My Apps' row */
    const float view_h0 = kBottom - ctop;
    gfx.clip(0, ctop - 12, gfx.design_w(), kBottom - ctop + 12);
    if (lib_view_ == 0)
    {
        const int n = int(mine.size());
        const bool lit = !lib_bar_ && !my_on_row_;
        if (n == 0)
        {
            glass(gfx, 96, ctop + slide, 900, 96, 26, kGlass, false);
            const auto lines = wrap(gfx, Font::Regular, 20, 900 - 60,
                                    prefs_.my_filter == 1 ? "No favorites installed. Square on an app adds it to your favorites."
                                                          : "Nothing installed yet. Find something in Discover, and it shows up here with its updates.", 2);
            draw_lines(gfx, Font::Regular, 20, 126, ctop + slide + 48 - 14.5f * float(lines.size()), 29, C(kSoft), lines);
        }
        else if (prefs_.lib_view == 0)
        {
            const int rows = (n + kPerRow - 1) / kPerRow, r = std::clamp(col_, 0, n - 1) / kPerRow;
            keep_in_view(lscroll_, lscroll_to_, float(r) * kTilePitch, float(r) * kTilePitch + kTilePitch - 30, view_h0,
                         float(rows) * kTilePitch);
            for (int i = 0; i < n; ++i)
            {
                float &f = focus_anim_[std::min(i, 511)];
                f = approach(f, i == col_ && lit ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
                const float y = ctop + 14 + float(i / kPerRow) * kTilePitch - lscroll_ + slide;
                if (y > kBottom || y + kTilePitch < ctop - 20) continue;
                const float x = 96 + float(i % kPerRow) * (kTileS + kTileGap);
                draw_tile(gfx, *mine[std::size_t(i)], x, y, kTileS, ease_out(f));
            }
        }
        else
        {
            keep_in_view(lscroll_, lscroll_to_, float(col_) * kRowPitch, float(col_) * kRowPitch + kRowH + 8, view_h0,
                         float(n) * kRowPitch);
            for (int i = 0; i < n; ++i)
            {
                float &f = focus_anim_[std::min(i, 511)];
                f = approach(f, i == col_ && lit ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
                const float y = ctop + 8 + float(i) * kRowPitch - lscroll_ + slide;
                if (y > kBottom || y + kRowH < ctop - 20) continue;
                const App &a = *mine[std::size_t(i)];
                bool up = false;
                const std::string st = state_of(a, &up);
                std::string line = st;
                if (prefs_.advanced) line += "  \xC2\xB7  " + app_folder(a.id);
                else if (!a.tags.empty()) line += "  \xC2\xB7  " + a.tags[0];
                const int qp = queue_place(a.id); /* (2.2.2) */
                draw_app_row(gfx, a, 96, y, 1728, f, line, qp == 1 ? "Updating\xE2\x80\xA6" : qp > 1 ? "Queued" : up ? "Update" : "Open",
                             qp ? 3 : up ? 0 : 2);
            }
        }
    }
    else if (lib_view_ == 1)
    {
        std::vector<UpdateRow> rows;
        if (ups.size() >= 2 || !engine().queue().empty() || !queue_now_.empty()) rows.push_back({0, nullptr});
        if (store_up) rows.push_back({1, nullptr});
        for (const App *a : ups) rows.push_back({2, a});
        const int n = int(rows.size());
        if (n == 0)
        {
            glass(gfx, 96, kContent + slide, 900, 96, 26, kGlass, false);
            gfx.panel(96 + 30, kContent + slide + 48 - 16, 32, 32, C(kGreen, 0.2f), 1.f, 16);
            draw_check(gfx, 96 + 46, kContent + slide + 48, 18, C(0x9FF0C6)); /* (2.2.2) drawn: the fonts have no check mark */
            gfx.text_mid(Font::SemiBold, 21, 96 + 80, kContent + slide + 48, C(kFrost), Align::Left,
                         "Everything is up to date. Triangle looks again.");
        }
        keep_in_view(lscroll_, lscroll_to_, float(col_) * kRowPitch, float(col_) * kRowPitch + kRowH + 8, view_h, float(n) * kRowPitch);
        const JobState job = engine().job();
        for (int i = 0; i < n; ++i)
        {
            float &f = focus_anim_[std::min(i, 511)];
            f = approach(f, i == col_ && !lib_bar_ ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
            const float y = kContent + 8 + float(i) * kRowPitch - lscroll_ + slide;
            if (y > kBottom || y + kRowH < kContent - 20) continue;
            const UpdateRow &r = rows[std::size_t(i)];
            if (r.kind == 2)
            {
                const App &a = *r.app;
                const std::string inst = inst_v(a.id);
                const bool going = job.phase == Phase::Working && job.app_id == a.id;
                const bool waiting = queue_place(a.id) > 1; /* (2.2.2) */
                std::string line = going ? (job.total ? job.step + "  " + std::to_string(int(100.0 * double(job.done) / double(job.total))) + "%"
                                                      : job.step + "\xE2\x80\xA6")
                                         : ("You have " + inst.substr(version_start(inst)) +
                                            (a.news.empty() ? std::string() : "  \xC2\xB7  " + md_inline(a.news)));
                draw_app_row(gfx, a, 96, y, 1728, f, line, going ? "Updating\xE2\x80\xA6" : waiting ? "Waiting" : "Update",
                             going || waiting ? 3 : 0);
                continue;
            }
            /* Update all, and the store's own update: glass rows of their own. */
            const float k = ease_out(f), s = 1 + 0.012f * k;
            const float w = 1728 * s, h = kRowH * s, x = 96 + (1728 - w) / 2, yy = y + (kRowH - h) / 2;
            glass(gfx, x, yy, w, h, 26, r.kind == 0 ? Color{0.21f, 0.84f, 0.96f, 0.10f + 0.05f * k} : Color{1, 1, 1, 0.07f + 0.05f * k}, true);
            ring(gfx, x, yy, w, h, 26, k);
            std::string title, line, button;
            int style = 0;
            if (r.kind == 0)
            {
                const bool running = !engine().queue().empty() || !queue_now_.empty();
                /* (2.2.2) The queue may hold more than this run counted (kept from before the store opened). */
                const int all = std::max(queue_total_, queue_done_ + int(engine().queue().size()) + (queue_now_.empty() ? 0 : 1));
                title = running ? "Updating " + std::to_string(std::min(queue_done_ + 1, all)) + " of " + std::to_string(all) : "Update all";
                if (running)
                {
                    const App *a = find(queue_now_);
                    line = a ? a->name + (job.total ? "  \xC2\xB7  " + job.step + " " + std::to_string(int(100.0 * double(job.done) / double(std::max<std::uint64_t>(1, job.total)))) + "%" : "") : "Starting\xE2\x80\xA6";
                }
                else
                    line = std::to_string(ups.size()) + " updates, one after another. Your saves and game files stay as they are.";
                button = running ? "Stop after this" : "Update all";
                style = running ? 2 : 0;
                /* the stack of icons it covers */
                float ix = x + 20;
                for (std::size_t q = 0; q < ups.size() && q < 3; ++q)
                {
                    Art &t = art(*ups[q]);
                    const float is = 70 * s;
                    const float qx = ix + float(q) * 24, qy = yy + (h - is) / 2;
                    gfx.panel(qx - 3, qy - 3, is + 6, is + 6, C(0x0E1729), 1.f, 21);
                    if (t.icon) gfx.image(t.icon, qx, qy, is, is, {}, 18);
                    else draw_no_icon(gfx, *ups[q], qx, qy, is, 18);
                }
                const int all_n = std::max(queue_total_, queue_done_ + int(engine().queue().size()) + (queue_now_.empty() ? 0 : 1));
                if (running && all_n > 0)
                {
                    const float pw = w - 300 - 360, px = x + 150 + 24, py = yy + h - 22;
                    gfx.panel(px, py, pw, 6, C(kFrost, 0.12f), 1.f, 3);
                    const float part = (float(queue_done_) + (job.total ? float(double(job.done) / double(job.total)) : 0.f)) / float(all_n);
                    gfx.panel(px, py, std::max(6.f, pw * clamp01(part)), 6, C(kCyan), 1.f, 3);
                }
            }
            else
            {
                std::string v = engine().store_update();
                if (!v.empty() && (v[0] == 'v' || v[0] == 'V')) v.erase(0, 1);
                title = "Recomp Store " + v;
                const bool fetching = job.phase == Phase::Working && job.kind == "storeupdate";
                line = !store_note_.empty() ? store_note_ : fetching ? (job.total ? "Downloading " + size_text(job.done) + " of " + size_text(job.total) : job.step + "\xE2\x80\xA6")
                                                                     : "A new version of the store. It downloads, closes, updates itself and opens again.";
                button = store_updating_ ? "Starting\xE2\x80\xA6" : fetching ? "Downloading\xE2\x80\xA6" : "Update";
                style = store_updating_ || fetching ? 3 : 0;
                if (logo_) gfx.image(logo_, x + 20, yy + (h - 84 * s) / 2, 84 * s, 84 * s, {}, 20);
            }
            const float tx = x + (r.kind == 0 ? 150.f : 128.f);
            const float bw = gfx.measure(Font::Bold, 19, button) + 52;
            gfx.text_mid(Font::ExtraBold, 24, tx, yy + h / 2 - 16, C(kFrost), Align::Left, title);
            gfx.text_mid(Font::Regular, 18, tx, yy + h / 2 + 18, r.kind == 1 && store_note_bad_ ? C(0xFFB4A8) : C(kSoft), Align::Left,
                         fit(gfx, Font::Regular, 18, w - (tx - x) - bw - 70, line));
            const Color bg = style == 0 ? C(kCyan) : style == 3 ? C(kFrost, 0.08f) : C(kFrost, 0.14f);
            const Color fg = style == 0 ? C(kMidnight) : style == 3 ? C(kMuted) : C(kFrost);
            gfx.panel(x + w - 28 - bw, yy + h / 2 - 26, bw, 52, bg, 1.f, 17);
            gfx.text_mid(Font::Bold, 19, x + w - 28 - bw / 2, yy + h / 2, fg, Align::Center, button);
        }
    }
    else
    {
        const auto list = beta_apps();
        const int n = int(list.size()) + 1;
        keep_in_view(lscroll_, lscroll_to_, float(col_) * kRowPitch, float(col_) * kRowPitch + kRowH + 8, view_h, float(n) * kRowPitch);
        for (int i = 0; i < n; ++i)
        {
            float &f = focus_anim_[std::min(i, 511)];
            f = approach(f, i == col_ && !lib_bar_ ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
            const float y = kContent + 8 + float(i) * kRowPitch - lscroll_ + slide;
            if (y > kBottom || y + kRowH < kContent - 20) continue;
            if (i < int(list.size()))
            {
                const App &a = *list[std::size_t(i)];
                bool up = false;
                const std::string st = state_of(a, &up);
                const bool inst = !inst_v(a.id).empty();
                draw_app_row(gfx, a, 96, y, 1728, f, inst ? st : std::string("Unlocked on this PS5 \xC2\xB7 not installed yet"),
                             up ? "Update" : inst ? "Open" : "Install", up || !inst ? 0 : 2);
                continue;
            }
            const float k = ease_out(f), s = 1 + 0.012f * k;
            const float w = 1728 * s, h = kRowH * s, x = 96 + (1728 - w) / 2, yy = y + (kRowH - h) / 2;
            glass(gfx, x, yy, w, h, 26, {1, 1, 1, 0.05f + 0.05f * k}, true);
            ring(gfx, x, yy, w, h, 26, k);
            gfx.panel(x + 20, yy + (h - 84 * s) / 2, 84 * s, 84 * s, C(kAmber, 0.16f), 1.f, 20, C(kAmber, 0.5f), 1.25f);
            gfx.text_mid(Font::ExtraBold, 30, x + 20 + 42 * s, yy + h / 2, C(kAmber), Align::Center, "#");
            gfx.text_mid(Font::ExtraBold, 24, x + 128, yy + h / 2 - 16, C(kFrost), Align::Left, "Enter a code");
            gfx.text_mid(Font::Regular, 18, x + 128, yy + h / 2 + 18, C(kSoft), Align::Left,
                         "Got a code for a game in testing? It unlocks here.");
        }
    }
    gfx.unclip();
    edge_fade(gfx, ctop - 12, kBottom, lscroll_ > 0.5f); /* (2.2.3) */
}

/* ---- A category tab ------------------------------------------------------------------ */

bool Store::update_category(float dt, int moves, bool u, bool d)
{
    (void)dt;
    const auto chips = category_chips(tab_);
    const auto list = category_apps(tab_);
    const int n = int(list.size());
    const bool all = tab_ == AllApps; /* (2.2.3) Library: a Sort chip after the parts */
    const bool has_chips = chips.size() > 1 || all;
    if (cat_on_chips_)
    {
        if (!has_chips) cat_on_chips_ = false;
        /* (2.3.0) Library: the parts, then Search, then Sort. */
        const int last = int(chips.size()) - 1 + (all ? 2 : 0);
        const int at = cat_sort_on_ ? last : cat_search_on_ ? last - 1 : cat_chip_;
        const int c = std::clamp(at + moves, 0, last);
        if (c != at)
        {
            cat_sort_on_ = all && c == last;
            cat_search_on_ = all && c == last - 1;
            if (!cat_sort_on_ && !cat_search_on_)
            {
                cat_chip_ = c;
                col_ = 0;
                cscroll_ = cscroll_to_ = 0;
            }
            play(int(Sfx::Move));
        }
        if (cat_search_on_ && pressed(pad::BtnCross))
        {
            open_kb(3); /* (2.3.0) the grid follows what's typed */
            col_ = 0;
            cscroll_ = cscroll_to_ = 0;
            return true;
        }
        if (cat_search_on_ && pressed(pad::BtnSquare) && !search_.empty())
        {
            search_.clear();
            play(int(Sfx::Back));
            return true;
        }
        if (cat_sort_on_ && pressed(pad::BtnCross))
        {
            prefs_.all_sort = (prefs_.all_sort + 1) % 4;
            save_prefs();
            col_ = 0;
            cscroll_ = cscroll_to_ = 0;
            play(int(Sfx::Select));
            return true;
        }
        if ((d || pressed(pad::BtnCross)) && n > 0) { cat_on_chips_ = false; cat_sort_on_ = false; cat_search_on_ = false; play(int(Sfx::Move)); }
        return true;
    }
    if (n == 0)
    {
        if (u && has_chips) { cat_on_chips_ = true; play(int(Sfx::Move)); }
        return true;
    }
    col_ = std::clamp(col_, 0, n - 1);
    int to = col_;
    if (moves) to = std::clamp(col_ + moves, 0, n - 1);
    if (d) to = std::min(n - 1, col_ + kPerRow);
    if (u)
    {
        if (col_ < kPerRow)
        {
            if (has_chips) { cat_on_chips_ = true; play(int(Sfx::Move)); }
            return true;
        }
        to = col_ - kPerRow;
    }
    if (to != col_) { col_ = to; play(int(Sfx::Move)); }
    focus_id_ = list[std::size_t(col_)]->id;
    if (pressed(pad::BtnCross)) open(focus_id_);
    else if (pressed(pad::BtnSquare) && !list[std::size_t(col_)]->soon) toggle_pin(focus_id_);
    return true;
}

void Store::draw_category(Gfx &gfx)
{
    const auto chips = category_chips(tab_);
    const auto list = category_apps(tab_);
    const int n = int(list.size());
    std::size_t total = 0;
    const std::string src = tab_ >= kSourceTab && std::size_t(tab_ - kSourceTab) < src_tabs_.size() ? src_tabs_[std::size_t(tab_ - kSourceTab)] : "";
    if (tab_ == AllApps)
        for (const App &a : catalog_.apps) total += a.beta ? 0 : 1;
    else
        for (const App &a : catalog_.apps) total += in_tab(a, category_of(tab_), src) ? 1 : 0;
    const char *noun = tab_ == Games ? "game" : tab_ == Emulators ? "emulator" : tab_ == Tools ? "tool" : "app";
    draw_tab_header(gfx, tab_name(tab_),
                    std::to_string(total) + " " + noun + (total == 1 ? "" : "s"));
    float list_top = kContent;
    if (tab_ == AllApps)
    {
        /* (2.2.3) Library's Sort, at the right of its row: Cross on it picks the next. */
        static const char *sorts[4] = {"Name", "Popular", "New", "Updated"};
        const std::string label = std::string("Sort: ") + sorts[std::clamp(prefs_.all_sort, 0, 3)];
        const float w = gfx.measure(Font::SemiBold, 18, label) + 44;
        const float x = 1824 - w;
        const bool on = cat_on_chips_ && cat_sort_on_;
        glass(gfx, x, kTop, w, 46, 23, kGlass, false);
        if (on) ring(gfx, x, kTop, w, 46, 23, 1.f, true);
        gfx.text_mid(Font::SemiBold, 18, x + w / 2, kTop + 23, C(kFrost), Align::Center, label);
        /* (2.3.0) Search, left of Sort: what's typed, or the word. */
        const std::string sl = search_.empty() ? std::string("Search") : "Search: " + fit(gfx, Font::SemiBold, 18, 260, search_);
        const float sw = gfx.measure(Font::SemiBold, 18, sl) + 44 + 24, sx = x - 12 - sw;
        const bool son = cat_on_chips_ && cat_search_on_;
        if (!search_.empty()) gfx.panel(sx, kTop, sw, 46, C(kCyan, 0.22f), 1.f, 23, C(kCyan, 0.6f), 1.25f);
        else glass(gfx, sx, kTop, sw, 46, 23, kGlass, false);
        if (son) ring(gfx, sx, kTop, sw, 46, 23, 1.f, true);
        gfx.panel(sx + 20, kTop + 15, 12, 12, Color{0, 0, 0, 0}, 1.f, 6, C(kFrost), 2.f); /* a magnifier */
        gfx.panel(sx + 30, kTop + 27, 7, 2.5f, C(kFrost), 1.f, 1);
        gfx.text_mid(Font::SemiBold, 18, sx + 44 + (sw - 44) / 2 - 12, kTop + 23, C(kFrost), Align::Center, sl);
    }
    if (chips.size() > 1)
    {
        /* The labels in this part of the store: one picked filters the grid. */
        float x = 96;
        for (int i = 0; i < int(chips.size()); ++i)
        {
            const bool picked = i == cat_chip_, on = cat_on_chips_ && picked && !cat_sort_on_ && !cat_search_on_;
            const float w = gfx.measure(Font::SemiBold, 18, chips[std::size_t(i)]) + 40;
            if (x + w > 1824) break;
            if (picked) gfx.panel(x, kTop, w, 46, C(kFrost), 0.92f, 23);
            else glass(gfx, x, kTop, w, 46, 23, kGlass, false);
            if (on) ring(gfx, x, kTop, w, 46, 23, 1.f, true);
            gfx.text_mid(picked ? Font::Bold : Font::SemiBold, 18, x + w / 2, kTop + 23, picked ? C(kMidnight) : C(kFrost),
                         Align::Center, chips[std::size_t(i)]);
            x += w + 10;
        }
    }
    else
        list_top = kTop + 10;
    if (n == 0)
    {
        gfx.text_mid(Font::Regular, 20, 96, list_top + 30, C(kMuted), Align::Left,
                     search_.empty() || tab_ != AllApps ? std::string("Nothing here yet.") : "Nothing matches \"" + search_ + "\". Square on Search clears it.");
        return;
    }
    const float view_h = kBottom - list_top;
    const int rows = (n + kPerRow - 1) / kPerRow, r = std::clamp(col_, 0, n - 1) / kPerRow;
    keep_in_view(cscroll_, cscroll_to_, float(r) * kTilePitch, float(r) * kTilePitch + kTilePitch - 30, view_h,
                 float(rows) * kTilePitch);
    gfx.clip(0, list_top - 12, gfx.design_w(), kBottom - list_top + 12);
    for (int i = 0; i < n; ++i)
    {
        float &f = focus_anim_[std::min(i, 511)];
        f = approach(f, i == col_ && !cat_on_chips_ ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
        const float y = list_top + 14 + float(i / kPerRow) * kTilePitch - cscroll_;
        if (y > kBottom || y + kTilePitch < list_top - 20) continue;
        draw_tile(gfx, *list[std::size_t(i)], 96 + float(i % kPerRow) * (kTileS + kTileGap), y, kTileS, ease_out(f));
    }
    gfx.unclip();
    edge_fade(gfx, list_top - 12, kBottom, cscroll_ > 0.5f); /* (2.2.3) */
    if (float(rows) * kTilePitch - cscroll_ > view_h + 8)
        draw_more(gfx);
}

/* ---- Settings > Customize ------------------------------------------------------------ */

namespace
{
/* (2.2.2) Start on, Tabs, Library view, Library order, Favorites on Discover, Icon colors, Background,
 * Motion, Advanced mode, and (Advanced mode only) Install new apps to. (2.2.3) Shown in groups:
 * Tabs, Look, Discover, Motion, Advanced (kCusOrder: the rows in the order shown). */
const int kCustomRows = 10;
const float kCusPitch = 80, kCusHead = 50;
const int kCusOrder[kCustomRows] = {0, 1, 2, 3, 5, 6, 4, 7, 8, 9};
const char *cus_group(int shown) /* the group a row starts, "" when it's in the one above's */
{
    switch (shown)
    {
    case 0: return "TABS";
    case 2: return "LOOK";
    case 6: return "DISCOVER";
    case 7: return "MOTION";
    case 8: return "ADVANCED";
    default: return "";
    }
}
} // namespace

bool Store::update_customize(float dt, bool l, bool r, bool u, bool d)
{
    (void)dt;
    const int rows = prefs_.advanced ? kCustomRows : kCustomRows - 1;
    const int to = std::clamp(cus_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, rows - 1);
    if (to != cus_row_) { cus_row_ = to; play(int(Sfx::Move)); }
    const int step = r || pressed(pad::BtnCross) ? 1 : l ? -1 : 0;
    if (!step) return false;
    switch (kCusOrder[std::clamp(cus_row_, 0, kCustomRows - 1)])
    {
    case 0:
    {
        /* (2.2.3) The tabs there are now: Discover, Library, My Apps, and the parts of the store
         * that have their own. */
        std::vector<int> kinds;
        for (int t : tab_order())
            if (t != Settings) kinds.push_back(t);
        if (kinds.empty()) kinds.push_back(Discover);
        int at = 0;
        for (int i = 0; i < int(kinds.size()); ++i) if (kinds[std::size_t(i)] == prefs_.start_tab) at = i;
        const int n = int(kinds.size());
        prefs_.start_tab = kinds[std::size_t((at + step + n) % n)];
        break;
    }
    case 1:
        if (!pressed(pad::BtnCross) && !r) return false;
        open_panel(PanelTabs); /* (2.2.2) */
        return true;
    case 2: prefs_.lib_view = 1 - prefs_.lib_view; break;
    case 3: prefs_.lib_order = (prefs_.lib_order + step + 3) % 3; break;
    case 4: prefs_.pins_on_discover = !prefs_.pins_on_discover; break;
    case 5: prefs_.icon_colors = 1 - prefs_.icon_colors; break; /* (2.2.2) */
    case 6: prefs_.backdrop = 1 - prefs_.backdrop; break;
    case 7: prefs_.reduced_motion = !prefs_.reduced_motion; break;
    case 8: prefs_.advanced = !prefs_.advanced; break;
    default:
    {
        /* (2.0) Where new apps install: the console, or a connected drive. */
        places_t_ = -1; /* looked for again now */
        const auto places = install_places_now();
        const std::string now = engine().install_root();
        int at = 0;
        for (int i = 0; i < int(places.size()); ++i) if (places[std::size_t(i)].path == now) at = i;
        if (places.size() < 2) { play(int(Sfx::Error)); return true; }
        engine().set_install_root(places[std::size_t((at + step + int(places.size())) % int(places.size()))].path);
        play(int(Sfx::Select));
        return true;
    }
    }
    save_prefs();
    play(int(Sfx::Select));
    return true;
}

void Store::draw_customize(Gfx &gfx, float cx, float top, float cw)
{
    gfx.text_mid(Font::ExtraBold, 38, cx, top + 48 + 24, C(kFrost), Align::Left, "Customize");
    const float list_top = top + 48 + 48 + 10, list_bottom = 1080 - 110 - 20;
    const char *orders[3] = {"Name", "Recently installed", "Updates first"};
    struct Row
    {
        const char *title, *help;
        std::string value;
        int sw = -1; /* (2.2.3) a switch: 1 on, 0 off; -1 a value to step through */
    };
    std::string tabs_value = "As the store sets them";
    if (prefs_.tabs_custom)
    {
        int n = 0;
        for (int t : tab_order()) n += is_category(t) && t != AllApps ? 1 : 0;
        tabs_value = n <= 0 ? std::string("No extra tabs") : n == 1 ? std::string("1 extra tab") : std::to_string(n) + " extra tabs";
    }
    const Row rows[kCustomRows] = {
        {"Start on", "The tab the store opens on.", tab_name(prefs_.start_tab)},
        {"Tabs", "Give Games, Emulators, Apps or Tools a tab of their own.", tabs_value},
        {"My Apps view", "A grid, or a list with more about each app.", prefs_.lib_view == 0 ? "Grid" : "List"},
        {"My Apps order", "Favorites always come first.", orders[prefs_.lib_order]},
        {"Favorites on Discover", "A row of your favorites at the top of Discover. Square adds an app.", "", prefs_.pins_on_discover ? 1 : 0},
        {"Icon colors", "The icons for Games, Emulators, Apps and Tools.", prefs_.icon_colors == 0 ? "Match the logo" : "Colorful"},
        {"Background", "The focused app's art and colour behind everything, or a calm gradient.", prefs_.backdrop == 0 ? "App art" : "Calm"},
        {"Reduce motion", "Less movement: no shimmer, no easing, no sliding.", "", prefs_.reduced_motion ? 1 : 0},
        {"Advanced mode", "Shows title IDs and folders, and where new apps install.", "", prefs_.advanced ? 1 : 0},
        {"Install new apps to", "Updates go where each app already is. ShadowMountPlus finds apps in all of these.", install_label()},
    };
    const int nrows = prefs_.advanced ? kCustomRows : kCustomRows - 1;
    /* Where each shown row is, with its group's heading above the first of a group. */
    float ys[kCustomRows] = {};
    float y = 0;
    for (int k = 0; k < nrows; ++k)
    {
        if (*cus_group(k)) y += kCusHead;
        ys[k] = y;
        y += kCusPitch;
    }
    const float total = y, view = list_bottom - list_top;
    /* Scrolled just enough to show the focused row whole (and its heading, at the top of a group). */
    const int fr = std::clamp(in_section_ ? cus_row_ : 0, 0, nrows - 1);
    float want = cus_scroll_to_;
    const float row_top = ys[fr] - (*cus_group(fr) ? kCusHead : 0.f);
    if (row_top < want) want = row_top;
    if (ys[fr] + kCusPitch > want + view) want = ys[fr] + kCusPitch - view;
    want = std::clamp(want, 0.f, std::max(0.f, total - view));
    cus_scroll_to_ = want;
    cus_scroll_ = prefs_.reduced_motion ? want : approach(cus_scroll_, want, 1.f / 60.f, 12.f);
    gfx.push_clip(cx - 30, list_top - 4, cw + 60, view + 8);
    for (int k = 0; k < nrows; ++k)
    {
        const float ry = list_top + ys[k] - cus_scroll_;
        /* Only whole rows (the arrows say there's more). */
        if (ry + kCusPitch > list_bottom + 6 || ry - (*cus_group(k) ? kCusHead : 0.f) < list_top - 6) continue;
        const Row &row = rows[kCusOrder[k]];
        if (const char *g = cus_group(k); *g)
            gfx.text_mid(Font::Bold, 15, cx + 8, ry - kCusHead + 30, C(kCyan), Align::Left, g, 2);
        const bool on = in_section_ && cus_row_ == k;
        if (!*cus_group(k)) gfx.panel(cx, ry, cw, 1.25f, kLine, 1.f, 0);
        if (on) ring(gfx, cx - 12, ry + 4, cw + 24, kCusPitch - 8, 18, 1.f, true);
        gfx.text_mid(Font::SemiBold, 22, cx + 8, ry + 16 + 13, C(kFrost), Align::Left, row.title);
        gfx.text_mid(Font::Regular, 17, cx + 8, ry + 16 + 26 + 6 + 10, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, cw - 420, row.help));
        const float mid = ry + kCusPitch / 2;
        if (row.sw >= 0)
        {
            draw_switch(gfx, cx + cw - 8 - 52, mid, row.sw == 1, on);
            continue;
        }
        /* The value, between its arrows when focused (Tabs: it opens). */
        const float vw = gfx.measure(Font::SemiBold, 19, row.value);
        const bool opens = kCusOrder[k] == 1;
        const float vx = cx + cw - 8 - (on ? 34 : 0);
        gfx.text_mid(Font::SemiBold, 19, vx, mid, C(kFrost), Align::Right, row.value);
        if (on && !opens)
            gfx.glyph(Glyph::Arrow, vx - vw - 18, mid, 12, C(kFrost, 0.7f), 3.14159265f * 1.5f);
        if (on)
            gfx.glyph(Glyph::Arrow, cx + cw - 14, mid, 12, C(kFrost, 0.7f), 3.14159265f * 0.5f);
    }
    gfx.pop_clip();
    /* More rows above or below: a faint arrow at that end. */
    if (cus_scroll_ > 1) gfx.glyph(Glyph::Arrow, cx + cw / 2, list_top - 12, 13, C(kFrost, 0.5f), 0);
    if (cus_scroll_ + view < total - 1) gfx.glyph(Glyph::Arrow, cx + cw / 2, list_bottom + 10, 13, C(kFrost, 0.5f), 3.14159265f);
}

const std::vector<Place> &Store::install_places_now() const
{
    /* The drives, looked for at most every two seconds (not every frame). */
    if (places_t_ < 0 || time_ - places_t_ > 2.f)
    {
        places_now_ = engine().install_places();
        places_t_ = time_;
    }
    return places_now_;
}

std::string Store::install_label() const
{
    const std::string now = engine().install_root();
    for (const Place &p : install_places_now())
        if (p.path == now) return p.label;
    /* Chosen before, on a drive that isn't connected now. */
    return now.rfind("/mnt/", 0) == 0 ? now.substr(5, now.find('/', 5) - 5) + " (not connected)" : "Console storage";
}
} // namespace recomp

namespace recomp
{
/* ---- What the buttons do, for the hints ------------------------------------------- */

std::string Store::square_hint() const
{
    std::string id;
    if (tab_ == Discover)
    {
        const auto lines = discover_lines();
        if (lines.empty()) return "";
        const int k = lines[std::size_t(std::clamp(row_, 0, int(lines.size()) - 1))].kind;
        if (k == 0 || k == 5) return ""; /* the banner, the category buttons */
        id = focus_id_;
    }
    else if (is_category(tab_))
    {
        if (cat_on_chips_) return "";
        id = focus_id_;
    }
    else if (tab_ == Library && !lib_bar_ && lib_view_ == 0 && !my_on_row_ && !library_apps().empty())
        id = focus_id_;
    else if (tab_ == Library && !lib_bar_ && lib_view_ == 1 && !update_apps().empty())
        return "What's new";
    else if (tab_ == Library && !lib_bar_ && lib_view_ == 3)
        return engine().storage_lost() || con_select_ ? "" : prefs_.con_view == 0 ? "List view" : "Grid view"; /* (2.2.2) */
    const App *a = id.empty() ? nullptr : find(id);
    if (!a || a->soon) return "";
    return pinned(id) ? "Remove favorite" : "Favorite";
}

std::string Store::cross_hint() const
{
    if (tab_ == Library && lib_bar_) return "";
    if (tab_ == Library && lib_view_ == 3) /* (2.2) */
    {
        if (engine().storage_lost()) return "";
        if (con_on_row_) return "Choose";
        if (con_select_) return "Mark";
        return "Manage";
    }
    if (tab_ == Library && lib_view_ == 4) return engine().drives().empty() ? "" : drv_col_ == 1 ? "Move" : "Its apps"; /* (2.2.3) Drives; (2.3.0) its apps */
    if (tab_ == Library && lib_view_ == 0 && my_on_row_) return "Choose";
    if (tab_ == Library && lib_view_ == 1) return update_apps().empty() && engine().store_update().empty() ? "" : "Update";
    if (tab_ == Library && lib_view_ == 0 && library_apps().empty()) return "";
    if (is_category(tab_) && cat_on_chips_) return "";
    if (tab_ == Settings && section_ == SecAbout && !in_section_) return "What's new"; /* (2.2.2) */
    if (tab_ == Discover && on_collection_row()) return "See all"; /* (2.3.0) */
    return tab_ == Settings ? "Select" : "Open";
}
} // namespace recomp
