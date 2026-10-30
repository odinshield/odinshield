// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Dropdown, Modal } from '@tabler/core';

export default class extends Controller {
    static targets = ['dialog', 'body', 'primaryLink', 'title'];
    static values = {
        errorText: { type: String, default: 'Content could not be loaded.' },
        loadingText: { type: String, default: 'Loading …' },
    };

    connect() {
        this._initDropdowns();
        this._frameRenderHandler = () => this._initDropdowns();
        this.element.addEventListener('turbo:frame-render', this._frameRenderHandler);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-render', this._frameRenderHandler);
        this._abort?.abort();
    }

    _initDropdowns() {
        this.element.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((el) => {
            new Dropdown(el, { popperConfig: { strategy: 'fixed' } });
        });
    }

    async open(event) {
        // #212: the trigger is a real link to the detail page. Ctrl/Cmd/Shift-click (and any
        // button but the main one) keep the browser's own meaning — new tab or window — instead
        // of the modal; the manual's tip promises exactly that.
        if (event.ctrlKey || event.metaKey || event.shiftKey || (event.button ?? 0) !== 0) {
            return;
        }
        event.preventDefault();

        const { url, primaryUrl, title } = event.params;

        this.titleTarget.textContent = title;
        if (this.hasPrimaryLinkTarget) {
            this.primaryLinkTarget.classList.add('d-none');
        }

        // Sequence guard: a second open() aborts the first so a slow earlier response can never
        // overwrite the body with the wrong entity's content (audit L16).
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

        // content is server-rendered Twig with auto-escaping — safe to parse
        const doc = new DOMParser().parseFromString(html, 'text/html');
        this.bodyTarget.replaceChildren(...doc.body.childNodes);
        this._initDropdowns();

        if (this.hasPrimaryLinkTarget && primaryUrl) {
            this.primaryLinkTarget.href = primaryUrl;
            this.primaryLinkTarget.classList.remove('d-none');
        }
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
