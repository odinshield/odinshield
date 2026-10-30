---
name: dev-browser
description: Browser automation with persistent page state. Use when users ask to navigate websites, fill forms, take screenshots, extract web data, test web apps, or automate browser workflows. Trigger phrases include "go to [url]", "click on", "fill out the form", "take a screenshot", "scrape", "automate", "test the website", "log into", or any browser interaction request.
---

# Dev Browser

A CLI for controlling browsers with sandboxed JavaScript scripts.

## Installation

Preinstalled in the claude container (`frankenphp_claude`, pinned `DEV_BROWSER_VERSION`,
Chromium included) — nothing to install.

## Usage

- Always headless — the container has no display: `dev-browser --headless run var/log/<script>.js`.
- The dev app is `http://php/` (service name, port 80). Not `https://localhost` — that is the
  container itself — and not `https://php`, which fails the TLS handshake.
- Screenshots: `await saveScreenshot(await page.screenshot(), "name.png")` writes to
  `~/.dev-browser/tmp/`; read the file from there.

Run `dev-browser --help` to learn more.
