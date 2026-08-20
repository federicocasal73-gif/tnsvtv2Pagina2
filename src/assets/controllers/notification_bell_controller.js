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
    access_request: '/sanctum/social',
    access_accepted: '/sanctum/social',
    access_rejected: '/sanctum/social',
    connection_removed: '/sanctum/social',
    permissions_changed: '/sanctum/social',
    economic_alert: '/calendar',
};
const MAX_ITEMS = 8;

export default class extends Controller {
    static targets = ['wrap', 'popover', 'list', 'count', 'button'];

    connect() {
        this.loading = false;
        document.addEventListener('click', this.documentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.documentClick);
    }

    documentClick = (event) => {
        if (this.popoverTarget.classList.contains('hidden')) return;
        if (this.wrapTarget.contains(event.target)) return;
        this.close();
    };

    toggle() {
        if (this.popoverTarget.classList.contains('hidden')) {
            this.open();
        } else {
            this.close();
        }
    }

    open() {
        this.popoverTarget.classList.remove('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        if (!this.loading) this.load();
    }

    close() {
        this.popoverTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || '';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    async load() {
        this.loading = true;
        const listEl = this.listTarget;
        listEl.innerHTML = '<p class="notif-popover-placeholder">Cargando...</p>';
        this.renderCount(0);

        try {
            const r = await window.apiFetch('/api/notifications?user_code=' + encodeURIComponent(this.me()), { silent: true });
            if (!r.ok || !Array.isArray(r.data)) {
                listEl.innerHTML = '<p class="notif-popover-placeholder">Sin notificaciones</p>';
                return;
            }
            this.renderItems(r.data);
        } finally {
            this.loading = false;
        }
    }

    renderCount(unread) {
        const n = unread || 0;
        this.countTarget.textContent = n > 99 ? '99+' : n;
        this.countTarget.classList.toggle('hidden', n === 0);
    }

    renderItems(notifs) {
        const listEl = this.listTarget;
        const unread = notifs.filter((n) => !n.read).length;
        this.renderCount(unread);

        if (notifs.length === 0) {
            listEl.innerHTML = '<p class="notif-popover-placeholder">Sin notificaciones todavía</p>';
            return;
        }

        const recent = notifs.slice(0, MAX_ITEMS);
        listEl.innerHTML = recent.map((n) => {
            const icon = ICONS[n.type] || 'notifications';
            const link = n.link || LINKS[n.type] || '/feed';
            const time = n.ts ? new Date(n.ts).toLocaleString() : '';
            const unreadClass = n.read ? '' : 'notif-popover-item-unread';
            return `<a href="${this.escapeHtml(link)}" class="notif-popover-item ${unreadClass}">
                        <span class="material-symbols-elev notif-popover-icon" aria-hidden="true">${icon}</span>
                        <span class="notif-popover-main">
                            <span class="notif-popover-text">${this.escapeHtml(n.text || '')}</span>
                            <span class="notif-popover-time">${this.escapeHtml(time)}</span>
                        </span>
                        ${n.read ? '' : '<span class="notif-popover-new">NUEVO</span>'}
                    </a>`;
        }).join('');
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