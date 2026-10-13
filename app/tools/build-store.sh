#!/usr/bin/env bash
# Recomp Store - build the title (no emulator core): eboot.bin and dist/PPSA92000/.
# Derived from Porpoise's tools/build-porpoise.sh (GPL-3.0-or-later).
#   PS5_VULKAN_DIR         Mihawk's PS5_Vulkan checkout with RADV built (release); ../PS5_Vulkan
#   PS5_PAYLOAD_SDK_FORK   Mihawk's PS5_PayloadSDK checkout; ../PS5_PayloadSDK
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"
sdk="$root/.deps/native/ps5-payload-sdk"
bash "$root/tools/setup-native-dependencies.sh" >/dev/null
[[ -x $sdk/bin/prospero-lld ]] || { echo "error: no SDK at $sdk" >&2; exit 2; }
vulkan_dir="${PS5_VULKAN_DIR:-$root/../PS5_Vulkan}"
radv_archive=${RADV_ARCHIVE:-$vulkan_dir/.deps/native/radv-release/lib/libvulkan_radeon.ps5.a}
[[ -f $radv_archive ]] || { echo "error: RADV is not built: $radv_archive" >&2; exit 2; }
source "$vulkan_dir/tools/radv-link.sh"
radv_link_recipe "$vulkan_dir" "$sdk" "$radv_archive" || exit 2
vulkan_flags="--no-dynamic-linker -z nodynamic-undefined-weak --wrap=sceVideoOutOpen"
for flag in "${radv_link_flags[@]}"; do
    case $flag in
        --wrap=malloc | --wrap=calloc | --wrap=realloc | --wrap=free | --wrap=posix_memalign | \
        --wrap=aligned_alloc | --wrap=memalign | --wrap=malloc_usable_size | --wrap=reallocf | \
        --wrap=reallocarray | --wrap=getline | --wrap=getdelim) ;;
        *) vulkan_flags+=" $flag" ;;
    esac
done
vulkan_flags+=" ${radv_link_inputs[*]:5}"
linker_script="$vulkan_dir/tooling/psbc/ps5-pie-unwind.ld"
directory_wrap_flags="--wrap=opendir --wrap=readdir --wrap=closedir --wrap=fdopendir --wrap=openat --wrap=unlinkat --wrap=fchmodat"
directory_wrap_flags+=" --wrap=realpath --wrap=getcwd --wrap=mkdir --wrap=open --wrap=fopen"
# The copier payload (payload/copier): game setups copy through it at the drive's full
# speed. Built fresh each time and shipped in the app's assets.
make -s -C "$root/payload/copier" PS5_PAYLOAD_SDK="$sdk" clean all
mkdir -p "$root/assets/payloads"
cp "$root/payload/copier/recompstore-copier.elf" "$root/assets/payloads/recompstore-copier.elf"
# The launcher payload (payload/launcher): Open hands it the game, as an app may not
# start another app; built as Porpoise's home launcher is.
PS5_PAYLOAD_SDK="$sdk" "$sdk/bin/prospero-clang" -Wall -Werror -O2 \
    -o "$root/payload/launcher/recompstore-launcher.elf" "$root/payload/launcher/launcher.c" -lSceSystemService -lSceUserService
PS5_PAYLOAD_SDK="$sdk" "$sdk/bin/prospero-strip" "$root/payload/launcher/recompstore-launcher.elf"
cp "$root/payload/launcher/recompstore-launcher.elf" "$root/assets/payloads/recompstore-launcher.elf"
# The installer payload (payload/installer): installs the store from its GitHub release,
# and is how the store updates itself. Shipped in the app and on the release.
make -s -C "$root/payload/installer" PS5_PAYLOAD_SDK="$sdk" clean all
cp "$root/payload/installer/RecompStore-Installer.elf" "$root/assets/payloads/RecompStore-Installer.elf"
PS5_PAYLOAD_SDK="$sdk" \
PS5_CLANG=/usr/bin/clang-18 \
PYTHONPATH="$root/tooling/pystub${PYTHONPATH:+:$PYTHONPATH}" \
APP_DEFINITIONS="PS5_RETROARCH_RADV PORPOISE RECOMP_STORE" \
APP_INCLUDE_PATHS="third_party third_party/libretro third_party/vulkan build" \
APP_STATIC_ARCHIVES="" \
APP_SDK_ARCHIVES="libps5platform.a" \
APP_VULKAN_ARCHIVES="$radv_archive" \
APP_EXTRA_OBJECTS="" \
APP_LINK_FLAGS="$vulkan_flags --wrap=malloc --wrap=calloc --wrap=realloc --wrap=free $directory_wrap_flags" \
APP_LINKER_SCRIPT="$linker_script" \
    make app
echo "==> [store] dist/PPSA92000 ready"
# The release: the store's zip, and the installer with that zip built in (it installs with
# no network; the store ships the lighter one above, for its own updates).
version=$(sed -n 's/.*kVersion = "\([^"]*\)".*/\1/p' "$root/src/ui_app_common.hpp")
content=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['contentVersion'])" "$root/dist/PPSA92000/sce_sys/param.json")
mkdir -p "$root/release"
rm -f "$root/release/PPSA92000.zip"
(cd "$root/dist" && zip -qr -X "$root/release/PPSA92000.zip" PPSA92000)
make -s -C "$root/payload/installer" PS5_PAYLOAD_SDK="$sdk" ELF=RecompStore-Installer-full.elf \
    EMBED_ZIP="$root/release/PPSA92000.zip" EMBED_VERSION="$version" EMBED_CONTENT="$content" clean all
cp "$root/payload/installer/RecompStore-Installer-full.elf" "$root/release/RecompStore-Installer.elf"
echo "==> [store] release/ ready: PPSA92000.zip and RecompStore-Installer.elf ($version, $content)"
