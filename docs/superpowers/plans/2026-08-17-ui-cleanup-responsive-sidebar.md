# UI Cleanup + Responsive Sidebar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clean up duplicated CSS across templates and implement a responsive sidebar with mobile drawer

**Architecture:** Consolidate all duplicated component styles into canonical CSS files, then add responsive sidebar behavior with hamburger toggle and overlay drawer for mobile/tablet

**Tech Stack:** Twig templates, CSS custom properties, vanilla JavaScript, Symfony AssetMapper

## Global Constraints

- All CSS tokens use `-elev` suffix (canonical naming)
- No new external dependencies (vanilla JS only)
- Maintain existing visual identity (gold/violet/void palette)
- Must work on 320px-1440px+ viewports
- Preserve `prefers-reduced-motion` support

---

## File Structure

### Files to Modify:
- `templates/shell.html.twig` - Add sidebar toggle HTML + responsive behavior
- `assets/styles/shell.css` - Add sidebar responsive styles
- `assets/styles/components.css` - Add canonical component classes (form inputs, buttons)
- `templates/sanctum/dashboard.html.twig` - Remove inline CSS, use canonical classes
- `templates/sanctum/journal.html.twig` - Remove inline CSS, fix form inputs
- `templates/sanctum/calendar.html.twig` - Move `<style>` to proper block

### Files to Create:
- `assets/styles/components/sidebar.css` - Sidebar responsive styles
- `assets/styles/components/forms.css` - Canonical form input styles

---

## Task 1: Add Sidebar Responsive Styles to shell.css

**Files:**
- Modify: `assets/styles/shell.css`

**Interfaces:**
- Consumes: CSS tokens from `tokens.css`
- Produces: `.sidebar-toggle`, `.sidebar-overlay`, `.sidebar-open` classes

- [ ] **Step 1: Add responsive sidebar styles to shell.css**

```css
/* ═══════════════════════════════════════════════════════════════
   SIDEBAR RESPONSIVE
   ═══════════════════════════════════════════════════════════════ */

/* Mobile toggle button - hidden on desktop */
.sidebar-toggle {
    display: none;
    position: fixed;
    top: 1rem;
    left: 1rem;
    z-index: 60;
    width: 44px;
    height: 44px;
    border-radius: 0.5rem;
    background: var(--surface-elev);
    border: 1px solid var(--outline-variant-elev);
    color: var(--gold-elev);
    cursor: pointer;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.sidebar-toggle:hover {
    background: var(--surface-high-elev);
    border-color: var(--gold-elev);
}

/* Overlay for mobile sidebar */
.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 45;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    opacity: 0;
    transition: opacity 0.3s ease;
}
.sidebar-overlay.active {
    display: block;
    opacity: 1;
}

/* Responsive breakpoints */
@media (max-width: 1024px) {
    .sidebar-toggle {
        display: flex;
    }
    
    #sanctum-sidebar {
        position: fixed;
        left: -280px;
        top: 0;
        bottom: 0;
        z-index: 50;
        width: 280px;
        transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    #sanctum-sidebar.sidebar-open {
        left: 0;
    }
    
    main {
        margin-left: 0 !important;
    }
    
    header {
        padding-left: 4rem !important;
    }
}

@media (min-width: 1025px) {
    .sidebar-toggle {
        display: none !important;
    }
    
    .sidebar-overlay {
        display: none !important;
    }
}
```

- [ ] **Step 2: Verify styles load correctly**

Open browser dev tools, check that `shell.css` loads and sidebar styles are applied.

- [ ] **Step 3: Commit**

```bash
git add assets/styles/shell.css
git commit -m "feat: add responsive sidebar CSS styles"
```

---

## Task 2: Add Sidebar Toggle HTML to shell.html.twig

**Files:**
- Modify: `templates/shell.html.twig:45-50`

**Interfaces:**
- Consumes: CSS classes from Task 1
- Produces: Toggle button + overlay HTML

- [ ] **Step 1: Add toggle button before sidebar**

Insert after `<body>` tag, before `<div class="flex min-h-screen">`:

```twig
{# ═══ SIDEBAR TOGGLE (Mobile) ═══ #}
<button class="sidebar-toggle" id="sidebar-toggle" aria-label="Abrir menú">
    <span class="material-symbols-elev">menu</span>
</button>

{# ═══ SIDEBAR OVERLAY (Mobile) ═══ #}
<div class="sidebar-overlay" id="sidebar-overlay"></div>
```

- [ ] **Step 2: Add JavaScript for toggle behavior**

Add before `{% block javascripts %}{% endblock %}`:

```javascript
// Sidebar toggle (mobile)
(function() {
    const toggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sanctum-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    if (!toggle || !sidebar || !overlay) return;
    
    function openSidebar() {
        sidebar.classList.add('sidebar-open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeSidebar() {
        sidebar.classList.remove('sidebar-open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    toggle.addEventListener('click', openSidebar);
    overlay.addEventListener('click', closeSidebar);
    
    // Close on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('sidebar-open')) {
            closeSidebar();
        }
    });
    
    // Close on link click (mobile)
    sidebar.querySelectorAll('.sanctum-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 1024) {
                closeSidebar();
            }
        });
    });
})();
```

- [ ] **Step 3: Test on mobile viewport**

Open Chrome DevTools, toggle device toolbar, test sidebar opens/closes.

- [ ] **Step 4: Commit**

```bash
git add templates/shell.html.twig
git commit -m "feat: add responsive sidebar toggle for mobile"
```

---

## Task 3: Create Canonical Form Input Styles

**Files:**
- Create: `assets/styles/components/forms.css`
- Modify: `assets/styles/components.css` (add import)

**Interfaces:**
- Consumes: CSS tokens from `tokens.css`
- Produces: `.form-input`, `.form-select`, `.form-label` classes

- [ ] **Step 1: Create forms.css**

```css
/* assets/styles/components/forms.css
 *
 * Canonical form input styles.
 * Replaces inline styles on <input> and <select> elements.
 */

/* ─── Form Input ─── */
.form-input {
    width: 100%;
    padding: 0.625rem 1rem;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid var(--glass-border-elev);
    border-radius: 0.375rem;
    color: var(--on-surface-elev);
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.form-input:focus {
    outline: none;
    border-color: var(--gold-elev);
    box-shadow: 0 0 0 3px rgba(242, 202, 80, 0.1);
}
.form-input::placeholder {
    color: var(--outline-elev);
    opacity: 0.6;
}

/* ─── Form Select ─── */
.form-select {
    width: 100%;
    padding: 0.625rem 2.5rem 0.625rem 1rem;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid var(--glass-border-elev);
    border-radius: 0.375rem;
    color: var(--on-surface-elev);
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.875rem;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2399907c' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 1rem center;
    transition: all 0.2s ease;
}
.form-select:focus {
    outline: none;
    border-color: var(--gold-elev);
    box-shadow: 0 0 0 3px rgba(242, 202, 80, 0.1);
}

/* ─── Form Label ─── */
.form-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--outline-elev);
    margin-bottom: 0.375rem;
}

/* ─── Form Group ─── */
.form-group {
    margin-bottom: 1rem;
}

/* ─── Form Row (horizontal) ─── */
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}
```

- [ ] **Step 2: Add import to components.css**

Add at top of `assets/styles/components.css`:

```css
@import './components/forms.css';
```

- [ ] **Step 3: Verify form styles work**

Create a test input with class `form-input` and verify styling.

- [ ] **Step 4: Commit**

```bash
git add assets/styles/components/forms.css assets/styles/components.css
git commit -m "feat: add canonical form input styles"
```

---

## Task 4: Clean Up dashboard.html.twig Inline CSS

**Files:**
- Modify: `templates/sanctum/dashboard.html.twig`

**Interfaces:**
- Consumes: Canonical classes from `components.css` and `shell.css`
- Produces: Removed inline `<style>` block

- [ ] **Step 1: Remove duplicate KPI card styles**

Delete lines 19-100 (`.kpi-card-elev`, `.kpi-label-elev`, `.kpi-value-elev`, `.kpi-meta-elev`) from inline `<style>` block.

Replace usage in template with existing classes:
- `.kpi-card-elev` → `.kpi-card` (from shell.css)
- `.kpi-label-elev` → `.kpi-label` (from shell.css)
- `.kpi-value-elev` → `.kpi-value` (from shell.css)

- [ ] **Step 2: Remove duplicate button styles**

Delete lines 1138-1154 (`.btn-primary`, `.btn-secondary`) from inline `<style>` block.

These already exist in `components.css`.

- [ ] **Step 3: Remove duplicate modal styles**

Delete lines containing `.modal-overlay`, `.modal-content`, `.modal-header`, `.modal-footer`.

These already exist in `components.css`.

- [ ] **Step 4: Keep only unique dashboard-specific styles**

Keep only styles that are truly unique to dashboard:
- `.dashboard-grid`
- `.dashboard-tabs-wrap`
- `.ec-chart`, `.ec-tooltip`, `.ec-tab`
- `.cal-month-grid`, `.cal-month-day`

- [ ] **Step 5: Test dashboard renders correctly**

Navigate to `/sanctum` and verify all components render properly.

- [ ] **Step 6: Commit**

```bash
git add templates/sanctum/dashboard.html.twig
git commit -m "refactor: remove duplicate CSS from dashboard template"
```

---

## Task 5: Clean Up journal.html.twig Inline CSS

**Files:**
- Modify: `templates/sanctum/journal.html.twig`

**Interfaces:**
- Consumes: Canonical classes from `components.css`
- Produces: Removed inline styles from form inputs

- [ ] **Step 1: Remove inline styles from form inputs**

Replace all instances of:
```html
<input style="width:100%; padding:0.5rem 0.75rem; background:var(--glass-bg-elev); border:1px solid var(--glass-border-elev); border-radius:0.375rem; color:var(--on-surface-elev); font-size:0.875rem;" ...>
```

With:
```html
<input class="form-input" ...>
```

- [ ] **Step 2: Remove duplicate button styles**

Delete lines 263-264 (`.btn-primary`, `.btn-secondary`) from inline `<style>` block.

- [ ] **Step 3: Keep unique journal styles**

Keep only:
- `.glass-card-elev.cosmic`
- `.journal-equity`
- `.journal-nav-btn`

- [ ] **Step 4: Test journal renders correctly**

Navigate to `/journal` and verify form inputs and buttons render properly.

- [ ] **Step 5: Commit**

```bash
git add templates/sanctum/journal.html.twig
git commit -m "refactor: use canonical form classes in journal"
```

---

## Task 6: Fix calendar.html.twig Style Block Placement

**Files:**
- Modify: `templates/sanctum/calendar.html.twig`

**Interfaces:**
- Consumes: None
- Produces: Valid HTML with `<style>` in proper block

- [ ] **Step 1: Move `<style>` to `{% block stylesheets %}`**

Move the `<style>` block from after content (lines 74-79) to inside `{% block stylesheets %}` at the top of the template.

- [ ] **Step 2: Remove inline styles from form inputs**

Replace all `<input style="...">` with `<input class="form-input">`.

- [ ] **Step 3: Test calendar renders correctly**

Navigate to `/calendar` and verify styling works.

- [ ] **Step 4: Commit**

```bash
git add templates/sanctum/calendar.html.twig
git commit -m "fix: move calendar styles to proper block"
```

---

## Task 7: Test Responsive Sidebar Across Viewports

**Files:**
- None (testing only)

**Interfaces:**
- Consumes: Tasks 1-6
- Produces: Verified working responsive sidebar

- [ ] **Step 1: Test on desktop (1440px)**

- Sidebar always visible
- Toggle button hidden
- No overlay

- [ ] **Step 2: Test on tablet (768px)**

- Sidebar hidden by default
- Toggle button visible
- Sidebar opens as drawer on toggle
- Overlay appears
- Clicking overlay closes sidebar

- [ ] **Step 3: Test on mobile (375px)**

- Same as tablet behavior
- Sidebar links close sidebar on click
- Escape key closes sidebar

- [ ] **Step 4: Test accessibility**

- Toggle button has `aria-label`
- Focus trap in sidebar when open
- Escape key works

- [ ] **Step 5: Final commit**

```bash
git add .
git commit -m "feat: complete responsive sidebar implementation"
```

---

## Success Criteria

- [ ] All inline CSS removed from dashboard, journal, calendar
- [ ] Form inputs use canonical `.form-input` class
- [ ] Sidebar collapses on screens < 1024px
- [ ] Hamburger toggle opens/closes sidebar
- [ ] Overlay appears and closes sidebar on click
- [ ] No visual regressions on desktop
- [ ] All templates render correctly

---

## Remaining Risks

1. **CSS specificity conflicts** - Some inline styles may have higher specificity than classes. Test thoroughly.
2. **JavaScript errors** - Toggle script may conflict with existing inline scripts. Monitor console.
3. **Mobile performance** - Sidebar animation should be smooth. Use `will-change` if needed.
