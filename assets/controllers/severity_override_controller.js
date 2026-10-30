// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

/*
 * Issue #215: marks the severity justification as required the moment the chosen severity
 * differs from the source value — before submit, instead of only through the error after it.
 *
 * Same rule as SeverityOverrideJustifiedValidator (severity ≠ sourceSeverity). This is a
 * convenience only; the validator stays the authority. The template renders the initial state
 * server-side, so without JavaScript the marking is right for the loaded value.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['severity', 'marker', 'notice', 'reason'];
    static values = { source: String };

    connect() {
        this.update();
    }

    update() {
        const required = this.severityTarget.value !== this.sourceValue;

        this.markerTarget.hidden = !required;
        this.noticeTarget.hidden = !required;
        if (required) {
            this.reasonTarget.setAttribute('aria-required', 'true');
        } else {
            this.reasonTarget.removeAttribute('aria-required');
        }
    }
}
