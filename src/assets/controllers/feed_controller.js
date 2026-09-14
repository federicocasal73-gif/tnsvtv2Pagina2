import { Controller } from '@hotwired/stimulus';

/**
 * Feed (Salón del Cónclave) — public timeline + composer + side panel.
 *
 * Extracted from templates/sanctum/feed.html.twig inline <script>
 * (P10 / F10 commit C7). Covers: tab filters, composer with chips
 * and char-counter, post rendering with like/save/comments/delete,
 * side-panel conversations + top posters, infinite-scroll via
 * sentinel + load-more button, and URL state via ?cat=…
 *
 * Replaces the orphan feed_controller.js that only handled a tiny
 * subset (5 actions) while the actual page ran ~500 LoC of inline JS
 * — Turbo navigation would re-execute that script, doubling event
 * listeners on every visit.
 */
const CARD_CAT_CLASS = {
    signal: 'signal',
    projection: 'projection',
    result: 'result-win',
    question: 'question',
};
const CAT_CLASS = {
    general: 'cat-general',
    señales: 'cat-signal',
    'result-win': 'cat-result-win',
    'result-loss': 'cat-result-loss',
    projection: 'cat-projection',
    question: 'cat-question',
};
const CAT_LABEL = {
    general: 'General',
    señales: 'Señal',
    'result-win': 'Resultado +',
    'result-loss': 'Resultado −',
    projection: 'Proyección',
    question: 'Pregunta',
};

const SAVED_KEY = 'tnsvt_feed_saved';

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
        if (diff < 60)    return 'hace ' + diff + 's';
        if (diff < 3600)  return 'hace ' + Math.floor(diff / 60) + 'm';
        if (diff < 86400) return 'hace ' + Math.floor(diff / 3600) + 'h';
        return d.toLocaleDateString('es-AR', { day: '2-digit', month: 'short' });
    } catch (e) { return ''; }
}

function catClass(cat)  { return CAT_CLASS[cat] || 'cat-general'; }
function catLabel(cat)  { return CAT_LABEL[cat] || cat; }

export default class extends Controller {
    static targets = [
        'list', 'loading', 'loadMore', 'sentinel',
        'text', 'count', 'submit', 'chips',
        'tabs', 'sideConversations', 'sideTop',
        'backToTop',
    ];

    connect() {
        this.category = new URLSearchParams(location.search).get('cat') || 'all';
        this.activeCat = 'general';
        this.feedNextBeforeId = null;
        this.feedHasMore = true;
        this.feedIsLoading = false;
        this.likedIds = new Set();
        this.savedIds = new Set();

        try {
            const raw = localStorage.getItem(SAVED_KEY);
            if (raw) this.savedIds = new Set(JSON.parse(raw));
        } catch (e) {}

        this.wire();
        this.syncTabsToUrl();
        this.loadFeed();

        if (window.TNSVT_USER) {
            this.loadSideConversations();
            this.loadSideTop();
        } else {
            window.addEventListener('tnsvt:user-loaded', () => {
                this.loadSideConversations();
                this.loadSideTop();
            }, { once: true });
        }
    }

    disconnect() {
        if (this._scrollBound) window.removeEventListener('scroll', this._scrollBound);
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || '';
    }

    persistSaved() {
        try { localStorage.setItem(SAVED_KEY, JSON.stringify(Array.from(this.savedIds))); } catch (e) {}
    }

    // ─── Wire-up ──────────────────────────────────────────────

    wire() {
        if (this.hasSubmitTarget && this.hasTextTarget) {
            this.submitTarget.addEventListener('click', () => this.publish());
            this.textTarget.addEventListener('input', () => this.updateComposer());
            this.updateComposer();
        }
        if (this.hasChipsTarget) {
            this.chipsTarget.querySelectorAll('.feed-composer-chip').forEach((chip) => {
                chip.addEventListener('click', () => this.activateChip(chip));
            });
            const defaultChip = this.chipsTarget.querySelector('[data-cat="general"]');
            if (defaultChip) defaultChip.classList.add('active');
        }
        if (this.hasTabsTarget) {
            this.tabsTarget.querySelectorAll('.feed-tab').forEach((t) => {
                t.addEventListener('click', () => this.selectTab(t));
            });
        }
        if (this.hasSentinelTarget) {
            this._scrollBound = () => this.onScroll();
            window.addEventListener('scroll', this._scrollBound, { passive: true });
        }
        if (this.hasLoadMoreTarget) {
            this.loadMoreTarget.addEventListener('click', () => this.loadFeed({ append: true }));
        }
        if (this.hasBackToTopTarget) {
            this.backToTopTarget.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            window.addEventListener('scroll', () => {
                if (!this.hasBackToTopTarget) return;
                this.backToTopTarget.style.opacity = window.scrollY > 400 ? '1' : '0';
            }, { passive: true });
        }
    }

    // ─── Tabs ──────────────────────────────────────────────

    syncTabsToUrl() {
        if (!this.hasTabsTarget) return;
        this.tabsTarget.querySelectorAll('.feed-tab').forEach((x) => {
            const active = x.dataset.cat === this.category;
            x.classList.toggle('active', active);
            x.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    selectTab(t) {
        if (!this.hasTabsTarget) return;
        this.tabsTarget.querySelectorAll('.feed-tab').forEach((x) => {
            const active = x === t;
            x.classList.toggle('active', active);
            x.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        this.category = t.dataset.cat;
        const url = new URL(location.href);
        if (this.category && this.category !== 'all') url.searchParams.set('cat', this.category);
        else url.searchParams.delete('cat');
        history.replaceState(null, '', url);
        this.loadFeed();
    }

    // ─── Composer ──────────────────────────────────────────

    updateComposer() {
        if (this.hasCountTarget) {
            const max = parseInt(this.textTarget.getAttribute('maxlength') || '2000', 10);
            const len = this.textTarget.value.length;
            this.countTarget.textContent = len + ' / ' + max;
            this.countTarget.classList.toggle('is-warn', len > max * 0.85 && len <= max * 0.97);
            this.countTarget.classList.toggle('is-danger', len > max * 0.97);
        }
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = !(this.textTarget.value.trim().length > 0 && this.activeCat);
        }
    }

    activateChip(chip) {
        if (!this.hasChipsTarget) return;
        this.chipsTarget.querySelectorAll('.feed-composer-chip').forEach((c) => {
            c.classList.toggle('active', c === chip);
        });
        this.activeCat = chip.dataset.cat;
        this.updateComposer();
    }

    async publish() {
        if (!this.hasTextTarget || !this.hasSubmitTarget) return;
        const text = this.textTarget.value.trim();
        if (!text || !this.activeCat) return;
        this.submitTarget.disabled = true;
        const origLabel = this.submitTarget.textContent;
        this.submitTarget.textContent = 'Publicando...';
        try {
            const r = await window.apiFetch('/api/feed', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text, cat: this.activeCat }),
            });
            if (r.ok && r.data && r.data.success) {
                this.textTarget.value = '';
                await this.loadFeed();
            } else {
                if (window.apiToast) window.apiToast(r.data && r.data.error ? r.data.error : 'Error al publicar', 'error');
            }
        } finally {
            this.submitTarget.textContent = origLabel;
            this.updateComposer();
        }
    }

    // ─── Feed loading ──────────────────────────────────────

    async loadFeed({ append = false } = {}) {
        if (!this.hasListTarget || this.feedIsLoading) return;
        if (!append) {
            this.feedNextBeforeId = null;
            this.feedHasMore = true;
            if (this.hasLoadingTarget) this.loadingTarget.hidden = false;
            if (this.hasLoadMoreTarget) this.loadMoreTarget.style.display = 'none';
        } else {
            if (!this.feedHasMore || !this.feedNextBeforeId) return;
        }
        this.feedIsLoading = true;
        try {
            let url = `/api/feed?category=${encodeURIComponent(this.category)}&limit=10`;
            if (append && this.feedNextBeforeId) url += `&before_id=${this.feedNextBeforeId}`;
            const r = await window.apiFetch(url, { silent: true });
            if (!r.ok || !r.data || !Array.isArray(r.data)) {
                if (!append) {
                    if (window.apiEmpty) window.apiEmpty(this.listTarget, {
                        icon: 'cloud_off', message: 'Sin datos disponibles', size: 'illustrated',
                    });
                }
                this.feedHasMore = false;
                return;
            }
            const me = this.me();
            if (append) {
                this.appendFeed(r.data, me);
            } else {
                this.renderFeed(r.data, me);
            }
            if (r.data.length < 10) this.feedHasMore = false;
            else this.feedNextBeforeId = r.data[r.data.length - 1]?.id || null;
            if (this.hasLoadMoreTarget) {
                this.loadMoreTarget.style.display = this.feedHasMore ? 'block' : 'none';
            }
        } catch (e) {
            if (!append && this.hasListTarget) {
                this.listTarget.innerHTML = '<div class="feed-empty"><span class="material-symbols-elev feed-empty-icon">cloud_off</span><p>Sin conexión.</p></div>';
            }
            this.feedHasMore = false;
        } finally {
            this.feedIsLoading = false;
            if (this.hasLoadingTarget) this.loadingTarget.hidden = true;
        }
    }

    renderPostHtml(p, meCode) {
        const cat = p.cat || 'general';
        const cls = catClass(cat);
        const sig = p.signal;
        const sigHtml = sig && typeof sig === 'object'
            ? `<div class="feed-post-signal">${Object.entries(sig).sort(([a],[b])=>String(a).localeCompare(String(b))).slice(0,4)
                .map(([k,v]) => `<div><div class="feed-post-signal-field-label">${esc(k.replace(/_/g,' '))}</div><div class="feed-post-signal-field-value gold">${esc(String(v))}</div></div>`)
                .join('')}</div>`
            : '';
        const photoHtml = p.photo
            ? `<img class="feed-post-photo lightbox-trigger" data-lightbox-group="feed-post" data-lightbox-name="${esc(p.user || 'usuario')}" style="width:auto;height:auto;max-width:100%;max-height:480px;border-radius:0.5rem;" src="${esc(p.photo)}" alt="captura de ${esc(p.user || 'usuario')}" loading="lazy" decoding="async" />`
            : '';
        const comments = Array.isArray(p.comments) ? p.comments : [];
        const myPost = meCode && p.author_code === meCode;
        const liked = this.likedIds.has(p.id);
        const saved = this.savedIds.has(p.id);
        return `<article class="feed-post cat-${esc(cat)}" data-id="${p.id}">
            <header class="feed-post-head">
                <div class="feed-avatar">${esc(initials(p.author_name))}</div>
                <div class="feed-author-block">
                    <div class="feed-author-name">${esc(p.author_name || 'Anónimo')}</div>
                    <div class="feed-author-meta">
                        <span class="feed-cat-pill ${cls}">${esc(catLabel(cat))}</span>
                        <span>· ${esc(relTime(p.created_at))}</span>
                    </div>
                </div>
                ${myPost ? `<button type="button" class="feed-action feed-post-delete" data-id="${p.id}" aria-label="Eliminar publicación">
                    <span class="material-symbols-elev icon-size-sm">delete</span>
                </button>` : ''}
            </header>
            <div class="feed-post-body">${esc(p.text || '')}</div>
            ${sigHtml}${photoHtml}
            <div class="feed-post-actions">
                <button type="button" class="feed-action feed-like-btn ${liked ? 'liked' : ''}" data-id="${p.id}">
                    <span class="material-symbols-elev icon-size-sm">favorite</span>
                    <span data-likes-count>${p.likes || 0}</span>
                </button>
                <button type="button" class="feed-action feed-toggle-comments" data-id="${p.id}">
                    <span class="material-symbols-elev icon-size-sm">chat_bubble</span>
                    <span data-comments-count>${comments.length}</span>
                </button>
                <button type="button" class="feed-action feed-save-btn ${saved ? 'saved' : ''}" data-id="${p.id}" aria-label="Guardar" title="Guardar">
                    <span class="material-symbols-elev icon-size-sm">bookmark</span>
                </button>
            </div>
            <div class="feed-comments" data-id="${p.id}" data-collapsed="true">
                ${comments.map((c) => `
                    <div class="feed-comment">
                        <div class="feed-comment-avatar">${esc(initials(c.author))}</div>
                        <div class="feed-comment-body">
                            <span class="feed-comment-author">${esc(c.author || '')}</span>
                            <span class="feed-comment-text">${esc(c.text || '')}</span>
                            <span class="feed-comment-time">${esc(relTime(c.date))}</span>
                        </div>
                    </div>
                `).join('')}
                <form class="feed-comment-form" data-id="${p.id}">
                    <input type="text" data-test-id="feed-comment-input" class="feed-comment-input" placeholder="Comentá (use @CODIGO para mencionar)" maxlength="500" />
                    <button type="submit" class="feed-comment-submit">Comentar</button>
                </form>
            </div>
        </article>`;
    }

    renderFeed(posts, meCode) {
        if (!this.hasListTarget) return;
        if (posts.length === 0) {
            this.listTarget.innerHTML = `
                <div class="feed-empty">
                    <span class="material-symbols-elev feed-empty-icon">forum</span>
                    <p style="font-size:1rem;color:var(--on-surface-elev);">No hay publicaciones todavía.</p>
                    <p>Sé el primero en escribir.</p>
                </div>
            `;
            return;
        }
        this.listTarget.innerHTML = posts.map((p) => this.renderPostHtml(p, meCode)).join('');
        this.bindPostActions(this.listTarget);
    }

    appendFeed(posts, meCode) {
        if (!this.hasListTarget || !posts.length) { this.feedHasMore = false; return; }
        const frag = document.createElement('div');
        frag.innerHTML = posts.map((p) => this.renderPostHtml(p, meCode)).join('');
        while (frag.firstChild) this.listTarget.appendChild(frag.firstChild);
        this.bindPostActions(this.listTarget);
    }

    bindPostActions(scope) {
        scope.querySelectorAll('.feed-like-btn').forEach((b) => {
            b.addEventListener('click', () => this.likePost(parseInt(b.dataset.id, 10), b));
        });
        scope.querySelectorAll('.feed-save-btn').forEach((b) => {
            b.addEventListener('click', () => this.toggleSave(parseInt(b.dataset.id, 10), b));
        });
        scope.querySelectorAll('.feed-toggle-comments').forEach((b) => {
            b.addEventListener('click', () => this.toggleComments(b));
        });
        scope.querySelectorAll('.feed-comment-form').forEach((f) => {
            f.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitComment(f);
            });
        });
        scope.querySelectorAll('.feed-post-delete').forEach((b) => {
            b.addEventListener('click', () => this.deletePost(parseInt(b.dataset.id, 10)));
        });
    }

    async likePost(id, btn) {
        const liked = btn.classList.contains('liked');
        const action = liked ? 'unlike' : 'like';
        const r = await window.apiFetch(`/api/feed/${id}/like`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action }),
        });
        if (r.ok && r.data && typeof r.data.likes === 'number') {
            const countEl = btn.querySelector('[data-likes-count]');
            if (countEl) countEl.textContent = r.data.likes;
            btn.classList.toggle('liked');
            if (!liked) this.likedIds.add(id); else this.likedIds.delete(id);
        }
    }

    toggleSave(id, btn) {
        if (this.savedIds.has(id)) {
            this.savedIds.delete(id);
            btn.classList.remove('saved');
            if (window.apiToast) window.apiToast('Eliminado de guardados', 'info');
        } else {
            this.savedIds.add(id);
            btn.classList.add('saved');
            if (window.apiToast) window.apiToast('Guardado', 'success');
        }
        this.persistSaved();
    }

    toggleComments(btn) {
        if (!this.hasListTarget) return;
        const id = parseInt(btn.dataset.id, 10);
        const c = this.listTarget.querySelector(`.feed-comments[data-id="${id}"]`);
        if (!c) return;
        const collapsed = c.getAttribute('data-collapsed') === 'true';
        c.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
        btn.classList.toggle('active', !collapsed);
        if (!collapsed) {
            const input = c.querySelector('.feed-comment-input');
            if (input) input.focus();
        }
    }

    async submitComment(form) {
        const id = parseInt(form.dataset.id, 10);
        const input = form.querySelector('.feed-comment-input');
        const text = input.value.trim();
        if (!text) return;
        input.disabled = true;
        const r = await window.apiFetch(`/api/feed/${id}/comment`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text }),
        });
        input.disabled = false;
        if (r.ok && r.data && r.data.success) {
            input.value = '';
            this.loadFeed();
        } else {
            if (window.apiToast) {
                window.apiToast(r.data && r.data.error ? r.data.error : 'Error al comentar', 'error');
            }
        }
    }

    async deletePost(id) {
        if (!await window.apiConfirm('¿Eliminar esta publicación?', {
            title: 'Eliminar publicación', variant: 'danger',
        })) return;
        try {
            const r = await window.apiFetch(`/api/feed/${id}`, { method: 'DELETE' });
            if (r.ok) {
                this.loadFeed();
            } else {
                if (window.apiToast) window.apiToast((r.data && r.data.error) || 'Error al eliminar', 'error');
            }
        } catch (e) {
            console.error('[feed] delete error', e);
            if (window.apiToast) window.apiToast('Error de red al eliminar', 'error');
        }
    }

    onScroll() {
        if (!this.hasSentinelTarget) return;
        const rect = this.sentinelTarget.getBoundingClientRect();
        if (rect.top < window.innerHeight + 200) {
            this.loadFeed({ append: true });
        }
    }

    // ─── Side panel ───────────────────────────────────────

    async loadSideConversations() {
        if (!this.hasSideConversationsTarget) return;
        try {
            const r = await window.apiFetch('/api/chat/conversations', { silent: true });
            if (!r.ok || !r.data || !Array.isArray(r.data)) {
                this.sideConversationsTarget.innerHTML = '<div class="feed-loading">Sin datos</div>';
                return;
            }
            const items = r.data.slice(0, 5);
            if (items.length === 0) {
                this.sideConversationsTarget.innerHTML = '<div class="feed-loading" style="font-style:normal;">Sin conversaciones aún</div>';
                return;
            }
            this.sideConversationsTarget.innerHTML = items.map((c) => `
                <a class="feed-side-row" href="/chat" style="text-decoration:none;color:inherit;">
                    <div class="feed-avatar" style="width:30px;height:30px;font-size:0.75rem;">${esc(initials(c.other_user_name || c.title || c.name || 'C'))}</div>
                    <div style="flex-grow:1;min-width:0;">
                        <div class="feed-side-name">${esc(c.other_user_name || c.title || c.name || 'Conversación')}</div>
                        <div class="feed-side-meta">${esc((c.last_message?.content || c.last_message || '').slice(0, 40))}</div>
                    </div>
                </a>
            `).join('');
        } catch (e) {
            this.sideConversationsTarget.innerHTML = '<div class="feed-loading">Sin conexión</div>';
        }
    }

    async loadSideTop() {
        if (!this.hasSideTopTarget) return;
        try {
            const r = await window.apiFetch('/api/feed?category=all&limit=50', { silent: true });
            if (!r.ok || !r.data || !Array.isArray(r.data)) {
                this.sideTopTarget.innerHTML = '<div class="feed-loading">Sin datos</div>';
                return;
            }
            const tally = {};
            r.data.forEach((p) => {
                const name = p.author_name || 'Anónimo';
                if (!tally[name]) tally[name] = { posts: 0, score: 0 };
                tally[name].posts += 1;
                const likes = Number(p.likes) || 0;
                const comments = Array.isArray(p.comments)
                    ? p.comments.length
                    : (Number(p.comment_count) || 0);
                tally[name].score += 1 + likes + 2 * comments;
            });
            const ranking = Object.entries(tally)
                .sort((a, b) => b[1].score - a[1].score)
                .slice(0, 5);
            if (ranking.length === 0) {
                this.sideTopTarget.innerHTML = '<div class="feed-loading" style="font-style:normal;">Aún sin rankings</div>';
                return;
            }
            this.sideTopTarget.innerHTML = ranking.map(([name, info]) => `
                <div class="feed-side-row">
                    <div class="feed-avatar" style="width:30px;height:30px;font-size:0.75rem;">${esc(initials(name))}</div>
                    <div style="flex-grow:1;min-width:0;">
                        <div class="feed-side-name">${esc(name)}</div>
                        <div class="feed-side-meta">${info.posts} posts · score ${info.score}</div>
                    </div>
                </div>
            `).join('');
        } catch (e) {
            this.sideTopTarget.innerHTML = '<div class="feed-loading">Sin conexión</div>';
        }
    }
}
