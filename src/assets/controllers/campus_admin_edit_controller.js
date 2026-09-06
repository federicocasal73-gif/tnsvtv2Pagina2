import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint G.4 — Campus Admin course editor.
 *
 * Manages a single course's basic info, modules (drag-and-drop reorderable),
 * and lessons inside each module. All operations go through the JSON
 * admin API at /api/campus/admin/*.
 */
export default class extends Controller {
    static targets = [
        'courseTitle', 'courseDescription', 'courseEmoji', 'courseThumbnail',
        'courseActive', 'courseSaveBtn',
        'modulesList', 'modulesCard',
        'preview', 'previewEmoji', 'previewTitle', 'previewDesc', 'previewModules', 'previewLessons',
    ];

    static values = {
        token: { type: String, default: '' },
        courseId: { type: Number, default: 0 },
    };

    connect() {
        if (this.courseIdValue > 0) {
            this.loadCourse();
        } else {
            this.bindPreview();
        }
    }

    back(event) {
        event.preventDefault();
        location.href = '/sanctum/campus/admin/courses';
    }

    // ── Preview binding (works for both new and edit) ──
    bindPreview() {
        ['courseTitle', 'courseDescription', 'courseEmoji'].forEach(target => {
            this[`has${target.charAt(0).toUpperCase() + target.slice(1)}Target`] &&
            this[`${target}Target`].addEventListener('input', () => this.updatePreview());
        });
        this.updatePreview();
    }

    updatePreview() {
        if (!this.hasPreviewTarget) return;
        const title = this.hasCourseTitleTarget ? (this.courseTitleTarget.value || 'Nuevo curso') : 'Nuevo curso';
        const desc = this.hasCourseDescriptionTarget ? (this.courseDescriptionTarget.value || 'Sin descripción aún.') : 'Sin descripción aún.';
        const emoji = this.hasCourseEmojiTarget ? (this.courseEmojiTarget.value || '📚') : '📚';
        if (this.hasPreviewTitleTarget) this.previewTitleTarget.textContent = title;
        if (this.hasPreviewDescTarget) this.previewDescTarget.textContent = desc;
        if (this.hasPreviewEmojiTarget) this.previewEmojiTarget.textContent = emoji;
    }

    // ── Load course + modules + lessons ──
    async loadCourse() {
        // Course basic info
        try {
            const r = await fetch('/api/campus/admin/courses', { headers: this.headers() });
            if (!r.ok) {
                this.modulesListTarget.innerHTML = `<p class="campus-admin-error">Error al cargar (${r.status})</p>`;
                return;
            }
            const all = await r.json();
            const course = all.find(c => c.id === this.courseIdValue);
            if (!course) {
                this.modulesListTarget.innerHTML = `<p class="campus-admin-error">Curso no encontrado.</p>`;
                return;
            }
            this.fillCourseForm(course);
            this.updatePreview();
            this.bindPreview();

            // Modules
            await this.loadModules();
        } catch (e) {
            console.error('[campus-admin-edit] loadCourse', e);
        }
    }

    fillCourseForm(course) {
        if (this.hasCourseTitleTarget) this.courseTitleTarget.value = course.title || '';
        if (this.hasCourseDescriptionTarget) this.courseDescriptionTarget.value = course.description || '';
        if (this.hasCourseEmojiTarget) this.courseEmojiTarget.value = course.emoji || '';
        if (this.hasCourseThumbnailTarget) this.courseThumbnailTarget.value = course.thumbnail || '';
        if (this.hasCourseActiveTarget) this.courseActiveTarget.checked = !!course.is_active;
        if (this.hasPreviewTitleTarget) this.previewTitleTarget.textContent = course.title || '';
        if (this.hasPreviewDescTarget) this.previewDescTarget.textContent = course.description || 'Sin descripción aún.';
        if (this.hasPreviewEmojiTarget) this.previewEmojiTarget.textContent = course.emoji || '📚';
    }

    async loadModules() {
        try {
            const r = await fetch(`/api/campus/admin/modules?course_id=${this.courseIdValue}`, { headers: this.headers() });
            if (!r.ok) {
                this.modulesListTarget.innerHTML = `<p class="campus-admin-error">Error al cargar módulos (${r.status})</p>`;
                return;
            }
            const modules = await r.json();
            this.renderModules(modules);
            // Load lessons for each module
            await Promise.all(modules.map(m => this.loadLessons(m.id)));
        } catch (e) {
            console.error('[campus-admin-edit] loadModules', e);
        }
    }

    renderModules(modules) {
        if (modules.length === 0) {
            this.modulesListTarget.innerHTML = `
                <div class="campus-admin-empty-mini">
                    <span class="material-symbols-elev">layers_clear</span>
                    <p>Aún no hay módulos. Agrega el primero.</p>
                </div>
            `;
            return;
        }
        this.modulesListTarget.innerHTML = modules.map(m => `
            <div class="campus-admin-module" data-module-id="${m.id}" draggable="true">
                <header class="campus-admin-module-header">
                    <span class="campus-admin-module-drag material-symbols-elev">drag_indicator</span>
                    <span class="campus-admin-module-title" contenteditable="true" data-action="blur->campus-admin-edit#renameModule" data-module-id="${m.id}">${this.escape(m.title)}</span>
                    <span class="campus-admin-module-meta">
                        <span data-lessons-count="${m.id}">0 lecciones</span>
                    </span>
                    <button type="button" class="ui-btn ui-btn-secondary ui-btn-size-sm" data-action="click->campus-admin-edit#addLesson" data-module-id="${m.id}">
                        <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">add</span>
                        Lección
                    </button>
                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-size-sm" data-action="click->campus-admin-edit#deleteModule" data-module-id="${m.id}">
                        <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">delete</span>
                    </button>
                </header>
                <ul class="campus-admin-lessons" data-module-lessons="${m.id}">
                    <li class="campus-admin-loading"><span class="material-symbols-elev">progress_activity</span> Cargando lecciones…</li>
                </ul>
            </div>
        `).join('');
        this.bindModuleDrag();
    }

    async loadLessons(moduleId) {
        try {
            const r = await fetch(`/api/campus/admin/lessons?module_id=${moduleId}`, { headers: this.headers() });
            if (!r.ok) return;
            const lessons = await r.json();
            const ul = this.modulesListTarget.querySelector(`[data-module-lessons="${moduleId}"]`);
            if (lessons.length === 0) {
                ul.innerHTML = `<li class="campus-admin-empty-mini"><span class="material-symbols-elev">school</span> Aún no hay lecciones en este módulo.</li>`;
            } else {
                ul.innerHTML = lessons.map(l => `
                    <li class="campus-admin-lesson" data-lesson-id="${l.id}">
                        <a href="/sanctum/campus/admin/courses/${this.courseIdValue}/lessons/${l.id}" class="campus-admin-lesson-link">
                            <span class="material-symbols-elev">play_circle</span>
                            <span class="campus-admin-lesson-title">${this.escape(l.title)}</span>
                            <span class="campus-admin-lesson-arrow material-symbols-elev">chevron_right</span>
                        </a>
                    </li>
                `).join('');
            }
            const countEl = this.modulesListTarget.querySelector(`[data-lessons-count="${moduleId}"]`);
            if (countEl) countEl.textContent = `${lessons.length} lección${lessons.length === 1 ? '' : 'es'}`;
        } catch (e) {
            console.error('[campus-admin-edit] loadLessons', e);
        }
    }

    // ── Course save ──
    async saveCourse(event) {
        event.preventDefault();
        const body = {
            title: this.courseTitleTarget.value.trim() || 'Sin título',
            emoji: this.courseEmojiTarget.value.trim() || '📚',
            description: this.courseDescriptionTarget.value.trim() || null,
            thumbnail: this.courseThumbnailTarget.value.trim() || null,
            is_active: this.courseActiveTarget.checked,
        };
        const isNew = this.courseIdValue === 0;
        const url = isNew ? '/api/campus/admin/courses' : `/api/campus/admin/courses/${this.courseIdValue}`;
        const method = isNew ? 'POST' : 'PUT';
        try {
            const r = await fetch(url, {
                method,
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!r.ok) {
                if (window.apiToast) window.apiToast('Error al guardar', 'error');
                return;
            }
            const data = await r.json();
            if (window.apiToast) window.apiToast(isNew ? 'Curso creado' : 'Cambios guardados', 'success');
            if (isNew && data.id) {
                location.href = `/sanctum/campus/admin/courses/${data.id}`;
            }
        } catch (e) {
            console.error('[campus-admin-edit] saveCourse', e);
            if (window.apiToast) window.apiToast('Error de red', 'error');
        }
    }

    // ── Module CRUD ──
    async addModule() {
        const title = prompt('Nombre del módulo:', 'Nuevo Módulo');
        if (!title) return;
        try {
            const r = await fetch('/api/campus/admin/modules', {
                method: 'POST',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ course_id: this.courseIdValue, title }),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Módulo creado', 'success');
                await this.loadModules();
            } else {
                if (window.apiToast) window.apiToast('Error al crear módulo', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-edit] addModule', e);
        }
    }

    async renameModule(event) {
        const el = event.currentTarget;
        const moduleId = parseInt(el.dataset.moduleId, 10);
        const newTitle = el.textContent.trim();
        if (!newTitle) return;
        try {
            await fetch(`/api/campus/admin/modules/${moduleId}`, {
                method: 'PUT',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: newTitle }),
            });
            if (window.apiToast) window.apiToast('Módulo renombrado', 'success', 1500);
        } catch (e) {
            console.error('[campus-admin-edit] renameModule', e);
        }
    }

    async deleteModule(event) {
        const btn = event.currentTarget;
        const moduleId = parseInt(btn.dataset.moduleId, 10);
        if (!confirm('¿Eliminar este módulo y todas sus lecciones? Esta acción no se puede deshacer.')) return;
        try {
            const r = await fetch(`/api/campus/admin/modules/${moduleId}`, {
                method: 'DELETE',
                headers: this.headers(),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Módulo eliminado', 'success');
                await this.loadModules();
            } else {
                if (window.apiToast) window.apiToast('Error al eliminar', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-edit] deleteModule', e);
        }
    }

    bindModuleDrag() {
        const list = this.modulesListTarget;
        let draggedId = null;
        list.querySelectorAll('.campus-admin-module').forEach(mod => {
            mod.addEventListener('dragstart', (e) => {
                draggedId = mod.dataset.moduleId;
                e.dataTransfer.effectAllowed = 'move';
                mod.classList.add('is-dragging');
            });
            mod.addEventListener('dragend', () => mod.classList.remove('is-dragging'));
            mod.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });
            mod.addEventListener('drop', async (e) => {
                e.preventDefault();
                const toId = mod.dataset.moduleId;
                if (draggedId && draggedId !== toId) {
                    await this.reorderModules(draggedId, toId);
                }
            });
        });
    }

    async reorderModules(fromId, toId) {
        const mods = Array.from(this.modulesListTarget.querySelectorAll('.campus-admin-module'));
        const fromIdx = mods.findIndex(m => m.dataset.moduleId === fromId);
        const toIdx = mods.findIndex(m => m.dataset.moduleId === toId);
        if (fromIdx === -1 || toIdx === -1) return;
        const moved = mods[fromIdx];
        moved.remove();
        this.modulesListTarget.insertBefore(moved, mods[toIdx]);

        const order = Array.from(this.modulesListTarget.querySelectorAll('.campus-admin-module'))
            .map(m => parseInt(m.dataset.moduleId, 10));
        try {
            await fetch('/api/campus/admin/modules/reorder', {
                method: 'POST',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ order }),
            });
            if (window.apiToast) window.apiToast('Módulos reordenados', 'success');
        } catch (e) {
            console.error('[campus-admin-edit] reorderModules', e);
        }
    }

    // ── Lesson CRUD ──
    async addLesson(event) {
        const btn = event.currentTarget;
        const moduleId = parseInt(btn.dataset.moduleId, 10);
        const title = prompt('Título de la lección:', 'Nueva Lección');
        if (!title) return;
        try {
            const r = await fetch('/api/campus/admin/lessons', {
                method: 'POST',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ module_id: moduleId, title, orden: 0 }),
            });
            if (r.ok) {
                const data = await r.json();
                if (window.apiToast) window.apiToast('Lección creada', 'success');
                // Redirect to edit page
                location.href = `/sanctum/campus/admin/courses/${this.courseIdValue}/lessons/${data.id}`;
            } else {
                if (window.apiToast) window.apiToast('Error al crear lección', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-edit] addLesson', e);
        }
    }

    headers() {
        return { 'X-Admin-Password': this.tokenValue || '' };
    }

    escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
}
