// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Toast } from '@tabler/core';
import { announceToast } from '../toast.js';

export default class extends Controller {
    static values = { level: String };

    connect() {
        requestAnimationFrame(() => new Toast(this.element).show());
        announceToast({
            level: this.levelValue,
            message: this.element.querySelector('.toast-body')?.textContent.trim(),
        });
    }
}
