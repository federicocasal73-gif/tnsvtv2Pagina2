import { Controller } from '@hotwired/stimulus';

/**
 * El Cuaderno (Diary) — encrypted client-side journal.
 *
 * Extracted from templates/sanctum/diary.html.twig inline <script>
 * (P10 / F10 commit C6). Same code paths, same endpoints,
 * same AES-GCM 256 + PBKDF2 derivation. State machine (locked/list/
 * empty/editing) lives in this.element via helper methods.
 *
 * Why migrate to Stimulus:
 *   - The inline <script> ran every Turbo navigation; each visit re-
 *     added listeners and re-ran checkSetupAndLoad, causing duplicate
 *     /api/diary/setup requests and growing event-listeners.
 *   - With Stimulus, disconnect() cleans up the autosave timer
 *     and dismisses the beforeunload warning.
 *
 * State kept in the controller instance:
 *   - this.masterKey   — CryptoKey from PBKDF2, in memory only.
 *   - this.entries     — array of decrypted { id, title, body, created_at, updated_at }.
 *   - this.editId      — id of the entry being edited, or null for a new one.
 *   - this.dirty       — boolean for autosave + beforeunload.
 *   - this.autosaveTimer — setTimeout handle.
 */
const STATES = ['locked', 'list', 'empty', 'editing'];

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

export default class extends Controller {
    static targets = [
        'message',
        'stateLocked', 'stateList', 'stateEmpty', 'stateEditing',
        'passInput', 'unlockBtn', 'fingerprintBtn', 'lockSubtitle',
        'newBtn', 'exportBtn', 'lockBtn', 'resetBtn', 'listSummary', 'entriesGrid',
        'writeFirstBtn', 'lockEmptyBtn',
        'editorWrap', 'editorTitle', 'editorTitleInput', 'editorTextarea',
        'editorPreview', 'autosaveBadge', 'wordCount', 'manualSaveBtn',
        'lockEditBtn', 'backBtn', 'viewTabs', 'promptChips',
    ];

    connect() {
        this.masterKey = null;
        this.entries = [];
        this.editId = null;
        this.dirty = false;
        this.autosaveTimer = null;
        this.previewFadeTimer = null;

        this.wire();
        this.checkSetupAndLoad();
        this.beforeUnloadHandler = (e) => {
            if (!this.dirty) return undefined;
            e.preventDefault();
            e.returnValue = '';
            return '';
        };
        window.addEventListener('beforeunload', this.beforeUnloadHandler);
    }

    disconnect() {
        if (this.autosaveTimer) clearTimeout(this.autosaveTimer);
        if (this.previewFadeTimer) clearTimeout(this.previewFadeTimer);
        if (this.beforeUnloadHandler) {
            window.removeEventListener('beforeunload', this.beforeUnloadHandler);
        }
    }

    // ─── State machine ─────────────────────────────────────────────

    showState(s) {
        STATES.forEach((name) => {
            const el = this[`state${this.cap(name)}Target`];
            if (!el) return;
            if (name === s) {
                el.hidden = false;
                requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-visible')));
            } else {
                el.classList.remove('is-visible');
                setTimeout(() => {
                    if (this.currentState() !== name) el.hidden = true;
                }, 160);
            }
        });
    }

    currentState() {
        for (const name of STATES) {
            const el = this[`state${this.cap(name)}Target`];
            if (el && !el.hidden) return name;
        }
        return null;
    }

    cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    // ─── Crypto helpers ────────────────────────────────────────────

    async deriveKey(pass) {
        const enc = new TextEncoder();
        const keyMaterial = await crypto.subtle.importKey(
            'raw', enc.encode(pass), { name: 'PBKDF2' }, false, ['deriveKey']
        );
        return crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt: enc.encode('tnsvt-diary-v2'),
              iterations: 100000, hash: 'SHA-256' },
            keyMaterial,
            { name: 'AES-GCM', length: 256 },
            true, ['encrypt', 'decrypt']
        );
    }

    async encryptText(plaintext, key) {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const ct = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv }, key, new TextEncoder().encode(plaintext));
        return {
            encrypted: btoa(String.fromCharCode(...new Uint8Array(ct))),
            iv: btoa(String.fromCharCode(...iv)),
        };
    }

    async decryptText(encB64, ivB64, key) {
        try {
            const iv = Uint8Array.from(atob(ivB64), c => c.charCodeAt(0));
            const ct = Uint8Array.from(atob(encB64), c => c.charCodeAt(0));
            const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
            return new TextDecoder().decode(pt);
        } catch (e) { return null; }
    }

    async tokenFromKey(key) {
        const raw = await crypto.subtle.exportKey('raw', key);
        const h = await crypto.subtle.digest('SHA-256', raw);
        return btoa(String.fromCharCode(...new Uint8Array(h)));
    }

    // ─── State helpers ────────────────────────────────────────────

    showMessage(text, type) {
        if (!this.hasMessageTarget) return;
        this.messageTarget.className = type === 'success' ? 'diary-success' : 'diary-error';
        this.messageTarget.textContent = text;
        this.messageTarget.hidden = false;
        if (type === 'success') {
            setTimeout(() => { this.messageTarget.hidden = true; }, 4000);
        }
    }

    parseEntry(plaintext) {
        const lines = (plaintext || '').split('\n');
        if (lines[0] && lines[0].startsWith('#')) {
            return {
                title: lines.shift().slice(1).trim() || '(sin título)',
                body: lines.join('\n').trim(),
            };
        }
        return { title: '(sin título)', body: (plaintext || '').trim() };
    }

    serializeEntry(title, body) {
        const safeTitle = (title || '').trim() || '(sin título)';
        return '# ' + safeTitle + '\n\n' + (body || '').trim();
    }

    fmtDate(iso) {
        try {
            const d = new Date(iso);
            const dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
            return {
                day: d.getDate(),
                weekday: dias[d.getDay()],
                month: d.toLocaleString('es-AR', { month: 'short' }),
            };
        } catch (e) { return { day: '?', weekday: '—', month: '—' }; }
    }

    wordCount(s) { return (s.trim().match(/\S+/g) || []).length; }

    relTime(iso) {
        try {
            const t = new Date(iso).getTime();
            const diff = Date.now() - t;
            const m = Math.floor(diff / 60000);
            if (m < 1) return 'recién';
            if (m < 60) return 'hace ' + m + ' min';
            const h = Math.floor(m / 60);
            if (h < 24) return 'hace ' + h + ' h';
            const d = Math.floor(h / 24);
            if (d < 7) return 'hace ' + d + ' día' + (d > 1 ? 's' : '');
            const w = Math.floor(d / 7);
            if (w < 4) return 'hace ' + w + ' sem';
            const mo = Math.floor(d / 30);
            if (mo < 12) return 'hace ' + mo + ' mes' + (mo > 1 ? 'es' : '');
            const y = Math.floor(d / 365);
            return 'hace ' + y + ' año' + (y > 1 ? 's' : '');
        } catch (e) { return ''; }
    }

    // ─── LOCKED state ─────────────────────────────────────────────

    async checkSetupAndLoad() {
        try {
            const r = await window.apiFetch('/api/diary/setup', { silent: true });
            const data = (r.ok && r.data) ? r.data : null;
            const hasSetup = !!(data && data.setup_token);
            if (this.hasLockSubtitleTarget) {
                this.lockSubtitleTarget.textContent = hasSetup
                    ? 'Ingresá tu clave maestra para descifrar tus reflexiones.'
                    : 'Bienvenido. Configurá tu clave maestra (mínimo 8 caracteres).';
            }
            this.showState('locked');
        } catch (e) {
            this.showState('locked');
        }
    }

    async unlockOrSetup() {
        if (!this.hasPassInputTarget) return;
        const pass = this.passInputTarget.value;
        if (!pass || pass.length < 8) {
            this.showMessage('La clave debe tener al menos 8 caracteres', 'error');
            return;
        }
        try {
            this.masterKey = await this.deriveKey(pass);
            const r = await window.apiFetch('/api/diary/setup', { silent: true });
            const data = (r.ok && r.data) ? r.data : null;
            const storedToken = (data && data.setup_token) || null;
            const computedToken = await this.tokenFromKey(this.masterKey);

            if (storedToken) {
                if (computedToken !== storedToken) {
                    this.masterKey = null;
                    this.showMessage('Clave incorrecta — no descifra las entradas', 'error');
                    return;
                }
                this.showMessage('Cuaderno desbloqueado', 'success');
            } else {
                const ivB64 = btoa(String.fromCharCode(
                    ...crypto.getRandomValues(new Uint8Array(12))));
                const sr = await window.apiFetch('/api/diary/setup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ setup_token: computedToken, setup_iv: ivB64 }),
                });
                if (!sr.ok || !sr.data || !sr.data.success) {
                    this.masterKey = null;
                    this.showMessage('Error guardando setup', 'error');
                    return;
                }
                this.showMessage('Clave maestra configurada', 'success');
            }
            await this.loadEntries();
        } catch (e) {
            console.error(e);
            this.masterKey = null;
            this.showMessage('Error: ' + (e.message || 'desconocido'), 'error');
        }
    }

    // ─── LIST / EMPTY state ───────────────────────────────────────

    async loadEntries() {
        try {
            const r = await window.apiFetch('/api/diary', { silent: true });
            if (!r.ok || !r.data) {
                this.showState('empty');
                return;
            }
            const data = Array.isArray(r.data) ? { entries: r.data } : r.data;
            const encrypted = data.entries || [];

            const decrypted = [];
            for (const e of encrypted) {
                const pt = await this.decryptText(e.encrypted_data, e.iv, this.masterKey);
                if (pt === null) {
                    this.showMessage('La clave no descifra estas entradas — usando clave incorrecta', 'error');
                    this.masterKey = null;
                    this.showState('locked');
                    return;
                }
                const parsed = this.parseEntry(pt);
                decrypted.push({
                    id: e.id,
                    created_at: e.created_at,
                    updated_at: e.updated_at,
                    ...parsed,
                });
            }
            this.entries = decrypted
                .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

            if (this.entries.length === 0) {
                this.showState('empty');
                return;
            }
            this.renderList();
            this.showState('list');
        } catch (e) {
            console.error(e);
            this.showState('empty');
        }
    }

    renderList() {
        if (!this.hasEntriesGridTarget) return;
        if (this.hasListSummaryTarget) {
            this.listSummaryTarget.textContent =
                `${this.entries.length} ${this.entries.length === 1 ? 'entrada cifrada' : 'entradas cifradas'}`;
        }
        this.entriesGridTarget.innerHTML = this.entries.map((e) => {
            const d = this.fmtDate(e.created_at);
            const wc = this.wordCount(e.body);
            return `
                <article class="diary-entry-card" data-id="${e.id}" tabindex="0">
                    <div class="diary-entry-card-date">${d.day}</div>
                    <div class="diary-entry-card-weekday">${d.weekday} · ${d.month}</div>
                    <h3 class="diary-entry-card-title">${escapeHtml(e.title)}</h3>
                    <p class="diary-entry-card-preview">${escapeHtml(e.body.slice(0, 200))}</p>
                    <div class="diary-entry-card-footer">
                        <span style="display:inline-flex;align-items:center;gap:0.3rem;">
                            <span class="material-symbols-outlined" style="font-size:0.85rem;">lock</span>
                            Cifrada · ${wc} palabras · ${escapeHtml(this.relTime(e.created_at))}
                        </span>
                        <span class="material-symbols-outlined diary-entry-card-chevron">chevron_right</span>
                    </div>
                </article>
            `;
        }).join('');

        this.entriesGridTarget.querySelectorAll('.diary-entry-card').forEach((el) => {
            el.addEventListener('click', () => this.openEntry(parseInt(el.dataset.id, 10)));
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.openEntry(parseInt(el.dataset.id, 10));
                    return;
                }
                if (e.key === 'ArrowDown' || e.key === 'ArrowRight'
                    || e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    const cards = Array.from(this.entriesGridTarget.querySelectorAll('.diary-entry-card'));
                    const i = cards.indexOf(el);
                    const dir = (e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : -1;
                    const next = cards[i + dir];
                    if (next) next.focus();
                }
            });
        });
    }

    // ─── EDITING state ────────────────────────────────────────────

    openEntry(id) {
        const e = this.entries.find((en) => en.id === id);
        if (!e) return;
        this.editId = id;
        if (this.hasEditorTitleTarget) {
            try {
                this.editorTitleTarget.textContent = new Date(e.created_at)
                    .toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric' });
            } catch (_) {
                this.editorTitleTarget.textContent = 'Editar entrada';
            }
        }
        if (this.hasEditorTitleInputTarget) this.editorTitleInputTarget.value = e.title;
        if (this.hasEditorTextareaTarget) this.editorTextareaTarget.value = e.body;
        this.updatePreview();
        this.updateWordCount();
        this.dirty = false;
        this.setAutosaveBadge('saved');
        this.showState('editing');
        setTimeout(() => {
            if (this.hasEditorTextareaTarget) this.editorTextareaTarget.focus();
        }, 50);
    }

    startNew() {
        this.editId = null;
        if (this.hasEditorTitleTarget) this.editorTitleTarget.textContent = 'Nueva entrada';
        if (this.hasEditorTitleInputTarget) this.editorTitleInputTarget.value = '';
        if (this.hasEditorTextareaTarget) this.editorTextareaTarget.value = '';
        this.updatePreview();
        this.updateWordCount();
        this.dirty = false;
        this.setAutosaveBadge('saved');
        this.showState('editing');
        setTimeout(() => {
            if (this.hasEditorTextareaTarget) this.editorTextareaTarget.focus();
        }, 50);
    }

    updatePreview() {
        if (!this.hasEditorTextareaTarget || !this.hasEditorPreviewTarget) return;
        const v = this.editorTextareaTarget.value;
        const preview = this.editorPreviewTarget;
        if (this.previewFadeTimer) clearTimeout(this.previewFadeTimer);
        preview.classList.add('is-fading');
        this.previewFadeTimer = setTimeout(() => {
            if (!v.trim()) {
                preview.innerHTML = '<p class="diary-editor-preview-empty">La vista previa aparece en cuanto escribas...</p>';
            } else {
                const html = escapeHtml(v)
                    .replace(/^# (.+)$/gm, '<h3>$1</h3>')
                    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
                    .replace(/\n\n/g, '</p><p>')
                    .replace(/\n/g, '<br>');
                preview.innerHTML = '<p>' + html + '</p>';
            }
            preview.classList.remove('is-fading');
        }, 120);
    }

    updateWordCount() {
        if (!this.hasEditorTextareaTarget || !this.hasWordCountTarget) return;
        const v = this.editorTextareaTarget.value;
        const wc = this.wordCount(v);
        this.wordCountTarget.textContent = `${wc} palabras · ${v.length} caracteres`;
    }

    setAutosaveBadge(state) {
        if (!this.hasAutosaveBadgeTarget) return;
        if (state === 'saving') {
            this.autosaveBadgeTarget.innerHTML =
                '<span class="material-symbols-outlined" style="font-size:0.85rem;">progress_activity</span> Guardando…';
            this.autosaveBadgeTarget.classList.remove('saved');
            this.autosaveBadgeTarget.classList.add('saving');
        } else {
            this.autosaveBadgeTarget.innerHTML =
                '<span class="material-symbols-outlined" style="font-size:0.85rem;">check_circle</span> Guardado';
            this.autosaveBadgeTarget.classList.remove('saving');
            this.autosaveBadgeTarget.classList.add('saved');
        }
    }

    async saveCurrent(opts = {}) {
        const silent = !!opts.silent;
        if (!this.hasEditorTitleInputTarget || !this.hasEditorTextareaTarget) return false;
        const title = this.editorTitleInputTarget.value.trim();
        const body = this.editorTextareaTarget.value;
        if (!body.trim()) return false;
        const payload = this.serializeEntry(title, body);
        const { encrypted, iv } = await this.encryptText(payload, this.masterKey);
        this.setAutosaveBadge('saving');
        let r;
        if (this.editId) {
            r = await window.apiFetch('/api/diary/' + this.editId, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ encrypted_data: encrypted, iv }),
            });
        } else {
            r = await window.apiFetch('/api/diary', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ encrypted_data: encrypted, iv }),
            });
            if (r.ok && r.data && r.data.id) this.editId = r.data.id;
        }
        if (r.ok && r.data && (r.data.success || r.data.id)) {
            this.dirty = false;
            this.setAutosaveBadge('saved');
            if (!silent) this.showMessage('Entrada guardada', 'success');
            return true;
        } else {
            this.setAutosaveBadge('saved');
            this.showMessage('Error guardando — reintentá', 'error');
            return false;
        }
    }

    scheduleAutosave() {
        this.dirty = true;
        if (this.autosaveTimer) clearTimeout(this.autosaveTimer);
        this.setAutosaveBadge('saved');
        this.autosaveTimer = setTimeout(() => {
            if (this.dirty) this.saveCurrent({ silent: true });
        }, 5000);
    }

    async lockNow() {
        if (this.dirty) {
            if (!await window.apiConfirm(
                'Tenés cambios sin guardar. ¿Bloquear igual?',
                { title: 'Cambios sin guardar' }
            )) return;
        }
        this.masterKey = null;
        this.editId = null;
        this.entries = [];
        if (this.hasPassInputTarget) this.passInputTarget.value = '';
        if (this.autosaveTimer) { clearTimeout(this.autosaveTimer); this.autosaveTimer = null; }
        this.showMessage('Cuaderno bloqueado', 'success');
        this.showState('locked');
    }

    exportEntries() {
        if (!this.entries.length) {
            this.showMessage('No hay entradas para exportar', 'error');
            return;
        }
        const lines = ['# Cuaderno del Alma — exportación', '', '_Exportado el ' + new Date().toLocaleString('es-AR') + '_', ''];
        this.entries.slice()
            .sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
            .forEach((e) => {
                const when = e.created_at ? new Date(e.created_at).toLocaleString('es-AR') : '';
                lines.push('## ' + (e.title || '(sin título)') + (when ? ' — ' + when : ''));
                lines.push('');
                lines.push(e.body || '');
                lines.push('');
                lines.push('---');
                lines.push('');
            });
        const blob = new Blob([lines.join('\n')], { type: 'text/markdown;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'cuaderno-tnsvt.md';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        this.showMessage(this.entries.length + ' entrada(s) exportada(s)', 'success');
    }

    async resetDiary() {
        const ok = await window.apiConfirm(
            '⚠ Esto BORRARÁ todas tus entradas cifradas y la clave maestra. No se puede deshacer. ¿Continuar?',
            {
                title: 'Resetear clave del Cuaderno',
                confirmLabel: 'Sí, resetear',
                cancelLabel: 'Cancelar',
                variant: 'danger',
            }
        );
        if (!ok) return;

        const r = await window.apiFetch('/api/diary/setup', {
            method: 'DELETE',
            body: JSON.stringify({ user_code: window.TNSVT_USER?.code }),
        });
        if (r.ok && r.data && r.data.success) {
            if (window.apiToast) {
                window.apiToast(
                    `Cuaderno reseteado. ${r.data.entries_deleted || 0} entradas eliminadas.`,
                    'success');
            }
            this.entries = [];
            this.editId = null;
            this.dirty = false;
            this.showState('locked');
            if (this.hasPassInputTarget) this.passInputTarget.value = '';
        } else {
            const msg = (r.data && r.data.error) || 'Error al resetear';
            if (window.apiToast) window.apiToast(msg, 'error');
            else this.showMessage(msg, 'error');
        }
    }

    // ─── Wire-up ──────────────────────────────────────────────────

    wire() {
        // L47: mobile write/read toggle for the stacked editor.
        this.element.querySelectorAll('.diary-view-tab').forEach((btn) => {
            btn.addEventListener('click', () => this.onViewTabClick(btn));
        });

        if (this.hasUnlockBtnTarget) {
            this.unlockBtnTarget.addEventListener('click', () => this.unlockOrSetup());
        }
        if (this.hasPassInputTarget) {
            this.passInputTarget.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') this.unlockOrSetup();
            });
        }
        if (this.hasFingerprintBtnTarget) {
            this.fingerprintBtnTarget.addEventListener('click', () => {
                this.showMessage('Huella biométrica requiere WebAuthn — próxima iteración', 'error');
            });
        }

        if (this.hasNewBtnTarget) this.newBtnTarget.addEventListener('click', () => this.startNew());
        if (this.hasLockBtnTarget) this.lockBtnTarget.addEventListener('click', () => this.lockNow());
        if (this.hasExportBtnTarget) this.exportBtnTarget.addEventListener('click', () => this.exportEntries());
        if (this.hasWriteFirstBtnTarget) this.writeFirstBtnTarget.addEventListener('click', () => this.startNew());
        if (this.hasLockEmptyBtnTarget) this.lockEmptyBtnTarget.addEventListener('click', () => this.lockNow());
        if (this.hasResetBtnTarget) this.resetBtnTarget.addEventListener('click', () => this.resetDiary());

        const $title = this.hasEditorTitleInputTarget ? this.editorTitleInputTarget : null;
        const $body = this.hasEditorTextareaTarget ? this.editorTextareaTarget : null;
        if ($body) {
            $body.addEventListener('input', () => {
                this.updatePreview();
                this.updateWordCount();
                this.scheduleAutosave();
            });
        }
        if ($title) {
            $title.addEventListener('input', () => this.scheduleAutosave());
        }

        this.element.querySelectorAll('.diary-prompt-chip').forEach((btn) => {
            btn.addEventListener('click', () => this.onPromptChipClick(btn));
        });

        if (this.hasBackBtnTarget) {
            this.backBtnTarget.addEventListener('click', () => this.onBackClick());
        }
        if (this.hasManualSaveBtnTarget) {
            this.manualSaveBtnTarget.addEventListener('click', () => this.saveCurrent());
        }
        if (this.hasLockEditBtnTarget) {
            this.lockEditBtnTarget.addEventListener('click', () => this.lockNow());
        }
    }

    onViewTabClick(btn) {
        if (this.hasEditorWrapTarget) this.editorWrapTarget.dataset.diaryView = btn.dataset.view;
        this.element.querySelectorAll('.diary-view-tab').forEach((x) => {
            const active = x === btn;
            x.classList.toggle('is-active', active);
            x.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    onPromptChipClick(btn) {
        if (!this.hasEditorTextareaTarget) return;
        const $body = this.editorTextareaTarget;
        const cur = $body.value;
        const phrase = btn.dataset.prompt;
        $body.value = cur ? cur + '\n\n' + phrase + '\n\n' : phrase + '\n\n';
        $body.focus();
        $body.dispatchEvent(new Event('input'));
    }

    async onBackClick() {
        if (this.dirty) {
            if (!await window.apiConfirm(
                'Tenés cambios sin guardar. ¿Volver sin guardar?',
                { title: 'Cambios sin guardar' }
            )) return;
            this.dirty = false;
        }
        if (this.editId) await this.saveCurrent({ silent: true });
        this.entries = [];
        await this.loadEntries();
    }
}
