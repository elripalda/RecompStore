/* Recomp Store - the app's screens and state, drawn after the design mockup
 * (Main.dc.html / Boot.dc.html): the focused game's art frosted behind
 * everything, glass panels, Discover / the categories / Library / Settings
 * (L1 / R1), and a game page with the download panel and the player's own
 * game files on the right. All sizes are the mockup's, in its 1920x1080
 * space; the screen draws them at its own resolution (4K: twice as sharp).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_ui.hpp"

#include "porpoise_pad.hpp"
#include "porpoise_paths.hpp"
#include "porpoise_sound.hpp"
#include "ui_app_common.hpp"

#include <cctype>
#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstring>
#include <sys/stat.h>

namespace recomp
{
using namespace porpoise::ui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

using namespace sui;

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
    hint_triangle_ = gfx.texture_file(assets + "/ui/hint-triangle.png", side(32));
    hint_square_ = gfx.texture_file(assets + "/ui/hint-square.png", side(32));
    cat_icon_[0] = gfx.texture_file(assets + "/ui/cat-games.png", side(96));
    cat_icon_[1] = gfx.texture_file(assets + "/ui/cat-emulators.png", side(96));
    cat_icon_[2] = gfx.texture_file(assets + "/ui/cat-apps.png", side(96));
    cat_icon_[3] = gfx.texture_file(assets + "/ui/cat-tools.png", side(96));
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
    /* (2.0) White fading out to the right: a colour's wash across a button. */
    {
        const int w = 128;
        std::vector<std::uint8_t> px(std::size_t(w) * 4);
        for (int x = 0; x < w; ++x)
        {
            const float t = (x + 0.5f) / w;
            px[std::size_t(x) * 4 + 0] = px[std::size_t(x) * 4 + 1] = px[std::size_t(x) * 4 + 2] = 255;
            px[std::size_t(x) * 4 + 3] = std::uint8_t(std::lround(255.f * (1 - t) * (1 - t)));
        }
        hfade_ = gfx.texture_rgba(px.data(), w, 1);
    }
    /* (2.1) Clear at the top to white at the bottom: the mosaic header's shade under its words. */
    {
        const int h = 128;
        std::vector<std::uint8_t> px(std::size_t(h) * 4);
        for (int y = 0; y < h; ++y)
        {
            const float t = (y + 0.5f) / h;
            px[std::size_t(y) * 4 + 0] = px[std::size_t(y) * 4 + 1] = px[std::size_t(y) * 4 + 2] = 255;
            px[std::size_t(y) * 4 + 3] = std::uint8_t(std::lround(255.f * t * t * (3 - 2 * t)));
        }
        vfade_ = gfx.texture_rgba(px.data(), 1, h);
    }
    porpoise::sound::load(assets);
    porpoise::sound::set_effects(true, 0.8f);
    porpoise::sound::set_music(false, 0);
    {
        /* (2.2.2) A store installed just now (nothing of it here before): What's new isn't for it. */
        struct stat st;
        fresh_ = stat((data_dir + "/prefs.json").c_str(), &st) != 0 && stat((data_dir + "/logs/store.log").c_str(), &st) != 0;
    }
    engine().start(data_dir);
    load_prefs(); /* (2.0) Customize, pins, sounds */
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
    charts_ = engine().charts(); /* (2.0) */
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
/* (2.0) A little smaller than before, so seven tiles fit a row. */
const float kTile = 216, kTileGap = 32, kTileLine = 296; /* a tile, the room between, a line of them with the words under */
const int kRowShown = 7, kGridPer = 7, kWideShown = 3;
const float kWideW = (1728 - kTileGap * 2) / 3, kWideH = 280, kWideLine = 280 + 80;
const float kHead = 50, kSectionGap = 44, kGridGap = 18, kBannerH = 360;
const float kCatH = 88; /* (2.0) the category buttons */
/* (2.1) Discover's header: the spotlight's height; split and mosaic's main card, the room beside
 * it, the cards there and the gap between them (the admin's preview draws the same). */
const float kSpotH = 520, kMainW = 1150, kSideGap = 24, kSideW = 1728 - kMainW - kSideGap, kCardGap = 16;
const float kDiscTop = 136, kViewTop = 118, kViewBottom = 1000; /* Discover's top on screen; what's shown of the lists */
} // namespace

std::vector<Store::DLine> Store::discover_lines() const
{
    std::vector<DLine> out;
    float y = 0;
    const auto feat = header_apps();
    const int hs = header_style();
    if (!feat.empty() && hs != 4)
    {
        /* (2.1) In the style the catalog says: the spotlight is taller; none leaves it out. */
        DLine b;
        b.kind = 0;
        b.style = hs;
        b.h = hs == 1 ? kSpotH : kBannerH;
        b.items = feat;
        out.push_back(b);
        y = b.h + kSectionGap;
    }
    /* (2.0) The parts of the store, as buttons under the banner, once two or more have apps. */
    {
        DLine c;
        c.kind = 5;
        for (int k : {int(Games), int(Emulators), int(Apps), int(Tools)})
        {
            bool any = false;
            for (const App &a : catalog_.apps) any = any || (!a.beta && a.category == category_of(k));
            if (any) c.cats.push_back(k);
        }
        if (c.cats.size() >= 2)
        {
            c.y = y;
            c.h = kCatH;
            out.push_back(c);
            y += kCatH + kSectionGap;
        }
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
    std::vector<const App *> all, soon, custom;
    for (const App &a : catalog_.apps)
    {
        /* (1.1.0) beta games: the Library only. (2.0) A custom source's apps: a row of their own,
         * never in the admin's sections. */
        if (a.custom) custom.push_back(&a);
        else if (!a.beta) all.push_back(&a);
    }
    for (const App &a : catalog_.soon) soon.push_back(&a);
    if (prefs_.pins_on_discover)
    {
        /* (2.0) The player's pins, first. */
        std::vector<const App *> pins;
        for (const std::string &id : prefs_.pins)
            if (const App *a = find(id); a && !a->soon) pins.push_back(a);
        section(1, "Favorites", pins); /* (2.2.2) pins are favorites now */
    }
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
                if (sec.type == "category")
                {
                    for (const App *a : all)
                        if (a->category == sec.category) items.push_back(a);
                    sort_apps(sec.category, items); /* (2.1) as its tab is sorted */
                }
                else if (sec.type == "liked")
                {
                    /* (2.3.0) Most liked: the charts' likes, most first; one part of the store's when it says. */
                    for (const auto &kv : charts_.likes)
                        for (const App *a : all)
                            if (a->id == kv.first && items.size() < 21 && (sec.category.empty() || a->category == sec.category))
                            {
                                items.push_back(a);
                                break;
                            }
                }
                else if (sec.type == "collection")
                {
                    /* (2.3.0) A collection: its apps in its order; Cross on the row opens it. */
                    if (const Collection *c = collection(sec.collection))
                        for (const std::string &id : c->ids)
                            for (const App *a : all)
                                if (a->id == id) { items.push_back(a); break; }
                    const std::size_t before = out.size();
                    section(sec.layout == "grid" ? 2 : sec.layout == "wide" ? 3 : 1, sec.title.empty() && collection(sec.collection) ? collection(sec.collection)->title : sec.title, items);
                    for (std::size_t k = before; k < out.size(); ++k) out[k].coll = sec.collection;
                    continue;
                }
                else if (const std::vector<std::string> *chart = charts_.list(sec.type))
                {
                    /* (2.0) Top, trending, new, recently updated: the charts' order; (2.2.2) only one part
                     * of the store's when the section says so. */
                    for (const std::string &id : *chart)
                        for (const App *a : all)
                            if (a->id == id && items.size() < 21 && (sec.category.empty() || a->category == sec.category))
                            {
                                items.push_back(a);
                                break;
                            }
                }
                else if (sec.all)
                    items = all;
                else
                    for (const std::string &id : sec.ids)
                        for (const App &a : catalog_.apps)
                            if (a.id == id) { items.push_back(&a); break; }
                section(sec.layout == "grid" ? 2 : sec.layout == "wide" ? 3 : 1, sec.title, items);
            }
        }
    section(1, "From your sources", custom); /* (2.0) */
    return out;
}

int Store::line_first(const DLine &l, int col) const
{
    const int n = l.count();
    if (l.kind == 1) return std::clamp(col - kRowShown + 2, 0, std::max(0, n - kRowShown));
    if (l.kind == 3) return std::clamp(col - 1, 0, std::max(0, n - kWideShown));
    return 0;
}

float Store::item_x(const DLine &l, int i, int first) const
{
    if (l.kind == 3) return 96 + float(i - first) * (kWideW + kTileGap);
    if (l.kind == 1) return 96 + float(i - first) * (kTile + kTileGap);
    if (l.kind == 2) return 96 + float(i) * (kTile + kTileGap);
    if (l.kind == 5)
    {
        const float n = float(std::max(1, l.count())), bw = (1728 - kTileGap * (n - 1)) / n;
        return 96 + float(i) * (bw + kTileGap);
    }
    return 96;
}

/* The version to have: the newest release on GitHub with the game's zip once the store
 * has looked, else the one the catalog names. */
std::string Store::version_of(const App &a) const
{
    std::string n = engine().newest_version(a);
    if (n.empty() || (!a.version.empty() && version_newer(a.version, n))) return a.version; /* the catalog may know first */
    n.erase(0, version_start(n)); /* "v1.0.4" reads 1.0.4, "V.1.1.1" reads 1.1.1 */
    return n;
}

std::string Store::state_of(const App &a, bool *update_ready) const
{
    const std::string v = inst_v(a.id);
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
        /* (2.1) Kept at an older version the player picked: not offered as an update here (Update
         * All leaves it); its page still offers the newest. */
        if (!kept_at(a).empty())
            return "Kept at " + v.substr(version_start(v));
        if (update_ready)
            *update_ready = true;
        return "Update " + want + " ready";
    }
    return "Installed \xC2\xB7 " + v.substr(version_start(v));
}

/* (2.2.3) The one line under a tile: Installed or Update ready, Coming Soon, or else who made it
 * (no version numbers: the page and the list say those). */
std::string Store::tile_line(const App &a, bool *update_ready) const
{
    if (update_ready) *update_ready = false;
    if (a.soon) return "Coming Soon";
    bool up = false;
    state_of(a, &up);
    if (update_ready) *update_ready = up;
    if (up) return "Update ready";
    if (!inst_v(a.id).empty()) return "Installed";
    if (!a.author.empty()) return a.author;
    const int c = category_index(a.category);
    static const char *const kinds[4] = {"Game", "Emulator", "App", "Tool"};
    return kinds[std::clamp(c, 0, 3)];
}

const std::string &Store::inst_v(const std::string &id) const
{
    auto it = inst_.find(id);
    if (it == inst_.end())
    {
        Inst i;
        i.v = engine().installed(id);
        i.at = time_;
        it = inst_.emplace(id, std::move(i)).first;
    }
    return it->second.v;
}

long long Store::inst_time(const std::string &id) const
{
    inst_v(id);
    Inst &i = inst_[id];
    if (i.when == -2) i.when = installed_time(id);
    return i.when;
}

void Store::inst_tick()
{
    /* Anything that can change what's installed: the catalog, the job and the queue, the console's apps. */
    const JobState j = engine().job();
    const unsigned long long key = (unsigned long long)revision_ * 1000003ull ^ (unsigned long long)int(j.phase) * 7919ull ^
                                   std::hash<std::string>{}(j.kind + "|" + j.app_id) ^ (unsigned long long)engine().console_revision() << 20;
    if (key != inst_key_)
    {
        /* Not all at once (a frame of file reads): the job's app now, the rest a few a frame. */
        inst_key_ = key;
        for (auto &kv : inst_) kv.second.at = -100.f;
        for (const std::string &id : {j.app_id, inst_last_app_})
            if (!id.empty()) inst_.erase(id);
        inst_last_app_ = j.app_id;
        return;
    }
    /* Looked at again: what changed above, six a frame; changes made from outside the store (FTP),
     * the oldest one a frame, every 10 s. */
    int stale = 6, old = 1;
    for (auto &kv : inst_)
    {
        const bool was_stale = kv.second.at < -1.f;
        if (was_stale ? stale-- <= 0 : (time_ - kv.second.at <= 10.f || old-- <= 0)) continue;
        kv.second.v = engine().installed(kv.first);
        kv.second.when = -2;
        kv.second.at = time_;
    }
}

bool Store::newer_out(const App &a) const
{
    const std::string v = inst_v(a.id), want = version_of(a);
    return !v.empty() && v != "?" && !want.empty() && version_key(v) != version_key(want) && !version_newer(v, want) && !store_too_old(a);
}

std::string Store::kept_at(const App &a) const
{
    const std::string h = engine().held(a.id);
    if (h.empty()) return "";
    const std::string v = inst_v(a.id);
    return !v.empty() && v != "?" && version_key(h) == version_key(v) ? v : std::string(); /* still that one */
}

int Store::header_style() const
{
    const std::string &s = catalog_.header_style;
    return s == "spotlight" ? 1 : s == "split" ? 2 : s == "mosaic" ? 3 : s == "none" ? 4 : 0;
}

std::vector<const App *> Store::header_apps() const
{
    /* (2.1) The header's own games when the catalog names them, else the featured ones. */
    std::vector<const App *> out;
    for (const std::string &id : catalog_.header_games)
        if (const App *a = find(id); a && !a->beta && std::find(out.begin(), out.end(), a) == out.end())
            out.push_back(a);
    if (out.empty())
        out = featured_apps();
    const int hs = header_style();
    const std::size_t most = hs == 1 ? 1 : hs == 3 ? 3 : out.size();
    if (out.size() > most)
        out.resize(most);
    return out;
}

const App *Store::header_focus(const DLine &l, int col) const
{
    const int n = int(l.items.size());
    if (n == 0)
        return nullptr;
    const int cur = std::clamp(slide_, 0, n - 1);
    if (l.style == 1)
        return l.items[0];
    if (l.style == 2)
        return l.items[std::size_t((cur + std::max(0, col)) % n)]; /* the banner, or a card beside it: the next ones */
    if (l.style == 3)
        return l.items[std::size_t(std::clamp(col, 0, n - 1))];
    return l.items[std::size_t(cur)];
}

std::vector<const App *> Store::featured_apps() const
{
    std::vector<const App *> out;
    for (const std::string &id : catalog_.featured)
        if (const App *a = find(id))
            out.push_back(a);
    if (out.empty())
        for (const App &a : catalog_.apps)
            if (!a.hero_url.empty() && !a.beta && out.size() < 3)
                out.push_back(&a);
    return out;
}

bool Store::show_setup(const App &a) const
{
    return a.has_setup && !a.slots.empty() && !inst_v(a.id).empty();
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
    const std::string inst = inst_v(a.id);
    if (!inst.empty() && inst != "?")
        c.push_back({Control::Delete, -1});
    /* (1.1.0) beside Delete; (2.0) for an app from a custom source, reporting the source instead
     * (its logs would only reach Recomp Store, which didn't make it). */
    c.push_back({a.custom ? Control::Report : a.beta ? Control::Feedback /* (2.3.0) a tester's */ : Control::Logs, -1});
    if (has_versions(a))
        c.push_back({Control::Versions, -1}); /* (2.1) after them */
    if (!a.custom && !a.soon)
        c.push_back({Control::Like, -1}); /* (2.3.0) */
    c.push_back({Control::Favorite, -1}); /* (2.2.2) the star, at the end of that row */
    if (!inst.empty() && inst != "?" && !(a.beta && a.sig.empty()))
        c.push_back({Control::AutoUpdate, -1}); /* (2.2.2) a row of its own */
    if (!inst.empty() && inst != "?" && !a.saves.empty() && !a.custom)
        c.push_back({Control::Saves, -1}); /* (2.3.0) back up and restore, a row of its own */
    if (!inst.empty() && inst != "?" && !a.mods.empty())
        c.push_back({Control::Mods, -1}); /* (2.3.0) a row of its own */
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
    page_rel_ = -1; /* (2.2.2) the rows under it: not focused, scrolled to the top */
    page_scroll_ = page_scroll_to_ = 0;
    rel_focus_.clear();
    notice_.clear();
    reset_notes();
    if (const App *a = find(id))
        if (!a->soon && a->source == "github" && !a->repo.empty())
            engine().want_notes(a->repo);
    play(int(Sfx::Open));
}

void Store::preview_open(const std::string &id)
{
    inst_.clear();
    booted_ = true;
    boot_t_ = 10;
    enter_t_ = 1;
    page_id_ = id;
    page_open_ = true;
    page_t_ = 1;
    page_rel_ = -1; /* (2.2.2) */
    page_scroll_ = page_scroll_to_ = 0;
    picker_ = false;
    confirm_ = false;
    vers_ = vers_ask_ = moved_ = false; /* (2.1) */
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
    page_back_.clear(); /* (2.2.2) */
    page_rel_ = -1;
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
    if (job.phase == Phase::Working && job.app_id == a->id)
    {
        play(int(Sfx::Error));
        return;
    }
    if (queue_place(a->id) > 1)
    {
        /* (2.2.2) Waiting its turn: the queue, with it focused. */
        open_panel(PanelQueue);
        return;
    }
    /* A game this store is too old for: only one already here and set up opens. */
    if (store_too_old(*a) && !(game_ready(*a) && !inst_v(a->id).empty() && inst_v(a->id) != "?"))
    {
        notice_ = a->name + " needs Recomp Store " + a->min_store + " or newer. Update the store from the Library first (Updates).";
        notice_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    bool update = false;
    state_of(*a, &update);
    if (!update && !kept_at(*a).empty() && newer_out(*a))
        update = true; /* (2.1) kept at an older version: Update to the newest lets it go */
    const std::string inst = inst_v(a->id);
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
        /* (2.2.2) Into the download queue: at once when nothing else is going. A new app: where
         * to put it first, when there's more than one place. */
        if (inst.empty()) start_install(*a, "");
        else enqueue(*a, "", "");
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
    if (job.phase == Phase::Working)
    {
        /* (2.2.2) Open closes the store, which would stop what's going. */
        const App *w = find(job.app_id);
        notice_ = (w ? "Wait for " + w->name + " to finish first." : std::string("Wait for the current job to finish first.")) +
                  " Opening an app closes the store.";
        notice_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    std::string why;
    if (!open_game(installed_id(a->id), why)) /* (2.0) under its new title ID, if it was moved */
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
        if (it->second == kDownloadChoice ? engine().fetch_update(a.id, s.key) : engine().setup(a.id, s.key, it->second))
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
            if (sl.kind == "stfs" || sl.kind == "arcade") ext = "stfs";
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
    /* (2.3.0) A game's file: from a phone on the same Wi-Fi, as the first row of every folder. */
    if (picker_mode_ == 0)
    {
        FsEntry phone;
        phone.name = "Upload from your phone";
        phone.path = kPhoneChoice;
        entries_.insert(entries_.begin(), phone);
    }
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
            if (e.path == kPhoneChoice)
            {
                picker_ = false; /* (2.3.0) the receiver's sheet instead */
                open_upload(0, page_id_, picker_slot_);
            }
            else if (e.is_dir)
            {
                picker_go(e.path);
                play(int(Sfx::Select));
            }
            else if (picker_mode_ == 1)
            {
                set_smp_path(e.path);
                picker_ = false;
                settings_note_ = "ShadowMountPlus set. When it isn't running, the store starts it as it closes to open a game or refresh your home screen.";
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
    inst_tick();
    watch_usb(); /* (2.2.3) */

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
    /* (2.0) Latest Updates is the Library's Updates now. */
    if (tab_ == Updates)
    {
        tab_ = Library;
        lib_view_ = 1;
    }
    /* (2.0) The tab chosen in Customize, once the catalog says which tabs there are. */
    if (!start_applied_ && !catalog_.apps.empty())
    {
        start_applied_ = true;
        const auto order = tab_order();
        if (tab_ == Discover && prefs_.start_tab != Discover &&
            std::find(order.begin(), order.end(), prefs_.start_tab) != order.end())
        {
            tab_ = prefs_.start_tab;
            row_ = col_ = 0;
        }
    }
    toast_t_ += dt;

    page_t_ = approach(page_t_, page_open_ ? 1.f : 0.f, dt, 9.f);
    notes_t_ = approach(notes_t_, notes_open_ ? 1.f : 0.f, dt, 10.f);
    tab_t_ = std::min(1.f, tab_t_ + dt * 2.2f);
    backdrop_t_ = std::min(1.f, backdrop_t_ + dt * 1.4f);
    {
        /* (2.2.3) The tint: the focused app's colour, eased (at once with Reduce motion). Not with
         * the Calm background. */
        const App *fa = prefs_.backdrop == 0 && !backdrop_id_.empty() ? find(backdrop_id_) : nullptr;
        const Texture *ic = fa ? art(*fa).icon : nullptr;
        const bool want = ic && ic->has_tint;
        if (want)
        {
            tint_to_[0] = float((ic->tint >> 16) & 0xFF) / 255.f;
            tint_to_[1] = float((ic->tint >> 8) & 0xFF) / 255.f;
            tint_to_[2] = float(ic->tint & 0xFF) / 255.f;
        }
        const float k = prefs_.reduced_motion ? 1.f : std::min(1.f, dt * 3.f);
        for (int c = 0; c < 3; ++c)
            tint_rgb_[c] += (tint_to_[c] - tint_rgb_[c]) * k;
        tint_a_ += ((want ? 1.f : 0.f) - tint_a_) * (prefs_.reduced_motion ? 1.f : std::min(1.f, dt * 2.f));
        if (tint_a_ < 0.002f) tint_a_ = 0;
    }

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
                const std::string why = "The launcher didn't start, so nothing happened. Check that your payload loader (etaHEN or elfldr) is running, then try again.";
                if (opening_lib_) { lib_note_ = why; lib_note_bad_ = true; } /* (2.2) */
                else if (tab_ == Settings) { settings_note_ = why; settings_note_bad_ = true; }
                else { notice_ = "The launcher didn't start, so the game wasn't opened. Check that your payload loader (etaHEN or elfldr) is running, then try again."; notice_bad_ = true; }
                opening_lib_ = false;
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
            picker_ = confirm_ = vers_ = vers_ask_ = false; /* (2.1) Versions too; a question about an app stays */
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
    /* (2.2) Back from Refresh home screen: how it went (once). */
    if (!refresh_checked_ && booted_)
    {
        refresh_checked_ = true;
        std::string what;
        if (refresh_result(what))
            toast(what);
    }
    /* Back from a game that wouldn't open: its page, saying why (once). */
    if (!open_checked_ && !catalog_.apps.empty())
    {
        open_checked_ = true;
        std::string id;
        unsigned result = 0;
        bool failed = open_game_failure(id, result);
        if (failed && !find(id))
        {
            /* (2.2) An app the store didn't install, opened from Library > On this PS5. */
            std::string name = id;
            for (const ConsoleApp &c : engine().console_apps())
                if (c.title_id == id) name = c.name;
            go_tab(Library, 1);
            lib_view_ = 3;
            engine().look_console();
            char t[300];
            std::snprintf(t, sizeof t, "%s didn't open (0x%08X), and ShadowMountPlus didn't add it to your home screen in time. "
                                       "Press Refresh home screen, then try again.", name.c_str(), result);
            lib_note_ = t;
            lib_note_bad_ = true;
            failed = false;
        }
        if (failed)
        {
            open(id);
            /* (2.0) Most often it isn't on the home screen yet: ShadowMountPlus is asked to add it
             * now (and again in a moment), rather than the player going to Settings for it. */
            std::string why;
            const int home = refresh_smp(why); /* (2.2.1) asks a running one; never starts one */
            if (home == 2) smp_scan_later();
            char t[300];
            if (home > 0)
                std::snprintf(t, sizeof t,
                              "%s didn't open (0x%08X), and ShadowMountPlus %s it to your home screen in time. "
                              "Press Open to try again, or close the store and look for its tile.",
                              find(id)->name.c_str(), result, home == 2 ? "didn't add" : "wasn't running to add");
            else
                std::snprintf(t, sizeof t,
                              "%s didn't open (0x%08X). %s", find(id)->name.c_str(), result,
                              why.find("no file is chosen") != std::string::npos
                                  ? "ShadowMountPlus isn't running. Choose its file in Settings > General, and the store starts it when you press Open."
                                  : why.c_str());
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
    phone_job_seen(job); /* (2.3.0) a phone's Delete or Move: how it went, back to the phone */
    if (take_23_result(job))
    {
        /* (2.3.0) a backup, a restore, a mod */
    }
    else if (job.kind == "storeupdate" && (job.phase == Phase::Done || job.phase == Phase::Failed))
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
    else if (take_manage_result(job))
    {
        /* (2.2) the App manager's: said in the Library */
    }
    else if (job.kind == "smpget" && (job.phase == Phase::Done || job.phase == Phase::Failed))
    {
        /* (2.2.2) Get ShadowMountPlus: saved and chosen, then loaded the one way that's safe (the
         * launcher, with the store closed). */
        engine().acknowledge();
        if (job.phase == Phase::Done)
        {
            play(int(Sfx::Complete));
            start_reload_smp(false);
            if (!settings_note_bad_)
                settings_note_ = job.note + " Closing the store for a moment while it starts\xE2\x80\xA6";
            else
                settings_note_ = job.note + " " + settings_note_;
        }
        else
        {
            settings_note_ = job.error;
            settings_note_bad_ = true;
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
        if (job.kind == "install") /* (2.2.2) Downloads lists it */
            finished(job.app_id, 0, job.installed_version.empty() ? std::string("Installed") : "Installed " + job.installed_version);
        const std::string done = job.kind == "setup" ? "Game files are ready. " : "Installed. ";
        if (!job.note.empty())
            notice_ = job.note;
        else if (job.kind == "delete")
            notice_ = job.home == 2   ? "Deleted. Its folder and everything in it are gone, and ShadowMountPlus took it off your home screen."
                      : job.home == 3 ? "Deleted. Its folder and everything in it are gone. Its tile comes off your home screen once the store closes."
                                      : "Deleted. Its folder and everything in it are gone. If its tile is still on your home screen, delete it there.";
        /* (2.1.1) ShadowMountPlus 1.7 adds nothing while an app is open, and the store is one:
         * Open closes the store, and the launcher waits for the tile, then opens it. */
        else if (job.home == 2 || job.home == 1)
            notice_ = done + "Press Open: the store closes, ShadowMountPlus puts it on your home screen, and it opens.";
        else
            notice_ = done + "Start ShadowMountPlus to see it on your home screen, or choose its file in Settings > General and the store starts it.";
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
    else if ((job.phase == Phase::Failed && (job.clash || job.source_moved)) && find(job.app_id) &&
             !(page_open_ && page_id_ == job.app_id && !modal_open()))
    {
        /* (2.2.2) A question about an app whose page isn't open: only that one stops. It waits in
         * Downloads, where Cross asks it (the download is kept for the answer); the rest go on. */
        engine().acknowledge();
        if (!queue_now_.empty() && job.app_id == queue_now_) queue_failed_.push_back(job.app_id);
        Finished f{job.app_id, 2, job.clash ? "Needs your answer: another app has its title ID" : "Needs your answer: where it downloads from changed", job};
        finished_.erase(std::remove_if(finished_.begin(), finished_.end(), [&](const Finished &x) { return x.id == job.app_id; }), finished_.end());
        finished_.insert(finished_.begin(), f);
        if (const App *qa = find(job.app_id)) toast(qa->name + " needs your answer. Options shows it.");
        play(int(Sfx::Error));
    }
    else if (job.phase == Phase::Failed && job.clash)
    {
        /* (2.0) Another app has the game's title ID: nothing was written, and the player
         * picks what happens, over the game's page. */
        engine().acknowledge();
        if (!queue_now_.empty() && job.app_id == queue_now_) queue_failed_.push_back(job.app_id); /* (2.0) Update All: it waits for an answer */
        if (find(job.app_id))
        {
            if (!page_open_ || page_id_ != job.app_id) open(job.app_id);
            picker_ = confirm_ = logs_ = kb_ = keypad_ = vers_ = moved_ = false;
            clash_ = true;
            clash_t_ = 0;
            clash_job_ = job;
            clash_sel_ = 0;
            notice_.clear();
            play(int(Sfx::Open));
        }
        else
        {
            notice_ = job.error;
            notice_bad_ = true;
            play(int(Sfx::Error));
        }
    }
    else if (job.phase == Phase::Failed && job.source_moved)
    {
        /* (2.1) Where it downloads from changed: nothing was downloaded, and the player says
         * whether to update from the new place, over its page. */
        engine().acknowledge();
        if (!queue_now_.empty() && job.app_id == queue_now_) queue_failed_.push_back(job.app_id); /* Update All: it waits for an answer */
        if (find(job.app_id))
        {
            if (!page_open_ || page_id_ != job.app_id) open(job.app_id);
            picker_ = confirm_ = logs_ = kb_ = keypad_ = vers_ = clash_ = false;
            moved_ = true;
            moved_t_ = 0;
            moved_job_ = job;
            moved_sel_ = 1; /* Cancel first: the new place is the player's to trust */
            notice_.clear();
            play(int(Sfx::Open));
        }
        else
        {
            notice_ = job.error;
            notice_bad_ = true;
            play(int(Sfx::Error));
        }
    }
    else if (job.phase == Phase::Failed && job.cancelled)
    {
        /* (2.2.2) Cancelled from Downloads: nothing was installed, nothing to fix. */
        if (const App *ca = find(job.app_id)) toast("Cancelled " + ca->name);
        finished(job.app_id, 1, "Cancelled");
        if (page_open_ && page_id_ == job.app_id) { notice_ = job.error; notice_bad_ = false; }
        play(int(Sfx::Back));
        engine().acknowledge();
    }
    else if (job.phase == Phase::Failed)
    {
        play(int(Sfx::Error));
        notice_ = engine().storage_lost() ? kStorageLost : job.error; /* (2.2.1) not each file it couldn't write */
        notice_bad_ = true;
        if (!queue_now_.empty() && job.app_id == queue_now_) queue_failed_.push_back(job.app_id); /* (2.0) Update All */
        if (job.kind == "install") finished(job.app_id, 1, notice_); /* (2.2.2) */
        engine().acknowledge();
    }
    tick_queue(); /* (2.0) Update All: the next one, once nothing else is going */

    /* (1.1.0) A code's answer from the site: in the keypad, or under Redeem a code when the
     * keypad was closed while it was checking. */
    {
        std::string msg, app;
        const int st = engine().redeem_state(msg, app);
        if (st == 2 || st == 3)
        {
            engine().redeem_ack();
            redeem_note_ = msg;
            redeem_note_bad_ = st == 3;
            if (keypad_ && key_view_ == 1)
            {
                key_msg_ = msg;
                key_bad_ = st == 3;
                key_view_ = st == 2 ? 2 : 0;
                key_app_ = app;
                key_done_sel_ = st == 2 && find(app) ? 0 : 1;
                if (st == 2) key_digits_.clear();
            }
            play(int(st == 2 ? Sfx::Complete : Sfx::Error));
        }
    }

    /* (1.1.0) How sending logs went. */
    {
        std::string msg;
        const int st = engine().logs_state(msg);
        if (st == 2 || st == 3)
        {
            engine().logs_ack();
            if (logs_ && logs_view_ == 1)
            {
                logs_view_ = st;
                logs_result_ = msg;
                logs_sel_ = st == 2 ? 2 : 3; /* (2.3.0) Done, or Send again */
            }
            else
            {
                notice_ = msg;
                notice_bad_ = st == 3;
            }
            play(int(st == 2 ? Sfx::Complete : Sfx::Error));
        }
    }

    take_link_actions(); /* (2.0) what a linked phone sent */
    tick_auto_update();  /* (2.2.2) */
    tick_clear();        /* (2.3.0) Clear download cache, on its thread */
    if ((prefs_sync_t_ -= dt) <= 0) { prefs_sync_t_ = 1; sync_phone_prefs(); } /* (2.3.0) for the phone's view */
    check_news();        /* (2.2.2) What's new, once */
    {
        /* (2.0) A phone linked while Link a phone is open: said straight away. */
        const LinkInfo li = engine().link_info();
        if (li.known)
        {
            if (link_devices_ != std::size_t(-1) && li.devices.size() > link_devices_ && tab_ == Settings && section_ == SecLink)
            {
                toast("Linked " + li.devices.back().label);
                play(int(Sfx::Complete));
            }
            link_devices_ = li.devices.size();
        }
    }
    /* (2.0) The Link screen asks the site every few seconds only while it's on screen. */
    engine().link_screen(booted_ && tab_ == Settings && section_ == SecLink && !page_open_);

    /* (2.2.1) The console's storage gone mid-session (ShadowMountPlus restarted while the store was
     * open): said once, over everything. */
    if (booted_ && !lost_told_ && engine().storage_lost())
    {
        lost_told_ = true;
        lost_ = true;
        lost_t_ = 0;
        play(int(Sfx::Error));
    }
    if (lost_)
        return update_lost(dt);

    if (picker_)
        return update_picker(dt);

    if (sheet_)
        return update_sheet(dt);

    if (mng_)
        return update_manage(dt); /* (2.2) the App manager */

    if (panel_)
        return update_panel(dt); /* (2.2.2) Downloads, where to install, storage, What's new, tabs, delete */

    if (keypad_)
        return update_keypad(dt);

    if (kb_)
        return update_keyboard(dt);

    if (logs_)
        return update_logs(dt);

    /* (2.0) Refresh's pill: shown while it reads, then how it went, for a few seconds. */
    if (refresh_shown_ && !refresh_preview_)
    {
        if (engine().update_check() == 1)
            refresh_t_ = 0;
        else if ((refresh_t_ += dt) > 3.f)
            refresh_shown_ = false;
    }

    if (clash_)
        return update_clash(dt);

    if (moved_)
        return update_moved(dt); /* (2.1) */

    if (vers_)
        return update_versions(dt); /* (2.1) */

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
        if (has_saves && pressed(pad::BtnTriangle))
        {
            backup_first_ = !backup_first_; /* (2.3.0) Back up saves first */
            play(int(Sfx::Select));
        }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && confirm_sel_ == 0))
        {
            confirm_ = false;
            play(int(Sfx::Back));
        }
        else if (pressed(pad::BtnCross))
        {
            confirm_ = false;
            const bool keep_saves = last == 2 && confirm_sel_ == 1;
            const bool first = has_saves && !keep_saves && backup_first_ && engine().saves_here(page_id_);
            if (engine().remove(page_id_, keep_saves, first))
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

    if (pressed(pad::BtnOptions) && downloads_shown() && !notes_open_)
    {
        open_panel(PanelQueue); /* (2.2.2) Downloads, from anywhere */
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
            /* (2.2.2) Opened from the rows under another app's page: back to that one. */
            if (!page_back_.empty() && find(page_back_.back()))
            {
                const std::string back = page_back_.back();
                page_back_.pop_back();
                open(back);
                page_rel_ = -1;
                page_scroll_to_ = 0;
                play(int(Sfx::Back));
                return true;
            }
            page_back_.clear();
            close();
            return true;
        }
        if (page_rel_ >= 0)
        {
            /* (2.2.2) The rows under the page. */
            const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
            const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
            update_related(dt, *a, l, r, u, d);
            return true;
        }
        if (pressed(pad::BtnSquare) && !notes_focus_)
        {
            toggle_pin(a->id); /* (2.2.2) a favorite, from its page too */
            return true;
        }
        /* Left: the What's new box; Right: back to the panel. */
        if (notes_focus_)
        {
            if (nav(pad::BtnRight, nav_right_, dt)) { notes_focus_ = false; play(int(Sfx::Move)); }
            else if (pressed(pad::BtnCross)) { notes_open_ = true; notes_top_ = 0; play(int(Sfx::Open)); }
            return true;
        }
        {
            /* (1.1.0) Delete and Send logs sit side by side, (2.1) and Versions after them: Left
             * and Right go along the row. */
            const auto row = page_controls(*a);
            const int pf = std::clamp(page_focus_, 0, int(row.size()) - 1);
            auto pill = [&](int i) {
                const Control::Kind k = row[std::size_t(i)].kind;
                return k == Control::Delete || k == Control::Logs || k == Control::Report || k == Control::Versions || k == Control::Favorite ||
                       k == Control::Like || k == Control::Feedback;
            };
            if (pill(pf) && pf > 0 && pill(pf - 1) && nav(pad::BtnLeft, nav_left_, dt))
            {
                page_focus_ = pf - 1;
                play(int(Sfx::Move));
                return true;
            }
            if (pill(pf) && pf + 1 < int(row.size()) && pill(pf + 1) && nav(pad::BtnRight, nav_right_, dt))
            {
                page_focus_ = pf + 1;
                play(int(Sfx::Move));
                return true;
            }
        }
        if (has_notes_box(*a) && nav(pad::BtnLeft, nav_left_, dt))
        {
            notes_focus_ = true;
            play(int(Sfx::Move));
            return true;
        }
        const auto ctl = page_controls(*a);
        const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
        {
            /* (2.2.2) Up and Down go a row at a time: the pills side by side are one row. */
            const int n = int(ctl.size());
            page_focus_ = std::clamp(page_focus_, 0, n - 1);
            auto row_of = [&](int i) {
                const Control::Kind k = ctl[std::size_t(i)].kind;
                return k == Control::Delete || k == Control::Logs || k == Control::Report || k == Control::Versions || k == Control::Favorite ||
                               k == Control::Like || k == Control::Feedback ? 1000 : i;
            };
            int f = page_focus_;
            if (d)
            {
                int j = f + 1;
                while (j < n && row_of(j) == row_of(f)) ++j;
                if (j < n) f = j;
                else if (!related_rows(*a).empty())
                {
                    page_rel_ = 0; /* on to the rows under the page */
                    page_rel_col_ = 0;
                    play(int(Sfx::Move));
                    return true;
                }
            }
            if (u && f > 0)
            {
                int j = f - 1;
                while (j > 0 && row_of(j) == row_of(f)) --j;
                if (row_of(j) != row_of(f))
                    while (j > 0 && row_of(j - 1) == row_of(j)) --j;
                f = j;
            }
            if (f != page_focus_) { page_focus_ = f; play(int(Sfx::Move)); }
        }
        if (pressed(pad::BtnCross))
        {
            const Control &c = ctl[std::size_t(page_focus_)];
            if (c.kind == Control::Primary)
                press_primary();
            else if (c.kind == Control::Slot)
            {
                const Slot &sl = a->slots[std::size_t(c.slot)];
                const std::string key = a->id + "/" + sl.key;
                if (!slot_kind_known(sl.kind)) play(int(Sfx::Error));
                else if (slot_fetchable(sl) && !chosen_.count(key) && !slot_ready(*a, sl))
                {
                    /* (1.0.8) A Title Update the store downloads itself: chosen as any file
                     * is, and fetched when the player sets up. Change picks a file instead. */
                    chosen_[key] = kDownloadChoice;
                    notice_.clear();
                    const auto ctl2 = page_controls(*a);
                    for (int i = 0; i < int(ctl2.size()); ++i)
                        if (ctl2[std::size_t(i)].kind == Control::Setup) page_focus_ = i;
                    play(int(Sfx::Select));
                }
                else open_picker(*a, c.slot);
            }
            else if (c.kind == Control::Logs)
                open_logs(a->id);
            else if (c.kind == Control::Report)
                report_source(a->source_value, true);
            else if (c.kind == Control::Versions)
                open_versions(*a); /* (2.1) */
            else if (c.kind == Control::Favorite)
                toggle_pin(a->id); /* (2.2.2) */
            else if (c.kind == Control::AutoUpdate)
                toggle_auto(a->id); /* (2.2.2) */
            else if (c.kind == Control::Saves)
                open_saves(a->id); /* (2.3.0) */
            else if (c.kind == Control::Mods)
                open_mods(a->id);
            else if (c.kind == Control::Feedback)
                open_feedback(a->id);
            else if (c.kind == Control::Like)
            {
                const bool on = !engine().liked(a->id);
                engine().like(a->id, on);
                toast(on ? "You like " + a->name : "Like taken back");
                play(int(Sfx::Select));
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

    /* Tabs (2.0: in the order the bar shows them, which grows with the catalog). */
    {
        const auto order = tab_order();
        const int n = int(order.size());
        int at = int(std::find(order.begin(), order.end(), tab_) - order.begin());
        if (at >= n)
        {
            if (side_tab_ && is_category(tab_))
            {
                /* (2.2.2) A part of the store without a tab, opened from Discover's buttons: Circle
                 * goes back, and L1 and R1 go on from Discover's place. */
                at = 0;
                if (pressed(pad::BtnCircle)) { go_tab(Discover, -1); return true; }
            }
            else
            {
                /* Its tab went (a category emptied): back to Discover. */
                go_tab(Discover, -1);
                return true;
            }
        }
        if (pressed(pad::BtnL1)) { go_tab(order[std::size_t((at + n - 1) % n)], -1); return true; }
        if (pressed(pad::BtnR1)) { go_tab(order[std::size_t((at + 1) % n)], 1); return true; }
    }

    /* (2.0) Triangle: Refresh, from every tab but Settings (it has its own row). */
    if (tab_ != Settings && pressed(pad::BtnTriangle))
    {
        if (tab_ == Library && lib_view_ == 3)
            engine().look_console(); /* (2.2) the homebrew folders looked in again too */
        if (engine().update_check() == 1)
            play(int(Sfx::Error));
        else
        {
            engine().refresh();
            refresh_shown_ = true;
            refresh_t_ = 0;
            play(int(Sfx::Select));
        }
        return true;
    }

    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    const int moves = (r ? 1 : 0) - (l ? 1 : 0);

    if (tab_ == Discover)
    {
        const auto feat = header_apps();
        const auto lines = discover_lines();
        const int nl = int(lines.size());
        const int hs = nl > 0 && lines[0].kind == 0 ? lines[0].style : -1; /* (2.1) the header's style, -1 none */
        if (!catalog_.apps.empty() && (hs >= 0) != had_header_)
        {
            /* (2.1) The header came or went (the catalog changed its style): the same line stays focused. */
            row_ = std::max(0, row_ + (hs >= 0 ? 1 : -1));
            had_header_ = hs >= 0;
        }
        dcols_.resize(std::size_t(nl), 0);
        row_ = std::clamp(row_, 0, std::max(0, nl - 1));
        if (nl > 0)
        {
            const DLine &L = lines[std::size_t(row_)];
            const int n = L.count();
            col_ = std::clamp(col_, 0, std::max(0, n - 1));
            bool up = u, down = d;
            if (L.kind == 0 && (L.style == 2 || L.style == 3))
            {
                /* (2.1) Split and mosaic: Left and Right go between the big card and the ones beside
                 * it, Up and Down along those (off the last one, on to the line below). */
                const int side = std::min(L.style == 2 ? 3 : 2, n - 1);
                if (side > 0 && moves > 0 && col_ == 0) { col_ = 1; play(int(Sfx::Move)); }
                else if (moves < 0 && col_ > 0) { col_ = 0; play(int(Sfx::Move)); }
                if (col_ > 0 && down && col_ < side) { ++col_; down = false; play(int(Sfx::Move)); }
                else if (col_ > 1 && up) { --col_; up = false; play(int(Sfx::Move)); }
            }
            else if (moves && L.kind == 0 && L.style == 0 && feat.size() > 1)
            {
                slide_prev_ = slide_; /* (2.2.2) stays under the next one as it fades in */
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
            const int to = std::clamp(row_ + (down ? 1 : 0) - (up ? 1 : 0), 0, nl - 1);
            if (to != row_)
            {
                /* Onto the item nearest the one focused, as the lines are drawn. */
                dcols_[std::size_t(row_)] = col_;
                auto width_of = [&](const DLine &x) {
                    return x.kind == 3 ? kWideW : x.kind == 5 ? (1728 - kTileGap * float(x.count() - 1)) / float(std::max(1, x.count())) : kTile;
                };
                const float fw = width_of(L);
                const float fx = item_x(L, col_, line_first(L, col_)) + fw / 2;
                const DLine &T = lines[std::size_t(to)];
                const int tn = T.count();
                const int remembered = std::clamp(dcols_[std::size_t(to)], 0, std::max(0, tn - 1));
                const int first = line_first(T, remembered);
                const int shown = T.kind == 1 ? kRowShown : T.kind == 3 ? kWideShown : tn;
                const float tw = width_of(T);
                int best = (T.kind == 1 || T.kind == 2 || T.kind == 3 || T.kind == 5) ? remembered : 0;
                float best_d = 1e9f;
                /* From the banner or a spotlight (the whole width): where that line was left. */
                if (L.kind != 0 && L.kind != 4 && (T.kind == 1 || T.kind == 2 || T.kind == 3 || T.kind == 5))
                    for (int i = first; i < tn && i < first + shown; ++i)
                    {
                        const float dx = std::fabs(item_x(T, i, first) + tw / 2 - fx);
                        if (dx < best_d) { best_d = dx; best = i; }
                    }
                /* (2.1) Up into a split or mosaic header from under its right side: the card there. */
                if (T.kind == 0 && (T.style == 2 || T.style == 3) && to < row_ && L.kind != 4)
                {
                    const int side = std::min(T.style == 2 ? 3 : 2, tn - 1);
                    best = side > 0 && fx > 96 + kMainW ? side : 0;
                }
                row_ = to;
                col_ = best;
                play(int(Sfx::Move));
            }
        }
        /* The header turns on its own: the carousel, and split's banner (not while a card beside
         * it is focused). (2.1) As often as the catalog says. */
        const bool turns = hs == 0 || hs == 2, waits = hs == 2 && row_ == 0 && col_ > 0;
        if (!waits)
            slide_t_ += dt;
        slide_fade_ = std::min(1.f, slide_fade_ + dt * 1.2f);
        if (turns && feat.size() > 1 && slide_t_ > float(catalog_.header_interval))
        {
            slide_prev_ = slide_;
            slide_ = (slide_ + 1) % int(feat.size());
            slide_t_ = 0;
            slide_fade_ = 0;
        }
        if (nl > 0)
        {
            const DLine &F = lines[std::size_t(row_)];
            if (F.kind == 0 && !feat.empty())
            {
                if (const App *h = header_focus(F, col_))
                    focus_id_ = h->id;
            }
            else if (!F.items.empty())
                focus_id_ = F.items[std::size_t(std::clamp(col_, 0, int(F.items.size()) - 1))]->id;
        }
        if (nl > 0 && lines[std::size_t(row_)].kind == 5)
        {
            /* (2.0) A category button: its tab. */
            const DLine &C5 = lines[std::size_t(row_)];
            if (pressed(pad::BtnCross) && !C5.cats.empty())
            {
                const int kind = C5.cats[std::size_t(std::clamp(col_, 0, C5.count() - 1))];
                const auto order = tab_order();
                const bool own_tab = std::find(order.begin(), order.end(), kind) != order.end();
                if (own_tab)
                    go_tab(kind, 1);
                else
                {
                    /* (2.2.3) No tab of its own: Library, showing that part. */
                    go_tab(AllApps, 1);
                    const auto chips = category_chips(AllApps);
                    for (int i = 0; i < int(chips.size()); ++i)
                        if (chips[std::size_t(i)] == tab_name(kind)) cat_chip_ = i;
                }
            }
        }
        else if (pressed(pad::BtnCross) && nl > 0 && !lines[std::size_t(row_)].coll.empty())
            open_collection(lines[std::size_t(row_)].coll); /* (2.3.0) */
        else if (pressed(pad::BtnCross) && !focus_id_.empty() && nl > 0)
            open(focus_id_);
        /* (2.0) Square pins the focused app (not the banner, not a Coming soon entry). */
        else if (pressed(pad::BtnSquare) && nl > 0 && lines[std::size_t(row_)].kind != 0 && !focus_id_.empty())
        {
            const App *fa = find(focus_id_);
            if (fa && !fa->soon) toggle_pin(focus_id_);
        }
    }
    else if (tab_ == Library)
        update_library(dt, moves, u, d);
    else if (is_category(tab_))
        update_category(dt, moves, u, d);
    else if (tab_ == Settings)
    {
        const auto order = settings_order();
        if (std::find(order.begin(), order.end(), section_) == order.end()) section_ = SecGeneral;
        if (!in_section_)
        {
            const int at = int(std::find(order.begin(), order.end(), section_) - order.begin());
            const int s = std::clamp(at + (u ? -1 : 0) + (d ? 1 : 0), 0, int(order.size()) - 1);
            if (s != at) { section_ = order[std::size_t(s)]; play(int(Sfx::Move)); }
            if ((pressed(pad::BtnCross) || r) && section_ == SecGeneral) { in_section_ = true; gen_row_ = 0; settings_note_.clear(); play(int(Sfx::Select)); }
            else if ((pressed(pad::BtnCross) || r) && section_ == SecSmp) { in_section_ = true; smp_row_ = 0; settings_note_.clear(); play(int(Sfx::Select)); }
            else if ((pressed(pad::BtnCross) || r) && section_ == SecStorage) { in_section_ = true; sto_row_ = 0; settings_note_.clear(); cache_look(true); play(int(Sfx::Select)); }
            else if (pressed(pad::BtnCross) && section_ == SecAbout) open_panel(PanelNews); /* (2.2.2) What's new */
            else if ((pressed(pad::BtnCross) || r) && section_ == SecCustomize) { in_section_ = true; cus_row_ = 0; play(int(Sfx::Select)); }
            else if ((pressed(pad::BtnCross) || r) && section_ == SecLink) { in_section_ = true; link_row_ = 0; link_armed_.clear(); play(int(Sfx::Select)); }
            else if ((pressed(pad::BtnCross) || r) && section_ == SecSources) { in_section_ = true; src_row_ = 0; play(int(Sfx::Select)); }
            else if (pressed(pad::BtnCross) && section_ == SecRedeem) open_keypad();
        }
        else if (section_ == SecLink || section_ == SecSources)
        {
            if (pressed(pad::BtnCircle) || l)
            {
                in_section_ = false;
                link_armed_.clear();
                play(int(Sfx::Back));
                return true;
            }
            if (section_ == SecLink) update_link(dt, u, d);
            else update_sources(dt, u, d);
        }
        else if (section_ == SecSmp || section_ == SecStorage)
        {
            /* (2.2.2) */
            if (pressed(pad::BtnCircle) || l)
            {
                in_section_ = false;
                play(int(Sfx::Back));
                return true;
            }
            if (section_ == SecSmp) update_smp_section(dt, u, d);
            else update_storage_section(dt, u, d);
        }
        else if (section_ == SecCustomize)
        {
            /* (2.0) Left goes back out from a row's first value only through Circle: Left and
             * Right change the value. */
            update_customize(dt, l, r, u, d);
            if (pressed(pad::BtnCircle)) { in_section_ = false; play(int(Sfx::Back)); return true; }
        }
        else
        {
            /* (2.2.2) Menu sounds, Update everything automatically, Refresh, Testers-only apps (a
             * tester's). ShadowMountPlus's rows have a section of their own now. */
            const int g = std::clamp(gen_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, engine().tester() ? 3 : 2);
            if (g != gen_row_) { gen_row_ = g; play(int(Sfx::Move)); }
            if (pressed(pad::BtnCross))
            {
                if (gen_row_ == 0)
                {
                    sounds_on_ = !sounds_on_;
                    save_prefs(); /* (2.0) kept */
                    if (sounds_on_) play(int(Sfx::Select));
                }
                else if (gen_row_ == 1)
                {
                    prefs_.auto_all = !prefs_.auto_all;
                    save_prefs();
                    if (prefs_.auto_all) auto_checks_ = 0; /* looked at now */
                    play(int(Sfx::Select));
                }
                else if (gen_row_ == 3)
                {
                    engine().set_test_view(!engine().test_view()); /* (2.0) the other catalog, read now */
                    toast(engine().test_view() ? "Showing testers-only apps" : "Showing what everyone sees");
                    play(int(Sfx::Select));
                }
                else
                {
                    if (engine().update_check() == 1)
                        play(int(Sfx::Error));
                    else
                    {
                        engine().refresh(); /* (2.0) */
                        settings_note_.clear();
                        play(int(Sfx::Select));
                    }
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
    if (w > 2 * r + 8) /* (2.2.3) the light catching its top edge, inside */
        gfx.panel(x + r, y + 1.25f, w - 2 * r, 1.25f, Color{1, 1, 1, 0.10f * std::min(1.f, fill.a * 4 + 0.4f)}, 1.f, 0.6f);
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
    /* (2.2.3) A word wider than the box (an address, a file name): broken after / . - _ ? & = where
     * it can be, else anywhere (never inside a character), so nothing runs past the edge. */
    auto split_long = [&](const std::string &w) {
        std::vector<std::string> parts;
        std::size_t from = 0;
        while (from < w.size())
        {
            std::size_t best = std::string::npos, last_fit = from;
            for (std::size_t k = from + 1; k <= w.size(); ++k)
            {
                if (k < w.size() && (static_cast<unsigned char>(w[k]) & 0xC0) == 0x80) continue; /* inside a character */
                if (gfx.measure(f, size, w.substr(from, k - from)) > width) break;
                last_fit = k;
                const char c = w[k - 1];
                if (c == '/' || c == '.' || c == '-' || c == '_' || c == '?' || c == '&' || c == '=') best = k;
            }
            std::size_t cut = (best != std::string::npos && best > from && last_fit < w.size()) ? best : last_fit;
            if (cut <= from) /* not even one character fits: one anyway */
            {
                cut = from + 1;
                while (cut < w.size() && (static_cast<unsigned char>(w[cut]) & 0xC0) == 0x80) ++cut;
            }
            parts.push_back(w.substr(from, cut - from));
            from = cut;
        }
        return parts;
    };
    while (i < text.size())
    {
        std::size_t end = text.find_first_of(" \n", i);
        if (end == std::string::npos)
            end = text.size();
        const std::string word = text.substr(i, end - i);
        const std::string trial = line.empty() ? word : line + " " + word;
        if (gfx.measure(f, size, word) > width)
        {
            if (!line.empty()) lines.push_back(line);
            auto parts = split_long(word);
            for (std::size_t k = 0; k + 1 < parts.size(); ++k) lines.push_back(parts[k]);
            line = parts.empty() ? std::string() : parts.back();
        }
        else if (!line.empty() && gfx.measure(f, size, trial) > width)
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
    /* (2.2.3) Shortened a whole letter at a time (never half of one), no space before the ellipsis. */
    std::string t = s;
    auto drop_one = [&] { /* the last letter: its continuation bytes, then its first */
        while (!t.empty() && (static_cast<unsigned char>(t.back()) & 0xC0) == 0x80)
            t.pop_back();
        if (!t.empty())
            t.pop_back();
    };
    while (!t.empty() && gfx.measure(f, size, t + "\xE2\x80\xA6") > width)
        drop_one();
    while (!t.empty() && (t.back() == ' ' || t.back() == '-' || t.back() == ':' || t.back() == ','))
        t.pop_back();
    return t + "\xE2\x80\xA6";
}

/* ---- Screens ------------------------------------------------------------------------ */

void Store::draw_backdrop(Gfx &gfx, float alpha)
{
    const float W = gfx.design_w(), H = gfx.design_h();
    /* The brand gradient, then the focused game's art frosted over it (blur 46px,
     * brightness 0.5), then midnight at 35%: Main.dc.html's background. (2.2.3) alpha < 1: over
     * what's drawn already (edge_fade). */
    if (gradient_)
        gfx.image(gradient_, 0, 0, W, H, {1, 1, 1, alpha});
    else
        gfx.panel(0, 0, W, H, C(kMidnight, alpha), 1.f, 0);
    auto blur_of = [&](const std::string &id) -> Texture * {
        const App *a = find(id);
        return a && prefs_.backdrop == 0 ? art(*a).blur : nullptr; /* (2.0) Calm: the gradient only */
    };
    const float t = ease_out(backdrop_t_);
    Texture *prev = blur_of(backdrop_prev_), *cur = blur_of(backdrop_id_);
    if (prev && t < 1)
        gfx.image(prev, -60, -60, W + 120, H + 120, {0.5f, 0.5f, 0.5f, (1 - t) * alpha});
    if (cur)
        gfx.image(cur, -60, -60, W + 120, H + 120, {0.5f, 0.5f, 0.5f, (prev ? t : ease_out(backdrop_t_ * 1.5f)) * alpha});
    gfx.panel(0, 0, W, H, C(kMidnight, 0.35f * alpha), 1.f, 0);
    if (tint_a_ > 0.003f && alpha >= 1.f)
        draw_tint(gfx);
}

void Store::draw_tint(Gfx &gfx, float alpha)
{
    const float a = tint_a_ * alpha;
    if (a <= 0.003f) return;
    const Color c{tint_rgb_[0], tint_rgb_[1], tint_rgb_[2], 0.20f * a};
    gfx.blob(1920 * 0.80f, 120, 2000, 1150, c);
    gfx.blob(1920 * 0.10f, 1080, 1500, 760, Color{c.r, c.g, c.b, 0.09f * a});
}

/* (2.2.3) A switch: a 52x30 track, the knob at the right and cyan when on. */
void Store::draw_switch(Gfx &gfx, float x, float cy, bool on, bool focus)
{
    const float w = 52, h = 30;
    gfx.panel(x, cy - h / 2, w, h, on ? C(kCyan, 0.9f) : C(kFrost, focus ? 0.22f : 0.16f), 1.f, h / 2, on ? C(kCyan) : C(kFrost, 0.25f), 1.f);
    const float kx = on ? x + w - 3 - 24 : x + 3;
    gfx.panel(kx, cy - 12, 24, 24, on ? C(kMidnight) : C(kFrost, 0.9f), 1.f, 12);
}

/* (2.2.3) The glow round a focused icon: its own colour, or the logo's cyan. */
Color Store::glow_of(const Texture *icon, float a) const
{
    if (icon && icon->has_tint)
        return C(icon->tint, a);
    return C(kCyan, a);
}

/* (2.2.3) While an icon loads: its place, with a soft light sweeping across (still, with Reduce
 * motion). The sweep is the same across the screen, so a row of them moves as one. */
void Store::draw_shimmer(Gfx &gfx, float x, float y, float w, float h, float r)
{
    gfx.panel(x, y, w, h, C(0x16233A, 0.92f), 1.f, r, kRim, 1.25f);
    if (prefs_.reduced_motion || !hfade_) return;
    const float period = 1.6f, span = 1920 + 600;
    const float p = time_ / period - float(int(time_ / period)); /* (no floor: the known imports) */
    const float bx = -600 + span * p; /* the light's left edge, across the whole screen */
    const float bw = 260;
    if (bx + bw < x || bx > x + w) return;
    gfx.push_clip(x + r * 0.3f, y, w - r * 0.6f, h);
    const float uv[4] = {1, 0, 0, 1}; /* hfade_ backwards: clear, then brightest at the light's front */
    gfx.image_part(hfade_, bx, y + 6, bw * 0.6f, h - 12, uv, C(kFrost, 0.07f));
    gfx.image(hfade_, bx + bw * 0.6f, y + 6, bw * 0.4f, h - 12, C(kFrost, 0.07f));
    gfx.pop_clip();
}

void Store::edge_fade(Gfx &gfx, float top, float bottom, bool at_top, bool at_bottom)
{
    float dx = 0, dy = 0, fade = 1;
    gfx.get_layer(dx, dy, fade);
    gfx.set_layer();
    const int N = 12;
    const float H = 54;
    for (int i = 0; i < N; ++i)
    {
        const float t = (float(i) + 0.5f) / float(N); /* 0 at the edge, 1 inside */
        const float a = 1.f - t * t * (3.f - 2.f * t);
        const float sy = H * float(i) / float(N), sh = H / float(N) + 0.5f;
        if (at_top)
        {
            gfx.push_clip(0, top + sy, gfx.design_w(), sh);
            draw_backdrop(gfx, a);
            if (tint_a_ > 0.003f) draw_tint(gfx, a);
            gfx.pop_clip();
        }
        if (at_bottom)
        {
            gfx.push_clip(0, bottom - sy - sh, gfx.design_w(), sh);
            draw_backdrop(gfx, a);
            if (tint_a_ > 0.003f) draw_tint(gfx, a);
            gfx.pop_clip();
        }
    }
    gfx.set_layer(dx, dy, fade);
}

void Store::draw_hint_band(Gfx &gfx)
{
    /* (2.2.3) A darker, frosted band along the bottom, behind the button hints, on every screen. */
    const float y = gfx.design_h() - 112;
    if (vfade_)
        gfx.image(vfade_, 0, y, gfx.design_w(), 112, C(0x070C16, 0.78f));
    gfx.panel(0, gfx.design_h() - 1.5f - 76, gfx.design_w(), 1.5f, C(kFrost, 0.035f), 1.f, 0);
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

    /* The tabs (2.0: as many as the catalog fills), with L1 and R1 keycaps on their sides,
     * centred between the name and a 290px space on the right. The Library's badge counts
     * the updates waiting, the store's own included. */
    int updates = int(update_apps().size());
    if (!engine().store_update().empty())
        ++updates;
    const auto order = tab_order();
    const int n = int(order.size());
    const std::string badge = std::to_string(updates);
    const float badge_w = gfx.measure(Font::Bold, 15, badge) + 18;
    std::vector<float> widths(order.size());
    float pill = 12;
    for (int i = 0; i < n; ++i)
    {
        widths[std::size_t(i)] = gfx.measure(Font::SemiBold, 20, tab_name(order[std::size_t(i)])) + (n > 5 ? 44 : 56);
        if (order[std::size_t(i)] == Library && updates > 0)
            widths[std::size_t(i)] += 10 + badge_w;
        pill += widths[std::size_t(i)] + (i ? 6 : 0);
    }
    const float cap_w = gfx.measure(Font::Bold, 17, "L1") + 24, cap_h = 37;
    const float group = cap_w + 18 + pill + 18 + cap_w;
    const float area_l = 96 + left_w, area_r = 1920 - 96 - 290;
    float gx = area_l + (area_r - area_l - group) / 2;
    tabs_right_ = gx + group; /* (2.2.3) Refresh's note stays right of it */
    const float cy = 76;

    auto keycap = [&](float x, const char *label) {
        glass(gfx, x, cy - cap_h / 2, cap_w, cap_h, 12, kGlass, false);
        gfx.text_mid(Font::Bold, 17, x + cap_w / 2, cy, C(0xE8EEF6), Align::Center, label);
    };
    keycap(gx, "L1");
    gx += cap_w + 18;
    glass(gfx, gx, cy - 30, pill, 60, 30, kGlass, true);
    /* The highlight slides to the tab that's on, stretching a little on the way. */
    {
        float x = gx + 6, tx = x, tw = widths.empty() ? 0 : widths[0];
        const int shown = side_tab_ ? Discover : tab_ == Updates ? Library : tab_; /* (2.2.2) a hidden tab's: Discover's */
        for (int i = 0; i < n; ++i)
        {
            if (order[std::size_t(i)] == shown) { tx = x; tw = widths[std::size_t(i)]; }
            x += widths[std::size_t(i)] + 6;
        }
        if (tab_hl_x_ < 0 || prefs_.reduced_motion) { tab_hl_x_ = tx; tab_hl_w_ = tw; }
        const float px = tab_hl_x_;
        tab_hl_x_ = approach(tab_hl_x_, tx, 1.f / 60.f, 13.f);
        tab_hl_w_ = approach(tab_hl_w_, tw, 1.f / 60.f, 13.f);
        const float stretch = std::min(40.f, std::fabs(tab_hl_x_ - px) * 1.6f);
        gfx.panel(tab_hl_x_ - stretch * 0.5f, cy - 24, tab_hl_w_ + stretch, 48, C(kFrost), 0.92f, 24, {0, 0, 0, 0}, 0, 0, 0.06f);
    }
    float x = gx + 6;
    for (int i = 0; i < n; ++i)
    {
        const int kind = order[std::size_t(i)];
        const float w = widths[std::size_t(i)];
        /* On when the highlight is mostly under it: the words turn dark as it arrives. */
        const float over = clamp01(1.f - std::fabs((tab_hl_x_ + tab_hl_w_ / 2) - (x + w / 2)) / (w * 0.6f));
        const Color fg = {C(kFrost).r + (C(kMidnight).r - C(kFrost).r) * over, C(kFrost).g + (C(kMidnight).g - C(kFrost).g) * over,
                          C(kFrost).b + (C(kMidnight).b - C(kFrost).b) * over, 1};
        const Font f = over > 0.5f ? Font::Bold : Font::SemiBold;
        if (kind == Library && updates > 0)
        {
            const float lw = gfx.measure(f, 20, tab_name(kind));
            const float lx = x + (n > 5 ? 22 : 28);
            gfx.text_mid(f, 20, lx, cy, fg, Align::Left, tab_name(kind));
            const float bx = lx + lw + 10;
            gfx.panel(bx, cy - 11, badge_w, 22, C(kCyan), 1.f, 11);
            gfx.text_mid(Font::Bold, 15, bx + badge_w / 2, cy, C(kMidnight), Align::Center, badge);
        }
        else
            gfx.text_mid(f, 20, x + w / 2, cy, fg, Align::Center, tab_name(kind));
        x += w + 6;
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
    if (focus > 0.01f) /* (2.2.3) a glow in the icon's own colour */
        gfx.panel(tx + 6, ty + 6, w - 12, w - 12, {0, 0, 0, 0}, 1.f, 28 * s, glow_of(t.icon, 0.42f * focus), 0, 30 * focus);
    if (t.icon && !shimmer_test_)
        gfx.image(t.icon, tx, ty, w, w, {}, 28 * s);
    else if (t.icon_wait || shimmer_test_)
        draw_shimmer(gfx, tx, ty, w, w, 28 * s); /* (2.2.3) still loading */
    else
        draw_no_icon(gfx, a, tx, ty, w, 28 * s);
    ring(gfx, tx, ty, w, w, 28 * s, focus);
    bool up = false;
    const std::string st = tile_line(a, &up); /* (2.2.3) */
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
    if (a.beta || a.testing)
    {
        /* (2.0) A testers-only app says so the same way, in violet. */
        const char *tag = a.beta ? "BETA" : "TESTING";
        const float bw = gfx.measure(Font::Bold, 14, tag, 1) + 24;
        gfx.panel(tx + 14, ty + 14, bw, 28, a.beta ? C(kAmber) : C(0xB9AEFF), 1.f, 14);
        gfx.text_mid(Font::Bold, 14, tx + 14 + bw / 2, ty + 28, C(kMidnight), Align::Center, tag, 1);
    }
    if (pinned(a.id))
        draw_pin(gfx, tx + w - 26, ty + w - 26); /* (2.0) */
    gfx.text_mid(Font::SemiBold, 21, x, y + size + 14 + 13, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, size + 20, a.name));
    gfx.text_mid(Font::Regular, 17, x, y + size + 14 + 26 + 4 + 11, C(kMuted), Align::Left, fit(gfx, Font::Regular, 17, size + 20, st));
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
            draw_no_icon(gfx, a, tx + ww / 2 - 84, ty + hh / 2 - 84, 168, 36);
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
    const std::string st = tile_line(a, &up); /* (2.2.3) */
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
                        bool drift, float focus, bool big, const App *prev)
{
    /* The banner (1728x400): the art frosted across it, the picture itself on the right
     * (with its slow drift, on the featured banner), and the words on the left. (2.1) Narrower
     * beside the split header's cards, its words' side a little narrower too; big, the spotlight
     * header's: taller, with bigger words. (2.2.2) Turning to the next game, the one before stays
     * whole underneath while the new one fades in over it (no dip to dark between them). */
    const float R = 36;
    gfx.panel(x + 10, y + 26, w - 20, h - 20, {0, 0, 0, 0}, 1.f, R, {0, 0, 0, 0.38f}, 0, 30);
    gfx.panel(x, y, w, h, C(kMidnight), 1.f, R);
    const float gw = big ? 720.f : w >= 1500 ? 600.f : float(std::lround(w * 0.46f));
    /* The pictures cross over the whole turn; the words one after the other, so they never overlap. */
    const bool turning = prev && prev != &a && fade < 0.999f;
    if (turning)
        banner_content(gfx, *prev, x, y, w, h, gw, R, kicker_head, 1.f, std::max(0.f, 1.f - 2.f * fade), drift, big);
    banner_content(gfx, a, x, y, w, h, gw, R, kicker_head, fade, turning ? std::max(0.f, 2.f * fade - 1.f) : fade, drift, big);
    gfx.panel(x, y, w, h, {0, 0, 0, 0}, 1.f, R, kRim, 1.25f);
    ring(gfx, x, y, w, h, R, focus);
}

void Store::banner_content(Gfx &gfx, const App &a, float x, float y, float w, float h, float gw, float R,
                           const std::string &kicker_head, float alpha, float words, bool drift, bool big)
{
    Art &t = art(a);
    const float uvb[4] = {0.03f, 0.08f, 0.97f, 0.92f}; /* the blur's soft middle, edges cut off */
    if (t.blur)
        gfx.image_part(t.blur, x, y, w, h, uvb, {0.62f, 0.62f, 0.62f, alpha}, R);
    /* (2.2.2) Where the words' side meets the picture, on a whole pixel: neither overlaps the other
     * (before, the picture was drawn twice there, and a light line marked the join). */
    const float seam = gfx.snap_x(x + gw);
    gfx.push_clip(x, y, seam - x, h);
    gfx.panel(x, y, seam - x + R, h, {1, 1, 1, 0.07f * alpha}, 1.f, R); /* the wash: square where it meets the picture */
    gfx.pop_clip();
    if (t.hero)
    {
        float uv[4];
        const float z = drift && !prefs_.reduced_motion ? 1.f + 0.07f * (0.5f - 0.5f * std::cos(time_ * 3.14159f / 16.f)) : 1.f;
        const float pw = x + w - seam;
        cover_uv(t.hero, pw, h, a.banner_x / 100.f, a.banner_y / 100.f, std::max(1.f, a.banner_zoom / 100.f) * z, uv);
        /* Drawn R wider to the left, past the clip, so its left corners are square and its right
         * ones round with the banner's: one picture, drawn once. */
        const float du = (uv[2] - uv[0]) * R / pw;
        const float wide[4] = {uv[0] - du, uv[1], uv[2], uv[3]};
        /* The picture fades in over the frosted art (drawn across the whole banner, under both
         * sides) in thin slices, each on whole pixels, the wash fading out over them: the frost
         * under the picture is the same frost as on the words' side, so the join can't show, even
         * halfway through a turn (2.2.2: the frost drawn over the picture showed a line then). */
        const float feather = t.blur ? std::min(110.f, pw * 0.25f) : 0.f;
        const float fe = feather > 0 ? gfx.snap_x(seam + feather) : seam;
        gfx.push_clip(fe, y, x + w - fe, h);
        gfx.image_part(t.hero, seam - R, y, pw + R, h, wide, {1, 1, 1, alpha}, R);
        gfx.pop_clip();
        if (feather > 0)
        {
            const int n = 32;
            float x0 = seam;
            for (int i = 0; i < n; ++i)
            {
                const float x1 = i + 1 == n ? fe : gfx.snap_x(seam + feather * float(i + 1) / float(n));
                if (x1 <= x0 + 0.01f) continue;
                const float m = (float(i) + 0.5f) / float(n);
                const float k = 1.f - m * m * (3.f - 2.f * m); /* smooth, from all frost to none */
                const float u0 = wide[0] + (wide[2] - wide[0]) * (x0 - (seam - R)) / (pw + R);
                const float u1 = wide[0] + (wide[2] - wide[0]) * (x1 - (seam - R)) / (pw + R);
                const float part[4] = {u0, wide[1], u1, wide[3]};
                gfx.image_part(t.hero, x0, y, x1 - x0, h, part, {1, 1, 1, alpha * (1.f - k)}, 0);
                gfx.panel(x0, y, x1 - x0, h, {1, 1, 1, 0.07f * alpha * k}, 1.f, 0);
                x0 = x1;
            }
        }
    }
    if (words <= 0.001f) return;
    const float fade = words;
    const float gx = x, gy = y, gh = h;
    bool up = false;
    const bool inst = !inst_v(a.id).empty();
    state_of(a, &up);
    const std::string kicker = kicker_head + " \xC2\xB7 " + (up ? "UPDATE AVAILABLE" : inst ? "INSTALLED" : "NEW ON RECOMP STORE");
    const float pad = big ? 72 : 64;
    const float tx0 = gx + pad, tw0 = gw - pad - 48;
    const float n_size = big ? 66 : 50, b_size = big ? 23 : 20, k_size = big ? 17 : 15;
    const auto name = wrap(gfx, Font::ExtraBold, n_size, tw0, a.name, 2);
    const auto blurb = wrap(gfx, Font::Regular, b_size, tw0, a.blurb.empty() ? a.description : a.blurb, big ? 4 : 3);
    const float k_h = big ? 20 : 18, n_lh = n_size * 1.05f, b_lh = b_size * 1.45f, gap = big ? 18 : 14;
    const float total = k_h + gap + n_lh * float(name.size()) + gap + b_lh * float(blurb.size());
    /* A logo, when the game has one, takes the name's place (its height, not the name's). */
    const float logo_box = big ? 200 : 140;
    const float logo_h = draw_logo(gfx, a, a.logo_banner, tx0, 0, tw0, logo_box, 0); /* alpha 0: measures only */
    const float name_h = logo_h >= 0 ? logo_h : n_lh * float(name.size());
    const float total2 = total - n_lh * float(name.size()) + name_h;
    float ty = gy + (gh - total2) / 2;
    gfx.text_mid(Font::Bold, k_size, tx0, ty + k_h / 2, C(kCyan, fade), Align::Left, fit(gfx, Font::Bold, k_size, tw0, kicker), 2);
    ty += k_h + gap;
    if (logo_h >= 0)
        draw_logo(gfx, a, a.logo_banner, tx0, ty, tw0, logo_box, fade);
    else
        for (std::size_t i = 0; i < name.size(); ++i)
            gfx.text_mid(Font::ExtraBold, n_size, tx0, ty + n_lh * (float(i) + 0.5f), C(kFrost, fade), Align::Left, name[i], big ? -1.5f : -1);
    ty += name_h + gap;
    draw_lines(gfx, Font::Regular, b_size, tx0, ty, b_lh, C(0xE3E9F2, fade), blurb);
}

/* ---- Discover's header (2.1) ----------------------------------------------------------- */
void Store::draw_header(Gfx &gfx, const DLine &L, int line, float sy, bool on)
{
    const int n = int(L.items.size());
    if (n == 0)
        return;
    const int cur = std::clamp(slide_, 0, n - 1);
    const float fade = ease_out(slide_fade_);
    /* (2.2.2) The slide it's turning from, under the one fading in (-1: none, -2: the one before). */
    const int was = slide_prev_ == -2 ? (cur + n - 1) % n : slide_prev_ >= 0 && slide_prev_ < n && slide_prev_ != cur ? slide_prev_ : -1;
    /* Which of the slides is on: a long cyan dot among short ones, at a banner's bottom right. */
    auto dots = [&](float right) {
        if (n < 2)
            return;
        float dx = right - 28;
        for (int k = n - 1; k >= 0; --k)
        {
            const float dw = k == cur ? 34 : 10;
            dx -= dw;
            gfx.panel(dx, sy + kBannerH - 32, dw, 10, k == cur ? C(kCyan) : C(kFrost, 0.45f), 1.f, 5);
            dx -= 8;
        }
    };
    /* A card's focus, eased as a tile's is. */
    auto focus = [&](int k) {
        float &f = dfocus_[line * 1000 + k];
        f = approach(f, on && col_ == k ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
        return ease_out(f);
    };
    if (L.style == 1)
    {
        /* Spotlight: one game, taller, staying put. */
        draw_banner(gfx, *L.items[0], 96, sy, 1728, kSpotH, "SPOTLIGHT", 1.f, true, on ? 1.f : 0.f, true);
        return;
    }
    if (L.style == 2)
    {
        /* Split: the turning banner, and beside it the games that come next. */
        const int side = std::min(3, n - 1);
        const float bw = side > 0 ? kMainW : 1728;
        draw_banner(gfx, *L.items[std::size_t(cur)], 96, sy, bw, kBannerH, "FEATURED", fade, true, on && col_ == 0 ? 1.f : 0.f, false,
                    was >= 0 ? L.items[std::size_t(was)] : nullptr);
        dots(96 + bw);
        const float ch = (kBannerH - kCardGap * 2) / 3;
        for (int k = 1; k <= side; ++k)
            draw_header_card(gfx, *L.items[std::size_t((cur + k) % n)], 96 + kMainW + kSideGap, sy + float(k - 1) * (ch + kCardGap), kSideW, ch,
                             focus(k), fade, was >= 0 ? L.items[std::size_t((was + k) % n)] : nullptr);
        return;
    }
    if (L.style == 3)
    {
        /* Mosaic: three games at once, one large and two stacked beside it. */
        const int side = std::min(2, n - 1);
        draw_mosaic_card(gfx, *L.items[0], 96, sy, side > 0 ? kMainW : 1728, kBannerH, focus(0), true);
        const float ch = side == 2 ? (kBannerH - kCardGap) / 2 : kBannerH;
        for (int k = 1; k <= side; ++k)
            draw_mosaic_card(gfx, *L.items[std::size_t(k)], 96 + kMainW + kSideGap, sy + float(k - 1) * (ch + kCardGap), kSideW, ch, focus(k), false);
        return;
    }
    /* The carousel: one featured game at a time, turning. */
    draw_banner(gfx, *L.items[std::size_t(cur)], 96, sy, 1728, kBannerH, "FEATURED", fade, true, on ? 1.f : 0.f, false,
                was >= 0 ? L.items[std::size_t(was)] : nullptr);
    dots(96 + 1728);
}

void Store::draw_header_card(Gfx &gfx, const App &a, float x0, float y0, float w0, float h0, float focus, float fade, const App *prev)
{
    /* A glass card as the Library's rows are: its picture on the left, its name and a line on
     * the right. Focused: a little bigger, brighter, the ring. (2.2.2) Turning, the game before
     * stays under the new one's picture as it fades in, and their words cross over. */
    const float s = 1 + 0.02f * focus;
    const float w = w0 * s, h = h0 * s, x = x0 + (w0 - w) / 2, y = y0 + (h0 - h) / 2;
    const float R = 24;
    glass(gfx, x, y, w, h, R, {1, 1, 1, 0.07f + 0.05f * focus}, true);
    const float ph = h - 20, pw = float(std::lround(ph * 16.f / 9.f)), px = x + 10, py = y + 10;
    const bool turning = prev && prev != &a && fade < 0.999f;
    if (turning)
        header_card_content(gfx, *prev, x, y, w, h, px, py, pw, ph, 1.f, std::max(0.f, 1.f - 2.f * fade));
    header_card_content(gfx, a, x, y, w, h, px, py, pw, ph, fade, turning ? std::max(0.f, 2.f * fade - 1.f) : fade);
    gfx.panel(px, py, pw, ph, {0, 0, 0, 0}, 1.f, 16, kRim, 1.25f);
    ring(gfx, x, y, w, h, R, focus);
}

void Store::header_card_content(Gfx &gfx, const App &a, float x, float y, float w, float h, float px, float py, float pw, float ph,
                                float fade, float words)
{
    Art &t = art(a);
    if (t.hero)
    {
        float uv[4];
        cover_uv(t.hero, pw, ph, a.banner_x / 100.f, a.banner_y / 100.f, std::max(1.f, a.banner_zoom / 100.f), uv);
        gfx.image_part(t.hero, px, py, pw, ph, uv, {1, 1, 1, fade}, 16);
    }
    else
    {
        /* No wide picture: the icon in the middle of the frosted art. */
        if (t.blur)
            gfx.image(t.blur, px, py, pw, ph, {0.7f, 0.7f, 0.7f, fade}, 16);
        else
            gfx.panel(px, py, pw, ph, C(0x16233A), 1.f, 16);
        const float is = ph - 24;
        if (t.icon)
            gfx.image(t.icon, px + (pw - is) / 2, py + 12, is, is, {1, 1, 1, fade}, 14);
        else
            draw_no_icon(gfx, a, px + (pw - is) / 2, py + 12, is, 14);
    }
    const float tx = px + pw + 22, tw = x + w - tx - 22;
    const std::string line = a.blurb.empty() ? state_of(a, nullptr) : a.blurb;
    gfx.text_mid(Font::ExtraBold, 22, tx, y + h / 2 - 15, C(kFrost, words), Align::Left, fit(gfx, Font::ExtraBold, 22, tw, a.name));
    gfx.text_mid(Font::Regular, 17, tx, y + h / 2 + 17, C(kMuted, words), Align::Left, fit(gfx, Font::Regular, 17, tw, line));
}

void Store::draw_mosaic_card(Gfx &gfx, const App &a, float x0, float y0, float w0, float h0, float focus, bool big)
{
    /* The game's art across the whole card, darkened from the left under its words; focused,
     * up a little and bigger with the ring, as a wide tile. */
    Art &t = art(a);
    const float s = 1 + (big ? 0.012f : 0.025f) * focus;
    const float w = w0 * s, h = h0 * s, x = x0 + (w0 - w) / 2, y = y0 + (h0 - h) / 2 - 6 * focus;
    const float R = big ? 36 : 28;
    gfx.panel(x + 10, y + 22 + 8 * focus, w - 20, h - 20, {0, 0, 0, 0}, 1.f, R, {0, 0, 0, 0.30f + 0.2f * focus}, 0, 26 + 10 * focus);
    gfx.panel(x, y, w, h, C(kMidnight), 1.f, R);
    if (t.hero)
    {
        float uv[4];
        cover_uv(t.hero, w, h, a.banner_x / 100.f, a.banner_y / 100.f, std::max(1.f, a.banner_zoom / 100.f), uv);
        gfx.image_part(t.hero, x, y, w, h, uv, {}, R);
    }
    else if (t.blur)
        gfx.image(t.blur, x, y, w, h, {0.7f, 0.7f, 0.7f, 1}, R);
    else
        gfx.panel(x, y, w, h, C(0x16233A), 1.f, R);
    /* Shade under the words: up from the bottom, and in from the left. */
    if (vfade_)
        gfx.image(vfade_, x, y + h * 0.25f, w, h * 0.75f, C(kMidnight, 0.85f), R);
    if (hfade_)
        gfx.image(hfade_, x, y, std::min(w, big ? 860.f : 470.f), h, C(kMidnight, 0.8f), R);
    if (!t.hero)
    {
        /* No wide picture: the icon on the right. */
        const float is = std::min(h - 48, big ? 200.f : 110.f);
        if (t.icon)
            gfx.image(t.icon, x + w - 40 - is, y + (h - is) / 2, is, is, {}, is * 0.22f);
        else
            draw_no_icon(gfx, a, x + w - 40 - is, y + (h - is) / 2, is, is * 0.22f);
    }
    gfx.panel(x, y, w, h, {0, 0, 0, 0}, 1.f, R, kRim, 1.25f);
    ring(gfx, x, y, w, h, R, focus);
    bool up = false;
    const std::string st = tile_line(a, &up); /* (2.2.3) */
    if (!big)
    {
        /* Its name and where it stands, at the bottom left. */
        const float tx = x + 30, tw = std::min(w * 0.7f, 400.f);
        gfx.text_mid(Font::ExtraBold, 26, tx, y + h - 30 - 26 - 14, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 26, tw, a.name), -0.5f);
        gfx.text_mid(Font::Regular, 17, tx, y + h - 30 - 10, C(0xD6DEEA), Align::Left, fit(gfx, Font::Regular, 17, tw, st));
        return;
    }
    /* The big one: as the banner says it, from the bottom left. */
    const bool inst = !inst_v(a.id).empty();
    const std::string kicker = std::string("FEATURED \xC2\xB7 ") + (up ? "UPDATE AVAILABLE" : inst ? "INSTALLED" : "NEW ON RECOMP STORE");
    const float tx = x + 56, tw = std::min(w * 0.5f, 600.f);
    const auto name = wrap(gfx, Font::ExtraBold, 48, tw, a.name, 2);
    const auto blurb = wrap(gfx, Font::Regular, 20, tw, a.blurb.empty() ? a.description : a.blurb, 2);
    const float n_lh = 48 * 1.05f, b_lh = 20 * 1.45f;
    const float logo_h = draw_logo(gfx, a, a.logo_banner, tx, 0, tw, 120, 0);
    const float name_h = logo_h >= 0 ? logo_h : n_lh * float(name.size());
    float ty = y + h - 48 - b_lh * float(blurb.size()) - 14 - name_h - 14 - 18;
    gfx.text_mid(Font::Bold, 15, tx, ty + 9, C(kCyan), Align::Left, fit(gfx, Font::Bold, 15, tw, kicker), 2);
    ty += 18 + 14;
    if (logo_h >= 0)
        draw_logo(gfx, a, a.logo_banner, tx, ty, tw, 120, 1.f);
    else
        for (std::size_t i = 0; i < name.size(); ++i)
            gfx.text_mid(Font::ExtraBold, 48, tx, ty + n_lh * (float(i) + 0.5f), C(kFrost), Align::Left, name[i], -1);
    ty += name_h + 14;
    draw_lines(gfx, Font::Regular, 20, tx, ty, b_lh, C(0xE3E9F2), blurb);
}

void Store::draw_cat_button(Gfx &gfx, int kind, float x0, float y0, float w0, float h0, float focus)
{
    /* (2.2.3) A glass card: the category's icon and its name, together in the middle, the name
     * in capitals. Focused: a little up and bigger, brighter, the ring. */
    const int which = kind == Games ? 0 : kind == Emulators ? 1 : kind == Apps ? 2 : 3;
    /* (2.2.2) The logo's cyan for all four, unless the player picked Colorful. */
    const std::uint32_t tint[4] = {kCyan, prefs_.icon_colors ? 0x9B8CFFu : kCyan, prefs_.icon_colors ? kGreen : kCyan, prefs_.icon_colors ? kAmber : kCyan};
    const float s = 1 + 0.03f * focus;
    const float w = w0 * s, h = h0 * s, x = x0 + (w0 - w) / 2, y = y0 + (h0 - h) / 2 - 4 * focus;
    const float r = h / 2 > 26 ? 26 : h / 2;
    glass(gfx, x, y, w, h, r, {1, 1, 1, 0.07f + 0.06f * focus}, true);
    /* a soft wash of the category's colour, brighter in the middle when focused */
    if (hfade_)
        gfx.image(hfade_, x, y, w * 0.8f, h, C(tint[which], 0.10f + 0.10f * focus), r);
    ring(gfx, x, y, w, h, r, focus);
    const char *names[4] = {"GAMES", "EMULATORS", "APPS", "TOOLS"};
    const float is = std::min(h - 36, 44.f), gap = 16;
    const float nw = gfx.measure(Font::ExtraBold, 22, names[which], 2.5f);
    const float gx = x + (w - (is + gap + nw)) / 2;
    if (cat_icon_[which])
        gfx.image(cat_icon_[which], gx, y + (h - is) / 2, is, is, C(tint[which], 0.85f + 0.15f * focus));
    gfx.text_mid(Font::ExtraBold, 22, gx + is + gap, y + h / 2, C(kFrost), Align::Left, names[which], 2.5f);
}

void Store::draw_no_icon(Gfx &gfx, const App &a, float x, float y, float size, float radius)
{
    const int c = category_index(a.category);
    const Color tint = C(prefs_.icon_colors ? category_tint(c) : kCyan); /* (2.2.2) */
    gfx.panel(x, y, size, size, C(0x15223A), 1.f, radius);
    gfx.panel(x, y, size, size, Color{tint.r, tint.g, tint.b, 0.20f}, 0.45f, radius, kRim, 1.25f, 0, 0.05f);
    if (Texture *ic = cat_icon_[c])
    {
        const float is = size * 0.44f;
        gfx.image(ic, x + (size - is) / 2, y + (size - is) / 2, is, is, Color{tint.r, tint.g, tint.b, 0.92f});
    }
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
    const auto lines = discover_lines();
    const int nl = int(lines.size());
    if (nl == 0)
    {
        const std::string note = engine().catalog_note();
        gfx.text(Font::Regular, 20, 96, 160 + 60, C(kMuted), Align::Left,
                 !note.empty() ? note.c_str() : engine().online() ? "No games in the catalog yet." : "Connect your PS5 to the internet to load the catalog.");
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
            draw_header(gfx, L, i, sy, on); /* (2.1) in the catalog's style */
            continue;
        }
        if (L.kind == 5)
        {
            /* (2.0) The category buttons, side by side across the width. */
            const int col = on ? std::clamp(col_, 0, L.count() - 1) : -1;
            const float bw = (1728 - kTileGap * float(L.count() - 1)) / float(std::max(1, L.count()));
            for (int k = 0; k < L.count(); ++k)
            {
                float &f = dfocus_[i * 1000 + k];
                f = approach(f, k == col ? 1.f : 0.f, 1.f / 60.f, prefs_.reduced_motion ? 60.f : 14.f);
                draw_cat_button(gfx, L.cats[std::size_t(k)], item_x(L, k, 0), sy, bw, kCatH, ease_out(f));
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
    edge_fade(gfx, kViewTop, kViewBottom, dscroll_ > 0.5f); /* (2.2.3) */
    if (kDiscTop + total - dscroll_ > kViewBottom + 4)
        draw_more(gfx);
}

void Store::draw_settings(Gfx &gfx)
{
    const float top = 160, bottom = 1080 - 110, h = bottom - top;
    glass(gfx, 96, top, 400, h, 32, kGlass, true);
    /* By kind (2.0): General 0, Adding 1, Redeem 2, About 3, Customize 4, Link 5, Sources 6, (2.2.2)
     * Storage 7, ShadowMountPlus 8. Nine, so a little closer together. */
    const char *names[9] = {"General", "Adding your game", "Redeem a code", "About", "Customize", "Link a phone", "Sources",
                            "Storage", "ShadowMountPlus"};
    const char *hints[9] = {"Sounds, updates, refresh", "How to set a game up", "Unlock a game in testing",
                            "What's new, legal, credits", "Tabs, layout, favorites", "Send things from your phone",
                            "Add apps from GitHub", "Space and the download cache", "Get it, reload it"};
    const auto order = settings_order();
    const float ih = order.size() > 7 ? 80.f : 90.f, ip = ih + 6;
    for (int k = 0; k < int(order.size()); ++k)
    {
        const int i = order[std::size_t(k)];
        const bool on = i == section_;
        const float y = top + 18 + float(k) * ip;
        if (on)
            gfx.panel(114, y, 364, ih, in_section_ ? C(kFrost, 0.16f) : C(kFrost), 1.f, 20);
        const bool solid = on && !in_section_;
        gfx.text_mid(on ? Font::Bold : Font::SemiBold, 22, 138, y + ih / 2 - 12, solid ? C(kMidnight) : C(kFrost), Align::Left, names[i]);
        gfx.text_mid(Font::Regular, 16, 138, y + ih / 2 + 15, solid ? C(0x3B4556) : C(kDim), Align::Left, hints[i]);
    }

    const float px = 96 + 400 + 36, pw = 1920 - 96 - px;
    glass(gfx, px, top, pw, h, 32, C(kMidnight, 0.5f), true);
    const float cx = px + 56, cw = pw - 112;
    if (section_ == SecCustomize)
        draw_customize(gfx, cx, top, cw);
    else if (section_ == SecLink)
        draw_link(gfx, cx, top, cw, bottom);
    else if (section_ == SecSources)
        draw_sources(gfx, cx, top, cw, bottom);
    else if (section_ == SecSmp)
        draw_smp_section(gfx, cx, top, cw, bottom); /* (2.2.2) */
    else if (section_ == SecStorage)
        draw_storage_section(gfx, cx, top, cw, bottom); /* (2.2.2) */
    else if (section_ == SecGeneral)
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
        auto toggle = [&](bool on, std::uint32_t color) {
            const float sx = cx + cw - 8 - 74, sy = ry + 52 - 21;
            gfx.panel(sx, sy, 74, 42, on ? C(color) : C(kFrost, 0.22f), 1.f, 21);
            gfx.panel(on ? sx + 36 : sx + 4, sy + 4, 34, 34, C(0xFFFFFF), 1.f, 17);
        };
        option(0, "Menu sounds", "Soft clicks for moving, opening pages and installing.");
        toggle(sounds_on_, kGreen);
        ry += 104;
        /* (2.2.2) Every app with an update, in the download queue on its own (an app's page can do it
         * for that one only). ShadowMountPlus's rows are in its own section now. */
        option(1, "Update everything automatically", "Updates download on their own after each check. Apps kept at an older version are left alone.");
        toggle(prefs_.auto_all, kGreen);
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
                    result = "Recomp Store " + su + " is ready: see the Library.";
                else if (su.empty())
                    result = g + (games == 1 ? " has an update" : " have updates") + ": see the Library.";
                else
                    result = "Recomp Store " + su + " and " + g + " have updates: see the Library.";
            }
            /* (2.0) Refresh: the catalog, updates, and apps installed while the store was open
             * (Triangle does the same from every other tab). */
            option(2, "Refresh",
                   st == 1   ? "Reading the catalog and asking GitHub about the store and your games\xE2\x80\xA6"
                   : st == 3 ? "GitHub couldn't be reached. Check your connection and try again."
                   : st == 2 ? result
                             : "Reads the catalog again, looks for updates and finds apps installed while the store was open.");
            gfx.text_mid(Font::Regular, 19, cx + cw - 8, ry + 52, st == 1 ? C(kMuted) : C(kFrost), Align::Right,
                         st == 1 ? "Refreshing\xE2\x80\xA6" : "Refresh now");
            ry += 104;
        }
        if (engine().tester())
        {
            /* (2.0) A console the admin marked as a tester: the testers-only apps, or what
             * everyone else sees (to check the store as it goes out). */
            const bool on = engine().test_view();
            option(3, "Testers-only apps", on ? "Shown, marked Testing. Turn off to see the store as everyone else does."
                                              : "Hidden: you see what everyone else sees.");
            const float sx = cx + cw - 8 - 74, sy = ry + 52 - 21;
            gfx.panel(sx, sy, 74, 42, on ? C(0xB9AEFF) : C(kFrost, 0.22f), 1.f, 21);
            gfx.panel(on ? sx + 36 : sx + 4, sy + 4, 34, 34, C(0xFFFFFF), 1.f, 17);
            ry += 104;
        }
        if (!settings_note_.empty())
        {
            gfx.text_mid(Font::SemiBold, 18, cx + 8, ry + 18, settings_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), Align::Left,
                         fit(gfx, Font::SemiBold, 18, cw - 16, settings_note_));
            ry += 40;
        }
        auto row = [&](const std::string &k, const std::string &v) {
            if (ry + 68 > bottom - 8) return; /* (2.0) no room left (a tester's extra row) */
            gfx.panel(cx, ry, cw, 1.25f, kLine, 1.f, 0);
            gfx.text_mid(Font::Regular, 20, cx + 8, ry + 34, C(kMuted), Align::Left, k);
            gfx.text_mid(Font::Regular, 20, cx + cw - 8, ry + 34, C(kFrost), Align::Right, v);
            ry += 68;
        };
        row("Apps install to", engine().install_root() + "/<title ID>"); /* (2.0) Customize can change it */
        row("Needs", "etaHEN or elfldr, kstuff, ShadowMountPlus " +
                         (catalog_.smp_min.empty() ? std::string("1.7 Beta 5") : catalog_.smp_min) + "+");
        if (disp_w_)
            row("Display", std::to_string(disp_w_) + " \xC3\x97 " + std::to_string(disp_h_));
    }
    else if (section_ == SecAdding)
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
    else if (section_ == SecRedeem)
    {
        /* (1.1.0) Redeem a code: X opens the keypad; under it, the beta games this console has. */
        float y = top + 48;
        gfx.text_mid(Font::ExtraBold, 38, cx, y + 24, C(kFrost), Align::Left, "Redeem a code");
        y += 48 + 22;
        y += draw_lines(gfx, Font::Regular, 21, cx, y, 31.5f, C(0xD9E1EC),
                        wrap(gfx, Font::Regular, 21, std::min(cw, 1080.f),
                             "Got a code to test a game before it's out? Enter it here. The game goes in your Library "
                             "and stays on this console for as long as Recomp Store is installed.", 3)) + 28;
        {
            /* While the site checks a code the keypad was closed on, it says so. */
            std::string m, app;
            const bool checking = engine().redeem_state(m, app) == 1;
            const std::string label = checking ? "Checking your code\xE2\x80\xA6" : "Enter a code";
            const float bw = gfx.measure(Font::Bold, 22, label) + 64;
            gfx.panel(cx, y, bw, 64, checking ? C(kFrost, 0.14f) : C(kCyan), 1.f, 20);
            gfx.text_mid(Font::Bold, 22, cx + bw / 2, y + 32, checking ? C(kFrost) : C(kMidnight), Align::Center, label);
            y += 64 + 22;
        }
        if (!redeem_note_.empty())
        {
            gfx.text_mid(Font::SemiBold, 19, cx + 4, y + 13, redeem_note_bad_ ? C(0xFFB4A8) : C(0x9FF0C6), Align::Left,
                         fit(gfx, Font::SemiBold, 19, cw - 8, redeem_note_));
            y += 26 + 22;
        }
        y += 14;
        gfx.text_mid(Font::Bold, 16, cx, y + 10, C(kCyan), Align::Left, "BETA GAMES ON THIS CONSOLE", 2);
        y += 20 + 18;
        int shown = 0;
        for (const App &a : catalog_.apps)
        {
            if (!a.beta) continue;
            if (y + 68 > bottom - 28) break;
            gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
            const std::string name = fit(gfx, Font::SemiBold, 21, cw * 0.5f, a.name);
            gfx.text_mid(Font::SemiBold, 21, cx + 8, y + 34, C(kFrost), Align::Left, name);
            gfx.text_mid(Font::SemiBold, 18, cx + 8 + gfx.measure(Font::SemiBold, 21, name) + 12, y + 35, C(kCyan), Align::Left,
                         version_of(a));
            const bool inst = !inst_v(a.id).empty();
            gfx.text_mid(Font::Regular, 19, cx + cw - 8, y + 34, inst ? C(kFrost) : C(kMuted), Align::Right,
                         inst ? state_of(a, nullptr) : std::string("Not installed"));
            y += 68;
            ++shown;
        }
        if (!shown)
        {
            gfx.panel(cx, y, cw, 1.25f, kLine, 1.f, 0);
            gfx.text_mid(Font::Regular, 20, cx + 8, y + 34, C(kDim), Align::Left, "None yet.");
        }
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
        {
            /* (2.0) This console's ID on recompstore.com, short (the admin's Testers list shows it). */
            const std::string id = engine().install_id();
            gfx.text_mid(Font::Regular, 20, cx + 138, y + 80, C(kMuted), Align::Left,
                         std::string("Version ") + kVersion + (*kVersionLabel ? std::string(", ") + kVersionLabel : std::string()) +
                             " (build " + std::to_string(kBuild) + ")" + (id.size() >= 8 ? " \xC2\xB7 Console ID " + id.substr(0, 8) : std::string()) +
                             (engine().tester() ? " \xC2\xB7 Tester" : ""));
        }
        {
            /* (2.2.2) What's new in this version: Cross opens it. */
            const std::string label = std::string("What's new in ") + kVersion;
            const float ww = gfx.measure(Font::Bold, 19, label) + 26 + 22 + 30;
            const float wx = cx + cw - ww, wy = y + 30;
            gfx.panel(wx, wy, ww, 52, C(kCyan), 1.f, 18);
            if (hint_cross_) gfx.image(hint_cross_, wx + 18, wy + 26 - 11, 22, 22, C(kMidnight));
            gfx.text_mid(Font::Bold, 19, wx + 18 + 22 + 10, wy + 26, C(kMidnight), Align::Left, label);
        }
        y += 112 + 24;
        const float bw = std::min(cw, 1080.f);
        const auto l1 = wrap(gfx, Font::Regular, 21, bw - 64,
                             "Recomp Store doesn't host any game files or copyrighted game content, and neither do the GitHub "
                             "repositories it links to. They hold only the ports and their tools. Every game needs your own legally "
                             "obtained copy, which you set up yourself. Title Updates the store downloads for you come from "
                             "XboxUnity's community archive; Recomp Store doesn't host them either.", 5);
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
float Store::preview_notes_overflow(int *lines) const
{
    if (lines) *lines = int(notes_lines_.size());
    float worst = 0;
    if (!gfx_) return 0;
    for (const NoteLine &l : notes_lines_)
    {
        const bool bullet = l.kind == 4 || l.kind == 6;
        const float avail = notes_w_ - (bullet ? 24.f : 0.f);
        const Font f = l.kind == 0 ? Font::ExtraBold : l.kind == 2 ? Font::Bold : Font::Regular;
        const float size = l.kind == 0 ? 26.f : l.kind == 1 ? 17.f : 20.f;
        worst = std::max(worst, gfx_->measure(f, size, l.text) - avail);
    }
    return std::max(0.f, worst);
}

void Store::layout_notes(Gfx &gfx, const std::string &key, const std::vector<Release> &rel, float width)
{
    notes_w_ = width;
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
        /* The body, a line at a time, its HTML made plain first (2.2.3: a tag over several lines,
         * a picture, an entity). */
        const std::string body = notes_text(x.body);
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
        /* (2.1) A beta's: what's new in each of its builds. */
        const std::string from = a.beta ? std::string("Beta builds") : a.source == "github" ? "From GitHub \xC2\xB7 " + a.repo : std::string();
        gfx.text_mid(Font::Regular, 16, x + w - 32, y + 28 + 9, C(kMuted, al), Align::Right, fit(gfx, Font::Regular, 16, w - 260, from));
    }
    const float top = y + 28 + 18 + 22, bottom = y + h - 26;
    std::vector<Release> rel;
    const int state = page_notes(a, rel);
    if (state <= 0)
    {
        const std::string msg = a.source != "github" ? std::string() /* (2.1) only GitHub's can be on their way */
                                : state == 0         ? "Loading the notes from GitHub\xE2\x80\xA6"
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
        draw_no_icon(gfx, a, ix, ry, 112, 26);
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
    /* (2.2.3) Scrolled down to the rows under the page, the art goes soft and darker behind them
     * (the frosted copy over the sharp one), so they read. */
    const float sk = clamp01(page_scroll_ / 420.f);
    if (t.hero && sk < 1)
    {
        float uv[4];
        const float z = 1.04f - 0.04f * k;
        cover_uv(t.hero, W, H, 0.5f, a.banner_y / 100.f, z, uv);
        gfx.image_part(t.hero, 0, 0, W, H, uv, {1, 1, 1, t.blur ? 1.f : 1.f - 0.5f * sk});
    }
    if (t.blur && (!t.hero || sk > 0))
        gfx.image(t.blur, -60, -60, W + 120, H + 120, {0.6f, 0.6f, 0.6f, t.hero ? sk : 1.f});
    gfx.panel(0, 0, W, H, C(kMidnight, 0.55f + 0.22f * sk), 1.f, 0);
    /* (2.2.2) The rows under the page: scrolled to the one focused. */
    const auto related = related_rows(a);
    {
        const float pitch = 352;
        page_rel_ = related.empty() ? -1 : std::min(page_rel_, int(related.size()) - 1);
        page_scroll_to_ = page_rel_ < 0 ? 0.f : std::max(0.f, 1090 + float(page_rel_ + 1) * pitch - 1010);
        if (page_scroll_ < 0 || prefs_.reduced_motion) page_scroll_ = page_scroll_to_;
        page_scroll_ = approach(page_scroll_, page_scroll_to_, 1.f / 60.f, 10.f);
        if (std::fabs(page_scroll_ - page_scroll_to_) < 0.5f) page_scroll_ = page_scroll_to_;
    }
    gfx.set_layer(0, (1 - k) * 28 - page_scroll_, k);

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
        if (a.beta) chip("Beta", Font::Bold, C(kAmber), C(kAmber), C(kMidnight));
        if (a.testing) chip("Testing", Font::Bold, C(0xB9AEFF), C(0xB9AEFF), C(kMidnight)); /* (2.0) testers only */
        if (a.custom) chip("From a source you added", Font::Bold, C(kAmber, 0.16f), C(kAmber, 0.6f), C(0xFFD9A0));
        if (!a.author.empty() || original) chip(kind, Font::Bold, original ? C(kCyan) : kGlass, original ? C(kCyan) : kRim, original ? C(kMidnight) : C(kFrost));
        /* Its title ID (a custom app's once the store has read it; "gh-..." is no title ID). */
        const std::string tid = a.custom ? own_id(a.id) : a.id;
        if (tid.rfind("gh-", 0) != 0) chip(tid, Font::SemiBold, kGlass, kRim, C(kFrost));
        if (a.category == "games" || !a.needs.empty())
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
    const int rel_state = page_notes(a, rel); /* (2.1) a beta's builds too */
    if (rel_state > 0)
        layout_notes(gfx, (a.beta ? a.id + "#beta" : a.repo) + "#" + std::to_string(rel.size()) + "#" + rel.front().tag + "#" +
                              std::to_string(rel.front().body.size()),
                     rel, 820 - 64 - 16);
    /* (2.1) A beta's own notes for its build come first (the first of its lines, as laid out). */
    std::string news = a.beta && !a.beta_notes.empty() ? std::string() : a.news;
    if (news.empty() && rel_state > 0)
        for (const NoteLine &l : notes_lines_)
            if (l.kind == 3 || l.kind == 4) { news = l.text; break; }
    if (news.empty() && rel_state > 0) news = "See every release's notes.";
    if (news.empty()) news = rel_state == 0 ? "Getting its notes from GitHub\xE2\x80\xA6" : "No notes for this release."; /* (2.0) never an empty box */
    const auto news_lines = wrap(gfx, Font::Regular, 21, 820 - 64, news, 2);
    const float nh = notes_box ? 28 + 18 + 10 + 31.5f * float(std::max<std::size_t>(1, news_lines.size())) + 28 : 0;
    const auto credits = wrap(gfx, Font::Regular, 18, 900, a.credits, 2);
    const float ch = a.credits.empty() ? 0 : 27 * float(credits.size());
    /* Bring your own: a game's disc, an emulator's games; an app or a tool needs nothing (2.0). */
    const bool game_like = a.category == "games" || !a.needs.empty();
    const char *kDiscHead = game_like ? "BRING YOUR OWN DISC" : "BRING YOUR OWN GAMES";
    const char *kDisc = !game_like ? "Recomp Store doesn't host any games, and neither does this emulator's GitHub repository. "
                                     "Play your own legally obtained copies."
                        : a.source == "github"
                            ? "Recomp Store and this game's GitHub repository don't host any game files. Set it up from your own "
                              "legally obtained copy."
                            : "Recomp Store doesn't host any game files. Set it up from your own legally obtained copy.";
    const bool disc_box = game_like || a.category == "emulators";
    const auto disc = wrap(gfx, Font::Regular, 18, 820 - 64, kDisc, 3);
    const float dh = disc_box ? 22 + 16 + 10 + 27 * float(disc.size()) + 22 : 0;
    /* Open, the notes cover everything from the description down (text is drawn over
     * panels, so what they cover fades out rather than showing through). */
    const float ne = notes_box && notes_t_ > 0.01f ? ease_out(notes_t_) : 0.f, u = 1.f - ne;
    const float desc_y = y;
    const float rdy_h = ready_height(a); /* (2.3.0) Ready to play */
    {
        const float room = bottom - y - (notes_box ? nh + 24 : 0) - (ch > 0 ? ch + 24 : 0) - (dh > 0 ? dh + 24 : 0) - (rdy_h > 0 ? rdy_h + 24 : 0);
        const int lines = std::clamp(int(room / 37.5f), 1, 5);
        y += draw_lines(gfx, Font::Regular, 25, x, y, 37.5f, C(kBody, u), wrap(gfx, Font::Regular, 25, 860, a.description, lines)) + 24;
    }
    const float ny = y;
    if (notes_box && ne < 0.01f)
    {
        glass(gfx, x, y, 820, nh, 26, kGlass, false);
        gfx.text_mid(Font::Bold, 15, x + 32, y + 28 + 9, C(kCyan), Align::Left,
                     "WHAT'S NEW" + (a.version.empty() ? std::string() : " \xC2\xB7 " + a.version.substr(version_start(a.version))), 2);
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
    if (rdy_h > 0)
    {
        draw_ready(gfx, a, x, y, 820, u); /* (2.3.0) */
        y += rdy_h + 24;
    }
    if (ch > 0)
    {
        draw_lines(gfx, Font::Regular, 18, x, y, 27, C(0xB9C4D3, u), credits);
        y += ch + 24;
    }
    if (u > 0.01f && disc_box)
    {
        const float dy = std::min(y, bottom - dh);
        gfx.panel(x, dy, 820, dh, C(kCyan, 0.08f * u), 1.f, 22, C(kCyan, 0.35f * u), 1.25f);
        gfx.text_mid(Font::Bold, 14, x + 32, dy + 22 + 8, C(kCyan, u), Align::Left, kDiscHead, 2);
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
    const std::string inst = inst_v(a.id);
    const bool setup = show_setup(a);
    const auto ctl = page_controls(a);
    auto focused = [&](Control::Kind kind, int slot) {
        const Control &c = ctl[std::size_t(std::clamp(page_focus_, 0, int(ctl.size()) - 1))];
        return !picker_ && !confirm_ && !notes_focus_ && !logs_ && !vers_ && !moved_ && page_rel_ < 0 && !panel_ && c.kind == kind &&
               (kind != Control::Slot || c.slot == slot);
    };

    /* Heights first, so the glass fits its contents. */
    const auto notice = notice_.empty() || busy ? std::vector<std::string>{} : wrap(gfx, Font::SemiBold, 18, iw, notice_, 3);
    const float head_h = 112, btn_h = 84, prog_h = installing ? 12 + 12 + 22 : 0;
    const float notice_h = notice.empty() ? 0 : 27 * float(notice.size());
    const float slot_h = 74;
    const float setup_h = setup ? 22 + 14 + slot_h * float(a.slots.size()) + 14 + 64 + (setting_up ? 14 + 10 + 12 + 22 : 0) : 0;
    /* (2.0) Its part of the store and its labels: "Emulator · GameCube · Wii". */
    std::string kind_row = a.category == "emulators" ? "Emulator" : a.category == "apps" ? "App" : a.category == "tools" ? "Tool" : "Game";
    if (a.testing) kind_row += "  \xC2\xB7  Testing";
    for (const std::string &tg : a.tags) kind_row += "  \xC2\xB7  " + tg;
    /* (2.0) Moved to its own title ID by the title ID guard: which one, and why. A custom
     * source's app: the title ID its release says, once the store has read it. */
    const std::string moved = moved_id(a.id);
    /* (2.0) How many consoles have it (the charts), and its repo's GitHub stars. */
    std::string popularity;
    {
        auto num = [](long long n) {
            std::string t = std::to_string(n);
            for (int i = int(t.size()) - 3; i > 0; i -= 3) t.insert(std::size_t(i), ",");
            return t;
        };
        const ChartStat *cs = charts_.stat(a.id);
        const long long installs = cs ? cs->installs : 0, stars = cs && cs->stars >= 0 ? cs->stars : a.stars;
        if (installs > 0) popularity = num(installs) + (installs == 1 ? " console" : " consoles");
        if (stars > 0) popularity += (popularity.empty() ? "" : "  \xC2\xB7  ") + num(stars) + (stars == 1 ? " star on GitHub" : " stars on GitHub");
    }
    std::string tid_row;
    if (!moved.empty()) tid_row = moved + " (" + own_id(a.id) + " was taken on this PS5)";
    else if (a.custom)
    {
        const std::string own = own_id(a.id);
        tid_row = own.rfind("gh-", 0) != 0 ? own : !a.title_id.empty() ? a.title_id : prefs_.advanced ? std::string("Read when it's installed") : std::string();
    }
    else if (prefs_.advanced)
        tid_row = a.id;
    const std::pair<std::string, std::string> rows[] = {
        {"Version", version_of(a)},
        {"Category", kind_row},
        {"Download", a.size},
        {"Installs to", a.custom && installed_id(a.id).rfind("gh-", 0) == 0
                            ? std::string(kHomebrew) + "/" + (a.title_id.empty() ? std::string("<its title ID>") : a.title_id)
                            : app_folder(a.id)},
        {"Title ID", tid_row},
        /* Only a game needs the player's own copy unless its listing says what to bring. */
        {"You bring", !a.needs.empty() ? "Your " + a.needs : a.category == "games" ? std::string("Your own game") : std::string()},
        {"Source", a.beta ? std::string("recompstore.com (beta)") : a.source == "github" ? "github.com/" + a.repo : a.url},
        {"Added from", a.custom ? (a.source_name.empty() ? a.source_value : a.source_name) : std::string()},
        {"Popularity", popularity},
    };
    int nrows = 0;
    for (const auto &r : rows)
        nrows += r.second.empty() ? 0 : 1;
    /* (2.0) More rows than before: a little closer together when there are many. */
    const float row_pitch = nrows > 5 ? 46.f : 56.f;
    const float rows_h = setup ? 0 : row_pitch * float(nrows);
    /* (2.0) An app from a custom source says so where the note goes, in amber. */
    const auto note = wrap(gfx, Font::Regular, 17, iw - 34,
                           a.custom ? source_line(a)
                           : a.category == "games" || !a.needs.empty()
                               ? "Updates replace only the app. Your game install, saves, DLC and mods stay where they are."
                               : "Updates replace only the app's own files. Anything else in its folder stays as it is.", 3);
    const float note_h = 24.6f * float(note.size());
    const bool can_delete = !inst.empty() && inst != "?";
    bool has_auto = false, has_saves = false;
    for (const Control &c : ctl) has_auto = has_auto || c.kind == Control::AutoUpdate;
    for (const Control &c : ctl) has_saves = has_saves || c.kind == Control::Saves;
    bool has_mods = false;
    for (const Control &c : ctl) has_mods = has_mods || c.kind == Control::Mods;
    const float del_h = 56 + (has_auto ? 12 + 56 : 0) + (has_saves ? 12 + 56 : 0) + (has_mods ? 12 + 56 : 0); /* (2.2.3) the actions' bar; Auto-update's row; (2.3.0) Saves' */
    float ph = 40 + head_h + 28 + btn_h;
    for (float part : {prog_h, notice_h, setup_h, rows_h, del_h, note_h})
        if (part > 0)
            ph += 28 + part;
    ph += 40;
    ph = std::min(ph, H - py - (has_auto || has_saves || has_mods ? 80.f : 40.f)); /* (2.2.2) clear of the hints, with Auto-update's row */
    glass(gfx, px, py, pw, ph, 34, C(kMidnight, 0.74f), true);

    float ry = py + 40;
    if (t.icon)
        gfx.image(t.icon, ix, ry, 112, 112, {}, 26);
    else
        draw_no_icon(gfx, a, ix, ry, 112, 26);
    gfx.text_mid(Font::ExtraBold, 28, ix + 134, ry + 56 - 16, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 28, iw - 134, a.name));
    gfx.text_mid(Font::Regular, 19, ix + 134, ry + 56 + 20, C(0xB9C4D3), Align::Left,
                 busy ? (job.step == "Downloading" ? "Downloading \xE2\x80\x94 you can keep browsing"
                                                   : "Installing \xE2\x80\x94 you can keep browsing")
                      : st);
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
        else if (queue_place(a.id) > 1)
        {
            /* (2.2.2) Waiting its turn in the download queue. */
            label = "Queued (" + ordinal(queue_place(a.id)) + ")";
            bg = C(kCyan, 0.12f);
            fg = C(kFrost);
            edge = C(kCyan, 0.45f);
        }
        else if (inst.empty())
            label = "Install";
        else if (inst == "?")
        {
            label = "Manage with Recomp Store";
            bg = C(kCyan);
        }
        else if (up || (!kept_at(a).empty() && newer_out(a))) /* (2.1) kept at an older one: the newest, still */
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
        else if (job.done && job.step.rfind("Download", 0) == 0)
            right = size_text(job.done); /* (1.1.2) a download whose size isn't known: what's here so far */
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
            else if (chosen) status = ch->second == kDownloadChoice ? "Downloaded when you set up" : base_name(ch->second);
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
                status = src.empty() ? "Ready" : store_downloaded(src) ? "Ready \xC2\xB7 downloaded" : "Ready \xC2\xB7 from " + base_name(src);
            }
            else if (slot_fetchable(s)) status = s.required ? "Not set up yet \xC2\xB7 the store can download it" : "Not set up \xC2\xB7 optional \xC2\xB7 the store can download it";
            else status = s.required ? "Not set up yet" : "Not set up \xC2\xB7 optional";
            const char *btn = !can                          ? "Later"
                              : (chosen || ready)           ? (s.multiple && !chosen ? "Add" : "Change")
                              : slot_fetchable(s)           ? "Download"
                                                            : "Choose\xE2\x80\xA6";
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
            label = job.step.rfind("Finding", 0) == 0 || job.step.rfind("Downloading", 0) == 0 ? job.step + "\xE2\x80\xA6"
                    : job.step.rfind("Checking", 0) == 0  ? "Checking the copied files\xE2\x80\xA6"
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
            gfx.text_mid(Font::Regular, 19, ix, ry + row_pitch / 2, C(kMuted), Align::Left, r.first);
            gfx.text_mid(Font::Regular, 19, ix + iw, ry + row_pitch / 2, C(kFrost), Align::Right,
                         fit(gfx, Font::Regular, 19, iw - 160, r.second));
            ry += row_pitch;
        }
    }
    {
        /* (2.2.3) The app's actions as one group: a single glass bar split evenly (Delete when
         * installed, Send logs, Versions, the star), and under it Auto-update as a row with a switch. */
        ry += 28;
        struct Seg { Control::Kind kind; std::string label; float w = 0; };
        std::vector<Seg> segs;
        if (can_delete) segs.push_back({Control::Delete, a.category.empty() || a.category == "games" ? "Delete game" : "Delete app"});
        segs.push_back({a.custom ? Control::Report : a.beta ? Control::Feedback : Control::Logs, a.custom ? "Report source" : a.beta ? "Feedback" : "Send logs"});
        if (has_versions(a)) segs.push_back({Control::Versions, "Versions"});
        if (!a.custom && !a.soon) /* (2.3.0) a like, with how many */
        {
            const long long n = engine().like_count(a.id);
            segs.push_back({Control::Like, std::string(engine().liked(a.id) ? "Liked" : "Like") + (n > 0 ? " \xC2\xB7 " + std::to_string(n) : std::string())});
        }
        segs.push_back({Control::Favorite, ""});
        const float bh = 56, star_w = 72;
        float rest = iw - star_w;
        const int nt = int(segs.size()) - 1;
        for (Seg &g : segs) g.w = g.label.empty() ? star_w : rest / float(std::max(1, nt));
        glass(gfx, ix, ry, iw, bh, 18, Color{1, 1, 1, 0.05f}, false);
        float sx = ix;
        for (std::size_t k = 0; k < segs.size(); ++k)
        {
            const Seg &g = segs[k];
            const bool f = focused(g.kind, -1);
            if (k) gfx.panel(sx, ry + 14, 1.25f, bh - 28, kLine, 1.f, 0); /* between them */
            if (f)
            {
                const Color fill = g.kind == Control::Delete ? C(0xE5484D, 0.20f) : C(kFrost, 0.14f);
                gfx.panel(sx + 4, ry + 4, g.w - 8, bh - 8, fill, 1.f, 14);
                ring(gfx, sx + 4, ry + 4, g.w - 8, bh - 8, 14, 1.f, true);
            }
            if (g.kind == Control::Favorite)
            {
                const bool fav = pinned(a.id);
                gfx.glyph(fav ? Glyph::Star : Glyph::StarOutline, sx + g.w / 2, ry + bh / 2 + 0.5f, 22, fav ? C(kCyan) : C(kSoft));
            }
            else
                gfx.text_mid(Font::SemiBold, 18, sx + g.w / 2, ry + bh / 2, g.kind == Control::Delete ? C(0xFF8A80) : C(kSoft),
                             Align::Center, fit(gfx, Font::SemiBold, 18, g.w - 20, g.label));
            sx += g.w;
        }
        ry += bh;
        if (has_auto)
        {
            /* (2.2.2) Auto-update, for this app on its own: (2.2.3) a row with a switch. */
            ry += 12;
            const bool on = auto_on(a.id) || prefs_.auto_all, af = focused(Control::AutoUpdate, -1);
            glass(gfx, ix, ry, iw, bh, 18, af ? C(kFrost, 0.12f) : Color{1, 1, 1, 0.05f}, false);
            ring(gfx, ix, ry, iw, bh, 18, af ? 1.f : 0.f, true);
            gfx.text_mid(Font::SemiBold, 19, ix + 22, ry + bh / 2, C(kFrost), Align::Left, "Auto-update");
            const char *sub = prefs_.auto_all ? "On for every app, in Settings" : on ? "New versions install on their own" : "You update it yourself";
            gfx.text_mid(Font::Regular, 16, ix + 22 + gfx.measure(Font::SemiBold, 19, "Auto-update") + 14, ry + bh / 2, C(kMuted), Align::Left, sub);
            draw_switch(gfx, ix + iw - 22 - 52, ry + bh / 2, on, af);
            ry += bh;
        }
        if (has_saves)
        {
            /* (2.3.0) Saves: back up, restore, or send them to a phone. */
            ry += 12;
            const bool sf = focused(Control::Saves, -1);
            glass(gfx, ix, ry, iw, bh, 18, sf ? C(kFrost, 0.12f) : Color{1, 1, 1, 0.05f}, false);
            ring(gfx, ix, ry, iw, bh, 18, sf ? 1.f : 0.f, true);
            gfx.text_mid(Font::SemiBold, 19, ix + 22, ry + bh / 2, C(kFrost), Align::Left, "Saves");
            gfx.text_mid(Font::Regular, 16, ix + 22 + gfx.measure(Font::SemiBold, 19, "Saves") + 14, ry + bh / 2, C(kMuted), Align::Left,
                         "Back up, restore, or keep them on your phone");
            gfx.text_mid(Font::SemiBold, 17, ix + iw - 22, ry + bh / 2, C(kSoft), Align::Right, "Open");
            ry += bh;
        }
        if (has_mods)
        {
            /* (2.3.0) Mods: how many are on. */
            ry += 12;
            int on_n = 0;
            for (const App::Mod &m : a.mods) on_n += engine().mod_state(a, m) == 1;
            const bool mf = focused(Control::Mods, -1);
            glass(gfx, ix, ry, iw, bh, 18, mf ? C(kFrost, 0.12f) : Color{1, 1, 1, 0.05f}, false);
            ring(gfx, ix, ry, iw, bh, 18, mf ? 1.f : 0.f, true);
            gfx.text_mid(Font::SemiBold, 19, ix + 22, ry + bh / 2, C(kFrost), Align::Left, "Mods");
            gfx.text_mid(Font::Regular, 16, ix + 22 + gfx.measure(Font::SemiBold, 19, "Mods") + 14, ry + bh / 2, C(kMuted), Align::Left,
                         std::to_string(on_n) + " of " + std::to_string(a.mods.size()) + " on");
            gfx.text_mid(Font::SemiBold, 17, ix + iw - 22, ry + bh / 2, C(kSoft), Align::Right, "Open");
            ry += bh;
        }
    }
    ry += 28;
    if (ry + note_h < py + ph - (has_auto || has_saves || has_mods ? 30.f : 0.f))
    {
        if (a.custom)
        {
            gfx.panel(ix + 2, ry + 3, 20, 20, C(kAmber), 1.f, 10);
            gfx.text_mid(Font::ExtraBold, 14, ix + 12, ry + 13, C(kMidnight), Align::Center, "!");
        }
        else if (shield_)
            gfx.image(shield_, ix, ry + 2, 22, 22, C(kGreen));
        draw_lines(gfx, Font::Regular, 17, ix + 34, ry, 24.6f, a.custom ? C(0xFFD9A0) : C(kSoft), note);
    }
    if (!related.empty())
    {
        /* (2.2.3) The rows on a sheet of glass of their own, rising over the art. */
        const float sheet_y = 1090 - 70, sheet_h = 70 + float(related.size()) * 352 + 60;
        glass(gfx, 40, sheet_y, W - 80, sheet_h, 44, C(kMidnight, 0.30f + 0.25f * sk), true);
        draw_related(gfx, a, 1090); /* (2.2.2) More like this, People also installed, More from <author> */
    }
    gfx.set_layer();
    if (!related.empty() && page_rel_ < 0 && page_scroll_ < 1 && !notes_open_ && !panel_)
        draw_more(gfx);
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
        else if (slot.kind == "arcade")
            hint_line = "The game's own file from the console, in its 000D0000 folder";
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
    else if (entries_.size() <= (picker_mode_ == 0 ? 1u : 0u))
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
        const bool phone = e.path == kPhoneChoice; /* (2.3.0) */
        Texture *ic = e.is_dir ? folder_icon_ : disc_icon_;
        if (phone)
        {
            gfx.panel(rx + 22, ry + row_h / 2 - 16, 22, 32, Color{0, 0, 0, 0}, 1.f, 5, C(kCyan), 2.f);
            gfx.panel(rx + 29, ry + row_h / 2 + 9, 8, 3, C(kCyan), 1.f, 1.5f);
        }
        else if (ic)
            gfx.image(ic, rx + 20, ry + row_h / 2 - 15, 30, 30, e.is_dir ? C(kSoft) : C(kCyan));
        const char *tag = phone ? "Same Wi-Fi" : e.is_dir ? "Open" : "Use this";
        const float tw = gfx.measure(Font::Bold, 15, tag);
        const std::string sz = e.is_dir || phone ? "" : size_text(e.size);
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
/* ---- Settings > Redeem a code (1.1.0) ------------------------------------------------ */
namespace
{
/* The keypad's keys, left to right and top to bottom. */
const char *const kKeys[12] = {"1", "2", "3", "4", "5", "6", "7", "8", "9", "Delete", "0", "Redeem"};
const int kKeyDelete = 9, kKeyZero = 10, kKeyRedeem = 11;
} // namespace

void Store::open_keypad()
{
    keypad_ = true;
    keypad_t_ = 0;
    key_sel_ = 0;
    key_digits_.clear();
    key_msg_.clear();
    key_bad_ = false;
    key_app_.clear();
    key_done_sel_ = 0;
    std::string m, app;
    key_view_ = engine().redeem_state(m, app) == 1 ? 1 : 0; /* one still being checked */
    play(int(Sfx::Open));
}

void Store::redeem_typed()
{
    if (key_digits_.size() != 8)
    {
        key_msg_ = "A code is 8 numbers, like 12-345-678.";
        key_bad_ = true;
        play(int(Sfx::Error));
        return;
    }
    if (!engine().redeem(key_digits_))
    {
        play(int(Sfx::Error));
        return;
    }
    key_view_ = 1;
    key_msg_.clear();
    key_bad_ = false;
    redeem_note_.clear();
    play(int(Sfx::Select));
}

bool Store::update_keypad(float dt)
{
    keypad_t_ = std::min(1.f, keypad_t_ + dt * 5.f);
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (key_view_ == 1)
    {
        /* Checking: Circle closes it; the answer then shows under Redeem a code. */
        if (pressed(pad::BtnCircle)) { keypad_ = false; play(int(Sfx::Back)); }
        return true;
    }
    if (key_view_ == 2)
    {
        /* Unlocked: Open it (the game's page, from the Library) or Done. */
        const App *a = find(key_app_);
        const int sel = std::clamp(key_done_sel_ + (r ? 1 : 0) - (l ? 1 : 0), a ? 0 : 1, 1);
        if (sel != key_done_sel_) { key_done_sel_ = sel; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && key_done_sel_ == 1))
        {
            keypad_ = false;
            play(int(Sfx::Back));
        }
        else if (pressed(pad::BtnCross) && a)
        {
            keypad_ = false;
            tab_dir_ = tab_ < Library ? 1 : -1;
            tab_ = Library;
            tab_t_ = 1;
            in_section_ = false;
            row_ = col_ = 0;
            lscroll_ = lscroll_to_ = 0;
            lib_view_ = 2; /* (2.0) the Library's Betas */
            lib_bar_ = false;
            const auto lib = beta_apps();
            for (int i = 0; i < int(lib.size()); ++i)
                if (lib[std::size_t(i)]->id == a->id) col_ = i;
            focus_id_ = a->id;
            open(a->id);
        }
        return true;
    }
    if (pressed(pad::BtnCircle))
    {
        keypad_ = false;
        play(int(Sfx::Back));
        return true;
    }
    const int row = std::clamp(key_sel_ / 3 + (d ? 1 : 0) - (u ? 1 : 0), 0, 3);
    const int col = std::clamp(key_sel_ % 3 + (r ? 1 : 0) - (l ? 1 : 0), 0, 2);
    if (row * 3 + col != key_sel_) { key_sel_ = row * 3 + col; play(int(Sfx::Move)); }
    auto erase = [&] {
        if (key_digits_.empty()) { play(int(Sfx::Error)); return; }
        key_digits_.pop_back();
        key_msg_.clear();
        key_bad_ = false;
        play(int(Sfx::Move));
    };
    if (pressed(pad::BtnSquare))
        erase(); /* a shortcut for Delete */
    else if (pressed(pad::BtnCross))
    {
        if (key_sel_ == kKeyDelete)
            erase();
        else if (key_sel_ == kKeyRedeem)
            redeem_typed();
        else if (key_digits_.size() >= 8)
            play(int(Sfx::Error));
        else
        {
            key_digits_ += char('0' + (key_sel_ == kKeyZero ? 0 : key_sel_ + 1));
            key_msg_.clear();
            key_bad_ = false;
            play(int(Sfx::Select));
            if (key_digits_.size() == 8) key_sel_ = kKeyRedeem; /* all there: onto Redeem */
        }
    }
    return true;
}

void Store::draw_keypad(Gfx &gfx)
{
    if (!keypad_)
        return;
    const float k = ease_out(keypad_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 720, bx = (W - bw) / 2, pad = 56, iw = bw - pad * 2;
    const float key_w = (iw - 2 * 14) / 3, key_h = 76, key_gap = 14;
    if (key_view_ == 2)
    {
        /* Unlocked. */
        const auto lines = wrap(gfx, Font::Regular, 22, iw, key_msg_, 4);
        const float bh = pad + 44 + 18 + 33 * float(lines.size()) + 36 + 64 + pad;
        const float by = (H - bh) / 2;
        glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
        float y = by + pad;
        gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 22, C(kFrost), Align::Left, "Unlocked");
        y += 44 + 18;
        y += draw_lines(gfx, Font::Regular, 22, bx + pad, y, 33, C(kSoft), lines) + 36;
        const bool can_open = find(key_app_) != nullptr;
        const float gap = 16, w2 = (iw - gap) / 2;
        const char *labels[2] = {"Open it", "Done"};
        for (int i = 0; i < 2; ++i)
        {
            const float x = bx + pad + float(i) * (w2 + gap);
            const bool on = key_done_sel_ == i;
            const bool dim = i == 0 && !can_open;
            gfx.panel(x, y, w2, 64, i == 0 ? C(kCyan, dim ? 0.2f : on ? 1.f : 0.3f) : C(kFrost, on ? 1.f : 0.12f), 1.f, 20);
            ring(gfx, x, y, w2, 64, 20, on ? 1.f : 0.f, true);
            gfx.text_mid(Font::Bold, 22, x + w2 / 2, y + 32, on ? C(kMidnight) : C(kFrost, dim ? 0.4f : 1.f), Align::Center, labels[i]);
        }
        gfx.set_layer();
        return;
    }
    const bool checking = key_view_ == 1;
    const float bh = pad + 44 + 10 + 30 + 30 + 80 + 20 + 28 + 26 + 4 * key_h + 3 * key_gap + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 22, C(kFrost), Align::Left, "Redeem a code");
    y += 44 + 10;
    gfx.text_mid(Font::Regular, 20, bx + pad, y + 15, C(kSoft), Align::Left, "Enter the 8 numbers of your code.");
    y += 30 + 30;
    {
        /* The code as it's typed, 12-345-678, the next number's box lit. */
        const float cw = 60, ch = 80, cgap = 10, dash = 36;
        const float total = 8 * cw + 5 * cgap + 2 * dash;
        float x = bx + pad + (iw - total) / 2;
        const float blink = 0.55f + 0.45f * std::sin(time_ * 5.f);
        for (int i = 0; i < 8; ++i)
        {
            const bool next = !checking && i == int(key_digits_.size());
            gfx.panel(x, y, cw, ch, C(kFrost, i < int(key_digits_.size()) ? 0.12f : 0.06f), 1.f, 16,
                      next ? C(kCyan, blink) : kRim, next ? 2.5f : 1.25f);
            if (i < int(key_digits_.size()))
                gfx.text_mid(Font::ExtraBold, 40, x + cw / 2, y + ch / 2, C(kFrost, checking ? 0.6f : 1.f), Align::Center,
                             std::string(1, key_digits_[std::size_t(i)]));
            x += cw;
            if (i == 1 || i == 4)
            {
                gfx.text_mid(Font::Bold, 34, x + dash / 2, y + ch / 2, C(kDim), Align::Center, "\xE2\x80\x93");
                x += dash;
            }
            else if (i < 7)
                x += cgap;
        }
        y += ch + 20;
    }
    {
        /* What's going on: checking, or why the last try didn't work. */
        std::string line;
        Color c = C(kMuted);
        if (checking)
            line = engine().job().phase == Phase::Working ? "Checking your code once the download that's running is done\xE2\x80\xA6"
                                                          : "Checking your code with recompstore.com\xE2\x80\xA6";
        else if (!key_msg_.empty())
        {
            line = key_msg_;
            c = key_bad_ ? C(0xFFB4A8) : C(0x9FF0C6);
        }
        if (!line.empty())
            gfx.text_mid(Font::SemiBold, 19, bx + bw / 2, y + 14, c, Align::Center, fit(gfx, Font::SemiBold, 19, iw, line));
        y += 28 + 26;
    }
    for (int i = 0; i < 12; ++i)
    {
        const float x = bx + pad + float(i % 3) * (key_w + key_gap), ky = y + float(i / 3) * (key_h + key_gap);
        const bool on = !checking && key_sel_ == i;
        const bool redeem = i == kKeyRedeem, ready = key_digits_.size() == 8;
        Color bg = C(kFrost, on ? 1.f : 0.08f), fg = on ? C(kMidnight) : C(kFrost);
        if (redeem && ready) { bg = C(kCyan, on ? 1.f : 0.85f); fg = C(kMidnight); }
        else if (redeem) fg = on ? C(kMidnight) : C(kDim);
        if (checking) { bg.a *= 0.5f; fg.a *= 0.5f; }
        gfx.panel(x, ky, key_w, key_h, bg, 1.f, 20, on ? Color{0, 0, 0, 0} : kRim, on ? 0.f : 1.25f);
        ring(gfx, x, ky, key_w, key_h, 20, on ? 1.f : 0.f, true);
        const bool word = i == kKeyDelete || redeem;
        gfx.text_mid(word ? Font::Bold : Font::ExtraBold, word ? 22 : 34, x + key_w / 2, ky + key_h / 2, fg, Align::Center, kKeys[i]);
    }
    gfx.set_layer();
}

/* ---- Send logs, and the keyboard for its message (1.1.0) --------------------------- */
namespace
{
const char *const kKbRows[4] = {"1234567890-", "qwertyuiop'", "asdfghjkl?!", "zxcvbnm,./:"};
/* (2.0) For a source: what repo names and web addresses use. */
const char *const kKbUrlRows[4] = {"1234567890-", "qwertyuiop_", "asdfghjkl/.", "zxcvbnm:?=&"};
struct KbKey
{
    int start, len;
    const char *label;
};
const KbKey kKbBottom[4] = {{0, 2, "Shift"}, {2, 5, "Space"}, {7, 2, "Delete"}, {9, 2, "Done"}};
const KbKey kKbUrlBottom[5] = {{0, 2, "Shift"}, {2, 3, "https://"}, {5, 2, ".com"}, {7, 2, "Delete"}, {9, 2, "Done"}};
const std::size_t kMessageMax = 500;
/* The bottom row's keys in a mode, and how many. */
const KbKey *kb_bottom(int mode, int &n)
{
    n = mode == 1 ? 5 : 4;
    return mode == 1 ? kKbUrlBottom : kKbBottom;
}
const char *const *kb_rows(int mode) { return mode == 1 ? kKbUrlRows : kKbRows; }
int kb_key(int mode, int col)
{
    int n = 0;
    const KbKey *keys = kb_bottom(mode, n);
    for (int i = n - 1; i >= 0; --i)
        if (col >= keys[i].start) return i;
    return 0;
}
std::string kb_size(std::uint64_t b)
{
    return b >= (1u << 20) ? std::to_string(b >> 20) + " MB" : std::to_string(std::max<std::uint64_t>(1, (b + 512) >> 10)) + " KB";
}
} // namespace

void Store::open_logs(const std::string &id)
{
    logs_ = true;
    logs_t_ = 0;
    logs_app_ = id;
    logs_msg_.clear();
    logs_result_.clear();
    logs_sel_ = 0;
    logs_cat_ = -1; /* (2.3.0) what happened: picked first */
    logs_chip_ = 0;
    logs_fb_ = false;
    std::string m;
    logs_view_ = engine().logs_state(m) == 1 ? 1 : 0;
    App a;
    if (const App *f = find(id)) a = *f;
    else a.id = id;
    logs_files_ = game_log_files(a);
    kb_ = false;
    play(int(Sfx::Open));
}

bool Store::update_logs(float dt)
{
    logs_t_ = std::min(1.f, logs_t_ + dt * 5.f);
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (logs_view_ == 1 || logs_view_ == 2)
    {
        /* Sending (closing it leaves them going: how it went shows on the page), or sent. */
        if (pressed(pad::BtnCircle) || (logs_view_ == 2 && pressed(pad::BtnCross)))
        {
            logs_ = false;
            play(int(Sfx::Back));
        }
        return true;
    }
    if (pressed(pad::BtnCircle))
    {
        logs_ = false;
        play(int(Sfx::Back));
        return true;
    }
    /* (2.3.0) 0 what happened (the chips), 1 the message, 2 Cancel, 3 Send. */
    int sel = logs_sel_;
    if (sel == 0)
    {
        const int c = std::clamp(logs_chip_ + (r ? 1 : 0) - (l ? 1 : 0), 0, logs_fb_ ? 3 : 4);
        if (c != logs_chip_) { logs_chip_ = c; play(int(Sfx::Move)); }
        if (d) sel = 1;
    }
    else if (sel == 1)
    {
        if (u) sel = 0;
        if (d) sel = 3;
    }
    else
    {
        if (u) sel = 1;
        if (l) sel = 2;
        if (r) sel = 3;
    }
    if (sel != logs_sel_) { logs_sel_ = sel; play(int(Sfx::Move)); }
    static const char *kCats[5] = {"bug", "crash", "install", "slow", "other"};
    static const char *kVerdicts[4] = {"works", "crashes", "slow", "other"}; /* (2.3.0) a tester's Feedback */
    if (pressed(pad::BtnCross))
    {
        if (logs_sel_ == 0)
        {
            logs_cat_ = logs_chip_;
            logs_sel_ = 1;
            play(int(Sfx::Select));
        }
        else if (logs_sel_ == 1)
            open_kb(0);
        else if (logs_sel_ == 2)
        {
            logs_ = false;
            play(int(Sfx::Back));
        }
        else if (!logs_ready())
        {
            logs_view_ = 0;
            logs_sel_ = logs_cat_ < 0 ? 0 : 1;
            play(int(Sfx::Error));
        }
        else if (logs_fb_ ? engine().send_logs(logs_app_, logs_msg_, "", kVerdicts[std::clamp(logs_cat_, 0, 3)], inst_v(logs_app_))
                          : engine().send_logs(logs_app_, logs_msg_, kCats[std::clamp(logs_cat_, 0, 4)]))
        {
            logs_view_ = 1;
            logs_result_.clear();
            play(int(Sfx::Select));
        }
        else
            play(int(Sfx::Error));
    }
    return true;
}

/* (2.3.0) Send logs is ready: what happened is picked, and the message has 3 characters that aren't spaces. */
bool Store::logs_ready() const
{
    int n = 0;
    for (const char c : logs_msg_) n += c != ' ' && c != '\n' && c != '\t';
    return logs_cat_ >= 0 && n >= 3;
}

bool Store::update_keyboard(float dt)
{
    kb_t_ = std::min(1.f, kb_t_ + dt * 6.f);
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    /* (2.0) The same keyboard types a message, a source, or why a source is reported (kb_mode_). */
    std::string &text = kb_text();
    const bool url = kb_mode_ == 1;
    int nkeys = 0;
    const KbKey *keys = kb_bottom(kb_mode_, nkeys);
    if (kb_mode_ != 0 && pressed(pad::BtnCircle))
    {
        /* (2.0) A source or a report: Circle backs out without adding or sending (what's typed
         * stays, for next time). Done, Options or R2 finish it. */
        kb_ = false;
        play(int(Sfx::Back));
        return true;
    }
    if (pressed(pad::BtnCircle) || pressed(pad::BtnOptions) || pressed(pad::BtnR2))
    {
        kb_finished();
        return true;
    }
    int row = std::clamp(kb_row_ + (d ? 1 : 0) - (u ? 1 : 0), 0, 4), col = kb_col_;
    if (row == 4)
    {
        const int k = kb_key(kb_mode_, col);
        if (l && k > 0) col = keys[k - 1].start;
        if (r && k < nkeys - 1) col = keys[k + 1].start;
    }
    else
        col = std::clamp(col + (r ? 1 : 0) - (l ? 1 : 0), 0, 10);
    if (row != kb_row_ || col != kb_col_) { kb_row_ = row; kb_col_ = col; play(int(Sfx::Move)); }
    auto type = [&](const std::string &c) {
        if (text.size() + c.size() > kMessageMax) { play(int(Sfx::Error)); return; }
        /* A capital after the end of a sentence (not in an address). */
        if (!url && c == " " && !text.empty() && std::strchr(".?!", text.back())) kb_caps_ = true;
        text += c;
        play(int(Sfx::Select));
    };
    auto erase = [&] {
        if (text.empty()) { play(int(Sfx::Error)); return; }
        text.pop_back();
        kb_caps_ = !url && text.empty();
        play(int(Sfx::Move));
    };
    if (pressed(pad::BtnSquare))
        erase();
    else if (pressed(pad::BtnTriangle))
        type(url ? "/" : " ");
    else if (pressed(pad::BtnL2))
    {
        kb_caps_ = !kb_caps_;
        play(int(Sfx::Select));
    }
    else if (pressed(pad::BtnCross))
    {
        if (kb_row_ < 4)
        {
            char c = kb_rows(kb_mode_)[kb_row_][kb_col_];
            if (kb_caps_ && c >= 'a' && c <= 'z')
            {
                c = char(c - 'a' + 'A');
                kb_caps_ = false;
            }
            type(std::string(1, c));
        }
        else
        {
            const std::string label = keys[kb_key(kb_mode_, kb_col_)].label;
            if (label == "Shift") { kb_caps_ = !kb_caps_; play(int(Sfx::Select)); }
            else if (label == "Space") type(" ");
            else if (label == "Delete") erase();
            else if (label == "Done") kb_finished();
            else type(label); /* https://, .com */
        }
    }
    return true;
}

void Store::draw_logs(Gfx &gfx)
{
    if (!logs_)
        return;
    const App *a = find(logs_app_);
    const std::string name = a ? a->name : logs_app_;
    const float k = ease_out(logs_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 860, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const std::size_t game_files = logs_files_.empty() ? 0 : logs_files_.size() - 1;
    const std::string body = logs_fb_ ? "Tells the maker how this build" + (inst_v(logs_app_).empty() ? std::string() : " (" + inst_v(logs_app_) + ")") + " runs on your PS5. Your logs go with it, so they can see what happened." : "Sends the store's log" +
                             (game_files ? " and " + name + "'s log " + (game_files == 1 ? "file" : "files") : std::string()) +
                             " to Recomp Store, so we can see what went wrong. Your saves and game files aren't sent.";
    const auto lines = wrap(gfx, Font::Regular, 20, iw, body, 4);
    std::vector<std::string> files;
    for (std::size_t i = 0; i < logs_files_.size() && i < 4; ++i)
        files.push_back(logs_files_[i].name + "  \xC2\xB7  " + kb_size(logs_files_[i].size));
    if (logs_files_.size() > 4) files.push_back("and " + std::to_string(logs_files_.size() - 4) + " more");
    const auto msg = logs_msg_.empty() ? std::vector<std::string>{} : wrap(gfx, Font::Regular, 21, iw - 48, logs_msg_, 3);
    const float msg_h = 28 + 16 + std::max(1.f, float(msg.size())) * 31 + 28;
    std::string result;
    Color result_c = C(kMuted);
    if (logs_view_ == 1 && engine().job().phase == Phase::Working)
        result = "Sending once the download that's running is done\xE2\x80\xA6";
    else if (logs_view_ == 2 || logs_view_ == 3)
    {
        result = logs_result_;
        result_c = logs_view_ == 2 ? C(0x9FF0C6) : C(0xFFB4A8);
    }
    /* (2.0) Sent: the report to finish on a phone (what happened, pictures), as a QR code. */
    std::string report_url;
    const std::string report = logs_view_ == 2 ? engine().logs_report(report_url) : std::string();
    const float report_h = report.empty() ? 0.f : 30 + 200;
    const bool ready = logs_ready();
    const bool asking = logs_view_ == 0 || logs_view_ == 3;
    const float chips_h = 22 + 10 + 52 + 26; /* (2.3.0) what happened */
    const float hint_h = asking && !ready ? 14 + 22 : 0;
    const float bh = pad + 44 + 14 + 30 * float(lines.size()) + 18 + 27 * float(files.size()) + 26 + chips_h + 22 + 10 + msg_h + hint_h +
                     (result.empty() ? 0.f : 18 + 26) + report_h + 30 + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 22, C(kFrost), Align::Left, logs_fb_ ? "Tester feedback" : "Send logs");
    y += 44 + 14;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines) + 18;
    for (const std::string &f : files)
    {
        gfx.text_mid(Font::Regular, 17, bx + pad, y + 13, C(kDim), Align::Left, fit(gfx, Font::Regular, 17, iw, f));
        y += 27;
    }
    y += 26;
    {
        /* (2.3.0) What happened: one of five, picked first. */
        gfx.text_mid(Font::Bold, 15, bx + pad, y + 11, C(kCyan), Align::Left, logs_fb_ ? "HOW THIS BUILD RUNS" : "WHAT HAPPENED", 2);
        y += 22 + 10;
        static const char *kNames[5] = {"Bug", "Crash", "Won't install", "Slow", "Other"};
        static const char *kVerdicts[4] = {"Works", "Crashes", "Slow", "Other"}; /* (2.3.0) a tester's */
        const int nc = logs_fb_ ? 4 : 5;
        const float gap = 12, cw = (iw - gap * float(nc - 1)) / float(nc);
        for (int i = 0; i < nc; ++i)
        {
            const float x = bx + pad + float(i) * (cw + gap);
            const bool picked = logs_cat_ == i, on = asking && logs_sel_ == 0 && logs_chip_ == i;
            gfx.panel(x, y, cw, 52, picked ? C(kCyan) : C(kFrost, on ? 0.16f : 0.07f), 1.f, 26, picked || on ? Color{0, 0, 0, 0} : kRim, picked || on ? 0.f : 1.25f);
            ring(gfx, x, y, cw, 52, 26, on ? 1.f : 0.f, true);
            gfx.text_mid(Font::SemiBold, 19, x + cw / 2, y + 26, picked ? C(kMidnight) : C(kFrost), Align::Center, logs_fb_ ? kVerdicts[i] : kNames[i]);
        }
        y += 52 + 26;
    }
    gfx.text_mid(Font::Bold, 15, bx + pad, y + 11, C(kCyan), Align::Left, "YOUR MESSAGE", 2);
    y += 22 + 10;
    {
        const bool on = logs_view_ != 1 && logs_view_ != 2 && logs_sel_ == 1;
        gfx.panel(bx + pad, y, iw, msg_h, C(kFrost, on ? 0.12f : 0.06f), 1.f, 20, on ? Color{0, 0, 0, 0} : kRim, on ? 0.f : 1.25f);
        ring(gfx, bx + pad, y, iw, msg_h, 20, on ? 1.f : 0.f, true);
        if (msg.empty())
            gfx.text_mid(Font::Regular, 21, bx + pad + 24, y + msg_h / 2, C(kDim), Align::Left,
                         "What were you doing when it went wrong?");
        else
            draw_lines(gfx, Font::Regular, 21, bx + pad + 24, y + 28, 31, C(kFrost), msg);
        y += msg_h;
    }
    if (hint_h > 0)
    {
        y += 14;
        gfx.text_mid(Font::SemiBold, 18, bx + pad, y + 11, C(kAmber), Align::Left, "Pick what happened and write a few words");
        y += 22;
    }
    if (!result.empty())
    {
        y += 18;
        gfx.text_mid(Font::SemiBold, 19, bx + bw / 2, y + 13, result_c, Align::Center, fit(gfx, Font::SemiBold, 19, iw, result));
        y += 26;
    }
    if (!report.empty())
    {
        y += 30;
        draw_qr(gfx, report_url, bx + pad, y, 200);
        const float tx = bx + pad + 200 + 32, tw = iw - 200 - 32;
        gfx.text_mid(Font::Bold, 15, tx, y + 18, C(kCyan), Align::Left, "ADD PICTURES FROM YOUR PHONE", 2);
        float ty = y + 40;
        ty += draw_lines(gfx, Font::Regular, 19, tx, ty, 28.5f, C(kSoft),
                         wrap(gfx, Font::Regular, 19, tw, "Scan this to tell us more and add screenshots or photos. Or go to recompstore.com/r and type:", 3)) + 10;
        const std::string code = report.size() == 6 ? report.substr(0, 3) + "-" + report.substr(3) : report;
        gfx.text_mid(Font::ExtraBold, 40, tx, ty + 24, C(kFrost), Align::Left, code, 2);
        y += 200;
    }
    y += 30;
    if (logs_view_ == 2)
    {
        gfx.panel(bx + pad, y, iw, 64, C(kFrost), 1.f, 20);
        ring(gfx, bx + pad, y, iw, 64, 20, 1.f, true);
        gfx.text_mid(Font::Bold, 22, bx + bw / 2, y + 32, C(kMidnight), Align::Center, "Done");
    }
    else
    {
        const float gap = 16, w2 = (iw - gap) / 2;
        const bool sending = logs_view_ == 1;
        const char *labels[2] = {"Cancel", sending ? "Sending\xE2\x80\xA6" : logs_view_ == 3 ? "Try again" : "Send"};
        for (int i = 0; i < 2; ++i)
        {
            const float x = bx + pad + float(i) * (w2 + gap);
            const bool on = !sending && logs_sel_ == i + 2;
            Color bg = i == 1 ? C(kCyan, on ? 1.f : 0.3f) : C(kFrost, on ? 1.f : 0.12f);
            if (sending || (i == 1 && !ready)) bg.a *= 0.4f; /* (2.3.0) Send stays off until it's ready */
            gfx.panel(x, y, w2, 64, bg, 1.f, 20);
            ring(gfx, x, y, w2, 64, 20, on ? 1.f : 0.f, true);
            gfx.text_mid(Font::Bold, 22, x + w2 / 2, y + 32, on ? C(kMidnight) : C(kFrost, sending ? 0.6f : 1.f), Align::Center, labels[i]);
        }
    }
    gfx.set_layer();
}

void Store::draw_keyboard(Gfx &gfx)
{
    if (!kb_)
        return;
    const float k = ease_out(kb_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.6f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 1240, bx = (W - bw) / 2, pad = 44, iw = bw - pad * 2;
    const float gap = 10, kw = (iw - gap * 10) / 11, kh = 72, field_h = 3 * 34 + 40;
    const float bh = pad + 40 + 18 + field_h + 26 + 5 * kh + 4 * gap + pad;
    const float by = H - 60 - bh;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.98f), true);
    float y = by + pad;
    const std::string &text = kb_text();
    const char *title = kb_mode_ == 1 ? "A GitHub repo, or a list's address" : kb_mode_ == 2 ? "What's wrong with this source?"
                        : kb_mode_ == 3 ? "Search the store" : "Your message";
    const char *empty = kb_mode_ == 1 ? "owner/name, or https://\xE2\x80\xA6"
                        : kb_mode_ == 2 ? "It's broken, it isn't what it says, or it shouldn't be here\xE2\x80\xA6"
                        : kb_mode_ == 3 ? "A name, a maker or a label"
                                        : "What were you doing when it went wrong?";
    gfx.text_mid(Font::ExtraBold, 30, bx + pad, y + 20, C(kFrost), Align::Left, title);
    if (kb_mode_ == 3)
        gfx.text_mid(Font::Regular, 18, bx + bw - pad, y + 20, C(kDim), Align::Right,
                     engine().link_info().devices.empty() ? std::string("Done shows the results")
                                                          : std::string("Or type it on your phone: My PS5 > Type on your PS5")); /* (2.3.0) */
    else
        gfx.text_mid(Font::Regular, 18, bx + bw - pad, y + 20, text.size() >= kMessageMax ? C(0xFFB4A8) : C(kDim), Align::Right,
                     std::to_string(text.size()) + " / " + std::to_string(kMessageMax));
    y += 40 + 18;
    {
        /* The text, its last three lines, and where the next letter goes. An address breaks
         * anywhere, not only at spaces. */
        gfx.panel(bx + pad, y, iw, field_h, C(kFrost, 0.06f), 1.f, 20, kRim, 1.25f);
        std::vector<std::string> all;
        if (kb_mode_ == 1)
        {
            std::string line;
            for (const char ch : text)
            {
                if (gfx.measure(Font::Regular, 24, line + ch) > iw - 60) { all.push_back(line); line.clear(); }
                line += ch;
            }
            if (!line.empty() || all.empty()) all.push_back(line);
        }
        else
            all = wrap(gfx, Font::Regular, 24, iw - 60, text, 100);
        if (all.size() > 3) all.erase(all.begin(), all.end() - 3);
        const float tx = bx + pad + 24, ty = y + 20;
        gfx.clip(bx + pad + 8, y + 4, iw - 16, field_h - 8); /* a word longer than a line runs out of sight */
        if (text.empty())
            gfx.text_mid(Font::Regular, 24, tx, ty + 17, C(kDim), Align::Left, empty);
        else
            draw_lines(gfx, Font::Regular, 24, tx, ty, 34, C(kFrost), all);
        const std::string last = all.empty() ? std::string() : all.back();
        float cx = tx + gfx.measure(Font::Regular, 24, last) + (!text.empty() && text.back() == ' ' ? gfx.measure(Font::Regular, 24, " ") : 0);
        const float cy = ty + 34 * float(std::max<std::size_t>(1, all.size()) - 1);
        if (text.empty()) cx = tx;
        if (std::fmod(time_, 1.f) < 0.6f)
            gfx.panel(cx + 1, cy + 3, 2.5f, 28, C(kCyan), 1.f, 1);
        gfx.unclip();
        y += field_h + 26;
    }
    for (int row = 0; row < 5; ++row)
    {
        const float ky = y + float(row) * (kh + gap);
        if (row < 4)
            for (int col = 0; col < 11; ++col)
            {
                const float kx = bx + pad + float(col) * (kw + gap);
                const bool on = kb_row_ == row && kb_col_ == col;
                char c = kb_rows(kb_mode_)[row][col];
                if (kb_caps_ && c >= 'a' && c <= 'z') c = char(c - 'a' + 'A');
                gfx.panel(kx, ky, kw, kh, C(kFrost, on ? 1.f : 0.08f), 1.f, 16, on ? Color{0, 0, 0, 0} : kRim, on ? 0.f : 1.25f);
                ring(gfx, kx, ky, kw, kh, 16, on ? 1.f : 0.f, true);
                gfx.text_mid(Font::Bold, 28, kx + kw / 2, ky + kh / 2, on ? C(kMidnight) : C(kFrost), Align::Center, std::string(1, c));
            }
        else
        {
            int nkeys = 0;
            const KbKey *keys = kb_bottom(kb_mode_, nkeys);
            for (int i = 0; i < nkeys; ++i)
            {
                const KbKey &key = keys[i];
                const float kx = bx + pad + float(key.start) * (kw + gap), w = float(key.len) * kw + float(key.len - 1) * gap;
                const bool on = kb_row_ == 4 && kb_key(kb_mode_, kb_col_) == i;
                Color bg = C(kFrost, on ? 1.f : 0.08f), fg = on ? C(kMidnight) : C(kFrost);
                if (i == 0 && kb_caps_ && !on) { bg = C(kFrost, 0.3f); }
                if (i == nkeys - 1) { bg = C(kCyan, on ? 1.f : 0.85f); fg = C(kMidnight); }
                const bool last = i == nkeys - 1;
                gfx.panel(kx, ky, w, kh, bg, 1.f, 16, on || last ? Color{0, 0, 0, 0} : kRim, on || last ? 0.f : 1.25f);
                ring(gfx, kx, ky, w, kh, 16, on ? 1.f : 0.f, true);
                gfx.text_mid(Font::Bold, 22, kx + w / 2, ky + kh / 2, fg, Align::Center, key.label);
            }
        }
    }
    gfx.set_layer();
}

/* ---- (2.2.1) The console's storage, gone ------------------------------------------------------ */
bool Store::update_lost(float dt)
{
    lost_t_ = std::min(1.f, lost_t_ + dt * 4.f);
    if (pressed(pad::BtnCross) || pressed(pad::BtnCircle))
    {
        lost_ = false;
        play(int(Sfx::Back));
    }
    return true;
}

void Store::draw_lost(Gfx &gfx)
{
    if (!lost_)
        return;
    const float k = ease_out(lost_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 820, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const auto lines = wrap(gfx, Font::Bold, 26, iw, kStorageLost, 5);
    const float bh = pad + 38 * float(lines.size()) + 36 + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    y += draw_lines(gfx, Font::Bold, 26, bx + pad, y, 38, C(kFrost), lines) + 36;
    gfx.panel(bx + pad, y, iw, 64, C(kFrost), 1.f, 20);
    ring(gfx, bx + pad, y, iw, 64, 20, 1.f, true);
    gfx.text_mid(Font::Bold, 22, bx + pad + iw / 2, y + 32, C(kMidnight), Align::Center, "OK");
    gfx.set_layer();
}

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
    const float bh = pad + 40 * float(title.size()) + 18 + 30 * float(lines.size()) + 36 + (saves ? 56 + 24 : 0) + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    for (std::size_t i = 0; i < title.size(); ++i)
        gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 40 * (float(i) + 0.5f), C(kFrost), Align::Left, title[i]);
    y += 40 * float(title.size()) + 18;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines) + 36;
    if (saves)
    {
        /* (2.3.0) Back up saves first: Triangle turns it on or off. */
        glass(gfx, bx + pad, y, iw, 56, 18, Color{1, 1, 1, 0.05f}, false);
        gfx.text_mid(Font::SemiBold, 19, bx + pad + 22, y + 28, C(kFrost), Align::Left, "Back up saves first");
        gfx.text_mid(Font::Regular, 16, bx + pad + 22 + gfx.measure(Font::SemiBold, 19, "Back up saves first") + 14, y + 28, C(kMuted), Align::Left,
                     "Triangle turns it on or off");
        draw_switch(gfx, bx + pad + iw - 22 - 52, y + 28, backup_first_, false);
        y += 56 + 24;
    }
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

/* ---- The title ID guard's question (2.0) -------------------------------------------- */
std::vector<int> Store::clash_choices() const
{
    std::vector<int> out;
    const App *a = find(clash_job_.app_id);
    if (a && a->can_move && !clash_job_.clash_new.empty()) out.push_back(1); /* Keep both */
    if (!clash_job_.clash_pkg) out.push_back(2);                              /* Install over it */
    out.push_back(0);                                                         /* Cancel */
    return out;
}

bool Store::update_clash(float dt)
{
    clash_t_ = std::min(1.f, clash_t_ + dt * 5.f);
    if (!find(clash_job_.app_id))
    {
        /* (2.0) The app left the catalog while the question was up: nothing to answer. */
        engine().resolve_clash(clash_job_.app_id, 0);
        clash_ = false;
        return true;
    }
    const auto ch = clash_choices();
    const int last = int(ch.size()) - 1;
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const int sel = std::clamp(clash_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, last);
    if (sel != clash_sel_) { clash_sel_ = sel; play(int(Sfx::Move)); }
    int choice = -1;
    if (pressed(pad::BtnCircle))
        choice = 0;
    else if (pressed(pad::BtnCross))
        choice = ch[std::size_t(std::clamp(clash_sel_, 0, last))];
    if (choice < 0)
        return true;
    clash_ = false;
    const std::string id = clash_job_.app_id;
    if (choice == 0)
    {
        engine().resolve_clash(id, 0);
        notice_ = "Not installed. Nothing on your PS5 was changed.";
        notice_bad_ = false;
        play(int(Sfx::Back));
    }
    else if (engine().resolve_clash(id, choice, clash_job_.version, clash_job_.root)) /* (2.1) the version picked, (2.2.2) and place */
    {
        notice_.clear();
        page_focus_ = 0;
        play(int(Sfx::Install));
    }
    else
        play(int(Sfx::Error));
    return true;
}

void Store::draw_clash(Gfx &gfx)
{
    if (!clash_)
        return;
    const App *a = find(clash_job_.app_id);
    if (!a)
        return;
    const float k = ease_out(clash_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 880, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const std::string other = clash_job_.clash_with.empty() ? std::string("Another app") : clash_job_.clash_with;
    const std::string &fresh = clash_job_.clash_new;
    const std::string tid = clash_job_.clash_tid.empty() ? a->id : clash_job_.clash_tid; /* (2.0) a custom app's real one */
    const auto ch = clash_choices();
    const bool keep_both = !ch.empty() && ch[0] == 1;
    std::string body;
    if (clash_job_.clash_pkg)
        body = other + " is installed on your PS5 as a package with " + a->name + "'s title ID, " + tid + ". Two apps can't share one.";
    else
        body = other + " already uses " + a->name + "'s title ID, " + tid + ", on your PS5. Installing " + a->name +
               " as it is would write over it.";
    if (keep_both)
        body += " Keep both installs " + a->name + " as its own app under " + fresh + ". Its updates go there too, and " +
                (clash_job_.clash_with.empty() ? std::string("the other app") : clash_job_.clash_with) + " stays as it is.";
    else if (clash_job_.clash_pkg)
        body += " " + a->name + " has to keep its own title ID, so delete the other app first, then install it.";
    else
        body += " " + a->name + " has to keep its own title ID, so delete the other app first, or install over it.";
    if (!clash_job_.clash_pkg)
        body += " Install over it only if it's an older copy of " + a->name + ".";
    const auto title = wrap(gfx, Font::ExtraBold, 32, iw, "Another app has this title ID", 2);
    const auto lines = wrap(gfx, Font::Regular, 20, iw, body, 8);
    const float chip_h = keep_both ? 44 + 24 : 0;
    const float bh = pad + 40 * float(title.size()) + 18 + 30 * float(lines.size()) + 24 + chip_h + 12 + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    for (std::size_t i = 0; i < title.size(); ++i)
        gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 40 * (float(i) + 0.5f), C(kFrost), Align::Left, title[i]);
    y += 40 * float(title.size()) + 18;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines) + 24;
    if (keep_both)
    {
        /* The move at a glance: its own ID, taken, and the one it gets. */
        auto chip = [&](float x, const std::string &t, bool on) {
            const float w = gfx.measure(Font::Bold, 20, t) + 36;
            gfx.panel(x, y, w, 44, on ? C(kCyan, 0.16f) : C(kFrost, 0.08f), 1.f, 22);
            if (on) ring(gfx, x, y, w, 44, 22, 0.55f);
            gfx.text_mid(Font::Bold, 20, x + w / 2, y + 22, on ? C(kCyan) : C(kMuted), Align::Center, t);
            return w;
        };
        float x = bx + pad;
        x += chip(x, tid, false) + 14;
        gfx.glyph(Glyph::Arrow, x + 12, y + 22, 13, C(kMuted), 1.5708f);
        x += 24 + 14;
        chip(x, fresh, true);
        y += chip_h;
    }
    y += 12;
    const int n = int(ch.size());
    const float gap = 16, w2 = (iw - gap * float(n - 1)) / float(n);
    for (int i = 0; i < n; ++i)
    {
        const float x = bx + pad + float(i) * (w2 + gap);
        const bool on = clash_sel_ == i;
        const int c = ch[std::size_t(i)];
        const char *label = c == 1 ? "Keep both" : c == 2 ? "Install over it" : "Cancel";
        gfx.panel(x, y, w2, 64, C(kFrost, on ? 1.f : 0.12f), 1.f, 20);
        ring(gfx, x, y, w2, 64, 20, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 22, x + w2 / 2, y + 32, on ? C(kMidnight) : C(kFrost), Align::Center, label);
    }
    gfx.set_layer();
}

/* (2.0) Refresh, at the top right: a turning ring of dots while it reads, then how it went. */
void Store::draw_refresh(Gfx &gfx)
{
    if (!refresh_shown_)
        return;
    const int st = refresh_preview_ ? refresh_preview_ : engine().update_check();
    const float fade = st == 1 ? 1.f : std::clamp((3.f - refresh_t_) / 0.5f, 0.f, 1.f);
    if (fade <= 0)
        return;
    const char *label = st == 1 ? "Refreshing\xE2\x80\xA6" : st == 3 ? "Couldn't reach GitHub" : "Up to date";
    const float tw = gfx.measure(Font::SemiBold, 18, label);
    /* (2.2.3) Beside the downloads pill (left of it), not under it over what's below */
    const float w = 20 + 22 + 12 + tw + 22, h = 44;
    float x = (dl_pill_x_ < 1920 - 96 - 1 ? dl_pill_x_ - 12 : 1920 - 96) - w, cy = 76;
    if (x < tabs_right_ + 16) { x = 1920 - 96 - w; cy = 76 + 56; } /* no room beside it (many tabs): under it */
    glass(gfx, x, cy - h / 2, w, h, h / 2, Color{kGlass.r, kGlass.g, kGlass.b, kGlass.a * fade}, fade > 0.5f);
    const float dx = x + 20 + 11;
    if (st == 1)
        for (int i = 0; i < 8; ++i)
        {
            const float ang = float(i) * 0.785398f;
            const float lit = std::fmod(time_ * 1.4f - float(i) / 8.f + 8.f, 1.f);
            gfx.panel(dx + std::cos(ang) * 8.5f - 2.5f, cy + std::sin(ang) * 8.5f - 2.5f, 5, 5, C(kCyan, (0.25f + 0.75f * lit) * fade), 1.f, 2.5f);
        }
    else
        gfx.panel(dx - 6, cy - 6, 12, 12, st == 3 ? C(0xFFB4A8, fade) : C(kGreen, fade), 1.f, 6);
    gfx.text_mid(Font::SemiBold, 18, dx + 11 + 12, cy, C(kFrost, fade), Align::Left, label);
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
    /* (2.2.2) A button drawn as its glyph (Options, L2/R2). */
    auto hint_glyph = [&](Glyph g, const char *label) {
        const float w = gfx.measure(Font::Regular, 19, label);
        gfx.text_mid(Font::Regular, 19, x - w, cy, C(0xD6DEEA), Align::Left, label);
        x -= w + 10 + 26;
        gfx.glyph(g, x + 13, cy, 26, C(0xD6DEEA));
        x -= 34;
    };
    const bool deeper = page_open_ || picker_;
    if (lost_)
    {
        hint(hint_cross_, "OK"); /* (2.2.1) */
        return;
    }
    if (panel_)
    {
        /* (2.2.2) */
        hint(hint_circle_, "Close");
        const std::string c = panel_cross_hint();
        if (!c.empty()) hint(hint_cross_, c.c_str());
        if (panel_ == PanelTabs) hint_glyph(Glyph::Square, "Hold to move");
        return;
    }
    if (kb_)
    {
        hint(hint_circle_, kb_mode_ == 0 ? "Done" : "Back"); /* (2.0) a source or a report: Done is on the keyboard */
        hint(hint_cross_, "Type");
        return;
    }
    if (logs_)
    {
        hint(hint_circle_, logs_view_ == 2 ? "Close" : "Cancel");
        if (logs_view_ != 1) hint(hint_cross_, "Choose");
        return;
    }
    if (keypad_)
    {
        hint(hint_circle_, key_view_ == 0 ? "Cancel" : "Close");
        if (key_view_ != 1) hint(hint_cross_, key_view_ == 2 ? "Choose" : "Press");
        return;
    }
    if (confirm_ || clash_ || moved_ || (vers_ && vers_ask_))
    {
        hint(hint_circle_, "Cancel");
        hint(hint_cross_, "Choose");
        return;
    }
    if (mng_)
    {
        hint(hint_circle_, mng_stage_ == 0 ? "Close" : "Back"); /* (2.2) */
        hint(hint_cross_, "Choose");
        return;
    }
    if (vers_)
    {
        /* (2.1) Versions: X asks first, then installs the one picked. */
        hint(hint_circle_, "Close");
        int state = 0;
        if (const App *va = find(vers_app_); va && !version_rows(*va, state).empty()) hint(hint_cross_, "Install");
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
    if (page_open_ && !picker_ && page_rel_ >= 0)
    {
        /* (2.2.2) The rows under the page. */
        hint(hint_circle_, "Back");
        hint(hint_cross_, "Open");
        const App *ra = nullptr;
        if (const App *pa = find(page_id_))
        {
            const auto rows = related_rows(*pa);
            if (page_rel_ < int(rows.size()) && !rows[std::size_t(page_rel_)].apps.empty())
                ra = rows[std::size_t(page_rel_)].apps[std::size_t(std::clamp(page_rel_col_, 0, int(rows[std::size_t(page_rel_)].apps.size()) - 1))];
        }
        if (ra) hint(hint_square_, pinned(ra->id) ? "Remove favorite" : "Favorite");
        return;
    }
    hint(hint_circle_, deeper ? (page_open_ && !page_back_.empty() ? "Back" : "Back") : (con_select_ && tab_ == Library ? "Done" : "Close"));
    if (picker_)
        hint(hint_cross_, "Choose");
    else if (page_open_)
        hint(hint_cross_, "Select");
    else
    {
        const std::string c = cross_hint();
        if (!c.empty()) hint(hint_cross_, c.c_str());
    }
    if (!deeper && tab_ != Settings)
    {
        hint(hint_triangle_, "Refresh"); /* (2.0) */
        const std::string sq = square_hint();
        if (!sq.empty()) hint(hint_square_, sq.c_str());
    }
    else if (page_open_ && !picker_)
    {
        if (const App *pa = find(page_id_); pa && !pa->soon) hint(hint_square_, pinned(pa->id) ? "Remove favorite" : "Favorite"); /* (2.2.2) */
    }
    if (downloads_shown() && !picker_)
        hint_glyph(Glyph::Options, "Downloads"); /* (2.2.2) */
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
    gfx.set_layer((1 - k) * (prefs_.reduced_motion ? 0.f : 160.f) * float(tab_dir_), 0, k);
    switch (tab_)
    {
    case Discover: draw_discover(gfx); break;
    case Library: case Updates: draw_library2(gfx); break; /* (2.0) My Apps, Updates, Betas */
    case Games: case Emulators: case Apps: case Tools: draw_category(gfx); break;
    default:
        if (is_category(tab_)) draw_category(gfx); /* (2.2.2) a source's tab */
        else draw_settings(gfx);
        break;
    }
    gfx.set_layer();
    draw_downloads(gfx); /* (2.2.2) over what's under the bar, as the toast is */
    draw_refresh(gfx);   /* (2.0) */
    draw_page(gfx);
    draw_picker(gfx);
    draw_confirm(gfx);
    draw_clash(gfx); /* (2.0) */
    draw_moved(gfx);    /* (2.1) */
    draw_versions(gfx); /* (2.1) */
    draw_sheet(gfx);  /* (2.0) */
    draw_manage(gfx); /* (2.2) */
    draw_panel(gfx);  /* (2.2.2) */
    draw_keypad(gfx);
    draw_logs(gfx);
    draw_keyboard(gfx);
    draw_lost(gfx); /* (2.2.1) */
    draw_hint_band(gfx); /* (2.2.3) */
    draw_hints(gfx);
    draw_toast(gfx); /* (2.0) */
}
} // namespace recomp
