/* Recomp Store - (2.0) a game's .7z, unpacked into its folder the way its zip is
 * (store_zip.cpp): the same folder of it installed, the same names refused, the same files
 * of the player's kept, every file written beside its old copy as <name>.new and checked,
 * then swapped in by the zip's own commit.
 *
 * A .7z packs many files into one stream (a solid block). That stream is decoded a piece at
 * a time, straight into the files it holds, so a big game never has to fit in memory: only
 * LZMA's dictionary does, and never more of it than the block's own size.
 * The 7z reading itself is Igor Pavlov's LZMA SDK (src/lzma, public domain).
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_zip.hpp"

#include <algorithm>
#include <cctype>
#include <cerrno>
#include <cstdlib>
#include <cstring>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>

#include "lzma/7z.h"
#include "lzma/7zCrc.h"
#include "lzma/Bra.h"
#include "lzma/Delta.h"
#include "lzma/Lzma2Dec.h"
#include "lzma/LzmaDec.h"

/* The SDK checks its headers with these: the store's own CRC-32, so its 7zCrc.c (and the CPU
 * feature probing it brings) isn't needed. CrcUpdate works on the CRC before its final
 * inversion, as the SDK's does. */
extern "C" UInt32 Z7_FASTCALL CrcUpdate(UInt32 v, const void *data, size_t size)
{
    return ~recomp::zip::crc32(static_cast<const std::uint8_t *>(data), size, ~v);
}
extern "C" UInt32 Z7_FASTCALL CrcCalc(const void *data, size_t size)
{
    return recomp::zip::crc32(static_cast<const std::uint8_t *>(data), size);
}

namespace recomp::zip
{
#ifdef RECOMP_7Z_TEST
/* Desktop tests only: the in-memory limit lowered, and the biggest allocation seen. */
std::uint64_t g_7z_in_memory_max = 512ull << 20;
std::size_t g_7z_biggest_alloc = 0;
#endif
namespace
{
/* Method IDs, as 7z writes them (7zDec.c). */
constexpr UInt32 kCopy = 0, kLzma = 0x30101, kLzma2 = 0x21, kAes = 0x6F10701;
constexpr UInt32 kBcj = 0x3030103, kPpc = 0x3030205, kIa64 = 0x3030401, kArm = 0x3030501, kArmt = 0x3030701,
                 kSparc = 0x3030805, kArm64 = 0xA, kRiscv = 0xB, kDelta = 3;
/* A block packed some other way (BCJ2, PPMd) is decoded whole, in memory, up to this size. */
[[maybe_unused]] constexpr std::uint64_t kInMemoryMax = 512ull << 20;
/* Pieces read from the download, and pieces decoded at a time. */
constexpr std::size_t kInPiece = 1u << 20, kOutPiece = 1u << 20;

/* What went wrong, beyond the SDK's own SZ_ERROR_ codes. */
constexpr SRes kSinkStopped = 100; /* the files' side said stop (Stop, a write failed, or read_one has its file) */
constexpr SRes kPassword = 101;    /* AES: the .7z has a password */
constexpr SRes kTooBig = 102;      /* packed a way that's only decoded in memory, and too big for that */

const char *const kUnsupported = "This .7z is packed in a way the store can't unpack. Ask its developer for a .zip.";
const char *const kHasPassword = "This .7z has a password, so the store can't unpack it. Ask its developer for a .zip.";

void *sz_alloc(ISzAllocPtr, size_t n)
{
#ifdef RECOMP_7Z_TEST
    g_7z_biggest_alloc = std::max(g_7z_biggest_alloc, n);
#endif
    return n == 0 ? nullptr : std::malloc(n);
}
void sz_free(ISzAllocPtr, void *p) { std::free(p); }
const ISzAlloc g_alloc = {sz_alloc, sz_free};

std::uint32_t le32(const Byte *p)
{
    return std::uint32_t(p[0]) | std::uint32_t(p[1]) << 8 | std::uint32_t(p[2]) << 16 | std::uint32_t(p[3]) << 24;
}

bool read_at(int fd, std::uint64_t at, void *dst, std::size_t n)
{
    auto *p = static_cast<std::uint8_t *>(dst);
    while (n)
    {
        const ssize_t got = pread(fd, p, n, off_t(at));
        if (got < 0 && errno == EINTR)
            continue;
        if (got <= 0)
            return false;
        p += got;
        at += std::uint64_t(got);
        n -= std::size_t(got);
    }
    return true;
}

/* The download, as the SDK reads it: pread on the file, nothing from stdio. vt comes first,
 * so the SDK's pointer to it is a pointer to the whole. */
struct FdIn
{
    ISeekInStream vt;
    int fd = -1;
    std::uint64_t pos = 0, size = 0;
};
SRes fd_read(ISeekInStreamPtr pp, void *buf, size_t *size)
{
    FdIn *p = const_cast<FdIn *>(reinterpret_cast<const FdIn *>(pp));
    const std::size_t want = *size;
    *size = 0;
    if (p->pos >= p->size || want == 0)
        return SZ_OK;
    const std::size_t n = std::size_t(std::min<std::uint64_t>(want, p->size - p->pos));
    if (!read_at(p->fd, p->pos, buf, n))
        return SZ_ERROR_READ;
    p->pos += n;
    *size = n;
    return SZ_OK;
}
SRes fd_seek(ISeekInStreamPtr pp, Int64 *pos, ESzSeek origin)
{
    FdIn *p = const_cast<FdIn *>(reinterpret_cast<const FdIn *>(pp));
    Int64 base = 0;
    if (origin == SZ_SEEK_CUR)
        base = Int64(p->pos);
    else if (origin == SZ_SEEK_END)
        base = Int64(p->size);
    const Int64 to = base + *pos;
    if (to < 0)
        return SZ_ERROR_PARAM;
    p->pos = std::uint64_t(to);
    *pos = to;
    return SZ_OK;
}

/* UTF-16 (a 7z's names) to UTF-8, '\' as '/'. */
std::string utf8(const std::vector<UInt16> &w)
{
    std::string s;
    for (std::size_t i = 0; i < w.size() && w[i]; ++i)
    {
        std::uint32_t c = w[i];
        if (c >= 0xD800 && c < 0xDC00 && i + 1 < w.size() && w[i + 1] >= 0xDC00 && w[i + 1] < 0xE000)
            c = 0x10000 + ((c - 0xD800) << 10) + (w[++i] - 0xDC00);
        if (c == '\\')
            c = '/';
        if (c < 0x80)
            s += char(c);
        else if (c < 0x800)
        {
            s += char(0xC0 | c >> 6);
            s += char(0x80 | (c & 0x3F));
        }
        else if (c < 0x10000)
        {
            s += char(0xE0 | c >> 12);
            s += char(0x80 | (c >> 6 & 0x3F));
            s += char(0x80 | (c & 0x3F));
        }
        else
        {
            s += char(0xF0 | c >> 18);
            s += char(0x80 | (c >> 12 & 0x3F));
            s += char(0x80 | (c >> 6 & 0x3F));
            s += char(0x80 | (c & 0x3F));
        }
    }
    return s;
}

/* An opened .7z: its directory, and each file as an Entry (folders end in "/", as a zip's do). */
struct Archive
{
    FdIn in;
    CLookToRead2 look;
    std::vector<Byte> look_buf;
    CSzArEx db;
    std::vector<Entry> entries;
    bool opened = false; /* the file itself */

    Archive() { SzArEx_Init(&db); }
    ~Archive()
    {
        SzArEx_Free(&db, &g_alloc);
        if (in.fd >= 0)
            ::close(in.fd);
    }
    Archive(const Archive &) = delete;
    Archive &operator=(const Archive &) = delete;

    SRes open(const std::string &path)
    {
        in.fd = ::open(path.c_str(), O_RDONLY);
        if (in.fd < 0)
            return SZ_ERROR_READ;
        opened = true;
        struct stat st;
        if (fstat(in.fd, &st) != 0)
            return SZ_ERROR_READ;
        in.size = std::uint64_t(st.st_size);
        in.vt.Read = fd_read;
        in.vt.Seek = fd_seek;
        look_buf.resize(1u << 16);
        LookToRead2_CreateVTable(&look, False);
        look.buf = look_buf.data();
        look.bufSize = look_buf.size();
        look.realStream = &in.vt;
        LookToRead2_INIT(&look)
        const SRes r = SzArEx_Open(&db, &look.vt, &g_alloc, &g_alloc);
        if (r != SZ_OK)
            return r;
        entries.resize(db.NumFiles);
        std::vector<UInt16> name;
        for (UInt32 i = 0; i < db.NumFiles; ++i)
        {
            Entry &e = entries[i];
            name.assign(SzArEx_GetFileNameUtf16(&db, i, nullptr), 0);
            SzArEx_GetFileNameUtf16(&db, i, name.data());
            e.name = utf8(name);
            if (SzArEx_IsDir(&db, i) && (e.name.empty() || e.name.back() != '/'))
                e.name += '/';
            e.usize = SzArEx_IsDir(&db, i) ? 0 : SzArEx_GetFileSize(&db, i);
            e.has_crc = SzBitWithVals_Check(&db.CRCs, i);
            e.crc = e.has_crc ? db.CRCs.Vals[i] : 0;
        }
        return SZ_OK;
    }

    bool is_dir(UInt32 i) const { return SzArEx_IsDir(&db, i); }
    /* The block a file's bytes are in, or none (a folder, an empty file). */
    bool in_block(UInt32 i) const { return db.FileToFolder[i] != UInt32(-1) && entries[i].usize > 0; }
};

/* Where a block's bytes come from and how they were packed. */
struct Block
{
    CSzFolder f;
    const Byte *props = nullptr; /* the coders' properties: props + Coders[c].PropsOffset */
    std::uint64_t unpack = 0, pack_at = 0, pack_size = 0;
};

SRes get_block(const Archive &ar, UInt32 fi, Block &b)
{
    const CSzAr &p = ar.db.db;
    CSzData sd;
    sd.Data = p.CodersData + p.FoCodersOffsets[fi];
    sd.Size = p.FoCodersOffsets[fi + 1] - p.FoCodersOffsets[fi];
    b.props = sd.Data;
    const SRes r = SzGetNextFolderItem(&b.f, &sd);
    if (r != SZ_OK)
        return r;
    b.unpack = SzAr_GetFolderUnpackSize(&p, fi);
    const UInt32 ps = p.FoStartPackStreamIndex[fi];
    b.pack_at = ar.db.dataPos + p.PackPositions[ps];
    b.pack_size = p.PackPositions[ps + 1] - p.PackPositions[ps];
    for (UInt32 c = 0; c < b.f.NumCoders; ++c)
        if (b.f.Coders[c].MethodID == kAes)
            return kPassword;
    return SZ_OK;
}

bool is_main(UInt32 m) { return m == kCopy || m == kLzma || m == kLzma2; }
bool is_filter(UInt32 m)
{
    switch (m)
    {
    case kBcj: case kPpc: case kIa64: case kArm: case kArmt: case kSparc: case kArm64: case kRiscv: case kDelta:
        return true;
    default:
        return false;
    }
}

/* Streamed: one coder (Copy, LZMA, LZMA2), or that and one filter after it (BCJ and the other
 * branch filters, Delta), the shapes 7-Zip writes by default. */
bool streamable(const CSzFolder &f)
{
    if (f.NumPackStreams != 1 || f.PackStreams[0] != 0 || f.Coders[0].NumStreams != 1 || !is_main(f.Coders[0].MethodID))
        return false;
    if (f.NumCoders == 1)
        return f.NumBonds == 0;
    return f.NumCoders == 2 && f.Coders[1].NumStreams == 1 && f.NumBonds == 1 && f.Bonds[0].InIndex == 1 &&
           f.Bonds[0].OutIndex == 0 && is_filter(f.Coders[1].MethodID);
}

using Sink = std::function<bool(const Byte *, std::size_t)>;

/* Block fi's bytes, in order, to sink (false from it stops here: kSinkStopped). The block's
 * own CRC-32 is checked when it has one and all of it went through. */
SRes decode_block(Archive &ar, UInt32 fi, const Sink &sink)
{
    Block b;
    SRes r = get_block(ar, fi, b);
    if (r != SZ_OK)
        return r;
    const CSzAr &p = ar.db.db;
    const bool has_crc = SzBitWithVals_Check(&p.FolderCRCs, fi);
    std::uint32_t crc = 0;
    auto emit = [&](const Byte *d, std::size_t n) {
        if (has_crc)
            crc = crc32(d, n, crc);
        return n == 0 || sink(d, n);
    };

    if (!streamable(b.f))
    {
        /* BCJ2, PPMd and the like: the SDK's own decoder, the whole block in memory. */
#ifdef RECOMP_7Z_TEST
        if (b.unpack > g_7z_in_memory_max)
#else
        if (b.unpack > kInMemoryMax)
#endif
            return kTooBig;
        Byte *whole = b.unpack ? static_cast<Byte *>(sz_alloc(&g_alloc, std::size_t(b.unpack))) : nullptr;
        if (b.unpack && !whole)
            return SZ_ERROR_MEM;
        struct Free
        {
            Byte *p;
            ~Free() { std::free(p); }
        } free_it{whole};
        r = SzAr_DecodeFolder(&p, fi, &ar.look.vt, ar.db.dataPos, whole, std::size_t(b.unpack), &g_alloc);
        if (r != SZ_OK)
            return r; /* its CRC-32 too */
        for (std::uint64_t at = 0; at < b.unpack;)
        {
            const std::size_t n = std::size_t(std::min<std::uint64_t>(kOutPiece, b.unpack - at));
            if (!sink(whole + at, n))
                return kSinkStopped;
            at += n;
        }
        return SZ_OK;
    }

    const CSzCoderInfo &coder = b.f.Coders[0];
    const Byte *main_props = b.props + coder.PropsOffset;
    /* LZMA's dictionary: never bigger than the block, which can't look further back than its
     * own start (a 7z made with a 1.5 GB dictionary holding 80 MB needs 80 MB here). */
    CLzmaDec lzma;
    CLzma2Dec lzma2;
    LzmaDec_CONSTRUCT(&lzma)
    Lzma2Dec_CONSTRUCT(&lzma2)
    struct Decoders
    {
        CLzmaDec *a;
        CLzma2Dec *b;
        ~Decoders()
        {
            LzmaDec_Free(a, &g_alloc);
            Lzma2Dec_Free(b, &g_alloc);
        }
    } decoders{&lzma, &lzma2};
    if (coder.MethodID == kLzma)
    {
        if (coder.PropsSize != 5)
            return SZ_ERROR_UNSUPPORTED;
        Byte props[5];
        std::memcpy(props, main_props, 5);
        const std::uint64_t dict = std::max<std::uint64_t>(std::min<std::uint64_t>(le32(props + 1), b.unpack), 1u << 12);
        props[1] = Byte(dict);
        props[2] = Byte(dict >> 8);
        props[3] = Byte(dict >> 16);
        props[4] = Byte(dict >> 24);
        if ((r = LzmaDec_Allocate(&lzma, props, 5, &g_alloc)) != SZ_OK)
            return r;
        LzmaDec_Init(&lzma);
    }
    else if (coder.MethodID == kLzma2)
    {
        if (coder.PropsSize != 1 || main_props[0] > 40)
            return SZ_ERROR_UNSUPPORTED;
        auto dict_of = [](unsigned v) -> std::uint64_t {
            return v == 40 ? 0xFFFFFFFFull : std::uint64_t(2 | (v & 1)) << (v / 2 + 11);
        };
        const std::uint64_t need = std::min<std::uint64_t>(dict_of(main_props[0]), b.unpack);
        unsigned prop = 0;
        while (prop < main_props[0] && dict_of(prop) < need)
            ++prop;
        if ((r = Lzma2Dec_Allocate(&lzma2, Byte(prop), &g_alloc)) != SZ_OK)
            return r;
        Lzma2Dec_Init(&lzma2);
    }
    else if (coder.PropsSize != 0)
        return SZ_ERROR_UNSUPPORTED;

    /* The filter after it, if any: where its program counter starts and its state. */
    UInt32 filter = UInt32(-1), pc = 0, x86 = Z7_BRANCH_CONV_ST_X86_STATE_INIT_VAL;
    unsigned delta = 0;
    Byte delta_state[DELTA_STATE_SIZE];
    Delta_Init(delta_state);
    if (b.f.NumCoders == 2)
    {
        const CSzCoderInfo &c = b.f.Coders[1];
        const Byte *cp = b.props + c.PropsOffset;
        filter = c.MethodID;
        if (filter == kDelta)
        {
            if (c.PropsSize != 1)
                return SZ_ERROR_UNSUPPORTED;
            delta = unsigned(cp[0]) + 1;
        }
        else if (filter == kArm64 || filter == kRiscv)
        {
            if (c.PropsSize == 4)
                pc = le32(cp);
            else if (c.PropsSize != 0)
                return SZ_ERROR_UNSUPPORTED;
            if (pc & (filter == kArm64 ? 3u : 1u))
                return SZ_ERROR_UNSUPPORTED;
        }
        else if (c.PropsSize != 0)
            return SZ_ERROR_UNSUPPORTED;
    }
    /* Converts what it can of data[0, n) and says how much: a branch filter leaves the last few
     * bytes of an instruction it can't see the end of for the next piece. */
    auto convert = [&](Byte *data, std::size_t n) -> std::size_t {
        Byte *end = data;
        switch (filter)
        {
        case kBcj: end = z7_BranchConvSt_X86_Dec(data, n, pc, &x86); break;
        case kPpc: end = z7_BranchConv_PPC_Dec(data, n, pc); break;
        case kIa64: end = z7_BranchConv_IA64_Dec(data, n, pc); break;
        case kArm: end = z7_BranchConv_ARM_Dec(data, n, pc); break;
        case kArmt: end = z7_BranchConv_ARMT_Dec(data, n, pc); break;
        case kSparc: end = z7_BranchConv_SPARC_Dec(data, n, pc); break;
        case kArm64: end = z7_BranchConv_ARM64_Dec(data, n, pc); break;
        case kRiscv: end = z7_BranchConv_RISCV_Dec(data, n, pc); break;
        case kDelta: Delta_Decode(delta_state, delta, data, n); return n;
        default: return n;
        }
        const std::size_t done = std::size_t(end - data);
        pc += UInt32(done);
        return done;
    };

    std::vector<Byte> in(kInPiece), out(kOutPiece + 64);
    std::size_t in_pos = 0, in_len = 0, held = 0; /* held: decoded, not yet through the filter */
    std::uint64_t pack_at = b.pack_at, pack_left = b.pack_size, out_left = b.unpack;
    while (out_left > 0)
    {
        if (in_pos == in_len && pack_left > 0)
        {
            in_len = std::size_t(std::min<std::uint64_t>(in.size(), pack_left));
            if (!read_at(ar.in.fd, pack_at, in.data(), in_len))
                return SZ_ERROR_READ;
            in_pos = 0;
            pack_at += in_len;
            pack_left -= in_len;
        }
        SizeT out_n = SizeT(std::min<std::uint64_t>(out.size() - held, out_left));
        SizeT in_n = in_len - in_pos;
        ELzmaStatus status = LZMA_STATUS_NOT_SPECIFIED;
        if (coder.MethodID == kCopy)
        {
            out_n = std::min(out_n, in_n);
            std::memcpy(out.data() + held, in.data() + in_pos, out_n);
            in_n = out_n;
        }
        else if (coder.MethodID == kLzma)
            r = LzmaDec_DecodeToBuf(&lzma, out.data() + held, &out_n, in.data() + in_pos, &in_n, LZMA_FINISH_ANY, &status);
        else
            r = Lzma2Dec_DecodeToBuf(&lzma2, out.data() + held, &out_n, in.data() + in_pos, &in_n, LZMA_FINISH_ANY, &status);
        if (r != SZ_OK)
            return SZ_ERROR_DATA;
        in_pos += in_n;
        out_left -= out_n;
        held += out_n;
        /* No progress: the packed data ran out, or its stream ended, before the block did. */
        if (out_n == 0 && in_n == 0 && ((in_pos == in_len && pack_left == 0) || status == LZMA_STATUS_FINISHED_WITH_MARK))
            return SZ_ERROR_DATA;
        std::size_t ready = convert(out.data(), held);
        if (out_left == 0)
            ready = held; /* the end: what no filter could take goes as it is, as the SDK leaves it */
        if (!emit(out.data(), ready))
            return kSinkStopped;
        std::memmove(out.data(), out.data() + ready, held - ready);
        held -= ready;
    }
    if (has_crc && crc != p.FolderCRCs.Vals[fi])
        return SZ_ERROR_CRC;
    return SZ_OK;
}

/* The player's words for an SDK error while opening or unpacking. */
std::string why_of(SRes r)
{
    switch (r)
    {
    case kPassword: return kHasPassword;
    case kTooBig:
    case SZ_ERROR_UNSUPPORTED: return kUnsupported;
    case SZ_ERROR_MEM: return "Your PS5 doesn't have the memory free to unpack this .7z.";
    case SZ_ERROR_READ: return "The download couldn't be read. Try again.";
    default: return "";
    }
}

bool same_name(const std::string &a, const std::string &b)
{
    if (a.size() != b.size())
        return false;
    for (std::size_t i = 0; i < a.size(); ++i)
        if (std::tolower(static_cast<unsigned char>(a[i])) != std::tolower(static_cast<unsigned char>(b[i])))
            return false;
    return true;
}

/* Writes a .new file as its bytes come: every byte (write() may take fewer), then synced,
 * closed and left 0777 like the zip's (so the system can load an eboot.bin or .prx). */
struct NewFile
{
    int fd = -1;
    std::string path;
    bool open(const std::string &p, std::string &why)
    {
        path = p;
        fd = ::open(p.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0777);
        if (fd < 0)
            why = "open errno " + std::to_string(errno);
        return fd >= 0;
    }
    bool write(const Byte *d, std::size_t n, std::string &why)
    {
        while (n)
        {
            const ssize_t w = ::write(fd, d, n);
            if (w < 0 && errno == EINTR)
                continue;
            if (w <= 0)
            {
                why = "write errno " + std::to_string(errno);
                return false;
            }
            d += w;
            n -= std::size_t(w);
        }
        return true;
    }
    bool close(std::string &why)
    {
        if (fd < 0)
            return true;
        fsync(fd);
        const bool ok = ::close(fd) == 0;
        fd = -1;
        chmod(path.c_str(), 0777);
        if (!ok)
            why = "close errno " + std::to_string(errno);
        return ok;
    }
    ~NewFile()
    {
        if (fd >= 0)
            ::close(fd);
    }
};
} // namespace

bool list_7z(const std::string &path, std::vector<Entry> &out)
{
    Archive ar;
    if (ar.open(path) != SZ_OK)
        return false;
    out = ar.entries;
    return true;
}

bool read_one_7z(const std::string &path, const std::string &title_id, const std::string &rel, std::string &out,
                 std::uint64_t max)
{
    out.clear();
    Archive ar;
    if (ar.open(path) != SZ_OK)
        return false;
    const std::string want = app_prefix(ar.entries, title_id) + rel;
    for (UInt32 i = 0; i < ar.db.NumFiles; ++i)
    {
        const Entry &e = ar.entries[i];
        if (ar.is_dir(i) || !same_name(e.name, want))
            continue;
        if (e.usize > max)
            return false;
        if (!ar.in_block(i))
            return e.usize == 0;
        /* Only its block, and only until it has gone by. */
        const UInt32 fi = ar.db.FileToFolder[i];
        const std::uint64_t from = ar.db.UnpackPositions[i] - ar.db.UnpackPositions[ar.db.FolderToFile[fi]];
        std::uint64_t at = 0;
        out.reserve(std::size_t(e.usize));
        const SRes r = decode_block(ar, fi, [&](const Byte *d, std::size_t n) {
            const std::uint64_t lo = std::max(at, from), hi = std::min(at + n, from + e.usize);
            if (lo < hi)
                out.append(reinterpret_cast<const char *>(d + (lo - at)), std::size_t(hi - lo));
            at += n;
            return out.size() < e.usize;
        });
        if ((r != SZ_OK && r != kSinkStopped) || out.size() != e.usize ||
            (e.has_crc && crc32(reinterpret_cast<const std::uint8_t *>(out.data()), out.size()) != e.crc))
        {
            out.clear();
            return false;
        }
        return true;
    }
    return false;
}

bool unpack_7z(const std::string &path, const std::string &root, const Progress &progress, const Log &log,
               const Stop &stop, std::string &error, bool &changed, std::size_t &files,
               const std::vector<std::string> *keep, const std::string &title_id)
{
    changed = false;
    files = 0;
    Archive ar;
    const SRes opened = ar.open(path);
    if (!ar.opened)
    {
        error = "The download couldn't be opened.";
        return false;
    }
    if (opened != SZ_OK || ar.entries.empty())
    {
        if (log)
            log("7z: the download's directory couldn't be read (" + std::to_string(opened) + ")");
        error = opened == SZ_ERROR_UNSUPPORTED ? std::string(kUnsupported) : "The download isn't a .7z the store can read.";
        return false;
    }
    /* The game's own folder in it, as for a zip (app_prefix), and nothing outside it. */
    const std::string strip = app_prefix(ar.entries, title_id);
    auto inside = [&](const Entry &e) { return e.name.compare(0, strip.size(), strip) == 0; };
    make_dirs(root);

    /* First every name is checked and every folder made; then what's written is known before
     * a byte is decoded. */
    const UInt32 n_files = ar.db.NumFiles;
    std::vector<char> wanted(n_files, 0);
    std::vector<std::string> names(n_files);
    std::uint64_t done = 0, total = 0;
    for (UInt32 i = 0; i < n_files; ++i)
    {
        const Entry &e = ar.entries[i];
        if (!inside(e))
            continue; /* beside the game's folder: notes, a PC build, another game */
        const std::string name = e.name.substr(strip.size());
        if (name.empty())
            continue;
        if (!safe_name(name))
        {
            error = "The .7z has a file with an unsafe name, so it wasn't installed.";
            return false;
        }
        const std::string full = root + "/" + name;
        if (name.back() == '/')
        {
            make_dirs(full.substr(0, full.size() - 1));
            continue;
        }
        total += e.usize;
        make_dirs(full.substr(0, full.find_last_of('/')));
        if (keep && kept(name, *keep))
        {
            struct stat st;
            if (stat(full.c_str(), &st) == 0)
            {
                /* The player's own (settings, saves, game files): the release's copy waits
                 * for a player who has none. */
                if (log)
                    log("kept " + name + " (the player's own)");
                done += e.usize;
                continue;
            }
        }
        wanted[i] = 1;
        names[i] = name;
    }

    std::vector<Written> written;
    auto drop_new = [&] {
        for (const Written &w : written)
            std::remove((w.path + ".new").c_str());
    };
    /* A written .new, checked as the zip's are: read back against its size and CRC-32. */
    auto checked = [&](UInt32 i, std::uint32_t got) -> bool {
        const Entry &e = ar.entries[i];
        const std::string full = root + "/" + names[i];
        std::string why;
        if (e.has_crc && got != e.crc)
        {
            if (log)
                log("unpack " + names[i] + " failed its CRC-32 check");
            error = "Unpacking " + names[i] + " failed its check. Nothing was changed.";
            return false;
        }
        if (!matches(full + ".new", e.usize, e.has_crc, e.crc, &why))
        {
            if (log)
                log("write " + names[i] + ".new: " + why);
            error = "Writing " + names[i] + " failed. Nothing was changed.";
            return false;
        }
        written.push_back({full, e.usize, e.crc, e.has_crc});
        return true;
    };

    /* Empty files hold no bytes in any block: written straight away. */
    for (UInt32 i = 0; i < n_files; ++i)
    {
        if (!wanted[i] || ar.in_block(i))
            continue;
        NewFile f;
        std::string why;
        if (!f.open(root + "/" + names[i] + ".new", why) || !f.close(why))
        {
            if (log)
                log("write " + names[i] + ".new: " + why);
            std::remove((root + "/" + names[i] + ".new").c_str());
            drop_new();
            error = "Writing " + names[i] + " failed. Nothing was changed.";
            return false;
        }
        if (!checked(i, 0))
        {
            std::remove((root + "/" + names[i] + ".new").c_str());
            drop_new();
            return false;
        }
    }

    /* Then each block that holds a file to write, start to end, its files in order. A block
     * with none of them isn't decoded at all. */
    const UInt32 n_blocks = ar.db.db.NumFolders;
    std::uint64_t shown = 0;
    for (UInt32 fi = 0; fi < n_blocks; ++fi)
    {
        std::vector<UInt32> in_it;
        bool any = false;
        for (UInt32 i = ar.db.FolderToFile[fi]; i < ar.db.FolderToFile[fi + 1] && i < n_files; ++i)
            if (ar.db.FileToFolder[i] == fi && ar.in_block(i))
            {
                in_it.push_back(i);
                any = any || wanted[i];
            }
        if (!any)
            continue;
        /* Once the block's last file to write is done, the rest of it (a PC build packed after
         * the game, say) isn't decoded. */
        std::size_t last = 0;
        for (std::size_t j = 0; j < in_it.size(); ++j)
            if (wanted[in_it[j]])
                last = j;
        std::size_t k = 0;      /* the file the next byte belongs to */
        std::uint64_t pos = 0;  /* how far into it */
        std::uint32_t crc = 0;
        NewFile f;
        bool stopped = false, finished = false;
        auto sink = [&](const Byte *d, std::size_t n) -> bool {
            while (n > 0 && k < in_it.size())
            {
                const UInt32 i = in_it[k];
                const Entry &e = ar.entries[i];
                if (pos == 0 && wanted[i])
                {
                    if (stop && stop())
                    {
                        stopped = true;
                        return false;
                    }
                    if (progress)
                        progress("Installing", done, total);
                    std::string why;
                    crc = 0;
                    if (!f.open(root + "/" + names[i] + ".new", why))
                    {
                        if (log)
                            log("write " + names[i] + ".new: " + why);
                        error = "Writing " + names[i] + " failed. Nothing was changed.";
                        return false;
                    }
                }
                const std::size_t take = std::size_t(std::min<std::uint64_t>(n, e.usize - pos));
                if (wanted[i])
                {
                    std::string why;
                    if (!f.write(d, take, why))
                    {
                        if (log)
                            log("write " + names[i] + ".new: " + why);
                        error = "Writing " + names[i] + " failed. Nothing was changed.";
                        return false;
                    }
                    crc = crc32(d, take, crc);
                    done += take;
                    if (done - shown >= (8u << 20))
                    {
                        shown = done;
                        if (stop && stop())
                        {
                            stopped = true;
                            return false;
                        }
                        if (progress)
                            progress("Installing", done, total);
                    }
                }
                d += take;
                n -= take;
                pos += take;
                if (pos == e.usize)
                {
                    if (wanted[i])
                    {
                        std::string why;
                        if (!f.close(why))
                        {
                            if (log)
                                log("write " + names[i] + ".new: " + why);
                            error = "Writing " + names[i] + " failed. Nothing was changed.";
                            return false;
                        }
                        if (!checked(i, crc))
                            return false;
                    }
                    ++k;
                    pos = 0;
                    if (k > last)
                    {
                        finished = true;
                        return false;
                    }
                }
            }
            return true;
        };
        const SRes r = decode_block(ar, fi, sink);
        if (finished && (r == SZ_OK || r == kSinkStopped))
            continue;
        if (r != SZ_OK || k != in_it.size())
        {
            std::string why_close;
            f.close(why_close);
            if (k < in_it.size() && wanted[in_it[k]])
                std::remove((root + "/" + names[in_it[k]] + ".new").c_str());
            drop_new();
            if (stopped)
                error = "Stopped. Nothing was changed.";
            else if (r != kSinkStopped)
            {
                if (log)
                    log("7z: block " + std::to_string(fi) + " couldn't be unpacked (" + std::to_string(r) + ")");
                const std::string why = why_of(r);
                const std::string at = k < in_it.size() ? names[in_it[k]] : std::string();
                error = !why.empty() ? why
                        : at.empty() ? "Unpacking the .7z failed its check. Nothing was changed."
                                     : "Unpacking " + at + " failed its check. Nothing was changed.";
            }
            return false;
        }
    }
    return commit(root, written, progress, log, error, changed, files);
}
} // namespace recomp::zip
