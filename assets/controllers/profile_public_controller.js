import { Controller } from '@hotwired/stimulus';

/**
 * Public profile page — fetches user profile by code from URL and renders.
 * Usage: <div data-controller="profile-public">
 */
export default class extends Controller {
    static targets = ['container'];

    static values = {
        userCode: String,
    };

    connect() {
        if (!this.hasUserCodeValue || !this.userCodeValue) {
            this.renderError('Usuario no especificado.');
            return;
        }
        this.load();
    }

    async load() {
        try {
            const r = await window.apiFetch('/api/profile/' + encodeURIComponent(this.userCodeValue));
            const user = r?.data?.user ?? null;
            if (!user) {
                this.renderError('Usuario no encontrado.');
                return;
            }
            this.render(user, r?.data?.is_owner ?? false);
        } catch (e) {
            this.renderError('Error al cargar.');
        }
    }

    render(u, isOwner) {
        document.title = (u.name || u.code) + ' · T.N.S.V.T';
        const avatar = u.avatar_url
            ? `<img src="${this.escape(u.avatar_url)}" alt="">`
            : `<span>${this.escape((u.name || '?').charAt(0))}</span>`;
        const adminBadge = u.is_admin ? '<span class="text-xs px-2 py-0.5 rounded-full bg-gradient-to-r from-purple-500 to-violet-500 text-white font-semibold ml-2">ADMIN</span>' : '';
        const wallet = u.wallet_balance ?? u.walletBalance ?? '0';

        this.containerTarget.innerHTML = `
            <div class="glass-card-elev profile-header">
                <div class="profile-avatar">${avatar}</div>
                <div class="profile-name">${this.escape(u.name || '—')}</div>
                <div class="profile-code">${this.escape(u.code || '—')}</div>
                <span class="tier-badge-elev profile-tier">${this.escape(u.tier || 'INITIATE')}</span>${adminBadge}
                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value">${u.reputation || 0}</div>
                        <div class="profile-stat-label">Reputación</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value">${u.coins || 0}</div>
                        <div class="profile-stat-label">Coins</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value">$${wallet}</div>
                        <div class="profile-stat-label">Wallet</div>
                    </div>
                </div>
            </div>
            ${isOwner ? '<div class="text-center mt-4"><a href="/profile" class="btn-primary">Editar Mi Perfil</a></div>' : ''}
        `;
    }

    renderError(message) {
        this.containerTarget.innerHTML = `<p class="text-center text-[var(--outline-elev)] py-8">${this.escape(message)}</p>`;
    }

    escape(str) {
        return String(str ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}