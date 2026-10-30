// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

import { Controller } from '@hotwired/stimulus';

// #207 (UX-44): the search is a modal dialog — Tab stays inside it while it is open, closing
// (Escape, scrim, close button) returns focus to where it was, and the result count is announced
// through a status region instead of the whole list. The shortcut hint names the viewer's OS.
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

export default class extends Controller {
    static targets = ['modal', 'dialog', 'input', 'results', 'opener', 'status'];

    static values = {
        loadingText: { type: String, default: 'Searching…' },
        noResultsText: { type: String, default: 'No results found' },
        unavailableText: { type: String, default: 'Search currently unavailable' },
        unavailableHint: { type: String, default: '' },
        resultOneText: { type: String, default: '1 result' },
        resultManyText: { type: String, default: '%count% results' },
        shortcut: { type: String, default: 'Ctrl+K' },
        shortcutMac: { type: String, default: 'Cmd+K' },
    };

    pagefind = null;
    debounceTimer = null;
    returnFocus = null;

    connect() {
        this.boundKeydown = this.keydown.bind(this);
        window.addEventListener('keydown', this.boundKeydown);
        this.labelOpener();
    }

    disconnect() {
        window.removeEventListener('keydown', this.boundKeydown);
    }

    labelOpener() {
        if (!this.hasOpenerTarget) return;
        const platform = navigator.userAgentData?.platform || navigator.platform || '';
        const mac = /mac|iphone|ipad/i.test(platform);
        // Built from the server title kept aside on first connect: a page restored from Turbo's
        // cache already carries the extended title and would get the shortcut twice.
        this.openerTarget.dataset.baseTitle ??= this.openerTarget.title;
        this.openerTarget.title = `${this.openerTarget.dataset.baseTitle} (${mac ? this.shortcutMacValue : this.shortcutValue})`;
        this.openerTarget.setAttribute('aria-keyshortcuts', mac ? 'Meta+K' : 'Control+K');
    }

    keydown(event) {
        if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
            event.preventDefault();
            this.open();
            return;
        }
        if (event.key === 'Tab' && this.isOpen()) {
            this.trapTab(event);
        }
    }

    trapTab(event) {
        const items = [...this.findModal().querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
        if (items.length === 0) return;
        const first = items[0];
        const last = items[items.length - 1];
        const inside = this.findModal().contains(document.activeElement);
        if (event.shiftKey && (document.activeElement === first || !inside)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
            event.preventDefault();
            first.focus();
        }
    }

    isOpen() {
        return this.findModal()?.classList.contains('open') ?? false;
    }

    open(event) {
        event?.preventDefault();
        const modal = this.findModal();
        if (!modal || this.isOpen()) return;
        this.returnFocus = document.activeElement;
        this.unavailableAnnounced = false;
        modal.classList.add('open');
        const input = modal.querySelector('.manual-search-input');
        setTimeout(() => input?.focus(), 50);
        this.loadPagefind().then(() => {
            if (false === this.pagefind) {
                this.renderUnavailable();
            }
        });
    }

    close(event) {
        const modal = this.findModal();
        if (!modal || !this.isOpen()) return;
        // Marks the Escape as used, so the drawer (manual-nav) leaves it alone whichever window
        // listener runs first.
        event?.preventDefault?.();
        modal.classList.remove('open');
        this.returnFocus?.focus?.();
        // The element may no longer take focus (e.g. a link in the drawer that closed — and went
        // inert — when focus moved into the search); then the search button is the way back.
        if (document.activeElement !== this.returnFocus && this.hasOpenerTarget) {
            this.openerTarget.focus();
        }
        this.returnFocus = null;
    }

    announce(text) {
        if (!this.hasStatusTarget) return;
        // Cleared first so the same text (two searches without hits) is a change again.
        this.statusTarget.textContent = '';
        setTimeout(() => {
            this.statusTarget.textContent = text;
        }, 100);
    }

    scrimClick(event) {
        const modal = this.findModal();
        if (modal && event.target === modal) {
            this.close();
        }
    }

    findModal() {
        return this.hasModalTarget ? this.modalTarget : document.querySelector('.manual-search-modal');
    }

    async loadPagefind() {
        if (this.pagefind) return;
        if (false === this.pagefind) return;
        try {
            this.pagefind = await import('/pagefind/pagefind.js');
            await this.pagefind.options({
                baseUrl: '/manual/',
                excerptLength: 30,
            });
        } catch (_e) {
            this.pagefind = false;
        }
    }

    search(event) {
        const term = event.target.value.trim();
        clearTimeout(this.debounceTimer);
        if (!term) {
            // Restore the unavailable hint if pagefind is known to be unavailable;
            // otherwise just clear the results area.
            if (false === this.pagefind) {
                this.renderUnavailable();
            } else {
                this.resultsTarget.replaceChildren();
            }
            return;
        }
        this.debounceTimer = setTimeout(() => this.runSearch(term), 200);
    }

    async runSearch(term) {
        await this.loadPagefind();
        if (false === this.pagefind) {
            this.renderUnavailable();
            return;
        }
        this.renderLoading();
        try {
            const result = await this.pagefind.search(term);
            const items = await Promise.all(result.results.slice(0, 10).map((r) => r.data()));
            this.renderResults(items, result.results.length);
        } catch (_e) {
            this.renderUnavailable();
        }
    }

    renderLoading() {
        this.renderMessage(this.loadingTextValue, 'manual-search-loading');
    }

    renderEmpty() {
        this.renderMessage(this.noResultsTextValue, 'manual-search-empty');
        this.announce(this.noResultsTextValue);
    }

    renderUnavailable() {
        const wrap = document.createElement('div');
        wrap.className = 'manual-search-unavailable';

        const title = document.createElement('div');
        title.className = 'manual-search-unavailable-title';
        title.textContent = this.unavailableTextValue;
        wrap.appendChild(title);

        if (this.unavailableHintValue) {
            const hint = document.createElement('div');
            hint.className = 'manual-search-unavailable-hint';
            hint.textContent = this.unavailableHintValue;
            wrap.appendChild(hint);
        }

        this.resultsTarget.replaceChildren(wrap);
        // Once per opening, with the hint: every keystroke re-renders this message.
        if (!this.unavailableAnnounced) {
            this.unavailableAnnounced = true;
            this.announce([this.unavailableTextValue, this.unavailableHintValue].filter(Boolean).join('. '));
        }
    }

    renderMessage(text, className) {
        const div = document.createElement('div');
        div.className = className;
        div.textContent = text;
        this.resultsTarget.replaceChildren(div);
    }

    renderResults(items, total = items.length) {
        if (items.length === 0) {
            this.renderEmpty();
            return;
        }

        const fragment = document.createDocumentFragment();
        items.forEach((item) => {
            const link = document.createElement('a');
            link.className = 'manual-search-result';
            link.href = this.sanitizeUrl(item.url);

            const title = document.createElement('div');
            title.className = 'manual-search-result-title';
            title.textContent = item.meta?.title || item.url || '';

            const snippet = document.createElement('div');
            snippet.className = 'manual-search-result-snippet';
            this.renderExcerptInto(snippet, item.excerpt || '');

            link.append(title, snippet);
            fragment.appendChild(link);
        });
        this.resultsTarget.replaceChildren(fragment);
        // The number of hits, not of the (at most ten) rendered entries.
        this.announce(
            total === 1 ? this.resultOneTextValue : this.resultManyTextValue.replace('%count%', String(total)),
        );
    }

    /**
     * Normalizes and validates URLs returned by Pagefind.
     *
     * Pagefind constructs URLs from the indexed file path
     * (.pagefind-build/manual/{de,en}/{slug}.html) — combined with the baseUrl
     * '/manual/' this yields /manual/{de,en}/{slug}.html. The Symfony route is
     * /manual/{slug} (locale-agnostic), so we strip the locale segment and the
     * .html suffix here.
     *
     * Defence in depth: also rejects javascript:/data: pseudo-protocols and
     * anything outside the /manual/* path, in case the index ever gets poisoned.
     */
    sanitizeUrl(url) {
        if (typeof url !== 'string' || url === '') {
            return '#';
        }
        if (url === '#' || url.startsWith('#')) {
            return url;
        }
        // Strip locale prefix injected by Pagefind's path-based URL inference.
        let normalized = url.replace(/^\/manual\/(?:de|en)\//, '/manual/');
        // Strip .html suffix; preserve any fragment.
        normalized = normalized.replace(/\.html(?=$|[?#])/, '');
        // Strict allowlist: must be a relative path starting with /manual/.
        if (/^\/manual\/[a-z0-9/?#&=._%-]*$/i.test(normalized)) {
            return normalized;
        }
        return '#';
    }

    /**
     * Renders a Pagefind excerpt into the target element. The excerpt may contain
     * <mark> tags around matched terms; everything else is treated as text. Uses
     * String.matchAll + textContent — never innerHTML — so even a poisoned index
     * cannot inject script content.
     */
    renderExcerptInto(target, excerpt) {
        const matches = [...excerpt.matchAll(/<mark>([\s\S]*?)<\/mark>/g)];
        let cursor = 0;
        for (const m of matches) {
            if (m.index > cursor) {
                target.appendChild(document.createTextNode(excerpt.slice(cursor, m.index)));
            }
            const mark = document.createElement('mark');
            mark.textContent = m[1];
            target.appendChild(mark);
            cursor = m.index + m[0].length;
        }
        if (cursor < excerpt.length) {
            target.appendChild(document.createTextNode(excerpt.slice(cursor)));
        }
    }
}
