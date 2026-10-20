# Recomp Store installer payload (GPL-3.0-or-later).
#   make PS5_PAYLOAD_SDK=<sdk>                      -> RecompStore-Installer.elf (no store inside:
#                                                     the one the store ships, for its updates)
#   make PS5_PAYLOAD_SDK=<sdk> EMBED_ZIP=<zip> EMBED_VERSION=1.0.0 EMBED_CONTENT=01.000.000
#                                                   -> the release's installer, the store inside
ifdef PS5_PAYLOAD_SDK
    include $(PS5_PAYLOAD_SDK)/toolchain/prospero.mk
else
    $(error PS5_PAYLOAD_SDK is undefined)
endif

ELF ?= RecompStore-Installer.elf
SRC := installer.cpp ../../src/porpoise_http.cpp ../../src/store_zip.cpp ../../src/store_7z.cpp ../../src/store_json.cpp
CXXFLAGS := -std=c++20 -O2 -Wall -Werror -I../../src -I../../third_party
# (2.0) store_zip.cpp unpacks a .7z too: the LZMA SDK's decoder (src/lzma, public domain), built as C.
LZMA_C := $(wildcard ../../src/lzma/*.c)
LZMA_O := $(patsubst ../../src/lzma/%.c,lzma-obj/%.o,$(LZMA_C))
# (2.1) Signature checks: rs_sig.c and Monocypher's Ed25519 (src/monocypher, CC0), built as C.
SIG_C := ../../src/rs_sig.c ../../src/monocypher/monocypher.c ../../src/monocypher/monocypher-ed25519.c
SIG_O := sig-obj/rs_sig.o sig-obj/monocypher.o sig-obj/monocypher-ed25519.o
LIBS := -lSceNet -lSceNetCtl -lSceSsl -lSceHttp2 -lSceSystemService -lSceUserService -lSceNotification -lpthread
ifdef EMBED_ZIP
    CXXFLAGS += -DRS_EMBEDDED -DRS_EMBEDDED_VERSION='"$(EMBED_VERSION)"' -DRS_EMBEDDED_CONTENT='"$(EMBED_CONTENT)"'
    EXTRA := embed.o
endif

all: $(ELF)

lzma-obj/%.o: ../../src/lzma/%.c $(wildcard ../../src/lzma/*.h)
	@mkdir -p lzma-obj
	$(CC) -std=c11 -O2 -Wall -c -o $@ $<

sig-obj/rs_sig.o: ../../src/rs_sig.c ../../src/rs_sig.h ../../src/rs_sig_keys.h
	@mkdir -p sig-obj
	$(CC) -std=c11 -O2 -Wall -Werror -c -o $@ $<

sig-obj/%.o: ../../src/monocypher/%.c $(wildcard ../../src/monocypher/*.h)
	@mkdir -p sig-obj
	$(CC) -std=c11 -O2 -Wall -c -o $@ $<

embed.o: embed.S $(EMBED_ZIP)
	$(CC) -c -DRS_EMBED_PATH='"$(abspath $(EMBED_ZIP))"' -o $@ embed.S

$(ELF): $(SRC) $(EXTRA) $(LZMA_O) $(SIG_O) ../../src/store_zip.hpp ../../src/store_json.hpp ../../src/store_sha256.hpp ../../src/porpoise_http.hpp ../../src/rs_sig.h
	$(CXX) $(CXXFLAGS) -o $@ $(SRC) $(EXTRA) $(LZMA_O) $(SIG_O) $(LIBS)
	$(PS5_PAYLOAD_SDK)/bin/llvm-strip --strip-debug $@ 2>/dev/null || true

clean:
	rm -f $(ELF) embed.o
	rm -rf lzma-obj sig-obj
