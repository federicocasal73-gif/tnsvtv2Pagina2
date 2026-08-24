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
        this.dismissedUntil = this.getDismissedUntil();
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

        // TNSVT Sprint B.5 — Verificar signals al cargar y mostrar/ocultar botón
        this.checkAlertsOnLoad();
        // Re-chequear cada 60s
        this.alertsTimer = setInterval(() => this.checkAlertsOnLoad(), 60000);
    }

    /**
     * TNSVT Sprint H.2 — Obtener el timestamp hasta el cual el usuario descartó el modal.
     * Si el timestamp aún no expiró (24hs), devuelve el timestamp.
     * Si no hay dismissal registrado o ya expiró, devuelve 0.
     */
    getDismissedUntil() {
        try {
            const raw = localStorage.getItem('tnsvt_protocol_dismissed_until');
            if (!raw) return 0;
            const ts = parseInt(raw, 10);
            return isNaN(ts) ? 0 : ts;
        } catch (e) {
            return 0;
        }
    }

    /**
     * TNSVT Sprint H.2 — Guardar el timestamp de dismissal (24hs).
     */
    setDismissedFor24h() {
        const until = Date.now() + (24 * 60 * 60 * 1000); // 24 horas en ms
        try {
            localStorage.setItem('tnsvt_protocol_dismissed_until', String(until));
        } catch (e) {
            // Silenciar
        }
    }

    /**
     * TNSVT Sprint H.2 — Verificar si el modal debe mostrarse automáticamente.
     * Retorna false si el usuario descartó el modal y aún no pasaron 24hs.
     */
    shouldShowAutoModal() {
        if (!this.dismissedUntil) return true;
        return Date.now() >= this.dismissedUntil;
    }

    /**
     * TNSVT Sprint H.2 — Limpiar el timestamp de dismissal (reabrir modal).
     */
    clearDismissed() {
        try {
            localStorage.removeItem('tnsvt_protocol_dismissed_until');
            this.dismissedUntil = 0;
        } catch (e) {
            // Silenciar
        }
    }

    disconnect() {
        if (this.alertsTimer) clearInterval(this.alertsTimer);
    }

    /**
     * TNSVT Sprint B.5 — Mostrar botón "Invocar Protocolo" solo cuando hay alerts.
     * Si no hay alerts, ocultar el botón completamente.
     */
    async checkAlertsOnLoad() {
        if (!window.apiFetch) return;
        try {
            const r = await window.apiFetch('/api/guardian/signals', { silent: true });
            if (r.ok && r.data && r.data.success) {
                const count = r.data.count || 0;
                this.updateButtonVisibility(count);
            }
        } catch (e) {
            // Silenciar errores — no mostrar el botón si falla
        }
    }

    /**
     * Mostrar u ocultar el botón según el conteo de signals.
     * Si count > 0: mostrar con badge
     * Si count === 0: ocultar
     */
    updateButtonVisibility(count) {
        const button = this.element.querySelector('[data-action="protocol#onButtonClick"]');
        if (!button) return;

        // Buscar o crear badge
        let badge = button.querySelector('.protocol-alert-badge');

        if (count > 0) {
            button.classList.remove('hidden');
            button.style.display = '';
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'protocol-alert-badge';
                button.appendChild(badge);
            }
            badge.textContent = count > 9 ? '9+' : String(count);
        } else {
            // Ocultar botón cuando no hay alerts
            button.classList.add('hidden');
            button.style.display = 'none';
            if (badge) badge.remove();
        }
    }

    onButtonClick(event) {
        event.preventDefault();
        if (this.modal) {
            // Si el modal está dismissed y no se ha reabierto, forzar apertura
            if (!this.shouldShowAutoModal()) {
                this.clearDismissed();
            }
            this.modal.open();
        }
    }

    onClose() {
        // TNSVT Sprint H.2 — Guardar dismissal de 24hs cuando el usuario cierra
        this.setDismissedFor24h();
        if (this.hardClose) this.hardClose();
    }

    onOverlayClick(event) {
        if (event.target === this.overlayTarget) this.onClose();
    }

    /**
     * TNSVT Sprint H.2 — Acción explícita "Cerrar por 24h".
     * Cierra el modal y guarda el timestamp para no mostrar de nuevo por 24 horas.
     */
    dismissFor24h(event) {
        if (event) event.preventDefault();
        this.setDismissedFor24h();
        if (this.hardClose) this.hardClose();
        if (window.apiToast) {
            window.apiToast('Modal del Guardian descartado por 24 horas', 'info');
        }
    }

    /**
     * TNSVT Sprint H.4 — Reabrir el modal forzando la apertura aunque esté dismissed.
     */
    reopenModal(event) {
        if (event) event.preventDefault();
        this.clearDismissed();
        if (this.modal) this.modal.open();
    }

    async load() {
        // TNSVT Sprint H.2 — Verificar si el modal debe mostrarse
        // Si el usuario lo descartó y aún no pasaron 24hs, no hacer nada
        if (!this.shouldShowAutoModal()) {
            return;
        }

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

        // Si hay signals, abrir el modal automáticamente
        const count = (signalsRes.data && signalsRes.data.count) || 0;
        if (count > 0 && this.shouldShowAutoModal() && this.modal) {
            this.modal.open();
        }
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