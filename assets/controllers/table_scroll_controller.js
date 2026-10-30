// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

/*
 * #80: mirrors a horizontally scrollable table container with a scrollbar above it.
 *
 * The top bar holds an empty spacer as wide as the table, so it scrolls the same distance; both
 * containers copy each other's scrollLeft. A ResizeObserver keeps the spacer in step when the
 * table grows (column resize, viewport change, Turbo frame reload), and hides the top bar while
 * nothing overflows.
 *
 * `collapse` names columns that may be dropped before the table overflows, in the order they go
 * (e.g. `["discovered", "software"]`). Each gets `table-collapse-<name>` on the table; the CSS hides
 * the cells marked `collapsible-<name>`. Measured instead of a fixed breakpoint: the room left for the
 * table depends on the sidebar, the display scaling and the font, and a breakpoint hid the columns
 * on screens that had space for them.
 *
 * #181: not once the user has set column widths (`is-resized`, which only takes effect from the xl
 * breakpoint up). Someone who drags a column wider wants to scroll, not to lose the columns next to
 * it — so from then on the table overflows and the top scrollbar takes over. Resetting the widths
 * removes the class, the table shrinks, and the observer measures again.
 */
// Same breakpoint as column_resize_controller.js and the `.is-resized` rules in app.css.
const XL_QUERY = '(min-width: 1200px)';

export default class extends Controller {
    static targets = ['top', 'spacer', 'body'];
    static values = { collapse: Array };

    connect() {
        this.onTopScroll = () => this.mirror(this.topTarget, this.bodyTarget);
        this.onBodyScroll = () => this.mirror(this.bodyTarget, this.topTarget);
        this.topTarget.addEventListener('scroll', this.onTopScroll, { passive: true });
        this.bodyTarget.addEventListener('scroll', this.onBodyScroll, { passive: true });

        this.observer = new ResizeObserver(() => this.update());
        this.observer.observe(this.bodyTarget);
        const table = this.bodyTarget.querySelector('table');
        if (table) {
            this.observer.observe(table);
        }
        this.update();
    }

    disconnect() {
        this.observer?.disconnect();
        this.topTarget.removeEventListener('scroll', this.onTopScroll);
        this.bodyTarget.removeEventListener('scroll', this.onBodyScroll);
    }

    update() {
        this.collapseColumns();
        this.spacerTarget.style.width = `${this.bodyTarget.scrollWidth}px`;
        this.topTarget.hidden = !this.overflows();
        this.topTarget.scrollLeft = this.bodyTarget.scrollLeft;
    }

    /*
     * Shows every collapsible column, then drops them one by one while the table overflows. The
     * forced reflows all happen inside one ResizeObserver callback; the size the observer sees
     * afterwards is the settled one, so this does not re-trigger itself.
     */
    collapseColumns() {
        const table = this.bodyTarget.querySelector('table');
        if (!table || this.collapseValue.length === 0) {
            return;
        }
        for (const name of this.collapseValue) {
            table.classList.remove(`table-collapse-${name}`);
        }
        if (table.classList.contains('is-resized') && window.matchMedia(XL_QUERY).matches) {
            return;
        }
        for (const name of this.collapseValue) {
            if (!this.overflows()) {
                break;
            }
            table.classList.add(`table-collapse-${name}`);
        }
    }

    overflows() {
        return this.bodyTarget.scrollWidth > this.bodyTarget.clientWidth + 1;
    }

    mirror(source, target) {
        // The assignment fires a scroll event on the other container, which lands here with equal
        // positions and stops — no flag needed, and no genuine scroll can be swallowed.
        if (target.scrollLeft !== source.scrollLeft) {
            target.scrollLeft = source.scrollLeft;
        }
    }
}
