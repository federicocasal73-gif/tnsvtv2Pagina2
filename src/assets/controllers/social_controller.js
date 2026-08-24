import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toast'];

    connect() {
        this.me = (window.TNSVT_USER && window.TNSVT_USER.code) || '';
        this.tab = 'users';
        this.allUsers = [];
        this.myRequests = [];
        this.connections = [];
        this.privacy = 'connections';

        this.wire();
        this.restoreTab();
    }

    esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    initials(name) {
        return (name || '?').split(/\s+/).map(p => p[0]).join('').slice(0, 2).toUpperCase();
    }

    toast(msg, type = 'success') {
        const t = document.getElementById('social-toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'social-toast visible ' + (type || 'success');
        setTimeout(() => t.classList.remove('visible'), 2800);
    }

    fmtDate(iso) {
        try { return new Date(iso).toLocaleDateString('es-AR', { day: '2-digit', month: 'short', year: 'numeric' }); }
        catch (e) { return iso; }
    }

    switchTab(name) {
        this.tab = name;
        document.querySelectorAll('.social-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
        document.querySelectorAll('.social-tab-panel').forEach(p => p.hidden = (p.id !== 'tab-' + name));

        const url = new URL(location.href);
        if (name && name !== 'users') url.searchParams.set('tab', name);
        else url.searchParams.delete('tab');
        history.replaceState(null, '', url);

        if (name === 'users') this.renderUsers();
        if (name === 'requests') this.renderReceived();
        if (name === 'connections') this.renderConnections();
        if (name === 'privacy') this.renderPrivacy();
    }

    userCard(u, accessStatus) {
        const isMe = u.code === this.me;
        const status = accessStatus || (isMe ? 'self' : (u.access_status || 'none'));
        let action = '';
        if (isMe) {
            action = '<span class="social-btn primary" style="opacity:0.6;cursor:default;">Sos vos</span>';
        } else if (status === 'connected' || status === 'accepted') {
            action = '<button type="button" class="social-btn connected" disabled>✓ Conectado</button>';
        } else if (status === 'pending' || status === 'sent') {
            action = '<button type="button" class="social-btn pending" disabled>⏳ Pendiente</button>';
        } else if (status === 'declined' || status === 'rejected') {
            action = '<button type="button" class="social-btn ghost" data-action="request" data-code="' + this.esc(u.code) + '">Reenviar</button>';
        } else if (status === 'blocked') {
            action = '<span class="social-btn danger" style="opacity:0.5;cursor:default;">Bloqueado</span>';
        } else {
            action = '<button type="button" class="social-btn primary" data-action="request" data-code="' + this.esc(u.code) + '">+ Conectar</button>';
        }
        const roleClass = ['admin', 'mentor', 'analyst', 'trader'].includes((u.role || '').toLowerCase())
            ? u.role.toLowerCase() : 'trader';
        return `
            <article class="social-card">
                <div class="social-avatar">${this.esc(this.initials(u.name))}</div>
                <div class="social-body">
                    <div class="social-name">
                        ${this.esc(u.name || '—')}
                        ${u.role ? `<span class="social-role ${this.esc(roleClass)}">${this.esc(u.role)}</span>` : ''}
                    </div>
                    <div class="social-code">${this.esc(u.code)}</div>
                </div>
                <div class="social-actions">${action}</div>
            </article>
        `;
    }

    requestCard(r) {
        return `
            <article class="social-card-row pending" data-id="${this.esc(r.id)}">
                <div class="social-avatar">${this.esc(this.initials(r.requester_name || r.requester_code))}</div>
                <div class="social-body">
                    <div class="social-card-topic">${this.esc(r.requester_name || r.requester_code)}</div>
                    <div class="social-card-meta">${this.esc(r.requester_code)} · ${this.fmtDate(r.created_at)}</div>
                </div>
                <div class="social-actions">
                    <button type="button" class="social-btn accept" data-action="accept" data-id="${this.esc(r.id)}">Aceptar</button>
                    <button type="button" class="social-btn deny" data-action="deny" data-id="${this.esc(r.id)}">Rechazar</button>
                </div>
            </article>
        `;
    }

    connectionCard(c) {
        return `
            <article class="social-card-row connected" data-id="${this.esc(c.id)}">
                <div class="social-avatar">${this.esc(this.initials(c.connected_name || c.connected_code))}</div>
                <div class="social-body">
                    <div class="social-card-topic">${this.esc(c.connected_name || 'Conexión')}</div>
                    <div class="social-card-meta">${this.esc(c.connected_code)} · desde ${this.fmtDate(c.created_at)}</div>
                </div>
                <div class="social-actions">
                    <a href="/u/${this.esc(c.connected_code)}" class="social-btn ghost">Ver perfil</a>
                    <button type="button" class="social-btn danger" data-action="remove" data-id="${this.esc(c.id)}">Eliminar</button>
                </div>
            </article>
        `;
    }

    labelForPrivacy(v) {
        return ({
            'public': '🌍 Público — todo el Sanctum puede ver tu journal',
            'connections': '👥 Solo conexiones — sólo conexiones aceptadas',
            'private': '🔒 Privado — sólo vos (los demás requieren solicitud)',
        })[v] || v;
    }

    async loadUsers() {
        const list = document.getElementById('users-list');
        if (!list) return;

        list.innerHTML = '<p class="social-loading" style="grid-column: 1 / -1;">Cargando...</p>';
        try {
            const q = document.getElementById('social-search').value.trim();
            const url = '/social/api/users' + (q ? '?q=' + encodeURIComponent(q) : '');
            const response = await fetch(url);
            const r = await response.json();

            if (!r.ok || !r.data || !Array.isArray(r.data.users)) {
                list.innerHTML = '<p class="social-empty" style="grid-column: 1 / -1;">Sin miembros para mostrar.</p>';
                return;
            }
            this.allUsers = r.data.users;
            const badge = document.getElementById('badge-users');
            if (badge) {
                badge.textContent = this.allUsers.length;
                badge.hidden = this.allUsers.length === 0;
            }
            if (this.allUsers.length === 0) {
                list.innerHTML = '<p class="social-empty" style="grid-column: 1 / -1;">Sin miembros. Probá con otra búsqueda.</p>';
                return;
            }
            list.innerHTML = this.allUsers.map(u => this.userCard(u)).join('');
            list.querySelectorAll('[data-action="request"]').forEach(b => {
                b.addEventListener('click', () => this.sendRequest(b.dataset.code));
            });
        } catch (e) {
            list.innerHTML = '<p class="social-empty" style="grid-column: 1 / -1;">Sin conexión.</p>';
        }
    }

    renderUsers() {
        this.loadUsers();
    }

    async loadReceived() {
        const list = document.getElementById('received-list');
        if (!list) return;

        list.innerHTML = '<p class="social-empty">Cargando...</p>';
        try {
            const response = await fetch('/api/access-request?user_code=' + encodeURIComponent(this.me));
            const r = await response.json();

            if (!r.ok || !r.data) {
                list.innerHTML = '<p class="social-empty">Sin solicitudes</p>';
                return;
            }
            const items = Array.isArray(r.data) ? r.data : (r.data.requests || []);
            this.myRequests = items.filter(x => x.target_code === this.me && x.status === 'pending');

            const pending = document.getElementById('badge-requests');
            if (pending) {
                pending.textContent = this.myRequests.length;
                pending.hidden = this.myRequests.length === 0;
            }
            if (this.myRequests.length === 0) {
                list.innerHTML = '<p class="social-empty">No tenés solicitudes pendientes.</p>';
                return;
            }
            list.innerHTML = this.myRequests.map(r => this.requestCard(r)).join('');
            list.querySelectorAll('[data-action="accept"]').forEach(b =>
                b.addEventListener('click', () => this.respondRequest(parseInt(b.dataset.id, 10), 'accepted'))
            );
            list.querySelectorAll('[data-action="deny"]').forEach(b =>
                b.addEventListener('click', () => this.respondRequest(parseInt(b.dataset.id, 10), 'rejected'))
            );
        } catch (e) {
            list.innerHTML = '<p class="social-empty">Sin conexión.</p>';
        }
    }

    renderReceived() {
        this.loadReceived();
    }

    async loadConnections() {
        const list = document.getElementById('conn-list');
        if (!list) return;

        list.innerHTML = '<p class="social-empty">Cargando...</p>';
        try {
            const response = await fetch('/api/connections?user_code=' + encodeURIComponent(this.me));
            const r = await response.json();

            if (!r.ok || !r.data) {
                list.innerHTML = '<p class="social-empty">Sin conexiones.</p>';
                return;
            }
            this.connections = r.data.connections || [];
            const badge = document.getElementById('badge-connections');
            if (badge) {
                badge.textContent = this.connections.length;
                badge.hidden = this.connections.length === 0;
            }
            if (this.connections.length === 0) {
                list.innerHTML = '<p class="social-empty">Sin conexiones todavía. Buscá usuarios y enviá solicitudes.</p>';
                return;
            }
            list.innerHTML = this.connections.map(c => this.connectionCard(c)).join('');
            list.querySelectorAll('[data-action="remove"]').forEach(b => {
                b.addEventListener('click', () => this.removeConnection(parseInt(b.dataset.id, 10)));
            });
        } catch (e) {
            list.innerHTML = '<p class="social-empty">Sin conexión.</p>';
        }
    }

    renderConnections() {
        this.loadConnections();
    }

    async loadPrivacy() {
        try {
            const response = await fetch('/api/journal/settings?code=' + encodeURIComponent(this.me));
            const r = await response.json();

            if (r.ok && r.data) {
                const v = r.data.visibility || r.data.setup_token ? 'connections' : 'public';
                this.privacy = v;
            } else {
                this.privacy = 'connections';
            }
            const currentValue = document.getElementById('privacy-current-value');
            if (currentValue) currentValue.textContent = this.labelForPrivacy(this.privacy);
            document.querySelectorAll('.social-privacy-opt').forEach(b => {
                b.classList.toggle('active', b.dataset.vis === this.privacy);
            });
        } catch (e) {
            const currentValue = document.getElementById('privacy-current-value');
            if (currentValue) currentValue.textContent = '—';
        }
    }

    renderPrivacy() {
        this.loadPrivacy();
    }

    async sendRequest(targetCode) {
        try {
            const response = await fetch('/api/access-request?code=' + encodeURIComponent(this.me), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_code: this.me, target_code: targetCode })
            });
            const r = await response.json();
            if (r.ok && r.data && r.data.success) {
                this.toast('Solicitud enviada a ' + targetCode, 'success');
                this.loadUsers();
            } else {
                this.toast((r.data && r.data.error) || 'Error al enviar solicitud', 'error');
            }
        } catch (e) {
            this.toast('Error de red', 'error');
        }
    }

    async respondRequest(id, status) {
        try {
            const response = await fetch('/api/access-request/' + id + '?code=' + encodeURIComponent(this.me), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_code: this.me, status: status })
            });
            const r = await response.json();
            if (r.ok && r.data && r.data.success) {
                this.toast('Solicitud ' + (status === 'accepted' ? 'aceptada' : 'rechazada'), 'success');
                this.loadReceived();
                this.loadUsers();
            } else {
                this.toast((r.data && r.data.error) || 'Error', 'error');
            }
        } catch (e) {
            this.toast('Error de red', 'error');
        }
    }

    async removeConnection(id) {
        if (!confirm('¿Eliminar esta conexión?')) return;
        try {
            const response = await fetch('/api/connections/' + id + '?code=' + encodeURIComponent(this.me), {
                method: 'DELETE'
            });
            if (response.ok) {
                this.toast('Conexión eliminada', 'success');
                this.loadConnections();
            } else {
                this.toast('Error al eliminar', 'error');
            }
        } catch (e) {
            this.toast('Error de red', 'error');
        }
    }

    async setPrivacy(vis) {
        try {
            const response = await fetch('/api/journal/settings?code=' + encodeURIComponent(this.me), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: this.me, visibility: vis })
            });
            const r = await response.json();
            if (r.ok && r.data && r.data.success) {
                this.privacy = vis;
                const currentValue = document.getElementById('privacy-current-value');
                if (currentValue) currentValue.textContent = this.labelForPrivacy(vis);
                document.querySelectorAll('.social-privacy-opt').forEach(b => {
                    b.classList.toggle('active', b.dataset.vis === vis);
                });
                this.toast('Privacidad actualizada', 'success');
            } else {
                this.toast((r.data && r.data.error) || 'Error al actualizar', 'error');
            }
        } catch (e) {
            this.toast('Error de red', 'error');
        }
    }

    wire() {
        document.querySelectorAll('.social-tab').forEach(t => {
            t.addEventListener('click', () => this.switchTab(t.dataset.tab));
        });
        let searchT = null;
        const searchInput = document.getElementById('social-search');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchT);
                searchT = setTimeout(() => this.loadUsers(), 200);
            });
        }
        document.querySelectorAll('.social-privacy-opt').forEach(b => {
            b.addEventListener('click', () => this.setPrivacy(b.dataset.vis));
        });
    }

    restoreTab() {
        const urlTab = new URLSearchParams(location.search).get('tab');
        if (urlTab && ['users', 'requests', 'connections', 'privacy'].includes(urlTab)) {
            this.switchTab(urlTab);
        } else {
            this.switchTab('users');
        }
    }
}
