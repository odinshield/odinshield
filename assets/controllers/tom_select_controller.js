// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.default.min.css';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this._initTomSelect();
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.wrapper?.removeEventListener('click', this._onWrapperClick);
            this.tomSelect.destroy();
        }
    }

    _initTomSelect() {
        this.tomSelect = new TomSelect(this.element, {
            plugins: this.element.multiple ? ['remove_button'] : [],
            placeholder: this.element.dataset.placeholder ?? '',
            hidePlaceholder: true,
            render: {
                option: (data, esc) =>
                    `<div class="${data.inactive ? 'ts-option-inactive' : ''}">${esc(data.text)}</div>`,
                item: (data, esc) => `<div class="${data.inactive ? 'ts-item-inactive' : ''}">${esc(data.text)}</div>`,
            },
        });

        // TomSelect only binds its click handler to .ts-control, not the wrapper.
        // Any wrapper padding area becomes a dead zone. Forward those clicks explicitly.
        this._onWrapperClick = (e) => {
            if (!e.target.closest('.ts-control') && !this.tomSelect.isOpen) {
                this.tomSelect.open();
            }
        };
        this.tomSelect.wrapper.addEventListener('click', this._onWrapperClick);
    }
}
