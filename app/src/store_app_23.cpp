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
    if (panel_ == PanelMods && job.app_id == md_app_)
    {
        md_note_ = text;
        md_note_bad_ = !ok;
    }
    else if (panel_ == PanelSaves && job.app_id == sv_app_)
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

/* ---- Ready to play ------------------------------------------------------------------------ */

std::vector<Store::Need> Store::ready_items(const App &a) const
{
    std::vector<Need> out;
    const int loader = engine().loader_seen();
    out.push_back({"Payload loader", loader == 1 ? "" : loader == 0 ? "Nothing answers on port 9021. Load etaHEN or elfldr." : "Looking\xE2\x80\xA6",
                   loader == 1 ? 1 : loader == 0 ? 0 : 2});
    std::string v;
    const int smp = engine().smp_state(v);
    const std::string min = catalog_.smp_min;
    const bool old = smp == 1 && !min.empty() && smp_version_cmp(v, min) == -1;
    out.push_back({"ShadowMountPlus" + (min.empty() ? std::string() : " " + min + " or newer"),
                   smp == 1 && !old ? "" : old ? "Yours is " + v + ". Get a newer one in Settings." : smp == 0 ? "Not running. Start it, or set its file in Settings." : "Looking\xE2\x80\xA6",
                   smp == 1 && !old ? 1 : smp == -1 ? 2 : 0});
    const std::string inst = inst_v(a.id);
    if (inst.empty())
    {
        /* Room where it goes: the download and the unpack, twice its size. */
        std::uint64_t need = 0;
        {
            char *end = nullptr;
            const double n = std::strtod(a.size.c_str(), &end);
            const std::string unit = end ? std::string(end) : "";
            if (n > 0) need = std::uint64_t(n * (unit.find("GB") != std::string::npos ? 1073741824.0 : unit.find("KB") != std::string::npos ? 1024.0 : 1048576.0)) * 2;
        }
        std::uint64_t free = 0;
        bool known = false;
        for (const Place &p : install_places_now())
            if (p.path == engine().install_root() && p.total) { free = p.free; known = true; }
        out.push_back({"Free space", !known ? "Can't be told right now." : free >= need ? "" : "It needs " + size_text(need) + " where it installs.",
                       !known ? 2 : free >= need ? 1 : 0});
    }
    else if (show_setup(a))
        for (const Slot &s : a.slots)
            if (s.required) out.push_back({s.label, slot_ready(a, s) ? "" : "Not set up yet.", slot_ready(a, s) ? 1 : 0});
    static const struct { const char *key, *label; } extra[] = {{"kstuff", "kstuff"}, {"etahen", "etaHEN"}, {"internet", "Internet"}, {"usb", "A USB drive"}};
    for (const std::string &r : a.requires_)
        for (const auto &x : extra)
            if (r == x.key)
            {
                if (r == "internet") out.push_back({x.label, engine().online() ? "" : "Your PS5 isn't online.", engine().online() ? 1 : 0});
                else if (r == "usb") out.push_back({x.label, engine().usb_drive().empty() ? "Plug one in." : "", engine().usb_drive().empty() ? 0 : 1});
                else out.push_back({"Needed: " + std::string(x.label), "", 2}); /* the store can't tell */
            }
    return out;
}

float Store::ready_height(const App &a) const
{
    if (a.soon || a.custom || (a.requires_.empty() && !a.has_setup && a.category != "games")) return 0;
    const std::size_t n = ready_items(a).size();
    return 22 + 14 + float((n + 1) / 2) * 34 + 20;
}

void Store::draw_ready(Gfx &gfx, const App &a, float x, float y, float w, float alpha)
{
    const auto items = ready_items(a);
    const float h = ready_height(a);
    if (h <= 0 || alpha < 0.01f) return;
    gfx.panel(x, y, w, h, C(kFrost, 0.05f * alpha), 1.f, 22, C(kFrost, 0.12f * alpha), 1.25f);
    int ok = 0;
    for (const Need &n : items) ok += n.state == 1;
    gfx.text_mid(Font::Bold, 14, x + 28, y + 22 + 4, C(kCyan, alpha), Align::Left, "READY TO PLAY", 2);
    gfx.text_mid(Font::Regular, 15, x + w - 28, y + 22 + 4, C(kMuted, alpha), Align::Right, std::to_string(ok) + " of " + std::to_string(items.size()));
    const float cw = (w - 56) / 2;
    for (std::size_t i = 0; i < items.size(); ++i)
    {
        const Need &n = items[i];
        const float cx = x + 28 + float(i % 2) * cw, cy = y + 22 + 14 + 10 + float(i / 2) * 34 + 10;
        const Color dot = n.state == 1 ? C(kGreen, alpha) : n.state == 0 ? C(0xFF8A80, alpha) : C(kDim, 0.6f * alpha);
        gfx.panel(cx, cy - 8, 16, 16, n.state == 2 ? Color{0, 0, 0, 0} : dot, 1.f, 8, dot, n.state == 2 ? 1.5f : 0.f);
        const std::string t = n.why.empty() || n.state == 1 ? n.label : n.label + " \xC2\xB7 " + n.why;
        gfx.text_mid(n.state == 2 ? Font::Regular : Font::SemiBold, 17, cx + 26, cy, n.state == 2 ? C(kDim, alpha) : C(kFrost, alpha), Align::Left,
                     fit(gfx, Font::SemiBold, 17, cw - 36, t));
    }
}

/* ---- Collections --------------------------------------------------------------------------- */

const Collection *Store::collection(const std::string &id) const
{
    for (const Collection &c : catalog_.collections)
        if (c.id == id) return &c;
    return nullptr;
}

void Store::open_collection(const std::string &id)
{
    if (!collection(id)) return;
    co_id_ = id;
    co_stage_ = 0;
    co_places_.clear();
    open_panel(PanelCollection);
}

/* Every app in it not installed yet, into the downloads, in the collection's order. */
void Store::install_all(const std::string &root)
{
    const Collection *c = collection(co_id_);
    if (!c) return;
    int added = 0;
    for (const std::string &id : c->ids)
    {
        const App *a = find(id);
        if (!a || a->soon || !inst_v(id).empty() || queue_place(id) || store_too_old(*a)) continue;
        if (enqueue(*a, "", root, true)) ++added;
    }
    toast(added ? "Installing " + std::to_string(added) + (added == 1 ? " app" : " apps") + " from " + c->title : "Everything in " + c->title + " is installed already");
    if (added) { queue_fresh_ = true; play(int(Sfx::Install)); }
}

bool Store::update_collection_panel(float dt)
{
    const Collection *c = collection(co_id_);
    if (!c) { panel_ = 0; return true; }
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (co_stage_ == 1)
    {
        const int n = int(co_places_.size());
        const int s2 = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n);
        if (s2 != panel_sel_) { panel_sel_ = s2; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && panel_sel_ == n)) { co_stage_ = 0; panel_sel_ = 0; play(int(Sfx::Back)); return true; }
        if (pressed(pad::BtnCross)) { const std::string root = co_places_[std::size_t(panel_sel_)].path; panel_ = 0; install_all(root); }
        return true;
    }
    /* 0 Install all, then a row per app (Cross opens its page). */
    const int rows = 1 + int(c->ids.size());
    const int s2 = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, rows - 1);
    if (s2 != panel_sel_) { panel_sel_ = s2; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle)) { panel_ = 0; play(int(Sfx::Back)); return true; }
    if (!pressed(pad::BtnCross)) return true;
    if (panel_sel_ == 0)
    {
        co_places_ = install_places_now();
        if (co_places_.size() > 1) { co_stage_ = 1; panel_sel_ = 0; play(int(Sfx::Open)); } /* where, asked once */
        else { panel_ = 0; install_all(co_places_.empty() ? std::string() : co_places_[0].path); }
        return true;
    }
    const std::string id = c->ids[std::size_t(panel_sel_ - 1)];
    panel_ = 0;
    open(id);
    return true;
}

void Store::draw_collection_panel(Gfx &gfx, float k)
{
    const Collection *c = collection(co_id_);
    if (!c) return;
    const int shown = std::min<int>(7, int(c->ids.size()));
    const float bw = 1100, pad = 48, rh = 76;
    const bool where = co_stage_ == 1;
    const float bh = pad + 44 + 10 + 56 + 20 + 64 + 24 + (where ? 64.f * float(co_places_.size() + 1) : rh * float(shown)) + pad;
    float bx, by;
    box23(gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, c->title);
    gfx.text_mid(Font::Regular, 17, bx + bw - pad, y + 20, C(kMuted), Align::Right, std::to_string(c->ids.size()) + (c->ids.size() == 1 ? " app" : " apps"));
    y += 44 + 10;
    draw_lines(gfx, Font::Regular, 19, bx + pad, y, 28, C(kSoft), wrap(gfx, Font::Regular, 19, bw - pad * 2, c->blurb, 2));
    y += 56 + 20;
    int missing = 0;
    for (const std::string &id : c->ids)
        if (const App *a = find(id)) missing += inst_v(id).empty() && !a->soon ? 1 : 0;
    if (where)
    {
        gfx.text_mid(Font::Bold, 15, bx + pad, y + 11, C(kCyan), Align::Left, "WHERE TO INSTALL THEM", 2);
        y += 40;
        for (std::size_t i = 0; i <= co_places_.size(); ++i)
        {
            const bool on = panel_sel_ == int(i);
            const bool cancel = i == co_places_.size();
            const std::string label = cancel ? "Cancel" : co_places_[i].label + (co_places_[i].total ? "  \xC2\xB7  " + size_text(co_places_[i].free) + " free" : std::string());
            button(this, gfx, bx + pad, y, bw - pad * 2, 54, label, on, cancel ? 0 : 1, nullptr);
            ring(gfx, bx + pad, y, bw - pad * 2, 54, 20, on ? 1.f : 0.f, true);
            y += 64;
        }
        return;
    }
    const bool on0 = panel_sel_ == 0;
    button(this, gfx, bx + pad, y, bw - pad * 2, 64, missing ? "Install all (" + std::to_string(missing) + ")" : "Everything's installed", on0, missing ? 1 : 0, nullptr);
    ring(gfx, bx + pad, y, bw - pad * 2, 64, 20, on0 ? 1.f : 0.f, true);
    y += 64 + 24;
    const int first = std::clamp(panel_sel_ - shown, 0, std::max(0, int(c->ids.size()) - shown));
    for (int i = first; i < first + shown && i < int(c->ids.size()); ++i)
    {
        const App *a = find(c->ids[std::size_t(i)]);
        if (!a) continue;
        const bool on = panel_sel_ == i + 1;
        gfx.panel(bx + pad, y, bw - pad * 2, 1.25f, kLine, 1.f, 0);
        if (on) ring(gfx, bx + pad - 10, y + 4, bw - pad * 2 + 20, rh - 8, 16, 1.f, true);
        Art &t = art(*a);
        if (t.icon) gfx.image(t.icon, bx + pad + 4, y + 10, 56, 56, {}, 14);
        else draw_no_icon(gfx, *a, bx + pad + 4, y + 10, 56, 14);
        gfx.text_mid(Font::SemiBold, 21, bx + pad + 80, y + rh / 2 - 10, C(kFrost), Align::Left, fit(gfx, Font::SemiBold, 21, bw - pad * 2 - 360, a->name));
        gfx.text_mid(Font::Regular, 16, bx + pad + 80, y + rh / 2 + 14, C(kMuted), Align::Left, fit(gfx, Font::Regular, 16, bw - pad * 2 - 360, a->blurb));
        const std::string st = tile_line(*a);
        gfx.text_mid(Font::SemiBold, 17, bx + bw - pad - 8, y + rh / 2, inst_v(a->id).empty() ? C(kSoft) : kGood, Align::Right, st);
        y += rh;
    }
}

/* ---- Search ------------------------------------------------------------------------------- */

bool Store::search_match(const App &a) const
{
    if (search_.empty()) return true;
    auto low = [](std::string t) {
        for (char &c : t) c = char(std::tolower(static_cast<unsigned char>(c)));
        return t;
    };
    std::string q = low(search_);
    while (!q.empty() && q.back() == ' ') q.pop_back();
    while (!q.empty() && q[0] == ' ') q.erase(0, 1);
    if (q.empty()) return true;
    if (low(a.name).find(q) != std::string::npos || low(a.author).find(q) != std::string::npos || low(a.id).find(q) != std::string::npos) return true;
    for (const std::string &t : a.tags)
        if (low(t).find(q) != std::string::npos) return true;
    return false;
}

/* ---- Tester feedback (a beta's) ------------------------------------------------------------ */

void Store::open_feedback(const std::string &id)
{
    open_logs(id);
    logs_fb_ = true; /* Works, Crashes, Slow, Other; the logs and this build go with it */
}

/* ---- Restore my apps ---------------------------------------------------------------------- */

std::vector<std::string> Store::myapps_sources() const
{
    std::vector<std::string> s{"From your linked phone", "From a USB drive", "Send it from your phone"};
    if (!engine().usb_drive().empty()) s.push_back("Save my list to the USB drive");
    s.push_back("Close");
    return s;
}

void Store::open_myapps()
{
    ma_stage_ = 0;
    ma_list_ = MyAppsList();
    ma_note_.clear();
    ma_note_bad_ = false;
    ma_waiting_ = false;
    open_panel(PanelMyApps);
}

bool Store::update_myapps_panel(float dt)
{
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    if (ma_waiting_)
    {
        std::vector<MyAppsList> lists;
        std::string why;
        const int st = engine().my_apps_lists(lists, why);
        if (st == 2 || st == 3)
        {
            ma_waiting_ = false;
            if (st == 2 && !lists.empty()) { ma_list_ = lists[0]; ma_stage_ = 1; panel_sel_ = 0; ma_note_.clear(); play(int(Sfx::Complete)); }
            else { ma_note_ = st == 3 ? why : "Your linked phones have no list yet. Link one, open Recomp Store on a PS5 that has your apps, and try again."; ma_note_bad_ = true; play(int(Sfx::Error)); }
        }
    }
    if (ma_stage_ == 1)
    {
        /* 0 Install the ones missing, 1 Back */
        const int s2 = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, 1);
        if (s2 != panel_sel_) { panel_sel_ = s2; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && panel_sel_ == 1)) { ma_stage_ = 0; panel_sel_ = 0; play(int(Sfx::Back)); return true; }
        if (pressed(pad::BtnCross))
        {
            int added = 0;
            for (const MyApp &m : ma_list_.apps)
            {
                const App *a = find(m.id);
                if (!a || a->soon || !inst_v(m.id).empty() || queue_place(m.id) || store_too_old(*a)) continue;
                if (enqueue(*a, "", "", true)) ++added;
            }
            panel_ = 0;
            toast(added ? "Putting back " + std::to_string(added) + (added == 1 ? " app" : " apps") : "Everything on that list is here already");
            if (added) { queue_fresh_ = true; play(int(Sfx::Install)); }
        }
        return true;
    }
    const auto src = myapps_sources();
    const int s2 = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, int(src.size()) - 1);
    if (s2 != panel_sel_) { panel_sel_ = s2; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle)) { panel_ = 0; play(int(Sfx::Back)); return true; }
    if (!pressed(pad::BtnCross)) return true;
    const std::string pick = src[std::size_t(panel_sel_)];
    if (pick == "Close") { panel_ = 0; play(int(Sfx::Back)); return true; }
    if (pick == "From your linked phone")
    {
        engine().ask_my_apps();
        ma_waiting_ = true;
        ma_note_ = "Asking recompstore.com for your lists\xE2\x80\xA6";
        ma_note_bad_ = false;
        play(int(Sfx::Select));
        return true;
    }
    if (pick == "Send it from your phone") { open_upload(3, "", -1); return true; }
    const std::string usb = engine().usb_drive();
    if (usb.empty()) { ma_note_ = "There's no USB drive in."; ma_note_bad_ = true; play(int(Sfx::Error)); return true; }
    const std::string path = usb + "/RecompStore Backups/my-apps.json";
    std::string why;
    if (pick == "Save my list to the USB drive")
    {
        const bool ok = engine().save_my_apps(path, why);
        ma_note_ = ok ? "Saved RecompStore Backups/my-apps.json on the USB drive." : why;
        ma_note_bad_ = !ok;
        play(int(ok ? Sfx::Complete : Sfx::Error));
        return true;
    }
    MyAppsList l;
    if (Engine::read_my_apps(path, l, why)) { l.from = "USB drive"; ma_list_ = l; ma_stage_ = 1; panel_sel_ = 0; ma_note_.clear(); play(int(Sfx::Select)); }
    else { ma_note_ = "No list on the USB drive (RecompStore Backups/my-apps.json). " + why; ma_note_bad_ = true; play(int(Sfx::Error)); }
    return true;
}

void Store::draw_myapps_panel(Gfx &gfx, float k)
{
    const float bw = 1000, pad = 48;
    const auto src = myapps_sources();
    const int shown = std::min<int>(8, int(ma_list_.apps.size()));
    const float bh = ma_stage_ == 1 ? pad + 44 + 12 + 30 + 20 + 44 * float(std::max(1, shown)) + 24 + 64 * 2 + 12 + pad
                                    : pad + 44 + 12 + 60 + 20 + 64 * float(src.size()) + 30 + pad;
    float bx, by;
    box23(gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, "Restore my apps");
    y += 44 + 12;
    if (ma_stage_ == 1)
    {
        int missing = 0;
        for (const MyApp &m : ma_list_.apps)
            if (const App *a = find(m.id)) missing += inst_v(m.id).empty() && !a->soon ? 1 : 0;
        gfx.text_mid(Font::Regular, 19, bx + pad, y + 14, C(kSoft), Align::Left,
                     std::to_string(ma_list_.apps.size()) + " apps on the list" + (ma_list_.from.empty() ? "" : ", from " + ma_list_.from) +
                         (ma_list_.made.size() >= 10 ? " (" + ma_list_.made.substr(0, 10) + ")" : ""));
        y += 30 + 20;
        for (int i = 0; i < shown; ++i)
        {
            const MyApp &m = ma_list_.apps[std::size_t(i)];
            const App *a = find(m.id);
            gfx.panel(bx + pad, y, bw - pad * 2, 1.25f, kLine, 1.f, 0);
            gfx.text_mid(Font::SemiBold, 19, bx + pad + 6, y + 22, a ? C(kFrost) : C(kDim), Align::Left, fit(gfx, Font::SemiBold, 19, bw - pad * 2 - 300, a ? a->name : m.name.empty() ? m.id : m.name));
            gfx.text_mid(Font::Regular, 17, bx + bw - pad - 6, y + 22, !a ? C(kDim) : inst_v(m.id).empty() ? C(kSoft) : kGood, Align::Right,
                         !a ? "Not in the store now" : inst_v(m.id).empty() ? "Comes back" : "Here already");
            y += 44;
        }
        if (int(ma_list_.apps.size()) > shown)
            gfx.text_mid(Font::Regular, 16, bx + pad + 6, y + 10, C(kMuted), Align::Left, "and " + std::to_string(ma_list_.apps.size() - std::size_t(shown)) + " more");
        y += 24;
        for (int i = 0; i < 2; ++i)
        {
            const bool on = panel_sel_ == i;
            button(this, gfx, bx + pad, y, bw - pad * 2, 64, i == 0 ? (missing ? "Install the " + std::to_string(missing) + " missing" : "Nothing's missing") : "Back", on, i == 0 && missing ? 1 : 0, nullptr);
            ring(gfx, bx + pad, y, bw - pad * 2, 64, 20, on ? 1.f : 0.f, true);
            y += 64 + 12;
        }
        return;
    }
    draw_lines(gfx, Font::Regular, 19, bx + pad, y, 28, C(kSoft),
               wrap(gfx, Font::Regular, 19, bw - pad * 2, "Put back the apps you had: from the list a linked phone keeps, a my-apps.json on a USB drive, or one sent from your phone.", 2));
    y += 60 + 20;
    for (std::size_t i = 0; i < src.size(); ++i)
    {
        const bool on = panel_sel_ == int(i);
        button(this, gfx, bx + pad, y, bw - pad * 2, 54, src[i], on, i == 0 ? 1 : 0, nullptr);
        ring(gfx, bx + pad, y, bw - pad * 2, 54, 20, on ? 1.f : 0.f, true);
        y += 64;
    }
    if (!ma_note_.empty())
        gfx.text_mid(Font::SemiBold, 18, bx + pad, y + 14, ma_note_bad_ ? kWarm : kGood, Align::Left, fit(gfx, Font::SemiBold, 18, bw - pad * 2, ma_note_));
}

/* ---- Mods ------------------------------------------------------------------------------- */

void Store::open_mods(const std::string &id)
{
    md_app_ = id;
    md_ask_ = 0;
    md_note_.clear();
    open_panel(PanelMods);
}

std::vector<std::string> Store::mod_actions(const App &a, const App::Mod &m) const
{
    const int st = engine().mod_state(a, m);
    if (st == 0) return {"Cancel", "Install"};
    if (st == 1) return {"Cancel", "Turn off", "Install again", "Remove"};
    return {"Cancel", "Turn on", "Remove"};
}

bool Store::update_mods_panel(float dt)
{
    const App *a = find(md_app_);
    if (!a || a->mods.empty()) { panel_ = 0; return true; }
    const bool l = nav(pad::BtnLeft, nav_left_, dt), r = nav(pad::BtnRight, nav_right_, dt);
    const bool u = nav(pad::BtnUp, nav_up_, dt), d = nav(pad::BtnDown, nav_down_, dt);
    const int n = int(a->mods.size());
    panel_sel_ = std::clamp(panel_sel_, 0, n - 1);
    const App::Mod &m = a->mods[std::size_t(panel_sel_)];
    if (md_ask_)
    {
        const auto acts = mod_actions(*a, m);
        const int s2 = std::clamp(md_ask_sel_ + (r ? 1 : 0) - (l ? 1 : 0), 0, int(acts.size()) - 1);
        if (s2 != md_ask_sel_) { md_ask_sel_ = s2; play(int(Sfx::Move)); }
        if (pressed(pad::BtnCircle) || (pressed(pad::BtnCross) && md_ask_sel_ == 0)) { md_ask_ = 0; play(int(Sfx::Back)); return true; }
        if (!pressed(pad::BtnCross)) return true;
        const std::string act = acts[std::size_t(md_ask_sel_)];
        const std::string what = act == "Turn off" ? "off" : act == "Turn on" ? "on" : act == "Remove" ? "remove" : "install";
        md_ask_ = 0;
        if (engine().mod_job(a->id, m.id, what)) { md_note_ = act + "\xE2\x80\xA6"; md_note_bad_ = false; play(int(Sfx::Install)); }
        else { md_note_ = "Something else is running. Try again once it's done."; md_note_bad_ = true; play(int(Sfx::Error)); }
        return true;
    }
    const int s2 = std::clamp(panel_sel_ + (d ? 1 : 0) - (u ? 1 : 0), 0, n - 1);
    if (s2 != panel_sel_) { panel_sel_ = s2; play(int(Sfx::Move)); }
    if (pressed(pad::BtnCircle)) { panel_ = 0; play(int(Sfx::Back)); return true; }
    if (pressed(pad::BtnCross))
    {
        md_ask_ = 1;
        md_ask_sel_ = 1;
        play(int(Sfx::Open));
    }
    return true;
}

void Store::draw_mods_panel(Gfx &gfx, float k)
{
    const App *a = find(md_app_);
    if (!a) return;
    const int shown = std::min<int>(6, int(a->mods.size()));
    const float bw = 1100, pad = 48, rh = 84;
    const float bh = pad + 44 + 12 + 30 + 20 + rh * float(shown) + 20 + 30 + pad;
    float bx, by;
    box23(gfx, k, bw, bh, bx, by);
    glass(gfx, bx, by, bw, bh, 32, C(0x0E1729, 0.97f), true);
    float y = by + pad;
    gfx.text_mid(Font::ExtraBold, 34, bx + pad, y + 20, C(kFrost), Align::Left, a->name + " mods");
    y += 44 + 12;
    gfx.text_mid(Font::Regular, 18, bx + pad, y + 13, C(kSoft), Align::Left,
                 fit(gfx, Font::Regular, 18, bw - pad * 2, "Each one is checked against its SHA-256 before it goes in the game's " + (a->mods_dir.empty() ? std::string("mods") : a->mods_dir) + " folder."));
    y += 30 + 20;
    const int first = std::clamp(panel_sel_ - shown + 1, 0, std::max(0, int(a->mods.size()) - shown));
    for (int i = first; i < first + shown; ++i)
    {
        const App::Mod &m = a->mods[std::size_t(i)];
        const int st = engine().mod_state(*a, m);
        const bool on = panel_sel_ == i;
        gfx.panel(bx + pad, y, bw - pad * 2, 1.25f, kLine, 1.f, 0);
        if (on) ring(gfx, bx + pad - 10, y + 4, bw - pad * 2 + 20, rh - 8, 16, md_ask_ ? 0.4f : 1.f, true);
        gfx.text_mid(Font::SemiBold, 21, bx + pad + 6, y + rh / 2 - 12, C(kFrost), Align::Left,
                     fit(gfx, Font::SemiBold, 21, bw - pad * 2 - 260, m.name + (m.author.empty() ? std::string() : "  \xC2\xB7  " + m.author)));
        gfx.text_mid(Font::Regular, 16, bx + pad + 6, y + rh / 2 + 14, C(kMuted), Align::Left, fit(gfx, Font::Regular, 16, bw - pad * 2 - 260, m.blurb));
        const std::string sz = m.size ? "  \xC2\xB7  " + size_text(m.size) : std::string();
        gfx.text_mid(Font::SemiBold, 18, bx + bw - pad - 8, y + rh / 2, st == 1 ? kGood : st == 2 ? C(kAmber) : C(kSoft), Align::Right,
                     (st == 1 ? std::string("On") : st == 2 ? std::string("Off") : std::string("Not installed")) + sz);
        y += rh;
    }
    y += 20;
    if (!md_note_.empty())
        gfx.text_mid(Font::SemiBold, 18, bx + pad, y + 13, md_note_bad_ ? kWarm : kGood, Align::Left, fit(gfx, Font::SemiBold, 18, bw - pad * 2, md_note_));
    if (md_ask_)
    {
        const App::Mod &m = a->mods[std::size_t(std::clamp(panel_sel_, 0, int(a->mods.size()) - 1))];
        const auto acts = mod_actions(*a, m);
        const float qw = 880, qh = 220, qx = bx + (bw - qw) / 2, qy = by + (bh - qh) / 2;
        glass(gfx, qx, qy, qw, qh, 28, C(0x111B30, 0.99f), true);
        gfx.text_mid(Font::ExtraBold, 28, qx + 40, qy + 50, C(kFrost), Align::Left, fit(gfx, Font::ExtraBold, 28, qw - 80, m.name));
        const float g = 12, w2 = (qw - 80 - g * float(acts.size() - 1)) / float(acts.size());
        for (std::size_t i = 0; i < acts.size(); ++i)
        {
            const float x = qx + 40 + float(i) * (w2 + g);
            const bool on = md_ask_sel_ == int(i);
            button(this, gfx, x, qy + qh - 40 - 60, w2, 60, acts[i], on, acts[i] == "Remove" ? 2 : acts[i] == "Cancel" ? 0 : 1, nullptr);
            ring(gfx, x, qy + qh - 40 - 60, w2, 60, 20, on ? 1.f : 0.f, true);
        }
    }
}
/* (2.3.0) Cross on a collection's row on Discover opens its page (store_app.cpp). */
bool Store::on_collection_row() const
{
    if (tab_ != Discover) return false;
    const auto lines = discover_lines();
    return row_ >= 0 && row_ < int(lines.size()) && !lines[std::size_t(row_)].coll.empty();
}
} // namespace recomp
