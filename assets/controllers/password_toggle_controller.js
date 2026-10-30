// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'button', 'iconShow', 'iconHide'];
    static values = { showLabel: String, hideLabel: String };

    toggle() {
        const visible = this.inputTarget.type === 'text';
        this.inputTarget.type = visible ? 'password' : 'text';
        this.iconShowTarget.classList.toggle('d-none', !visible);
        this.iconHideTarget.classList.toggle('d-none', visible);
        if (this.hasButtonTarget && this.showLabelValue && this.hideLabelValue) {
            this.buttonTarget.setAttribute('aria-label', visible ? this.showLabelValue : this.hideLabelValue);
        }
    }
}
