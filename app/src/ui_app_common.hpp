/* Recomp Store - the version, read by tools/build.sh for param.json's contentVersion.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

namespace recomp
{
constexpr const char *kVersion = "2.3.0";
constexpr int kVersionMajor = 2, kVersionMinor = 3, kVersionPatch = 0;
constexpr int kBuild = 38; /* rises with every build: shown in Settings > About and at the top of the log */
/* (2.0) What the store calls itself while 2.0 is built in stages: "" once it's out. The
 * previews number 1.9.x, so 2.0.0 is newer than every one of them and they update to it. */
constexpr const char *kVersionLabel = "";
} // namespace recomp
