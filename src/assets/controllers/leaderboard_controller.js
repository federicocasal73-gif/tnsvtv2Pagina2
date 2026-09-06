import { Controller } from '@hotwired/stimulus';

/**
 * Leaderboard — loads top 3 (podium) + full ranking on connect.
 * Refreshes every 60s via Mercure subscription (if available).
 *
 * Usage: <div data-controller="leaderboard">
 */
export default class extends Controller {
    static targets = ['podium', 'list'];

    static values = {
        refreshInterval: { type: Number, default: 60000 },
    };

    connect() {
        this.load();
        this.intervalId = setInterval(() => this.load(), this.refreshIntervalValue);
    }

    disconnect() {
        if (this.intervalId) clearInterval(this.intervalId);
    }

    async load() {
        try {
            const r = await window.apiFetch('/api/leaderboard?limit=50');
            const data = Array.isArray(r?.data) ? r.data : (Array.isArray(r) ? r : []);
            if (data.length === 0) {
                this.renderEmpty();
                return;
            }
            this.renderPodium(data.slice(0, 3));
            this.renderList(data);
        } catch (e) {
            this.renderError();
        }
    }

    renderEmpty() {
        this.podiumTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-3">Sin datos aún.</p>';
        this.listTarget.innerHTML = '';
    }

    renderError() {
        this.podiumTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-3">Error al cargar.</p>';
    }

    renderPodium(top3) {
        const podiumHTML = top3.map((p) => `
            <div class="glass-card-elev podium-card">
                <div class="podium-avatar">${this.escape((p.name || p.code || '?').charAt(0))}</div>
                <div class="podium-name">${this.escape(p.name || p.code || '')}</div>
                <div class="podium-score">${(p.total_pnl ?? 0).toFixed ? '$' + p.total_pnl.toFixed(2) : '$' + (p.total_pnl || 0)}</div>
            </div>
        `).join('');
        this.podiumTarget.innerHTML = podiumHTML;
    }

    renderList(all) {
        const medals = ['gold', 'silver', 'bronze'];
        const listHTML = all.map((p, i) => {
            const medal = medals[i] || '';
            const initial = this.escape((p.name || p.code || '?').charAt(0));
            const name = this.escape(p.name || p.code || '');
            const score = p.total_pnl ?? 0;
            return `
                <div class="lb-rank">
                    <span class="lb-pos ${medal}">${i + 1}</span>
                    <span class="lb-avatar-mini">${initial}</span>
                    <span class="lb-name">${name}</span>
                    <span class="lb-score">$${Number(score).toFixed(2)}</span>
                </div>
            `;
        }).join('');
        this.listTarget.innerHTML = listHTML;
    }

    escape(str) {
        return String(str ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}