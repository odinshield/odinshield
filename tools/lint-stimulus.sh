#!/usr/bin/env sh

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
# SPDX-License-Identifier: AGPL-3.0-or-later

# Verify every Stimulus controller referenced in Twig templates has a matching
# assets/controllers/<name>_controller.js file. A data-controller / data-action
# name with no controller file is a SILENT no-op (Stimulus just does nothing) —
# Biome / make asset-lint cannot catch it. This is the one useful check from the
# UX 3.2 toolkit-kit-linter, minus the kit tooling (issue #16).
#
# Reference forms handled:
#   data-controller="a b c"          (HTML attribute, space-separated)
#   'data-controller': 'a b'         (Twig form_widget attr hash)
#   data-action="evt->ctrl#m x#m"    (HTML attribute; controller = token, minus evt-> and #method)
#   'data-action': 'ctrl#m'          (Twig hash)
#   stimulus_controller('name')      (Twig function)
#
# Third-party controllers are skipped: `live` (ux-live-component, injected at
# render time) and any namespaced identifier containing `--` (e.g. UX controllers).

set -eu

templates_dir="templates"
controllers_dir="assets/controllers"

refs=$(
    {
        # data-controller (HTML attr + Twig hash) — values may list several controllers
        grep -rhoE "data-controller=\"[^\"]*\"|'data-controller':[[:space:]]*'[^']*'" "$templates_dir" 2>/dev/null |
            sed -E "s/data-controller=\"//; s/'data-controller':[[:space:]]*'//; s/['\"]//g" |
            tr ' ' '\n'
        # data-action (HTML attr + Twig hash) — strip optional `evt->` prefix and `#method` suffix
        grep -rhoE "data-action=\"[^\"]*\"|'data-action':[[:space:]]*'[^']*'" "$templates_dir" 2>/dev/null |
            sed -E "s/data-action=\"//; s/'data-action':[[:space:]]*'//; s/['\"]//g" |
            tr ' ' '\n' |
            sed -E 's/.*->//; s/#.*//'
        # stimulus_controller('name') Twig function
        grep -rhoE "stimulus_controller\('[^']*'" "$templates_dir" 2>/dev/null |
            sed -E "s/stimulus_controller\('//"
    } | sed -E "s/['\"]//g" | sort -u | grep -v '^$' || true
)

missing=0
for ref in $refs; do
    case "$ref" in
    live | *--*) continue ;;
    esac
    file="$controllers_dir/$(echo "$ref" | tr '-' '_')_controller.js"
    if [ ! -f "$file" ]; then
        echo "  MISSING: \"$ref\" referenced in templates but $file does not exist"
        missing=1
    fi
done

if [ "$missing" -ne 0 ]; then
    echo "[stimulus-lint] Stimulus controllers referenced without a JS file (see above)."
    exit 1
fi

echo " [OK] Every referenced Stimulus controller has a matching JS file."
