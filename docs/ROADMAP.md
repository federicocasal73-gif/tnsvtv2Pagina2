# TNSVT V2 Roadmap

Updated after initial audit (`/audit`) and module mapping (`/map`).

Legend: `[x]` done · `[~]` in progress · `[ ]` not started · `[!]` blocked

---

## Phase 0 — Security & Audit (CURRENT)

- [x] Repository reconnaissance (stack, files, controllers, entities)
- [x] Route map (web + API)
- [x] Module map (per macro, status: REAL/LEGACY/STUB)
- [x] Dependency map (services per macro)
- [x] Technical debt map (CSS fragmentation, inline CSS)
- [x] Visual debt map (Stitch vs repo divergence)
- [x] SECURITY.md drafted — **operator action required** (rotation per §3)
- [ ] **Rotate all secrets** — manual, see `SECURITY.md` §3
- [ ] Move secrets to Symfony Secrets vault
- [ ] Remove committed `JWT_PASSPHRASE` from `.env` line 50
- [ ] Fix `APP_SECRET` empty for prod
- [ ] Decide on `stitch_tnsvt_app_m_vil/` (remove or `.gitignore`)

## Phase 1 — Product Organization

- [x] Information architecture (target documented in `INFORMATION-ARCHITECTURE.md`)
- [x] Navigation hierarchy (5 macros + Admin target)
- [~] User flows (drafted in `USER-FLOWS.md`, needs `/ia` validation)
- [x] Role boundaries (Public / Sanctum / Admin / Mentor target)
- [ ] **Validate IA against code** — confirm every macro maps to real routes
- [ ] **Verify `/sanctum` route** — audit open item

## Phase 2 — Design System

- [x] Token consolidation (target: 1 master + 1 legacy alias; current: 3)
- [x] Component inventory (target list in `DESIGN-SYSTEM.md` §6)
- [~] Shell/navigation consolidation (sidebar visual restructure done
      Phase 4 — see `INTEGRATION-NOTES.md` §9; folder restructure pending)
- [ ] Form/table/card standards (extract from inline CSS)
- [ ] Responsive standards (mobile tab bar to 6 tabs target)
- [ ] **Extract inline `<style>` blocks** from `shell.html.twig` (deferred —
      minor, ~30 lines) into `assets/styles/components/`. `home.html.twig`
      extracted to `assets/styles/home.css` ✅ (Phase 5).
- [ ] **CSS consolidation** — actual merge deferred (documented in
      `DESIGN-SYSTEM.md`, requires running app for visual verification)

## Phase 3 — Module Consolidation

- [ ] Trading macro (Journal as 1 page with tabs: Resumen / Operaciones /
      Estadísticas / Equity / Drawdown / Calendario)
- [ ] Journal split — verify what exists vs target
- [ ] Formation macro (Academy/Campus consolidation question — see `MODULE-MAP.md`)
- [ ] Mind & Macro macro
- [ ] Community macro (Social + Compete + Wallet grouping)
- [ ] Account macro
- [ ] **Admin / Account settings split** (`/sanctum/settings` is currently shared)
- [x] **Notifications consolidation** (1 sidebar entry, in Account only)
- [ ] Mentor — **gap**, no code yet
- [ ] **Guardian surface** — atoms exist, surface doesn't (see `USER-FLOWS.md` §6.5)

## Phase 4 — Integration

- [ ] Real data everywhere (replace stubbed KPIs in dashboard)
- [ ] API contracts (cross-check every template with backing API)
- [ ] Authorization (role gates verified on every endpoint)
- [ ] Notifications (output channel via `PushService` — verify wiring)
- [ ] Trading integrations (cTrader, broker connections)
- [x] **Guardian service** (Phase 1 API + Phase 2 UI + Phase 3 event
      subscriber + dashboard widget + pre-trade banner + daily-loss signal —
      all done — see `INTEGRATION-NOTES.md`)
- [x] **Sidebar 5-macro restructure** (Phase 4 done — see `INTEGRATION-NOTES.md` §9)
- [x] **Home/Login split** (Phase 5 done — see `INTEGRATION-NOTES.md` §10)
- [x] **CSS extraction from home.html.twig** (Phase 5 done — moved to
      `assets/styles/home.css`)
- [x] **CI pipeline** (Phase 7 done — `.github/workflows/ci.yml`)
- [x] **shell.html.twig inline CSS extraction** (Phase 8 done — moved to
      `assets/styles/shell.css`)
- [x] **Settings split — Account** (Phase 7 done — `/account/settings` for user
      prefs, `/sanctum/settings` stays admin)
- [x] **CSS consolidation plan documented** (Phase 8 done — see
      `CSS-MERGE-PLAN.md`)
- [x] **CSS Merge 1: tokens (3 → 1)** (Phase 9 done — see `CSS-MERGE-PLAN.md`)
- [x] **CSS Merge 2: glow (3 → 1)** (Phase 9 done — see `CSS-MERGE-PLAN.md`)
- [x] **CSS Merge 3: components (2 → 1)** (Phase 9 done — see `CSS-MERGE-PLAN.md`)
- [x] **CSS cleanup — `--v2-*` aliases removed** (Phase 10 done — tokens migrated
      to `-elev`, components.css uses `-elev` vars only, 0 refs remain)
- [ ] **CSS cleanup (deferred)**: migrate glow.css to `-elev` suffix only
      (no-suffix aliases `--gold`, `--violet-glow` still needed by glow/home)
- [ ] AI (Oracle is the AI surface today — verify scope)
- [ ] **Home/Login split** — `home.html.twig` → `public/home.html.twig` +
      `public/login.html.twig`

## Phase 5 — Quality

- [ ] Functional QA (every route tested)
- [ ] Visual QA (consistency against `DESIGN-SYSTEM.md`)
- [ ] Mobile QA (responsive at all breakpoints)
- [ ] Security (`SECURITY.md` rotation + auth/Z hardening)
- [ ] Performance (cacher, eager loading, asset compilation)
- [ ] Accessibility (WCAG 2.2 AA baseline)
- [ ] CORS review (currently allows private network ranges — risk)

## Phase 6 — Production

- [ ] Deployment configuration (verify Hostinger prod config still works)
- [ ] Observability (Monolog, Mercure hub, error tracking)
- [ ] Backup/rollback plan
- [ ] **Final rotation** of all secrets pre-launch
- [ ] Production audit (final pre-flight)
- [ ] **Remove `stitch_tnsvt_app_m_vil/` from root** (before any external commit)
- [ ] Final release

---

## Cross-cutting open items (carried across phases)

These were surfaced during audit and need decisions before implementation:

1. **Where does `/trading` go?** Currently a legacy placeholder. Options:
   - Migrate to v2 fully (long, scope creep).
   - Keep legacy bridge, document as "external system".
   - Replace `/trading` with `/journal/trade` (record-only) and keep tnsvt.com
     for execution. (Recommended for now.)

2. **Academia vs Campus** — same product? Verify with business owner.

3. **Frequencies placement** — audio hub under Mind & Macro or under Account >
   Preferences?

4. **Mentor area** — implement or defer? `INFORMATION-ARCHITECTURE.md` lists it
   but no code exists.

5. **Guardian scope** — full cross-cutting service or Sanctum Home widget + 1
   page under Mind & Macro? (Recommended: widget + 1 page; full service deferred.)

6. **Notification sidebar entry** — keep in Account only or in Community too?
   (Recommended: Account only — it is an inbox, not a community surface.)

7. **Settings split** — `/account/settings` (personal) vs `/admin/settings`
   (system). Currently shared.

8. **`/sanctum` route** — verify it exists; if not, add it.

9. **Home/Login split** — `/` landing + `/login` form. Documented in IA.

10. **CSS extraction order** — extract from `home.html.twig` first (heaviest),
    then `shell.html.twig`, then any other template with inline `<style>`.

---

## Carried risk

The following items have been identified and will remain until addressed:

- **Compromised secrets in the zip** — operator action required.
- **Inline CSS payload in `home.html.twig`** — ~330 lines; performance + maintain.
- **3 token CSS files** — design system fragmentation.
- **3 glow CSS files** — design system fragmentation.
- **24 flat `sanctum/*.twig`** — navigation hierarchy impossible without grouping.
- **`/trading` is a legacy placeholder** — product is incomplete without it.
- **No Guardian surface** — atoms exist, surface doesn't.

---

**Status:** Roadmap grounded in audit. Phases 0–1 in progress. Phases 2+ queued.
No implementation begun. Operator action required for secrets rotation.
