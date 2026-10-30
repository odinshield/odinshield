// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { spawnToast } from '../toast.js';

export default class extends Controller {
    static values = {
        level: String,
        message: String,
        closeLabel: { type: String, default: 'Schließen' },
    };

    connect() {
        spawnToast({
            level: this.levelValue,
            message: this.messageValue,
            closeLabel: this.closeLabelValue,
        });

        this.element.remove();
    }
}
