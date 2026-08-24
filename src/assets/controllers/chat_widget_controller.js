import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint B.3 — Chat Widget Controller.
 *
 * Widget de chat flotante para todas las páginas del Sanctum.
 * Muestra lista de conversaciones + mensajes sin necesidad de ir a /chat.
 *
 * Targets:
 *   - toggle       (botón flotante)
 *   - toggleIcon   (ícono del botón)
 *   - badge        (contador de no leídos)
 *   - panel        (panel del chat)
 *   - search       (input de búsqueda)
 *   - list         (lista de conversaciones)
 *   - convPanel    (panel de conversación activa)
 *   - convAvatar   (avatar de la conversación)
 *   - convName     (nombre de la conversación)
 *   - convStatus   (estado de la conversación)
 *   - messages     (lista de mensajes)
 *   - input        (textarea para escribir)
 *   - sendBtn      (botón enviar)
 *   - newDmModal   (modal de nuevo DM)
 *   - userSearch   (input buscar usuarios)
 *   - users        (lista de usuarios)
 *
 * Values:
 *   - open         (boolean: panel abierto o no)
 */
export default class extends Controller {
    static targets = [
        'toggle', 'toggleIcon', 'badge', 'panel',
        'search', 'list',
        'convPanel', 'convAvatar', 'convName', 'convStatus',
        'messages', 'input', 'sendBtn',
        'newDmModal', 'userSearch', 'users',
        'charCount', 'charCountValue', 'soundIcon',
    ];

    static values = {
        open: { type: Boolean, default: false },
        pollInterval: { type: Number, default: 15000 },
    };

    connect() {
        this.conversations = [];
        this.users = [];
        this.activeConv = null;
        this.activeTab = 'all';
        this.pollTimer = null;
        this._previouslyFocused = null;
        this._modalTrigger = null;
        this.userSearchTimer = null;
        this.lastMessageCount = 0;
        this.typingTimer = null;
        this.previousUnreadCount = 0;
        this.soundEnabled = this.getSoundPreference();
        this.searchTimer = null;

        // Auto-resize del textarea
        if (this.hasInputTarget) {
            this.inputTarget.addEventListener('input', () => this.onInputChange());
        }

        // Búsqueda de usuarios con debounce
        if (this.hasUserSearchTarget) {
            this.userSearchTarget.addEventListener('input', (e) => this.onUserSearchInput(e));
        }

        // TNSVT Sprint J.2 — Atajos de teclado globales
        this._globalKeyHandler = (e) => this.onGlobalKeydown(e);
        document.addEventListener('keydown', this._globalKeyHandler);

        // Cargar conversaciones iniciales
        this.loadConversations().then(() => {
            // Guardar el conteo inicial para detectar nuevos mensajes
            this.previousUnreadCount = this.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
        });
        this.updateUnreadBadge();

        // TNSVT Sprint J.3 — Actualizar icono de sonido según preferencia
        this.updateSoundIcon();
    }

        disconnect() {
        this.stopPolling();
        if (this.userSearchTimer) clearTimeout(this.userSearchTimer);
        if (this.typingTimer) clearTimeout(this.typingTimer);
        if (this.searchTimer) clearTimeout(this.searchTimer);
        if (this._globalKeyHandler) {
            document.removeEventListener('keydown', this._globalKeyHandler);
        }
    }

    /**
     * TNSVT Sprint J.2 — Manejador global de atajos de teclado.
     * Ctrl+K: Toggle panel de chat
     * Ctrl+M: Nuevo DM
     * Escape: Cerrar modal o volver a lista
     */
    onGlobalKeydown(event) {
        // Tab/Shift+Tab inside the new-DM modal: cycle within the modal.
        this._trapModalFocus(event);

        // Ctrl+K (Cmd+K en Mac): Toggle chat
        if ((event.ctrlKey || event.metaKey) && event.key === 'k' && !event.shiftKey) {
            event.preventDefault();
            this.toggle();
            return;
        }
        // Ctrl+M: Abrir modal de nuevo DM
        if ((event.ctrlKey || event.metaKey) && event.key === 'm' && !event.shiftKey) {
            event.preventDefault();
            if (this.openValue) this.openNewDm();
            return;
        }
        // Escape: Cerrar modal o volver a lista
        if (event.key === 'Escape') {
            // Si el modal de nuevo DM está abierto, cerrarlo
            if (this.hasNewDmModalTarget && !this.newDmModalTarget.classList.contains('hidden')) {
                event.preventDefault();
                this.closeNewDm();
                return;
            }
            // Si estamos en una conversación, volver a la lista
            if (this.activeConv) {
                event.preventDefault();
                this.backToList();
                return;
            }
        }
    }

    /**
     * TNSVT Sprint J.3 — Obtener preferencia de sonido del localStorage.
     */
    getSoundPreference() {
        try {
            const v = localStorage.getItem('tnsvt_chat_sound');
            // Default: true (activado)
            return v === null ? true : v === '1';
        } catch (e) {
            return false;
        }
    }

    /**
     * TNSVT Sprint J.3 — Toggle de sonido de notificación.
     */
    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        try {
            localStorage.setItem('tnsvt_chat_sound', this.soundEnabled ? '1' : '0');
        } catch (e) {
            // Silenciar
        }
        this.updateSoundIcon();
        if (window.apiToast) {
            window.apiToast(
                this.soundEnabled ? 'Sonido de notificación activado' : 'Sonido desactivado',
                'info'
            );
        }
    }

    /**
     * TNSVT Sprint J.3 — Actualizar el icono según el estado del sonido.
     */
    updateSoundIcon() {
        if (!this.hasSoundIconTarget) return;
        this.soundIconTarget.textContent = this.soundEnabled ? 'volume_up' : 'volume_off';
    }

    /**
     * TNSVT Sprint J.3 — Reproducir sonido de notificación cuando llega un mensaje.
     */
    playNotificationSound() {
        if (!this.soundEnabled) return;
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            oscillator.connect(gain);
            gain.connect(audioCtx.destination);
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            gain.gain.setValueAtTime(0, audioCtx.currentTime);
            gain.gain.linearRampToValueAtTime(0.1, audioCtx.currentTime + 0.05);
            gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.2);
            oscillator.start(audioCtx.currentTime);
            oscillator.stop(audioCtx.currentTime + 0.2);
        } catch (e) {
            // Silenciar si Web Audio no está disponible
        }
    }

    // TNSVT Sprint B.2 — Debounce en búsqueda + indicador typing
    onUserSearchInput(event) {
        const q = event.target.value.trim();
        if (this.userSearchTimer) clearTimeout(this.userSearchTimer);
        this.userSearchTimer = setTimeout(() => {
            this.loadUsers(q);
        }, 300);
    }

    /**
     * TNSVT Sprint K.3 — Búsqueda global de conversaciones.
     * Envía el query al servidor para filtrar conversaciones por nombre,
     * título o contenido del último mensaje.
     */
    search(event) {
        const q = event.target.value.trim();
        if (this.searchTimer) clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => {
            this.loadConversations(q);
        }, 300);
    }

    onInputChange() {
        this.autoResize();
        const body = this.inputTarget.value.trim();
        if (this.sendBtnTarget) {
            this.sendBtnTarget.disabled = body.length === 0;
        }
        // Indicador visual "escribiendo..."
        if (body.length > 5) {
            this.showTypingIndicator();
        }
        // TNSVT Sprint E.2 — Contador de caracteres
        this.updateCharCount();
    }

    updateCharCount() {
        if (!this.hasInputTarget || !this.hasCharCountTarget) return;
        const len = this.inputTarget.value.length;
        if (this.hasCharCountValueTarget) {
            this.charCountValueTarget.textContent = len;
        }
        // Mostrar contador solo cuando > 500 chars (warning) o > 1800 (danger)
        if (len === 0) {
            this.charCountTarget.hidden = true;
        } else if (len > 1500) {
            this.charCountTarget.hidden = false;
            this.charCountTarget.classList.add('char-count-danger');
            this.charCountTarget.classList.remove('char-count-warning');
        } else if (len > 800) {
            this.charCountTarget.hidden = false;
            this.charCountTarget.classList.add('char-count-warning');
            this.charCountTarget.classList.remove('char-count-danger');
        } else {
            this.charCountTarget.hidden = true;
            this.charCountTarget.classList.remove('char-count-warning', 'char-count-danger');
        }
    }

    showTypingIndicator() {
        if (!this.hasMessagesTarget) return;
        // Solo añadir una vez
        if (this.messagesTarget.querySelector('.chat-widget-typing')) return;
        const typingHtml = `
            <div class="chat-widget-typing">
                <div class="chat-widget-typing-bubble">
                    <span></span><span></span><span></span>
                </div>
                <span class="chat-widget-typing-label">Escribiendo...</span>
            </div>
        `;
        this.messagesTarget.insertAdjacentHTML('beforeend', typingHtml);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        if (this.typingTimer) clearTimeout(this.typingTimer);
        this.typingTimer = setTimeout(() => this.removeTypingIndicator(), 2000);
    }

    removeTypingIndicator() {
        if (!this.hasMessagesTarget) return;
        const el = this.messagesTarget.querySelector('.chat-widget-typing');
        if (el) el.remove();
    }

    // ══════ Toggle del panel ══════
    toggle() {
        if (this.openValue) {
            this.close();
        } else {
            // Remember which element opened the widget so we can restore focus.
            if (document.activeElement && document.activeElement !== document.body) {
                this._previouslyFocused = document.activeElement;
            }
            this.openPanel();
        }
    }

    openPanel() {
        this.openValue = true;
        this.panelTarget.classList.remove('hidden');
        this.toggleTarget.classList.add('active');
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        this.toggleIconTarget.textContent = 'close';
        // Refrescar al abrir
        this.loadConversations();
        this.startPolling();
        // Focus management: move focus into the panel for keyboard users.
        // Prefer the search field; fall back to the first conversation.
        this._moveFocusIntoPanel();
    }

    close() {
        this.openValue = false;
        this.panelTarget.classList.add('hidden');
        this.toggleTarget.classList.remove('active');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        this.toggleIconTarget.textContent = 'chat_bubble';
        this.stopPolling();
        this.backToList();
        // Restore focus to the toggle button (or whatever opened it).
        const restoreTo = this._previouslyFocused && document.contains(this._previouslyFocused)
            ? this._previouslyFocused
            : this.toggleTarget;
        restoreTo.focus();
    }

    _moveFocusIntoPanel() {
        // Wait for the panel to be visible before focusing.
        requestAnimationFrame(() => {
            if (this.hasSearchTarget) {
                this.searchTarget.focus();
                return;
            }
            const firstConv = this.panelTarget.querySelector('.chat-widget-item');
            if (firstConv) firstConv.focus();
        });
    }

    // Focus trap for the new-DM modal. Keeps Tab cycling inside the modal.
    _trapModalFocus(event) {
        if (!this.hasNewDmModalTarget) return;
        const modal = this.newDmModalTarget;
        if (modal.classList.contains('hidden')) return;
        if (event.key !== 'Tab') return;
        const focusables = modal.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    // ══════ Cargar conversaciones ══════
    async loadConversations(searchQuery = '') {
        if (!window.apiFetch) return;
        try {
            let url = '/api/chat/conversations?user_code=' + this.getUserCode();
            if (searchQuery) {
                url += '&q=' + encodeURIComponent(searchQuery);
            }
            const r = await window.apiFetch(url);
            let conversations = [];
            if (r.ok && r.data) {
                if (r.data.conversations) {
                    conversations = r.data.conversations;
                } else if (Array.isArray(r.data)) {
                    conversations = r.data;
                }
            }
            this.conversations = conversations;
            this.renderList();
            this.updateUnreadBadge();
        } catch (e) {
            console.error('[chat-widget] load error', e);
        }
    }

    renderList() {
        if (!this.hasListTarget) return;
        const filtered = this.filterConversations();
        if (filtered.length === 0) {
            this.listTarget.innerHTML = `
                <div class="chat-widget-empty">
                    <span class="material-symbols-elev">forum</span>
                    <p>${this.activeTab === 'unread' ? 'Sin mensajes no leídos' : (this.activeTab === 'groups' ? 'Sin grupos activos' : 'Sin conversaciones todavía')}</p>
                </div>
            `;
            return;
        }
        this.listTarget.innerHTML = filtered.map(conv => this.renderConvItem(conv)).join('');
        // Wire clicks
        this.listTarget.querySelectorAll('.chat-widget-item').forEach(el => {
            el.addEventListener('click', () => this.openConv(parseInt(el.dataset.id, 10)));
        });
    }

    renderConvItem(conv) {
        const lastMsg = conv.last_message || {};
        const initials = (conv.title || conv.other_user_name || '?').slice(0, 2).toUpperCase();
        const isUnread = (conv.unread_count || 0) > 0;
        const preview = lastMsg.body ? this.escapeHtml(lastMsg.body.slice(0, 50)) : '<em>Sin mensajes</em>';
        const time = lastMsg.created_at ? this.relativeTime(lastMsg.created_at) : '';
        const avatarBg = conv.other_user_color || 'var(--violet-elev)';
        return `
            <div class="chat-widget-item ${isUnread ? 'unread' : ''}" data-id="${conv.id}">
                <div class="chat-widget-item-avatar" style="background: ${avatarBg};">${this.escapeHtml(initials)}</div>
                <div class="chat-widget-item-body">
                    <div class="chat-widget-item-head">
                        <span class="chat-widget-item-name">${this.escapeHtml(conv.title || conv.other_user_name || 'Conversación')}</span>
                        <span class="chat-widget-item-time">${time}</span>
                    </div>
                    <div class="chat-widget-item-preview">${preview}</div>
                </div>
                ${isUnread ? `<span class="chat-widget-item-badge">${conv.unread_count}</span>` : ''}
            </div>
        `;
    }

    // TNSVT Sprint K.3 — Filtrado ahora es solo por tabs (busqueda server-side)
    filterConversations() {
        let convs = this.conversations;
        if (this.activeTab === 'unread') {
            convs = convs.filter(c => (c.unread_count || 0) > 0);
        } else if (this.activeTab === 'groups') {
            convs = convs.filter(c => c.is_group);
        }
        return convs;
    }

    // ══════ Abrir conversación ══════
    async openConv(convId) {
        this.activeConv = this.conversations.find(c => c.id === convId);
        if (!this.activeConv) return;
        this.convPanelTarget.classList.remove('hidden');
        this.listTarget.parentElement.classList.add('hidden');
        const initials = (this.activeConv.title || this.activeConv.other_user_name || '?').slice(0, 2).toUpperCase();
        this.convAvatarTarget.textContent = initials;
        this.convNameTarget.textContent = this.activeConv.title || this.activeConv.other_user_name || 'Conversación';
        this.convStatusTarget.textContent = this.activeConv.is_group ? 'Grupo' : 'en línea';
        await this.loadMessages(convId);
        this.markRead(convId);
    }

    backToList() {
        if (this.hasConvPanelTarget) {
            this.convPanelTarget.classList.add('hidden');
            this.listTarget.parentElement.classList.remove('hidden');
        }
        this.activeConv = null;
    }

    // ══════ Cargar mensajes ══════
    async loadMessages(convId) {
        try {
            const r = await window.apiFetch(`/api/chat/conversations/${convId}/messages?user_code=${this.getUserCode()}`);
            if (r.ok && r.data && r.data.success) {
                this.renderMessages(r.data.messages || []);
                // Scroll al final
                setTimeout(() => {
                    if (this.hasMessagesTarget) {
                        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
                    }
                }, 50);
            }
        } catch (e) {
            console.error('[chat-widget] load messages error', e);
        }
    }

    renderMessages(msgs) {
        if (!this.hasMessagesTarget) return;
        // Limpiar typing indicator al renderizar mensajes
        this.removeTypingIndicator();
        if (msgs.length === 0) {
            this.messagesTarget.innerHTML = `
                <div class="chat-widget-empty">
                    <span class="material-symbols-elev">chat</span>
                    <p>Sin mensajes. ¡Sé el primero en escribir!</p>
                </div>
            `;
            return;
        }
        const me = window.TNSVT_USER?.code;
        this.messagesTarget.innerHTML = msgs.map(m => {
            const isMe = m.sender_code === me;
            const initials = (m.sender_name || '?').slice(0, 2).toUpperCase();
            const avatarBg = m.sender_color || 'var(--gold-elev)';
            return `
                <div class="chat-widget-msg ${isMe ? 'me' : 'them'}">
                    ${!isMe ? `<div class="chat-widget-msg-avatar" style="background: ${avatarBg};">${this.escapeHtml(initials)}</div>` : ''}
                    <div class="chat-widget-msg-body">
                        ${!isMe ? `<div class="chat-widget-msg-name">${this.escapeHtml(m.sender_name || '')}</div>` : ''}
                        <div class="chat-widget-msg-text">${this.escapeHtml(m.body || '')}</div>
                        <div class="chat-widget-msg-time">${this.relativeTime(m.created_at)}</div>
                    </div>
                </div>
            `;
        }).join('');
        // Scroll al final
        setTimeout(() => {
            if (this.hasMessagesTarget) {
                this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
            }
        }, 50);
    }

    // ══════ Enviar mensaje ══════
    async send() {
        if (!this.activeConv) return;
        const body = this.inputTarget.value.trim();
        if (!body) return;
        this.sendBtnTarget.disabled = true;
        try {
            const r = await window.apiFetch(`/api/chat/conversations/${this.activeConv.id}/messages`, {
                method: 'POST',
                body: JSON.stringify({
                    user_code: this.getUserCode(),
                    body: body,
                }),
            });
            if (r.ok && r.data && r.data.success) {
                this.inputTarget.value = '';
                this.autoResize();
                this.loadMessages(this.activeConv.id);
                this.loadConversations();
            } else {
                const msg = (r.data && r.data.error) || 'Error al enviar';
                if (window.apiToast) window.apiToast(msg, 'error');
            }
        } catch (e) {
            console.error('[chat-widget] send error', e);
        }
        this.sendBtnTarget.disabled = false;
    }

    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.send();
        }
    }

    autoResize() {
        this.inputTarget.style.height = 'auto';
        this.inputTarget.style.height = Math.min(this.inputTarget.scrollHeight, 100) + 'px';
    }

    // ══════ Marcar como leído ══════
    async markRead(convId) {
        try {
            await window.apiFetch(`/api/chat/conversations/${convId}/read`, {
                method: 'POST',
                body: JSON.stringify({ user_code: this.getUserCode() }),
            });
        } catch (e) {}
    }

    // �═════ Nuevo DM ══════
    openNewDm() {
        // Remember which control opened the modal so we can restore focus.
        this._modalTrigger = document.activeElement;
        this.newDmModalTarget.classList.remove('hidden');
        this.loadUsers('');
        setTimeout(() => this.userSearchTarget.focus(), 100);
    }

    closeNewDm() {
        this.newDmModalTarget.classList.add('hidden');
        // Restore focus to the control that opened the modal.
        const restoreTo = this._modalTrigger && document.contains(this._modalTrigger)
            ? this._modalTrigger
            : this.toggleTarget;
        restoreTo.focus();
        this._modalTrigger = null;
    }

    async loadUsers(q) {
        try {
            const r = await window.apiFetch('/api/chat/users?user_code=' + this.getUserCode() + (q ? '&q=' + encodeURIComponent(q) : ''));
            if (r.ok && r.data && r.data.success) {
                this.users = r.data.users || [];
                this.renderUsers();
            }
        } catch (e) {
            console.error('[chat-widget] load users error', e);
        }
    }

    searchUsers(event) {
        this.loadUsers(event.target.value);
    }

    renderUsers() {
        if (this.users.length === 0) {
            this.usersTarget.innerHTML = '<p class="chat-widget-empty-mini">Sin usuarios encontrados</p>';
            return;
        }
        this.usersTarget.innerHTML = this.users.map(u => {
            const initials = (u.name || u.code || '?').slice(0, 2).toUpperCase();
            return `
                <div class="chat-widget-user" data-code="${u.code}">
                    <div class="chat-widget-user-avatar" style="background: ${u.color || 'var(--violet-elev)'};">${this.escapeHtml(initials)}</div>
                    <div class="chat-widget-user-info">
                        <div class="chat-widget-user-name">${this.escapeHtml(u.name)}</div>
                        <div class="chat-widget-user-code">${this.escapeHtml(u.code)}</div>
                    </div>
                </div>
            `;
        }).join('');
        this.usersTarget.querySelectorAll('.chat-widget-user').forEach(el => {
            el.addEventListener('click', () => this.startDmWith(el.dataset.code));
        });
    }

    async startDmWith(userCode) {
        try {
            const r = await window.apiFetch('/api/chat/conversations', {
                method: 'POST',
                body: JSON.stringify({
                    user_code: this.getUserCode(),
                    other_user_code: userCode,
                }),
            });
            if (r.ok && r.data && r.data.success) {
                this.closeNewDm();
                await this.loadConversations();
                this.openConv(r.data.conversation.id);
            }
        } catch (e) {
            console.error('[chat-widget] start DM error', e);
        }
    }

    // ══════ Tabs ══════
    switchTab(event) {
        this.activeTab = event.currentTarget.dataset.tab;
        this.element.querySelectorAll('.chat-widget-tab').forEach(t =>
            t.classList.toggle('active', t.dataset.tab === this.activeTab)
        );
        this.renderList();
    }

    // ══════ Búsqueda ══════
    search(event) {
        this.renderList();
    }

    // ══════ Refrescar ══════
    refresh() {
        this.loadConversations();
        if (this.activeConv) {
            this.loadMessages(this.activeConv.id);
        }
    }

    // ══════ Polling ══════
    startPolling() {
        this.stopPolling();
        this.pollTimer = setInterval(() => {
            // TNSVT Sprint J.3 — Detectar nuevos mensajes para reproducir sonido
            const beforeCount = this.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
            this.loadConversations().then(() => {
                const afterCount = this.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
                if (afterCount > beforeCount && !this.openValue) {
                    // Hay mensajes nuevos y el panel está cerrado → reproducir sonido
                    this.playNotificationSound();
                }
                if (this.activeConv) this.loadMessages(this.activeConv.id);
            });
        }, this.pollIntervalValue);
    }

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    // ══════ Unread badge ══════
    updateUnreadBadge() {
        const total = this.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
        if (this.hasBadgeTarget) {
            if (total > 0) {
                this.badgeTarget.textContent = total > 99 ? '99+' : total;
                this.badgeTarget.classList.remove('hidden');
            } else {
                this.badgeTarget.classList.add('hidden');
            }
        }
    }

    // �═════ Helpers ══════
    getUserCode() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || 'DEMO';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    relativeTime(iso) {
        if (!iso) return '';
        try {
            const d = new Date(iso);
            const now = new Date();
            const diff = (now - d) / 1000;
            if (diff < 60) return 'ahora';
            if (diff < 3600) return Math.floor(diff / 60) + 'm';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd';
            return d.toLocaleDateString();
        } catch (e) {
            return '';
        }
    }
}
