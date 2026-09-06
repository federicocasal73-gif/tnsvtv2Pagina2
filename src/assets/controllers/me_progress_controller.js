import { Controller } from '@hotwired/stimulus';

/**
 * TNSVT Sprint H.5 — Personal progress widgets.
 *
 * Loads + renders three widgets from /api/me/*:
 *   - Streak counter (current streak, longest, 30-day history)
 *   - Activity heatmap (last 12 weeks)
 *   - Achievements grid (unlocked + auto-evaluate on fetch)
 *
 * Targets:
 *   streakValue, streakToday, streakLongest
 *   heatmapGrid, heatmapLegend
 *   achievementsGrid
 */
export default class extends Controller {
    static targets = [
        'streakValue', 'streakToday', 'streakLongest',
        'heatmapGrid', 'heatmapMonth',
        'achievementsGrid', 'achievementsTotal',
    ];

    connect() {
        this.loadStreak();
        this.loadHeatmap();
        this.loadAchievements();
    }

    // ── Streak ──
    async loadStreak() {
        try {
            const r = await fetch('/api/me/streak?days=14', {
                headers: { 'X-Game-Code': this.userCode() || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            this.renderStreak(data);
        } catch (e) { console.warn('[me-progress] loadStreak', e); }
    }

    renderStreak(d) {
        if (this.hasStreakValueTarget) this.streakValueTarget.textContent = d.current;
        if (this.hasStreakTodayTarget) {
            this.streakTodayTarget.classList.toggle('is-active', d.today_active);
            this.streakTodayTarget.title = d.today_active ? 'Hoy ya sumaste actividad' : 'Hoy aún no sumaste actividad';
        }
        if (this.hasStreakLongestTarget) this.streakLongestTarget.textContent = d.longest;
    }

    // ── Heatmap ──
    async loadHeatmap() {
        try {
            const r = await fetch('/api/me/heatmap?weeks=12', {
                headers: { 'X-Game-Code': this.userCode() || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            this.renderHeatmap(data);
        } catch (e) { console.warn('[me-progress] loadHeatmap', e); }
    }

    renderHeatmap(d) {
        if (!this.hasHeatmapGridTarget) return;
        const days = d.days || [];
        if (!days.length) {
            this.heatmapGridTarget.innerHTML = '<p class="text-xs text-[var(--outline-elev)]">Sin datos</p>';
            return;
        }
        // Group days into weeks (7 rows × N cols). Our `days` array is
        // a flat chronological sequence already aligned to the grid.
        const html = days.map(d => `
            <div class="me-heatmap-cell level-${d.level}" title="${d.date}: ${d.count} evento${d.count === 1 ? '' : 's'}"></div>
        `).join('');
        this.heatmapGridTarget.innerHTML = html;
    }

    // ── Achievements ──
    async loadAchievements() {
        try {
            const r = await fetch('/api/me/achievements', {
                headers: { 'X-Game-Code': this.userCode() || '' },
            });
            if (!r.ok) return;
            const data = await r.json();
            this.renderAchievements(data);
        } catch (e) { console.warn('[me-progress] loadAchievements', e); }
    }

    renderAchievements(d) {
        if (!this.hasAchievementsGridTarget) return;
        if (this.hasAchievementsTotalTarget) {
            this.achievementsTotalTarget.textContent = d.total_points + ' pts';
        }
        const unlocked = d.unlocked || [];
        if (unlocked.length === 0) {
            this.achievementsGridTarget.innerHTML = `
                <div class="me-achievements-empty">
                    <span class="material-symbols-elev">emoji_events</span>
                    <p>Aún no tienes logros. ¡Completa tu primera lección para empezar!</p>
                </div>
            `;
            return;
        }
        this.achievementsGridTarget.innerHTML = unlocked.map(a => `
            <article class="me-achievement-badge color-${a.color}" title="${this.escape(a.description || a.title)}">
                <div class="me-achievement-icon">
                    <span class="material-symbols-elev">${this.escape(a.icon)}</span>
                </div>
                <div class="me-achievement-meta">
                    <div class="me-achievement-title">${this.escape(a.title)}</div>
                    <div class="me-achievement-points">+${a.points} pts</div>
                </div>
            </article>
        `).join('');
    }

    userCode() {
        return document.body?.dataset?.userCode || '';
    }

    escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
}
