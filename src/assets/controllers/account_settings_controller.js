import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.KEY = 'tnsvt_user_prefs_v1';

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }

        const saveBtn = document.getElementById('save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.save());
        }
    }

    init() {
        const prefs = this.loadPrefs();
        this.applyPrefs(prefs);
        this.hideLoading();
    }

    hideLoading() {
        const loading = document.getElementById('loading-state');
        const content = document.getElementById('settings-content');
        if (loading) loading.classList.add('hidden');
        if (content) content.classList.remove('hidden');
    }

    loadPrefs() {
        try {
            return JSON.parse(localStorage.getItem(this.KEY) || '{}');
        } catch (_) {
            return {};
        }
    }

    savePrefs(prefs) {
        try {
            localStorage.setItem(this.KEY, JSON.stringify(prefs));
        } catch (_) {}
    }

    applyPrefs(prefs) {
        if (prefs.theme) {
            const radio = document.querySelector(`input[name="theme"][value="${prefs.theme}"]`);
            if (radio && !radio.disabled) radio.checked = true;
        }
        if (prefs.density) {
            const radio = document.querySelector(`input[name="density"][value="${prefs.density}"]`);
            if (radio) radio.checked = true;
        }
        const elLocale = document.getElementById('locale');
        const elTz = document.getElementById('tz');
        const elLastSaved = document.getElementById('last-saved');
        if (prefs.locale && elLocale) elLocale.value = prefs.locale;
        if (prefs.tz && elTz) elTz.value = prefs.tz;
        const elNotifTrade = document.getElementById('notif-trade');
        const elNotifMacro = document.getElementById('notif-macro');
        const elNotifCommunity = document.getElementById('notif-community');
        const elNotifFrequencies = document.getElementById('notif-frequencies');
        if (typeof prefs.notif_trade === 'boolean' && elNotifTrade) elNotifTrade.checked = prefs.notif_trade;
        if (typeof prefs.notif_macro === 'boolean' && elNotifMacro) elNotifMacro.checked = prefs.notif_macro;
        if (typeof prefs.notif_community === 'boolean' && elNotifCommunity) elNotifCommunity.checked = prefs.notif_community;
        if (typeof prefs.notif_frequencies === 'boolean' && elNotifFrequencies) elNotifFrequencies.checked = prefs.notif_frequencies;
        if (prefs.saved_at && elLastSaved) elLastSaved.textContent = new Date(prefs.saved_at).toLocaleString();
    }

    readPrefs() {
        const elLocale = document.getElementById('locale');
        const elTz = document.getElementById('tz');
        const elNotifTrade = document.getElementById('notif-trade');
        const elNotifMacro = document.getElementById('notif-macro');
        const elNotifCommunity = document.getElementById('notif-community');
        const elNotifFrequencies = document.getElementById('notif-frequencies');
        return {
            theme: document.querySelector('input[name="theme"]:checked')?.value || 'dark',
            density: document.querySelector('input[name="density"]:checked')?.value || 'comfortable',
            locale: elLocale ? elLocale.value : 'en',
            tz: elTz ? elTz.value : 'UTC',
            notif_trade: elNotifTrade ? elNotifTrade.checked : true,
            notif_macro: elNotifMacro ? elNotifMacro.checked : true,
            notif_community: elNotifCommunity ? elNotifCommunity.checked : true,
            notif_frequencies: elNotifFrequencies ? elNotifFrequencies.checked : true,
            saved_at: new Date().toISOString(),
        };
    }

    save() {
        this.savePrefs(this.readPrefs());
        const elLastSaved = document.getElementById('last-saved');
        if (elLastSaved) elLastSaved.textContent = new Date().toLocaleString();
        if (window.apiToast) window.apiToast('Preferencias guardadas', 'success');
    }
}
