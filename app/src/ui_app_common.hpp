/* Recomp Store - the version, read by tools/build.sh for param.json's contentVersion.
 * SPDX-License-Identifier: GPL-3.0-or-later */
#pragma once

namespace recomp
{
constexpr const char *kVersion = "1.9.1";
constexpr int kVersionMajor = 1, kVersionMinor = 9, kVersionPatch = 1;
constexpr int kBuild = 30; /* rises with every build: shown in Settings > About and at the top of the log */
/* (2.0) What the store calls itself while 2.0 is built in stages: "" once it's out. The
 * previews number 1.9.x, so 2.0.0 is newer than every one of them and they update to it. */
constexpr const char *kVersionLabel = "2.0 Preview 1";
} // namespace recomp
