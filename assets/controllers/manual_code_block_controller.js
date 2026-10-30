// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

const COPY_ICON = `
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
    </svg>
`;
const RESET_MS = 2000;

export default class extends Controller {
    static targets = ['body'];
    static values = {
        copyLabel: { type: String, default: 'Code kopieren' },
        copiedLabel: { type: String, default: 'Kopiert' },
        failedLabel: { type: String, default: 'Kopieren nicht möglich' },
    };

    connect() {
        this.addCopyButtons();
    }

    addCopyButtons() {
        const preBlocks = this.element.querySelectorAll('pre');
        preBlocks.forEach((pre) => {
            // Ensure we don't add it twice
            if (pre.querySelector('.manual-copy-button')) return;

            pre.style.position = 'relative';

            const button = document.createElement('button');
            button.className = 'manual-copy-button md-icon-button';
            button.type = 'button';
            button.setAttribute('aria-label', this.copyLabelValue);
            button.innerHTML = COPY_ICON;

            button.addEventListener('click', () => this.copyToClipboard(pre, button));

            pre.appendChild(button);
        });
    }

    async copyToClipboard(pre, button) {
        const code = pre.querySelector('code');
        const text = code ? code.innerText : pre.innerText;

        try {
            await navigator.clipboard.writeText(text);
            this.showResult(button, true);
        } catch (_err) {
            // #207 (UX-44): a failure used to reach only the console; now it shows and is read.
            this.showResult(button, false);
        }
    }

    // #207 (UX-44): the outcome is announced through the status region of manual_base.html.twig
    // only — the button keeps its name, a changing aria-label on the focused button would be read
    // as well. Sighted users get the icon and the title tooltip. The reset always restores the
    // copy icon, so a second click within the reset time cannot make the result icon stick.
    showResult(button, ok) {
        const label = ok ? this.copiedLabelValue : this.failedLabelValue;
        button.innerHTML = ok
            ? `
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="green" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        `
            : `
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        `;
        button.classList.remove('success', 'failed');
        button.classList.add(ok ? 'success' : 'failed');
        button.title = label;

        const status = document.getElementById('manual-copy-status');
        if (status) {
            status.textContent = '';
            setTimeout(() => {
                status.textContent = label;
            }, 100);
        }

        clearTimeout(button.resetTimer);
        button.resetTimer = setTimeout(() => {
            button.innerHTML = COPY_ICON;
            button.classList.remove('success', 'failed');
            button.removeAttribute('title');
        }, RESET_MS);
    }
}
