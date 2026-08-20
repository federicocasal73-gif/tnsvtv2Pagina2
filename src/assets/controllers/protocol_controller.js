import { Controller } from '@hotwired/stimulus';

const SEVERITY_RANK = { danger: 3, warning: 2, info: 1 };
const SEVERITY_VISUAL = {
    danger:  { icon: 'error',   pill: 'status-inactive', color: '#f87171' },
    warning: { icon: 'warning', pill: 'status-pending',  color: 'var(--gold-elev)' },
    info:    { icon: 'info',    pill: 'status-active',   color: 'var(--violet)' },
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
        'overlay',
        'loading',
        'error',
        'content',
        'scoreValue',
        'scoreTier',
        'scoreBreakdown',
        'scoreComputedAt',
        'signalsList',
        'signalsEmpty',
        'signalCount',
    ];

    connect() {
        if (!window.apiSetupModal) return;
        this.prevFocus = null;
        const close = () => {
            this.overlayTarget.style.display = 'none';
            this.overlayTarget.setAttribute('hidden', '');
            document.body.style.overflow = '';
            if (this.prevFocus && typeof this.prevFocus.focus === 'function') {
                this.prevFocus.focus();
            }
            this.prevFocus = null;
        };
        this.hardClose = close;
        this.modal = window.apiSetupModal(this.overlayTarget, {
            open: () => {
                this.prevFocus = document.activeElement;
                this.load();
            },
            close,
        });
    }

    onButtonClick(event) {
        event.preventDefault();
        if (this.modal) this.modal.open();
    }

    onClose() {
        if (this.hardClose) this.hardClose();
    }

    onOverlayClick(event) {
        if (event.target === this.overlayTarget) this.onClose();
    }

    async load() {
        this.loadingTarget.classList.remove('hidden');
        this.errorTarget.classList.add('hidden');
        this.contentTarget.classList.add('hidden');

        const [signalsRes, scoreRes] = await Promise.all([
            window.apiFetch('/api/guardian/signals', { silent: true }),
            window.apiFetch('/api/guardian/score', { silent: true }),
        ]);

        this.loadingTarget.classList.add('hidden');

        if (!signalsRes.ok || !scoreRes.ok) {
            this.errorTarget.classList.remove('hidden');
            return;
        }

        this.renderScore(scoreRes.data);
        this.renderSignals(signalsRes.data.signals || []);
        this.contentTarget.classList.remove('hidden');
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    renderScore(data) {
        const score = data.score ?? 0;
        const tierVisual = TIER_VISUAL[data.tier] || { label: '—', color: 'var(--outline-elev)' };

        this.scoreValueTarget.textContent = score;
        this.scoreValueTarget.style.color = score >= 75 ? tierVisual.color : (score >= 40 ? 'var(--gold-elev)' : '#f87171');

        this.scoreTierTarget.textContent = tierVisual.label;
        this.scoreTierTarget.style.color = tierVisual.color;
        this.scoreTierTarget.style.borderColor = tierVisual.color;

        const items = (Array.isArray(data.breakdown) && data.breakdown.length > 0) ? data.breakdown : null;
        this.scoreBreakdownTarget.innerHTML = items
            ? items.map((item) => {
                const sign = item.delta > 0 ? '+' : '';
                return `
                    <li class="protocol-breakdown-item">
                        <span class="material-symbols-elev protocol-icon-danger" aria-hidden="true">trending_down</span>
                        <div class="protocol-breakdown-text">
                            <p>${this.escapeHtml(item.label)}</p>
                            ${item.source ? `<p class="protocol-breakdown-source">${this.escapeHtml(item.source)}</p>` : ''}
                        </div>
                        <span class="protocol-breakdown-delta">${sign}${item.delta}</span>
                    </li>
                `;
            }).join('')
            : '<li class="protocol-breakdown-clear">Sin factores negativos.</li>';

        this.scoreComputedAtTarget.textContent = data.computed_at
            ? new Date(data.computed_at).toLocaleString()
            : '—';
    }

    renderSignals(signals) {
        const listEl = this.signalsListTarget;
        const emptyEl = this.signalsEmptyTarget;

        this.signalCountTarget.textContent = signals.length;

        Array.from(listEl.children).forEach((child) => {
            if (child !== emptyEl) child.remove();
        });

        if (signals.length === 0) {
            emptyEl.classList.remove('hidden');
            return;
        }
        emptyEl.classList.add('hidden');

        const sorted = signals.slice().sort((a, b) =>
            (SEVERITY_RANK[b.severity] || 0) - (SEVERITY_RANK[a.severity] || 0)
        );

        sorted.forEach((sig) => {
            const visual = SEVERITY_VISUAL[sig.severity] || SEVERITY_VISUAL.info;
            const actionHtml = sig.action_label && sig.action_route
                ? `<a href="${this.escapeHtml(sig.action_route)}" class="protocol-signal-action">
                       ${this.escapeHtml(sig.action_label)}
                       <span class="material-symbols-elev" aria-hidden="true">arrow_forward</span>
                   </a>`
                : '';

            listEl.appendChild(this.buildSignalNode(sig, visual, actionHtml));
        });
    }

    buildSignalNode(sig, visual, actionHtml) {
        const node = document.createElement('li');
        node.className = 'protocol-signal';
        node.innerHTML = `
            <span class="material-symbols-elev protocol-signal-icon" style="color: ${visual.color};" aria-hidden="true">${visual.icon}</span>
            <div class="protocol-signal-body">
                <div class="protocol-signal-head">
                    <h4 class="protocol-signal-title">${this.escapeHtml(sig.title)}</h4>
                    <span class="status-pill ${visual.pill}">${this.escapeHtml(sig.severity)}</span>
                </div>
                <p class="protocol-signal-message">${this.escapeHtml(sig.message)}</p>
                ${actionHtml}
            </div>
        `;
        return node;
    }
}