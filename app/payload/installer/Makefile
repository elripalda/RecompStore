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
SRC := installer.cpp ../../src/porpoise_http.cpp ../../src/store_zip.cpp ../../src/store_json.cpp
CXXFLAGS := -std=c++20 -O2 -Wall -Werror -I../../src -I../../third_party
LIBS := -lSceNet -lSceNetCtl -lSceSsl -lSceHttp2 -lSceSystemService -lSceUserService -lSceNotification -lpthread
ifdef EMBED_ZIP
    CXXFLAGS += -DRS_EMBEDDED -DRS_EMBEDDED_VERSION='"$(EMBED_VERSION)"' -DRS_EMBEDDED_CONTENT='"$(EMBED_CONTENT)"'
    EXTRA := embed.o
endif

all: $(ELF)

embed.o: embed.S $(EMBED_ZIP)
	$(CC) -c -DRS_EMBED_PATH='"$(abspath $(EMBED_ZIP))"' -o $@ embed.S

$(ELF): $(SRC) $(EXTRA) ../../src/store_zip.hpp ../../src/store_json.hpp ../../src/store_sha256.hpp ../../src/porpoise_http.hpp
	$(CXX) $(CXXFLAGS) -o $@ $(SRC) $(EXTRA) $(LIBS)
	$(PS5_PAYLOAD_SDK)/bin/llvm-strip --strip-debug $@ 2>/dev/null || true

clean:
	rm -f $(ELF) embed.o
