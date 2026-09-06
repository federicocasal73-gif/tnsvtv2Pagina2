import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'count', 'empty'];

    connect() {
        this.load();
    }

    async load() {
        if (!this.hasListTarget) return;
        try {
            const r = await fetch('/api/me/upcoming-tasks?days=7&limit=5', {
                headers: { 'X-Game-Code': document.body?.dataset?.userCode || '' },
            });
            if (!r.ok) {
                this.showEmpty();
                return;
            }
            const data = await r.json();
            const today = data.today || [];
            const upcoming = data.upcoming || [];
            // Prefer today tasks, fallback to upcoming
            const toShow = today.length ? today : upcoming.slice(0, 5);
            if (!toShow.length) {
                this.showEmpty();
                return;
            }
            if (this.hasCountTarget) this.countTarget.textContent = `${toShow.length}`;
            this.listTarget.innerHTML = toShow.map(t => `
                <a href="/sanctum/tasks/${t.id}" class="task-card ${t.is_overdue ? 'is-urgent' : ''}" style="padding: var(--space-3); display:block; text-decoration:none;">
                    <div style="display:flex; align-items:center; gap: var(--space-2);">
                        <span class="status-pill status-pill-${t.status} size-sm">${this.escape(t.status_label || t.status)}</span>
                        ${t.is_overdue ? '<span class="status-pill status-pill-overdue size-sm">Vencida</span>' : ''}
                        <span style="margin-left:auto; font-size:0.7rem; color: var(--outline-elev);">${this.formatDue(t.due_date)}</span>
                    </div>
                    <div style="margin-top: var(--space-2); font-weight:600; color: var(--on-surface-elev);">${this.escape(t.title)}</div>
                    ${t.description ? `<div style="font-size:0.8rem; color: var(--on-surface-variant-elev);">${this.escape(t.description.slice(0,80))}</div>` : ''}
                </a>
            `).join('');
        } catch (e) {
            this.showEmpty();
        }
    }

    showEmpty() {
        if (this.hasListTarget) this.listTarget.innerHTML = `<p style="text-align:center; color: var(--outline-elev); padding: var(--space-4);">Sin tareas para hoy 🎉</p>`;
        if (this.hasCountTarget) this.countTarget.textContent = '0';
    }

    formatDue(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso);
            const now = new Date();
            const diff = (d - now)/86400000;
            if (diff < 0) return 'Vencida';
            if (diff < 1) return 'Hoy';
            if (diff < 2) return 'Mañana';
            return d.toLocaleDateString('es-AR', { day:'2-digit', month:'short' });
        } catch { return ''; }
    }

    escape(s) { return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
}
