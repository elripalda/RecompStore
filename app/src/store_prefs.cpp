/* Recomp Store - prefs.json, What's new's rules and its text.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_prefs.hpp"
#include "store_json.hpp"

#include <algorithm>
#include <cstdio>

namespace recomp
{
namespace
{
std::string json_text(const std::string &s)
{
    std::string out = "\"";
    for (const unsigned char c : s)
    {
        if (c == '"' || c == '\\') { out += '\\'; out += char(c); }
        else if (c < 0x20) { char b[8]; std::snprintf(b, sizeof b, "\\u%04x", c); out += b; }
        else out += char(c);
    }
    return out + "\"";
}

std::string json_list(const std::vector<std::string> &v)
{
    std::string out = "[";
    for (std::size_t i = 0; i < v.size(); ++i) out += (i ? "," : "") + json_text(v[i]);
    return out + "]";
}

/* A list of short plain strings, each once, at most most of them. */
void read_list(const Json &a, std::vector<std::string> &out, std::size_t most, std::size_t longest)
{
    out.clear();
    for (std::size_t i = 0; i < a.size() && out.size() < most; ++i)
    {
        const std::string s = a[i].str();
        bool plain = !s.empty() && s.size() <= longest;
        for (const unsigned char c : s) plain = plain && c >= 0x20 && c != 0x7F;
        if (plain && std::find(out.begin(), out.end(), s) == out.end()) out.push_back(s);
    }
}

bool tab_entry_ok(const std::string &t)
{
    return t == "games" || t == "emulators" || t == "apps" || t == "tools" || (t.rfind("source:", 0) == 0 && t.size() > 7);
}

int version_part(const std::string &v, std::size_t &at)
{
    int n = 0;
    while (at < v.size() && v[at] >= '0' && v[at] <= '9' && n < 100000) n = n * 10 + (v[at++] - '0');
    if (at < v.size() && v[at] == '.') ++at;
    return n;
}

/* "2.2.2" and "v2.2.2.0" are the same version. */
bool same_version(const std::string &a, const std::string &b)
{
    std::size_t i = (!a.empty() && (a[0] == 'v' || a[0] == 'V')) ? 1 : 0, j = (!b.empty() && (b[0] == 'v' || b[0] == 'V')) ? 1 : 0;
    if (i >= a.size() || j >= b.size()) return false;
    for (int k = 0; k < 4; ++k)
        if (version_part(a, i) != version_part(b, j)) return false;
    return i >= a.size() && j >= b.size();
}
} // namespace

bool load_prefs_file(const std::string &path, StorePrefs &out)
{
    std::string text;
    std::FILE *f = std::fopen(path.c_str(), "rb");
    if (!f) return false;
    char b[4096];
    std::size_t n;
    while ((n = std::fread(b, 1, sizeof b, f)) > 0 && text.size() < (256u << 10)) text.append(b, n);
    std::fclose(f);
    Json j;
    if (!Json::parse(text, j) || !j.is_object()) return false;
    StorePrefs p;
    const int st = int(j["startTab"].integer(0));
    p.start_tab = st == 1 || (st >= 4 && st <= 7) ? st : 0; /* Library, Games, Emulators, Apps, Tools; else Discover */
    p.lib_view = std::clamp(int(j["libraryView"].integer(0)), 0, 1);
    p.lib_order = std::clamp(int(j["libraryOrder"].integer(0)), 0, 2);
    p.pins_on_discover = j["pinsOnDiscover"].boolean(true);
    p.backdrop = std::clamp(int(j["backdrop"].integer(0)), 0, 1);
    p.reduced_motion = j["reducedMotion"].boolean(false);
    p.advanced = j["advanced"].boolean(false);
    p.sounds = j["sounds"].boolean(true);
    p.music = j["music"].boolean(true);
    p.music_volume = std::clamp(int(j["musicVolume"].integer(40)), 0, 100) / 10 * 10;
    read_list(j["pins"], p.pins, 64, 64);
    p.icon_colors = std::clamp(int(j["iconColors"].integer(0)), 0, 1);
    p.tabs_custom = j["tabsCustom"].boolean(false);
    {
        std::vector<std::string> t;
        read_list(j["tabs"], t, 24, 300);
        for (const std::string &x : t)
            if (tab_entry_ok(x)) p.tabs.push_back(x);
        read_list(j["sourceTabs"], t, 16, 300);
        for (const std::string &x : t)
            if (tab_entry_ok("source:" + x)) p.source_tabs.push_back(x);
    }
    if (!p.tabs_custom) p.tabs.clear();
    p.con_view = std::clamp(int(j["consoleView"].integer(0)), 0, 1);
    p.all_sort = std::clamp(int(j["librarySort"].integer(0)), 0, 3);
    p.con_sort = std::clamp(int(j["consoleSort"].integer(0)), 0, 3);
    p.con_filter = j["consoleFilter"].str("all");
    if (p.con_filter.empty() || p.con_filter.size() > 64) p.con_filter = "all";
    p.my_filter = std::clamp(int(j["myFilter"].integer(0)), 0, 1);
    p.auto_all = j["autoAll"].boolean(false);
    read_list(j["autoUpdate"], p.auto_ids, 256, 64);
    read_list(j["seenNews"], p.seen_news, 32, 24);
    p.last_root = j["lastRoot"].str();
    if (p.last_root.size() > 64) p.last_root.clear();
    p.last_version = j["lastVersion"].str();
    if (p.last_version.size() > 24) p.last_version.clear();
    out = p;
    return true;
}

bool save_prefs_file(const std::string &path, const StorePrefs &p)
{
    auto yes = [](bool b) { return b ? "true" : "false"; };
    std::string t = "{";
    t += "\"startTab\":" + std::to_string(p.start_tab);
    t += ",\"libraryView\":" + std::to_string(p.lib_view);
    t += ",\"libraryOrder\":" + std::to_string(p.lib_order);
    t += std::string(",\"pinsOnDiscover\":") + yes(p.pins_on_discover);
    t += ",\"backdrop\":" + std::to_string(p.backdrop);
    t += std::string(",\"reducedMotion\":") + yes(p.reduced_motion);
    t += std::string(",\"advanced\":") + yes(p.advanced);
    t += std::string(",\"sounds\":") + yes(p.sounds);
    t += std::string(",\"music\":") + yes(p.music) + ",\"musicVolume\":" + std::to_string(p.music_volume);
    t += ",\"pins\":" + json_list(p.pins);
    t += ",\"iconColors\":" + std::to_string(p.icon_colors);
    t += std::string(",\"tabsCustom\":") + yes(p.tabs_custom);
    t += ",\"tabs\":" + json_list(p.tabs_custom ? p.tabs : std::vector<std::string>{});
    t += ",\"sourceTabs\":" + json_list(p.source_tabs);
    t += ",\"consoleView\":" + std::to_string(p.con_view);
    t += ",\"librarySort\":" + std::to_string(p.all_sort);
    t += ",\"consoleSort\":" + std::to_string(p.con_sort);
    t += ",\"consoleFilter\":" + json_text(p.con_filter);
    t += ",\"myFilter\":" + std::to_string(p.my_filter);
    t += std::string(",\"autoAll\":") + yes(p.auto_all);
    t += ",\"autoUpdate\":" + json_list(p.auto_ids);
    t += ",\"seenNews\":" + json_list(p.seen_news);
    t += ",\"lastRoot\":" + json_text(p.last_root);
    t += ",\"lastVersion\":" + json_text(p.last_version);
    t += "}\n";
    const std::string tmp = path + ".new";
    std::FILE *f = std::fopen(tmp.c_str(), "wb");
    if (!f) return false;
    const bool ok = std::fwrite(t.data(), 1, t.size(), f) == t.size();
    if (std::fclose(f) == 0 && ok && std::rename(tmp.c_str(), path.c_str()) == 0) return true;
    std::remove(tmp.c_str());
    return false;
}

bool whats_new_due(const std::string &running, const std::string &notes_version, const std::vector<std::string> &seen, bool fresh)
{
    if (fresh || notes_version.empty() || !same_version(running, notes_version)) return false;
    for (const std::string &s : seen)
        if (same_version(s, notes_version)) return false;
    return true;
}

std::vector<NewsPiece> news_pieces(const std::string &body)
{
    std::vector<NewsPiece> out;
    int last = -1;
    bool ended = true;
    std::size_t at = 0;
    while (at <= body.size() && out.size() < 400)
    {
        std::size_t end = body.find('\n', at);
        if (end == std::string::npos) end = body.size();
        std::string line = body.substr(at, end - at);
        at = end + 1;
        while (!line.empty() && (line.back() == '\r' || line.back() == ' ' || line.back() == '\t')) line.pop_back();
        std::size_t lead = 0;
        while (lead < line.size() && line[lead] == ' ') ++lead;
        if (lead == line.size())
        {
            ended = true;
            if (end == body.size()) break;
            continue;
        }
        const bool bullet = line.compare(0, 2, "- ") == 0;
        const int kind = bullet ? 1 : 0;
        if (!out.empty() && (ended || kind != last)) out.push_back({2, ""});
        out.push_back({kind, bullet ? line.substr(2) : line});
        last = kind;
        ended = false;
        if (end == body.size()) break;
    }
    return out;
}
} // namespace recomp
