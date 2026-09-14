import { Controller } from '@hotwired/stimulus';

/**
 * campus-admin-user-detail — Perfil de alumno.
 * Combina /api/campus/admin/user-progress (cursos + entregas) con
 * /api/tasks?assigned_to= (mentoría). Reutiliza la fórmula round(completed/total*100).
 */
export default class extends Controller {
  static targets = ['heroProgress', 'statLessons', 'statSubs', 'statGrade', 'statTasks', 'coursesGrid', 'campusTasks', 'mentoringTasks', 'tabBtn', 'tabPanel'];
  static values = { userCode: String };

  connect() {
    this.userCode = this.element.dataset.userCode;
    this.loadProgress();
    this.loadMentoring();
  }

  showTab(e) {
    const tab = e.currentTarget.dataset.tab;
    this.tabBtnTargets.forEach((b) => b.setAttribute('aria-selected', b.dataset.tab === tab ? 'true' : 'false'));
    this.tabPanelTargets.forEach((p) => { p.hidden = p.dataset.panel !== tab; });
  }

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  paintHero(pct) {
    if (!this.hasHeroProgressTarget) return;
    const fill = this.heroProgressTarget.querySelector('.progress-bar-fill');
    const val = this.heroProgressTarget.querySelector('.progress-bar-value');
    if (fill) fill.style.width = `${pct}%`;
    if (val) val.textContent = `${pct}%`;
    this.heroProgressTarget.querySelector('.progress-bar')?.setAttribute('aria-valuenow', String(pct));
    this.heroProgressTarget.setAttribute('aria-valuenow', String(pct));
  }

  async loadProgress() {
    const res = await window.apiFetch(`/api/campus/admin/user-progress?user_code=${encodeURIComponent(this.userCode)}`, { silent: true });
    if (!res.ok || !res.data) return;
    const d = res.data;
    const t = d.totals || {};
    const pct = t.progress_percent ?? 0;
    this.paintHero(pct);
    if (this.hasStatLessonsTarget) this.statLessonsTarget.textContent = `${t.completed_lessons ?? 0}/${t.total_lessons ?? 0}`;
    if (this.hasStatSubsTarget) this.statSubsTarget.textContent = `${t.total_submissions ?? 0}`;
    if (this.hasStatGradeTarget) this.statGradeTarget.textContent = t.average_grade ?? '—';

    const courses = d.courses || [];
    if (this.hasCoursesGridTarget) {
      this.coursesGridTarget.innerHTML = courses.map((c) => {
        const p = c.progress_percent ?? 0;
        return `<article class="ui-card ui-card-elevated ui-card-padding-md">
          <header class="ui-card-header"><h3 class="ui-card-title">${this.esc(c.course_emoji)} ${this.esc(c.course_title)}</h3></header>
          <div class="progress-bar progress-bar-md progress-bar-gold" role="progressbar" aria-valuenow="${p}" aria-valuemin="0" aria-valuemax="100" aria-label="${this.esc(c.course_title)}: ${p}%">
            <div class="progress-bar-label"><span>Progreso</span><span class="progress-bar-value">${p}%</span></div>
            <div class="progress-bar-track"><div class="progress-bar-fill" style="width: ${p}%"></div></div>
          </div>
          <p class="text-xs opacity-80">${c.completed_lessons ?? 0}/${c.total_lessons ?? 0} lecciones · ${(c.submissions || []).length} entregas</p>
        </article>`;
      }).join('') || '<p class="text-sm opacity-70">Sin cursos.</p>';
    }

    if (this.hasCampusTasksTarget) {
      const allSubs = courses.flatMap((c) => (c.submissions || []).map((s) => ({ ...s, _course: c.course_title })));
      this.campusTasksTarget.innerHTML = allSubs.length === 0
        ? '<p class="text-sm opacity-70">Sin entregas de campus.</p>'
        : `<table class="ui-table"><thead><tr><th>Curso</th><th>Tarea</th><th>Estado</th><th>Nota</th><th>Fecha</th></tr></thead><tbody>${allSubs.map((s) => `<tr><td>${this.esc(s._course)}</td><td>${this.esc(s.assignment_title)}</td><td><span class="status-pill status-pill-${this.esc(s.status)} size-sm">${this.esc(s.status)}</span></td><td>${s.grade ?? '—'}</td><td class="text-xs">${this.esc(s.submitted_at || '')}</td></tr>`).join('')}</tbody></table>`;
    }
  }

  async loadMentoring() {
    const res = await window.apiFetch(`/api/tasks?assigned_to=${encodeURIComponent(this.userCode)}&active=0`, { silent: true });
    if (!res.ok || !res.data) return;
    const tasks = res.data.tasks || [];
    if (this.hasStatTasksTarget) this.statTasksTarget.textContent = `${tasks.length}`;
    if (!this.hasMentoringTasksTarget) return;
    this.mentoringTasksTarget.innerHTML = tasks.length === 0
      ? '<p class="text-sm opacity-70">Sin tasks de mentoría.</p>'
      : `<table class="ui-table"><thead><tr><th>Tarea</th><th>Estado</th><th>Prioridad</th><th>Vence</th></tr></thead><tbody>${tasks.map((t) => `<tr><td><a href="/sanctum/tasks/${t.id}">${this.esc(t.title)}</a></td><td><span class="status-pill status-pill-${this.esc(t.status)} size-sm">${this.esc(t.status)}</span></td><td>${this.esc(t.priority || '—')}</td><td class="text-xs">${this.esc(t.due_date || t.dueDate || '')}</td></tr>`).join('')}</tbody></table>`;
  }
}
