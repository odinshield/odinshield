#!/bin/sh
# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# The production image carries no development or Claude tooling.
#
# Usage: tools/prod-check.sh <image>   (lists come from the Makefile: PROD_FORBIDDEN_BINS,
# PROD_FORBIDDEN_PATHS)
#
# frankenphp_prod starts from debian-slim and copies only named paths from the builder, so this
# holds by construction today. The check guards the construction: a tool installed into
# frankenphp_base, a builder that derives from _dev/_claude, or a new repo file that slips past
# .dockerignore and the COPY --exclude list would all show up here before the image is pushed.
set -eu

image=${1:?usage: tools/prod-check.sh <image>}
bins=${PROD_FORBIDDEN_BINS:?PROD_FORBIDDEN_BINS not set (run via make prod-check)}
paths=${PROD_FORBIDDEN_PATHS:?PROD_FORBIDDEN_PATHS not set (run via make prod-check)}
failed=0

# The lists are space-separated on purpose: word splitting turns them into the arguments.
# shellcheck disable=SC2086
found=$(docker run --rm --entrypoint sh "$image" -c '
    for b in "$@"; do command -v "$b" >/dev/null 2>&1 && echo "binary: $b"; done
    true' sh $bins)
# shellcheck disable=SC2086
found_paths=$(docker run --rm --entrypoint sh "$image" -c '
    for p in "$@"; do [ -e "$p" ] && echo "path: $p"; done
    true' sh $paths)

for hit in "$found" "$found_paths"; do
    if [ -n "$hit" ]; then
        echo "$hit" | sed 's/^/✗ prod-check: /' >&2
        failed=1
    fi
done

# The SBOM describes what the image ships; none of the require-dev packages may appear in it.
sbom=$(docker run --rm --entrypoint cat "$image" /app/sbom.json 2>/dev/null || true)
if [ -z "$sbom" ]; then
    echo "✗ prod-check: no /app/sbom.json in $image — cannot compare against require-dev" >&2
    failed=1
    dev_in_sbom=
else
    dev_in_sbom=$(printf '%s' "$sbom" | python3 -I -c '
import json, sys
dev = set(json.load(open(sys.argv[1])).get("require-dev", {}))
sbom = json.load(sys.stdin)
names = {(c.get("group") + "/" if c.get("group") else "") + c.get("name", "") for c in sbom.get("components", [])}
print("\n".join(sorted(dev & names)))
' composer.json)
fi
if [ -n "$dev_in_sbom" ]; then
    echo "$dev_in_sbom" | sed 's/^/✗ prod-check: dev package in SBOM: /' >&2
    failed=1
fi

if [ "$failed" = 1 ]; then
    echo "✗ prod-check: $image carries development tooling — not pushing" >&2
    exit 1
fi
echo "✓ prod-check: $image carries no development tooling"
