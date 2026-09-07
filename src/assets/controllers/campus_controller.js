import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint C.2 — Campus Controller
 *
 * Drives the full Campus module:
 *   - Dashboard (progreso global, "Continuar donde quedé", lista de cursos)
 *   - Course detail (módulos + lecciones + progreso por lección)
 *   - Lesson view (video + materiales + tareas + marcar completada)
 *
 * Routing is URL-driven:
 *   /campus                  → dashboard
 *   /campus?course=42        → course detail (id 42)
 *   /campus?lesson=123       → lesson view (id 123)
 *
 * On boot the controller inspects `location.search` and dispatches to
 * `renderDashboard()`, `renderCourse()`, or `renderLesson()`.
 */
export default class extends Controller {
    static targets = [
        'dashboard',
        'continueCard',
        'progressBar',
        'progressPct',
        'statCourses',
        'statLessons',
        'statAssignments',
        'coursesGrid',
        'courseDetail',
        'breadcrumb',
        'lessonView',
        'assignmentsList',
        'videoContainer',
        'materialsList',
    ];

    static values = {
        userCode: { type: String, default: '' },
    };

    connect() {
        this.conversations = [];
        this.userCode = this.resolveUserCode();
        this.routeFromUrl();
        window.addEventListener('popstate', () => this.routeFromUrl());
    }

    // ── Routing ──
    routeFromUrl() {
        const params = new URLSearchParams(location.search);
        const lessonId = params.get('lesson');
        const courseId = params.get('course');

        if (lessonId) {
            this.showOnly('lessonView');
            this.loadLesson(parseInt(lessonId, 10));
        } else if (courseId) {
            this.showOnly('courseDetail');
            this.loadCourse(parseInt(courseId, 10));
        } else {
            this.showOnly('dashboard');
            this.loadDashboard();
        }
    }

    navigate(url) {
        history.pushState({}, '', url);
        this.routeFromUrl();
    }

    showOnly(visibleTarget) {
        ['dashboard', 'courseDetail', 'lessonView'].forEach((name) => {
            const el = this[`has${name.charAt(0).toUpperCase() + name.slice(1)}Target`]
                ? this[`${name}Target`]
                : null;
            if (!el) return;
            el.hidden = (name !== visibleTarget);
        });
    }

    // ── Dashboard ──
    async loadDashboard() {
        try {
            await Promise.all([
                this.loadProgress(),
                this.loadContinue(),
                this.loadCourses(),
                this.loadAssignments(),
            ]);
        } catch (e) {
            console.error('[campus] dashboard load error', e);
        }
    }

    async loadProgress() {
        try {
            const r = await fetch('/api/campus/progress');
            if (!r.ok) return;
            const data = await r.json();
            const global = data.global || {};
            const assignments = data.assignments || {};
            const pct = global.progress_percent ?? 0;

            if (this.hasProgressBarTarget) {
                this.progressBarTarget.style.width = pct + '%';
            }
            if (this.hasProgressPctTarget) {
                this.progressPctTarget.textContent = pct + '% completado';
            }
            if (this.hasStatCoursesTarget) {
                this.statCoursesTarget.textContent = (data.courses || []).length;
            }
            if (this.hasStatLessonsTarget) {
                this.statLessonsTarget.textContent =
                    (global.completed_lessons ?? 0) + '/' + (global.total_lessons ?? 0);
            }
            if (this.hasStatAssignmentsTarget) {
                this.statAssignmentsTarget.textContent = assignments.pending ?? 0;
            }
        } catch (e) {}
    }

    async loadContinue() {
        if (!this.hasContinueCardTarget) return;
        try {
            const r = await fetch('/api/campus/continue');
            if (!r.ok) return;
            const data = await r.json();

            if (!data.lesson) {
                this.continueCardTarget.innerHTML = `
                    <div class="campus-continue-empty">
                        <span class="material-symbols-elev text-4xl text-[var(--gold-elev)]">workspace_premium</span>
                        <p class="text-base font-semibold text-[var(--on-surface-elev)]">${this.escapeHtml(data.message || 'Todo al día')}</p>
                        <p class="text-xs text-[var(--outline-elev)]">Revisa los cursos disponibles abajo.</p>
                    </div>
                `;
                return;
            }

            const l = data.lesson;
            const reasonLabel = {
                first_lesson: 'Empieza tu primera lección',
                next_in_module: 'Continúa este módulo',
                next_module: 'Nuevo módulo disponible',
                next_course: 'Siguiente curso',
            }[data.reason] || 'Continúa aprendiendo';

            this.continueCardTarget.innerHTML = `
                <div class="campus-continue-header">
                    <span class="material-symbols-elev text-[var(--gold-elev)]">play_circle</span>
                    <span class="text-xs uppercase tracking-wider text-[var(--gold-elev)] font-bold">${reasonLabel}</span>
                </div>
                <h4 class="campus-continue-title">${this.escapeHtml(l.title)}</h4>
                <p class="campus-continue-meta">
                    <span>${this.escapeHtml(l.course_emoji || '📚')} ${this.escapeHtml(l.course_title || '')}</span>
                    <span class="campus-continue-sep">›</span>
                    <span>${this.escapeHtml(l.module_title || '')}</span>
                </p>
                <a href="/campus?lesson=${l.id}"
                   class="ui-btn ui-btn primary ui-btn-size-md campus-continue-cta"
                   data-action="click->campus#navigateToLesson">
                    Continuar lección
                    <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">arrow_forward</span>
                </a>
            `;
            this.continueCardTarget.style.display = '';
        } catch (e) {
            console.warn('[campus] continue load error', e);
        }
    }

    navigateToLesson(event) {
        event.preventDefault();
        const href = event.currentTarget.getAttribute('href');
        if (href) this.navigate(href);
    }

    navigateToCourse(event) {
        event.preventDefault();
        const href = event.currentTarget.getAttribute('href');
        if (href) this.navigate(href);
    }

    async loadCourses() {
        if (!this.hasCoursesGridTarget) return;
        try {
            const r = await fetch('/api/campus/courses');
            if (!r.ok) {
                this.coursesGridTarget.innerHTML = this.emptyStateHtml('Sin cursos disponibles aún.');
                return;
            }
            const courses = await r.json();
            if (!Array.isArray(courses) || courses.length === 0) {
                this.coursesGridTarget.innerHTML = this.emptyStateHtml('No hay cursos disponibles.');
                return;
            }
            this.coursesGridTarget.innerHTML = courses.map(c => this.courseCardHtml(c)).join('');
            this.bindCourseCardClicks();
        } catch (e) {
            this.coursesGridTarget.innerHTML = this.errorStateHtml(e);
        }
    }

    courseCardHtml(c) {
        const completed = c.completed_lessons || 0;
        const total = c.total_lessons || 0;
        const pct = c.progress || 0;
        const stateClass = pct >= 100 ? 'is-completed' : (pct > 0 ? 'is-in-progress' : 'is-pending');
        return `
            <a href="/campus?course=${c.id}"
               class="campus-course-card ${stateClass}"
               data-action="click->campus#navigateToCourse">
                <div class="campus-course-card-header">
                    <span class="campus-course-emoji">${this.escapeHtml(c.emoji || '📚')}</span>
                    <span class="status-pill status-pill-${pct >= 100 ? 'approved' : (pct > 0 ? 'in-progress' : 'pending')}">${pct}%</span>
                </div>
                <h4 class="campus-course-title">${this.escapeHtml(c.title || '')}</h4>
                ${c.description ? `<p class="campus-course-desc">${this.escapeHtml(c.description)}</p>` : ''}
                <div class="progress-bar progress-bar-md progress-bar-gold">
                    <div class="progress-bar-label">
                        <span>${completed} / ${total} lecciones</span>
                    </div>
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" style="width: ${pct}%"></div>
                    </div>
                </div>
            </a>
        `;
    }

    bindCourseCardClicks() {
        this.coursesGridTarget.querySelectorAll('a[data-action*="navigateToCourse"]').forEach(a => {
            a.addEventListener('click', (e) => {
                if (e.defaultPrevented) return;
                e.preventDefault();
                this.navigate(a.getAttribute('href'));
            });
        });
    }

    async loadAssignments() {
        // Inherited from previous implementation; render list under dashboard
        // (no dedicated target — assignment list lives below courses)
        // Skipped here for now as Phase 2 focuses on the learning flow
    }

    // ── Course detail ──
    async loadCourse(courseId) {
        try {
            const r = await fetch(`/api/campus/courses/${courseId}`);
            if (!r.ok) {
                if (this.hasCourseDetailTarget) {
                    this.courseDetailTarget.innerHTML = `<p class="campus-error">Curso no encontrado.</p>`;
                }
                return;
            }
            const course = await r.json();
            this.renderCourseDetail(course);
        } catch (e) {
            console.error('[campus] course load error', e);
        }
    }

    renderCourseDetail(course) {
        if (this.hasBreadcrumbTarget) {
            this.breadcrumbTarget.innerHTML = `
                <a href="/campus" data-action="click->campus#navigateToCourse">← Volver al Campus</a>
                <span class="campus-breadcrumb-sep">›</span>
                <span>${this.escapeHtml(course.title || '')}</span>
            `;
            this.breadcrumbTarget.querySelector('a').addEventListener('click', (e) => {
                e.preventDefault();
                this.navigate('/campus');
            });
        }
        if (!this.hasCourseDetailTarget) return;

        const totalLessons = course.modules.reduce((sum, m) => sum + m.lessons.length, 0);
        const completed = course.modules.reduce(
            (sum, m) => sum + m.lessons.filter(l => l.completed).length, 0
        );
        const pct = totalLessons > 0 ? Math.round((completed / totalLessons) * 100) : 0;

        this.courseDetailTarget.innerHTML = `
            <div class="campus-course-hero">
                <span class="campus-course-hero-emoji">${this.escapeHtml(course.emoji || '📚')}</span>
                <div class="campus-course-hero-body">
                    <h2 class="campus-course-hero-title">${this.escapeHtml(course.title || '')}</h2>
                    ${course.description ? `<p class="campus-course-hero-desc">${this.escapeHtml(course.description)}</p>` : ''}
                    <div class="campus-course-hero-meta">
                        <span><span class="material-symbols-elev text-base">layers</span> ${course.modules.length} módulos</span>
                        <span><span class="material-symbols-elev text-base">play_lesson</span> ${totalLessons} lecciones</span>
                        <span><span class="material-symbols-elev text-base">workspace_premium</span> ${pct}% completado</span>
                    </div>
                    <div class="progress-bar progress-bar-lg progress-bar-gold">
                        <div class="progress-bar-track">
                            <div class="progress-bar-fill" style="width: ${pct}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="campus-modules">
                ${course.modules.map(m => this.moduleHtml(m)).join('')}
            </div>
        `;

        this.courseDetailTarget.querySelectorAll('.campus-lesson-row').forEach(row => {
            row.addEventListener('click', (e) => {
                e.preventDefault();
                const lessonId = parseInt(row.dataset.lessonId, 10);
                if (lessonId) this.navigate(`/campus?lesson=${lessonId}`);
            });
        });
    }

    moduleHtml(m) {
        const completed = m.lessons.filter(l => l.completed).length;
        const total = m.lessons.length;
        const pct = total > 0 ? Math.round((completed / total) * 100) : 0;
        return `
            <details class="campus-module" open>
                <summary class="campus-module-summary">
                    <div class="campus-module-info">
                        <h3 class="campus-module-title">${this.escapeHtml(m.title)}</h3>
                        ${m.description ? `<p class="campus-module-desc">${this.escapeHtml(m.description)}</p>` : ''}
                    </div>
                    <div class="campus-module-progress">
                        <span class="status-pill status-pill-${pct >= 100 ? 'approved' : (pct > 0 ? 'in-progress' : 'pending')}">${completed}/${total}</span>
                        <span class="material-symbols-elev campus-module-caret">expand_more</span>
                    </div>
                </summary>
                <ul class="campus-module-lessons">
                    ${m.lessons.map(l => this.lessonRowHtml(l)).join('')}
                </ul>
            </details>
        `;
    }

    lessonRowHtml(l) {
        const hasAssignment = l.has_assignment;
        const icon = l.completed ? 'check_circle' : 'play_circle';
        const stateClass = l.completed ? 'is-completed' : (l.has_assignment ? 'has-assignment' : 'is-pending');
        return `
            <li class="campus-lesson-row ${stateClass}" data-lesson-id="${l.id}" tabindex="0" role="button" aria-label="Abrir lección ${this.escapeHtml(l.title)}">
                <span class="material-symbols-elev campus-lesson-icon">${icon}</span>
                <div class="campus-lesson-body">
                    <div class="campus-lesson-title">${this.escapeHtml(l.title)}</div>
                    ${hasAssignment ? '<span class="campus-lesson-badge">Tiene tarea</span>' : ''}
                </div>
                <span class="material-symbols-elev campus-lesson-arrow">chevron_right</span>
            </li>
        `;
    }

    // ── Lesson view ──
    async loadLesson(lessonId) {
        try {
            const r = await fetch(`/api/campus/lessons/${lessonId}`);
            if (!r.ok) {
                if (this.hasLessonViewTarget) {
                    this.lessonViewTarget.innerHTML = `<p class="campus-error">Lección no encontrada.</p>`;
                }
                return;
            }
            const lesson = await r.json();
            this.renderLesson(lesson);
        } catch (e) {
            console.error('[campus] lesson load error', e);
        }
    }

    renderLesson(lesson) {
        if (this.hasBreadcrumbTarget) {
            this.breadcrumbTarget.innerHTML = `
                <a href="/campus" data-action="click->campus#navigateToCourse">← Volver al Campus</a>
                <span class="campus-breadcrumb-sep">›</span>
                <span>${this.escapeHtml(lesson.title || 'Lección')}</span>
            `;
            this.breadcrumbTarget.querySelector('a').addEventListener('click', (e) => {
                e.preventDefault();
                this.navigate('/campus');
            });
        }
        if (!this.hasLessonViewTarget) return;

        const videoHtml = this.videoEmbedHtml(lesson.video_url);
        const completed = !!lesson.completed;

        this.lessonViewTarget.innerHTML = `
            <article class="campus-lesson-view">
                <header class="campus-lesson-header">
                    <h1 class="campus-lesson-h1">${this.escapeHtml(lesson.title)}</h1>
                    ${lesson.description ? `<p class="campus-lesson-desc">${this.escapeHtml(lesson.description)}</p>` : ''}
                    <div class="campus-lesson-status">
                        <span class="status-pill status-pill-${completed ? 'approved' : 'in-progress'}">
                            ${completed ? '✓ Completada' : 'En curso'}
                        </span>
                    </div>
                </header>

                ${videoHtml ? `<section class="campus-lesson-video" data-target="campus.videoContainer">${videoHtml}</section>` : ''}

                ${lesson.materials && lesson.materials.length > 0 ? `
                    <section class="campus-lesson-section" data-target="campus.materialsList">
                        <h2 class="campus-section-title">
                            <span class="material-symbols-elev">attach_file</span>
                            Materiales
                        </h2>
                        <ul class="campus-materials-list">
                            ${lesson.materials.map(m => this.materialHtml(m)).join('')}
                        </ul>
                    </section>
                ` : ''}

                ${lesson.assignments && lesson.assignments.length > 0 ? `
                    <section class="campus-lesson-section" data-target="campus.assignmentsList">
                        <h2 class="campus-section-title">
                            <span class="material-symbols-elev">task_alt</span>
                            Tareas
                        </h2>
                        <div class="campus-assignments">
                            ${lesson.assignments.map(a => this.assignmentHtml(a, lesson.my_submission)).join('')}
                        </div>
                    </section>
                ` : ''}

                <footer class="campus-lesson-footer">
                    <button type="button"
                            class="ui-btn ui-btn primary ui-btn-size-lg campus-mark-complete"
                            data-action="click->campus#markComplete"
                            data-lesson-id="${lesson.id}"
                            ${completed ? 'disabled' : ''}>
                        <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">
                            ${completed ? 'check_circle' : 'task_alt'}
                        </span>
                        ${completed ? 'Lección completada' : 'Marcar como completada'}
                    </button>
                </footer>
            </article>
        `;

        this.lessonViewTarget.querySelectorAll('[data-action="click->campus#markComplete"]').forEach(btn => {
            btn.addEventListener('click', () => this.markComplete(btn));
        });
    }

    videoEmbedHtml(url) {
        if (!url) return '';
        // YouTube detection
        const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([\w-]{6,})/);
        if (yt) {
            return `<iframe class="campus-video-iframe"
                src="https://www.youtube.com/embed/${yt[1]}?rel=0"
                title="Video"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen></iframe>`;
        }
        // Vimeo
        const vimeo = url.match(/vimeo\.com\/(\d+)/);
        if (vimeo) {
            return `<iframe class="campus-video-iframe"
                src="https://player.vimeo.com/video/${vimeo[1]}"
                title="Video"
                frameborder="0"
                allow="autoplay; fullscreen; picture-in-picture"
                allowfullscreen></iframe>`;
        }
        // Direct file
        return `<video class="campus-video-native" controls preload="metadata" playsinline>
            <source src="${this.escapeHtml(url)}">
            Tu navegador no soporta video HTML5.
        </video>`;
    }

    materialHtml(m) {
        const icon = this.materialIcon(m.type);
        return `
            <li class="campus-material-item">
                <span class="material-symbols-elev campus-material-icon">${icon}</span>
                <a href="${this.escapeHtml(m.url)}" target="_blank" rel="noopener" class="campus-material-link">
                    ${this.escapeHtml(m.title)}
                </a>
                <span class="campus-material-type">${this.escapeHtml(m.type)}</span>
            </li>
        `;
    }

    materialIcon(type) {
        const map = {
            pdf: 'picture_as_pdf',
            video: 'play_circle',
            image: 'image',
            link: 'link',
            doc: 'description',
        };
        return map[type] || 'attach_file';
    }

    assignmentHtml(a, submission) {
        const sub = submission && submission.id ? submission : null;
        const status = sub ? sub.status : 'pending';
        const statusLabel = {
            pending: 'Pendiente',
            submitted: 'Entregada',
            corrected: 'Calificada',
            revision: 'En revisión',
            completed: 'Aprobada',
        }[status] || status;
        return `
            <article class="campus-assignment-card">
                <header class="campus-assignment-header">
                    <h3 class="campus-assignment-title">${this.escapeHtml(a.title)}</h3>
                    <span class="status-pill status-pill-${status === 'completed' ? 'approved' : (status === 'submitted' ? 'in-review' : (status === 'revision' ? 'needs-revision' : 'pending'))}">${statusLabel}</span>
                </header>
                ${a.description ? `<p class="campus-assignment-desc">${this.escapeHtml(a.description)}</p>` : ''}
                ${a.objective ? `<p class="campus-assignment-meta"><strong>Objetivo:</strong> ${this.escapeHtml(a.objective)}</p>` : ''}
                ${a.instructions ? `<pre class="campus-assignment-instructions">${this.escapeHtml(a.instructions)}</pre>` : ''}
                <div class="campus-assignment-footer">
                    ${a.due_date ? `<span class="campus-assignment-due"><span class="material-symbols-elev">schedule</span> Vence: ${this.escapeHtml(new Date(a.due_date).toLocaleDateString())}</span>` : ''}
                    ${a.estimated_minutes ? `<span class="campus-assignment-time"><span class="material-symbols-elev">timer</span> ~${a.estimated_minutes} min</span>` : ''}
                </div>
                ${sub && sub.comments ? `<p class="campus-assignment-my-comments">Tu entrega: ${this.escapeHtml(sub.comments)}</p>` : ''}
            </article>
        `;
    }

    async markComplete(btn) {
        const lessonId = parseInt(btn.dataset.lessonId, 10);
        if (!lessonId) return;

        btn.disabled = true;
        btn.querySelector('.material-symbols-elev').textContent = 'progress_activity';
        const labelSpan = btn.querySelector('.ui-btn-label') || btn;
        const origLabel = btn.textContent.trim();
        btn.dataset.origLabel = origLabel;

        try {
            const r = await fetch(`/api/campus/lessons/${lessonId}/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_code: this.userCode }),
            });
            if (r.ok) {
                btn.classList.add('is-success');
                btn.querySelector('.material-symbols-elev').textContent = 'check_circle';
                // Reload the lesson to update state
                await this.loadLesson(lessonId);
                // Announce success
                if (window.apiToast) window.apiToast('Lección completada', 'success');
            } else {
                btn.disabled = false;
                btn.querySelector('.material-symbols-elev').textContent = 'task_alt';
                if (window.apiToast) window.apiToast('No se pudo marcar como completada', 'error');
            }
        } catch (e) {
            btn.disabled = false;
            btn.querySelector('.material-symbols-elev').textContent = 'task_alt';
            console.error('[campus] markComplete error', e);
        }
    }

    // ── Helpers ──
    resolveUserCode() {
        if (window.TNSVT_USER?.code) return window.TNSVT_USER.code;
        const m = document.body?.dataset?.userCode;
        if (m) return m;
        return '';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    emptyStateHtml(message) {
        return `<div class="empty-state empty-state-md" role="status">
            <span class="material-symbols-elev empty-state-icon" aria-hidden="true">school</span>
            <p class="empty-state-message">${this.escapeHtml(message)}</p>
        </div>`;
    }

    errorStateHtml(e) {
        return `<div class="empty-state empty-state-md" role="status">
            <span class="material-symbols-elev empty-state-icon" aria-hidden="true">error</span>
            <p class="empty-state-message">No se pudo cargar.</p>
            <p class="empty-state-description">${this.escapeHtml(e?.message || 'Error desconocido')}</p>
        </div>`;
    }
}
