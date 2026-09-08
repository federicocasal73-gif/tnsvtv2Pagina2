import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint G.4 — Submissions grading queue.
 *
 * Lists pending submissions (across all courses) so the admin can grade
 * them one by one. Filters by status with tab buttons.
 */
export default class extends Controller {
    static targets = [
        'list',
        'tabAll', 'tabPending', 'tabApproved', 'tabRevision',
    ];

    static values = {
        token: { type: String, default: '' },
    };

    connect() {
        this.currentFilter = 'all';
        this.loadAll();
    }

    loadAll() { this.currentFilter = 'all'; this.loadList(); }
    loadPending() { this.currentFilter = 'pending'; this.loadList(); }
    loadApproved() { this.currentFilter = 'approved'; this.loadList(); }
    loadRevision() { this.currentFilter = 'revision'; this.loadList(); }

    async loadList() {
        if (!this.hasListTarget) return;
        try {
            // The admin submissions endpoint supports filtering via query
            const params = new URLSearchParams();
            if (this.currentFilter !== 'all') {
                params.set('status', this.currentFilter);
            }
            const r = await fetch(`/api/campus/admin/submissions?${params}`, { headers: this.headers() });
            if (!r.ok) {
                this.listTarget.innerHTML = `<p class="empty-state empty-state-md"><span class="material-symbols-elev empty-state-icon">error</span><p class="empty-state-message">Error ${r.status}</p></p>`;
                return;
            }
            const json = await r.json();
            // API returns {data, total, page, limit} — accept a raw array too
            // for backward compatibility with older responses.
            const submissions = Array.isArray(json) ? json : (json.data || []);
            if (!Array.isArray(submissions) || submissions.length === 0) {
                this.listTarget.innerHTML = `
                    <div class="empty-state empty-state-md">
                        <span class="material-symbols-elev empty-state-icon">inbox</span>
                        <p class="empty-state-message">Bandeja vacía</p>
                    </div>`;
                return;
            }
            this.listTarget.innerHTML = submissions.map(s => this.rowHtml(s)).join('');
        } catch (e) {
            console.error('[campus-admin-submissions] loadList', e);
        }
    }

    rowHtml(s) {
        const statusLabel = ({
            pending: 'Pendiente',
            submitted: 'Enviada',
            approved: 'Aprobada',
            corrected: 'Calificada',
            revision: 'Devuelta',
            completed: 'Completada',
        })[s.status] || s.status;
        const statusClass = ({
            pending: 'pending',
            submitted: 'in-progress',
            approved: 'approved',
            corrected: 'approved',
            revision: 'needs-revision',
            completed: 'approved',
        })[s.status] || 'pending';

        return `
            <article class="campus-admin-submission" data-submission-id="${s.id}">
                <header class="campus-admin-submission-header">
                    <div>
                        <h3 class="ui-card-title">${this.escape(s.user_code || 'Usuario')}</h3>
                        <p class="campus-admin-submission-meta">
                            <span class="status-pill status-pill-${statusClass} size-sm">${statusLabel}</span>
                            <span>${s.submitted_at ? new Date(s.submitted_at).toLocaleString('es-AR') : ''}</span>
                        </p>
                    </div>
                </header>
                ${s.comments ? `<p class="campus-admin-submission-comments">${this.escape(s.comments)}</p>` : ''}
                ${s.files && s.files.length > 0 ? `
                    <div class="campus-admin-submission-files">
                        ${s.files.map(f => `<a href="${this.escape(f.url)}" target="_blank" rel="noopener" class="ui-btn ui-btn ghost ui-btn-size-sm"><span class="material-symbols-elev ui-btn-icon">attach_file</span> ${this.escape(f.name || 'archivo')}</a>`).join('')}
                    </div>
                ` : ''}
                <footer class="campus-admin-submission-actions">
                    <button type="button" class="ui-btn ui-btn primary ui-btn-size-sm"
                            data-action="click->campus-admin-submissions#approve"
                            data-submission-id="${s.id}">
                        <span class="material-symbols-elev ui-btn-icon">check_circle</span>
                        Aprobar
                    </button>
                    <button type="button" class="ui-btn ui-btn secondary ui-btn-size-sm"
                            data-action="click->campus-admin-submissions#returnForRevision"
                            data-submission-id="${s.id}">
                        <span class="material-symbols-elev ui-btn-icon">undo</span>
                        Devolver
                    </button>
                </footer>
            </article>
        `;
    }

    async grade(submissionId, decision) {
        const grade = prompt('Nota (0–10):', decision === 'approved' ? '8' : '');
        const comment = prompt('Comentario (opcional):', '') || '';
        try {
            const r = await fetch(`/api/campus/admin/submissions/${submissionId}/grade`, {
                method: 'POST',
                headers: { ...this.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ grade, comment, decision }),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast(decision === 'approved' ? 'Aprobada' : 'Devuelta para corrección', 'success');
                this.loadList();
            } else {
                if (window.apiToast) window.apiToast('Error al calificar', 'error');
            }
        } catch (e) {
            console.error('[campus-admin-submissions] grade', e);
        }
    }

    approve(event) {
        this.grade(parseInt(event.currentTarget.dataset.submissionId, 10), 'approved');
    }

    returnForRevision(event) {
        this.grade(parseInt(event.currentTarget.dataset.submissionId, 10), 'revision');
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
