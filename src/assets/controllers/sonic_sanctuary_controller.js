import { Controller } from '@hotwired/stimulus';

/**
 * sonic-sanctuary (modo solo-global).
 *
 * Reproductor cross-page de una única fuente:
 *   - 'global' → HTMLAudioElement streaming from /api/music/stream?id={id}
 *
 * Historia: antes tenía 3 tabs (templo/mine/global) atados al módulo
 * Frecuencias (API eliminada el 2026-09-29)
 * y el player se redujo a la playlist admin. Esta es la base para la
 * futura sección Meditación (nueva playlist/categoría sobre MusicController).
 *
 * Mounts inside <div id="sanctum-floats"> in shell.html.twig, which is
 * marked data-turbo-permanent by the shell controller, so AudioContext
 * survives every Turbo navigation.
 *
 * State persistence:
 *   - Volume + loop persist in localStorage.
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
    volume: 75, // 0..100
    muted: false,
    loop: 'all', // 'off' | 'one' | 'all'
};

export default class extends Controller {
    static targets = [
        'miniPlayer',
        'miniLauncher',
        'miniTitle',
        'miniElapsed',
        'miniDuration',
        'miniPlayBtn',
        'miniIcon',
        'panel',
        'panelClose',
        'globalTab',
        'globalList',
        'visualizer',
        'visualizerWrap',
        'volume',
        'volumeIcon',
        'muteBtn',
        'loopBtn',
        'loading',
        'toast',
        'keyboardHints',
    ];

    static values = {
        openPanel: { type: Boolean, default: false },
        source: { type: String, default: 'idle' }, // idle|global
        trackId: { type: String, default: '' },
        trackName: { type: String, default: '' },
    };

    // ─── lifecycle ─────────────────────────────────────────────────

    connect() {
        // Audio
        this.audioCtx = null;
        this.analyser = null;
        this.mediaSource = null;
        this.audioElement = null; // HTMLAudioElement (global source)
        this.secondsElapsed = 0;
        this.timerInterval = null;
        this.rafId = null;

        // State
        const stored = this.loadState();
        this.volume = stored.volume;
        this.muted = stored.muted;
        this.loop = stored.loop;
        this.activeTab = 'global';
        this.activeSource = 'idle';
        this.activeTrackId = '';
        this.activeTrackName = '';

        // Event bindings
        this.boundOnKeyDown = this.onKeyDown.bind(this);
        this.boundOnGlobalPlay = this.onGlobalPlay.bind(this);
        this.boundOnGlobalPrev = this.onGlobalPrev.bind(this);
        this.boundOnGlobalNext = this.onGlobalNext.bind(this);
        window.addEventListener('keydown', this.boundOnKeyDown);
        window.addEventListener('sonic:global-play', this.boundOnGlobalPlay);
        window.addEventListener('sonic:global-prev', this.boundOnGlobalPrev);
        window.addEventListener('sonic:global-next', this.boundOnGlobalNext);

        // First-render fetch
        this.applyVolume();
        this.applyLoopUI();
        this.renderGlobal();
    }

    disconnect() {
        window.removeEventListener('keydown', this.boundOnKeyDown);
        window.removeEventListener('sonic:global-play', this.boundOnGlobalPlay);
        window.removeEventListener('sonic:global-prev', this.boundOnGlobalPrev);
        window.removeEventListener('sonic:global-next', this.boundOnGlobalNext);

        this.stopTimer();
        this.stopVisualizer();
        // B4: clear body scroll-lock class so logout/transition doesn't leave
        // the page frozen if the panel was open.
        document.body.classList.remove('sonic-panel-open');
    }

    // ─── tab switching (single tab, kept for template compat) ──────

    selectTab() {
        this.activeTab = 'global';
        this.saveState();
        this.renderGlobal();
    }

    // ─── list rendering ────────────────────────────────────────────

    async refreshLists() {
        await this.renderGlobal();
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
            this.globalListTarget.innerHTML =
                '<p class="sonic-empty">El Cónclave no está transmitiendo ahora.</p>';
            return;
        }
        const activeIdx = r.data.activeIndex ?? 0;
        this.globalListTarget.innerHTML = tracks
            .map((t, i) => {
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
            })
            .join('');
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
     */
    async onGlobalPlay() {
        this.openPanelValue = true;

        await this.renderGlobal();

        const r = await window.apiFetch('/api/music/current', { silent: true });
        if (!r.ok || !r.data || !r.data.hasMusic || !r.data.playlist?.length) {
            if (window.apiToast)
                window.apiToast('El Cónclave no está transmitiendo ahora.', 'warning');
            return;
        }
        const idx = r.data.activeIndex ?? 0;
        const tracks = r.data.playlist;
        const target = tracks[idx] || tracks[0];
        if (!target) return;

        const fakeBtn = document.createElement('button');
        fakeBtn.dataset.trackId = target.id;
        fakeBtn.dataset.trackName = target.name;
        this.playGlobal({ currentTarget: fakeBtn });
    }

    onGlobalPrev() {
        this.onGlobalPlay()
            .then(() => this.prevTrack())
            .catch(() => {});
    }

    onGlobalNext() {
        this.onGlobalPlay()
            .then(() => this.nextTrack())
            .catch(() => {});
    }

    // ─── playback: common ────────────────────────────────────────

    ensureAudioCtx() {
        if (this.audioCtx) return;
        this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        this.analyser = this.audioCtx.createAnalyser();
        this.analyser.fftSize = 256;
        this.analyser.smoothingTimeConstant = 0.7;
        // B1: connect analyser → destination ONCE here.
        this.analyser.connect(this.audioCtx.destination);
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
            } catch (e) {
                // MediaElementSource can fail if the element was already connected.
            }
        }
    }

    gainValue() {
        if (this.muted) return 0;
        return (this.volume / 100) * 0.4; // master cap at 0.4 to avoid clipping
    }

    isPlaying() {
        if (this.activeSource === 'global') {
            return !!(this.audioElement && !this.audioElement.paused);
        }
        return false;
    }

    stopCurrent() {
        if (this.audioElement) {
            try {
                this.audioElement.pause();
                this.audioElement.currentTime = 0;
            } catch (e) {}
        }
        this.stopTimer();
        this.stopVisualizer();
        this.activeSource = 'idle';
        this.activeTrackId = '';
        this.activeTrackName = '';
        this.secondsElapsed = 0;
        this.updateMiniUI();
        this.refreshLists();
    }

    togglePlay() {
        if (this.activeSource === 'idle') {
            this.refreshLists().then(() => {
                const first = this.element.querySelector(
                    '[data-action="click->sonic-sanctuary#playGlobal"]'
                );
                if (first) first.click();
            });
            return;
        }
        if (this.isPlaying()) {
            if (this.audioElement) this.audioElement.pause();
            this.stopTimer();
            this.stopVisualizer();
            this.updateMiniUI();
        } else if (this.audioElement) {
            this.audioElement.play();
            this.startVisualizer();
            this.updateMiniUI();
        }
    }

    nextTrack() {
        const current = this.element.querySelector('.sonic-track.is-current');
        if (!current) return;
        const all = Array.from(this.element.querySelectorAll('.sonic-track')).filter((b) =>
            this.element.contains(b)
        );
        const idx = all.indexOf(current);
        let next = all[idx + 1];
        if (!next && this.loop === 'all') next = all[0];
        if (next) next.click();
    }

    prevTrack() {
        const current = this.element.querySelector('.sonic-track.is-current');
        if (!current) return;
        const all = Array.from(this.element.querySelectorAll('.sonic-track')).filter((b) =>
            this.element.contains(b)
        );
        const idx = all.indexOf(current);
        let prev = all[idx - 1];
        if (!prev && this.loop === 'all') prev = all[all.length - 1];
        if (prev) prev.click();
    }

    // ─── panel toggle ─────────────────────────────────────────────

    togglePanel() {
        this.openPanelValue = !this.openPanelValue;
    }

    openPanelValueChanged() {
        document.body.classList.toggle('sonic-panel-open', this.openPanelValue);
        if (this.hasPanelTarget) this.panelTarget.classList.toggle('is-open', this.openPanelValue);
        if (this.openPanelValue && !this.visualizerRunning) {
            this.startIdleVisualizer();
        } else if (!this.openPanelValue) {
            this.stopVisualizer();
        }
    }

    closePanel() {
        this.openPanelValue = false;
    }

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
            this.muteBtnTarget.querySelector('.material-symbols-elev').textContent = this.muted
                ? 'volume_off'
                : this.volume === 0
                  ? 'volume_mute'
                  : 'volume_up';
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
        if (!this.hasVisualizerTarget || !this.audioCtx) return;
        if (!this.analyser) {
            this.analyser = this.audioCtx.createAnalyser();
            this.analyser.fftSize = 256;
        }
        this.visualizerRunning = true;
        const canvas = this.visualizerTarget;
        const ctx = canvas.getContext('2d');
        const buf = new Uint8Array(this.analyser.frequencyBinCount);

        const dpr = Math.max(1, window.devicePixelRatio || 1);
        const cssW = canvas.clientWidth || 360;
        const cssH = canvas.clientHeight || 64;
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
        ctx.scale(dpr, dpr);
        const W = cssW;
        const H = cssH;

        let phase = 0;
        const draw = () => {
            if (!this.visualizerRunning) return;
            this.rafId = requestAnimationFrame(draw);
            this.analyser.getByteFrequencyData(buf);
            ctx.clearRect(0, 0, W, H);
            if (!this.isPlaying() || this.activeSource === 'idle') {
                phase += 0.04;
                ctx.strokeStyle = 'rgba(212, 175, 55, 0.45)';
                ctx.lineWidth = 2;
                ctx.beginPath();
                for (let x = 0; x < W; x += 2) {
                    const y = H / 2 + Math.sin(x * 0.04 + phase) * (H * 0.18);
                    if (x === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                }
                ctx.stroke();
                return;
            }
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
        if (this.hasMiniLauncherTarget) {
            this.miniLauncherTarget.hidden = show;
        }
        if (!show) return;
        if (this.hasMiniTitleTarget) {
            this.miniTitleTarget.textContent = this.activeTrackName || '—';
        }
        if (this.hasMiniElapsedTarget) {
            this.miniElapsedTarget.textContent = this.fmtTime(this.secondsElapsed);
        }
        if (this.hasMiniDurationTarget) {
            this.miniDurationTarget.textContent =
                this.audioElement && !isNaN(this.audioElement.duration)
                    ? this.fmtTime(this.audioElement.duration)
                    : '—';
        }
        if (this.hasMiniPlayBtnTarget) {
            const icon = this.miniPlayBtnTarget.querySelector('.material-symbols-elev');
            if (icon) icon.textContent = this.isPlaying() ? 'pause' : 'play_arrow';
        }
    }

    refreshCurrentTrackInLists() {
        this.refreshLists();
    }

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
            localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify({
                    volume: this.volume,
                    muted: this.muted,
                    loop: this.loop,
                })
            );
        } catch (e) {}
    }

    // ─── helpers ──────────────────────────────────────────────────

    isCurrent(source, id) {
        return this.activeSource === source && this.activeTrackId === id;
    }

    esc(s) {
        return String(s == null ? '' : s).replace(
            /[&<>"']/g,
            (m) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[m]
        );
    }

    fmtTime(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        const pad = (n) => String(n).padStart(2, '0');
        return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
    }
}
