import { Controller } from '@hotwired/stimulus';

const POLL_INTERVAL_MS = 4000;

export default class extends Controller {
    static targets = [
        'list', 'header', 'messages', 'composer',
        'input', 'file', 'sendBtn', 'attachBtn',
        'newDmModal', 'dmSearch', 'dmResults',
    ];

    connect() {
        this.currentConvId = null;
        this.pollTimer = null;
        if (window.TNSVT_USER) {
            this.loadConversations();
        } else {
            window.addEventListener('tnsvt:user-loaded', () => this.loadConversations(), { once: true });
        }
    }

    disconnect() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || '';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    async loadConversations() {
        const r = await window.apiFetch('/api/chat/conversations?user_code=' + this.me());
        if (!r.ok || !r.data) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 text-sm">Sin datos disponibles</p>';
            return;
        }
        const convs = Array.isArray(r.data) ? r.data : (r.data.conversations || []);
        if (convs.length === 0) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8 text-sm">Sin conversaciones</p>';
            return;
        }
        this.listTarget.innerHTML = convs.map((c) => {
            const initials = (c.other_user_name || c.title || '?').slice(0, 2).toUpperCase();
            const unread = c.unread_count > 0 ? `<span class="conv-unread">${c.unread_count}</span>` : '';
            return `
                <div class="conv-row" data-id="${c.id}">
                    <div class="conv-avatar">${this.escapeHtml(initials)}</div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center">
                            <span class="conv-name">${this.escapeHtml(c.other_user_name || c.title || 'Grupo')}</span>
                            <span class="conv-time">${this.escapeHtml(c.last_message?.created_at_display || c.created_at_display || '')}</span>
                        </div>
                        <div class="flex items-center">
                            <span class="conv-preview">${this.escapeHtml((c.last_message?.content || c.last_message?.has_photo) ? (c.last_message?.content || '📷 Foto') : 'Sin mensajes')}</span>
                            ${unread}
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        this.listTarget.querySelectorAll('.conv-row').forEach((row) => {
            row.addEventListener('click', () => this.selectConversation(parseInt(row.dataset.id, 10)));
        });
    }

    async selectConversation(convId) {
        this.currentConvId = convId;
        this.listTarget.querySelectorAll('.conv-row').forEach((r) => r.classList.remove('active'));
        this.listTarget.querySelector(`[data-id="${convId}"]`)?.classList.add('active');
        this.composerTarget.style.display = 'flex';
        await this.loadMessages(false);
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = setInterval(() => {
            if (this.currentConvId) this.loadMessages(true);
        }, POLL_INTERVAL_MS);
    }

    async loadMessages(silent) {
        if (!this.currentConvId) return;
        if (!silent) {
            this.messagesTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-12 text-sm">Cargando mensajes...</p>';
        }
        const r = await window.apiFetch(`/api/chat/conversations/${this.currentConvId}/messages?user_code=${this.me()}`);
        if (!r.ok || !r.data) {
            if (!silent) {
                this.messagesTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-12 text-sm">Sin mensajes</p>';
            }
            return;
        }
        const data = Array.isArray(r.data) ? { messages: r.data, conversation: {} } : r.data;
        const msgs = data.messages || [];
        const conv = data.conversation || {};
        this.headerTarget.innerHTML = `
            <div class="conv-avatar">${this.escapeHtml((conv.other_user_name || conv.title || '?').slice(0, 2).toUpperCase())}</div>
            <div class="flex-1">
                <div class="font-semibold text-sm">${this.escapeHtml(conv.other_user_name || conv.title || 'Conversación')}</div>
                <div class="text-xs text-[var(--outline-elev)]">${this.escapeHtml(conv.other_user_code || '')}</div>
            </div>
        `;

        if (msgs.length === 0) {
            this.messagesTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-12 text-sm">Sin mensajes. ¡Sé el primero en escribir!</p>';
            return;
        }

        this.messagesTarget.innerHTML = msgs.map((m) => {
            const own = m.sender_code === this.me();
            const initial = (m.sender_name || '?').slice(0, 1).toUpperCase();
            const photo = m.photo ? `<img src="${this.escapeHtml(m.photo)}" class="msg-photo" />` : '';
            const attachment = m.attachment && m.attachment.url
                ? `<div class="msg-content mt-2"><a href="${this.escapeHtml(m.attachment.url)}" target="_blank" class="underline">${this.escapeHtml(m.attachment.name || 'Adjunto')}</a></div>`
                : '';
            return `
                <div class="msg-row ${own ? 'own' : ''}">
                    <div class="msg-avatar">${this.escapeHtml(initial)}</div>
                    <div class="msg-bubble">
                        <div class="msg-content">${this.escapeHtml(m.content || '')}</div>
                        ${photo}
                        ${attachment}
                        <div class="msg-meta">${this.escapeHtml(m.created_at_display || '')}</div>
                    </div>
                </div>
            `;
        }).join('');

        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }

    async sendMessage() {
        const content = this.inputTarget.value.trim();
        if (!content && !this.fileTarget.files[0]) return;
        if (!this.currentConvId) return;

        let photoData = null;
        if (this.fileTarget.files[0]) {
            const reader = new FileReader();
            photoData = await new Promise((resolve) => {
                reader.onload = () => resolve(reader.result);
                reader.readAsDataURL(this.fileTarget.files[0]);
            });
        }

        const r = await window.apiFetch(`/api/chat/conversations/${this.currentConvId}/messages`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_code: this.me(),
                content: content || '',
                photo: photoData,
            }),
        });

        if (r.ok && r.data) {
            const ok = Array.isArray(r.data) ? r.data.length > 0 : (r.data.success !== false && r.data.id);
            if (ok) {
                this.inputTarget.value = '';
                this.fileTarget.value = '';
                if (window.apiToast) window.apiToast('Mensaje enviado', 'success');
                await this.loadMessages(false);
            } else {
                const msg = (r.data && r.data.error) || 'Error';
                if (window.apiToast) window.apiToast(msg, 'error');
            }
        } else {
            if (window.apiToast) window.apiToast('Error al enviar', 'error');
        }
    }

    async searchUsers(query) {
        if (query.length < 2) {
            this.dmResultsTarget.innerHTML = '';
            return;
        }
        const r = await window.apiFetch(`/api/chat/users?user_code=${this.me()}&q=${encodeURIComponent(query)}`);
        if (!r.ok || !r.data) {
            this.dmResultsTarget.innerHTML = '<p class="text-[var(--outline-elev)] text-sm">Sin resultados</p>';
            return;
        }
        let users = Array.isArray(r.data) ? r.data : (r.data.users || []);
        const q = query.toLowerCase();
        users = users.filter((u) => u.code !== this.me() && (
            (u.code || '').toLowerCase().includes(q) || (u.name || '').toLowerCase().includes(q)
        ));
        if (users.length === 0) {
            this.dmResultsTarget.innerHTML = '<p class="text-[var(--outline-elev)] text-sm">Sin resultados</p>';
            return;
        }
        this.dmResultsTarget.innerHTML = users.map((u) => `
            <div class="conv-row" data-code="${this.escapeHtml(u.code)}">
                <div class="conv-avatar">${this.escapeHtml((u.name || u.code).slice(0, 2).toUpperCase())}</div>
                <div class="flex-1">
                    <div class="conv-name">${this.escapeHtml(u.name)}</div>
                    <div class="conv-preview">${this.escapeHtml(u.code)}</div>
                </div>
            </div>
        `).join('');

        this.dmResultsTarget.querySelectorAll('.conv-row').forEach((row) => {
            row.addEventListener('click', () => this.startDM(row.dataset.code));
        });
    }

    async startDM(otherCode) {
        const r = await window.apiFetch('/api/chat/conversations?user_code=' + this.me(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_code: this.me(), other_code: otherCode }),
        });
        if (r.ok && r.data) {
            const conv = Array.isArray(r.data) ? r.data : (r.data.conversation || r.data);
            if (conv && conv.id) {
                if (window.apiToast) window.apiToast('Conversación creada', 'success');
                this.newDmModalTarget.style.display = 'none';
                await this.loadConversations();
                this.selectConversation(conv.id);
            }
        }
    }

    openNewDm(event) {
        if (event) event.preventDefault();
        this.newDmModalTarget.style.display = 'flex';
        this.dmSearchTarget.focus();
    }

    closeNewDm() {
        this.newDmModalTarget.style.display = 'none';
    }

    backdropClick(event) {
        if (event.target === this.newDmModalTarget) {
            this.closeNewDm();
        }
    }

    triggerFile(event) {
        if (event) event.preventDefault();
        this.fileTarget.click();
    }

    onInputKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.sendMessage();
        }
    }

    onSearchInput() {
        this.searchUsers(this.dmSearchTarget.value);
    }

    placeholderGroup() {
        if (window.apiToast) window.apiToast('Función de grupos próximamente', 'info');
    }
}