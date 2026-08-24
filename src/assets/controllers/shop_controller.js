import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['balance', 'items'];

    connect() {
        this.loadBalance();
        this.loadItems();
    }

    async loadBalance() {
        const balanceEl = document.getElementById('shop-balance');
        if (!balanceEl) return;

        try {
            const r = await fetch('/api/wallet/balance');
            const data = await r.json();
            balanceEl.textContent = '$' + (data.balance || 0);
        } catch (e) {}
    }

    async loadItems() {
        const itemsEl = document.getElementById('shop-items');
        if (!itemsEl) return;

        try {
            const r = await fetch('/api/shop/items');
            const data = await r.json();

            if (!data || data.length === 0) {
                itemsEl.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-3">No hay items disponibles.</p>';
                return;
            }

            itemsEl.innerHTML = data.map(item => `
                <div class="glass-card-elev shop-card">
                    <div class="shop-emoji">${item.emoji || '🎁'}</div>
                    <div class="shop-name">${item.name}</div>
                    <div class="shop-desc">${item.description || ''}</div>
                    <div class="shop-price">$${item.price || 0}</div>
                    ${item.owned ? '<div class="shop-owned">✓ Adquirido</div>' : `<button class="btn-primary w-full mt-2 text-xs" data-action="click->shop#purchase" data-id="${item.id}">Comprar</button>`}
                </div>
            `).join('');
        } catch (e) {
            itemsEl.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 col-span-3">Error al cargar.</p>';
        }
    }

    async purchase(e) {
        const id = e.target.dataset.id;
        try {
            await fetch('/api/shop/purchase', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: parseInt(id) })
            });
            location.reload();
        } catch (err) {
            console.error(err);
        }
    }
}
