// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

// Disables the button and shows a spinner while its form submits. Uses named handlers so
// disconnect() can detach them (no listener stacking across Turbo cache restores) and resets any
// leftover loading state so a cached page never restores a permanently-disabled button (M9).
//
// When the submission is answered by a Turbo Stream (e.g. the bulk-close modal) the page does not
// navigate, so disconnect() never fires — turbo:submit-end is the only signal that the request
// finished. Without resetting there the button stays a permanent spinner after the modal closes.
export default class extends Controller {
    connect() {
        this.form = this.element.closest('form');
        if (!this.form) {
            return;
        }
        this.onSubmit = () => {
            this.element.setAttribute('disabled', '');
            this.element.classList.add('btn-loading');
        };
        this.reset = () => {
            this.element.removeAttribute('disabled');
            this.element.classList.remove('btn-loading');
        };
        this.form.addEventListener('submit', this.onSubmit);
        this.form.addEventListener('turbo:submit-end', this.reset);
    }

    disconnect() {
        this.form?.removeEventListener('submit', this.onSubmit);
        this.form?.removeEventListener('turbo:submit-end', this.reset);
        this.reset?.();
    }
}
