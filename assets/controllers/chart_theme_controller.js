// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

// #207 (UX-39): Chart.js bakes colours in at render time, so the fixed blue-grey of L20 stayed
// at ~3:1 on the light card. Here axis and legend text take --tblr-secondary-color (measured
// 4.95:1 light, 7.38:1 dark on the card surface), the grid --tblr-border-color, and both are
// re-applied whenever the theme toggle swaps data-bs-theme. Mounted on a wrapper around
// render_chart(): the ux-chartjs controller announces its chart via the bubbling chartjs:connect.
export default class extends Controller {
    #chart = null;
    #observer = null;
    #onConnect = (event) => {
        this.#chart = event.detail.chart;
        this.#apply();
    };

    connect() {
        this.element.addEventListener('chartjs:connect', this.#onConnect);
        this.#observer = new MutationObserver(() => this.#apply());
        this.#observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }

    disconnect() {
        this.element.removeEventListener('chartjs:connect', this.#onConnect);
        this.#observer?.disconnect();
        this.#chart = null;
    }

    #apply() {
        const chart = this.#chart;
        if (!chart) return;

        const text = this.#resolve('--tblr-secondary-color');
        const grid = this.#resolve('--tblr-border-color');
        chart.options.plugins.legend.labels.color = text;
        for (const axis of Object.values(chart.options.scales)) {
            axis.ticks.color = text;
            axis.grid.color = grid;
        }
        chart.update('none');
    }

    // Tabler defines its colours in oklch/oklab; read them back as an rgba() pixel so Chart.js's
    // own colour parser never has to understand them.
    #resolve(variable) {
        const probe = document.createElement('span');
        probe.style.color = `var(${variable})`;
        this.element.append(probe);
        const css = getComputedStyle(probe).color;
        probe.remove();

        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.fillStyle = css;
        ctx.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;

        return `rgba(${r}, ${g}, ${b}, ${Math.round((a / 255) * 100) / 100})`;
    }
}
