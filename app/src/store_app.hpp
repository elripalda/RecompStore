/* Recomp Store - the app's screens and state.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include "porpoise_pad.hpp"
#include "store_engine.hpp"
#include "store_prefs.hpp"
#include "ui_gfx.hpp"

#include <cstdint>
#include <map>
#include <string>
#include <vector>

namespace recomp
{
/* (1.0.8) What chosen_ holds for a Title Update the store downloads itself: never a path. */
constexpr const char *kDownloadChoice = "\x01" "download";
/* (2.3.0) The file chooser's first row: Upload from your phone (never a path). */
constexpr const char *kPhoneChoice = "\x01" "phone";
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
    void preview_tab(int tab) { inst_.clear(); tab_ = tab; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; }
    void preview_open(const std::string &id);
    void preview_settings(int section) { tab_ = 3; section_ = section; in_section_ = false; booted_ = true; boot_t_ = 10; tab_t_ = 1; enter_t_ = 1; }
    void preview_boot(float t) { booted_ = false; boot_t_ = t; }
    void preview_picker(const std::string &slot, const std::string &path);
    void preview_focus(int f) { page_focus_ = f; }
    void preview_choose(const std::string &slot, const std::string &image) { chosen_[page_id_ + "/" + slot] = image; }
    void preview_row(int row, int col) { row_ = row; col_ = col; page_open_ = false; page_t_ = 0; confirm_ = false; }
    /* (2.3.0) Discover's row with this title (its first, if none has it). */
    void preview_row_titled(const std::string &t, int col)
    {
        const auto lines = discover_lines();
        int r = 0;
        for (int i = 0; i < int(lines.size()); ++i)
            if (lines[std::size_t(i)].title == t) { r = i; break; }
        preview_row(r, col);
    }
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
                      int kb_row = 1, int kb_col = 0, int cat = -1)
    {
        open_logs(page_id_);
        logs_cat_ = cat; logs_chip_ = std::max(0, cat);
        logs_t_ = 1; logs_msg_ = msg; logs_sel_ = sel; logs_view_ = view; logs_result_ = result;
        kb_ = kb; kb_t_ = 1; kb_row_ = kb_row; kb_col_ = kb_col; kb_mode_ = 0;
    }
    void preview_logs_close() { logs_ = kb_ = false; }
    void preview_redeem_note(const std::string &t, bool bad) { redeem_note_ = t; redeem_note_bad_ = bad; }
    /* (2.0) The title ID guard's question over the open game's page. */
    void preview_clash(const std::string &with, const std::string &fresh, bool pkg, int sel = 0)
    {
        clash_ = true; clash_t_ = 1; clash_sel_ = sel;
        clash_job_ = JobState(); clash_job_.app_id = page_id_; clash_job_.clash = true;
        clash_job_.clash_with = with; clash_job_.clash_new = fresh; clash_job_.clash_pkg = pkg;
    }
    void preview_clash_close() { clash_ = false; }
    /* (2.0) The Refresh pill at the top right: 1 refreshing, 2 done, 3 failed. */
    void preview_refresh(int st) { refresh_shown_ = true; refresh_preview_ = st; refresh_t_ = 0; }

    /* (2.0) The Library's views (0 My Apps, 1 Updates, 2 Betas, (2.2) 3 On this PS5) and a category tab. */
    void preview_library(int view, int row = 0) { preview_tab(Library); lib_view_ = view; col_ = row; lib_bar_ = false; }
    void preview_category(int tab, int chip = 0, int row = 0, bool chips = false)
    {
        preview_tab(tab); cat_chip_ = chip; col_ = row; cat_on_chips_ = chips;
    }
    void preview_customize(int row) { preview_settings(SecCustomize); in_section_ = true; cus_row_ = row; }
    void preview_pin(const std::string &id) { toggle_pin(id); }
    void preview_queue(const std::vector<std::string> &ids, int done) { for (const std::string &i : ids) engine().queue_add(i); queue_total_ = int(ids.size()) + done; queue_done_ = done; }
    void preview_prefs(int lib_view, bool advanced) { prefs_.lib_view = lib_view; prefs_.advanced = advanced; }
    /* (2.0) Settings > Link a phone and Sources: the row focused; a sheet over the store (1 a
     * phone sent a source to add, 2 a source's choices); the keyboard in a mode (1 a source,
     * 2 why it's reported). */
    void preview_link(int row) { preview_settings(SecLink); in_section_ = row >= 0; link_row_ = std::max(0, row); }
    void preview_sources(int row) { preview_settings(SecSources); in_section_ = row >= 0; src_row_ = std::max(0, row); }
    void preview_sheet(int kind, const std::string &value, int sel = 0) { sheet_ = true; sheet_t_ = 1; sheet_kind_ = kind; sheet_value_ = value; sheet_sel_ = sel; }
    void preview_sheet_close() { sheet_ = false; }
    void preview_kb(int mode, const std::string &text, int row, int col)
    {
        kb_mode_ = mode; kb_text() = text; kb_ = true; kb_t_ = 1; kb_row_ = row; kb_col_ = col; kb_caps_ = false;
    }
    void preview_src_note(const std::string &t, bool bad) { src_note_ = t; src_note_bad_ = bad; }
    /* (2.1) Versions on the open app's page: the row focused, and the question before it
     * installs (ask; its button 0 Cancel, 1 Install). */
    void preview_versions(int sel, bool ask = false, int ask_sel = 1);
    void preview_versions_close() { vers_ = vers_ask_ = false; }
    /* (2.1) Where the open app downloads from changed: the question, its button focused. */
    void preview_moved(const std::string &from, const std::string &to, int sel = 1)
    {
        moved_ = true; moved_t_ = 1; moved_sel_ = sel;
        moved_job_ = JobState(); moved_job_.app_id = page_id_; moved_job_.source_moved = true;
        moved_job_.source_old = from; moved_job_.source_new = to;
    }
    void preview_moved_close() { moved_ = false; }
    /* (2.1) Discover's header in another style (its games: the featured ones when empty), and a
     * part of the store (category_index) sorted another way. */
    void preview_header(const std::string &style, const std::vector<std::string> &games = {})
    {
        catalog_.header_style = style; catalog_.header_games = games; slide_ = 0; slide_prev_ = -1; slide_t_ = 0; slide_fade_ = 1;
    }
    /* (2.2.2) The header turning from the slide before cur to cur, fade of the way through (0..1). */
    void preview_slide(int cur, float fade) { slide_ = cur; slide_prev_ = -2; slide_t_ = 0; slide_fade_ = fade; }
    void preview_sort(int category, const std::string &mode) { catalog_.cat_sort[std::clamp(category, 0, 3)] = mode; }
    /* (2.2) Library > On this PS5: the row focused, the sort, a note; the App manager's sheet over it
     * for the app in that row (stage as mng_stage_, its button focused). */
    void preview_console(int row, int sort = 0, const std::string &note = "", bool bad = false)
    {
        preview_library(3, row); prefs_.con_sort = sort; lib_note_ = note; lib_note_bad_ = bad; mng_ = false; console_rev_ = ~0u;
    }
    void preview_manage(int stage, int sel)
    {
        sync_console();
        const auto list = console_list();
        const int i = col_;
        if (i < 0 || i >= int(list.size())) return;
        open_manage(*list[std::size_t(i)]);
        mng_t_ = 1;
        if (stage == 1) { mng_places_.clear(); places_t_ = -1; for (const Place &p : install_places_now()) if (p.path != mng_app_.root) mng_places_.push_back(p); }
        if (stage == 2) mng_fresh_ = engine().free_title_id();
        mng_stage_ = stage;
        mng_sel_ = sel;
    }
    /* (2.2.1) The lost-storage box (or, box false, what's left once it's been seen). */
    void preview_lost(bool box) { lost_told_ = true; lost_ = box; lost_t_ = 1; }
    /* (2.2.2) */
    void preview_panel(int kind, int sel = 0) { open_panel(kind); panel_t_ = 1; panel_sel_ = sel; }
    void preview_panel_close() { panel_ = 0; }
    void preview_place(const std::string &id, int sel) { place_app_ = id; place_version_.clear(); open_panel(PanelPlace); panel_t_ = 1; panel_sel_ = sel; }
    void preview_console2(int col, int view, int sort, const std::string &filter, int chip = -1)
    {
        preview_library(3, std::max(0, col)); prefs_.con_view = view; prefs_.con_sort = sort; prefs_.con_filter = filter;
        con_on_row_ = chip >= 0; con_chip_ = std::max(0, chip); mng_ = false; console_rev_ = ~0u;
    }
    void preview_select(const std::vector<std::string> &folders) { con_select_ = true; con_marked_ = folders; }
    void preview_select_off() { con_select_ = false; con_marked_.clear(); }
    void preview_mine(int col, int filter, int view, bool on_row = false) { preview_library(0, col); prefs_.my_filter = filter; prefs_.lib_view = view; my_on_row_ = on_row; }
    void preview_related(int row, int col) { page_rel_ = row; page_rel_col_ = col; page_scroll_ = page_scroll_to_ = -1; }
    void preview_prefs2(int icon_colors, bool favs_on_discover) { prefs_.icon_colors = icon_colors; prefs_.pins_on_discover = favs_on_discover; }
    void preview_auto(const std::vector<std::string> &ids, bool all) { prefs_.auto_ids = ids; prefs_.auto_all = all; }
    void preview_finished(const std::string &id, int state, const std::string &text) { finished_.insert(finished_.begin(), Finished{id, state, text, JobState()}); }
    void preview_tabs(const std::vector<std::string> &tabs, bool custom) { prefs_.tabs = tabs; prefs_.tabs_custom = custom; }
    void preview_smp_row(int row) { preview_settings(SecSmp); in_section_ = row >= 0; smp_row_ = std::max(0, row); }
    void preview_storage_row(int row) { preview_settings(SecStorage); in_section_ = row >= 0; sto_row_ = std::max(0, row); }
    void preview_customize_top(int row) { preview_customize(row); }
    /* (2.2.3) The patch notes as laid out: how far the widest line goes past its box (0 none), and how
     * many lines there are. */
    float preview_notes_overflow(int *lines = nullptr) const;
    void preview_all_sort(int sort, bool focus_sort) { prefs_.all_sort = sort; cat_on_chips_ = focus_sort; cat_sort_on_ = focus_sort; }
    void preview_drives(int sel, int col = 0, int app = 0) { preview_library(4, 0); drv_sel_ = sel; drv_col_ = col; drv_app_ = app; }
    void preview_drive_move(int sel)
    {
        sync_console();
        const auto apps = drive_apps(engine().drives()[std::size_t(drv_sel_)].homebrew);
        if (apps.empty()) return;
        open_manage(*apps[std::size_t(std::clamp(drv_app_, 0, int(apps.size()) - 1))]);
        mng_t_ = 1; mng_places_.clear();
        for (const Place &p : install_places_now()) if (p.path != mng_app_.root) mng_places_.push_back(p);
        mng_stage_ = 1; mng_sel_ = sel; mng_direct_ = true;
    }
    /* (2.3.0) */
    void preview_upload(int mode, int slot) { open_upload(mode, page_id_, slot); panel_t_ = 1; }
    void preview_upload_status(const ReceiverStatus &st, const std::string &code) { up_st_ = st; up_code_ = code; up_why_.clear(); up_poll_ = 1e9f; }
    void preview_saves(const std::vector<Backup> &list, int sel, int ask = 0, int ask_sel = 1)
    {
        open_saves(page_id_); sv_list_ = list; panel_t_ = 1; panel_sel_ = sel; sv_ask_ = ask; sv_ask_sel_ = ask_sel;
    }
    void preview_ask(const LinkAction &a, const ConsoleApp &c) { rq_ = a; rq_app_ = c; open_panel(PanelAsk); panel_t_ = 1; panel_sel_ = 1; }
    void preview_backup_first(bool on) { backup_first_ = on; }
    void preview_collection(const std::string &id, int stage = 0, int sel = 0) { open_collection(id); panel_t_ = 1; co_stage_ = stage; panel_sel_ = sel; if (stage) co_places_ = install_places_now(); }
    void preview_search(const std::string &text, bool kb) { preview_category(AllApps, 0, 0, true); cat_search_on_ = true; search_ = text; kb_ = false; if (kb) { open_kb(3); kb_t_ = 1; } }
    void preview_myapps(int stage, const MyAppsList &l, int sel = 0) { open_myapps(); panel_t_ = 1; ma_stage_ = stage; ma_list_ = l; panel_sel_ = sel; }
    void preview_mods(int sel, int ask = 0) { open_mods(page_id_); panel_t_ = 1; panel_sel_ = sel; md_ask_ = ask; md_ask_sel_ = 0; }
    void preview_feedback(const std::string &msg, int pick, int sel) { open_feedback(page_id_); logs_t_ = 1; logs_msg_ = msg; logs_cat_ = pick; logs_chip_ = std::max(0, pick); logs_sel_ = sel; }
    void preview_shimmer(bool on) { shimmer_test_ = on; }
    void preview_scroll_page(float y) { page_scroll_ = page_scroll_to_ = y; }

private:
    /* The kinds of tab (2.0: Updates is a view of the Library now; Games, Emulators and Apps
     * are shown when the catalog has something in them). tab_ holds a kind; tab_order() the
     * kinds in the order the tab bar shows them. */
    /* (2.2.2) kSourceTab + i: a source the player pinned as a tab (src_tabs_[i]). */
    /* (2.2.3) Library (kind AllApps) is the whole store now, by part; what's on the console is My
     * Apps (kind Library, kept for what it was: its views Installed, Updates, On this PS5, Drives, Betas). */
    enum Tab { Discover = 0, Library = 1, Updates = 2, Settings = 3, Games = 4, Emulators = 5, Apps = 6, Tools = 7, AllApps = 8,
               kSourceTab = 100 };
    /* Settings' sections, by kind; settings_order() lists them as shown. */
    enum SetSec { SecGeneral = 0, SecAdding = 1, SecRedeem = 2, SecAbout = 3, SecCustomize = 4, SecLink = 5, SecSources = 6,
                  SecStorage = 7 /* (2.2.2) */, SecSmp = 8 /* (2.2.2) */ };
    std::vector<int> tab_order() const;
    std::vector<int> settings_order() const;
    std::string tab_name(int kind) const;
    static const char *category_of(int kind); /* "games", "emulators", "apps", "tools"; "" for others */
    static bool is_category(int kind) { return kind == Games || kind == Emulators || kind == Apps || kind == Tools || kind == AllApps || kind >= kSourceTab; }
    void go_tab(int kind, int dir);

    /* (2.0) What the player chose to keep: Settings > Customize, the favorites, menu sounds, (2.2.2)
     * the Library's views, the tabs, auto-update. /data/recompstore/prefs.json (store_prefs.cpp). */
    using Prefs = StorePrefs;
    void load_prefs();
    void save_prefs();
    bool pinned(const std::string &id) const;
    void toggle_pin(const std::string &id);

    /* (2.0) The Library, the category tabs and Update All (store_app_library.cpp). */
    std::vector<const App *> library_apps() const;  /* My Apps, in the chosen order, pins first */
    std::vector<const App *> update_apps() const;   /* installed, with an update ready */
    std::vector<const App *> beta_apps() const;
    std::vector<const App *> category_apps(int kind) const; /* with the chip's label applied */
    /* (2.1) Apps of one part of the store in the order the catalog says for it (Catalog::cat_sort). */
    void sort_apps(const std::string &category, std::vector<const App *> &apps) const;
    void sort_by(const std::string &mode, std::vector<const App *> &apps) const; /* (2.2.3) */
    bool cat_sort_on_ = false; /* (2.2.3) Library: its Sort chip focused */
    /* (2.2.3) My Apps > Drives: each drive and what's at its top (read-only); a USB drive coming or
     * going while the store is open. */
    bool update_drives(float dt, int moves, bool u, bool d);
    void draw_drives(porpoise::ui::Gfx &gfx, float slide);
    void watch_usb();
    int drv_sel_ = 0;
    int drv_col_ = 0, drv_app_ = 0; /* (2.3.0) 1: the drive's apps (Cross moves one) */
    std::vector<const ConsoleApp *> drive_apps(const std::string &homebrew) const;
    std::string move_saves_app() const;
    bool mng_direct_ = false; /* (2.3.0) the sheet opened straight on Move (from Drives): Circle closes it */
    float drv_scroll_ = 0;
    unsigned usb_mask_ = 0;
    float usb_t_ = -10;
    bool usb_known_ = false;
    std::vector<std::string> category_chips(int kind) const; /* "All" then the labels used */
    std::vector<int> library_views() const;          /* 0 My Apps, 1 Updates, 2 Betas (when there are some), (2.2) 3 On this PS5 */
    bool update_library(float dt, int moves, bool u, bool d);
    bool update_category(float dt, int moves, bool u, bool d);
    void draw_library2(porpoise::ui::Gfx &gfx);
    void draw_category(porpoise::ui::Gfx &gfx);
    /* A game as a wide row (the Library's list and Updates): icon, name, version, a line, the button. */
    void draw_app_row(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float focus, const std::string &line,
                      const std::string &button, int button_style);
    void draw_tab_header(porpoise::ui::Gfx &gfx, const std::string &title, const std::string &count);
    void start_update_all();
    void tick_queue();
    /* A tile's pin, top right (2.0). */
    void draw_pin(porpoise::ui::Gfx &gfx, float x, float y);
    void draw_customize(porpoise::ui::Gfx &gfx, float cx, float top, float cw);
    std::string square_hint() const; /* what Square does here, "" nothing */
    std::string cross_hint() const;
    bool update_customize(float dt, bool l, bool r, bool u, bool d);
    std::string install_label() const; /* (2.0) where new apps install, by name */
    const std::vector<Place> &install_places_now() const;
    mutable std::vector<Place> places_now_;
    mutable float places_t_ = -1;
    struct Art
    {
        porpoise::ui::Texture *icon = nullptr, *hero = nullptr, *blur = nullptr, *logo = nullptr;
        bool icon_wait = false, hero_wait = false, blur_wait = false, logo_wait = false;
        std::string icon_file, hero_file, logo_file;
    };
    /* The game page's controls, top to bottom: what the D-pad moves between. */
    struct Control
    {
        enum Kind { Primary, Slot, Setup, Delete, Logs /* (1.1.0) Send logs */, Report /* (2.0) a custom app's source */,
                    Versions /* (2.1) */, Favorite /* (2.2.2) */, AutoUpdate /* (2.2.2) */, Saves /* (2.3.0) */,
                    Like /* (2.3.0) */, Feedback /* (2.3.0) a beta's */, Mods /* (2.3.0) */ } kind;
        int slot = -1;
    };
    /* Discover (1.0.6), as the lines the D-pad moves between, top to bottom: the featured
     * banner, then the catalog's sections (a grid is a line per six games). */
    struct DLine
    {
        int kind = 0;        /* 0 the featured banner, 1 a row of tiles, 2 a line of a grid, 3 wide tiles, 4 a spotlight,
                                (2.0) 5 the category buttons */
        int style = 0;       /* (2.1) kind 0: the header's style (header_style()) */
        float y = 0, h = 0;  /* in Discover's own space: 0 is its top */
        float head = 0;      /* the section's title above it: its height (0 none) */
        std::string title;   /* the title; a spotlight's names it */
        std::vector<const App *> items;
        std::vector<int> cats; /* kind 5: the category tabs, in order */
        std::string coll;      /* (2.3.0) a collection's row: Cross opens the collection */
        int count() const { return kind == 5 ? int(cats.size()) : int(items.size()); }
    };

    bool pressed(std::uint32_t bits) const { return (held_ & ~prev_ & bits) != 0; }
    bool nav(std::uint32_t bit, float &timer, float dt);
    void sync_catalog();
    Art &art(const App &a);
    const App *find(const std::string &id) const; /* a game, or a Coming soon entry */
    std::vector<DLine> discover_lines() const;
    bool on_collection_row() const; /* (2.3.0) Discover's focus is on a collection's row */
    /* Discover: the first item of a line that scrolls sideways that's shown, with col
     * focused (or last focused) on it; and where item i of it is drawn. */
    int line_first(const DLine &l, int col) const;
    float item_x(const DLine &l, int i, int first) const;
    std::string state_of(const App &a, bool *update_ready) const;
    /* (2.2.3) What's installed and when, as the engine last said. Asking the engine for each tile
     * every frame was hundreds of file reads a frame on the PS5 (a stat and a read each), which
     * made every screen lag, worst while a download kept the drive busy. Kept until something
     * changes (the catalog, a job, the console's apps); a few stale ones looked at again each frame. */
    std::string tile_line(const App &a, bool *update_ready = nullptr) const; /* (2.2.3) */
    const std::string &inst_v(const std::string &id) const;
    long long inst_time(const std::string &id) const;
    void inst_tick();
    struct Inst
    {
        std::string v;
        long long when = -2; /* -2 not asked yet */
        float at = 0;
    };
    mutable std::map<std::string, Inst> inst_;
    unsigned long long inst_key_ = ~0ull;
    std::string inst_last_app_; /* (2.2.3) the job's app when inst_key_ was made */
    std::string version_of(const App &a) const;
    std::vector<const App *> featured_apps() const;
    /* (2.1) Discover's header: its style (0 carousel, 1 spotlight, 2 split, 3 mosaic, 4 none) and
     * its games (the catalog's, else the featured ones; spotlight one, mosaic three). */
    int header_style() const;
    std::vector<const App *> header_apps() const;
    bool has_header() const { return header_style() != 4 && !header_apps().empty(); }
    bool had_header_ = true; /* Discover had the header last frame (row_ follows it coming or going) */
    /* The app the header shows where col is focused (0 the banner or the big card). */
    const App *header_focus(const DLine &l, int col) const;
    void draw_header(porpoise::ui::Gfx &gfx, const DLine &l, int line, float sy, bool on);
    /* A game beside the split header's banner: its picture, its name, a line about it. */
    void draw_header_card(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, float focus, float fade,
                          const App *prev = nullptr);
    /* One of the mosaic header's games: its art across the card, the words over it. */
    void draw_mosaic_card(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, float focus, bool big);
    /* (2.1) Kept at an older version the player picked: that version ("" when it isn't); and a
     * newer version than the one installed is out. */
    std::string kept_at(const App &a) const;
    bool newer_out(const App &a) const;
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

    void draw_backdrop(porpoise::ui::Gfx &gfx, float alpha = 1.f);
    /* (2.2.3) What scrolls fades out under the header above it and into the hints below: the
     * background drawn again over its edges, a little less in each strip. */
    void edge_fade(porpoise::ui::Gfx &gfx, float top, float bottom, bool at_top, bool at_bottom = true);
    void draw_hint_band(porpoise::ui::Gfx &gfx);
    void draw_top(porpoise::ui::Gfx &gfx);
    void draw_discover(porpoise::ui::Gfx &gfx);
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
                     float fade, bool drift, float focus, bool big = false /* (2.1) the spotlight header's bigger words */,
                     const App *prev = nullptr /* (2.2.2) the game it's turning from, drawn whole under it */);
    /* (2.2.2) A banner's art and words at alpha, without its frame: gw the words' side. */
    void banner_content(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, float gw, float R,
                        const std::string &kicker, float alpha, float words, bool drift, bool big);
    void header_card_content(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float h, float px, float py, float pw,
                             float ph, float fade, float words);
    /* "There's more below": an arrow at the bottom of a list that scrolls. */
    void draw_more(porpoise::ui::Gfx &gfx);
    void draw_soon_page(porpoise::ui::Gfx &gfx, const App &a, float k);
    void glass(porpoise::ui::Gfx &gfx, float x, float y, float w, float h, float r,
               porpoise::ui::Color fill, bool shadow = true);
    /* glow: the cyan outline with a soft glow (buttons on the game page, which can be white). */
    void ring(porpoise::ui::Gfx &gfx, float x, float y, float w, float h, float r, float amount, bool glow = false);
    void draw_confirm(porpoise::ui::Gfx &gfx);
    /* (2.0) The title ID guard's question: Keep both, Install over it, Cancel. */
    std::vector<int> clash_choices() const;
    bool update_clash(float dt);
    void draw_clash(porpoise::ui::Gfx &gfx);
    void draw_refresh(porpoise::ui::Gfx &gfx);
    /* The game's logo in a box (fitted, then sized and nudged as the catalog says):
     * the height it takes, or -1 when there's no logo (yet) and the name is drawn. */
    float draw_logo(porpoise::ui::Gfx &gfx, const App &a, const LogoPlace &place, float x, float y, float box_w,
                    float box_h, float alpha);
    /* The game page's What's new box opens into every release's notes from GitHub. (2.1) A
     * beta's: what's new in each of its builds. */
    bool has_notes_box(const App &a) const
    {
        return !a.news.empty() || (a.source == "github" && !a.repo.empty()) || (a.beta && !a.beta_notes.empty());
    }
    /* (2.1) The releases the page's notes show, as Engine::notes says (1, 0 coming, -1 none): a
     * GitHub app's, or a beta's builds. */
    int page_notes(const App &a, std::vector<Release> &out) const;
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
    porpoise::ui::Texture *hint_triangle_ = nullptr, *hint_square_ = nullptr; /* (2.0) Refresh, Pin */
    porpoise::ui::Texture *cat_icon_[4] = {}; /* (2.0) Games, Emulators, Apps, Tools */
    porpoise::ui::Texture *hfade_ = nullptr;  /* (2.0) white fading out to the right */
    porpoise::ui::Texture *vfade_ = nullptr;  /* (2.1) clear at the top to white at the bottom: a shade under words on art */
    void draw_cat_button(porpoise::ui::Gfx &gfx, int kind, float x, float y, float w, float h, float focus);
    /* (2.0) An app with no icon yet: its category's colour and icon, in a rounded square. */
    void draw_no_icon(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float size, float radius);
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
    int slide_prev_ = -1; /* (2.2.2) the slide it turned from (-1 none, -2 the one before slide_) */
    float slide_t_ = 0, slide_fade_ = 1;
    int section_ = 0;    /* Settings: 0 General, 1 Adding your game, 2 Redeem a code, 3 About */
    bool in_section_ = false;
    bool sounds_on_ = true;
    float nav_left_ = 0, nav_right_ = 0, nav_up_ = 0, nav_down_ = 0;
    std::string focus_id_;
    float backdrop_t_ = 1;
    /* (2.2.3) The background's soft tint, the focused app's main colour (its icon's, worked out
     * where it was decoded), eased in and out; and the glow round a focused icon. */
    bool shimmer_test_ = false; /* (2.2.3) the preview: tiles drawn as if their icons were loading */
    float dl_pill_x_ = 1920 - 96, tabs_right_ = 1534; /* (2.2.3) */ /* (2.2.3) where the downloads pill starts (the Refresh note goes left of it) */
    float tint_a_ = 0, tint_rgb_[3] = {0.13f, 0.83f, 0.93f}, tint_to_[3] = {0.13f, 0.83f, 0.93f};
    void draw_tint(porpoise::ui::Gfx &gfx, float alpha = 1.f);
    porpoise::ui::Color glow_of(const porpoise::ui::Texture *icon, float a) const;
    void draw_shimmer(porpoise::ui::Gfx &gfx, float x, float y, float w, float h, float r);
    void draw_switch(porpoise::ui::Gfx &gfx, float x, float cy, bool on, bool focus);
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
    float notes_w_ = 0; /* (2.2.3) the width they were laid out for */

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

    /* (2.0) The title ID guard: an install stopped because another app has the game's title
     * ID. The question over its page; clash_job_ is the job that stopped (who, which new ID);
     * clash_sel_ the choice focused, in clash_choices() (1 Keep both, 2 Install over it, 0 Cancel). */
    bool clash_ = false;
    float clash_t_ = 0;
    int clash_sel_ = 0;
    JobState clash_job_;
    /* (2.1) Versions, on an app's page: each version it can install, newest first, and the
     * question before one installs (vers_ask_: Cancel 0, Install 1). */
    struct VersionRow
    {
        std::string key;               /* what install() is given: a release's tag, a beta build's file ("" its current build) */
        std::string label, date, size; /* as shown */
        bool pre = false, installed = false, newest = false;
    };
    bool has_versions(const App &a) const;
    /* state as Engine::notes: 1 here they are, 0 still coming, -1 none to show. */
    std::vector<VersionRow> version_rows(const App &a, int &state) const;
    void open_versions(const App &a);
    bool update_versions(float dt);
    void draw_versions(porpoise::ui::Gfx &gfx);
    bool vers_ = false, vers_ask_ = false;
    float vers_t_ = 0, vers_ask_t_ = 0;
    int vers_sel_ = 0, vers_top_ = 0, vers_ask_sel_ = 1;
    std::string vers_app_;
    VersionRow vers_pick_;
    /* (2.1) Where an app downloads from changed (JobState::source_moved): the question over its
     * page; moved_sel_ 0 Update from the new place, 1 Cancel. */
    bool moved_ = false;
    float moved_t_ = 0;
    int moved_sel_ = 1;
    JobState moved_job_;
    bool update_moved(float dt);
    void draw_moved(porpoise::ui::Gfx &gfx);
    /* (2.0) Refresh (Triangle, or Settings > General): the pill at the top right while it
     * reads the catalog, then how it went for a moment. */
    bool refresh_shown_ = false;
    float refresh_t_ = 0;
    int refresh_preview_ = 0;

    /* (2.0) The tab bar's sliding highlight (where it is, where it's going). */
    float tab_hl_x_ = -1, tab_hl_w_ = 0;
    Prefs prefs_;
    bool prefs_loaded_ = false;
    /* The Library: its view, the bar of views focused (Up from the top row), and its note. */
    int lib_view_ = 0;
    bool lib_bar_ = false;
    float lib_view_t_ = 1;
    std::string lib_note_;
    bool lib_note_bad_ = false;
    /* A category tab: the label chip picked, and whether the chips row has the focus. */
    int cat_chip_ = 0;
    bool cat_on_chips_ = false;
    float cscroll_ = 0, cscroll_to_ = 0;
    /* Update All: how many in all, how many done, which failed; (2.2.2) the waiting ones are the
     * engine's queue (Engine::queue). */
    int queue_total_ = 0, queue_done_ = 0;
    std::string queue_now_;
    std::vector<std::string> queue_failed_;
    /* Settings > Customize: the row focused. */
    int cus_row_ = 0;
    /* (2.0) A short note at the top right ("Pinned"), for a couple of seconds. */
    std::string toast_;
    float toast_t_ = 9;
    void toast(const std::string &t) { toast_ = t; toast_t_ = 0; }
    bool start_applied_ = false; /* the start tab, once the catalog is in */

    /* (2.0) The rest of 2.0's screens (store_app_link.cpp). */
    Charts charts_;
    /* The keyboard types into one of three things (kb_mode_): 0 the logs' message, 1 a source to
     * add (a repo or a list's address), 2 why a source is reported. */
    int kb_mode_ = 0;
    std::string src_text_, src_why_;
    std::string &kb_text() { return kb_mode_ == 1 ? src_text_ : kb_mode_ == 2 ? src_why_ : kb_mode_ == 3 ? search_ : logs_msg_; }
    void open_kb(int mode);
    void kb_finished(); /* Done on the keyboard: what its text was for */
    /* Settings > Sources: the row focused (0 Add a source, then the sources), and how the last
     * thing done there went. A report is about src_report_value_; from a game page it says how it
     * went there (src_report_page_). */
    int src_row_ = 0;
    std::string src_note_;
    bool src_note_bad_ = false;
    std::string src_report_value_;
    bool src_report_page_ = false;
    void draw_sources(porpoise::ui::Gfx &gfx, float cx, float top, float cw, float bottom);
    bool update_sources(float dt, bool u, bool d);
    void report_source(const std::string &value, bool from_page);
    /* Settings > Link a phone: the row focused (0 New code, then the devices), a device asked
     * to be unlinked (Cross again unlinks it), and the QR code's texture for the text it shows. */
    int link_row_ = 0;
    std::string link_armed_;
    porpoise::ui::Texture *qr_ = nullptr;
    std::string qr_text_;
    void draw_qr(porpoise::ui::Gfx &gfx, const std::string &text, float x, float y, float size);
    void draw_link(porpoise::ui::Gfx &gfx, float cx, float top, float cw, float bottom);
    bool update_link(float dt, bool u, bool d);
    /* A sheet over the store: 1 "add this source?" (a phone sent it), 2 a source's choices
     * (Report, Remove, Close). sheet_sel_: the button focused. */
    bool sheet_ = false;
    float sheet_t_ = 0;
    int sheet_kind_ = 0, sheet_sel_ = 0;
    std::string sheet_value_, sheet_from_;
    bool update_sheet(float dt);
    void draw_sheet(porpoise::ui::Gfx &gfx);
    /* What linked phones sent, waiting for a moment nothing else is open over the store. */
    std::vector<LinkAction> link_waiting_;
    void take_link_actions();
    bool queue_fresh_ = false; /* the queue has installs, not only updates (a phone sent them) */
    void draw_toast(porpoise::ui::Gfx &gfx);
    std::size_t link_devices_ = std::size_t(-1); /* how many were linked (once known), to say when one more is */
    bool modal_open() const { return picker_ || keypad_ || kb_ || logs_ || clash_ || confirm_ || sheet_ || vers_ || moved_ || mng_ || lost_ || panel_; }
    bool logs_ready() const; /* (2.3.0) a category and a few words */
    /* (2.2) Library > On this PS5 (store_app_manager.cpp): every folder in the homebrew folders
     * (Engine::console_apps); (2.2.2) as a grid or a list, sorted and filtered (Prefs::con_*). */
    std::vector<ConsoleApp> console_;
    unsigned console_rev_ = ~0u;
    bool console_looking_ = false, console_asked_ = false;
    std::map<std::string, std::pair<porpoise::ui::Texture *, bool>> con_icons_; /* icon0.png -> its texture, still loading */
    void sync_console();
    std::vector<const ConsoleApp *> console_list() const;
    porpoise::ui::Texture *console_icon(const ConsoleApp &c);
    bool update_console(float dt, int moves, bool u, bool d);
    void draw_console(porpoise::ui::Gfx &gfx, float slide);
    void draw_console_row(porpoise::ui::Gfx &gfx, const ConsoleApp &c, float x, float y, float w, float focus);
    /* Refresh home screen (the Library's row, Settings > General): the launcher does it once the
     * store has closed, then opens the store again. Its words go in the Library or in Settings. */
    void start_refresh_home(bool from_lib);
    /* (2.2.1) Reload ShadowMountPlus (the Library's second row): through the launcher too, so the
     * store is closed when the new copy replaces the running one. */
    void start_reload_smp(bool from_lib = true);
    /* (2.2.1) The store lost the console's storage mid-session (Engine::storage_lost): said once,
     * in a box over everything; after that, where it matters (the Library's On this PS5). */
    bool lost_ = false, lost_told_ = false;
    float lost_t_ = 0;
    bool update_lost(float dt);
    void draw_lost(porpoise::ui::Gfx &gfx);
    /* The App manager's sheet over it: mng_stage_ 0 what to do (Open, Move, New ID, Delete), 1 where
     * to move it, 2 the new title ID's question, 3 Delete's. mng_job_: a job it started is going
     * (its result goes in the Library); mng_delete_name_: the app a store delete is for. */
    bool mng_ = false, mng_job_ = false;
    float mng_t_ = 0;
    int mng_stage_ = 0, mng_sel_ = 0;
    ConsoleApp mng_app_;
    std::string mng_fresh_, mng_delete_name_;
    std::vector<Place> mng_places_;
    void open_manage(const ConsoleApp &c);
    std::vector<std::string> manage_buttons() const;
    bool update_manage(float dt);
    void draw_manage(porpoise::ui::Gfx &gfx);
    bool take_manage_result(const JobState &job);
    /* Open (or Refresh home screen) started from the Library: what happens is said there. */
    bool opening_lib_ = false;
    bool refresh_checked_ = false; /* back from Refresh home screen: said once */
    /* A game page's line for an app from a custom source. */
    std::string source_line(const App &a) const;

    /* ---- (2.2.2) store_app_more.cpp ---- */
    /* Sheets over the store (panel_): the download queue, where to install, the storage view, What's
     * new, the tabs, deleting what's selected. panel_sel_: the row or button focused. */
    enum PanelKind { PanelNone = 0, PanelQueue, PanelPlace, PanelStorage, PanelNews, PanelTabs, PanelDelete,
                     PanelUpload /* (2.3.0) */, PanelSaves, PanelAsk, PanelCollection, PanelMyApps, PanelMods };
    int panel_ = 0;
    float panel_t_ = 0;
    int panel_sel_ = 0, panel_top_ = 0;
    void open_panel(int kind);
    bool update_panel(float dt);
    std::string panel_cross_hint() const;
    void draw_panel(porpoise::ui::Gfx &gfx);
    /* The queue: Install, Update and Update all add to it (the engine's, kept between launches); what
     * finished this session, newest first (state 0 done, 1 didn't finish, 2 needs the player's answer). */
    struct Finished
    {
        std::string id;
        int state = 0;
        std::string text;
        JobState job; /* state 2: the question it stopped on, asked again from Downloads */
    };
    std::vector<Finished> finished_;
    void finished(const std::string &id, int state, const std::string &text);
    /* Add an install to the queue (it starts at once when nothing else is going). false: it's there
     * already, or couldn't be added (said with a toast unless quiet). */
    bool enqueue(const App &a, const std::string &version, const std::string &root, bool quiet = false);
    /* Where an app is in the queue: 0 not in it, 1 going now, 2 the next, ... */
    int queue_place(const std::string &id) const;
    static std::string ordinal(int n);
    void draw_downloads(porpoise::ui::Gfx &gfx); /* the pill at the top right */
    bool downloads_shown() const;
    bool update_queue_panel(float dt);
    void draw_queue_panel(porpoise::ui::Gfx &gfx, float k);
    /* Where to install a new app: the places with their free space, the last one picked first. */
    std::string place_app_, place_version_;
    std::vector<Place> place_list_;
    /* Install pressed on a new app: the chooser when there's more than one place, else straight in. */
    void start_install(const App &a, const std::string &version);
    bool update_place_panel(float dt);
    void draw_place_panel(porpoise::ui::Gfx &gfx, float k);
    /* The storage view: each drive, what's on it, the biggest folders, Clear download cache. */
    bool update_storage_panel(float dt);
    void draw_storage_panel(porpoise::ui::Gfx &gfx, float k);
    void draw_drive_bars(porpoise::ui::Gfx &gfx, float x, float y, float w, bool compact);
    std::uint64_t cache_now_ = 0;
    std::size_t cache_files_ = 0;
    float cache_t_ = -9;
    void cache_look(bool now);
    void clear_cache(std::string &note, bool &bad);
    /* What's new: the site's notes for this version (else GitHub's), shown on their own once. */
    bool news_checked_ = false, fresh_ = false;
    void check_news();
    std::vector<NewsPiece> news_now(std::string &title, int &state) const;
    bool update_news_panel(float dt);
    void draw_news_panel(porpoise::ui::Gfx &gfx, float k);
    /* The tabs (Customize > Tabs): each one on or off, in order, and Back to the default. */
    struct TabRow
    {
        std::string entry; /* "games", ... or "source:<value>" */
        bool on = false;
    };
    std::vector<TabRow> tab_rows_;
    void build_tab_rows();
    void apply_tab_rows();
    std::vector<std::string> shown_tab_entries() const;
    mutable std::vector<std::string> src_tabs_; /* the sources pinned as tabs, as tab_order() last listed them */
    bool side_tab_ = false; /* a category whose tab is hidden, opened from Discover's buttons */
    std::string tab_entry_name(const std::string &entry) const;
    bool source_pinned(const std::string &value) const;
    void pin_source(const std::string &value, bool on);
    bool update_tabs_panel(float dt);
    void draw_tabs_panel(porpoise::ui::Gfx &gfx, float k);
    /* On this PS5: its row of views and actions (con_on_row_, con_chip_ the one focused), and
     * Select (the folders marked). */
    bool con_on_row_ = false;
    int con_chip_ = 0;
    bool con_select_ = false;
    std::vector<std::string> con_marked_;
    std::vector<std::string> console_chips() const;
    std::vector<std::string> console_filters() const;
    std::string filter_label(const std::string &f) const;
    bool console_match(const ConsoleApp &c) const;
    void press_console_chip(const std::string &chip);
    void draw_console_tile(porpoise::ui::Gfx &gfx, const ConsoleApp &c, float x, float y, float size, float focus);
    void draw_chip_row(porpoise::ui::Gfx &gfx, const std::vector<std::string> &chips, int focused, bool on, float y,
                       const std::string &right = "");
    bool update_delete_panel(float dt);
    void draw_delete_panel(porpoise::ui::Gfx &gfx, float k);
    /* My Apps: its row (All, Favorites, Grid or List). */
    bool my_on_row_ = false;
    int my_chip_ = 0;
    std::vector<std::string> my_chips() const;
    /* Settings > ShadowMountPlus and Storage. */
    int smp_row_ = 0, sto_row_ = 0;
    bool smp_reload_after_get_ = false;
    std::vector<std::string> smp_rows() const;
    void draw_smp_section(porpoise::ui::Gfx &gfx, float cx, float top, float cw, float bottom);
    bool update_smp_section(float dt, bool u, bool d);
    void draw_storage_section(porpoise::ui::Gfx &gfx, float cx, float top, float cw, float bottom);
    bool update_storage_section(float dt, bool u, bool d);
    std::string smp_warning() const; /* what's wrong with the running ShadowMountPlus, "" nothing */
    /* Auto-update: looked at each time the store has checked for updates. */
    unsigned auto_checks_ = 0;
    void tick_auto_update();
    bool auto_on(const std::string &id) const;
    void toggle_auto(const std::string &id);
    /* The app page's rows under it: More like this, People also installed, More from <author>. */
    struct RelRow
    {
        std::string title;
        std::vector<const App *> apps;
    };
    std::vector<RelRow> related_rows(const App &a) const;
    int page_rel_ = -1, page_rel_col_ = 0;
    float page_scroll_ = 0, page_scroll_to_ = 0;
    std::vector<std::string> page_back_; /* apps opened from those rows: Circle goes back to the one before */
    float draw_related(porpoise::ui::Gfx &gfx, const App &a, float top);
    bool update_related(float dt, const App &a, bool l, bool r, bool u, bool d);
    std::map<int, float> rel_focus_;
    mutable std::string rel_key_; /* related_rows' last answer, for this app and catalog */
    mutable std::vector<RelRow> rel_cache_;
    int cus_top_ = 0; /* Customize: the first row shown */

    /* ---- (2.3.0) store_app_23.cpp ---- */
    /* Upload from your phone (the receiver): 0 a game's file slot, 1 any file (Settings > Storage),
     * 2 a game's backups to and from the phone, 3 a list of apps. */
    int up_mode_ = 0, up_slot_ = -1, up_files_ = 0;
    std::string up_app_, up_code_, up_why_, up_last_;
    ReceiverStatus up_st_;
    float up_poll_ = 0;
    void open_upload(int mode, const std::string &app, int slot);
    void close_upload();
    bool update_upload_panel(float dt);
    void draw_upload_panel(porpoise::ui::Gfx &gfx, float k);
    /* A game's saves: its backups, and the question over one (1 restore, 2 delete, 3 which). */
    std::string sv_app_, sv_note_;
    bool sv_note_bad_ = false;
    std::vector<Backup> sv_list_;
    int sv_ask_ = 0, sv_ask_sel_ = 0;
    void open_saves(const std::string &id);
    std::vector<std::string> saves_buttons() const;
    bool update_saves_panel(float dt);
    void draw_saves_panel(porpoise::ui::Gfx &gfx, float k);
    /* Delete and Move: the saves backed up first (on unless the player turns it off). */
    bool backup_first_ = true;
    /* What a linked phone asks: Delete / Move wait for Delete or Ignore here; the job's result goes
     * back to the phone. */
    LinkAction rq_;
    ConsoleApp rq_app_;
    long long phone_req_ = 0;
    std::string phone_req_kind_, phone_req_app_;
    bool phone_action(const LinkAction &a);
    bool update_ask_panel(float dt);
    void draw_ask_panel(porpoise::ui::Gfx &gfx, float k);
    void phone_job_seen(const JobState &job);
    bool take_23_result(const JobState &job);
    float prefs_sync_t_ = 0;
    void sync_phone_prefs();
    /* Clear download cache, on the engine's thread: where its words go. */
    bool clearing_ = false;
    std::string *clear_note_ = nullptr;
    bool *clear_bad_ = nullptr;
    void tick_clear();
    /* Send logs: what happened (0 Bug .. 4 Other, -1 not picked yet), the chip focused; a beta's
     * Feedback (Works, Crashes, Slow, Other) instead. */
    int logs_cat_ = -1, logs_chip_ = 0;
    bool logs_fb_ = false;
    std::string ma_note_;
    void open_feedback(const std::string &id);
    /* Ready to play: what the game needs, each 1 ready, 0 not yet, 2 can't be told here. */
    struct Need
    {
        std::string label, why;
        int state = 2;
    };
    std::vector<Need> ready_items(const App &a) const;
    float ready_height(const App &a) const;
    void draw_ready(porpoise::ui::Gfx &gfx, const App &a, float x, float y, float w, float alpha);
    /* Collections: the one open, and (stage 1) where Install all puts them. */
    std::string co_id_;
    int co_stage_ = 0;
    std::vector<Place> co_places_;
    const Collection *collection(const std::string &id) const;
    void open_collection(const std::string &id);
    void install_all(const std::string &root);
    bool update_collection_panel(float dt);
    void draw_collection_panel(porpoise::ui::Gfx &gfx, float k);
    /* Library: Search (its pill between the parts and Sort), the text, and the match. */
    std::string search_;
    bool cat_search_on_ = false;
    bool search_match(const App &a) const;
    /* Restore my apps: 0 where from, 1 the list. */
    int ma_stage_ = 0;
    MyAppsList ma_list_;
    bool ma_note_bad_ = false, ma_waiting_ = false;
    void open_myapps();
    std::vector<std::string> myapps_sources() const;
    bool update_myapps_panel(float dt);
    void draw_myapps_panel(porpoise::ui::Gfx &gfx, float k);
    /* Mods: the app's, and the question over one (its actions). */
    std::string md_app_, md_note_;
    bool md_note_bad_ = false;
    int md_ask_ = 0, md_ask_sel_ = 0;
    void open_mods(const std::string &id);
    std::vector<std::string> mod_actions(const App &a, const App::Mod &m) const;
    bool update_mods_panel(float dt);
    void draw_mods_panel(porpoise::ui::Gfx &gfx, float k);
    float cus_scroll_ = 0, cus_scroll_to_ = 0; /* (2.2.3) Customize, scrolled (its groups' headings make it taller) */
};
} // namespace recomp
