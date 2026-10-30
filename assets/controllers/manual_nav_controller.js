// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

// #207 (UX-44): below the lg breakpoint the drawer is only slid out of view (manual.css), so its
// links stayed in the Tab order while invisible. There it is `inert` while closed; opening moves
// focus to the current chapter, Escape or the scrim close it and focus returns to the menu button,
// and tabbing out of the open drawer closes it (the content behind the scrim is not a place to
// land in with the drawer still covering it). On wide screens the drawer is part of the layout and
// never inert. Keep the query in step with the 991px media block.
const NARROW = '(max-width: 991px)';

export default class extends Controller {
    static targets = ['toggle', 'drawer', 'scrim'];

    connect() {
        this.media = window.matchMedia(NARROW);
        this.onMediaChange = () => this.syncInert();
        this.media.addEventListener('change', this.onMediaChange);
        this.onKeydown = (event) => {
            // An open search dialog owns Escape; one key press closes one layer.
            // defaultPrevented covers the search having handled this Escape before us.
            if (
                event.key === 'Escape' &&
                this.isOpen() &&
                !event.defaultPrevented &&
                !document.querySelector('.manual-search-modal.open')
            ) {
                this.close();
            }
        };
        window.addEventListener('keydown', this.onKeydown);
        this.onFocusOut = (event) => {
            const next = event.relatedTarget;
            if (!this.media.matches || !this.isOpen() || !next) return;
            // The app bar (menu button included) is not covered by the scrim; only leaving into
            // the dimmed content closes the drawer.
            if (this.drawerTarget.contains(next) || next.closest('.manual-app-bar')) return;
            this.close({ refocus: false });
        };
        if (this.hasDrawerTarget) this.drawerTarget.addEventListener('focusout', this.onFocusOut);
        this.syncInert();
    }

    disconnect() {
        this.media?.removeEventListener('change', this.onMediaChange);
        window.removeEventListener('keydown', this.onKeydown);
        if (this.hasDrawerTarget) this.drawerTarget.removeEventListener('focusout', this.onFocusOut);
    }

    isOpen() {
        return this.hasDrawerTarget && this.drawerTarget.classList.contains('open');
    }

    toggle() {
        const isOpen = this.drawerTarget.classList.toggle('open');
        if (this.hasScrimTarget) {
            this.scrimTarget.classList.toggle('open', isOpen);
        }
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', String(isOpen));
        }
        this.syncInert();
        if (isOpen) {
            const nav = this.drawerTarget;
            (nav.querySelector('.manual-nav-link.active') ?? nav.querySelector('.manual-nav-link'))?.focus();
        }
    }

    close({ refocus = true } = {}) {
        // Focus inside the drawer would be dropped onto <body> once it turns inert; a tap on the
        // scrim has already dropped it there. Both go back to the menu button.
        const focus = document.activeElement;
        const hadFocus = this.isOpen() && (!focus || focus === document.body || this.drawerTarget.contains(focus));
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.remove('open');
        }
        if (this.hasScrimTarget) {
            this.scrimTarget.classList.remove('open');
        }
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', 'false');
        }
        this.syncInert();
        // Tabbing out (refocus: false) already moves focus on; called as the scrim's Stimulus
        // action the argument is the click event, which has no `refocus` and keeps the default.
        if (refocus && hadFocus && this.hasToggleTarget) {
            this.toggleTarget.focus();
        }
    }

    syncInert() {
        if (!this.hasDrawerTarget) return;
        this.drawerTarget.inert = this.media.matches && !this.isOpen();
    }
}
