/* Recomp Store 2.3.0 - the engine's new parts (docs/store-2.3.md), included at the end of
 * store_engine.cpp so they share its state (g) and helpers.
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace recomp
{
namespace
{
/* ---- Small things ---------------------------------------------------------------- */

/* A line for a job file or a status: no line breaks, no control characters, at most max bytes. */
std::string one_line(const std::string &s, std::size_t max)
{
    std::string o;
    for (const unsigned char c : s)
        if (c >= 0x20 && c != 0x7F && o.size() < max) o += char(c);
    return o;
}

/* A folder the store hands a payload to read or write: absolute, on the console's storage or a
 * drive, spelled plainly. */
bool folder_plain(const std::string &p)
{
    if (p.size() < 6 || p[0] != '/' || p.find("..") != std::string::npos || p.find("//") != std::string::npos || p.back() == '/')
        return false;
    for (const unsigned char c : p)
        if (c < 0x20 || c == 0x7F || c == '\\') return false;
#ifdef RECOMP_ENGINE_TEST
    if (p.rfind("/tmp/", 0) == 0) return true;
#endif
    return p.rfind("/data/", 0) == 0 || p.rfind("/mnt/usb", 0) == 0 || p.rfind("/mnt/ext", 0) == 0;
}

/* Digits nobody can guess from outside: SHA-256 over the clocks, the process, a counter and the
 * last digest, each digit from a byte below 250 (no bias). */
std::string random_digits(int n)
{
    static std::string last;
    static unsigned counter = 0;
    std::string out;
    while (int(out.size()) < n)
    {
        Sha256 h;
        timespec a{}, b{};
        clock_gettime(CLOCK_REALTIME, &a);
        clock_gettime(CLOCK_MONOTONIC, &b);
        const unsigned long long stack = (unsigned long long)(std::uintptr_t)&a;
        const unsigned long long seed[] = {(unsigned long long)a.tv_sec, (unsigned long long)a.tv_nsec, (unsigned long long)b.tv_sec,
                                           (unsigned long long)b.tv_nsec, (unsigned long long)getpid(), stack, ++counter};
        h.update(reinterpret_cast<const std::uint8_t *>(seed), sizeof seed);
        h.update(reinterpret_cast<const std::uint8_t *>(last.data()), last.size());
        last = h.hex();
        for (std::size_t i = 0; i + 1 < last.size() && int(out.size()) < n; i += 2)
        {
            const int v = std::stoi(last.substr(i, 2), nullptr, 16);
            if (v < 250) out += char('0' + v % 10);
        }
    }
    return out;
}

std::string time_text(const char *fmt, bool utc, std::time_t t = 0)
{
    if (!t) t = std::time(nullptr);
    const std::tm *tm = utc ? std::gmtime(&t) : std::localtime(&t);
    char b[64] = "";
    if (tm) std::strftime(b, sizeof b, fmt, tm);
    return b;
}

std::string json_esc(const std::string &v)
{
    std::string o;
    for (const unsigned char ch : v)
        if (ch == '"' || ch == '\\') { o += '\\'; o += char(ch); }
        else if (ch >= 0x20) o += char(ch);
    return o;
}

App app_copy(const std::string &id, bool &found)
{
    std::lock_guard<std::mutex> l(g.m);
    for (const App &a : g.catalog.apps)
        if (a.id == id)
        {
            found = true;
            return a;
        }
    found = false;
    return App();
}

/* ---- Link a phone: its requests, and what the screens keep ----------------------- */
std::mutex g23_m;
std::vector<std::string> g_favs, g_autos;
bool g_auto_all = false;
struct PhoneReq
{
    long long id = 0;
    std::string kind, app, state, text;
    double at = 0;
};
std::vector<PhoneReq> g_reqs;
std::atomic<bool> g_push{false};

std::string link_requests_json()
{
    std::lock_guard<std::mutex> l(g23_m);
    std::string out;
    const double now = mono_s();
    for (const PhoneReq &r : g_reqs)
    {
        if (now - r.at > 86400) continue;
        out += std::string(out.empty() ? "" : ",") + "{\"id\":" + std::to_string(r.id) + ",\"kind\":\"" + json_esc(r.kind) + "\",\"app\":\"" +
               json_esc(r.app) + "\",\"state\":\"" + json_esc(r.state) + "\",\"text\":\"" + json_esc(one_line(r.text, 300)) + "\"}";
    }
    return out;
}
bool link_push_wanted() { return g_push.load(); }
void link_push_clear() { g_push = false; }
void phone_prefs(std::vector<std::string> &favs, std::vector<std::string> &autos, bool &all)
{
    std::lock_guard<std::mutex> l(g23_m);
    favs = g_favs;
    autos = g_autos;
    all = g_auto_all;
}
} // namespace

void Engine::set_phone_prefs(const std::vector<std::string> &favs, const std::vector<std::string> &autos, bool all)
{
    std::lock_guard<std::mutex> l(g23_m);
    if (favs == g_favs && autos == g_autos && all == g_auto_all) return;
    g_favs = favs;
    g_autos = autos;
    g_auto_all = all;
    g_push = true;
}

void Engine::link_result(long long id, const std::string &kind, const std::string &app, const std::string &state, const std::string &text)
{
    if (id <= 0) return;
    {
        std::lock_guard<std::mutex> l(g23_m);
        bool found = false;
        for (PhoneReq &r : g_reqs)
            if (r.id == id)
            {
                r.state = state;
                r.text = text;
                r.at = mono_s();
                found = true;
            }
        if (!found)
        {
            PhoneReq r;
            r.id = id;
            r.kind = one_line(kind, 16);
            r.app = one_line(app, 200);
            r.state = state;
            r.text = text;
            r.at = mono_s();
            g_reqs.push_back(r);
            if (g_reqs.size() > 20) g_reqs.erase(g_reqs.begin());
        }
    }
    slog("link: request %lld (%s %s): %s%s%s", id, kind.c_str(), app.c_str(), state.c_str(), text.empty() ? "" : ": ", text.c_str());
    g_push = true;
}

void Engine::link_push_soon() { g_push = true; }

/* ---- Upload from your phone: the receiver payload --------------------------------- */
namespace
{
std::mutex g_rcv_m;
std::string g_rcv_token, g_rcv_why;

struct RcvStart
{
    std::string token, job;
};

void *rcv_start_main(void *arg)
{
    std::unique_ptr<RcvStart> st(static_cast<RcvStart *>(arg));
    const std::string dir = g.data + "/receiver";
    make_dirs(dir);
    auto still_mine = [&]() {
        std::lock_guard<std::mutex> l(g_rcv_m);
        return g_rcv_token == st->token;
    };
    auto fail = [&](const std::string &why) {
        std::lock_guard<std::mutex> l(g_rcv_m);
        if (g_rcv_token == st->token) g_rcv_why = why;
        slog("receiver: %s", why.c_str());
    };
    /* One running already (another screen, or one the store left when it closed): it's asked to
     * stop and given a few seconds. */
    std::string last;
    if (read_file(dir + "/status.txt", last))
    {
        const std::string state = field_of(last, "state");
        const long long at = std::strtoll(field_of(last, "at").c_str(), nullptr, 10); /* (atoll isn't one of the store's imports) */
        if ((state == "starting" || state == "listening" || state == "receiving" || state == "sending") && std::time(nullptr) - at < 20)
        {
            write_file(dir + "/stop", "stop\n", 5);
            const double t0 = mono_s();
            while (mono_s() - t0 < 6)
            {
                usleep(150000);
                std::string now;
                if (!read_file(dir + "/status.txt", now)) break;
                const std::string s = field_of(now, "state");
                if (s == "stopped" || s == "failed") break;
            }
            slog("receiver: asked the one already running to stop");
        }
    }
    if (!still_mine()) return nullptr;
    std::remove((dir + "/stop").c_str());
    std::remove((dir + "/status.txt").c_str());
    if (!write_file(dir + "/job.txt", st->job.data(), st->job.size()))
        return fail("The store couldn't write the receiver's job. Is the console's storage full?"), nullptr;
    std::string elf;
    const std::string elf_path = std::string(PORPOISE_APP) + "/assets/payloads/recompstore-receiver.elf";
    if (!read_file(elf_path, elf) || elf.size() < 64)
        return fail("This copy of Recomp Store has no receiver in it. Update the store."), nullptr;
    int err = 0;
    if (!send_to_loader(elf, err))
    {
        std::remove((dir + "/job.txt").c_str());
        return fail("No payload loader answered on port 9021, so your phone can't send files. Start one (etaHEN or elfldr), then "
                    "open this again."),
               nullptr;
    }
    slog("receiver: sent the receiver (%zu KB) to the payload loader on port 9021", elf.size() / 1024);
    const double t0 = mono_s();
    while (mono_s() - t0 < 12)
    {
        usleep(200000);
        if (!still_mine()) return nullptr;
        std::string now;
        if (read_file(dir + "/status.txt", now) && field_of(now, "token") == st->token)
        {
            slog("receiver: started after %.1f s", mono_s() - t0);
            return nullptr;
        }
    }
    write_file(dir + "/stop", "stop\n", 5);
    std::remove((dir + "/job.txt").c_str());
    fail("The receiver didn't start. Close this and try again.");
    return nullptr;
}
} // namespace

bool Engine::start_receiver(const ReceiverJob &job0, std::string &code, std::string &why)
{
    ReceiverJob job = job0;
    why.clear();
    if ((!job.dest.empty() && !folder_plain(job.dest)) || (!job.backups.empty() && !folder_plain(job.backups)) ||
        (job.dest.empty() && job.backups.empty()))
    {
        why = "The store can't take files there.";
        return false;
    }
    if (!job.backup_id.empty() && !source_id_ok(job.backup_id) && !title_id_ok(job.backup_id))
    {
        why = "That isn't an app the store knows.";
        return false;
    }
    std::string ext;
    for (const unsigned char c : job.ext)
        if (std::isalnum(c) || c == '.' || c == ' ') ext += char(std::tolower(c));
    if (job.max == 0)
    {
        std::uint64_t total = 0, avail = 0;
        bool smp = false;
        const std::string where = job.dest.empty() ? job.backups : job.dest;
        if (real_space(where, total, avail, smp, true))
            job.max = avail > (512ull << 20) ? avail - (512ull << 20) : 0;
        else
            job.max = 100ull << 30;
    }
    if (job.max == 0)
    {
        why = "There isn't room on the console for files from your phone. Make some room first.";
        return false;
    }
    code = random_digits(6);
    char token[48];
    std::snprintf(token, sizeof token, "%lld%03d", (long long)std::time(nullptr), int(mono_s() * 1000) % 1000);
    auto *st = new RcvStart;
    st->token = token;
    st->job = "token=" + st->token + "\ncode=" + code + "\ntitle=" + one_line(job.title, 120) + "\ndest=" + job.dest + "\next=" + ext.substr(0, 180) +
              "\nbackups=" + job.backups + "\nbackup_id=" + job.backup_id + "\nmax=" + std::to_string(job.max) +
              "\nidle=" + std::to_string(std::clamp(job.idle, 30, 3600)) + "\n";
    {
        std::lock_guard<std::mutex> l(g_rcv_m);
        g_rcv_token = st->token;
        g_rcv_why.clear();
    }
    slog("receiver: starting for \"%s\" (uploads to %s, backups %s, up to %llu bytes)", one_line(job.title, 120).c_str(),
         job.dest.empty() ? "none" : job.dest.c_str(), job.backups.empty() ? "none" : job.backups.c_str(), (unsigned long long)job.max);
    pthread_t t;
    if (create_title_thread(&t, rcv_start_main, st) != 0)
    {
        delete st;
        std::lock_guard<std::mutex> l(g_rcv_m);
        g_rcv_token.clear();
        why = "The store couldn't start the receiver.";
        return false;
    }
    pthread_detach(t);
    return true;
}

void Engine::stop_receiver()
{
    {
        std::lock_guard<std::mutex> l(g_rcv_m);
        if (g_rcv_token.empty()) return;
        g_rcv_token.clear();
        g_rcv_why.clear();
    }
    const std::string dir = g.data + "/receiver";
    make_dirs(dir);
    write_file(dir + "/stop", "stop\n", 5);
    slog("receiver: asked to stop (the screen closed)");
}

ReceiverStatus Engine::receiver_status() const
{
    ReceiverStatus r;
    std::string token;
    {
        std::lock_guard<std::mutex> l(g_rcv_m);
        token = g_rcv_token;
        r.why = g_rcv_why;
    }
    if (token.empty()) return r;
    std::string st;
    if (!read_file(g.data + "/receiver/status.txt", st) || field_of(st, "token") != token) return r;
    r.known = true;
    r.state = field_of(st, "state");
    r.ip = field_of(st, "ip");
    r.file = field_of(st, "file");
    r.last = field_of(st, "last");
    r.error = field_of(st, "error");
    r.port = std::atoi(field_of(st, "port").c_str());
    r.files = std::atoi(field_of(st, "files").c_str());
    r.bytes = std::strtoull(field_of(st, "bytes").c_str(), nullptr, 10);
    r.total = std::strtoull(field_of(st, "total").c_str(), nullptr, 10);
    r.speed = std::strtoull(field_of(st, "speed").c_str(), nullptr, 10);
    return r;
}

bool Engine::space_of(const std::string &path, std::uint64_t &total, std::uint64_t &avail)
{
    bool smp = false;
    return real_space(path, total, avail, smp, true);
}

/* ---- Save backups ------------------------------------------------------------------ */
namespace
{
/* An app's saves as the backup sees them: where each is now, where it goes in a zip. */
struct SavePlan
{
    std::vector<backup::Save> saves;
    std::vector<std::string> as_said; /* the catalog's own words, for the manifest */
};

SavePlan save_plan(const App &a)
{
    SavePlan p;
    const std::string folder = app_folder(a.id);
    const std::vector<std::string> &src = a.src_set ? a.saves_src : a.saves;
    for (std::size_t i = 0; i < a.saves.size() && i < src.size() && p.saves.size() < 16; ++i)
    {
        const std::string &now = a.saves[i];
        if (!plain_path(now)) continue;
        const std::string path = now[0] == '/' ? now : folder + "/" + now;
        if (path == folder) continue;
        const std::string as = backup::zip_prefix(src[i]);
        if (as.empty()) continue;
        backup::Save s;
        s.path = path;
        s.as = as;
        struct stat st;
        s.dir = !(stat(path.c_str(), &st) == 0 && S_ISREG(st.st_mode));
        p.saves.push_back(s);
        p.as_said.push_back(src[i]);
    }
    return p;
}

std::string backup_name(const std::string &id, bool auto_made, int n)
{
    return id + " " + time_text("%Y-%m-%d %H%M%S", false) + (n > 1 ? "-" + std::to_string(n) : std::string()) + (auto_made ? " auto" : "") + ".zip";
}

/* An app's saves into a backup zip, by the copier (which can read a game's own folders) or here.
 * usb: a copy in that folder too. false: fail_job said why. */
bool do_backup(const App &a, bool auto_made, const std::string &usb_dir, std::string *made = nullptr)
{
    const SavePlan p = save_plan(a);
    if (p.saves.empty())
    {
        fail_job("The store doesn't know where " + a.name + " keeps its saves.");
        return false;
    }
    const std::string dir = g.data + "/backups/" + a.id;
    make_dirs(dir);
    std::string name = backup_name(a.id, auto_made, 1);
    for (int i = 2; exists(dir + "/" + name) && i < 50; ++i) name = backup_name(a.id, auto_made, i);
    const std::string out = dir + "/" + name;
    std::string v = engine().installed(a.id);
    std::string manifest = "{\"format\":1,\"app\":\"" + json_esc(a.id) + "\",\"name\":\"" + json_esc(a.name) + "\",\"version\":\"" +
                           json_esc(v == "?" ? std::string() : v) + "\",\"made\":\"" + time_text("%Y-%m-%dT%H:%M:%SZ", true) +
                           "\",\"auto\":" + (auto_made ? "true" : "false") + ",\"store\":\"" + kVersion + "\",\"saves\":[";
    for (std::size_t i = 0; i < p.saves.size(); ++i)
        manifest += std::string(i ? "," : "") + "{\"save\":\"" + json_esc(p.as_said[i]) + "\",\"as\":\"" + json_esc(p.saves[i].as) +
                    "\",\"dir\":" + (p.saves[i].dir ? "true" : "false") + "}";
    manifest += "]}\n";
    slog("backup %s: %zu save place(s) into %s%s%s", a.id.c_str(), p.saves.size(), out.c_str(), usb_dir.empty() ? "" : ", and a copy in ",
         usb_dir.c_str());
    set_job(auto_made ? "Backing up your saves first" : "Backing up your saves", 0, 0);
    /* The copier: the manifest beside its job, each save as save<i>, as<i>, dir<i>. */
    const std::string mpath = g.data + "/copyjob/backup-manifest.json";
    make_dirs(g.data + "/copyjob");
    std::string extra = "manifest=" + mpath + "\nusb=" + usb_dir + "\n";
    for (std::size_t i = 0; i < p.saves.size(); ++i)
        extra += "save" + std::to_string(i) + "=" + p.saves[i].path + "\nas" + std::to_string(i) + "=" + p.saves[i].as + "\ndir" +
                 std::to_string(i) + "=" + (p.saves[i].dir ? "1" : "0") + "\n";
    Copier c = Copier::Unavailable;
    if (write_file(mpath, manifest.data(), manifest.size())) c = run_copier("backup", "", out, nullptr, extra);
    if (c == Copier::Failed) return false; /* run_copier said why */
    if (c == Copier::Unavailable)
    {
        std::size_t files = 0;
        std::uint64_t bytes = 0;
        std::string why;
        std::vector<std::string> missing;
        const bool ok = backup::make(
            out, manifest, p.saves, {}, [](const char *step, std::uint64_t d, std::uint64_t t) { set_job(step, d, t); },
            [](const std::string &l) { slog("%s", l.c_str()); }, why, files, bytes, &missing);
        if (!ok)
        {
            fail_job(why);
            return false;
        }
        if (!usb_dir.empty() && !backup::copy_checked(out, usb_dir, [](const std::string &l) { slog("%s", l.c_str()); }, why))
        {
            fail_job("Your saves were backed up on the console, but not to the USB drive: " + why);
            return false;
        }
    }
    /* Checked here too, whoever made it. */
    std::string text, why;
    std::vector<zip::Entry> entries;
    if (!backup::check(out, text, entries, {}, why) || backup::json_field(text, "app") != a.id)
    {
        std::remove(out.c_str());
        fail_job("The backup didn't check out (" + why + "). Nothing was kept.");
        return false;
    }
    if (!usb_dir.empty())
    {
        std::string t2;
        std::vector<zip::Entry> e2;
        if (!backup::check(usb_dir + "/" + name, t2, e2, {}, why) || e2.size() != entries.size())
        {
            fail_job("Your saves were backed up on the console, but the USB drive's copy didn't check out (" + why + ").");
            return false;
        }
    }
    slog("backup %s: %s: %zu files, checked", a.id.c_str(), name.c_str(), entries.size() - 1);
    if (made) *made = out;
    return true;
}

std::string usb_now()
{
    const unsigned mask = engine().usb_seen();
    for (int i = 0; i < 8; ++i)
        if (mask & (1u << i)) return "/mnt/usb" + std::to_string(i);
    return "";
}

void run_backup_task(const std::vector<std::string> &args)
{
    const std::string id = args.size() > 0 ? args[0] : "";
    const bool usb = args.size() > 1 && args[1] == "1";
    bool found = false;
    const App a = app_copy(id, found);
    if (!found || engine().installed(id).empty()) return fail_job("Back up works for apps the store installed.");
    std::string usb_dir;
    if (usb)
    {
        const std::string u = usb_now();
        if (u.empty()) return fail_job("There's no USB drive in. Plug one in, or back up to the console only.");
        usb_dir = u + "/RecompStore Backups/" + id;
    }
    std::string made;
    if (!do_backup(a, false, usb_dir, &made)) return;
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.step = "Backed up";
    g.job.note = "Backed up " + a.name + "'s saves" + (usb_dir.empty() ? "." : ", on the console and the USB drive.");
}

void run_restore_task(const std::vector<std::string> &args)
{
    const std::string id = args.size() > 0 ? args[0] : "", zip_path = args.size() > 1 ? args[1] : "";
    bool found = false;
    const App a = app_copy(id, found);
    if (!found || engine().installed(id).empty()) return fail_job("Restore works for apps the store installed.");
    /* Only a zip in the backups (the console's or a USB drive's), never anything else. */
    const std::string mine = g.data + "/backups/" + id + "/";
    const bool on_usb = zip_path.rfind("/mnt/usb", 0) == 0 && zip_path.find("/RecompStore Backups/" + id + "/") != std::string::npos;
    if ((zip_path.rfind(mine, 0) != 0 && !on_usb) || zip_path.find("..") != std::string::npos || zip_path.size() < 5 ||
        zip_path.compare(zip_path.size() - 4, 4, ".zip") != 0)
        return fail_job("The store only restores backups from its own folder or a USB drive's RecompStore Backups.");
    set_job("Checking the backup", 0, 0);
    std::string manifest, why;
    std::vector<zip::Entry> entries;
    if (!backup::check(zip_path, manifest, entries, [](const char *s, std::uint64_t d, std::uint64_t t) { set_job(s, d, t); }, why))
        return fail_job("That backup can't be used: " + why + ".");
    if (backup::json_field(manifest, "app") != id)
    {
        const std::string other = backup::json_field(manifest, "name");
        return fail_job("That backup is " + (other.empty() ? std::string("another game") : other) + "'s saves, not " + a.name + "'s.");
    }
    /* The manifest's saves that the catalog still lists: only those come back. */
    Json j;
    if (!Json::parse(manifest, j) || !j.is_object()) return fail_job("That backup's list of saves can't be read.");
    const SavePlan now = save_plan(a);
    std::vector<backup::Save> back;
    for (std::size_t i = 0; i < j["saves"].size(); ++i)
    {
        const std::string said = j["saves"][i]["save"].str(), as = j["saves"][i]["as"].str();
        const bool dir = j["saves"][i]["dir"].type() == Json::Type::Bool ? j["saves"][i]["dir"].boolean(true) : true;
        for (std::size_t k = 0; k < now.saves.size(); ++k)
            if (now.as_said[k] == said && backup::zip_prefix(said) == as)
            {
                backup::Save s = now.saves[k];
                s.dir = dir;
                back.push_back(s);
            }
    }
    if (back.empty()) return fail_job("That backup has none of the saves " + a.name + " uses now.");
    /* The saves there now, backed up first (when there are any). */
    bool any = false;
    for (const backup::Save &s : now.saves) any = any || exists(s.path);
    std::string before;
    if (any && !do_backup(a, true, "", &before))
    {
        std::string w;
        {
            std::lock_guard<std::mutex> l(g.m);
            w = g.job.error;
        }
        return fail_job("Your saves now couldn't be backed up first, so nothing was restored. " + w);
    }
    set_job("Restoring your saves", 0, 0);
    std::string extra;
    for (std::size_t i = 0; i < back.size(); ++i)
        extra += "save" + std::to_string(i) + "=" + back[i].path + "\nas" + std::to_string(i) + "=" + back[i].as + "\ndir" + std::to_string(i) + "=" +
                 (back[i].dir ? "1" : "0") + "\n";
    const Copier c = run_copier("restore", zip_path, app_folder(id), nullptr, extra);
    if (c == Copier::Failed) return;
    if (c == Copier::Unavailable)
    {
        std::size_t files = 0;
        if (!backup::restore(zip_path, entries, back, {}, [](const char *s, std::uint64_t d, std::uint64_t t) { set_job(s, d, t); },
                             [](const std::string &l) { slog("%s", l.c_str()); }, why, files))
            return fail_job(why + " Your saves are as they were.");
    }
    /* Checked here too: every file of the backup where it belongs now. */
    const int fd = ::open(zip_path.c_str(), O_RDONLY);
    for (const backup::Save &s : back)
        for (const zip::Entry &e : entries)
        {
            if (e.name == backup::kManifest || (!e.name.empty() && e.name.back() == '/')) continue;
            std::string dst;
            if (s.dir && e.name.size() > s.as.size() + 1 && e.name.compare(0, s.as.size() + 1, s.as + "/") == 0)
                dst = s.path + "/" + e.name.substr(s.as.size() + 1);
            else if (!s.dir && e.name == s.as)
                dst = s.path;
            if (dst.empty()) continue;
            std::string bad;
            if (!zip::file_matches(dst, e.usize, e.crc, &bad))
            {
                if (fd >= 0) ::close(fd);
                return fail_job(dst + " isn't right after the restore (" + bad + "). Your saves from before are in the backups.");
            }
        }
    if (fd >= 0) ::close(fd);
    const std::string made = backup::json_field(manifest, "made");
    slog("restore %s: from %s, checked", id.c_str(), zip_path.c_str());
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.step = "Restored";
    g.job.note = "Restored " + a.name + "'s saves" + (made.size() >= 10 ? " from " + made.substr(0, 10) : std::string()) + "." +
                 (before.empty() ? std::string() : " The saves from before are in its backups too.");
}
} // namespace

bool run_backup_first(const std::string &app_id)
{
    bool found = false;
    const App a = app_copy(app_id, found);
    if (!found) return true;
    bool any = false;
    for (const backup::Save &s : save_plan(a).saves) any = any || exists(s.path);
    if (!any) return true; /* nothing to back up */
    std::string made;
    if (do_backup(a, false, "", &made))
    {
        std::lock_guard<std::mutex> l(g.m);
        g.job.phase = Phase::Working;
        g.job.error.clear();
        return true;
    }
    std::string why;
    {
        std::lock_guard<std::mutex> l(g.m);
        why = g.job.error;
    }
    fail_job("Your saves couldn't be backed up, so nothing else was done. " + why);
    return false;
}

std::string Engine::backups_dir() const { return g.data + "/backups"; }
std::string Engine::usb_drive() const { return usb_now(); }

std::vector<Backup> Engine::backups(const std::string &app_id) const
{
    std::vector<Backup> out;
    if (!source_id_ok(app_id) && !title_id_ok(app_id)) return out;
    std::vector<std::pair<std::string, std::string>> dirs{{g.data + "/backups/" + app_id, "Console storage"}};
    const unsigned mask = usb_seen();
    for (int i = 0; i < 8; ++i)
        if (mask & (1u << i)) dirs.push_back({"/mnt/usb" + std::to_string(i) + "/RecompStore Backups/" + app_id, "USB drive " + std::to_string(i + 1)});
    for (const auto &d : dirs)
    {
        DIR *dir = opendir(d.first.c_str());
        if (!dir) continue;
        std::vector<std::string> names;
        while (dirent *e = readdir(dir))
        {
            const std::string n = e->d_name;
            if (n.size() > 4 && n[0] != '.' && n.compare(n.size() - 4, 4, ".zip") == 0 && names.size() < 200) names.push_back(n);
        }
        closedir(dir);
        for (const std::string &n : names)
        {
            Backup b;
            b.path = d.first + "/" + n;
            b.file = n;
            b.where = d.second;
            b.usb = d.first.rfind("/mnt/usb", 0) == 0;
            struct stat st;
            if (stat(b.path.c_str(), &st) != 0 || !S_ISREG(st.st_mode)) continue;
            b.size = std::uint64_t(st.st_size);
            b.mtime = (long long)st.st_mtime;
            /* Its manifest: the first file, read straight (a stored zip's local header). */
            const int fd = ::open(b.path.c_str(), O_RDONLY);
            if (fd >= 0)
            {
                std::uint8_t lh[30];
                if (::pread(fd, lh, sizeof lh, 0) == 30 && lh[0] == 'P' && lh[1] == 'K' && lh[2] == 3 && lh[3] == 4 && lh[8] == 0 && lh[9] == 0)
                {
                    const unsigned nl = unsigned(lh[26]) | unsigned(lh[27]) << 8, xl = unsigned(lh[28]) | unsigned(lh[29]) << 8;
                    const std::uint32_t sz = std::uint32_t(lh[18]) | std::uint32_t(lh[19]) << 8 | std::uint32_t(lh[20]) << 16 | std::uint32_t(lh[21]) << 24;
                    std::string name(nl, '\0'), text(sz < 65536 ? sz : 0, '\0');
                    if (nl < 256 && ::pread(fd, name.data(), nl, 30) == ssize_t(nl) && name == backup::kManifest && !text.empty() &&
                        ::pread(fd, text.data(), text.size(), off_t(30 + nl + xl)) == ssize_t(text.size()))
                    {
                        b.ok = backup::json_field(text, "app") == app_id;
                        b.made = backup::json_field(text, "made");
                        b.version = backup::json_field(text, "version");
                        b.auto_made = text.find("\"auto\":true") != std::string::npos;
                    }
                }
                ::close(fd);
            }
            out.push_back(b);
        }
    }
    std::stable_sort(out.begin(), out.end(), [](const Backup &x, const Backup &y) {
        if (x.usb != y.usb) return !x.usb;
        return x.mtime > y.mtime;
    });
    return out;
}

bool Engine::saves_here(const std::string &app_id) const
{
    bool found = false;
    const App a = app_copy(app_id, found);
    if (!found) return false;
    for (const backup::Save &s : save_plan(a).saves)
        if (exists(s.path)) return true;
    return false;
}

namespace
{
/* A 2.3.0 job: nothing else going, then it's the job. */
bool start_task(const std::string &task, const std::vector<std::string> &args, const std::string &app, const std::string &step)
{
    std::lock_guard<std::mutex> l(g.m);
    if (g.job.phase == Phase::Working || !g.pending_install.empty() || !g.pending_setup_app.empty() || g.pending_bench ||
        !g.pending_delete.empty() || !g.pending_manage.empty() || !g.pending_mdeletes.empty() || g.pending_smpget || !g.pending_task.empty())
        return false;
    g.pending_task = task;
    g.pending_task_args = args;
    g.job = JobState();
    g.job.phase = Phase::Working;
    g.job.kind = task;
    g.job.app_id = app;
    g.job.step = step;
    return true;
}
} // namespace

bool Engine::backup_saves(const std::string &app_id, bool usb)
{
    return start_task("backup", {app_id, usb ? "1" : "0"}, app_id, "Backing up your saves");
}

bool Engine::restore_saves(const std::string &app_id, const std::string &zip)
{
    return start_task("restore", {app_id, zip}, app_id, "Checking the backup");
}

bool Engine::delete_backup(const std::string &app_id, const std::string &zip, std::string &why)
{
    const std::string mine = g.data + "/backups/" + app_id + "/";
    const bool on_usb = zip.rfind("/mnt/usb", 0) == 0 && zip.find("/RecompStore Backups/" + app_id + "/") != std::string::npos;
    const std::string leaf = zip.substr(zip.find_last_of('/') + 1);
    if ((zip.rfind(mine, 0) != 0 && !on_usb) || zip.find("..") != std::string::npos || leaf.empty() || leaf[0] == '.' ||
        leaf.size() < 5 || leaf.compare(leaf.size() - 4, 4, ".zip") != 0)
    {
        why = "The store only deletes its own backups.";
        return false;
    }
    std::remove(zip.c_str());
    if (exists(zip))
    {
        why = "That backup couldn't be deleted.";
        slog("backup: %s couldn't be deleted (errno %d)", zip.c_str(), errno);
        return false;
    }
    slog("backup: deleted %s", zip.c_str());
    return true;
}

bool Engine::remove(const std::string &app_id, bool keep_saves, bool backup_first)
{
    if (!remove(app_id, keep_saves)) return false;
    if (backup_first)
    {
        std::lock_guard<std::mutex> l(g.m);
        g.pending_backup_first = app_id;
    }
    return true;
}

bool Engine::manage(const std::string &kind, const std::string &folder, const std::string &dest, const std::string &backup_app)
{
    if (!manage(kind, folder, dest)) return false;
    if (!backup_app.empty())
    {
        std::lock_guard<std::mutex> l(g.m);
        g.pending_backup_first = backup_app;
    }
    return true;
}

/* ---- Clear download cache, on a thread ---------------------------------------------- */
namespace
{
std::mutex g_clear_m;
int g_clear_state = 0;
std::uint64_t g_clear_freed = 0;
std::size_t g_clear_done = 0, g_clear_total = 0;
std::string g_clear_why;

void *clear_main(void *)
{
    std::uint64_t freed = 0;
    std::size_t files = 0;
    std::string why;
    const bool ok = clear_cache_now(freed, files, why, [](std::size_t done, std::size_t total) {
        std::lock_guard<std::mutex> l(g_clear_m);
        g_clear_done = done;
        g_clear_total = total;
    });
    std::lock_guard<std::mutex> l(g_clear_m);
    g_clear_freed = freed;
    g_clear_why = why;
    g_clear_state = ok ? 2 : 3;
    return nullptr;
}
} // namespace

bool Engine::clear_downloads_start()
{
    {
        std::lock_guard<std::mutex> l(g_clear_m);
        if (g_clear_state == 1) return false;
        g_clear_state = 1;
        g_clear_freed = 0;
        g_clear_done = g_clear_total = 0;
        g_clear_why.clear();
    }
    pthread_t t;
    if (create_title_thread(&t, clear_main, nullptr) != 0)
    {
        std::lock_guard<std::mutex> l(g_clear_m);
        g_clear_state = 3;
        g_clear_why = "The store couldn't start clearing.";
        return false;
    }
    pthread_detach(t);
    return true;
}

int Engine::clear_state(std::uint64_t &freed, std::size_t &done, std::size_t &total, std::string &why) const
{
    std::lock_guard<std::mutex> l(g_clear_m);
    freed = g_clear_freed;
    done = g_clear_done;
    total = g_clear_total;
    why = g_clear_why;
    return g_clear_state;
}

void Engine::clear_ack()
{
    std::lock_guard<std::mutex> l(g_clear_m);
    if (g_clear_state != 1) g_clear_state = 0;
}

/* ---- Ready to play: the payload loader ---------------------------------------------- */
namespace
{
std::atomic<int> g_loader{-1};
std::atomic<long long> g_loader_at{-100000};
std::atomic<bool> g_loader_looking{false};

void *loader_main(void *)
{
    const int fd = socket(AF_INET, SOCK_STREAM, 0);
    bool up = false;
    if (fd >= 0)
    {
        timeval limit{};
        limit.tv_sec = 1;
        setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &limit, sizeof limit);
        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &limit, sizeof limit);
        sockaddr_in sa{};
        sa.sin_family = AF_INET;
        sa.sin_port = htons(9021);
        sa.sin_addr.s_addr = htonl(0x7F000001);
        up = connect(fd, reinterpret_cast<sockaddr *>(&sa), sizeof sa) == 0;
        close(fd); /* nothing sent: a loader reads nothing and goes back to waiting */
    }
    if (up != (g_loader.load() == 1)) slog("ready to play: a payload loader %s on port 9021", up ? "answers" : "doesn't answer");
    g_loader = up ? 1 : 0;
    g_loader_looking = false;
    return nullptr;
}
} // namespace

int Engine::loader_seen()
{
    const long long now = (long long)(mono_s() * 1000);
    if (now - g_loader_at.load() >= 30000 && !g_loader_looking.exchange(true))
    {
        g_loader_at = now;
        pthread_t t;
        if (create_title_thread(&t, loader_main, nullptr) == 0) pthread_detach(t);
        else g_loader_looking = false;
    }
    return g_loader.load();
}

/* ---- Likes, Restore my apps: a thread of their own for the site -------------------- */
namespace
{
constexpr const char *kLikesUrl = "https://recompstore.com/api/likes.php";
std::mutex g_like_m;
std::set<std::string> g_liked;
bool g_liked_loaded = false;
std::vector<std::pair<std::string, bool>> g_like_due;
struct LikeAdj
{
    long long base = 0;
    int adj = 0;
};
std::map<std::string, LikeAdj> g_like_adj;
std::atomic<bool> g_net_started{false};
std::atomic<bool> g_myapps_want{false};
std::mutex g_myapps_m;
int g_myapps_state = 0;
std::string g_myapps_why;
std::vector<MyAppsList> g_myapps;

void load_likes_locked()
{
    if (g_liked_loaded) return;
    g_liked_loaded = true;
    std::string text;
    if (!read_file(g.data + "/likes.txt", text)) return;
    std::size_t at = 0;
    while (at < text.size() && g_liked.size() < 2000)
    {
        std::size_t end = text.find('\n', at);
        if (end == std::string::npos) end = text.size();
        const std::string id = text.substr(at, end - at);
        if (source_id_ok(id) || title_id_ok(id)) g_liked.insert(id);
        at = end + 1;
    }
}

void save_likes_locked()
{
    std::string text;
    for (const std::string &id : g_liked) text += id + "\n";
    if (!write_file(g.data + "/likes.txt", text.data(), text.size())) slog("likes: couldn't save %s/likes.txt", g.data.c_str());
}

void *net_main(void *)
{
    for (;;)
    {
        usleep(300000);
        std::vector<std::pair<std::string, bool>> due;
        {
            std::lock_guard<std::mutex> l(g_like_m);
            due.swap(g_like_due);
        }
        const bool myapps = g_myapps_want.exchange(false);
        if (due.empty() && !myapps) continue;
        porpoise::http::Session http("likes", "RecompStore/2.3");
        if (!http.init())
        {
            if (myapps)
            {
                std::lock_guard<std::mutex> l(g_myapps_m);
                g_myapps_state = 3;
                g_myapps_why = "Your PS5 isn't online. Connect it and try again.";
            }
            std::lock_guard<std::mutex> l(g_like_m);
            g_like_due.insert(g_like_due.begin(), due.begin(), due.end()); /* later */
            if (g_like_due.size() > 100) g_like_due.resize(100);
            usleep(20000000);
            continue;
        }
        for (const auto &d : due)
        {
            const std::string form = beta_form(d.second ? "like" : "unlike", "&app=" + form_value(d.first));
            std::vector<std::uint8_t> body;
            const int st = http.request(kLikesUrl, &form, body, 16u << 10);
            slog("likes: %s %s: HTTP %d", d.second ? "like" : "unlike", d.first.c_str(), st);
        }
        if (myapps)
        {
            const std::string form = beta_form("myapps", "");
            std::vector<std::uint8_t> body;
            const int st = http.request(kLinkUrl, &form, body, 512u << 10);
            Json j;
            std::vector<MyAppsList> lists;
            const bool ok = Json::parse(std::string(body.begin(), body.end()), j) && j.is_object() && j["ok"].boolean(false);
            for (std::size_t i = 0; ok && i < j["lists"].size() && lists.size() < 16; ++i)
            {
                const Json &x = j["lists"][i];
                MyAppsList m;
                m.from = one_line(x["from"].str("A phone"), 60);
                m.made = one_line(x["made"].str(), 32);
                for (std::size_t k = 0; k < x["apps"].size() && m.apps.size() < 300; ++k)
                {
                    MyApp a{x["apps"][k]["id"].str(), one_line(x["apps"][k]["name"].str(), 120), one_line(x["apps"][k]["version"].str(), 40)};
                    if (source_id_ok(a.id) || title_id_ok(a.id)) m.apps.push_back(a);
                }
                if (!m.apps.empty()) lists.push_back(m);
            }
            slog("restore my apps: the site's lists: HTTP %d, %zu list(s)", st, lists.size());
            std::lock_guard<std::mutex> l(g_myapps_m);
            g_myapps = lists;
            g_myapps_state = ok ? 2 : 3;
            g_myapps_why = ok ? "" : st < 0 ? "recompstore.com didn't answer. Try again in a moment." : j["error"].str("recompstore.com couldn't give the lists.");
        }
    }
    return nullptr;
}

void start_net()
{
    bool was = false;
    if (!g_net_started.compare_exchange_strong(was, true)) return;
    pthread_t t;
    if (create_title_thread(&t, net_main, nullptr) == 0) pthread_detach(t);
    else g_net_started = false;
}
} // namespace

void Engine::like(const std::string &app_id, bool on)
{
    if (!source_id_ok(app_id) && !title_id_ok(app_id)) return;
    const long long base = charts().likes_of(app_id);
    {
        std::lock_guard<std::mutex> l(g_like_m);
        load_likes_locked();
        const bool was = g_liked.count(app_id) > 0;
        if (was == on) return;
        if (on) g_liked.insert(app_id);
        else g_liked.erase(app_id);
        save_likes_locked();
        LikeAdj &a = g_like_adj[app_id];
        if (a.base != base) a = LikeAdj{base, 0};
        a.adj += on ? 1 : -1;
        g_like_due.push_back({app_id, on});
    }
    start_net();
}

bool Engine::liked(const std::string &app_id) const
{
    std::lock_guard<std::mutex> l(g_like_m);
    load_likes_locked();
    return g_liked.count(app_id) > 0;
}

long long Engine::like_count(const std::string &app_id) const
{
    const long long c = charts().likes_of(app_id);
    std::lock_guard<std::mutex> l(g_like_m);
    auto it = g_like_adj.find(app_id);
    if (it != g_like_adj.end() && it->second.base == c) return std::max(0LL, c + it->second.adj);
    return c;
}

std::string Engine::my_apps_json() const
{
    Catalog c;
    {
        std::lock_guard<std::mutex> l(g.m);
        c = g.catalog;
    }
    std::string apps;
    for (const App &a : c.apps)
    {
        if (a.custom || a.beta || a.soon) continue;
        std::string v = installed(a.id);
        if (v.empty()) continue;
        if (v == "?") v.clear();
        apps += std::string(apps.empty() ? "" : ",\n  ") + "{\"id\":\"" + json_esc(a.id) + "\",\"name\":\"" + json_esc(a.name) + "\",\"version\":\"" +
                json_esc(v) + "\"}";
    }
    return "{\"format\":1,\"made\":\"" + time_text("%Y-%m-%dT%H:%M:%SZ", true) + "\",\"store\":\"" + kVersion + "\",\"apps\":[\n  " + apps + "\n]}\n";
}

bool Engine::save_my_apps(const std::string &path, std::string &why) const
{
    const std::string text = my_apps_json();
    const std::size_t slash = path.find_last_of('/');
    if (slash == std::string::npos || !folder_plain(path.substr(0, slash)))
    {
        why = "The store can't save the list there.";
        return false;
    }
    make_dirs(path.substr(0, slash));
    std::string back;
    if (!write_file(path, text.data(), text.size()) || !read_file(path, back) || back != text)
    {
        why = "The list couldn't be saved there. Is the drive full, or read-only?";
        slog("restore my apps: couldn't save %s", path.c_str());
        return false;
    }
    slog("restore my apps: saved the list (%zu bytes) to %s, read back", text.size(), path.c_str());
    return true;
}

bool Engine::read_my_apps(const std::string &path, MyAppsList &out, std::string &why)
{
    out = MyAppsList();
    struct stat st;
    std::string text;
    if (stat(path.c_str(), &st) != 0 || st.st_size > (256 << 10) || !read_file(path, text))
    {
        why = "That file can't be read, or it's too big to be a list of apps.";
        return false;
    }
    Json j;
    if (!Json::parse(text, j) || !j.is_object() || j["format"].integer(0) != 1)
    {
        why = "That isn't a list of apps from Recomp Store.";
        return false;
    }
    out.made = one_line(j["made"].str(), 32);
    for (std::size_t i = 0; i < j["apps"].size() && out.apps.size() < 300; ++i)
    {
        MyApp a{j["apps"][i]["id"].str(), one_line(j["apps"][i]["name"].str(), 120), one_line(j["apps"][i]["version"].str(), 40)};
        if (source_id_ok(a.id) || title_id_ok(a.id)) out.apps.push_back(a);
    }
    if (out.apps.empty())
    {
        why = "That list has no apps in it.";
        return false;
    }
    return true;
}

void Engine::ask_my_apps()
{
    {
        std::lock_guard<std::mutex> l(g_myapps_m);
        g_myapps_state = 1;
        g_myapps_why.clear();
    }
    g_myapps_want = true;
    start_net();
}

int Engine::my_apps_lists(std::vector<MyAppsList> &out, std::string &why) const
{
    std::lock_guard<std::mutex> l(g_myapps_m);
    out = g_myapps;
    why = g_myapps_why;
    return g_myapps_state;
}

/* ---- Mods ----------------------------------------------------------------------------- */
namespace
{
/* The game's mods folder, and a mod's folder in it ("" when either isn't one the store would use). */
std::string mods_folder(const App &a)
{
    const std::string d = a.mods_dir.empty() ? std::string("mods") : a.mods_dir;
    if (d[0] == '/' || !plain_path(d)) return "";
    return app_folder(a.id) + "/" + d;
}

const App::Mod *find_mod(const App &a, const std::string &id)
{
    for (const App::Mod &m : a.mods)
        if (m.id == id) return &m;
    return nullptr;
}

bool mod_url_ok(const App::Mod &m, std::string &url)
{
    if (!repo_text_ok(m.repo) || m.asset.empty()) return false;
    /* asset: "<tag>/<file>" of the repo's releases. */
    const std::size_t slash = m.asset.find('/');
    if (slash == std::string::npos || slash == 0 || slash + 1 >= m.asset.size() || m.asset.find('/', slash + 1) != std::string::npos ||
        m.asset.find("..") != std::string::npos)
        return false;
    for (const unsigned char c : m.asset)
        if (!(std::isalnum(c) || c == '.' || c == '_' || c == '-' || c == '/' || c == '+')) return false;
    url = "https://github.com/" + m.repo + "/releases/download/" + m.asset;
    return true;
}

void mod_done(const std::string &note)
{
    std::lock_guard<std::mutex> l(g.m);
    g.job.phase = Phase::Done;
    g.job.step = "Done";
    g.job.note = note;
}

void run_mod_task(const std::vector<std::string> &args)
{
    const std::string id = args.size() > 0 ? args[0] : "", mid = args.size() > 1 ? args[1] : "", what = args.size() > 2 ? args[2] : "";
    bool found = false;
    const App a = app_copy(id, found);
    if (!found || engine().installed(id).empty()) return fail_job("Mods go in apps the store installed.");
    const App::Mod *m = find_mod(a, mid);
    if (!m) return fail_job("That mod isn't in the store's list any more.");
    const std::string mods = mods_folder(a);
    if (mods.empty() || !plain_leaf(m->target)) return fail_job("This mod's folder isn't one the store can use.");
    const std::string on = mods + "/" + m->target, off = on + ".disabled";
    auto remove_dir = [&](const std::string &p) -> bool {
        if (p.rfind(mods + "/", 0) != 0) return false; /* only inside the mods folder */
        std::size_t n = 0;
        std::string bad;
        int e = 0;
        return fs::remove_tree(p, n, bad, e) && fs::gone(p);
    };
    slog("mods: %s %s for %s (%s)", what.c_str(), m->id.c_str(), id.c_str(), on.c_str());
    if (what == "off" || what == "on")
    {
        const std::string &from = what == "off" ? on : off, &to = what == "off" ? off : on;
        if (!exists(from)) return fail_job(what == "off" ? m->name + " is already off." : m->name + " is already on.");
        if (exists(to) && !remove_dir(to)) return fail_job("An old copy of " + m->name + " is in the way.");
        if (std::rename(from.c_str(), to.c_str()) != 0 || !exists(to) || exists(from))
            return fail_job(m->name + " couldn't be turned " + what + " (error " + std::to_string(errno) + ").");
        return mod_done(m->name + (what == "off" ? " is off. Turn it on again any time." : " is on."));
    }
    if (what == "remove")
    {
        if (!remove_dir(on) || !remove_dir(off)) return fail_job(m->name + " couldn't be removed whole.");
        return mod_done(m->name + " is removed.");
    }
    if (what != "install") return fail_job("The store doesn't know that.");
    std::string url;
    if (!mod_url_ok(*m, url) || !lower_hex(m->sha256, 64)) return fail_job("This mod's download isn't one the store trusts.");
    /* Downloaded, its SHA-256 and size checked, then put in place beside the old one. */
    const std::string dl = g.data + "/downloads/mod-" + id + "-" + m->id + ".part";
    make_dirs(g.data + "/downloads");
    set_job("Downloading " + m->name, 0, m->size);
    porpoise::http::Session http("mod", "RecompStore/2.3");
    if (!http.init()) return fail_job("Your PS5 isn't online. Connect it and try again.");
    std::FILE *f = std::fopen(dl.c_str(), "wb");
    if (!f) return fail_job("The store couldn't save the download.");
    Sha256 sha;
    std::uint64_t got = 0;
    bool wrote = true;
    const int st = http.get_stream(url, [&](const std::uint8_t *p, std::size_t n) {
        if (std::fwrite(p, 1, n, f) != n) wrote = false;
        sha.update(p, n);
        got += n;
        set_job("Downloading " + m->name, got, m->size);
        return wrote && got <= (2ull << 30);
    });
    std::fclose(f);
    http.term();
    const std::string sum = sha.hex();
    if (st != 200 || !wrote || sum != m->sha256 || (m->size && got != m->size))
    {
        std::remove(dl.c_str());
        slog("mods: %s: HTTP %d, %llu bytes, sha256 %s (want %s)", url.c_str(), st, (unsigned long long)got, sum.c_str(), m->sha256.c_str());
        return fail_job(st != 200 ? "The mod couldn't be downloaded (HTTP " + std::to_string(st) + ")." : "The download isn't the mod the store lists (its checksum is wrong). Nothing was changed.");
    }
    make_dirs(mods);
    const std::string fresh = on + ".rs-new";
    remove_dir(fresh);
    const std::string leaf = m->asset.substr(m->asset.find('/') + 1);
    const bool is_zip = leaf.size() > 4 && lower_ascii(leaf.substr(leaf.size() - 4)) == ".zip";
    set_job("Installing " + m->name, 0, 0);
    if (is_zip)
    {
        std::string error;
        bool changed = false;
        std::size_t files = 0;
        make_dirs(fresh);
        if (!zip::unpack(dl, fresh, [](const char *, std::uint64_t d, std::uint64_t t) { set_job("Installing", d, t); },
                         [](const std::string &l) { slog("mods: %s", l.c_str()); }, {}, error, changed, files))
        {
            remove_dir(fresh);
            std::remove(dl.c_str());
            return fail_job(m->name + " couldn't be unpacked: " + error);
        }
    }
    else
    {
        make_dirs(fresh);
        std::string data;
        if (!plain_leaf(leaf) || !read_file(dl, data) || !write_file(fresh + "/" + leaf, data.data(), data.size()) ||
            !zip::file_matches(fresh + "/" + leaf, data.size(), zip::crc32(reinterpret_cast<const std::uint8_t *>(data.data()), data.size())))
        {
            remove_dir(fresh);
            std::remove(dl.c_str());
            return fail_job(m->name + " couldn't be copied into the game's mods folder.");
        }
    }
    std::remove(dl.c_str());
    /* The old copy (on or off) goes, the new one comes in on. */
    if ((exists(on) && !remove_dir(on)) || (exists(off) && !remove_dir(off)))
    {
        remove_dir(fresh);
        return fail_job("The old copy of " + m->name + " couldn't be replaced.");
    }
    if (std::rename(fresh.c_str(), on.c_str()) != 0 || !exists(on))
    {
        remove_dir(fresh);
        return fail_job(m->name + " couldn't be put in place.");
    }
    slog("mods: %s installed in %s", m->id.c_str(), on.c_str());
    mod_done(m->name + " is installed and on.");
}
} // namespace

int Engine::mod_state(const App &a, const App::Mod &m) const
{
    const std::string mods = mods_folder(a);
    if (mods.empty() || !plain_leaf(m.target) || installed(a.id).empty()) return 0;
    if (exists(mods + "/" + m.target)) return 1;
    if (exists(mods + "/" + m.target + ".disabled")) return 2;
    return 0;
}

bool Engine::mod_job(const std::string &app_id, const std::string &mod_id, const std::string &what)
{
    if (what != "install" && what != "off" && what != "on" && what != "remove") return false;
    return start_task("mod", {app_id, mod_id, what}, app_id,
                      what == "install" ? "Downloading the mod" : what == "remove" ? "Removing the mod" : "Changing the mod");
}

/* ---- Send logs, with what happened ------------------------------------------------- */
bool Engine::send_logs(const std::string &app_id, const std::string &message, const std::string &category, const std::string &feedback,
                       const std::string &build)
{
    static const char *cats[] = {"bug", "crash", "install", "slow", "other"};
    static const char *verdicts[] = {"works", "crashes", "slow", "other"};
    std::string extra;
    for (const char *c : cats)
        if (category == c) extra = "&category=" + category;
    for (const char *v : verdicts)
        if (feedback == v) extra += "&feedback=" + feedback + "&build=" + form_value(one_line(build, 40));
    std::lock_guard<std::mutex> l(g.m);
    if (g.logs == 1) return false;
    g.pending_logs = true;
    g.pending_logs_app = app_id;
    g.pending_logs_msg = message.substr(0, 2000);
    g.pending_logs_extra = extra;
    g.logs = 1;
    g.logs_msg.clear();
    return true;
}

/* ---- The worker's 2.3.0 jobs ------------------------------------------------------- */
void run_task(const std::string &task, const std::vector<std::string> &args)
{
    if (task == "backup") run_backup_task(args);
    else if (task == "restore") run_restore_task(args);
    else if (task == "mod") run_mod_task(args);
    else fail_job("The store doesn't know that.");
}
} // namespace recomp

#ifdef RECOMP_ENGINE_TEST
namespace recomp
{
std::string link_state_for_test() { return link_state_json(); }
} // namespace recomp
#endif
