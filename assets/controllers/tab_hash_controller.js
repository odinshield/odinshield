// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

// Activates the Bootstrap tab whose target matches location.hash on page load,
// so deep-links like /software/{uuid}#tab-software-history open on that tab.
// Used by the optimistic-lock conflict banner (H6) to land the user on the
// entity's fully-chromed history tab instead of a styleless audit fragment.
export default class extends Controller {
    connect() {
        const hash = window.location.hash;
        if (hash.length < 2) return;

        const trigger = document.querySelector(`[data-bs-toggle="tab"][href="#${CSS.escape(hash.slice(1))}"]`);
        trigger?.click();
    }
}
