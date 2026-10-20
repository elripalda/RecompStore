/* Recomp Store 2.1 - Versions on an app's page (an older release or beta build to go back to,
 * kept at it until a newer one is installed), a beta's notes for each of its builds, and the
 * question when the place an app downloads from changed.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_ui.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"

#include <algorithm>
#include <cmath>

namespace recomp
{
using namespace porpoise::ui;
using namespace sui;
namespace pad = porpoise::pad;
using Sfx = porpoise::sound::Effect;

namespace
{
const int kVersShown = 7;      /* rows in view at once */
const float kVersRowH = 74;    /* each row */
}

/* ---- What there is to pick from ------------------------------------------------------- */

bool Store::has_versions(const App &a) const
{
    /* A GitHub app's releases, or a beta's builds; never one this store is too old for. */
    if (a.soon || store_too_old(a))
        return false;
    if (a.beta)
        return !a.url.empty();
    return a.source == "github" && !a.repo.empty() && !a.asset.empty();
}

std::vector<Store::VersionRow> Store::version_rows(const App &a, int &state) const
{
    std::vector<VersionRow> out;
    const std::string inst = engine().installed(a.id);
    auto same = [&](const std::string &v) { return !inst.empty() && inst != "?" && version_key(v) == version_key(inst); };
    if (a.beta)
    {
        /* The current build, then the earlier ones as the site lists them (newest first). Only
         * one is the installed one: the current, unless the app is kept at an earlier build. */
        state = 1;
        VersionRow cur;
        cur.label = a.version.empty() ? std::string("Current build") : a.version.substr(version_start(a.version));
        cur.size = a.size;
        cur.newest = true;
        cur.installed = same(a.version) && kept_at(a).empty();
        bool marked = cur.installed;
        out.push_back(cur);
        for (const BetaBuild &b : a.beta_builds)
        {
            VersionRow r;
            r.key = b.file.empty() ? b.version : b.file;
            r.label = b.version.substr(version_start(b.version));
            r.date = long_date(b.date);
            r.size = b.size;
            r.installed = !marked && same(b.version);
            marked = marked || r.installed;
            out.push_back(r);
        }
        return out;
    }
    /* A GitHub app: the releases the store has (newest first) that have its file. */
    std::vector<Release> rel;
    state = a.source == "github" && !a.repo.empty() ? engine().notes(a.repo, rel) : -1;
    for (const Release &x : rel)
    {
        bool has = false;
        for (const std::string &n : x.assets)
            has = has || asset_matches(a.asset, n);
        if (!has || x.tag.empty())
            continue;
        VersionRow r;
        r.key = x.tag;
        r.label = x.tag.substr(version_start(x.tag));
        r.date = long_date(x.date);
        r.pre = x.prerelease;
        r.newest = out.empty();
        r.installed = same(x.tag);
        out.push_back(r);
    }
    return out;
}

int Store::page_notes(const App &a, std::vector<Release> &out) const
{
    out.clear();
    if (a.beta)
    {
        /* A beta's builds as releases: what's new in each, its current build first. */
        bool any = !a.beta_notes.empty();
        Release cur;
        cur.tag = cur.name = a.version;
        cur.body = a.beta_notes;
        out.push_back(cur);
        for (const BetaBuild &b : a.beta_builds)
        {
            Release r;
            r.tag = r.name = b.version;
            r.date = b.date;
            r.body = b.notes;
            any = any || !b.notes.empty();
            out.push_back(r);
        }
        if (!any)
            out.clear();
        return any ? 1 : -1;
    }
    return a.source == "github" && !a.repo.empty() ? engine().notes(a.repo, out) : -1;
}

/* ---- Versions -------------------------------------------------------------------------- */

void Store::open_versions(const App &a)
{
    vers_ = true;
    vers_t_ = 0;
    vers_app_ = a.id;
    vers_ask_ = false;
    vers_top_ = vers_sel_ = 0;
    if (a.source == "github" && !a.repo.empty())
        engine().want_notes(a.repo); /* GitHub's newest list, if the last is old */
    /* On the one installed, so what's around it is in view. */
    int state = 0;
    const auto rows = version_rows(a, state);
    for (int i = 0; i < int(rows.size()); ++i)
        if (rows[std::size_t(i)].installed) { vers_sel_ = i; break; }
    play(int(Sfx::Open));
}

void Store::preview_versions(int sel, bool ask, int ask_sel)
{
    const App *a = find(page_id_);
    if (!a)
        return;
    open_versions(*a);
    vers_t_ = 1;
    vers_sel_ = sel;
    int state = 0;
    const auto rows = version_rows(*a, state);
    if (ask && sel >= 0 && sel < int(rows.size()))
    {
        vers_pick_ = rows[std::size_t(sel)];
        vers_ask_ = true;
        vers_ask_t_ = 1;
        vers_ask_sel_ = ask_sel;
    }
}

bool Store::update_versions(float dt)
{
    vers_t_ = std::min(1.f, vers_t_ + dt * 5.f);
    const App *a = find(vers_app_);
    if (!a)
    {
        vers_ = vers_ask_ = false; /* it left the catalog */
        return true;
    }
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (vers_ask_)
    {
        /* "Install <name> <version>?": Cancel, Install. */
        vers_ask_t_ = std::min(1.f, vers_ask_t_ + dt * 5.f);
        const int s = std::clamp(vers_ask_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, 1);
        if (s != vers_ask_sel_) { vers_ask_sel_ = s; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && vers_ask_sel_ == 0))
        {
            vers_ask_ = false;
            play(int(Sfx::Back));
        }
        else if (pressed(pad::BtnCross))
        {
            if (!freed_)
            {
                vers_ = vers_ask_ = false;
                notice_ = "Recomp Store can't write to /data. Start etaHEN, then open the store again.";
                notice_bad_ = true;
                play(int(Sfx::Error));
            }
            else if (engine().install(a->id, vers_pick_.key))
            {
                vers_ = vers_ask_ = false;
                notice_.clear();
                page_focus_ = 0; /* back on the button, which shows it going */
                play(int(Sfx::Install));
            }
            else
                play(int(Sfx::Error)); /* something else is going */
        }
        return true;
    }
    if (pressed(pad::BtnCircle))
    {
        vers_ = false;
        play(int(Sfx::Back));
        return true;
    }
    int state = 0;
    const auto rows = version_rows(*a, state);
    const int n = int(rows.size());
    if (n > 0)
    {
        const int s = std::clamp(vers_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n - 1);
        if (s != vers_sel_) { vers_sel_ = s; play(int(Sfx::Move)); }
    }
    if (pressed(pad::BtnCross) && n > 0)
    {
        if (engine().job().phase == Phase::Working)
            play(int(Sfx::Error));
        else
        {
            vers_pick_ = rows[std::size_t(std::clamp(vers_sel_, 0, n - 1))];
            vers_ask_ = true;
            vers_ask_t_ = 0;
            vers_ask_sel_ = 1;
            play(int(Sfx::Open));
        }
    }
    return true;
}

void Store::draw_versions(Gfx &gfx)
{
    if (!vers_)
        return;
    const App *a = find(vers_app_);
    if (!a)
        return;
    int state = 0;
    const auto rows = version_rows(*a, state);
    const int n = int(rows.size());
    vers_sel_ = std::clamp(vers_sel_, 0, std::max(0, n - 1));
    if (vers_sel_ < vers_top_) vers_top_ = vers_sel_;
    if (vers_sel_ >= vers_top_ + kVersShown) vers_top_ = vers_sel_ - kVersShown + 1;
    vers_top_ = std::clamp(vers_top_, 0, std::max(0, n - kVersShown));

    const float k = ease_out(vers_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 900, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const float list_h = float(std::clamp(n, 1, kVersShown)) * kVersRowH;
    const float bh = pad + 40 + 16 + 30 + 26 + list_h + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 20, C(kFrost), Align::Left, "Versions");
    y += 40 + 16;
    gfx.text_mid(Font::SemiBold, 20, bx + pad, y + 15, C(kCyan), Align::Left,
                 fit(gfx, Font::SemiBold, 20, iw, a->beta ? a->name + " \xC2\xB7 beta builds" : a->name));
    y += 30 + 26;
    if (n == 0)
    {
        const std::string msg = state == 0  ? "Getting its versions from GitHub\xE2\x80\xA6"
                                : state < 0 ? "GitHub couldn't be reached. Try again once your PS5 is online."
                                            : "There are no versions to pick from yet.";
        gfx.panel(bx + pad, y, iw, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::Regular, 20, bx + pad + 12, y + kVersRowH / 2, C(kMuted), Align::Left, fit(gfx, Font::Regular, 20, iw - 24, msg));
    }
    for (int i = vers_top_; i < n && i < vers_top_ + kVersShown; ++i)
    {
        const VersionRow &r = rows[std::size_t(i)];
        const float ry = y + float(i - vers_top_) * kVersRowH, cy = ry + kVersRowH / 2;
        const bool on = i == vers_sel_ && !vers_ask_;
        if (i > vers_top_)
            gfx.panel(bx + pad, ry, iw, 1.25f, kLine, 1.f, 0);
        if (on)
        {
            gfx.panel(bx + pad - 12, ry + 5, iw + 24, kVersRowH - 10, C(kFrost, 0.08f), 1.f, 18);
            ring(gfx, bx + pad - 12, ry + 5, iw + 24, kVersRowH - 10, 18, 1.f, true);
        }
        /* Its version, then when it came out (and a beta build's size), then what it is: newest,
         * the one installed, a pre-release. */
        const float lx = bx + pad + 12;
        gfx.text_mid(Font::Bold, 24, lx, cy, C(kFrost), Align::Left, fit(gfx, Font::Bold, 24, 210, r.label));
        float cx = bx + pad + iw - 12;
        auto chip = [&](const char *t, Color fill, Color edge, Color fg) {
            const float w = gfx.measure(Font::Bold, 15, t) + 28;
            cx -= w;
            gfx.panel(cx, cy - 15, w, 30, fill, 1.f, 15, edge, edge.a > 0 ? 1.25f : 0.f);
            gfx.text_mid(Font::Bold, 15, cx + w / 2, cy, fg, Align::Center, t);
            cx -= 10;
        };
        if (r.newest) chip("Newest", C(kCyan), Color{0, 0, 0, 0}, C(kMidnight));
        if (r.installed) chip("Installed", C(kGreen, 0.18f), C(kGreen, 0.5f), C(0x9FF0C6));
        if (r.pre) chip("Pre-release", C(kAmber, 0.16f), C(kAmber, 0.6f), C(0xFFD9A0));
        std::string meta = r.date;
        if (!r.size.empty()) meta += (meta.empty() ? "" : " \xC2\xB7 ") + r.size;
        const float mx = lx + 230;
        if (!meta.empty() && cx - mx > 40)
            gfx.text_mid(Font::Regular, 18, mx, cy + 1, C(kMuted), Align::Left, fit(gfx, Font::Regular, 18, cx - mx - 8, meta));
    }
    if (n > kVersShown)
    {
        /* Where it is in the list: a thin bar on the right. */
        const float tx = bx + bw - 22;
        gfx.panel(tx, y, 4, list_h, C(0xFFFFFF, 0.10f), 1.f, 2);
        const float bar = std::max(28.f, list_h * float(kVersShown) / float(n));
        const float pos = float(vers_top_) / float(n - kVersShown);
        gfx.panel(tx, y + (list_h - bar) * pos, 4, bar, C(kCyan, 0.9f), 1.f, 2);
    }
    gfx.set_layer();
    if (!vers_ask_)
        return;

    /* "Install <name> <version>?" over the list. */
    const float k2 = ease_out(vers_ask_t_);
    gfx.panel(0, 0, W, H, C(kMidnight, 0.5f * k2), 1.f, 0);
    gfx.set_layer(0, (1 - k2) * 20, k2);
    const float cw = 820, cx0 = (W - cw) / 2, cpad = 48, ciw = cw - cpad * 2;
    /* Over an install: what stays. Older than the newest: Update All leaves it (it's kept). */
    const std::string inst = engine().installed(a->id);
    std::string body = !inst.empty() && inst != "?" ? "Your saves and game files stay. Files a newer version added stay too." : "";
    if (!vers_pick_.newest)
        body += std::string(body.empty() ? "" : " ") + "Update all skips it until you update it yourself.";
    else if (body.empty())
        body = "It's the newest version.";
    const auto title = wrap(gfx, Font::ExtraBold, 32, ciw, "Install " + a->name + " " + vers_pick_.label + "?", 2);
    const auto lines = wrap(gfx, Font::Regular, 20, ciw, body, 4);
    const float ch = cpad + 40 * float(title.size()) + 18 + 30 * float(lines.size()) + 36 + 64 + cpad;
    const float cy0 = (H - ch) / 2;
    glass(gfx, cx0, cy0, cw, ch, 32, C(0x0E1729, 0.98f), true);
    float ty = cy0 + cpad;
    for (std::size_t i = 0; i < title.size(); ++i)
        gfx.text_mid(Font::ExtraBold, 32, cx0 + cpad, ty + 40 * (float(i) + 0.5f), C(kFrost), Align::Left, title[i]);
    ty += 40 * float(title.size()) + 18;
    ty += draw_lines(gfx, Font::Regular, 20, cx0 + cpad, ty, 30, C(kSoft), lines) + 36;
    const float gap = 16, w2 = (ciw - gap) / 2;
    const char *labels[2] = {"Cancel", "Install"};
    for (int i = 0; i < 2; ++i)
    {
        const float x = cx0 + cpad + float(i) * (w2 + gap);
        const bool on = vers_ask_sel_ == i;
        gfx.panel(x, ty, w2, 64, i == 1 ? C(kCyan, on ? 1.f : 0.3f) : C(kFrost, on ? 1.f : 0.12f), 1.f, 20);
        ring(gfx, x, ty, w2, 64, 20, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 22, x + w2 / 2, ty + 32, on ? C(kMidnight) : C(kFrost), Align::Center, labels[i]);
    }
    gfx.set_layer();
}

/* ---- Where an app downloads from changed ------------------------------------------------ */

bool Store::update_moved(float dt)
{
    moved_t_ = std::min(1.f, moved_t_ + dt * 5.f);
    const App *a = find(moved_job_.app_id);
    if (!a)
    {
        moved_ = false; /* it left the catalog while the question was up */
        return true;
    }
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const int s = std::clamp(moved_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, 1);
    if (s != moved_sel_) { moved_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && moved_sel_ == 1))
    {
        moved_ = false;
        notice_ = "Not updated. Nothing on your PS5 was changed.";
        notice_bad_ = false;
        play(int(Sfx::Back));
    }
    else if (pressed(pad::BtnCross))
    {
        moved_ = false;
        /* This once: the install after it writes the new place in the app's folder. */
        if (engine().resolve_clash(a->id, 3, moved_job_.version))
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

void Store::draw_moved(Gfx &gfx)
{
    if (!moved_)
        return;
    const App *a = find(moved_job_.app_id);
    if (!a)
        return;
    const float k = ease_out(moved_t_);
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    const float bw = 880, bx = (W - bw) / 2, pad = 48, iw = bw - pad * 2;
    const auto title = wrap(gfx, Font::ExtraBold, 32, iw, "Where " + a->name + " downloads from changed", 2);
    const auto lines = wrap(gfx, Font::Regular, 20, iw, "Only update if you trust the new place.", 2);
    const float row_h = 56;
    const float bh = pad + 40 * float(title.size()) + 22 + row_h * 2 + 22 + 30 * float(lines.size()) + 36 + 64 + pad;
    const float by = (H - bh) / 2;
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    for (std::size_t i = 0; i < title.size(); ++i)
        gfx.text_mid(Font::ExtraBold, 32, bx + pad, y + 40 * (float(i) + 0.5f), C(kFrost), Align::Left, title[i]);
    y += 40 * float(title.size()) + 22;
    /* Before and now, as the page's rows: the old place plain, the new one in cyan. */
    const std::pair<const char *, std::string> places[2] = {{"Before", "github.com/" + moved_job_.source_old},
                                                            {"Now", "github.com/" + moved_job_.source_new}};
    for (int i = 0; i < 2; ++i)
    {
        gfx.panel(bx + pad, y, iw, 1.25f, kLine, 1.f, 0);
        gfx.text_mid(Font::Regular, 20, bx + pad, y + row_h / 2, C(kMuted), Align::Left, places[i].first);
        const Font f = i == 1 ? Font::SemiBold : Font::Regular;
        gfx.text_mid(f, 20, bx + pad + iw, y + row_h / 2, i == 1 ? C(kCyan) : C(kFrost), Align::Right, fit(gfx, f, 20, iw - 140, places[i].second));
        y += row_h;
    }
    gfx.panel(bx + pad, y, iw, 1.25f, kLine, 1.f, 0);
    y += 22;
    y += draw_lines(gfx, Font::Regular, 20, bx + pad, y, 30, C(kSoft), lines) + 36;
    const float gap = 16, w2 = (iw - gap) / 2;
    const char *labels[2] = {"Update from the new place", "Cancel"};
    for (int i = 0; i < 2; ++i)
    {
        const float x = bx + pad + float(i) * (w2 + gap);
        const bool on = moved_sel_ == i;
        gfx.panel(x, y, w2, 64, C(kFrost, on ? 1.f : 0.12f), 1.f, 20);
        ring(gfx, x, y, w2, 64, 20, on ? 1.f : 0.f, true);
        gfx.text_mid(Font::Bold, 22, x + w2 / 2, y + 32, on ? C(kMidnight) : C(kFrost), Align::Center, labels[i]);
    }
    gfx.set_layer();
}
} // namespace recomp
