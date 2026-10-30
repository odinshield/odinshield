// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.enhanceCallouts();
    }

    enhanceCallouts() {
        const blockquotes = this.element.querySelectorAll('blockquote');
        blockquotes.forEach((bq) => {
            const firstP = bq.querySelector('p');
            if (!firstP) return;

            const firstStrong = firstP.querySelector('strong:first-child');
            if (!firstStrong) return;

            const text = firstStrong.textContent.toLowerCase();

            if (text.includes('tipp') || text.includes('tip')) {
                bq.classList.add('callout-tip');
            } else if (
                text.includes('wichtig') ||
                text.includes('important') ||
                text.includes('achtung') ||
                text.includes('warning')
            ) {
                bq.classList.add('callout-warning');
            } else if (text.includes('hinweis') || text.includes('note')) {
                bq.classList.add('callout-info');
            }
        });
    }
}
