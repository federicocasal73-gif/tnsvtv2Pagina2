import { Controller } from '@hotwired/stimulus';

/**
 * Settings page — loads user preferences + global settings, handles inline updates.
 * Usage in template: data-controller="settings" + data-settings-target="<name>"
 */
export default class extends Controller {
    static targets = [
        'sound',
        'theme',
        'savePrefsBtn',
        'reloadBtn',
        'container',
        'importFile',
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
            if (r.ok && r.data?.user) {
                if (r.data.user.notification_sound && this.hasSoundTarget) {
                    this.soundTarget.value = r.data.user.notification_sound;
                }
                if (r.data.user.theme_preference && this.hasThemeTarget) {
                    this.themeTarget.value = r.data.user.theme_preference;
                    if (window.tnsvtTheme) {
                        window.tnsvtTheme.set(r.data.user.theme_preference);
                    }
                }
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
        if (this.hasSoundTarget) await this.persistPreference(this.soundTarget.value);
        if (this.hasThemeTarget) await this.persistPreference(this.themeTarget.value);
    }

    async persistPreference(value) {
        const originalText = this.savePrefsBtnTarget?.textContent;
        const doneLoading = (typeof window.apiButtonLoading === 'function' && this.hasSavePrefsBtnTarget)
            ? window.apiButtonLoading(this.savePrefsBtnTarget)
            : null;
        if (this.hasSavePrefsBtnTarget) {
            this.savePrefsBtnTarget.textContent = 'Guardando...';
        }
        try {
            // Detectar qué campo se está guardando según el target activo.
            const payload = {};
            if (this.hasSoundTarget) payload.notification_sound = this.soundTarget.value;
            if (this.hasThemeTarget) payload.theme_preference = this.themeTarget.value;

            const r = await window.apiFetch('/api/profile', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
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
        } finally {
            if (doneLoading) doneLoading();
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
                if (window.apiToast) window.apiToast('Error: ' + (data.error || 'desconocido'), 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Error: ' + e.message, 'error');
        }
    }

    reload() {
        this.loadAll();
    }

    // F13: export user preferences as a JSON download.
    exportPrefs() {
        const prefs = {
            tnsvt: 'user-preferences',
            version: 1,
            exported_at: new Date().toISOString(),
            theme_preference: this.hasThemeTarget ? this.themeTarget.value : null,
            notification_sound: this.hasSoundTarget ? this.soundTarget.value : null,
        };
        const blob = new Blob([JSON.stringify(prefs, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'tnsvt-preferencias.json';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        if (window.apiToast) window.apiToast('Preferencias exportadas', 'success');
    }

    // F13: import preferences from a JSON file, validate, then persist.
    importPrefs() {
        if (this.hasImportFileTarget) {
            this.importFileTarget.value = '';
            this.importFileTarget.click();
            if (!this.importFileTarget.dataset.wired) {
                this.importFileTarget.dataset.wired = '1';
                this.importFileTarget.addEventListener('change', () => this.onImportFile());
            }
        }
    }

    async onImportFile() {
        const file = this.hasImportFileTarget ? this.importFileTarget.files[0] : null;
        if (!file) return;
        try {
            const text = await file.text();
            const data = JSON.parse(text);
            if (!data || data.tnsvt !== 'user-preferences') {
                throw new Error('formato inválido');
            }
            const themes = ['auto', 'dark', 'light'];
            const sounds = ['chime', 'mario_coin', 'zelda_secret', 'sonic_ring', 'apple_tritone',
                'pixel_popcorn', 'pokemon_levelup', 'deus_ex_scan', 'indiana_jones_whip',
                'msn_message', 'swoosh'];
            const payload = {};
            if (typeof data.theme_preference === 'string' && themes.includes(data.theme_preference)) {
                payload.theme_preference = data.theme_preference;
                if (this.hasThemeTarget) this.themeTarget.value = data.theme_preference;
                if (window.tnsvtTheme) window.tnsvtTheme.set(data.theme_preference);
            }
            if (typeof data.notification_sound === 'string' && sounds.includes(data.notification_sound)) {
                payload.notification_sound = data.notification_sound;
                if (this.hasSoundTarget) this.soundTarget.value = data.notification_sound;
            }
            if (Object.keys(payload).length === 0) {
                throw new Error('sin preferencias válidas');
            }
            const r = await window.apiFetch('/api/profile', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (r.ok) {
                if (window.apiToast) window.apiToast('Preferencias importadas', 'success');
            } else {
                throw new Error((r.data && r.data.error) || 'error del servidor');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('No se pudo importar: ' + (e.message || 'error'), 'error');
        }
    }

    escape(str) {
        return String(str ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}