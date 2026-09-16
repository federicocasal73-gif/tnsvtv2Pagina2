import { Controller } from '@hotwired/stimulus';

/**
 * Chat page (/sanctum/chat) — full conversation list + messages + new DM.
 *
 * Extracted from templates/sanctum/chat.html.twig inline <script>
 * (~365 LoC). Same endpoints, same markup, same behaviour.
 *
 * Why migrate: the inline <script> re-ran on every Turbo navigation,
 * re-attaching listeners and stacking a new apiPoller every visit
 * (loadConversations every 30s × N visits). The Stimulus
 * disconnect() stops the poller and clears debounce timers, so one
 * mount == one poller.
 */
const REACT_QUICK = ['❤️', '👍', '🔥', '👏', '😮'];
const DM_SAVED_KEY = 'tnsvt_feed_saved';

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[m]));
}
function initials(name) {
    return (name || '?').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase();
}
function relTime(iso) {
    try {
        const d = new Date(iso);
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60)   return 'ahora';
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        return d.toLocaleDateString('es-AR', { day: '2-digit', month: 'short' });
    } catch (e) { return ''; }
}
function fmtTime(iso) {
    try {
        const d = new Date(iso);
        return d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
    } catch (e) { return ''; }
}

export default class extends Controller {
    static targets = [
        'list', 'tabs', 'search', 'newDmBtn',
        'convHeader', 'convAvatar', 'convName', 'convStatus',
        'messages', 'composer', 'input', 'send', 'emptyDefault',
        'newDmModal', 'modalClose', 'dmSearch', 'dmResults',
    ];

    connect() {
        this.tab = 'all';
        this.search = '';
        this.activeConvId = null;
        this.conversations = [];
        this.likedIds = new Set();
        this.savedIds = new Set();
        try {
            const raw = localStorage.getItem(DM_SAVED_KEY);
            if (raw) this.savedIds = new Set(JSON.parse(raw));
        } catch (e) { /* localStorage disabled */ }
        this.searchTimer = null;
        this.dmTimer = null;

        this.wire();
        this.restoreUrlState();
        // Wait for the shell to hydrate window.TNSVT_USER before the first
        // load: without it apiFetch sends no X-Game-Code and the API answers
        // 400 'user_code requerido'. Same pattern as chat-widget/feed.
        if (window.TNSVT_USER?.code) {
            this.loadConversations();
        } else {
            window.addEventListener('tnsvt:user-loaded', () => this.loadConversations(), { once: true });
        }
        if (typeof window.apiPoller === 'function') {
            this._poller = window.apiPoller(() => this.loadConversations(), 30 * 1000);
        }
    }

    disconnect() {
        if (this._poller && typeof this._poller.stop === 'function') {
            this._poller.stop();
            this._poller = null;
        }
        if (this.searchTimer) clearTimeout(this.searchTimer);
        if (this.dmTimer) clearTimeout(this.dmTimer);
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || null;
    }

    // ─── Wire-up (runs once per mount; disconnect clears nothing else
    //     because every listener below is attached to elements inside
    //     this.element, which Turbo discards on navigation) ──────────

    wire() {
        if (this.hasInputTarget) {
            this.inputTarget.addEventListener('input', () => this.updateSend());
            this.inputTarget.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    if (this.hasSendTarget && !this.sendTarget.disabled) this.sendTarget.click();
                }
            });
        }
        if (this.hasSendTarget) {
            this.sendTarget.addEventListener('click', () => this.sendMessage());
        }
        if (this.hasTabsTarget) {
            this.tabsTarget.querySelectorAll('.chat-tab').forEach((t) => {
                t.addEventListener('click', () => this.selectTab(t));
            });
        }
        if (this.hasSearchTarget) {
            this.searchTarget.addEventListener('input', (e) => this.onSearchInput(e));
        }
        if (this.hasNewDmBtnTarget) {
            this.newDmBtnTarget.addEventListener('click', () => this.openNewDmModal());
        }
        if (this.hasModalCloseTarget) {
            this.modalCloseTarget.addEventListener('click', () => {
                if (this._dmModal) this._dmModal.close();
            });
        }
        if (this.hasDmSearchTarget) {
            this.dmSearchTarget.addEventListener('input', (e) => this.onDmSearchInput(e));
        }
        if (typeof window.apiSetupModal === 'function' && this.hasNewDmModalTarget) {
            this._dmModal = window.apiSetupModal(this.newDmModalTarget, {
                open: () => {
                    if (this.hasDmSearchTarget) this.dmSearchTarget.value = '';
                    if (this.hasDmResultsTarget) {
                        this.dmResultsTarget.innerHTML = '<p class="chat-list-empty">Escribí un código o nombre para buscar...</p>';
                    }
                },
            });
        }
    }

    // ─── Conversations list ─────────────────────────────────────────

    async loadConversations() {
        if (!this.hasListTarget) return;
        // No credentials yet (shell still hydrating user): skip silently
        // instead of firing a request that can only answer 400.
        if (!this.me()) return;
        try {
            const r = await window.apiFetch('/api/chat/conversations', { silent: true });
            if (!r.ok || !r.data) {
                this.listTarget.innerHTML = '';
                if (window.apiEmpty) {
                    window.apiEmpty(this.listTarget, { icon: 'forum', message: 'Sin conversaciones', size: 'compact' });
                }
                return;
            }
            const items = Array.isArray(r.data) ? r.data : (r.data.conversations || []);
            this.conversations = items;
            this.renderConversations();
        } catch (e) {
            this.listTarget.innerHTML = '<p class="chat-list-empty">Sin conexión</p>';
        }
    }

    renderConversations() {
        let items = this.conversations.slice();
        if (this.tab === 'groups') {
            items = items.filter((c) => c.is_group === true || c.type === 'group');
        }
        if (this.tab === 'unread') {
            items = items.filter((c) => (c.unread || 0) > 0);
        }
        if (this.search) {
            const q = this.search.toLowerCase();
            const bodyOf = (c) => {
                const lm = c.last_message_preview ?? c.last_message ?? c.lastMessage ?? '';
                if (typeof lm === 'string') return lm;
                if (lm && typeof lm === 'object') return lm.content || lm.text || lm.message || '';
                return '';
            };
            items = items.filter((c) =>
                (c.name || c.title || '').toLowerCase().includes(q)
                || bodyOf(c).toLowerCase().includes(q));
        }

        if (items.length === 0) {
            this.listTarget.innerHTML = '';
            if (window.apiEmpty) {
                window.apiEmpty(this.listTarget, {
                    icon: 'search_off',
                    message: `No hay conversaciones${this.search ? ' que coincidan con la búsqueda' : ''}.`,
                    size: 'compact',
                });
            }
            return;
        }

        this.listTarget.innerHTML = items.map((c) => {
            const id = c.id || c.conversation_id;
            const name = c.name || c.title || 'Conversación';
            const isActive = String(this.activeConvId) === String(id);
            const unread = c.unread || 0;
            const preview = c.last_message_preview || c.last_message || (c.lastMessage || '');
            const time = c.last_message_at || c.last_message_time || c.updated_at || '';
            return `
                <div class="chat-conv ${isActive ? 'active' : ''}" data-id="${esc(id)}">
                    <div class="chat-conv-avatar">${esc(initials(name))}</div>
                    <div class="chat-conv-body">
                        <div class="chat-conv-name">${esc(name)}</div>
                        <div class="chat-conv-preview">${esc(String(preview).slice(0, 60))}</div>
                    </div>
                    <div class="chat-conv-meta">
                        <span class="chat-conv-time">${esc(relTime(time))}</span>
                        ${unread > 0 ? `<span class="chat-conv-unread">${unread}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        this.listTarget.querySelectorAll('.chat-conv').forEach((el) => {
            el.addEventListener('click', () => this.openConversation(el.dataset.id));
        });
    }

    async openConversation(id) {
        this.activeConvId = id;
        this.renderConversations();
        const conv = this.conversations.find((c) => String(c.id || c.conversation_id) === String(id));
        if (conv && this.hasConvNameTarget) {
            this.convNameTarget.textContent = conv.name || conv.title || 'Conversación';
            if (this.hasConvAvatarTarget) {
                this.convAvatarTarget.textContent = initials(conv.name || conv.title || 'C');
            }
            if (this.hasConvStatusTarget) {
                const last = conv.last_message?.created_at || conv.lastMessage?.created_at
                    || conv.updated_at || conv.last_message_at;
                if (conv.is_group || conv.type === 'group') {
                    const n = conv.member_count || conv.members?.length;
                    this.convStatusTarget.textContent = n ? 'Grupo · ' + n : 'Grupo';
                } else if (last) {
                    const diff = (Date.now() - new Date(last).getTime()) / 1000;
                    this.convStatusTarget.textContent = diff < 300
                        ? 'activo ahora'
                        : 'últ. actividad ' + fmtTime(last);
                } else {
                    this.convStatusTarget.textContent = 'Sin mensajes';
                }
            }
        }
        if (this.hasConvHeaderTarget) this.convHeaderTarget.style.display = 'flex';
        if (this.hasComposerTarget) this.composerTarget.style.display = 'flex';
        if (this.hasEmptyDefaultTarget) this.emptyDefaultTarget.style.display = 'none';
        if (this.hasMessagesTarget) {
            this.messagesTarget.innerHTML = '<div class="skeleton-stack" aria-hidden="true"><div class="skeleton skeleton-card"><div class="skeleton skeleton-line w-3-4"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line w-1-2"></div></div><div class="skeleton skeleton-card"><div class="skeleton skeleton-line w-3-4"></div><div class="skeleton skeleton-line"></div></div></div>';
        }
        try {
            const r = await window.apiFetch(`/api/chat/conversations/${id}/messages`, { silent: true });
            if (!r.ok || !r.data) {
                if (this.hasMessagesTarget) this.messagesTarget.innerHTML = '<p class="chat-list-empty">Sin mensajes</p>';
                return;
            }
            const items = Array.isArray(r.data) ? r.data : (r.data.messages || []);
            this.renderMessages(items);
            window.apiFetch(`/api/chat/conversations/${id}/read`, { method: 'POST', silent: true });
            const c = this.conversations.find((cc) => String(cc.id || cc.conversation_id) === String(id));
            if (c) c.unread = 0;
        } catch (e) {
            if (this.hasMessagesTarget) this.messagesTarget.innerHTML = '<p class="chat-list-empty">Error al cargar mensajes</p>';
        }
    }

    renderMessages(items) {
        if (!this.hasMessagesTarget) return;
        if (items.length === 0) {
            this.messagesTarget.innerHTML = '<p class="chat-list-empty">Sin mensajes — escribí el primero.</p>';
            return;
        }
        const me = this.me();
        this.messagesTarget.innerHTML = items.map((m) => {
            const mine = me && (m.author_code === me || m.sender_code === me || m.from_code === me);
            const authorName = m.author || m.author_name || m.sender_name || m.from_name || (mine ? 'Vos' : '—');
            const readers = Array.isArray(m.read_by) ? m.read_by : [];
            const receipt = mine
                ? (readers.length > 0
                    ? `<span class="chat-read is-read" title="Leído por ${esc(readers.join(', '))}">✓✓</span>`
                    : `<span class="chat-read" title="Enviado">✓</span>`)
                : '';
            const reacts = (m.reactions && typeof m.reactions === 'object') ? m.reactions : {};
            const chips = Object.entries(reacts)
                .filter(([, codes]) => Array.isArray(codes) && codes.length > 0)
                .map(([emoji, codes]) => {
                    const own = me && codes.includes(me);
                    return `<button type="button" class="chat-react-chip${own ? ' is-own' : ''}" data-react="${esc(emoji)}" data-id="${m.id}" title="${esc(codes.join(', '))}">${esc(emoji)} ${codes.length}</button>`;
                }).join('');
            const picker = REACT_QUICK.map((e) =>
                `<button type="button" class="chat-react-pick" data-react="${esc(e)}" data-id="${m.id}" aria-label="Reaccionar ${esc(e)}">${esc(e)}</button>`
            ).join('');
            return `
                <div class="chat-msg ${mine ? 'own' : ''}" data-id="${esc(m.id || '')}">
                    <div class="chat-msg-avatar">${esc(initials(authorName))}</div>
                    <div>
                        <div class="chat-msg-bubble">
                            <div class="chat-msg-content">${esc(m.text || m.content || m.message || '')}</div>
                            ${m.photo ? `<img class="chat-msg-photo lightbox-trigger" data-lightbox-group="chat-msg" data-lightbox-name="${esc(m.user || 'adjunto')}" style="display:block;width:auto;height:auto;max-width:240px;max-height:320px;border-radius:0.5rem;margin-top:0.4rem;cursor:zoom-in;" src="${esc(m.photo)}" alt="${esc(m.user || '')}" loading="lazy" decoding="async" />` : ''}
                            ${chips ? `<div class="chat-react-row">${chips}</div>` : ''}
                            <div class="chat-react-bar" aria-label="Reaccionar">${picker}</div>
                        </div>
                        <div class="chat-msg-meta">${esc(authorName)}${m.date || m.created_at ? ' · ' + esc(fmtTime(m.date || m.created_at)) : ''} ${receipt}</div>
                    </div>
                </div>
            `;
        }).join('');
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        this.messagesTarget.querySelectorAll('[data-react][data-id]').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                this.sendReaction(this.activeConvId, btn.dataset.id, btn.dataset.react);
            });
        });
    }

    async sendReaction(convId, msgId, emoji) {
        if (!convId || !msgId || !emoji) return;
        try {
            await window.apiFetch(`/api/chat/conversations/${convId}/messages/${msgId}/react`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ emoji }),
                silent: true,
            });
            if (this.activeConvId) this.openConversation(this.activeConvId);
        } catch (e) { /* silent: toggle is best-effort */ }
    }

    // ─── Composer ───────────────────────────────────────────────

    updateSend() {
        if (!this.hasSendTarget || !this.hasInputTarget) return;
        this.sendTarget.disabled = !(this.inputTarget.value.trim().length > 0 && this.activeConvId);
    }

    async sendMessage() {
        if (!this.hasInputTarget || !this.hasSendTarget) return;
        const text = this.inputTarget.value.trim();
        if (!text || !this.activeConvId) return;
        this.sendTarget.disabled = true;
        try {
            const r = await window.apiFetch(`/api/chat/conversations/${this.activeConvId}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content: text }),
            });
            if (r.ok && r.data) {
                this.inputTarget.value = '';
                await this.openConversation(this.activeConvId);
            } else {
                if (window.apiToast) {
                    window.apiToast(r.data && r.data.error ? r.data.error : 'Error al enviar', 'error');
                }
            }
        } catch (e) {
            console.error('[chat] send error', e);
            if (window.apiToast) window.apiToast('Error de red al enviar', 'error');
        } finally {
            this.updateSend();
        }
    }

    // ─── Tabs + search (URL state) ─────────────────────────────

    selectTab(t) {
        if (!this.hasTabsTarget) return;
        this.tabsTarget.querySelectorAll('.chat-tab').forEach((x) =>
            x.classList.toggle('active', x === t));
        this.tab = t.dataset.tab;
        const url = new URL(location.href);
        if (this.tab && this.tab !== 'all') url.searchParams.set('tab', this.tab);
        else url.searchParams.delete('tab');
        history.replaceState(null, '', url);
        this.renderConversations();
    }

    onSearchInput(e) {
        this.search = e.target.value;
        const url = new URL(location.href);
        if (this.search) url.searchParams.set('q', this.search);
        else url.searchParams.delete('q');
        history.replaceState(null, '', url);
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.renderConversations(), 150);
    }

    restoreUrlState() {
        const params = new URLSearchParams(location.search);
        const tab = params.get('tab');
        if (tab && ['all', 'unread', 'groups'].includes(tab) && this.hasTabsTarget) {
            this.tabsTarget.querySelectorAll('.chat-tab').forEach((x) => {
                const active = x.dataset.tab === tab;
                x.classList.toggle('active', active);
                if (active) this.tab = tab;
            });
        }
        const q = params.get('q');
        if (q && this.hasSearchTarget) {
            this.searchTarget.value = q;
            this.search = q;
        }
    }

    // ─── New DM modal ──────────────────────────────────────────

    openNewDmModal() {
        if (this._dmModal) {
            this._dmModal.open();
        } else if (window.apiToast) {
            window.apiToast('El diálogo no está disponible, recargá la página', 'error');
        }
    }

    onDmSearchInput(e) {
        const q = e.target.value.trim();
        clearTimeout(this.dmTimer);
        if (!q) {
            if (this.hasDmResultsTarget) {
                this.dmResultsTarget.innerHTML = '<p class="chat-list-empty">Escribí un código o nombre para buscar...</p>';
            }
            return;
        }
        this.dmTimer = setTimeout(() => this.searchDmUsers(q), 250);
    }

    async searchDmUsers(q) {
        try {
            const r = await window.apiFetch(`/api/chat/users?q=${encodeURIComponent(q)}`, { silent: true });
            if (!r.ok || !r.data || !this.hasDmResultsTarget) return;
            const users = Array.isArray(r.data) ? r.data : (r.data.users || []);
            if (users.length === 0) {
                this.dmResultsTarget.innerHTML = '<p class="chat-list-empty">Sin resultados</p>';
                return;
            }
            this.dmResultsTarget.innerHTML = users.slice(0, 8).map((u) => `
                <div class="chat-user-result" data-code="${esc(u.code)}" data-name="${esc(u.name || u.code)}">
                    <div class="chat-conv-avatar">${esc(initials(u.name || u.code))}</div>
                    <div>
                        <div style="font-weight:600;">${esc(u.name || u.code)}</div>
                        <div style="font-size:0.7rem;color:var(--outline-elev);">${esc(u.code)}</div>
                    </div>
                </div>
            `).join('');
            this.dmResultsTarget.querySelectorAll('.chat-user-result').forEach((el) => {
                el.addEventListener('click', () => this.createDm(el.dataset.code, el.dataset.name));
            });
        } catch (e) { console.error(e); }
    }

    async createDm(code, name) {
        try {
            const r = await window.apiFetch('/api/chat/conversations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code, name }),
            });
            if (r.ok && r.data) {
                if (this._dmModal) this._dmModal.close();
                await this.loadConversations();
                const newId = r.data.id || r.data.conversation_id || code;
                this.openConversation(newId);
            }
        } catch (e) { console.error(e); }
    }
}
