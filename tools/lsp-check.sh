#!/bin/sh

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# #100: Symfony-aware diagnostics (routes, templates, services, translations, Messenger, Stimulus …)
# via `symfony lsp:check`. Runs in the claude container (Symfony CLI and PHP from its image) or on the
# host (host CLI, application in the php container).
#
# A missing or too old CLI skips the check on the host, but fails it in the claude container: there
# `make ci` stamps the commit, and a skipped check must never pass as checked.
#
# Exit codes of lsp:check: 0 clean, 10 blocking findings, 11 invalid invocation, 12 the checker
# itself failed. A failed checker still prints "0 diagnostics" — so 12 must never pass as clean.
#
# Known blind spot (measured 2026-09-16, Language Tools 0.20.3): every directory named `vendor` is
# treated as a dependency directory, so templates/vendor/ (the vendor = manufacturer module) is
# silently not analysed. Translation diagnostics are on: they catch literal keys missing in Twig/PHP,
# which `make trans-lint` (YAML syntax only) cannot.
set -u

BASELINE=tools/symfony-lsp-baseline.json
MIN_VERSION=5.20.0

skip() {
    if [ -n "${IN_CONTAINER:-}" ]; then
        echo "lsp-check FAILED: $1 — the claude image must carry it (rebuild: docs/internal/CLAUDE-CONTAINER.md)."
        exit 1
    fi
    echo "lsp-check skipped: $1."
    exit 0
}

if ! command -v symfony >/dev/null 2>&1; then
    skip "Symfony CLI not installed (needs >= $MIN_VERSION)"
fi

version=$(symfony version --no-ansi 2>/dev/null | sed -n 's/.*version \([0-9][0-9.]*\).*/\1/p' | head -n 1)
if [ -z "$version" ] || [ "$(printf '%s\n%s\n' "$MIN_VERSION" "$version" | sort -V | head -n 1)" != "$MIN_VERSION" ]; then
    skip "Symfony CLI ${version:-unknown} is older than $MIN_VERSION"
fi

# On the host the bridge is written here and executed in the php container through the nested bind
# mount in compose.override.yaml (the anonymous /app/var volume hides the rest of var/). In the
# claude container (IN_CONTAINER) PHP is local and the project already lives at /app.
mkdir -p var/symfony-lsp
if [ -n "${IN_CONTAINER:-}" ]; then
    php_command='["php"]'
    # The image inherits XDG_CONFIG_HOME=/config from frankenphp (root-owned), where the CLI cannot
    # create its Language Tools cache as the claude user.
    mkdir -p var/symfony-cli
    XDG_CONFIG_HOME="$PWD/var/symfony-cli"
    export XDG_CONFIG_HOME
else
    php_command='["docker","compose","exec","-T","php","php"]'
fi

symfony lsp:check \
    --php-command="$php_command" \
    --container-project-root=/app \
    --baseline="$BASELINE" \
    --strict-baseline \
    --translation-diagnostics \
    "$@"
status=$?

if [ "$status" -eq 12 ]; then
    echo "lsp-check: the checker itself failed (exit 12) — this is NOT a clean result."
    if [ -z "${IN_CONTAINER:-}" ]; then
        echo "Most likely the php container cannot see var/symfony-lsp/: recreate it with 'docker compose up -d php'."
    fi
fi

exit "$status"
