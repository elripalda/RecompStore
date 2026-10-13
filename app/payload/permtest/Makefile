# Recomp Store permission test payload (GPL-3.0-or-later).
#   make PS5_PAYLOAD_SDK=<sdk>        -> RecompStore-PermTest.elf
ifdef PS5_PAYLOAD_SDK
    include $(PS5_PAYLOAD_SDK)/toolchain/prospero.mk
else
    $(error PS5_PAYLOAD_SDK is undefined)
endif

ELF := RecompStore-PermTest.elf
CXXFLAGS := -std=c++20 -O2 -Wall -Werror

all: $(ELF)

$(ELF): permtest.cpp
	$(CXX) $(CXXFLAGS) -o $@ permtest.cpp
	$(PS5_PAYLOAD_SDK)/bin/llvm-strip --strip-debug $@ 2>/dev/null || true

clean:
	rm -f $(ELF)
