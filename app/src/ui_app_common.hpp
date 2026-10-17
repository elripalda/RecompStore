/* Recomp Store - the version, read by tools/build.sh for param.json's contentVersion.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

namespace recomp
{
constexpr const char *kVersion = "1.1.0";
constexpr int kVersionMajor = 1, kVersionMinor = 1, kVersionPatch = 0;
constexpr int kBuild = 25; /* rises with every build: shown in Settings > About and at the top of the log */
} // namespace recomp
