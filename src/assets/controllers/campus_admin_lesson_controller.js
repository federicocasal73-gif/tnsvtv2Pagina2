import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint G.4 — Lesson editor controller.
 *
 * Handles a single lesson's video, description, materials and inline
 * preview. All CRUD goes through the admin JSON API.
 */
export default class extends Controller {
    static targets = [
        'lessonTitle', 'lessonDescription', 'lessonVideo', 'lessonOrden',
        'videoPreview', 'videoHint', 'materialsList', 'deleteBtn',
    ];

    static values = {
        token: { type: String, default: '' },
        courseId: { type: Number, default: 0 },
        lessonId: { type: Number, default: 0 },
    };

    connect() {
        this.loadLesson();
    }

    back(event) {
        event.preventDefault();
        location.href = `/sanctum/campus/admin/courses/${this.courseIdValue}`;
    }

    async loadLesson() {
        if (!this.lessonIdValue) return;
        try {
            const r = await fetch(`/api/campus/admin/lessons?module_id=0&lesson_id=${this.lessonIdValue}`,
                { headers: this.headers() });
            // The admin API supports filtering by module; if that doesn't work
            // we fall back to fetching all lessons for the course.
            let lesson = null;
            if (r.ok) {
                const list = await r.json();
                lesson = list.find(l => l.id === this.lessonIdValue);
            }
            if (!lesson) {
                const r2 = await fetch(`/api/campus/admin/lessons?course_id=${this.courseIdValue}`,
                    { headers: this.headers() });
                if (r2.ok) {
                    const list2 = await r2.json();
                    lesson = list2.find(l => l.id === this.lessonIdValue);
                }
            }
            if (!lesson) {
                // Fallback: load via the public endpoint
                const r3 = await fetch(`/api/campus/lessons/${this.lessonIdValue}`,
                    { headers: this.headers() });
                if (r3.ok) {
                    const data = await r3.json();
                    lesson = { id: data.id, title: data.title, description: data.description, video_url: data.video_url };
                }
            }
            if (!lesson) return;
            this.fillForm(lesson);
            this.updateVideoPreview();
        } catch (e) {
            console.error('[campus-admin-lesson] loadLesson', e);
        }
    }

    fillForm(lesson) {
        if (this.hasLessonTitleTarget) this.lessonTitleTarget.value = lesson.title || '';
        if (this.hasLessonDescriptionTarget) this.lessonDescriptionTarget.value = lesson.description || '';
        if (this.hasLessonVideoTarget) this.lessonVideoTarget.value = lesson.video_url || '';
        if (this.hasLessonOrdenTarget) this.lessonOrdenTarget.value = lesson.orden ?? 0;

        const titleEl = document.getElementById('lesson-crumb-title');
        if (titleEl) titleEl.textContent = lesson.title || 'Lección';

        if (this.hasDeleteBtnTarget) {
            this.deleteBtnTarget.dataset.lessonId = lesson.id;
            this.deleteBtnTarget.classList.remove('is-hidden');
        }
    }

    detectVideo() {
        this.updateVideoPreview();
    }

    updateVideoPreview() {
        if (!this.hasVideoPreviewTarget) return;
        const url = this.hasLessonVideoTarget ? this.lessonVideoTarget.value.trim() : '';
        if (!url) {
            this.videoPreviewTarget.innerHTML = `
                <div class="campus-admin-video-placeholder">
                    <span class="material-symbols-elev">play_circle</span>
                    <p>Pega una URL de video para ver la vista previa</p>
                </div>`;
            if (this.hasVideoHintTarget) this.videoHintTarget.textContent = 'YouTube, Vimeo o archivo .mp4 directo';
            return;
        }
        const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([\w-]{6,})/);
        if (yt) {
            if (this.hasVideoHintTarget) this.videoHintTarget.textContent = 'YouTube detectado';
            this.videoPreviewTarget.innerHTML = `<iframe class="campus-video-iframe" src="https://www.youtube.com/embed/${yt[1]}?rel=0" frameborder="0" allowfullscreen></iframe>`;
            return;
        }
        const vimeo = url.match(/vimeo\.com\/(\d+)/);
        if (vimeo) {
            if (this.hasVideoHintTarget) this.videoHintTarget.textContent = 'Vimeo detectado';
            this.videoPreviewTarget.innerHTML = `<iframe class="campus-video-iframe" src="https://player.vimeo.com/video/${vimeo[1]}" frameborder="0" allowfullscreen></iframe>`;
            return;
        }
        if (/\.(mp4|webm|ogg)$/i.test(url)) {
            if (this.hasVideoHintTarget) this.videoHintTarget.textContent = 'Video nativo (.mp4)';
            this.videoPreviewTarget.innerHTML = `<video class="campus-video-native" controls preload="metadata" playsinline><source src="${this.escape(url)}"></video>`;
            return;
        }
        if (this.hasVideoHintTarget) this.videoHintTarget.textContent = 'URL no reconocida (probá YouTube, Vimeo o .mp4)';
        this.videoPreviewTarget.innerHTML = `
            <div class="campus-admin-video-placeholder">
                <span class="material-symbols-elev">link_off</span>
                <p>URL no reconocida</p>
            </div>`;
    }

    async saveLesson(event) {
        event.preventDefault();
        const body = {
            title: this.lessonTitleTarget.value.trim() || 'Sin título',
            description: this.lessonDescriptionTarget.value.trim() || null,
            video_url: this.lessonVideoTarget.value.trim() || null,
            orden: parseInt(this.lessonOrdenTarget.value, 10) || 0,
        };
        try {
            const r = await fetch(`/api/campus/admin/lessons/${this.lessonIdValue}`, {
                method: 'PUT',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Lección guardada', 'success');
            } else {
                if (window.apiToast) window.apiToast('Error al guardar', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-lesson] saveLesson', e);
        }
    }

    async deleteLesson() {
        if (!confirm('¿Eliminar esta lección? Esta acción no se puede deshacer.')) return;
        try {
            const r = await fetch(`/api/campus/admin/lessons/${this.lessonIdValue}`, {
                method: 'DELETE',
                headers: this.headers(),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Lección eliminada', 'success');
                setTimeout(() => location.href = `/sanctum/campus/admin/courses/${this.courseIdValue}`, 500);
            } else {
                if (window.apiToast) window.apiToast('Error al eliminar', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-lesson] deleteLesson', e);
        }
    }

    // ── Materials ──
    async addMaterial() {
        const title = prompt('Título del material:', 'Nuevo Material');
        if (!title) return;
        const type = prompt('Tipo (pdf / video / image / link / doc):', 'pdf') || 'pdf';
        const url = prompt('URL del material:', 'https://…');
        if (!url) return;
        try {
            const r = await fetch('/api/campus/admin/materials', {
                method: 'POST',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ lesson_id: this.lessonIdValue, title, type, url }),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Material creado', 'success');
                this.loadMaterials();
            } else {
                if (window.apiToast) window.apiToast('Error al crear material', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-lesson] addMaterial', e);
        }
    }

    async loadMaterials() {
        if (!this.hasMaterialsListTarget) return;
        try {
            const r = await fetch(`/api/campus/admin/materials?lesson_id=${this.lessonIdValue}`, { headers: this.headers() });
            if (!r.ok) {
                this.materialsListTarget.innerHTML = `<p class="campus-admin-empty-mini">Sin materiales aún.</p>`;
                return;
            }
            const materials = await r.json();
            if (materials.length === 0) {
                this.materialsListTarget.innerHTML = `<p class="campus-admin-empty-mini"><span class="material-symbols-elev">attach_file</span> Sin materiales aún.</p>`;
                return;
            }
            this.materialsListTarget.innerHTML = materials.map(m => `
                <div class="campus-admin-material-row" data-material-id="${m.id}">
                    <span class="material-symbols-elev">${this.materialIcon(m.type)}</span>
                    <span class="campus-admin-material-title">${this.escape(m.title)}</span>
                    <span class="campus-admin-material-type">${this.escape(m.type)}</span>
                    <button type="button" class="ui-btn ui-btn-ghost ui-btn-size-sm" data-action="click->campus-admin-lesson#deleteMaterial" data-material-id="${m.id}">
                        <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">delete</span>
                    </button>
                </div>
            `).join('');
        } catch (e) {
            console.error('[campus-admin-lesson] loadMaterials', e);
        }
    }

    async deleteMaterial(event) {
        const id = parseInt(event.currentTarget.dataset.materialId, 10);
        if (!confirm('¿Eliminar este material?')) return;
        try {
            const r = await fetch(`/api/campus/admin/materials/${id}`, {
                method: 'DELETE',
                headers: this.headers(),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Material eliminado', 'success');
                this.loadMaterials();
            } else {
                if (window.apiToast) window.apiToast('Error al eliminar', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-lesson] deleteMaterial', e);
        }
    }

    materialIcon(type) {
        const map = { pdf: 'picture_as_pdf', video: 'play_circle', image: 'image', link: 'link', doc: 'description' };
        return map[type] || 'attach_file';
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
