// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

/**
 * Collapsible form help (#79, OPNsense pattern).
 *
 * The markup renders every hint visible and `aria-expanded="true"` on purpose: without
 * JavaScript the hints stay readable, which is the behaviour this feature replaces. Collapsing
 * happens here, on connect, so the enhancement can never hide text it cannot bring back.
 *
 * The "show everything" choice is remembered in a cookie, not in localStorage, so that it
 * behaves like the theme cookie and survives a Turbo visit without a flash of the wrong state.
 */
export default class extends Controller {
    static targets = ['toggle', 'hint', 'switch'];

    connect() {
        this.showAll = this.cookieShowAll();
        this.applyAll(this.showAll);
        this.switchTargets.forEach((input) => {
            input.checked = this.showAll;
        });
    }

    toggle(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const hint = this.hintFor(button);
        if (!hint) {
            return;
        }
        this.setHint(button, hint, hint.hasAttribute('hidden'));
    }

    toggleAll(event) {
        this.showAll = event.currentTarget.checked;
        this.applyAll(this.showAll);
        this.switchTargets.forEach((input) => {
            input.checked = this.showAll;
        });
        // SEC-NEW-004 (same reasoning as the theme cookie): only send the cookie over TLS when
        // the browser is on HTTPS. It carries a display preference, no personal data.
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `form_help=${this.showAll ? 'all' : 'off'}; path=/; SameSite=Lax${secure}`;
    }

    applyAll(visible) {
        this.toggleTargets.forEach((button) => {
            const hint = this.hintFor(button);
            if (hint) {
                this.setHint(button, hint, visible);
            }
        });
        // A hint whose field carries no toggle button (not a form field) follows the switch too.
        this.hintTargets
            .filter((hint) => !this.toggleTargets.some((button) => this.hintFor(button) === hint))
            .forEach((hint) => {
                hint.toggleAttribute('hidden', !visible);
            });
    }

    setHint(button, hint, visible) {
        hint.toggleAttribute('hidden', !visible);
        button.setAttribute('aria-expanded', visible ? 'true' : 'false');
        const label = visible ? button.dataset.labelHide : button.dataset.labelShow;
        if (label) {
            button.setAttribute('title', label);
            const sr = button.querySelector('.visually-hidden');
            if (sr) {
                sr.textContent = label;
            }
        }
    }

    hintFor(button) {
        const id = button.getAttribute('aria-controls');

        return id ? this.element.querySelector(`#${CSS.escape(id)}`) : null;
    }

    cookieShowAll() {
        return /(?:^|;\s*)form_help=all(?:;|$)/.test(document.cookie);
    }
}
