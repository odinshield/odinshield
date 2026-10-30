// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['name', 'key'];

    connect() {
        this._autoFill = this.keyTarget.value === '';
    }

    nameInput() {
        if (this._autoFill) {
            this.keyTarget.value = this._slugify(this.nameTarget.value);
        }
    }

    nameBlur() {
        if (this._autoFill) {
            this.keyTarget.value = this._slugify(this.nameTarget.value);
            this.keyTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    keyInput() {
        this._autoFill = this.keyTarget.value === '';
    }

    // Mirrors App\Service\Csaf\Matching\KeyNormaliser (#215), so the pre-filled key is
    // already the normalised form the help text names: "Coriva Engine" → "coriva_engine".
    _slugify(value) {
        return value
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }
}
