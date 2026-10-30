// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['searchInput', 'searchClear'];
    static values = { tableId: String, newLinkId: String };

    #syncHandler;

    connect() {
        this.#syncHandler = () => this.#syncNewLink();
        if (this.hasTableIdValue) {
            document.getElementById(this.tableIdValue)?.addEventListener('turbo:frame-render', this.#syncHandler);
        }
    }

    disconnect() {
        if (this.hasTableIdValue && this.#syncHandler) {
            document.getElementById(this.tableIdValue)?.removeEventListener('turbo:frame-render', this.#syncHandler);
        }
    }

    toggleClear() {
        if (this.hasSearchClearTarget) {
            this.searchClearTarget.classList.toggle('d-none', this.searchInputTarget.value === '');
        }
    }

    submitForm() {
        this.element.requestSubmit();
    }

    clearSearch() {
        this.searchInputTarget.value = '';
        if (this.hasSearchClearTarget) this.searchClearTarget.classList.add('d-none');
        this.element.requestSubmit();
    }

    selectFilter(event) {
        event.preventDefault();
        const { inputId, labelId, filterValue } = event.params;
        const input = document.getElementById(inputId);
        const label = document.getElementById(labelId);
        if (input) input.value = filterValue;
        if (label) label.textContent = event.currentTarget.textContent.trim();
        document.querySelectorAll(`[data-index-filter-input-id-param="${inputId}"]`).forEach((el) => {
            el.classList.toggle('active', el.dataset.indexFilterFilterValueParam === filterValue);
        });
        event.currentTarget.closest('form').requestSubmit();
    }

    searchDropdown(event) {
        const q = event.target.value.trim().toLowerCase();
        event.target
            .closest('.dropdown')
            ?.querySelectorAll('[data-index-filter-filter-value-param]')
            .forEach((el) => {
                el.classList.toggle('d-none', q !== '' && !el.textContent.trim().toLowerCase().includes(q));
            });
    }

    resetDropdown(event) {
        const searchInput = event.currentTarget.querySelector('[data-action*="index-filter#searchDropdown"]');
        if (!searchInput) return;
        searchInput.value = '';
        event.currentTarget.querySelectorAll('[data-index-filter-filter-value-param]').forEach((el) => {
            el.classList.remove('d-none');
        });
    }

    #syncNewLink() {
        if (!this.hasNewLinkIdValue) return;
        const btn = document.getElementById(this.newLinkIdValue);
        if (!btn) return;
        const url = new URL(btn.href);
        new URLSearchParams(window.location.search).forEach((v, k) => {
            url.searchParams.set(k, v);
        });
        btn.href = url.toString();
    }
}
