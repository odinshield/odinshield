#!/bin/sh
# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# Secret scan of the tracked files only (#264), as they are in the working tree.
#
# gitleaks must never see the plain working directory: it would scan environment/.env.local,
# vendor/ and var/ — files that never reach the repository, whose secrets would then appear in the
# gate output. So the tracked files are copied into a throwaway directory first. --redact keeps
# matched values out of the report.
#
# Usage: tools/secrets-lint.sh [<gitleaks image>]
# With an image (host) gitleaks runs as a throwaway container; without one (claude container) the
# local binary scans the same copy.

set -eu

image=${1:-}
scan=$(mktemp -d)
trap 'rm -rf "$scan"' EXIT

# --ignore-failed-read: a tracked file deleted in the working tree is simply absent from the scan.
git ls-files -z | tar --null --ignore-failed-read -T - -cf - 2>/dev/null | tar -xf - -C "$scan"

if [ -n "$image" ]; then
    docker run --rm -v "$scan:/scan:ro" "$image" dir /scan --redact --no-banner
else
    gitleaks dir "$scan" --redact --no-banner
fi
