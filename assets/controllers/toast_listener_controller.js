// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { spawnToast } from '../toast.js';

// Bridges a bubbling `toast:spawn` browser event (e.g. dispatched by a Live Component
// via dispatchBrowserEvent) to a client-side toast. Mounted broadly on <body> so it
// catches events from server-rendered content injected anywhere on the page.
export default class extends Controller {
    #handler;

    connect() {
        this.#handler = (event) => spawnToast(event.detail || {});
        this.element.addEventListener('toast:spawn', this.#handler);
    }

    disconnect() {
        if (this.#handler) {
            this.element.removeEventListener('toast:spawn', this.#handler);
        }
    }
}
