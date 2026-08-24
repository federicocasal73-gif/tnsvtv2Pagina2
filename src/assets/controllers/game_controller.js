import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['highscore', 'played', 'vip', 'lb'];

    connect() {
        this.loadStats();
        this.loadLeaderboard();
    }

    async loadStats() {
        try {
            const r = await fetch('/api/game/my-stats');
            const data = await r.json();

            if (this.hasHighscoreTarget) this.highscoreTarget.textContent = data.high_score || 0;
            if (this.hasPlayedTarget) this.playedTarget.textContent = data.games_played || 0;
            if (this.hasVipTarget) this.vipTarget.textContent = data.vip_active ? 'SÍ' : 'NO';
        } catch (e) {}
    }

    async loadLeaderboard() {
        if (!this.hasLbTarget) return;

        try {
            const r = await fetch('/api/game/leaderboard');
            const data = await r.json();

            if (!data || data.length === 0) {
                this.lbTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-4">Sin partidas todavía</p>';
                return;
            }

            this.lbTarget.innerHTML = data.slice(0, 10).map((p, i) => `
                <div class="game-lb-row">
                    <span class="game-lb-pos">${i + 1}</span>
                    <span class="game-lb-name">${p.name || p.code || '—'}</span>
                    <span class="game-lb-score">${p.score || 0}</span>
                </div>
            `).join('');
        } catch (e) {
            this.lbTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-4">Error al cargar.</p>';
        }
    }

    async startGame() {
        try {
            const r = await fetch('/api/game/session', { method: 'POST' });
            const data = await r.json();
            if (data.success) {
                window.apiToast('¡Partida iniciada!', 'success');
            }
        } catch (err) {
            console.error(err);
        }
    }
}
