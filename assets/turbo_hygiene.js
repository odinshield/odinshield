// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Modal } from '@tabler/core';

// H3: leave a clean DOM in Turbo's page cache. Without this, navigating away while a modal is
// open (or a toast is visible, or a datepicker popup is mounted) caches that frozen state — on
// "Back" the page restores with a dead backdrop that blocks all clicks, stacked toasts, or an
// orphaned datepicker overlay.
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('.modal.show').forEach((el) => {
        Modal.getInstance(el)?.dispose();
        el.classList.remove('show');
        el.setAttribute('aria-hidden', 'true');
        el.style.display = 'none';
    });
    document.querySelectorAll('.modal-backdrop').forEach((el) => {
        el.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.toast-container .toast').forEach((el) => {
        el.remove();
    });
    // Litepicker mounts its popup as a detached element on <body>; drop any leftovers.
    document.querySelectorAll('.litepicker').forEach((el) => {
        el.remove();
    });
    // #207: the manual search is a modal dialog with a focus trap; restored open on "Back" it
    // would hold focus without anywhere to return it to. Same for the open narrow drawer.
    document
        .querySelectorAll('.manual-search-modal.open, .manual-drawer.open, .manual-drawer-scrim.open')
        .forEach((el) => {
            el.classList.remove('open');
        });
    document.querySelector('.manual-menu-toggle')?.setAttribute('aria-expanded', 'false');
});

// H4: a 401 on any Turbo fetch means the session expired mid-session. Redirect hard to the login
// page (URL provided by TurboAwareAuthenticationEntryPoint) instead of letting Turbo render the
// empty 401 body or splice a login page into a frame.
document.addEventListener('turbo:before-fetch-response', (event) => {
    const response = event.detail?.fetchResponse?.response;
    if (response && response.status === 401) {
        event.preventDefault();
        window.location.href = response.headers.get('X-Login-Redirect') || '/login';
    }
});
