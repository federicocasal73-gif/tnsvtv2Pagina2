import { Controller } from '@hotwired/stimulus';

/**
 * Settings page — loads user preferences + global settings, handles inline updates.
 * Usage in template: data-controller="settings" + data-settings-target="<name>"
 */
export default class extends Controller {
    static targets = [
        'sound',
        'savePrefsBtn',
        'reloadBtn',
        'container',
    ];

    static values = {
        userCode: String,
    };

    connect() {
        this.loadUserPreferences();
        this.loadAll();
    }

    async loadUserPreferences() {
        if (!this.hasUserCodeValue) return;
        try {
            const r = await window.apiFetch(`/api/profile/${this.userCodeValue}`);
            if (r.ok && r.data?.user?.notification_sound) {
                this.soundTarget.value = r.data.user.notification_sound;
            }
        } catch (e) {
            // Silent fail
        }
    }

    async loadAll() {
        try {
            const r = await fetch('/sanctum/api/settings', { credentials: 'include' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const data = await r.json();
            if (!data.success) throw new Error(data.error || 'unknown');
            this.render(data.by_category);
        } catch (e) {
            this.containerTarget.innerHTML = `<p class="text-red-400 text-center py-8">Error: ${e.message}</p>`;
        }
    }

    render(byCategory) {
        const labels = { tier: 'Tiers & Pricing', feature: 'Feature Flags', general: 'General', limit: 'Límites del Sistema' };
        const icons = { tier: 'workspace_premium', feature: 'toggle_on', general: 'settings', limit: 'tune' };
        let html = '';
        for (const [cat, settings] of Object.entries(byCategory)) {
            html += this.renderCategory(cat, settings, labels, icons);
        }
        this.containerTarget.innerHTML = html;
    }

    renderCategory(cat, settings, labels, icons) {
        const label = labels[cat] || cat;
        const icon = icons[cat] || 'settings';
        let items = '';
        for (const s of settings) {
            items += this.renderSetting(s, cat);
        }
        return `
            <div class="glass-card-elev p-6 mb-6">
                <div class="flex items-center gap-3 mb-4">
                    <span class="material-symbols-elev text-[var(--gold-elev)]">${icon}</span>
                    <h3 class="text-lg font-semibold text-[var(--on-surface-elev)]">${label}</h3>
                    <span class="text-xs text-[var(--outline-elev)]">${settings.length} settings</span>
                </div>
                <div class="space-y-3">${items}</div>
            </div>
        `;
    }

    renderSetting(s, cat) {
        const isFeature = cat === 'feature';
        const toggleOn = s.value === '1' || s.value === 'true';
        const inputControl = isFeature
            ? `<label class="toggle-switch">
                 <input type="checkbox" data-key="${s.key}" ${toggleOn ? 'checked' : ''} data-action="change->settings#updateToggle" />
                 <span class="toggle-slider"></span>
               </label>`
            : `<input type="text" data-key="${s.key}" value="${this.escape(s.value || '')}" data-action="blur->settings#updateText"
                 class="w-32 px-2 py-1 rounded bg-[var(--glass-bg-elev)] border border-[var(--outline-variant-elev)] text-[var(--on-surface-elev)] text-sm focus:outline-none focus:border-[var(--gold-elev)]" />`;

        return `
            <div class="flex items-start gap-3 p-3 rounded glass-card-elev">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-mono text-[var(--on-surface-elev)] break-all">${this.escape(s.key)}</p>
                    ${s.description ? `<p class="text-xs text-[var(--outline-elev)] mt-1">${this.escape(s.description)}</p>` : ''}
                </div>
                <div class="flex items-center gap-2">${inputControl}</div>
            </div>
        `;
    }

    async savePreference(event) {
        const value = event.target.value;
        await this.persistPreference(value);
    }

    async saveAll() {
        if (!this.hasSoundTarget) return;
        await this.persistPreference(this.soundTarget.value);
    }

    async persistPreference(value) {
        const originalText = this.savePrefsBtnTarget?.textContent;
        if (this.hasSavePrefsBtnTarget) {
            this.savePrefsBtnTarget.textContent = 'Guardando...';
        }
        try {
            const r = await window.apiFetch('/api/profile', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_sound: value }),
            });
            if (r.ok && this.hasSavePrefsBtnTarget) {
                this.savePrefsBtnTarget.textContent = '✓ Guardado';
                setTimeout(() => {
                    if (this.hasSavePrefsBtnTarget) {
                        this.savePrefsBtnTarget.textContent = originalText || 'Guardar Preferencias';
                    }
                }, 2000);
            }
        } catch (e) {
            if (this.hasSavePrefsBtnTarget) {
                this.savePrefsBtnTarget.textContent = originalText || 'Guardar Preferencias';
            }
        }
    }

    async updateToggle(event) {
        const input = event.target;
        await this.persistSetting(input.dataset.key, input.checked ? '1' : '0');
    }

    async updateText(event) {
        const input = event.target;
        await this.persistSetting(input.dataset.key, input.value);
    }

    async persistSetting(key, value) {
        try {
            const r = await fetch(`/sanctum/api/settings/${encodeURIComponent(key)}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ value: String(value) }),
            });
            const data = await r.json();
            if (!data.success) {
                alert('Error: ' + (data.error || 'desconocido'));
            }
        } catch (e) {
            alert('Error: ' + e.message);
        }
    }

    reload() {
        this.loadAll();
    }

    escape(str) {
        return String(str ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}