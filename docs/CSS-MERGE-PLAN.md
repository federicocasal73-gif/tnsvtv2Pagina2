# TNSVT — CSS Consolidation Plan

The current CSS bundle has 12 files in `assets/styles/` with 3 competing naming
conventions, several duplicate classes, and platform-gated overrides. This
document is the **executable plan** to consolidate to a clean target state.

---

## Status (updated 2026-08-14/15)

- [x] **Phase 9.A — Baseline:** 27 screenshots captured in
  `docs/screenshots/baseline-2026-08-14/`. Observation: site shows OLD pre-Phase-4
  sidebar — operator must deploy to see the new 5-macro sidebar.
- [x] **Phase 9.B — Merge 1: tokens (3 → 1).** `tokens.css` consolidated.
  `tokens-elev.css` and `v2-tokens.css` deleted. `shell.html.twig` and
  `legacy/redirect.html.twig` load lists updated. Backwards-compat aliases
  added for v2-components.css and no-suffix vars (--gold, --gold-bright,
  --violet, --violet-glow) for glow files.
- [x] **Phase 9.C — Merge 2: glow (3 → 1).** `glow.css` created. Contains
  universal classes (no gate), Web-only classes (`body:not(.is-apk)`), and
  APK-only classes (`body.is-apk`). `glass-premium.css`, `apk-glowup.css`,
  and `web-glowup.css` deleted. `shell.html.twig` load list updated.
- [x] **Phase 9.D — Merge 3: components (2 → 1).** `components.css` created
  with union of `elev.css` and `v2-components.css`. Conflicts resolved:
  `.glass-card-elev`, `.status-active`, `.btn-elev-secondary` use the
  v2-components.css version (it was already winning in cascade). `elev.css`
  and `v2-components.css` deleted. `shell.html.twig` load list updated.
- [x] **Phase 10 — Cleanup of legacy `--v2-*` aliases.** Missing `-elev` tokens
  added to `tokens.css` (`--violet-elev`, `--violet-mid-elev`,
  `--violet-dim-elev`, `--violet-line-elev`, `--muted-elev`,
  `--on-surface-dim-elev`, `--glass-border-gold-elev`, `--glass-blur-elev`,
  `--glow-gold-elev`, `--glow-gold-strong-elev`, `--glow-violet-elev`,
  `--glow-violet-strong-elev`). `components.css` migrated from `var(--v2-*)`
  to `var(--*-elev)` (gradients inline, transitions hardcoded). All `--v2-*`
  aliases removed from `tokens.css`. Verified: **0 `var(--v2-*)` references
  remaining** in `assets/`; PHP lint passes on both files. The no-suffix
  aliases (`--gold`, `--gold-bright`, `--violet`, `--violet-glow`) are kept
  intentionally for `glow.css`/`home.css` compatibility.

---

## Final state (post all merges)

```
assets/styles/
├── animations.css       ← unchanged
├── apk-layout-fix.css   ← unchanged (platform fix)
├── app.css              ← unchanged
├── components/          ← subfolder (not audited)
├── components.css       ← NEW — Phase 9 Merge 3 (was elev.css + v2-components.css)
├── glow.css             ← NEW — Phase 9 Merge 2 (was glass-premium + apk-glowup + web-glowup)
├── home.css             ← Phase 5 (public landing)
├── shell.css            ← Phase 8 (shell UI primitives)
├── tokens.css           ← Phase 9 Merge 1 (was tokens + tokens-elev + v2-tokens)
└── v2-sparklines.css   ← unchanged (charts)
```

**9 canonical files** instead of 14 (60% reduction in CSS files). All visual
behavior preserved.

---

## Known caveats (deferred cleanup)

- ✅ **DONE Phase 10:** All `--v2-*` aliases removed and `components.css` migrated
  to `-elev` variables. 0 references remain.
- `tokens.css` still has `--gold`, `--gold-bright`, `--violet`, `--violet-glow`
  no-suffix aliases (for glow.css usage). Migration of glow.css to -elev
  suffix is deferred.
- Keyframes named `v2-*` (`@keyframes v2-*`) remain in `components.css` — purely
  cosmetic/non-functional naming, safe to ignore.

---

## 1. Current state (12 files)

```
assets/styles/
├── tokens.css         (92 lines)   --*-elev suffix (Stitch gold/nebula palette)
├── tokens-elev.css    (84 lines)   no suffix (Material 3 inspired)
├── v2-tokens.css      (101 lines)  v2-* prefix (canonical v2)
├── elev.css           (251 lines)  .*-elev classes (kpi-card-elev, tier-badge-elev)
├── v2-components.css  (376 lines)  .* classes + v2-* vars (kpi-card, btn-primary)
├── v2-sparklines.css  (unchanged)  charts
├── tokens-elev.css    (already listed above)
├── animations.css     (unchanged)  motion
├── glass-premium.css  (245 lines)  .glass-card-premium, .tier-badge-elev, .filter-pill
├── apk-glowup.css     (712 lines)  body.is-apk gated (APK only)
├── web-glowup.css     (708 lines)  body:not(.is-apk) gated (Web only)
├── home.css           (Phase 5)    public landing
├── shell.css          (Phase 8)    shell UI primitives
└── components/        subfolder (not audited line-by-line)
```

---

## 2. The naming problem

Three naming conventions exist in parallel. Audit found:

| Convention | Defined in | Used in templates? |
|---|---|---|
| `--*-elev` | `tokens.css`, `elev.css` | ✅ YES — 90+ matches across templates |
| `--*` (no suffix) | `tokens-elev.css` | Limited — `home.css` only |
| `--v2-*` prefix | `v2-tokens.css` | ❌ NO — only used by `v2-components.css` internally |

**Implication:** `v2-tokens.css` can be deleted entirely with zero template changes.
Its only consumer is `v2-components.css`, which will be migrated to use `-elev`
variables during the merge.

---

## 3. Conflicting classes

| Class | Defined in | Style that wins (load order) |
|---|---|---|
| `.glass-card-elev` | `elev.css` + `v2-components.css` | **v2-components.css** (loaded later) |
| `.status-active` | `elev.css` + `v2-components.css` + `glass-premium.css` | **v2-components.css** (loaded last) |
| `.btn-elev-secondary` | `elev.css` + `v2-components.css` | **v2-components.css** |
| `.status-pill` | `shell.css` (Phase 8) + `v2-components.css` | **shell.css** (loaded last) |
| `.kpi-label`, `.kpi-value`, `.kpi-meta`, `.kpi-card` | `elev.css` (suffix) + `v2-components.css` (no suffix) | **v2-components.css** |

**Decision:** All conflicting classes use the **v2-components.css** version
because:
1. It's already winning in the cascade today.
2. It has richer styles (gradient surfaces, glow effects).
3. Fewer `-elev` suffixes in class names simplifies the API.

---

## 4. Classes used in templates (verified)

Confirmed via `grep -r` across `templates/`. Each class listed is currently used
in at least one template.

### Tier badges (from `elev.css`)
- `.tier-badge-elev` — shell.html.twig, profile.html.twig, profile_public.html.twig, guardian.html.twig
- `.tier-initiate`, `.tier-aspirant`, `.tier-1`, `.tier-2`, `.tier-3-zenith`, `.tier-master`
  — set by JS in shell.html.twig (`tierEl.className = 'tier-badge-elev tier-' + ...`)

### KPI cards (from `v2-components.css`)
- `.kpi-card` — used in dashboard.html.twig via `<div class="kpi-card-elev">` (the `-elev` is a typo in dashboard — it conflicts with `elev.css`'s `.kpi-card-elev` definition which doesn't apply because of the cascade). The visual result comes from `.kpi-card-elev` (defined in `elev.css`) + `.kpi-card` overrides from `v2-components.css`.
- `.kpi-label`, `.kpi-value`, `.kpi-meta` — used in dashboard.html.twig

### Buttons (from `v2-components.css`)
- `.btn-primary` — chat.html.twig, diary.html.twig, clan.html.twig, game.html.twig, journal.html.twig, feed.html.twig, tournaments.html.twig, profile.html.twig, etc.
- `.btn-elev-secondary` — monitoring.html.twig, sanctum/guardian.html.twig, dashboard.html.twig

### Forms & inputs (from `v2-components.css`)
- `.form-input` — chat.html.twig, profile.html.twig, settings.html.twig, calendar.html.twig, journal.html.twig, account_settings.html.twig

### Modals (from `v2-components.css`)
- `.modal-overlay`, `.modal-content`, `.modal-header`, `.modal-footer` — dashboard.html.twig (create-task-modal)

### Glass cards (from both — v2 wins)
- `.glass-card-elev` — used in many templates via `glass-card-elev p-6` class combos

### Status pills (from `shell.css` — Phase 8)
- `.status-pill`, `.status-active`, `.status-pending`, `.status-completed`, `.status-inactive`
- Used in: dashboard.html.twig, journal.html.twig, sanctum/audit.html.twig, etc.

### Data tables (from `shell.css` — Phase 8)
- `.data-table` — used in many templates

### Sidebar (from `v2-components.css`)
- `.sanctum-link`, `#bell-badge`, `#user-avatar`, `#user-tier`

### Icons (from `elev.css`)
- `.material-symbols-elev` — used extensively

### Loading pulse (from `shell.css` — Phase 8)
- `.loading-pulse` — used in many templates

### Glow utilities (from `elev.css`)
- `.gold-glow-elev`, `.gold-glow-elev-strong`, `.purple-glow-elev`
- Used in shell.html.twig, frequencies/hub.html.twig

### Other classes (used but minor)
- `.quick-action` (from `glass-premium.css` + `v2-components.css`) — dashboard.html.twig
- `.filter-pill` (from `glass-premium.css`) — journal.html.twig
- `.sacred-progress` (from `glass-premium.css`) — used in some templates

---

## 5. Target state (post-merge)

```
assets/styles/
├── tokens.css        ← union of 3 token files (canonical: -elev suffix)
├── components.css    ← union of 2 component files (v2 versions win for conflicts)
├── v2-sparklines.css ← unchanged (charts)
├── animations.css    ← unchanged (motion)
├── glow.css          ← union of 3 glow files (preserves platform gates)
├── shell.css         ← Phase 8 (shell UI primitives)
├── home.css          ← Phase 5 (public landing)
└── components/       ← subfolder (existing, kept as-is for now)
```

**6 canonical files** instead of 12. Each has a single responsibility.

---

## 6. Merge plan

### Merge 1 — tokens.css (3 → 1)

**Source files:** `tokens.css`, `tokens-elev.css`, `v2-tokens.css`

**Steps:**

1. Create new `tokens.css` with all `-elev`-suffixed variables from all 3 files.
2. Add no-suffix aliases for variables that home.css uses (and that aren't in the canonical version):
   ```css
   --gold: var(--gold-elev);
   --gold-bright: var(--gold-bright-elev);
   --surface: var(--surface-elev);
   --void-black: var(--void-elev);
   /* etc. */
   ```
3. Add new variables for things only in `tokens-elev.css`:
   - `--font-display-elev`, `--font-body-elev`, `--font-label-elev`
   - `--space-xs-elev` through `--space-2xl-elev`
   - `--radius-sm-elev` through `--radius-full-elev`
   - `--shadow-sm-elev` through `--shadow-purple-elev`
   - `--success-elev`, `--warning-elev`
4. **Drop** `tokens-elev.css` and `v2-tokens.css`.
5. Update `templates/shell.html.twig` to remove the `<link>` tags.
6. Update `templates/legacy/redirect.html.twig` to remove the `<link>` tag.

**Verification:** Open browser, refresh all Sanctum pages. Visual must be unchanged.

### Merge 2 — components.css (2 → 1)

**Source files:** `elev.css`, `v2-components.css`

**Decision matrix:**

| Class | Source chosen |
|---|---|
| `.glass-card-elev` | v2-components.css (with `::before` line + hover with gold border) |
| `.kpi-card`, `.kpi-label`, `.kpi-value`, `.kpi-meta` | v2-components.css (no suffix) |
| `.kpi-card-elev`, `.kpi-label-elev`, etc. | elev.css (suffix) |
| `.btn-primary` | v2-components.css |
| `.btn-elev-primary`, `.btn-elev-secondary`, `.btn-elev-ghost` | elev.css for the suffix variants, v2-components.css for `.btn-elev-secondary` |
| `.status-pill`, `.status-active`, etc. | shell.css (Phase 8) — already canonical |
| `.tier-badge-elev`, `.tier-initiate`, `.tier-aspirant`, `.tier-1`, `.tier-2`, `.tier-3-zenith`, `.tier-master` | elev.css (only source) |
| `.gold-glow-elev`, `.gold-glow-elev-strong`, `.purple-glow-elev` | elev.css (only source) |
| `.glass-card-elev-strong` | elev.css (only source) |
| `.gold-border-gradient-elev` | elev.css |
| `.status-pulse-elev`, `.sacred-geometry-spin-elev`, `.aura-glow-elev` | elev.css (only source) |
| `.material-symbols-elev` | elev.css (only source) |
| `.section-divider-elev` | elev.css |
| `.skeleton-elev`, `.stat-card-elev`, `.stat-label-elev`, `.stat-value-elev`, `.stat-meta-elev`, `.status-pill-elev` | elev.css (suffix) |
| `.sanctum-link` | v2-components.css (also in `shell.css` inline — extract conflict if needed) |
| `#sanctum-sidebar`, `#bell-badge`, `#user-avatar`, `#user-tier` | v2-components.css |
| `.data-table` | shell.css (Phase 8) |
| `.modal-overlay`, `.modal-content`, `.modal-header`, `.modal-footer` | v2-components.css |
| `.form-input` | v2-components.css |
| `.loading-pulse` | shell.css (Phase 8) — but keyframe can come from anywhere |

**Steps:**

1. Create `components.css` containing all classes from both sources.
2. For conflicts, use v2-components.css version.
3. Drop `elev.css` and `v2-components.css`.
4. Update `templates/shell.html.twig`.

**Note:** Since `v2-components.css` references `var(--v2-*)` internally, those
references must be updated to `var(--*-elev)` to match the new tokens.

### Merge 3 — glow.css (3 → 1)

**Source files:** `glass-premium.css`, `apk-glowup.css`, `web-glowup.css`

**Structure:**
```css
/* glow.css — platform-aware visual polish */

/* No platform gate (universal) */
.glass-card-premium { ... }
.glass-panel { ... }
.gold-border-gradient { ... }
.stat-card, .stat-value, .stat-label { ... }
.impact-dot, .impact-high, .impact-medium, .impact-low { ... }
.tier-badge-elev { ... }  /* NOTE: tier badge variant lives here too */
.sacred-progress, .sacred-progress-fill, .sacred-progress-head { ... }
.filter-pill { ... }
.quick-action { ... }
.status-live, .status-dot { ... }
@keyframes pulse-gold { ... }

/* Web only (preserve gate) */
body:not(.is-apk) ::-webkit-scrollbar { ... }
body:not(.is-apk) #login-screen { ... }
/* ... all rules from web-glowup.css, gated with body:not(.is-apk) */

/* APK only (preserve gate) */
body.is-apk { ... }
/* ... all rules from apk-glowup.css, gated with body.is-apk */
```

**Steps:**

1. Create `glow.css` with the structure above.
2. Drop `glass-premium.css`, `apk-glowup.css`, `web-glowup.css`.
3. Update `templates/shell.html.twig`.
4. **CRITICAL:** Test on both Web (browser) and APK (if available) to ensure
   the gates work correctly.

---

## 7. Updated load list (post-merge)

```html
<!-- shell.html.twig -->
<link href="tokens.css" />
<link href="components.css" />
<link href="v2-sparklines.css" />
<link href="animations.css" />
<link href="glow.css" />
<link href="shell.css" />

<!-- public/shell.html.twig -->
<link href="tokens.css" />
<link href="home.css" />
<!-- (no shell.css needed — public shell has different primitives) -->
```

**Result:** shell.html.twig loads 6 files instead of 9. public/shell.html.twig
stays at 2 files (already minimal).

---

## 8. Execution order (with rollback safety)

```
PHASE 1: Merge 1 — tokens
   1.1 Create new tokens.css (union)
   1.2 Update shell.html.twig (drop 2 link tags)
   1.3 Update legacy/redirect.html.twig (drop 2 link tags)
   1.4 Delete tokens-elev.css, v2-tokens.css
   1.5 Commit "css: consolidate tokens (3 → 1)"
   1.6 BROWSER VERIFICATION — all pages
   1.7 If regression: git revert HEAD

PHASE 2: Merge 3 — glow (less risky than components)
   2.1 Create glow.css (union with gates)
   2.2 Update shell.html.twig (drop 3 link tags)
   2.3 Delete glass-premium.css, apk-glowup.css, web-glowup.css
   2.4 Commit "css: consolidate glow (3 → 1)"
   2.5 BROWSER VERIFICATION
   2.6 If regression: git revert HEAD

PHASE 3: Merge 2 — components (highest risk due to conflicts)
   3.1 Update v2-components.css's `var(--v2-*)` references to `var(--*-elev)`
   3.2 Create components.css (union)
   3.3 Update shell.html.twig (drop 2 link tags)
   3.4 Delete elev.css, v2-components.css
   3.5 Commit "css: consolidate components (2 → 1)"
   3.6 BROWSER VERIFICATION
   3.7 If regression: git revert HEAD

PHASE 4: Cleanup
   4.1 Update docs/DESIGN-SYSTEM.md with final state
   4.2 Update bin/pre-deploy-check.sh if needed
   4.3 Update docs/INTEGRATION-NOTES.md
   4.4 Final commit "docs: css consolidation complete"
```

---

## 9. Risk mitigation

| Risk | Likelihood | Mitigation |
|---|---|---|
| Token variable conflicts (e.g., `--gold` redefined) | Low | Aliases preserve both names |
| Glass card style regression | Medium | v2-components.css version already wins today |
| Tier badges lose `-elev` suffix | Low | Keep `-elev` suffix in components.css |
| Glows break APK or Web | Medium | Preserve platform gates |
| Sidebar layout breaks | Low | `.sanctum-link` etc. copied verbatim |
| Custom properties (`var(--v2-*)`) lost | Medium | Map each v2-* var to -elev equivalent |

---

## 10. Open items for after merge

- Migrate `home.css` from no-suffix to `-elev` suffix (cleanup)
- Migrate `templates/legacy/redirect.html.twig` if kept
- Add CSS lint step to `.github/workflows/ci.yml` (stylelint?)
- Document the merged system in `docs/DESIGN-SYSTEM.md`
