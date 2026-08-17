import { Controller } from '@hotwired/stimulus';
import { Turbo } from '@hotwired/turbo';

/**
 * Calendar events — listens to filter changes, fetches updated fragment, swaps via Turbo Frame.
 * Replaces the original fetch-and-innerHTML approach with declarative Turbo Frame updates.
 *
 * Usage:
 *   <div data-controller="calendar-events">
 *     <turbo-frame id="cal-events-frame">
 *       ... table ...
 *     </turbo-frame>
 *   </div>
 *
 * Filters (date/country/impact) should be inside the controller element with
 * data-action="change->calendar-events#refresh".
 */
export default class extends Controller {
    static targets = ['frame'];

    static values = {
        url: String,
    };

    connect() {
        this.loadInitial();
    }

    async loadInitial() {
        try {
            const response = await fetch(this.buildUrl());
            const html = await response.text();
            this.replaceFrameContent(html);
        } catch (e) {
            console.warn('Calendar initial load failed:', e);
        }
    }

    refresh() {
        this.loadInitial();
    }

    buildUrl() {
        const params = new URLSearchParams();
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Argentina/Buenos_Aires';
        params.set('tz', tz);

        const dateInput = this.element.querySelector('#cal-date');
        const countrySel = this.element.querySelector('#cal-country');
        const impactSel = this.element.querySelector('#cal-impact');

        if (dateInput?.value) params.set('date', dateInput.value);
        const countries = Array.from(countrySel?.selectedOptions || []).map((o) => o.value);
        if (countries.length) params.set('countries', countries.join(','));
        if (impactSel?.value) params.set('impact', impactSel.value);

        return `${this.urlValue}?${params.toString()}`;
    }

    replaceFrameContent(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newFrame = doc.querySelector('turbo-frame');
        if (newFrame) {
            const current = this.element.querySelector('turbo-frame');
            if (current) current.replaceWith(newFrame);
        }
    }
}