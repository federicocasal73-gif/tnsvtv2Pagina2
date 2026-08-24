import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['active', 'mine'];

    connect() {
        this.loadActive();
        this.loadMine();
    }

    async loadActive() {
        if (!this.hasActiveTarget) return;

        try {
            const r = await fetch('/api/tournaments/active');
            const data = await r.json();

            if (!data || data.length === 0) {
                this.activeTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">No hay torneos activos.</p>';
                return;
            }

            this.activeTarget.innerHTML = data.map(t => `
                <div class="glass-card-elev tournament-card">
                    <div class="tournament-header">
                        <span class="tournament-title">${t.name || 'Torneo'}</span>
                        <span class="tournament-prize">$${t.prize_pool || 0}</span>
                    </div>
                    <div class="tournament-meta">
                        <span>Participantes: ${t.participant_count || 0}</span>
                        <span>Estado: ${t.status || 'active'}</span>
                    </div>
                    <button class="btn-primary w-full mt-3" data-action="click->tournaments#join" data-id="${t.id}">Unirse</button>
                </div>
            `).join('');
        } catch (e) {
            this.activeTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }

    async loadMine() {
        if (!this.hasMineTarget) return;

        try {
            const r = await fetch('/api/tournaments/my');
            const data = await r.json();

            if (!data || data.length === 0) {
                this.mineTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">No estás en ningún torneo.</p>';
                return;
            }

            this.mineTarget.innerHTML = data.map(t => `
                <div class="glass-card-elev tournament-card">
                    <div class="tournament-header">
                        <span class="tournament-title">${t.name || 'Torneo'}</span>
                        <span class="text-sm ${t.my_position === 1 ? 'text-[var(--gold-elev)]' : 'text-[var(--outline-elev)]'}">#${t.my_position || '—'}</span>
                    </div>
                </div>
            `).join('');
        } catch (e) {
            this.mineTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }

    async join(e) {
        const id = e.target.dataset.id;
        try {
            await fetch(`/api/tournaments/${id}/join`, { method: 'POST' });
            location.reload();
        } catch (err) {
            console.error(err);
        }
    }
}
