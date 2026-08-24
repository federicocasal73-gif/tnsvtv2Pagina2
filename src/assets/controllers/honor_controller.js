import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list'];

    connect() {
        this.loadHonor();
    }

    async loadHonor() {
        if (!this.hasListTarget) return;

        try {
            const r = await fetch('/api/honor/board');
            const data = await r.json();

            if (!data || data.length === 0) {
                this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Aún no hay reconocimientos.</p>';
                return;
            }

            this.listTarget.innerHTML = data.map(h => `
                <div class="glass-card-elev honor-card">
                    <div class="honor-badge">${h.emoji || '🏆'}</div>
                    <div class="honor-info">
                        <div class="honor-title">${h.title}</div>
                        <div class="honor-desc">${h.description || ''}</div>
                    </div>
                    <div class="honor-date">${h.awarded_at || ''}</div>
                </div>
            `).join('');
        } catch (e) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }
}
