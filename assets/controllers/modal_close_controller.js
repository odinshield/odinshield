// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Modal } from '@tabler/core';

export default class extends Controller {
    static values = { targetId: String };

    connect() {
        if (this.hasTargetIdValue) {
            const el = document.getElementById(this.targetIdValue);
            const instance = el ? Modal.getInstance(el) : null;
            if (instance) {
                // Bootstrap's hide() unconditionally removes body.modal-open even if another
                // modal is still visible (e.g. the outer detail modal in a nested flow).
                el.addEventListener(
                    'hidden.bs.modal',
                    () => {
                        if (document.querySelector('.modal.show')) {
                            document.body.classList.add('modal-open');
                        }
                    },
                    { once: true },
                );
                instance.hide();
            }
            this.element.remove();

            return;
        }

        this.handler = () => Modal.getInstance(this.element)?.hide();
        this.element.addEventListener('modal:close', this.handler);
    }

    disconnect() {
        if (this.handler) {
            this.element.removeEventListener('modal:close', this.handler);
        }
    }
}
