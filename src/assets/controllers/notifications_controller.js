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

export default class extends Controller {
    static targets = ['list', 'unread', 'markAll'];

    connect() {
        if (window.TNSVT_USER) {
            this.load();
        } else {
            window.addEventListener('tnsvt:user-loaded', () => this.load(), { once: true });
        }
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || '';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    async load() {
        const r = await window.apiFetch('/api/notifications?user_code=' + encodeURIComponent(this.me()));
        if (!r.ok || !Array.isArray(r.data)) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin notificaciones</p>';
            this.unreadTarget.textContent = '0';
            return;
        }
        const notifs = r.data;
        const unread = notifs.filter((n) => !n.read).length;
        this.unreadTarget.textContent = unread;

        if (notifs.length === 0) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin notificaciones todavía</p>';
            return;
        }
        this.listTarget.innerHTML = notifs.map((n) => {
            const icon = ICONS[n.type] || 'notifications';
            const link = n.link || LINKS[n.type] || '/feed';
            const time = n.ts ? new Date(n.ts).toLocaleString() : '';
            const unreadClass = n.read ? '' : 'border-l-4 border-[var(--gold-elev)] bg-[rgba(242,202,80,0.05)]';
            return `<a href="${this.escapeHtml(link)}" data-id="${n.id}" class="block glass-card-elev p-3 ${unreadClass} hover:bg-[var(--glass-bg-elev)] transition">`
                + `<div class="flex items-start gap-3">`
                + `<span class="material-symbols-elev text-[var(--gold-elev)] mt-0.5">${icon}</span>`
                + `<div class="flex-1 min-w-0">`
                + `<p class="text-sm text-[var(--on-surface-elev)]">${this.escapeHtml(n.text || '')}</p>`
                + `<p class="text-xs text-[var(--outline-elev)] mt-1">${this.escapeHtml(time)}</p>`
                + `</div>`
                + (n.read ? '' : '<span class="text-xs text-[var(--gold-elev)] font-semibold">NUEVO</span>')
                + `</div>`
                + `</a>`;
        }).join('');
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
        const a = event.target.closest('a[data-id]');
        if (!a) return;
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