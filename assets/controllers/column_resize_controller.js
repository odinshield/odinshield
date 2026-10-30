// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

/*
 * #80: user-resizable columns for the vulnerability list.
 *
 * Widths are persisted in a cookie so the server renders them straight into the header cells
 * (`--col-w`) — no layout jump on load, and a Turbo frame reload keeps them. They apply only at
 * the xl breakpoint and up (see app.css): below it Bootstrap hides columns responsively, and fixed
 * pixel widths would fight that.
 *
 * The first drag freezes every column at its current rendered width before switching the table to
 * `table-layout: fixed`, so nothing jumps; every column is stored, because fixed layout would
 * otherwise share the leftover space equally with the narrow select/actions columns.
 *
 * No column may get narrower than its header content: the label may wrap at word boundaries, but
 * the sort icon has to stay visible. Columns marked `data-column-resize-content-min` (badges, the
 * actions button) also keep their widest cell whole; `data-column-resize-min` adds a fixed floor
 * for columns whose badges shorten with an ellipsis. The minimum is measured, not configured, so it follows the
 * translation and the theme's font.
 */
const MIN_WIDTH = 32;
const MAX_WIDTH = 1200;
const KEY_STEP = 16;
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365;
const XL_QUERY = '(min-width: 1200px)';

export default class extends Controller {
    static targets = ['table', 'column', 'reset'];

    initialize() {
        this.minWidths = new WeakMap();
    }

    static values = {
        cookie: String,
        label: String,
    };

    columnTargetConnected(th) {
        if (th.querySelector('.column-resize-handle')) {
            return;
        }
        const handle = document.createElement('span');
        handle.className = 'column-resize-handle';
        handle.tabIndex = 0;
        handle.setAttribute('role', 'separator');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute(
            'aria-label',
            this.labelValue.replace('%column%', th.textContent.trim().replace(/\s+/g, ' ') || th.dataset.columnKey),
        );
        handle.addEventListener('pointerdown', (event) => this.startDrag(event, th));
        handle.addEventListener('keydown', (event) => this.keyResize(event, th));
        // A drag must never reach the sort link in the same header cell.
        handle.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
        th.appendChild(handle);
    }

    tableTargetConnected() {
        // Widths from an older cookie (or a changed translation) may undercut a header's minimum —
        // correct them once the header cells are laid out, and store the corrected set.
        requestAnimationFrame(() => {
            const table = this.tableTargets[0];
            if (!table?.classList.contains('is-resized') || !window.matchMedia(XL_QUERY).matches) {
                return;
            }
            let changed = false;
            for (const th of this.columnTargets) {
                const current = Number.parseInt(th.style.getPropertyValue('--col-w'), 10);
                if (!Number.isNaN(current) && current < this.minWidth(th)) {
                    this.setWidth(th, current);
                    changed = true;
                }
            }
            if (changed) {
                this.save();
            }
        });
    }

    startDrag(event, th) {
        if (!window.matchMedia(XL_QUERY).matches || event.button !== 0) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.freeze();

        const handle = event.currentTarget;
        const startX = event.clientX;
        const startWidth = th.getBoundingClientRect().width;
        handle.setPointerCapture(event.pointerId);
        handle.classList.add('is-dragging');
        // Pointer capture alone does not stop the browser from selecting header text on the way.
        document.body.classList.add('user-select-none');

        const move = (moveEvent) => this.setWidth(th, startWidth + moveEvent.clientX - startX);
        const end = () => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', end);
            handle.removeEventListener('pointercancel', end);
            handle.classList.remove('is-dragging');
            document.body.classList.remove('user-select-none');
            this.save();
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
    }

    keyResize(event, th) {
        if (!window.matchMedia(XL_QUERY).matches || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) {
            return;
        }
        event.preventDefault();
        this.freeze();
        const delta = event.key === 'ArrowRight' ? KEY_STEP : -KEY_STEP;
        this.setWidth(th, th.getBoundingClientRect().width + delta);
        this.save();
    }

    reset() {
        document.cookie = `${this.cookieValue}=; path=/; max-age=0; SameSite=Lax${this.secureFlag()}`;
        for (const th of this.columnTargets) {
            th.style.removeProperty('--col-w');
        }
        for (const table of this.tableTargets) {
            table.classList.remove('is-resized');
        }
        this.toggleReset(false);
    }

    freeze() {
        const table = this.tableTargets[0];
        if (!table || table.classList.contains('is-resized')) {
            return;
        }
        // #181: columns the table-scroll controller collapsed would measure 0 and freeze at their
        // minimum. Show them first; with widths set by hand the table scrolls instead of dropping
        // columns, and that controller leaves them visible from now on.
        for (const name of [...table.classList]) {
            if (name.startsWith('table-collapse-')) {
                table.classList.remove(name);
            }
        }
        // Measure everything first, then write — interleaving would reflow between reads.
        const widths = this.columnTargets.map((th) => th.getBoundingClientRect().width);
        this.columnTargets.forEach((th, index) => {
            this.setWidth(th, widths[index]);
        });
        table.classList.add('is-resized');
    }

    setWidth(th, width) {
        const clamped = Math.round(Math.min(MAX_WIDTH, Math.max(this.minWidth(th), width)));
        th.style.setProperty('--col-w', `${clamped}px`);
        const handle = th.querySelector('.column-resize-handle');
        if (handle) {
            handle.setAttribute('aria-valuenow', String(clamped));
        }
    }

    /*
     * The narrowest a column can get: its header's min-content (label wrapped at word boundaries,
     * sort icon kept) and, for badge columns, the widest cell. Cached per header cell; a Turbo frame
     * reload brings new cells and therefore a fresh measurement.
     */
    minWidth(th) {
        if (this.minWidths.has(th)) {
            return this.minWidths.get(th);
        }
        let width = this.measureMinContent(th);
        if (th.hasAttribute('data-column-resize-content-min')) {
            const table = th.closest('table');
            for (const row of table ? (table.tBodies[0]?.rows ?? []) : []) {
                const cell = row.cells[th.cellIndex];
                if (cell) {
                    width = Math.max(width, this.measureMinContent(cell));
                }
            }
        }
        const floor = Number.parseInt(th.dataset.columnResizeMin ?? '', 10);
        width = Math.max(MIN_WIDTH, Number.isNaN(floor) ? 0 : floor, width);
        this.minWidths.set(th, width);

        return width;
    }

    /*
     * min-content width of a cell's children plus its inline padding, measured on an inert hidden
     * clone inside the same cell so it inherits the cell's font, size and text-transform.
     */
    measureMinContent(cell) {
        const probe = document.createElement('div');
        probe.setAttribute('aria-hidden', 'true');
        probe.style.cssText =
            'position:absolute;visibility:hidden;inline-size:min-content;white-space:normal;inset-block-start:0;inset-inline-start:0;';
        for (const child of cell.children) {
            if (!child.classList.contains('column-resize-handle')) {
                probe.appendChild(child.cloneNode(true));
            }
        }
        // The clone must stay inert: no ids, names or Stimulus wiring (the select-all checkbox is a
        // bulk-action target), or a controller could pick it up during the measurement.
        for (const element of probe.querySelectorAll('*')) {
            for (const attribute of [...element.attributes]) {
                if (attribute.name === 'id' || attribute.name === 'name' || attribute.name.startsWith('data-')) {
                    element.removeAttribute(attribute.name);
                }
            }
        }
        // Inside the cell itself, so body content is measured in body typography, not the header's.
        // min-content is intrinsic, so it does not matter which ancestor the probe is positioned in.
        cell.appendChild(probe);
        const style = getComputedStyle(cell);
        const padding = Number.parseFloat(style.paddingInlineStart) + Number.parseFloat(style.paddingInlineEnd);
        const width = Math.ceil(probe.getBoundingClientRect().width + padding);
        probe.remove();

        return width;
    }

    save() {
        const value = this.columnTargets
            .map((th) => {
                const width = Number.parseInt(th.style.getPropertyValue('--col-w'), 10);
                return Number.isNaN(width) ? null : `${th.dataset.columnKey}:${width}`;
            })
            .filter(Boolean)
            .join('|');
        document.cookie = `${this.cookieValue}=${value}; path=/; max-age=${COOKIE_MAX_AGE}; SameSite=Lax${this.secureFlag()}`;
        this.toggleReset(true);
    }

    toggleReset(visible) {
        for (const button of this.resetTargets) {
            button.classList.toggle('d-xl-inline-flex', visible);
        }
    }

    // SEC-NEW-004 (see theme_toggle_controller.js): Secure flag whenever the page is served over HTTPS.
    secureFlag() {
        return window.location.protocol === 'https:' ? '; Secure' : '';
    }
}
