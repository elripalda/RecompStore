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
    void preview_settings(int section) { tab_ = 3; section_ = section; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; }
    void preview_boot(float t) { booted_ = false; boot_t_ = t; }
    void preview_picker(const std::string &slot, const std::string &path);
    void preview_focus(int f) { page_focus_ = f; }
    void preview_choose(const std::string &slot, const std::string &image) { chosen_[page_id_ + "/" + slot] = image; }
    void preview_row(int row, int col) { row_ = row; col_ = col; }
    void preview_confirm() { confirm_ = true; confirm_t_ = 1; confirm_sel_ = 0; }
    void preview_notes(int mode, int top = 0) { notes_focus_ = mode >= 1; notes_open_ = mode >= 2; notes_t_ = notes_open_ ? 1.f : 0.f; notes_top_ = top; confirm_ = false; }
    void preview_general(int row) { tab_ = 3; section_ = 0; in_section_ = true; gen_row_ = row; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; page_open_ = false; page_t_ = 0; confirm_ = false; }
    void preview_speed(float mbs) { speed_ = mbs; }
    void preview_notice(const std::string &t, bool bad) { notice_ = t; notice_bad_ = bad; }
    void preview_settings_note(const std::string &t, bool bad) { settings_note_ = t; settings_note_bad_ = bad; }

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
        enum Kind { Primary, Slot, Setup, Delete } kind;
        int slot = -1;
    };

    bool pressed(std::uint32_t bits) const { return (held_ & ~prev_ & bits) != 0; }
    bool nav(std::uint32_t bit, float &timer, float dt);
    void sync_catalog();
    Art &art(const App &a);
    const App *find(const std::string &id) const;
    std::string state_of(const App &a, bool *update_ready) const;
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
    int row_ = 1;     /* Discover: 0 the banner, 1 the games */
    int col_ = 0;
    float focus_anim_[64] = {};
    int slide_ = 0;
    float slide_t_ = 0, slide_fade_ = 1;
    int section_ = 0;    /* Settings: 0 General, 1 Adding your game, 2 About */
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
    std::map<std::string, std::string> chosen_; /* "<id>/<slot>" -> the disc image picked */

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

    /* "Delete this game?" */
    bool confirm_ = false;
    int confirm_sel_ = 0; /* 0 Cancel, 1 Delete */
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
};
} // namespace recomp
