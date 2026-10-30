#!/bin/sh

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# Claude Code hook (Notification, Stop) in the claude container: rings the terminal bell, so a
# background tab in Windows Terminal shows the bell icon. Hooks run without a controlling
# terminal (/dev/tty fails), so this walks up the process tree via /proc (the image has no ps) to
# the first process holding a /dev/pts/N — Claude Code itself — and writes BEL there. Silent on
# any failure, always exit 0: a hook must never get in the way of a session.

pid=$PPID
while [ "${pid:-0}" -gt 0 ] 2>/dev/null; do
    for fd in 0 1 2; do
        target=$(readlink "/proc/$pid/fd/$fd" 2>/dev/null) || continue
        case $target in
        /dev/pts/[0-9]*)
            { printf '\a' >"$target"; } 2>/dev/null
            exit 0
            ;;
        esac
    done
    pid=$(awk '/^PPid:/ { print $2 }' "/proc/$pid/status" 2>/dev/null)
done
exit 0
