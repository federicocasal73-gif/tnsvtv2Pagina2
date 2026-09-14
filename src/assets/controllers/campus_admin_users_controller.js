import { Controller } from '@hotwired/stimulus';

/**
 * campus-admin-users — Progreso bulk para 50+ alumnos.
 * 1 llamada a /api/campus/admin/overview (5 queries) en vez de N llamadas a user-progress.
 * Mejora progresiva: pinta las barras sobre las cards server-side y añade search/sort/paginación.
 */
export default class extends Controller {
  static targets = ['grid', 'search', 'sort', 'prev', 'next', 'pageInfo', 'count'];

  connect() {
    this.page = 1;
    this.limit = 20;
    this.sort = 'name_asc';
    this.search = '';
    this._deb = null;
    this.load();
  }

  onSearch() {
    clearTimeout(this._deb);
    this._deb = setTimeout(() => {
      this.search = this.searchTarget.value.trim();
      this.page = 1;
      this.load();
    }, 300);
  }

  onSort() {
    this.sort = this.sortTarget.value;
    this.page = 1;
    this.load();
  }

  prev() { if (this.page > 1) { this.page -= 1; this.load(); } }
  next() { this.page += 1; this.load(); }

  async load() {
    const params = new URLSearchParams({
      page: String(this.page),
      limit: String(this.limit),
      sort: this.sort,
    });
    if (this.search) params.set('search', this.search);
    const res = await window.apiFetch(`/api/campus/admin/overview?${params.toString()}`, { silent: true });
    if (!res.ok || !res.data) return;
    this.render(res.data);
  }

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  render(data) {
    const users = data.users || [];
    if (this.hasCountTarget) this.countTarget.textContent = `${data.total ?? users.length} alumnos · ${data.total_lessons_catalog ?? 0} lecciones`;
    if (this.hasPageInfoTarget) this.pageInfoTarget.textContent = `Página ${data.page ?? this.page} / ${data.pages ?? 1}`;
    if (this.hasPrevTarget) this.prevTarget.disabled = (data.page ?? 1) <= 1;
    if (this.hasNextTarget) this.nextTarget.disabled = (data.page ?? 1) >= (data.pages ?? 1);

    if (!this.hasGridTarget) return;
    if (users.length === 0) {
      this.gridTarget.innerHTML = '<p class="text-sm opacity-70">Sin alumnos para este filtro.</p>';
      return;
    }
    this.gridTarget.innerHTML = users.map((u) => {
      const pct = u.progress_percent ?? 0;
      const avg = u.average_grade !== null && u.average_grade !== undefined ? ` · Nota ${this.esc(u.average_grade)}` : '';
      const initial = this.esc((u.name || u.code || '?').slice(0, 1).toUpperCase());
      return `<article class="ui-card ui-card-elevated ui-card-padding-md campus-admin-user-card" data-user-code="${this.esc(u.code)}">
        <header class="ui-card-header">
          <div class="campus-admin-user-avatar"><span>${initial}</span></div>
          <div><h3 class="ui-card-title">${this.esc(u.name || '—')}</h3>
          <p class="campus-admin-user-code">${this.esc(u.code)}</p></div>
        </header>
        <div class="campus-admin-user-meta">
          <span class="status-pill status-pill-${this.esc(u.tier || 'INITIATE')} size-md" role="status">${this.esc(u.tier || 'INITIATE')}</span>
          <span class="text-xs">${this.esc(u.email || '')}</span>
        </div>
        <div class="progress-bar progress-bar-md progress-bar-gold" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100" aria-label="Progreso general: ${pct}%">
          <div class="progress-bar-label"><span>Progreso general</span><span class="progress-bar-value">${pct}%</span></div>
          <div class="progress-bar-track"><div class="progress-bar-fill" style="width: ${pct}%"></div></div>
        </div>
        <p class="text-xs opacity-80">${u.completed_lessons ?? 0}/${u.total_lessons ?? 0} lecciones · ${u.submissions_count ?? 0} entregas${avg}</p>
        <footer class="campus-admin-user-actions">
          <a href="/sanctum/campus/admin/users/${this.esc(u.code)}" class="ui-btn ui-btn secondary ui-btn-size-sm">
            <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">visibility</span> Ver detalle
          </a>
        </footer>
      </article>`;
    }).join('');
  }
}
