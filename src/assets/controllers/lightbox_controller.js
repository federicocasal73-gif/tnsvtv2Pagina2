import { Controller } from '@hotwired/stimulus';

/**
 * Lightbox controller — fullscreen photo viewer with swipe/zoom/keyboard.
 *
 * Auto-binds via event delegation on document:
 *   - click on any <img class="lightbox-trigger"> opens the lightbox
 *   - photos sharing data-lightbox-group="<id>" form a navigable gallery
 *
 * Hash deep-linking:
 *   - #lightbox=group:index  → opens at boot if present
 *   - popstate (browser back) → closes
 *
 * Touch:
 *   - swipe horizontally (>40px, <600ms) navigates prev/next
 *   - pinch (2-finger) zooms
 *   - single-finger drag pans when zoomed
 *
 * Keyboard (only when open):
 *   Esc     → close
 *   ← / →   → prev / next
 *   + / -   → zoom in / out
 *   0       → reset zoom + pan
 *
 * Accessibility:
 *   - focus trap inside the root while open
 *   - aria-modal="true" set on the root
 *   - prefers-reduced-motion disables scale/fade entrance
 *
 * Side-effects on body:
 *   - document.body.style.overflow = 'hidden' while open (matches apiConfirm)
 */
const MIN_SCALE = 1;
const MAX_SCALE = 6;
const WHEEL_STEP = 0.18;
const SWIPE_MIN_PX = 40;
const SWIPE_MAX_MS = 600;

export default class extends Controller {
    static targets = [
        'backdrop',
        'stage',
        'image',
        'caption',
        'counter',
    ];

    connect() {
        this._triggers = [];
        this._index = -1;
        this._scale = 1;
        this._tx = 0;     // pan x (CSS translate)
        this._ty = 0;     // pan y
        this._isClosing = false;
        this._isDragging = false;
        this._dragStart = null;
        this._touchStart = null;
        this._pinchStart = null;
        this._prevFocus = null;

        this._onDocClick = this._onDocClick.bind(this);
        this._onKey = this._onKey.bind(this);
        this._onPop = this._onPop.bind(this);

        document.addEventListener('click', this._onDocClick, true);
        window.addEventListener('keydown', this._onKey);
        window.addEventListener('popstate', this._onPop);

        this._refreshTriggers();
        // Photos added dynamically (re-rendered lists) — observe the DOM.
        this._observer = new MutationObserver(() => this._refreshTriggers());
        this._observer.observe(document.body, { childList: true, subtree: true });

        // Honor deep-link on load (#lightbox=group:index).
        if (location.hash.startsWith('#lightbox=')) {
            queueMicrotask(() => this._openFromHash());
        }
    }

    disconnect() {
        document.removeEventListener('click', this._onDocClick, true);
        window.removeEventListener('keydown', this._onKey);
        window.removeEventListener('popstate', this._onPop);
        if (this._observer) this._observer.disconnect();
        if (this._isOpen) this._restoreBody();
    }

    /* ───── public actions (data-action="lightbox#…") ───── */

    close(event) {
        if (event) event.preventDefault();
        this._close();
    }

    next() { this._navigate(1); }
    prev() { this._navigate(-1); }

    zoomIn()  { this._zoomAt(1.25); }
    zoomOut() { this._zoomAt(1 / 1.25); }
    zoomReset() { this._resetZoom(); }

    onBackdrop(event) {
        if (event.target === this.backdropTarget) this._close();
    }

    onWheel(event) {
        if (!this._isOpen) return;
        event.preventDefault();
        const dir = event.deltaY < 0 ? 1 : -1;
        this._zoomAt(dir > 0 ? 1 + WHEEL_STEP : 1 / (1 + WHEEL_STEP), event);
    }

    onDoubleClick(event) {
        if (!this._isOpen) return;
        if (this._scale > 1) this._resetZoom();
        else this._zoomAt(2, event);
    }

    /* ───── touch: swipe + pinch + pan ───── */

    onTouchStart(event) {
        if (!this._isOpen) return;
        if (event.touches.length === 2) {
            const [a, b] = event.touches;
            this._pinchStart = {
                dist: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY),
                scale: this._scale,
            };
            this._touchStart = null;
            return;
        }
        if (event.touches.length === 1) {
            const t = event.touches[0];
            this._touchStart = { x: t.clientX, y: t.clientY, t: Date.now() };
        }
    }

    onTouchMove(event) {
        if (!this._isOpen) return;
        if (event.touches.length === 2 && this._pinchStart) {
            event.preventDefault();
            const [a, b] = event.touches;
            const dist = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
            const ratio = dist / this._pinchStart.dist;
            this._applyZoom(this._pinchStart.scale * ratio);
            return;
        }
        // Pan when zoomed (single finger drag)
        if (event.touches.length === 1 && this._scale > 1 && this._touchStart) {
            event.preventDefault();
            const t = event.touches[0];
            const dx = t.clientX - this._touchStart.x;
            const dy = t.clientY - this._touchStart.y;
            this._tx = this._dragStart ? this._dragStart.tx + dx : dx;
            this._ty = this._dragStart ? this._dragStart.ty + dy : dy;
            this._applyTransform();
        }
    }

    onTouchEnd(event) {
        if (!this._isOpen) return;
        if (this._pinchStart && event.touches.length < 2) {
            this._pinchStart = null;
            return;
        }
        if (!this._touchStart || event.touches.length > 0) return;
        const t0 = this._touchStart;
        const t1 = event.changedTouches[0];
        const dx = t1.clientX - t0.x;
        const dy = t1.clientY - t0.y;
        const dt = Date.now() - t0.t;
        this._touchStart = null;
        this._dragStart = null;

        // Horizontal swipe → navigate
        if (Math.abs(dx) > SWIPE_MIN_PX && Math.abs(dx) > Math.abs(dy) && dt < SWIPE_MAX_MS) {
            if (dx < 0) this.next();
            else this.prev();
            return;
        }
    }

    /* ───── mouse: drag-pan when zoomed ───── */

    onMouseDown(event) {
        if (!this._isOpen || this._scale <= 1) return;
        if (event.button !== 0) return;
        this._isDragging = true;
        this._dragStart = { x: event.clientX, y: event.clientY, tx: this._tx, ty: this._ty };
        event.preventDefault();
    }

    onMouseMove(event) {
        if (!this._isDragging || !this._dragStart) return;
        this._tx = this._dragStart.tx + (event.clientX - this._dragStart.x);
        this._ty = this._dragStart.ty + (event.clientY - this._dragStart.y);
        this._applyTransform();
    }

    onMouseUp() {
        this._isDragging = false;
        this._dragStart = null;
    }

    /* ───── toolbar: download / share / copy URL ───── */

    download() {
        const item = this._current();
        if (!item || !item.src) return;
        const a = document.createElement('a');
        a.href = item.src;
        a.download = item.name || ('tnsvt-' + Date.now() + '.jpg');
        a.rel = 'noopener';
        // data: URLs work; http(s) URLs rely on server CORS/disposition.
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    async share() {
        const item = this._current();
        if (!item || !item.src) return;
        try {
            if (navigator.share && navigator.canShare) {
                const file = await this._srcToFile(item);
                const data = { title: item.alt || 'Foto TNSVT', text: 'Compartido desde TNSVT' };
                if (file) data.files = [file];
                if (navigator.canShare(data)) {
                    await navigator.share(data);
                    return;
                }
            }
        } catch (e) {
            // fall through to copy
        }
        this._copyCurrentUrl('Enlace copiado al portapapeles');
    }

    async copyUrl() {
        const item = this._current();
        if (!item) return;
        try {
            await this._copyText(this._currentPageUrl());
            this._toast('Enlace copiado al portapapeles', 'success');
        } catch (e) {
            this._toast('No se pudo copiar el enlace', 'error');
        }
    }

    /* ───── internals ───── */

    _isOpen = false;

    _refreshTriggers() {
        // Snapshot of <img class="lightbox-trigger"> at this moment. Each
        // open() picks the current set, so newly added photos work too.
        this._triggers = Array.from(document.querySelectorAll('img.lightbox-trigger'));
    }

    _onDocClick(event) {
        const img = event.target.closest('img.lightbox-trigger');
        if (!img) return;
        // Re-scan so freshly rendered photos are picked up.
        this._refreshTriggers();
        const idx = this._triggers.indexOf(img);
        if (idx === -1) return;
        event.preventDefault();
        event.stopPropagation();
        this._open(idx);
    }

    _onKey(event) {
        if (!this._isOpen) return;
        switch (event.key) {
            case 'Escape':     this._close(); break;
            case 'ArrowLeft':  this.prev();    break;
            case 'ArrowRight': this.next();    break;
            case '+':
            case '=':          this.zoomIn();  break;
            case '-':
            case '_':          this.zoomOut(); break;
            case '0':          this.zoomReset(); break;
            default: return;
        }
        event.preventDefault();
    }

    _onPop() {
        if (this._isOpen && !location.hash.startsWith('#lightbox=')) {
            this._close({ silent: true });
        } else if (!this._isOpen && location.hash.startsWith('#lightbox=')) {
            this._openFromHash();
        }
    }

    _openFromHash() {
        const raw = location.hash.slice('#lightbox='.length);
        const [group, idxStr] = raw.split(':');
        const idx = parseInt(idxStr, 10);
        if (!group || Number.isNaN(idx)) return;
        this._refreshTriggers();
        const matches = this._triggers
            .map((el, i) => ({ el, i }))
            .filter(({ el }) => (el.dataset.lightboxGroup || '') === group);
        if (matches.length === 0) return;
        const target = matches[Math.min(Math.max(idx, 0), matches.length - 1)];
        this._open(target.i);
    }

    _open(index) {
        if (this._triggers.length === 0) return;
        const item = this._triggers[index];
        if (!item) return;
        this._index = index;
        this._isOpen = true;
        this._isClosing = false;

        this._prevFocus = document.activeElement;
        this.element.setAttribute('aria-modal', 'true');
        this.element.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';

        this._render();
        this._writeHash();

        // Focus the close button for keyboard users.
        setTimeout(() => {
            const closeBtn = this.element.querySelector('.lightbox-btn--close');
            if (closeBtn) closeBtn.focus();
        }, 30);
    }

    _close(opts = {}) {
        if (!this._isOpen || this._isClosing) return;
        this._isClosing = true;
        const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
        const finish = () => {
            this._isOpen = false;
            this._isClosing = false;
            this.element.setAttribute('hidden', '');
            this.element.removeAttribute('aria-modal');
            this._restoreBody();
            this._resetZoom();
            if (this._prevFocus && typeof this._prevFocus.focus === 'function') {
                this._prevFocus.focus();
            }
            this._prevFocus = null;
            if (!opts.silent) this._clearHash();
        };
        if (reduced) {
            finish();
        } else {
            this.element.classList.add('is-closing');
            setTimeout(finish, 180);
        }
    }

    _restoreBody() {
        document.body.style.overflow = '';
    }

    _navigate(delta) {
        if (!this._isOpen) return;
        const group = this._currentGroupKey();
        const sameGroup = this._triggers
            .map((el, i) => ({ el, i }))
            .filter(({ el }) => (el.dataset.lightboxGroup || '') === group);
        if (sameGroup.length < 2) return;
        const localIdx = sameGroup.findIndex(({ i }) => i === this._index);
        if (localIdx === -1) return;
        const nextLocal = (localIdx + delta + sameGroup.length) % sameGroup.length;
        const nextGlobal = sameGroup[nextLocal].i;
        this._open(nextGlobal);
    }

    _current() {
        const el = this._triggers[this._index];
        if (!el) return null;
        return {
            src: el.currentSrc || el.src,
            alt: el.alt || '',
            name: (el.dataset.lightboxName || el.alt || '').trim(),
            group: el.dataset.lightboxGroup || '',
        };
    }

    _currentGroupKey() {
        const item = this._current();
        return item ? item.group : '';
    }

    _render() {
        const item = this._current();
        if (!item) return;
        this.imageTarget.src = item.src;
        this.imageTarget.alt = item.alt;
        if (item.name) {
            this.captionTarget.textContent = item.name;
            this.captionTarget.hidden = false;
        } else {
            this.captionTarget.textContent = '';
            this.captionTarget.hidden = true;
        }
        // counter
        const group = this._currentGroupKey();
        const sameGroup = this._triggers
            .map((el, i) => ({ el, i }))
            .filter(({ el }) => (el.dataset.lightboxGroup || '') === group);
        if (group && sameGroup.length > 1) {
            const localIdx = sameGroup.findIndex(({ i }) => i === this._index);
            this.counterTarget.textContent = (localIdx + 1) + ' / ' + sameGroup.length;
            this.counterTarget.hidden = false;
        } else {
            this.counterTarget.textContent = '';
            this.counterTarget.hidden = true;
        }
        this._resetZoom();
    }

    /* ───── zoom + pan math ───── */

    _zoomAt(factor, anchorEvent) {
        const next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, this._scale * factor));
        if (next === this._scale) return;
        const rect = this.imageTarget.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        // Anchor zoom around click/wheel point when available
        const ax = anchorEvent && anchorEvent.clientX ? anchorEvent.clientX : cx;
        const ay = anchorEvent && anchorEvent.clientY ? anchorEvent.clientY : cy;
        // Adjust pan so the anchor point stays fixed in screen space.
        const k = next / this._scale;
        this._tx = ax - cx + (this._tx - (ax - cx)) * k;
        this._ty = ay - cy + (this._ty - (ay - cy)) * k;
        this._scale = next;
        this._clampPan();
        this._applyTransform();
    }

    _applyZoom(scale) {
        this._scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale));
        this._clampPan();
        this._applyTransform();
    }

    _resetZoom() {
        this._scale = 1;
        this._tx = 0;
        this._ty = 0;
        this._applyTransform();
    }

    _clampPan() {
        const stage = this.stageTarget.getBoundingClientRect();
        const w = stage.width * (this._scale - 1) / 2;
        const h = stage.height * (this._scale - 1) / 2;
        if (this._tx > w) this._tx = w;
        if (this._tx < -w) this._tx = -w;
        if (this._ty > h) this._ty = h;
        if (this._ty < -h) this._ty = -h;
    }

    _applyTransform() {
        this.imageTarget.style.transform =
            'translate(' + this._tx + 'px,' + this._ty + 'px) scale(' + this._scale + ')';
        this.imageTarget.style.cursor = this._scale > 1 ? (this._isDragging ? 'grabbing' : 'grab') : 'zoom-in';
    }

    /* ───── hash routing ───── */

    _writeHash() {
        const item = this._current();
        if (!item) return;
        const group = item.group;
        const groupEls = this._triggers
            .map((el, i) => ({ el, i }))
            .filter(({ el }) => (el.dataset.lightboxGroup || '') === group);
        if (!group || groupEls.length < 2) return; // solo photos: no hash
        const localIdx = groupEls.findIndex(({ i }) => i === this._index);
        const newHash = '#lightbox=' + encodeURIComponent(group) + ':' + localIdx;
        if (location.hash !== newHash) {
            history.replaceState(null, '', newHash);
        }
    }

    _clearHash() {
        if (location.hash.startsWith('#lightbox=')) {
            history.replaceState(null, '', location.pathname + location.search);
        }
    }

    _currentPageUrl() {
        return location.origin + location.pathname + location.search + location.hash;
    }

    /* ───── utilities ───── */

    async _copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback for non-secure contexts.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        ta.remove();
        if (!ok) throw new Error('copy failed');
    }

    async _srcToFile(item) {
        if (!item.src || !item.src.startsWith('data:')) return null;
        try {
            const r = await fetch(item.src);
            const blob = await r.blob();
            return new File([blob], item.name || 'tnsvt.jpg', { type: blob.type || 'image/jpeg' });
        } catch (e) {
            return null;
        }
    }

    _toast(message, kind) {
        if (typeof window.apiToast === 'function') {
            window.apiToast(message, kind || 'info');
        }
    }

    _copyCurrentUrl(message) {
        this._copyText(this._currentPageUrl())
            .then(() => this._toast(message || 'Enlace copiado', 'success'))
            .catch(() => this._toast('No se pudo copiar', 'error'));
    }
}