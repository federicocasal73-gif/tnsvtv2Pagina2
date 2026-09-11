import { Controller } from '@hotwired/stimulus';

/**
 * Account switcher — manages user's trading accounts and active selection.
 *
 * Features:
 * - Loads accounts from /api/accounts on connect
 * - Renders chip strip (#account-chips) with: "Todas" + per-account chips + "+ Nueva"
 * - Populates <select id="trade-account-id"> in trade forms
 * - Persists active account in localStorage
 * - Updates global window.TNSVT_ACTIVE_ACCOUNT_ID + TNSVT_ACTIVE_ACCOUNT
 * - Opens accounts manager modal for CRUD
 * - Dispatches `account:changed` event so journal reloads data
 */
export default class extends Controller {
    connect() {
        this.modal = null;
        window._accountSwitcher = this;
        this.loadAccounts();
        // Bind open-manager button
        const openBtn = document.getElementById('open-accounts-manager');
        if (openBtn) {
            openBtn.addEventListener('click', () => this.openManager());
        }
        // Bind save form
        const saveForm = document.getElementById('accounts-manager-form');
        if (saveForm) {
            saveForm.addEventListener('submit', (e) => this.saveAccount(e));
        }
        // Update cap display in modal
        const updateCap = () => {
            const capEl = document.getElementById('accounts-manager-cap');
            if (capEl) capEl.textContent = String(this.maxAccounts || 3);
        };
        updateCap();
        document.addEventListener('refresh-cap', updateCap);
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
            this.renderChips();
            this.populateSelects();
            this.setActiveAccount(this.getPersistedActive());
            this.updateCapHint();
        } catch (e) {
            console.error('[account-switcher] load error', e);
        }
    }

    renderChips() {
        const container = document.getElementById('account-chips');
        if (!container) return;
        const currentId = window.TNSVT_ACTIVE_ACCOUNT_ID || this.getPersistedActive();

        let html = `<button type="button" class="account-chip ${!currentId ? 'active' : ''}" data-account-id=""
                          title="Mostrar trades de todas las cuentas">
                        <span class="material-symbols-elev icon-size-sm">apps</span>
                        <span class="text-xs font-semibold">Todas</span>
                        <span class="account-chip-count">${this.accounts.reduce((s, a) => s + (a.trade_count || 0), 0)}</span>
                    </button>`;

        html += this.accounts.map(a => {
            const active = String(currentId) === String(a.id) ? 'active' : '';
            return `<button type="button" class="account-chip ${active}" data-account-id="${a.id}"
                          style="--chip-color: ${escapeAttr(a.color || '#d4af37')}"
                          title="${escapeAttr(a.name)} · $${Number(a.account_size).toLocaleString()}">
                        <span class="account-chip-dot"></span>
                        <span class="account-chip-name">${escapeHtml(a.name)}</span>
                        <span class="account-chip-size">$${Number(a.account_size).toLocaleString()}</span>
                        <span class="account-chip-count">${a.trade_count || 0}</span>
                        <span class="account-chip-edit material-symbols-elev icon-size-sm" data-edit-id="${a.id}" title="Editar">edit</span>
                    </button>`;
        }).join('');

        container.innerHTML = html;

        // Bind click handlers
        container.querySelectorAll('.account-chip[data-account-id]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (e.target.classList.contains('account-chip-edit')) return;
                const id = btn.dataset.accountId || null;
                this.activate(id);
            });
        });
        container.querySelectorAll('.account-chip-edit').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = parseInt(btn.dataset.editId);
                this.openManager(id);
            });
        });

        // Disable "+" if at cap (also handled in chip HTML)
        const addBtn = document.getElementById('open-accounts-manager');
        if (addBtn) {
            if (this.accounts.length >= this.maxAccounts) {
                addBtn.classList.add('disabled');
                addBtn.setAttribute('title', `Máximo ${this.maxAccounts} cuentas — elimina una para crear otra`);
            } else {
                addBtn.classList.remove('disabled');
                addBtn.setAttribute('title', 'Crear nueva cuenta');
            }
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
            return localStorage.getItem('tnsvt_active_account_id') || null;
        } catch {
            return null;
        }
    }

    setActiveAccount(accountId) {
        const id = accountId ? String(accountId) : null;
        if (id && !this.accounts.find(a => String(a.id) === id)) {
            const first = this.accounts[0];
            if (first) {
                this._setGlobal(first.id, first);
            }
            return;
        }
        const acc = id ? this.accounts.find(a => String(a.id) === id) : null;
        this._setGlobal(id, acc);
    }

    _setGlobal(id, acc) {
        window.TNSVT_ACTIVE_ACCOUNT_ID = id;
        window.TNSVT_ACTIVE_ACCOUNT = acc;
        try { localStorage.setItem('tnsvt_active_account_id', id || ''); } catch {}
        // Dispatch event so journal reloads trades / equity curve
        window.dispatchEvent(new CustomEvent('account:changed', { detail: { id, account: acc } }));
    }

    activate(accountId) {
        const id = accountId ? String(accountId) : null;
        this.setActiveAccount(id);
        this.renderChips();
        this.populateSelects();
        // Refresh trade list + equity curve + calendar
        if (typeof window.loadTrades === 'function') window.loadTrades();
        if (typeof window.loadEquityCurve === 'function') window.loadEquityCurve();
        if (typeof window.loadCalendarMonthly === 'function') window.loadCalendarMonthly();
        if (typeof window.refreshOverviewPanel === 'function') window.refreshOverviewPanel();
        if (typeof window.loadStats === 'function') window.loadStats();
    }

    onTradeFormChange(event) {
        const id = parseInt(event.target.value);
        if (id) this.activate(id);
    }

    async openManager(editId = null) {
        if (this.accounts.length >= this.maxAccounts && !editId) {
            if (window.apiToast) window.apiToast(`Máximo ${this.maxAccounts} cuentas. Elimina una para crear otra.`, 'warn');
            return;
        }
        if (!this.modal) {
            const el = document.getElementById('accounts-manager-modal');
            if (!el) {
                console.warn('[account-switcher] accounts-manager-modal not found in DOM');
                return;
            }
            this.modal = window.apiSetupModal(el);
        }
        // Populate list
        this.renderManagerList(editId);
        this.modal.open();
    }

    renderManagerList(editId = null) {
        const listEl = document.getElementById('accounts-manager-list');
        const formEl = document.getElementById('accounts-manager-form');
        if (!listEl || !formEl) return;

        listEl.innerHTML = this.accounts.map(a => `
            <div class="account-manager-row ${editId === a.id ? 'editing' : ''}" data-id="${a.id}">
                <span class="account-chip-dot" style="--chip-color: ${escapeAttr(a.color || '#d4af37')}"></span>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold truncate">${escapeHtml(a.name)}</div>
                    <div class="text-xs text-[var(--outline-elev)]">$${Number(a.account_size).toLocaleString()} · ${a.trade_count || 0} trades</div>
                </div>
                <button type="button" class="account-manager-btn" data-edit-id="${a.id}" title="Editar">
                    <span class="material-symbols-elev icon-size-sm">edit</span>
                </button>
                <button type="button" class="account-manager-btn account-manager-btn-danger" data-delete-id="${a.id}" title="Eliminar">
                    <span class="material-symbols-elev icon-size-sm">delete</span>
                </button>
            </div>
        `).join('');

        // Bind buttons
        listEl.querySelectorAll('[data-edit-id]').forEach(btn => {
            btn.addEventListener('click', () => this.renderManagerList(parseInt(btn.dataset.editId)));
        });
        listEl.querySelectorAll('[data-delete-id]').forEach(btn => {
            btn.addEventListener('click', () => this.confirmDelete(parseInt(btn.dataset.deleteId)));
        });

        // Reset form for new
        const editing = editId ? this.accounts.find(a => a.id === editId) : null;
        formEl.querySelector('input[name="id"]').value = editing ? editing.id : '';
        formEl.querySelector('input[name="name"]').value = editing ? editing.name : '';
        formEl.querySelector('input[name="account_size"]').value = editing ? editing.account_size : 10000;
        formEl.querySelector('input[name="color"]').value = editing ? (editing.color || '#d4af37') : '#d4af37';
        formEl.querySelector('input[name="icon"]').value = editing ? (editing.icon || '💰') : '💰';
        const errEl = formEl.querySelector('.form-error');
        if (errEl) errEl.textContent = '';
    }

    async saveAccount(event) {
        event.preventDefault();
        const formEl = event.currentTarget;
        const fd = new FormData(formEl);
        const id = fd.get('id');
        const payload = {
            name: fd.get('name'),
            account_size: parseFloat(fd.get('account_size')) || 10000,
            color: fd.get('color') || '#d4af37',
            icon: fd.get('icon') || '💰',
        };
        const errEl = formEl.querySelector('.form-error');
        const url = id ? `/api/accounts/${id}` : '/api/accounts';
        const method = id ? 'PATCH' : 'POST';
        const r = await window.apiFetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (r.ok && r.data && r.data.success) {
            if (window.apiToast) window.apiToast(id ? 'Cuenta actualizada' : 'Cuenta creada', 'success');
            this.modal.close();
            this.loadAccounts();
        } else {
            if (errEl) errEl.textContent = (r.data && (r.data.error || r.data.message)) || 'Error al guardar';
        }
    }

    async confirmDelete(id) {
        const acc = this.accounts.find(a => a.id === id);
        if (!acc) return;
        const trades = acc.trade_count || 0;
        if (trades > 0) {
            if (!confirm(`La cuenta "${acc.name}" tiene ${trades} trades. Se hará soft-delete (se conserva el historial). ¿Continuar?`)) return;
        } else {
            if (!confirm(`¿Eliminar la cuenta "${acc.name}"?`)) return;
        }
        const r = await window.apiFetch(`/api/accounts/${id}`, { method: 'DELETE' });
        if (r.ok && r.data && r.data.success) {
            if (window.apiToast) window.apiToast('Cuenta eliminada', 'success');
            this.loadAccounts();
        } else {
            if (window.apiToast) window.apiToast((r.data && r.data.error) || 'Error al eliminar', 'error');
        }
    }
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function escapeAttr(s) {
    return escapeHtml(s);
}
