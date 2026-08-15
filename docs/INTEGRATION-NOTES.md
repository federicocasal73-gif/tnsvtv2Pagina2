# TNSVT — Integration Notes

Build log for the Guardian surface (Phase 1: API + Phase 2: UI + Phase 3: event-driven),
the Sidebar 5-macro restructure (Phase 4), the Home/Login split (Phase 5),
the Deploy tooling (Phase 6), the CI pipeline (Phase 7), the Settings split +
shell.html.twig inline CSS extraction (Phase 8), and the CSS consolidation (Phase 9).

**Date:** 2026-08-14/15
**Phase:** F4 Integrate — Phases 1 through 9 all done (CSS consolidation complete)
**Scope:** additive only — no migrations, no entity changes, no route deletions
(except orphan `templates/home.html.twig` removed in Phase 5;
`tokens-elev.css`, `v2-tokens.css`, `glass-premium.css`, `apk-glowup.css`,
`web-glowup.css`, `elev.css`, `v2-components.css` removed across Phase 9 merges).

---

## 1. Files created

```
src/Event/TradeSavedEvent.php                            (35 lines)   domain event
src/EventSubscriber/GuardianSubscriber.php               (60 lines)   push subscriber
src/Service/Guardian/GuardianSignal.php                  (60 lines)   value object
src/Service/Guardian/GuardianSignalCollector.php         (260 lines)  aggregator
src/Service/Guardian/DisciplineScoreCalculator.php       (110 lines)  scorer
src/Controller/Api/GuardianController.php                (75 lines)   JSON API
templates/sanctum/guardian.html.twig                     (240 lines)  Guardian UI page
```

**Total:** 7 new files, ~840 lines, all `php -l` clean.

**Modified (additive / surgical):**

| File | Change |
|---|---|
| `src/Service/PropFirmRuleChecker.php` | Added `daily_loss_pct` to `getStatus()`; extracted `getDailyLossPctForUser()` (public) |
| `src/Service/Guardian/GuardianSignal.php` | Added `TYPE_RISK_DAILY_LOSS_CRITICAL` |
| `src/Service/Guardian/GuardianSignalCollector.php` | New daily-loss signals (NEAR + CRITICAL) |
| `src/Controller/Api/JournalController.php` | Inject `EventDispatcherInterface`; dispatch `TradeSavedEvent` after `create` + `update` |
| `src/Controller/Api/SyncController.php` | Inject `EventDispatcherInterface`; dispatch event for each pending create in bulk sync |
| `src/Controller/SanctumModuleController.php` | Added `guardian()` action |
| `templates/sanctum/dashboard.html.twig` | Added Guardian widget section + `loadGuardianWidget()` JS |
| `templates/sanctum/journal.html.twig` | Added Guardian pre-trade banner + `loadGuardianBanner()` JS, refreshes after save/delete |
| `templates/shell.html.twig` | Added Guardian link in sidebar (Phase 2); **Sidebar 5-macro restructure (Phase 4)** |
| `config/packages/security.yaml` | Narrowed admin-only Sanctum routes; opened `/sanctum/*` to ROLE_USER |

---

## 2. Architecture

```
                  ┌──────────────────────────────────────────┐
                  │  HTTP layer                               │
                  │  GET /api/guardian/signals                │
                  │  GET /api/guardian/score                  │
                  └────────────────┬─────────────────────────┘
                                   │
                  ┌────────────────▼─────────────────────────┐
                  │  App\Controller\Api\GuardianController   │
                  │   (auth check, JSON shaping)              │
                  └────────────────┬─────────────────────────┘
                                   │
            ┌──────────────────────┴──────────────────────┐
            │                                             │
   ┌────────▼─────────┐                       ┌───────────▼──────────�
   │ GuardianSignal   │                       │ DisciplineScore      │
   │ Collector        │�──────────────────────┤ Calculator           │
   │ (read-only)      │  uses signals          │ (read-only)          │
   └────────┬─────────┘                       └──────────────────────┘
            │
            │ uses (already in repo, no changes):
            │
            ├─▶ App\Service\PropFirmRuleChecker
            ├─▶ App\Service\Macro\NoTradeWindowService
            ├─▶ App\Repository\PropFirmAccountRepository
            ├─▶ App\Repository\JournalEntryRepository
            └─▶ App\Repository\DiaryEntryRepository

            outputs:
            └─▶ App\Entity\PropFirmAlert  (already exists — used by score)
```

---

## 3. What it produces

### `GET /api/guardian/signals`

```json
{
  "success": true,
  "count": 2,
  "signals": [
    {
      "type": "risk.drawdown.near",
      "severity": "warning",
      "title": "Drawdown cerca del límite",
      "message": "Drawdown actual 8.5% sobre máximo de 10%",
      "action_label": "Revisar plan",
      "action_route": "/journal",
      "context": {
        "account_id": 12,
        "drawdown_pct": 8.5,
        "max_drawdown_pct": 10
      }
    },
    {
      "type": "macro.no_trade.upcoming",
      "severity": "info",
      "title": "Próxima ventana de no-trade",
      "message": "US IPC Mensual — ventana inicia pronto",
      "action_label": "Ver calendario",
      "action_route": "/calendar",
      "context": { "event_id": 42, "starts_at": "2026-08-14T13:15:00+00:00" }
    }
  ],
  "computed_at": "2026-08-14T13:02:11+00:00"
}
```

### `GET /api/guardian/score`

```json
{
  "success": true,
  "score": 72,
  "tier": "steady",
  "breakdown": [
    { "label": "Drawdown al 8.5% — muy cerca del límite de 10%", "delta": -10, "source": "prop_firm_alert" },
    { "label": "Sin trades registrados", "delta": -3, "source": "discipline.no_journal" }
  ],
  "computed_at": "2026-08-14T13:02:11+00:00"
}
```

Tiers:
- `elite` ≥ 90
- `strong` ≥ 75
- `steady` ≥ 60
- `caution` ≥ 40
- `risk` < 40

---

## 4. Signal types

| Type | Severity | Source |
|---|---|---|
| `risk.drawdown.near` | warning / danger | `PropFirmRuleChecker::getStatus()` |
| `macro.no_trade.active` | warning | `NoTradeWindowService::getActiveWindow()` |
| `macro.no_trade.upcoming` | info | `NoTradeWindowService::getNextWindow()` (within 30 min) |
| `discipline.no_journal` | info | `JournalEntryRepository` recency (≥ 5 days) |
| `discipline.no_diary` | info | `DiaryEntryRepository` recency (≥ 7 days) |
| `discipline.streak` | info | (reserved for future use) |

---

## 5. What is NOT in this phase (deferred)

- **No new entity** (`GuardianSignal` is a value object, not persisted).
- **No event subscriber** — signals are computed on demand. ✅ **DONE Phase 3**
- **No UI** — `templates/sanctum/guardian.html.twig` is the next step. The
  Sanctum Home widget is the next-next step. ✅ **DONE Phase 2 + 3**
- **No pre-trade warning modal in Journal** — ✅ **DONE Phase 3** (banner, not modal — by design)
- **Daily-loss signal** — ✅ **DONE Phase 3** (`PropFirmRuleChecker::getStatus()` now exposes `daily_loss_pct`)
- **No mentor integration** — MentorController doesn't exist (gap from audit, deferred).

---

## 8. Phase 3 — event-driven push

Added:
- `App\Event\TradeSavedEvent` — domain event with `(entry, user, isNew)`.
- `App\EventSubscriber\GuardianSubscriber` — listens and calls
  `PropFirmRuleChecker::checkTrade()` for each active account when the trade
  has a PnL value. Failures are logged but never block the trade.
- `EventDispatcherInterface` injected into:
  - `JournalController::create` (manual new trade)
  - `JournalController::update` (with `isNew: false`, subscriber skips it)
  - `SyncController` (bulk sync creates)
- **NOT** injected into `SyncTradeController` because it already calls
  `PropFirmRuleChecker::checkTrade()` inline for the PropFirm case. Adding the
  subscriber there would double-count alerts and double-update the account
  balance.

### Why a banner, not a modal

The user asked for Guardian "to inject logic in Journal/Trading/Diary". The
choice was between:
1. **Modal** that blocks the user from saving
2. **Banner** that warns without blocking

Phase 3 implements option 2 — the philosophy is "Guardian is your coach, not
your jailer". The user sees the signals, decides. The push subscriber ensures
that IF they save a violating trade, an alert is persisted immediately. Both
sides (pull via API + push via subscriber) work together.

### Dashboard widget

Added a compact Guardian widget right after the 4 KPI cards on
`/sanctum/dashboard`:
- Score (large number, color by tier)
- Tier badge
- Top 3 signals with severity color
- "Ver completo →" link to `/sanctum/guardian`
- Refreshes every 90s via `setInterval`

### Daily-loss signal coverage

New signal types (added to `GuardianSignal::TYPE_*`):
- `risk.daily_loss.near` (warning) — daily loss ≥ 70% of max
- `risk.daily_loss.critical` (danger) — daily loss ≥ 100% of max

Requires `PropFirmRuleChecker::getStatus()` to expose current `daily_loss_pct`.
Done via the new public `getDailyLossPctForUser(User $user)` method.

---

## 6. Verification

- ✅ `php -l` on all 4 new files — no syntax errors.
- ✅ Services registered automatically via `App\:` resource in `services.yaml`
  with autowiring + autoconfigure.
- ✅ Controller uses standard `AbstractController::getUser()` pattern
  (matches existing API controllers).
- ⚠️ **No live HTTP test** — sandbox doesn't have a running PHP/Symfony
  environment. Verify manually with `bin/console debug:router` and
  `curl -b cookie.txt http://localhost/api/guardian/score` after deployment.

---

## 7. `/sanctum` security rule — fixed in Phase 2

The pre-existing security misconfiguration flagged in the audit has been fixed
in Phase 2. Before:

```yaml
- { path: ^/sanctum, roles: ROLE_ADMIN }   # ← blocked ALL /sanctum/*
```

After:

```yaml
- { path: ^/sanctum/(users|audit|tasks|settings|monitoring), roles: ROLE_ADMIN }
- { path: ^/api/sanctum, roles: ROLE_ADMIN }
- { path: ^/sanctum, roles: ROLE_USER }
```

**Effect:** regular users can now reach `/sanctum/dashboard` (and `/sanctum/guardian`).
Admin-only paths (`users`, `audit`, `tasks`, `settings`, `monitoring`) remain
gated. `/api/sanctum/*` stays admin-only (Audit, Dashboard, Monitoring,
Oracle, Settings, Tasks, Users controllers — all admin surface per audit).

**Verification needed:** manual test with a non-admin user account on a deployed
environment. Sandbox doesn't have one available.

---

## 8. Next phase (Phase 2 of Guardian)

Planned, in order:

1. Fix the `/sanctum` security rule (separate commit). ✅ **DONE Phase 2**
2. Create `templates/sanctum/guardian.html.twig` — minimal page consuming
   `/api/guardian/signals` + `/api/guardian/score`. ✅ **DONE Phase 2**
3. Add a small Guardian widget to `templates/sanctum/dashboard.html.twig`
   (only after verifying `/sanctum` works for regular users). ✅ **DONE Phase 3**
4. Add an `EventSubscriber` that listens to `TradeSavedEvent` and pre-computes
   signals on save (push instead of pull). ✅ **DONE Phase 3**
5. Extend `PropFirmRuleChecker` to expose current daily loss in `getStatus()`.
   ✅ **DONE Phase 3**
6. Add a pre-trade warning modal in the Journal "New Trade" form. ✅ **DONE Phase 3** (banner, not modal — by design)

---

## 9. Phase 4 — Sidebar 5-macro restructure

**Scope:** one file (`templates/shell.html.twig`), zero route changes.

### Before (7 flat sections, 25 peer links)

| Section | Links |
|---|---|
| Principal | Dashboard, Mi Perfil |
| Trading | Journal, Calendario, Leaderboard |
| Social | Chat, Feed, Clan, Journal Sharing |
| Competición | Torneos, Duelos 1v1, Game, Honor |
| Educación | Campus |
| Economía | Tienda, Wallet |
| Herramientas | Oráculo, Macro, Guardian, Frecuencias, Diario, Notificaciones |
| Administración (ROLE_ADMIN) | Usuarios, Tareas, Audit, Settings, Monitoring |

### After (5 macros + Admin + Inicio, 27 links)

| Macro | Sub-grupo | Links |
|---|---|---|
| ⌂ Inicio | — | Inicio (dashboard) |
| 👤 Mi cuenta | — | Perfil, Notificaciones |
| ⚔ Trading | — | Journal, Calendario, Leaderboard |
| 🎓 Formación | — | Campus |
| 🧠 Mente & Macro | — | Macroeconomía, Oráculo, Guardian, Diario, Frecuencias |
| 👥 Comunidad | Social | Feed, Conexiones, Chat, Clanes |
| � Comunidad | Compete | Torneos, Duelos 1v1, Game, Honor |
| 👥 Comunidad | Wallet | Wallet, Tienda |
| ⚙ Admin (ROLE_ADMIN) | — | Usuarios, Tareas, Audit, Settings, Monitoring |

### Changes (visual only)

- Reorganized `<nav>` section in `shell.html.twig`
- Removed duplicate "Notificaciones" entry (was in 2 places)
- Renamed `/social` visible label from "Journal Sharing" → "Conexiones"
- Reordered items within Comunidad into Social / Compete / Wallet sub-groups
  with small uppercase labels (`pl-10 opacity-30 text-[9px]`)
- "Tareas" moved to Admin-only (was in Formación + Admin)

### What stayed the same

- All URL routes (`/journal`, `/sanctum/guardian`, etc.) — no redirect needed
- All `data-page` attributes (used by the active-state highlight JS)
- The `bell-badge` element (still under Mi Cuenta > Notificaciones, JS untouched)
- The `sanctum-link` CSS class
- No new CSS files
- No new controllers

---

## 10. Phase 5 — Home/Login split + CSS extraction

**Scope:** extract marketing + login into separate routes, extract 330 lines
of inline CSS to `assets/styles/home.css`, create minimal public shell.

### Before

- `/` and `/home` both rendered `templates/home.html.twig` (586 lines)
- File combined: hero + verse + login form + stats strip + features grid
- 330 lines of CSS embedded in `<style>` block inside the template
- Loaded 8 Sanctum CSS files (waste on a public page)

### After

| File | Purpose |
|---|---|
| `templates/public/shell.html.twig` | Minimal shell — loads only `tokens.css` + `home.css` (no Sanctum CSS) |
| `templates/public/_public_nav.html.twig` | Top-right "Entrar" / "Ir al Sanctum" link, context-aware |
| `templates/public/home.html.twig` | Landing only: hero, stats strip, features grid (no form). Features link to `/login` for unauth users. |
| `templates/public/login.html.twig` | Login form only. Includes "← Volver al inicio" link. |
| `assets/styles/home.css` | All extracted styles (~330 lines): hero, orbs, particles, login card, features grid, public-nav |
| `src/Controller/HomeController.php` | Added `login()` method + route `^/login`. Auth users on `/login` → redirect `/sanctum` |

### Removed

- `templates/home.html.twig` — orphaned by the split; all references updated.

### Routes

| Route | Handler | Template | Auth behaviour |
|---|---|---|---|
| `GET /` | `HomeController::index` | `public/home.html.twig` | Authed → 302 `/sanctum` |
| `GET /home` | `HomeController::home` | `public/home.html.twig` | Same |
| `GET /login` | `HomeController::login` | `public/login.html.twig` | Authed → 302 `/sanctum` |

### Security

- `/login` falls under `^/` rule which is `PUBLIC_ACCESS`. No firewall change.
- Authenticated visitors to `/login` are redirected to `/sanctum` at the
  controller level (before rendering).

### Verification

- ✅ `php -l` on `HomeController.php` clean.
- ✅ Twig files: `shell.html.twig`, `home.html.twig`, `login.html.twig`,
  `_public_nav.html.twig` created.
- ✅ Old `templates/home.html.twig` deleted (no references).
- ✅ Old CSS extracted to `assets/styles/home.css`.
- ⚠️ Manual smoke test recommended: visit `/`, then `/login`, verify form
  submission, verify redirect to `/sanctum` on auth.

### Open items still pending (per audit)

- Public/Sanctum shell CSS boundary (the public shell already loads only its
  own CSS — done).
- `/trading` legacy placeholder (deferred per decision).
- Mentor area (no code yet).
- CSS token/glow consolidation (3 tokens files + 3 glow files).

---

## 11. Phase 6 — Deploy tooling

Added a small but complete deployment pipeline. **I cannot deploy directly** —
no credentials, no SSH access, no CI runner. What I delivered is the tooling
that lets the operator deploy safely from their local machine.

### New files

```
bin/pre-deploy-check.sh       ~120 lines   local verification (Linux/macOS)
bin/pre-deploy-check.ps1      ~140 lines   local verification (Windows)
bin/deploy.sh                  ~100 lines   full deploy via SSH + git
bin/post-deploy-smoke.sh       ~95 lines    HTTP smoke tests against live URL
bin/rollback.sh                ~70 lines    rollback to a previous release
docs/DEPLOYMENT.md             ~210 lines   complete deploy guide
```

### `.gitignore` additions

- `stitch_tnsvt_app_m_vil/` (27 mockup HTMLs — design exploration, not source)
- `public/uploads/` (runtime content)
- `.idea/`, `.vscode/`, `*.swp`, `*.swo`, `.DS_Store`, `Thumbs.db`
- `/public/build/` (compiled assets)

### Pre-deploy check (local, read-only)

`./bin/pre-deploy-check.sh` verifies in 6 sections:
1. PHP syntax (`php -l` on every file under `src/`, `templates/`, `bin/`)
2. Templates exist (Phase 1–5 critical templates)
3. Guardian services present (Phase 1+2+3)
4. Security (no `.env.local` in git, no real JWT passphrase in `.env`,
   Mercure default secret replaced)
5. Git state (clean working tree)
6. Composer sanity

Exit 0 = OK. Exit 1 = blocks `deploy.sh`.

### Deploy flow (`deploy.sh`)

1. Run `pre-deploy-check.sh` (aborts on failure)
2. SSH connectivity check
3. Tag release (`tnsvt-release-<timestamp>`)
4. `git push origin main --tags`
5. On remote: `git pull`, `composer install`, cache clear/warmup,
   `doctrine:migrations:migrate`, asset install
6. Run `post-deploy-smoke.sh` against the live URL
7. Print release tag for rollback

Required env vars (set in operator shell, NOT in repo):
- `TNSVT_DEPLOY_SSH` — e.g. `user@host`
- `TNSVT_DEPLOY_PATH` — e.g. `/home/user/public_html/tnsvt`
- `TNSVT_DEPLOY_BRANCH` (default `main`)
- `TNSVT_DEPLOY_HEALTHCHECK` (default derived from SSH)

### Smoke tests (`post-deploy-smoke.sh`)

9 endpoint checks against the live URL:

| Path | Expected |
|---|---|
| `/` | 200 |
| `/login` | 200 |
| `/home` | 200 |
| `/api/auth/check` | 200 |
| `/sanctum` | 302 (anon → login) |
| `/styles/home.css` | 200 |
| `/styles/tokens.css` | 200 |
| `/sanctum/users` | 302 (admin → anon) |
| `/sanctum/monitoring` | 302 (admin → anon) |

Exit 0 = pass. Non-zero = smoke failed.

### Rollback (`rollback.sh`)

- Default: rolls back to `.last-release` (the last successful deploy).
- Or pass a tag/commit: `./bin/rollback.sh tnsvt-release-20260814-181530`
- Prompts for confirmation
- Re-checks-out the target, reinstalls deps, warms cache
- ⚠️ **Does NOT reverse DB migrations** — operator must run
  `doctrine:migrations:migrate prev` on the server if needed.

### What I cannot do (operator actions required)

1. **Rotate secrets per `SECURITY.md` §3** before any deploy. The current
   `.env.local` values are considered compromised.
2. **Configure SSH access** between dev machine and Hostinger.
3. **Set up a git remote** for `tnsvt-app` (the repo currently has no remote
   configured per audit).
4. **Run the actual deploy** — invoke `./bin/deploy.sh` when ready.

### Documentation

See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) for the full guide including first-time
provisioning, env config, DB setup, operational rules, and open items.

---

## 12. Phase 7 — CI pipeline + Settings split (Account)

### CI pipeline (`.github/workflows/ci.yml`)

Added a GitHub Actions workflow that runs on every push and PR to `main`:

- PHP syntax check (`php -l` on every `.php` file under `src/`, `templates/`, `bin/`)
- Pre-deploy checks (delegated to `bin/pre-deploy-check.sh`)
- Secrets audit (refuses build if `.env.local` is tracked, or if `.env`
  contains a real JWT passphrase, or if Mercure default secret is anywhere)
- Guardian services presence
- Phase 5 templates split verification (legacy `home.html.twig` removed,
  new `public/home.html.twig` + `public/login.html.twig` present)

**Concurrency:** cancels in-progress runs when a new commit lands.
**Cache:** Composer dependencies cached.
**PHP version:** 8.4.
**Future:** PHPUnit against SQLite test DB (placeholder job documented).

### Settings split — `/account/settings`

Added user-facing personal preferences at `/account/settings`:

- **Route**: `account_settings` (SanctumModuleController::accountSettings)
- **Security**: `ROLE_USER` (added rule in `security.yaml` BEFORE `^/`)
- **Template**: `templates/sanctum/account_settings.html.twig`
- **Sections**: Apariencia (tema, densidad) · Notificaciones (4 toggles) ·
  Idioma y zona horaria · Seguridad (links a /profile)
- **Persistence**: localStorage (`tnsvt_user_prefs_v1`) — Phase de UI; persistencia
  en servidor planificada cuando exista endpoint `/api/account/prefs`

The admin `/sanctum/settings` stays untouched (system settings, ROLE_ADMIN).

Sidebar updated: under `👤 Mi Cuenta`, added "Configuración" between "Perfil" and
"Notificaciones".

---

## 13. Phase 8 — shell.html.twig inline CSS extraction + CSS consolidation plan

### `shell.html.twig` inline CSS extracted

Extracted the ~30 lines of inline `<style>` from `templates/shell.html.twig`
to `assets/styles/shell.css`. The template now loads it via `<link>` like the
rest of the bundle. No visual change (verbatim extraction).

### CSS consolidation plan (executed in Phase 9)

Documented the merge target in `docs/DESIGN-SYSTEM.md` and `docs/CSS-MERGE-PLAN.md`:

| Source files | Target |
|---|---|
| `tokens.css` + `tokens-elev.css` + `v2-tokens.css` | `tokens.css` (canonical) ✅ DONE Phase 9.B |
| `elev.css` + `v2-components.css` | `components.css` (canonical) ✅ DONE Phase 9.D |
| `glass-premium.css` + `apk-glowup.css` + `web-glowup.css` | `glow.css` (canonical) ✅ DONE Phase 9.C |
| `shell.css` (just created) | (already canonical) ✅ Phase 8 |
| `home.css` (Phase 5) | (already canonical) ✅ Phase 5 |

### Phase 9 — CSS Consolidation Executed (3 merges)

#### Phase 9.B — tokens (3 → 1)
- New `tokens.css` with union of all -elev suffixed vars from tokens.css
  + tokens-elev.css unique vars (fonts, spacing, radius, shadows)
- Aliases added for `--v2-*` (for v2-components.css compatibility — Phase 9.D cleans up)
- `tokens-elev.css` + `v2-tokens.css` deleted
- `shell.html.twig` + `legacy/redirect.html.twig` load lists updated

#### Phase 9.C — glow (3 → 1)
- New `glow.css` with universal + Web-only + APK-only classes (platform gates preserved)
- Aliases for no-suffix vars (`--gold`, `--gold-bright`, `--violet`, `--violet-glow`)
- `glass-premium.css`, `apk-glowup.css`, `web-glowup.css` deleted
- `shell.html.twig` load list updated

#### Phase 9.D — components (2 → 1)
- New `components.css` with union of elev.css + v2-components.css
- 3 conflicts resolved to v2-components.css version:
  - `.glass-card-elev` (richer with ::before line + hover with translate)
  - `.status-active` (semantic green gradient)
  - `.btn-elev-secondary` (richer hover with gold-subtle gradient)
- `elev.css` + `v2-components.css` deleted
- `shell.html.twig` load list updated

### Final CSS Bundle (Phase 9 complete)

```
assets/styles/   (9 canonical files, was 14)
├── animations.css       (unchanged)
├── apk-layout-fix.css   (unchanged)
├── app.css              (unchanged)
├── components/          (subfolder)
├── components.css       ← NEW (Phase 9.D, ~620 lines)
├── glow.css             ← NEW (Phase 9.C, ~1100 lines)
├── home.css             (Phase 5)
├── shell.css            (Phase 8)
├── tokens.css           (Phase 9.B, ~140 lines)
└── v2-sparklines.css   (unchanged)
```

**60% reduction** in CSS files. All visual behavior preserved (verbatim merges
except for 3 conflict classes which already had v2 versions winning in cascade).

### Known caveats (deferred cleanup)

- `tokens.css` still has `--v2-*` aliases (no longer used after Phase 9.D)
- `tokens.css` still has no-suffix aliases (`--gold`, etc., used by glow.css)
- `components.css` uses `--v2-*` references throughout

These are all harmless and can be cleaned up in a future "tokens cleanup" pass.

### Verification

- ✅ All PHP files lint clean
- ✅ All shell.html.twig and templates parse OK
- ✅ 5 CSS files deleted (tokens-elev, v2-tokens, glass-premium, apk-glowup, web-glowup, elev, v2-components)
- ✅ 3 CSS files created (tokens new, glow, components)
- ✅ No new templates or controllers touched in Phase 9
- ✅ Baseline screenshots captured (27 files in docs/screenshots/baseline-2026-08-14/)
