#!/bin/sh
# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# Host guard: before the host runs a file the claude container could have written, show what
# changed since the last approval and ask.
#
# This file is only the TEMPLATE. The working copy lives at .git/hooks/host-guard, which the
# claude container mounts read-only — like the approval hash .git/hooks/host-files-ok. A guard
# inside the writable repo could be edited by the very session it is meant to check, and so
# could the Makefile (an altered `make ship` would simply not call it). Install and update by
# hand, after reading the diff: docs/internal/CLAUDE-CONTAINER.md.
#
# Called by .git/hooks/pre-push (every push) and by the `make` shell function (every make on
# the host). No change → silent. Otherwise: diffstat since the approved commit plus uncommitted,
# untracked and ignored files under the same paths, then [j/d/N]:
#   j  approve — writes HEAD to host-files-ok; uncommitted changes cannot be approved and are
#      shown again next time
#   d  full diff, then ask again
#   N  abort (default; also without a terminal)
set -eu

# Everything the host executes or Claude Code on the host loads: build and release tooling,
# hooks, container definitions, and the project's own Claude settings, hooks and MCP servers.
# Only tracked files belong here — an ignored file never reaches a commit, so it could never be
# approved and would be asked about on every run. Such files are mounted read-only into the
# claude container instead (.claude/settings.local.json, see compose.override.yaml).
set -- Makefile .githooks/ tools/ deploy/ Dockerfile ':(glob)compose*.yaml' frankenphp/ \
    .claude/settings.json .claude/hooks/ .mcp.json

git_dir=$(git rev-parse --absolute-git-dir)
ok_file="$git_dir/hooks/host-files-ok"
cd "$(git rev-parse --show-toplevel)"

head=$(git rev-parse --verify --quiet HEAD || true)
approved=$(cat "$ok_file" 2>/dev/null || true)
if [ -n "$approved" ] && ! git cat-file -e "${approved}^{commit}" 2>/dev/null; then
    approved=
fi
# No approval yet: compare against the empty tree, i.e. show every host file once.
base=${approved:-$(git hash-object -t tree /dev/null)}

committed=
if [ -n "$head" ] && [ "$base" != "$head" ]; then
    committed=$(git diff --stat "$base" "$head" -- "$@")
fi
uncommitted=$(git status --porcelain --ignored=matching --untracked-files=all -- "$@")

if [ -z "$committed" ] && [ -z "$uncommitted" ]; then
    exit 0
fi

echo "┌─ host-guard: host files changed since ${approved:-the start (no approval yet)}"
if [ -n "$committed" ]; then
    echo "$committed" | sed 's/^/│ /'
fi
if [ -n "$uncommitted" ]; then
    echo "│ not committed (M modified, ?? untracked, !! ignored):"
    echo "$uncommitted" | sed 's/^/│   /'
fi
echo "└─"

if ! [ -r /dev/tty ] || ! (: </dev/tty) 2>/dev/null; then
    echo "host-guard: no terminal to ask — aborting" >&2
    exit 1
fi

while :; do
    printf 'host-guard: approve and continue? [j/d/N] ' >/dev/tty
    read -r answer </dev/tty || answer=
    case "$answer" in
    j | J)
        if [ -n "$head" ]; then
            echo "$head" >"$ok_file"
        fi
        if [ -n "$uncommitted" ]; then
            echo "host-guard: approved up to $head — the uncommitted files above stay unapproved"
        fi
        exit 0
        ;;
    d | D)
        if [ -n "$committed" ]; then
            git --no-pager diff "$base" "$head" -- "$@" >/dev/tty
        fi
        git --no-pager diff -- "$@" >/dev/tty
        git status --porcelain --ignored=matching --untracked-files=all -- "$@" |
            sed -n 's/^[?!][?!] //p' | while IFS= read -r file; do
            git --no-pager diff --no-index -- /dev/null "$file" >/dev/tty || true
        done
        ;;
    *)
        echo "host-guard: aborted" >&2
        exit 1
        ;;
    esac
done
