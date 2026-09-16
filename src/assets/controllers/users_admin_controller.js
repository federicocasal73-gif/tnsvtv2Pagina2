import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.allUsers = [];
        this.currentFilter = { search: '', tier: '' };

        this.loadUsers();

        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) refreshBtn.addEventListener('click', () => this.loadUsers());

        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.currentFilter.search = e.target.value;
                this.renderUsers();
            });
        }

        const filterTier = document.getElementById('filter-tier');
        if (filterTier) {
            filterTier.addEventListener('change', (e) => {
                this.currentFilter.tier = e.target.value;
                this.renderUsers();
            });
        }

        // ─── Modal: Agregar adepto → POST /api/admin/users ───
        const addBtn = document.getElementById('add-user-btn');
        const modal = document.getElementById('add-user-modal');
        if (addBtn && modal && typeof window.apiSetupModal === 'function') {
            this._addUserModal = window.apiSetupModal(modal, {
                open: () => {
                    const codeEl = document.getElementById('add-user-code');
                    const nameEl = document.getElementById('add-user-name');
                    if (codeEl) codeEl.value = '';
                    if (nameEl) nameEl.value = '';
                },
            });
            addBtn.addEventListener('click', () => this._addUserModal.open());
            const closeBtn = document.getElementById('add-user-close');
            if (closeBtn) closeBtn.addEventListener('click', () => this._addUserModal.close());
            const cancelBtn = document.getElementById('add-user-cancel');
            if (cancelBtn) cancelBtn.addEventListener('click', () => this._addUserModal.close());
            const submitBtn = document.getElementById('add-user-submit');
            if (submitBtn) submitBtn.addEventListener('click', () => this.createUser());
        }

        document.addEventListener('click', (e) => {
            if (!e.target.matches('[data-action="toggle-user"]')) return;
            this.toggleUser(e.target);
        });
        document.addEventListener('click', (e) => {
            if (!e.target.matches('[data-action="delete-user"]')) return;
            this.deleteUser(e.target);
        });
    }

    async loadUsers() {
        const grid = document.getElementById('users-grid');
        if (!grid) return;

        try {
            // apiFetch (not raw fetch): 30s timeout so a hung request
            // (e.g. PHP session lock) aborts instead of leaving the
            // server placeholder "Cargando..." stuck forever.
            const r = await window.apiFetch('/sanctum/api/users');
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const data = r.data;
            if (!data || !data.success) throw new Error((data && data.error) || 'unknown');
            this.allUsers = data.users;
            this.renderUsers();
        } catch (e) {
            grid.innerHTML = `<p class="col-span-full text-red-400 text-center py-8">Error: ${this.escapeHtml(e.message)}</p>`;
        }
    }

    renderUsers() {
        const grid = document.getElementById('users-grid');
        if (!grid) return;

        const filtered = this.allUsers.filter(u => {
            if (this.currentFilter.search && !u.code.toLowerCase().includes(this.currentFilter.search.toLowerCase())) return false;
            if (this.currentFilter.tier && u.tier !== this.currentFilter.tier) return false;
            return true;
        });

        if (filtered.length === 0) {
            grid.innerHTML = '<p class="col-span-full text-center text-[var(--outline-elev)] py-8">Sin usuarios encontrados</p>';
            return;
        }

        grid.innerHTML = filtered.map(u => {
            const initials = (u.code || '??').substring(0, 2).toUpperCase();
            // Backend sends `roles`, not `isAdmin` — derive it, otherwise
            // every admin rendered as USER.
            const isAdmin = Array.isArray(u.roles) && u.roles.includes('ROLE_ADMIN');
            const roleClass = isAdmin ? 'ADMIN' : 'USER';
            const avatarClass = isAdmin ? 'admin' : 'user';
            const statusClass = u.active ? 'active' : 'inactive';
            const statusText = u.active ? 'Active' : 'Inactive';
            const statusDotClass = u.active ? 'online' : 'offline';
            const tier = u.tier || 'INITIATE';
            // Backend sends `last_login` (snake_case).
            const lastLogin = u.last_login ? String(u.last_login).substring(5, 16).replace('T', ' ') : '—';

            return `
            <div class="user-card ${u.active ? '' : 'inactive'}">
                <div class="flex items-start gap-3">
                    <div class="relative">
                        <div class="user-avatar ${avatarClass}">${initials}</div>
                        <span class="user-status-dot ${statusDotClass}"></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-mono text-[var(--on-surface-elev)] truncate">${this.escapeHtml(u.code || '')}</p>
                            <span class="role-pill ${roleClass}">${roleClass}</span>
                        </div>
                        <p class="text-sm text-[var(--outline-elev)] truncate">${this.escapeHtml(u.name || '')}</p>
                        ${u.email ? `<p class="text-xs text-[var(--outline-elev)] truncate">${this.escapeHtml(u.email)}</p>` : ''}
                        <div class="flex items-center gap-2 mt-2">
                            <span class="tier-badge tier-${this.escapeHtml(tier)}">${tier.replace(/_/g, ' ')}</span>
                            <span class="status-pill ${statusClass}">${statusText}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between mt-3 pt-3 border-t border-[var(--outline-variant-elev)] text-xs text-[var(--outline-elev)]">
                    <span>Último acceso: ${this.escapeHtml(lastLogin)}</span>
                </div>
                <div class="mt-2 flex justify-end gap-2">
                    <button class="text-xs px-2 py-1 rounded ${u.active ? 'bg-red-900/30 text-red-400 hover:bg-red-900/50' : 'bg-green-900/30 text-green-400 hover:bg-green-900/50'} transition-colors" data-user-code="${this.escapeHtml(u.code || '')}" data-action="toggle-user">
                        ${u.active ? 'Desactivar' : 'Activar'}
                    </button>
                    <button class="text-xs px-2 py-1 rounded bg-red-950/40 text-red-300 border border-red-900/40 hover:bg-red-900/50 transition-colors" data-user-code="${this.escapeHtml(u.code || '')}" data-action="delete-user" title="Borrar definitivamente">
                        Eliminar
                    </button>
                </div>
            </div>`;
        }).join('');
    }

    async toggleUser(btn) {
        const code = btn.dataset.userCode;
        if (!code) return;
        btn.disabled = true;
        try {
            const r = await window.apiFetch('/sanctum/api/users/' + encodeURIComponent(code) + '/active', { method: 'PATCH' });
            const data = r.data;
            if (r.ok && data && data.success) {
                this.loadUsers();
            } else {
                if (window.apiToast) window.apiToast('Error: ' + ((data && data.error) || 'desconocido'), 'error');
                btn.disabled = false;
            }
        } catch (err) {
            if (window.apiToast) window.apiToast('Error: ' + err.message, 'error');
            btn.disabled = false;
        }
    }

    escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    async deleteUser(btn) {
        const code = btn.dataset.userCode;
        if (!code) return;
        const ok = window.apiConfirm
            ? await window.apiConfirm(
                `¿PURGAR TOTALMENTE al adepto ${code}?\n\nSe borra el usuario + TODO lo suyo: mensajes, diario, journal, frecuencias, tareas, notificaciones, wallet, campus, clanes y archivos. IRREVERSIBLE. Si lidera un clan con miembros o es el último admin, se rechaza.`,
                { title: 'Purgado total', confirmLabel: 'Sí, purgar todo', cancelLabel: 'Cancelar', variant: 'danger' }
              )
            : true;
        if (!ok) return;
        btn.disabled = true;
        try {
            const r = await window.apiFetch('/sanctum/api/users/' + encodeURIComponent(code) + '?force=1', { method: 'DELETE' });
            if (r.ok && r.data && r.data.success) {
                const n = r.data.purged
                    ? Object.values(r.data.purged).reduce((a, b) => a + (typeof b === 'number' ? b : 0), 0)
                    : 0;
                if (window.apiToast) window.apiToast(`Adepto ${code} purgado (${n} filas)`, 'success');
                this.loadUsers();
            } else {
                if (window.apiToast) window.apiToast('Error: ' + ((r.data && r.data.error) || 'desconocido'), 'error');
                btn.disabled = false;
            }
        } catch (err) {
            if (window.apiToast) window.apiToast('Error: ' + err.message, 'error');
            btn.disabled = false;
        }
    }

    async createUser() {
        const codeEl = document.getElementById('add-user-code');
        const nameEl = document.getElementById('add-user-name');
        const submitBtn = document.getElementById('add-user-submit');
        const code = (codeEl ? codeEl.value : '').trim().toUpperCase();
        const name = (nameEl ? nameEl.value : '').trim();
        if (!code || !name) {
            if (window.apiToast) window.apiToast('Código y nombre son requeridos', 'warning');
            return;
        }
        if (submitBtn) submitBtn.disabled = true;
        try {
            // Session cookie authenticates (same firewall as the page);
            // X-Game-Code rides along harmlessly via apiFetch.
            const r = await window.apiFetch('/api/admin/users', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code, name }),
            });
            if (r.ok && r.data && r.data.id) {
                if (window.apiToast) window.apiToast(`Adepto ${code} creado`, 'success');
                if (this._addUserModal) this._addUserModal.close();
                this.loadUsers();
            } else {
                if (window.apiToast) window.apiToast('Error: ' + ((r.data && r.data.error) || 'desconocido'), 'error');
            }
        } catch (err) {
            if (window.apiToast) window.apiToast('Error: ' + err.message, 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }
}
