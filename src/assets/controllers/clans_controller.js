import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['info', 'search'];

    connect() {
        this.loadMyClan();
        this.loadClans();
    }

    async loadMyClan() {
        if (!this.hasInfoTarget) return;

        try {
            const r = await fetch('/api/clan/my');
            const data = await r.json();

            if (!data || !data.clan) {
                this.infoTarget.innerHTML = '<p class="text-center">No perteneces a ningún clan. ¡Crea o únete a uno!</p>';
                return;
            }

            const clan = data.clan;
            this.infoTarget.innerHTML = `
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold">${clan.name}</h3>
                        <p class="text-sm text-[var(--outline-elev)]">Miembros: ${clan.memberCount || 0}</p>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full bg-[var(--glass-bg-strong-elev)] text-[var(--gold-elev)]">${clan.tag || 'CLAN'}</span>
                </div>
            `;
        } catch (e) {
            this.infoTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)]">Error al cargar.</p>';
        }
    }

    async loadClans() {
        if (!this.hasSearchTarget) return;

        try {
            const r = await fetch('/api/clan/search?q=__list__');
            const data = await r.json();
            const clans = Array.isArray(data.clans) ? data.clans : [];

            if (clans.length === 0) {
                const fallback = await fetch('/api/clan');
                const fallbackData = await fallback.json();
                const list = Array.isArray(fallbackData.clans) ? fallbackData.clans : [];
                this.renderClans(list);
                return;
            }

            this.renderClans(clans);
        } catch (e) {
            this.searchTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }

    renderClans(clans) {
        if (!clans || clans.length === 0) {
            this.searchTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">No hay clanes públicos.</p>';
            return;
        }
        this.searchTarget.innerHTML = clans.map(c => `
            <div class="glass-card-elev clan-card flex items-center justify-between">
                <div>
                    <div class="clan-name">${c.name}</div>
                    <div class="clan-meta">${c.memberCount || 0} miembros</div>
                </div>
                <button class="btn-primary text-xs" data-action="click->clans#join" data-id="${c.id}">Unirse</button>
            </div>
        `).join('');
    }

    async join(e) {
        const id = e.target.dataset.id;
        try {
            await fetch(`/api/clan/${id}/join`, { method: 'POST' });
            location.reload();
        } catch (err) {
            console.error(err);
        }
    }
}
