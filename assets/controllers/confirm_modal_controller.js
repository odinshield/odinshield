// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Modal } from '@tabler/core';

export default class extends Controller {
    static targets = ['dialog', 'message'];

    pendingForm = null;

    open(event) {
        event.preventDefault();
        this.pendingForm = event.currentTarget.closest('form');
        this.messageTarget.textContent = event.params.message;
        const modal = Modal.getInstance(this.dialogTarget) || new Modal(this.dialogTarget);
        modal.show();
        this.dialogTarget.addEventListener(
            'hidden.bs.modal',
            () => {
                this.pendingForm = null;
            },
            { once: true },
        );
    }

    confirm() {
        const modal = Modal.getInstance(this.dialogTarget);
        if (modal) {
            modal.hide();
        }
        if (this.pendingForm) {
            this.pendingForm.requestSubmit();
            this.pendingForm = null;
        }
    }
}
