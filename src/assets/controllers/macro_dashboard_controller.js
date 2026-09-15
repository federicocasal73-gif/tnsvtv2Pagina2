import { Controller } from '@hotwired/stimulus';

/**
 * Macro dashboard (/macro) — bento grid, no-trade-window timeline,
 * oracle SVG charts, questionnaires modal.
 *
 * Extracted from templates/macro/dashboard.html.twig inline <script>
 * (~1000 LoC). Same endpoints, same markup, same behaviour.
 *
 * Why migrate: the inline <script> re-ran on every Turbo navigation
 * because <main> is replaced while only shell regions are permanent.
 * Each re-run stacked: a 5s freshness setInterval, a 1s NTW countdown
 * setInterval, two apiPoller loops (5min bento + 60s windows), a
 * document-level click delegation, a document-level keydown handler
 * for discipline rules, and the modal 1-9 keydown handler. After N
 * visits there were N timers polling the API concurrently.
 * Stimulus connect()/disconnect() keeps exactly one of each.
 *
 * Lookup strategy: all element IDs live inside this.element
 * (the page wrapper), so a scoped `this.el(id)` helper replaces the
 * old document.getElementById calls without touching 60+ template
 * attributes. Only `data-controller="macro-dashboard"` was added
 * to the wrapper; the inline <script> was deleted.
 */
const MACRO_QUESTIONNAIRES = {
    risk_profile: {
        card: 'risk-profile-card', body: 'risk-profile-body',
        result: 'risk-profile-result', tierBadge: 'risk-profile-tier-badge',
        scoreEl: 'risk-profile-score', tierEl: 'risk-profile-tier',
    },
    market_knowledge: {
        card: 'market-knowledge-card', body: 'market-knowledge-body',
        result: 'market-knowledge-result', tierBadge: 'market-knowledge-tier-badge',
        scoreEl: 'market-knowledge-score', tierEl: 'market-knowledge-tier',
    },
};

// Reminder timer must survive disconnect (the browser Notification
// should still fire even if the user navigated away), so it lives at
// module scope — single instance by construction.
let _bentoReminderTimer = null;

function fmtDuration(seconds) {
    if (seconds < 0) seconds = 0;
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m`;
    if (m > 0) return `${m}m ${String(s).padStart(2, '0')}s`;
    return `${s}s`;
}
const formatTime = fmtDuration;

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[m]));
}
function formatBsAs(iso) {
    try {
        const d = new Date(iso);
        return d.toLocaleString('es-AR', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short' });
    } catch (e) { return iso; }
}

export default class extends Controller {
    connect() {
        this._bentoState = null;
        this._bentoLastUpdate = null;
        this.allWindows = [];
        this.macroUserCode = (window.TNSVT_USER && window.TNSVT_USER.code) || 'DEMO';

        this._onDocClick = (e) => this.onDocClick(e);
        this._onDocKeydown = (e) => this.onDocKeydown(e);
        document.addEventListener('click', this._onDocClick);
        document.addEventListener('keydown', this._onDocKeydown);

        this.wireBento();
        this.loadMacroBento();
        this.loadAll();
        this.renderOracleCharts();
        this.initMacroQSummary();
        this.loadMacroQuestionnaire('risk_profile');
        this.loadMacroQuestionnaire('market_knowledge');

        this._freshnessInterval = setInterval(() => this.tickBentoFreshness(), 5 * 1000);
        if (typeof window.apiPoller === 'function') {
            this._bentoPoller = window.apiPoller(() => this.loadMacroBento(), 5 * 60 * 1000);
            this._allPoller = window.apiPoller(() => this.loadAll(), 60000);
        }
        const submitBtn = this.el('macro-questionnaire-submit-btn');
        if (submitBtn) submitBtn.addEventListener('click', () => this.submitMacroQuestionnaire());
    }

    disconnect() {
        if (this._freshnessInterval) clearInterval(this._freshnessInterval);
        if (this._countdownInterval) clearInterval(this._countdownInterval);
        for (const p of ['_bentoPoller', '_allPoller']) {
            if (this[p] && typeof this[p].stop === 'function') this[p].stop();
            this[p] = null;
        }
        document.removeEventListener('click', this._onDocClick);
        document.removeEventListener('keydown', this._onDocKeydown);
        const modal = this.el('macro-questionnaire-modal');
        if (modal && modal._cleanupKeydown) {
            modal._cleanupKeydown();
            modal._cleanupKeydown = null;
        }
        // NOTE: _bentoReminderTimer intentionally survives disconnect —
        // the user asked for a Notification; killing it on navigation
        // would silently drop their reminder.
    }

    /** Scoped lookup: every macro ID lives inside this.element. */
    el(id) {
        return this.element.querySelector('#' + CSS.escape(id));
    }

    // ══════ F3 — Bento Grid ══════

    async loadMacroBento() {
        const grid = this.el('bento-counter-text');
        if (!grid) return;
        const tz = (this.el('bento-tz') || { value: 'America/Argentina/Buenos_Aires' }).value;
        try {
            const r = await window.apiFetch('/api/macro/bento?tz=' + encodeURIComponent(tz), { silent: true });
            if (!r.ok || !r.data || !r.data.success) {
                grid.textContent = 'Sin datos';
                return;
            }
            this._bentoState = r.data;
            this.renderBento(r.data);
            this.updateBentoLastUpdate();
        } catch (e) {
            grid.textContent = 'Sin conexión';
        }
    }

    updateBentoLastUpdate() {
        this._bentoLastUpdate = new Date();
        const el = this.el('bento-last-update');
        if (!el) return;
        el.textContent = 'Recién';
        el.classList.remove('stale');
    }

    tickBentoFreshness() {
        if (!this._bentoLastUpdate) return;
        const el = this.el('bento-last-update');
        if (!el) return;
        const secs = Math.floor((Date.now() - this._bentoLastUpdate.getTime()) / 1000);
        if (secs < 5)        el.textContent = 'Recién';
        else if (secs < 60)  el.textContent = `hace ${secs}s`;
        else if (secs < 3600) el.textContent = `hace ${Math.floor(secs / 60)}m`;
        else                 el.textContent = `hace ${Math.floor(secs / 3600)}h`;
        if (secs > 600) el.classList.add('stale');
        else el.classList.remove('stale');
    }

    renderBento(data) {
        const next = data.next_critical;
        const win = data.window;

        const counterEl = this.el('bento-counter-text');
        if (counterEl) {
            const total = data.upcoming_count || 0;
            const critical = data.critical_this_week_count || 0;
            counterEl.textContent = `${total} eventos próximos · ${critical} críticos esta semana`;
        }

        const impactEl = this.el('bento-critical-impact');
        const titleEl = this.el('bento-critical-title');
        const countryEl = this.el('bento-critical-country');
        const timeEl = this.el('bento-critical-time');
        const tzEl = this.el('bento-critical-tz');
        const countdownEl = this.el('bento-critical-countdown');
        const forecastEl = this.el('bento-critical-forecast');
        const previousEl = this.el('bento-critical-previous');
        const actualEl = this.el('bento-critical-actual');

        if (next) {
            if (impactEl) {
                impactEl.textContent = `IMPACT ${next.importance || 3}`;
                impactEl.classList.remove('muted');
            }
            if (titleEl) titleEl.textContent = next.title || next.original_title || '—';
            if (countryEl) countryEl.textContent = `${next.country || ''} ${next.currency || ''}`.trim() || '—';
            if (timeEl) timeEl.textContent = `${next.date || ''} ${next.time || ''}`;
            if (tzEl) tzEl.textContent = data.tz_label || '—';
            if (forecastEl) forecastEl.textContent = next.forecast || '—';
            if (previousEl) previousEl.textContent = next.previous || '—';
            if (actualEl) actualEl.textContent = next.actual || '—';
            if (countdownEl && data.next_critical) {
                if (data.next_critical.seconds_until_event > 0) {
                    countdownEl.textContent = 'En ' + fmtDuration(data.next_critical.seconds_until_event);
                    countdownEl.classList.remove('active');
                    countdownEl.classList.remove('cleared');
                } else {
                    countdownEl.textContent = 'EN VIVO';
                    countdownEl.classList.add('active');
                }
            }
        } else {
            if (impactEl) { impactEl.textContent = '—'; impactEl.classList.add('muted'); }
            if (titleEl) titleEl.textContent = 'Sin eventos críticos próximos';
            if (countryEl) countryEl.textContent = '—';
            if (timeEl) timeEl.textContent = '—';
            if (tzEl) tzEl.textContent = '—';
            if (forecastEl) forecastEl.textContent = '—';
            if (previousEl) previousEl.textContent = '—';
            if (actualEl) actualEl.textContent = '—';
            if (countdownEl) {
                countdownEl.textContent = '✓ Libre';
                countdownEl.classList.add('cleared');
            }
        }

        const winStatusEl = this.el('bento-window-status');
        const winTitleEl = this.el('bento-window-title');
        const winMetaEl = this.el('bento-window-meta');
        const winCountdownEl = this.el('bento-window-countdown');

        if (win) {
            if (winStatusEl) winStatusEl.classList.remove('clear', 'active');
            if (win.is_active) {
                if (winStatusEl) { winStatusEl.textContent = 'ACTIVA'; winStatusEl.classList.add('active'); }
                if (winTitleEl) winTitleEl.textContent = '⛔ NO OPERAR';
                if (winCountdownEl) {
                    winCountdownEl.textContent = 'Ends in ' + fmtDuration(win.seconds_until_end);
                    winCountdownEl.classList.add('active');
                }
            } else {
                if (winStatusEl) winStatusEl.textContent = 'PRÓXIMA';
                if (winTitleEl) winTitleEl.textContent = win.reason || '—';
                if (winCountdownEl) {
                    winCountdownEl.textContent = 'En ' + fmtDuration(win.seconds_until_start);
                    winCountdownEl.classList.remove('active');
                }
            }
            if (winMetaEl) winMetaEl.textContent = `± 30 min · starts ${formatBsAs(win.starts_at)}`;
        } else {
            if (winStatusEl) { winStatusEl.textContent = 'CLEAR'; winStatusEl.classList.add('clear'); }
            if (winTitleEl) winTitleEl.textContent = 'Sin ventanas activas';
            if (winCountdownEl) {
                winCountdownEl.textContent = '✓';
                winCountdownEl.classList.add('cleared');
            }
            if (winMetaEl) winMetaEl.textContent = '';
        }

        const pairsTitle = this.el('bento-pairs-title');
        const pairsList = this.el('bento-pairs-list');
        const pairs = data.affected_pairs || [];
        if (pairsList) {
            if (pairs.length > 0 && next) {
                if (pairsTitle) pairsTitle.textContent = `${next.currency || 'USD'} mueve:`;
                pairsList.innerHTML = pairs.map((p) => {
                    const w = Math.max(8, Math.min(100, p.confidence || 0));
                    return `
                        <li class="bento-pair-row">
                            <span class="bento-pair-name">${esc(p.pair)}</span>
                            <div class="bento-pair-bar"><div class="bento-pair-bar-fill" style="width:${w}%"></div></div>
                            <span class="bento-pair-conf">${p.confidence}%</span>
                            <span class="bento-pair-rationale">${esc(p.rationale)}</span>
                        </li>
                    `;
                }).join('');
            } else {
                if (pairsTitle) pairsTitle.textContent = 'Sin próximo evento';
                pairsList.innerHTML = `
                    <li class="bento-pair-empty">
                        <span class="material-symbols-outlined">sailing</span>
                        Mar en calma.
                        <br>El próximo evento crítico activará la lista.
                    </li>
                `;
            }
        }

        this.restoreReminderUI();
    }

    async requestNotificationPermissionIfNeeded() {
        if (!('Notification' in window)) return false;
        if (Notification.permission === 'granted') return true;
        if (Notification.permission === 'denied') return false;
        try {
            const perm = await Notification.requestPermission();
            return perm === 'granted';
        } catch (e) { return false; }
    }

    setReminder(eventIso, remindBeforeSec = 900) {
        const eventDt = new Date(eventIso).getTime();
        if (isNaN(eventDt)) return false;
        const remindAt = eventDt - remindBeforeSec * 1000;
        if (remindAt <= Date.now()) return false;
        localStorage.setItem('tnsvt_bento_reminder', JSON.stringify({
            eventIso, remindAtSec: remindBeforeSec,
        }));
        if (_bentoReminderTimer) clearTimeout(_bentoReminderTimer);
        const ms = Math.max(0, remindAt - Date.now());
        _bentoReminderTimer = setTimeout(() => this.triggerReminder(), ms);
        return true;
    }

    clearReminder() {
        localStorage.removeItem('tnsvt_bento_reminder');
        if (_bentoReminderTimer) clearTimeout(_bentoReminderTimer);
        _bentoReminderTimer = null;
    }

    triggerReminder() {
        if (!(this._bentoState && this._bentoState.next_critical)) return;
        const evt = this._bentoState.next_critical;
        this.showReminderBanner(evt);
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification('⏰ Recordatorio macro · 15 min', {
                    body: `${evt.title} ${evt.currency} en 15 min · No operes durante ±30 min.`,
                    icon: '/assets/icons/icon-192.png',
                });
            } catch (e) { /* no-op */ }
        }
    }

    showReminderBanner(evt) {
        const main = document.querySelector('main') || document.body;
        const existing = document.getElementById('bento-reminder-banner');
        if (existing) existing.remove();
        const banner = document.createElement('div');
        banner.id = 'bento-reminder-banner';
        banner.style.cssText = 'position:sticky;top:0;z-index:50;background:rgba(248,113,113,0.18);border:1px solid #f87171;color:#fecaca;padding:0.6rem 1rem;border-radius:0.4rem;backdrop-filter:blur(8px);margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:0.5rem;';
        banner.innerHTML = `
            <span><strong>⏰ 15 min para:</strong> ${esc(evt.title)} (${esc(evt.currency)}) · cerrá posiciones o esperá</span>
            <button type="button" id="bento-reminder-dismiss" style="background:transparent;border:1px solid #f87171;color:#fecaca;padding:0.25rem 0.5rem;border-radius:0.3rem;cursor:pointer;">OK</button>
        `;
        main.insertBefore(banner, main.firstChild);
        document.getElementById('bento-reminder-dismiss').addEventListener('click', () => banner.remove());
    }

    restoreReminderUI() {
        const remindBtn = this.el('bento-remind-btn');
        const reminderConfig = this.el('bento-reminder-config');
        const reminderWhen = this.el('bento-reminder-when');
        const stored = localStorage.getItem('tnsvt_bento_reminder');
        if (!stored) {
            if (remindBtn) remindBtn.classList.remove('active');
            if (reminderConfig) reminderConfig.hidden = true;
            return;
        }
        try {
            const r = JSON.parse(stored);
            if (remindBtn) remindBtn.classList.add('active');
            if (reminderConfig) reminderConfig.hidden = false;
            if (reminderWhen) {
                reminderWhen.textContent = new Date(r.eventIso).toLocaleString('es-AR');
            }
            const remindAt = new Date(r.eventIso).getTime() - r.remindAtSec * 1000;
            const ms = Math.max(0, remindAt - Date.now());
            if (ms > 0 && !_bentoReminderTimer) {
                _bentoReminderTimer = setTimeout(() => this.triggerReminder(), ms);
            }
        } catch (e) {
            localStorage.removeItem('tnsvt_bento_reminder');
        }
    }

    wireBento() {
        const remindBtn = this.el('bento-remind-btn');
        const muteBtn = this.el('bento-mute-btn');
        const refreshBtn = this.el('bento-refresh');
        const tzSelect = this.el('bento-tz');
        if (!remindBtn) return;

        if (tzSelect) {
            const saved = localStorage.getItem('tnsvt_macro_tz');
            if (saved) {
                const opt = Array.from(tzSelect.options).find((o) => o.value === saved);
                if (opt) tzSelect.value = saved;
            }
            tzSelect.addEventListener('change', () => {
                localStorage.setItem('tnsvt_macro_tz', tzSelect.value);
                this.loadMacroBento();
            });
        }

        remindBtn.addEventListener('click', async () => {
            if (!this._bentoState || !this._bentoState.next_critical || !this._bentoState.window) {
                if (window.apiToast) window.apiToast('No hay un próximo evento crítico para recordarte.', 'info');
                return;
            }
            const existing = localStorage.getItem('tnsvt_bento_reminder');
            if (existing) {
                if (await window.apiConfirm('¿Cancelar el recordatorio actual?', { title: 'Cancelar recordatorio' })) {
                    this.clearReminder();
                    this.restoreReminderUI();
                }
                return;
            }
            await this.requestNotificationPermissionIfNeeded();
            const set = this.setReminder(this._bentoState.window.starts_at, 15 * 60);
            if (!set) {
                if (window.apiToast) window.apiToast('El evento empieza en menos de 15 minutos. Recordatorio no aplica.', 'warning');
                return;
            }
            this.restoreReminderUI();
        });
        if (muteBtn) muteBtn.addEventListener('click', () => this.clearReminder());
        if (refreshBtn) refreshBtn.addEventListener('click', () => this.loadMacroBento());
    }

    // ══════ NTW windows / timeline / upcoming ══════

    async loadAll() {
        try {
            const resp = await fetch('/api/macro/windows?limit=30');
            const data = await resp.json();
            if (data.success) {
                this.allWindows = data.windows;
                this.renderTimeline(this.allWindows);
                this.renderUpcoming(this.allWindows);
                this.updateStatus(data.windows);
            }
        } catch (e) {
            console.error(e);
        }
    }

    updateStatus(windows) {
        const now = new Date();
        const active = windows.find((w) => w.is_active);
        const upcoming = windows
            .filter((w) => !w.is_active && new Date(w.start) > now)
            .sort((a, b) => new Date(a.start) - new Date(b.start));

        const titleEl = this.el('ntw-title');
        const msgEl = this.el('ntw-message');
        const iconEl = this.el('ntw-icon');
        const countdownEl = this.el('ntw-countdown');
        const labelEl = this.el('ntw-countdown-label');
        const bannerEl = this.el('ntw-banner');
        if (!titleEl || !bannerEl) return;

        if (this._countdownInterval) clearInterval(this._countdownInterval);

        if (active) {
            titleEl.textContent = '🔴 No-Trade Window ACTIVA';
            if (msgEl) msgEl.innerHTML = `<strong>${active.title}</strong> (${active.country} ${active.currency}) · impact ${active.importance}`;
            if (iconEl) iconEl.style.background = '#ff6b6b';
            if (countdownEl) countdownEl.textContent = formatTime(active.seconds_until_end);
            if (labelEl) labelEl.textContent = 'Hasta el final del window';
            bannerEl.style.borderLeftColor = '#ff6b6b';
        } else if (upcoming.length > 0) {
            const next = upcoming[0];
            titleEl.textContent = 'Próximo: ' + next.title;
            if (msgEl) msgEl.innerHTML = `${next.country} ${next.currency} · impact ${next.importance} · ${new Date(next.start).toLocaleString()}`;
            if (iconEl) iconEl.style.background = 'var(--gold-elev)';
            if (labelEl) labelEl.textContent = 'Inicia en';
            bannerEl.style.borderLeftColor = 'var(--gold-elev)';

            this._countdownInterval = setInterval(() => {
                const remaining = new Date(next.start).getTime() - Date.now();
                if (countdownEl) countdownEl.textContent = formatTime(Math.max(0, Math.floor(remaining / 1000)));
                if (remaining <= 0) this.loadAll();
            }, 1000);
        } else {
            titleEl.textContent = '✓ Sin eventos próximos';
            if (msgEl) msgEl.textContent = 'No hay no-trade windows en el horizonte cercano';
            if (iconEl) iconEl.style.background = 'var(--glass-bg-elev)';
            if (countdownEl) countdownEl.textContent = 'LIBRE';
            if (labelEl) labelEl.textContent = 'No events';
            bannerEl.style.borderLeftColor = 'var(--gold-elev)';
        }
    }

    renderTimeline(windows) {
        const svg = this.el('timeline-svg');
        if (!svg) return;
        const w = 800, h = 280;
        const now = new Date();
        const range = 7 * 24 * 60 * 60 * 1000;
        const startMs = now.getTime();
        const xScale = (ms) => 40 + ((ms - startMs) / range) * (w - 60);
        const yHigh = h - 50;
        const yMid = h - 80;
        const yLow = h - 110;

        let html = '';
        const nowX = xScale(now.getTime());
        html += `<line x1="${nowX}" y1="20" x2="${nowX}" y2="${h - 30}" stroke="var(--gold-elev)" stroke-width="1.5" />`;
        html += `<text x="${nowX}" y="14" text-anchor="middle" font-size="10" fill="var(--gold-elev)">NOW</text>`;
        for (let d = 0; d <= 7; d++) {
            const dayX = xScale(startMs + d * 24 * 60 * 60 * 1000);
            html += `<line x1="${dayX}" y1="${h - 30}" x2="${dayX}" y2="${h - 25}" stroke="var(--outline-variant-elev)" stroke-width="0.5" />`;
            const dayDate = new Date(startMs + d * 24 * 60 * 60 * 1000);
            html += `<text x="${dayX}" y="${h - 12}" text-anchor="middle" font-size="9" fill="var(--outline-elev)">${dayDate.getDate()}/${dayDate.getMonth() + 1}</text>`;
        }
        html += `<line x1="40" y1="${h - 30}" x2="${w - 20}" y2="${h - 30}" stroke="var(--outline-variant-elev)" stroke-width="0.5" />`;
        for (const win of windows) {
            const ws = new Date(win.start).getTime();
            const we = new Date(win.end).getTime();
            const x1 = Math.max(40, xScale(ws));
            const x2 = Math.min(w - 20, xScale(we));
            if (x2 < x1) continue;
            const isActive = win.is_active;
            const color = isActive ? '#ff6b6b' : 'var(--gold-elev)';
            const opacity = isActive ? '0.8' : '0.4';
            const y = win.importance >= 3 ? yHigh : (win.importance >= 2 ? yMid : yLow);
            const wpx = x2 - x1;
            html += `<rect x="${x1}" y="${y - 8}" width="${wpx}" height="16" fill="${color}" fill-opacity="${opacity}" rx="2"><title>${esc(win.title)} (${esc(win.country)})</title></rect>`;
            html += `<line x1="${x1}" y1="${y - 14}" x2="${x1}" y2="${y + 14}" stroke="${color}" stroke-width="1.5" />`;
            if (wpx > 40) {
                const displayTitle = win.title.length > 18 ? win.title.substring(0, 15) + '...' : win.title;
                html += `<text x="${x1 + 2}" y="${y + 3}" font-size="8" fill="white" pointer-events="none">${esc(displayTitle)}</text>`;
            }
        }
        html += `<rect x="40" y="10" width="10" height="10" fill="var(--gold-elev)" fill-opacity="0.4" />`;
        html += `<text x="55" y="19" font-size="9" fill="var(--outline-elev)">Próximo</text>`;
        html += `<rect x="100" y="10" width="10" height="10" fill="#ff6b6b" fill-opacity="0.8" />`;
        html += `<text x="115" y="19" font-size="9" fill="var(--outline-elev)">Activo</text>`;
        svg.innerHTML = html;
    }

    renderUpcoming(windows) {
        const list = this.el('upcoming-list');
        if (!list) return;
        const now = new Date();
        const upcoming = windows
            .filter((w) => !w.is_active && new Date(w.start) > now)
            .sort((a, b) => new Date(a.start) - new Date(b.start));
        if (upcoming.length === 0) {
            list.innerHTML = '<p class="text-center text-[var(--outline-elev)] py-8">Sin eventos próximos</p>';
            return;
        }
        list.innerHTML = upcoming.slice(0, 15).map((w) => {
            const startDt = new Date(w.start);
            const seconds = w.seconds_until_start;
            const urgency = seconds < 3600 ? 'urgent' : (seconds < 21600 ? 'soon' : 'later');
            return `
                <div class="flex items-center gap-3 p-2 rounded glass-card-elev border-l-2 ${urgency === 'urgent' ? 'border-red-400' : 'border-[var(--gold-elev)]'}">
                    <div class="text-center min-w-12">
                        <p class="text-2xl font-bold text-[var(--gold-elev)]">${startDt.getDate()}</p>
                        <p class="text-xs text-[var(--outline-elev)] uppercase">${['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][startDt.getMonth()]}</p>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-[var(--on-surface-elev)] truncate">${esc(w.title)}</p>
                        <p class="text-xs text-[var(--outline-elev)]">${esc(w.country)} ${esc(w.currency)} · ${startDt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-[var(--outline-elev)] uppercase">En</p>
                        <p class="text-sm font-bold ${urgency === 'urgent' ? 'text-red-400' : 'text-[var(--gold-elev)]'}">${formatTime(seconds)}</p>
                    </div>
                </div>
            `;
        }).join('');
    }

    // ══════ Oracle SVG charts ══════

    renderOracleCharts() {
        this.renderFaithLogicGauge();
        this.renderEmotionalScatter();
        this.renderSessionBars();
    }

    async renderFaithLogicGauge() {
        const svg = this.el('faith-logic-gauge');
        if (!svg) return;
        let faith = 50;
        try {
            const code = (window.TNSVT_USER && window.TNSVT_USER.code) || '';
            if (code) {
                const r = await window.apiFetch(`/sanctum/api/oracle/faith-logic?code=${encodeURIComponent(code)}&days=30`, { silent: true });
                const g = r && r.ok && r.data ? (r.data.gauge || r.data) : null;
                if (g && Number(g.total) > 0) {
                    const v = Number(g.faith ?? NaN);
                    if (Number.isFinite(v)) faith = Math.min(100, Math.max(0, v));
                }
            }
        } catch (e) { /* fall back to 50/50 */ }
        const cx = 100, cy = 100, r = 80;
        const angle = -90 + (faith / 100) * 180;
        const rad = (angle * Math.PI) / 180;
        const needleX = cx + (r - 12) * Math.cos(rad);
        const needleY = cy + (r - 12) * Math.sin(rad);
        let html = '';
        html += `<path d="M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}" stroke="rgba(255,255,255,0.08)" stroke-width="12" fill="none"/>`;
        html += `<path d="M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${needleX} ${needleY}" stroke="url(#gauge-grad)" stroke-width="12" fill="none" stroke-linecap="round"/>`;
        html += `<defs><linearGradient id="gauge-grad" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stop-color="#f2ca50"/><stop offset="100%" stop-color="#8a3cff"/>
        </linearGradient></defs>`;
        html += `<line x1="${cx}" y1="${cy}" x2="${needleX}" y2="${needleY}" stroke="#f2ca50" stroke-width="2" stroke-linecap="round"/>`;
        html += `<circle cx="${cx}" cy="${cy}" r="6" fill="#f2ca50"/>`;
        html += `<text x="20" y="180" font-size="11" fill="#f2ca50" font-weight="600">FAITH</text>`;
        html += `<text x="155" y="180" font-size="11" fill="#99907c" font-weight="600">LOGIC</text>`;
        svg.innerHTML = html;
    }

    renderEmotionalScatter() {
        const svg = this.el('emotional-scatter');
        if (!svg) return;
        const points = [];
        for (let i = 0; i < 30; i++) {
            const x = Math.random() * 380 + 10;
            const y = Math.random() * 180 + 10;
            const rr = Math.random() * 4 + 2;
            const opacity = Math.random() * 0.5 + 0.3;
            points.push(`<circle cx="${x}" cy="${y}" r="${rr}" fill="#f2ca50" fill-opacity="${opacity}" />`);
        }
        const axes = `
            <line x1="40" y1="180" x2="380" y2="180" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>
            <line x1="40" y1="20" x2="40" y2="180" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>
            <text x="20" y="30" font-size="9" fill="#99907c" text-anchor="middle">High</text>
            <text x="20" y="180" font-size="9" fill="#99907c" text-anchor="middle">Low</text>
            <text x="380" y="195" font-size="9" fill="#99907c" text-anchor="end">Conf →</text>
        `;
        svg.innerHTML = axes + points.join('');
    }

    renderSessionBars() {
        const svg = this.el('session-bars');
        if (!svg) return;
        const sessions = [
            { name: 'Asia', value: -120 },
            { name: 'London', value: 280 },
            { name: 'NY', value: 450 },
            { name: 'Late', value: -80 },
        ];
        const max = Math.max(...sessions.map((s) => Math.abs(s.value)));
        const barWidth = 60;
        const gap = 30;
        const startX = 50;
        let html = '';
        html += `<line x1="40" y1="170" x2="380" y2="170" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>`;
        sessions.forEach((s, i) => {
            const x = startX + i * (barWidth + gap);
            const height = (Math.abs(s.value) / max) * 130;
            const y = s.value >= 0 ? 170 - height : 170;
            const fill = s.value >= 0 ? 'url(#bar-grad-pos)' : '#f87171';
            html += `<rect x="${x}" y="${y}" width="${barWidth}" height="${height}" fill="${fill}" rx="4" fill-opacity="0.85"/>`;
            html += `<text x="${x + barWidth / 2}" y="190" font-size="10" fill="#99907c" text-anchor="middle">${s.name}</text>`;
            html += `<text x="${x + barWidth / 2}" y="${y - 5}" font-size="10" fill="#f2ca50" text-anchor="middle" font-weight="600">${s.value >= 0 ? '+' : ''}$${s.value}</text>`;
        });
        html += `<defs><linearGradient id="bar-grad-pos" x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stop-color="#f2ca50" stop-opacity="0.9"/>
            <stop offset="100%" stop-color="#f2ca50" stop-opacity="0.3"/>
        </linearGradient></defs>`;
        svg.innerHTML = html;
    }

    // ══════ Questionnaires ══════

    async loadMacroQuestionnaire(type) {
        const cfg = MACRO_QUESTIONNAIRES[type];
        const card = this.el(cfg.card);
        const bodyEl = this.el(cfg.body);
        const tierBadge = this.el(cfg.tierBadge);
        if (!card || !bodyEl || !tierBadge) return;

        const updateSummaryForType = (completed, score, tier) => {
            const statusEl = this.el(type === 'risk_profile' ? 'macro-q-summary-rp' : 'macro-q-summary-mk');
            if (!statusEl) return;
            if (completed) {
                statusEl.textContent = `${tier.toUpperCase()} (${score}/100)`;
                statusEl.className = 'macro-q-summary-status completed';
            } else {
                statusEl.textContent = 'Pendiente';
                statusEl.className = 'macro-q-summary-status';
            }
            this.updateMacroQSummary();
        };

        try {
            const r = await window.apiFetch(`/api/macro/questionnaire/${type}?user_code=${this.macroUserCode}`);
            if (!r.ok || !r.data || !r.data.success) {
                bodyEl.innerHTML = `<p class="text-center text-[var(--error-elev)] py-4">Error al cargar cuestionario</p>`;
                updateSummaryForType(false, 0, 'unknown');
                return;
            }

            const data = r.data;
            const tier = data.tier || 'unknown';
            tierBadge.setAttribute('data-tier', tier);
            tierBadge.textContent = tier === 'unknown' ? 'Pendiente' : tier.toUpperCase();
            updateSummaryForType(data.completed, data.score || 0, tier);

            if (data.completed && data.score !== undefined) {
                let html = `<div class="questions-list">`;
                data.questions.forEach((q, idx) => {
                    const ans = data.answers[q.id];
                    const opt = q.options[ans];
                    html += `
                        <div class="question">
                            <div class="question-label">${idx + 1}. ${esc(q.label)}</div>
                            <div class="text-xs text-[var(--gold-elev)] font-semibold">Tu respuesta: ${esc(opt ? opt.label : ans)}</div>
                        </div>
                    `;
                });
                html += `</div>
                    <button type="button" class="btn-start-questionnaire" data-macro-retake="${type}">Rehacer cuestionario</button>
                `;
                bodyEl.innerHTML = html;
                const resultEl = this.el(cfg.result);
                if (resultEl) resultEl.classList.remove('hidden');
                const scoreEl = this.el(cfg.scoreEl);
                if (scoreEl) scoreEl.textContent = data.score;
                const tierEl = this.el(cfg.tierEl);
                if (tierEl) tierEl.textContent = tier.toUpperCase();
                const updatedEl = this.el(cfg.scoreEl === 'risk-profile-score' ? 'risk-profile-updated' : 'market-knowledge-updated');
                if (updatedEl && data.completed_at) {
                    try {
                        const dt = new Date(data.completed_at);
                        updatedEl.textContent = `Última actualización: ${dt.toLocaleDateString('es-AR', { day: '2-digit', month: 'short', year: 'numeric' })}`;
                    } catch (e) { /* no-op */ }
                }
            } else {
                bodyEl.innerHTML = `
                    <p class="text-center text-[var(--outline-elev)] py-4">Cuestionario pendiente</p>
                    <div class="flex justify-center">
                        <button type="button" class="btn-start-questionnaire" data-macro-start="${type}">Empezar cuestionario</button>
                    </div>
                `;
                const resultEl = this.el(cfg.result);
                if (resultEl) resultEl.classList.add('hidden');
            }
        } catch (e) {
            console.error('[macro-questionnaire] load error', e);
            bodyEl.innerHTML = `<p class="text-center text-[var(--error-elev)] py-4">Error de red</p>`;
        }
    }

    openMacroQuestionnaireModal(type) {
        const modal = this.el('macro-questionnaire-modal');
        const title = this.el('macro-questionnaire-modal-title');
        const body = this.el('macro-questionnaire-modal-body');
        if (!modal || !title || !body) return;
        title.textContent = type === 'risk_profile' ? 'Perfil de Riesgo' : 'Conocimiento del Mercado';

        window.apiFetch(`/api/macro/questionnaire/${type}?user_code=${this.macroUserCode}`).then((r) => {
            if (!r.ok || !r.data) {
                body.innerHTML = '<p>Error al cargar preguntas</p>';
                return;
            }
            const questions = r.data.questions;
            const total = questions.length;

            let html = `
                <div class="macro-q-header">
                    <div class="macro-q-progress-info">
                        <span id="macro-q-progress-text">Pregunta 1 de ${total}</span>
                        <span id="macro-q-progress-pct">0%</span>
                    </div>
                    <div class="macro-q-progress-bar">
                        <div class="macro-q-progress-fill" id="macro-q-progress-fill" style="width: 0%"></div>
                    </div>
                </div>
                <div class="questions-form">
            `;
            questions.forEach((q, idx) => {
                html += `<div class="question" data-question-idx="${idx}">
                    <div class="question-label">${idx + 1}. ${esc(q.label)}</div>
                    <div class="question-options">`;
                Object.entries(q.options).forEach(([key, opt]) => {
                    html += `<label class="question-option" data-macro-q="${q.id}" data-macro-opt="${key}">
                        <input type="radio" name="${q.id}" value="${key}">
                        <span>${esc(opt.label)}</span>
                    </label>`;
                });
                html += `</div></div>`;
            });
            html += '</div>';
            body.innerHTML = html;
            modal.dataset.type = type;
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            body.scrollTop = 0;
            const firstOpt = body.querySelector('.question-option');
            if (firstOpt) firstOpt.focus();

            const answered = new Set();
            const updateProgress = () => {
                const pct = Math.round((answered.size / total) * 100);
                const progressFill = body.querySelector('#macro-q-progress-fill');
                const progressText = body.querySelector('#macro-q-progress-text');
                const progressPct = body.querySelector('#macro-q-progress-pct');
                if (progressFill) progressFill.style.width = pct + '%';
                if (progressText) progressText.textContent = `Pregunta ${Math.min(answered.size + 1, total)} de ${total}`;
                if (progressPct) progressPct.textContent = pct + '%';
                if (answered.size === total && progressFill) {
                    progressFill.classList.add('complete');
                }
                const submitBtn = this.el('macro-questionnaire-submit-btn');
                if (submitBtn) submitBtn.disabled = answered.size < total;
            };

            body.querySelectorAll('.question-option').forEach((opt) => {
                opt.addEventListener('click', () => {
                    const qid = opt.dataset.macroQ;
                    body.querySelectorAll(`[data-macro-q="${qid}"]`).forEach((o) => o.classList.remove('selected'));
                    opt.classList.add('selected');
                    opt.querySelector('input').checked = true;
                    answered.add(qid);
                    updateProgress();
                });
            });

            const handleKeydown = (e) => {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                if (e.repeat) return;
                const key = e.key;
                if (!/^[1-9]$/.test(key)) return;
                const opts = body.querySelectorAll('.question-option');
                if (opts.length === 0) return;
                const idx = parseInt(key, 10) - 1;
                if (idx >= opts.length) return;
                opts[idx].click();
            };
            document.addEventListener('keydown', handleKeydown);
            modal._cleanupKeydown = () => document.removeEventListener('keydown', handleKeydown);
            this._submitMode = 'answer';

            updateProgress();
        });
    }

    closeMacroQuestionnaireModal() {
        const modal = this.el('macro-questionnaire-modal');
        if (!modal) return;
        if (modal._cleanupKeydown) {
            modal._cleanupKeydown();
            modal._cleanupKeydown = null;
        }
        modal.setAttribute('aria-hidden', 'true');
        modal.classList.add('hidden');
        this._submitMode = 'answer';
        const submitBtn = this.el('macro-questionnaire-submit-btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Enviar respuestas';
        }
    }

    async submitMacroQuestionnaire() {
        // After a successful submit the button flips to "Cerrar" mode.
        if (this._submitMode === 'close') {
            const modal = this.el('macro-questionnaire-modal');
            const type = modal ? modal.dataset.type : null;
            this.closeMacroQuestionnaireModal();
            if (type) this.loadMacroQuestionnaire(type);
            return;
        }
        const modal = this.el('macro-questionnaire-modal');
        const body = this.el('macro-questionnaire-modal-body');
        if (!modal || !body) return;
        const type = modal.dataset.type;
        if (!type) return;

        const inputs = modal.querySelectorAll('input[type=radio]:checked');
        if (inputs.length === 0) {
            if (window.apiToast) window.apiToast('Respondé todas las preguntas', 'error');
            return;
        }
        const answers = {};
        inputs.forEach((inp) => {
            answers[inp.name] = inp.value;
        });

        const submitBtn = this.el('macro-questionnaire-submit-btn');
        if (submitBtn) submitBtn.disabled = true;

        body.innerHTML = `
            <div class="macro-q-loading">
                <div class="macro-q-spinner"></div>
                <p>Calculando tu perfil...</p>
            </div>
        `;

        try {
            const r = await window.apiFetch(`/api/macro/questionnaire/${type}`, {
                method: 'POST',
                body: JSON.stringify({ user_code: this.macroUserCode, answers }),
            });
            if (r.ok && r.data && r.data.success) {
                const { score, tier } = r.data;
                body.innerHTML = this.renderQuestionnaireResult(type, score, tier);
                if (submitBtn) {
                    submitBtn.textContent = 'Cerrar';
                    submitBtn.disabled = false;
                }
                // Flip to close-mode so the single connect-time listener
                // handles "Cerrar" (fixes the old double-POST where the
                // connect listener AND an overwritten onclick both fired).
                this._submitMode = 'close';
                if (window.apiToast) {
                    window.apiToast(`¡Completado! Score: ${score}/100 — Tier: ${tier.toUpperCase()}`, 'success');
                }
            } else {
                const msg = (r.data && r.data.error) || 'Error al guardar';
                if (window.apiToast) window.apiToast(msg, 'error');
                if (submitBtn) submitBtn.disabled = false;
            }
        } catch (e) {
            console.error('[macro-questionnaire] submit error', e);
            if (window.apiToast) window.apiToast('Error de red', 'error');
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    renderQuestionnaireResult(type, score, tier) {
        const tierColors = {
            conservative: { color: '#4ade80', label: 'Conservador', desc: 'Prefieres preservar capital. Horizonte largo, riesgo bajo.' },
            moderate:     { color: '#f2ca50', label: 'Moderado',     desc: 'Balance entre riesgo y retorno. Estrategia estándar.' },
            aggressive:   { color: '#fb923c', label: 'Agresivo',     desc: 'Buscas retornos altos. Riesgo medio-alto, horizonte medio.' },
            degen:        { color: '#f87171', label: 'Degen',        desc: 'Máximo riesgo. All-in en cada operación.' },
        };
        const tierInfo = tierColors[tier] || tierColors.moderate;
        const pct = Math.max(0, Math.min(100, score));
        const angle = -90 + (pct / 100) * 180;
        const rad = (angle * Math.PI) / 180;
        const cx = 100, cy = 100, r = 80;
        const needleX = cx + (r - 12) * Math.cos(rad);
        const needleY = cy + (r - 12) * Math.sin(rad);

        return `
            <div class="macro-q-result">
                <h2 class="macro-q-result-title">¡Resultado calculado!</h2>
                <p class="macro-q-result-sub">${esc(type === 'risk_profile' ? 'Perfil de Riesgo' : 'Conocimiento del Mercado')}</p>

                <div class="macro-q-gauge">
                    <svg viewBox="0 0 200 110" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="gauge-grad-${type}" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#4ade80"/>
                                <stop offset="50%" stop-color="#f2ca50"/>
                                <stop offset="100%" stop-color="#f87171"/>
                            </linearGradient>
                        </defs>
                        <path d="M 20 100 A 80 80 0 0 1 180 100" stroke="rgba(255,255,255,0.1)" stroke-width="12" fill="none"/>
                        <path d="M 20 100 A 80 80 0 0 1 ${needleX.toFixed(1)} ${needleY.toFixed(1)}" stroke="url(#gauge-grad-${type})" stroke-width="12" fill="none" stroke-linecap="round" class="macro-q-gauge-fill"/>
                        <circle cx="${cx}" cy="${cy}" r="5" fill="${tierInfo.color}"/>
                    </svg>
                    <div class="macro-q-score" style="color: ${tierInfo.color}">${score}</div>
                    <div class="macro-q-score-label">de 100 puntos</div>
                </div>

                <div class="macro-q-tier-card" style="border-color: ${tierInfo.color}">
                    <div class="macro-q-tier-label" style="color: ${tierInfo.color}">${tierInfo.label.toUpperCase()}</div>
                    <div class="macro-q-tier-desc">${tierInfo.desc}</div>
                </div>

                <div class="macro-q-actions-footer">
                    <button type="button" class="btn-start-questionnaire" data-macro-retake="${type}">Rehacer</button>
                </div>
            </div>
        `;
    }

    // ─── Delegated clicks (macro-start / retake / card / rules / close)
    //     Scoped to this.element so a re-mount never double-binds. ───

    onDocClick(e) {
        const startBtn = e.target.closest('[data-macro-start]');
        if (startBtn) {
            this.openMacroQuestionnaireModal(startBtn.dataset.macroStart);
            return;
        }
        const retakeBtn = e.target.closest('[data-macro-retake]');
        if (retakeBtn) {
            this.openMacroQuestionnaireModal(retakeBtn.dataset.macroRetake);
            return;
        }
        const card = e.target.closest('[data-questionnaire-card]');
        if (card && !e.target.closest('button, a')) {
            this.openMacroQuestionnaireModal(card.dataset.questionnaireCard);
            return;
        }
        const rule = e.target.closest('.discipline-rule');
        if (rule) {
            this.element.querySelectorAll('.discipline-rule').forEach((r) =>
                r.classList.toggle('is-focused', r === rule));
            return;
        }
        if (e.target.closest('[data-macro-close-questionnaire]')) {
            this.closeMacroQuestionnaireModal();
        }
    }

    onDocKeydown(e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList?.contains('discipline-rule')) {
            e.preventDefault();
            e.target.click();
        }
    }

    // ══════ Summary / ticker ══════

    updateMacroQSummary() {
        const completed = this.element.querySelectorAll('.macro-q-summary-status.completed').length;
        const total = 2;
        const pct = Math.round((completed / total) * 100);
        const fill = this.el('macro-q-summary-fill');
        const pctEl = this.el('macro-q-summary-pct');
        if (fill) fill.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';
        if (fill) {
            if (pct === 100) fill.classList.add('complete');
            else fill.classList.remove('complete');
        }
        const track = this.el('macro-ticker-track');
        if (track) {
            const rp = this.el('macro-q-summary-rp')?.textContent?.trim() || 'Pendiente';
            const mk = this.el('macro-q-summary-mk')?.textContent?.trim() || 'Pendiente';
            const items = [
                'Perfil de Riesgo: ' + rp,
                'Conocimiento del Mercado: ' + mk,
                'Progreso: ' + pct + '%',
            ];
            const seq = items.map((t) => '<span class="macro-ticker-item">' + t.replace(/</g, '&lt;') + '</span>').join('<span class="macro-ticker-sep">◆</span>');
            track.innerHTML = seq + '<span class="macro-ticker-sep">◆</span>' + seq + '<span class="macro-ticker-sep">◆</span>';
        }
    }

    initMacroQSummary() {
        const cards = this.element.querySelectorAll('.macro-q-summary-card');
        cards.forEach((card) => {
            const type = card.dataset.type;
            const statusEl = this.el('macro-q-summary-' + (type === 'risk_profile' ? 'rp' : 'mk'));
            if (statusEl) {
                statusEl.textContent = 'Cargando...';
                statusEl.className = 'macro-q-summary-status';
            }
        });
    }
}
