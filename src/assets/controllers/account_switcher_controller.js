import { Controller } from '@hotwired/stimulus';

/**
 * Account switcher — manages user's trading accounts and active selection.
 *
 * - Loads accounts from /api/accounts on connect
 * - Populates <select id="trade-account-id"> in trade forms
 * - Persists active account in localStorage
 * - Updates global window.TNSVT_ACTIVE_ACCOUNT_ID + TNSVT_ACTIVE_ACCOUNT
 * - Re-renders when account is changed
 */
export default class extends Controller {
    connect() {
        this.loadAccounts();
    }

    async loadAccounts() {
        try {
            const r = await window.apiFetch('/api/accounts', { silent: true });
            if (!r.ok || !r.data || !r.data.success) {
                console.warn('[account-switcher] Failed to load accounts');
                return;
            }
            this.accounts = r.data.accounts || [];
            this.maxAccounts = r.data.max_accounts || 3;
            this.populateSelects();
            this.setActiveAccount(this.getPersistedActive());
            this.updateCapHint();
        } catch (e) {
            console.error('[account-switcher] load error', e);
        }
    }

    populateSelects() {
        const selects = document.querySelectorAll('select#trade-account-id');
        selects.forEach(sel => {
            const current = window.TNSVT_ACTIVE_ACCOUNT_ID || this.getPersistedActive() || '';
            sel.innerHTML = this.accounts.map(a =>
                `<option value="${a.id}" data-size="${a.account_size}">${escapeHtml(a.name)} — $${Number(a.account_size).toLocaleString()}</option>`
            ).join('');
            sel.value = current ? String(current) : '';
        });
    }

    updateCapHint() {
        const hint = document.getElementById('trade-account-cap-hint');
        if (hint) {
            hint.textContent = `(${this.accounts.length}/${this.maxAccounts})`;
        }
    }

    getPersistedActive() {
        try {
            return parseInt(localStorage.getItem('tnsvt_active_account_id')) || null;
        } catch {
            return null;
        }
    }

    setActiveAccount(accountId) {
        const id = parseInt(accountId);
        if (!id || !this.accounts.find(a => a.id === id)) {
            // fallback to first active account
            const first = this.accounts[0];
            if (first) {
                window.TNSVT_ACTIVE_ACCOUNT_ID = first.id;
                window.TNSVT_ACTIVE_ACCOUNT = first;
                try { localStorage.setItem('tnsvt_active_account_id', String(first.id)); } catch {}
            }
            return;
        }
        const acc = this.accounts.find(a => a.id === id);
        window.TNSVT_ACTIVE_ACCOUNT_ID = id;
        window.TNSVT_ACTIVE_ACCOUNT = acc;
        try { localStorage.setItem('tnsvt_active_account_id', String(id)); } catch {}
    }

    onTradeFormChange(event) {
        const id = parseInt(event.target.value);
        if (id) {
            this.setActiveAccount(id);
        }
    }
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
