/* Recomp Store - a small JSON reader.
 * SPDX-License-Identifier: GPL-3.0-or-later */

#include "store_json.hpp"

#include <cstdlib>
#include <cstring>

namespace recomp
{
namespace
{
const Json &null_value()
{
    static const Json n;
    return n;
}
} // namespace

struct JsonParser
{
    const char *p, *end;
    int depth = 0;

    void ws()
    {
        while (p < end && (*p == ' ' || *p == '\t' || *p == '\n' || *p == '\r'))
            ++p;
    }

    static void utf8(std::string &out, unsigned cp)
    {
        if (cp < 0x80)
            out += char(cp);
        else if (cp < 0x800)
        {
            out += char(0xC0 | (cp >> 6));
            out += char(0x80 | (cp & 0x3F));
        }
        else if (cp < 0x10000)
        {
            out += char(0xE0 | (cp >> 12));
            out += char(0x80 | ((cp >> 6) & 0x3F));
            out += char(0x80 | (cp & 0x3F));
        }
        else
        {
            out += char(0xF0 | (cp >> 18));
            out += char(0x80 | ((cp >> 12) & 0x3F));
            out += char(0x80 | ((cp >> 6) & 0x3F));
            out += char(0x80 | (cp & 0x3F));
        }
    }

    bool hex4(unsigned &v)
    {
        if (end - p < 4)
            return false;
        v = 0;
        for (int i = 0; i < 4; ++i)
        {
            const char c = *p++;
            v <<= 4;
            if (c >= '0' && c <= '9')
                v |= unsigned(c - '0');
            else if (c >= 'a' && c <= 'f')
                v |= unsigned(c - 'a' + 10);
            else if (c >= 'A' && c <= 'F')
                v |= unsigned(c - 'A' + 10);
            else
                return false;
        }
        return true;
    }

    bool string(std::string &out)
    {
        if (p >= end || *p != '"')
            return false;
        ++p;
        while (p < end && *p != '"')
        {
            const char c = *p++;
            if (c != '\\')
            {
                out += c;
                continue;
            }
            if (p >= end)
                return false;
            const char e = *p++;
            switch (e)
            {
            case '"': out += '"'; break;
            case '\\': out += '\\'; break;
            case '/': out += '/'; break;
            case 'b': out += '\b'; break;
            case 'f': out += '\f'; break;
            case 'n': out += '\n'; break;
            case 'r': out += '\r'; break;
            case 't': out += '\t'; break;
            case 'u':
            {
                unsigned cp = 0;
                if (!hex4(cp))
                    return false;
                if (cp >= 0xD800 && cp < 0xDC00 && end - p >= 6 && p[0] == '\\' && p[1] == 'u')
                {
                    p += 2;
                    unsigned lo = 0;
                    if (!hex4(lo))
                        return false;
                    cp = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00);
                }
                utf8(out, cp);
                break;
            }
            default: return false;
            }
        }
        if (p >= end)
            return false;
        ++p;
        return true;
    }

    bool value(Json &v)
    {
        if (++depth > 64)
            return false;
        ws();
        if (p >= end)
            return false;
        bool ok = false;
        switch (*p)
        {
        case '{':
        {
            ++p;
            v.type_ = Json::Type::Object;
            ws();
            if (p < end && *p == '}')
            {
                ++p;
                ok = true;
                break;
            }
            for (;;)
            {
                ws();
                std::string key;
                if (!string(key))
                    return false;
                ws();
                if (p >= end || *p != ':')
                    return false;
                ++p;
                v.members_.emplace_back(std::move(key), Json());
                if (!value(v.members_.back().second))
                    return false;
                ws();
                if (p < end && *p == ',')
                {
                    ++p;
                    continue;
                }
                if (p < end && *p == '}')
                {
                    ++p;
                    ok = true;
                }
                break;
            }
            break;
        }
        case '[':
        {
            ++p;
            v.type_ = Json::Type::Array;
            ws();
            if (p < end && *p == ']')
            {
                ++p;
                ok = true;
                break;
            }
            for (;;)
            {
                v.items_.emplace_back();
                if (!value(v.items_.back()))
                    return false;
                ws();
                if (p < end && *p == ',')
                {
                    ++p;
                    continue;
                }
                if (p < end && *p == ']')
                {
                    ++p;
                    ok = true;
                }
                break;
            }
            break;
        }
        case '"':
            v.type_ = Json::Type::String;
            ok = string(v.text_);
            break;
        case 't':
            ok = end - p >= 4 && std::memcmp(p, "true", 4) == 0;
            if (ok)
            {
                p += 4;
                v.type_ = Json::Type::Bool;
                v.flag_ = true;
            }
            break;
        case 'f':
            ok = end - p >= 5 && std::memcmp(p, "false", 5) == 0;
            if (ok)
            {
                p += 5;
                v.type_ = Json::Type::Bool;
            }
            break;
        case 'n':
            ok = end - p >= 4 && std::memcmp(p, "null", 4) == 0;
            if (ok)
                p += 4;
            break;
        default:
        {
            char buf[64];
            std::size_t n = 0;
            while (p < end && n < sizeof buf - 1 &&
                   ((*p >= '0' && *p <= '9') || *p == '-' || *p == '+' || *p == '.' || *p == 'e' || *p == 'E'))
                buf[n++] = *p++;
            buf[n] = 0;
            if (n == 0)
                return false;
            char *stop = nullptr;
            v.number_ = std::strtod(buf, &stop);
            v.type_ = Json::Type::Number;
            ok = stop == buf + n;
        }
        }
        --depth;
        return ok;
    }
};

bool Json::parse(const std::string &text, Json &out)
{
    out = Json();
    JsonParser parser{text.data(), text.data() + text.size()};
    if (!parser.value(out))
    {
        out = Json();
        return false;
    }
    parser.ws();
    if (parser.p != parser.end)
    {
        out = Json();
        return false;
    }
    return true;
}

const Json &Json::operator[](const char *key) const
{
    if (type_ == Type::Object)
        for (const auto &m : members_)
            if (m.first == key)
                return m.second;
    return null_value();
}

const Json &Json::operator[](std::size_t index) const
{
    return type_ == Type::Array && index < items_.size() ? items_[index] : null_value();
}
} // namespace recomp
