import { Controller } from '@hotwired/stimulus';

/**
 * Shell controller — owns all top-level UI behaviour for the Sanctum
 * shell: sidebar collapse/expand, section collapse, active-nav
 * highlighting, logout, theme toggle, notif badge poll, user-info
 * hydration, and the rail tooltip (sidebar collapsed <1024px).
 *
 * Extracted from 6 inline `<script>` IIFEs in templates/shell.html.twig
 * (P10 / F10 commit C11).
 *
 * WHY THIS EXISTS:
 *   The inline scripts ran on every shell.html.twig render. With Turbo
 *   Drive active (F10 commit 3), every <main>-only navigation caused
 *   the <body> to be partially replaced. The `<script>` tags lived in the
 *   replaced region, so the IIFEs re-executed. Each re-execution reattached
 *   a NEW click listener to the SAME #sidebar-toggle (because data-turbo-
 *   permanent kept the element but the scripts are NOT permanent). After 5
 *   navigations, five listeners stacked. Two of them toggling back-and-
 *   forth in opposite directions cancels out → sidebar appears "stuck".
 *   Same pathology was duplicating `apiPoller` calls for the notif badge,
 *   the rail tooltip listeners, etc.
 *
 * Stimulus `disconnect()` runs when the controller's element (here, the
 * <body>) is removed from the DOM. data-turbo-permanent on the shell
 *     regions keeps the <body> alive across <main>-only navigations, so
 * disconnect only fires when the user navigates AWAY from the shell
 *     entirely (e.g. to /frequencies or /macro). Listeners stay single-
 *     instance.
 *
 * SAFE GUARDS:
 *   - `data-shell-target="X"` template references. New shell.html.twig
 *     reads the elements by Stimulus-managed targets.
 *   - IIFE-state that previously lived in module-scope (`_notifPrev`,
 *     `_notifFirstLoad`, `RAIL_BP`, `KEY`) now lives in the controller
 *     instance, so re-mounts can never race against stale state.
 *
 * ACTIONS (data-action="<event>->shell-init#<method>"):
 *   toggleSidebar, toggleSection, toggleTheme, logout.
 */
const SIDEBAR_KEY = 'tnsvt_sidebar_collapsed';
const SIDEBAR_HINT_KEY = 'tnsvt_sidebar_hint_shown';
const RAIL_BP = 1024;

function throttle(fn, wait) {
    let last = 0, t;
    return function () {
        const now = Date.now();
        if (now - last >= wait) {
            last = now;
            fn();
        } else {
            clearTimeout(t);
            t = setTimeout(fn, wait - (now - last));
        }
    };
}

export default class extends Controller {
    static targets = [
        'sidebar', 'sidebarToggle', 'navIndicator', 'sidebarSection',
        'userAvatar', 'userName', 'userTier',
        'logoutBtn',
        'themeToggleBtn', 'themeIcon',
        'bellBadge', 'headerNotifBadge',
    ];

    static outlets = [];

    static classes = [];
    /* Plural-suffix targets auto-derived by Stimulus: */
    /* sidebarLinkTargets, sidebarSectionTargets, userCodeTargets. */

    connect() {
        this._notifPrev = 0;
        this._notifFirstLoad = true;
        this._themeAttempts = 0;
        this._throttledPaintNavIndicator = throttle(
            () => this.paintNavIndicator(), 100);

        this._applyPersistedSidebarState();
        this._wireSectionToggles();
        this._wireSidebarLinks();
        this._wireThemeToggle();
        this._wireNotifPoll();

        this.markActiveNav();
        this.loadUserInfo();
        this._initRailTooltip();
    }

    disconnect() {
        // Drop the notif poll so we don't leave a dangling interval when
        // the user navigates away from the shell entirely.
        if (this._notifPollId) {
            clearInterval(this._notifPollId);
            this._notifPollId = null;
        }
        // Theme also listens to a document-level event; removeEventListener
        // with the bound function reference lets GC drop it.
        if (this._onThemeChange) {
            document.documentElement.removeEventListener('theme:change', this._onThemeChange);
            this._onThemeChange = null;
        }
        if (this._onResize) {
            window.removeEventListener('resize', this._onResize);
            this._onResize = null;
        }
        // turbo:render / turbo:load re-highlight listeners (see
        // _wireSidebarLinks). Without cleanup they stack once per mount.
        if (this._onTurboRender) {
            document.removeEventListener('turbo:render', this._onTurboRender);
            this._onTurboRender = null;
        }
        if (this._onTurboLoad) {
            document.removeEventListener('turbo:load', this._onTurboLoad);
            this._onTurboLoad = null;
        }
    }

    // ─── Action: data-action="click->shell#toggleSidebar" ──
    // NOTE: no imperative addEventListener here on purpose — the template
    // already declares data-action, and a second listener would fire the
    // toggle twice per click (net effect zero = "stuck" sidebar).
    toggleSidebar() {
        if (!this._hasSidebarOrToggle()) return;
        const collapsed = !this.sidebarTarget.classList.contains('sidebar-manual-collapsed');
        this.applySidebarCollapsed(collapsed);
    }

    // Action: data-action="click->shell#toggleSection" (also
    // keydown with Enter/Space on the section title, wired below).
    toggleSection(event) {
        const title = event.currentTarget;
        const collapsed = title.classList.toggle('is-collapsed');
        title.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        let el = title.nextElementSibling;
        while (el && !el.classList.contains('sanctum-section-title')) {
            if (el.classList.contains('sanctum-link')) el.hidden = collapsed;
            el = el.nextElementSibling;
        }
        this.paintNavIndicator();
    }

    // Action: data-action="click->shell#toggleTheme"
    toggleTheme() {
        if (!window.tnsvtTheme) return;
        const cur = window.tnsvtTheme.get();
        const next = cur === 'dark' ? 'light' : cur === 'light' ? 'auto' : 'dark';
        window.tnsvtTheme.set(next);
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', window.tnsvtTheme.isDark() ? '#050308' : '#f5f1e8');
        this.paintTheme();
    }

    // Action: data-action="click->shell#logout"
    async logout(event) {
        event.preventDefault();
        if (!await window.apiConfirm('¿Cerrar sesión?', {
            title: 'Cerrar sesión', variant: 'danger',
        })) return;
        await window.apiFetch('/api/auth/logout', { method: 'POST', silent: true });
        window.location.href = '/';
    }

    // Action: data-action="click->shell#markActiveNav"
    //     Actually, markActiveNav runs once in connect(); this is a manual
    //     re-runnable for late Turbo navigations.
    markActiveNav() {
        const target = this._findActiveLink();
        if (!target) return;
        this._clearActive();
        target.classList.add('active');
        target.setAttribute('aria-current', 'page');
        // The nav container scrolls if the active item is offscreen.
        requestAnimationFrame(() => {
            const nav = target.closest('.sanctum-nav');
            if (nav) {
                const navRect = nav.getBoundingClientRect();
                const itemRect = target.getBoundingClientRect();
                if (itemRect.top < navRect.top || itemRect.bottom > navRect.bottom) {
                    target.scrollIntoView({ block: 'center' });
                }
            }
            this.paintNavIndicator();
        });
    }

    // ─── Sidebar ─────────────────────────────────────────────

    _hasSidebarOrToggle() {
        return this.hasSidebarTarget && this.hasSidebarToggleTarget;
    }

    _applyPersistedSidebarState() {
        if (!this._hasSidebarOrToggle()) return;
        try {
            if (localStorage.getItem(SIDEBAR_KEY) === '1') {
                this.applySidebarCollapsed(true);
            }
        } catch (_) { /* localStorage disabled — ignore */ }
    }

    // NOTE: no _wireSidebarToggle() on purpose — the template declares
    // data-action="click->shell#toggleSidebar" and a second imperative
    // listener would fire the toggle twice per click (net zero).

    applySidebarCollapsed(collapsed) {
        if (!this._hasSidebarOrToggle()) return;
        const aside = this.sidebarTarget;
        const btn   = this.sidebarToggleTarget;
        aside.classList.toggle('sidebar-manual-collapsed', collapsed);
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-label', collapsed ? 'Expandir menú' : 'Colapsar menú');
        const icon = btn.querySelector('.material-symbols-elev');
        if (icon) icon.textContent = collapsed ? 'chevron_right' : 'chevron_left';
        try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch (_) {}
        if (collapsed) {
            try {
                if (!localStorage.getItem(SIDEBAR_HINT_KEY)) {
                    localStorage.setItem(SIDEBAR_HINT_KEY, '1');
                    if (window.apiToast) {
                        window.apiToast('Menú colapsado. Tu preferencia está guardada.', 'info');
                    }
                }
            } catch (_) {}
        }
    }

    _findActiveLink() {
        const path = (window.location.pathname || '/').replace(/\/+$/, '') || '/';
        const links = this.element.querySelectorAll('#sanctum-sidebar .sanctum-link[data-page]');
        let best = null, bestLen = -1;
        links.forEach((a) => {
            const href = (a.getAttribute('href') || '').replace(/\/+$/, '') || '/';
            if (path === href || path.startsWith(href + '/') || (href === '/' && path.startsWith('/'))) {
                if (href.length > bestLen) {
                    best = a;
                    bestLen = href.length;
                }
            }
        });
        return best;
    }

    _clearActive() {
        this.element.querySelectorAll('#sanctum-sidebar .sanctum-link.active').forEach((el) => {
            el.classList.remove('active');
            el.removeAttribute('aria-current');
        });
    }

    paintNavIndicator() {
        if (!this.hasNavIndicatorTarget) return;
        const active = this.element.querySelector('#sanctum-sidebar .sanctum-link.active');
        if (!active) return;
        this.navIndicatorTarget.style.transform =
            'translateY(' + active.offsetTop + 'px)';
        this.navIndicatorTarget.style.height = active.offsetHeight + 'px';
        this.navIndicatorTarget.style.opacity = '1';
    }

    // ─── Section toggles (click + keyboard) ─────────────────

    _wireSectionToggles() {
        const titles = this.element.querySelectorAll('#sanctum-sidebar [data-section-toggle]');
        titles.forEach((title) => {
            title.setAttribute('aria-expanded', 'true');
            // NOTE: click is handled by data-action="click->shell#toggleSection"
            // in the template — do NOT add another click listener here or
            // sections toggle twice per click. keydown has no declarative
            // equivalent, so it stays imperative.
            title.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    this.toggleSection({ currentTarget: title, originalEvent: ev });
                }
            });
        });
    }

    _wireSidebarLinks() {
        // Re-run active-nav highlight on every Turbo navigation. The link
        // itself is plain `<a>` so Turbo intercepts the click; we only need
        // to keep the indicator pointing at the right one. Bound refs are
        // stored so disconnect() can remove them (no stacking).
        this._onTurboRender = () => this.markActiveNav();
        this._onTurboLoad = () => this.markActiveNav();
        document.addEventListener('turbo:render', this._onTurboRender);
        document.addEventListener('turbo:load', this._onTurboLoad);
    }

    // ─── Logout ──────────────────────────────────────────────
    // NOTE: no _wireLogout() on purpose — the template declares
    // data-action="click->shell#logout". A second listener would show
    // the confirm modal twice.

    // ─── Theme ──────────────────────────────────────────────

    _wireThemeToggle() {
        if (!this.hasThemeToggleBtnTarget) return;
        // NOTE: click is handled by data-action="click->shell#toggleTheme"
        // in the template — do NOT add another click listener here or the
        // theme would advance two steps per click.
        this._onThemeChange = () => this.paintTheme();
        document.documentElement.addEventListener('theme:change', this._onThemeChange);
        // theme.js loads deferred; tnsvtTheme may not be ready yet. Retry
        // up to ~3s. The previous inline IIFE loop is replaced by this
        // controller's onThemeReady hook called from the retry.
        this._tryPaintTheme();
    }

    _tryPaintTheme(attempts = 0) {
        if (!this.hasThemeToggleBtnTarget) return;
        if (window.tnsvtTheme) {
            this.paintTheme();
            return;
        }
        if (attempts < 30) {
            setTimeout(() => this._tryPaintTheme(attempts + 1), 100);
        }
    }

    paintTheme() {
        if (!this.hasThemeToggleBtnTarget || !this.hasThemeIconTarget) return;
        if (!window.tnsvtTheme) return;
        const cur = window.tnsvtTheme.get();
        const dark = window.tnsvtTheme.isDark();
        this.themeIconTarget.textContent = dark ? 'dark_mode' : 'light_mode';
        const btn = this.themeToggleBtnTarget;
        btn.setAttribute('aria-label', 'Tema actual: ' + cur + '. Activar para cambiar.');
        btn.title = 'Tema: ' + cur + ' (click para cambiar)';
    }

    // ─── Notification badge poll ─────────────────────────────

    _wireNotifPoll() {
        this.refreshNotifBadge();
        if (typeof window.apiPoller === 'function') {
            this._notifPollId = window.apiPoller(
                () => this.refreshNotifBadge(), 60000);
        }
    }

    async refreshNotifBadge() {
        if (!window.TNSVT_USER) return;
        const doPaint = (data) => {
            const n = (data && data.count) || 0;
            const label = n > 99 ? '99+' : String(n);
            if (this.hasBellBadgeTarget) {
                this.bellBadgeTarget.textContent = label;
                this.bellBadgeTarget.classList.toggle('hidden', n === 0);
                if (n > this._notifPrev) this._triggerBadgePop(this.bellBadgeTarget);
            }
            if (this.hasHeaderNotifBadgeTarget) {
                this.headerNotifBadgeTarget.textContent = label;
                this.headerNotifBadgeTarget.classList.toggle('hidden', n === 0);
                if (n > this._notifPrev) this._triggerBadgePop(this.headerNotifBadgeTarget);
            }
            this._notifPrev = n;
            this._notifFirstLoad = false;
        };
        if (window.apiFetch) {
            const r = await window.apiFetch('/api/notifications/count', {
                silent: true, redirectOn401: false,
            }).catch(() => null);
            if (r && r.ok && r.data) { doPaint(r.data); return; }
        }
        // Fallback: raw fetch. Kept for legacy callers.
        try {
            const fr = await fetch('/notifications/unread-count');
            const json = fr.ok ? await fr.json() : { count: 0 };
            doPaint(json);
        } catch (_) { /* offline — fine, last value stays */ }
    }

    _triggerBadgePop(el) {
        if (!el || this._notifFirstLoad) return;
        el.classList.remove('is-popping');
        // Force reflow so the animation restarts each new poll.
        void el.offsetWidth;
        el.classList.add('is-popping');
        setTimeout(() => el.classList.remove('is-popping'), 600);
    }

    // ─── User info hydration ─────────────────────────────────

    async loadUserInfo() {
        if (!window.apiFetch) return;
        const r = await window.apiFetch('/api/auth/check').catch(() => null);
        if (!r || !r.ok) return;
        if (!r.data || !r.data.user) return;
        const u = r.data.user;
        window.TNSVT_USER = u;
        window.dispatchEvent(new CustomEvent('tnsvt:user-loaded', { detail: u }));
        this.paintUserInfo(u);
    }

    paintUserInfo(u) {
        const code = (u.code || '??').slice(0, 2);
        if (this.hasUserAvatarTarget) this.userAvatarTarget.textContent = code;
        if (this.hasUserNameTarget)    this.userNameTarget.textContent = u.name || u.code || 'Invitado';
        if (this.hasUserTierTarget) {
            const tier = u.tier || 'INITIATE';
            this.userTierTarget.textContent = tier;
            this.userTierTarget.className = 'tier-badge-elev tier-' +
                tier.toLowerCase().replace(/_/g, '-');
        }
    }

    // ─── Rail tooltip (sidebar collapsed <1024px) ───────────

    static get shouldMountRailTooltip() { return true; }
    /* The rail tooltip is initialized in connect() but uses raw DOM
       manipulation since it's a singleton floating element with no
       Stimulus-managed mount. */

    /* eslint-disable no-unused-vars */
    _initRailTooltip() {
        // Idempotent: if the singleton tooltip element already exists from a
        // previous mount, reuse it. This handles the edge case where a user
        // visits a shell page, navigates to /frequencies (where this
        // controller is disconnected), then returns to the shell.
        let tip = document.getElementById('sanctum-rail-tooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'sanctum-rail-tooltip';
            document.body.appendChild(tip);
        }

        const show = (link) => {
            if (window.innerWidth > RAIL_BP) return;
            const label = link.querySelector('.sanctum-link-label');
            if (!label) return;
            tip.textContent = label.textContent;
            tip.classList.add('visible');
            const r = link.getBoundingClientRect();
            tip.style.left = (r.right + 12) + 'px';
            tip.style.top = Math.round(r.top + r.height / 2) + 'px';
        };
        const hide = () => tip.classList.remove('visible');

        // Clear any previous handlers on each link before re-binding, so
        // a re-mount never stacks mouseenter/mouseleave listeners.
        document.querySelectorAll('#sanctum-sidebar .sanctum-link').forEach((link) => {
            link.removeEventListener('mouseenter', this._railShowFor);
            link.removeEventListener('mouseleave', this._railHideFor);
            link.removeEventListener('focus', this._railShowFor);
            link.removeEventListener('blur', this._railHideFor);
        });

        this._railShowFor = (e) => show(e.currentTarget);
        this._railHideFor = hide;

        document.querySelectorAll('#sanctum-sidebar .sanctum-link').forEach((link) => {
            link.addEventListener('mouseenter', this._railShowFor);
            link.addEventListener('mouseleave', this._railHideFor);
            link.addEventListener('focus', this._railShowFor);
            link.addEventListener('blur', this._railHideFor);
        });
        this._onResize = () => hide();
        window.addEventListener('resize', this._onResize);
    }
    /* eslint-enable no-unused-vars */
}
