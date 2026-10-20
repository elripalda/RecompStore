/* Recomp Store preview: renders the store's screens offscreen with any Vulkan
 * driver (lavapipe) and writes PNGs, to check the UI without a console.
 *   store-preview <assets dir> <out dir>
 * Based on Porpoise's tools/ui-preview (GPL-3.0-or-later). */
#include <unistd.h>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <string>
#include <vector>
#include "store_app.hpp"
#include "ui_gfx.hpp"
#define STB_IMAGE_WRITE_IMPLEMENTATION
#include "stb/stb_image_write.h"

extern "C" VKAPI_ATTR PFN_vkVoidFunction VKAPI_CALL vkGetInstanceProcAddr(VkInstance, const char *);
using namespace porpoise::ui;
#define GI(name) auto name = reinterpret_cast<PFN_##name>(vkGetInstanceProcAddr(instance, #name))
#define GD(name) auto name = reinterpret_cast<PFN_##name>(gdpa(device, #name))

namespace recomp { void preview_job(const char *kind, const char *app); }
int main(int argc, char **argv)
{
    if (argc < 3) { std::fprintf(stderr, "usage: store-preview <assets> <out>\n"); return 2; }
    const std::string assets = argv[1], out = argv[2];
    const bool k4 = std::getenv("PREVIEW_4K") != nullptr;
    const unsigned W = k4 ? 3840 : 1920, H = k4 ? 2160 : 1080;
    VkInstance instance = VK_NULL_HANDLE;
    auto vkCreateInstance = reinterpret_cast<PFN_vkCreateInstance>(vkGetInstanceProcAddr(nullptr, "vkCreateInstance"));
    VkApplicationInfo app{VK_STRUCTURE_TYPE_APPLICATION_INFO};
    app.apiVersion = VK_API_VERSION_1_1;
    VkInstanceCreateInfo ici{VK_STRUCTURE_TYPE_INSTANCE_CREATE_INFO};
    ici.pApplicationInfo = &app;
    if (vkCreateInstance(&ici, nullptr, &instance) != VK_SUCCESS)
        return 1;
    GI(vkEnumeratePhysicalDevices);
    GI(vkCreateDevice);
    GI(vkGetDeviceProcAddr);
    GI(vkGetPhysicalDeviceMemoryProperties);
    VkPhysicalDevice gpu = VK_NULL_HANDLE;
    std::uint32_t n = 1;
    vkEnumeratePhysicalDevices(instance, &n, &gpu);
    const float prio = 1;
    VkDeviceQueueCreateInfo q{VK_STRUCTURE_TYPE_DEVICE_QUEUE_CREATE_INFO};
    q.queueCount = 1;
    q.pQueuePriorities = &prio;
    VkDeviceCreateInfo dci{VK_STRUCTURE_TYPE_DEVICE_CREATE_INFO};
    dci.queueCreateInfoCount = 1;
    dci.pQueueCreateInfos = &q;
    VkDevice device = VK_NULL_HANDLE;
    if (vkCreateDevice(gpu, &dci, nullptr, &device) != VK_SUCCESS)
        return 1;
    PFN_vkGetDeviceProcAddr gdpa = vkGetDeviceProcAddr;
    GD(vkGetDeviceQueue);
    GD(vkCreateImage);
    GD(vkGetImageMemoryRequirements);
    GD(vkAllocateMemory);
    GD(vkBindImageMemory);
    GD(vkCreateImageView);
    GD(vkCreateRenderPass);
    GD(vkCreateFramebuffer);
    GD(vkCreateCommandPool);
    GD(vkAllocateCommandBuffers);
    GD(vkBeginCommandBuffer);
    GD(vkEndCommandBuffer);
    GD(vkCmdBeginRenderPass);
    GD(vkCmdEndRenderPass);
    GD(vkCmdCopyImageToBuffer);
    GD(vkQueueSubmit);
    GD(vkQueueWaitIdle);
    GD(vkCreateBuffer);
    GD(vkGetBufferMemoryRequirements);
    GD(vkBindBufferMemory);
    GD(vkMapMemory);
    GD(vkResetCommandBuffer);
    VkQueue queue = VK_NULL_HANDLE;
    vkGetDeviceQueue(device, 0, 0, &queue);

    VkPhysicalDeviceMemoryProperties mp{};
    vkGetPhysicalDeviceMemoryProperties(gpu, &mp);
    auto memtype = [&](std::uint32_t bits, VkMemoryPropertyFlags f) {
        for (std::uint32_t i = 0; i < mp.memoryTypeCount; ++i)
            if ((bits & (1u << i)) && (mp.memoryTypes[i].propertyFlags & f) == f)
                return i;
        return 0u;
    };

    const VkFormat format = VK_FORMAT_R8G8B8A8_UNORM;
    VkImageCreateInfo ii{VK_STRUCTURE_TYPE_IMAGE_CREATE_INFO};
    ii.imageType = VK_IMAGE_TYPE_2D;
    ii.format = format;
    ii.extent = {W, H, 1};
    ii.mipLevels = ii.arrayLayers = 1;
    ii.samples = VK_SAMPLE_COUNT_1_BIT;
    ii.usage = VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_TRANSFER_SRC_BIT;
    VkImage image;
    vkCreateImage(device, &ii, nullptr, &image);
    VkMemoryRequirements req;
    vkGetImageMemoryRequirements(device, image, &req);
    VkMemoryAllocateInfo ai{VK_STRUCTURE_TYPE_MEMORY_ALLOCATE_INFO};
    ai.allocationSize = req.size;
    ai.memoryTypeIndex = memtype(req.memoryTypeBits, VK_MEMORY_PROPERTY_DEVICE_LOCAL_BIT);
    VkDeviceMemory imem;
    vkAllocateMemory(device, &ai, nullptr, &imem);
    vkBindImageMemory(device, image, imem, 0);
    VkImageViewCreateInfo vi{VK_STRUCTURE_TYPE_IMAGE_VIEW_CREATE_INFO};
    vi.image = image;
    vi.viewType = VK_IMAGE_VIEW_TYPE_2D;
    vi.format = format;
    vi.subresourceRange = {VK_IMAGE_ASPECT_COLOR_BIT, 0, 1, 0, 1};
    VkImageView view;
    vkCreateImageView(device, &vi, nullptr, &view);

    VkAttachmentDescription att{};
    att.format = format;
    att.samples = VK_SAMPLE_COUNT_1_BIT;
    att.loadOp = VK_ATTACHMENT_LOAD_OP_CLEAR;
    att.storeOp = VK_ATTACHMENT_STORE_OP_STORE;
    att.initialLayout = VK_IMAGE_LAYOUT_UNDEFINED;
    att.finalLayout = VK_IMAGE_LAYOUT_TRANSFER_SRC_OPTIMAL;
    VkAttachmentReference ref{0, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL};
    VkSubpassDescription sub{};
    sub.pipelineBindPoint = VK_PIPELINE_BIND_POINT_GRAPHICS;
    sub.colorAttachmentCount = 1;
    sub.pColorAttachments = &ref;
    VkRenderPassCreateInfo rpi{VK_STRUCTURE_TYPE_RENDER_PASS_CREATE_INFO};
    rpi.attachmentCount = 1;
    rpi.pAttachments = &att;
    rpi.subpassCount = 1;
    rpi.pSubpasses = &sub;
    VkRenderPass rp;
    vkCreateRenderPass(device, &rpi, nullptr, &rp);
    VkFramebufferCreateInfo fbi{VK_STRUCTURE_TYPE_FRAMEBUFFER_CREATE_INFO};
    fbi.renderPass = rp;
    fbi.attachmentCount = 1;
    fbi.pAttachments = &view;
    fbi.width = W;
    fbi.height = H;
    fbi.layers = 1;
    VkFramebuffer fb;
    vkCreateFramebuffer(device, &fbi, nullptr, &fb);

    VkBufferCreateInfo bi{VK_STRUCTURE_TYPE_BUFFER_CREATE_INFO};
    bi.size = VkDeviceSize(W) * H * 4;
    bi.usage = VK_BUFFER_USAGE_TRANSFER_DST_BIT;
    VkBuffer readback;
    vkCreateBuffer(device, &bi, nullptr, &readback);
    vkGetBufferMemoryRequirements(device, readback, &req);
    ai.allocationSize = req.size;
    ai.memoryTypeIndex = memtype(req.memoryTypeBits, VK_MEMORY_PROPERTY_HOST_VISIBLE_BIT |
                                                         VK_MEMORY_PROPERTY_HOST_COHERENT_BIT);
    VkDeviceMemory rmem;
    vkAllocateMemory(device, &ai, nullptr, &rmem);
    vkBindBufferMemory(device, readback, rmem, 0);
    void *mapped = nullptr;
    vkMapMemory(device, rmem, 0, VK_WHOLE_SIZE, 0, &mapped);

    VkCommandPoolCreateInfo cpi{VK_STRUCTURE_TYPE_COMMAND_POOL_CREATE_INFO};
    cpi.flags = VK_COMMAND_POOL_CREATE_RESET_COMMAND_BUFFER_BIT;
    VkCommandPool pool;
    vkCreateCommandPool(device, &cpi, nullptr, &pool);
    VkCommandBufferAllocateInfo cai{VK_STRUCTURE_TYPE_COMMAND_BUFFER_ALLOCATE_INFO};
    cai.commandPool = pool;
    cai.commandBufferCount = 1;
    VkCommandBuffer cmd;
    vkAllocateCommandBuffers(device, &cai, &cmd);

    GfxInit gi;
    gi.instance = instance;
    gi.gpu = gpu;
    gi.device = device;
    gi.queue = queue;
    gi.queue_family = 0;
    gi.render_pass = rp;
    gi.slots = 1;
    gi.get_instance_proc = vkGetInstanceProcAddr;
    gi.get_device_proc = gdpa;
    gi.asset_dir = assets;
    if (const char *cache = std::getenv("PREVIEW_CACHE"))
        gi.cache_dir = cache; /* the baked text atlases, as Porpoise keeps them */
    Gfx gfx;
    /* Covers decode on a worker thread in Porpoise; here, in place, so every
     * scene shows them (PREVIEW_ASYNC=1 to see the worker's way). */
    const bool async = std::getenv("PREVIEW_ASYNC") != nullptr;
    gfx.set_async_loads(async);
    if (!gfx.init(gi))
    {
        std::fprintf(stderr, "gfx init failed\n");
        return 1;
    }

    auto render = [&](const char *name, auto &&draw) {
        gfx.begin(0, float(W), float(H), 12.0f, 0.0f, false);
        draw();
        vkResetCommandBuffer(cmd, 0);
        VkCommandBufferBeginInfo b{VK_STRUCTURE_TYPE_COMMAND_BUFFER_BEGIN_INFO};
        vkBeginCommandBuffer(cmd, &b);
        gfx.record_uploads(cmd); /* streamed textures (Wii banners) */
        VkClearValue clear{};
        VkRenderPassBeginInfo rbi{VK_STRUCTURE_TYPE_RENDER_PASS_BEGIN_INFO};
        rbi.renderPass = rp;
        rbi.framebuffer = fb;
        rbi.renderArea.extent = {W, H};
        rbi.clearValueCount = 1;
        rbi.pClearValues = &clear;
        vkCmdBeginRenderPass(cmd, &rbi, VK_SUBPASS_CONTENTS_INLINE);
        gfx.record(cmd);
        vkCmdEndRenderPass(cmd);
        VkBufferImageCopy copy{};
        copy.imageSubresource = {VK_IMAGE_ASPECT_COLOR_BIT, 0, 0, 1};
        copy.imageExtent = {W, H, 1};
        vkCmdCopyImageToBuffer(cmd, image, VK_IMAGE_LAYOUT_TRANSFER_SRC_OPTIMAL, readback, 1, &copy);
        vkEndCommandBuffer(cmd);
        VkSubmitInfo si{VK_STRUCTURE_TYPE_SUBMIT_INFO};
        si.commandBufferCount = 1;
        si.pCommandBuffers = &cmd;
        vkQueueSubmit(queue, 1, &si, VK_NULL_HANDLE);
        vkQueueWaitIdle(queue);
        auto *px = static_cast<unsigned char *>(mapped);
        for (std::size_t i = 0; i < std::size_t(W) * H; ++i)
            px[i * 4 + 3] = 255;
        const std::string path = out + "/" + name + ".png";
        stbi_write_png(path.c_str(), int(W), int(H), 4, px, int(W * 4));
        std::printf("wrote %s\n", path.c_str());
    };

    auto render_noop = [&]() {
        vkResetCommandBuffer(cmd, 0);
        VkCommandBufferBeginInfo b{VK_STRUCTURE_TYPE_COMMAND_BUFFER_BEGIN_INFO};
        vkBeginCommandBuffer(cmd, &b);
        gfx.record_uploads(cmd);
        VkClearValue clear{};
        VkRenderPassBeginInfo rbi{VK_STRUCTURE_TYPE_RENDER_PASS_BEGIN_INFO};
        rbi.renderPass = rp; rbi.framebuffer = fb; rbi.renderArea.extent = {W, H};
        rbi.clearValueCount = 1; rbi.pClearValues = &clear;
        vkCmdBeginRenderPass(cmd, &rbi, VK_SUBPASS_CONTENTS_INLINE);
        gfx.record(cmd);
        vkCmdEndRenderPass(cmd);
        vkEndCommandBuffer(cmd);
        VkSubmitInfo si{VK_STRUCTURE_TYPE_SUBMIT_INFO};
        si.commandBufferCount = 1; si.pCommandBuffers = &cmd;
        vkQueueSubmit(queue, 1, &si, VK_NULL_HANDLE);
        vkQueueWaitIdle(queue);
    };

    recomp::Store store;
    store.start(gfx, out, true, float(W) / 1920.f);
    store.set_display(W, H);
    porpoise::pad::State none;
    auto settle = [&](int frames) { for (int i = 0; i < frames; ++i) store.update(none, 0.016f); };
    auto press = [&](std::uint32_t b) { porpoise::pad::State p; p.buttons = b; store.update(p, 0.016f); store.update(none, 0.016f); };
    auto frame = [&](const char *name) {
        /* Async picture loads finish over a few frames. */
        for (int i = 0; i < 40; ++i) { gfx.begin(0, float(W), float(H), 12.0f, 0.0f, false); store.draw(gfx); render_noop(); usleep(30000); }
        render(name, [&] { store.draw(gfx); });
    };
    (void)press;
    const char *only = std::getenv("PREVIEW_ONLY");
    auto want = [&](const char *n) { return !only || std::strstr(only, n); };
    for (float bt : {0.55f, 1.25f, 1.7f, 3.2f})
    {
        store.preview_boot(bt);
        char name[32];
        std::snprintf(name, sizeof name, "boot-%.2f", bt);
        if (want("boot")) { gfx.begin(0, float(W), float(H), 12.0f, 0.0f, false); store.draw(gfx); render_noop(); render(name, [&] { store.draw(gfx); }); }
    }
    store.preview_tab(0);
    settle(120);
    if (want("discover")) frame("discover");
    store.preview_row(0, 0);
    settle(30);
    if (want("discover")) frame("discover-banner");
    store.preview_tab(1); settle(90); if (want("library")) frame("library");
    store.preview_tab(2); settle(90); if (want("updates")) frame("updates");
    store.preview_settings(0); settle(90); if (want("settings")) frame("settings-general");
    store.preview_settings(1); settle(90); if (want("settings")) frame("settings-adding");
    store.preview_settings(3); settle(90); if (want("settings")) frame("settings-about");
    /* (2.2) Library > On this PS5 and the App manager. */
    if (want("v22"))
    {
        store.preview_console(0); settle(60); frame("v22-console-top");
        store.preview_console(4); settle(60); frame("v22-console-app");
        store.preview_console(5, 1); settle(60); frame("v22-console-biggest");
        store.preview_console(4, 0, "PS5SX2 is PPSA97003 now. Its new tile shows up once the store closes, and the old one goes. Saves it made as PPSA99203 stay with PPSA99203.");
        settle(60); frame("v22-console-note");
        store.preview_console(4); settle(30);
        store.preview_manage(0, 0); settle(10); frame("v22-manage");
        store.preview_manage(1, 0); settle(10); frame("v22-manage-move");
        store.preview_manage(2, 1); settle(10); frame("v22-manage-newid");
        store.preview_manage(3, 0); settle(10); frame("v22-manage-delete");
        store.preview_console(9); settle(30); store.preview_manage(3, 0); settle(10); frame("v22-manage-delete-store");
        store.preview_console(8); settle(30); store.preview_manage(0, 0); settle(10); frame("v22-manage-store");
        store.preview_console(3); settle(30); store.preview_manage(0, 0); settle(10); frame("v22-manage-folder");
        recomp::preview_job("mmove", "/data/homebrew/PPSA99203"); store.preview_console(4); settle(30); frame("v22-console-moving");
        recomp::preview_job("", "");
        store.preview_general(2); settle(30); frame("v22-settings-refresh");
    }
    if (const char *id = std::getenv("PREVIEW_PAGE")) { store.preview_open(id); settle(60); if (want("custom")) frame("page-custom"); }
    store.preview_open("PPSA92011"); settle(60); if (want("page")) frame("page-2011");
    store.preview_focus(1); if (want("page")) frame("page-2011-slot");
    if (const char *usb = std::getenv("PREVIEW_USB"))
    {
        store.preview_picker("game", std::string(usb) + "/Games");
        if (want("picker")) frame("picker");
        store.preview_open("PPSA92011");
    }
    store.preview_choose("game", "/mnt/usb0/Games/SmackDown vs Raw 2011.iso");
    store.preview_focus(2); if (want("page")) frame("page-2011-chosen");
    recomp::preview_job("setup", "PPSA92011"); if (want("page")) frame("page-2011-setup");
    recomp::preview_job("install", "PPSA92007");
    store.preview_open("PPSA92007"); settle(60);
    store.preview_speed(5.6f); if (want("page")) frame("page-2007-downloading");
    recomp::preview_job("", "");
    store.preview_speed(0);
    store.preview_open("PPSA92011"); settle(5); store.preview_focus(0); if (want("page")) frame("page-2011-open");
    store.preview_notice("SvR 2011 didn't open (0x80940005). If it isn't on your home screen yet, start ShadowMountPlus (Settings > General), give it a few seconds, then try again.", true);
    if (want("page")) frame("page-2011-open-failed");
    store.preview_notice("", false);
    store.preview_notes(1); if (want("notes")) frame("page-2011-notes-focus");
    store.preview_notes(2); if (want("notes")) frame("page-2011-notes-open");
    store.preview_notes(2, 9); if (want("notes")) frame("page-2011-notes-scrolled");
    store.preview_notes(0);
    store.preview_focus(3); if (want("page")) frame("page-2011-delete-focus");
    store.preview_confirm(); if (want("page")) frame("page-2011-confirm");
    store.preview_open("PPSA99902"); settle(60); if (want("page")) frame("page-unleashed");
    store.preview_general(1); settle(30); if (want("settings")) frame("settings-general-smp");
    store.preview_general(2); store.preview_settings_note("ShadowMountPlus is running; it's looking for new games now.", false); settle(30);
    if (want("settings")) frame("settings-general-refresh");
    store.preview_settings_note("", false);
    store.preview_general(3); settle(30); if (want("check")) frame("settings-general-check");
    /* A game set up from a GameCube disc image, and one that needs a newer store. */
    store.preview_open("PPSA99309"); settle(60); if (want("ww")) frame("page-windwaker");
    store.preview_focus(1); if (want("ww")) frame("page-windwaker-slot");
    if (const char *usb = std::getenv("PREVIEW_USB"))
    {
        store.preview_picker("game", std::string(usb) + "/Games");
        if (want("ww")) frame("picker-windwaker");
        store.preview_open("PPSA99309");
    }
    store.preview_open("PPSA99310"); settle(60); if (want("ww")) frame("page-needs-newer");
    store.preview_tab(2); settle(90); if (want("ww")) frame("updates-windwaker");
    /* 1.0.6: Discover's sections, scrolling; Coming soon; the lists scrolling; Keep my saves. */
    if (want("v106"))
    {
        store.preview_tab(0); settle(30);
        const int lines[][2] = {{0, 0}, {1, 2}, {2, 3}, {3, 1}, {4, 0}, {5, 2}, {6, 1}};
        const char *names[] = {"d106-banner", "d106-grid1", "d106-grid2", "d106-soon", "d106-spotlight", "d106-wide", "d106-bottom"};
        for (int i = 0; i < 7; ++i) { store.preview_row(lines[i][0], lines[i][1]); settle(5); frame(names[i]); }
        store.preview_open("soon-oot"); settle(60); frame("d106-soon-page");
        store.preview_tab(2); store.preview_row(0, 0); settle(30); frame("u106-top");
        store.preview_row(0, 7); settle(5); frame("u106-scrolled");
        store.preview_tab(1); store.preview_row(0, 0); settle(30); frame("l106-top");
        store.preview_row(0, 7); settle(5); frame("l106-scrolled");
        store.preview_open("PPSA92011"); settle(60); store.preview_focus(3); store.preview_confirm(1); frame("c106-keep-saves");
        store.preview_confirm(2); frame("c106-delete-everything");
    }
    /* 1.0.8: a Title Update the store downloads itself (Spider-Man: Edge of Time). */
    if (want("v108"))
    {
        store.preview_open("PPSA99300"); settle(60); store.preview_focus(2); frame("p108-download");
        store.preview_choose("update", recomp::kDownloadChoice); store.preview_focus(3); frame("p108-chosen");
        recomp::preview_job("fetch", "PPSA99300"); frame("p108-downloading");
        recomp::preview_job("", "");
        store.preview_row(0, 0); store.preview_settings(3); settle(90); frame("p108-about");
    }
    /* 1.1.0: Settings > Redeem a code, its keypad, and a beta game in the Library. */
    if (want("v110"))
    {
        store.preview_row(0, 0); store.preview_settings(2); settle(90); frame("r110-section");
        store.preview_redeem_note("Beta Test Game is unlocked. It's in your Library.", false); frame("r110-section-note");
        store.preview_redeem_note("", false);
        store.preview_keypad("", 0, 0); settle(10); frame("r110-keypad");
        store.preview_keypad("12345", 4, 0); frame("r110-typing");
        store.preview_keypad("12345678", 11, 0); frame("r110-full");
        store.preview_keypad("12345678", 11, 0, "That code isn't right. Check the numbers and try again.", true); frame("r110-wrong");
        store.preview_keypad("12345678", 11, 1); frame("r110-checking");
        store.preview_keypad("", 0, 2, "Beta Test Game is unlocked. It's in your Library.", false, "PPSA99777"); frame("r110-unlocked");
        store.preview_keypad_close();
        store.preview_tab(1); store.preview_row(0, 0); settle(60); frame("r110-library");
        store.preview_open("PPSA99777"); settle(60); frame("r110-page");
        store.preview_tab(2); store.preview_row(0, 0); settle(60); frame("r110-updates");
        store.preview_tab(0); store.preview_row(1, 0); settle(60); frame("r110-discover");
    }
    /* 1.1.0: Send logs on a game's page, and the keyboard for its message. */
    if (want("logs"))
    {
        store.preview_open("PPSA92011"); settle(60); store.preview_focus(4); frame("l110-page-focus");
        store.preview_logs("", 0); frame("l110-dialog");
        store.preview_logs("The game freezes when I", 0, 0, "", true, 2, 3); frame("l110-keyboard");
        store.preview_logs("The game freezes when I open Create a Superstar and pick a face paint. Happens every time.", 2); frame("l110-typed");
        store.preview_logs("The game freezes when I open Create a Superstar and pick a face paint. Happens every time.", 2, 1); frame("l110-sending");
        store.preview_logs("The game freezes when I open Create a Superstar and pick a face paint. Happens every time.", 1, 2, "Sent. Thanks for helping."); frame("l110-sent");
        store.preview_logs("", 2, 3, "That's a lot of logs at once. Try again in an hour."); frame("l110-failed");
        store.preview_logs_close();
        store.preview_open("PPSA92007"); settle(60); store.preview_focus(1); frame("l110-page-not-installed");
        /* With the controller: X on Send logs, X on the message, type H i (caps first), Done. */
        using namespace porpoise::pad;
        store.preview_open("PPSA92011"); settle(30); store.preview_focus(4);
        press(BtnCross); settle(10); press(BtnCross); settle(10);
        press(BtnDown); press(BtnRight); press(BtnRight); press(BtnRight); press(BtnRight); press(BtnRight); press(BtnCross); /* h */
        press(BtnUp); press(BtnRight); press(BtnRight); press(BtnCross); /* i */
        press(BtnTriangle); press(BtnUp); press(BtnCross); press(BtnSquare); press(BtnCross); settle(10);
        frame("l110-input-kb");
        press(BtnCircle); settle(20); frame("l110-input-dialog");
        store.preview_logs_close();
    }
    /* 1.1.0, with the controller: X on Redeem a code, type 1 2 (Right, X), Square deletes
     * the 2, Down Down Down onto 0... then Open it from the unlocked box. */
    if (want("v110in"))
    {
        using namespace porpoise::pad;
        store.preview_row(0, 0); store.preview_settings(2); settle(30);
        press(BtnCross); settle(20);
        press(BtnCross); press(BtnRight); press(BtnCross); press(BtnSquare);
        press(BtnDown); press(BtnDown); press(BtnDown); press(BtnCross); press(BtnCross); settle(10);
        frame("r110-input");
        store.preview_keypad("", 0, 2, "Beta Test Game is unlocked. It's in your Library.", false, "PPSA99777");
        press(BtnCross); settle(60);
        frame("r110-open-it");
        press(BtnCircle); settle(60);
        frame("r110-open-it-back");
    }
    /* 2.0: categories, the Library, Customize, the title ID guard, Refresh, pins. Tab kinds:
     * Discover 0, Library 1, Settings 3, Games 4, Emulators 5, Apps 6. */
    if (want("v2"))
    {
        store.preview_tab(0); store.preview_row(0, 0); settle(90); frame("v2-discover");
        store.preview_row(1, 1); settle(30); frame("v2-discover-cats");
        store.preview_pin("PPSA94202"); store.preview_pin("PPSA64007"); store.preview_pin("PPSA99764");
        store.preview_row(2, 0); settle(30); frame("v2-discover-pinned");
        store.preview_row(4, 2); settle(40); frame("v2-discover-emulators-row");
        store.preview_category(4, 0, 0); settle(60); frame("v2-games");
        store.preview_category(4, 2, 0, true); settle(30); frame("v2-games-chip");
        store.preview_category(5, 0, 0); settle(60); frame("v2-emulators");
        store.preview_category(6, 0, 1); settle(60); frame("v2-apps");
        store.preview_category(7, 0, 0); settle(60); frame("v2-tools");
        store.preview_library(0, 2); settle(60); frame("v2-library");
        store.preview_prefs(1, false); store.preview_library(0, 1); settle(60); frame("v2-library-list");
        store.preview_prefs(1, true); settle(5); frame("v2-library-list-advanced");
        store.preview_prefs(0, false);
        store.preview_library(1, 0); settle(60); frame("v2-library-updates");
        store.preview_queue({"PPSA64008", "PPSA94202"}, 1); recomp::preview_job("install", "PPSA64007"); settle(10); frame("v2-library-updating");
        recomp::preview_job("", ""); store.preview_queue({}, 0);
        store.preview_library(2, 0); settle(60); frame("v2-library-betas");
        store.preview_customize(0); settle(60); frame("v2-customize");
        store.preview_customize(6); settle(10); frame("v2-customize-advanced");
        store.preview_open("PPSA94202"); settle(60); store.preview_clash("RPCS3", "PPSA97001", false); settle(10); frame("v2-clash");
        store.preview_clash("RPCS3", "PPSA97001", false, 1); frame("v2-clash-over");
        store.preview_clash("RPCS3", "PPSA97001", true); frame("v2-clash-pkg");
        store.preview_clash("Old Port", "", false); frame("v2-clash-cantmove");
        store.preview_clash_close(); store.preview_open("PPSA92011"); settle(60); frame("v2-page-moved");
        store.preview_tab(0); store.preview_row(0, 0); store.preview_refresh(1); settle(30); frame("v2-refreshing");
        store.preview_refresh(2); frame("v2-refreshed");
    }
    /* 2.0, stage 3 and 4: Link a phone, Sources, the sheet, the keyboard for an address, a
     * custom source's app, the charts and the sources' row on Discover, the report QR. */
    if (want("v2b"))
    {
        store.preview_tab(0); store.preview_row(2, 0); settle(90); frame("v2b-discover-top");
        store.preview_row(5, 0); settle(60); frame("v2b-discover-sources");
        store.preview_link(-1); settle(60); frame("v2b-link");
        store.preview_link(0); settle(10); frame("v2b-link-focus");
        setenv("PREVIEW_DEVICES", "2", 1); store.preview_link(1); settle(10); frame("v2b-link-devices");
        setenv("PREVIEW_LINK", "1", 1); store.preview_link(-1); settle(10); frame("v2b-link-waiting");
        setenv("PREVIEW_LINK", "3", 1); frame("v2b-link-error");
        setenv("PREVIEW_LINK", "2", 1); unsetenv("PREVIEW_DEVICES");
        store.preview_sources(-1); settle(60); frame("v2b-sources");
        store.preview_sources(0); store.preview_src_note("Added. Reading it now\xE2\x80\xA6", false); settle(10); frame("v2b-sources-add");
        store.preview_src_note("", false);
        store.preview_sources(1); settle(10); frame("v2b-sources-row");
        store.preview_sheet(2, "https://raw.githubusercontent.com/someone/ps5-lists/main/emulators.json", 2); settle(10); frame("v2b-sheet-source");
        store.preview_sheet(1, "drkn/ps5-ftp", 0); frame("v2b-sheet-phone");
        store.preview_sheet_close();
        store.preview_kb(1, "github.com/someone/mgba-p", 2, 9); settle(5); frame("v2b-kb-source");
        store.preview_kb(2, "It installs but crashes on start", 3, 2); frame("v2b-kb-report");
        store.preview_logs_close();
        store.preview_open("PPSA77001"); settle(60); frame("v2b-page-custom");
        store.preview_open("gh-1a2b3c4d5e"); settle(60); store.preview_focus(1); frame("v2b-page-custom-gh");
        store.preview_open("PPSA97771"); settle(60); frame("v2b-page-testing");
        store.preview_category(5, 0, 0); settle(60); frame("v2b-emulators");
        store.preview_open("PPSA92011"); settle(30);
        setenv("PREVIEW_REPORT", "R7K2QX", 1);
        store.preview_logs("The game freezes when I open Create a Superstar.", 1, 2, "Sent. Thanks for helping."); settle(10); frame("v2b-logs-report");
        store.preview_logs_close(); unsetenv("PREVIEW_REPORT");
        setenv("PREVIEW_TESTER", "1", 1);
        store.preview_general(4); settle(30); frame("v2b-general-tester");
        store.preview_settings(3); settle(30); frame("v2b-about");
        setenv("PREVIEW_ROOT", "/mnt/ext1/homebrew", 1);
        store.preview_prefs(0, true); store.preview_customize(7); settle(30); frame("v2b-customize-root");
        store.preview_open("PPSA92011"); settle(30); frame("v2b-page-root");
        unsetenv("PREVIEW_ROOT"); store.preview_prefs(0, false);
        store.preview_open("PPSA99764"); store.preview_focus(0); settle(60); frame("v2b-page-emulator");
        store.preview_open("PPSA97772"); store.preview_focus(0); settle(60); frame("v2b-page-app");
    }
    /* 2.1: Discover's header styles, a part of the store sorted, Versions (a GitHub app's and a
     * beta's), an app kept at an older version, a beta's notes, and where an app downloads from
     * changed. With PREVIEW_NOTES (releases with their files), PREVIEW_NEWEST and PREVIEW_HELD. */
    if (want("s21"))
    {
        store.preview_tab(0); store.preview_row(0, 0); settle(90); frame("v21-carousel");
        store.preview_header("spotlight"); store.preview_row(0, 0); settle(30); frame("v21-spotlight");
        store.preview_row(1, 1); settle(40); frame("v21-spotlight-below");
        store.preview_header("split", {"PPSA92011", "PPSA64007", "PPSA94202", "PPSA99314"}); store.preview_row(0, 0); settle(30); frame("v21-split");
        store.preview_row(0, 2); settle(40); frame("v21-split-card");
        store.preview_header("split", {"PPSA92011", "PPSA64007"}); store.preview_row(0, 0); settle(30); frame("v21-split-two");
        {
            /* With the controller: Up from Tools into the split header's bottom card, Up, Left onto the
             * banner, Right, then Down along the cards and out to the buttons. */
            using namespace porpoise::pad;
            store.preview_header("split", {"PPSA92011", "PPSA64007", "PPSA94202", "PPSA99314"});
            store.preview_row(1, 3); settle(10); press(BtnUp); settle(30); frame("v21-split-up-from-tools");
            press(BtnUp); press(BtnLeft); settle(30); frame("v21-split-left-to-banner");
            press(BtnRight); press(BtnDown); press(BtnDown); press(BtnDown); settle(30); frame("v21-split-down-out");
            store.preview_header("mosaic"); store.preview_row(1, 3); settle(10); press(BtnUp); settle(30); frame("v21-mosaic-up-from-tools");
            press(BtnUp); settle(20); press(BtnLeft); settle(30); frame("v21-mosaic-up-left");
        }
        store.preview_header("mosaic"); store.preview_row(0, 0); settle(40); frame("v21-mosaic");
        store.preview_row(0, 2); settle(40); frame("v21-mosaic-small");
        store.preview_header("none"); store.preview_tab(0); store.preview_row(0, 0); settle(40); frame("v21-none");
        store.preview_header("carousel");
        store.preview_sort(0, "az"); store.preview_category(4, 0, 0); settle(60); frame("v21-games-az");
        store.preview_sort(0, "installs"); store.preview_category(4, 0, 0); settle(30); frame("v21-games-installs");
        store.preview_sort(0, "new"); store.preview_category(4, 0, 0); settle(30); frame("v21-games-new");
        store.preview_sort(0, "az"); store.preview_tab(0); store.preview_row(3, 0); settle(40); frame("v21-discover-category-az");
        store.preview_sort(0, "manual");
        /* Versions on SvR 2011's page (installed: Open, Delete, Send logs, Versions). */
        store.preview_open("PPSA92011"); settle(60); store.preview_focus(3); frame("v21-page-versions-focus");
        store.preview_versions(6); settle(5); frame("v21-versions");
        store.preview_versions(8); settle(5); frame("v21-versions-scrolled");
        store.preview_versions(3, true); settle(5); frame("v21-versions-ask");
        store.preview_versions(0, true); settle(5); frame("v21-versions-ask-newest");
        store.preview_versions_close();
        const char *notes = std::getenv("PREVIEW_NOTES");
        const std::string keep_notes = notes ? notes : "";
        unsetenv("PREVIEW_NOTES"); store.preview_versions(0); settle(5); frame("v21-versions-offline");
        store.preview_versions_close();
        if (!keep_notes.empty()) setenv("PREVIEW_NOTES", keep_notes.c_str(), 1);
        /* Not installed: Versions beside Send logs; the newest picked. */
        store.preview_open("PPSA94002"); settle(60); store.preview_focus(2); frame("v21-page-not-installed-versions");
        store.preview_versions(0, true); settle(5); frame("v21-versions-ask-not-installed");
        store.preview_versions_close();
        /* Kept at 1.0.0: its page still offers the newest; the Library says it's kept. */
        setenv("PREVIEW_HELD", "PPSA92011=1.0.0", 1);
        store.preview_open("PPSA92011"); settle(60); store.preview_focus(0); frame("v21-page-kept");
        store.preview_row(0, 0); store.preview_prefs(1, false); store.preview_library(0, 5); settle(60); frame("v21-library-kept");
        store.preview_library(1, 0); settle(60); frame("v21-updates-kept");
        store.preview_prefs(0, false); store.preview_library(0, 0); settle(60); frame("v21-library-kept-tiles");
        unsetenv("PREVIEW_HELD");
        /* A beta: its notes for this build, its builds as Versions. */
        store.preview_open("PPSA69424"); settle(60); store.preview_focus(0); frame("v21-beta-page");
        store.preview_focus(3); frame("v21-beta-page-versions-focus");
        store.preview_versions(0); settle(5); frame("v21-beta-versions");
        store.preview_versions(1, true); settle(5); frame("v21-beta-versions-ask");
        store.preview_versions_close();
        store.preview_notes(2); frame("v21-beta-notes");
        store.preview_notes(0);
        {
            const char *inst = std::getenv("PREVIEW_INSTALLED");
            const std::string keep_inst = inst ? inst : "";
            setenv("PREVIEW_INSTALLED", "PPSA69424=0.9.2", 1);
            setenv("PREVIEW_HELD", "PPSA69424=0.9.2", 1);
            store.preview_open("PPSA69424"); settle(60); store.preview_focus(0); frame("v21-beta-kept");
            store.preview_versions(1); settle(5); frame("v21-beta-kept-versions");
            store.preview_versions(0, true); settle(5); frame("v21-beta-kept-ask-newest");
            store.preview_versions_close();
            store.preview_row(0, 0); store.preview_library(2, 0); settle(60); frame("v21-betas-kept");
            unsetenv("PREVIEW_HELD");
            if (!keep_inst.empty()) setenv("PREVIEW_INSTALLED", keep_inst.c_str(), 1);
        }
        /* Where SvR 2011 downloads from changed. */
        store.preview_open("PPSA92011"); settle(60); store.preview_moved("someone/SvR2011-PS5", "elripalda/SvR2011-Recomp-PS5"); settle(5);
        frame("v21-moved");
        store.preview_moved("someone/SvR2011-PS5", "elripalda/SvR2011-Recomp-PS5", 0); frame("v21-moved-update");
        store.preview_moved_close();
    }
    return 0;
}
