// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Toast } from '@tabler/core';

const LEVEL_MODIFIERS = {
    success: 'toast--success',
    error: 'toast--danger',
    warning: 'toast--warning',
    info: 'toast--info',
};

// #205: warnings and errors stay until closed and interrupt the screen reader; success/info
// fade after the Bootstrap default (5 s) and are announced politely. Same split as base.html.twig.
const URGENT_LEVELS = ['error', 'warning'];

// #263: a region that is inserted together with its text is not reliably announced, so the text
// goes into the two permanent, empty live regions of base.html.twig instead of the toast itself.
// Each message is its own child node: a second (or identical) message is a new addition and gets
// read too, instead of overwriting the first. The short delay lets a region parsed on this very
// page load register with the screen reader before it changes; the node is dropped afterwards so
// the region does not grow (removals are not announced). The region is looked up only when
// writing: a toast controller connects during a Turbo render, while Turbo is still swapping the
// permanent regions back in — an element taken at that moment is the one about to be discarded.
const ANNOUNCE_DELAY_MS = 150;
const ANNOUNCE_KEEP_MS = 10000;

export function announceToast({ level, message }) {
    if (!message) return;

    const id = URGENT_LEVELS.includes(level) ? 'toast-announcer-assertive' : 'toast-announcer-polite';

    setTimeout(() => {
        const region = document.getElementById(id);
        if (!region) return;

        const line = document.createElement('div');
        line.textContent = message;
        region.append(line);
        setTimeout(() => line.remove(), ANNOUNCE_KEEP_MS);
    }, ANNOUNCE_DELAY_MS);
}

// The regions are permanent, so a message still inside would follow a Turbo visit onto the next
// page (logout included) and turn up there in browse mode. Each page starts with empty regions;
// its own toasts are written after the render.
document.addEventListener('turbo:before-render', () => {
    for (const id of ['toast-announcer-polite', 'toast-announcer-assertive']) {
        document.getElementById(id)?.replaceChildren();
    }
});

export function spawnToast({ level, message, closeLabel = 'Schließen' }) {
    if (!message) return;

    const modifier = LEVEL_MODIFIERS[level] || LEVEL_MODIFIERS.info;
    const urgent = URGENT_LEVELS.includes(level);

    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1090';
        container.style.marginTop = '4rem';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast fade align-items-center ${modifier}`;

    const inner = document.createElement('div');
    inner.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', closeLabel);

    inner.append(body, close);
    toast.append(inner);
    container.append(toast);

    requestAnimationFrame(() => new Toast(toast, { autohide: !urgent }).show());
    announceToast({ level, message });
}
