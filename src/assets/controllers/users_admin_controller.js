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

        document.addEventListener('click', (e) => {
            if (!e.target.matches('[data-action="toggle-user"]')) return;
            this.toggleUser(e.target);
        });
    }

    async loadUsers() {
        const grid = document.getElementById('users-grid');
        if (!grid) return;

        try {
            const r = await fetch('/sanctum/api/users');
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const data = await r.json();
            if (!data.success) throw new Error(data.error || 'unknown');
            this.allUsers = data.users;
            this.renderUsers();
        } catch (e) {
            grid.innerHTML = `<p class="col-span-full text-red-400 text-center py-8">Error: ${e.message}</p>`;
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
            const roleClass = u.isAdmin ? 'ADMIN' : 'USER';
            const avatarClass = u.isAdmin ? 'admin' : 'user';
            const statusClass = u.active ? 'active' : 'inactive';
            const statusText = u.active ? 'Active' : 'Inactive';
            const statusDotClass = u.active ? 'online' : 'offline';
            const tier = u.tier || 'INITIATE';

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
                    <span>$${parseFloat(u.walletBalance || 0).toFixed(2)}</span>
                    <span>${u.coins || 0} coins</span>
                    <span>${u.lastLogin ? u.lastLogin.substring(5, 16).replace('T', ' ') : '—'}</span>
                </div>
                <div class="mt-2 flex justify-end">
                    <button class="text-xs px-2 py-1 rounded ${u.active ? 'bg-red-900/30 text-red-400 hover:bg-red-900/50' : 'bg-green-900/30 text-green-400 hover:bg-green-900/50'} transition-colors" data-user-code="${this.escapeHtml(u.code || '')}" data-action="toggle-user">
                        ${u.active ? 'Desactivar' : 'Activar'}
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
            const r = await fetch('/sanctum/api/users/' + encodeURIComponent(code) + '/active', { method: 'PATCH' });
            const data = await r.json();
            if (data.success) {
                this.loadUsers();
            } else {
                if (window.apiToast) window.apiToast('Error: ' + (data.error || 'desconocido'), 'error');
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
}
