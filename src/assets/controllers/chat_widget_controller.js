import { Controller } from '@hotwired/stimulus';

/**
 * chat-widget — Flotante global. Funcional: realtime (Mercure SSE),
 * typing real, optimistic UI, retry offline, badges siempre vivos.
 */
export default class extends Controller {
  static targets = [
    'toggle', 'toggleIcon', 'badge', 'panel', 'bubbles', 'search', 'list',
    'convPanel', 'convAvatar', 'convName', 'convStatus', 'messages',
    'input', 'sendBtn', 'newDmModal', 'userSearch', 'users',
    'charCount', 'charCountValue', 'soundIcon',
    'typingIndicator', 'unreadPill', 'scrollToBottom',
    'fileInput', 'composerEl',
  ];
  static values = {
    open: { type: Boolean, default: false },
    pollInterval: { type: Number, default: 15000 },
  };

  connect() {
    this.activeConvId = null;
    this.lastMessageId = 0;
    this.pollTimer = null;
    this.badgePoll = null;
    this.mercure = null;          // EventSource for /chat/{id}
    this.typingEventSource = null; // EventSource for /chat/{id}/typing
    this.typingMap = {};          // {code: timestamp}
    this.typingDebounce = null;
    this.knownUserCode = null;
    this.reconnectAttempts = 0;

    if (window.TNSVT_USER?.code) {
      this.knownUserCode = window.TNSVT_USER.code;
      this.startBadgePoll();
    }
    window.addEventListener('tnsvt:user-loaded', (e) => {
      if (e.detail?.code && !this.knownUserCode) {
        this.knownUserCode = e.detail.code;
        this.startBadgePoll();
      }
    });
    window.addEventListener('online', () => this.flushOutbox());
  }

  disconnect() {
    this.stopPoll();
    this.stopBadgePoll();
    this.closeMercure();
    this.closeTypingEventSource();
    clearTimeout(this.typingDebounce);
  }

  // ─── Badge always live (panel cerrado) ─────────────────────
  startBadgePoll() {
    this.stopBadgePoll();
    this.badgePoll = setInterval(() => this.loadBadge(), this.pollIntervalValue);
    this.loadBadge();
  }
  stopBadgePoll() {
    if (this.badgePoll) clearInterval(this.badgePoll);
    this.badgePoll = null;
  }
  async loadBadge() {
    if (!this.knownUserCode) return;
    const r = await window.apiFetch(`/api/chat/conversations?user_code=${this.knownUserCode}`, { silent: true });
    if (!r.ok || !r.data) return;
    const total = (r.data.users || []).reduce((s, c) => s + (c.unread_count || 0), 0);
    this.updateUnreadBadge(total);
  }
  updateUnreadBadge(n) {
    const label = n > 99 ? '99+' : String(n);
    [this.badgeTarget, ...this.bubbleBadgeTargets()].forEach((el) => {
      if (!el) return;
      el.textContent = label;
      el.classList.toggle('hidden', n === 0);
    });
  }
  bubbleBadgeTargets() {
    return Array.from(this.element.querySelectorAll('[data-chat-widget-bubble-badge]'));
  }

  // ─── Open / close ─────────────────────────────────────────
  toggle() { this.openValue ? this.close() : this.openPanel(); }

  async openPanel() {
    this.openValue = true;
    this.panelTarget.classList.remove('hidden');
    this.panelTarget.style.display = 'flex';
    this.panelTarget.classList.add('is-open');
    this.toggleIconTarget.textContent = 'close';
    this._moveFocusIntoPanel();
    await this.loadConversations();
    this.startPoll();
  }

  close() {
    this.openValue = false;
    this.panelTarget.classList.add('hidden');
    this.panelTarget.style.display = 'none';
    this.panelTarget.classList.remove('is-open');
    this.toggleIconTarget.textContent = 'chat_bubble';
    this.stopPoll();
    this.backToList();
    this.closeMercure();
    this.closeTypingEventSource();
  }

  _moveFocusIntoPanel() {
    setTimeout(() => this.searchTarget?.focus(), 50);
  }

  // ─── Conversaciones ────────────────────────────────────────
  async loadConversations(q = '') {
    if (!this.knownUserCode) return;
    const url = `/api/chat/conversations?user_code=${this.knownUserCode}` + (q ? `&q=${encodeURIComponent(q)}` : '');
    const r = await window.apiFetch(url, { silent: true });
    if (!r.ok || !r.data) return;
    this.conversations = r.data.users || [];
    this.totalUnread = this.conversations.reduce((s, c) => s + (c.unread_count || 0), 0);
    this.updateUnreadBadge(this.totalUnread);
    this.renderList(this.conversations, this.currentFilter || 'all');
  }

  currentFilter = 'all';
  switchTab(e) {
    this.currentFilter = e.currentTarget.dataset.tab;
    this.tabBtnTargets?.forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === this.currentFilter)));
    this.renderList(this.conversations || [], this.currentFilter);
  }

  onSearch() {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.loadConversations(this.searchTarget.value), 300);
  }

  renderList(items, filter) {
    const filtered = items.filter((c) => {
      if (filter === 'unread') return (c.unread_count || 0) > 0;
      if (filter === 'groups') return c.is_group;
      return true;
    });
    this.listTarget.innerHTML = filtered.map((c) => `
      <button type="button" class="chat-widget-conv" data-conv-id="${c.id}">
        <div class="chat-widget-conv-avatar">${this.esc((c.other_user_name || c.title || '?').slice(0, 1).toUpperCase())}</div>
        <div class="chat-widget-conv-meta">
          <div class="chat-widget-conv-row">
            <span class="chat-widget-conv-name">${this.esc(c.other_user_name || c.title || 'Conversación')}</span>
            <span class="chat-widget-conv-time">${this.relativeTime(c.last_message_at)}</span>
          </div>
          <div class="chat-widget-conv-row">
            <span class="chat-widget-conv-preview">${this.esc(c.last_message_preview || '').slice(0, 50)}</span>
            ${c.unread_count ? `<span class="chat-widget-conv-badge">${c.unread_count > 99 ? '99+' : c.unread_count}</span>` : ''}
          </div>
        </div>
      </button>`).join('');
    this.listTarget.querySelectorAll('.chat-widget-conv').forEach((btn) => {
      btn.addEventListener('click', () => this.openConv(parseInt(btn.dataset.convId, 10)));
    });
    if (!filtered.length) {
      this.listTarget.innerHTML = '<p class="text-xs opacity-70 p-3">Sin conversaciones.</p>';
    }
  }

  async openConv(id) {
    this.activeConvId = id;
    this.lastMessageId = 0;
    this.convPanelTarget.classList.remove('hidden');
    this.convPanelTarget.classList.add('is-open');
    const conv = (this.conversations || []).find((c) => c.id === id);
    this.convNameTarget.textContent = conv?.other_user_name || conv?.title || 'Conversación';
    this.convAvatarTarget.textContent = (conv?.other_user_name || conv?.title || '?').slice(0, 1).toUpperCase();
    this.convStatusTarget.textContent = conv?.online ? 'En línea' : 'Desconectado';
    await this.loadMessages();
    await this.markRead(id);
    this.openMercure(id);
    this.openTypingEventSource(id);
  }

  backToList() {
    this.activeConvId = null;
    this.lastMessageId = 0;
    this.convPanelTarget?.classList.add('hidden');
    this.convPanelTarget?.classList.remove('is-open');
    this.closeMercure();
    this.closeTypingEventSource();
    this.clearTypingIndicator();
  }

  // ─── Mensajes con delta polling + realtime ─────────────────
  async loadMessages() {
    if (!this.activeConvId || !this.knownUserCode) return;
    const url = `/api/chat/conversations/${this.activeConvId}/messages?user_code=${this.knownUserCode}`;
    const r = await window.apiFetch(url, { silent: true });
    if (!r.ok || !r.data) return;
    const msgs = r.data || [];
    if (!msgs.length) return;
    this.messagesTarget.innerHTML = msgs.map((m) => this.renderMessage(m)).join('');
    if (msgs.length) this.lastMessageId = msgs[msgs.length - 1].id;
    this._smartScrollToBottom();
  }

  // ─── Optimistic UI + retry offline ────────────────────────
  outbox = [];
  async send() {
    const content = (this.inputTarget.value || '').trim();
    if (!content || !this.activeConvId) return;
    const tempId = `temp-${Date.now()}`;
    const optimistic = {
      id: tempId,
      conversation_id: this.activeConvId,
      content,
      sender_code: this.knownUserCode,
      created_at: new Date().toISOString(),
      pending: true,
    };
    this.messagesTarget.insertAdjacentHTML('beforeend', this.renderMessage(optimistic));
    this.inputTarget.value = '';
    this.autoResize();
    this._smartScrollToBottom();

    if (!navigator.onLine) {
      this.outbox.push({ tempId, content, convId: this.activeConvId });
      this.showBanner('Sin conexión — mensaje en cola', 'info');
      return;
    }
    await this._postMessage(tempId, content, this.activeConvId);
  }

  async _postMessage(tempId, content, convId) {
    const r = await window.apiFetch(`/api/chat/conversations/${convId}/messages`, {
      method: 'POST',
      body: { user_code: this.knownUserCode, content },
    });
    const node = this.messagesTarget.querySelector(`[data-msg-temp="${tempId}"]`);
    if (r.ok && r.data) {
      if (node) node.outerHTML = this.renderMessage(r.data);
      this.lastMessageId = Math.max(this.lastMessageId, r.data.id);
    } else if (node) {
      node.classList.add('chat-widget-msg-failed');
      node.title = 'Envío falló. Click para reintentar.';
      node.addEventListener('click', () => this._postMessage(tempId, content, convId), { once: true });
    }
  }

  flushOutbox() {
    if (!this.outbox.length || !navigator.onLine) return;
    const copy = this.outbox.slice();
    this.outbox = [];
    copy.forEach((m) => this._postMessage(m.tempId, m.content, m.convId));
  }

  // ─── Realtime Mercure ─────────────────────────────────────
  async openMercure(convId) {
    this.closeMercure();
    const tokenR = await window.apiFetch('/api/mercure/subscribe-token', {
      method: 'POST',
      body: { topics: [`/chat/${convId}`] },
    });
    if (!tokenR.ok || !tokenR.data?.token) return;
    const url = `${this._mercureHubUrl()}?topic=${encodeURIComponent(`/chat/${convId}`)}&access_token=${encodeURIComponent(tokenR.data.token)}`;
    this.mercure = new EventSource(url);
    this.mercure.onmessage = (e) => this._handleMercureEvent(e);
    this.mercure.onerror = () => this._scheduleMercureReconnect(convId);
  }
  closeMercure() {
    if (this.mercure) { this.mercure.close(); this.mercure = null; }
  }
  _handleMercureEvent(e) {
    try {
      const data = JSON.parse(e.data);
      if (data.event !== 'message' || !data.message) return;
      // Skip if we already have it (race with polling or own optimistic).
      if (data.message.id <= this.lastMessageId) return;
      if (this.messagesTarget.querySelector(`[data-msg-id="${data.message.id}"]`)) return;
      this.messagesTarget.insertAdjacentHTML('beforeend', this.renderMessage(data.message));
      this.lastMessageId = Math.max(this.lastMessageId, data.message.id);
      this._smartScrollToBottom();
      if (data.message.sender_code !== this.knownUserCode) this.markRead(this.activeConvId);
    } catch {}
  }
  _scheduleMercureReconnect(convId) {
    this.closeMercure();
    if (!this.activeConvId || this.activeConvId !== convId) return;
    const delay = Math.min(30000, 1000 * Math.pow(2, this.reconnectAttempts++));
    setTimeout(() => this.openMercure(convId), delay);
  }
  _mercureHubUrl() {
    // Mirrors api_helper's MercureURL convention if present, falls back to same-origin /mercure.
    return window.MERCURE_URL || `${window.location.origin}/.well-known/mercure`;
  }

  // ─── Typing real ──────────────────────────────────────────
  async openTypingEventSource(convId) {
    this.closeTypingEventSource();
    const tokenR = await window.apiFetch('/api/mercure/subscribe-token', {
      method: 'POST',
      body: { topics: [`/chat/${convId}/typing`] },
    });
    if (!tokenR.ok || !tokenR.data?.token) return;
    const url = `${this._mercureHubUrl()}?topic=${encodeURIComponent(`/chat/${convId}/typing`)}&access_token=${encodeURIComponent(tokenR.data.token)}`;
    this.typingEventSource = new EventSource(url);
    this.typingEventSource.onmessage = (e) => {
      try {
        const data = JSON.parse(e.data);
        if (data.sender_code === this.knownUserCode) return;
        this.typingMap[data.sender_code] = Date.now();
        this.renderTypingIndicator();
      } catch {}
    };
  }
  closeTypingEventSource() {
    if (this.typingEventSource) { this.typingEventSource.close(); this.typingEventSource = null; }
    this.typingMap = {};
  }
  onInputChange() {
    this.autoResize();
    if (!this.activeConvId) return;
    clearTimeout(this.typingDebounce);
    this.typingDebounce = setTimeout(() => {
      window.apiFetch('/api/chat/typing', {
        method: 'POST',
        body: { user_code: this.knownUserCode, conversation_id: this.activeConvId },
      }).catch(() => {});
    }, 300);
  }
  renderTypingIndicator() {
    const now = Date.now();
    // Drop stale (>3s) entries.
    Object.keys(this.typingMap).forEach((code) => {
      if (now - this.typingMap[code] > 3000) delete this.typingMap[code];
    });
    const names = Object.keys(this.typingMap);
    if (!names.length || !this.hasTypingIndicatorTarget) {
      this.clearTypingIndicator();
      return;
    }
    this.typingIndicatorTarget.textContent = names.length === 1
      ? `${names[0]} está escribiendo…`
      : `${names.length} personas están escribiendo…`;
    this.typingIndicatorTarget.classList.remove('hidden');
  }
  clearTypingIndicator() {
    if (this.hasTypingIndicatorTarget) {
      this.typingIndicatorTarget.textContent = '';
      this.typingIndicatorTarget.classList.add('hidden');
    }
  }

  // ─── Polling (ciclo de vida del panel abierto) ────────────
  startPoll() {
    this.stopPoll();
    this.pollTimer = setInterval(async () => {
      await this.loadConversations();
      if (this.activeConvId) {
        const url = `/api/chat/conversations/${this.activeConvId}/messages?user_code=${this.knownUserCode}&after_id=${this.lastMessageId}`;
        const r = await window.apiFetch(url, { silent: true });
        if (r.ok && r.data && r.data.length) {
          const newMsgs = r.data.filter((m) => !this.messagesTarget.querySelector(`[data-msg-id="${m.id}"]`));
          if (newMsgs.length) {
            this.messagesTarget.insertAdjacentHTML('beforeend', newMsgs.map((m) => this.renderMessage(m)).join(''));
            this.lastMessageId = Math.max(this.lastMessageId, ...newMsgs.map((m) => m.id));
            this._smartScrollToBottom();
          }
        }
      }
    }, this.pollIntervalValue);
  }
  stopPoll() {
    if (this.pollTimer) clearInterval(this.pollTimer);
    this.pollTimer = null;
  }

  async markRead(convId) {
    await window.apiFetch(`/api/chat/conversations/${convId}/read`, {
      method: 'POST',
      body: { user_code: this.knownUserCode },
    }).catch(() => {});
    this.loadBadge();
  }

  // ─── Render message + attachment firmado ──────────────────
  renderMessage(m) {
    const isOwn = m.sender_code === this.knownUserCode;
    const tempAttr = m.pending ? ` data-msg-temp="${m.id}" class="chat-widget-msg-pending"` : ` data-msg-id="${m.id}"`;
    const content = m.pending ? this.esc(m.content) + '<small class="chat-widget-msg-pending-mark">⏱</small>' : this.esc(m.content || '');
    const attachment = m.attachment ? this.renderAttachment(m.attachment, isOwn) : '';
    return `<article${tempAttr}>
      <div class="chat-widget-msg-bubble ${isOwn ? 'is-own' : 'is-other'}">
        ${content ? `<div class="chat-widget-msg-content">${content}</div>` : ''}
        ${attachment}
        <time class="chat-widget-msg-time">${this.relativeTime(m.created_at)}</time>
      </div>
    </article>`;
  }
  renderAttachment(att) {
    if (!att || !att.url) return '';
    const mime = att.mime || '';
    const isImg = mime.startsWith('image/');
    const isVid = mime.startsWith('video/');
    const isAud = mime.startsWith('audio/');
    const label = this.esc(att.name || 'archivo');
    // The server returns signed_url only via /attachment/sign; the widget
    // asks for it lazily when the user clicks the file (avoid storing tokens
    // we may never use). Inline media uses a one-shot signed URL too.
    const inline = isImg || isVid || isAud;
    const dataAttr = `data-attachment-url="${this.esc(att.url)}" data-attachment-name="${label}" data-attachment-mime="${this.esc(mime)}" data-attachment-inline="${inline}"`;
    const inner = inline
      ? (isImg ? `<img class="chat-widget-att-preview" alt="${label}" data-signed-src="${this.esc(att.url)}">`
              : isVid ? `<video controls class="chat-widget-att-preview" data-signed-src="${this.esc(att.url)}"></video>`
              : `<audio controls class="chat-widget-att-preview" data-signed-src="${this.esc(att.url)}"></audio>`)
      : `<span class="material-symbols-elev">description</span> ${label}`;
    return `<a href="#" class="chat-widget-att" ${dataAttr}>${inner}</a>`;
  }

  // ─── Composer + keydown ───────────────────────────────────
  keydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      this.send();
    } else if (e.key === 'Escape') {
      this.backToList();
    }
  }
  autoResize() {
    const el = this.inputTarget;
    el.style.height = 'auto';
    el.style.height = Math.min(160, el.scrollHeight) + 'px';
    const n = el.value.length;
    if (this.hasCharCountTarget) {
      this.charCountTarget.classList.toggle('hidden', n < 800);
      this.charCountTarget.classList.toggle('is-warning', n > 800 && n <= 1500);
      this.charCountTarget.classList.toggle('is-danger', n > 1500);
      if (this.hasCharCountValueTarget) this.charCountValueTarget.textContent = String(n);
    }
  }

  // ─── Scroll inteligente ───────────────────────────────────
  _smartScrollToBottom() {
    const m = this.messagesTarget;
    if (!m) return;
    const distanceToBottom = m.scrollHeight - m.scrollTop - m.clientHeight;
    if (distanceToBottom < 120) {
      m.scrollTop = m.scrollHeight;
    } else if (this.hasScrollToBottomTarget) {
      const pending = this.messagesTarget.querySelectorAll('[data-msg-id]:not([data-msg-temp])').length - this._lastSeenCount;
      if (pending > 0) this.scrollToBottomTarget.classList.remove('hidden');
    }
    this._lastSeenCount = this.messagesTarget.querySelectorAll('[data-msg-id]').length;
  }

  scrollToBottomClicked() {
    this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    if (this.hasScrollToBottomTarget) this.scrollToBottomTarget.classList.add('hidden');
  }

  // ─── Reacciones + editar + eliminar ────────────────────────
  react(emoji, msgId) {
    return window.apiFetch(`/api/chat/conversations/${this.activeConvId}/messages/${msgId}/react`, {
      method: 'POST',
      body: { user_code: this.knownUserCode, emoji },
    }).then(() => this.loadMessages());
  }
  editMessage(msgId) {
    const node = this.messagesTarget.querySelector(`[data-msg-id="${msgId}"] .chat-widget-msg-content`);
    if (!node) return;
    const next = prompt('Editar mensaje:', node.textContent || '');
    if (next === null || next.trim() === '') return;
    window.apiFetch(`/api/chat/conversations/${this.activeConvId}/messages/${msgId}`, {
      method: 'PUT',
      body: { user_code: this.knownUserCode, content: next.trim() },
    }).then(() => this.loadMessages());
  }
  deleteMessage(msgId) {
    if (!confirm('¿Eliminar mensaje?')) return;
    window.apiFetch(`/api/chat/conversations/${this.activeConvId}/messages/${msgId}`, {
      method: 'DELETE',
      body: { user_code: this.knownUserCode },
    }).then(() => this.loadMessages());
  }

  // ─── New DM modal ─────────────────────────────────────────
  showNewDm() {
    if (!this.hasNewDmModalTarget) return;
    this.newDmModalTarget.classList.remove('hidden');
    this.userSearchTarget.focus();
  }
  hideNewDm() {
    this.newDmModalTarget.classList.add('hidden');
  }
  onUserSearchInput() {
    clearTimeout(this._uTimer);
    this._uTimer = setTimeout(() => this.loadUsers(), 200);
  }
  async loadUsers() {
    // Backend returns a bare JSON array (not {users: [...]}); accept both.
    if (!this.knownUserCode) return;
    const r = await window.apiFetch(`/api/chat/users?user_code=${this.knownUserCode}&q=${encodeURIComponent(this.userSearchTarget.value || '')}`, { silent: true });
    if (!r.ok || !r.data) return;
    const users = Array.isArray(r.data) ? r.data : (r.data.users || []);
    this.usersTarget.innerHTML = users
      .filter((u) => u.code !== this.knownUserCode)
      .map((u) => `<button class="chat-widget-user-item" data-user-code="${this.esc(u.code)}"><span class="chat-widget-user-name">${this.esc(u.name)}</span><span class="chat-widget-user-meta">${this.esc(u.code)} · ${u.online ? '🟢' : 'off'}</span></button>`)
      .join('');
    this.usersTarget.querySelectorAll('.chat-widget-user-item').forEach((b) => {
      b.addEventListener('click', () => this.startDmWith(b.dataset.userCode));
    });
  }
  async startDmWith(code) {
    const r = await window.apiFetch('/api/chat/conversations', {
      method: 'POST',
      body: { user_code: this.knownUserCode, other_code: code },
    });
    // Backend answers 201 with the serialized conversation ({id, ...}),
    // not nested under `conversation`. Accept both shapes.
    const convId = r?.data?.id ?? r?.data?.conversation?.id ?? r?.data?.conversation_id;
    if (r.ok && convId) {
      this.hideNewDm();
      await this.loadConversations();
      this.openConv(convId);
    } else if (window.apiToast) {
      window.apiToast(r?.data?.error || 'No se pudo crear la conversación', 'error');
    }
  }

  // ─── Adjuntos: drag/drop + signed URLs ────────────────────
  composerKeydown(e) {
    // Composer keydown already bound in template; keep this as a no-op alias
    // in case the template wires it without the main handler.
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
  }
  pickAttachment() { this.fileInputTarget?.click(); }
  onAttachmentChosen(e) {
    const file = e.target.files?.[0];
    if (file) this.uploadAttachment(file);
    e.target.value = '';
  }
  async uploadAttachment(file) {
    if (!this.activeConvId) return;
    const fd = new FormData();
    fd.append('user_code', this.knownUserCode);
    fd.append('file', file);
    const r = await fetch(`/api/chat/upload?user_code=${this.knownUserCode}`, { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!r.ok) { this.showBanner('Adjunto falló', 'error'); return; }
    const data = await r.json();
    // Ask the server to sign it for this conversation.
    const signed = await window.apiFetch('/api/chat/attachment/sign', {
      method: 'POST',
      body: { user_code: this.knownUserCode, conversation_id: this.activeConvId, url: data.url },
    });
    const finalUrl = signed.ok ? signed.data.signed_url : data.url;
    await window.apiFetch(`/api/chat/conversations/${this.activeConvId}/messages`, {
      method: 'POST',
      body: { user_code: this.knownUserCode, attachment: { url: finalUrl, name: data.name, mime: data.mime, size: data.size } },
    });
  }
  dragOver(e) { e.preventDefault(); this.composerEl?.classList.add('is-drag'); }
  dragLeave() { this.composerEl?.classList.remove('is-drag'); }
  async drop(e) {
    e.preventDefault();
    this.composerEl?.classList.remove('is-drag');
    const file = e.dataTransfer?.files?.[0];
    if (file) await this.uploadAttachment(file);
  }

  // ─── Sonido ───────────────────────────────────────────────
  getSoundPref() { return localStorage.getItem('tnsvt_chat_sound') !== '0'; }
  toggleSound() {
    const next = !this.getSoundPref();
    localStorage.setItem('tnsvt_chat_sound', next ? '1' : '0');
    this._updateSoundIcon();
    this.showBanner(next ? 'Sonido activado' : 'Sonido desactivado', 'info');
  }
  _updateSoundIcon() {
    if (this.hasSoundIconTarget) this.soundIconTarget.textContent = this.getSoundPref() ? 'volume_up' : 'volume_off';
  }
  _playSound() {
    if (!this.getSoundPref()) return;
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator(); const gain = ctx.createGain();
      osc.frequency.value = 800; osc.type = 'sine';
      gain.gain.setValueAtTime(0.1, ctx.currentTime);
      osc.connect(gain).connect(ctx.destination);
      osc.start(); osc.stop(ctx.currentTime + 0.2);
    } catch {}
  }

  // ─── Atajos globales ──────────────────────────────────────
  onGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      this.openValue ? this.close() : this.openPanel();
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'm' && this.openValue) {
      e.preventDefault();
      this.showNewDm();
    } else if (e.key === 'Escape') {
      if (this.hasNewDmModalTarget && !this.newDmModalTarget.classList.contains('hidden')) {
        this.hideNewDm();
      } else if (this.openValue) {
        this.close();
      }
    }
  }

  // ─── Helpers ──────────────────────────────────────────────
  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  relativeTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'ahora';
    if (diff < 3600) return Math.floor(diff / 60) + 'm';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h';
    return Math.floor(diff / 86400) + 'd';
  }
  showBanner(msg, kind = 'info') {
    if (window.showBanner) window.showBanner(msg);
  }

  // ─── Lazy signed URLs para adjuntos inline ────────────────
  onMessagesClick(e) {
    const link = e.target.closest('.chat-widget-att');
    if (!link) return;
    const inline = link.dataset.attachmentInline === 'true';
    if (inline) {
      e.preventDefault();
      const url = link.dataset.attachmentUrl;
      if (!link.querySelector('[data-signed-src]')) return;
      const media = link.querySelector('[data-signed-src]');
      this._signThenSet(url, media);
    } else {
      e.preventDefault();
      this._signThenOpen(link.dataset.attachmentUrl, link.dataset.attachmentName);
    }
  }
  async _signThenSet(url, mediaEl) {
    const r = await window.apiFetch('/api/chat/attachment/sign', {
      method: 'POST',
      body: { user_code: this.knownUserCode, conversation_id: this.activeConvId, url },
    });
    mediaEl.src = r.ok ? r.data.signed_url : url;
  }
  async _signThenOpen(url, name) {
    const r = await window.apiFetch('/api/chat/attachment/sign', {
      method: 'POST',
      body: { user_code: this.knownUserCode, conversation_id: this.activeConvId, url },
    });
    if (r.ok) window.open(r.data.signed_url, '_blank');
  }
}
