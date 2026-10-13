/* Recomp Store - reading catalog.json.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_engine.hpp"
#include "store_json.hpp"

#include <algorithm>

namespace recomp
{
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
    const Json &apps = j["apps"];
    for (std::size_t i = 0; i < apps.size(); ++i)
    {
        const Json &x = apps[i];
        App a;
        a.id = x["id"].str();
        if (a.id.empty() || a.id.find('/') != std::string::npos || a.id.find("..") != std::string::npos) continue;
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
                s.required = slots[k]["required"].boolean(true);
                s.multiple = slots[k]["multiple"].boolean(false);
                a.slots.push_back(s);
            }
        }
        out.apps.push_back(a);
    }
    return true;
}

} // namespace recomp
