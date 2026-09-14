import { Controller } from '@hotwired/stimulus';

/**
 * Oráculo de Métricas — gauges + emotional-bias scatter + session
 * performance bar chart. Three pure-SVG renderers, no charting lib.
 *
 * Extracted from templates/oracle/dashboard.html.twig inline <script>
 * (P10 / F10 commit C8). Same D3-less SVG approach, same endpoints.
 *
 * Endpoints:
 *   /sanctum/api/oracle/faith-logic?code=…&days=…
 *   /sanctum/api/oracle/emotional-bias?code=…&days=…
 *   /sanctum/api/oracle/session-performance?code=…&days=…
 */
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[m]));
}

export default class extends Controller {
    static targets = [
        'userCode', 'daysSelect', 'refreshBtn', 'verMapaBtn', 'loadingStatus',
        'gaugeSvg', 'logicPercent', 'gaugeMeta',
        'biasSvg', 'biasMeta',
        'perfSvg',
    ];

    connect() {
        this.loadAll();
    }

    refresh() { this.loadAll(); }

    selectDays() { this.loadAll(); }

    submitOnEnter(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.loadAll();
        }
    }

    scrollToMapa() {
        if (this.hasGaugeSvgTarget) {
            this.gaugeSvgTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    async loadAll() {
        // L40: default to the logged-in user.
        if (this.hasUserCodeTarget && !this.userCodeTarget.value.trim()) {
            if (window.TNSVT_USER && window.TNSVT_USER.code) {
                this.userCodeTarget.value = window.TNSVT_USER.code;
            }
        }
        const userCode = this.hasUserCodeTarget ? this.userCodeTarget.value.trim() : '';
        const days = this.hasDaysSelectTarget ? parseInt(this.daysSelectTarget.value, 10) : 30;

        if (this.hasLoadingStatusTarget) {
            this.loadingStatusTarget.textContent = 'Cargando...';
        }

        try {
            const [gaugeRes, biasRes, perfRes] = await Promise.all([
                fetch(`/sanctum/api/oracle/faith-logic?code=${encodeURIComponent(userCode)}&days=${days}`),
                fetch(`/sanctum/api/oracle/emotional-bias?code=${encodeURIComponent(userCode)}&days=${days}`),
                fetch(`/sanctum/api/oracle/session-performance?code=${encodeURIComponent(userCode)}&days=${days}`),
            ]);
            const gaugeJson = await gaugeRes.json();
            const biasJson  = await biasRes.json();
            const perfJson  = await perfRes.json();

            this.renderGauge(gaugeJson.gauge || {});
            this.renderBiasMap(biasJson);
            this.renderPerformance(perfJson);

            if (this.hasLoadingStatusTarget) {
                this.loadingStatusTarget.textContent = 'Última actualización: '
                    + new Date().toLocaleTimeString();
            }
        } catch (e) {
            console.error(e);
            if (this.hasLoadingStatusTarget) {
                this.loadingStatusTarget.textContent = 'Error: ' + e.message;
            }
        }
    }

    renderGauge(g) {
        if (!this.hasGaugeSvgTarget) return;
        const svg = this.gaugeSvgTarget;
        const cx = 110, cy = 110, r = 90;
        let html = '';
        html += `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none"
            stroke="var(--glass-border-elev)" stroke-width="16" />`;
        const logicPct = g.logic || 0;
        const circ = 2 * Math.PI * r;
        const arcLen = circ * 0.75;
        html += `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="var(--gold-elev)" stroke-width="16"
            stroke-dasharray="${(logicPct / 100) * arcLen} ${arcLen}"
            transform="rotate(135 ${cx} ${cy})" stroke-linecap="round" />`;
        html += `<text x="${cx}" y="${cy + 5}" text-anchor="middle" font-size="36" font-weight="bold" fill="var(--gold-elev)">${logicPct}</text>`;
        html += `<text x="${cx}" y="${cy + 30}" text-anchor="middle" font-size="11" fill="var(--outline-elev)">LOGIC</text>`;
        html += `<text x="35" y="200" text-anchor="start" font-size="10" fill="var(--outline-elev)">Faith</text>`;
        html += `<text x="185" y="200" text-anchor="end" font-size="10" fill="var(--outline-elev)">Cold Logic</text>`;
        svg.innerHTML = html;

        if (this.hasLogicPercentTarget) this.logicPercentTarget.textContent = logicPct + '%';
        if (this.hasGaugeMetaTarget) {
            this.gaugeMetaTarget.textContent =
                `${g.total} trades · ${g.win_rate}% win rate · asset favorito: ${g.preferred_asset || '-'}`;
        }
    }

    renderBiasMap(d) {
        if (!this.hasBiasSvgTarget) return;
        const svg = this.biasSvgTarget;
        const w = 600, h = 280;
        const points = d.points || [];
        let html = '';
        html += `<line x1="40" y1="${h - 30}" x2="${w - 20}" y2="${h - 30}" stroke="var(--outline-variant-elev)" stroke-width="0.5" />`;
        html += `<line x1="40" y1="20" x2="40" y2="${h - 30}" stroke="var(--outline-variant-elev)" stroke-width="0.5" />`;
        html += `<text x="10" y="20" font-size="10" fill="var(--outline-elev)">Analytical</text>`;
        html += `<text x="${w - 80}" y="${h - 10}" font-size="10" fill="var(--outline-elev)">Emotional</text>`;
        for (const p of points) {
            const x = 40 + (p.analytical * (w - 60));
            const y = (h - 30) - (p.emotional * (h - 50));
            const win = p.result === 'WIN';
            const color = win ? 'var(--gold-elev)' : '#ff6b6b';
            const rDot = Math.max(3, Math.min(10, Math.abs(p.pnl) / 50 + 3));
            html += `<circle cx="${x}" cy="${y}" r="${rDot}" fill="${color}" fill-opacity="0.6"
                stroke="${color}" stroke-width="0.5">
                <title>${escapeHtml(p.date)} ${escapeHtml(p.asset)}
                ${escapeHtml(p.result)} PnL: ${escapeHtml(p.pnl)}</title>
            </circle>`;
        }
        svg.innerHTML = html;

        if (this.hasBiasMetaTarget) {
            const winCount   = points.filter((p) => p.result === 'WIN').length;
            const lossCount  = points.filter((p) => p.result === 'LOSS' || p.result === 'BE').length;
            this.biasMetaTarget.textContent =
                `${points.length} trades · ${winCount} wins · ${lossCount} losses/BE`;
        }
    }

    renderPerformance(d) {
        if (!this.hasPerfSvgTarget) return;
        const svg = this.perfSvgTarget;
        const w = 800, h = 240;
        const days = d.days || [];
        if (days.length === 0) {
            svg.innerHTML = `<text x="400" y="120" text-anchor="middle" font-size="14"
                fill="var(--outline-elev)">Sin trades en el rango seleccionado</text>`;
            return;
        }
        const pnls = days.map((dd) => dd.pnl);
        const maxAbs = Math.max(100, ...pnls.map(Math.abs));
        const yScale = (val) => h - 30 - (val / maxAbs) * (h - 50) / 2 - (h / 2 - 30);
        const zeroY = h - 30;
        const xStep = (w - 80) / Math.max(1, days.length - 1);

        let html = '';
        html += `<line x1="40" y1="${zeroY}" x2="${w - 20}" y2="${zeroY}"
            stroke="var(--outline-variant-elev)" stroke-width="0.5" stroke-dasharray="2,2" />`;
        for (let i = 0; i < days.length; i++) {
            const day = days[i];
            if (day.pnl >= 0) {
                const x = 40 + i * xStep;
                const y = zeroY - (day.pnl / maxAbs) * (h - 50) / 2;
                const height = zeroY - y;
                html += `<rect x="${x - 4}" y="${y}" width="8" height="${height}"
                    fill="var(--gold-elev)" fill-opacity="0.7" rx="1">
                    <title>${escapeHtml(day.date)} ${day.trades}t PnL: ${day.pnl}
                    ${day.wins}W ${day.losses}L</title></rect>`;
            }
        }
        for (let i = 0; i < days.length; i++) {
            const day = days[i];
            if (day.pnl < 0) {
                const x = 40 + i * xStep;
                const y = zeroY;
                const height = (Math.abs(day.pnl) / maxAbs) * (h - 50) / 2;
                html += `<rect x="${x - 4}" y="${y}" width="8" height="${height}"
                    fill="#ff6b6b" fill-opacity="0.7" rx="1">
                    <title>${escapeHtml(day.date)} ${day.trades}t PnL: ${day.pnl}
                    ${day.wins}W ${day.losses}L</title></rect>`;
            }
        }
        if (days.length > 0) {
            html += `<text x="40" y="${h - 12}" font-size="9" fill="var(--outline-elev)">${days[0].date.substring(5)}</text>`;
            if (days.length > 2) {
                html += `<text x="${40 + (days.length - 1) / 2 * xStep}" y="${h - 12}" font-size="9"
                    fill="var(--outline-elev)" text-anchor="middle">${days[Math.floor(days.length / 2)].date.substring(5)}</text>`;
            }
            html += `<text x="${40 + (days.length - 1) * xStep}" y="${h - 12}" font-size="9"
                fill="var(--outline-elev)" text-anchor="end">${days[days.length - 1].date.substring(5)}</text>`;
        }
        svg.innerHTML = html;
    }
}
