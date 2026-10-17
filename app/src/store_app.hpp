/* Recomp Store - the app's screens and state.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include "porpoise_pad.hpp"
#include "store_engine.hpp"
#include "ui_gfx.hpp"

#include <cstdint>
#include <map>
#include <string>
#include <vector>

namespace recomp
{
/* (1.0.8) What chosen_ holds for a Title Update the store downloads itself: never a path. */
constexpr const char *kDownloadChoice = "\x01" "download";
class Store
{
public:
    /* px_scale: screen pixels per design pixel (2 at 4K), so pictures load at the size they're drawn. */
    void start(porpoise::ui::Gfx &gfx, const std::string &data_dir, bool freed, float px_scale);
    /* false: leave the app. */
    bool update(const porpoise::pad::State &pad, float dt);
    void draw(porpoise::ui::Gfx &gfx);
    void set_display(unsigned w, unsigned h) { disp_w_ = w; disp_h_ = h; }

    /* For the desktop preview: jump straight to a screen. */
    void preview_tab(int tab) { tab_ = tab; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; }
    void preview_open(const std::string &id);
    void preview_settings(int section) { tab_ = 3; section_ = section; in_section_ = false; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; }
    void preview_boot(float t) { booted_ = false; boot_t_ = t; }
    void preview_picker(const std::string &slot, const std::string &path);
    void preview_focus(int f) { page_focus_ = f; }
    void preview_choose(const std::string &slot, const std::string &image) { chosen_[page_id_ + "/" + slot] = image; }
    void preview_row(int row, int col) { row_ = row; col_ = col; page_open_ = false; page_t_ = 0; confirm_ = false; }
    void preview_confirm(int sel = 0) { confirm_ = true; confirm_t_ = 1; confirm_sel_ = sel; const App *a = find(page_id_); confirm_saves_ = a && !a->saves.empty(); }
    void preview_notes(int mode, int top = 0) { notes_focus_ = mode >= 1; notes_open_ = mode >= 2; notes_t_ = notes_open_ ? 1.f : 0.f; notes_top_ = top; confirm_ = false; }
    void preview_general(int row) { tab_ = 3; section_ = 0; in_section_ = true; gen_row_ = row; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; page_open_ = false; page_t_ = 0; confirm_ = false; }
    void preview_speed(float mbs) { speed_ = mbs; }
    void preview_notice(const std::string &t, bool bad) { notice_ = t; notice_bad_ = bad; }
    void preview_settings_note(const std::string &t, bool bad) { settings_note_ = t; settings_note_bad_ = bad; }
    /* (1.1.0) The Redeem a code keypad: what's typed, the key focused, and view 0 typing
     * (msg: what went wrong, if bad), 1 checking, 2 unlocked (msg, app). */
    void preview_keypad(const std::string &digits, int key, int view, const std::string &msg = "", bool bad = false,
                        const std::string &app = "")
    {
        preview_settings(2);
        keypad_ = true; keypad_t_ = 1; key_digits_ = digits; key_sel_ = key; key_view_ = view; key_msg_ = msg;
        key_bad_ = bad; key_app_ = app; key_done_sel_ = 0;
    }
    void preview_keypad_close() { keypad_ = false; }
    /* (1.1.0) Send logs on the open game's page: sel 0 the message, 1 Cancel, 2 Send; view 0
     * ready, 1 sending, 2 sent, 3 didn't go (result); kb: the keyboard open on the message. */
    void preview_logs(const std::string &msg, int sel, int view = 0, const std::string &result = "", bool kb = false,
                      int kb_row = 1, int kb_col = 0)
    {
        open_logs(page_id_);
        logs_t_ = 1; logs_msg_ = msg; logs_sel_ = sel; logs_view_ = view; logs_result_ = result;
        kb_ = kb; kb_t_ = 1; kb_row_ = kb_row; kb_col_ = kb_col;
    }
    void preview_logs_close() { logs_ = kb_ = false; }
    void preview_redeem_note(const std::string &t, bool bad) { redeem_note_ = t; redeem_note_bad_ = bad; }

private:
    enum Tab { Discover = 0, Library = 1, Updates = 2, Settings = 3, TabCount = 4 };
    struct Art
    {
        porpoise::ui::Texture *icon = nullptr, *hero = nullptr, *blur = nullptr, *logo = nullptr;
        bool icon_wait = false, hero_wait = false, blur_wait = false, logo_wait = false;
        std::string icon_file, hero_file, logo_file;
    };
    /* The game page's controls, top to bottom: what the D-pad moves between. */
    struct Control
    {
        enum Kind { Primary, Slot, Setup, Delete, Logs /* (1.1.0) Send logs */ } kind;
        int slot = -1;
    };
    /* Discover (1.0.6), as the lines the D-pad moves between, top to bottom: the featured
     * banner, then the catalog's sections (a grid is a line per six games). */
    struct DLine
    {
        int kind = 0;        /* 0 the featured banner, 1 a row of tiles, 2 a line of a grid, 3 wide tiles, 4 a spotlight */
        float y = 0, h = 0;  /* in Discover's own space: 0 is its top */
        float head = 0;      /* the section's title above it: its height (0 none) */
        std::string title;   /* the title; a spotlight's names it */
        std::vector<const App *> items;
    };

    bool pressed(std::uint32_t bits) const { return (held_ & ~prev_ & bits) != 0; }
    bool nav(std::uint32_t bit, float &timer, float dt);
    void sync_catalog();
    Art &art(const App &a);
    const App *find(const std::string &id) const; /* a game, or a Coming soon entry */
    std::vector<DLine> discover_lines() const;
    /* Discover: the first item of a line that scrolls sideways that's shown, with col
     * focused (or last focused) on it; and where item i of it is drawn. */
    int line_first(const DLine &l, int col) const;
    float item_x(const DLine &l, int i, int first) const;
    std::string state_of(const App &a, bool *update_ready) const;
    std::string version_of(const App &a) const;
    std::vector<const App *> installed_apps() const;
    std::vector<const App *> featured_apps() const;
    void open(const std::string &id);
    void close();
    void press_primary();
    void press_setup(const App &a);
    void start_store_update();
    void play(int effect);
    std::vector<Control> page_controls(const App &a) const;
    bool show_setup(const App &a) const;

    void open_picker(const App &a, int slot);
    void picker_go(const std::string &path);
    bool update_picker(float dt);

    void draw_backdrop(porpoise::ui::Gfx &gfx);
    void draw_top(porpoise::ui::Gfx &gfx);
    void draw_discover(porpoise::ui::Gfx &gfx);
    void draw_library(porpoise::ui::Gfx &gfx);
    void draw_updates(porpoise::ui::Gfx &gfx);
    void draw_settings(porpoise::ui::Gfx &gfx);
    void draw_page(porpoise::ui::Gfx &gfx);
    void draw_picker(porpoise::ui::Gfx &gfx);
    void draw_hints(porpoise::ui::Gfx &gfx);
    void draw_boot(porpoise::ui::Gfx &gfx);
    void draw_tile(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float size, float focus);
    /* A game's picture, wide (Discover's wide tiles), its name under it. */
    void draw_wide(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, float focus);
    /* The featured banner, and a spotlight (the same, standing still). */
    void draw_banner(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, const std::string &kicker,
                     float fade, bool drift, float focus);
    /* "There's more below": an arrow at the bottom of a list that scrolls. */
    void draw_more(porpoise::ui::Gfx &gfx);
    void draw_soon_page(porpoise::ui::Gfx &gfx, const App &a, float k);
    void glass(porpoise::ui::Gfx &gfx, float x, float y, float w, float h, float r,
               porpoise::ui::Color fill, bool shadow = true);
    /* glow: the cyan outline with a soft glow (buttons on the game page, which can be white). */
    void ring(porpoise::ui::Gfx &gfx, float x, float y, float w, float h, float r, float amount, bool glow = false);
    void draw_confirm(porpoise::ui::Gfx &gfx);
    /* The game's logo in a box (fitted, then sized and nudged as the catalog says):
     * the height it takes, or -1 when there's no logo (yet) and the name is drawn. */
    float draw_logo(porpoise::ui::Gfx &gfx, const App &a, const LogoPlace &place, float x, float y, float box_w,
                    float box_h, float alpha);
    /* The game page's What's new box opens into every release's notes from GitHub. */
    bool has_notes_box(const App &a) const { return !a.news.empty() || (a.source == "github" && !a.repo.empty()); }
    void reset_notes() { notes_focus_ = notes_open_ = false; notes_t_ = 0; notes_top_ = 0; notes_key_.clear(); }
    void draw_notes(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, float alpha);
    void layout_notes(porpoise::ui::Gfx &gfx, const std::string &key, const std::vector<Release> &rel, float width);
    bool game_ready(const App &a) const;
    void open_smp_picker();
    /* (1.1.0) Settings > Redeem a code. */
    void open_keypad();
    /* (1.1.0) Send logs, and the keyboard for its message. */
    void open_logs(const std::string &id);
    bool update_logs(float dt);
    void draw_logs(porpoise::ui::Gfx &gfx);
    bool update_keyboard(float dt);
    void draw_keyboard(porpoise::ui::Gfx &gfx);
    bool update_keypad(float dt);
    void draw_keypad(porpoise::ui::Gfx &gfx);
    void redeem_typed();
    std::vector<std::string> wrap(porpoise::ui::Gfx &gfx, porpoise::ui::Font f, float size, float width,
                                  const std::string &text, int max_lines) const;
    float draw_lines(porpoise::ui::Gfx &gfx, porpoise::ui::Font f, float size, float x, float y, float line_h,
                     porpoise::ui::Color c, const std::vector<std::string> &lines);
    std::string fit(porpoise::ui::Gfx &gfx, porpoise::ui::Font f, float size, float width, const std::string &s) const;

    porpoise::ui::Gfx *gfx_ = nullptr;
    std::string data_;
    bool freed_ = false;
    float px_scale_ = 1;
    unsigned disp_w_ = 0, disp_h_ = 0;
    std::uint32_t held_ = 0, prev_ = 0;
    float time_ = 0;

    Catalog catalog_;
    unsigned revision_ = ~0u;
    std::map<std::string, Art> art_;
    porpoise::ui::Texture *logo_small_ = nullptr, *logo_ = nullptr;
    porpoise::ui::Texture *mark_[3] = {}, *mark_glow_ = nullptr, *gradient_ = nullptr;
    porpoise::ui::Texture *hint_cross_ = nullptr, *hint_circle_ = nullptr, *chevron_ = nullptr, *shield_ = nullptr;
    porpoise::ui::Texture *disc_icon_ = nullptr, *folder_icon_ = nullptr;

    bool booted_ = false;
    float boot_t_ = 0;
    float enter_t_ = 0; /* the store fading in after the boot */

    int tab_ = Discover;
    int tab_dir_ = 1;
    float tab_t_ = 1; /* 0..1 since the tab changed */
    int row_ = 1;     /* Discover: the line focused (0 the banner, when there is one) */
    int col_ = 0;     /* the item on it; Library and Latest Updates: the item focused */
    std::vector<int> dcols_;       /* Discover: each line's last focused item */
    std::map<int, float> dfocus_;  /* Discover: each tile's focus, animated (line * 1000 + item) */
    /* How far each tab's list is scrolled (design pixels), and where it's going. */
    float dscroll_ = 0, dscroll_to_ = 0, uscroll_ = 0, uscroll_to_ = 0, lscroll_ = 0, lscroll_to_ = 0;
    float focus_anim_[512] = {};
    int slide_ = 0;
    float slide_t_ = 0, slide_fade_ = 1;
    int section_ = 0;    /* Settings: 0 General, 1 Adding your game, 2 Redeem a code, 3 About */
    bool in_section_ = false;
    bool sounds_on_ = true;
    float nav_left_ = 0, nav_right_ = 0, nav_up_ = 0, nav_down_ = 0;
    std::string focus_id_;
    float backdrop_t_ = 1;
    std::string backdrop_id_, backdrop_prev_;

    std::string page_id_;
    float page_t_ = 0;   /* 0..1 open */
    bool page_open_ = false;
    int page_focus_ = 0;
    std::string notice_; /* a line under the button after an install or setup */
    bool notice_bad_ = false;
    std::map<std::string, std::string> chosen_; /* "<id>/<slot>" -> the disc image picked, or kDownloadChoice */

    /* The file browser for a slot's disc image. */
    bool picker_ = false;
    float picker_t_ = 0;
    int picker_slot_ = -1;
    int picker_col_ = 1; /* 0 the places, 1 the files */
    int picker_place_ = 0, picker_sel_ = 0, picker_top_ = 0;
    std::string picker_root_, picker_path_;
    std::vector<Place> places_;
    std::vector<FsEntry> entries_;
    bool picker_err_ = false;
    int picker_mode_ = 0; /* 0 a game's disc image, 1 the ShadowMountPlus payload */
    std::string picker_ext_; /* a "file" slot's extensions, for list_dir */

    /* "Delete this game?" */
    bool confirm_ = false;
    int confirm_sel_ = 0; /* 0 Cancel, 1 Delete; a game with saves: 0 Cancel, 1 Keep my saves, 2 Delete everything */
    bool confirm_saves_ = false; /* whether it offered Keep my saves when it opened */
    float confirm_t_ = 0;

    /* How fast the current job goes: MB/s over the last second or so. */
    std::string speed_step_;
    double speed_t_ = 0;
    std::uint64_t speed_done_ = 0;
    float speed_ = 0;

    /* Patch notes on the game page: the box has the focus (Left from the panel), is open,
     * how far it is scrolled, and its lines laid out for the current notes. */
    bool notes_focus_ = false, notes_open_ = false;
    float notes_t_ = 0;
    int notes_top_ = 0, notes_visible_ = 0; /* notes_visible_: the furthest it scrolls */
    std::string notes_key_;
    struct NoteLine
    {
        int kind; /* 0 a release's title, 1 its date, 2 a heading, 3 text, 4 a bullet, 5 a gap */
        std::string text;
    };
    std::vector<NoteLine> notes_lines_;

    /* Updating the store itself (Latest Updates): waiting for its installer to start. */
    bool store_updating_ = false;
    float store_upd_t_ = 0, store_upd_poll_ = 0;
    std::string store_note_;
    bool store_note_bad_ = false;

    /* Open pressed: waiting for the launcher to start (then the store closes). */
    bool opening_ = false;
    float opening_t_ = 0, opening_poll_ = 0;
    bool open_checked_ = false;

    int gen_row_ = 0;          /* Settings > General: 0 sounds, 1 the ShadowMountPlus file, 2 start it */
    std::string settings_note_;
    bool settings_note_bad_ = false;

    /* (1.1.0) Settings > Redeem a code: the keypad over the store. key_sel_ is the key
     * focused, 1 2 3 / 4 5 6 / 7 8 9 / Delete 0 Redeem (0..11); key_view_ 0 typing, 1 the
     * site is checking it, 2 unlocked (Open it, Done). redeem_note_: how the last code
     * went, shown in Settings after the keypad has closed. */
    bool keypad_ = false;
    float keypad_t_ = 0;
    int key_sel_ = 0, key_view_ = 0, key_done_sel_ = 0;
    std::string key_digits_, key_msg_, key_app_;
    bool key_bad_ = false;
    std::string redeem_note_;
    bool redeem_note_bad_ = false;

    /* (1.1.0) Send logs: the box over a game's page. logs_sel_ 0 the message, 1 Cancel, 2 Send;
     * logs_view_ 0 ready, 1 sending, 2 sent, 3 didn't go (logs_result_ says why). The files it
     * takes are found once, when it opens. */
    bool logs_ = false;
    float logs_t_ = 0;
    int logs_sel_ = 0, logs_view_ = 0;
    std::string logs_app_, logs_msg_, logs_result_;
    std::vector<LogFile> logs_files_;
    /* The keyboard for the message: the key focused (rows 0-3 of 11 keys, row 4 Shift, Space,
     * Delete, Done), and a capital next. */
    bool kb_ = false;
    float kb_t_ = 0;
    int kb_row_ = 1, kb_col_ = 0;
    bool kb_caps_ = false;
};
} // namespace recomp
