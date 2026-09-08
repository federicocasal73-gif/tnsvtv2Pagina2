import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['taskList', 'signalList', 'pnlMeta'];

    async connect() {
        await this.loadTasks();
    }

    async loadTasks() {
        if (!this.hasTaskListTarget) return;

        try {
            // Unified API (X-Game-Code auto-attached by apiFetch).
            let data = null;
            try {
                const r = await window.apiFetch('/api/tasks/mine?sort=due_date&order=asc', { silent: true });
                if (r.ok && r.data && r.data.tasks) {
                    data = { success: true, tasks: r.data.tasks.slice(0, 5), activeCount: r.data.count };
                }
            } catch {}

            if (data && data.success) {
                this.renderTasks(data.tasks || []);
                if (this.hasPnlMetaTarget) {
                    this.pnlMetaTarget.textContent = `${data.activeCount ?? data.count ?? 0} activas`;
                }
                this.renderSignals(data.tasks || []);
            } else {
                this.showEmpty('task', 'Sin tareas activas');
                this.showEmpty('signal', 'Sin señales');
            }
        } catch (e) {
            this.showEmpty('task', 'Sin tareas activas');
            this.showEmpty('signal', 'Sin señales');
        }
    }

    renderTasks(tasks) {
        if (!this.hasTaskListTarget) return;

        if (!tasks || tasks.length === 0) {
            this.showEmpty('task', 'Sin tareas activas');
            return;
        }

        this.taskListTarget.innerHTML = tasks.slice(0, 5).map(t => `
            <div class="task-row-elev">
                <span class="status-pill status-pill-${this.escapeHtml(t.status || 'pending')} text-xs">${this.escapeHtml(t.status_label || t.status || '')}</span>
                <span class="text-sm flex-1 truncate">${this.escapeHtml(t.title || '')}</span>
                <span class="text-xs text-[var(--outline-elev)] font-mono">${t.due_date ? this.escapeHtml(String(t.due_date).slice(0, 10)) : ''}</span>
            </div>
        `).join('');
    }

    renderSignals(tasks) {
        if (!this.hasSignalListTarget) return;

        if (!tasks || tasks.length === 0) {
            this.signalListTarget.innerHTML = this.emptyHtml('notifications_off', 'Sin actividad reciente');
            return;
        }

        this.signalListTarget.innerHTML = tasks.slice(0, 5).map(t => `
            <div class="flex items-center justify-between p-2 rounded glass-card-elev text-sm">
                <span class="truncate">${this.escapeHtml(t.title)}</span>
                <span class="status-pill status-pill-${this.escapeHtml(t.status || 'pending')} text-xs">${this.escapeHtml(t.status_label || t.status || '')}</span>
            </div>
        `).join('');
    }

    showEmpty(type, message) {
        const el = type === 'task' ? this.taskListTarget : this.signalListTarget;
        if (el) {
            el.innerHTML = this.emptyHtml(type === 'task' ? 'task_alt' : 'notifications_off', message);
        }
    }

    emptyHtml(icon, message) {
        return `<p class="text-center text-[var(--outline-elev)] py-8">${message}</p>`;
    }

    escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
}