/* Recomp Store - startup, the frame loop and leaving.
 * Built on Porpoise's platform layer (graphics, controller, sound, HTTPS, the jailbreak).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "porpoise_audio.hpp"
#include "porpoise_jailbreak.hpp"
#include "porpoise_pacer.hpp"
#include "porpoise_pad.hpp"
#include "porpoise_sound.hpp"
#include "porpoise_paths.hpp"
#include "porpoise_vk.hpp"
#include "store_app.hpp"
#include "ui_app_common.hpp"
#include "ui_gfx.hpp"

#include <cstdio>
#include <ctime>
#include <string>
#include <sys/stat.h>
#include <unistd.h>

extern "C" int sceSystemServiceLoadExec(const char *path, char *const argv[]);
extern "C" int sceSystemServiceHideSplashScreen(void);

namespace
{
porpoise::ui::Gfx g_gfx;
porpoise::pacer::Pacer g_pacer;
recomp::Store g_store;

long long now_ns()
{
    timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return static_cast<long long>(ts.tv_sec) * 1000000000LL + ts.tv_nsec;
}

[[noreturn]] void leave()
{
    porpoise::audio::close();
    porpoise::pad::close();
    std::fflush(nullptr);
    sceSystemServiceLoadExec("exit", nullptr);
    for (;;)
        usleep(100000); /* the shell ends the process; never exit(): it raises SIGSYS */
}

void prepass(VkCommandBuffer cmd, void *)
{
    if (g_gfx.ready())
        g_gfx.record_uploads(cmd);
}

void overlay(VkCommandBuffer cmd, unsigned, void *)
{
    if (g_gfx.ready())
        g_gfx.record(cmd);
}

bool start_gfx(const std::string &data)
{
    const porpoise::vk::Context c = porpoise::vk::context();
    porpoise::ui::GfxInit init;
    init.instance = c.instance;
    init.gpu = c.gpu;
    init.device = c.device;
    init.queue = c.queue;
    init.queue_family = c.queue_family;
    init.render_pass = c.render_pass;
    init.slots = c.slots;
    init.get_instance_proc = c.get_instance_proc;
    init.get_device_proc = c.get_device_proc;
    init.queue_mutex = c.queue_mutex;
    init.asset_dir = PORPOISE_APP "/assets";
    init.cache_dir = data + "/cache";
    mkdir(init.cache_dir.c_str(), 0777);
    return g_gfx.init(init);
}
} // namespace

int main(int, char **)
{
    std::freopen(PORPOISE_APP "/trace.txt", "w", stderr);
    std::setvbuf(stderr, nullptr, _IONBF, 0); /* every line on disk at once: the log survives a freeze */
    std::fprintf(stderr, "Recomp Store %s starting\n", recomp::kVersion);

    /* Out of the sandbox while still one thread, so /data/homebrew can be written. */
    porpoise::jailbreak::prepare();
    const bool freed = porpoise::jailbreak::ensure();
    std::fprintf(stderr, "jailbreak: %s\n", freed ? "data reachable" : "still sandboxed");

    const std::string data = freed ? std::string("/data/recompstore") : std::string(PORPOISE_APP "/data");
    mkdir(data.c_str(), 0777);

    if (!porpoise::vk::open_display())
        leave();
    porpoise::pad::open();
    porpoise::audio::open();
    if (!porpoise::vk::open_device(nullptr) || !start_gfx(data))
    {
        sceSystemServiceHideSplashScreen();
        leave();
    }
    porpoise::vk::set_overlay(overlay, nullptr);
    porpoise::vk::set_prepass(prepass, nullptr);
    sceSystemServiceHideSplashScreen();

    g_store.start(g_gfx, data, freed, float(porpoise::vk::screen_width()) / 1920.f);
    g_store.set_display(porpoise::vk::screen_width(), porpoise::vk::screen_height());
    std::fprintf(stderr, "display: %ux%u\n", porpoise::vk::screen_width(), porpoise::vk::screen_height());
    g_pacer.start(60.0, "store");
    long long last = now_ns();
    double time = 0.0;
    for (;;)
    {
        const long long now = now_ns();
        const float dt = static_cast<float>((now - last) / 1e9);
        last = now;
        time += dt;
        const porpoise::pad::State &pad = porpoise::pad::poll();
        if (!g_store.update(pad, dt))
            break;
        g_gfx.begin(porpoise::vk::current_slot(), float(porpoise::vk::screen_width()),
                    float(porpoise::vk::screen_height()), float(time), 0.f, false);
        g_store.draw(g_gfx);
        porpoise::sound::pump();
        porpoise::vk::present_clear(0.043f, 0.071f, 0.125f); /* midnight #0B1220 */
        g_pacer.frame_done();
    }
    leave();
}
