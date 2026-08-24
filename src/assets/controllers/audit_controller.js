import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'pagination', 'total', 'success', 'failed'];
    static values = { page: Number, perPage: Number };

    constructor() {
        super(...arguments);
        this.page = 1;
        this.perPage = 25;
        this.filter = { result: '', search: '' };
    }

    connect() {
        this.loadAudit();
        this.loadStats();

        this._refreshBtn = document.getElementById('refresh-btn');
        this._filterResult = document.getElementById('filter-result');
        this._filterSearch = document.getElementById('filter-search');

        if (this._refreshBtn) {
            this._refreshBtn.addEventListener('click', () => this.loadAudit());
        }
        if (this._filterResult) {
            this._filterResult.addEventListener('change', (e) => {
                this.filter.result = e.target.value;
                this.page = 1;
                this.loadAudit();
            });
        }
        if (this._filterSearch) {
            this._filterSearch.addEventListener('input', (e) => {
                this.filter.search = e.target.value;
                this.loadAudit();
            });
        }
    }

    async loadAudit() {
        const list = document.getElementById('entries-list');
        if (!list) return;

        list.classList.add('loading-pulse');
        try {
            let url = `/sanctum/api/audit?page=${this.page}&per_page=${this.perPage}`;
            if (this.filter.result) url += `&result=${encodeURIComponent(this.filter.result)}`;

            const r = await fetch(url);
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const data = await r.json();
            if (!data.success) throw new Error(data.error || 'unknown');
            this.renderEntries(data.entries, data.pagination);
        } catch (e) {
            list.innerHTML = `<p class="text-red-400 text-center py-8">Error: ${this.escapeHtml(e.message)}</p>`;
        } finally {
            list.classList.remove('loading-pulse');
        }
    }

    async loadStats() {
        try {
            const r = await fetch(`/sanctum/api/audit?page=1&per_page=1000`);
            if (!r.ok) return;
            const data = await r.json();
            if (!data.success) return;

            const total = data.entries.length;
            const success = data.entries.filter(e => e.result === 'success').length;
            const failed = data.entries.filter(e => e.result === 'fail').length;

            const elTotal = document.getElementById('stat-total');
            const elSuccess = document.getElementById('stat-success');
            const elFailed = document.getElementById('stat-failed');

            if (elTotal) elTotal.textContent = total;
            if (elSuccess) elSuccess.textContent = success;
            if (elFailed) elFailed.textContent = failed;
        } catch (e) {}
    }

    renderEntries(entries, pagination) {
        const list = document.getElementById('entries-list');
        if (!list) return;

        if (!entries || entries.length === 0) {
            list.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin entradas de audit log</p>';
            const elPag = document.getElementById('pagination');
            if (elPag) elPag.innerHTML = '';
            return;
        }

        const filtered = this.filter.search
            ? entries.filter(e => e.action.toLowerCase().includes(this.filter.search.toLowerCase()))
            : entries;

        list.innerHTML = filtered.map(e => {
            const iconClass = e.result === 'success' ? 'success' : 'fail';
            const iconName = e.result === 'success' ? 'check_circle' : 'cancel';
            return `
            <div class="audit-entry">
                <div class="audit-icon ${iconClass}">
                    <span class="material-symbols-elev">${iconName}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="action-tag">${this.escapeHtml(e.action)}</span>
                        <span class="status-pill ${e.result}">${e.result}</span>
                        ${e.admin && e.admin.startsWith('ADMIN') ? '<span class="status-pill admin">ADMIN</span>' : ''}
                    </div>
                    <p class="text-xs text-[var(--outline-elev)] mt-1">
                        <span class="font-mono">${this.escapeHtml(e.admin || '—')}</span>
                        ${e.ip ? '<span class="ml-2">· ' + this.escapeHtml(e.ip) + '</span>' : ''}
                    </p>
                </div>
                <p class="text-xs text-[var(--outline-elev)] font-mono whitespace-nowrap">${(e.time || '').substring(0, 16).replace('T', ' ')}</p>
            </div>`;
        }).join('');

        this.renderPagination(pagination);
    }

    renderPagination(p) {
        const elPag = document.getElementById('pagination');
        if (!elPag) return;

        if (!p || p.pages <= 1) {
            elPag.innerHTML = '';
            return;
        }

        const html = [];
        if (p.page > 1) {
            html.push(`<button class="btn-elev-ghost text-xs" data-action="click->audit#goToPage" data-page="${p.page - 1}">← Anterior</button>`);
        }
        html.push(`<span class="px-3 text-[var(--var(--on-surface-elev))]">Página ${p.page} de ${p.pages} · ${p.total} total</span>`);
        if (p.page < p.pages) {
            html.push(`<button class="btn-elev-ghost text-xs" data-action="click->audit#goToPage" data-page="${p.page + 1}">Siguiente →</button>`);
        }
        elPag.innerHTML = html.join('');
    }

    goToPage(e) {
        this.page = parseInt(e.target.dataset.page);
        this.loadAudit();
    }

    escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
}
