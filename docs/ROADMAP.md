# TNSVT V2 Roadmap

Updated after initial audit (`/audit`) and module mapping (`/map`).
**Last cleanup pass: 2026-08-19 — Phase 1-5 complete (see "Cleanup Pass" below).**

Legend: `[x]` done · `[~]` in progress · `[ ]` not started · `[!]` blocked

---

## Cleanup Pass — 2026-08-19

### Phase 1: Quick wins
- [x] Cleaned phantom trades in DB (`DEMO` $100M, `280416`, `FRAN.1428.JUSTI`, `ADMIN01`)
- [x] Fixed `journal.html.twig` — added missing `stat-wins` / `stat-losses` IDs to KPI grid
- [x] Fixed `calendar.html.twig` — moved `data-controller="calendar-events"` to wrap filters + table, added `data-action` to inputs
- [x] Added `for` attributes on calendar labels
- [x] Added `scope="col"` to calendar table headers
- [x] Added CSS classes to `calendar.css` (cal-impact-1/2/3, cal-critical)
- [x] Removed duplicate `webgl-background.js` from `dashboard.html.twig`
- [x] Translated "Invoke Protocol" → "Invocar Protocolo" (shell.html.twig)
- [x] Translated "Active"/"Inactive" → "Activa"/"Inactiva" (dashboard.html.twig)
- [x] Added `aria-label` to icon-only buttons in shell.html.twig

### Phase 2: Token cleanup
- [x] Replaced hardcoded `#4ade80`/`#f87171`/`#fbbf24` in dashboard.html.twig JS with `var(--success-elev)`/`var(--error-elev)`/`var(--warning-elev)`
- [x] Replaced hardcoded colors in home.html.twig with CSS classes (`.pnl-pos`/`.pnl-neg`)
- [x] Replaced hardcoded colors in journal.html.twig Guardian severity palette
- [x] Migrated `dashboard.css` hardcoded colors to tokens
- [x] Added `ec-legend-pos/neg/mix` CSS classes (replacing inline `style="background: #..."`)

### Phase 3: Stimulus restoration
- [x] Updated `importmap.php` with `@hotwired/stimulus`, `@hotwired/turbo`, `@symfony/stimulus-bundle`
- [x] Downloaded and vendored stimulus/turbo modules to `assets/third_party/`
- [x] Replaced shim in `stimulus_bootstrap.js` with real `startStimulusApp()`
- [x] Removed unused `@hotwired/turbo` import from `calendar_events_controller.js`
- [x] **Stimulus controllers now active**: pwa, calendar-events, notifications, chat, wallet, settings, leaderboard, feed, profile-public

### Phase 4: Asset/CSS cleanup
- [x] Cleaned 0-byte recursive-hash broken compile artifacts on server
- [x] Verified `redirect.css` is used (legacy redirect template)
- [x] Did NOT touch `app.css` (164KB) — V1 legacy classes are unused but conservatively kept per "100% confirmado" rule

### Phase 5: A11y + Docs
- [x] Added `aria-current="page"` to active sidebar links (shell.html.twig JS)
- [x] Added `aria-live` + `role="alert"`/`role="status"` to login error/success
- [x] Added `aria-hidden="true"` to decorative cross in login
- [x] Updated ROADMAP.md (this file)

### Phase 6: UX/SEO/Security polish (2026-08-19/20)
- [x] **6.1 Top 5**: reduced-motion (`transform: none !important`), sidebar contrast (`gold-elev opacity-70`), touch targets 44×44 (`@media (pointer:coarse)`), URL state persistence (calendar/journal/dashboard/feed/chat/social), custom 404/500 pages (`templates/bundles/TwigBundle/Exception/`)
- [x] **6.2 Modal a11y**: `apiSetupModal()` (focus trap + Escape + restore focus) applied to trade/create-task/cal-day/academic/chat-new-dm modals; `role=dialog`/`aria-modal`/`aria-labelledby`
- [x] **6.3 Empty states**: `_partials/empty_state.html.twig` + skeleton CSS
- [x] **6.4 Toast queue**: max 5, dedupe, hover-pause, click-to-dismiss, ARIA live, TTL per kind
- [x] **6.5 Open Graph**: `_partials/og_meta.html.twig` (OG + Twitter + PWA meta) in both shells
- [x] **6.5b Dynamic OG image**: `OgImageController` + `og_image.svg.twig` at `/og/image` (variants default/gold/violet/trade), `og:image` now points to dynamic endpoint
- [x] **6.6 CSRF/CSP hardening**: `framework.yaml` cookie_samesite=lax/secure=auto/httponly; nelmio_security CSP (`object-src 'none'`, `form-action 'self'`, `upgrade-insecure-requests`), permissions_policy
- [x] **6.7 SW cleanup**: `sw.js` rewrite (CACHE_VERSION, precache, NEVER_CACHE_PATHS)
- [x] **6.7b SW dynamic version**: `ServiceWorkerController` serves `/sw.js` with `CACHE_VERSION=tnsvt-{APP_VERSION}`; static `public/sw.js` removed (deployed), `sw.js.twig` template
- [x] **6.8 Confirm/alert**: `apiConfirm()` styled Promise modal; `confirm()` migrated in shell/bookings_admin/feed/journal/journal_new/social/tasks/diary/macro-dashboard; alert→`apiToast()` in 8 templates
- [x] **6.9 CLS fixes**: width/height/decoding=lazy on chat/feed/journal_new/profile images
- [x] **6.10 Empty-state helper**: `apiEmpty()` applied in chat/feed/journal/social/bookings_admin/dashboard/game
- [x] **6.11 CORS hardening**: prod `.env.local` narrowed to
      `localhost|127.0.0.1|tnsvt.com|www.tnsvt.com` (private ranges
      `192.168.*`, `100.*`, `10.0.2.2`, hostinger temp domain removed —
      served on server directly, not via deploy). Verified: private origins
      rejected, tnsvt.com/www/localhost accepted.
- [x] **6.12 `/trading` decision**: `/trading` now 302 → `/journal/new`
      (record-only per recommended roadmap option; execution stays on
      tnsvt.com). `LegacyModuleController::trading()` returns RedirectResponse.

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
- [x] **Extract inline `<style>` blocks** from `shell.html.twig` — verified
      done 2026-08-19: no `<style>` blocks remain in any template (grep
      confirmed 0 matches); CSS lives in `assets/styles/shell.css`.
      `home.html.twig` extracted to `assets/styles/home.css` ✅ (Phase 5).
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

1. **Where does `/trading` go?** **RESOLVED 2026-08-19** — `/trading`
   redirects to `/journal/new` (302, register operation) per the recommended
   option "record-only, keep tnsvt.com for execution". Execution stays on
   tnsvt.com; execution platform not yet migrated to v2.
   (Deployed and verified 2026-08-19.)

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
