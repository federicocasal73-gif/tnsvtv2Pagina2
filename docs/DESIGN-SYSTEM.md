# TNSVT Design System

## Direction

The visual direction is based on the TNSVT V2 repository and the provided Google
Stitch project (Celestial Guidance). Character:
**premium / dark / technical / disciplined / intelligent / restrained.**

---

## 1. Semantic palette

| Role | Value | Use |
|---|---|---|
| Void | `#050308` | Background foundation, "the cosmos" |
| Surface | `#161121` | Cards, containers |
| Surface Container Lowest | `#100b1c` | Deeper cards, modals |
| Surface Container Low | `#1e192a` | Standard cards |
| Surface Container | `#221d2e` | Elevated cards |
| Surface Container High | `#2d2739` | Active cards |
| Surface Container Highest | `#383244` | Highest elevation |
| Gold | `#D4AF37` | **Action / emphasis / sacred / value** |
| Gold Bright | `#F2CA50` | Hover state of Gold, primary CTAs |
| Violet | `#8A3CFF` | **Navigation / intelligence / data viz** |
| Nebula Purple | `#2D1B4E` | Gradient origin for violet → gold transitions |
| Starlight White | `#FDFCF8` | Reserved for primary body on dark surfaces |
| Primary Text | `#E9DEF6` | Default body text |
| Muted Text | `#D0C5AF` | Secondary, labels |
| Outline | `#99907C` | Borders, dividers |
| Outline Variant | `#4D4635` | Subtle dividers |
| Error | `#FFB4AB` | Danger |
| Success | `#34C759` | Positive outcome |
| Warning | `#F2CA50` (reuse Gold) | Caution |

### Semantic rule (from the analysis, ratified here)

| Meaning | Color |
|---|---|
| Action / importance / value | **Gold** |
| Navigation / intelligence | **Violet** |
| Information | **White / Starlight** |
| Danger / error | **Red / Error** |
| Positive outcome | **Green** |

> Do not use Gold and Violet indiscriminately. Each has a job. Mixing them in the
> same component dilutes the signal.

---

## 2. Typography

| Token | Family | Size | Weight | Use |
|---|---|---|---|---|
| `headline-xl` | Space Grotesk | 40 / 48 | 700 | Hero, big numbers |
| `headline-lg` | Space Grotesk | 32 / 40 | 600 | Page titles |
| `headline-lg-mobile` | Space Grotesk | 28 / 36 | 600 | Page titles, mobile |
| `title-md` | Space Grotesk | 20 / 28 | 500 | Section titles |
| `body-lg` | Inter | 18 / 28 | 400 | Long-form |
| `body-md` | Inter | 16 / 24 | 400 | Default |
| `label-md` | Space Grotesk | 14 / 20 | 600 / +0.05em | Form labels, button text |
| `label-sm` | Space Grotesk | 12 / 16 | 500 / +0.02em | Tags, microcopy |

Fonts loaded by `shell.html.twig` (Sanctum) and `public/shell.html.twig` (public):
- **Space Grotesk** (300–700) — display + labels (both)
- **Inter** (300–700) — body (both)
- **Cinzel** (400, 600, 700) — used **only** on `public/home.html.twig` and
  `public/login.html.twig` for the logo display. Could be split-loaded per page
  to save ~20KB.
- **Material Symbols Outlined** — icons (both)

---

## 3. Spacing & shape

| Token | Value |
|---|---|
| Base | 8px |
| Container margin | 20px |
| Gutter | 16px |
| Stack sm | 12px |
| Stack md | 24px |
| Stack lg | 48px |
| Radius sm | 0.25rem |
| Radius DEFAULT | 0.5rem |
| Radius md | 0.75rem |
| Radius lg | 1rem |
| Radius xl | 1.5rem |
| Radius full | 9999px (pills, avatars) |

---

## 4. Elevation & depth

Achieved through **Glassmorphism + Tonal Layering**, not drop shadows.

1. **Foundation** — `#050308` Void
2. **Surface layers** — semi-transparent fill (`rgba(255,255,255,0.05)`) +
   **backdrop blur (20–30px)**
3. **Outlines** — 1px inner border with gradient (white 20% → transparent) to
   mimic light catching glass
4. **Glows** — soft colored outer glow (Gold or Violet), 0px offset, 15–25px blur

> **Rule:** glass effects are accents. The whole interface should not be glass. If
> everything is glass, nothing is.

---

## 5. Current CSS layer map (audit finding)

12 CSS files in `assets/styles/` + `assets/styles/components/` subfolder. Evolution
trail:

```
V1 (tokens.css)                          ← oldest tokens
   ↓
"elev" theme (elev.css, tokens-elev.css)  ← first rebrand attempt
   ↓
V2 (v2-tokens.css, v2-components.css,
     v2-sparklines.css)                   ← latest tokens + reusable components
   ↓
glass-premium.css                        ← decorative glass layer
   ↓
animations.css                           ← decorative motion
   ↓
app.css                                  ← generic app styles
   ↓
apk-layout-fix.css                       ← platform-specific hot-fix (Android)
apk-glowup.css                           ← platform-specific glow (Android)
web-glowup.css                           ← platform-specific glow (Web)
```

### Roles by file

| File | Role | Audit verdict |
|---|---|---|
| `tokens.css` | Base tokens | **Keep** as fallback, but redundant with v2 |
| `tokens-elev.css` | "elev" theme tokens | **Consolidate** into v2-tokens |
| `v2-tokens.css` | Master tokens | **Keep** as canonical |
| `elev.css` | "elev" theme effects | **Consolidate** into v2-components or delete |
| `v2-components.css` | Reusable components | **Keep** as canonical |
| `v2-sparklines.css` | Sparkline components | **Keep** — used by trading KPIs |
| `glass-premium.css` | Glassmorphism layer | **Keep** — used by cards |
| `animations.css` | Animations | **Keep** — used by decorative elements |
| `app.css` | App-level styles | **Audit** — may be empty or stale |
| `apk-layout-fix.css` | Android-specific | **Keep** — platform-specific |
| `apk-glowup.css` | Android-specific glow | **Consolidate** with web-glowup |
| `web-glowup.css` | Web-specific glow | **Consolidate** with apk-glowup |

### Subfolder `assets/styles/components/`

Contains files for (per audit, names truncated in `ls` output):
- `cards`, `charts`, `dashboard`, `modals`, `navigation`, `oracle`, `sanctum`,
  one more (verify with `ls`)

Not audited line-by-line. Listed in `MODULE-MAP.md`.

### Inline CSS in templates

- ~~`templates/shell.html.twig`~~ — Phase 8: inline `<style>` extracted to
  `assets/styles/shell.css`. Now loaded via `<link>` like the rest.
- ~~`templates/home.html.twig`~~ — deleted in Phase 5. Replaced by
  `public/home.html.twig` + `public/login.html.twig` + `assets/styles/home.css`
  (330 lines extracted). Loads only `tokens.css` + `home.css` (no Sanctum CSS).

### CSS consolidation plan (Phase 8+)

The current state is 14 CSS files (some platform-specific, some legacy, some
canonical). Phase 8 sets up the foundation; the actual merge is deferred because
it requires running the app to verify nothing breaks visually.

**Target state** (after merge, future phase):

| New canonical file | Replaces |
|---|---|
| `tokens.css` (canonical) | `tokens.css` + `tokens-elev.css` + `v2-tokens.css` (3 → 1) |
| `components.css` (canonical) | `elev.css` + `v2-components.css` (2 → 1) |
| `glow.css` (canonical) | `glass-premium.css` + `apk-glowup.css` + `web-glowup.css` (3 → 1) |
| `shell.css` ✅ (Phase 8) | (already done — extracted from `shell.html.twig`) |
| `home.css` ✅ (Phase 5) | (already done — extracted from old `home.html.twig`) |

After consolidation, the shell would load:

```html
<link href="tokens.css" />           <!-- all token vars (was 3) -->
<link href="components.css" />       <!-- all UI primitives (was 2) -->
<link href="sparklines.css" />       <!-- v2 sparklines -->
<link href="glow.css" />             <!-- glassmorphism + platform glows (was 3) -->
<link href="animations.css" />       <!-- (kept — distinct concern) -->
<link href="shell.css" />            <!-- shell-specific (was inline) -->
```

That's 6 files instead of 9 (or 8 if we merge glass-premium into components).

**Why not merge yet:**

- Risk: removing a CSS file without testing each page visually can break
  rendering. Templates use combinations of classes that are spread across
  multiple files; one missing class breaks visuals.
- Effort: requires a smoke test pass on every Sanctum page.
- Schedule: defer until a manual review window (operator + browser session).

**Why this plan is OK to commit now:**

- Files are documented as redundant in this DESIGN-SYSTEM.md.
- A future contributor can execute the merge with confidence.
- No behavior change today.

### What this audit already extracted (✅)

- `assets/styles/home.css` — 330 lines from old `home.html.twig` (Phase 5).
- `assets/styles/shell.css` — ~30 lines from `shell.html.twig` (Phase 8).

---

## 6. Components (target inventory)

These are the reusable component patterns identified during audit. Each should
have a single source of truth and be reusable across templates.

### Buttons

| Variant | Use | Today |
|---|---|---|
| Primary | Gold background, on-primary text | scattered |
| Ghost | Transparent + gold border | scattered |
| Action | Small toggle button | `.btn-action` in `shell.html.twig` inline |
| Icon | Material Symbol + tooltip | scattered |

### Cards

| Variant | Use | Today |
|---|---|---|
| Glass card | Default surface | `.glass-card-elev` (token) |
| KPI card | Trading-style number with label | `.kpi-card` in `shell.html.twig` inline + `kpi-card-elev` in `dashboard.html.twig` |
| Tier card | User tier badge card | `.tier-initiate`, etc. in `shell.html.twig` inline |
| Data table | Tabular data | `.data-table` in `shell.html.twig` inline |
| Status pill | Compact status indicator | `.status-pill` in `shell.html.twig` inline |

### Inputs

| Variant | Use | Today |
|---|---|---|
| Text field | Default | scattered |
| Login field | Larger, gold-bordered on focus | `assets/styles/home.css` `.form-field` (was inline in `home.html.twig` until Phase 5) |
| Select | dropdown | scattered |
| Date | date picker | scattered |

### Navigation

| Component | Today |
|---|---|
| Sidebar | `shell.html.twig` (inline `.sanctum-link`) |
| Topbar | `shell.html.twig` (`.topbar`) |
| Mobile tab bar | `_partials/tabbar.html.twig` |
| Breadcrumb | none |
| Pagination | none |

### Charts & data viz

| Component | Today |
|---|---|
| Sparkline | `v2-sparklines.css` |
| Equity curve | not standardized |
| Drawdown chart | not standardized |
| Heatmap | not standardized |

### Specialized

| Component | Use |
|---|---|
| Tier badge | user progression |
| Honor ribbon | honor board |
| Macro event chip | calendar |
| Guardian signal | proposed |

### Action items for F2

- [x] Extract `shell.html.twig` ~30 lines inline `<style>` (Phase 8 done —
      moved to `assets/styles/shell.css`).
      `home.html.twig` extracted to `assets/styles/home.css` ✅ (Phase 5).
- [ ] Decide: 1 master tokens file or 3? (recommendation: 1 master + 1 legacy
      alias)
- [ ] Decide: 1 glow file or 3? (recommendation: 1 master + 2 platform overrides)
- [ ] Document each component in `assets/styles/components/README.md`.

---

## 7. Responsive standards

| Breakpoint | Width |
|---|---|
| `xs` | < 480px |
| `sm` | 480–767px |
| `md` | 768–1023px |
| `lg` | 1024–1439px |
| `xl` | ≥ 1440px |

The sidebar is hidden on `< md` and replaced by the bottom tab bar (4 tabs today,
target 6).

---

## 8. Accessibility baseline

| Concern | Rule |
|---|---|
| Contrast | Body text on Void ≥ 7:1 (AAA) |
| Focus | Visible focus ring on all interactive elements |
| Touch targets | ≥ 44×44px on mobile |
| Icon-only buttons | Always have `aria-label` |
| Forms | `<label>` paired with each input |
| Color is not the only signal | Pair Gold with text or icon |

Detailed WCAG audit belongs to `/qa` (Phase 5).

---

## 9. Stitch relationship

`stitch_tnsvt_app_m_vil/celestial_guidance/DESIGN.md` is the **original** design
system document. This file is the **audited, live, repo-accurate** version. They
should converge.

The 27 mockup HTML files in `stitch_tnsvt_app_m_vil/` are reference, not code. They
must not be loaded by the app.

---

## 10. Anti-patterns to remove

| Anti-pattern | Where |
|---|---|
| Hardcoded color literals | many templates — replace with `var(--gold-elev)` etc. |
| Repeated `<style>` blocks | `shell.html.twig` (deferred, minor), ~~`home.html.twig`~~ ✅ (Phase 5) |
| Glow on every element | scattered |
| Glass on every surface | scattered |
| Mixing Gold and Violet in the same UI element without role separation | some cards |

---

**Status:** Design system consolidated post-audit. Inline CSS identified.
Consolidation actions listed but not executed. Hand off to `/consolidate` for
the candidate work.
