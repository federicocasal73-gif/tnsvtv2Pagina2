import { Controller } from '@hotwired/stimulus';

/**
 * Leaderboard — loads top 3 (podium) + full ranking on connect.
 * Refreshes every 60s via Mercure subscription (if available).
 *
 * Filters:
 *   - period (data-action="click->leaderboard#setPeriod") — UI only;
 *     only "Total" is currently backed (mes/semana marked Próximamente).
 *   - metric (data-action="change->leaderboard#setMetric") — sorts
 *     client-side by selected metric.
 *
 * Usage: <div data-controller="leaderboard">
 */
export default class extends Controller {
    static targets = ['podium', 'list', 'metricSelect', 'yourRank', 'yourRankPos', 'yourRankName', 'yourRankMetric', 'yourRankBox'];

    static values = {
        refreshInterval: { type: Number, default: 60000 },
    };

    connect() {
        this.metric = 'total_pnl';
        this.all = [];
        this.load();
        this.intervalId = setInterval(() => this.load(), this.refreshIntervalValue);
    }

    disconnect() {
        if (this.intervalId) clearInterval(this.intervalId);
    }

    setMetric(event) {
        this.metric = event.target.value;
        this.render();
    }

    setPeriod(event) {
        const btn = event.currentTarget;
        if (btn.disabled) return;
        this.element.querySelectorAll('.lb-filter').forEach((b) => {
            const active = b === btn;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        // Backend doesn't bucket by period yet — single reload is a no-op
        // for "all" but keeps the hook ready when mes/semana go live.
        this.load();
    }

    async load() {
        try {
            const r = await window.apiFetch('/api/leaderboard?limit=50');
            const data = Array.isArray(r?.data) ? r.data : (Array.isArray(r) ? r : []);
            this.all = Array.isArray(data) ? data : [];
            this.render();
        } catch (e) {
            this.renderError();
        }
    }

    render() {
        if (!this.all || this.all.length === 0) {
            this.renderEmpty();
            return;
        }
        const sorted = this.all.slice().sort((a, b) => {
            const av = Number(a[this.metric] ?? 0);
            const bv = Number(b[this.metric] ?? 0);
            return bv - av;
        });
        this.renderPodium(sorted.slice(0, 3));
        this.renderList(sorted);
        this.renderYourRank(sorted);
    }

    // L72: surface the current user's position with their chosen-metric score.
    renderYourRank(sorted) {
        const me = window.TNSVT_USER && window.TNSVT_USER.code;
        const box = document.getElementById('lb-your-rank');
        if (!box || !me) return;
        const idx = sorted.findIndex(p => String(p.code) === String(me));
        if (idx === -1) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        const pos = idx + 1;
        const entry = sorted[idx];
        this.yourRankPosTarget.textContent = '#' + pos;
        this.yourRankNameTarget.textContent = entry.name || entry.code;
        this.yourRankMetricTarget.textContent = this.scoreFor(entry);
    }

    renderEmpty() {
        this.podiumTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-3">Sin datos aún.</p>';
        this.listTarget.innerHTML = '';
    }

    renderError() {
        this.podiumTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-3">Error al cargar.</p>';
    }

    scoreFor(p) {
        const v = Number(p[this.metric] ?? 0);
        if (this.metric === 'win_rate' || this.metric === 'total_trades') return v.toString();
        if (this.metric === 'profit_factor') return v.toFixed(2);
        return '$' + v.toFixed(2);
    }

    renderPodium(top3) {
        const podiumHTML = top3.map((p) => `
            <div class="glass-card-elev podium-card">
                <div class="podium-avatar">${this.escape((p.name || p.code || '?').charAt(0))}</div>
                <div class="podium-name">${this.escape(p.name || p.code || '')}</div>
                <div class="podium-score">${this.scoreFor(p)}</div>
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
            return `
                <div class="lb-rank">
                    <span class="lb-pos ${medal}">${i + 1}</span>
                    <span class="lb-avatar-mini">${initial}</span>
                    <span class="lb-name">${name}</span>
                    <span class="lb-score">${this.scoreFor(p)}</span>
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