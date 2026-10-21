# Recomp Store copier payload (GPL-3.0-or-later).
#   make PS5_PAYLOAD_SDK=<sdk>        -> recompstore-copier.elf
ifdef PS5_PAYLOAD_SDK
    include $(PS5_PAYLOAD_SDK)/toolchain/prospero.mk
else
    $(error PS5_PAYLOAD_SDK is undefined)
endif

ELF := recompstore-copier.elf
SRC := copier.cpp ../../src/store_disc.cpp ../../src/store_zip.cpp ../../src/store_7z.cpp ../../src/store_stfs.cpp \
       ../../src/store_fs.cpp ../../src/store_backup.cpp
CXXFLAGS := -std=c++20 -O2 -Wall -Werror -I../../src -I../../third_party
# (2.0) .7z releases: the LZMA SDK's decoder (src/lzma, public domain), built as C.
LZMA_C := $(wildcard ../../src/lzma/*.c)
LZMA_O := $(patsubst ../../src/lzma/%.c,lzma-obj/%.o,$(LZMA_C))

all: $(ELF)

lzma-obj/%.o: ../../src/lzma/%.c $(wildcard ../../src/lzma/*.h)
	@mkdir -p lzma-obj
	$(CC) -std=c11 -O2 -Wall -c -o $@ $<

$(ELF): $(SRC) $(LZMA_O) ../../src/store_disc.hpp ../../src/store_zip.hpp ../../src/store_stfs.hpp ../../src/store_fs.hpp ../../src/store_backup.hpp
	$(CXX) $(CXXFLAGS) -o $@ $(SRC) $(LZMA_O) -lpthread
	$(PS5_PAYLOAD_SDK)/bin/llvm-strip --strip-debug $@ 2>/dev/null || true

clean:
	rm -f $(ELF)
	rm -rf lzma-obj
