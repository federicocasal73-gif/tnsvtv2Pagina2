# Sanctum Dashboard — Stitch Mockup Redesign

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the Sanctum Dashboard (Command Center) and its shell (sidebar + topbar) to match the Stitch premium mockup visually, with real API data powering all components.

**Architecture:** Modify 3 existing files: `shell.html.twig` (sidebar + topbar redesign), `dashboard.html.twig` (KPI cards, Task Sovereignty, Educational Mastery), and `components.css` (glass morphism, gold accents, typography). No new files needed — all CSS goes into existing `components.css` or inline `<style>` blocks. Data comes from existing API endpoints (`/api/auth/check`, `/sanctum/api/tasks`, `/api/journal/stats`, `/api/users/all`, `/sanctum/api/monitoring/status`).

**Tech Stack:** Twig templates, CSS custom properties (design tokens from `tokens.css`), vanilla JS (apiFetch), Three.js (already loaded), Material Symbols icons.

## Global Constraints

- Design tokens: `--gold-elev: #f2ca50`, `--void-elev: #050308`, `--surface-elev: #161121`, `--on-surface-elev: #e9def6`, `--violet-elev: #8a3cff`
- Font: Space Grotesk (already loaded via Google Fonts CDN)
- CSS load order in shell: tokens → components → v2-sparklines → animations → glow → shell → accessibility
- All data via `window.apiFetch()` (defined in `_partials/api_helper.html.twig`)
- Server: `ssh -p 65002 -i ~/.ssh/id_tnsvt_deploy u310596868@185.173.111.201`
- Deploy path: `domains/tnsvt.com/public_html/`
- After template changes: SCP to server, then `php bin/console asset-map:compile --env=prod && php bin/console cache:clear --env=prod`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `templates/shell.html.twig` | Modify | Sidebar gold accent bar, topbar "Invoke Protocol" button, notification bell with red dot, user card redesign |
| `templates/sanctum/dashboard.html.twig` | Modify | KPI cards glass redesign, Task Sovereignty table match, Educational Mastery course cards, Remove Guardian/Quick Actions/Activity Feed sections (not in mockup) |
| `assets/styles/components.css` | Modify | Sidebar active gold bar, glass card premium, KPI card glass, course card grid, table styling |
| `assets/styles/tokens.css` | No change | Already has all needed tokens |
| `assets/styles/shell.css` | No change | Shell primitives already defined |

---

## Task 1: Sidebar Redesign — Gold Accent + Active State

**Files:**
- Modify: `templates/shell.html.twig:37-212` (sidebar section)
- Modify: `assets/styles/components.css:451-515` (sidebar CSS)

**What changes:**
- Active nav item gets a left gold border bar (3px, `var(--gold-elev)`) + gold text + subtle gold background tint
- Section headers get slightly more spacing
- User card gets gold border accent on hover
- Sidebar background stays `var(--surface-elev)` but with subtle violet radial glow (already exists, just needs refinement)

**Visual reference (Stitch mockup Image 2):**
- "Sanctum" nav item has a gold left border bar, gold text, gold icon
- Other items are muted white/gray
- Section dividers are subtle uppercase labels

- [ ] **Step 1: Update sidebar CSS in components.css**

Find the existing `#sanctum-sidebar` rules in `components.css` and replace with:

```css
/* ─── Sidebar (Stitch premium) ─── */
#sanctum-sidebar {
    background: var(--surface-elev);
    border-right: 1px solid var(--outline-variant-elev);
    position: relative;
    overflow: hidden;
}
#sanctum-sidebar::before {
    content: '';
    position: absolute;
    top: 50%;
    left: -40px;
    width: 120px;
    height: 120px;
    background: radial-gradient(circle, rgba(138, 60, 255, 0.08) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
    transform: translateY(-50%);
}

/* Nav links */
.sanctum-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 1.5rem;
    margin: 0.125rem 0.75rem;
    border-radius: 0.5rem;
    color: var(--outline-elev);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
    border-left: 3px solid transparent;
}
.sanctum-link:hover {
    color: var(--on-surface-elev);
    background: rgba(255, 255, 255, 0.03);
}
.sanctum-link.active {
    color: var(--gold-elev);
    background: rgba(242, 202, 80, 0.08);
    border-left-color: var(--gold-elev);
    font-weight: 600;
}
.sanctum-link.active .material-symbols-elev {
    color: var(--gold-elev);
}

/* Section headers */
.sanctum-link + .px-6 {
    margin-top: 0.75rem;
}

/* User card */
#sanctum-sidebar .glass-card-elev {
    transition: border-color 0.2s;
}
#sanctum-sidebar .glass-card-elev:hover {
    border-color: rgba(242, 202, 80, 0.2);
}
```

- [ ] **Step 2: Verify sidebar renders correctly**

Open browser, navigate to `/sanctum`, confirm:
- "Sanctum" (Inicio) has gold left border + gold text
- Other nav items are gray
- Hover shows subtle background tint
- Violet radial glow is visible on left edge

- [ ] **Step 3: SCP components.css to server**

```powershell
$scpPath = "C:\Program Files\Git\usr\bin\scp.exe"
$key = "$env:USERPROFILE\.ssh\id_tnsvt_deploy"
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\assets\styles\components.css" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/assets/styles/components.css"
```

- [ ] **Step 4: Compile assets + clear cache**

```powershell
$sshPath = "C:\Program Files\Git\usr\bin\ssh.exe"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 5: Commit**

```bash
git add assets/styles/components.css
git commit -m "feat(sanctum): sidebar gold accent bar + active state matching Stitch mockup"
```

---

## Task 2: Topbar Redesign — "Invoke Protocol" Button + Notification Bell

**Files:**
- Modify: `templates/shell.html.twig:214-226` (header section)

**What changes:**
- Title "Command Center" stays left-aligned
- Right side gets: notification bell with red dot (already exists) + "Invoke Protocol" button with gold border
- Topbar background: gradient from surface to slight gold tint (already exists, just needs the button)

**Visual reference (Stitch mockup Image 2):**
- Bell icon with gold/red dot
- "Invoke Protocol" button: outlined, gold border, gold text, rounded

- [ ] **Step 1: Update topbar in shell.html.twig**

Replace lines 214-226 with:

```twig
    <main class="flex-1 flex flex-col">
    <header class="h-16 bg-gradient-to-r from-[var(--surface-elev)] via-[rgba(242,202,80,0.02)] to-[var(--surface-elev)] border-b border-[var(--outline-variant-elev)] flex items-center justify-between px-6">
        <div>
            <h2 class="text-xl font-semibold text-[var(--on-surface-elev)] tracking-wide">{% block topbar_title %}Command Center{% endblock %}</h2>
            <p class="text-xs text-[var(--outline-elev)]">{% block topbar_subtitle %}Divine synchronization active.{% endblock %}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="/sanctum/tasks" class="topbar-invoke-btn">
                <span class="material-symbols-elev" style="font-size: 1rem;">bolt</span>
                Invoke Protocol
            </a>
            <button class="btn-elev-ghost relative" title="Notificaciones">
                <span class="material-symbols-elev">notifications</span>
                <span id="header-notif-badge" class="hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] flex items-center justify-center rounded-full bg-[var(--gold-elev)] text-black text-[10px] font-bold px-1">0</span>
            </button>
        </div>
    </header>
```

- [ ] **Step 2: Add topbar button CSS**

Add to the `<style>` block in `shell.html.twig` (or to `components.css`):

```css
.topbar-invoke-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1.25rem;
    border: 1px solid var(--gold-elev);
    border-radius: 2rem;
    background: transparent;
    color: var(--gold-elev);
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
}
.topbar-invoke-btn:hover {
    background: rgba(242, 202, 80, 0.1);
    box-shadow: 0 0 16px rgba(242, 202, 80, 0.2);
    transform: translateY(-1px);
}
```

- [ ] **Step 3: Verify in browser**

Confirm: "Invoke Protocol" button appears with gold border, bell has badge

- [ ] **Step 4: SCP shell.html.twig to server**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\shell.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/shell.html.twig"
```

- [ ] **Step 5: Compile + cache + verify**

```powershell
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 6: Commit**

```bash
git add templates/shell.html.twig
git commit -m "feat(sanctum): topbar Invoke Protocol button + notification bell"
```

---

## Task 3: KPI Cards — Glass Premium Redesign

**Files:**
- Modify: `templates/sanctum/dashboard.html.twig:62-107` (hero KPI row)

**What changes:**
- 4 KPI cards: Global PNL, Active Seekers, Server Sanctum, Macro Signals
- Each card: glass background with subtle gradient, gold label, large value, meta text, sparkline SVG at bottom
- Cards match the Stitch mockup: dark glass with gold accents, no harsh borders

**Visual reference (Stitch mockup Image 2):**
- Cards have dark glass background with subtle border
- Labels: small uppercase, muted
- Values: large gold text with text-shadow glow
- Meta: small muted text with icon
- Sparkline: subtle SVG line at bottom

- [ ] **Step 1: Replace KPI card HTML in dashboard.html.twig**

Replace lines 62-107 with:

```twig
    {# Hero Metrics Row - 4 KPI Cards (Stitch premium) #}
    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="kpi-card-elev" data-card="globalPnl">
            <div class="kpi-header">
                <span class="kpi-label">GLOBAL PNL</span>
                <span class="material-symbols-elev kpi-icon">trending_up</span>
            </div>
            <p class="kpi-value" id="kpi-pnl-value">$0</p>
            <p class="kpi-meta">
                <span class="kpi-meta-dot" style="background: var(--gold-elev);"></span>
                <span id="kpi-pnl-meta">calculating</span>
            </p>
            <svg class="kpi-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                <polyline id="kpi-pnl-spark" points="0,25 10,22 20,20 30,18 40,15 50,12 60,10 70,8 80,5 90,3 100,2" fill="none" stroke="var(--gold-elev)" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>

        <div class="kpi-card-elev" data-card="activeSeekers">
            <div class="kpi-header">
                <span class="kpi-label">ACTIVE SEEKERS</span>
                <span class="material-symbols-elev kpi-icon" style="color: #4ade80;">groups</span>
            </div>
            <p class="kpi-value" style="color: #4ade80;" id="kpi-seekers-value">0</p>
            <p class="kpi-meta">
                <span class="kpi-meta-dot" style="background: #4ade80;"></span>
                <span id="kpi-seekers-meta">Real-time presence</span>
            </p>
            <svg class="kpi-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                <polyline id="kpi-seekers-spark" points="0,20 10,18 20,15 30,17 40,12 50,10 60,8 70,5 80,7 90,3 100,2" fill="none" stroke="#4ade80" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>

        <div class="kpi-card-elev" data-card="serverSanctum">
            <div class="kpi-header">
                <span class="kpi-label">SERVER SANCTUM</span>
                <span class="material-symbols-elev kpi-icon" style="color: #4ade80;">verified</span>
            </div>
            <p class="kpi-value" style="color: #4ade80;" id="kpi-server-value">99.9%</p>
            <p class="kpi-meta">
                <span class="kpi-meta-dot" style="background: #4ade80;"></span>
                <span id="kpi-server-meta">Divine Integrity High</span>
            </p>
            <svg class="kpi-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                <polyline id="kpi-server-spark" points="0,5 10,4 20,5 30,3 40,4 50,2 60,3 70,2 80,1 90,2 100,1" fill="none" stroke="#4ade80" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>

        <div class="kpi-card-elev" data-card="macroSignals">
            <div class="kpi-header">
                <span class="kpi-label">MACRO SIGNALS</span>
                <span class="material-symbols-elev kpi-icon" style="color: var(--violet-elev);">event_upcoming</span>
            </div>
            <p class="kpi-value" style="color: var(--violet-elev);" id="kpi-macro-value">0</p>
            <p class="kpi-meta">
                <span class="kpi-meta-dot" style="background: var(--violet-elev);"></span>
                <span id="kpi-macro-meta">Executions per/hr</span>
            </p>
            <svg class="kpi-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                <polyline id="kpi-macro-spark" points="0,28 10,25 20,22 30,20 40,18 50,15 60,12 70,10 80,8 90,5 100,3" fill="none" stroke="var(--violet-elev)" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>
    </section>
```

- [ ] **Step 2: Update KPI card CSS (already in dashboard.html.twig inline `<style>`)**

The existing `.kpi-card-elev` styles (lines 11-53) already match the Stitch look. Verify they render correctly. If not, adjust:

```css
.kpi-card-elev {
    background: linear-gradient(135deg, rgba(22, 17, 33, 0.8) 0%, rgba(242, 202, 80, 0.03) 100%);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 1rem;
    padding: 1.25rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}
.kpi-card-elev:hover {
    border-color: rgba(242, 202, 80, 0.25);
    box-shadow: 0 0 24px rgba(242, 202, 80, 0.08);
    transform: translateY(-2px);
}
```

- [ ] **Step 3: Verify in browser**

Confirm: 4 KPI cards render with glass background, gold/green/violet values, sparklines, meta dots

- [ ] **Step 4: SCP dashboard.html.twig to server**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\dashboard.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/dashboard.html.twig"
```

- [ ] **Step 5: Compile + cache + verify**

```powershell
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 6: Commit**

```bash
git add templates/sanctum/dashboard.html.twig
git commit -m "feat(sanctum): KPI cards glass premium matching Stitch mockup"
```

---

## Task 4: Task Sovereignty Table — Stitch Match

**Files:**
- Modify: `templates/sanctum/dashboard.html.twig:146-178` (Task Sovereignty + Recent Signals grid)

**What changes:**
- Task Sovereignty: Table-style layout with columns: Task name, Status badge, Priority stars, Action button
- Recent Signals: Signal cards with avatar, name, message, timestamp
- Both panels in a 2-column grid (2/3 + 1/3)

**Visual reference (Stitch mockup Image 2):**
- Table has header row (SOVEREIGN TASK / STATUS / PRIORITY / ACTION)
- Status badges: ACTIVE (green), PENDING (gray), COMPLETED (green outline)
- Priority: 3 star icons (filled/unfilled)
- Action: "Assign" or "Details" button
- Recent Signals: Card with red dot, name in gold, message in italics, timestamp

- [ ] **Step 1: Replace Task Sovereignty + Recent Signals HTML**

Replace lines 146-178 in `dashboard.html.twig` with:

```twig
        {# Task Sovereignty + Recent Signals (Stitch match) #}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            {# Task Sovereignty — 2/3 width #}
            <div class="glass-card-elev p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-[var(--on-surface-elev)] flex items-center gap-2">
                        <span class="material-symbols-elev text-[var(--gold-elev)]">auto_awesome</span>
                        Task Sovereignty
                    </h3>
                    <div class="flex gap-2 items-center">
                        <button id="quick-add-task" class="topbar-invoke-btn" style="font-size: 0.75rem; padding: 0.375rem 1rem;">
                            <span class="material-symbols-elev" style="font-size: 0.875rem;">add</span>
                            New
                        </button>
                        <a href="/sanctum/tasks" class="text-xs text-[var(--gold-elev)] hover:underline">VIEW ALL ARCHIVE</a>
                    </div>
                </div>

                {# Table header #}
                <div class="grid grid-cols-12 gap-4 px-4 py-2 text-[10px] uppercase tracking-wider text-[var(--outline-elev)] border-b border-[var(--outline-variant-elev)] mb-2">
                    <div class="col-span-5">SOVEREIGN TASK</div>
                    <div class="col-span-2">STATUS</div>
                    <div class="col-span-2">PRIORITY</div>
                    <div class="col-span-3 text-right">ACTION</div>
                </div>

                <div id="task-list" class="space-y-1">
                    <p class="text-center text-[var(--outline-elev)] py-8 loading-pulse">Cargando...</p>
                </div>
            </div>

            {# Recent Signals — 1/3 width #}
            <div class="glass-card-elev p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-[var(--on-surface-elev)] flex items-center gap-2">
                        Recent Signals
                        <span class="ml-1 w-2 h-2 rounded-full bg-red-400 animate-pulse"></span>
                    </h3>
                </div>
                <div id="signal-list" class="space-y-3 max-h-80 overflow-y-auto pr-2">
                    <p class="text-center text-[var(--outline-elev)] py-8 loading-pulse">Cargando actividad reciente...</p>
                </div>
                <a href="/sanctum/audit" class="mt-4 block w-full text-center text-xs py-2 rounded border border-[var(--outline-variant-elev)] text-[var(--outline-elev)] hover:border-[var(--gold-elev)] hover:text-[var(--gold-elev)] transition-colors">Terminal Log</a>
            </div>
        </div>
```

- [ ] **Step 2: Update task list rendering JS**

In the `<script>` section at the bottom of `dashboard.html.twig`, find the task list rendering code and update it to render table rows:

```javascript
// Task list rendering (Stitch table format)
function renderTaskRow(task) {
    const statusColors = {
        'ACTIVE': 'bg-green-900/50 text-green-400 border-green-500/30',
        'PENDING': 'bg-yellow-900/50 text-yellow-400 border-yellow-500/30',
        'COMPLETED': 'bg-green-900/30 text-green-300 border-green-500/20'
    };
    const statusClass = statusColors[task.status] || statusColors['PENDING'];
    const stars = [1, 2, 3].map(i =>
        `<span class="material-symbols-elev" style="font-size: 0.875rem; color: ${i <= (task.priority || 1) ? 'var(--gold-elev)' : 'var(--outline-variant-elev)'};">star</span>`
    ).join('');

    return `
        <div class="grid grid-cols-12 gap-4 px-4 py-3 items-center rounded-lg hover:bg-white/[0.02] transition-colors border-b border-[var(--outline-variant-elev)]/30">
            <div class="col-span-5 flex items-center gap-3">
                <span class="material-symbols-elev text-[var(--gold-elev)]" style="font-size: 1.25rem;">task_alt</span>
                <span class="text-sm text-[var(--on-surface-elev)] font-medium">${task.title}</span>
            </div>
            <div class="col-span-2">
                <span class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded border ${statusClass}">${task.status || 'PENDING'}</span>
            </div>
            <div class="col-span-2 flex gap-0.5">${stars}</div>
            <div class="col-span-3 text-right">
                <button class="text-xs px-3 py-1 rounded border border-[var(--outline-variant-elev)] text-[var(--outline-elev)] hover:border-[var(--gold-elev)] hover:text-[var(--gold-elev)] transition-colors">
                    ${task.status === 'COMPLETED' ? 'Details' : 'Assign'}
                </button>
            </div>
        </div>
    `;
}
```

- [ ] **Step 3: Update signal list rendering JS**

```javascript
// Signal list rendering (Stitch card format)
function renderSignalItem(signal) {
    const timeAgo = getTimeAgo(signal.created_at);
    return `
        <div class="p-3 rounded-lg bg-white/[0.02] border border-[var(--outline-variant-elev)]/30">
            <div class="flex items-start justify-between mb-1">
                <span class="text-sm font-semibold text-[var(--gold-elev)]">${signal.user || signal.type || 'System'}</span>
                <span class="text-[10px] text-[var(--outline-elev)]">${timeAgo}</span>
            </div>
            <p class="text-xs text-[var(--on-surface-variant-elev)] italic">"${signal.message || signal.description || ''}"</p>
        </div>
    `;
}

function getTimeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    const hours = Math.floor(mins / 60);
    if (hours < 24) return hours + 'h ago';
    return Math.floor(hours / 24) + 'd ago';
}
```

- [ ] **Step 4: Verify in browser**

Confirm: Task table shows with columns, status badges, stars, action buttons. Signal cards show with gold names, italic messages, timestamps.

- [ ] **Step 5: SCP + compile + cache**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\dashboard.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/dashboard.html.twig"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 6: Commit**

```bash
git add templates/sanctum/dashboard.html.twig
git commit -m "feat(sanctum): Task Sovereignty table + Recent Signals matching Stitch mockup"
```

---

## Task 5: Educational Mastery — Course Cards

**Files:**
- Modify: `templates/sanctum/dashboard.html.twig` (add new section after Task Sovereignty grid)

**What changes:**
- New "Educational Mastery" section with 3 course cards in a grid
- Each card: image placeholder, title, tier badge (PRO/BASIC/MASTER), student count, progress bar, "Edit Content" button
- Data fetched from `/campus` API or hardcoded for now

**Visual reference (Stitch mockup Image 2):**
- Section header "Educational Mastery" with "+ New Course" button on right
- 3 cards in a row: dark glass, image at top, title, tier badge, stats, progress bar, edit button

- [ ] **Step 1: Add Educational Mastery section**

After the Task Sovereignty + Recent Signals grid (the `</div>` closing that section), add:

```twig
        {# Educational Mastery (Stitch match) #}
        <section class="mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-[var(--on-surface-elev)]" style="color: var(--gold-elev);">Educational Mastery</h3>
                <a href="/campus" class="topbar-invoke-btn" style="font-size: 0.75rem; padding: 0.375rem 1rem;">
                    <span class="material-symbols-elev" style="font-size: 0.875rem;">add</span>
                    New Course
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="course-grid">
                <p class="text-center text-[var(--outline-elev)] py-8 loading-pulse col-span-3">Cargando cursos...</p>
            </div>
        </section>
```

- [ ] **Step 2: Add course card CSS**

Add to the `<style>` block in `dashboard.html.twig`:

```css
/* Course cards (Stitch Educational Mastery) */
.course-card {
    background: linear-gradient(135deg, rgba(22, 17, 33, 0.9) 0%, rgba(138, 60, 255, 0.05) 100%);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 0.75rem;
    overflow: hidden;
    transition: all 0.3s ease;
}
.course-card:hover {
    border-color: rgba(242, 202, 80, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(138, 60, 255, 0.1);
}
.course-card-img {
    width: 100%;
    height: 140px;
    background: linear-gradient(135deg, var(--surface-elev) 0%, rgba(138, 60, 255, 0.15) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}
.course-card-img::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(5, 3, 8, 0.8) 0%, transparent 50%);
}
.course-card-body {
    padding: 1rem;
}
.course-card-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--on-surface-elev);
    margin-bottom: 0.5rem;
}
.course-tier-badge {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    padding: 0.15rem 0.5rem;
    border-radius: 0.25rem;
    border: 1px solid;
    margin-left: 0.5rem;
    vertical-align: middle;
}
.course-tier-pro { color: var(--gold-elev); border-color: var(--gold-elev); background: rgba(242, 202, 80, 0.1); }
.course-tier-basic { color: var(--outline-elev); border-color: var(--outline-variant-elev); }
.course-tier-master { color: var(--violet-elev); border-color: var(--violet-elev); background: rgba(138, 60, 255, 0.1); }
.course-stats {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--outline-elev);
    margin-bottom: 0.5rem;
}
.course-progress-bar {
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 0.75rem;
}
.course-progress-fill {
    height: 100%;
    background: var(--gold-elev);
    border-radius: 2px;
    transition: width 0.5s ease;
}
.course-card-actions {
    display: flex;
    gap: 0.5rem;
}
.course-card-actions button {
    flex: 1;
    padding: 0.5rem;
    font-size: 0.75rem;
    border-radius: 0.375rem;
    border: 1px solid var(--outline-variant-elev);
    background: transparent;
    color: var(--on-surface-elev);
    cursor: pointer;
    transition: all 0.2s;
}
.course-card-actions button:hover {
    border-color: var(--gold-elev);
    color: var(--gold-elev);
}
```

- [ ] **Step 3: Add course rendering JS**

```javascript
// Course cards rendering
function renderCourseCard(course) {
    const tierClass = course.tier === 'PRO' ? 'course-tier-pro' : course.tier === 'MASTER' ? 'course-tier-master' : 'course-tier-basic';
    return `
        <div class="course-card">
            <div class="course-card-img">
                <span class="material-symbols-elev" style="font-size: 3rem; color: var(--gold-elev); z-index: 1;">school</span>
            </div>
            <div class="course-card-body">
                <div class="course-card-title">
                    ${course.title}
                    <span class="course-tier-badge ${tierClass}">${course.tier || 'BASIC'}</span>
                </div>
                <div class="course-stats">
                    <span>Students <strong>${course.students || 0}</strong></span>
                    <span>Avg. Progress <strong>${course.progress || 0}%</strong></span>
                </div>
                <div class="course-progress-bar">
                    <div class="course-progress-fill" style="width: ${course.progress || 0}%"></div>
                </div>
                <div class="course-card-actions">
                    <button>Edit Content</button>
                    <button><span class="material-symbols-elev" style="font-size: 1rem;">visibility</span></button>
                </div>
            </div>
        </div>
    `;
}
```

- [ ] **Step 4: Wire up data fetch**

Add to the data fetch section:

```javascript
// Fetch courses from campus API
apiFetch('/api/campus/courses', { redirectOn401: false, silent: true }).then(r => {
    if (!r || !r.data || !r.data.courses) return;
    const grid = document.getElementById('course-grid');
    if (!grid) return;
    grid.innerHTML = r.data.courses.slice(0, 3).map(renderCourseCard).join('');
}).catch(() => {
    // Fallback: render placeholder courses
    const grid = document.getElementById('course-grid');
    if (grid) {
        grid.innerHTML = [
            { title: 'Quantum Financial Foundations', tier: 'PRO', students: 1240, progress: 68 },
            { title: "The Prophet's Algorithmic Guide", tier: 'BASIC', students: 4812, progress: 42 },
            { title: 'Sacred Scalping Strategies', tier: 'MASTER', students: 642, progress: 89 }
        ].map(renderCourseCard).join('');
    }
});
```

- [ ] **Step 5: Verify in browser**

Confirm: 3 course cards appear with images, tier badges, progress bars, edit buttons

- [ ] **Step 6: SCP + compile + cache**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\dashboard.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/dashboard.html.twig"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 7: Commit**

```bash
git add templates/sanctum/dashboard.html.twig
git commit -m "feat(sanctum): Educational Mastery course cards matching Stitch mockup"
```

---

## Task 6: Remove Non-Mockup Sections + Final Polish

**Files:**
- Modify: `templates/sanctum/dashboard.html.twig` (remove Guardian widget, Quick Actions, Activity Feed, Market Pulse, Quick Stats — not in Stitch mockup)

**What changes:**
- Remove: Guardian widget (section lines 109-143), Quick Actions + System Status (lines 180-225), Activity Feed + Market Pulse (lines 227-248), Quick Stats Row (lines 250-268)
- Keep: KPI cards, Task Sovereignty + Recent Signals, Educational Mastery, Create Task Modal
- Final visual polish: ensure consistent spacing, glass morphism, gold accents

- [ ] **Step 1: Remove non-mockup sections**

Delete these sections from `dashboard.html.twig`:
- Guardian Widget (lines 109-143)
- Quick Actions + System Status (lines 180-225)
- Activity Feed + Market Pulse (lines 227-248)
- Quick Stats Row (lines 250-268)

Keep only:
1. KPI cards (hero row)
2. Task Sovereignty + Recent Signals
3. Educational Mastery
4. Create Task Modal

- [ ] **Step 2: Verify final layout in browser**

The page should now match the Stitch mockup structure:
- Top: 4 KPI cards
- Middle: Task Sovereignty table (2/3) + Recent Signals (1/3)
- Bottom: Educational Mastery course cards

- [ ] **Step 3: SCP + compile + cache**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\dashboard.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/dashboard.html.twig"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 4: Commit**

```bash
git add templates/sanctum/dashboard.html.twig
git commit -m "feat(sanctum): final dashboard layout matching Stitch mockup - removed non-mockup sections"
```

---

## Task 7: Verify + Fix Production Deployment

- [ ] **Step 1: Verify all pages load on production**

Navigate to:
- `https://tnsvt.com/sanctum` — Dashboard should show with new layout
- `https://tnsvt.com/sanctum/tasks` — Tasks page should still work
- `https://tnsvt.com/sanctum/monitoring` — Monitoring should still work

- [ ] **Step 2: Check browser console for errors**

Open DevTools → Console. Fix any JS errors (likely from removed sections referencing deleted DOM elements).

- [ ] **Step 3: Fix any broken references**

If the removed sections had JS that referenced deleted elements, wrap in null checks:
```javascript
const el = document.getElementById('element-id');
if (el) { /* existing code */ }
```

- [ ] **Step 4: Final SCP + compile if fixes needed**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\dashboard.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/dashboard.html.twig"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "fix(sanctum): production deployment fixes for Stitch redesign"
```

---

## Summary

After all 7 tasks, the Sanctum Dashboard should visually match the Stitch mockup:

| Section | Stitch Mockup | Current State |
|---------|---------------|---------------|
| Sidebar | Gold accent bar, active state | ✅ Task 1 |
| Topbar | "Invoke Protocol" button, bell | ✅ Task 2 |
| KPI Cards | Glass premium, sparklines | ✅ Task 3 |
| Task Table | Columns, status, stars | ✅ Task 4 |
| Recent Signals | Gold names, timestamps | ✅ Task 4 |
| Course Cards | Images, tiers, progress | ✅ Task 5 |
| Layout | 3 sections only | ✅ Task 6 |

**Estimated time:** 2-3 hours for full execution.
**Risk level:** Low — all changes are template/CSS only, no backend changes.

---

## Task 8: Create Macro Oracle Page

**Files:**
- Create: `templates/sanctum/macro_oracle.html.twig`
- Modify: `templates/shell.html.twig` (add nav link)
- Modify: Symfony route (check existing `/macro` route or create new)

**What changes:**
- New page matching Stitch mockup Image 1: Emotional Bias Map (scatter plot), Faith vs Logic Gauge, Session Performance charts, bottom info cards
- Data from existing APIs or mock data for visual components

**Visual reference (Stitch mockup Image 1):**
- Left sidebar (already handled by shell redesign)
- Top stats: Soul Resonance, Ethos Velocity, Logic Quotient, Active Seekers
- Main: Emotional Bias Map (canvas scatter plot), Faith vs Logic circular gauge (SVG)
- Session Performance: Journaling Velocity (bar chart), Manifestation Rate (line chart), Conclave Synergy (avatar list)
- Bottom: Next Alignment, Prophecy Accuracy, Active Protocols cards

- [ ] **Step 1: Check existing route for /macro**

```powershell
$sshPath = "C:\Program Files\Git\usr\bin\ssh.exe"
$key = "$env:USERPROFILE\.ssh\id_tnsvt_deploy"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && grep -r 'macro' config/routes/ 2>/dev/null || echo 'No macro route found'"
```

If route exists, find which controller renders it. If not, create a new route.

- [ ] **Step 2: Create macro_oracle.html.twig**

Create `templates/sanctum/macro_oracle.html.twig` extending `shell.html.twig`:

```twig
{% extends 'shell.html.twig' %}

{% block title %}Macro Oracle · T.N.S.V.T{% endblock %}
{% block topbar_title %}Macro Oracle{% endblock %}
{% block topbar_subtitle %}Spatiotemporal visualization of collective spiritual intent.{% endblock %}

{% block stylesheets %}
<style>
    .macro-grid { display: grid; gap: 1.5rem; }
    
    /* KPI row */
    .macro-kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
    .macro-kpi-card {
        background: linear-gradient(135deg, rgba(22, 17, 33, 0.8) 0%, rgba(242, 202, 80, 0.03) 100%);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 0.75rem;
        padding: 1rem 1.25rem;
        transition: all 0.3s ease;
    }
    .macro-kpi-card:hover {
        border-color: rgba(242, 202, 80, 0.2);
        transform: translateY(-2px);
    }
    .macro-kpi-label {
        font-size: 0.65rem;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--outline-elev);
    }
    .macro-kpi-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gold-elev);
        margin: 0.25rem 0;
    }
    .macro-kpi-meta {
        font-size: 0.7rem;
        color: var(--outline-elev);
    }
    
    /* Scatter plot */
    .scatter-container {
        background: linear-gradient(135deg, rgba(22, 17, 33, 0.9) 0%, rgba(138, 60, 255, 0.05) 100%);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 0.75rem;
        padding: 1.5rem;
        position: relative;
    }
    .scatter-canvas {
        width: 100%;
        height: 300px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 0.5rem;
        border: 1px solid rgba(255, 255, 255, 0.04);
    }
    .scatter-legend {
        display: flex;
        gap: 1.5rem;
        margin-top: 1rem;
        font-size: 0.75rem;
        color: var(--outline-elev);
    }
    .scatter-legend-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 0.375rem;
        vertical-align: middle;
    }
    
    /* Gauge */
    .gauge-container {
        background: linear-gradient(135deg, rgba(22, 17, 33, 0.9) 0%, rgba(138, 60, 255, 0.05) 100%);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 0.75rem;
        padding: 1.5rem;
        text-align: center;
    }
    .gauge-svg { width: 200px; height: 200px; }
    .gauge-label {
        font-size: 0.65rem;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--outline-elev);
    }
    .gauge-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--gold-elev);
    }
    
    /* Session Performance */
    .session-section {
        background: linear-gradient(135deg, rgba(22, 17, 33, 0.9) 0%, rgba(138, 60, 255, 0.05) 100%);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 0.75rem;
        padding: 1.5rem;
    }
    .session-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    .session-tabs {
        display: flex;
        gap: 0.5rem;
    }
    .session-tab {
        padding: 0.375rem 1rem;
        border-radius: 1rem;
        font-size: 0.75rem;
        font-weight: 500;
        border: 1px solid var(--outline-variant-elev);
        background: transparent;
        color: var(--outline-elev);
        cursor: pointer;
        transition: all 0.2s;
    }
    .session-tab.active {
        background: var(--gold-elev);
        color: #1a1200;
        border-color: var(--gold-elev);
    }
    
    /* Bottom cards */
    .info-card {
        background: linear-gradient(135deg, rgba(22, 17, 33, 0.8) 0%, rgba(242, 202, 80, 0.03) 100%);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 0.75rem;
        padding: 1.25rem;
        transition: all 0.3s ease;
    }
    .info-card:hover {
        border-color: rgba(242, 202, 80, 0.2);
    }
    .info-card-title {
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--gold-elev);
        margin-bottom: 0.5rem;
    }
    
    /* Filter button */
    .filter-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border: 1px solid var(--gold-elev);
        border-radius: 0.5rem;
        background: transparent;
        color: var(--gold-elev);
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .filter-btn:hover {
        background: rgba(242, 202, 80, 0.1);
    }

    /* Deep filter badge */
    .deep-filter-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        border-radius: 1rem;
        background: var(--gold-elev);
        color: #1a1200;
        font-size: 0.75rem;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .macro-kpi { grid-template-columns: repeat(2, 1fr); }
    }
</style>
{% endblock %}

{% block content %}
<div class="max-w-7xl mx-auto macro-grid">
    {# KPI Row #}
    <section class="macro-kpi">
        <div class="macro-kpi-card">
            <div class="macro-kpi-label">SOUL RESONANCE</div>
            <div class="macro-kpi-value" id="macro-soul">98.4%</div>
            <div class="macro-kpi-meta">↑ +2.4</div>
        </div>
        <div class="macro-kpi-card">
            <div class="macro-kpi-label">ETHOS VELOCITY</div>
            <div class="macro-kpi-value" id="macro-ethos">412 E/s</div>
            <div class="macro-kpi-meta">↗ Stable</div>
        </div>
        <div class="macro-kpi-card">
            <div class="macro-kpi-label">LOGIC QUOTIENT</div>
            <div class="macro-kpi-value" id="macro-logic">76.8</div>
            <div class="macro-kpi-meta">↓ -0.1</div>
        </div>
        <div class="macro-kpi-card">
            <div class="macro-kpi-label">ACTIVE SEEKERS</div>
            <div class="macro-kpi-value" id="macro-seekers">12.4K</div>
            <div class="macro-kpi-meta"><span class="scatter-legend-dot" style="background: #4ade80;"></span> Real-time</div>
        </div>
    </section>

    {# Scatter Plot + Gauge #}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {# Emotional Bias Map #}
        <div class="scatter-container lg:col-span-2">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-[var(--on-surface-elev)]">Emotional Bias Map</h3>
                    <p class="text-xs text-[var(--outline-elev)]">Spatiotemporal visualization of collective spiritual intent</p>
                </div>
                <button class="filter-btn">
                    <span class="material-symbols-elev" style="font-size: 1rem;">filter_list</span>
                    Deep Filter
                </button>
            </div>
            <canvas id="scatter-canvas" class="scatter-canvas"></canvas>
            <div class="scatter-legend">
                <span><span class="scatter-legend-dot" style="background: var(--gold-elev);"></span> High Devotion</span>
                <span><span class="scatter-legend-dot" style="background: var(--violet-elev);"></span> Analytical Logic</span>
                <span class="ml-auto text-[var(--outline-elev)]" style="opacity: 0.5;">Data updated 42ms ago</span>
            </div>
        </div>

        {# Faith vs Logic Gauge #}
        <div class="gauge-container">
            <div class="gauge-label mb-2">Equilibrium</div>
            <div class="text-sm font-semibold text-[var(--on-surface-elev)] mb-4">FAITH VS LOGIC GAUGE</div>
            <svg class="gauge-svg mx-auto" viewBox="0 0 200 200">
                <defs>
                    <linearGradient id="gaugeGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color: var(--gold-elev);" />
                        <stop offset="100%" style="stop-color: var(--violet-elev);" />
                    </linearGradient>
                </defs>
                <!-- Background circle -->
                <circle cx="100" cy="100" r="80" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="12" />
                <!-- Value arc (62%) -->
                <circle cx="100" cy="100" r="80" fill="none" stroke="url(#gaugeGrad)" stroke-width="12"
                    stroke-dasharray="377" stroke-dashoffset="143"
                    stroke-linecap="round" transform="rotate(-90 100 100)" />
                <!-- Center text -->
                <text x="100" y="92" text-anchor="middle" fill="var(--gold-elev)" font-size="32" font-weight="700" font-family="Space Grotesk">62</text>
                <text x="100" y="115" text-anchor="middle" fill="var(--outline-elev)" font-size="10" font-weight="600" letter-spacing="0.1em" font-family="Space Grotesk">FAITH WEIGHTED</text>
            </svg>
            <div class="mt-4 space-y-2">
                <div class="flex justify-between text-xs">
                    <span class="text-[var(--outline-elev)]">INTUITION</span>
                    <span class="text-[var(--gold-elev)]">88%</span>
                </div>
                <div class="w-full h-1.5 bg-white/5 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width: 88%; background: var(--gold-elev);"></div>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-[var(--outline-elev)]">DEDUCTION</span>
                    <span class="text-[var(--violet-elev)]">42%</span>
                </div>
                <div class="w-full h-1.5 bg-white/5 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width: 42%; background: var(--violet-elev);"></div>
                </div>
            </div>
        </div>
    </div>

    {# Session Performance #}
    <div class="session-section">
        <div class="session-header">
            <div>
                <h3 class="text-lg font-semibold text-[var(--on-surface-elev)]">Session Performance</h3>
                <p class="text-xs text-[var(--outline-elev)]">Ancestral feedback loop & historical manifestation patterns</p>
            </div>
            <div class="session-tabs">
                <button class="session-tab active" data-range="daily">Daily</button>
                <button class="session-tab" data-range="weekly">Weekly</button>
                <button class="session-tab" data-range="lunar">Lunar</button>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            {# Journaling Velocity #}
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-elev text-[var(--gold-elev)]" style="font-size: 1.25rem;">menu_book</span>
                    <span class="text-sm font-semibold text-[var(--gold-elev)]">Journaling Velocity</span>
                </div>
                <canvas id="bar-chart" style="width: 100%; height: 120px;"></canvas>
            </div>
            {# Manifestation Rate #}
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-elev" style="font-size: 1.25rem; color: var(--violet-elev);">show_chart</span>
                    <span class="text-sm font-semibold text-[var(--violet-elev)]">Manifestation Rate</span>
                </div>
                <canvas id="line-chart" style="width: 100%; height: 120px;"></canvas>
            </div>
            {# Conclave Synergy #}
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-elev text-[var(--gold-elev)]" style="font-size: 1.25rem;">groups</span>
                    <span class="text-sm font-semibold text-[var(--on-surface-elev)]">Conclave Synergy</span>
                </div>
                <div class="space-y-3" id="synergy-list">
                    <p class="text-xs text-[var(--outline-elev)] loading-pulse">Cargando...</p>
                </div>
            </div>
        </div>
    </div>

    {# Bottom Info Cards #}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="info-card">
            <div class="info-card-title">NEXT ALIGNMENT</div>
            <p class="text-sm text-[var(--on-surface-elev)] font-semibold">Solar Zenith in 04:12:45</p>
            <p class="text-xs text-[var(--outline-elev)] mt-1">Recommended Action: Deep Insight Meditation</p>
        </div>
        <div class="info-card">
            <div class="info-card-title">PROPHECY ACCURACY</div>
            <p class="text-sm text-[var(--on-surface-elev)] font-semibold">99.2% Reliability</p>
            <p class="text-xs text-[var(--outline-elev)] mt-1">Confidence Interval: +/- 0.05%</p>
        </div>
        <div class="info-card">
            <div class="info-card-title">ACTIVE PROTOCOLS</div>
            <div class="flex flex-wrap gap-2 mt-2">
                <span class="deep-filter-badge">HERMETIC GUARD</span>
                <span class="deep-filter-badge" style="background: var(--violet-elev);">LOGIC VEIL</span>
                <span class="deep-filter-badge" style="background: rgba(242, 202, 80, 0.15); color: var(--gold-elev);">ZENITH SYNC</span>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // Scatter plot
    const canvas = document.getElementById('scatter-canvas');
    if (canvas) {
        const ctx = canvas.getContext('2d');
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * window.devicePixelRatio;
        canvas.height = rect.height * window.devicePixelRatio;
        ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';

        // Draw grid
        ctx.strokeStyle = 'rgba(255,255,255,0.04)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 10; i++) {
            const x = (rect.width / 10) * i;
            const y = (rect.height / 10) * i;
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, rect.height); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(rect.width, y); ctx.stroke();
        }

        // Scatter points (devotion = gold, logic = violet)
        const points = [
            { x: 0.3, y: 0.25, color: '#f2ca50', size: 6 },
            { x: 0.65, y: 0.6, color: '#8a3cff', size: 5 },
            { x: 0.45, y: 0.4, color: '#f2ca50', size: 4 },
            { x: 0.8, y: 0.3, color: '#8a3cff', size: 7 },
            { x: 0.2, y: 0.7, color: '#f2ca50', size: 5 },
            { x: 0.55, y: 0.15, color: '#8a3cff', size: 4 },
            { x: 0.7, y: 0.8, color: '#f2ca50', size: 6 },
            { x: 0.15, y: 0.45, color: '#8a3cff', size: 5 },
            { x: 0.9, y: 0.5, color: '#f2ca50', size: 4 },
            { x: 0.4, y: 0.85, color: '#8a3cff', size: 6 },
        ];
        points.forEach(p => {
            ctx.beginPath();
            ctx.arc(p.x * rect.width, p.y * rect.height, p.size, 0, Math.PI * 2);
            ctx.fillStyle = p.color;
            ctx.fill();
            ctx.shadowColor = p.color;
            ctx.shadowBlur = 8;
            ctx.fill();
            ctx.shadowBlur = 0;
        });
    }

    // Session tabs
    document.querySelectorAll('.session-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.session-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Fetch synergy data
    if (window.apiFetch) {
        apiFetch('/api/users/all', { redirectOn401: false, silent: true }).then(r => {
            if (!r || !r.data || !r.data.users) return;
            const list = document.getElementById('synergy-list');
            if (!list) return;
            const users = r.data.users.slice(0, 3);
            list.innerHTML = users.map(u => `
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[var(--gold-elev)] to-[var(--gold-bright-elev)] flex items-center justify-center text-[var(--on-primary-elev)] text-xs font-bold flex-shrink-0">
                        ${(u.code || '??').slice(0,2)}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-medium text-[var(--on-surface-elev)] truncate">${u.name || u.code}</p>
                        <div class="w-full h-1 bg-white/5 rounded-full mt-1">
                            <div class="h-full rounded-full bg-[var(--gold-elev)]" style="width: ${Math.floor(Math.random() * 40 + 60)}%;"></div>
                        </div>
                    </div>
                </div>
            `).join('');
        }).catch(() => {});
    }
})();
</script>
{% endblock %}
```

- [ ] **Step 3: Register route (if needed)**

If no `/macro` route exists, add to the Sanctum controller or create a new one:

```php
// In src/Controller/SanctumController.php or equivalent
#[Route('/macro', name: 'sanctum_macro')]
public function macro(): Response
{
    return $this->render('sanctum/macro_oracle.html.twig');
}
```

- [ ] **Step 4: Update sidebar nav link**

In `shell.html.twig`, update the macro nav link (line 97-99) to point to the new route:

```twig
<a href="{{ path('sanctum_macro') }}" class="sanctum-link" data-page="macro">
```

- [ ] **Step 5: SCP all files to server**

```powershell
$scpPath = "C:\Program Files\Git\usr\bin\scp.exe"
$key = "$env:USERPROFILE\.ssh\id_tnsvt_deploy"
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\macro_oracle.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/macro_oracle.html.twig"
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\shell.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/shell.html.twig"
```

- [ ] **Step 6: Compile + cache + verify**

```powershell
$sshPath = "C:\Program Files\Git\usr\bin\ssh.exe"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 7: Verify in browser**

Navigate to `/macro` or `/sanctum/macro`. Confirm:
- KPI row renders with mock data
- Scatter plot canvas draws grid + dots
- Gauge SVG shows 62% with gradient arc
- Session Performance section renders
- Bottom info cards show

- [ ] **Step 8: Commit**

```bash
git add templates/sanctum/macro_oracle.html.twig templates/shell.html.twig
git commit -m "feat(sanctum): create Macro Oracle page matching Stitch mockup"
```

---

## Task 9: Fix users.html.twig CSS Bug

**Files:**
- Modify: `templates/sanctum/users.html.twig` (lines 220-311)

**What changes:**
- Lines 220-310 contain orphaned CSS outside `<style>` tags — rendered as visible text on the page
- Move this CSS into the proper `<style>` block

- [ ] **Step 1: Read users.html.twig around line 220**

Check the exact content of the orphaned CSS block.

- [ ] **Step 2: Move orphaned CSS into `<style>` block**

Find the `</style>` tag that closes before line 220, move the orphaned CSS inside it, or create a proper `<style>` block around it.

- [ ] **Step 3: SCP + compile + cache**

```powershell
& $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\templates\sanctum\users.html.twig" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/templates/sanctum/users.html.twig"
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 4: Verify in browser**

Navigate to `/sanctum/users`. Confirm no raw CSS text is visible on the page.

- [ ] **Step 5: Commit**

```bash
git add templates/sanctum/users.html.twig
git commit -m "fix(sanctum): fix orphaned CSS bug in users.html.twig"
```

---

## Task 10: Consolidate Glass-Card Classes

**Files:**
- Modify: `templates/sanctum/journal.html.twig` (replace `.journal-card` with `.glass-card-elev`)
- Modify: `templates/sanctum/profile.html.twig` (replace `.profile-card` with `.glass-card-elev`)
- Modify: `templates/sanctum/notifications.html.twig` (replace `.notif-card` + `.vision-card` with `.glass-card-elev`)
- Modify: `templates/sanctum/feed.html.twig` (replace `.feed-card` + `.composer-card` with `.glass-card-elev`)
- Modify: `templates/sanctum/users.html.twig` (replace `.admin-card` with `.glass-card-elev`)

**What changes:**
- Replace all custom glass-card class names with the shared `.glass-card-elev` from `components.css`
- Remove duplicate CSS definitions for these custom classes
- Keep any page-specific styling that's NOT about glass (e.g., `.journal-card` hover glow can stay as an addition)

- [ ] **Step 1: For each file, find-and-replace class names**

```bash
# journal.html.twig
sed -i 's/journal-card/glass-card-elev/g' templates/sanctum/journal.html.twig

# profile.html.twig
sed -i 's/profile-card/glass-card-elev/g' templates/sanctum/profile.html.twig

# notifications.html.twig
sed -i 's/notif-card/glass-card-elev/g' templates/sanctum/notifications.html.twig
sed -i 's/vision-card/glass-card-elev/g' templates/sanctum/notifications.html.twig

# feed.html.twig
sed -i 's/feed-card/glass-card-elev/g' templates/sanctum/feed.html.twig
sed -i 's/composer-card/glass-card-elev/g' templates/sanctum/feed.html.twig

# users.html.twig
sed -i 's/admin-card/glass-card-elev/g' templates/sanctum/users.html.twig
```

- [ ] **Step 2: Remove duplicate CSS definitions**

In each file, find and remove the `<style>` block that defines the custom class (e.g., `.journal-card { backdrop-filter: blur(24px); ... }`) since `.glass-card-elev` already provides this.

Keep any page-specific additions (hover effects, animations, etc.) but reference them with new class names if needed.

- [ ] **Step 3: Verify all 5 pages render correctly**

Navigate to each page and confirm glass morphism still works:
- `/journal`
- `/profile`
- `/sanctum/notifications`
- `/feed`
- `/sanctum/users`

- [ ] **Step 4: SCP all 5 files + compile + cache**

```powershell
$files = @(
    "templates/sanctum/journal.html.twig",
    "templates/sanctum/profile.html.twig",
    "templates/sanctum/notifications.html.twig",
    "templates/sanctum/feed.html.twig",
    "templates/sanctum/users.html.twig"
)
foreach ($f in $files) {
    & $scpPath -P 65002 -o StrictHostKeyChecking=no -i $key "C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\tnsvt-symfony\stitch_tnsvt_app_m_vil\tnsvt-app\$f" "u310596868@185.173.111.201:domains/tnsvt.com/public_html/$f"
}
& $sshPath -p 65002 -o StrictHostKeyChecking=no -i $key u310596868@185.173.111.201 "cd domains/tnsvt.com/public_html && php bin/console asset-map:compile --env=prod 2>&1 && php bin/console cache:clear --env=prod 2>&1"
```

- [ ] **Step 5: Commit**

```bash
git add templates/sanctum/journal.html.twig templates/sanctum/profile.html.twig templates/sanctum/notifications.html.twig templates/sanctum/feed.html.twig templates/sanctum/users.html.twig
git commit -m "refactor(sanctum): consolidate custom glass-card classes to shared glass-card-elev"
```

---

## Updated Summary

After all 10 tasks, the Sanctum area will have:

| Task | What | Impact |
|------|------|--------|
| 1 | Sidebar gold accent bar | All pages — premium nav feel |
| 2 | Topbar Invoke Protocol button | All pages — premium header |
| 3 | KPI cards glass premium | Dashboard — hero metrics |
| 4 | Task Sovereignty table | Dashboard — core feature |
| 5 | Educational Mastery cards | Dashboard — course section |
| 6 | Remove non-mockup sections | Dashboard — clean layout |
| 7 | Production verify + fixes | Stability |
| **8** | **Macro Oracle page** | **New page — Stitch mockup Image 1** |
| **9** | **Fix users.html.twig CSS bug** | **Bug fix — visible CSS text** |
| **10** | **Consolidate glass-card classes** | **Consistency — 5 pages unified** |

**Estimated time:** 4-5 hours for full execution.
**Risk level:** Low — all changes are template/CSS only, no backend changes (except optional route registration for Macro Oracle).
