import { Controller } from '@hotwired/stimulus';

const CARD_CLASS = {
    signal: 'signal',
    projection: 'projection',
    result: 'result-win',
    question: 'question',
};

const CAT_CLASS = {
    signal: 'cat-signal',
    projection: 'cat-projection',
    result: 'cat-result',
    question: 'cat-question',
};

const CAT_LABEL = {
    signal: 'SEÑAL',
    projection: 'PROYECCIÓN',
    result: 'RESULTADO',
    question: 'PREGUNTA',
};

export default class extends Controller {
    static targets = [
        'list', 'text', 'category', 'publishBtn',
        'fab', 'modal',
    ];

    connect() {
        this.currentFilter = 'all';
        if (window.TNSVT_USER) {
            this.load();
        } else {
            window.addEventListener('tnsvt:user-loaded', () => this.load(), { once: true });
        }
    }

    me() {
        return (window.TNSVT_USER && window.TNSVT_USER.code) || '';
    }

    escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    async load() {
        const r = await window.apiFetch('/api/feed');
        if (!r.ok || !r.data) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin datos disponibles</p>';
            return;
        }
        let posts = Array.isArray(r.data) ? r.data : [];
        if (this.currentFilter !== 'all') {
            posts = posts.filter((p) => (p.cat || 'general') === this.currentFilter);
        }
        if (posts.length === 0) {
            this.listTarget.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin publicaciones</p>';
            return;
        }
        this.listTarget.innerHTML = posts.map((p) => {
            const initial = (p.author_name || p.author_code || '?').slice(0, 1).toUpperCase();
            const time = p.created_at ? new Date(p.created_at).toLocaleString() : '';
            const photo = p.photo ? `<img src="${this.escapeHtml(p.photo)}" style="max-width:100%;border-radius:0.75rem;margin-top:1rem;" />` : '';
            const likes = p.likes || 0;
            const comments = (p.comments || []).length;
            const cat = p.cat || 'general';
            return `
                <article class="feed-card ${CARD_CLASS[cat] || ''} fade-in-up" data-id="${p.id}" data-cat="${cat}">
                    <div class="p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="feed-avatar">${this.escapeHtml(initial)}</div>
                            <div class="flex-1">
                                <div class="feed-author">${this.escapeHtml(p.author_name || p.author_code)}</div>
                                <div class="feed-time">${this.escapeHtml(p.author_code)} · ${this.escapeHtml(time)}</div>
                            </div>
                            <span class="feed-cat ${CAT_CLASS[cat] || 'cat-signal'}">${CAT_LABEL[cat] || 'GENERAL'}</span>
                        </div>
                        <div style="font-size:0.9375rem;line-height:1.6;color:var(--on-surface-elev);white-space:pre-wrap;word-break:break-word;">${this.escapeHtml(p.text || '')}</div>
                        ${photo}
                        <div class="feed-actions">
                            <button class="feed-action-btn" data-action="feed#like" data-id="${p.id}">
                                <span class="material-symbols-outlined text-[18px]">thumb_up</span>
                                <span>${likes}</span>
                            </button>
                            <button class="feed-action-btn" data-action="feed#comment" data-id="${p.id}">
                                <span class="material-symbols-outlined text-[18px]">chat_bubble</span>
                                <span>${comments}</span>
                            </button>
                            <button class="feed-action-btn ml-auto" data-action="share" data-id="${p.id}">
                                <span class="material-symbols-outlined text-[18px]">share</span>
                            </button>
                        </div>
                    </div>
                </article>
            `;
        }).join('');
    }

    async publish() {
        const text = this.textTarget.value.trim();
        if (!text) return;
        this.publishBtnTarget.disabled = true;
        const r = await window.apiFetch('/api/feed', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: this.me(), text: text, cat: this.categoryTarget.value }),
        });
        this.publishBtnTarget.disabled = false;
        if (r.ok && r.data && (r.data.success || r.data.id)) {
            this.textTarget.value = '';
            if (window.apiToast) window.apiToast('Publicación creada', 'success');
            await this.load();
        } else {
            const msg = (r.data && (r.data.error || r.data.message)) || 'Error';
            if (window.apiToast) window.apiToast(msg, 'error');
        }
    }

    async like(event) {
        const btn = event.currentTarget;
        const postId = btn.dataset.id;
        const r = await window.apiFetch(`/api/feed/${postId}/like`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: this.me() }),
        });
        if (r.ok && r.data && r.data.likes !== undefined) {
            btn.classList.add('liked');
            const span = btn.querySelector('span:last-child');
            if (span) span.textContent = r.data.likes;
        }
    }

    comment() {
        if (window.apiToast) window.apiToast('Comentarios próximamente', 'info');
    }

    setFilter(event) {
        const pill = event.currentTarget;
        this.element.querySelectorAll('.filter-pill').forEach((p) => p.classList.remove('active'));
        pill.classList.add('active');
        this.currentFilter = pill.dataset.filter;
        this.load();
    }

    openFab() {
        if (this.hasModalTarget) this.modalTarget.classList.add('active');
    }

    closeFab() {
        if (this.hasModalTarget) this.modalTarget.classList.remove('active');
    }
}