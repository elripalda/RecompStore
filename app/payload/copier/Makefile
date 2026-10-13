# Recomp Store copier payload (GPL-3.0-or-later).
#   make PS5_PAYLOAD_SDK=<sdk>        -> recompstore-copier.elf
ifdef PS5_PAYLOAD_SDK
    include $(PS5_PAYLOAD_SDK)/toolchain/prospero.mk
else
    $(error PS5_PAYLOAD_SDK is undefined)
endif

ELF := recompstore-copier.elf
SRC := copier.cpp ../../src/store_disc.cpp ../../src/store_zip.cpp
CXXFLAGS := -std=c++20 -O2 -Wall -Werror -I../../src -I../../third_party

all: $(ELF)

$(ELF): $(SRC) ../../src/store_disc.hpp ../../src/store_zip.hpp
	$(CXX) $(CXXFLAGS) -o $@ $(SRC) -lpthread
	$(PS5_PAYLOAD_SDK)/bin/llvm-strip --strip-debug $@ 2>/dev/null || true

clean:
	rm -f $(ELF)
