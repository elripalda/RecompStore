/* Recomp Store - the app's screens and state, drawn after the design mockup
 * (Main.dc.html / Boot.dc.html): the focused game's art frosted behind
 * everything, glass panels, Discover / Library / Latest Updates / Settings
 * (L1 / R1), and a game page with the download panel and the player's own
 * game files on the right. All sizes are the mockup's, in its 1920x1080
 * space; the screen draws them at its own resolution (4K: twice as sharp).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"

#include "porpoise_pad.hpp"
#include "porpoise_paths.hpp"
#include "porpoise_sound.hpp"
#include "ui_app_common.hpp"

#include <cctype>
#include <algorithm>
#include <cmath>
#include <cstdio>

namespace recomp
{
using namespace porpoise::ui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

namespace
{
Color C(std::uint32_t hex, float a = 1) { return rgba(hex, a); }
const std::uint32_t kMidnight = 0x0B1220, kFrost = 0xF4F8FC, kCyan = 0x35D6F4, kMuted = 0xAAB6C6, kGreen = 0x5FE3A1,
                    kSoft = 0xCDD6E2, kDim = 0x95A2B4, kBody = 0xE1E8F1;
const Color kGlass = {1, 1, 1, 0.08f}, kRim = {1, 1, 1, 0.16f}, kLine = {1, 1, 1, 0.12f};

float clamp01(float t) { return std::clamp(t, 0.f, 1.f); }
float ease_out(float t)
{
    t = clamp01(t);
    return 1 - (1 - t) * (1 - t) * (1 - t);
}
/* cubic-bezier(.2,.9,.25,1.15)-like: a little past, then back. */
float ease_back(float t)
{
    t = clamp01(t);
    const float c = 1.4f;
    return 1 + (c + 1) * std::pow(t - 1, 3.f) + c * std::pow(t - 1, 2.f);
}
float approach(float cur, float target, float dt, float rate) { return cur + (target - cur) * std::min(1.f, dt * rate); }

std::string version_key(std::string v)
{
    /* "v1.0", "1.0" and "1.0.0" are the same version. */
    if (!v.empty() && (v[0] == 'v' || v[0] == 'V'))
        v.erase(0, 1);
    while (v.size() > 2 && v.compare(v.size() - 2, 2, ".0") == 0 && std::count(v.begin(), v.end(), '.') > 0)
        v.erase(v.size() - 2);
    return v;
}

/* object-fit: cover with a focus point and zoom: the part of the picture a box shows. */
void cover_uv(const Texture *t, float bw, float bh, float fx, float fy, float zoom, float uv[4])
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
std::string md_inline(const std::string &in)
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
std::string long_date(const std::string &d)
{
    static const char *months[12] = {"January", "February", "March", "April", "May", "June", "July",
                                     "August", "September", "October", "November", "December"};
    if (d.size() < 10) return d;
    const int m = std::atoi(d.substr(5, 2).c_str()), day = std::atoi(d.substr(8, 2).c_str());
    if (m < 1 || m > 12 || day < 1) return d;
    return std::string(months[m - 1]) + " " + std::to_string(day) + ", " + d.substr(0, 4);
}

std::string base_name(const std::string &p)
{
    const std::size_t s = p.find_last_of('/');
    return s == std::string::npos ? p : p.substr(s + 1);
}

std::string size_text(std::uint64_t b)
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

const char *kTabNames[4] = {"Discover", "Library", "Latest Updates", "Settings"};
} // namespace

/* ---- Setup ------------------------------------------------------------------------- */

void Store::start(Gfx &gfx, const std::string &data_dir, bool freed, float px_scale)
{
    gfx_ = &gfx;
    data_ = data_dir;
    freed_ = freed;
    px_scale_ = std::max(1.f, px_scale);
    const std::string assets = gfx.asset_dir();
    /* Each picture loaded no bigger than it is drawn (no mipmaps: a big picture drawn
     * small shimmers), at this screen's pixels. */
    auto side = [&](float design) { return int(std::ceil(design * px_scale_)); };
    logo_small_ = gfx.texture_file(assets + "/brand/logo-512.png", side(64));
    logo_ = gfx.texture_file(assets + "/brand/logo-512.png", side(128));
    const char *pieces[3] = {"mark-arrow", "mark-bowl", "mark-leg"};
    for (int i = 0; i < 3; ++i)
        mark_[i] = gfx.texture_file(assets + "/brand/" + pieces[i] + ".png", side(320));
    mark_glow_ = gfx.texture_file(assets + "/brand/mark-glow.png", side(512));
    hint_cross_ = gfx.texture_file(assets + "/ui/hint-cross.png", side(32));
    hint_circle_ = gfx.texture_file(assets + "/ui/hint-circle.png", side(32));
    chevron_ = gfx.texture_file(assets + "/ui/chevron-left.png", side(24));
    shield_ = gfx.texture_file(assets + "/ui/shield.png", side(24));
    disc_icon_ = gfx.texture_file(assets + "/ui/disc.png", side(32));
    folder_icon_ = gfx.texture_file(assets + "/ui/folder.png", side(32));

    /* The brand gradient, one texture: midnight, into deep blue at 45%, into teal. */
    {
        const int h = 270;
        std::vector<std::uint8_t> px(std::size_t(h) * 2 * 4);
        const float stops[3][4] = {{0.f, 0x0B, 0x12, 0x20}, {0.45f, 0x0D, 0x22, 0x33}, {1.f, 0x0F, 0x34, 0x46}};
        for (int y = 0; y < h; ++y)
        {
            const float t = (y + 0.5f) / h;
            const int s = t < stops[1][0] ? 0 : 1;
            const float k = (t - stops[s][0]) / (stops[s + 1][0] - stops[s][0]);
            for (int x = 0; x < 2; ++x)
                for (int c = 0; c < 3; ++c)
                    px[(std::size_t(y) * 2 + x) * 4 + c] =
                        std::uint8_t(std::lround(stops[s][c + 1] + (stops[s + 1][c + 1] - stops[s][c + 1]) * k));
            px[(std::size_t(y) * 2) * 4 + 3] = px[(std::size_t(y) * 2 + 1) * 4 + 3] = 255;
        }
        gradient_ = gfx.texture_rgba(px.data(), 2, h);
    }
    porpoise::sound::load(assets);
    porpoise::sound::set_effects(true, 0.8f);
    porpoise::sound::set_music(false, 0);
    engine().start(data_dir);
}

void Store::play(int effect)
{
    if (sounds_on_)
        porpoise::sound::play(static_cast<Sfx>(effect));
}

bool Store::nav(std::uint32_t bit, float &timer, float dt)
{
    if (!(held_ & bit))
    {
        timer = 0;
        return false;
    }
    if (!(prev_ & bit))
    {
        timer = 0.38f;
        return true;
    }
    timer -= dt;
    if (timer <= 0)
    {
        timer = 0.085f;
        return true;
    }
    return false;
}

void Store::sync_catalog()
{
    const unsigned r = engine().revision();
    if (r == revision_)
        return;
    revision_ = r;
    Catalog c;
    if (engine().catalog(c))
        catalog_ = c;
}

Store::Art &Store::art(const App &a)
{
    Art &t = art_[a.id];
    if (gfx_)
    {
        /* A picture loads over a few frames: the first call queues it (pending), and the
         * calls after collect it once it is decoded. Keep asking while it is pending. */
        if (!a.icon_file.empty() && t.icon_file != a.icon_file)
        {
            t.icon_file = a.icon_file;
            t.icon = nullptr;
            t.icon_wait = true;
        }
        if (!t.icon && t.icon_wait)
            t.icon = gfx_->texture_file_async(t.icon_file, &t.icon_wait, int(std::ceil(272 * px_scale_)));
        if (!a.hero_file.empty() && t.hero_file != a.hero_file)
        {
            t.hero_file = a.hero_file;
            t.hero = t.blur = nullptr;
            t.hero_wait = t.blur_wait = true;
        }
        if (!t.hero && t.hero_wait)
            t.hero = gfx_->texture_file_async(t.hero_file, &t.hero_wait, 4096);
        if (!a.logo_file.empty() && t.logo_file != a.logo_file)
        {
            t.logo_file = a.logo_file;
            t.logo = nullptr;
            t.logo_wait = true;
        }
        if (!t.logo && t.logo_wait)
            t.logo = gfx_->texture_file_async(t.logo_file, &t.logo_wait, int(std::ceil(900 * px_scale_)));
        /* The frosted copy behind everything: small, blurred and saturated as it loads. */
        if (!t.blur && t.blur_wait && !t.hero_wait)
            t.blur = gfx_->texture_file_async(t.hero_file + "#blur", &t.blur_wait, 256);
    }
    return t;
}

const App *Store::find(const std::string &id) const
{
    for (const App &a : catalog_.apps)
        if (a.id == id)
            return &a;
    for (const App &a : catalog_.soon)
        if (a.id == id)
            return &a;
    return nullptr;
}

/* ---- Discover's lines ---------------------------------------------------------------- */
namespace
{
const float kTile = 256, kTileGap = 36, kTileLine = 336; /* a tile, the room between, a line of them with the words under */
const int kRowShown = 6, kGridPer = 6, kWideShown = 3;
const float kWideW = (1728 - kTileGap * 2) / 3, kWideH = 310, kWideLine = 310 + 84;
const float kHead = 54, kSectionGap = 48, kGridGap = 20, kBannerH = 400;
const float kDiscTop = 136, kViewTop = 118, kViewBottom = 1000; /* Discover's top on screen; what's shown of the lists */
} // namespace

std::vector<Store::DLine> Store::discover_lines() const
{
    std::vector<DLine> out;
    float y = 0;
    const auto feat = featured_apps();
    if (!feat.empty())
    {
        DLine b;
        b.kind = 0;
        b.h = kBannerH;
        b.items = feat;
        out.push_back(b);
        y = kBannerH + kSectionGap;
    }
    auto section = [&](int kind, const std::string &title, const std::vector<const App *> &items) {
        if (items.empty()) return;
        const float head = kind == 4 || title.empty() ? 0.f : kHead;
        y += head;
        if (kind == 2)
        {
            for (std::size_t i = 0; i < items.size(); i += kGridPer)
            {
                DLine l;
                l.kind = 2;
                l.y = y;
                l.h = kTileLine;
                if (i == 0) { l.head = head; l.title = title; }
                l.items.assign(items.begin() + std::ptrdiff_t(i), items.begin() + std::ptrdiff_t(std::min(items.size(), i + kGridPer)));
                out.push_back(l);
                y += kTileLine + kGridGap;
            }
            y += kSectionGap - kGridGap;
            return;
        }
        DLine l;
        l.kind = kind;
        l.y = y;
        l.h = kind == 4 ? kBannerH : kind == 3 ? kWideLine : kTileLine;
        l.head = head;
        l.title = title;
        l.items = items;
        out.push_back(l);
        y += l.h + kSectionGap;
    };
    std::vector<const App *> all, soon;
    for (const App &a : catalog_.apps) all.push_back(&a);
    for (const App &a : catalog_.soon) soon.push_back(&a);
    if (catalog_.sections.empty())
    {
        /* A catalog from before sections: every game, then what's coming. */
        section(2, "Latest games", all);
        section(1, "Coming soon", soon);
    }
    else
        for (const Section &sec : catalog_.sections)
        {
            if (sec.type == "spotlight")
            {
                for (const App &a : catalog_.apps)
                    if (!sec.ids.empty() && a.id == sec.ids[0]) section(4, sec.title, {&a});
            }
            else if (sec.type == "soon")
                section(sec.layout == "wide" ? 3 : 1, sec.title, soon);
            else
            {
                std::vector<const App *> items;
                if (sec.all)
                    items = all;
                else
                    for (const std::string &id : sec.ids)
                        for (const App &a : catalog_.apps)
                            if (a.id == id) { items.push_back(&a); break; }
                section(sec.layout == "grid" ? 2 : sec.layout == "wide" ? 3 : 1, sec.title, items);
            }
        }
    return out;
}

int Store::line_first(const DLine &l, int col) const
{
    const int n = int(l.items.size());
    if (l.kind == 1) return std::clamp(col - kRowShown + 2, 0, std::max(0, n - kRowShown));
    if (l.kind == 3) return std::clamp(col - 1, 0, std::max(0, n - kWideShown));
    return 0;
}

float Store::item_x(const DLine &l, int i, int first) const
{
    if (l.kind == 3) return 96 + float(i - first) * (kWideW + kTileGap);
    if (l.kind == 1) return 96 + float(i - first) * (kTile + kTileGap);
    if (l.kind == 2) return 96 + float(i) * (kTile + kTileGap);
    return 96;
}

/* The version to have: the newest release on GitHub with the game's zip once the store
 * has looked, else the one the catalog names. */
std::string Store::version_of(const App &a) const
{
    std::string n = engine().newest_version(a);
    if (n.empty() || (!a.version.empty() && version_newer(a.version, n))) return a.version; /* the catalog may know first */
    if (n.size() > 1 && (n[0] == 'v' || n[0] == 'V') && std::isdigit(static_cast<unsigned char>(n[1]))) n.erase(0, 1); /* "v1.0.4" reads 1.0.4 */
    return n;
}

std::string Store::state_of(const App &a, bool *update_ready) const
{
    const std::string v = engine().installed(a.id);
    if (update_ready)
        *update_ready = false;
    if (store_too_old(a) && (v.empty() || v == "?"))
        return "Needs Recomp Store " + a.min_store;
    if (v.empty())
        return a.kind == "original" ? "Not installed" + (a.size.empty() ? std::string() : " \xC2\xB7 " + a.size)
                                    : (a.author.empty() ? "Not installed" : a.author);
    if (v == "?")
        return "On your PS5 \xC2\xB7 not managed yet";
    const std::string want = version_of(a);
    if (!want.empty() && version_key(v) != version_key(want) && !version_newer(v, want) && !store_too_old(a))
    {
        if (update_ready)
            *update_ready = true;
        return "Update " + want + " ready";
    }
    return "Installed \xC2\xB7 " + v;
}

std::vector<const App *> Store::installed_apps() const
{
    std::vector<const App *> out;
    for (const App &a : catalog_.apps)
        if (!engine().installed(a.id).empty())
            out.push_back(&a);
    return out;
}

std::vector<const App *> Store::featured_apps() const
{
    std::vector<const App *> out;
    for (const std::string &id : catalog_.featured)
        if (const App *a = find(id))
            out.push_back(a);
    if (out.empty())
        for (const App &a : catalog_.apps)
            if (!a.hero_url.empty() && out.size() < 3)
                out.push_back(&a);
    return out;
}

bool Store::show_setup(const App &a) const
{
    return a.has_setup && !a.slots.empty() && !engine().installed(a.id).empty();
}

std::vector<Store::Control> Store::page_controls(const App &a) const
{
    std::vector<Control> c{{Control::Primary, -1}};
    if (show_setup(a))
    {
        for (int i = 0; i < int(a.slots.size()); ++i)
            c.push_back({Control::Slot, i});
        c.push_back({Control::Setup, -1});
    }
    const std::string inst = engine().installed(a.id);
    if (!inst.empty() && inst != "?")
        c.push_back({Control::Delete, -1});
    return c;
}

/* Installed, and every game file it needs in place: it can be opened. */
bool Store::game_ready(const App &a) const
{
    if (!show_setup(a))
        return true;
    for (const Slot &s : a.slots)
        if (s.required && !slot_ready(a, s))
            return false;
    return true;
}

void Store::open(const std::string &id)
{
    page_id_ = id;
    page_open_ = true;
    page_focus_ = 0;
    notice_.clear();
    reset_notes();
    if (const App *a = find(id))
        if (!a->soon && a->source == "github" && !a->repo.empty())
            engine().want_notes(a->repo);
    play(int(Sfx::Open));
}

void Store::preview_open(const std::string &id)
{
    booted_ = true;
    boot_t_ = 10;
    enter_t_ = 1;
    page_id_ = id;
    page_open_ = true;
    page_t_ = 1;
    picker_ = false;
    confirm_ = false;
    reset_notes();
    if (const App *a = find(id))
        if (a->source == "github" && !a->repo.empty())
            engine().want_notes(a->repo);
    focus_id_ = id;
    backdrop_id_ = id;
}

void Store::preview_picker(const std::string &slot, const std::string &path)
{
    if (const App *a = find(page_id_))
        for (int i = 0; i < int(a->slots.size()); ++i)
            if (a->slots[i].key == slot)
            {
                open_picker(*a, i);
                picker_go(path);
                picker_t_ = 1;
            }
}

void Store::close()
{
    page_open_ = false;
    notes_focus_ = notes_open_ = false;
    play(int(Sfx::Back));
}

void Store::press_primary()
{
    const App *a = find(page_id_);
    if (!a)
        return;
    if (a->soon)
    {
        play(int(Sfx::Error)); /* not out yet */
        return;
    }
    const JobState job = engine().job();
    if (job.phase == Phase::Working)
    {
        play(int(Sfx::Error));
        return;
    }
    /* A game this store is too old for: only one already here and set up opens. */
    if (store_too_old(*a) && !(game_ready(*a) && !engine().installed(a->id).empty() && engine().installed(a->id) != "?"))
    {
        notice_ = a->name + " needs Recomp Store " + a->min_store + " or newer. Update the store from Latest Updates first.";
        notice_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    bool update = false;
    state_of(*a, &update);
    const std::string inst = engine().installed(a->id);
    if (inst == "?")
    {
        /* Already on the console, put there by hand: take it over, no download. */
        std::string v;
        if (engine().adopt(a->id, v))
        {
            bool up2 = false;
            state_of(*a, &up2);
            notice_ = up2 ? "Recomp Store now looks after " + a->name + ". Version " + v + " is on your PS5; " + version_of(*a) + " is ready to install."
                          : "Recomp Store now looks after " + a->name + ". Its updates will show up here.";
            notice_bad_ = false;
            play(int(Sfx::Complete));
        }
        else
        {
            notice_ = "Couldn't take over " + a->name + ". Its folder may have moved.";
            notice_bad_ = true;
            play(int(Sfx::Error));
        }
        return;
    }
    if (inst.empty() || update)
    {
        if (!freed_)
        {
            notice_ = "Recomp Store can't write to /data. Start etaHEN, then open the store again.";
            notice_bad_ = true;
            play(int(Sfx::Error));
            return;
        }
        if (engine().install(a->id))
        {
            notice_.clear();
            play(int(Sfx::Install));
        }
        return;
    }
    if (!game_ready(*a))
    {
        /* Installed, but its game files aren't set up yet: on to the first file to choose. */
        const auto ctl = page_controls(*a);
        for (int i = 0; i < int(ctl.size()); ++i)
            if (ctl[std::size_t(i)].kind == Control::Slot) { page_focus_ = i; break; }
        notice_ = "Set up the game files below first.";
        notice_bad_ = false;
        play(int(Sfx::Move));
        return;
    }
    std::string why;
    if (!open_game(a->id, why))
    {
        notice_ = "Couldn't open " + a->name + ". " + why;
        notice_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    /* The launcher opens it once the store has closed. */
    opening_ = true;
    opening_t_ = 0;
    opening_poll_ = 0;
    notice_ = "Opening " + a->name + "\xE2\x80\xA6";
    notice_bad_ = false;
    play(int(Sfx::Select));
}

void Store::start_store_update()
{
    if (store_updating_ || engine().job().phase == Phase::Working)
    {
        if (!store_updating_)
        {
            store_note_ = "Wait for the current install or setup to finish first.";
            store_note_bad_ = true;
        }
        play(int(Sfx::Error));
        return;
    }
    if (!engine().download_store_update())
    {
        play(int(Sfx::Error));
        return;
    }
    store_note_.clear();
    store_note_bad_ = false;
    play(int(Sfx::Select));
}

void Store::press_setup(const App &a)
{
    if (engine().job().phase == Phase::Working)
    {
        play(int(Sfx::Error));
        return;
    }
    /* The first slot with a disc image picked and not yet in place. */
    for (const Slot &s : a.slots)
    {
        auto it = chosen_.find(a.id + "/" + s.key);
        if (it == chosen_.end() || !slot_kind_known(s.kind))
            continue;
        if (engine().setup(a.id, s.key, it->second))
        {
            notice_.clear();
            play(int(Sfx::Install));
        }
        return;
    }
    play(int(Sfx::Error));
}

/* ---- The file browser ------------------------------------------------------------- */

void Store::open_smp_picker()
{
    picker_mode_ = 1;
    picker_ = true;
    picker_t_ = 0;
    picker_col_ = 1;
    places_ = places();
    /* Where the file is now, else the console's storage. */
    picker_place_ = 0;
    for (int i = 0; i < int(places_.size()); ++i)
        if (places_[std::size_t(i)].path == "/data") picker_place_ = i;
    std::string start = places_.empty() ? std::string("/data") : places_[std::size_t(picker_place_)].path;
    const std::string last = smp_path();
    for (int i = 0; i < int(places_.size()); ++i)
        if (!last.empty() && last.rfind(places_[std::size_t(i)].path + "/", 0) == 0)
        {
            picker_place_ = i;
            start = last.substr(0, last.find_last_of('/'));
        }
    picker_root_ = places_.empty() ? "/data" : places_[std::size_t(picker_place_)].path;
    picker_go(start);
    play(int(Sfx::Open));
}

void Store::open_picker(const App &a, int slot)
{
    picker_mode_ = 0;
    picker_ = true;
    picker_t_ = 0;
    picker_slot_ = slot;
    picker_col_ = 1;
    places_ = places();
    /* Where the last image came from, or the first place. */
    picker_place_ = 0;
    std::string start = places_.empty() ? std::string("/data") : places_[0].path;
    const std::string last = slot < int(a.slots.size()) ? slot_source(a, a.slots[std::size_t(slot)]) : "";
    for (int i = 0; i < int(places_.size()); ++i)
        if (!last.empty() && last.rfind(places_[std::size_t(i)].path + "/", 0) == 0)
        {
            picker_place_ = i;
            start = last.substr(0, last.find_last_of('/'));
        }
    picker_root_ = places_.empty() ? "/data" : places_[std::size_t(picker_place_)].path;
    picker_go(start);
    play(int(Sfx::Open));
}

void Store::picker_go(const std::string &path)
{
    picker_path_ = path;
    picker_sel_ = 0;
    picker_top_ = 0;
    const char *ext = ".iso";
    if (picker_mode_ == 1)
        ext = ".elf";
    else if (const App *a = find(page_id_))
        if (picker_slot_ >= 0 && picker_slot_ < int(a->slots.size()))
        {
            const Slot &sl = a->slots[std::size_t(picker_slot_)];
            if (sl.kind == "stfs") ext = "stfs";
            else if (sl.kind == "image") ext = "image";
            else if (sl.kind == "file")
            {
                /* The slot's extensions, or every file. */
                picker_ext_ = sl.ext.empty() ? std::string("*") : sl.ext;
                for (char &c : picker_ext_) if (c == ',') c = ' ';
                ext = picker_ext_.c_str();
            }
        }
    picker_err_ = !list_dir(path, entries_, ext);
}

bool Store::update_picker(float dt)
{
    picker_t_ = std::min(1.f, picker_t_ + dt * 4.f);
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (l && picker_col_ == 1) { picker_col_ = 0; play(int(Sfx::Move)); }
    if (r && picker_col_ == 0) { picker_col_ = 1; play(int(Sfx::Move)); }
    if (picker_col_ == 0)
    {
        const int n = int(places_.size());
        const int p = std::clamp(picker_place_ + (d ? 1 : 0) - (u ? 1 : 0), 0, std::max(0, n - 1));
        if (p != picker_place_ && n > 0)
        {
            picker_place_ = p;
            picker_root_ = places_[std::size_t(p)].path;
            picker_go(picker_root_);
            play(int(Sfx::Move));
        }
        if (pressed(pad::BtnCross)) { picker_col_ = 1; play(int(Sfx::Select)); }
    }
    else
    {
        const int n = int(entries_.size());
        const int s = std::clamp(picker_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, std::max(0, n - 1));
        if (s != picker_sel_) { picker_sel_ = s; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCross) && n > 0)
        {
            const FsEntry &e = entries_[std::size_t(picker_sel_)];
            if (e.is_dir)
            {
                picker_go(e.path);
                play(int(Sfx::Select));
            }
            else if (picker_mode_ == 1)
            {
                set_smp_path(e.path);
                picker_ = false;
                settings_note_ = "ShadowMountPlus set. The store starts it after each install and game setup.";
                settings_note_bad_ = false;
                play(int(Sfx::Select));
            }
            else if (const App *a = find(page_id_))
            {
                const Slot &sl = a->slots[std::size_t(picker_slot_)];
                chosen_[a->id + "/" + sl.key] = e.path;
                picker_ = false;
                notice_.clear();
                /* Straight on to the Set up button. */
                const auto ctl = page_controls(*a);
                for (int i = 0; i < int(ctl.size()); ++i)
                    if (ctl[std::size_t(i)].kind == Control::Setup) page_focus_ = i;
                play(int(Sfx::Select));
            }
        }
    }
    if (pressed(pad::BtnCircle))
    {
        if (picker_col_ == 1 && picker_path_ != picker_root_ && picker_path_.size() > picker_root_.size())
        {
            const std::string up = picker_path_.substr(0, picker_path_.find_last_of('/'));
            const std::string from = picker_path_;
            picker_go(up.empty() ? "/" : up);
            for (int i = 0; i < int(entries_.size()); ++i)
                if (entries_[std::size_t(i)].path == from) picker_sel_ = i;
        }
        else
            picker_ = false;
        play(int(Sfx::Back));
    }
    /* Keep the chosen row in view. */
    const int rows = 6;
    if (picker_sel_ < picker_top_) picker_top_ = picker_sel_;
    if (picker_sel_ >= picker_top_ + rows) picker_top_ = picker_sel_ - rows + 1;
    return true;
}

/* ---- Input ------------------------------------------------------------------------- */

bool Store::update(const pad::State &p, float dt)
{
    prev_ = held_;
    held_ = p.buttons;
    /* The left stick moves like the D-pad. */
    if (p.left_x < -20000) held_ |= pad::BtnLeft;
    if (p.left_x > 20000) held_ |= pad::BtnRight;
    if (p.left_y < -20000) held_ |= pad::BtnUp;
    if (p.left_y > 20000) held_ |= pad::BtnDown;
    time_ += dt;
    sync_catalog();

    if (!booted_)
    {
        boot_t_ += dt;
        /* The boot plays at least through its logo and name, and stays while the catalog loads. */
        if (boot_t_ > 2.6f && (!catalog_.apps.empty() || !engine().loading()))
            booted_ = true;
        if (pressed(pad::BtnCircle) && boot_t_ > 0.5f && catalog_.apps.empty() && !engine().loading())
            return false;
        return true;
    }
    enter_t_ = std::min(1.f, enter_t_ + dt * 2.f);

    page_t_ = approach(page_t_, page_open_ ? 1.f : 0.f, dt, 9.f);
    notes_t_ = approach(notes_t_, notes_open_ ? 1.f : 0.f, dt, 10.f);
    tab_t_ = std::min(1.f, tab_t_ + dt * 2.2f);
    backdrop_t_ = std::min(1.f, backdrop_t_ + dt * 1.4f);

    /* Open: the store closes once the launcher has started, and it opens the game. */
    if (opening_)
    {
        opening_t_ += dt;
        opening_poll_ -= dt;
        if (opening_poll_ <= 0)
        {
            opening_poll_ = 0.15f;
            const int s = open_game_state();
            if (s > 0)
                return false;
            if (s < 0 || opening_t_ > 10.f)
            {
                if (s == 0)
                    open_game_cancel();
                opening_ = false;
                notice_ = "The launcher didn't start, so the game wasn't opened. Check that your payload loader (etaHEN or elfldr) is running, then try again.";
                notice_bad_ = true;
                play(int(Sfx::Error));
            }
        }
        if (opening_)
            return true; /* nothing else while it opens */
    }
    /* Updating the store: it closes once its installer has started. */
    if (store_updating_)
    {
        store_upd_t_ += dt;
        store_upd_poll_ -= dt;
        if (store_upd_poll_ <= 0)
        {
            store_upd_poll_ = 0.15f;
            const int s = update_store_state();
            if (s > 0)
                return false;
            if (s < 0 || store_upd_t_ > 10.f)
            {
                if (s == 0)
                    update_store_cancel();
                store_updating_ = false;
                store_note_ = "The installer didn't start, so nothing changed. Check that your payload loader is running, then try again.";
                store_note_bad_ = true;
                play(int(Sfx::Error));
            }
        }
        if (store_updating_)
            return true;
    }
    /* Installed from the copy built into an installer that couldn't reach GitHub: the
     * newest version, straight away, as soon as the store has found it. */
    {
        std::string v;
        if (engine().take_auto_update(v))
        {
            picker_ = confirm_ = false;
            page_open_ = notes_focus_ = notes_open_ = false;
            if (tab_ != Updates)
            {
                tab_dir_ = 1;
                tab_ = Updates;
                tab_t_ = 0;
            }
            col_ = row_ = 0;
            in_section_ = false;
            start_store_update();
            if (!v.empty() && (v[0] == 'v' || v[0] == 'V')) v.erase(0, 1);
            if (!store_note_bad_)
                store_note_ = "Getting the newest version, " + v + ", for you. The store closes, updates itself and opens again.";
            return true;
        }
    }
    /* Back from a game that wouldn't open: its page, saying why (once). */
    if (!open_checked_ && !catalog_.apps.empty())
    {
        open_checked_ = true;
        std::string id;
        unsigned result = 0;
        if (open_game_failure(id, result) && find(id))
        {
            open(id);
            char t[260];
            std::snprintf(t, sizeof t,
                          "%s didn't open (0x%08X). If it isn't on your home screen yet, start ShadowMountPlus "
                          "(Settings > General), give it a few seconds, then try again.",
                          find(id)->name.c_str(), result);
            notice_ = t;
            notice_bad_ = true;
        }
    }

    /* A job turning into its result. */
    const JobState job = engine().job();
    /* How fast it's going: measured over about a second, smoothed a little. */
    if (job.phase == Phase::Working)
    {
        if (job.step != speed_step_ || job.done < speed_done_)
        {
            speed_step_ = job.step;
            speed_t_ = time_;
            speed_done_ = job.done;
            speed_ = 0;
        }
        else if (time_ - speed_t_ >= 1.0)
        {
            const float now = float(double(job.done - speed_done_) / 1048576.0 / (time_ - speed_t_));
            speed_ = speed_ <= 0 ? now : speed_ * 0.5f + now * 0.5f;
            speed_t_ = time_;
            speed_done_ = job.done;
        }
    }
    else
        speed_step_.clear();
    if (job.kind == "storeupdate" && (job.phase == Phase::Done || job.phase == Phase::Failed))
    {
        /* The store's own update: downloaded and checked, it goes to the installer. */
        engine().acknowledge();
        std::string why;
        if (job.phase == Phase::Done && update_store(why))
        {
            store_updating_ = true;
            store_upd_t_ = 0;
            store_upd_poll_ = 0;
            store_note_ = "Downloaded. The store closes, updates itself and opens again.";
            store_note_bad_ = false;
            play(int(Sfx::Install));
        }
        else
        {
            store_note_ = job.phase == Phase::Failed ? job.error : why;
            store_note_bad_ = true;
            play(int(Sfx::Error));
        }
    }
    else if (job.phase == Phase::Done && job.kind == "bench")
    {
        play(int(Sfx::Complete));
        engine().acknowledge();
    }
    else if (job.phase == Phase::Done)
    {
        play(int(Sfx::Complete));
        const std::string done = job.kind == "setup" ? "Game files are ready. " : "Installed. ";
        if (!job.note.empty())
            notice_ = job.note;
        else if (job.kind == "delete")
            notice_ = job.home == 2 ? "Deleted. Its folder and everything in it are gone, and ShadowMountPlus took it off your home screen."
                                    : "Deleted. Its folder and everything in it are gone. If its tile is still on your home screen, delete it there.";
        else if (job.home == 2)
            notice_ = done + "ShadowMountPlus is adding it to your home screen; give it about 15 seconds.";
        else if (job.home == 1)
            notice_ = done + "ShadowMountPlus is starting and will add it to your home screen.";
        else
            notice_ = done + "Start ShadowMountPlus to see it on your home screen, or let the store do it: Settings > General.";
        notice_bad_ = false;
        if (job.kind == "setup")
            chosen_.erase(job.app_id + "/" + job.slot);
        engine().acknowledge();
        /* More files chosen for this game: on to the next, one after another. */
        if (job.kind == "setup")
            if (const App *a = find(job.app_id))
                for (const Slot &sl : a->slots)
                    if (chosen_.count(a->id + "/" + sl.key))
                    {
                        const std::string keep = notice_;
                        press_setup(*a);
                        notice_ = keep;
                        break;
                    }
    }
    else if (job.phase == Phase::Failed && job.kind == "bench")
    {
        play(int(Sfx::Error));
        engine().acknowledge();
    }
    else if (job.phase == Phase::Failed)
    {
        play(int(Sfx::Error));
        notice_ = job.error;
        notice_bad_ = true;
        engine().acknowledge();
    }

    if (picker_)
        return update_picker(dt);

    if (confirm_)
    {
        confirm_t_ = std::min(1.f, confirm_t_ + dt * 5.f);
        /* Cancel, Delete; or, for a game whose saves the store knows, Cancel, Keep my saves,
         * Delete everything. */
        const App *ca = find(page_id_);
        const bool has_saves = ca && !ca->saves.empty();
        if (has_saves != confirm_saves_)
        {
            /* The catalog changed under it (the choices with it): back to Cancel. */
            confirm_saves_ = has_saves;
            confirm_sel_ = 0;
        }
        const int last = has_saves ? 2 : 1;
        const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
        const int sel = std::clamp(confirm_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, last);
        if (sel != confirm_sel_) { confirm_sel_ = sel; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && confirm_sel_ == 0))
        {
            confirm_ = false;
            play(int(Sfx::Back));
        }
        else if (pressed(pad::BtnCross))
        {
            confirm_ = false;
            const bool keep_saves = last == 2 && confirm_sel_ == 1;
            if (engine().remove(page_id_, keep_saves))
            {
                notice_.clear();
                page_focus_ = 0;
                play(int(Sfx::Install));
            }
            else
                play(int(Sfx::Error));
        }
        return true;
    }

    if (page_open_)
    {
        const App *a = find(page_id_);
        if (!a)
        {
            close();
            return true;
        }
        /* A Coming soon entry's page: nothing to press but Back. */
        if (a->soon)
        {
            if (pressed(pad::BtnCircle))
                close();
            return true;
        }
        /* The patch notes, open: Up and Down scroll, Circle or Cross close them. */
        if (notes_open_)
        {
            if (pressed(pad::BtnCircle) || pressed(pad::BtnCross))
            {
                notes_open_ = false;
                play(int(Sfx::Back));
                return true;
            }
            const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
            const int most = std::max(0, notes_visible_);
            const int top = std::clamp(notes_top_ + (d ? 3 : 0) - (u ? 3 : 0), 0, most);
            if (top != notes_top_) { notes_top_ = top; play(int(Sfx::Move)); }
            return true;
        }
        if (pressed(pad::BtnCircle))
        {
            close();
            return true;
        }
        /* Left: the What's new box; Right: back to the panel. */
        if (notes_focus_)
        {
            if (nav(pad::BtnRight, nav_right_, dt)) { notes_focus_ = false; play(int(Sfx::Move)); }
            else if (pressed(pad::BtnCross)) { notes_open_ = true; notes_top_ = 0; play(int(Sfx::Open)); }
            return true;
        }
        if (has_notes_box(*a) && nav(pad::BtnLeft, nav_left_, dt))
        {
            notes_focus_ = true;
            play(int(Sfx::Move));
            return true;
        }
        const auto ctl = page_controls(*a);
        const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
        const int f = std::clamp(page_focus_ + (d ? 1 : 0) - (u ? 1 : 0), 0, int(ctl.size()) - 1);
        if (f != page_focus_) { page_focus_ = f; play(int(Sfx::Move)); }
        page_focus_ = std::clamp(page_focus_, 0, int(ctl.size()) - 1);
        if (pressed(pad::BtnCross))
        {
            const Control &c = ctl[std::size_t(page_focus_)];
            if (c.kind == Control::Primary)
                press_primary();
            else if (c.kind == Control::Slot)
            {
                const std::string &kind = a->slots[std::size_t(c.slot)].kind;
                if (!slot_kind_known(kind)) play(int(Sfx::Error));
                else open_picker(*a, c.slot);
            }
            else if (c.kind == Control::Delete)
            {
                if (engine().job().phase == Phase::Working)
                    play(int(Sfx::Error));
                else
                {
                    confirm_ = true;
                    confirm_sel_ = 0; /* Cancel first: deleting takes a deliberate move */
                    confirm_saves_ = !a->saves.empty();
                    confirm_t_ = 0;
                    play(int(Sfx::Open));
                }
            }
            else
                press_setup(*a);
        }
        return true;
    }

    /* Tabs. */
    int tab = tab_;
    if (pressed(pad::BtnL1)) tab = (tab_ + TabCount - 1) % TabCount;
    if (pressed(pad::BtnR1)) tab = (tab_ + 1) % TabCount;
    if (tab != tab_)
    {
        tab_dir_ = (tab == (tab_ + 1) % TabCount) ? 1 : -1;
        tab_ = tab;
        tab_t_ = 0;
        col_ = 0;
        row_ = tab == Discover && !featured_apps().empty() ? 1 : 0;
        dscroll_ = dscroll_to_ = uscroll_ = uscroll_to_ = lscroll_ = lscroll_to_ = 0;
        in_section_ = false;
        play(int(Sfx::Tab));
        return true;
    }

    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    const int moves = (r ? 1 : 0) - (l ? 1 : 0);

    if (tab_ == Discover)
    {
        const auto feat = featured_apps();
        const auto lines = discover_lines();
        const int nl = int(lines.size());
        dcols_.resize(std::size_t(nl), 0);
        row_ = std::clamp(row_, 0, std::max(0, nl - 1));
        if (nl > 0)
        {
            const DLine &L = lines[std::size_t(row_)];
            const int n = int(L.items.size());
            col_ = std::clamp(col_, 0, std::max(0, n - 1));
            if (moves && L.kind == 0 && feat.size() > 1)
            {
                slide_ = (slide_ + moves + int(feat.size())) % int(feat.size());
                slide_t_ = 0;
                slide_fade_ = 0;
                play(int(Sfx::Move));
            }
            else if (moves && L.kind != 0 && n > 0)
            {
                const int c = std::clamp(col_ + moves, 0, n - 1);
                if (c != col_) { col_ = c; play(int(Sfx::Move)); }
            }
            const int to = std::clamp(row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, nl - 1);
            if (to != row_)
            {
                /* Onto the item nearest the one focused, as the lines are drawn. */
                dcols_[std::size_t(row_)] = col_;
                const float fw = L.kind == 3 ? kWideW : kTile;
                const float fx = item_x(L, col_, line_first(L, col_)) + fw / 2;
                const DLine &T = lines[std::size_t(to)];
                const int tn = int(T.items.size());
                const int remembered = std::clamp(dcols_[std::size_t(to)], 0, std::max(0, tn - 1));
                const int first = line_first(T, remembered);
                const int shown = T.kind == 1 ? kRowShown : T.kind == 3 ? kWideShown : tn;
                const float tw = T.kind == 3 ? kWideW : kTile;
                int best = (T.kind == 1 || T.kind == 2 || T.kind == 3) ? remembered : 0;
                float best_d = 1e9f;
                /* From the banner or a spotlight (the whole width): where that line was left. */
                if (L.kind != 0 && L.kind != 4 && (T.kind == 1 || T.kind == 2 || T.kind == 3))
                    for (int i = first; i < tn && i < first + shown; ++i)
                    {
                        const float dx = std::fabs(item_x(T, i, first) + tw / 2 - fx);
                        if (dx < best_d) { best_d = dx; best = i; }
                    }
                row_ = to;
                col_ = best;
                play(int(Sfx::Move));
            }
        }
        slide_t_ += dt;
        slide_fade_ = std::min(1.f, slide_fade_ + dt * 1.2f);
        if (feat.size() > 1 && slide_t_ > 8.f)
        {
            slide_ = (slide_ + 1) % int(feat.size());
            slide_t_ = 0;
            slide_fade_ = 0;
        }
        if (nl > 0)
        {
            const DLine &F = lines[std::size_t(row_)];
            if (F.kind == 0 && !feat.empty())
                focus_id_ = feat[std::min<std::size_t>(std::size_t(slide_), feat.size() - 1)]->id;
            else if (!F.items.empty())
                focus_id_ = F.items[std::size_t(std::clamp(col_, 0, int(F.items.size()) - 1))]->id;
        }
        if (pressed(pad::BtnCross) && !focus_id_.empty() && nl > 0)
            open(focus_id_);
    }
    else if (tab_ == Library || tab_ == Updates)
    {
        std::vector<const App *> list;
        /* Updates: a newer Recomp Store comes first, as a row of its own (nullptr). */
        const bool store_row = tab_ == Updates && !engine().store_update().empty();
        if (store_row)
            list.push_back(nullptr);
        if (tab_ == Library)
            list = installed_apps();
        else
            for (const App &a : catalog_.apps)
                list.push_back(&a);
        const int n = int(list.size());
        const int step = tab_ == Library ? (u ? -5 : d ? 5 : 0) + moves : (u ? -1 : d ? 1 : 0);
        if (step && n > 0)
        {
            const int c = std::clamp(col_ + step, 0, n - 1);
            if (c != col_) { col_ = c; play(int(Sfx::Move)); }
        }
        if (n > 0)
        {
            const App *at = list[std::size_t(std::min(col_, n - 1))];
            if (!at)
            {
                if (pressed(pad::BtnCross))
                    start_store_update();
            }
            else
            {
                focus_id_ = at->id;
                if (pressed(pad::BtnCross))
                    open(focus_id_);
            }
        }
    }
    else if (tab_ == Settings)
    {
        if (!in_section_)
        {
            const int s = std::clamp(section_ + (u ? -1 : 0) + (d ? 1 : 0), 0, 2);
            if (s != section_) { section_ = s; play(int(Sfx::Move)); }
            if ((pressed(pad::BtnCross) || r) && section_ == 0) { in_section_ = true; gen_row_ = 0; settings_note_.clear(); play(int(Sfx::Select)); }
        }
        else
        {
            const int g = std::clamp(gen_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, 3);
            if (g != gen_row_) { gen_row_ = g; play(int(Sfx::Move)); }
            if (pressed(pad::BtnCross))
            {
                if (gen_row_ == 0)
                {
                    sounds_on_ = !sounds_on_;
                    if (sounds_on_) play(int(Sfx::Select));
                }
                else if (gen_row_ == 1)
                    open_smp_picker();
                else if (gen_row_ == 3)
                {
                    if (engine().update_check() == 1)
                        play(int(Sfx::Error));
                    else
                    {
                        engine().check_updates_now();
                        settings_note_.clear();
                        play(int(Sfx::Select));
                    }
                }
                else
                {
                    std::string why;
                    const int r = refresh_smp(why);
                    const bool ok = r > 0;
                    settings_note_ = r == 2 ? "ShadowMountPlus is running; it's looking for new games now."
                                   : r == 1 ? "Started ShadowMountPlus. New games show up on your home screen in a few seconds."
                                            : why;
                    settings_note_bad_ = !ok;
                    play(int(ok ? Sfx::Complete : Sfx::Error));
                }
            }
            if (pressed(pad::BtnCircle) || l) { in_section_ = false; play(int(Sfx::Back)); return true; }
        }
    }
    if (focus_id_ != backdrop_id_ && !focus_id_.empty())
    {
        backdrop_prev_ = backdrop_id_;
        backdrop_id_ = focus_id_;
        backdrop_t_ = 0;
    }
    return true;
}

/* ---- Drawing helpers ---------------------------------------------------------------- */

/* The mockup's .rs-glass: a faint white fill, a light rim, a soft shadow under it. */
void Store::glass(Gfx &gfx, float x, float y, float w, float h, float r, Color fill, bool shadow)
{
    if (shadow)
        gfx.panel(x, y, w, h, {0, 0, 0, 0}, 1.f, r, {0, 0, 0, 0.30f}, 0, 22); /* only outside: the glass shows what's under it */
    gfx.panel(x, y, w, h, fill, 1.f, r, kRim, 1.25f, 0, 0.035f);
}

/* The focus ring: 3px of white just outside the shape. */
void Store::ring(Gfx &gfx, float x, float y, float w, float h, float r, float amount, bool glow)
{
    if (amount <= 0.01f)
        return;
    if (glow)
    {
        /* Cyan with a soft glow round it: it shows on a white button as well as a dark one. */
        gfx.panel(x - 4, y - 4, w + 8, h + 8, {0, 0, 0, 0}, 1.f, r + 4, C(kCyan, 0.55f * amount), 0, 12);
        gfx.panel(x - 4, y - 4, w + 8, h + 8, {0, 0, 0, 0}, 1.f, r + 4, C(kCyan, amount), 3.f);
        return;
    }
    gfx.panel(x - 3, y - 3, w + 6, h + 6, {0, 0, 0, 0}, 1.f, r + 3, C(0xFFFFFF, 0.92f * amount), 3.f);
}

std::vector<std::string> Store::wrap(Gfx &gfx, Font f, float size, float width, const std::string &text,
                                     int max_lines) const
{
    std::vector<std::string> lines;
    std::string line;
    std::size_t i = 0;
    while (i < text.size())
    {
        std::size_t end = text.find_first_of(" \n", i);
        if (end == std::string::npos)
            end = text.size();
        const std::string word = text.substr(i, end - i);
        const std::string trial = line.empty() ? word : line + " " + word;
        if (!line.empty() && gfx.measure(f, size, trial) > width)
        {
            lines.push_back(line);
            line = word;
        }
        else
            line = trial;
        if (end < text.size() && text[end] == '\n')
        {
            lines.push_back(line);
            line.clear();
        }
        i = end + 1;
    }
    if (!line.empty())
        lines.push_back(line);
    if (int(lines.size()) > max_lines)
    {
        lines.resize(std::size_t(max_lines));
        std::string &last = lines.back();
        while (!last.empty() && gfx.measure(f, size, last + "\xE2\x80\xA6") > width)
            last.pop_back();
        while (!last.empty() && last.back() == ' ')
            last.pop_back();
        last += "\xE2\x80\xA6";
    }
    return lines;
}

/* Lines laid as CSS lays them: each centred in its line box of line_h. Returns the height. */
float Store::draw_lines(Gfx &gfx, Font f, float size, float x, float y, float line_h, Color c,
                        const std::vector<std::string> &lines)
{
    for (std::size_t i = 0; i < lines.size(); ++i)
        gfx.text_mid(f, size, x, y + line_h * (float(i) + 0.5f), c, Align::Left, lines[i]);
    return line_h * float(lines.size());
}

std::string Store::fit(Gfx &gfx, Font f, float size, float width, const std::string &s) const
{
    if (gfx.measure(f, size, s) <= width)
        return s;
    std::string t = s;
    while (!t.empty() && gfx.measure(f, size, t + "\xE2\x80\xA6") > width)
        t.pop_back();
    return t + "\xE2\x80\xA6";
}

/* ---- Screens ------------------------------------------------------------------------ */

void Store::draw_backdrop(Gfx &gfx)
{
    const float W = gfx.design_w(), H = gfx.design_h();
    /* The brand gradient, then the focused game's art frosted over it (blur 46px,
     * brightness 0.5), then midnight at 35%: Main.dc.html's background. */
    if (gradient_)
        gfx.image(gradient_, 0, 0, W, H);
    else
        gfx.panel(0, 0, W, H, C(kMidnight), 1.f, 0);
    auto blur_of = [&](const std::string &id) -> Texture * {
        const App *a = find(id);
        return a ? art(*a).blur : nullptr;
    };
    const float t = ease_out(backdrop_t_);
    Texture *prev = blur_of(backdrop_prev_), *cur = blur_of(backdrop_id_);
    if (prev && t < 1)
        gfx.image(prev, -60, -60, W + 120, H + 120, {0.5f, 0.5f, 0.5f, 1 - t});
    if (cur)
        gfx.image(cur, -60, -60, W + 120, H + 120, {0.5f, 0.5f, 0.5f, prev ? t : ease_out(backdrop_t_ * 1.5f)});
    gfx.panel(0, 0, W, H, C(kMidnight, 0.35f), 1.f, 0);
}

void Store::draw_top(Gfx &gfx)
{
    /* Left: the logo and the name. */
    if (logo_small_)
    {
        gfx.panel(96 + 4, 50 + 10, 44, 44, {0, 0, 0, 0}, 1.f, 16, {0, 0, 0, 0.35f}, 0, 12);
        gfx.image(logo_small_, 96, 50, 52, 52, {}, 16);
    }
    gfx.text_mid(Font::ExtraBold, 30, 164, 76, C(kFrost), Align::Left, "Recomp Store", -0.5f);
    const float left_w = 52 + 16 + gfx.measure(Font::ExtraBold, 30, "Recomp Store", -0.5f);

    /* The tabs, with L1 and R1 keycaps on their sides, centred between the name and a
     * 290px space on the right (the mockup's flex row). */
    int updates = 0;
    for (const App &a : catalog_.apps)
    {
        bool up = false;
        state_of(a, &up);
        updates += up ? 1 : 0;
    }
    if (!engine().store_update().empty())
        ++updates; /* the store itself */
    const std::string badge = std::to_string(updates);
    const float badge_w = gfx.measure(Font::Bold, 15, badge) + 18;
    float widths[4], pill = 12;
    for (int i = 0; i < 4; ++i)
    {
        widths[i] = gfx.measure(Font::SemiBold, 20, kTabNames[i]) + 56;
        if (i == Updates && updates > 0)
            widths[i] += 10 + badge_w;
        pill += widths[i] + (i ? 6 : 0);
    }
    const float cap_w = gfx.measure(Font::Bold, 17, "L1") + 24, cap_h = 37;
    const float group = cap_w + 18 + pill + 18 + cap_w;
    const float area_l = 96 + left_w, area_r = 1920 - 96 - 290;
    float gx = area_l + (area_r - area_l - group) / 2;
    const float cy = 76;

    auto keycap = [&](float x, const char *label) {
        glass(gfx, x, cy - cap_h / 2, cap_w, cap_h, 12, kGlass, false);
        gfx.text_mid(Font::Bold, 17, x + cap_w / 2, cy, C(0xE8EEF6), Align::Center, label);
    };
    keycap(gx, "L1");
    gx += cap_w + 18;
    glass(gfx, gx, cy - 30, pill, 60, 30, kGlass, true);
    float x = gx + 6;
    for (int i = 0; i < 4; ++i)
    {
        const bool on = i == tab_;
        if (on)
            gfx.panel(x, cy - 24, widths[i], 48, C(kFrost), 1.f, 24);
        const Color fg = on ? C(kMidnight) : C(kFrost);
        const Font f = on ? Font::Bold : Font::SemiBold;
        if (i == Updates && updates > 0)
        {
            const float lw = gfx.measure(f, 20, kTabNames[i]);
            const float lx = x + 28;
            gfx.text_mid(f, 20, lx, cy, fg, Align::Left, kTabNames[i]);
            const float bx = lx + lw + 10;
            gfx.panel(bx, cy - 11, badge_w, 22, C(kCyan), 1.f, 11);
            gfx.text_mid(Font::Bold, 15, bx + badge_w / 2, cy, C(kMidnight), Align::Center, badge);
        }
        else
            gfx.text_mid(f, 20, x + widths[i] / 2, cy, fg, Align::Center, kTabNames[i]);
        x += widths[i] + 6;
    }
    keycap(gx + pill + 18, "R1");
}

void Store::draw_tile(Gfx &gfx, const App &a, float x, float y, float size, float focus)
{
    Art &t = art(a);
    /* Focused: up 10px and 6% bigger, a white ring and a deeper shadow (.rs-tile:focus). */
    const float s = 1 + 0.06f * focus;
    const float w = size * s;
    const float tx = x + size / 2 - w / 2, ty = y + size / 2 - w / 2 - 10 * focus;
    gfx.panel(tx + 8, ty + 22 + 8 * focus, w - 16, w - 16, {0, 0, 0, 0}, 1.f, 28, {0, 0, 0, 0.25f + 0.25f * focus}, 0,
              24 + 12 * focus);
    if (t.icon)
        gfx.image(t.icon, tx, ty, w, w, {}, 28 * s);
    else
    {
        gfx.panel(tx, ty, w, w, C(0x16233A), 1.f, 28 * s, kRim, 1.25f);
        gfx.text(Font::ExtraBold, 64, tx + 28, ty + 18, C(kFrost), Align::Left, a.name.substr(0, 2), -2);
        gfx.text(Font::SemiBold, 16, tx + 28, ty + w - 48, C(kSoft), Align::Left,
                 fit(gfx, Font::SemiBold, 16, w - 48, a.repo));
    }
    ring(gfx, tx, ty, w, w, 28 * s, focus);
    bool up = false;
    const std::string st = a.soon ? (a.date.empty() ? std::string("Coming soon") : a.date) : state_of(a, &up);
    if (a.soon)
    {
        const float bw = gfx.measure(Font::Bold, 14, "SOON", 1) + 24;
        gfx.panel(tx + w - 14 - bw, ty + 14, bw, 28, C(kFrost), 1.f, 14);
        gfx.text_mid(Font::Bold, 14, tx + w - 14 - bw / 2, ty + 28, C(kMidnight), Align::Center, "SOON", 1);
    }
    if (up)
    {
        const float bw = gfx.measure(Font::Bold, 14, "Update") + 24;
        gfx.panel(tx + w - 14 - bw, ty + 14, bw, 28, C(kCyan), 1.f, 14);
        gfx.text_mid(Font::Bold, 14, tx + w - 14 - bw / 2, ty + 28, C(kMidnight), Align::Center, "Update");
    }
    gfx.text_mid(Font::SemiBold, 21, x, y + size + 14 + 13, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, size + 20, a.name));
    gfx.text_mid(Font::Regular, 17, x, y + size + 14 + 26 + 4 + 11, C(kMuted), Align::Left, st);
}

void Store::draw_wide(Gfx &gfx, const App &a, float x, float y, float w, float h, float focus)
{
    Art &t = art(a);
    /* Focused: up 8px and 3% bigger, the ring and a deeper shadow, as a tile. */
    const float s = 1 + 0.03f * focus;
    const float ww = w * s, hh = h * s, R = 28 * s;
    const float tx = x + (w - ww) / 2, ty = y + (h - hh) / 2 - 8 * focus;
    gfx.panel(tx + 10, ty + 22 + 8 * focus, ww - 20, hh - 20, {0, 0, 0, 0}, 1.f, R, {0, 0, 0, 0.25f + 0.25f * focus}, 0, 24 + 12 * focus);
    if (t.hero)
    {
        float uv[4];
        cover_uv(t.hero, ww, hh, a.banner_x / 100.f, a.banner_y / 100.f, std::max(1.f, a.banner_zoom / 100.f), uv);
        gfx.image_part(t.hero, tx, ty, ww, hh, uv, {}, R);
    }
    else if (t.blur)
        gfx.image(t.blur, tx, ty, ww, hh, {0.7f, 0.7f, 0.7f, 1}, R);
    else
        gfx.panel(tx, ty, ww, hh, C(0x16233A), 1.f, R, kRim, 1.25f);
    if (!t.hero)
    {
        /* No wide picture: the icon in the middle, or the name's first letters. */
        if (t.icon)
            gfx.image(t.icon, tx + ww / 2 - 84, ty + hh / 2 - 84, 168, 168, {}, 36);
        else
        {
            gfx.text(Font::ExtraBold, 64, tx + 32, ty + 22, C(kFrost), Align::Left, a.name.substr(0, 2), -2);
            gfx.text(Font::SemiBold, 16, tx + 32, ty + hh - 48, C(kSoft), Align::Left, fit(gfx, Font::SemiBold, 16, ww - 64, a.repo));
        }
    }
    /* The logo over the picture when there is one, else the icon in the corner. */
    else if (draw_logo(gfx, a, LogoPlace{}, tx + 28, ty + hh - 28 - 96, ww * 0.55f, 96, 0) < 0)
    {
        if (t.icon)
            gfx.image(t.icon, tx + 22, ty + hh - 22 - 84, 84, 84, {}, 20);
    }
    else
        draw_logo(gfx, a, LogoPlace{}, tx + 28, ty + hh - 28 - 96, ww * 0.55f, 96, 1.f);
    gfx.panel(tx, ty, ww, hh, {0, 0, 0, 0}, 1.f, R, kRim, 1.25f);
    ring(gfx, tx, ty, ww, hh, R, focus);
    bool up = false;
    const std::string st = a.soon ? (a.date.empty() ? std::string("Coming soon") : a.date) : state_of(a, &up);
    const char *badge = a.soon ? "SOON" : up ? "Update" : nullptr;
    if (badge)
    {
        const float bw = gfx.measure(Font::Bold, 14, badge, a.soon ? 1.f : 0.f) + 24;
        gfx.panel(tx + ww - 16 - bw, ty + 16, bw, 28, a.soon ? C(kFrost) : C(kCyan), 1.f, 14);
        gfx.text_mid(Font::Bold, 14, tx + ww - 16 - bw / 2, ty + 30, C(kMidnight), Align::Center, badge, a.soon ? 1.f : 0.f);
    }
    gfx.text_mid(Font::SemiBold, 21, x, y + h + 14 + 13, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, w, a.name));
    gfx.text_mid(Font::Regular, 17, x, y + h + 14 + 26 + 4 + 11, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, w, st));
}

void Store::draw_banner(Gfx &gfx, const App &a, float x, float y, float w, float h, const std::string &kicker_head, float fade,
                        bool drift, float focus)
{
    /* The banner (1728x400): the art frosted across it, the picture itself on the right
     * (with its slow drift, on the featured banner), and the words on the left. */
    const float R = 36;
    Art &t = art(a);
    gfx.panel(x + 10, y + 26, w - 20, h - 20, {0, 0, 0, 0}, 1.f, R, {0, 0, 0, 0.38f}, 0, 30);
    gfx.panel(x, y, w, h, C(kMidnight), 1.f, R);
    if (t.blur)
    {
        const float uv[4] = {0.03f, 0.08f, 0.97f, 0.92f}; /* the blur's soft middle, edges cut off */
        gfx.image_part(t.blur, x, y, w, h, uv, {0.62f, 0.62f, 0.62f, fade}, R);
    }
    /* The words' side: the frosted art with a light wash, meeting the picture in a clean
     * vertical edge (the picture is drawn over the wash's right end). */
    const float gx = x, gy = y, gw = 600, gh = h;
    gfx.panel(x, y, gw + R, h, {1, 1, 1, 0.07f}, 1.f, R);
    if (t.hero)
    {
        float uv[4];
        const float z = drift ? 1.f + 0.07f * (0.5f - 0.5f * std::cos(time_ * 3.14159f / 16.f)) : 1.f;
        cover_uv(t.hero, w - gw, h, a.banner_x / 100.f, a.banner_y / 100.f, std::max(1.f, a.banner_zoom / 100.f) * z, uv);
        gfx.image_part(t.hero, x + gw, y, w - gw, h, uv, {1, 1, 1, fade}, R);
        const float sw = R + 2, du = (uv[2] - uv[0]) * sw / (w - gw);
        const float strip[4] = {uv[0], uv[1], uv[0] + du, uv[3]};
        gfx.image_part(t.hero, x + gw, y, sw, h, strip, {1, 1, 1, fade}, 0);
        gfx.panel(x + gw, y, 2, h, {1, 1, 1, 0.10f}, 1.f, 0);
    }
    gfx.panel(x, y, w, h, {0, 0, 0, 0}, 1.f, R, kRim, 1.25f);
    bool up = false;
    const bool inst = !engine().installed(a.id).empty();
    state_of(a, &up);
    const std::string kicker = kicker_head + " \xC2\xB7 " + (up ? "UPDATE AVAILABLE" : inst ? "INSTALLED" : "NEW ON RECOMP STORE");
    const float tx0 = gx + 64, tw0 = gw - 64 - 48;
    const auto name = wrap(gfx, Font::ExtraBold, 50, tw0, a.name, 2);
    const auto blurb = wrap(gfx, Font::Regular, 20, tw0, a.blurb.empty() ? a.description : a.blurb, 3);
    const float k_h = 18, n_lh = 50 * 1.05f, b_lh = 20 * 1.45f;
    const float total = k_h + 14 + n_lh * float(name.size()) + 14 + b_lh * float(blurb.size());
    /* A logo, when the game has one, takes the name's place (its height, not the name's). */
    const float logo_h = draw_logo(gfx, a, a.logo_banner, tx0, 0, tw0, 140, 0); /* alpha 0: measures only */
    const float name_h = logo_h >= 0 ? logo_h : n_lh * float(name.size());
    const float total2 = total - n_lh * float(name.size()) + name_h;
    float ty = gy + (gh - total2) / 2;
    gfx.text_mid(Font::Bold, 15, tx0, ty + k_h / 2, C(kCyan, fade), Align::Left, fit(gfx, Font::Bold, 15, tw0, kicker), 2);
    ty += k_h + 14;
    if (logo_h >= 0)
        draw_logo(gfx, a, a.logo_banner, tx0, ty, tw0, 140, fade);
    else
        for (std::size_t i = 0; i < name.size(); ++i)
            gfx.text_mid(Font::ExtraBold, 50, tx0, ty + n_lh * (float(i) + 0.5f), C(kFrost, fade), Align::Left, name[i], -1);
    ty += name_h + 14;
    draw_lines(gfx, Font::Regular, 20, tx0, ty, b_lh, C(0xE3E9F2, fade), blurb);
    ring(gfx, x, y, w, h, R, focus);
}

void Store::draw_more(Gfx &gfx)
{
    /* Bottom middle, bobbing gently: a glass dot with an arrow pointing down. */
    const float bob = 3.f * std::sin(time_ * 3.2f);
    const float cx = gfx.design_w() / 2, cy = 1034 + bob, r = 22;
    glass(gfx, cx - r, cy - r, r * 2, r * 2, r, kGlass, false);
    gfx.glyph(Glyph::Arrow, cx, cy + 1, 15, C(kFrost, 0.9f), 3.14159265f);
}

void Store::draw_discover(Gfx &gfx)
{
    const auto feat = featured_apps();
    const auto lines = discover_lines();
    const int nl = int(lines.size());
    if (nl == 0)
    {
        gfx.text(Font::Regular, 20, 96, 160 + 60, C(kMuted), Align::Left,
                 engine().online() ? "No games in the catalog yet." : "Connect your PS5 to the internet to load the catalog.");
        return;
    }
    const int row = std::clamp(row_, 0, nl - 1);
    /* Scrolled just enough to show the focused line whole, its section's title with it; at
     * the top for the banner. */
    const DLine &F = lines[std::size_t(row)];
    const float bottom = kViewBottom - 8, total = lines.back().y + lines.back().h;
    float to = dscroll_to_;
    if (F.kind == 0) to = 0;
    if (kDiscTop + F.y + F.h - to > bottom) to = kDiscTop + F.y + F.h - bottom;
    if (F.y - F.head < to) to = F.y - F.head;
    to = std::clamp(to, 0.f, std::max(0.f, kDiscTop + total - bottom));
    dscroll_to_ = to;
    dscroll_ = approach(dscroll_, to, 1.f / 60.f, 12.f);
    if (std::fabs(dscroll_ - to) < 0.5f) dscroll_ = to;

    gfx.clip(0, kViewTop, gfx.design_w(), kViewBottom - kViewTop);
    for (int i = 0; i < nl; ++i)
    {
        const DLine &L = lines[std::size_t(i)];
        const float sy = kDiscTop + L.y - dscroll_;
        if (sy + L.h < kViewTop - 20 || sy - L.head > kViewBottom) continue;
        if (L.head > 0)
            gfx.text_mid(Font::ExtraBold, 26, 96, sy - L.head + 16, C(kFrost), Align::Left, L.title);
        const bool on = i == row;
        if (L.kind == 0)
        {
            const int cur = std::min<int>(slide_, int(L.items.size()) - 1);
            draw_banner(gfx, *L.items[std::size_t(cur)], 96, sy, 1728, kBannerH, "FEATURED", ease_out(slide_fade_), true, on ? 1.f : 0.f);
            if (L.items.size() > 1)
            {
                float dx = 96 + 1728 - 28;
                for (int k = int(L.items.size()) - 1; k >= 0; --k)
                {
                    const float dw = k == cur ? 34 : 10;
                    dx -= dw;
                    gfx.panel(dx, sy + kBannerH - 32, dw, 10, k == cur ? C(kCyan) : C(kFrost, 0.45f), 1.f, 5);
                    dx -= 8;
                }
            }
            continue;
        }
        if (L.kind == 4)
        {
            std::string head = L.title.empty() ? std::string("SPOTLIGHT") : L.title;
            for (char &c : head) if (c >= 'a' && c <= 'z') c = char(c - 'a' + 'A'); /* (no toupper: only the imports the console is known to have) */
            draw_banner(gfx, *L.items[0], 96, sy, 1728, kBannerH, head, 1.f, false, on ? 1.f : 0.f);
            continue;
        }
        const int n = int(L.items.size());
        const int col = on ? std::clamp(col_, 0, n - 1) : (i < int(dcols_.size()) ? std::clamp(dcols_[std::size_t(i)], 0, n - 1) : 0);
        const int first = line_first(L, col);
        const int shown = L.kind == 1 ? kRowShown : L.kind == 3 ? kWideShown : n;
        for (int k = first; k < n && k < first + shown; ++k)
        {
            float &f = dfocus_[i * 1000 + k];
            f = approach(f, on && k == col ? 1.f : 0.f, 1.f / 60.f, 14.f);
            const float x = item_x(L, k, first);
            if (L.kind == 3)
                draw_wide(gfx, *L.items[std::size_t(k)], x, sy, kWideW, kWideH, ease_out(f));
            else
                draw_tile(gfx, *L.items[std::size_t(k)], x, sy, kTile, ease_out(f));
        }
        /* More of a row off to the side: a faint arrow at that end. */
        if (L.kind == 1 || L.kind == 3)
        {
            const float mid = sy + (L.kind == 3 ? kWideH : kTile) / 2;
            if (first > 0)
                gfx.glyph(Glyph::Arrow, 60, mid, 16, C(kFrost, 0.55f), -1.5708f);
            if (first + shown < n)
                gfx.glyph(Glyph::Arrow, 1920 - 60, mid, 16, C(kFrost, 0.55f), 1.5708f);
        }
    }
    gfx.unclip();
    if (kDiscTop + total - dscroll_ > kViewBottom + 4)
        draw_more(gfx);
}

void Store::draw_library(Gfx &gfx)
{
    const auto list = installed_apps();
    gfx.text_mid(Font::ExtraBold, 48, 96, 160 + 30, C(kFrost), Align::Left, "Library", -1);
    const std::string count = list.size() == 1 ? "1 game installed" : std::to_string(list.size()) + " games installed";
    gfx.text_mid(Font::Regular, 21, 96 + gfx.measure(Font::ExtraBold, 48, "Library", -1) + 18, 160 + 36, C(kMuted), Align::Left, count);
    const float size = 256, gap = 36, top = 160 + 60 + 26 + 10, pitch = size + 120, clip_top = 226;
    const int rows = int((list.size() + 4) / 5);
    /* Scrolled a line at a time to keep the focused game whole above the buttons' hints. */
    const float bottom = kViewBottom - 8;
    float to = lscroll_to_;
    const int r = list.empty() ? 0 : std::clamp(col_, 0, int(list.size()) - 1) / 5;
    if (top + float(r) * pitch + 330 - to > bottom) to = top + float(r) * pitch + 330 - bottom;
    if (float(r) * pitch < to) to = float(r) * pitch;
    const float total = float(rows) * pitch + 84;
    to = std::clamp(to, 0.f, std::max(0.f, top + total - bottom));
    lscroll_to_ = to;
    lscroll_ = approach(lscroll_, to, 1.f / 60.f, 12.f);
    if (std::fabs(lscroll_ - to) < 0.5f) lscroll_ = to;
    gfx.clip(0, clip_top, gfx.design_w(), kViewBottom - clip_top);
    for (int i = 0; i < int(list.size()); ++i)
    {
        float &f = focus_anim_[std::min(i, 511)];
        f = approach(f, i == col_ ? 1.f : 0.f, 1.f / 60.f, 14.f);
        const float y = top + float(i / 5) * pitch - lscroll_;
        if (y > kViewBottom || y + 340 < clip_top) continue;
        draw_tile(gfx, *list[std::size_t(i)], 96 + float(i % 5) * (size + gap), y, size, ease_out(f));
    }
    const float by = (list.empty() ? top : top + float(rows) * pitch) - lscroll_;
    if (by < kViewBottom)
    {
        glass(gfx, 96, by, 900, 84, 24, kGlass, false);
        const auto lines = wrap(gfx, Font::Regular, 19, 900 - 56, "Games you install from the store show up here. Open one to update it, set up its game files, or see where it lives on your console.", 2);
        draw_lines(gfx, Font::Regular, 19, 124, by + 42 - 13.5f * float(lines.size()), 27, C(kSoft), lines);
    }
    gfx.unclip();
    if (rows > 0 && top + float(rows - 1) * pitch + 330 - lscroll_ > kViewBottom + 4)
        draw_more(gfx);
}

void Store::draw_updates(Gfx &gfx)
{
    gfx.text_mid(Font::ExtraBold, 48, 96, 160 + 30, C(kFrost), Align::Left, "Latest Updates", -1);
    gfx.text_mid(Font::Regular, 21, 96 + gfx.measure(Font::ExtraBold, 48, "Latest Updates", -1) + 18, 160 + 36, C(kMuted),
                 Align::Left, "Newest releases first. Once you've updated, a game drops off your to-do.");
    const float top = 160 + 60 + 22, pitch = 148 + 22, clip_top = 226;
    const std::string store_up = engine().store_update();
    const int first = store_up.empty() ? 0 : 1;
    const int n = int(catalog_.apps.size()) + first;
    /* Scrolled a row at a time to keep the focused one whole above the buttons' hints. */
    const float bottom = kViewBottom - 8;
    float to = uscroll_to_;
    const int f0 = std::clamp(col_, 0, std::max(0, n - 1));
    if (top + float(f0) * pitch + 148 - to > bottom) to = top + float(f0) * pitch + 148 - bottom;
    if (float(f0) * pitch < to) to = float(f0) * pitch;
    const float total = float(n) * pitch - 22;
    to = std::clamp(to, 0.f, std::max(0.f, top + total - bottom));
    uscroll_to_ = to;
    uscroll_ = approach(uscroll_, to, 1.f / 60.f, 12.f);
    if (std::fabs(uscroll_ - to) < 0.5f) uscroll_ = to;
    gfx.clip(0, clip_top, gfx.design_w(), kViewBottom - clip_top);
    float y = top - uscroll_;
    if (first)
    {
        float &f = focus_anim_[0];
        f = approach(f, col_ == 0 ? 1.f : 0.f, 1.f / 60.f, 14.f);
        const float k = ease_out(f);
        const float s = 1 + 0.015f * k;
        const float w0 = 1728, h0 = 148;
        const float w = w0 * s, h = h0 * s, x = 96 + (w0 - w) / 2, yy = y + (h0 - h) / 2;
        glass(gfx, x, yy, w, h, 28, {1, 1, 1, 0.08f + 0.04f * k}, true);
        ring(gfx, x, yy, w, h, 28, k);
        if (logo_)
            gfx.image(logo_, x + 28, yy + 22 * s, 104, 104, {}, 24);
        const float tx = x + 28 + 104 + 28;
        std::string v = store_up;
        if (!v.empty() && (v[0] == 'v' || v[0] == 'V')) v.erase(0, 1);
        gfx.text_mid(Font::ExtraBold, 26, tx, yy + 40 * s, C(kFrost), Align::Left, "Recomp Store");
        gfx.text_mid(Font::SemiBold, 20, tx + gfx.measure(Font::ExtraBold, 26, "Recomp Store") + 10, yy + 41 * s, C(kCyan), Align::Left, v);
        const JobState sj = engine().job();
        const bool fetching = sj.phase == Phase::Working && sj.kind == "storeupdate";
        const char *label = store_updating_ ? "Starting\xE2\x80\xA6" : fetching ? "Downloading\xE2\x80\xA6" : "Update";
        const float bw = gfx.measure(Font::Bold, 20, label) + 60;
        std::string line = !store_note_.empty() ? store_note_
                                                : "A new version of the store. It downloads, then closes, updates itself and opens again.";
        if (fetching)
            line = sj.total ? "Downloading " + size_text(sj.done) + " of " + size_text(sj.total) : sj.step + "\xE2\x80\xA6";
        gfx.text_mid(Font::Regular, 19, tx, yy + 76 * s, store_note_bad_ ? C(0xFFB4A8) : C(kSoft), Align::Left,
                     fit(gfx, Font::Regular, 19, w - (tx - x) - bw - 80, line));
        gfx.text_mid(Font::Regular, 16, tx, yy + 108 * s, C(kDim), Align::Left,
                     std::string("You have ") + kVersion + " \xC2\xB7 from GitHub, elripalda/RecompStore");
        gfx.panel(x + w - 28 - bw, yy + h / 2 - 28, bw, 56, C(kCyan), 1.f, 18);
        gfx.text_mid(Font::Bold, 20, x + w - 28 - bw / 2, yy + h / 2, C(kMidnight), Align::Center, label);
        y += pitch;
    }
    for (int i = 0; i < int(catalog_.apps.size()); ++i, y += pitch)
    {
        const App &a = catalog_.apps[std::size_t(i)];
        float &f = focus_anim_[std::min(i + first, 511)];
        f = approach(f, i + first == col_ ? 1.f : 0.f, 1.f / 60.f, 14.f);
        if (y > kViewBottom || y + 160 < clip_top) continue;
        const float k = ease_out(f);
        const float s = 1 + 0.015f * k;
        const float w0 = 1728, h0 = 148;
        const float w = w0 * s, h = h0 * s, x = 96 + (w0 - w) / 2, yy = y + (h0 - h) / 2;
        glass(gfx, x, yy, w, h, 28, {1, 1, 1, 0.08f + 0.04f * k}, true);
        ring(gfx, x, yy, w, h, 28, k);
        Art &t = art(a);
        if (t.icon)
            gfx.image(t.icon, x + 28, yy + 22 * s, 104, 104, {}, 24);
        else
        {
            gfx.panel(x + 28, yy + 22 * s, 104, 104, C(kFrost, 0.1f), 1.f, 24);
            gfx.text_mid(Font::ExtraBold, 40, x + 80, yy + 74 * s, C(kFrost), Align::Center, a.name.substr(0, 2));
        }
        const float tx = x + 28 + 104 + 28;
        gfx.text_mid(Font::ExtraBold, 26, tx, yy + 40 * s, C(kFrost), Align::Left, a.name);
        gfx.text_mid(Font::SemiBold, 20, tx + gfx.measure(Font::ExtraBold, 26, a.name) + 10, yy + 41 * s, C(kCyan),
                     Align::Left, version_of(a));
        bool up = false;
        state_of(a, &up);
        const bool inst = !engine().installed(a.id).empty();
        const char *label = up ? "Update" : inst ? "Up to date" : "Get";
        const float bw = gfx.measure(Font::Bold, 20, label) + 60;
        gfx.text_mid(Font::Regular, 19, tx, yy + 76 * s, C(kSoft), Align::Left,
                     fit(gfx, Font::Regular, 19, w - (tx - x) - bw - 80, a.news));
        gfx.text_mid(Font::Regular, 16, tx, yy + 108 * s, C(kDim), Align::Left,
                     (a.kind == "original" ? std::string("Recomp Store") : "By " + a.author) +
                         (a.released.empty() ? "" : " \xC2\xB7 " + a.released));
        const Color bg = up ? C(kCyan) : inst ? C(kGreen, 0.18f) : C(kFrost, 0.14f);
        const Color fg = up ? C(kMidnight) : inst ? C(0x9FF0C6) : C(kFrost);
        gfx.panel(x + w - 28 - bw, yy + h / 2 - 28, bw, 56, bg, 1.f, 18);
        gfx.text_mid(Font::Bold, 20, x + w - 28 - bw / 2, yy + h / 2, fg, Align::Center, label);
    }
    gfx.unclip();
    if (top + total - uscroll_ > kViewBottom + 4)
        draw_more(gfx);
}

void Store::draw_settings(Gfx &gfx)
{
    const float top = 160, bottom = 1080 - 110, h = bottom - top;
    glass(gfx, 96, top, 400, h, 32, kGlass, true);
    const char *names[3] = {"General", "Adding your game", "About"};
    const char *hints[3] = {"Sounds and where games go", "How to set a game up", "Legal notice and credits"};
    for (int i = 0; i < 3; ++i)
    {
        const bool on = i == section_;
        const float y = top + 18 + float(i) * (90 + 6);
        if (on)
            gfx.panel(114, y, 364, 90, in_section_ ? C(kFrost, 0.16f) : C(kFrost), 1.f, 20);
        const bool solid = on && !in_section_;
        gfx.text_mid(on ? Font::Bold : Font::SemiBold, 22, 138, y + 34, solid ? C(kMidnight) : C(kFrost), Align::Left, names[i]);
        gfx.text_mid(Font::Regular, 16, 138, y + 62, solid ? C(0x3B4556) : C(kDim), Align::Left, hints[i]);
    }

    const float px = 96 + 400 + 36, pw = 1920 - 96 - px;
    glass(gfx, px, top, pw, h, 32, C(kMidnight, 0.5f), true);
    const float cx = px + 56, cw = pw - 112;
    if (section_ == 0)
    {
        gfx.text_mid(Font::ExtraBold, 38, cx, top + 48 + 24, C(kFrost), Align::Left, "General");
        float ry = top + 48 + 48 + 24;
        /* The rows X acts on: a title, a line of help, and what it is now on the right. */
        auto option = [&](int index, const std::string &title, const std::string &help) {
            gfx.panel(cx, ry, cw, 1.25f, kLine, 1.f, 0);
            if (in_section_ && gen_row_ == index)
                ring(gfx, cx - 12, ry + 6, cw + 24, 92, 18, 1.f, true);
            gfx.text_mid(Font::SemiBold, 22, cx + 8, ry + 22 + 13, C(kFrost), Align::Left, title);
            gfx.text_mid(Font::Regular, 18, cx + 8, ry + 22 + 26 + 6 + 11, C(kMuted), Align::Left, fit(gfx, Font::Regular, 18, cw - 420, help));
        };
        option(0, "Menu sounds", "Soft clicks for moving, opening pages and installing.");
        {
            const float sx = cx + cw - 8 - 74, sy = ry + 52 - 21;
            gfx.panel(sx, sy, 74, 42, sounds_on_ ? C(kGreen) : C(kFrost, 0.22f), 1.f, 21);
            gfx.panel(sounds_on_ ? sx + 36 : sx + 4, sy + 4, 34, 34, C(0xFFFFFF), 1.f, 17);
        }
        ry += 104;
        const std::string smp = smp_path();
        option(1, "ShadowMountPlus", "After each install and setup, the store has it add the game to your home screen, starting it if needed.");
        gfx.text_mid(Font::Regular, 19, cx + cw - 8, ry + 52, smp.empty() ? C(kCyan) : C(kFrost), Align::Right,
                     smp.empty() ? std::string("Choose its file\xE2\x80\xA6") : fit(gfx, Font::Regular, 19, 380, base_name(smp)));
        ry += 104;
        option(2, "Refresh ShadowMountPlus", "Has it look for new games now, or starts it (port 9021) if it isn't running.");
        gfx.text_mid(Font::Regular, 19, cx + cw - 8, ry + 52, C(kFrost), Align::Right, "Refresh");
        ry += 104;
        {
            /* What the last check found: the store's own update and the games', from GitHub. */
            const int st = engine().update_check();
            std::string result;
            if (st == 2)
            {
                int games = 0;
                for (const App &a : catalog_.apps)
                {
                    bool up = false;
                    state_of(a, &up);
                    games += up ? 1 : 0;
                }
                std::string su = engine().store_update();
                if (!su.empty() && (su[0] == 'v' || su[0] == 'V')) su.erase(0, 1);
                const std::string g = games == 1 ? "1 game" : std::to_string(games) + " games";
                if (su.empty() && !games)
                    result = "Everything is up to date.";
                else if (!games)
                    result = "Recomp Store " + su + " is ready: see Latest Updates.";
                else if (su.empty())
                    result = g + (games == 1 ? " has an update" : " have updates") + ": see Latest Updates.";
                else
                    result = "Recomp Store " + su + " and " + g + " have updates: see Latest Updates.";
            }
            option(3, "Check for updates",
                   st == 1   ? "Asking GitHub about the store and your games\xE2\x80\xA6"
                   : st == 3 ? "GitHub couldn't be reached. Check your connection and try again."
                   : st == 2 ? result
                             : "The store and your games look on GitHub on their own; this looks now.");
            gfx.text_mid(Font::Regular, 19, cx + cw - 8, ry + 52, st == 1 ? C(kMuted) : C(kFrost), Align::Right,
                         st == 1 ? "Checking\xE2\x80\xA6" : "Check now");
            ry += 104;
        }
        if (!settings_note_.empty())
        {
            gfx.text_mid(Font::SemiBold, 18, cx + 8, ry + 18, settings_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), Align::Left,
                         fit(gfx, Font::SemiBold, 18, cw - 16, settings_note_));
            ry += 40;
        }
        auto row = [&](const std::string &k, const std::string &v) {
            gfx.panel(cx, ry, cw, 1.25f, kLine, 1.f, 0);
            gfx.text_mid(Font::Regular, 20, cx + 8, ry + 34, C(kMuted), Align::Left, k);
            gfx.text_mid(Font::Regular, 20, cx + cw - 8, ry + 34, C(kFrost), Align::Right, v);
            ry += 68;
        };
        row("Games install to", "/data/homebrew/<title ID>");
        row("Needs", "etaHEN or elfldr, kstuff, ShadowMountPlus 1.7 Beta 5+");
        if (disp_w_)
            row("Display", std::to_string(disp_w_) + " \xC3\x97 " + std::to_string(disp_h_));
    }
    else if (section_ == 1)
    {
        float y = top + 48;
        gfx.text_mid(Font::ExtraBold, 38, cx, y + 24, C(kFrost), Align::Left, "Adding your game");
        y += 48 + 22;
        y += draw_lines(gfx, Font::Regular, 21, cx, y, 31.5f, C(0xD9E1EC),
                        wrap(gfx, Font::Regular, 21, std::min(cw, 1080.f),
                             "Every game here needs your own copy. The store installs the port, then copies the game's files out of your disc image.", 3)) + 22;
        const char *titles[4] = {"Install the game from the store", "Make a disc image of your own copy",
                                 "Put the ISO on your PS5", "Set up your game files"};
        const char *bodies[4] = {"Open its page and press Install. The port goes in /data/homebrew/<title ID>.",
                                 "Rip your own disc to an .iso. Recomp Store never downloads games.",
                                 "Anywhere on the console over FTP, or on a USB drive.",
                                 "On the game's page, under Your game files, choose the ISO and press Set up game files. You do this once: updates keep your game files and saves."};
        for (int i = 0; i < 4; ++i)
        {
            gfx.panel(cx, y, 48, 48, C(kCyan), 1.f, 24);
            gfx.text_mid(Font::ExtraBold, 22, cx + 24, y + 24, C(kMidnight), Align::Center, std::to_string(i + 1));
            gfx.text_mid(Font::SemiBold, 23, cx + 70, y + 4 + 14, C(kFrost), Align::Left, titles[i]);
            const float hh = draw_lines(gfx, Font::Regular, 19, cx + 70, y + 4 + 28 + 6, 28.5f, C(kSoft),
                                        wrap(gfx, Font::Regular, 19, std::min(cw, 1080.f) - 70, bodies[i], 2));
            y += std::max(48.f, 4 + 28 + 6 + hh) + 22;
        }
        gfx.text_mid(Font::Regular, 18, cx, y + 12, C(kDim), Align::Left,
                     "Community games may set up differently; their page in the store says what they need.");
    }
    else
    {
        float y = top + 48;
        if (logo_)
        {
            gfx.panel(cx, y, 112, 112, {0, 0, 0, 0}, 1.f, 28, C(kCyan, 0.25f), 0, 20);
            gfx.image(logo_, cx, y, 112, 112, {}, 28);
            gfx.panel(cx, y, 112, 112, {0, 0, 0, 0}, 1.f, 28, C(kCyan, 0.4f), 1.25f);
        }
        gfx.text_mid(Font::ExtraBold, 40, cx + 138, y + 38, C(kFrost), Align::Left, "Recomp Store", -0.5f);
        gfx.text_mid(Font::Regular, 20, cx + 138, y + 80, C(kMuted), Align::Left,
                     std::string("Version ") + kVersion + " (build " + std::to_string(kBuild) + ") \xC2\xB7 recompstore.com");
        y += 112 + 24;
        const float bw = std::min(cw, 1080.f);
        const auto l1 = wrap(gfx, Font::Regular, 21, bw - 64,
                             "Recomp Store doesn't host any game files or copyrighted game content, and neither do the GitHub "
                             "repositories it links to. They hold only the ports and their tools. Every game needs your own legally "
                             "obtained copy, which you set up yourself.", 4);
        const auto l2 = wrap(gfx, Font::Regular, 18, bw - 64,
                             "Recomp Store and the games listed in it aren't affiliated with or endorsed by Sony Interactive "
                             "Entertainment, Microsoft, or any game's publisher or developer. All trademarks belong to their owners.", 3);
        const float bh = 28 + 20 + 12 + 32.5f * float(l1.size()) + 12 + 27 * float(l2.size()) + 28;
        gfx.panel(cx, y, bw, bh, C(kCyan, 0.10f), 1.f, 24, C(kCyan, 0.45f), 1.25f);
        gfx.text_mid(Font::Bold, 16, cx + 32, y + 28 + 10, C(kCyan), Align::Left, "BRING YOUR OWN DISC", 2);
        float ty = y + 28 + 20 + 12;
        ty += draw_lines(gfx, Font::Regular, 21, cx + 32, ty, 32.5f, C(kFrost), l1) + 12;
        draw_lines(gfx, Font::Regular, 18, cx + 32, ty, 27, C(kBody), l2);
        y += bh + 24;
        gfx.text_mid(Font::Regular, 20, cx, y + 15, C(0xD9E1EC), Align::Left,
                     "Made by elripalda. Each game's page credits the people whose work it builds on.");
        y += 31 + 10;
        {
            /* Bug reports go to the site's form, where the right game can be picked. */
            const std::string lead = "Found a bug? Report it at ";
            gfx.text_mid(Font::Regular, 20, cx, y + 15, C(0xD9E1EC), Align::Left, lead);
            gfx.text_mid(Font::Bold, 20, cx + gfx.measure(Font::Regular, 20, lead), y + 15, C(kCyan), Align::Left, "recompstore.com/report");
        }
        y += 31 + 24;
        std::vector<std::string> chips;
        if (!catalog_.discord.empty()) chips.push_back("Discord \xC2\xB7 help");
        if (!catalog_.kofi.empty())
            chips.push_back(catalog_.kofi.rfind("https://", 0) == 0 ? catalog_.kofi.substr(8) : catalog_.kofi);
        chips.push_back("recompstore.com");
        chips.push_back("github.com/elripalda/RecompStore");
        float chx = cx;
        for (const std::string &c : chips)
        {
            const float w = gfx.measure(Font::SemiBold, 19, c) + 44;
            if (chx + w > cx + cw) break;
            glass(gfx, chx, y, w, 51, 18, kGlass, false);
            gfx.text_mid(Font::SemiBold, 19, chx + w / 2, y + 25.5f, C(kFrost), Align::Center, c);
            chx += w + 16;
        }
    }
}

float Store::draw_logo(Gfx &gfx, const App &a, const LogoPlace &place, float x, float y, float box_w, float box_h,
                       float alpha)
{
    if (a.logo_url.empty())
        return -1;
    Art &t = art(a);
    if (!t.logo || t.logo->width <= 0 || t.logo->height <= 0)
        return -1;
    const float s = float(std::clamp(place.scale, 20, 300)) / 100.f;
    const float bw = box_w * s, bh = box_h * s;
    const float aspect = float(t.logo->width) / float(t.logo->height);
    float w = bw, h = bw / aspect;
    if (h > bh)
    {
        h = bh;
        w = h * aspect;
    }
    if (alpha > 0.001f)
        gfx.image(t.logo, x + float(place.x), y + float(place.y), w, h, C(0xFFFFFF, alpha));
    return h;
}

/* Each release: its title, its date, then its notes as headings, text and bullets. */
void Store::layout_notes(Gfx &gfx, const std::string &key, const std::vector<Release> &rel, float width)
{
    if (key == notes_key_)
        return;
    notes_key_ = key;
    notes_lines_.clear();
    auto add_wrapped = [&](int kind, Font f, float size, float w, const std::string &text) {
        bool first = true;
        for (const std::string &l : wrap(gfx, f, size, w, text, 40))
        {
            notes_lines_.push_back({kind == 4 && !first ? 6 : kind, l});
            first = false;
        }
    };
    for (std::size_t r = 0; r < rel.size(); ++r)
    {
        const Release &x = rel[r];
        if (r > 0) notes_lines_.push_back({5, ""});
        std::string title = md_inline(x.name.empty() ? x.tag : x.name);
        if (title.empty()) title = x.tag;
        add_wrapped(0, Font::ExtraBold, 26, width, title);
        std::string meta = long_date(x.date);
        if (x.prerelease) meta += meta.empty() ? "Pre-release" : " \xC2\xB7 Pre-release";
        if (!x.tag.empty() && x.tag != title) meta += (meta.empty() ? "" : " \xC2\xB7 ") + x.tag;
        if (!meta.empty()) notes_lines_.push_back({1, meta});
        /* The body, a line at a time; comments (<!-- -->) dropped first. */
        std::string body = x.body;
        for (std::size_t c = body.find("<!--"); c != std::string::npos; c = body.find("<!--", c))
        {
            const std::size_t e = body.find("-->", c);
            body.erase(c, e == std::string::npos ? std::string::npos : e + 3 - c);
        }
        bool gap = true, any = false;
        std::size_t at = 0;
        while (at <= body.size())
        {
            std::size_t end = body.find('\n', at);
            if (end == std::string::npos) end = body.size();
            std::string line = body.substr(at, end - at);
            at = end + 1;
            if (!line.empty() && line.back() == '\r') line.pop_back();
            std::size_t lead = 0;
            while (lead < line.size() && (line[lead] == ' ' || line[lead] == '\t')) ++lead;
            line = line.substr(lead);
            if (line.empty() || line == "---" || line == "***" || line == "___")
            {
                if (!gap && any) { notes_lines_.push_back({5, ""}); gap = true; }
                continue;
            }
            if (line[0] == '>') line = line.substr(line.size() > 1 && line[1] == ' ' ? 2 : 1);
            if (line[0] == '#')
            {
                std::size_t h = 0;
                while (h < line.size() && line[h] == '#') ++h;
                const std::string t = md_inline(line.substr(h));
                if (!t.empty()) add_wrapped(2, Font::Bold, 20, width, t);
            }
            else if (line.size() > 1 && (line[0] == '-' || line[0] == '*' || line[0] == '+') && line[1] == ' ')
            {
                const std::string t = md_inline(line.substr(2));
                if (!t.empty()) add_wrapped(4, Font::Regular, 20, width - 24, t);
            }
            else
            {
                const std::string t = md_inline(line);
                if (!t.empty()) add_wrapped(3, Font::Regular, 20, width, t);
            }
            gap = false;
            any = true;
            if (end == body.size()) break;
        }
        while (!notes_lines_.empty() && notes_lines_.back().kind == 5) notes_lines_.pop_back();
        if (!any) notes_lines_.push_back({3, "No notes for this release."});
    }
}

namespace
{
/* How each kind of patch-note line looks: its font, size, height and colour. */
struct NoteStyle
{
    Font font;
    float size, height;
    std::uint32_t color;
};
NoteStyle note_style(int kind)
{
    switch (kind)
    {
    case 0: return {Font::ExtraBold, 26, 40, 0xF4F8FC};
    case 1: return {Font::Regular, 17, 30, 0xAAB6C6};
    case 2: return {Font::Bold, 20, 34, 0xF4F8FC};
    case 5: return {Font::Regular, 20, 16, 0};
    default: return {Font::Regular, 20, 30, 0xE1E8F1};
    }
}
} // namespace

void Store::draw_notes(Gfx &gfx, const App &a, float x, float y, float w, float h, float e)
{
    gfx.panel(x, y, w, h, C(0x0E1729, 0.97f), 1.f, 26, C(kCyan, 0.55f), 1.5f);
    ring(gfx, x, y, w, h, 26, e, true);
    if (e < 0.35f)
        return;
    const float al = clamp01((e - 0.35f) / 0.65f);
    gfx.text_mid(Font::Bold, 15, x + 32, y + 28 + 9, C(kCyan, al), Align::Left, "PATCH NOTES", 2);
    {
        const std::string from = "From GitHub \xC2\xB7 " + a.repo;
        gfx.text_mid(Font::Regular, 16, x + w - 32, y + 28 + 9, C(kMuted, al), Align::Right, fit(gfx, Font::Regular, 16, w - 260, from));
    }
    const float top = y + 28 + 18 + 22, bottom = y + h - 26;
    std::vector<Release> rel;
    const int state = a.source == "github" && !a.repo.empty() ? engine().notes(a.repo, rel) : -1;
    if (state <= 0)
    {
        const std::string msg = state == 0 ? "Loading the notes from GitHub\xE2\x80\xA6"
                                           : "GitHub's notes couldn't be loaded right now. Check back when the PS5 is online.";
        float ty = top;
        if (!a.news.empty())
            ty += draw_lines(gfx, Font::Regular, 20, x + 32, ty, 30, C(kBody, al), wrap(gfx, Font::Regular, 20, w - 64, a.news, 8)) + 16;
        draw_lines(gfx, Font::Regular, 18, x + 32, ty, 27, C(kMuted, al), wrap(gfx, Font::Regular, 18, w - 64, msg, 3));
        notes_visible_ = 0;
        return;
    }
    /* The furthest it scrolls: the last lines just fill the box. */
    {
        float sum = 0;
        int most = int(notes_lines_.size());
        while (most > 0 && sum + note_style(notes_lines_[std::size_t(most - 1)].kind).height <= bottom - top)
            sum += note_style(notes_lines_[std::size_t(--most)].kind).height;
        notes_visible_ = most;
        notes_top_ = std::clamp(notes_top_, 0, most);
    }
    float ty = top;
    int shown = 0;
    for (std::size_t i = std::size_t(notes_top_); i < notes_lines_.size(); ++i)
    {
        const NoteLine &l = notes_lines_[i];
        const NoteStyle st = note_style(l.kind);
        if (ty + st.height > bottom + 0.5f)
            break;
        if (l.kind == 4)
            gfx.text_mid(Font::Bold, 20, x + 34, ty + st.height / 2, C(kCyan, al), Align::Left, "\xE2\x80\xA2");
        if (l.kind != 5)
            gfx.text_mid(st.font, st.size, x + 32 + (l.kind == 4 || l.kind == 6 ? 24.f : 0.f), ty + st.height / 2, C(st.color, al),
                         Align::Left, l.text, l.kind == 0 ? -0.5f : 0.f);
        ty += st.height;
        ++shown;
    }
    /* Where it is in the list: a thin bar on the right. */
    if (notes_visible_ > 0 && shown < int(notes_lines_.size()))
    {
        const float th = bottom - top, tx = x + w - 16;
        gfx.panel(tx, top, 4, th, C(0xFFFFFF, 0.10f * al), 1.f, 2);
        const float frac = float(shown) / float(notes_lines_.size());
        const float bar = std::max(28.f, th * frac);
        const float pos = notes_visible_ > 0 ? float(notes_top_) / float(notes_visible_) : 0.f;
        gfx.panel(tx, top + (th - bar) * pos, 4, bar, C(kCyan, 0.9f * al), 1.f, 2);
    }
}

void Store::draw_soon_page(Gfx &gfx, const App &a, float k)
{
    /* A game that isn't out yet: its art, its name, when, and what it is. Nothing to press. */
    Art &t = art(a);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.set_layer(0, 0, k);
    gfx.panel(0, 0, W, H, C(kMidnight), 1.f, 0);
    if (t.hero)
    {
        float uv[4];
        cover_uv(t.hero, W, H, 0.5f, a.banner_y / 100.f, 1.04f - 0.04f * k, uv);
        gfx.image_part(t.hero, 0, 0, W, H, uv);
    }
    else if (t.blur)
        gfx.image(t.blur, -60, -60, W + 120, H + 120, {0.6f, 0.6f, 0.6f, 1});
    gfx.panel(0, 0, W, H, C(kMidnight, 0.6f), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 28, k);
    {
        const float bw = 20 + 22 + 10 + gfx.measure(Font::SemiBold, 19, "Store") + 26;
        glass(gfx, 96, 52, bw, 51, 25.5f, kGlass, false);
        if (chevron_)
            gfx.image(chevron_, 96 + 20, 52 + 25.5f - 11, 22, 22);
        gfx.text_mid(Font::SemiBold, 19, 96 + 20 + 22 + 10, 52 + 25.5f, C(kFrost), Align::Left, "Store");
    }
    float x = 96, y = 190;
    {
        float chx = x;
        auto chip = [&](const std::string &s, Font f, Color fill, Color edge, Color fg) {
            const float w = gfx.measure(f, 16, s) + 32;
            gfx.panel(chx, y, w, 34, fill, 1.f, 17, edge, 1.25f);
            gfx.text_mid(f, 16, chx + w / 2, y + 17, fg, Align::Center, s);
            chx += w + 12;
        };
        chip("Coming soon", Font::Bold, C(kCyan), C(kCyan), C(kMidnight));
        if (!a.date.empty()) chip(a.date, Font::SemiBold, kGlass, kRim, C(kFrost));
        if (!a.author.empty()) chip("By " + a.author, Font::SemiBold, kGlass, kRim, C(kFrost));
        y += 34 + 26;
    }
    const float logo_h = draw_logo(gfx, a, a.logo_page, x, y, 820, 230, 1.f);
    if (logo_h >= 0)
        y += logo_h + 26;
    else
    {
        const auto name = wrap(gfx, Font::ExtraBold, 92, 920, a.name, 2);
        for (std::size_t i = 0; i < name.size(); ++i)
            gfx.text_mid(Font::ExtraBold, 92, x, y + 92 * (float(i) + 0.5f), C(kFrost), Align::Left, name[i], -2.5f);
        y += 92 * float(name.size()) + 26;
    }
    const std::string about = a.description.empty() ? a.blurb : a.description;
    y += draw_lines(gfx, Font::Regular, 25, x, y, 37.5f, C(kBody), wrap(gfx, Font::Regular, 25, 860, about, 8));

    /* Right: the panel, as a game's, with when instead of a button. */
    const float pw = 640, px = W - 96 - pw, py = 190, ix = px + 40, iw = pw - 80;
    const auto lines = wrap(gfx, Font::Regular, 20, iw,
                            "It isn't out yet. When it is, it shows up in Discover and you can install it from here, as any other game.", 4);
    const std::string where = !a.repo.empty() ? "github.com/" + a.repo : a.url;
    const float ph = 40 + 112 + 28 + 84 + 28 + 30 * float(lines.size()) + (where.empty() ? 0 : 28 + 56) + 40;
    glass(gfx, px, py, pw, ph, 34, C(kMidnight, 0.74f), true);
    float ry = py + 40;
    if (t.icon)
        gfx.image(t.icon, ix, ry, 112, 112, {}, 26);
    else
    {
        gfx.panel(ix, ry, 112, 112, C(kFrost, 0.1f), 1.f, 26);
        gfx.text_mid(Font::ExtraBold, 44, ix + 56, ry + 56, C(kFrost), Align::Center, a.name.substr(0, 2));
    }
    gfx.text_mid(Font::ExtraBold, 28, ix + 134, ry + 56 - 16, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 28, iw - 134, a.name));
    gfx.text_mid(Font::Regular, 19, ix + 134, ry + 56 + 20, C(0xB9C4D3), Align::Left, a.author.empty() ? "Coming soon" : "By " + a.author);
    ry += 112 + 28;
    gfx.panel(ix, ry, iw, 84, C(kFrost, 0.10f), 1.f, 24, C(kFrost, 0.25f), 1.25f);
    gfx.text_mid(Font::Bold, 26, ix + iw / 2, ry + 42, C(kFrost), Align::Center, a.date.empty() ? "Coming soon" : "Coming " + a.date);
    ry += 84 + 28;
    ry += draw_lines(gfx, Font::Regular, 20, ix, ry, 30, C(kSoft), lines);
    if (!where.empty())
    {
        ry += 28;
        gfx.panel(ix, ry, iw, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::Regular, 19, ix, ry + 28, C(kMuted), Align::Left, "Follow it");
        gfx.text_mid(Font::Regular, 19, ix + iw, ry + 28, C(kFrost), Align::Right, fit(gfx, Font::Regular, 19, iw - 160, where));
    }
    gfx.set_layer();
}

void Store::draw_page(Gfx &gfx)
{
    const App *ap = find(page_id_);
    if (!ap || page_t_ < 0.01f)
        return;
    if (ap->soon)
        return draw_soon_page(gfx, *ap, ease_out(page_t_));
    const App &a = *ap;
    Art &t = art(a);
    const float W = gfx.design_w(), H = gfx.design_h();
    const float k = ease_out(page_t_);
    /* The page opens over the store: the game's art full screen under midnight at 55%. */
    gfx.set_layer(0, 0, k);
    gfx.panel(0, 0, W, H, C(kMidnight), 1.f, 0);
    if (t.hero)
    {
        float uv[4];
        const float z = 1.04f - 0.04f * k;
        cover_uv(t.hero, W, H, 0.5f, a.banner_y / 100.f, z, uv);
        gfx.image_part(t.hero, 0, 0, W, H, uv);
    }
    else if (t.blur)
        gfx.image(t.blur, -60, -60, W + 120, H + 120, {0.6f, 0.6f, 0.6f, 1});
    gfx.panel(0, 0, W, H, C(kMidnight, 0.55f), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 28, k);

    /* Back: a glass pill with a chevron. */
    {
        const float bw = 20 + 22 + 10 + gfx.measure(Font::SemiBold, 19, "Store") + 26;
        glass(gfx, 96, 52, bw, 51, 25.5f, kGlass, false);
        if (chevron_)
            gfx.image(chevron_, 96 + 20, 52 + 25.5f - 11, 22, 22);
        gfx.text_mid(Font::SemiBold, 19, 96 + 20 + 22 + 10, 52 + 25.5f, C(kFrost), Align::Left, "Store");
    }

    /* Left column, 920 wide from (96, 190), 26 between its parts. */
    float x = 96, y = 190;
    {
        const bool original = a.kind == "original";
        const std::string kind = original ? "Recomp Store" : "By " + a.author;
        float chx = x;
        auto chip = [&](const std::string &s, Font f, Color fill, Color edge, Color fg) {
            const float w = gfx.measure(f, 16, s) + 32;
            gfx.panel(chx, y, w, 34, fill, 1.f, 17, edge, 1.25f);
            gfx.text_mid(f, 16, chx + w / 2, y + 17, fg, Align::Center, s);
            chx += w + 12;
        };
        chip(kind, Font::Bold, original ? C(kCyan) : kGlass, original ? C(kCyan) : kRim, original ? C(kMidnight) : C(kFrost));
        chip(a.id, Font::SemiBold, kGlass, kRim, C(kFrost));
        chip("Needs your " + (a.needs.empty() ? std::string("own game") : a.needs), Font::SemiBold, kGlass, kRim, C(kFrost));
        y += 34 + 26;
    }
    {
        /* The logo, when the game has one, in place of its name. */
        const float logo_h = draw_logo(gfx, a, a.logo_page, x, y, 820, 230, 1.f);
        if (logo_h >= 0)
            y += logo_h + 26;
        else
        {
            const auto name = wrap(gfx, Font::ExtraBold, 92, 920, a.name, 2);
            for (std::size_t i = 0; i < name.size(); ++i)
                gfx.text_mid(Font::ExtraBold, 92, x, y + 92 * (float(i) + 0.5f), C(kFrost), Align::Left, name[i], -2.5f);
            y += 92 * float(name.size()) + 26;
        }
    }
    /* Below: the description, What's new (opens into every release's notes), the credits,
     * and the bring-your-own-disc note; the description gives way when space is short. */
    const float bottom = H - 110;
    const bool notes_box = has_notes_box(a);
    std::vector<Release> rel;
    const int rel_state = a.source == "github" && !a.repo.empty() ? engine().notes(a.repo, rel) : -1;
    if (rel_state > 0)
        layout_notes(gfx, a.repo + "#" + std::to_string(rel.size()) + "#" + rel.front().tag + "#" + std::to_string(rel.front().body.size()),
                     rel, 820 - 64 - 16);
    std::string news = a.news;
    if (news.empty() && rel_state > 0)
        for (const NoteLine &l : notes_lines_)
            if (l.kind == 3 || l.kind == 4) { news = l.text; break; }
    if (news.empty() && rel_state > 0) news = "See every release's notes.";
    const auto news_lines = wrap(gfx, Font::Regular, 21, 820 - 64, news, 2);
    const float nh = notes_box ? 28 + 18 + 10 + 31.5f * float(std::max<std::size_t>(1, news_lines.size())) + 28 : 0;
    const auto credits = wrap(gfx, Font::Regular, 18, 900, a.credits, 2);
    const float ch = a.credits.empty() ? 0 : 27 * float(credits.size());
    static const char *kDisc =
        "Recomp Store and this game's GitHub repository don't host any game files. Set it up from your own "
        "legally obtained copy.";
    const auto disc = wrap(gfx, Font::Regular, 18, 820 - 64, kDisc, 3);
    const float dh = 22 + 16 + 10 + 27 * float(disc.size()) + 22;
    /* Open, the notes cover everything from the description down (text is drawn over
     * panels, so what they cover fades out rather than showing through). */
    const float ne = notes_box && notes_t_ > 0.01f ? ease_out(notes_t_) : 0.f, u = 1.f - ne;
    const float desc_y = y;
    {
        const float room = bottom - y - (notes_box ? nh + 24 : 0) - (ch > 0 ? ch + 24 : 0) - dh - 24;
        const int lines = std::clamp(int(room / 37.5f), 1, 5);
        y += draw_lines(gfx, Font::Regular, 25, x, y, 37.5f, C(kBody, u), wrap(gfx, Font::Regular, 25, 860, a.description, lines)) + 24;
    }
    const float ny = y;
    if (notes_box && ne < 0.01f)
    {
        glass(gfx, x, y, 820, nh, 26, kGlass, false);
        gfx.text_mid(Font::Bold, 15, x + 32, y + 28 + 9, C(kCyan), Align::Left,
                     "WHAT'S NEW" + (a.version.empty() ? std::string() : " \xC2\xB7 " + a.version), 2);
        /* Right of the heading: how to open the rest (Left from the panel, then Cross). */
        if (rel_state > 0 || notes_focus_)
        {
            const char *more = "All patch notes";
            const float mw = gfx.measure(Font::SemiBold, 17, more);
            const float mx = x + 820 - 32 - mw;
            gfx.text_mid(Font::SemiBold, 17, mx, y + 28 + 9, notes_focus_ ? C(kFrost) : C(kMuted), Align::Left, more);
            Texture *ic = notes_focus_ ? hint_cross_ : chevron_;
            if (ic) gfx.image(ic, mx - 8 - 22, y + 28 + 9 - 11, 22, 22, notes_focus_ ? Color{} : C(kMuted));
        }
        draw_lines(gfx, Font::Regular, 21, x + 32, y + 28 + 18 + 10, 31.5f, C(kBody), news_lines);
        if (notes_focus_ && !notes_open_)
            ring(gfx, x, y, 820, nh, 26, 1.f, true);
    }
    if (notes_box)
        y += nh + 24;
    if (ch > 0)
    {
        draw_lines(gfx, Font::Regular, 18, x, y, 27, C(0xB9C4D3, u), credits);
        y += ch + 24;
    }
    if (u > 0.01f)
    {
        const float dy = std::min(y, bottom - dh);
        gfx.panel(x, dy, 820, dh, C(kCyan, 0.08f * u), 1.f, 22, C(kCyan, 0.35f * u), 1.25f);
        gfx.text_mid(Font::Bold, 14, x + 32, dy + 22 + 8, C(kCyan, u), Align::Left, "BRING YOUR OWN DISC", 2);
        draw_lines(gfx, Font::Regular, 18, x + 32, dy + 22 + 16 + 10, 27, C(kBody, u), disc);
    }
    /* Open: the box grows from What's new up over the description and down to the bottom. */
    if (ne > 0)
    {
        const float top = ny + (desc_y - ny) * ne;
        const float oh = (ny + nh + (bottom - ny - nh) * ne) - top;
        draw_notes(gfx, a, x, top, 820, oh, ne);
    }

    /* Right: the panel, 640 wide, padding 40, 28 between its parts. */
    const float pw = 640, px = W - 96 - pw, py = 190, ix = px + 40, iw = pw - 80;
    const JobState job = engine().job();
    const bool busy = job.phase == Phase::Working && job.app_id == a.id;
    const bool setting_up = busy && job.kind == "setup";
    const bool deleting = busy && job.kind == "delete";
    const bool installing = busy && !setting_up && !deleting; /* the port's install */
    /* "312 MB of 476 MB (5.6 MB/s)": how much, and how fast. */
    auto amount = [&](std::uint64_t done, std::uint64_t total) {
        std::string t = size_text(done) + " of " + size_text(total);
        if (speed_ > 0.05f)
        {
            char sp[32];
            std::snprintf(sp, sizeof sp, speed_ < 10 ? " (%.1f MB/s)" : " (%.0f MB/s)", double(speed_));
            t += sp;
        }
        return t;
    };
    bool up = false;
    const std::string st = state_of(a, &up);
    const std::string inst = engine().installed(a.id);
    const bool setup = show_setup(a);
    const auto ctl = page_controls(a);
    auto focused = [&](Control::Kind kind, int slot) {
        const Control &c = ctl[std::size_t(std::clamp(page_focus_, 0, int(ctl.size()) - 1))];
        return !picker_ && !confirm_ && !notes_focus_ && c.kind == kind && (kind != Control::Slot || c.slot == slot);
    };

    /* Heights first, so the glass fits its contents. */
    const auto notice = notice_.empty() || busy ? std::vector<std::string>{} : wrap(gfx, Font::SemiBold, 18, iw, notice_, 3);
    const float head_h = 112, btn_h = 84, prog_h = installing ? 12 + 12 + 22 : 0;
    const float notice_h = notice.empty() ? 0 : 27 * float(notice.size());
    const float slot_h = 74;
    const float setup_h = setup ? 22 + 14 + slot_h * float(a.slots.size()) + 14 + 64 + (setting_up ? 14 + 10 + 12 + 22 : 0) : 0;
    const std::pair<std::string, std::string> rows[] = {
        {"Version", version_of(a)},
        {"Download", a.size},
        {"Installs to", app_folder(a.id)},
        {"You bring", "Your " + (a.needs.empty() ? std::string("own game") : a.needs)},
        {"Source", a.source == "github" ? "github.com/" + a.repo : a.url},
    };
    int nrows = 0;
    for (const auto &r : rows)
        nrows += r.second.empty() ? 0 : 1;
    const float rows_h = setup ? 0 : 56 * float(nrows);
    const auto note = wrap(gfx, Font::Regular, 17, iw - 34, "Updates replace only the app. Your game install, saves, DLC and mods stay where they are.", 2);
    const float note_h = 24.6f * float(note.size());
    const bool can_delete = !inst.empty() && inst != "?";
    const float del_h = can_delete ? 48 : 0;
    float ph = 40 + head_h + 28 + btn_h;
    for (float part : {prog_h, notice_h, setup_h, rows_h, del_h, note_h})
        if (part > 0)
            ph += 28 + part;
    ph += 40;
    ph = std::min(ph, H - py - 40);
    glass(gfx, px, py, pw, ph, 34, C(kMidnight, 0.74f), true);

    float ry = py + 40;
    if (t.icon)
        gfx.image(t.icon, ix, ry, 112, 112, {}, 26);
    else
    {
        gfx.panel(ix, ry, 112, 112, C(kFrost, 0.1f), 1.f, 26);
        gfx.text_mid(Font::ExtraBold, 44, ix + 56, ry + 56, C(kFrost), Align::Center, a.name.substr(0, 2));
    }
    gfx.text_mid(Font::ExtraBold, 28, ix + 134, ry + 56 - 16, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 28, iw - 134, a.name));
    gfx.text_mid(Font::Regular, 19, ix + 134, ry + 56 + 20, C(0xB9C4D3), Align::Left,
                 busy ? "Working \xE2\x80\x94 keep the store open" : st);
    ry += head_h + 28;

    /* The primary button. While working it names what it's doing, light on dark. */
    {
        std::string label;
        Color bg = C(kFrost), fg = C(kMidnight), edge = {0, 0, 0, 0};
        if (deleting)
        {
            label = "Deleting\xE2\x80\xA6";
            bg = C(0xE5484D, 0.16f);
            fg = C(kFrost);
            edge = C(0xE5484D, 0.55f);
        }
        else if (installing)
        {
            const bool dl = job.step.rfind("Download", 0) == 0 || job.step.rfind("Finding", 0) == 0 || job.step == "Starting";
            const bool check = job.step.rfind("Checking", 0) == 0;
            label = dl ? "Downloading\xE2\x80\xA6" : check ? "Checking\xE2\x80\xA6" : (inst.empty() ? "Installing\xE2\x80\xA6" : "Updating\xE2\x80\xA6");
            bg = C(kCyan, 0.16f);
            fg = C(kFrost);
            edge = C(kCyan, 0.55f);
        }
        else if (busy)
        {
            label = "Setting up game files\xE2\x80\xA6";
            bg = C(kFrost, 0.10f);
            fg = C(kSoft);
        }
        else if (store_too_old(a) && (inst.empty() || inst == "?"))
        {
            label = "Needs Recomp Store " + a.min_store;
            bg = C(kFrost, 0.12f);
            fg = C(kMuted);
        }
        else if (inst.empty())
            label = "Install";
        else if (inst == "?")
        {
            label = "Manage with Recomp Store";
            bg = C(kCyan);
        }
        else if (up)
        {
            label = "Update to " + version_of(a);
            bg = C(kCyan);
        }
        else if (game_ready(a))
            label = "Open";
        else
        {
            label = "Set up the game files below";
            bg = C(kFrost, 0.12f);
            fg = C(kMuted);
        }
        const bool f = focused(Control::Primary, -1);
        gfx.panel(ix, ry, iw, btn_h, bg, 1.f, 24, edge, edge.a > 0 ? 1.5f : 0);
        ring(gfx, ix, ry, iw, btn_h, 24, f ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 26, ix + iw / 2, ry + btn_h / 2, fg, Align::Center, label);
        ry += btn_h;
    }
    if (installing)
    {
        ry += 28;
        const float frac = job.total ? float(double(job.done) / double(job.total)) : 0.f;
        gfx.panel(ix, ry, iw, 12, C(kFrost, 0.14f), 1.f, 6);
        gfx.panel(ix, ry, std::max(12.f, iw * clamp01(frac)), 12, C(kCyan), 1.f, 6);
        std::string right;
        if (job.total && (job.step.rfind("Download", 0) == 0 || job.step == "Installing"))
            right = amount(job.done, job.total);
        else if (job.total)
            right = std::to_string(int(frac * 100 + 0.5f)) + "%";
        gfx.text_mid(Font::Regular, 18, ix, ry + 12 + 12 + 11, C(kSoft), Align::Left, job.step);
        gfx.text_mid(Font::Regular, 18, ix + iw, ry + 12 + 12 + 11, C(kSoft), Align::Right, right);
        ry += prog_h;
    }
    if (!notice.empty())
    {
        ry += 28;
        ry += draw_lines(gfx, Font::SemiBold, 18, ix, ry, 27, notice_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), notice);
    }

    if (setup)
    {
        ry += 28;
        gfx.text_mid(Font::Bold, 15, ix, ry + 11, C(kCyan), Align::Left, "YOUR GAME FILES", 2);
        const std::string dir = a.data_dir.empty() ? app_folder(a.id) : a.data_dir;
        gfx.text_mid(Font::Regular, 16, ix + iw, ry + 11, C(kDim), Align::Right, fit(gfx, Font::Regular, 16, iw - 240, dir));
        ry += 22 + 14;
        bool all_ready = true, any_chosen = false;
        for (int i = 0; i < int(a.slots.size()); ++i)
        {
            const Slot &s = a.slots[std::size_t(i)];
            const bool ready = slot_ready(a, s);
            auto ch = chosen_.find(a.id + "/" + s.key);
            const bool chosen = ch != chosen_.end();
            any_chosen = any_chosen || chosen;
            if (s.required && !ready) all_ready = false;
            gfx.panel(ix, ry, iw, 1.25f, kLine, 1.f, 0);
            const Color dot = ready && !chosen ? C(kGreen) : chosen ? C(kCyan) : s.required ? C(kCyan, 0.55f) : C(kFrost, 0.3f);
            gfx.panel(ix, ry + slot_h / 2 - 6, 12, 12, dot, 1.f, 6);
            const bool can = slot_kind_known(s.kind);
            std::string status;
            if (!can) status = ready ? "In place" : "Not something this version of the store sets up";
            else if (chosen) status = base_name(ch->second);
            else if (ready && s.multiple)
            {
                /* DLC: each package in a folder of its own. */
                int packs = 0;
                std::vector<FsEntry> sub;
                if (list_dir(slot_target(a, s), sub, "\x01"))
                    for (const FsEntry &e : sub) packs += e.is_dir ? 1 : 0;
                status = packs == 1 ? "1 package in place" : std::to_string(packs) + " packages in place";
            }
            else if (ready)
            {
                const std::string src = slot_source(a, s);
                status = src.empty() ? "Ready" : "Ready \xC2\xB7 from " + base_name(src);
            }
            else status = s.required ? "Not set up yet" : "Not set up \xC2\xB7 optional";
            const char *btn = !can ? "Later" : (chosen || ready) ? (s.multiple && !chosen ? "Add" : "Change") : "Choose\xE2\x80\xA6";
            const float bw = gfx.measure(Font::SemiBold, 17, btn) + 36;
            gfx.text_mid(Font::SemiBold, 19, ix + 28, ry + slot_h / 2 - 11, C(kFrost), Align::Left, s.label);
            gfx.text_mid(Font::Regular, 16, ix + 28, ry + slot_h / 2 + 13, C(kMuted), Align::Left,
                         fit(gfx, Font::Regular, 16, iw - 28 - bw - 24, status));
            const float bx = ix + iw - bw, by = ry + slot_h / 2 - 21;
            glass(gfx, bx, by, bw, 42, 14, !can ? Color{1, 1, 1, 0.04f} : kGlass, false);
            ring(gfx, bx, by, bw, 42, 14, focused(Control::Slot, i) ? 1.f : 0.f, true);
            gfx.text_mid(Font::SemiBold, 17, bx + bw / 2, by + 21, !can ? C(kDim) : C(kFrost), Align::Center, btn);
            ry += slot_h;
        }
        ry += 14;
        std::string label;
        Color bg = C(kFrost, 0.12f), fg = C(kMuted);
        if (setting_up)
            label = job.step.rfind("Checking", 0) == 0  ? "Checking the copied files\xE2\x80\xA6"
                    : job.step.rfind("Reading", 0) == 0 ? job.step + "\xE2\x80\xA6"
                    : job.step.rfind("Setting up", 0) == 0 ? job.step + "\xE2\x80\xA6"
                                                            : "Copying the game's files\xE2\x80\xA6";
        else if (any_chosen)
        {
            label = "Set up game files";
            bg = C(kFrost);
            fg = C(kMidnight);
        }
        else if (all_ready)
        {
            label = "Game files are ready";
            bg = C(kGreen, 0.18f);
            fg = C(0x9FF0C6);
        }
        else
            label = a.slots.size() > 1 ? "Choose the files above first" : "Choose your disc image first";
        if (setting_up)
        {
            bg = C(kGreen, 0.14f);
            fg = C(kFrost);
        }
        gfx.panel(ix, ry, iw, 64, bg, 1.f, 20, setting_up ? C(kGreen, 0.5f) : Color{}, setting_up ? 1.5f : 0);
        ring(gfx, ix, ry, iw, 64, 20, focused(Control::Setup, -1) ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 21, ix + iw / 2, ry + 32, fg, Align::Center, label);
        ry += 64;
        if (setting_up)
        {
            ry += 14;
            const float frac = job.total ? float(double(job.done) / double(job.total)) : 0.f;
            gfx.panel(ix, ry, iw, 10, C(kFrost, 0.14f), 1.f, 5);
            gfx.panel(ix, ry, std::max(10.f, iw * clamp01(frac)), 10, C(kGreen), 1.f, 5);
            gfx.text_mid(Font::Regular, 17, ix, ry + 10 + 12 + 11, C(kSoft), Align::Left, job.step);
            gfx.text_mid(Font::Regular, 17, ix + iw, ry + 10 + 12 + 11, C(kSoft), Align::Right, job.total ? amount(job.done, job.total) : "");
            ry += 10 + 12 + 22;
        }
    }
    else
    {
        ry += 28;
        for (const auto &r : rows)
        {
            if (r.second.empty())
                continue;
            gfx.panel(ix, ry, iw, 1.25f, kLine, 1.f, 0);
            gfx.text_mid(Font::Regular, 19, ix, ry + 28, C(kMuted), Align::Left, r.first);
            gfx.text_mid(Font::Regular, 19, ix + iw, ry + 28, C(kFrost), Align::Right,
                         fit(gfx, Font::Regular, 19, iw - 160, r.second));
            ry += 56;
        }
    }
    if (can_delete)
    {
        /* Delete: quiet until chosen, then a confirmation first. */
        ry += 28;
        const char *label = "Delete game";
        const float bw = gfx.measure(Font::SemiBold, 18, label) + 48;
        const bool f = focused(Control::Delete, -1);
        glass(gfx, ix, ry, bw, 48, 16, f ? C(0xE5484D, 0.18f) : Color{1, 1, 1, 0.05f}, false);
        ring(gfx, ix, ry, bw, 48, 16, f ? 1.f : 0.f, true);
        gfx.text_mid(Font::SemiBold, 18, ix + bw / 2, ry + 24, C(0xFF8A80), Align::Center, label);
        ry += 48;
    }
    ry += 28;
    if (ry + note_h < py + ph)
    {
        if (shield_)
            gfx.image(shield_, ix, ry + 2, 22, 22, C(kGreen));
        draw_lines(gfx, Font::Regular, 17, ix + 34, ry, 24.6f, C(kSoft), note);
    }
    gfx.set_layer();
}

void Store::draw_picker(Gfx &gfx)
{
    if (!picker_)
        return;
    const App *a = find(page_id_);
    std::string title, hint_line;
    if (picker_mode_ == 1)
    {
        title = "Choose: ShadowMountPlus";
        hint_line = "Your shadowmountplus.elf, wherever you keep your payloads";
    }
    else
    {
        if (!a || picker_slot_ < 0 || picker_slot_ >= int(a->slots.size()))
            return;
        const Slot &slot = a->slots[std::size_t(picker_slot_)];
        title = "Choose: " + slot.label;
        if (slot.kind == "stfs")
            hint_line = slot.multiple ? "Any one of your DLC files from the console: every pack in that folder is set up"
                                      : "Your Title Update from the console: the file whose name starts with TU_";
        else if (slot.kind == "image")
            hint_line = "Your own " + a->name + " disc image" + (slot.disc_id.empty() ? std::string() : ", " + slot.disc_id) +
                        " (.iso, .gcm or .ciso)";
        else if (slot.kind == "file")
            hint_line = slot.n64 ? "Your own " + a->name + " ROM (.z64, .n64 or .v64): the store checks it and turns it into a .z64"
                        : !slot.pick.empty() ? "Your " + slot.pick + (slot.also.empty() ? std::string() : ": the files that go with it, from the same folder, are copied too")
                        : "Your own " + slot.label + (slot.ext.empty() ? std::string() : " (" + slot.ext + ")");
        else
            hint_line = slot.title_id.empty() ? std::string("Your own Xbox 360 disc image (.iso)") : "Your own " + a->name + " disc image (.iso)";
    }
    const float k = ease_out(picker_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 28, k);
    const float bw = 1180, bh = 700, bx = (W - bw) / 2, by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 36, C(0x0E1729, 0.97f), true);

    /* Left: where to look. */
    gfx.panel(bx + 320, by + 1, 1.25f, bh - 2, kLine, 1.f, 0);
    gfx.text_mid(Font::Bold, 15, bx + 22 + 14, by + 34 + 9, C(kDim), Align::Left, "LOOK IN", 2);
    float ly = by + 34 + 18 + 12;
    for (int i = 0; i < int(places_.size()); ++i)
    {
        const Place &p = places_[std::size_t(i)];
        const bool on = i == picker_place_;
        const bool f = on && picker_col_ == 0;
        if (on)
            gfx.panel(bx + 22, ly, 276, 76, f ? C(kFrost) : C(kFrost, 0.14f), 1.f, 16);
        gfx.text_mid(Font::SemiBold, 19, bx + 22 + 18, ly + 28, f ? C(kMidnight) : C(kFrost), Align::Left, p.label);
        gfx.text_mid(Font::Regular, 15, bx + 22 + 18, ly + 52, f ? C(0x3B4556) : C(kDim), Align::Left, p.path);
        ly += 76 + 6;
    }

    /* Right: the folder. */
    const float rx = bx + 320 + 40, rw = bw - 320 - 80;
    float ry = by + 34;
    gfx.text_mid(Font::ExtraBold, 30, rx, ry + 19, C(kFrost), Align::Left, title);
    gfx.text_mid(Font::Regular, 17, rx, ry + 38 + 6 + 11, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, rw - 200, hint_line));
    {
        const float cw = gfx.measure(Font::SemiBold, 18, "Back") + 44 + 30;
        glass(gfx, rx + rw - cw, ry + 4, cw, 46, 23, kGlass, false);
        if (hint_circle_)
            gfx.image(hint_circle_, rx + rw - cw + 16, ry + 4 + 23 - 11, 22, 22);
        gfx.text_mid(Font::SemiBold, 18, rx + rw - cw + 16 + 22 + 8, ry + 27, C(kFrost), Align::Left, "Back");
    }
    ry += 38 + 6 + 22 + 18;
    gfx.text_mid(Font::Regular, 16, rx, ry + 10, C(kDim), Align::Left, fit(gfx, Font::Regular, 16, rw, picker_path_));
    ry += 20 + 14;

    const int rows = 6;
    const float row_h = 72, gap = 8;
    if (picker_err_)
        gfx.text_mid(Font::Regular, 19, rx, ry + 30, C(kMuted), Align::Left, "This folder can't be opened.");
    else if (entries_.empty())
        gfx.text_mid(Font::Regular, 19, rx, ry + 30, C(kMuted), Align::Left,
                     picker_mode_ == 1 ? "No .elf files or folders here."
                     : hint_line.rfind("Your own", 0) == 0 ? "No disc images or folders here."
                                                           : "No Xbox 360 packages or folders here.");
    for (int i = picker_top_; i < int(entries_.size()) && i < picker_top_ + rows; ++i)
    {
        const FsEntry &e = entries_[std::size_t(i)];
        const bool f = i == picker_sel_ && picker_col_ == 1;
        gfx.panel(rx, ry, rw, row_h, {1, 1, 1, f ? 0.14f : 0.06f}, 1.f, 18, {1, 1, 1, f ? 0.0f : 0.12f}, 1.25f);
        ring(gfx, rx, ry, rw, row_h, 18, f ? 1.f : 0.f);
        Texture *ic = e.is_dir ? folder_icon_ : disc_icon_;
        if (ic)
            gfx.image(ic, rx + 20, ry + row_h / 2 - 15, 30, 30, e.is_dir ? C(kSoft) : C(kCyan));
        const char *tag = e.is_dir ? "Open" : "Use this";
        const float tw = gfx.measure(Font::Bold, 15, tag);
        const std::string sz = e.is_dir ? "" : size_text(e.size);
        const float sw = sz.empty() ? 0 : gfx.measure(Font::Regular, 17, sz) + 24;
        gfx.text_mid(Font::SemiBold, 20, rx + 68, ry + row_h / 2, C(kFrost), Align::Left,
                     fit(gfx, Font::SemiBold, 20, rw - 68 - tw - sw - 48, e.name));
        if (!sz.empty())
            gfx.text_mid(Font::Regular, 17, rx + rw - 20 - tw - 24, ry + row_h / 2, C(kMuted), Align::Right, sz);
        gfx.text_mid(Font::Bold, 15, rx + rw - 20, ry + row_h / 2, e.is_dir ? C(kSoft) : C(0x9FF0C6), Align::Right, tag);
        ry += row_h + gap;
    }
    if (int(entries_.size()) > rows)
    {
        /* Where in the folder: a thin bar at the right. */
        const float th = (row_h + gap) * rows - gap, top = ry - (row_h + gap) * float(std::min<int>(rows, int(entries_.size()) - picker_top_));
        const float frac = float(rows) / float(entries_.size());
        const float pos = float(picker_top_) / float(entries_.size() - rows);
        gfx.panel(rx + rw + 14, top, 4, th, C(kFrost, 0.1f), 1.f, 2);
        gfx.panel(rx + rw + 14, top + (th - th * frac) * pos, 4, th * frac, C(kFrost, 0.5f), 1.f, 2);
    }
    gfx.set_layer();
}

/* "Delete <game>?": what goes, that it can't be undone, Cancel chosen first. */
void Store::draw_confirm(Gfx &gfx)
{
    if (!confirm_)
        return;
    const App *a = find(page_id_);
    if (!a)
        return;
    const float k = ease_out(confirm_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 820, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const std::string folder = app_folder(a->id);
    const bool saves = !a->saves.empty();
    std::string body = saves ? "This deletes " + folder + ": the game, the files from your disc and your mods."
                             : "This deletes " + folder + " and everything in it: the game, the files from your disc, your saves and mods.";
    if (a->has_setup && !a->data_dir.empty() && a->data_dir.rfind(folder + "/", 0) != 0 && a->data_dir != folder)
        body += " The game files the store set up in " + a->data_dir + " go too.";
    if (saves)
    {
        std::string where;
        for (std::size_t i = 0; i < a->saves.size() && i < 3; ++i) where += (i ? ", " : "") + a->saves[i];
        body += " Keep my saves leaves your saves (" + where + ") where they are, so installing it again picks up where you left off.";
        body += " Delete everything takes them too, and that can't be undone.";
    }
    else
        body += " It can't be undone.";
    const auto title = wrap(gfx, Font::ExtraBold, 32, iw, "Delete " + a->name + "?", 2);
    const auto lines = wrap(gfx, Font::Regular, 20, iw, body, saves ? 8 : 5);
    const float bh = pad + 40 * float(title.size()) + 18 + 30 * float(lines.size()) + 36 + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    for (std::size_t i = 0; i < title.size(); ++i)
        gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 40 * (float(i) + 0.5f), C(kFrost), Align::Left, title[i]);
    y += 40 * float(title.size()) + 18;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines) + 36;
    const int n = saves ? 3 : 2;
    const float gap = 16, w2 = (iw - gap * float(n - 1)) / float(n);
    const char *labels2[2] = {"Cancel", "Delete"};
    const char *labels3[3] = {"Cancel", "Keep my saves", "Delete everything"};
    for (int i = 0; i < n; ++i)
    {
        const float x = bx + pad + float(i) * (w2 + gap);
        const bool on = confirm_sel_ == i;
        const bool red = i == n - 1;
        const Color bg = red ? C(0xE5484D, on ? 1.f : 0.25f) : C(kFrost, on ? 1.f : 0.12f);
        const Color fg = red ? C(0xFFFFFF) : (on ? C(kMidnight) : C(kFrost));
        const char *label = saves ? labels3[i] : labels2[i];
        gfx.panel(x, y, w2, 64, bg, 1.f, 20);
        ring(gfx, x, y, w2, 64, 20, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 22, x + w2 / 2, y + 32, fg, Align::Center, label);
    }
    gfx.set_layer();
}

void Store::draw_hints(Gfx &gfx)
{
    /* Bottom right, 96 in and 40 up: a 26px outline icon, 10, the label; 34 between. */
    const float cy = gfx.design_h() - 40 - 13;
    float x = gfx.design_w() - 96;
    auto hint = [&](Texture *icon, const char *label) {
        const float w = gfx.measure(Font::Regular, 19, label);
        gfx.text_mid(Font::Regular, 19, x - w, cy, C(0xD6DEEA), Align::Left, label);
        x -= w + 10 + 26;
        if (icon)
            gfx.image(icon, x, cy - 13, 26, 26);
        x -= 34;
    };
    const bool deeper = page_open_ || picker_;
    if (confirm_)
    {
        hint(hint_circle_, "Cancel");
        hint(hint_cross_, "Choose");
        return;
    }
    if (page_open_ && !picker_ && notes_open_)
    {
        hint(hint_circle_, "Close");
        return;
    }
    if (page_open_ && !picker_)
        if (const App *a = find(page_id_); a && a->soon)
        {
            hint(hint_circle_, "Back");
            return;
        }
    if (page_open_ && !picker_ && notes_focus_)
    {
        hint(hint_circle_, "Back");
        hint(hint_cross_, "Patch notes");
        return;
    }
    hint(hint_circle_, deeper ? "Back" : "Close");
    if (picker_)
        hint(hint_cross_, "Choose");
    else
        hint(hint_cross_, page_open_ ? "Select" : "Open");
}

void Store::draw_boot(Gfx &gfx)
{
    /* Boot.dc.html: the mark builds itself (arrow drops, bowl slides in, leg slides up),
     * flashes, settles with a soft glow; the name spreads in; then the loading bar. */
    const float W = gfx.design_w(), H = gfx.design_h();
    if (gradient_)
        gfx.image(gradient_, 0, 0, W, H);
    const float t = boot_t_;
    const float mx = 810, my = 300, ms = 300; /* the mark's 256-unit box, drawn 300px */
    const float u = ms / 256.f;
    /* Glow: up to 0.95 at the flash, settling to 0.35. */
    const float ft = (t - 1.05f) / 1.1f;
    float glow = 0;
    if (ft > 0)
        glow = ft < 0.22f ? 0.95f * (ft / 0.22f) : 0.95f + (0.35f - 0.95f) * ease_out((ft - 0.22f) / 0.78f);
    if (mark_glow_ && glow > 0)
    {
        const float gs = ms * 1.6f;
        gfx.image(mark_glow_, mx + ms / 2 - gs / 2, my + ms / 2 - gs / 2, gs, gs, C(kCyan, std::min(1.f, glow)));
    }
    struct Piece
    {
        float delay, dur, dx, dy;
    } pieces[3] = {{0.15f, 0.7f, 0, -70 / u}, {0.38f, 0.65f, 60 / u, 0}, {0.58f, 0.6f, 46 / u, 46 / u}};
    for (int i = 0; i < 3; ++i)
    {
        if (!mark_[i])
            continue;
        const float p = (t - pieces[i].delay) / pieces[i].dur;
        if (p <= 0)
            continue;
        const float e = ease_back(p);
        const float a = clamp01(p * 1.6f);
        const float ox = pieces[i].dx * u * (1 - e), oy = pieces[i].dy * u * (1 - e);
        /* Brighter for a moment at the flash. */
        const float lift = ft > 0 && ft < 0.5f ? 1.f - std::fabs(ft - 0.22f) / 0.28f : 0.f;
        gfx.image(mark_[i], mx + ox, my + oy, ms, ms, {1, 1, 1, a});
        if (lift > 0)
            gfx.image(mark_[i], mx + ox, my + oy, ms, ms, C(kCyan, 0.35f * std::max(0.f, lift)));
    }
    /* "Recomp Store": Sora 56, letter spacing 22 -> 1 while it fades in; top 584. */
    const float word = ease_out((t - 1.45f) / 0.9f);
    if (word > 0)
        gfx.text_mid(Font::ExtraBold, 56, W / 2, 584 + 35, C(kFrost, word), Align::Center, "Recomp Store", 1 + 21 * (1 - word));
    /* The loading bar (280x4 at top 690) and its line, 16 under it. */
    const float load = ease_out((t - 2.0f) / 0.6f);
    if (load > 0)
    {
        const float dy = (1 - load) * 10;
        const float w = 280, x = W / 2 - w / 2, y = 690 + dy;
        gfx.panel(x, y, w, 4, C(kFrost, 0.14f * load), 1.f, 2);
        const float p = std::fmod(std::max(0.f, t - 2.0f) / 1.3f, 1.f);
        const float q = p < 0.5f ? 4 * p * p * p : 1 - std::pow(-2 * p + 2, 3.f) / 2; /* ease-in-out */
        const float seg = 120;
        const float sx = x - 120 + (320 + 120) * q;
        const float l = std::max(x, sx), r = std::min(x + w, sx + seg);
        if (r > l)
            gfx.panel(l, y, r - l, 4, C(kCyan, load), 1.f, 2, C(kCyan, 0.9f * load), 0, 7);
        std::string msg = !freed_ ? "Recomp Store needs etaHEN to install games"
                          : (catalog_.apps.empty() && !engine().loading()) ? "Couldn't reach recompstore.com. Check your connection."
                                                                           : "Loading the catalog";
        const float mw = gfx.measure(Font::SemiBold, 19, msg, 0.5f);
        const bool dots = msg == "Loading the catalog";
        const float total = mw + (dots ? 21 : 0);
        const float tx = W / 2 - total / 2, ty = y + 4 + 16 + 12;
        gfx.text_mid(Font::SemiBold, 19, tx, ty, C(kFrost, 0.7f * load), Align::Left, msg, 0.5f);
        if (dots)
            for (int i = 0; i < 3; ++i)
            {
                const float ph = std::fmod(t - 2.0f - 0.2f * float(i) + 12.f, 1.2f) / 1.2f;
                const float o = ph < 0.4f ? 0.2f + 0.8f * (ph / 0.4f) : 1.f - 0.8f * ((ph - 0.4f) / 0.6f);
                gfx.text_mid(Font::SemiBold, 19, tx + mw + 7 * float(i), ty, C(kFrost, 0.7f * load * o), Align::Left, ".");
            }
    }
}

void Store::draw(Gfx &gfx)
{
    if (!booted_)
    {
        draw_boot(gfx);
        return;
    }
    const float enter = ease_out(enter_t_);
    draw_backdrop(gfx);
    if (enter < 1 && gradient_)
        gfx.image(gradient_, 0, 0, gfx.design_w(), gfx.design_h(), {1, 1, 1, 1 - enter});
    gfx.set_intro(enter, (1 - enter) * 20);
    draw_top(gfx);
    const float k = ease_out(tab_t_);
    gfx.set_layer((1 - k) * 160 * float(tab_dir_), 0, k);
    switch (tab_)
    {
    case Discover: draw_discover(gfx); break;
    case Library: draw_library(gfx); break;
    case Updates: draw_updates(gfx); break;
    default: draw_settings(gfx); break;
    }
    gfx.set_layer();
    draw_page(gfx);
    draw_picker(gfx);
    draw_confirm(gfx);
    draw_hints(gfx);
}
} // namespace recomp
