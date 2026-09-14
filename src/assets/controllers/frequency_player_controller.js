import { Controller } from '@hotwired/stimulus';

/**
 * Frequencies hub — local player controller.
 *
 * Extracted from `templates/frequencies/hub.html.twig` inline `<script>` in
 * P10 (vertical slice commit 2). Owns the Web Audio API oscillator for the
 * duration this page is mounted. Persistence across navigation lives in
 * `frequency_mini_player_controller.js` (commit 4) and is wired here via
 * the `tnsvt:freq:start` / `tnsvt:freq:stop` CustomEvents.
 *
 * AudioContext is created lazily inside the onclick handler (browser
 * autoplay policy requires a user gesture).
 */
const RECENT_KEY = 'tnsvt_freq_recent';
const RECENT_MAX = 5;

export default class extends Controller {
    static targets = [
        'playBtn', 'stopBtn',
        'durationSelect', 'addFreqBtn',
        'freqDisplay', 'freqNameDisplay',
        'timerDisplay', 'visualizer',
        'statMinutes', 'statHours', 'statActive',
        'recentFreqs', 'presetsGrid', 'myFreqsList',
        'myFreqName', 'myFreqHz',
    ];

    static values = {
        defaultDuration: { type: Number, default: 30 },
    };

    connect() {
        // Audio state (lives only while this controller is connected)
        this.audioCtx = null;
        this.oscillator = null;
        this.gainNode = null;
        this.currentSessionId = null;
        this.timerInterval = null;
        this.secondsElapsed = 0;
        this.selectedDuration = this.defaultDurationValue;
        this.selectedFrequency = null;

        // Sync the duration control + timer chips to the default
        this.syncDurationUI();

        // First-render fetches
        this.loadPresets();
        this.loadMyFreqs();
        this.loadStats();
        this.renderRecents();

        // Wire timer chip clicks
        this.element.querySelectorAll('.timer-btn[data-time]').forEach((btn) => {
            btn.addEventListener('click', () => this.setDuration(parseInt(btn.dataset.time, 10)));
        });
    }

    disconnect() {
        this.stopTimer();
        if (!this.globalMiniPlayerMounted()) {
            this.stopAudio({ silent: true, noApi: true });
        }
    }

    // ─── UI sync ──────────────────────────────────────────────────────

    syncDurationUI() {
        if (this.hasDurationSelectTarget) {
            this.durationSelectTarget.value = String(this.selectedDuration);
        }
        this.element.querySelectorAll('.timer-btn[data-time]').forEach((b) => {
            const active = parseInt(b.dataset.time, 10) === this.selectedDuration;
            b.classList.toggle('active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    setDuration(minutes) {
        this.selectedDuration = Number.isFinite(minutes) ? minutes : this.defaultDurationValue;
        this.syncDurationUI();
    }

    // ─── Frequency selection ──────────────────────────────────────────

    selectFrequency(freq) {
        if (!freq) return;
        this.selectedFrequency = freq;
        if (this.hasFreqDisplayTarget) {
            this.freqDisplayTarget.textContent = freq.frequency + ' Hz';
        }
        if (this.hasFreqNameDisplayTarget) {
            this.freqNameDisplayTarget.textContent = freq.name || '';
        }
        // De-select every preset card visually; re-apply the selection if
        // it matches a preset or a user-frequency row.
        this.element.querySelectorAll('.preset-card').forEach((c) => {
            c.classList.remove('ring-2', 'ring-[var(--gold-elev)]');
            if (freq.type === 'preset' && parseInt(c.dataset.id, 10) === freq.id) {
                c.classList.add('ring-2', 'ring-[var(--gold-elev)]');
            }
        });
    }

    selectPreset(event) {
        const card = event.currentTarget;
        this.selectFrequency({
            id: parseInt(card.dataset.id, 10),
            frequency: parseInt(card.dataset.freq, 10),
            name: card.dataset.name,
            type: 'preset',
        });
    }

    selectUserFreq(event) {
        const btn = event.currentTarget;
        this.selectFrequency({
            id: parseInt(btn.dataset.id, 10),
            frequency: parseInt(btn.dataset.freq, 10),
            name: btn.dataset.name,
            type: 'user',
        });
    }

    selectRecent(event) {
        const btn = event.currentTarget;
        this.selectFrequency({
            id: null,
            frequency: parseInt(btn.dataset.recentFreq, 10),
            name: btn.dataset.recentName || (btn.dataset.recentFreq + ' Hz'),
            type: 'recent',
        });
    }

    // ─── Data loads ──────────────────────────────────────────────────

    async loadPresets() {
        if (!this.hasPresetsGridTarget) return;
        const r = await window.apiFetch('/api/frequencies/presets', { silent: true });
        if (!r.ok || !r.data || !r.data.success) return;
        const html = r.data.presets.map((p) => `
            <button type="button"
                class="preset-card glass-card-elev p-4 text-left hover:gold-glow-elev transition-all"
                data-freq="${p.frequency}" data-name="${this.escape(p.name)}"
                data-id="${p.id}" data-action="click->frequency-player#selectPreset">
                <div class="text-2xl font-bold text-[var(--gold-elev)] mb-1">${p.frequency}Hz</div>
                <div class="text-sm text-[var(--on-surface-elev)] mb-2 line-clamp-2">${this.escape(p.name)}</div>
                <div class="text-xs text-[var(--outline-elev)] uppercase tracking-wider">${this.escape(p.category || '')}</div>
            </button>
        `).join('');
        this.presetsGridTarget.innerHTML = html;
    }

    async loadMyFreqs() {
        if (!this.hasMyFreqsListTarget) return;
        const r = await window.apiFetch('/api/frequencies/mine', { silent: true });
        if (!r.ok || !r.data || !r.data.success) return;
        const list = r.data.frequencies || [];
        if (list.length === 0) {
            this.myFreqsListTarget.innerHTML = '<p class="text-sm text-[var(--outline-elev)] text-center py-4">Sin frecuencias personalizadas aún.</p>';
            return;
        }
        this.myFreqsListTarget.innerHTML = list.map((f) => `
            <button type="button"
                class="w-full text-left p-2 rounded glass-card-elev hover:gold-glow-elev"
                data-freq="${f.frequency}" data-name="${this.escape(f.name)}"
                data-id="${f.id}" data-action="click->frequency-player#selectUserFreq">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-[var(--on-surface-elev)]">${this.escape(f.name)}</span>
                    <span class="text-sm font-mono text-[var(--gold-elev)]">${f.frequency} Hz</span>
                </div>
            </button>
        `).join('');
    }

    async loadStats() {
        const r = await window.apiFetch('/api/frequencies/stats', { silent: true });
        if (!r.ok || !r.data || !r.data.success) return;
        if (this.hasStatMinutesTarget) this.statMinutesTarget.textContent = r.data.totalMinutes ?? 0;
        if (this.hasStatHoursTarget) this.statHoursTarget.textContent = r.data.totalHours ?? 0;
        if (this.hasStatActiveTarget) this.statActiveTarget.textContent = r.data.activeSessions ?? 0;
    }

    // ─── Add custom frequency ────────────────────────────────────────

    async addMyFreq() {
        if (!this.hasMyFreqNameTarget || !this.hasMyFreqHzTarget) return;
        const name = this.myFreqNameTarget.value.trim();
        const hz = parseInt(this.myFreqHzTarget.value, 10);
        if (!name || !hz || hz < 50 || hz > 2000) {
            if (window.apiToast) window.apiToast('Nombre y Hz (50-2000) requeridos', 'warning');
            return;
        }
        const dup = Array.from(this.myFreqsListTarget.querySelectorAll('[data-freq]'))
            .some((el) => parseInt(el.dataset.freq, 10) === hz);
        if (dup) {
            const ok = await (window.apiConfirm ? window.apiConfirm(
                `Ya tenés una frecuencia en ${hz} Hz. ¿Crear otra igual?`,
                { title: 'Frecuencia duplicada' }
            ) : Promise.resolve(true));
            if (!ok) return;
        }
        const r = await window.apiFetch('/api/frequencies/add', {
            method: 'POST',
            body: { name, frequency: hz },
        });
        if (r.ok) {
            this.myFreqNameTarget.value = '';
            this.myFreqHzTarget.value = '';
            await this.loadMyFreqs();
        } else if (window.apiToast) {
            window.apiToast('Error: ' + (r.data?.error || 'desconocido'), 'error');
        }
    }

    // ─── Recent frequencies (localStorage) ───────────────────────────

    getRecents() {
        try {
            const raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(raw) ? raw.slice(0, RECENT_MAX) : [];
        } catch (e) {
            return [];
        }
    }

    recordRecent(freq, name) {
        if (!freq) return;
        try {
            const list = this.getRecents().filter((r) => Number(r.frequency) !== Number(freq));
            list.unshift({ frequency: Number(freq), name: name || (freq + ' Hz'), at: Date.now() });
            localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
            this.renderRecents();
        } catch (e) {
            // localStorage may be disabled — silent.
        }
    }

    renderRecents() {
        if (!this.hasRecentFreqsTarget) return;
        const list = this.getRecents();
        if (list.length === 0) {
            this.recentFreqsTarget.hidden = true;
            this.recentFreqsTarget.innerHTML = '';
            return;
        }
        this.recentFreqsTarget.hidden = false;
        this.recentFreqsTarget.innerHTML =
            '<span class="text-xs uppercase tracking-widest text-[var(--outline-elev)] w-full">Recientes</span>' +
            list.map((r) => `<button type="button" class="cal-country-chip"
                data-recent-freq="${r.frequency}"
                data-recent-name="${this.escape(r.name)}"
                data-action="click->frequency-player#selectRecent">${r.frequency} Hz</button>`).join('');
    }

    // ─── Session lifecycle + Web Audio API ───────────────────────────

    async startSession() {
        if (!this.selectedFrequency) {
            if (window.apiToast) window.apiToast('Selecciona una frecuencia primero', 'warning');
            return;
        }

        const r = await window.apiFetch('/api/frequencies/session/start', {
            method: 'POST',
            body: {
                duration_minutes: this.selectedDuration,
                preset_id: this.selectedFrequency.type === 'preset' ? this.selectedFrequency.id : null,
                user_frequency_id: this.selectedFrequency.type === 'user' ? this.selectedFrequency.id : null,
            },
        });
        if (!r.ok || !r.data?.success) {
            if (window.apiToast) window.apiToast('Error: ' + (r.data?.error || 'no se pudo iniciar'), 'error');
            return;
        }
        this.currentSessionId = r.data.id;
        this.recordRecent(this.selectedFrequency.frequency, this.selectedFrequency.name);

        const globalExists = this.globalMiniPlayerMounted();
        if (!globalExists) {
            // Legacy fallback: shell didn't include the floats panel.
            // Same path as pre-F10 so the page still works without the
            // global shell.
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                this.oscillator = this.audioCtx.createOscillator();
                this.gainNode = this.audioCtx.createGain();
                this.oscillator.frequency.value = this.selectedFrequency.frequency;
                this.oscillator.type = 'sine';
                this.gainNode.gain.value = 0.05;
                this.oscillator.connect(this.gainNode);
                this.gainNode.connect(this.audioCtx.destination);
                this.oscillator.start();
                this.gainNode.gain.linearRampToValueAtTime(0.15, this.audioCtx.currentTime + 2);
            } catch (e) {
                if (window.apiToast) window.apiToast('Audio error: ' + e.message, 'error');
            }
        }

        this.secondsElapsed = 0;
        const totalSeconds = this.selectedDuration * 60;

        if (this.hasPlayBtnTarget) this.playBtnTarget.disabled = true;
        if (this.hasStopBtnTarget) this.stopBtnTarget.disabled = false;
        if (this.hasVisualizerTarget) {
            this.visualizerTarget.classList.replace('is-idle', 'is-playing');
        }

        // Local display timer (page-level) — independent of the global
        // mini-player so the user always sees the elapsed time on the hub.
        this.timerInterval = setInterval(() => {
            this.secondsElapsed += 1;
            if (totalSeconds > 0 && this.secondsElapsed >= totalSeconds) {
                this.stopSession();
                return;
            }
            this.renderTimer();
        }, 1000);
        this.renderTimer();

        // Dispatch global event — the frequency_mini_player_controller in
        // the shell owns the AudioContext + survives navigations.
        window.dispatchEvent(new CustomEvent('tnsvt:freq:start', {
            detail: {
                sessionId: this.currentSessionId,
                frequency: this.selectedFrequency.frequency,
                name: this.selectedFrequency.name,
                durationMinutes: this.selectedDuration,
                startedAt: Date.now(),
            },
        }));
    }

    async stopSession() {
        const elapsedSec = this.secondsElapsed;
        this.stopTimer();
        const globalExists = this.globalMiniPlayerMounted();
        if (!globalExists) {
            this.stopAudio();
        }

        if (this.currentSessionId) {
            const sid = this.currentSessionId;
            this.currentSessionId = null;
            try {
                await window.apiFetch(`/api/frequencies/session/${sid}/end`, {
                    method: 'POST',
                    silent: true,
                });
            } catch (e) {
                // surface only if not silent
            }
        }

        if (this.hasPlayBtnTarget) this.playBtnTarget.disabled = false;
        if (this.hasStopBtnTarget) this.stopBtnTarget.disabled = true;
        if (this.hasVisualizerTarget) {
            this.visualizerTarget.classList.replace('is-playing', 'is-idle');
        }
        if (this.hasTimerDisplayTarget) this.timerDisplayTarget.textContent = '';

        await this.loadStats();

        // Ask the global mini-player to stop too (if mounted).
        window.dispatchEvent(new CustomEvent('tnsvt:freq:stop', {
            detail: { elapsedSeconds: elapsedSec },
        }));
    }

    globalMiniPlayerMounted() {
        return typeof document !== 'undefined'
            && !!document.querySelector('[data-controller~="frequency-mini-player"]');
    }

    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    stopAudio(opts = {}) {
        if (this.oscillator) {
            try { this.gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + 0.5); } catch (e) {}
            setTimeout(() => { try { this.oscillator.stop(); } catch (e) {} }, 600);
            this.oscillator = null;
        }
        if (this.audioCtx) {
            try { this.audioCtx.close(); } catch (e) {}
            this.audioCtx = null;
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────

    renderTimer() {
        if (!this.hasTimerDisplayTarget) return;
        const h = Math.floor(this.secondsElapsed / 3600);
        const m = Math.floor((this.secondsElapsed % 3600) / 60);
        const s = this.secondsElapsed % 60;
        const pad = (n) => String(n).padStart(2, '0');
        this.timerDisplayTarget.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
    }

    escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}
