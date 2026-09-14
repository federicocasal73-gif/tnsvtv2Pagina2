import { Controller } from '@hotwired/stimulus';

/**
 * Guardian page — fetches signals + score from the API and renders them.
 *
 * Extracted from templates/sanctum/guardian.html.twig inline <script>
 * (P10 / F10 commit C5). Same render output, same endpoints, same escape
 * strategy. No public surface change.
 */
const SEVERITY_RANK = { danger: 3, warning: 2, info: 1 };
const SEVERITY_VISUAL = {
    danger:  { icon: 'error',   pillClass: 'status-inactive', color: '#f87171' },
    warning: { icon: 'warning', pillClass: 'status-pending',  color: 'var(--gold-elev)' },
    info:    { icon: 'info',    pillClass: 'status-active',   color: 'var(--violet)' },
};
const TIER_VISUAL = {
    elite:   { label: 'ELITE',   color: '#ffe088' },
    strong:  { label: 'STRONG',  color: 'var(--gold-elev)' },
    steady:  { label: 'STEADY',  color: 'var(--violet)' },
    caution: { label: 'CAUTION', color: 'var(--gold-elev)' },
    risk:    { label: 'RISK',    color: '#f87171' },
};

export default class extends Controller {
    static targets = [
        'refreshBtn', 'lastRefresh', 'loadingState', 'errorState',
        'content', 'scoreValue', 'scoreTier', 'scoreBreakdown', 'scoreComputedAt',
        'signalsList', 'signalsEmpty', 'signalCountBadge',
    ];

    connect() {
        if (this.hasRefreshBtnTarget) {
            this.refreshBtnTarget.addEventListener('click', () => this.load());
        }
        if (document.readyState !== 'loading') {
            this.load();
        } else {
            document.addEventListener('DOMContentLoaded', () => this.load(), { once: true });
        }
    }

    refresh() { this.load(); }

    async load() {
        if (this.hasErrorStateTarget) this.errorStateTarget.hidden = true;

        const [signalsRes, scoreRes] = await Promise.all([
            window.apiFetch('/api/guardian/signals', { silent: true }),
            window.apiFetch('/api/guardian/score',   { silent: true }),
        ]);

        if (!signalsRes.ok || !scoreRes.ok) {
            if (this.hasLoadingStateTarget) this.loadingStateTarget.hidden = true;
            if (this.hasErrorStateTarget) this.errorStateTarget.hidden = false;
            return;
        }

        this.renderScore(scoreRes.data);
        this.renderSignals(signalsRes.data.signals || []);

        if (this.hasLoadingStateTarget) this.loadingStateTarget.hidden = true;
        if (this.hasContentTarget) this.contentTarget.hidden = false;
        if (this.hasLastRefreshTarget) {
            this.lastRefreshTarget.textContent = new Date().toLocaleTimeString();
        }
    }

    renderScore(data) {
        if (this.hasScoreValueTarget) this.scoreValueTarget.textContent = (data.score ?? 0);

        const tierVisual = TIER_VISUAL[data.tier] || { label: '—', color: 'var(--outline-elev)' };
        if (this.hasScoreTierTarget) {
            this.scoreTierTarget.textContent = tierVisual.label;
            this.scoreTierTarget.style.color = tierVisual.color;
            this.scoreTierTarget.style.borderColor = tierVisual.color;
        }

        if (this.hasScoreValueTarget) {
            if (data.score >= 75)        this.scoreValueTarget.style.color = tierVisual.color;
            else if (data.score >= 40)   this.scoreValueTarget.style.color = 'var(--gold-elev)';
            else                          this.scoreValueTarget.style.color = '#f87171';
        }

        if (this.hasScoreBreakdownTarget) {
            if (Array.isArray(data.breakdown) && data.breakdown.length > 0) {
                this.scoreBreakdownTarget.innerHTML = data.breakdown.map((item) => {
                    const sign = item.delta > 0 ? '+' : '';
                    return `
                        <li class="flex items-start gap-2 py-1.5 border-b border-[var(--outline-variant-elev)] border-opacity-30 last:border-0">
                            <span class="material-symbols-elev icon-size-xs flex-shrink-0" style="color: #f87171;">trending_down</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-[var(--on-surface-elev)] text-sm leading-snug">${this.escape(item.label)}</p>
                                <p class="text-xs text-[var(--outline-elev)] mt-0.5">${this.escape(item.source)}</p>
                            </div>
                            <span class="font-mono text-sm flex-shrink-0" style="color: #f87171;">${sign}${item.delta}</span>
                        </li>
                    `;
                }).join('');
            } else {
                this.scoreBreakdownTarget.innerHTML = '<li class="text-[var(--outline-elev)] py-2">Sin factores negativos.</li>';
            }
        }

        if (this.hasScoreComputedAtTarget) {
            this.scoreComputedAtTarget.textContent = data.computed_at
                ? new Date(data.computed_at).toLocaleString()
                : '—';
        }
    }

    renderSignals(signals) {
        if (!this.hasSignalsListTarget) return;
        const emptyEl = this.hasSignalsEmptyTarget ? this.signalsEmptyTarget : null;
        if (this.hasSignalCountBadgeTarget) this.signalCountBadgeTarget.textContent = signals.length;

        // Wipe every existing dynamic child except the empty placeholder
        Array.from(this.signalsListTarget.children).forEach((child) => {
            if (child !== emptyEl) child.remove();
        });

        if (signals.length === 0) {
            if (emptyEl) emptyEl.hidden = false;
            return;
        }
        if (emptyEl) emptyEl.hidden = true;

        const sorted = signals.slice().sort((a, b) =>
            (SEVERITY_RANK[b.severity] || 0) - (SEVERITY_RANK[a.severity] || 0));

        sorted.forEach((sig) => {
            const visual = SEVERITY_VISUAL[sig.severity] || SEVERITY_VISUAL.info;
            const actionHtml = sig.action_label && sig.action_route
                ? `<a href="${this.escape(sig.action_route)}" class="text-xs text-[var(--gold-elev)] hover:underline mt-2 inline-flex items-center gap-1">
                     ${this.escape(sig.action_label)}
                     <span class="material-symbols-elev icon-size-xs">arrow_forward</span>
                   </a>`
                : '';

            const card = document.createElement('div');
            card.className = 'p-4 rounded-lg border border-[var(--outline-variant-elev)] bg-[var(--glass-bg-elev)]';
            card.innerHTML = `
                <div class="flex items-start gap-3">
                    <span class="material-symbols-elev flex-shrink-0" style="color: ${visual.color}; font-variation-settings: 'FILL' 1;">${visual.icon}</span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h4 class="text-sm font-semibold text-[var(--on-surface-elev)]">${this.escape(sig.title)}</h4>
                            <span class="status-pill ${visual.pillClass}" style="font-size: 0.65rem; padding: 0.1rem 0.5rem;">${this.escape(sig.severity)}</span>
                        </div>
                        <p class="text-sm text-[var(--on-surface-variant-elev)] mt-1 leading-snug">${this.escape(sig.message)}</p>
                        ${actionHtml}
                    </div>
                </div>
            `;
            this.signalsListTarget.insertBefore(card, emptyEl);
        });
    }

    escape(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}
