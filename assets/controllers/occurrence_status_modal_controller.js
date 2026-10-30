// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Modal } from '@tabler/core';

export default class extends Controller {
    static targets = ['dialog', 'body', 'title'];
    static values = {
        errorText: { type: String, default: 'Content could not be loaded.' },
        loadingText: { type: String, default: 'Loading …' },
    };

    disconnect() {
        this._abort?.abort();
    }

    async open(event) {
        event.preventDefault();

        const { url, title } = event.params;

        this.titleTarget.textContent = title;

        // Sequence guard against last-response-wins: opening a second occurrence aborts the first
        // pending fetch so the body can't be filled with the wrong occurrence's form (audit L16).
        this._abort?.abort();
        this._abort = new AbortController();
        const { signal } = this._abort;

        this._showSpinner();
        (Modal.getInstance(this.dialogTarget) || new Modal(this.dialogTarget)).show();

        let response;
        try {
            response = await fetch(url, { signal });
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            this._showError();
            return;
        }

        if (response.status === 401) {
            window.location.href = response.headers.get('X-Login-Redirect') || '/login';
            return;
        }
        // SEC-012: Guard against non-HTML or error responses before injecting into the DOM.
        if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) {
            this._showError();
            return;
        }

        const html = await response.text();
        if (signal.aborted) {
            return;
        }

        const doc = new DOMParser().parseFromString(html, 'text/html');
        this.bodyTarget.replaceChildren(...doc.body.childNodes);
    }

    _showSpinner() {
        const wrapper = document.createElement('div');
        wrapper.className = 'text-center py-4';
        const spinner = document.createElement('div');
        spinner.className = 'spinner-border text-secondary';
        spinner.setAttribute('role', 'status');
        const label = document.createElement('span');
        label.className = 'visually-hidden';
        label.textContent = this.loadingTextValue;
        spinner.append(label);
        wrapper.append(spinner);
        this.bodyTarget.replaceChildren(wrapper);
    }

    _showError() {
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger mb-0';
        alert.setAttribute('role', 'alert');
        alert.textContent = this.errorTextValue;
        this.bodyTarget.replaceChildren(alert);
    }
}
