/* Recomp Store - a small JSON reader for the catalog and GitHub's answers.
 * No exceptions (the title builds without them): parse() says whether it worked.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

#include <string>
#include <utility>
#include <vector>

namespace recomp
{
class Json
{
public:
    enum class Type
    {
        Null,
        Bool,
        Number,
        String,
        Array,
        Object
    };

    /* The whole text must be one JSON value; false (and a Null value) if not. */
    static bool parse(const std::string &text, Json &out);

    Type type() const { return type_; }
    bool is_object() const { return type_ == Type::Object; }
    bool is_array() const { return type_ == Type::Array; }
    bool is_null() const { return type_ == Type::Null; }

    /* A missing key or index gives a shared Null value, so lookups can be chained. */
    const Json &operator[](const char *key) const;
    const Json &operator[](std::size_t index) const;
    std::size_t size() const { return type_ == Type::Array ? items_.size() : type_ == Type::Object ? members_.size() : 0; }
    const std::vector<std::pair<std::string, Json>> &members() const { return members_; }

    std::string str(const std::string &fallback = "") const { return type_ == Type::String ? text_ : fallback; }
    double num(double fallback = 0) const { return type_ == Type::Number ? number_ : fallback; }
    long long integer(long long fallback = 0) const
    {
        return type_ == Type::Number ? static_cast<long long>(number_) : fallback;
    }
    bool boolean(bool fallback = false) const { return type_ == Type::Bool ? flag_ : fallback; }

private:
    friend struct JsonParser;
    Type type_ = Type::Null;
    bool flag_ = false;
    double number_ = 0;
    std::string text_;
    std::vector<Json> items_;
    std::vector<std::pair<std::string, Json>> members_;
};
} // namespace recomp
