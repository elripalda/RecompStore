# Recomp Store installer payload (GPL-3.0-or-later).
#   make PS5_PAYLOAD_SDK=<sdk>        -> RecompStore-Installer.elf
ifdef PS5_PAYLOAD_SDK
    include $(PS5_PAYLOAD_SDK)/toolchain/prospero.mk
else
    $(error PS5_PAYLOAD_SDK is undefined)
endif

ELF := RecompStore-Installer.elf
SRC := installer.cpp ../../src/porpoise_http.cpp ../../src/store_zip.cpp ../../src/store_json.cpp
CXXFLAGS := -std=c++20 -O2 -Wall -Werror -I../../src -I../../third_party

all: $(ELF)

$(ELF): $(SRC) ../../src/store_zip.hpp ../../src/store_json.hpp ../../src/store_sha256.hpp ../../src/porpoise_http.hpp
	$(CXX) $(CXXFLAGS) -o $@ $(SRC) -lSceNet -lSceNetCtl -lSceSsl -lSceHttp2 -lSceSystemService -lSceUserService -lpthread
	$(PS5_PAYLOAD_SDK)/bin/llvm-strip --strip-debug $@ 2>/dev/null || true

clean:
	rm -f $(ELF)
