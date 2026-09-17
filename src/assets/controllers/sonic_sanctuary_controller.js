import { Controller } from '@hotwired/stimulus';

/**
 * sonic-sanctuary
 *
 * Unified cross-page audio player that replaces the previous
 * frequency_mini_player. Three sources, one player:
 *
 *   - 'templo'  → Web Audio OscillatorNode (Hz + Web Audio sine)
 *   - 'mine'    → HTMLAudioElement streaming from /api/frequencies/stream/{id}
 *   - 'global'  → HTMLAudioElement streaming from /api/music/stream?id={id}
 *
 * Plays nicely with the existing frequency hub at /frequencies:
 *   - Listens for `tnsvt:freq:start` / `:stop` / `:request_stop` events the
 *     hub dispatches when a Templo preset is started there. The hub also
 *     calls /api/frequencies/session/{id}/end when stopping.
 *   - The 'mine' and 'global' sources do NOT touch the frequency session
 *     backend — they're free-form playback (no minutes-tracked).
 *
 * Mounts inside <div id="sanctum-floats"> in shell.html.twig, which is
 * marked data-turbo-permanent by the shell controller, so AudioContext
 * survives every Turbo navigation.
 *
 * State persistence:
 *   - Volume + loop + active tab persist in localStorage so the user's
 *     preferences are remembered across sessions.
 *
 * Keyboard shortcuts (when not focused on an input):
 *   - Space     play/pause toggle
 *   - M         mute toggle
 *   - ←         prev track
 *   - →         next track
 *   - E         toggle side panel
 */
const STORAGE_KEY = 'tnsvt_sanctuary_v1';

const DEFAULT_STATE = {
    volume: 75,           // 0..100
    muted: false,
    loop: 'all',          // 'off' | 'one' | 'all'
    activeTab: 'templo',  // 'templo' | 'mine' | 'global'
};

const FADE_IN_S  = 0.6;
const FADE_OUT_S = 0.4;

export default class extends Controller {
    static targets = [
        'miniPlayer', 'miniTitle', 'miniElapsed', 'miniDuration',
        'miniPlayBtn', 'miniIcon',
        'panel', 'panelClose',
        'temploTab', 'mineTab', 'globalTab',
        'temploList', 'mineList', 'globalList',
        'visualizer', 'visualizerWrap',
        'volume', 'volumeIcon', 'muteBtn',
        'loopBtn',
        'fileInput', 'dropOverlay',
        'loading',
        'toast',
        'keyboardHints',
    ];

    static values = {
        openPanel: { type: Boolean, default: false },
        source:    { type: String, default: 'idle' }, // idle|templo|mine|global
        trackId:   { type: String, default: '' },
        trackName: { type: String, default: '' },
    };

    // ─── lifecycle ─────────────────────────────────────────────────

    connect() {
        // Audio
        this.audioCtx = null;
        this.oscillator = null;
        this.gainNode = null;
        this.analyser = null;
        this.mediaSource = null;
        this.audioElement = null;      // HTMLAudioElement (for mine/global)
        this.sessionId = null;
        this.secondsElapsed = 0;
        this.timerInterval = null;
        this.rafId = null;
        this.queuedSource = null;       // pending fetch when user clicks too fast

        // State
        const stored = this.loadState();
        this.volume = stored.volume;
        this.muted = stored.muted;
        this.loop = stored.loop;
        this.activeTab = stored.activeTab;

        // Event bindings (compat with hub + cross-controller)
        this.boundOnStart       = this.onHubStart.bind(this);
        this.boundOnStop        = this.onHubStop.bind(this);
        this.boundOnRequestStop = this.onHubRequestStop.bind(this);
        this.boundOnKeyDown     = this.onKeyDown.bind(this);
        this.boundOnDragEnter   = this.onDragEnter.bind(this);
        this.boundOnDragOver    = this.onDragOver.bind(this);
        this.boundOnDragLeave   = this.onDragLeave.bind(this);
        this.boundOnDrop        = this.onDrop.bind(this);
        this.boundOnGlobalPlay  = this.onGlobalPlay.bind(this);
        this.boundOnGlobalPrev  = this.onGlobalPrev.bind(this);
        this.boundOnGlobalNext  = this.onGlobalNext.bind(this);
        window.addEventListener('tnsvt:freq:start',       this.boundOnStart);
        window.addEventListener('tnsvt:freq:stop',        this.boundOnStop);
        window.addEventListener('tnsvt:freq:request_stop', this.boundOnRequestStop);
        window.addEventListener('keydown',                 this.boundOnKeyDown);
        window.addEventListener('dragenter',               this.boundOnDragEnter);
        window.addEventListener('dragover',                this.boundOnDragOver);
        window.addEventListener('dragleave',               this.boundOnDragLeave);
        window.addEventListener('drop',                    this.boundOnDrop);
        window.addEventListener('sonic:global-play',      this.boundOnGlobalPlay);
        window.addEventListener('sonic:global-prev',      this.boundOnGlobalPrev);
        window.addEventListener('sonic:global-next',      this.boundOnGlobalNext);

        // First-render fetches
        this.applyVolume();
        this.applyLoopUI();
        this.applyActiveTab();
        this.refreshLists();
        this.reconcileSession();
    }

    disconnect() {
        window.removeEventListener('tnsvt:freq:start',       this.boundOnStart);
        window.removeEventListener('tnsvt:freq:stop',        this.boundOnStop);
        window.removeEventListener('tnsvt:freq:request_stop', this.boundOnRequestStop);
        window.removeEventListener('keydown',                 this.boundOnKeyDown);
        window.removeEventListener('dragenter',               this.boundOnDragEnter);
        window.removeEventListener('dragover',                this.boundOnDragOver);
        window.removeEventListener('dragleave',               this.boundOnDragLeave);
        window.removeEventListener('drop',                    this.boundOnDrop);
        window.removeEventListener('sonic:global-play',      this.boundOnGlobalPlay);
        window.removeEventListener('sonic:global-prev',      this.boundOnGlobalPrev);
        window.removeEventListener('sonic:global-next',      this.boundOnGlobalNext);

        this.stopTimer();
        this.stopVisualizer();
        // Don't kill AudioContext here — the floats div is turbo-permanent
        // and disconnect happens during heavy page transitions; we only
        // really stop on explicit user request.
    }

    // ─── tab switching ─────────────────────────────────────────────

    selectTab(event) {
        const tab = event.currentTarget.dataset.tab;
        if (!tab) return;
        this.activeTab = tab;
        this.saveState();
        this.applyActiveTab();
        this.refreshLists();
    }

    applyActiveTab() {
        ['templo', 'mine', 'global'].forEach((t) => {
            const btn = this[`${t}TabTarget`];
            if (!btn) return;
            const active = t === this.activeTab;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        // Show only the active list (other lists stay in DOM for caching)
        if (this.hasTemploListTarget) this.temploListTarget.hidden = this.activeTab !== 'templo';
        if (this.hasMineListTarget)   this.mineListTarget.hidden   = this.activeTab !== 'mine';
        if (this.hasGlobalListTarget) this.globalListTarget.hidden = this.activeTab !== 'global';
    }

    cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    // ─── list rendering ────────────────────────────────────────────

    async refreshLists() {
        await Promise.all([
            this.renderTemplo(),
            this.renderMine(),
            this.renderGlobal(),
        ]);
    }

    async renderTemplo() {
        if (!this.hasTemploListTarget) return;
        const r = await window.apiFetch('/api/frequencies/presets', { silent: true });
        if (!r.ok || !r.data || !r.data.success) {
            this.temploListTarget.innerHTML = '<p class="sonic-empty">Sin frecuencias del Templo.</p>';
            return;
        }
        const presets = r.data.presets || [];
        if (presets.length === 0) {
            this.temploListTarget.innerHTML = '<p class="sonic-empty">Sin frecuencias del Templo.</p>';
            return;
        }
        this.temploListTarget.innerHTML = presets.map((p) => `
            <button type="button"
                    class="sonic-track ${this.isCurrent('templo', String(p.id)) ? 'is-current' : ''}"
                    data-track-id="${p.id}"
                    data-track-name="${this.esc(p.name)}"
                    data-track-freq="${p.frequency}"
                    data-action="click->sonic-sanctuary#playTemplo">
                <span class="sonic-track-icon">${this.fmtHz(p.frequency)}</span>
                <span class="sonic-track-body">
                    <span class="sonic-track-name">${this.esc(p.name)}</span>
                    <span class="sonic-track-sub">${this.esc(p.category || 'Solfeggio')}</span>
                </span>
                <span class="sonic-track-action material-symbols-elev">play_arrow</span>
            </button>
        `).join('');
    }

    async renderMine() {
        if (!this.hasMineListTarget) return;
        const r = await window.apiFetch('/api/frequencies/mine', { silent: true });
        if (!r.ok || !r.data || !r.data.success) {
            this.mineListTarget.innerHTML = '<p class="sonic-empty">No autenticado.</p>';
            return;
        }
        const list = (r.data.frequencies || []).filter((f) => f.hasFile || f.type === 'custom_upload');
        if (list.length === 0) {
            this.mineListTarget.innerHTML = `
                <div class="sonic-empty sonic-empty-cta">
                    <p>Tu Santuario está vacío.</p>
                    <p class="text-xs">Arrastrá un .mp3/.wav/.ogg a este panel para empezar.</p>
                </div>`;
            return;
        }
        this.mineListTarget.innerHTML = list.map((f) => {
            const isCurrent = this.isCurrent('mine', String(f.id));
            return `
            <div class="sonic-track-row ${isCurrent ? 'is-current' : ''}">
                <button type="button"
                        class="sonic-track sonic-track-flex"
                        data-track-id="${f.id}"
                        data-track-name="${this.esc(f.name)}"
                        data-track-stream="${this.esc(f.streamUrl || '')}"
                        data-action="click->sonic-sanctuary#playMine">
                    <span class="sonic-track-icon">${this.fmtHz(f.frequency)}</span>
                    <span class="sonic-track-body">
                        <span class="sonic-track-name">${this.esc(f.name)}</span>
                        <span class="sonic-track-sub">${this.fmtType(f.type)} · ${f.frequency}Hz</span>
                    </span>
                    <span class="sonic-track-action material-symbols-elev">${isCurrent ? 'equalizer' : 'play_arrow'}</span>
                </button>
                <button type="button"
                        class="sonic-track-delete"
                        title="Borrar ${this.esc(f.name)}"
                        data-action="click->sonic-sanctuary#deleteMine"
                        data-delete-id="${f.id}">
                    <span class="material-symbols-elev">delete</span>
                </button>
            </div>`;
        }).join('');
    }

    async renderGlobal() {
        if (!this.hasGlobalListTarget) return;
        const r = await window.apiFetch('/api/music/current', { silent: true });
        if (!r.ok || !r.data) {
            this.globalListTarget.innerHTML = '<p class="sonic-empty">Global vacío.</p>';
            return;
        }
        const tracks = r.data.playlist || [];
        if (!r.data.hasMusic || tracks.length === 0) {
            this.globalListTarget.innerHTML = '<p class="sonic-empty">El Cónclave no está transmitiendo ahora.</p>';
            return;
        }
        const activeIdx = r.data.activeIndex ?? 0;
        this.globalListTarget.innerHTML = tracks.map((t, i) => {
            const isCurrent = this.isCurrent('global', t.id);
            const isActive = i === activeIdx;
            return `
            <button type="button"
                    class="sonic-track ${isCurrent ? 'is-current' : ''} ${isActive ? 'is-active-admin' : ''}"
                    data-track-id="${this.esc(t.id)}"
                    data-track-name="${this.esc(t.name)}"
                    data-action="click->sonic-sanctuary#playGlobal">
                <span class="sonic-track-icon">${isActive ? '📡' : '🎵'}</span>
                <span class="sonic-track-body">
                    <span class="sonic-track-name">${this.esc(t.name)}</span>
                    <span class="sonic-track-sub">${t.source === 'external' ? 'URL externa' : 'Archivo'} ${isActive ? '· sonando' : ''}</span>
                </span>
                <span class="sonic-track-action material-symbols-elev">${isCurrent ? 'equalizer' : 'play_arrow'}</span>
            </button>`;
        }).join('');
    }

    // ─── playback: templo (oscillator) ─────────────────────────────

    async playTemplo(event) {
        const btn = event.currentTarget;
        const id = btn.dataset.trackId;
        const name = btn.dataset.trackName;
        const freq = parseFloat(btn.dataset.trackFreq);

        // Start a backend session so stats + minutes tracking still works.
        const r = await window.apiFetch('/api/frequencies/session/start', {
            method: 'POST',
            body: { duration_minutes: 30, preset_id: parseInt(id, 10) },
        });
        if (!r.ok || !r.data?.success) {
            if (window.apiToast) window.apiToast('Error: ' + (r.data?.error || 'no se pudo iniciar'), 'error');
            return;
        }

        this.sessionId = r.data.id;
        this.stopCurrent();
        this.activeSource = 'templo';
        this.activeTrackId = String(id);
        this.activeTrackName = name;
        this.activeFrequency = freq;

        try {
            this.ensureAudioCtx();
            if (this.audioCtx.state === 'suspended') await this.audioCtx.resume();

            this.oscillator = this.audioCtx.createOscillator();
            this.gainNode = this.audioCtx.createGain();
            this.oscillator.frequency.value = freq;
            this.oscillator.type = 'sine';
            this.gainNode.gain.value = 0;
            this.oscillator.connect(this.gainNode);
            this.gainNode.connect(this.analyser);
            this.analyser.connect(this.audioCtx.destination);
            this.oscillator.start();
            this.gainNode.gain.linearRampToValueAtTime(this.gainValue(), this.audioCtx.currentTime + FADE_IN_S);

            this.startTimer(30);
            this.startVisualizer();
            this.updateMiniUI();
            this.refreshLists();
        } catch (e) {
            if (window.apiToast) window.apiToast('Audio error: ' + e.message, 'error');
        }
    }

    // ─── playback: mine (HTMLAudio streaming) ─────────────────────

    playMine(event) {
        const btn = event.currentTarget;
        const id = btn.dataset.trackId;
        const name = btn.dataset.trackName;
        const streamUrl = btn.dataset.trackStream;
        if (!streamUrl) {
            if (window.apiToast) window.apiToast('Este track no tiene stream', 'warning');
            return;
        }

        // Toggle off if clicking the current track
        if (this.activeSource === 'mine' && this.activeTrackId === id && this.isPlaying()) {
            this.stopCurrent();
            return;
        }

        this.stopCurrent();
        this.activeSource = 'mine';
        this.activeTrackId = id;
        this.activeTrackName = name;
        this.activeFrequency = null;

        this.ensureAudioCtx();
        this.ensureHtmlAudio(streamUrl);
        this.audioElement.play().catch((e) => {
            if (window.apiToast) window.apiToast('Reproducción bloqueada: ' + e.message, 'warning');
        });
        this.startTimer(0); // 0 = infinite (no backend session)
        this.startVisualizer();
        this.updateMiniUI();
        this.refreshLists();
    }

    ensureHtmlAudio(src) {
        if (!this.audioElement) {
            this.audioElement = new Audio();
            this.audioElement.crossOrigin = 'anonymous';
            this.audioElement.preload = 'auto';
            this.audioElement.addEventListener('ended', () => this.onAudioEnded());
            this.audioElement.addEventListener('timeupdate', () => this.onAudioTimeUpdate());
            this.audioElement.addEventListener('error', () => {
                if (window.apiToast) window.apiToast('No se pudo cargar el audio', 'error');
                this.stopCurrent();
            });
        }
        if (this.audioElement.src !== new URL(src, window.location.href).href) {
            this.audioElement.src = src;
        }
        this.audioElement.volume = this.gainValue();
        this.audioElement.loop = this.loop === 'one';

        // Wire into analyser for visualizer
        if (!this.mediaSource) {
            try {
                this.mediaSource = this.audioCtx.createMediaElementSource(this.audioElement);
                this.mediaSource.connect(this.analyser);
                this.analyser.connect(this.audioCtx.destination);
            } catch (e) {
                // MediaElementSource can fail if the element was already connected.
                // Safe to ignore; visualizer will just not draw for this source.
            }
        }
    }

    // ─── playback: global (admin playlist) ────────────────────────

    playGlobal(event) {
        const btn = event.currentTarget;
        const id = btn.dataset.trackId;
        const name = btn.dataset.trackName;

        if (this.activeSource === 'global' && this.activeTrackId === id && this.isPlaying()) {
            this.stopCurrent();
            return;
        }

        this.stopCurrent();
        this.activeSource = 'global';
        this.activeTrackId = id;
        this.activeTrackName = name;
        this.activeFrequency = null;

        const src = '/api/music/stream?id=' + encodeURIComponent(id);
        this.ensureAudioCtx();
        this.ensureHtmlAudio(src);
        this.audioElement.play().catch((e) => {
            if (window.apiToast) window.apiToast('Reproducción bloqueada: ' + e.message, 'warning');
        });
        this.startTimer(0);
        this.startVisualizer();
        this.updateMiniUI();
        this.refreshLists();
    }

    /**
     * Fired by the "Global Temple Broadcast" widget in /sanctum/users
     * (and any other page that wants the global admin playlist).
     * Opens the side panel, switches to the 'global' tab, and plays the
     * admin's currently-active track.
     */
    async onGlobalPlay() {
        this.openPanelValue = true;
        this.activeTab = 'global';
        this.saveState();
        this.applyActiveTab();

        // Wait one frame so the global list is rendered (or refetched)
        // before we click the active track.
        await this.renderGlobal();

        const r = await window.apiFetch('/api/music/current', { silent: true });
        if (!r.ok || !r.data || !r.data.hasMusic || !r.data.playlist?.length) {
            if (window.apiToast) window.apiToast('El Cónclave no está transmitiendo ahora.', 'warning');
            return;
        }
        const idx = r.data.activeIndex ?? 0;
        const tracks = r.data.playlist;
        const target = tracks[idx] || tracks[0];
        if (!target) return;

        // Synthesize a "click" event so we go through the normal playGlobal
        // path (DOM lookup + isPlaying toggle).
        const fakeBtn = document.createElement('button');
        fakeBtn.dataset.trackId = target.id;
        fakeBtn.dataset.trackName = target.name;
        this.playGlobal({ currentTarget: fakeBtn });
    }

    onGlobalPrev() {
        this.onGlobalPlay().then(() => this.prevTrack()).catch(() => {});
    }

    onGlobalNext() {
        this.onGlobalPlay().then(() => this.nextTrack()).catch(() => {});
    }

    // ─── playback: hub (compat) ──────────────────────────────────

    async reconcileSession() {
        try {
            const r = await window.apiFetch('/api/frequencies/session/active', { silent: true });
            if (!r.ok || !r.data || !r.data.session) return;
            const remaining = r.data.session.remainingSeconds;
            if (remaining !== null && remaining <= 0) return;
            // Don't auto-resume; show a tiny toast hint. The user opens the
            // panel and clicks "Resume" or "Abandon" (mock for now).
            // The hub already shows its own resume modal — this is just a hint.
            this.activeSource = 'templo';
            this.activeTrackId = String(r.data.session.preset?.id ?? '');
            this.activeTrackName = `${r.data.session.frequency?.hz ?? '?'} Hz · ${r.data.session.frequency?.name ?? ''}`;
            if (window.apiToast) {
                window.apiToast(`Tenés sesión activa: ${this.activeTrackName}. Click ▶ para reanudar.`, 'info');
            }
            this.updateMiniUI();
        } catch (e) {}
    }

    async resumeActiveSession() {
        try {
            const r = await window.apiFetch('/api/frequencies/session/active', { silent: true });
            if (!r.ok || !r.data || !r.data.session) return;
            const s = r.data.session;
            this.activeSource = 'templo';
            this.activeTrackId = String(s.preset?.id ?? '');
            this.activeTrackName = `${s.frequency?.hz ?? '?'} Hz · ${s.frequency?.name ?? ''}`;
            this.activeFrequency = s.frequency?.hz ?? 432;
            await this.playTemplo({ currentTarget: {
                dataset: {
                    trackId: String(s.preset?.id ?? ''),
                    trackName: s.frequency?.name ?? '',
                    trackFreq: String(s.frequency?.hz ?? 432),
                },
            } });
        } catch (e) {
            if (window.apiToast) window.apiToast('Error al reanudar', 'error');
        }
    }

    async abandonActiveSession() {
        if (!this.sessionId) return;
        try {
            await window.apiFetch(`/api/frequencies/session/${this.sessionId}/abandon`, {
                method: 'DELETE', silent: true,
            });
        } catch (e) {}
        this.sessionId = null;
        this.stopCurrent();
    }

    onHubStart(event) {
        // The hub's frequency_player dispatches this when user clicks "Iniciar".
        // We intercept and route through playTemplo so the visualizer / panel
        // both stay in sync. The hub will not create its own AudioContext if
        // we appear "mounted" (see frequency_player_controller.js update).
        const d = event.detail || {};
        if (!d.frequency) return;
        // Defer to next tick so we don't race with our own session start.
        setTimeout(() => {
            if (this.activeSource === 'templo' && this.activeTrackId === String(d.presetId ?? '')) return;
            this.activeSource = 'templo';
            this.activeTrackId = d.presetId ? String(d.presetId) : '';
            this.activeTrackName = d.name || (d.frequency + ' Hz');
            this.activeFrequency = d.frequency;
            this.ensureAudioCtx();
            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }
            this.stopCurrent();
            this.oscillator = this.audioCtx.createOscillator();
            this.gainNode = this.audioCtx.createGain();
            this.oscillator.frequency.value = d.frequency;
            this.oscillator.type = 'sine';
            this.gainNode.gain.value = 0;
            this.oscillator.connect(this.gainNode);
            this.gainNode.connect(this.analyser);
            this.analyser.connect(this.audioCtx.destination);
            this.oscillator.start();
            this.gainNode.gain.linearRampToValueAtTime(this.gainValue(), this.audioCtx.currentTime + FADE_IN_S);
            this.sessionId = d.sessionId || null;
            this.startTimer(d.durationMinutes || 30);
            this.startVisualizer();
            this.updateMiniUI();
        }, 0);
    }

    onHubStop() {
        // The hub told us to stop. Don't tear down session tracking — the hub
        // will POST to session/{id}/end on its own.
        if (this.activeSource === 'templo') {
            this.stopCurrent({ keepSession: true });
        }
    }

    onHubRequestStop() {
        // User clicked Stop on the hub. Same as onHubStop.
        if (this.activeSource === 'templo') {
            this.stopCurrent({ keepSession: true });
        }
    }

    // ─── playback: common ────────────────────────────────────────

    ensureAudioCtx() {
        if (this.audioCtx) return;
        this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        this.analyser = this.audioCtx.createAnalyser();
        this.analyser.fftSize = 256;
        this.analyser.smoothingTimeConstant = 0.7;
    }

    gainValue() {
        if (this.muted) return 0;
        return (this.volume / 100) * 0.4; // master cap at 0.4 to avoid clipping
    }

    isPlaying() {
        if (this.activeSource === 'templo') return !!this.oscillator;
        if (this.activeSource === 'mine' || this.activeSource === 'global') {
            return !!(this.audioElement && !this.audioElement.paused);
        }
        return false;
    }

    stopCurrent(opts = {}) {
        if (this.activeSource === 'templo' && this.oscillator) {
            try {
                this.gainNode.gain.cancelScheduledValues(this.audioCtx.currentTime);
                this.gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + FADE_OUT_S);
            } catch (e) {}
            setTimeout(() => {
                try { this.oscillator.stop(); } catch (e) {}
            }, FADE_OUT_S * 1000 + 60);
            this.oscillator = null;
            this.gainNode = null;
        }
        if ((this.activeSource === 'mine' || this.activeSource === 'global') && this.audioElement) {
            try {
                this.audioElement.pause();
                this.audioElement.currentTime = 0;
            } catch (e) {}
        }
        if (!opts.keepSession && this.sessionId) {
            const sid = this.sessionId;
            this.sessionId = null;
            window.apiFetch(`/api/frequencies/session/${sid}/end`, {
                method: 'POST', silent: true,
            }).catch(() => {});
        }
        this.stopTimer();
        this.stopVisualizer();
        this.activeSource = 'idle';
        this.activeTrackId = '';
        this.activeTrackName = '';
        this.activeFrequency = null;
        this.secondsElapsed = 0;
        this.updateMiniUI();
        this.refreshLists();
    }

    togglePlay() {
        if (this.activeSource === 'idle') {
            // No source: play first available track from active tab
            this.refreshLists().then(() => {
                const selector = this.activeTab === 'mine' ? '.sonic-track-flex'
                    : this.activeTab === 'global' ? '[data-action="click->sonic-sanctuary#playGlobal"]'
                    : '[data-action="click->sonic-sanctuary#playTemplo"]';
                const first = this.element.querySelector(selector);
                if (first) first.click();
            });
            return;
        }
        if (this.isPlaying()) {
            // Pause
            if (this.activeSource === 'templo') {
                try {
                    this.gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + 0.2);
                } catch (e) {}
                setTimeout(() => {
                    try { this.oscillator.stop(); } catch (e) {}
                    this.oscillator = null;
                }, 250);
            } else if (this.audioElement) {
                this.audioElement.pause();
            }
            this.stopTimer();
            this.stopVisualizer();
            this.updateMiniUI();
        } else {
            // Resume
            if (this.activeSource === 'templo' && this.activeFrequency) {
                this.oscillator = this.audioCtx.createOscillator();
                this.gainNode = this.audioCtx.createGain();
                this.oscillator.frequency.value = this.activeFrequency;
                this.oscillator.type = 'sine';
                this.gainNode.gain.value = 0;
                this.oscillator.connect(this.gainNode);
                this.gainNode.connect(this.analyser);
                this.analyser.connect(this.audioCtx.destination);
                this.oscillator.start();
                this.gainNode.gain.linearRampToValueAtTime(this.gainValue(), this.audioCtx.currentTime + FADE_IN_S);
                this.startTimer(30);
                this.startVisualizer();
                this.updateMiniUI();
            } else if (this.audioElement) {
                this.audioElement.play();
                this.startVisualizer();
                this.updateMiniUI();
            }
        }
    }

    nextTrack() {
        // Navigate to next track in current list (DOM order).
        const current = this.element.querySelector('.sonic-track.is-current, .sonic-track-flex.is-current');
        if (!current) return;
        const all = Array.from(this.element.querySelectorAll('.sonic-track, .sonic-track-flex'))
            .filter((b) => this.element.contains(b));
        const idx = all.indexOf(current);
        let next = all[idx + 1];
        if (!next && this.loop === 'all') next = all[0];
        if (next) next.click();
    }

    prevTrack() {
        const current = this.element.querySelector('.sonic-track.is-current, .sonic-track-flex.is-current');
        if (!current) return;
        const all = Array.from(this.element.querySelectorAll('.sonic-track, .sonic-track-flex'))
            .filter((b) => this.element.contains(b));
        const idx = all.indexOf(current);
        let prev = all[idx - 1];
        if (!prev && this.loop === 'all') prev = all[all.length - 1];
        if (prev) prev.click();
    }

    // ─── delete own upload ─────────────────────────────────────────

    async deleteMine(event) {
        const btn = event.currentTarget;
        const id = btn.dataset.deleteId;
        if (!id) return;
        if (!window.apiConfirm) {
            if (!confirm('¿Borrar esta frecuencia?')) return;
        } else {
            const ok = await window.apiConfirm('¿Borrar esta frecuencia? El archivo de audio también se eliminará.', {
                title: 'Confirmar borrado', danger: true,
            });
            if (!ok) return;
        }
        const r = await window.apiFetch(`/api/frequencies/mine/${id}`, { method: 'DELETE' });
        if (!r.ok) {
            if (window.apiToast) window.apiToast('Error al borrar', 'error');
            return;
        }
        if (window.apiToast) window.apiToast('Frecuencia borrada', 'success');
        if (this.activeSource === 'mine' && this.activeTrackId === id) {
            this.activeSource = 'idle';
        }
        await this.renderMine();
        this.applyActiveTab();
    }

    // ─── upload (button + drag-drop) ───────────────────────────────

    triggerUpload() {
        if (this.hasFileInputTarget) this.fileInputTarget.click();
    }

    pickFile(event) {
        const files = Array.from(event.target.files || []);
        if (files.length) this.uploadFiles(files);
        event.target.value = '';
    }

    onDragEnter(e) { e.preventDefault(); if (this.hasDropOverlayTarget) this.dropOverlayTarget.classList.add('is-active'); }
    onDragOver(e)  { e.preventDefault(); if (this.hasDropOverlayTarget) this.dropOverlayTarget.classList.add('is-active'); }
    onDragLeave(e) {
        // Only deactivate when leaving the window (not child elements).
        if (e.target === document || e.relatedTarget == null) {
            if (this.hasDropOverlayTarget) this.dropOverlayTarget.classList.remove('is-active');
        }
    }
    onDrop(e) {
        e.preventDefault();
        if (this.hasDropOverlayTarget) this.dropOverlayTarget.classList.remove('is-active');
        const files = Array.from(e.dataTransfer?.files || []);
        const audio = files.filter((f) => /^audio\//.test(f.type));
        if (audio.length === 0) {
            if (window.apiToast) window.apiToast('Solo archivos de audio (.mp3, .wav, .ogg)', 'warning');
            return;
        }
        this.uploadFiles(audio);
    }

    async uploadFiles(files) {
        for (const f of files) {
            if (window.apiToast) window.apiToast(`Subiendo ${f.name}…`, 'info');
            const fd = new FormData();
            fd.append('file', f);
            fd.append('name', f.name.replace(/\.[^.]+$/, ''));
            fd.append('frequency', 432);
            try {
                const r = await fetch('/api/frequencies/upload', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!r.ok) {
                    const txt = await r.text();
                    if (window.apiToast) window.apiToast('Error: ' + (txt || r.statusText), 'error');
                    continue;
                }
                if (window.apiToast) window.apiToast(`✓ ${f.name} subido`, 'success');
            } catch (e) {
                if (window.apiToast) window.apiToast('Error de red: ' + e.message, 'error');
            }
        }
        await this.renderMine();
        this.applyActiveTab();
    }

    // ─── panel toggle ─────────────────────────────────────────────

    togglePanel() {
        this.openPanelValue = !this.openPanelValue;
    }

    openPanelValueChanged() {
        document.body.classList.toggle('sonic-panel-open', this.openPanelValue);
        if (this.hasPanelTarget) this.panelTarget.classList.toggle('is-open', this.openPanelValue);
        if (this.openPanelValue && !this.visualizerRunning) {
            // Start the visualizer when the panel opens (so the canvas
            // is "alive" even when paused).
            this.startIdleVisualizer();
        } else if (!this.openPanelValue) {
            this.stopVisualizer();
        }
    }

    closePanel() { this.openPanelValue = false; }

    // ─── volume / mute / loop ────────────────────────────────────

    onVolumeInput(e) {
        this.volume = parseInt(e.target.value, 10);
        this.applyVolume();
        this.saveState();
    }

    toggleMute() {
        this.muted = !this.muted;
        this.applyVolume();
        this.saveState();
        this.updateMiniUI();
    }

    applyVolume() {
        if (this.hasVolumeTarget) this.volumeTarget.value = this.volume;
        if (this.hasMuteBtnTarget) {
            this.muteBtnTarget.querySelector('.material-symbols-elev').textContent =
                this.muted ? 'volume_off' : (this.volume === 0 ? 'volume_mute' : 'volume_up');
        }
        if (this.oscillator && this.gainNode) {
            try {
                this.gainNode.gain.linearRampToValueAtTime(this.gainValue(), this.audioCtx.currentTime + 0.1);
            } catch (e) {}
        }
        if (this.audioElement) {
            this.audioElement.volume = this.gainValue();
        }
    }

    cycleLoop() {
        this.loop = { off: 'one', one: 'all', all: 'off' }[this.loop] || 'all';
        this.applyLoopUI();
        if (this.audioElement) this.audioElement.loop = this.loop === 'one';
        this.saveState();
    }

    applyLoopUI() {
        if (!this.hasLoopBtnTarget) return;
        const labels = { off: '⤬ sin loop', one: '↻ uno', all: '↻∞ todo' };
        const title = labels[this.loop] || '↻∞ todo';
        this.loopBtnTarget.textContent = title;
        this.loopBtnTarget.dataset.loop = this.loop;
    }

    // ─── visualizer ───────────────────────────────────────────────

    startIdleVisualizer() {
        // Subtle wave on the canvas even when paused.
        if (!this.hasVisualizerTarget || !this.audioCtx) return;
        if (!this.analyser) {
            this.analyser = this.audioCtx.createAnalyser();
            this.analyser.fftSize = 256;
        }
        this.visualizerRunning = true;
        const canvas = this.visualizerTarget;
        const ctx = canvas.getContext('2d');
        const buf = new Uint8Array(this.analyser.frequencyBinCount);
        let phase = 0;
        const draw = () => {
            if (!this.visualizerRunning) return;
            this.rafId = requestAnimationFrame(draw);
            this.analyser.getByteFrequencyData(buf);
            const W = canvas.width, H = canvas.height;
            ctx.clearRect(0, 0, W, H);
            // Idle wave (subtle moving sine)
            if (!this.isPlaying() || this.activeSource === 'idle') {
                phase += 0.04;
                ctx.strokeStyle = 'rgba(212, 175, 55, 0.45)';
                ctx.lineWidth = 2;
                ctx.beginPath();
                for (let x = 0; x < W; x += 2) {
                    const y = H / 2 + Math.sin(x * 0.04 + phase) * (H * 0.18);
                    if (x === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                }
                ctx.stroke();
                return;
            }
            // Real FFT bars
            const bars = 48;
            const step = Math.floor(buf.length / bars);
            const bw = W / bars;
            for (let i = 0; i < bars; i++) {
                let sum = 0;
                for (let j = 0; j < step; j++) sum += buf[i * step + j];
                const v = sum / step / 255;
                const h = v * H * 0.9;
                const grad = ctx.createLinearGradient(0, H - h, 0, H);
                grad.addColorStop(0, 'rgba(138, 60, 255, 0.85)');
                grad.addColorStop(1, 'rgba(212, 175, 55, 0.95)');
                ctx.fillStyle = grad;
                const x = i * bw + 1;
                ctx.fillRect(x, H - h, bw - 2, h);
            }
        };
        draw();
    }

    startVisualizer() {
        if (this.visualizerRunning) return;
        if (!this.analyser) {
            this.ensureAudioCtx();
            this.analyser = this.audioCtx.createAnalyser();
            this.analyser.fftSize = 256;
            this.analyser.smoothingTimeConstant = 0.7;
        }
        if (this.oscillator) this.oscillator.connect(this.gainNode);
        this.startIdleVisualizer();
    }

    stopVisualizer() {
        this.visualizerRunning = false;
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    }

    // ─── timer ────────────────────────────────────────────────────

    startTimer(minutes) {
        this.stopTimer();
        this.secondsElapsed = 0;
        const totalSec = (minutes || 0) * 60;
        this.timerInterval = setInterval(() => {
            this.secondsElapsed += 1;
            this.updateMiniUI();
            if (totalSec > 0 && this.secondsElapsed >= totalSec) {
                this.stopCurrent();
                if (window.apiToast) window.apiToast('Sesión de frecuencia completada', 'success');
            }
        }, 1000);
    }

    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    // ─── UI sync ──────────────────────────────────────────────────

    updateMiniUI() {
        if (!this.hasMiniPlayerTarget) return;
        const show = this.activeSource !== 'idle';
        this.miniPlayerTarget.hidden = !show;
        if (!show) return;
        if (this.hasMiniTitleTarget) {
            this.miniTitleTarget.textContent = this.activeTrackName || '—';
        }
        if (this.hasMiniElapsedTarget) {
            this.miniElapsedTarget.textContent = this.fmtTime(this.secondsElapsed);
        }
        if (this.hasMiniDurationTarget) {
            this.miniDurationTarget.textContent = (this.activeSource === 'templo' && this.activeFrequency)
                ? this.fmtHz(this.activeFrequency)
                : (this.audioElement && !isNaN(this.audioElement.duration)) ? this.fmtTime(this.audioElement.duration) : '—';
        }
        if (this.hasMiniPlayBtnTarget) {
            const icon = this.miniPlayBtnTarget.querySelector('.material-symbols-elev');
            if (icon) icon.textContent = this.isPlaying() ? 'pause' : 'play_arrow';
        }
    }

    refreshCurrentTrackInLists() { this.refreshLists(); }

    // ─── keyboard shortcuts ──────────────────────────────────────

    onKeyDown(e) {
        const tag = (e.target?.tagName || '').toUpperCase();
        if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable) return;
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.key === ' ') {
            e.preventDefault();
            this.togglePlay();
        } else if (e.key.toLowerCase() === 'm') {
            this.toggleMute();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            this.prevTrack();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            this.nextTrack();
        } else if (e.key.toLowerCase() === 'e') {
            this.togglePanel();
        }
    }

    // ─── audio element events ─────────────────────────────────────

    onAudioEnded() {
        if (this.loop === 'one' && this.audioElement) {
            this.audioElement.currentTime = 0;
            this.audioElement.play();
            return;
        }
        if (this.loop === 'all') {
            this.nextTrack();
            return;
        }
        this.stopCurrent();
    }

    onAudioTimeUpdate() {
        if (this.hasMiniElapsedTarget && this.audioElement) {
            this.miniElapsedTarget.textContent = this.fmtTime(this.audioElement.currentTime);
        }
    }

    // ─── state persistence ───────────────────────────────────────

    loadState() {
        try {
            const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            return { ...DEFAULT_STATE, ...raw };
        } catch (e) {
            return { ...DEFAULT_STATE };
        }
    }

    saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                volume: this.volume,
                muted: this.muted,
                loop: this.loop,
                activeTab: this.activeTab,
            }));
        } catch (e) {}
    }

    // ─── helpers ──────────────────────────────────────────────────

    isCurrent(source, id) {
        return this.activeSource === source && this.activeTrackId === id;
    }

    fmtHz(hz) {
        if (!hz && hz !== 0) return '♪';
        return Math.round(hz) + 'Hz';
    }

    fmtType(type) {
        return ({
            custom_upload: 'Subido',
            custom_generated: 'Generado',
            preset: 'Preset',
        })[type] || type;
    }

    fmtTime(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        const pad = (n) => String(n).padStart(2, '0');
        return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
    }

    esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[m]));
    }
}
