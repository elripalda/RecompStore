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
/* Where a version's numbers start: after a "v" or "V", and after a "." "-" "_" or space
 * right behind it when a digit follows ("v1.0", "V.1.1.1", "v-2.0" all start at their 1
 * or 2). Anything else starts where it starts. */
std::size_t version_start(const std::string &v)
{
    auto digit = [&](std::size_t i) { return i < v.size() && v[i] >= '0' && v[i] <= '9'; };
    if (v.empty() || (v[0] != 'v' && v[0] != 'V')) return 0;
    if (digit(1)) return 1;
    if (v.size() > 2 && (v[1] == '.' || v[1] == '-' || v[1] == '_' || v[1] == ' ') && digit(2)) return 2;
    return 0;
}

bool version_newer(const std::string &a, const std::string &b)
{
    auto parts = [](std::string v) {
        std::vector<long> out;
        std::size_t at = version_start(v);
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
    /* (2.2.2) The category tabs the store shows by default, in order ("tabs"; absent: all four). */
    if (j["tabs"].is_array())
    {
        out.tabs_set = true;
        for (std::size_t i = 0; i < j["tabs"].size() && out.tabs.size() < 4; ++i)
        {
            const std::string t = j["tabs"][i].str();
            if ((t == "games" || t == "emulators" || t == "apps" || t == "tools") &&
                std::find(out.tabs.begin(), out.tabs.end(), t) == out.tabs.end())
                out.tabs.push_back(t);
        }
    }
    /* (2.2.2) The ShadowMountPlus the store offers ("smp"): only from drakmor's own releases. */
    {
        const Json &m = j["smp"];
        out.smp_min = m["min"].str();
        const std::string url = m["url"].str(), sha = m["sha256"].str(), ver = m["version"].str(), elf = m["elf"].str();
        const std::string pre = "https://github.com/drakmor/ShadowMountPlus/releases/download/";
        auto plain = [](const std::string &v, bool slash) {
            if (v.empty() || v.size() > 200 || v.find("..") != std::string::npos) return false;
            for (const char c : v)
                if (!(std::isalnum(static_cast<unsigned char>(c)) || c == '.' || c == '_' || c == '-' || (slash && c == '/'))) return false;
            return true;
        };
        bool hex = sha.size() == 64;
        for (const char c : sha) hex = hex && ((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'));
        const long long size = m["size"].integer(0);
        if (url.rfind(pre, 0) == 0 && plain(url.substr(pre.size()), true) && std::count(url.begin() + long(pre.size()), url.end(), '/') == 1 &&
            hex && size > 64 && size <= (64ll << 20) && plain(ver, false) && (elf.empty() || plain(elf, true)))
        {
            out.smp_version = ver;
            out.smp_url = url;
            out.smp_elf = elf;
            out.smp_asset = url.substr(url.rfind('/') + 1);
            out.smp_sha256 = sha;
            out.smp_size = std::uint64_t(size);
        }
    }
    /* (2.2.2) What's new, written on the site ("whats_new"): for players on that store version. */
    {
        const Json &w = j["whats_new"];
        const std::string v = w["version"].str(), body = w["body"].str();
        if (!v.empty() && v.size() <= 24 && !body.empty())
        {
            out.whats_new_version = v;
            out.whats_new_title = w["title"].str("What's new in " + v);
            out.whats_new_body = body.size() > 16000 ? body.substr(0, 16000) : body;
        }
    }
    /* (2.0) featuredV2: the admin's whole list, with apps 1.x stores don't get (v2Apps). */
    const Json &feat = j["featuredV2"].is_array() ? j["featuredV2"] : j["featured"];
    for (std::size_t i = 0; i < feat.size(); ++i) out.featured.push_back(feat[i].str());
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
        a.beta = !soon && x["beta"].boolean(false);
        for (std::size_t k = 0; k < x["saves"].size(); ++k)
        {
            const std::string p = x["saves"][k].str();
            if (plain_path(p) && a.saves.size() < 16) a.saves.push_back(p);
        }
        a.min_store = x["minStore"].str();
        /* (2.3.0) What it needs to be ready to play, and its mods (docs/store-2.3.md, 7 and 14). */
        for (std::size_t k = 0; k < x["requires"].size() && a.requires_.size() < 8; ++k)
        {
            const std::string r = x["requires"][k].str();
            if (r == "loader" || r == "smp" || r == "kstuff" || r == "etahen" || r == "internet" || r == "usb") a.requires_.push_back(r);
        }
        a.mods_dir = x["modsDir"].str();
        if (!a.mods_dir.empty() && (a.mods_dir[0] == '/' || !plain_path(a.mods_dir))) a.mods_dir.clear();
        for (std::size_t k = 0; k < x["mods"].size() && a.mods.size() < 64; ++k)
        {
            const Json &m = x["mods"][k];
            App::Mod md;
            md.id = m["id"].str().substr(0, 48);
            md.name = m["name"].str(md.id).substr(0, 120);
            md.author = m["author"].str().substr(0, 80);
            md.blurb = m["blurb"].str().substr(0, 400);
            md.repo = m["repo"].str().substr(0, 200);
            md.asset = m["asset"].str().substr(0, 260);
            md.sha256 = m["sha256"].str();
            md.target = m["target"].str();
            md.size = std::uint64_t(std::max<long long>(0, m["size"].integer(0)));
            bool id_ok = !md.id.empty();
            for (const unsigned char c : md.id)
                if (!(std::isalnum(c) || c == '-' || c == '_' || c == '.')) id_ok = false;
            for (const App::Mod &o : a.mods) id_ok = id_ok && o.id != md.id;
            if (id_ok && plain_leaf(md.target) && md.sha256.size() == 64) a.mods.push_back(md);
        }
        for (std::size_t k = 0; k < x["logs"].size() && a.logs.size() < 12; ++k)
        {
            /* (1.1.0) inside the game's folder only */
            const std::string p = x["logs"][k].str();
            if (plain_path(p) && p[0] != '/') a.logs.push_back(p);
        }
        for (std::size_t k = 0; k < x["keep"].size() && a.keep.size() < 32; ++k)
        {
            /* (1.1.2) inside the game's folder only */
            std::string p = x["keep"][k].str();
            while (p.size() > 1 && p.back() == '/') p.pop_back();
            if (plain_path(p) && p[0] != '/') a.keep.push_back(p);
        }
        {
            const long long b = x["bytes"].integer(0); /* (1.1.2) */
            a.bytes = b > 0 ? std::uint64_t(b) : 0;
        }
        {
            /* (2.0) Its part of the store; anything else is a game, as every entry was before. */
            const std::string c = x["category"].str("games");
            a.category = c == "emulators" || c == "apps" || c == "tools" ? c : "games";
            for (std::size_t k = 0; k < x["tags"].size() && a.tags.size() < 6; ++k)
            {
                const std::string t = x["tags"][k].str();
                if (!t.empty() && t.size() <= 24) a.tags.push_back(t);
            }
            a.can_move = x["canMove"].boolean(true);
            /* A custom source's app (source.php): its title ID when the site could read it,
             * and its repo's GitHub numbers. Whether it's custom is the engine's to say. */
            a.title_id = x["titleId"].str();
            if (a.title_id.size() > 16) a.title_id.clear();
            a.source_name = x["sourceName"].str().substr(0, 80);
            a.stars = x["stars"].integer(-1);
            a.downloads = x["downloads"].integer(-1);
            a.testing = x["testing"].boolean(false);
        }
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
        a.manual = x["manualSetup"].str(); /* (2.6) */
        if (a.manual.size() > 600) a.manual.resize(600);
        a.build = int(x["build"].integer());
        a.source = x["source"]["type"].str("github");
        a.repo = x["source"]["repo"].str();
        a.asset = x["source"]["asset"].str();
        a.inside = x["source"]["inside"].str();
        a.url = x["url"].str();
        a.sha256 = x["sha256"].str();
        a.sig = x["sig"].str(); /* (2.1) a beta's */
        /* (2.1) A beta's notes for its current build, and the earlier builds a tester can go back
         * to (each signed like the current one, checked when it's installed). */
        a.beta_notes = x["betaNotes"].str().substr(0, 4000);
        for (std::size_t k = 0; k < x["versions"].size() && a.beta_builds.size() < 20; ++k)
        {
            const Json &v = x["versions"][k];
            BetaBuild b;
            b.version = v["version"].str().substr(0, 40);
            b.file = v["file"].str().substr(0, 200);
            b.url = v["url"].str();
            b.sha256 = v["sha256"].str();
            b.sig = v["sig"].str();
            b.size = v["size"].str().substr(0, 24);
            b.notes = v["notes"].str().substr(0, 4000);
            b.date = v["date"].str().substr(0, 40);
            const long long n = v["bytes"].integer(0);
            b.bytes = n > 0 ? std::uint64_t(n) : 0;
            if (b.version.empty() || b.url.rfind("https://", 0) != 0) continue;
            a.beta_builds.push_back(b);
        }
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
                if (s.kind == "iso")
                {
                    /* (1.1.2) Part of the disc, and the files it must have. One that can't be
                     * read as meant: a slot this store won't set up (the whole disc would be
                     * the wrong thing to copy). */
                    bool bad = false;
                    auto add_only = [&](std::string p) {
                        while (p.size() > 1 && p.back() == '/') p.pop_back();
                        if (p.empty()) return;
                        if (!plain_path(p) || p[0] == '/' || s.only.size() >= 16) bad = true;
                        else s.only.push_back(p);
                    };
                    const Json &only = slots[k]["only"];
                    if (only.is_array())
                        for (std::size_t i = 0; i < only.size(); ++i) add_only(only[i].str());
                    else
                    {
                        std::string list = only.str(), part;
                        for (char &c : list) if (c == ',') c = ' ';
                        for (std::size_t i = 0; i <= list.size(); ++i)
                            if (i == list.size() || list[i] == ' ') { add_only(part); part.clear(); }
                            else part += list[i];
                    }
                    const Json &files = slots[k]["files"];
                    for (std::size_t i = 0; i < files.size() && !bad; ++i)
                    {
                        DiscWant w;
                        w.path = files[i]["path"].str();
                        const double size = files[i]["size"].num(-1);
                        const std::string crc = files[i]["crc32"].str();
                        if (!plain_path(w.path) || w.path[0] == '/' || size <= 0 || size > 4294967295.0 || size != double((long long)size) ||
                            s.files.size() >= 512)
                        {
                            bad = true;
                            break;
                        }
                        w.size = std::uint64_t(size);
                        if (!crc.empty())
                        {
                            char *end = nullptr;
                            const unsigned long v = std::strtoul(crc.c_str(), &end, 16);
                            if (crc.size() != 8 || !end || *end != '\0' || crc.find_first_not_of("0123456789abcdefABCDEF") != std::string::npos)
                            {
                                bad = true;
                                break;
                            }
                            w.crc = std::uint32_t(v);
                            w.has_crc = true;
                        }
                        s.files.push_back(w);
                    }
                    if (files.size() > 0 && !files.is_array()) bad = true;
                    s.wrong = slots[k]["wrong"].str().substr(0, 300);
                    if (bad) s.kind = "iso-bad";
                }
                s.need = int(std::clamp<long long>(slots[k]["need"].integer(1), 1, 64));
                /* A name or a made file that isn't one plain name: a slot this store won't set up. */
                if (s.kind == "file" && ((!s.name.empty() && !plain_leaf(s.name)) || (!s.made.empty() && !plain_leaf(s.made))))
                    s.kind = "file-bad";
                /* (2.0.1) A Title Update copied in as it is: set up once it's there, by the name
                 * it's given. A name that isn't one plain name: a slot this store won't set up. */
                if (s.kind == "stfs" && slots[k]["raw"].boolean(false) && !slots[k]["multiple"].boolean(false))
                {
                    s.raw = true;
                    if (!s.name.empty() && !plain_leaf(s.name)) s.kind = "file-bad";
                    else if (s.ready.empty() && !s.name.empty()) s.ready = s.name;
                }
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
    /* (2.0) Entries only 2.0 stores read at all: emulators, apps, tools and anything else that
     * needs 1.9.1 or newer (1.x stores never see them, not even as "update the store"). */
    for (std::size_t i = 0; i < j["v2Apps"].size(); ++i) add(j["v2Apps"][i], false);
    for (std::size_t i = 0; i < j["comingSoon"].size(); ++i) add(j["comingSoon"][i], true);
    for (std::size_t i = 0; i < j["comingSoonV2"].size(); ++i) add(j["comingSoonV2"][i], true); /* (2.0) not games */
    /* Both lists back in the admin's order. */
    std::stable_sort(out.apps.begin(), out.apps.end(), [](const App &x, const App &y) { return x.order < y.order; });
    std::stable_sort(out.soon.begin(), out.soon.end(), [](const App &x, const App &y) { return x.order < y.order; });

    /* (2.1) How each part of the store is sorted. One this store doesn't know: the admin's order. */
    {
        const char *cats[4] = {"games", "emulators", "apps", "tools"};
        for (int i = 0; i < 4; ++i)
        {
            const std::string s = j["categories"][cats[i]]["sort"].str("manual");
            out.cat_sort[i] = s == "az" || s == "installs" || s == "new" || s == "updated" ? s : "manual";
        }
    }
    /* (2.1) Discover's header. A style this store doesn't know: the carousel, as before. */
    {
        const Json &h = j["discover"]["header"];
        const std::string st = h["style"].str("carousel");
        out.header_style = st == "spotlight" || st == "split" || st == "mosaic" || st == "none" ? st : "carousel";
        for (std::size_t k = 0; k < h["games"].size() && out.header_games.size() < 12; ++k)
        {
            const std::string id = h["games"][k].str();
            if (!id.empty() && id.size() <= 64) out.header_games.push_back(id);
        }
        out.header_interval = int(std::clamp<long long>(h["interval"].integer(8), 4, 30));
    }

    /* (2.3.0) Collections: a name, a line, apps in order (only ones in the catalog). */
    for (std::size_t i = 0; i < j["collections"].size() && out.collections.size() < 32; ++i)
    {
        const Json &x = j["collections"][i];
        Collection c;
        c.id = x["id"].str().substr(0, 48);
        c.title = x["title"].str().substr(0, 80);
        c.blurb = x["blurb"].str().substr(0, 300);
        for (std::size_t k = 0; k < x["ids"].size() && c.ids.size() < 60; ++k)
        {
            const std::string id = x["ids"][k].str();
            bool there = false;
            for (const App &a : out.apps) there = there || a.id == id;
            if (there && std::find(c.ids.begin(), c.ids.end(), id) == c.ids.end()) c.ids.push_back(id);
        }
        bool dup = false;
        for (const Collection &o : out.collections) dup = dup || o.id == c.id;
        if (!c.id.empty() && !c.title.empty() && !c.ids.empty() && !dup) out.collections.push_back(c);
    }

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
        if ((s.type == "collection" || s.type == "liked") && s.layout.empty()) s.layout = "row"; /* (2.3.0) */
        const bool known_layout = s.layout == "row" || s.layout == "grid" || s.layout == "wide";
        if (s.type == "collection")
        {
            /* (2.3.0) one of the catalog's collections, as a row */
            s.collection = x["collection"].str();
            bool there = false;
            for (const Collection &c : out.collections) there = there || c.id == s.collection;
            if (there && known_layout) out.sections.push_back(s);
        }
        else if (s.type == "liked")
        {
            /* (2.3.0) Most liked, from the charts; one part of the store's when it says */
            const std::string c = x["category"].str();
            if (c == "games" || c == "emulators" || c == "apps" || c == "tools") s.category = c;
            if (known_layout) out.sections.push_back(s);
        }
        else if (s.type == "category")
        {
            s.category = x["category"].str();
            if (known_layout && (s.category == "games" || s.category == "emulators" || s.category == "apps" || s.category == "tools"))
                out.sections.push_back(s);
        }
        else if (s.type == "games" && known_layout) out.sections.push_back(s);
        else if ((s.type == "top" || s.type == "trending" || s.type == "new" || s.type == "updated") && known_layout)
        {
            /* (2.0) filled from the charts; (2.2.2) only one part of the store's, when it says so */
            const std::string c = x["category"].str();
            if (c == "games" || c == "emulators" || c == "apps" || c == "tools") s.category = c;
            out.sections.push_back(s);
        }
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

/* (2.2.2) ShadowMountPlus's version strings (store_engine.hpp). */
int smp_version_cmp(const std::string &a, const std::string &b)
{
    struct V
    {
        std::vector<long> nums;
        long beta = -1; /* -1: no beta part (newer than any beta) */
        long fix = 0;
        bool ok = false;
    };
    auto parse = [](std::string s) {
        V v;
        for (char &c : s) c = char(std::tolower(static_cast<unsigned char>(c)));
        std::size_t i = 0;
        while (i < s.size() && (s[i] == 'v' || s[i] == ' ')) ++i;
        if (i >= s.size() || !std::isdigit(static_cast<unsigned char>(s[i]))) return v;
        while (i < s.size() && std::isdigit(static_cast<unsigned char>(s[i])))
        {
            long n = 0;
            while (i < s.size() && std::isdigit(static_cast<unsigned char>(s[i])) && n < 100000) n = n * 10 + (s[i++] - '0');
            v.nums.push_back(n);
            if (i < s.size() && s[i] == '.' && i + 1 < s.size() && std::isdigit(static_cast<unsigned char>(s[i + 1]))) ++i;
            else break;
        }
        const std::size_t bpos = s.find("beta", i);
        const std::size_t git = s.find("-g", i); /* "1.7beta5-3-gabc123": a build after it */
        if (bpos != std::string::npos && (git == std::string::npos || bpos < git))
        {
            std::size_t k = bpos + 4;
            long n = 0;
            while (k < s.size() && std::isdigit(static_cast<unsigned char>(s[k])) && n < 100000) n = n * 10 + (s[k++] - '0');
            v.beta = n;
            const std::size_t f = s.find("fix", k);
            if (f != std::string::npos && (git == std::string::npos || f < git))
            {
                std::size_t m = f + 3;
                long x = 0;
                while (m < s.size() && std::isdigit(static_cast<unsigned char>(s[m])) && x < 100000) x = x * 10 + (s[m++] - '0');
                v.fix = x;
            }
        }
        v.ok = !v.nums.empty();
        return v;
    };
    const V x = parse(a), y = parse(b);
    if (!x.ok || !y.ok) return 2;
    for (std::size_t i = 0; i < std::max(x.nums.size(), y.nums.size()); ++i)
    {
        const long p = i < x.nums.size() ? x.nums[i] : 0, q = i < y.nums.size() ? y.nums[i] : 0;
        if (p != q) return p < q ? -1 : 1;
    }
    if (x.beta != y.beta)
    {
        if (x.beta < 0) return 1;
        if (y.beta < 0) return -1;
        return x.beta < y.beta ? -1 : 1;
    }
    if (x.fix != y.fix) return x.fix < y.fix ? -1 : 1;
    return 0;
}

} // namespace recomp
