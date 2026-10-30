// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// "Load older changes" (#216): the button is busy while a page loads, so a double click cannot
// fetch the same page twice; a failed load says so instead of doing nothing, and an expired
// session redirects to the login like the modal loaders (SEC-014).
export default class extends Controller {
    static targets = ['list', 'loadMoreWrapper', 'button', 'error'];
    static values = { url: String, page: Number };

    async loadMore() {
        if (this.loading) {
            return;
        }
        this.loading = true;
        this.#setBusy(true);
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = true;
        }

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('page', String(this.pageValue + 1));

        try {
            const res = await fetch(url.toString(), {
                headers: { Accept: 'text/html' },
            });

            if (res.status === 401) {
                window.location.href = res.headers.get('X-Login-Redirect') || '/login';
                return;
            }
            if (!res.ok) {
                this.#showError();
                return;
            }

            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');

            doc.querySelectorAll('li.timeline-item').forEach((li) => {
                this.listTarget.appendChild(li.cloneNode(true));
            });
            this.pageValue += 1;

            const hasMore = doc.querySelector('[data-timeline-has-more]') !== null;
            if (!hasMore && this.hasLoadMoreWrapperTarget) {
                this.loadMoreWrapperTarget.remove();
            }
        } catch {
            this.#showError();
        } finally {
            this.loading = false;
            this.#setBusy(false);
        }
    }

    #setBusy(busy) {
        if (!this.hasButtonTarget) {
            return;
        }
        this.buttonTarget.disabled = busy;
        this.buttonTarget.classList.toggle('btn-loading', busy);
    }

    #showError() {
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = false;
        }
    }
}
