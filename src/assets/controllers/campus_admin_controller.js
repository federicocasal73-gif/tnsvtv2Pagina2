import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint G.4 — Campus Admin list view.
 *
 * Loads courses from /api/campus/admin/courses (X-Admin-Password auth
 * header) and renders a drag-and-drop reorderable list.
 *
 * Search + tabs are local UI filters — the API returns ALL courses and
 * the client picks which ones to show.
 */
export default class extends Controller {
    static targets = [
        'list', 'count',
        'tabAll', 'tabActive', 'tabDraft',
        'searchInput',
    ];

    static values = {
        token: { type: String, default: '' },
    };

    connect() {
        this.currentFilter = 'all';
        this.loadAll();
    }

    // ── Tabs ──
    loadAll() { this.currentFilter = 'all'; this.loadList(); }
    loadActive() { this.currentFilter = 'active'; this.loadList(); }
    loadDrafts() { this.currentFilter = 'draft'; this.loadList(); }

    onSearch() {
        clearTimeout(this._searchTimer);
        this._searchTimer = setTimeout(() => this.loadList(), 250);
    }

    async loadList() {
        if (!this.hasListTarget) return;
        try {
            const r = await fetch('/api/campus/admin/courses', {
                headers: this.adminHeaders(),
            });
            if (!r.ok) {
                this.listTarget.innerHTML = this.errorHtml(r.status);
                return;
            }
            const courses = await r.json();
            if (!Array.isArray(courses)) {
                this.listTarget.innerHTML = this.errorHtml('Datos inválidos');
                return;
            }
            const filtered = this.applyFilter(courses);
            if (this.hasCountTarget) {
                this.countTarget.textContent = `${filtered.length} curso${filtered.length === 1 ? '' : 's'}`;
            }
            if (filtered.length === 0) {
                this.listTarget.innerHTML = this.emptyHtml();
                return;
            }
            this.listTarget.innerHTML = filtered.map(c => this.cardHtml(c)).join('');
            this.bindRowEvents();
        } catch (e) {
            this.listTarget.innerHTML = this.errorHtml('Red');
            console.error('[campus-admin] loadList', e);
        }
    }

    applyFilter(courses) {
        const search = this.hasSearchInputTarget ? this.searchInputTarget.value.trim().toLowerCase() : '';
        let filtered = courses;
        if (this.currentFilter === 'active') filtered = courses.filter(c => c.is_active);
        else if (this.currentFilter === 'draft') filtered = courses.filter(c => !c.is_active);
        if (search) {
            filtered = filtered.filter(c =>
                (c.title || '').toLowerCase().includes(search) ||
                (c.description || '').toLowerCase().includes(search));
        }
        return filtered;
    }

    cardHtml(c) {
        return `
            <article class="campus-admin-card" draggable="true" data-course-id="${c.id}">
                <div class="campus-admin-card-drag">
                    <span class="material-symbols-elev">drag_indicator</span>
                </div>
                <div class="campus-admin-card-emoji">${this.escape(c.emoji || '📚')}</div>
                <div class="campus-admin-card-body">
                    <div class="campus-admin-card-header">
                        <h3 class="campus-admin-card-title">
                            <a href="/sanctum/campus/admin/courses/${c.id}">${this.escape(c.title || '')}</a>
                        </h3>
                        <span class="status-pill size-sm ${c.is_active ? 'status-active' : 'status-inactive'}">${c.is_active ? 'Activo' : 'Borrador'}</span>
                    </div>
                    ${c.description ? `<p class="campus-admin-card-desc">${this.escape(c.description)}</p>` : ''}
                    <div class="campus-admin-card-stats">
                        <span><span class="material-symbols-elev">layers</span> ${c.modules_count || 0} módulos</span>
                        <span><span class="material-symbols-elev">play_lesson</span> ${c.lessons_count || 0} lecciones</span>
                    </div>
                </div>
                <div class="campus-admin-card-actions">
                    <a href="/sanctum/campus/admin/courses/${c.id}"
                       class="ui-btn ui-btn ghost ui-btn-size-sm">
                        <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">edit</span>
                        Editar
                    </a>
                </div>
            </article>
        `;
    }

    bindRowEvents() {
        this.listTarget.querySelectorAll('.campus-admin-card').forEach(card => {
            card.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.courseId);
                card.classList.add('is-dragging');
            });
            card.addEventListener('dragend', () => card.classList.remove('is-dragging'));
            card.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });
            card.addEventListener('drop', (e) => {
                e.preventDefault();
                const fromId = e.dataTransfer.getData('text/plain');
                const toId = card.dataset.courseId;
                if (fromId !== toId) this.reorder(fromId, toId);
            });
        });
    }

    async reorder(fromId, toId) {
        // For simplicity we just bump the dropped item above the target
        // and persist the new order to the server.
        const cards = Array.from(this.listTarget.querySelectorAll('.campus-admin-card'));
        const fromIdx = cards.findIndex(c => c.dataset.courseId === fromId);
        const toIdx = cards.findIndex(c => c.dataset.courseId === toId);
        if (fromIdx === -1 || toIdx === -1) return;
        // Optimistic DOM update
        const moved = cards[fromIdx];
        cards[fromIdx].remove();
        this.listTarget.insertBefore(moved, cards[toIdx]);

        const order = Array.from(this.listTarget.querySelectorAll('.campus-admin-card'))
            .map(c => c.dataset.courseId);
        try {
            await fetch('/api/campus/admin/courses/reorder', {
                method: 'POST',
                headers: { ...this.adminHeaders(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ order: order.map(id => parseInt(id, 10)) }),
            });
            if (window.apiToast) window.apiToast('Orden actualizado', 'success');
        } catch (e) {
            console.error('[campus-admin] reorder', e);
            if (window.apiToast) window.apiToast('Error al reordenar', 'error');
        }
    }

    adminHeaders() {
        return { 'X-Admin-Password': this.tokenValue || '' };
    }

    emptyHtml() {
        return `
            <div class="empty-state empty-state-md">
                <span class="material-symbols-elev empty-state-icon" aria-hidden="true">school</span>
                <p class="empty-state-message">Sin cursos</p>
                <p class="empty-state-description">Crea tu primer curso para empezar.</p>
            </div>
        `;
    }

    errorHtml(status) {
        return `<p class="empty-state empty-state-md"><span class="material-symbols-elev empty-state-icon">error</span><p class="empty-state-message">Error ${status} al cargar.</p></p>`;
    }

    escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
}
