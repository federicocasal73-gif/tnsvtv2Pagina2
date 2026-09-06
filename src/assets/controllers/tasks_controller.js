import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint F.3 — Tasks Controller
 *
 * Drives the unified task system (Phase 3) on both the list view and the
 * detail view. The same controller powers `/sanctum/tasks`,
 * `/sanctum/tasks/new`, and `/sanctum/tasks/{id}`.
 *
 * List view targets (used by templates/sanctum/tasks.html.twig):
 *   - tabs (mine / created / all)
 *   - filters (search / status / priority / sort)
 *   - list container with task cards
 *   - count badges (pending / in_progress / submitted / approved / overdue)
 *
 * Detail view targets (templates/sanctum/tasks/show.html.twig):
 *   - actionBar       : status-transition buttons
 *   - submissionsList : history of student submissions
 *   - feedbackContent : mentor grade + comment
 *   - commentsList    : threaded conversation
 *   - submitCard      : student submission form
 *   - gradeForm       : mentor grade form
 *   - commentsCount   : number of comments
 */
export default class extends Controller {
    static targets = [
        // List
        'tabMine', 'tabCreated', 'tabAll',
        'searchInput', 'statusFilter', 'priorityFilter', 'sortSelect',
        'list', 'listCount',
        'countPending', 'countInProgress', 'countSubmitted', 'countApproved', 'countOverdue',
        // Detail
        'detailContainer', 'actionBar',
        'submissionsSection', 'submissionsList',
        'feedbackSection', 'feedbackContent', 'gradeForm',
        'commentsList', 'commentsCount',
        'submitCard',
        // New form
        'newForm', 'newFormCard',
    ];

    static values = {
        userCode: { type: String, default: '' },
        isAdmin: { type: String, default: '0' },
        taskId: { type: Number, default: 0 },
    };

    POLL_INTERVAL = 15000;

    connect() {
        this.currentView = 'mine';
        this.currentFilters = { status: '', priority: '', search: '', sort: 'due_date|asc' };
        this.pollTimer = null;
        this.autoSaveTimer = null;

        // Determine view from URL: /sanctum/tasks/{id}
        const path = location.pathname;
        const m = path.match(/\/sanctum\/tasks\/(\d+)/);
        if (m) {
            this.taskIdValue = parseInt(m[1], 10);
            this.initDetail();
            return;
        }

        // Otherwise we're on the list view
        this.initList();
    }

    disconnect() {
        if (this.pollTimer) clearInterval(this.pollTimer);
    }

    // ── LIST VIEW ──
    initList() {
        this.loadCounts();
        this.loadList();
    }

    switchView(event) {
        const view = event.currentTarget.dataset.view;
        if (this.currentView === view) return;
        this.currentView = view;

        // Update tab visuals
        [this.tabMineTarget, this.tabCreatedTarget, this.tabAllTarget].forEach(t => {
            if (t) t.classList.toggle('is-active', t.dataset.view === view);
        });

        this.loadList();
    }

    onFilterChange() {
        // Debounce
        clearTimeout(this._filterTimer);
        this._filterTimer = setTimeout(() => this.loadList(), 250);
    }

    async loadCounts() {
        try {
            const r = await fetch('/api/tasks/counts', {
                headers: { 'X-Game-Code': this.userCodeValue || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            const c = data.counts || {};
            this.setStat(this.countPendingTarget, c.pending || 0);
            this.setStat(this.countInProgressTarget, c.in_progress || 0);
            this.setStat(this.countSubmittedTarget, c.submitted || 0);
            this.setStat(this.countApprovedTarget, c.approved || 0);
            this.setStat(this.countOverdueTarget, c.overdue || 0);
        } catch (e) {
            console.warn('[tasks] loadCounts error', e);
        }
    }

    setStat(target, value) {
        if (target) target.querySelector('.tasks-stat-value').textContent = value;
    }

    async loadList() {
        const params = new URLSearchParams();
        const search = this.hasSearchInputTarget ? this.searchInputTarget.value.trim() : '';
        const status = this.hasStatusFilterTarget ? this.statusFilterTarget.value : '';
        const priority = this.hasPriorityFilterTarget ? this.priorityFilterTarget.value : '';
        const sort = this.hasSortSelectTarget ? this.sortSelectTarget.value : 'due_date|asc';

        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (priority) params.set('priority', priority);

        const [sortField, sortOrder] = sort.split('|');
        if (sortField) params.set('sort', sortField);
        if (sortOrder) params.set('order', sortOrder);

        const url = this.currentView === 'created'
            ? `/api/tasks/created-by-me?${params}`
            : this.currentView === 'all'
                ? `/api/tasks?${params}`
                : `/api/tasks/mine?${params}`;

        try {
            const r = await fetch(url, {
                headers: { 'X-Game-Code': this.userCodeValue || '' },
            });
            if (!r.ok) {
                this.listTarget.innerHTML = `<p class="tasks-error">Error ${r.status} al cargar</p>`;
                return;
            }
            const data = await r.json();
            const tasks = data.tasks || [];
            if (this.hasListCountTarget) {
                this.listCountTarget.textContent = `${tasks.length} ${tasks.length === 1 ? 'tarea' : 'tareas'}`;
            }
            if (tasks.length === 0) {
                this.listTarget.innerHTML = this.emptyStateHtml();
                return;
            }
            this.listTarget.innerHTML = tasks.map(t => this.taskCardHtml(t)).join('');
            this.bindTaskCardEvents();
        } catch (e) {
            console.error('[tasks] loadList error', e);
            this.listTarget.innerHTML = `<p class="tasks-error">Error de red.</p>`;
        }
    }

    taskCardHtml(t) {
        const isUrgent = t.priority === 'urgent';
        return `
            <a href="/sanctum/tasks/${t.id}" class="task-card ${isUrgent ? 'is-urgent' : ''}" data-task-id="${t.id}">
                <div class="task-card-header">
                    {{html-status-pill}}
                    {{html-priority}}
                    <span class="task-card-when">${this.formatRelative(t.due_date)}</span>
                </div>
                <h3 class="task-card-title">${this.escape(t.title)}</h3>
                ${t.description ? `<p class="task-card-desc">${this.escape(t.description)}</p>` : ''}
                <div class="task-card-meta">
                    ${t.assigned_to ? `<span><span class="material-symbols-elev">person</span> ${this.escape(t.assigned_to)}</span>` : ''}
                    ${t.is_overdue ? '<span class="status-pill status-pill-overdue size-sm">Vencida</span>' : ''}
                    ${t.submissions_count > 0 ? `<span><span class="material-symbols-elev">upload_file</span> ${t.submissions_count} entrega${t.submissions_count !== 1 ? 's' : ''}</span>` : ''}
                    ${t.comments_count > 0 ? `<span><span class="material-symbols-elev">forum</span> ${t.comments_count}</span>` : ''}
                </div>
            </a>
        `.replace('{{html-status-pill}}', this.statusPillHtml(t.status, t.status_label))
         .replace('{{html-priority}}', this.priorityPillHtml(t.priority, t.priority_label));
    }

    statusPillHtml(status, label) {
        return `<span class="status-pill status-pill-${status} size-sm">${this.escape(label)}</span>`;
    }

    priorityPillHtml(priority, label) {
        if (priority === 'normal') return '';
        return `<span class="status-pill size-sm" style="background: var(--glass-bg-strong-elev); color: var(--gold-elev); border: 1px solid var(--gold-container-elev);">${this.escape(label)}</span>`;
    }

    emptyStateHtml() {
        return `
            <div class="empty-state empty-state-md">
                <span class="material-symbols-elev empty-state-icon">task_alt</span>
                <p class="empty-state-message">Sin tareas</p>
                <p class="empty-state-description">No hay tareas con los filtros actuales.</p>
            </div>
        `;
    }

    bindTaskCardEvents() {
        this.listTarget.querySelectorAll('.task-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.metaKey || e.ctrlKey) return;
                e.preventDefault();
                location.href = card.getAttribute('href');
            });
        });
    }

    // ── DETAIL VIEW ──
    initDetail() {
        this.loadSubmissions();
        this.loadFeedback();
        this.loadComments();
        this.renderActionBar();
        this.bindSubmitForm();
        this.bindGradeForm();
        this.startPolling();
    }

    startPolling() {
        // Refresh comments and submissions every 15s
        this.pollTimer = setInterval(() => {
            this.loadSubmissions();
            this.loadComments();
        }, this.POLL_INTERVAL);
    }

    async loadSubmissions() {
        if (!this.taskIdValue) return;
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}`, {
                headers: { 'X-Game-Code': this.userCodeValue || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            this.renderSubmissions(data.submissions || []);
        } catch (e) {
            console.warn('[tasks] loadSubmissions error', e);
        }
    }

    renderSubmissions(subs) {
        if (!this.hasSubmissionsListTarget) return;
        if (subs.length === 0) {
            this.submissionsListTarget.innerHTML = `
                <p class="task-empty-mini">Aún no hay entregas.</p>
            `;
            return;
        }
        this.submissionsListTarget.innerHTML = subs.map(s => this.submissionItemHtml(s)).join('');
    }

    submissionItemHtml(s) {
        const statusLabel = ({ pending: 'Enviada', review: 'En revisión', approved: 'Aprobada', revision: 'Devuelta' })[s.status] || s.status;
        return `
            <div class="task-submission-item">
                <div class="task-submission-icon">
                    <span class="material-symbols-elev">upload_file</span>
                </div>
                <div class="task-submission-body">
                    <div class="task-submission-header">
                        <strong>${this.escape(s.user_name || s.user_code)}</strong>
                        <span class="status-pill status-pill-${s.status === 'approved' ? 'approved' : (s.status === 'revision' ? 'needs-revision' : 'in-review')} size-sm">${statusLabel}</span>
                    </div>
                    <div class="task-submission-meta">${this.formatDateTime(s.submitted_at)}</div>
                    ${s.file_name ? `<div class="task-submission-file"><span class="material-symbols-elev">attach_file</span> ${this.escape(s.file_name)} (${this.formatBytes(s.file_size)})</div>` : ''}
                    ${s.comments ? `<p class="task-submission-comments">${this.escape(s.comments)}</p>` : ''}
                </div>
            </div>
        `;
    }

    async loadFeedback() {
        if (!this.taskIdValue) return;
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}`, {
                headers: { 'X-Game-Code': this.userCodeValue || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            this.renderFeedback(data.feedback || null);
        } catch (e) {
            console.warn('[tasks] loadFeedback error', e);
        }
    }

    renderFeedback(fb) {
        if (!this.hasFeedbackContentTarget) return;
        if (!fb) {
            this.feedbackContentTarget.innerHTML = `
                <p class="task-empty-mini">Sin calificar todavía.</p>
            `;
            return;
        }
        const gradeColor = fb.grade >= 7 ? 'success' : (fb.grade >= 4 ? 'in-progress' : 'needs-revision');
        this.feedbackContentTarget.innerHTML = `
            <div class="task-feedback-card">
                <div class="task-feedback-grade">
                    <span class="task-feedback-grade-value status-pill status-pill-${gradeColor}">${this.escape(fb.grade)}</span>
                    <span class="task-feedback-decision">${this.escape(fb.decision)}</span>
                </div>
                ${fb.comment ? `<p class="task-feedback-comment">${this.escape(fb.comment)}</p>` : ''}
                <div class="task-feedback-meta">
                    <span>Por ${this.escape(fb.grader_name || fb.grader_code)}</span>
                    <span>${this.formatDateTime(fb.graded_at)}</span>
                </div>
            </div>
        `;
    }

    async loadComments() {
        if (!this.taskIdValue) return;
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}`, {
                headers: { 'X-Game-Code': this.userCodeValue || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            this.renderComments(data.comments || []);
        } catch (e) {
            console.warn('[tasks] loadComments error', e);
        }
    }

    renderComments(comments) {
        if (!this.hasCommentsListTarget) return;
        if (this.hasCommentsCountTarget) {
            this.commentsCountTarget.textContent = comments.length > 0 ? `(${comments.length})` : '';
        }
        if (comments.length === 0) {
            this.commentsListTarget.innerHTML = `<p class="task-empty-mini">Sé el primero en comentar.</p>`;
            return;
        }
        this.commentsListTarget.innerHTML = comments.map(c => this.commentItemHtml(c)).join('');
    }

    commentItemHtml(c) {
        return `
            <div class="task-comment">
                <div class="task-comment-header">
                    <strong>${this.escape(c.author_name || c.author_code)}</strong>
                    <span class="task-comment-time">${this.formatDateTime(c.created_at)}</span>
                </div>
                <p class="task-comment-body">${this.escape(c.body)}</p>
            </div>
        `;
    }

    renderActionBar() {
        if (!this.hasActionBarTarget || !this.taskIdValue) return;
        // Buttons depend on current status — fetched via API
        fetch(`/api/tasks/${this.taskIdValue}`, {
            headers: { 'X-Game-Code': this.userCodeValue || '' },
        })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data) return;
                const t = data.task;
                const html = this.actionButtonsHtml(t);
                this.actionBarTarget.innerHTML = html;
                this.bindActionButtons();
            })
            .catch(() => {});
    }

    actionButtonsHtml(t) {
        const status = t.status;
        const isAdmin = this.isAdminValue === '1';
        const userCode = this.userCodeValue;
        const isAssignee = userCode && t.assigned_to === userCode;

        const buttons = [];
        if (isAdmin) {
            if (status !== 'approved' && status !== 'submitted') {
                buttons.push({ status: 'in_review', label: 'Revisar', icon: 'visibility' });
            }
            if (status === 'in_review' || status === 'submitted') {
                buttons.push({ status: 'approved', label: 'Aprobar', icon: 'check_circle', primary: true });
                buttons.push({ status: 'needs_revision', label: 'Devolver', icon: 'undo' });
            }
        } else if (isAssignee) {
            if (status === 'pending' || status === 'needs_revision') {
                buttons.push({ status: 'in_progress', label: 'Empezar', icon: 'play_arrow' });
            }
            if (status === 'in_progress' || status === 'pending') {
                buttons.push({ status: 'submitted', label: 'Marcar entregada', icon: 'check', primary: true });
            }
        }

        return buttons.map(b => `
            <button type="button"
                    class="ui-btn ${b.primary ? 'ui-btn-primary' : 'ui-btn-secondary'} ui-btn-size-md"
                    data-action="click->tasks#changeStatus"
                    data-new-status="${b.status}">
                <span class="material-symbols-elev ui-btn-icon" aria-hidden="true">${b.icon}</span>
                <span class="ui-btn-label">${b.label}</span>
            </button>
        `).join('');
    }

    bindActionButtons() {
        if (!this.hasActionBarTarget) return;
        this.actionBarTarget.querySelectorAll('[data-action*="changeStatus"]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.changeStatus(btn.dataset.newStatus);
            });
        });
    }

    async changeStatus(newStatus) {
        if (!this.taskIdValue) return;
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}/status`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Game-Code': this.userCodeValue || '',
                },
                body: JSON.stringify({ status: newStatus }),
            });
            if (!r.ok) {
                const err = await r.json().catch(() => ({}));
                if (window.apiToast) window.apiToast(err.error || 'Error al cambiar estado', 'error');
                return;
            }
            if (window.apiToast) window.apiToast('Estado actualizado', 'success');
            this.loadSubmissions();
            this.renderActionBar();
        } catch (e) {
            console.error('[tasks] changeStatus error', e);
        }
    }

    bindSubmitForm() {
        if (!this.hasSubmitCardTarget) return;
        const form = this.submitCardTarget.querySelector('form');
        if (!form) return;
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.onSubmit(e);
        });
    }

    async onSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const fd = new FormData(form);
        const comments = fd.get('comments') || '';
        const file = fd.get('file');
        const body = { comments };
        if (file && file.size > 0) {
            if (file.size > 5 * 1024 * 1024) {
                if (window.apiToast) window.apiToast('El archivo excede 5 MB', 'error');
                return;
            }
            body.file_name = file.name;
            body.file_mime = file.type || 'application/octet-stream';
            body.file_size = file.size;
            body.file_data = await this.fileToBase64(file);
        }
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}/submit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Game-Code': this.userCodeValue || '',
                },
                body: JSON.stringify(body),
            });
            if (!r.ok) {
                const err = await r.json().catch(() => ({}));
                if (window.apiToast) window.apiToast(err.error || 'Error al entregar', 'error');
                return;
            }
            if (window.apiToast) window.apiToast('Entrega registrada', 'success');
            form.reset();
            this.loadSubmissions();
            this.renderActionBar();
        } catch (e) {
            console.error('[tasks] onSubmit error', e);
            if (window.apiToast) window.apiToast('Error de red', 'error');
        }
    }

    fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const r = new FileReader();
            r.onload = () => resolve(r.result.split(',')[1]);
            r.onerror = reject;
            r.readAsDataURL(file);
        });
    }

    bindGradeForm() {
        if (!this.hasGradeFormTarget) return;
        const form = this.gradeFormTarget;
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const btn = e.submitter;
            const decision = btn?.dataset.grade || 'approved';
            this.onGrade(form, decision);
        });
    }

    async onGrade(form, decision) {
        const fd = new FormData(form);
        const body = {
            grade: fd.get('grade') || null,
            comment: fd.get('comment') || '',
            decision,
        };
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}/grade`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Game-Code': this.userCodeValue || '',
                },
                body: JSON.stringify(body),
            });
            if (!r.ok) {
                const err = await r.json().catch(() => ({}));
                if (window.apiToast) window.apiToast(err.error || 'Error al calificar', 'error');
                return;
            }
            if (window.apiToast) window.apiToast(decision === 'approved' ? 'Tarea aprobada' : 'Devuelta para corrección', 'success');
            this.loadFeedback();
            this.renderActionBar();
        } catch (e) {
            console.error('[tasks] onGrade error', e);
        }
    }

    async onComment(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const body = form.elements.namedItem('body').value.trim();
        if (!body) return;
        try {
            const r = await fetch(`/api/tasks/${this.taskIdValue}/comments`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Game-Code': this.userCodeValue || '',
                },
                body: JSON.stringify({ body }),
            });
            if (!r.ok) {
                if (window.apiToast) window.apiToast('Error al comentar', 'error');
                return;
            }
            form.reset();
            this.loadComments();
        } catch (e) {
            console.error('[tasks] onComment error', e);
        }
    }

    // ── NEW FORM ──
    initNew() {
        // nothing yet — submit handler binds on submit
    }

    async onCreate(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const fd = new FormData(form);
        const body = {
            title: fd.get('title'),
            description: fd.get('description') || null,
            instructions: fd.get('instructions') || null,
            assigned_to: fd.get('assigned_to'),
            priority: fd.get('priority') || 'normal',
            type: fd.get('type') || 'general',
            estimated_minutes: fd.get('estimated_minutes') ? parseInt(fd.get('estimated_minutes'), 10) : null,
            due_date: fd.get('due_date') ? new Date(fd.get('due_date')).toISOString() : null,
        };
        try {
            const r = await fetch('/api/tasks', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Game-Code': this.userCodeValue || '',
                },
                body: JSON.stringify(body),
            });
            if (!r.ok) {
                const err = await r.json().catch(() => ({}));
                if (window.apiToast) window.apiToast(err.error || 'Error al crear tarea', 'error');
                return;
            }
            const data = await r.json();
            if (window.apiToast) window.apiToast('Tarea creada', 'success');
            location.href = `/sanctum/tasks/${data.id}`;
        } catch (e) {
            console.error('[tasks] onCreate error', e);
            if (window.apiToast) window.apiToast('Error de red', 'error');
        }
    }

    backToList(event) {
        event.preventDefault();
        location.href = '/sanctum/tasks';
    }

    // ── Helpers ──
    escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    formatDateTime(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso);
            return d.toLocaleString('es-AR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return iso;
        }
    }

    formatRelative(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso);
            const now = new Date();
            const diff = (d - now) / 1000;
            if (diff < 0) {
                const days = Math.ceil(-diff / 86400);
                return `Vencida hace ${days}d`;
            }
            if (diff < 3600) return `Vence en ${Math.floor(diff / 60)}min`;
            if (diff < 86400) return `Vence en ${Math.floor(diff / 3600)}h`;
            const days = Math.ceil(diff / 86400);
            return days === 1 ? 'Vence mañana' : `En ${days} días`;
        } catch (e) {
            return '';
        }
    }

    formatBytes(bytes) {
        if (!bytes) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        let n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return `${n.toFixed(1)} ${units[i]}`;
    }
}
