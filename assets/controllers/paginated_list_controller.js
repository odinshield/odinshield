// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['item', 'pagination'];
    static values = { pageSize: { type: Number, default: 15 }, page: { type: Number, default: 1 } };

    connect() {
        this.render();
    }

    render() {
        const total = this.itemTargets.length;
        const pageSize = this.pageSizeValue;
        const page = this.pageValue;
        const pages = Math.ceil(total / pageSize);
        const start = (page - 1) * pageSize;
        const end = start + pageSize;

        this.itemTargets.forEach((el, i) => {
            el.hidden = i < start || i >= end;
        });

        if (this.hasPaginationTarget) {
            this.renderPagination(page, pages);
        }
    }

    renderPagination(page, pages) {
        const container = this.paginationTarget;
        container.replaceChildren();

        if (pages <= 1) {
            return;
        }

        const ul = document.createElement('ul');
        ul.className = 'pagination pagination-sm m-0 mt-2';

        ul.appendChild(this.pageItem(page > 1 ? this.pageLink('\u2039', 'paginated-list#prev') : null));

        for (let p = 1; p <= pages; p++) {
            if (p === page) {
                const li = document.createElement('li');
                li.className = 'page-item active';
                const span = document.createElement('span');
                span.className = 'page-link';
                span.textContent = String(p);
                li.appendChild(span);
                ul.appendChild(li);
            } else {
                const a = this.pageLink(String(p), 'paginated-list#goto');
                a.dataset.paginatedListTargetPageParam = String(p);
                ul.appendChild(this.pageItem(a));
            }
        }

        ul.appendChild(this.pageItem(page < pages ? this.pageLink('\u203a', 'paginated-list#next') : null));

        container.appendChild(ul);
    }

    pageItem(anchor) {
        const li = document.createElement('li');
        li.className = `page-item${anchor === null ? ' disabled' : ''}`;
        if (anchor === null) {
            const span = document.createElement('span');
            span.className = 'page-link';
            li.appendChild(span);
        } else {
            li.appendChild(anchor);
        }

        return li;
    }

    pageLink(text, action) {
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.textContent = text;
        a.dataset.action = action;

        return a;
    }

    prev(event) {
        event.preventDefault();
        if (this.pageValue > 1) {
            this.pageValue--;
        }
    }

    next(event) {
        event.preventDefault();
        const pages = Math.ceil(this.itemTargets.length / this.pageSizeValue);
        if (this.pageValue < pages) {
            this.pageValue++;
        }
    }

    goto(event) {
        event.preventDefault();
        const p = parseInt(event.params.targetPage, 10);
        if (p >= 1 && p <= Math.ceil(this.itemTargets.length / this.pageSizeValue)) {
            this.pageValue = p;
        }
    }

    pageValueChanged() {
        this.render();
    }
}
