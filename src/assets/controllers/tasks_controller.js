import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'activeCount', 'inactiveCount'];

    connect() {
        this.allTasks = [];
        this.loadTasks();
    }

    async loadTasks() {
        if (!this.hasListTarget) return;
        this.listTarget.classList.add('loading-pulse');

        try {
            const r = await fetch('/sanctum/api/tasks');
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const data = await r.json();
            if (!data.success) throw new Error(data.error || 'unknown');

            this.allTasks = data.tasks || [];
            if (this.hasActiveCountTarget) {
                this.activeCountTarget.textContent = (data.activeCount || 0) + ' active';
            }
            if (this.hasInactiveCountTarget) {
                this.inactiveCountTarget.textContent = (data.inactiveCount || 0) + ' inactive';
            }
            this.renderTasks();
        } catch (e) {
            this.listTarget.innerHTML = `<p class="text-red-400 text-center py-8">Error: ${e.message}</p>`;
        }
        this.listTarget.classList.remove('loading-pulse');
    }

    renderTasks() {
        if (!this.hasListTarget) return;

        if (this.allTasks.length === 0) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin tareas. Crea la primera usando el formulario.</p>';
            return;
        }

        this.listTarget.innerHTML = this.allTasks.map(t => `
            <div class="flex items-center gap-3 p-3 rounded-lg glass-card-elev" data-task-id="${t.id}">
                <div class="flex flex-col gap-1">
                    <button data-action="click->tasks#reorder" data-direction="-1" data-id="${t.id}" class="text-[var(--outline-elev)] hover:text-[var(--gold-elev)] text-xs" ${t.orden === 0 ? 'disabled' : ''}>▲</button>
                    <button data-action="click->tasks#reorder" data-direction="1" data-id="${t.id}" class="text-[var(--outline-elev)] hover:text-[var(--gold-elev)] text-xs" ${t.orden === this.allTasks[this.allTasks.length-1].orden ? 'disabled' : ''}>▼</button>
                </div>
                <input type="checkbox" ${t.active ? 'checked' : ''} data-action="change->tasks#toggle" data-id="${t.id}" class="w-4 h-4" />
                <div class="flex-1 min-w-0">
                    <input type="text" value="${this.escapeHtml(t.title || '')}" data-action="blur->tasks#update" data-field="title" data-id="${t.id}" class="w-full bg-transparent text-[var(--on-surface-elev)] font-medium focus:outline-none focus:bg-[var(--glass-bg-elev)] px-2 py-1 rounded" />
                    ${t.description ? `<p class="text-xs text-[var(--outline-elev)] mt-1 px-2">${this.escapeHtml(t.description.substring(0, 100))}${t.description.length > 100 ? '...' : ''}</p>` : ''}
                </div>
                <span class="text-xs text-[var(--outline-elev)] font-mono">#${t.orden}</span>
                <span class="status-pill ${t.active ? 'status-active' : 'status-inactive'}">${t.active ? 'Active' : 'Inactive'}</span>
                <button data-action="click->tasks#delete" data-id="${t.id}" class="text-red-400 hover:text-red-300 text-xs px-2">✕</button>
            </div>
        `).join('');
    }

    async createTask(e) {
        e.preventDefault();
        const errEl = document.getElementById('create-error');
        errEl.classList.add('hidden');

        const title = document.getElementById('create-title').value.trim();
        const description = document.getElementById('create-description').value.trim();
        const orden = parseInt(document.getElementById('create-orden').value) || 0;
        const active = document.getElementById('create-active').checked;

        if (!title) {
            errEl.textContent = 'El título es obligatorio';
            errEl.classList.remove('hidden');
            return;
        }

        try {
            const r = await fetch('/sanctum/api/tasks', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, description, orden, active }),
            });
            const data = await r.json();
            if (data.success) {
                document.getElementById('create-title').value = '';
                document.getElementById('create-description').value = '';
                await this.loadTasks();
            } else {
                errEl.textContent = data.error || 'Error desconocido';
                errEl.classList.remove('hidden');
            }
        } catch (e) {
            errEl.textContent = 'Error de red: ' + e.message;
            errEl.classList.remove('hidden');
        }
    }

    async toggle(e) {
        const id = e.target.dataset.id;
        const active = e.target.checked;
        try {
            await fetch('/sanctum/api/tasks/' + id + '/toggle', { method: 'PATCH' });
            await this.loadTasks();
        } catch (e) { console.error(e); }
    }

    async update(e) {
        const id = e.target.dataset.id;
        const field = e.target.dataset.field;
        const value = e.target.value;
        try {
            await fetch('/sanctum/api/tasks/' + id, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ [field]: value }),
            });
            await this.loadTasks();
        } catch (e) { console.error(e); }
    }

    async delete(e) {
        const id = e.target.dataset.id;
        if (!confirm('¿Eliminar esta tarea? Esta acción no se puede deshacer.')) return;
        try {
            const r = await fetch('/sanctum/api/tasks/' + id, { method: 'DELETE' });
            const data = await r.json();
            if (data.success) {
                await this.loadTasks();
            } else {
                if (window.apiToast) window.apiToast('Error: ' + (data.error || 'desconocido'), 'error');
            }
        } catch (e) { if (window.apiToast) window.apiToast('Error: ' + e.message, 'error'); }
    }

    async reorder(e) {
        const id = parseInt(e.target.dataset.id);
        const direction = parseInt(e.target.dataset.direction);
        const currentIndex = this.allTasks.findIndex(t => t.id === id);
        const newIndex = currentIndex + direction;
        if (newIndex < 0 || newIndex >= this.allTasks.length) return;

        const newOrder = this.allTasks.map(t => t.id);
        [newOrder[currentIndex], newOrder[newIndex]] = [newOrder[newIndex], newOrder[currentIndex]];

        try {
            const r = await fetch('/sanctum/api/tasks/reorder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order: newOrder }),
            });
            const data = await r.json();
            if (data.success) {
                await this.loadTasks();
            }
        } catch (e) { console.error(e); }
    }

    escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
}
