// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['body', 'toc', 'list'];

    observer = null;
    links = new Map();

    connect() {
        this.buildToc();
        this.observeHeadings();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    buildToc() {
        // HeadingPermalinkExtension places the id on the <a> inside <h2>/<h3>, not on the heading itself.
        const anchors = this.bodyTarget.querySelectorAll(
            'h2 > a.manual-heading-anchor[id], h3 > a.manual-heading-anchor[id]',
        );
        if (anchors.length === 0) {
            this.tocTarget.style.display = 'none';
            return;
        }

        const list = this.listTarget;
        list.replaceChildren();
        let currentH2List = null;

        anchors.forEach((a) => {
            const h = a.parentElement;
            const id = a.id;
            const text = (h.textContent || '').replace(/^#\s*/, '').trim();
            const link = document.createElement('a');
            link.href = `#${id}`;
            link.className = 'manual-toc-link';
            link.textContent = text;
            this.links.set(id, link);

            const item = document.createElement('li');
            item.appendChild(link);

            if (h.tagName === 'H2') {
                list.appendChild(item);
                currentH2List = null;
            } else {
                if (!currentH2List) {
                    currentH2List = document.createElement('ul');
                    const lastItem = list.lastElementChild;
                    (lastItem || list).appendChild(currentH2List);
                }
                currentH2List.appendChild(item);
            }
        });
    }

    observeHeadings() {
        if (this.links.size === 0) return;

        const anchors = this.bodyTarget.querySelectorAll(
            'h2 > a.manual-heading-anchor[id], h3 > a.manual-heading-anchor[id]',
        );
        const headings = Array.from(anchors).map((a) => a.parentElement);
        const options = {
            rootMargin: '-80px 0px -70% 0px',
            threshold: 0,
        };

        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const anchor = entry.target.querySelector('a.manual-heading-anchor[id]');
                    if (anchor) this.setActive(anchor.id);
                }
            });
        }, options);

        headings.forEach((h) => {
            this.observer.observe(h);
        });
    }

    setActive(id) {
        this.links.forEach((link, key) => {
            link.classList.toggle('active', key === id);
        });
    }
}
