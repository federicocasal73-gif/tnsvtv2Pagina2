import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.restoreState();

        const dateInput = document.getElementById('cal-date');
        const countrySel = document.getElementById('cal-country');
        const impactSel = document.getElementById('cal-impact');

        if (!dateInput.value) {
            const now = new Date();
            dateInput.value = now.toISOString().slice(0, 10);
        }

        if (dateInput) dateInput.addEventListener('change', () => this.onFilterChange());
        if (countrySel) countrySel.addEventListener('change', () => this.onFilterChange());
        if (impactSel) impactSel.addEventListener('change', () => this.onFilterChange());

        this.loadEvents();
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    selectedCountries() {
        const countrySel = document.getElementById('cal-country');
        if (!countrySel) return [];
        return Array.from(countrySel.selectedOptions).map(o => o.value);
    }

    pushState() {
        const dateInput = document.getElementById('cal-date');
        const impactSel = document.getElementById('cal-impact');
        const params = new URLSearchParams();
        if (dateInput && dateInput.value) params.set('date', dateInput.value);
        const countries = this.selectedCountries();
        if (countries.length) params.set('countries', countries.join(','));
        if (impactSel && impactSel.value) params.set('impact', impactSel.value);
        const qs = params.toString();
        history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    restoreState() {
        const dateInput = document.getElementById('cal-date');
        const countrySel = document.getElementById('cal-country');
        const impactSel = document.getElementById('cal-impact');
        const params = new URLSearchParams(location.search);

        if (params.has('date') && dateInput) dateInput.value = params.get('date');
        if (params.has('countries') && countrySel) {
            const wanted = params.get('countries').split(',');
            Array.from(countrySel.options).forEach(o => {
                o.selected = wanted.includes(o.value);
            });
        }
        if (params.has('impact') && impactSel) impactSel.value = params.get('impact');
    }

    async loadEvents() {
        const listEl = document.getElementById('cal-list');
        const countEl = document.getElementById('cal-count');
        const countrySel = document.getElementById('cal-country');
        const impactSel = document.getElementById('cal-impact');

        if (!listEl) return;

        listEl.innerHTML = '<tr><td colspan="7" class="text-center text-[var(--outline-elev)] py-12">Cargando eventos...</td></tr>';

        const params = new URLSearchParams();
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Argentina/Buenos_Aires';
        params.set('tz', tz);
        const countries = this.selectedCountries();
        if (countries.length) params.set('countries', countries.join(','));
        if (impactSel && impactSel.value) params.set('impact', impactSel.value);

        try {
            const r = await fetch('/api/calendar/events?' + params.toString());
            const data = await r.json();

            if (!data.ok || !data.data || !data.data.events) {
                listEl.innerHTML = '<tr><td colspan="7" class="text-center text-[var(--outline-elev)] py-12">Sin datos disponibles</td></tr>';
                if (countEl) countEl.textContent = '0 eventos';
                return;
            }

            const events = data.data.events || [];
            if (countEl) countEl.textContent = events.length + ' eventos';

            if (events.length === 0) {
                listEl.innerHTML = '<tr><td colspan="7" class="text-center text-[var(--outline-elev)] py-12">No hay eventos para los filtros seleccionados</td></tr>';
                return;
            }

            listEl.innerHTML = events.map(e => {
                const impact = e.importance || 1;
                const critical = e.is_critical ? 'cal-critical' : '';
                return `
                    <tr class="${critical}">
                        <td class="font-mono">${this.escapeHtml(e.time || '')}</td>
                        <td>${this.escapeHtml(e.currency || e.country_code || '')}</td>
                        <td>${this.escapeHtml(e.title || '')}</td>
                        <td class="cal-impact-${impact}">${'●'.repeat(impact)}${'○'.repeat(3 - impact)}</td>
                        <td>${this.escapeHtml(e.actual || '—')}</td>
                        <td>${this.escapeHtml(e.forecast || '—')}</td>
                        <td>${this.escapeHtml(e.previous || '—')}</td>
                    </tr>
                `;
            }).join('');
        } catch (e) {
            listEl.innerHTML = '<tr><td colspan="7" class="text-center text-[var(--outline-elev)] py-12">Error cargando eventos</td></tr>';
        }
    }

    onFilterChange() {
        this.pushState();
        this.loadEvents();
    }
}
