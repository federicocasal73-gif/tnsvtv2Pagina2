import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.loadProgress();
        this.loadCourses();
        this.loadAssignments();
    }

    async loadProgress() {
        try {
            const r = await fetch('/api/campus/progress');
            const data = await r.json();

            if (!data.success) return;

            const pct = data.progress || 0;
            const progressBar = document.getElementById('campus-progress-bar');
            const progressPct = document.getElementById('campus-progress-pct');

            if (progressBar) progressBar.style.width = pct + '%';
            if (progressPct) progressPct.textContent = pct + '% completado';

            const statCourses = document.getElementById('stat-courses');
            if (statCourses) statCourses.textContent = data.courses || 0;

            const statLessons = document.getElementById('stat-lessons');
            if (statLessons) statLessons.textContent = data.lessons_completed + '/' + data.lessons_total;

            const statAssignments = document.getElementById('stat-assignments');
            if (statAssignments) statAssignments.textContent = data.assignments_pending || 0;
        } catch (e) {}
    }

    async loadCourses() {
        const coursesEl = document.getElementById('campus-courses');
        if (!coursesEl) return;

        try {
            const r = await fetch('/api/campus/courses');
            const data = await r.json();

            if (!data || data.length === 0) {
                coursesEl.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-2">No hay cursos disponibles.</p>';
                return;
            }

            coursesEl.innerHTML = data.map(c => `
                <div class="glass-card-elev campus-card" onclick="location.href='/campus?course=${c.id}'">
                    <div class="campus-emoji">${c.emoji || '📚'}</div>
                    <div class="campus-title">${c.title}</div>
                    <div class="campus-desc">${c.descripcion || ''}</div>
                    <div class="campus-progress"><div class="campus-progress-fill" style="width:${c.progress || 0}%"></div></div>
                </div>
            `).join('');
        } catch (e) {
            coursesEl.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-2">Error al cargar.</p>';
        }
    }

    async loadAssignments() {
        const assignmentsEl = document.getElementById('campus-assignments');
        if (!assignmentsEl) return;

        try {
            const r = await fetch('/api/campus/assignments/pending');
            const data = await r.json();

            if (!data || data.length === 0) {
                assignmentsEl.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">No hay tareas pendientes.</p>';
                return;
            }

            assignmentsEl.innerHTML = data.map(a => `
                <div class="glass-card-elev assignment-item">
                    <div class="assignment-status ${a.status}"></div>
                    <div class="flex-1">
                        <div class="text-sm font-medium">${a.title}</div>
                        <div class="text-xs text-[var(--outline-elev)]">${a.course_title || ''} · Vence: ${a.due_date || '—'}</div>
                    </div>
                    <button class="btn-primary text-xs" onclick="location.href='/campus?assignment=${a.id}'">Ver</button>
                </div>
            `).join('');
        } catch (e) {
            assignmentsEl.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }
}
