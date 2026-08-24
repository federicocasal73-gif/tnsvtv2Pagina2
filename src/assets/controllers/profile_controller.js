import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.loadProfile();
        this.loadJournalStats();

        const avatarInput = document.getElementById('avatar-input');
        if (avatarInput) {
            avatarInput.addEventListener('change', () => this.uploadAvatar());
        }

        const saveBtn = document.getElementById('save-profile');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveProfile());
        }
    }

    async loadProfile() {
        const nameEl = document.getElementById('profile-name');
        const codeEl = document.getElementById('profile-code');
        const tierEl = document.getElementById('profile-tier');
        const vipEl = document.getElementById('profile-vip');
        const avatarImg = document.getElementById('avatar-img');
        const avatarInitial = document.getElementById('avatar-initial');
        const editName = document.getElementById('edit-name');
        const editSound = document.getElementById('edit-sound');

        try {
            const userCode = window.TNSVT_USER?.code || '{{ app.user.code }}';
            const r = await fetch('/api/profile/' + userCode);
            const data = await r.json();

            if (!data.success) return;

            const u = data.user;
            if (nameEl) nameEl.textContent = u.name || '—';
            if (codeEl) codeEl.textContent = u.code || '—';
            if (tierEl) tierEl.textContent = u.tier || 'INITIATE';
            if (avatarImg && u.avatar_url) {
                avatarImg.src = u.avatar_url;
                avatarImg.style.display = '';
                if (avatarInitial) avatarInitial.style.display = 'none';
            } else if (avatarInitial) {
                avatarInitial.textContent = (u.name || '?').charAt(0);
            }

            const profileReputation = document.getElementById('profile-reputation');
            if (profileReputation) profileReputation.textContent = u.reputation || 0;

            const profileCoins = document.getElementById('profile-coins');
            if (profileCoins) profileCoins.textContent = u.coins || 0;

            const profileWallet = document.getElementById('profile-wallet');
            if (profileWallet) profileWallet.textContent = '$' + (u.wallet_balance || '0');

            if (vipEl && u.vip_until) vipEl.style.display = '';
            if (editName) editName.value = u.name || '';
            if (editSound) editSound.value = u.notification_sound || 'chime';
        } catch (e) {}
    }

    async uploadAvatar() {
        const avatarInput = document.getElementById('avatar-input');
        const avatarImg = document.getElementById('avatar-img');
        const avatarInitial = document.getElementById('avatar-initial');

        const file = avatarInput.files[0];
        if (!file) return;

        const fd = new FormData();
        fd.append('avatar', file);

        try {
            const r = await fetch('/api/profile/avatar', {
                method: 'POST',
                body: fd,
            });
            const data = await r.json();

            if (data.success && avatarImg) {
                avatarImg.src = data.avatar_url + '?t=' + Date.now();
                avatarImg.style.display = '';
                if (avatarInitial) avatarInitial.style.display = 'none';
                if (window.apiToast) window.apiToast('Avatar actualizado', 'success');
            } else if (window.apiToast) {
                window.apiToast('Error al subir avatar', 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Error al subir avatar', 'error');
        }
    }

    async saveProfile() {
        const editName = document.getElementById('edit-name');
        const editSound = document.getElementById('edit-sound');
        const nameEl = document.getElementById('profile-name');

        try {
            const r = await fetch('/api/profile', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: editName ? editName.value : '',
                    notification_sound: editSound ? editSound.value : 'chime'
                })
            });
            const data = await r.json();

            if (data.success) {
                if (nameEl) nameEl.textContent = data.user.name;
                if (window.apiToast) window.apiToast('Perfil guardado', 'success');
            } else if (window.apiToast) {
                window.apiToast('Error al guardar', 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Error al guardar', 'error');
        }
    }

    async loadJournalStats() {
        try {
            const r = await fetch('/api/journal/stats');
            const data = await r.json();

            const jsTotal = document.getElementById('js-total');
            if (jsTotal) jsTotal.textContent = data.total || 0;

            const jsWins = document.getElementById('js-wins');
            if (jsWins) jsWins.textContent = data.wins || 0;

            const jsWinrate = document.getElementById('js-winrate');
            if (jsWinrate) jsWinrate.textContent = (data.win_rate || 0) + '%';

            const pnl = parseFloat(data.total_pnl || 0);
            const pnlEl = document.getElementById('js-pnl');
            if (pnlEl) {
                pnlEl.textContent = (pnl >= 0 ? '+' : '') + '$' + Math.abs(pnl).toFixed(0);
                pnlEl.className = 'text-lg font-bold ' + (pnl >= 0 ? 'text-green-400' : 'text-red-400');
            }
        } catch (e) {}
    }
}
