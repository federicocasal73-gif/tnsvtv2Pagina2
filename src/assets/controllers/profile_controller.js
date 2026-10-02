import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.loadProfile();
        this.loadJournalStats();
        this.loadSecurity();
        this._secChallengeId = null;
        this._secTimer = null;

        const avatarInput = document.getElementById('avatar-input');
        if (avatarInput) {
            avatarInput.addEventListener('change', () => this.uploadAvatar());
        }

        const saveBtn = document.getElementById('save-profile');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveProfile());
        }

        const copyBtn = document.getElementById('profile-code-copy');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => this.copyCode());
        }

        const shareBtn = document.getElementById('profile-share');
        if (shareBtn) {
            shareBtn.addEventListener('click', () => this.shareProfile());
        }

        const emailSend = document.getElementById('sec-email-send');
        if (emailSend) {
            emailSend.addEventListener('click', () => this.sendEmailCode());
        }
        const emailVerify = document.getElementById('sec-email-verify');
        if (emailVerify) {
            emailVerify.addEventListener('click', () => this.verifyEmailCode());
        }
        const emailResend = document.getElementById('sec-email-resend');
        if (emailResend) {
            emailResend.addEventListener('click', () => this.sendEmailCode());
        }
        const passSave = document.getElementById('sec-pass-save');
        if (passSave) {
            passSave.addEventListener('click', () => this.savePassword());
        }
        const passSend = document.getElementById('sec-pass-send');
        if (passSend) {
            passSend.addEventListener('click', () => this.sendPassCode());
        }
        const passCode = document.getElementById('sec-pass-code');
        if (passCode) {
            passCode.addEventListener('input', () => {
                passCode.value = passCode.value.replace(/\D/g, '').slice(0, 6);
            });
            passCode.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.savePassword();
                }
            });
        }
        const emailCode = document.getElementById('sec-email-code');
        if (emailCode) {
            emailCode.addEventListener('input', () => {
                emailCode.value = emailCode.value.replace(/\D/g, '').slice(0, 6);
            });
            emailCode.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.verifyEmailCode();
                }
            });
        }
    }

    async copyCode() {
        const codeEl = document.getElementById('profile-code');
        const code = codeEl ? codeEl.textContent.trim() : '';
        if (!code || code === '—' || code === 'Cargando...') return;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(code);
            } else {
                const ta = document.createElement('textarea');
                ta.value = code;
                ta.style.position = 'absolute';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
            }
            if (window.apiToast) window.apiToast('Código copiado', 'success');
        } catch (e) {
            if (window.apiToast) window.apiToast('No se pudo copiar', 'error');
        }
    }

    // F8: copy the public profile URL with the user's code.
    async shareProfile() {
        const codeEl = document.getElementById('profile-code');
        const code = codeEl ? codeEl.textContent.trim() : '';
        if (!code || code === '—' || code === 'Cargando...') return;
        const url = location.origin + '/profile/' + encodeURIComponent(code);
        try {
            // Web Share API first (mobile-friendly), fallback to copy.
            if (navigator.share) {
                try {
                    await navigator.share({ title: 'Mi perfil en T.N.S.V.T', url });
                    return;
                } catch (e) {
                    /* user cancelled, fall through */
                }
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(url);
                if (window.apiToast) window.apiToast('Enlace copiado al portapapeles', 'success');
            } else {
                const ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'absolute';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                if (window.apiToast) window.apiToast('Enlace copiado', 'success');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('No se pudo compartir', 'error');
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
            const copyBtn = document.getElementById('profile-code-copy');
            if (copyBtn && u.code) copyBtn.style.display = '';
            const shareBtn = document.getElementById('profile-share');
            if (shareBtn && u.code) shareBtn.style.display = '';
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
                    notification_sound: editSound ? editSound.value : 'chime',
                }),
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

            const stats = data.stats || data;

            const jsTotal = document.getElementById('js-total');
            if (jsTotal) jsTotal.textContent = stats.total || 0;

            const jsWins = document.getElementById('js-wins');
            if (jsWins) jsWins.textContent = stats.wins || 0;

            const jsWinrate = document.getElementById('js-winrate');
            if (jsWinrate) jsWinrate.textContent = (stats.win_rate || 0) + '%';

            const pnl = parseFloat(stats.total_pnl || 0);
            const pnlEl = document.getElementById('js-pnl');
            if (pnlEl) {
                pnlEl.textContent = (pnl >= 0 ? '+' : '') + '$' + Math.abs(pnl).toFixed(0);
                pnlEl.className =
                    'text-lg font-bold ' + (pnl >= 0 ? 'text-green-400' : 'text-red-400');
            }
        } catch (e) {}
    }

    // ─── Mail y verificación en dos pasos ─────────────────────────
    async loadSecurity() {
        try {
            const r = await fetch('/api/auth/check', { credentials: 'same-origin' });
            const data = await r.json();
            const u = (data && data.user) || {};
            const badge = document.getElementById('sec-email-badge');
            if (badge) {
                if (u.email_verified) {
                    badge.textContent = '✓ verificado';
                    badge.style.color = 'var(--success, #34d399)';
                } else if (u.email_masked) {
                    badge.textContent = 'pendiente de verificación';
                    badge.style.color = 'var(--gold-elev)';
                } else {
                    badge.textContent = 'sin cargar';
                    badge.style.color = 'var(--outline-elev)';
                }
            }
            const status = document.getElementById('sec-2fa-status');
            if (status) {
                status.textContent =
                    'Verificación en dos pasos: ' +
                    (u.two_factor_enabled ? 'ACTIVADA' : 'desactivada') +
                    (u.two_factor_required ? ' — requerida: cargá tu mail' : '');
            }
        } catch (e) {}
    }

    async sendEmailCode() {
        const input = document.getElementById('sec-email');
        const email = input ? input.value.trim() : '';
        if (!email || email.indexOf('@') < 0) {
            if (window.apiToast) window.apiToast('Ingresá un mail válido', 'warning');
            return;
        }
        try {
            const r = await fetch('/api/profile/email', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email }),
            });
            const data = await r.json();
            if (data.success) {
                this._secChallengeId = data.challenge_id;
                const row = document.getElementById('sec-email-code-row');
                if (row) row.style.display = '';
                this.startResendCooldown(60);
                if (window.apiToast)
                    window.apiToast('Código enviado a ' + (data.masked_email || email), 'success');
            } else if (window.apiToast) {
                window.apiToast('Error: ' + (data.error || 'desconocido'), 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Sin conexión con el servidor', 'error');
        }
    }

    async verifyEmailCode() {
        const input = document.getElementById('sec-email-code');
        const code = input ? input.value.trim() : '';
        if (code.length < 6) {
            if (window.apiToast) window.apiToast('Ingresá los 6 dígitos', 'warning');
            return;
        }
        try {
            const r = await fetch('/api/profile/email/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ challenge_id: this._secChallengeId, code: code }),
            });
            const data = await r.json();
            if (data.success) {
                const row = document.getElementById('sec-email-code-row');
                if (row) row.style.display = 'none';
                clearInterval(this._secTimer);
                this.loadSecurity();
                if (window.apiToast) window.apiToast('Mail verificado ✓', 'success');
            } else if (window.apiToast) {
                window.apiToast('Error: ' + (data.error || 'código incorrecto'), 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Sin conexión con el servidor', 'error');
        }
    }

    startResendCooldown(sec) {
        const btn = document.getElementById('sec-email-resend');
        const label = document.getElementById('sec-email-timer');
        if (!btn) return;
        clearInterval(this._secTimer);
        let left = sec;
        btn.disabled = true;
        if (label) label.textContent = left;
        this._secTimer = setInterval(() => {
            left -= 1;
            if (left <= 0) {
                clearInterval(this._secTimer);
                btn.disabled = false;
                btn.innerHTML = 'Reenviar';
            } else if (label) {
                label.textContent = left;
            }
        }, 1000);
    }

    async sendPassCode() {
        try {
            const r = await fetch('/api/profile/password/code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
            });
            const data = await r.json();
            if (data.success) {
                if (window.apiToast)
                    window.apiToast(
                        'Código enviado a ' +
                            (data.masked_email || 'tu mail') +
                            ' (vale 10 minutos)',
                        'success'
                    );
                const input = document.getElementById('sec-pass-code');
                if (input) input.focus();
            } else if (window.apiToast) {
                window.apiToast('Error: ' + (data.error || 'desconocido'), 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Sin conexión con el servidor', 'error');
        }
    }

    async savePassword() {
        const currentEl = document.getElementById('sec-pass-current');
        const newEl = document.getElementById('sec-pass-new');
        const codeEl = document.getElementById('sec-pass-code');
        const fresh = newEl ? newEl.value : '';
        if (fresh.length < 10) {
            if (window.apiToast) window.apiToast('Mínimo 10 caracteres', 'warning');
            return;
        }
        const emailCode = codeEl ? codeEl.value.trim() : '';
        if (emailCode.length < 6) {
            if (window.apiToast)
                window.apiToast('Pedí un código con Enviar código e ingresalo', 'warning');
            return;
        }
        try {
            const r = await fetch('/api/profile/password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    current_password: currentEl ? currentEl.value : '',
                    new_password: fresh,
                    email_code: emailCode,
                }),
            });
            const data = await r.json();
            if (data.success) {
                if (currentEl) currentEl.value = '';
                if (newEl) newEl.value = '';
                if (codeEl) codeEl.value = '';
                if (window.apiToast) window.apiToast('Contraseña actualizada', 'success');
            } else if (window.apiToast) {
                window.apiToast('Error: ' + (data.error || 'desconocido'), 'error');
            }
        } catch (e) {
            if (window.apiToast) window.apiToast('Sin conexión con el servidor', 'error');
        }
    }
}
