// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Mirrors PLAN §3 step 4 default-state logic on the successor form:
 * if every asset-group choice is "migrate", suggest "inactive" for the
 * predecessor; if any AG stays on the old version (keep/both), flip the
 * default to "active". Users can still override manually.
 */
export default class extends Controller {
    static values = { agPrefix: String, actionPrefix: String, submitted: Boolean };

    connect() {
        this.element.addEventListener('change', this.#onChange);
        // #214: after a failed submit the server re-renders the user's own choice; deriving the
        // default again would silently undo it (e.g. "keep predecessor active" → "inactive").
        if (!this.submittedValue) {
            this.#sync();
        }
    }

    disconnect() {
        this.element.removeEventListener('change', this.#onChange);
    }

    #onChange = (event) => {
        if (event.target?.name?.startsWith(this.agPrefixValue)) {
            this.#sync();
        }
    };

    #sync() {
        const agInputs = this.element.querySelectorAll(`input[type="radio"][name^="${this.agPrefixValue}"]:checked`);
        if (agInputs.length === 0) return;

        let allMigrate = true;
        agInputs.forEach((input) => {
            if (input.value !== 'migrate') allMigrate = false;
        });

        const desired = allMigrate ? 'inactive' : 'active';
        const target = this.element.querySelector(
            `input[type="radio"][name="${this.actionPrefixValue}"][value="${desired}"]`,
        );
        if (target && !target.checked) {
            target.checked = true;
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
