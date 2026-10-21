/* Recomp Store 2.3.0 - Upload from your phone (the receiver's sheet), a game's Saves, the phone's
 * requests (Delete / Ignore on the PS5), Clear download cache with progress, and what 2.3.0's jobs
 * say when they're done. The contract: docs/store-2.3.md.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_app.hpp"
#include "store_ui.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"
#include "ui_app_common.hpp"

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

float box23(Gfx &gfx, float k, float bw, float bh, float &bx, float &by)
{
    const float W = gfx.design_w(), H = gfx.design_h();
    gfx.panel(0, 0, W, H, C(kMidnight, 0.72f * k), 1.f, 0);
    gfx.set_layer(0, (1 - k) * 20, k);
    bx = (W - bw) / 2;
    by = std::max(60.f, (H - bh) / 2);
    return by;
}

std::string leaf_of(const std::string &p) { return p.substr(p.find_last_of('/') + 1); }

/* "2026-10-20T19:15:00Z" as "Oct 20, 2026 19:15" (UTC as it was made; good enough to tell them apart). */
std::string made_text(const std::string &iso, long long mtime)
{
    static const char *mon[] = {"Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"};
    if (mtime > 0)
    {
        const std::time_t t = std::time_t(mtime);
        if (const std::tm *tm = std::localtime(&t))
        {
            char b[48];
            std::snprintf(b, sizeof b, "%s %d, %d  %02d:%02d", mon[tm->tm_mon % 12], tm->tm_mday, tm->tm_year + 1900, tm->tm_hour, tm->tm_min);
            return b;
        }
    }
    return iso.size() >= 16 ? iso.substr(0, 10) + "  " + iso.substr(11, 5) : iso;
}

void button(Store *, Gfx &gfx, float x, float y, float w, float h, const std::string &label, bool on, int style, void (*ring_fn)(Gfx &, float, float, float, float, bool))
{
    (void)ring_fn;
    Color bg = style == 1 ? C(kCyan, on ? 1.f : 0.3f) : style == 2 ? C(0xE5484D, on ? 0.9f : 0.18f) : C(kFrost, on ? 1.f : 0.12f);
    gfx.panel(x, y, w, h, bg, 1.f, 20);
    const Color fg = style == 2 ? (on ? C(0xFFFFFF) : C(0xFF8A80)) : on ? C(kMidnight) : C(kFrost);
    gfx.text_mid(Font::Bold, 21, x + w / 2, y + h / 2, fg, Align::Center, label);
}
} // namespace

/* ---- Upload from your phone ------------------------------------------------------------------ */

void Store::open_upload(int mode, const std::string &app, int slot)
{
    up_mode_ = mode;
    up_app_ = app;
    up_slot_ = slot;
    up_code_.clear();
    up_why_.clear();
    up_files_ = 0;
    up_last_.clear();
    up_st_ = ReceiverStatus();
    ReceiverJob job;
    const App *a = find(app);
    if (mode == 0 && a && slot >= 0 && slot < int(a->slots.size()))
    {
        const Slot &s = a->slots[std::size_t(slot)];
        job.title = s.label + " for " + a->name;
        job.dest = data_ + "/uploads/" + a->id;
        job.ext = s.kind == "iso" ? ".iso" : s.kind == "image" ? ".iso .gcm .ciso" : s.kind == "file" ? s.ext : "";
        for (char &c : job.ext) if (c == ',') c = ' ';
    }
    else if (mode == 2 && a)
    {
        job.title = a->name + " saves";
        job.backups = data_ + "/backups";
        job.backup_id = a->id;
    }
    else if (mode == 3)
    {
        job.title = "Your list of apps";
        job.dest = data_ + "/uploads";
        job.ext = ".json";
    }
    else
    {
        up_mode_ = 1;
        job.title = "Files for your PS5";
        job.dest = data_ + "/uploads";
        job.backups = data_ + "/backups";
    }
    if (!engine().start_receiver(job, up_code_, up_why_)) up_code_.clear();
    open_panel(PanelUpload);
}

void Store::close_upload()
{
    engine().stop_receiver();
    panel_ = 0;
}

bool Store::update_upload_panel(float dt)
{
    up_poll_ -= dt;
    if (up_poll_ <= 0)
    {
        up_poll_ = 0.3f;
        up_st_ = engine().receiver_status();
        if (!up_st_.why.empty()) up_why_ = up_st_.why;
        if (up_st_.known && up_st_.files > up_files_ && !up_st_.last.empty())
        {
            up_files_ = up_st_.files;
            up_last_ = up_st_.last;
            play(int(Sfx::Complete));
            const App *a = find(up_app_);
            if (up_mode_ == 0 && a && up_slot_ >= 0 && up_slot_ < int(a->slots.size()))
            {
                /* The slot's file came: set up with it, as if it had been chosen in the file chooser. */
                const Slot &sl = a->slots[std::size_t(up_slot_)];
                chosen_[a->id + "/" + sl.key] = up_last_;
                close_upload();
                const auto ctl = page_controls(*a);
                for (int i = 0; i < int(ctl.size()); ++i)
                    if (ctl[std::size_t(i)].kind == Control::Setup) page_focus_ = i;
                if (engine().job().phase == Phase::Working)
                {
                    notice_ = leaf_of(up_last_) + " came from your phone. Press Set up game files once what's running is done.";
                    notice_bad_ = false;
                }
                else
                    press_setup(*a);
                return true;
            }
            if (up_mode_ == 3)
            {
                std::string why;
                MyAppsList l;
                ma_note_ = Engine::read_my_apps(up_last_, l, why) ? "Got a list of " + std::to_string(l.apps.size()) + " apps from your phone." : why;
                toast(ma_note_);
            }
            else
                toast("Got " + leaf_of(up_last_));
        }
    }
    if (pressed(pad::BtnCircle) || pressed(pad::BtnCross))
    {
        close_upload();
        play(int(Sfx::Back));
    }
    return true;
}

void Store::draw_upload_panel(Gfx &gfx, float k)
{
    const float bw = 1180, bh = 620, pad = 52;
    float bx, by;
    box23(gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    const App *a = find(up_app_);
    const char *title = up_mode_ == 2 ? "Back up to your phone" : up_mode_ == 3 ? "Send your list from your phone" : "Upload from your phone";
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, title);
    std::string sub;
    if (up_mode_ == 0 && a && up_slot_ >= 0 && up_slot_ < int(a->slots.size()))
    {
        const Slot &s = a->slots[std::size_t(up_slot_)];
        const std::string ext = s.kind == "iso" ? ".iso" : s.kind == "image" ? ".iso, .gcm or .ciso" : s.kind == "file" ? s.ext : "";
        sub = "Your own " + s.label + (ext.empty() ? "" : " (" + ext + ")") + " for " + a->name + ". It's set up as soon as it arrives.";
    }
    else if (up_mode_ == 2 && a)
        sub = "Download " + a->name + "'s backups to your phone, or send one back to restore it here.";
    else if (up_mode_ == 3)
        sub = "Send the my-apps.json you saved, and the store shows what it can put back.";
    else
        sub = "Files land in Uploads, which the file chooser shows. Your save backups can be downloaded too.";
    y += 40 + 10;
    y += draw_lines(gfx, Font::Regular, 19, bx + pad, y, 28, C(kSoft), wrap(gfx, Font::Regular, 19, bw - pad * 2, sub, 2)) + 22;
    const ReceiverStatus &st = up_st_;
    const float qs = 300, qx = bx + pad, qy = y;
    const std::string url = st.ip.empty() || !st.port ? std::string() : "http://" + st.ip + ":" + std::to_string(st.port);
    const float tx = qx + qs + 48, tw = bw - pad * 2 - qs - 48;
    if (!up_why_.empty() || st.state == "failed")
    {
        gfx.panel(qx, qy, qs, qs, C(kFrost, 0.06f), 1.f, 22, kRim, 1.25f);
        gfx.text_mid(Font::ExtraBold, 60, qx + qs / 2, qy + qs / 2, kWarm, Align::Center, "!");
        draw_lines(gfx, Font::SemiBold, 21, tx, qy + 10, 31, kWarm, wrap(gfx, Font::SemiBold, 21, tw, up_why_.empty() ? st.error : up_why_, 6));
    }
    else if (!st.known || st.state == "starting")
    {
        gfx.panel(qx, qy, qs, qs, C(kFrost, 0.06f), 1.f, 22, kRim, 1.25f);
        gfx.text_mid(Font::SemiBold, 20, qx + qs / 2, qy + qs / 2, C(kMuted), Align::Center, "Starting\xE2\x80\xA6");
        draw_lines(gfx, Font::Regular, 20, tx, qy + 10, 30, C(kSoft),
                   wrap(gfx, Font::Regular, 20, tw, "The store is starting a small receiver on this PS5. It runs only while this is open.", 3));
    }
    else
    {
        if (!url.empty())
        {
            gfx.panel(qx, qy, qs, qs, C(0xFFFFFF), 1.f, 22);
            draw_qr(gfx, url + "/?c=" + up_code_, qx + 18, qy + 18, qs - 36);
        }
        else
        {
            gfx.panel(qx, qy, qs, qs, C(kFrost, 0.06f), 1.f, 22, kRim, 1.25f);
            draw_lines(gfx, Font::Regular, 18, qx + 24, qy + 30, 27, C(kSoft),
                       wrap(gfx, Font::Regular, 18, qs - 48, "This PS5's address isn't known. Find it in Settings > Network > Connection status.", 6));
        }
        float ty = qy;
        auto step = [&](const char *n, const std::string &text) {
            gfx.panel(tx, ty + 2, 34, 34, C(kCyan, 0.18f), 1.f, 17);
            gfx.text_mid(Font::Bold, 18, tx + 17, ty + 19, C(kCyan), Align::Center, n);
            ty += draw_lines(gfx, Font::Regular, 20, tx + 50, ty + 4, 29, C(kSoft), wrap(gfx, Font::Regular, 20, tw - 50, text, 2)) + 14;
        };
        step("1", "Connect your phone to the same Wi-Fi as this PS5.");
        step("2", url.empty() ? "On your phone, go to http://<the PS5's address>:" + std::to_string(st.port) : "Scan the code with your phone's camera, or go to");
        if (!url.empty())
        {
            gfx.text_mid(Font::ExtraBold, 30, tx + 50, ty + 18, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 30, tw - 50, url));
            ty += 48;
        }
        step("3", "Enter this code:");
        const std::string code = up_code_.size() == 6 ? up_code_.substr(0, 3) + " " + up_code_.substr(3) : up_code_;
        gfx.text_mid(Font::ExtraBold, 52, tx + 50, ty + 26, C(kCyan), Align::Left, code, 4);
        ty += 64;
    }
    y = qy + qs + 30;
    /* What's happening now. */
    std::string line;
    Color lc = C(kSoft);
    float frac = -1;
    if (st.state == "receiving" || st.state == "sending")
    {
        line = (st.state == "receiving" ? "Receiving " : "Sending ") + st.file + "  \xC2\xB7  " + size_text(st.bytes) + " of " + size_text(st.total);
        if (st.speed > 0)
        {
            char sp[32];
            std::snprintf(sp, sizeof sp, "  \xC2\xB7  %.0f MB/s", double(st.speed) / 1048576.0);
            line += sp;
        }
        frac = st.total ? float(double(st.bytes) / double(st.total)) : 0.f;
    }
    else if (st.state == "locked")
    {
        line = st.error;
        lc = kWarm;
    }
    else if (!st.error.empty())
    {
        line = st.error;
        lc = kWarm;
    }
    else if (up_files_ > 0)
    {
        line = "Got " + leaf_of(up_last_) + (up_mode_ == 1 ? ". Find it in the file chooser under Uploads." : ".");
        lc = kGood;
    }
    else if (st.state == "listening")
        line = "Waiting for your phone\xE2\x80\xA6";
    if (frac >= 0)
    {
        gfx.panel(bx + pad, y, bw - pad * 2, 10, C(kFrost, 0.12f), 1.f, 5);
        gfx.panel(bx + pad, y, std::max(10.f, (bw - pad * 2) * clamp01(frac)), 10, C(kCyan), 1.f, 5);
        y += 22;
    }
    if (!line.empty()) gfx.text_mid(Font::SemiBold, 20, bx + pad, y + 14, lc, Align::Left, fit(gfx, Font::SemiBold, 20, bw - pad * 2 - 220, line));
    const float cw = 180, cxb = bx + bw - pad - cw, cyb = by + bh - pad - 60;
    gfx.panel(cxb, cyb, cw, 60, C(kFrost), 1.f, 20);
    ring(gfx, cxb, cyb, cw, 60, 20, 1.f, true);
    gfx.text_mid(Font::Bold, 21, cxb + cw / 2, cyb + 30, C(kMidnight), Align::Center, "Close");
}

/* ---- A game's saves ---------------------------------------------------------------------------- */

void Store::open_saves(const std::string &id)
{
    sv_app_ = id;
    sv_ask_ = 0;
    sv_note_.clear();
    sv_list_ = engine().backups(id);
    open_panel(PanelSaves);
}

/* The sheet's buttons, then a row per backup. */
std::vector<std::string> Store::saves_buttons() const
{
    std::vector<std::string> b{"Back up now"};
    if (!engine().usb_drive().empty()) b.push_back("Back up to USB too");
    b.push_back("Your phone");
    return b;
}

bool Store::update_saves_panel(float dt)
{
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    const auto btns = saves_buttons();
    const int nb = int(btns.size()), rows = nb + int(sv_list_.size());
    if (sv_ask_)
    {
        /* Restore it? / Delete it? : Cancel, then the act. */
        const int s = std::clamp(sv_ask_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, sv_ask_ == 3 ? 2 : 1);
        if (s != sv_ask_sel_) { sv_ask_sel_ = s; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && sv_ask_sel_ == 0)) { sv_ask_ = 0; play(int(Sfx::Back)); return true; }
        if (!pressed(pad::BtnCross)) return true;
        const int row = panel_sel_ - nb;
        if (row < 0 || row >= int(sv_list_.size())) { sv_ask_ = 0; return true; }
        const Backup b = sv_list_[std::size_t(row)];
        if (sv_ask_ == 3 && sv_ask_sel_ == 2) { sv_ask_ = 2; sv_ask_sel_ = 0; play(int(Sfx::Move)); return true; }
        if (sv_ask_ == 2)
        {
            std::string why;
            if (engine().delete_backup(sv_app_, b.path, why)) { sv_note_ = "Deleted that backup."; sv_note_bad_ = false; play(int(Sfx::Complete)); }
            else { sv_note_ = why; sv_note_bad_ = true; play(int(Sfx::Error)); }
            sv_list_ = engine().backups(sv_app_);
            panel_sel_ = std::min(panel_sel_, nb + int(sv_list_.size()) - 1);
        }
        else if (engine().restore_saves(sv_app_, b.path))
        {
            sv_note_ = "Restoring\xE2\x80\xA6";
            sv_note_bad_ = false;
            play(int(Sfx::Install));
        }
        else { sv_note_ = "Something else is running. Try again once it's done."; sv_note_bad_ = true; play(int(Sfx::Error)); }
        sv_ask_ = 0;
        return true;
    }
    int s = panel_sel_;
    if (s < nb)
    {
        if (l) s = std::max(0, s - 1);
        if (r) s = std::min(nb - 1, s + 1);
        if (d && rows > nb) s = nb;
    }
    else
    {
        if (d) s = std::min(rows - 1, s + 1);
        if (u) s = s == nb ? 0 : s - 1;
    }
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle)) { panel_ = 0; play(int(Sfx::Back)); return true; }
    if (!pressed(pad::BtnCross)) return true;
    if (panel_sel_ < nb)
    {
        const std::string &b = btns[std::size_t(panel_sel_)];
        if (b == "Your phone") { open_upload(2, sv_app_, -1); return true; }
        if (engine().backup_saves(sv_app_, b != "Back up now"))
        {
            sv_note_ = "Backing up\xE2\x80\xA6";
            sv_note_bad_ = false;
            play(int(Sfx::Install));
        }
        else { sv_note_ = "Something else is running. Try again once it's done."; sv_note_bad_ = true; play(int(Sfx::Error)); }
        return true;
    }
    sv_ask_ = 3; /* Restore or Delete */
    sv_ask_sel_ = 1;
    play(int(Sfx::Open));
    return true;
}

void Store::draw_saves_panel(Gfx &gfx, float k)
{
    const App *a = find(sv_app_);
    const auto btns = saves_buttons();
    const int nb = int(btns.size());
    const int shown = std::min<int>(6, int(sv_list_.size()));
    const float bw = 1100, pad = 48, rh = 72;
    const float bh = pad + 44 + 12 + 30 + 26 + 64 + 30 + 22 + 14 + rh * float(std::max(1, shown)) + 22 + 30 + pad;
    float bx, by;
    box23(gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, (a ? a->name : sv_app_) + " saves");
    y += 44 + 12;
    std::string where;
    if (a)
        for (std::size_t i = 0; i < a->saves.size() && i < 3; ++i) where += (i ? ", " : "") + a->saves[i];
    gfx.text_mid(Font::Regular, 18, bx + pad, y + 13, C(kSoft), Align::Left,
                 fit(gfx, Font::Regular, 18, bw - pad * 2, "Backs up " + (where.empty() ? std::string("its saves") : where) + " as one zip you can open anywhere."));
    y += 30 + 26;
    const float gap = 14, w = (bw - pad * 2 - gap * float(nb - 1)) / float(nb);
    for (int i = 0; i < nb; ++i)
    {
        const float x = bx + pad + float(i) * (w + gap);
        const bool on = !sv_ask_ && panel_sel_ == i;
        button(this, gfx, x, y, w, 64, btns[std::size_t(i)], on, i == 0 ? 1 : 0, nullptr);
        ring(gfx, x, y, w, 64, 20, on ? 1.f : 0.f, true);
    }
    y += 64 + 30;
    gfx.text_mid(Font::Bold, 15, bx + pad, y + 11, C(kCyan), Align::Left, "BACKUPS", 2);
    y += 22 + 14;
    if (sv_list_.empty())
        gfx.text_mid(Font::Regular, 19, bx + pad, y + rh / 2, C(kMuted), Align::Left, "None yet.");
    const int first = std::clamp(panel_sel_ - nb - shown + 1, 0, std::max(0, int(sv_list_.size()) - shown));
    for (int i = first; i < first + shown && i < int(sv_list_.size()); ++i)
    {
        const Backup &b = sv_list_[std::size_t(i)];
        const bool on = panel_sel_ == nb + i;
        gfx.panel(bx + pad, y, bw - pad * 2, 1.25f, kLine, 1.f, 0);
        if (on) ring(gfx, bx + pad - 10, y + 4, bw - pad * 2 + 20, rh - 8, 16, sv_ask_ ? 0.4f : 1.f, true);
        gfx.text_mid(Font::SemiBold, 20, bx + pad + 6, y + rh / 2 - 11, C(kFrost), Align::Left,
                     made_text(b.made, b.mtime) + (b.auto_made ? "  \xC2\xB7  before a restore" : ""));
        gfx.text_mid(Font::Regular, 16, bx + pad + 6, y + rh / 2 + 14, b.ok ? C(kMuted) : kWarm, Align::Left,
                     fit(gfx, Font::Regular, 16, bw - pad * 2 - 260,
                         b.where + "  \xC2\xB7  " + (b.ok ? (b.version.empty() ? b.file : "made with " + b.version) : "not checked yet (from your phone?)")));
        gfx.text_mid(Font::SemiBold, 18, bx + bw - pad - 6, y + rh / 2, C(kSoft), Align::Right, size_text(b.size));
        y += rh;
    }
    if (sv_list_.empty()) y += rh;
    y += 22;
    if (!sv_note_.empty())
        gfx.text_mid(Font::SemiBold, 18, bx + pad, y + 13, sv_note_bad_ ? kWarm : kGood, Align::Left, fit(gfx, Font::SemiBold, 18, bw - pad * 2, sv_note_));
    if (sv_ask_)
    {
        /* The question over it. */
        const int row = panel_sel_ - nb;
        const Backup *b = row >= 0 && row < int(sv_list_.size()) ? &sv_list_[std::size_t(row)] : nullptr;
        const float qw = 860, qh = 300, qx = bx + (bw - qw) / 2, qy = by + (bh - qh) / 2;
        glass(gfx, qx, qy, qw, qh, 28, C(0x111B30, 0.99f), true);
        const std::string q = sv_ask_ == 2 ? "Delete this backup?" : sv_ask_ == 1 ? "Restore these saves?" : "This backup";
        gfx.text_mid(Font::ExtraBold, 28, qx + 40, qy + 50, C(kFrost), Align::Left, q);
        const std::string body = sv_ask_ == 2 ? "It can't be undone."
                                              : "Your saves now are backed up first, then these replace them. From " +
                                                    (b ? made_text(b->made, b->mtime) : std::string()) + ".";
        draw_lines(gfx, Font::Regular, 19, qx + 40, qy + 86, 28, C(kSoft), wrap(gfx, Font::Regular, 19, qw - 80, body, 3));
        const std::vector<std::string> labels = sv_ask_ == 3 ? std::vector<std::string>{"Cancel", "Restore", "Delete"}
                                                : sv_ask_ == 2 ? std::vector<std::string>{"Cancel", "Delete"}
                                                               : std::vector<std::string>{"Cancel", "Restore"};
        const float g = 14, bw2 = (qw - 80 - g * float(labels.size() - 1)) / float(labels.size());
        for (std::size_t i = 0; i < labels.size(); ++i)
        {
            const float x = qx + 40 + float(i) * (bw2 + g);
            const bool on = sv_ask_sel_ == int(i);
            button(this, gfx, x, qy + qh - 40 - 60, bw2, 60, labels[i], on, labels[i] == "Restore" ? 1 : labels[i] == "Delete" ? 2 : 0, nullptr);
            ring(gfx, x, qy + qh - 40 - 60, bw2, 60, 20, on ? 1.f : 0.f, true);
        }
    }
}

/* ---- What a phone asks: Delete / Move, answered on the PS5 ----------------------------------- */

bool Store::phone_action(const LinkAction &a)
{
    auto app_of = [&](const std::string &id) -> const App * { return id.empty() ? nullptr : find(id); };
    if (a.kind == "cancel")
    {
        const App *x = app_of(a.app);
        const JobState j = engine().job();
        const bool going = j.phase == Phase::Working && j.app_id == a.app;
        const bool ok = !going && engine().queue_remove(a.app);
        engine().link_result(a.id, a.kind, a.app, ok ? "done" : "failed",
                             ok ? "Taken out of the downloads." : going ? "It's installing now: stop it on the PS5." : "It isn't waiting in the downloads.");
        if (ok) toast("Your phone took " + (x ? x->name : a.app) + " out of the downloads");
        return true;
    }
    if (a.kind == "favorite" || a.kind == "autoupdate")
    {
        const App *x = app_of(a.app);
        if (!x) { engine().link_result(a.id, a.kind, a.app, "failed", "This store doesn't have that app."); return true; }
        const bool now = a.kind == "favorite" ? pinned(x->id) : auto_on(x->id);
        if (a.on < 0 || (a.on == 1) != now)
        {
            if (a.kind == "favorite") toggle_pin(x->id);
            else toggle_auto(x->id);
        }
        engine().link_result(a.id, a.kind, a.app, "done", "");
        engine().link_push_soon();
        return true;
    }
    if (a.kind == "text")
    {
        if (kb_)
        {
            std::string &t = kb_text();
            t = a.text.substr(0, 200);
            toast("Typed from your phone");
            engine().link_result(a.id, a.kind, "", "done", "");
        }
        else
            engine().link_result(a.id, a.kind, "", "ignored", "Nothing on the PS5 is waiting for typing. Open the keyboard first.");
        return true;
    }
    if (a.kind == "delete" || a.kind == "move")
    {
        /* Never at once: the PS5 asks first (rq_). */
        ConsoleApp c;
        bool found = false;
        for (const ConsoleApp &x : console_)
            if ((!a.folder.empty() && x.folder == a.folder) || (!a.app.empty() && x.store_id == a.app)) { c = x; found = true; }
        if (!found && !a.app.empty() && app_of(a.app) && !inst_v(a.app).empty())
        {
            c.folder = app_folder(a.app);
            c.store_id = a.app;
            c.name = app_of(a.app)->name;
            found = true;
        }
        if (!found || c.store)
        {
            engine().link_result(a.id, a.kind, a.app.empty() ? a.folder : a.app, "failed", c.store ? "Recomp Store can't do that to itself." : "That app isn't on the PS5 any more.");
            return true;
        }
        if (a.kind == "move")
        {
            bool ok_to = false;
            for (const Place &p : install_places_now()) ok_to = ok_to || (p.path == a.to && p.path != c.root);
            if (!ok_to)
            {
                engine().link_result(a.id, a.kind, c.folder, "failed", "That drive isn't one the PS5 can move it to now.");
                return true;
            }
        }
        rq_ = a;
        rq_app_ = c;
        engine().link_result(a.id, a.kind, a.app.empty() ? a.folder : a.app, "waiting", "Waiting for you to confirm on the PS5");
        open_panel(PanelAsk);
        panel_sel_ = 1; /* Ignore first */
        return true;
    }
    return false;
}

bool Store::update_ask_panel(float dt)
{
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const int s = std::clamp(panel_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, 1);
    if (s != panel_sel_) { panel_sel_ = s; play(int(Sfx::Move)); }
    const std::string what = rq_.app.empty() ? rq_.folder : rq_.app;
    if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && panel_sel_ == 1))
    {
        engine().link_result(rq_.id, rq_.kind, what, "ignored", "Ignored on the PS5.");
        panel_ = 0;
        play(int(Sfx::Back));
        return true;
    }
    if (!pressed(pad::BtnCross)) return true;
    panel_ = 0;
    bool ok = false;
    const bool store_app = !rq_app_.store_id.empty() && app_folder(rq_app_.store_id) == rq_app_.folder && find(rq_app_.store_id);
    if (rq_.kind == "delete")
        ok = store_app ? engine().remove(rq_app_.store_id, false) : engine().manage("mdelete", rq_app_.folder);
    else
        ok = engine().manage("mmove", rq_app_.folder, rq_.to);
    if (ok)
    {
        phone_req_ = rq_.id;
        phone_req_kind_ = rq_.kind;
        phone_req_app_ = what;
        mng_job_ = true; /* its result goes in the Library, as the App manager's do */
        mng_delete_name_ = rq_app_.name;
        play(int(Sfx::Install));
    }
    else
    {
        engine().link_result(rq_.id, rq_.kind, what, "failed", "Something else is running on the PS5. Try again in a moment.");
        play(int(Sfx::Error));
    }
    return true;
}

void Store::draw_ask_panel(Gfx &gfx, float k)
{
    const float bw = 900, bh = 330, pad = 48;
    float bx, by;
    box23(gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    const std::string name = rq_app_.name.empty() ? leaf_of(rq_app_.folder) : rq_app_.name;
    const std::string size = rq_app_.size > 0 ? " (" + size_text(std::uint64_t(rq_app_.size)) + ")" : "";
    std::string where;
    for (const Place &p : install_places_now())
        if (p.path == rq_.to) where = p.label;
    const std::string q = rq_.kind == "delete" ? "Your phone asks to delete " + name + size + "."
                                               : "Your phone asks to move " + name + size + " to " + (where.empty() ? rq_.to : where) + ".";
    gfx.text_mid(Font::ExtraBold, 30, bx + pad, by + pad + 18, C(kFrost), Align::Left, rq_.kind == "delete" ? "Delete it?" : "Move it?");
    draw_lines(gfx, Font::Regular, 21, bx + pad, by + pad + 56, 31, C(kSoft),
               wrap(gfx, Font::Regular, 21, bw - pad * 2,
                    q + (rq_.kind == "delete" ? " Its folder and everything in it go, saves too." : " Nothing happens unless you say so here."), 3));
    const float gap = 16, w2 = (bw - pad * 2 - gap) / 2, y = by + bh - pad - 64;
    for (int i = 0; i < 2; ++i)
    {
        const float x = bx + pad + float(i) * (w2 + gap);
        const bool on = panel_sel_ == i;
        button(this, gfx, x, y, w2, 64, i == 0 ? (rq_.kind == "delete" ? "Delete" : "Move") : "Ignore", on, i == 0 ? (rq_.kind == "delete" ? 2 : 1) : 0, nullptr);
        ring(gfx, x, y, w2, 64, 20, on ? 1.f : 0.f, true);
    }
}

/* A phone's request turned into a job: how it went, back to the phone. */
void Store::phone_job_seen(const JobState &job)
{
    if (!phone_req_ || job.phase == Phase::Working || job.phase == Phase::Idle) return;
    if (!(job.kind == "delete" || job.kind == "mdelete" || job.kind == "mmove")) return;
    const bool ok = job.phase == Phase::Done;
    engine().link_result(phone_req_, phone_req_kind_, phone_req_app_, ok ? "done" : "failed",
                         ok ? (job.note.empty() ? std::string(phone_req_kind_ == "delete" ? "Deleted." : "Moved.") : job.note) : job.error);
    phone_req_ = 0;
    engine().look_console();
}

/* ---- 2.3.0's jobs, done ---------------------------------------------------------------------- */

bool Store::take_23_result(const JobState &job)
{
    if (job.phase != Phase::Done && job.phase != Phase::Failed) return false;
    if (job.kind != "backup" && job.kind != "restore" && job.kind != "mod") return false;
    engine().acknowledge();
    const bool ok = job.phase == Phase::Done;
    const std::string text = ok ? job.note : job.error;
    play(int(ok ? Sfx::Complete : Sfx::Error));
    if (panel_ == PanelSaves && job.app_id == sv_app_)
    {
        sv_note_ = text;
        sv_note_bad_ = !ok;
        sv_list_ = engine().backups(sv_app_);
    }
    else if (page_open_ && page_id_ == job.app_id)
    {
        notice_ = text;
        notice_bad_ = !ok;
    }
    else
        toast(text);
    return true;
}

/* ---- Clear download cache, with progress ------------------------------------------------------ */

void Store::clear_cache(std::string &note, bool &bad)
{
    if (!engine().clear_downloads_start())
    {
        play(int(Sfx::Error));
        return;
    }
    clear_note_ = &note;
    clear_bad_ = &bad;
    note = "Clearing the download cache\xE2\x80\xA6";
    bad = false;
    clearing_ = true;
}

void Store::tick_clear()
{
    if (!clearing_) return;
    std::uint64_t freed = 0;
    std::size_t done = 0, total = 0;
    std::string why;
    const int st = engine().clear_state(freed, done, total, why);
    if (st == 1)
    {
        if (clear_note_ && total) *clear_note_ = "Clearing the download cache\xE2\x80\xA6 " + std::to_string(done) + " of " + std::to_string(total) + " files";
        return;
    }
    clearing_ = false;
    engine().clear_ack();
    std::string note = freed ? "Cleared " + size_text(freed) + " from the download cache." + (why.empty() ? std::string() : " " + why)
                             : why.empty() ? std::string("The download cache is empty already.") : why;
    if (clear_note_) *clear_note_ = note;
    if (clear_bad_) *clear_bad_ = st == 3;
    if (panel_ == PanelStorage) toast(note);
    cache_look(true);
    play(int(st == 2 ? Sfx::Complete : Sfx::Error));
}

/* The favorites and Auto-update choices, for the phone's view (the engine sends them). */
void Store::sync_phone_prefs()
{
    engine().set_phone_prefs(prefs_.pins, prefs_.auto_ids, prefs_.auto_all);
}
} // namespace recomp
