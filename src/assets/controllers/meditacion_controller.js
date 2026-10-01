import { Controller } from '@hotwired/stimulus';

/**
 * meditacion
 *
 * Track C+D (2026-09-29): sección /meditacion.
 *  - syncDrive: pega URL de carpeta Drive pública → POST /api/music/sync-from-drive
 *  - upload: multipart POST /api/music/upload (mp3/wav/ogg/mp4, ≤10MB)
 *  - loadStatus: GET /api/music/current → renderizar
 *
 * El mini-player real sigue siendo el del Sanctuary global en #sanctum-floats;
 * esta página es gestión, no reproducción.
 */
export default class extends Controller {
    static targets = [
        'status',
        'folderInput',
        'syncBtn',
        'syncResult',
        'dropZone',
        'fileInput',
        'uploadForm',
        'uploadBtn',
        'uploadResult',
        'tracksList',
    ];

    static values = {
        hasDriveKey: { type: Boolean, default: false },
    };

    connect() {
        this.refreshUIState();
        this.loadStatus();
    }

    // ─── Drive sync ────────────────────────────────────────────────

    async syncDrive(event) {
        event?.preventDefault?.();
        const folder = this.folderInputTarget.value.trim();
        if (!folder) {
            this.showResult(this.syncResultTarget, 'Pegá la URL o ID de la carpeta.', 'error');
            return;
        }
        this.syncBtnTarget.disabled = true;
        this.syncBtnTarget.textContent = 'Sincronizando…';
        try {
            const r = await window.apiFetch('/api/music/sync-from-drive', {
                method: 'POST',
                body: { folder },
                silent: true,
            });
            if (!r.ok || !r.data?.success) {
                const err = r.data?.error || `HTTP ${r.status}`;
                this.showResult(this.syncResultTarget, 'Error: ' + err, 'error');
                return;
            }
            const { added = [], skipped = [], total = 0 } = r.data;
            const lines = [];
            if (added.length) lines.push(`✓ Añadidos (${added.length}): ${added.join(', ')}`);
            if (skipped.length)
                lines.push(`· Ya estaban (${skipped.length}): ${skipped.join(', ')}`);
            lines.push(`Total en Drive: ${total}`);
            this.showResult(this.syncResultTarget, lines.join('\n'), 'success');
            if (window.apiToast) {
                window.apiToast(
                    added.length ? `Drive: ${added.length} nuevos` : 'Drive: nada nuevo',
                    'success'
                );
            }
            this.loadStatus();
        } catch (e) {
            this.showResult(this.syncResultTarget, 'Error de red: ' + e.message, 'error');
        } finally {
            this.syncBtnTarget.disabled = false;
            this.syncBtnTarget.innerHTML =
                '<span class="material-symbols-elev" aria-hidden="true">play_arrow</span> Sincronizar ahora';
        }
    }

    // ─── Upload ────────────────────────────────────────────────────

    triggerUpload(event) {
        event?.preventDefault?.();
        this.fileInputTarget.click();
    }

    pickFile(event) {
        const file = event.target.files?.[0];
        if (!file) return;
        this.refreshUIState();
    }

    onDragOver(event) {
        event.preventDefault();
        this.dropZoneTarget.classList.add('is-active');
    }

    onDragLeave(event) {
        event.preventDefault();
        this.dropZoneTarget.classList.remove('is-active');
    }

    onDrop(event) {
        event.preventDefault();
        this.dropZoneTarget.classList.remove('is-active');
        const files = Array.from(event.dataTransfer?.files ?? []);
        if (files.length === 0) return;
        const audio = files.find((f) => /^audio\//.test(f.type));
        if (!audio) {
            if (window.apiToast) window.apiToast('Solo archivos de audio.', 'warning');
            return;
        }
        const dt = new DataTransfer();
        dt.items.add(audio);
        this.fileInputTarget.files = dt.files;
        this.refreshUIState();
    }

    async upload(event) {
        event.preventDefault();
        const file = this.fileInputTarget.files?.[0];
        if (!file) {
            this.showResult(this.uploadResultTarget, 'Elegí un archivo primero.', 'error');
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            this.showResult(this.uploadResultTarget, 'El archivo supera 10MB.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('file', file);
        this.uploadBtnTarget.disabled = true;
        this.uploadBtnTarget.textContent = 'Subiendo…';
        try {
            const r = await fetch('/api/music/upload', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok || !data.success) {
                this.showResult(
                    this.uploadResultTarget,
                    'Error: ' + (data.error || r.statusText),
                    'error'
                );
                return;
            }
            this.showResult(
                this.uploadResultTarget,
                `✓ Subido: ${data.name} (${Math.round((data.size || 0) / 1024)} KB)`,
                'success'
            );
            if (window.apiToast) window.apiToast(`Audio añadido: ${data.name}`, 'success');
            this.fileInputTarget.value = '';
            this.refreshUIState();
            this.loadStatus();
        } catch (e) {
            this.showResult(this.uploadResultTarget, 'Error de red: ' + e.message, 'error');
        } finally {
            this.uploadBtnTarget.disabled = false;
            this.uploadBtnTarget.innerHTML =
                '<span class="material-symbols-elev" aria-hidden="true">cloud_upload</span> Subir';
        }
    }

    // ─── Status / Playlist ────────────────────────────────────────

    async loadStatus() {
        try {
            const r = await window.apiFetch('/api/music/current', { silent: true });
            if (!r.ok || !r.data) {
                this.statusTarget.textContent = 'sin playlist';
                this.tracksListTarget.innerHTML =
                    '<p class="meditacion-empty">No se pudo cargar el playlist.</p>';
                return;
            }
            const { playlist = [], total = 0, hasMusic = false } = r.data;
            this.statusTarget.textContent = hasMusic
                ? `${total} ${total === 1 ? 'canción' : 'canciones'}`
                : 'vacío';

            if (total === 0) {
                this.tracksListTarget.innerHTML =
                    '<p class="meditacion-empty">Aún no hay canciones. Sincronizá desde Drive o subí un archivo.</p>';
                return;
            }
            this.tracksListTarget.innerHTML = playlist
                .map((t, i) => {
                    const source = t.source === 'external' ? 'Drive / link' : 'Subido';
                    return `
                    <div class="meditacion-track">
                        <span class="meditacion-track-num">${i + 1}</span>
                        <span class="meditacion-track-name">${this.esc(t.name)}</span>
                        <span class="meditacion-track-meta">${source} · ${this.esc(t.mime || '')}</span>
                    </div>
                `;
                })
                .join('');
        } catch (e) {
            this.statusTarget.textContent = 'error';
            this.tracksListTarget.innerHTML = '<p class="meditacion-empty">Error de red.</p>';
        }
    }

    // ─── helpers ──────────────────────────────────────────────────

    refreshUIState() {
        if (this.hasUploadBtnTarget) {
            this.uploadBtnTarget.disabled =
                !this.hasFileInputTarget || !this.fileInputTarget.files?.length;
        }
    }

    showResult(target, text, kind) {
        if (!target) return;
        target.hidden = false;
        target.textContent = text;
        target.dataset.kind = kind || 'info';
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
}
