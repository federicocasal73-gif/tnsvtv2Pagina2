import { Controller } from '@hotwired/stimulus';

/**
 * Command palette (F1) — global Ctrl+K / Cmd+K launcher.
 *
 * Catalog = static navigation entries + contextual page actions that are
 * detected via the DOM (only offered when the backing element exists).
 * Filtering is a small subsequence fuzzy match with prefix bonus.
 *
 * Keyboard:
 *   Ctrl/⌘+K → toggle (global, bound on window)
 *   Esc       → close
 *   ↑ / ↓     → move active result
 *   Enter     → run active result
 */
const NAV = [
    { id: 'nav-dashboard',     label: 'Ir a Inicio',            hint: 'Dashboard',        icon: 'dashboard',        url: '/',                  keys: 'inicio home dashboard panel' },
    { id: 'nav-journal',       label: 'Ir a Journal',           hint: 'Trading',          icon: 'edit_note',        url: '/journal',            keys: 'journal bitacora trades libro ejecutor' },
    { id: 'nav-journal-new',   label: 'Asentar operación',      hint: 'Nuevo trade',      icon: 'add',              url: '/journal/new',        keys: 'nuevo trade asentar registrar operacion' },
    { id: 'nav-calendar',      label: 'Ir a Calendario',        hint: 'Macro',            icon: 'calendar_month',   url: '/calendar',           keys: 'calendario eventos macro noticias' },
    { id: 'nav-leaderboard',   label: 'Ir a Leaderboard',       hint: 'Ranking',          icon: 'leaderboard',      url: '/leaderboard',        keys: 'leaderboard ranking top traders' },
    { id: 'nav-macro',         label: 'Ir a Macroeconomía',     hint: 'Hilos del mundo',  icon: 'event_upcoming',   url: '/macro',              keys: 'macro economia hilos mundo bento' },
    { id: 'nav-academy',       label: 'Ir a Macro Academy',     hint: 'Formación',        icon: 'school',           url: '/macro/academy',      keys: 'academy academia curso aprender' },
    { id: 'nav-oracle',        label: 'Ir a Oráculo',           hint: 'Métricas',         icon: 'auto_awesome',     url: '/oracle',             keys: 'oraculo metricas faith logic sesion' },
    { id: 'nav-guardian',      label: 'Ir a Guardian',          hint: 'Disciplina',       icon: 'shield_person',    url: '/sanctum/guardian',   keys: 'guardian disciplina riesgo señales' },
    { id: 'nav-diary',         label: 'Ir a Diario',            hint: 'Cuaderno',         icon: 'menu_book',        url: '/diario',             keys: 'diario cuaderno reflexiones alma' },
    { id: 'nav-frequencies',   label: 'Ir a Frecuencias',       hint: '432Hz',            icon: 'graphic_eq',       url: '/frequencies',        keys: 'frecuencias sonido 432hz santuario musica' },
    { id: 'nav-campus',        label: 'Ir a Campus',            hint: 'Cursos',           icon: 'school',           url: '/campus',             keys: 'campus cursos lecciones aprender' },
    { id: 'nav-feed',          label: 'Ir a Feed',              hint: 'Cónclave',         icon: 'forum',            url: '/feed',               keys: 'feed conclave posts publicaciones' },
    { id: 'nav-social',        label: 'Ir a Social',            hint: 'Conexiones',       icon: 'share',            url: '/social',             keys: 'social conexiones amigos' },
    { id: 'nav-chat',          label: 'Ir a Chat',              hint: 'Mensajes',         icon: 'chat',             url: '/chat',               keys: 'chat mensajes dm conversacion' },
    { id: 'nav-clan',          label: 'Ir a Clan',              hint: 'Hermandad',        icon: 'groups',           url: '/clan',               keys: 'clan hermandad grupo' },
    { id: 'nav-tasks',         label: 'Ir a Tareas',            hint: 'Tasks',            icon: 'task_alt',         url: '/sanctum/tasks',      keys: 'tareas tasks pendientes' },
    { id: 'nav-wallet',        label: 'Ir a Wallet',            hint: 'Saldo',            icon: 'account_balance_wallet', url: '/wallet',       keys: 'wallet saldo dinero transacciones' },
    { id: 'nav-profile',       label: 'Ir a Perfil',            hint: 'Cuenta',           icon: 'person',           url: '/profile',            keys: 'perfil cuenta identidad avatar' },
    { id: 'nav-notifications', label: 'Ir a Notificaciones',    hint: 'Visiones',         icon: 'notifications_active', url: '/notifications',  keys: 'notificaciones visiones alertas' },
    { id: 'nav-settings',      label: 'Ir a Configuración',     hint: 'Ajustes',          icon: 'tune',             url: '/account_settings',   keys: 'configuracion ajustes settings preferencias' },
];

export default class extends Controller {
    static targets = ['backdrop', 'input', 'list'];

    connect() {
        this._isOpen = false;
        this._results = [];
        this._active = 0;
        this._prevFocus = null;

        this._onGlobalKey = this._onGlobalKey.bind(this);
        window.addEventListener('keydown', this._onGlobalKey);
        // Visible trigger button (topbar) opens via custom event.
        this._onOpenEvent = () => this.open();
        window.addEventListener('cmdk:open', this._onOpenEvent);
        const trigger = document.getElementById('cmdk-open-btn');
        if (trigger) {
            trigger.addEventListener('click', this._onOpenEvent);
        }
    }

    disconnect() {
        window.removeEventListener('keydown', this._onGlobalKey);
        window.removeEventListener('cmdk:open', this._onOpenEvent);
    }

    /* ───── global toggle ───── */

    _onGlobalKey(event) {
        const mod = event.ctrlKey || event.metaKey;
        if (mod && (event.key === 'k' || event.key === 'K')) {
            event.preventDefault();
            this.toggle();
            return;
        }
        if (event.key === 'Escape' && this._isOpen) {
            event.preventDefault();
            this.close();
        }
    }

    toggle() {
        if (this._isOpen) this.close();
        else this.open();
    }

    open() {
        if (this._isOpen) return;
        this._isOpen = true;
        this._prevFocus = document.activeElement;
        this.element.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        this.inputTarget.value = '';
        this._render(this._catalog());
        // Focus on next tick so the keyup from Ctrl+K doesn't leak in.
        setTimeout(() => this.inputTarget.focus(), 30);
    }

    close() {
        if (!this._isOpen) return;
        this._isOpen = false;
        this.element.setAttribute('hidden', '');
        document.body.style.overflow = '';
        if (this._prevFocus && typeof this._prevFocus.focus === 'function') {
            this._prevFocus.focus();
        }
        this._prevFocus = null;
    }

    onBackdrop(event) {
        if (event.target === this.backdropTarget) this.close();
    }

    /* ───── filtering ───── */

    onInput() {
        const q = this.inputTarget.value.trim().toLowerCase();
        const all = this._catalog();
        if (!q) {
            this._render(all);
            return;
        }
        const scored = [];
        for (const cmd of all) {
            const s = this._score(cmd, q);
            if (s > 0) scored.push({ cmd, s });
        }
        scored.sort((a, b) => b.s - a.s);
        this._render(scored.map(x => x.cmd));
    }

    onInputKey(event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const dir = event.key === 'ArrowDown' ? 1 : -1;
            if (this._results.length === 0) return;
            this._active = (this._active + dir + this._results.length) % this._results.length;
            this._paintActive();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const cmd = this._results[this._active];
            if (cmd) this._run(cmd);
        }
    }

    // Subsequence fuzzy match: every query char must appear in order in
    // the haystack. Prefix + word-boundary hits score higher.
    _score(cmd, q) {
        const hay = (cmd.label + ' ' + (cmd.hint || '') + ' ' + (cmd.keys || '')).toLowerCase();
        let hi = 0;
        let score = 0;
        let consecutive = 0;
        for (let qi = 0; qi < q.length; qi++) {
            const ch = q[qi];
            const found = hay.indexOf(ch, hi);
            if (found === -1) return 0;
            if (found === hi) {
                consecutive += 1;
                score += 3 + consecutive;
            } else {
                consecutive = 0;
                score += 1;
            }
            // Word-boundary bonus (start of label/hint/keys or after space).
            if (found === 0 || hay[found - 1] === ' ') score += 2;
            hi = found + 1;
        }
        // Short labels win ties.
        score += Math.max(0, 20 - hay.length * 0.1);
        return score;
    }

    /* ───── catalog ───── */

    _catalog() {
        const cmds = NAV.slice();
        // Contextual actions — only when the backing element exists.
        if (document.getElementById('btn-new-trade')) {
            cmds.push({
                id: 'act-new-trade', label: 'Nuevo trade (modal)', hint: 'Esta página',
                icon: 'add', keys: 'nuevo trade modal registrar',
                run: () => document.getElementById('btn-new-trade').click(),
            });
        }
        const markAll = document.querySelector('[data-action="notifications#markAll"], .notif-popover-markall');
        if (markAll) {
            cmds.push({
                id: 'act-mark-all', label: 'Marcar notificaciones como leídas', hint: 'Esta página',
                icon: 'done_all', keys: 'marcar leidas notificaciones leer todas',
                run: () => markAll.click(),
            });
        }
        const todayBtn = document.getElementById('cal-today-btn');
        if (todayBtn) {
            cmds.push({
                id: 'act-today', label: 'Calendario: ir a hoy', hint: 'Esta página',
                icon: 'today', keys: 'hoy calendario fecha today',
                run: () => todayBtn.click(),
            });
        }
        const feedTop = document.getElementById('feed-back-to-top');
        if (feedTop) {
            cmds.push({
                id: 'act-top', label: 'Volver arriba', hint: 'Esta página',
                icon: 'arrow_upward', keys: 'arriba top subir inicio pagina',
                run: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
            });
        }
        return cmds;
    }

    /* ───── render + run ───── */

    _render(cmds) {
        this._results = cmds.slice(0, 9);
        this._active = 0;
        if (this._results.length === 0) {
            this.listTarget.innerHTML = '<p class="cmdk-empty">Sin resultados. Probá con otra búsqueda.</p>';
            return;
        }
        this.listTarget.innerHTML = this._results.map((c, i) => `
            <button type="button" role="option" aria-selected="${i === 0}"
                    class="cmdk-item${i === 0 ? ' is-active' : ''}" data-idx="${i}">
                <span class="material-symbols-elev cmdk-item-icon" aria-hidden="true">${c.icon || 'chevron_right'}</span>
                <span class="cmdk-item-main">
                    <span class="cmdk-item-label">${this._esc(c.label)}</span>
                    ${c.hint ? `<span class="cmdk-item-hint">${this._esc(c.hint)}</span>` : ''}
                </span>
                <span class="material-symbols-elev cmdk-item-go" aria-hidden="true">north_west</span>
            </button>
        `).join('');
        this.listTarget.querySelectorAll('.cmdk-item').forEach(el => {
            el.addEventListener('click', () => {
                const cmd = this._results[parseInt(el.dataset.idx, 10)];
                if (cmd) this._run(cmd);
            });
            el.addEventListener('mousemove', () => {
                const idx = parseInt(el.dataset.idx, 10);
                if (idx !== this._active) {
                    this._active = idx;
                    this._paintActive();
                }
            });
        });
    }

    _paintActive() {
        this.listTarget.querySelectorAll('.cmdk-item').forEach(el => {
            const active = parseInt(el.dataset.idx, 10) === this._active;
            el.classList.toggle('is-active', active);
            el.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) el.scrollIntoView({ block: 'nearest' });
        });
    }

    _run(cmd) {
        this.close();
        if (typeof cmd.run === 'function') {
            try { cmd.run(); } catch (e) { console.error('[cmdk]', e); }
            return;
        }
        if (cmd.url) {
            window.location.href = cmd.url;
        }
    }

    _esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }
}
