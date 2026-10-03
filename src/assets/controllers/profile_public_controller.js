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
            const r = await window.apiFetch(
                '/api/profile/' + encodeURIComponent(this.userCodeValue)
            );
            const d = r?.data ?? null;
            // Back devuelve {success, profile:{code,name,is_admin}}; aceptar también {user,...} legacy.
            const user = d?.user ?? d?.profile ?? null;
            if (!user) {
                this.renderError('Usuario no encontrado.');
                return;
            }
            const isOwner =
                d?.is_owner ??
                d?.isOwner ??
                (window.TNSVT_USER && window.TNSVT_USER.code === user.code);
            this.render(user, !!isOwner);
            this.loadJournal(user.code);
        } catch (e) {
            this.renderError('Error al cargar.');
        }
    }

    async loadJournal(code) {
        const box = this.element.querySelector('[data-journal-box]');
        if (!box) return;
        try {
            const r = await window.apiFetch('/api/journal?user_code=' + encodeURIComponent(code), {
                silent: true,
                redirectOn401: false,
            });
            if (!r.ok) {
                const msg =
                    r.status === 403
                        ? 'Este journal no es visible para vos (privado, sin conexión o sin permisos).'
                        : r.status === 401
                          ? 'Iniciá sesión para ver journals.'
                          : 'Journal no disponible.';
                box.innerHTML = `<p class="text-center text-[var(--outline-elev)] py-4">${this.escape(msg)}</p>`;
                return;
            }
            const trades = r.data?.trades ?? [];
            const stats = r.data?.stats ?? null;
            const scope = r.data?.scope ?? '';
            const head = stats
                ? `<div class="profile-stats"><div class="profile-stat"><div class="profile-stat-value">${trades.length}</div><div class="profile-stat-label">Trades ${this.escape(scope)}</div></div></div>`
                : '';
            if (!trades.length) {
                box.innerHTML =
                    head +
                    '<p class="text-center text-[var(--outline-elev)] py-4">Sin trades visibles.</p>';
                return;
            }
            const rows = trades
                .slice(0, 20)
                .map(
                    (t) =>
                        `<div class="social-card-row"><div class="social-body"><div class="social-card-topic">${this.escape(t.asset || '—')} · ${this.escape(t.dir || '')} · ${this.escape(t.result || '')}</div><div class="social-card-meta">${this.escape((t.date || '').slice(0, 10))} · PnL ${this.escape(String(t.pnl ?? 0))}</div></div></div>`
                );
            box.innerHTML = head + rows.join('');
        } catch (e) {
            box.innerHTML =
                '<p class="text-center text-[var(--outline-elev)] py-4">Journal no disponible.</p>';
        }
    }

    render(u, isOwner) {
        document.title = (u.name || u.code) + ' · T.N.S.V.T';
        const avatar = u.avatar_url
            ? `<img src="${this.escape(u.avatar_url)}" alt="">`
            : `<span>${this.escape((u.name || '?').charAt(0))}</span>`;
        const adminBadge = u.is_admin
            ? '<span class="text-xs px-2 py-0.5 rounded-full bg-gradient-to-r from-purple-500 to-violet-500 text-white font-semibold ml-2">ADMIN</span>'
            : '';
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
            ${isOwner ? '<div class="text-center mt-4"><a href="/profile" class="ui-btn ui-btn-primary">Editar Mi Perfil</a></div>' : ''}
            <div class="glass-card-elev mt-4"><div class="p-4">
                <h2 class="text-lg font-semibold mb-2">Journal</h2>
                <div data-journal-box><p class="text-center py-4 loading-pulse">Cargando journal...</p></div>
            </div></div>
        `;
    }

    renderError(message) {
        this.containerTarget.innerHTML = `<p class="text-center text-[var(--outline-elev)] py-8">${this.escape(message)}</p>`;
    }

    escape(str) {
        return String(str ?? '').replace(
            /[&<>"']/g,
            (m) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[m]
        );
    }
}
