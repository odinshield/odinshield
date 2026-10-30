// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Tooltip } from '@tabler/core';

// Per-element tooltip init. Tabler's one-shot bootstrap only initialises tooltips present on the
// first page load — elements rendered by a later Turbo navigation would stay dead. Binding a
// controller instead re-inits on every connect and disposes on disconnect (audit L17).
export default class extends Controller {
    connect() {
        Tooltip.getInstance(this.element)?.dispose();
        this.tooltip = new Tooltip(this.element);
    }

    disconnect() {
        this.tooltip?.dispose();
    }
}
