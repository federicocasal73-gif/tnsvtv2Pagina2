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

            if (!data || !data.id) {
                this.infoTarget.innerHTML = '<p class="text-center">No perteneces a ningún clan. ¡Crea o únete a uno!</p>';
                return;
            }

            this.infoTarget.innerHTML = `
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold">${data.name}</h3>
                        <p class="text-sm text-[var(--outline-elev)]">Miembros: ${data.member_count || 0}</p>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full bg-[var(--glass-bg-strong-elev)] text-[var(--gold-elev)]">${data.tag || 'CLAN'}</span>
                </div>
            `;
        } catch (e) {
            this.infoTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)]">Error al cargar.</p>';
        }
    }

    async loadClans() {
        if (!this.hasSearchTarget) return;

        try {
            const r = await fetch('/api/clan/search');
            const data = await r.json();

            if (!data || data.length === 0) {
                this.searchTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">No hay clanes públicos.</p>';
                return;
            }

            this.searchTarget.innerHTML = data.map(c => `
                <div class="glass-card-elev clan-card flex items-center justify-between">
                    <div>
                        <div class="clan-name">${c.name}</div>
                        <div class="clan-meta">${c.member_count || 0} miembros</div>
                    </div>
                    <button class="btn-primary text-xs" data-action="click->clans#join" data-id="${c.id}">Unirse</button>
                </div>
            `).join('');
        } catch (e) {
            this.searchTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
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
