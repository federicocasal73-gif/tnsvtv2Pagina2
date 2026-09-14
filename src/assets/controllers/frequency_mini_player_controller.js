import { Controller } from '@hotwired/stimulus';

/**
 * Frequencies global mini-player.
 *
 * Mounts once inside <div id="sanctum-floats"> in templates/shell.html.twig,
 * which is marked data-turbo-permanent by the shell controller. Because the
 * floats div (and therefore this controller's element + its Web Audio API
 * AudioContext) survive every Turbo navigation, audio genuinely persists
 * across pages.
 *
 * Responsibilities:
 *  - On connect, reconcile any orphan active session via
 *    GET /api/frequencies/session/active and offer resume / abandon.
 *  - Listen for `tnsvt:freq:start` CustomEvents dispatched by the local
 *    `frequency_player_controller` (so the inline page can use the same
 *    audio path as before without re-creating an AudioContext — the local
 *    page controllers no longer spawn their own oscillator).
 *  - Own the actual oscillator + gain envelope and update the elapsed time
 *    display on the mini-player face.
 *  - Listen for `tnsvt:freq:stop` and `tnsvt:freq:request_stop` to stop.
 *
 * Failure modes:
 *  - If <div id="sanctum-floats"> is not present (e.g. legacy shell), the
 *    controller still mounts but the user has no UI; the local player will
 *    then synthesise its own AudioContext (graceful degradation).
 */
const FADE_IN_S  = 2.0;
const FADE_OUT_S = 0.5;

export default class extends Controller {
    static targets = [
        'panel', 'frequencyLabel', 'elapsedLabel', 'durationLabel',
        'resumeModal', 'resumeModalFreq', 'resumeModalRemaining', 'resumeModalAbandon',
    ];

    connect() {
        this.audioCtx = null;
        this.oscillator = null;
        this.gainNode = null;
        this.timerInterval = null;
        this.secondsElapsed = 0;
        this.session = null; // {sessionId, frequency, durationMinutes, startedAt, isInfinite}

        this.gap = Math.floor(Math.random() * 800);
        this.pinged = false;

        this.boundOnStart = this.onStart.bind(this);
        this.boundOnStop = this.onStop.bind(this);
        this.boundOnRequestStop = this.onRequestStop.bind(this);
        window.addEventListener('tnsvt:freq:start', this.boundOnStart);
        window.addEventListener('tnsvt:freq:stop', this.boundOnStop);
        window.addEventListener('tnsvt:freq:request_stop', this.boundOnRequestStop);

        this.reconcile();
    }

    disconnect() {
        window.removeEventListener('tnsvt:freq:start', this.boundOnStart);
        window.removeEventListener('tnsvt:freq:stop', this.boundOnStop);
        window.removeEventListener('tnsvt:freq:request_stop', this.boundOnRequestStop);
        this.stopTimer();
    }

    async reconcile() {
        try {
            const r = await window.apiFetch('/api/frequencies/session/active', { silent: true });
            if (!r.ok || !r.data || !r.data.session) return;
            this.session = r.data.session;
            const remaining = r.data.session.remainingSeconds;
            if (remaining !== null && remaining <= 0) {
                // Already past duration; offer to abandon rather than resume
                if (this.hasResumeModalTarget) {
                    if (this.hasResumeModalFreqTarget) {
                        this.resumeModalFreqTarget.textContent = this.fmtFreq(this.session);
                    }
                    if (this.hasResumeModalRemainingTarget) {
                        this.resumeModalRemainingTarget.textContent = 'ya finalizada';
                    }
                    this.resumeModalTarget.hidden = false;
                }
                return;
            }
            if (this.hasResumeModalTarget) {
                if (this.hasResumeModalFreqTarget) {
                    this.resumeModalFreqTarget.textContent = this.fmtFreq(this.session);
                }
                if (this.hasResumeModalRemainingTarget) {
                    const mins = Math.floor(remaining / 60);
                    const secs = remaining % 60;
                    this.resumeModalRemainingTarget.textContent =
                        `${mins}:${String(secs).padStart(2, '0')} restantes`;
                }
                this.resumeModalTarget.hidden = false;
            }
        } catch (e) {
            // Silent — non-essential
        }
    }

    async resumeSession() {
        if (!this.session) return;
        // Re-create audio using stored session info. The backend is the
        // source of truth; we just spin the oscillator again and dispatch
        // tnsvt:freq:start so the local page controller can also pick it up
        // (no-op if the user is on a non-frequency page).
        const detail = {
            sessionId: this.session.sessionId,
            frequency: this.session.frequency.hz,
            name: this.session.frequency.name,
            durationMinutes: this.session.durationMinutes,
            startedAt: Date.now(),
            isResume: true,
        };
        this.hideResumeModal();
        this.onStart({ detail });
    }

    async abandonSession() {
        if (!this.session) return;
        const sid = this.session.sessionId;
        try {
            await window.apiFetch(`/api/frequencies/session/${sid}/abandon`, {
                method: 'DELETE',
                silent: true,
            });
        } catch (e) {}
        this.hideResumeModal();
        this.session = null;
        if (this.hasPanelTarget) this.panelTarget.hidden = true;
    }

    hideResumeModal() {
        if (this.hasResumeModalTarget) this.resumeModalTarget.hidden = true;
    }

    async onStart(event) {
        const d = event.detail || {};
        this.session = {
            sessionId: d.sessionId,
            frequency: { hz: d.frequency, name: d.name },
            durationMinutes: d.durationMinutes ?? 0,
            isInfinite: (d.durationMinutes ?? 0) === 0,
            startedAt: d.startedAt ?? Date.now(),
        };
        // Render UI
        if (this.hasPanelTarget) this.panelTarget.hidden = false;
        if (this.hasFrequencyLabelTarget) {
            this.frequencyLabelTarget.textContent = `${d.frequency} Hz · ${d.name || ''}`.trim();
        }
        if (this.hasDurationLabelTarget) {
            const m = d.durationMinutes || 0;
            this.durationLabelTarget.textContent = m === 0 ? '∞' : `${m} min`;
        }
        // Start audio (lazily, requires user gesture — startSession() is
        // always triggered from a click, so this is safe).
        try {
            if (!this.audioCtx) {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (this.audioCtx.state === 'suspended') {
                await this.audioCtx.resume();
            }
            this.stopOscillator({ silent: true });
            this.oscillator = this.audioCtx.createOscillator();
            this.gainNode = this.audioCtx.createGain();
            this.oscillator.frequency.value = d.frequency;
            this.oscillator.type = 'sine';
            this.gainNode.gain.value = 0.05;
            this.oscillator.connect(this.gainNode);
            this.gainNode.connect(this.audioCtx.destination);
            this.oscillator.start();
            this.gainNode.gain.linearRampToValueAtTime(0.15, this.audioCtx.currentTime + FADE_IN_S);
        } catch (e) {
            if (window.apiToast) window.apiToast('Audio error: ' + e.message, 'error');
        }

        // Reset timer
        this.secondsElapsed = 0;
        this.stopTimer();
        this.timerInterval = setInterval(() => {
            this.secondsElapsed += 1;
            this.renderElapsed();
            const totalSec = (d.durationMinutes || 0) * 60;
            if (!this.session || !this.session.isInfinite && totalSec > 0 && this.secondsElapsed >= totalSec) {
                this.finishNatural();
            }
        }, 1000);
        this.renderElapsed();
    }

    onStop() {
        this.stopOscillator();
        this.stopTimer();
        if (this.hasPanelTarget) this.panelTarget.hidden = true;
        this.session = null;
    }

    onRequestStop() {
        // Page-level controller asked us to stop. We play the fade + emit
        // tnsvt:freq:stop so listeners (page UI) update their state.
        const sid = this.session?.sessionId;
        this.stopOscillator();
        this.stopTimer();
        if (sid) {
            window.apiFetch(`/api/frequencies/session/${sid}/end`, {
                method: 'POST',
                silent: true,
            }).catch(() => {});
        }
        window.dispatchEvent(new CustomEvent('tnsvt:freq:stop', { detail: { reason: 'user_requested' } }));
        if (this.hasPanelTarget) this.panelTarget.hidden = true;
        this.session = null;
    }

    async finishNatural() {
        this.onStop();
        const sid = this.session?.sessionId;
        if (sid) {
            try {
                await window.apiFetch(`/api/frequencies/session/${sid}/end`, {
                    method: 'POST',
                    silent: true,
                });
            } catch (e) {}
        }
        if (window.apiToast) window.apiToast('Sesión de frecuencia completada', 'success');
    }

    stopOscillator(opts = {}) {
        if (this.oscillator) {
            try {
                this.gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + FADE_OUT_S);
            } catch (e) {}
            setTimeout(() => { try { this.oscillator.stop(); } catch (e) {} }, FADE_OUT_S * 1000 + 60);
            this.oscillator = null;
        }
        if (!opts.keepCtx && this.audioCtx) {
            // Keep the AudioContext alive across restarts so the next click
            // starts immediately without paying the create-then-resume cost.
        }
    }

    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    requestStop() {
        this.onRequestStop();
    }

    renderElapsed() {
        if (!this.hasElapsedLabelTarget || !this.session) return;
        const h = Math.floor(this.secondsElapsed / 3600);
        const m = Math.floor((this.secondsElapsed % 3600) / 60);
        const s = this.secondsElapsed % 60;
        const pad = (n) => String(n).padStart(2, '0');
        this.elapsedLabelTarget.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
    }

    fmtFreq(s) {
        const f = s?.frequency || {};
        const hz = f.hz ?? '?';
        const name = f.name || '';
        return name ? `${hz} Hz · ${name}` : `${hz} Hz`;
    }
}
