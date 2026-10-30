// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import 'litepicker';

export default class extends Controller {
    static targets = ['input'];
    static values = {
        format: { type: String, default: 'YYYY-MM-DD' },
        lang: { type: String, default: 'de' },
    };

    connect() {
        this.picker = new window.Litepicker({
            element: this.inputTarget,
            format: this.formatValue,
            lang: this.langValue,
            resetButton: true,
            autoApply: true,
        });

        const dispatchChange = () => this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.picker.on('selected', dispatchChange);
        this.picker.on('clear:selection', dispatchChange);
    }

    disconnect() {
        this.picker?.destroy();
    }
}
