# CSS Consolidation & Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate duplicate CSS definitions between `shell.css` and `components.css`, extract remaining inline styles from templates to dedicated CSS files, and clean up `journal_new.html.twig` local overrides.

**Architecture:** Remove dead code from `shell.css` (canonical source is `components.css`), extract page-specific inline styles to new component CSS files, and consolidate `journal_new.html.twig` form styles to use canonical `forms.css`.

**Tech Stack:** CSS, Twig templates, vanilla JS

## Global Constraints

- All CSS tokens use `-elev` suffix (canonical naming)
- No new external dependencies (vanilla JS only)
- Maintain existing visual identity (gold/violet/void palette)
- Must work on 320px-1440px+ viewports
- Preserve `prefers-reduced-motion` support
- Follow existing file structure in `assets/styles/components/`

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `assets/styles/shell.css` | Modify | Remove duplicate definitions (`.sanctum-link`, `.kpi-card`, `.kpi-label`, `.kpi-value`, `.kpi-meta`, `.tier-*`, `.status-pill`, `.status-*`, `.btn-action`, `.btn-toggle`, `.loading-pulse`) |
| `assets/styles/components/dashboard.css` | Create | Dashboard-specific layout styles (`.dashboard-grid`, `.course-card-elev`, `.task-row-elev`, hero, tabs) |
| `assets/styles/components/journal.css` | Create | Journal-specific styles (`.journal-equity`, `.journal-nav-btn`, trade list styles) |
| `assets/styles/components/calendar.css` | Create | Calendar-specific styles (`.cal-impact-*`, `.cal-critical`) |
| `assets/styles/components.css` | Modify | Add imports for new component CSS files |
| `templates/sanctum/journal_new.html.twig` | Modify | Remove local `.form-input` override, use canonical `forms.css` |

---

### Task 1: Remove Duplicate Definitions from shell.css

**Files:**
- Modify: `assets/styles/shell.css:12-180`

**Interfaces:**
- Consumes: None
- Produces: `shell.css` contains only sidebar responsive styles (lines 182-262) and data table styles (lines 131-152)

- [ ] **Step 1: Read shell.css to confirm duplicates**

Verify the following classes exist in both `shell.css` and `components.css`:
- `.sanctum-link` (shell.css:12-34 vs components.css:492-516)
- `.kpi-card`, `.kpi-label`, `.kpi-value`, `.kpi-meta` (shell.css:37-70 vs components.css:548-610)
- `.tier-*` (shell.css:73-96 vs components.css:173-196)
- `.status-pill`, `.status-*` (shell.css:99-128 vs components.css:254-285)
- `.btn-action`, `.btn-toggle` (shell.css:155-171 vs components.css:291-307)
- `.loading-pulse` (shell.css:174-176 vs components.css:684-686)

- [ ] **Step 2: Remove duplicate definitions from shell.css**

Delete lines 12-180 from `shell.css`. Keep only:
- File header comment (lines 1-9)
- Data table styles (lines 131-152) — these are NOT in `components.css`
- Sidebar responsive styles (lines 182-262)

- [ ] **Step 3: Verify data-table styles exist only in shell.css**

Search `components.css` for `.data-table` — confirm it's NOT there. If it IS there, remove from shell.css too.

- [ ] **Step 4: Run visual verification**

Navigate to any sanctum page (e.g., `/dashboard`). Verify:
- Sidebar links still styled correctly
- KPI cards still styled correctly
- Status pills still styled correctly
- No visual regressions

- [ ] **Step 5: Commit**

```bash
git add assets/styles/shell.css
git commit -m "refactor: remove duplicate CSS definitions from shell.css"
```

---

### Task 2: Extract Dashboard Inline Styles

**Files:**
- Read: `templates/sanctum/dashboard.html.twig:19-160`
- Create: `assets/styles/components/dashboard.css`
- Modify: `assets/styles/components.css` (add import)

**Interfaces:**
- Consumes: Design tokens from `tokens.css`
- Produces: `.dashboard-grid`, `.course-card-elev`, `.task-row-elev`, hero, tabs classes in `dashboard.css`

- [ ] **Step 1: Read dashboard.html.twig inline styles**

Read the `{% block stylesheets %}` section (lines 19-160+) and identify:
- Layout classes (`.dashboard-grid`)
- Card classes (`.course-card-elev`, `.task-row-elev`)
- Hero section styles
- Tab styles
- Any other page-specific classes

- [ ] **Step 2: Create dashboard.css**

Create `assets/styles/components/dashboard.css` with the extracted styles. Use canonical tokens (`-elev` suffix).

- [ ] **Step 3: Update dashboard.html.twig**

Remove the inline `<style>` block. The template should only have `{% block stylesheets %}{% endblock %}` or no block at all.

- [ ] **Step 4: Add import to components.css**

Add `@import './components/dashboard.css';` after the `forms.css` import (line 21).

- [ ] **Step 5: Visual verification**

Navigate to `/dashboard`. Verify all sections render correctly.

- [ ] **Step 6: Commit**

```bash
git add assets/styles/components/dashboard.css assets/styles/components.css templates/sanctum/dashboard.html.twig
git commit -m "refactor: extract dashboard inline styles to dashboard.css"
```

---

### Task 3: Extract Journal Inline Styles

**Files:**
- Read: `templates/sanctum/journal.html.twig:9-95`
- Create: `assets/styles/components/journal.css`
- Modify: `assets/styles/components.css` (add import)

**Interfaces:**
- Consumes: Design tokens from `tokens.css`, canonical `.form-input` from `forms.css`
- Produces: Journal-specific classes in `journal.css`

- [ ] **Step 1: Read journal.html.twig inline styles**

Read the `{% block stylesheets %}` section (lines 9-95) and identify:
- Trade list styles (`.trade-row`, `.trade-asset`, `.trade-dir-*`, `.trade-pnl-*`, `.trade-actions`)
- Journal-specific layout (`.journal-equity`, `.journal-nav-btn`)
- Any remaining form styles (should already use `.form-input`)

- [ ] **Step 2: Create journal.css**

Create `assets/styles/components/journal.css` with the extracted styles.

- [ ] **Step 3: Update journal.html.twig**

Remove the inline `<style>` block.

- [ ] **Step 4: Add import to components.css**

Add `@import './components/journal.css';` after `dashboard.css`.

- [ ] **Step 5: Visual verification**

Navigate to `/journal`. Verify trade list and journal layout render correctly.

- [ ] **Step 6: Commit**

```bash
git add assets/styles/components/journal.css assets/styles/components.css templates/sanctum/journal.html.twig
git commit -m "refactor: extract journal inline styles to journal.css"
```

---

### Task 4: Extract Calendar Inline Styles

**Files:**
- Read: `templates/sanctum/calendar.html.twig:9-14`
- Create: `assets/styles/components/calendar.css`
- Modify: `assets/styles/components.css` (add import)

**Interfaces:**
- Consumes: Design tokens from `tokens.css`
- Produces: Calendar-specific classes in `calendar.css`

- [ ] **Step 1: Read calendar.html.twig inline styles**

Read the `{% block stylesheets %}` section (lines 9-14) and identify:
- `.cal-impact-*` classes
- `.cal-critical` class
- Any other calendar-specific styles

- [ ] **Step 2: Create calendar.css**

Create `assets/styles/components/calendar.css` with the extracted styles.

- [ ] **Step 3: Update calendar.html.twig**

Remove the inline `<style>` block.

- [ ] **Step 4: Add import to components.css**

Add `@import './components/calendar.css';` after `journal.css`.

- [ ] **Step 5: Visual verification**

Navigate to `/calendar`. Verify calendar events render with correct impact colors.

- [ ] **Step 6: Commit**

```bash
git add assets/styles/components/calendar.css assets/styles/components.css templates/sanctum/calendar.html.twig
git commit -m "refactor: extract calendar inline styles to calendar.css"
```

---

### Task 5: Clean Up journal_new.html.twig Local Overrides

**Files:**
- Modify: `templates/sanctum/journal_new.html.twig:72-96`

**Interfaces:**
- Consumes: Canonical `.form-input`, `.form-select`, `.form-label` from `forms.css`
- Produces: Template uses only canonical form classes

- [ ] **Step 1: Read journal_new.html.twig form styles**

Read lines 72-96 and identify the local `.form-input`, `.form-textarea`, `.form-select`, `.form-label` definitions.

- [ ] **Step 2: Compare with canonical forms.css**

Compare the local definitions with `assets/styles/components/forms.css`. Note differences:
- Local: `padding: 0.65rem 0.85rem` vs canonical: `padding: 0.625rem 1rem`
- Local: `background: rgba(8, 5, 14, 0.5)` vs canonical: `background: rgba(0, 0, 0, 0.3)`
- Local: `border-radius: 0.4rem` vs canonical: `border-radius: 0.375rem`

- [ ] **Step 3: Remove local overrides**

Delete lines 72-96 from `journal_new.html.twig`. The template will now inherit canonical styles from `forms.css`.

- [ ] **Step 4: Visual verification**

Navigate to `/journal/new`. Verify form inputs still render correctly. The visual difference should be minimal (slightly different padding/background).

- [ ] **Step 5: Commit**

```bash
git add templates/sanctum/journal_new.html.twig
git commit -m "refactor: remove local form overrides from journal_new.html.twig"
```

---

### Task 6: Final Verification & Cleanup

**Files:**
- Read: All modified files
- Verify: No duplicate definitions remain

**Interfaces:**
- Consumes: All previous tasks
- Produces: Clean CSS architecture with single source of truth

- [ ] **Step 1: Verify no duplicate definitions**

Search for each class in both `shell.css` and `components.css`:
- `.sanctum-link`
- `.kpi-card`
- `.status-pill`
- `.btn-action`
- `.loading-pulse`

Confirm each exists in ONLY ONE file.

- [ ] **Step 2: Verify all inline styles extracted**

Check these templates have NO inline `<style>` blocks:
- `dashboard.html.twig`
- `journal.html.twig`
- `calendar.html.twig`
- `journal_new.html.twig`

- [ ] **Step 3: Verify CSS imports**

Check `components.css` imports:
- `forms.css`
- `dashboard.css`
- `journal.css`
- `calendar.css`

- [ ] **Step 4: Run full visual test**

Navigate through all sanctum pages:
- `/dashboard`
- `/journal`
- `/journal/new`
- `/calendar`

Verify no visual regressions.

- [ ] **Step 5: Commit (if any cleanup needed)**

```bash
git add -A
git commit -m "chore: final CSS consolidation cleanup"
```
