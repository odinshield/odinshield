// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';
import { Modal } from '@tabler/core';

// Drives the vulnerability bulk-close selection: row checkboxes, the sticky bar,
// the select-all header checkbox and the confirmation modal. The checkboxes are
// pure selection UI — on confirm the selected UUIDs are copied into the modal form
// as hidden inputs, so the form submit (and its turbo-stream response) is self-contained.
export default class extends Controller {
    static targets = ['selectAll', 'checkbox', 'bar', 'label', 'dialog', 'uuids', 'summary', 'params', 'target'];

    toggleAll() {
        const checked = this.selectAllTarget.checked;
        for (const checkbox of this.checkboxTargets) {
            checkbox.checked = checked;
        }
        this.update();
    }

    toggle() {
        this.update();
    }

    update() {
        const selected = this.checkboxTargets.filter((checkbox) => checkbox.checked);
        const count = selected.length;

        if (this.hasBarTarget) {
            this.barTarget.classList.toggle('d-none', count === 0);
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = this._fill(this.labelTarget, count);
        }
        if (this.hasSelectAllTarget) {
            const total = this.checkboxTargets.length;
            const allChecked = total > 0 && count === total;
            this.selectAllTarget.checked = allChecked;
            this.selectAllTarget.indeterminate = count > 0 && !allChecked;
        }
    }

    clear() {
        for (const checkbox of this.checkboxTargets) {
            checkbox.checked = false;
        }
        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = false;
            this.selectAllTarget.indeterminate = false;
        }
        this.update();
    }

    openModal() {
        const uuids = this.checkboxTargets.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value);
        if (uuids.length === 0) {
            return;
        }

        this.uuidsTarget.replaceChildren();
        for (const uuid of uuids) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'uuids[]';
            input.value = uuid;
            this.uuidsTarget.append(input);
        }

        this._count = uuids.length;
        this.updateSummary();

        // Re-sync the list-filter params from the live URL so the post-submit redirect lands on
        // the currently-filtered list, not the frozen params from the initial page render (L18).
        if (this.hasParamsTarget) {
            this.paramsTarget.replaceChildren();
            for (const [key, value] of new URLSearchParams(window.location.search)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                this.paramsTarget.append(input);
            }
        }

        (Modal.getInstance(this.dialogTarget) || new Modal(this.dialogTarget)).show();
    }

    onSubmitEnd(event) {
        if (event.detail?.success) {
            this.clear();
        }
    }

    _fill(element, count) {
        return (element.dataset.template || '').replace('__count__', String(count));
    }

    // #203: "Deferred" postpones the findings, every other target closes them — say which.
    updateSummary() {
        if (!this.hasSummaryTarget) {
            return;
        }
        const defers = this.hasTargetTarget && this.targetTarget.value === 'deferred';
        const count = this._count ?? 0;
        // The template renders both plural forms; German and English share the one/other rule.
        const form = count === 1 ? 'One' : 'Other';
        const template = this.summaryTarget.dataset[`template${defers ? 'Defer' : 'Close'}${form}`];
        this.summaryTarget.textContent = (template || '').replace('__count__', String(count));
    }
}
