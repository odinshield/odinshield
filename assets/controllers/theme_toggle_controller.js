// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        serverTheme: { type: String, default: '' },
    };

    connect() {
        const cookieTheme = this.cookieTheme();
        if (cookieTheme) {
            this.applyTheme(cookieTheme);
            return;
        }
        if (this.serverThemeValue) {
            this.applyTheme(this.serverThemeValue);
            return;
        }
        this.applyTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    }

    toggle() {
        const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        this.applyTheme(next);
        // SEC-NEW-004: Add Secure flag when the page is served over HTTPS so that
        // the cookie is not transmitted over plain HTTP. window.location.protocol
        // is 'https:' behind a TLS-terminating proxy once the browser sees HTTPS.
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `theme=${next}; path=/; SameSite=Lax${secure}`;
    }

    applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
    }

    cookieTheme() {
        const match = document.cookie.match(/(?:^|;\s*)theme=(light|dark)/);
        return match ? match[1] : null;
    }
}
