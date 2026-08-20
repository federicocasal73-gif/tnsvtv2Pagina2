import { Controller } from '@hotwired/stimulus';

/**
 * Loads wallet balance + recent transactions on page mount.
 * Renders into `data-wallet-target="balance"` and `data-wallet-target="transactions"`.
 *
 * Usage:
 *   <div data-controller="wallet">
 *       <div data-wallet-target="balance">—</div>
 *       <div data-wallet-target="transactions">Cargando...</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['balance', 'transactions'];

    connect() {
        this.loadBalance();
        this.loadTransactions();
    }

    async loadBalance() {
        try {
            const r = await window.apiFetch('/api/wallet/balance');
            if (r.ok && r.data) {
                this.balanceTarget.textContent = '$' + (r.data.balance_usd || '0.00');
            }
        } catch (e) {
            // Silent fail — UI shows default
        }
    }

    async loadTransactions() {
        try {
            const r = await window.apiFetch('/api/wallet/transactions');
            if (!r.ok || !r.data) {
                this.transactionsTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
                return;
            }
            const txs = Array.isArray(r.data) ? r.data : (r.data.transactions || []);
            if (txs.length === 0) {
                this.transactionsTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin transacciones.</p>';
                return;
            }
            this.transactionsTarget.innerHTML = txs.map((tx) => this.renderTx(tx)).join('');
        } catch (e) {
            this.transactionsTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Error al cargar.</p>';
        }
    }

    renderTx(tx) {
        const isCredit = (tx.amount ?? 0) > 0;
        const amount = parseFloat(tx.amount ?? 0).toFixed(2);
        return `
            <div class="tx-item">
                <div class="tx-icon ${isCredit ? 'credit' : 'debit'}">${isCredit ? '↓' : '↑'}</div>
                <div class="tx-info">
                    <div class="tx-desc">${this.escape(tx.notes || tx.type || 'Transacción')}</div>
                    <div class="tx-date">${this.escape(tx.created_at || '')}</div>
                </div>
                <div class="tx-amount ${isCredit ? 'credit' : 'debit'}">${isCredit ? '+' : ''}$${amount}</div>
            </div>
        `;
    }

    escape(str) {
        return String(str ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}