import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list'];

    connect() {
        this.loadDuels();
    }

    async loadDuels() {
        if (!this.hasListTarget) return;

        try {
            const r = await fetch('/api/duels');
            const data = await r.json();

            if (!data || data.length === 0) {
                this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">No hay duelos. ¡Crea uno!</p>';
                return;
            }

            this.listTarget.innerHTML = data.map(d => `
                <div class="glass-card-elev duel-card">
                    <span class="duel-vs">VS</span>
                    <div class="duel-info">
                        <div class="duel-players">${d.player1 || '—'} vs ${d.player2 || '—'}</div>
                        <div class="duel-meta">Ronda ${d.current_round || 0}/${d.totalRounds || 3} · ${d.status || 'active'}</div>
                    </div>
                    <span class="text-sm ${d.status === 'finished' ? 'text-[var(--outline-elev)]' : 'text-[var(--gold-elev)]'}">${d.status || 'active'}</span>
                </div>
            `).join('');
        } catch (e) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }
}
