import { Controller } from '@hotwired/stimulus';

const ICONS = {
    comment: 'comment',
    like: 'favorite',
    post: 'campaign',
    mention: 'alternate_email',
    signal: 'trending_up',
    dm: 'chat_bubble',
    academia: 'school',
    task: 'task_alt',
    task_graded: 'verified',
    task_overdue: 'warning',
    task_revision_requested: 'undo',
    achievement_unlocked: 'emoji_events',
    access_request: 'person_add',
    access_accepted: 'check_circle',
    access_rejected: 'cancel',
    connection_removed: 'person_off',
    permissions_changed: 'lock_open',
    economic_alert: 'event',
};

const LINKS = {
    comment: '/feed',
    like: '/feed',
    post: '/feed',
    mention: '/feed',
    signal: '/calendar',
    dm: '/chat',
    academia: '/sanctum',
    task: '/sanctum/tasks',
    task_graded: '/sanctum/tasks',
    task_overdue: '/sanctum/tasks',
    task_revision_requested: '/sanctum/tasks',
    achievement_unlocked: '/sanctum/campus',
    access_request: '/sanctum/social',
    access_accepted: '/sanctum/social',
    access_rejected: '/sanctum/social',
    connection_removed: '/sanctum/social',
    permissions_changed: '/sanctum/social',
    economic_alert: '/calendar',
};

export default class extends Controller {
    static targets = ['list', 'unread', 'markAll', 'bulkApply', 'bulkCount'];

    connect() {
        this.filter = 'all';
        this.bulkMode = false;
        this.bulkSelected = new Set();
        if (window.TNSVT_USER) {
            this.load();
        } else {
            window.addEventListener('tnsvt:user-loaded', () => this.load(), { once: true });
        }
        // F5: filter chip clicks
        this.element.querySelectorAll('.notif-filter').forEach(btn => {
            btn.addEventListener('click', () => this.setFilter(btn.dataset.filter));
        });
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || '';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    setFilter(name) {
        this.filter = name || 'all';
        this.element.querySelectorAll('.notif-filter').forEach((b) => {
            const active = b.dataset.filter === this.filter;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        this.render();
    }

    toggleBulk() {
        this.bulkMode = !this.bulkMode;
        this.bulkSelected.clear();
        const btn = this.element.querySelector('.notif-bulk-btn');
        const apply = this.bulkApplyTarget;
        if (btn) {
            const lbl = btn.querySelector('.notif-bulk-label');
            if (lbl) lbl.textContent = this.bulkMode ? 'Cancelar' : 'Seleccionar';
        }
        if (apply) apply.hidden = !this.bulkMode;
        this.render();
    }

    bulkMarkRead() {
        const ids = Array.from(this.bulkSelected);
        if (ids.length === 0) return;
        const sel = this;
        const undo = async () => {
            // Restore via per-id read-toggle (PUT /:id/unread).
            for (const id of ids) {
                await window.apiFetch('/api/notifications/' + id + '/unread?user_code=' + encodeURIComponent(this.me()), {
                    method: 'PUT', silent: true,
                });
            }
            sel.load();
        };
        // Mark as read first
        (async () => {
            for (const id of ids) {
                await window.apiFetch('/api/notifications/' + id + '/read?user_code=' + encodeURIComponent(this.me()), {
                    method: 'PUT', silent: true,
                });
            }
            if (window.apiUndoToast) {
                window.apiUndoToast({
                    message: ids.length + ' notificación(es) marcada(s) como leída(s)',
                    undoLabel: 'Deshacer',
                    ttl: 5000,
                    undo,
                });
            }
            sel.bulkMode = false;
            sel.bulkSelected.clear();
            sel.load();
        })();
    }

    async load() {
        const r = await window.apiFetch('/api/notifications?user_code=' + encodeURIComponent(this.me()));
        if (!r.ok || !Array.isArray(r.data)) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin notificaciones</p>';
            this.unreadTarget.textContent = '0';
            return;
        }
        this.all = r.data;
        this.render();
    }

    render() {
        if (!this.all) return;
        const unread = this.all.filter((n) => !n.read).length;
        this.unreadTarget.textContent = unread;

        // Apply filter
        let notifs = this.all;
        if (this.filter === 'unread') notifs = notifs.filter(n => !n.read);
        else if (this.filter !== 'all') notifs = notifs.filter(n => (n.type || '').startsWith(this.filter));

        if (notifs.length === 0) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin notificaciones para este filtro</p>';
            return;
        }
        const sel = this;
        // L84: group by day — Hoy / Ayer / formatted date.
        const dayKey = (n) => {
            const d = n.ts ? new Date(n.ts) : null;
            if (!d || Number.isNaN(d.getTime())) return '';
            return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
        };
        const dayLabel = (key) => {
            if (!key) return '';
            const [y, m, d] = key.split('-').map(Number);
            const dt = new Date(y, m, d);
            const today = new Date(); today.setHours(0, 0, 0, 0);
            const diff = Math.round((today - dt) / 86400000);
            if (diff === 0) return 'Hoy';
            if (diff === 1) return 'Ayer';
            return dt.toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'short' });
        };
        let html = '';
        let lastKey = null;
        notifs.forEach((n) => {
            const icon = ICONS[n.type] || ICONS[n.type?.split('_')[0]] || 'notifications';
            let link = n.link || LINKS[n.type] || '/feed';
            if (link.startsWith('task:')) link = '/sanctum/tasks/' + link.split(':')[1];
            if (link.startsWith('chat:')) link = '/chat';
            const time = n.ts ? new Date(n.ts).toLocaleString() : '';
            const unreadClass = n.read ? '' : 'border-l-4 border-[var(--gold-elev)] bg-[rgba(242,202,80,0.05)]';
            const checked = sel.bulkSelected.has(String(n.id)) ? 'is-selected' : '';
            const checkbox = sel.bulkMode
                ? `<input type="checkbox" class="notif-bulk-checkbox" data-id="${n.id}" ${checked ? 'checked' : ''} />`
                : '';
            const tagName = sel.bulkMode ? 'div' : 'a';
            const hrefAttr = sel.bulkMode ? '' : ` href="${sel.escapeHtml(link)}"`;
            const key = dayKey(n);
            if (key !== lastKey) {
                lastKey = key;
                const label = dayLabel(key);
                if (label) html += `<p class="notif-day-header">${sel.escapeHtml(label)}</p>`;
            }
            html += `<${tagName}${hrefAttr} data-id="${n.id}" class="block glass-card-elev p-3 ${unreadClass} ${checked} hover:bg-[var(--glass-bg-elev)] transition">`
                + `<div class="flex items-start gap-3">`
                + checkbox
                + `<span class="material-symbols-elev text-[var(--gold-elev)] mt-0.5">${icon}</span>`
                + `<div class="flex-1 min-w-0">`
                + `<p class="text-sm text-[var(--on-surface-elev)]">${sel.escapeHtml(n.text || '')}</p>`
                + `<p class="text-xs text-[var(--outline-elev)] mt-1">${sel.escapeHtml(time)}</p>`
                + `</div>`
                + (n.read ? '' : '<span class="text-xs text-[var(--gold-elev)] font-semibold">NUEVO</span>')
                + `</div>`
                + `</${tagName}>`;
        });
        this.listTarget.innerHTML = html;
        if (this.bulkMode) {
            this.listTarget.querySelectorAll('.notif-bulk-checkbox').forEach(cb => {
                cb.addEventListener('change', () => {
                    if (cb.checked) this.bulkSelected.add(cb.dataset.id);
                    else this.bulkSelected.delete(cb.dataset.id);
                    this.updateBulkCount();
                });
            });
        }
        this.updateBulkCount();
    }

    updateBulkCount() {
        if (this.hasBulkCountTarget) {
            this.bulkCountTarget.textContent = '(' + this.bulkSelected.size + ')';
        }
        if (this.hasBulkApplyTarget) {
            this.bulkApplyTarget.disabled = this.bulkSelected.size === 0;
        }
    }

    async markRead(id, el) {
        const r = await window.apiFetch('/api/notifications/' + id + '/read?user_code=' + encodeURIComponent(this.me()), {
            method: 'PUT',
            silent: true,
        });
        if (r.ok && el) {
            el.classList.remove('border-l-4', 'border-[var(--gold-elev)]', 'bg-[rgba(242,202,80,0.05)]');
            const badge = el.querySelector('.font-semibold');
            if (badge) badge.remove();
        }
    }

    onListClick(event) {
        if (this.bulkMode) {
            const cb = event.target.closest('.notif-bulk-checkbox');
            if (cb) return; // checkbox handles its own change
        }
        const a = event.target.closest('a[data-id], div[data-id]');
        if (!a) return;
        if (this.bulkMode) return;
        this.markRead(a.dataset.id, a);
    }

    async markAll() {
        const r = await window.apiFetch('/api/notifications/read-all?user_code=' + encodeURIComponent(this.me()), {
            method: 'PUT',
            silent: true,
        });
        if (r.ok) {
            if (window.apiToast) window.apiToast('Todas marcadas como leídas', 'success');
            this.load();
        }
    }
}