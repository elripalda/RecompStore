/* Recomp Store - what the player chose to keep (/data/recompstore/prefs.json): Settings >
 * Customize, favorites, the Library's views, the tabs, auto-update, What's new seen.
 * (2.2.2) Its own file, so the desktop tests can read and write it as the store does.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <string>
#include <vector>

namespace recomp
{
struct StorePrefs
{
    int start_tab = 0;    /* a Store::Tab kind; 0 Discover */
    int lib_view = 0;     /* My Apps: 0 grid, 1 list */
    int lib_order = 0;    /* 0 name, 1 recently installed, 2 updates first */
    bool pins_on_discover = true; /* (2.2.2) "Favorites on Discover" (pins are favorites now) */
    int backdrop = 0;     /* 0 the game's art, 1 calm (the gradient only) */
    bool reduced_motion = false;
    bool advanced = false;
    bool sounds = true;
    std::vector<std::string> pins; /* favorites, newest first */
    /* (2.2.2) */
    int icon_colors = 0;            /* the category icons: 0 the logo's cyan, 1 a colour each */
    bool tabs_custom = false;       /* tabs: the player's own (else the catalog's) */
    std::vector<std::string> tabs;  /* when tabs_custom: the tabs shown, in order: "games", "emulators",
                                       "apps", "tools", "source:<a source's value>" */
    std::vector<std::string> source_tabs; /* sources pinned as tabs while tabs_custom is off */
    int con_view = 0;               /* On this PS5: 0 grid, 1 list */
    int con_sort = 0;               /* 0 name, 1 biggest, 2 newest, 3 where it is */
    std::string con_filter = "all"; /* "all", "store" (from Recomp Store), "other", or a homebrew folder */
    int my_filter = 0;              /* My Apps: 0 everything, 1 favorites */
    bool auto_all = false;          /* Update everything automatically */
    std::vector<std::string> auto_ids; /* apps updated automatically on their own */
    std::vector<std::string> seen_news; /* What's new versions already shown */
    std::string last_root;          /* the last homebrew folder picked for an install */
    std::string last_version;       /* the store version that last saved these */
};

/* false when there's no file (or it isn't one the store wrote): out keeps its defaults. */
bool load_prefs_file(const std::string &path, StorePrefs &out);
/* Written whole to path.new, then put in place. */
bool save_prefs_file(const std::string &path, const StorePrefs &p);
/* What's new is shown on its own once: on the store version it's about, not seen yet, and not on a
 * store just installed for the first time (fresh: there were no prefs before it started). */
bool whats_new_due(const std::string &running, const std::string &notes_version, const std::vector<std::string> &seen, bool fresh);
/* The site's What's new text as paragraphs and bullets: each piece's kind (0 a paragraph's line,
 * 1 a bullet, 2 a gap between them) and its words. Lines next to each other make one paragraph;
 * "- " starts a bullet; a blank line ends either. Nothing else is markup. */
struct NewsPiece
{
    int kind = 0;
    std::string text;
};
std::vector<NewsPiece> news_pieces(const std::string &body);
} // namespace recomp
