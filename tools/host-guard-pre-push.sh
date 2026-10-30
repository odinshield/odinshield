#!/bin/sh
# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# TEMPLATE for .git/hooks/pre-push (core.hooksPath = .git/hooks, read-only in the claude
# container). Runs the host guard first, then the versioned gate in .githooks/pre-push — which
# the container can write, so it only runs once the guard has shown and approved its changes.
# Git's stdin (the refs being pushed) passes through to the versioned hook; the guard asks on
# /dev/tty and leaves stdin alone.
set -eu

"$(dirname "$0")/host-guard"
exec "$(git rev-parse --show-toplevel)/.githooks/pre-push" "$@"
