/* Recomp Store - reading catalog.json.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_engine.hpp"
#include "store_json.hpp"
#include "ui_app_common.hpp"

#include <algorithm>
#include <cctype>

namespace recomp
{
/* True when version a is newer than b ("v1.0.10" > "1.0.9"; a pre-release tag's
 * "-beta" part is ignored). */
bool version_newer(const std::string &a, const std::string &b)
{
    auto parts = [](std::string v) {
        std::vector<long> out;
        std::size_t at = (!v.empty() && (v[0] == 'v' || v[0] == 'V')) ? 1 : 0;
        while (at < v.size() && out.size() < 4)
        {
            long n = 0;
            bool digits = false;
            while (at < v.size() && v[at] >= '0' && v[at] <= '9') { n = n * 10 + (v[at++] - '0'); digits = true; }
            if (!digits) break;
            out.push_back(n);
            if (at < v.size() && v[at] == '.') ++at;
            else break;
        }
        while (out.size() < 4) out.push_back(0);
        return out;
    };
    return parts(a) > parts(b);
}

bool store_too_old(const App &a)
{
    return !a.min_store.empty() && version_newer(a.min_store, kVersion);
}

bool plain_leaf(const std::string &n)
{
    if (n.empty() || n.size() > 128 || n[0] == '.') return false;
    for (const char c : n)
        if (c == '/' || c == '\\' || c == ':' || static_cast<unsigned char>(c) < 0x20 || c == 0x7F) return false;
    return true;
}

bool plain_path(const std::string &p)
{
    if (p.empty() || p.size() > 200) return false;
    if (p[0] == '/' && p.rfind("/data/", 0) != 0) return false;
    std::size_t at = p[0] == '/' ? 1 : 0;
    while (at <= p.size())
    {
        std::size_t end = p.find('/', at);
        if (end == std::string::npos) end = p.size();
        const std::string part = p.substr(at, end - at);
        if (part.empty() || part == "." || part == "..") return false;
        at = end + 1;
    }
    for (const char c : p)
        if (c == '\\' || static_cast<unsigned char>(c) < 0x20 || c == 0x7F) return false;
    return true;
}

bool slot_kind_known(const std::string &kind)
{
    return kind == "iso" || kind == "stfs" || kind == "image" || kind == "file" || kind == "arcade";
}

bool asset_matches(const std::string &pattern, const std::string &name)
{
    /* * any run of characters, ? any one; everything else as it is. */
    std::size_t p = 0, n = 0, star = std::string::npos, mark = 0;
    while (n < name.size())
    {
        if (p < pattern.size() && (pattern[p] == '?' || pattern[p] == name[n])) { ++p; ++n; }
        else if (p < pattern.size() && pattern[p] == '*') { star = p++; mark = n; }
        else if (star != std::string::npos) { p = star + 1; n = ++mark; }
        else return false;
    }
    while (p < pattern.size() && pattern[p] == '*') ++p;
    return p == pattern.size();
}

bool parse_catalog(const std::string &text, Catalog &out)
{
    Json j;
    if (!Json::parse(text, j) || !j["apps"].is_array()) return false;
    out = Catalog();
    out.updated = j["updated"].str();
    const Json &store = j["store"];
    out.store_version = store["version"].str();
    out.store_build = int(store["build"].integer());
    out.store_url = store["url"].str();
    out.store_sha256 = store["sha256"].str();
    const Json &site = j["site"];
    out.tagline = site["tagline"].str();
    out.discord = site["discord"].str();
    out.kofi = site["kofi"].str();
    out.payload = site["payload"].str();
    for (std::size_t i = 0; i < j["featured"].size(); ++i) out.featured.push_back(j["featured"][i].str());
    /* "apps": every store sees them. "newerStoreApps": games that need Recomp Store 1.0.3 or
     * newer (each says which in "minStore"), which older stores don't read at all.
     * "comingSoon" (1.0.6): entries that can't be installed yet, read the same way. */
    auto add = [&](const Json &x, bool soon) {
        App a;
        a.id = x["id"].str();
        if (a.id.empty() || a.id.find('/') != std::string::npos || a.id.find("..") != std::string::npos) return;
        for (const App &o : out.apps)
            if (o.id == a.id) return; /* listed twice: the first */
        for (const App &o : out.soon)
            if (o.id == a.id) return;
        a.soon = soon;
        a.date = x["date"].str();
        for (std::size_t k = 0; k < x["saves"].size(); ++k)
        {
            const std::string p = x["saves"][k].str();
            if (plain_path(p) && a.saves.size() < 16) a.saves.push_back(p);
        }
        a.min_store = x["minStore"].str();
        a.order = int(x["order"].integer(int(soon ? out.soon.size() : out.apps.size()) + 1000));
        a.name = x["name"].str(a.id);
        a.kind = x["kind"].str("community");
        a.author = x["author"].str();
        a.blurb = x["blurb"].str();
        a.description = x["description"].str();
        a.news = x["news"].str();
        a.credits = x["credits"].str();
        a.version = x["version"].str();
        a.released = x["released"].str();
        a.size = x["size"].str();
        a.needs = x["needs"].str();
        a.build = int(x["build"].integer());
        a.source = x["source"]["type"].str("github");
        a.repo = x["source"]["repo"].str();
        a.asset = x["source"]["asset"].str();
        a.inside = x["source"]["inside"].str();
        a.url = x["url"].str();
        a.sha256 = x["sha256"].str();
        a.icon_url = x["icon"].str();
        a.hero_url = x["hero"].str();
        a.hero_web_url = x["heroWeb"].str();
        a.banner_x = int(x["banner"]["x"].integer(50));
        a.banner_y = int(x["banner"]["y"].integer(30));
        a.banner_zoom = int(x["banner"]["zoom"].integer(100));
        const Json &logo = x["logo"];
        if (logo.is_object())
        {
            a.logo_url = logo["url"].str();
            auto place = [](const Json &p, LogoPlace &out) {
                out.x = int(std::clamp<long long>(p["x"].integer(0), -1920, 1920));
                out.y = int(std::clamp<long long>(p["y"].integer(0), -1080, 1080));
                out.scale = int(std::clamp<long long>(p["scale"].integer(100), 20, 300));
            };
            place(logo["banner"], a.logo_banner);
            place(logo["page"], a.logo_page);
        }
        const Json &setup = x["setup"];
        if (setup.is_object())
        {
            a.has_setup = true;
            a.data_dir = setup["dataDir"].str();
            const Json &slots = setup["slots"];
            for (std::size_t k = 0; k < slots.size(); ++k)
            {
                Slot s;
                s.key = slots[k]["key"].str();
                s.label = slots[k]["label"].str();
                s.kind = slots[k]["kind"].str("iso");
                s.to = slots[k]["to"].str(".");
                s.title_id = slots[k]["titleId"].str();
                s.disc_id = slots[k]["discId"].str();
                s.ext = slots[k]["ext"].str();
                s.pick = slots[k]["pick"].str();
                s.also = slots[k]["also"].str();
                s.name = slots[k]["name"].str();
                s.sha1 = slots[k]["sha1"].str();
                s.made = slots[k]["made"].str();
                s.n64 = slots[k]["n64"].boolean(false);
                {
                    /* (1.0.8) A set-up file, and a Title Update the store may download. */
                    const std::string r = slots[k]["ready"].str();
                    if (plain_path(r) && r[0] != '/') s.ready = r;
                    auto hex40 = [](std::string h) {
                        for (char &c : h) c = char(std::tolower(static_cast<unsigned char>(c)));
                        if (h.size() != 40) return std::string();
                        for (char c : h)
                            if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'))) return std::string();
                        return h;
                    };
                    s.update_id = hex40(slots[k]["updateId"].str());
                    s.update_sha1 = hex40(slots[k]["updateSha1"].str());
                }
                s.need = int(std::clamp<long long>(slots[k]["need"].integer(1), 1, 64));
                /* A name or a made file that isn't one plain name: a slot this store won't set up. */
                if (s.kind == "file" && ((!s.name.empty() && !plain_leaf(s.name)) || (!s.made.empty() && !plain_leaf(s.made))))
                    s.kind = "file-bad";
                if (!plain_leaf(s.key)) continue; /* its setup record is named after it */
                s.required = slots[k]["required"].boolean(true);
                s.multiple = slots[k]["multiple"].boolean(false);
                a.slots.push_back(s);
            }
        }
        if (soon)
        {
            a.kind = "soon";
            out.soon.push_back(a);
        }
        else
            out.apps.push_back(a);
    };
    for (std::size_t i = 0; i < j["apps"].size(); ++i) add(j["apps"][i], false);
    for (std::size_t i = 0; i < j["newerStoreApps"].size(); ++i) add(j["newerStoreApps"][i], false);
    for (std::size_t i = 0; i < j["comingSoon"].size(); ++i) add(j["comingSoon"][i], true);
    /* Both lists back in the admin's order. */
    std::stable_sort(out.apps.begin(), out.apps.end(), [](const App &x, const App &y) { return x.order < y.order; });
    std::stable_sort(out.soon.begin(), out.soon.end(), [](const App &x, const App &y) { return x.order < y.order; });

    /* Discover's sections (1.0.6). A kind or layout this store doesn't know is left out,
     * and so is a spotlight on a game that isn't in the catalog. */
    const Json &secs = j["discover"]["sections"];
    for (std::size_t i = 0; i < secs.size() && out.sections.size() < 32; ++i)
    {
        const Json &x = secs[i];
        Section s;
        s.type = x["type"].str("games");
        s.title = x["title"].str();
        s.layout = x["layout"].str(s.type == "games" ? "row" : s.type == "soon" ? "row" : "");
        s.all = x["all"].boolean(false);
        for (std::size_t k = 0; k < x["games"].size(); ++k) s.ids.push_back(x["games"][k].str());
        if (s.type == "spotlight" && s.ids.empty()) s.ids.push_back(x["game"].str());
        const bool known_layout = s.layout == "row" || s.layout == "grid" || s.layout == "wide";
        if (s.type == "games" && known_layout) out.sections.push_back(s);
        else if (s.type == "soon" && (s.layout == "row" || s.layout == "wide")) out.sections.push_back(s);
        else if (s.type == "spotlight" && !s.ids.empty())
        {
            bool there = false;
            for (const App &a : out.apps) there = there || a.id == s.ids[0];
            if (there) out.sections.push_back(s);
        }
    }
    return true;
}

} // namespace recomp
