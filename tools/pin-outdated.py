#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
#
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Pinned tool versions vs. their latest release — report only, never bumps anything.

Called by `make outdated` with name=version pairs straight from the Makefile pins. Reads the
public GitHub, npm, PyPI and nodejs.org APIs without a token; an unreachable source prints `?`
and the run still exits 0. Node is compared within the pinned major line (a major bump is a
decision, not an update) and the newest LTS line is shown next to it.

Release dates help with the rule to adopt only versions at least two weeks old.
"""
from __future__ import annotations

import json
import sys
import urllib.request

# name -> (kind, source)
SOURCES = {
    "shellcheck": ("github", "koalaman/shellcheck"),
    "hadolint": ("github", "hadolint/hadolint"),
    "gitleaks": ("github", "gitleaks/gitleaks"),
    "symfony": ("github", "symfony-cli/symfony-cli"),
    "shfmt": ("github", "mvdan/sh"),
    "reuse": ("pypi", "reuse"),
    "biome": ("npm", "@biomejs/biome"),
    "markdownlint": ("npm", "markdownlint-cli2"),
    "claude-code": ("npm", "@anthropic-ai/claude-code"),
    "dev-browser": ("npm", "dev-browser"),
    "node": ("node", ""),
}


def main() -> int:
    pins = dict(arg.split("=", 1) for arg in sys.argv[1:] if "=" in arg)
    unknown = sorted(set(pins) - set(SOURCES))
    if unknown or not pins:
        print(f"usage: pin-outdated.py name=version … (known: {', '.join(SOURCES)})", file=sys.stderr)
        return 2

    print(f"{'tool':<14}{'pinned':<12}{'latest':<14}{'released':<12}status")
    for name, pinned in pins.items():
        try:
            latest, released, extra = _latest(name, pinned)
            status = ("ok" if latest == pinned else "update available") + (f"; {extra}" if extra else "")
        except Exception as e:  # report-only: one failing source must not hide the others
            latest, released, status = "?", "", f"lookup failed: {e}"
        print(f"{name:<14}{pinned:<12}{latest:<14}{released:<12}{status}")
    return 0


def _latest(name: str, pinned: str) -> tuple[str, str, str]:
    kind, source = SOURCES[name]
    if kind == "github":
        data = _get(f"https://api.github.com/repos/{source}/releases/latest")
        return data["tag_name"].lstrip("v"), data["published_at"][:10], ""
    if kind == "npm":
        return _get(f"https://registry.npmjs.org/-/package/{source}/dist-tags")["latest"], "", ""
    if kind == "pypi":
        data = _get(f"https://pypi.org/pypi/{source}/json")
        version = data["info"]["version"]
        files = data["releases"].get(version) or [{}]
        return version, files[0].get("upload_time", "")[:10], ""
    # node: newest release of the pinned major, plus the newest LTS line for orientation
    releases = _get("https://nodejs.org/dist/index.json")
    major = pinned.split(".")[0]
    same = next(r for r in releases if r["version"].lstrip("v").split(".")[0] == major)
    lts = next(r for r in releases if r["lts"])
    extra = "" if lts["version"].lstrip("v").split(".")[0] == major else f"newest LTS line: {lts['version']}"
    return same["version"].lstrip("v"), same["date"], extra


def _get(url: str) -> dict | list:
    req = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "odinshield-make-outdated"})
    with urllib.request.urlopen(req, timeout=15) as r:
        return json.load(r)


if __name__ == "__main__":
    sys.exit(main())
