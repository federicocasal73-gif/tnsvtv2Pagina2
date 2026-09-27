# Risk Mitigation Plan — TNSVT V2 production fixes
_September 2026_

This document enumerates the residual risks after the in-session
remediation of the 5 reported production errors + chat presence bug
+ 8 journal account-switching bugs + add Playwright regression suite,
and pairs each risk with a concrete mitigation (owner, deadline,
rollback plan).

The goals are:
1. **Nothing we just shipped silently regresses** within 30 days.
2. **Mercure / SSE assumed working but actually broken** does not keep
   degrading the UX without us noticing.
3. **No class of "missing migration in prod" ever silently 500s again.**
4. **Audit & telemetry gaps** are captured in TODOs so they don't get
   lost.

---

## Risk #1 — `account_id` filtering regression on /api/journal*

| | |
|---|---|
| **Severity if it breaks** | 🔴 High (every journal widget shows wrong totals, breaks the user's trust in stats) |
| **Likelihood** | Medium — backend `JournalController::loadEntriesForOwner()` is the single point of truth; touches many paths (log, stats, calendar-monthly, equity-curve, drawdown). Easy to accidentally skip the param in a future endpoint. |
| **Mitigation** | • Bloque F (P0) ships `accountQueryParam()` helper centralised in `templates/sanctum/journal.html.twig`. Any future widget MUST use it.<br>• Bloque K3 (e2e) tests assert every journal URL contains `account_id=` after a non-default chip click.<br>• ✅ PHPUnit regression test added (commit `9afb5c1`) — `tests/Functional/JournalAccountFilterTest.php` with 6 tests / 37 assertions covering all 4 endpoints + grace-fallback + cross-user anti-leak. |
| **Owner** | Backend team |
| **Deadline** | ✅ DONE on 2026-09-25 (commit `9afb5c1`). |
| **Rollback plan** | `git revert c41d9d3` (commit of Fix #F+G). Verifies widgets revert to 'all accounts'. Then investigate. |

## Risk #2 — Mercure hub will keep failing silently on Hostinger

| | |
|---|---|
| **Severity** | 🟠 Medium — chat realtime (typing indicators, message arrival without reload) is permanently degraded. Users learn to live with it. The failure is silent: no log, no metric. |
| **Likelihood** | Certain (already failing) |
| **Mitigation (tier 1)** | Add a `MercureHealthCheck` listener: every 5 min, try `cache_pool->getItem('mercure_alive')->isHit()`. If the publisher fails ≥ 3 times, log a single `CRITICAL` line with `<env=production>` tag so `monolog` alerts catch it. Minimal overhead.<br>**Status: ✅ DONE on 2026-09-27 (commit `c6c5151`).** `MercureHealthService` probes the hub on demand, caches 30s, debounces critical logs to one per failure streak, exposes `GET /api/health/mercure` (public, for UptimeRobot) + `POST /api/admin/mercure/check` (admin). 13 PHPUnit tests / 28 assertions. |
| **Mitigation (tier 2)** | Document a runbook in `AGENTS.md § Mercure / SSE` explaining how to host Mercure externally (Fly.io free tier, Render, or a $5/mo VPS). Hostinger shared has `proc_open` disabled — same constraint that prevents `composer install` — so the hub MUST be elsewhere. |
| **Owner** | Platform team |
| **Deadline** | Tier 1: ✅ DONE. Tier 2: when chat realtime becomes a customer-requested feature. |
| **Rollback plan** | Tier 1 is read-only — no rollback needed. Tier 2 is a deployment; old domain stays live until new hub smoke-tested. |

## Risk #3 — Future `campus_*`-style entities never get a migration

| | |
|---|---|
| **Severity if it breaks** | 🔴 High (entire feature invisible in prod, works in tests because `doctrine:schema:create` mirrors all entities). Lessons learned from the 12-table bug we just fixed. |
| **Likelihood** | Medium — easy to ship a new entity without a migration; CI never catches it (sqlite via `schema:create`). |
| **Mitigation (automated)** | Custom PHPStan rule (config in `phpstan-baseline.neon`) that detects a new `#[ORM\Entity]` without a corresponding `Version*.php` migration in the same PR. Reject the PR via the linter step.<br>**Status: NOT shipped (would take ~2-3h to write + test a custom rule).** |
| **Mitigation (process)** | `AGENTS.md § Deploy to Hostinger` now includes `php bin/console doctrine:migrations:migrate --env=prod --no-interaction` as part of the deploy command. From this commit onward, every push applies pending migrations on prod. |
| **Mitigation (observability)** | Add a `/api/health/migrations` endpoint that returns the highest-applied migration version vs the highest-defined. If out of sync, returns 500 + logs `critical`. Expose in uptime monitoring (UptimeRobot / Betterstack).<br>**Status: ✅ DONE on 2026-09-27 (commit `954e6de` + hotfix `068350c` + data-alignment `f95b294`).** `MigrationHealthService` reads from the Doctrine DependencyFactory, caches 10s, returns `{in_sync, available, executed, pending, latest_available, latest_executed, pending_versions, unavailable_versions, checked_at, error}`. `GET /api/health/migrations` returns 200/500; `POST /api/admin/migrations/check` forces a fresh check (admin). **MySQL quirk fix (`068350c`)**: prod MySQL returns tiny `platformOptions: {charset:null, collation:null}` metadata that Doctrine's comparator flags as "metadata storage is not up to date" — even though the table IS fine. The service now catches `MetadataStorageError::notUpToDate()` and falls back to a raw SQL query against `doctrine_migration_versions`. **Data-alignment (`f95b294`)**: 2 outlier migrations (`Version20260806000000`, `Version20260807000001`) used a non-standard sub-namespace + class names; renamed to `VersionXXXX` to match the others. One-time `UPDATE` on prod's `doctrine_migration_versions` table aligned the stored versions. 14 PHPUnit tests / 42 assertions + `StubDependencyFactory` test helper. |
| **Owner** | Backend team |
| **Deadline** | Process (✅ shipped). Automation: ✅ shipped (commit `4dbcf5a`: `app:lint:entity-migrations` Symfony command + new CI job `lint-entity-migrations`). |
| **Rollback plan** | If a migration breaks prod, SSH `doctrine:migrations:migrate prev` to roll back one version. The m:ss option lets us reach a specific version non-sequentially. |

## Risk #4 — Account-switcher chip strip flicker on cold cache

| | |
|---|---|
| **Severity** | 🟡 Low (cosmetic) |
| **Likelihood** | Medium — `renderChips()` runs twice on initial load (once before `setActiveAccount` resolves the persisted/orphan id, once after). Bloque I partially fixed by reordering, but the flicker window is ~10–50ms on slow networks. |
| **Mitigation** | • Bloque I reorders `loadAccounts()` to resolve active before rendering.<br>• ✅ DONE on 2026-09-27 (commit `607fe38`): extracted `resolveActiveAccountId(persistedId)` (pure, no side effects) and `renderFor(activeId)` (single-pass, no read of localStorage / window globals). The `loadAccounts()` flow now: resolve → setActiveAccount → renderFor — exactly one render with the correct id, no flicker window. `renderChips()` is kept as a thin wrapper for backward compat. |
| **Owner** | Frontend team |
| **Deadline** | Refactor: 2026-Q4 |
| **Rollback plan** | If flicker becomes user-visible: temporarily inline the persisted id into the Twig template with `data-active-account-id="{{ app.user.activeAccountId }}"` so the first paint is correct server-side. Removes the need for the JS resolution. |

## Risk #5 — Service Worker `cache_version` stale after asset changes

| | |
|---|---|
| **Severity** | 🟠 Medium (silently keeps clients pinned to old bundles; the "Failed to fetch" at sw.js:158 returns; users see broken UI until manual SW unregister) |
| **Likelihood** | Low now (bug fix in `ServiceWorkerController` reads `$_ENV` correctly). Recurs only if a future regression breaks the env var reading. |
| **Mitigation** | • Bloque "fix(sw): read APP_VERSION from \$_ENV..." shipped (`eec49b5`).<br>• ✅ AGENTS.md documents the convention + mandatory PR check (commit `5204849`): bump APP_VERSION in BOTH `.env` AND `.env.local` on every asset change, mention in PR description. Common pitfalls table lists the symptom (`sw.js:158 Failed to fetch`). |
| **Owner** | Platform team |
| **Deadline** | Doc update: this PR (combine with Bloque E). |
| **Rollback plan** | If SW is broken on prod: SSH in and manually edit `var/cache/prod/App_KernelProdContainer.xml` to set the `cache_version` to the next incremented value. Or have users hit DevTools → Application → Service Workers → Unregister. |

## Risk #6 — `assets/api-helper.js` orphan import can re-occur

| | |
|---|---|
| **Severity** | 🟡 Low (asset-map:compile fails locally; deploy script `set -e` halts) |
| **Likelihood** | Medium — a future agent (or dev) might re-introduce the ES module pattern that Bloque A (and its revert) shows is broken for this codebase. |
| **Mitigation** | • ✅ AGENTS.md documents the inline-script constraint (commit `5204849`): full section "Inline `<script>` vs ES modules — DO NOT convert `api_helper.html.twig`" with rule of thumb, history (failed df090e6, revert a010e62), and remediation patterns. |
| **Owner** | Tech writer / docs |
| **Deadline** | Doc update: this PR (combine with Bloque E) |
| **Rollback plan** | `git checkout HEAD -- templates/_partials/api_helper.html.twig src/assets/app.js` |

## Risk #7 — Production database backup integrity

| | |
|---|---|
| **Severity** | 🔴 Critical if needed (no backup = permanent data loss on migration failure) |
| **Likelihood** | Real — every `doctrine:migrations:migrate` on prod runs without an automatic snapshot. |
| **Mitigation** | • Deploy script should call `mysqldump` BEFORE the migration in the same SSH session.<br>• ✅ Daily backup script shipped (commit `14ae10a`): `scripts/db-backup.sh` parses DATABASE_URL, dumps via Unix socket, gzips, verifies, rotates files > 30 days. Tested on prod: 110 KB gzip verified.<br>• ⚠️ Cron schedule: Hostinger shared has no `crontab`/`at`/`systemd`. Schedule must be installed via **Hostinger hPanel → Advanced → Cron Jobs** (web UI). Documented in AGENTS.md § Database backups. Off-server upload (S3/Backblaze/NAS) is a 1-line change when configured. |
| **Owner** | Platform team |
| **Deadline** | Daily cron: 2026-10-15 |
| **Rollback plan** | `gunzip < ~/backups/db_*.sql.gz | mysql -u $DB_USER -p $DB_PASS $DB_NAME`. Tested with `BACKUP_PATH` from the Bloque D script. |

## Risk #8 — Secrets in chat / logs leak via API responses

| | |
|---|---|
| **Severity** | 🟠 Medium (PII exposure — emails, X-Game-Code reuse risks) |
| **Likelihood** | Medium — `/api/chat/users` currently returns the full user list. **TODO confirm shape.** Same for `/api/auth/check`. |
| **Mitigation** | Audit every API response that touches `/api/chat/` for leaking `email`, `lastLogin`, `password_hash`, or any field not strictly needed by the client.<br>Add a Symfony `kernel.response` listener that strips `password`/`apiKey`/`lastLoginIp` on the way out.<br>**Status: ✅ DONE on 2026-09-25 (commit `88bf052`).** Audit found `/api/chat/users` and `/api/auth/check` were already safe (explicit array maps, no User serialisation). The listener `src/EventListener/SensitiveFieldStripListener.php` is now installed as defence-in-depth, with 7 PHPUnit tests covering top-level, nested, case-insensitive, non-JSON, and end-to-end behaviour. |
| **Owner** | Backend team |
| **Deadline** | 2026-Q4 (next security sprint) |
| **Rollback plan** | If over-aggressive stripping breaks a legitimate use case: refine the per-endpoint rule via a `#[StripFields('password')]` attribute + targeted listener. |

## Risk #9 — E2E tests against prod mutate real data

| | |
|---|---|
| **Severity** | 🔴 High (a runaway test could delete a user's trade or accounts) |
| **Likelihood** | Low now (all current tests are read-only). Future contributors might add a write test. |
| **Mitigation** | • `tests/e2e/README.md` mandates "Don't create or delete state — tests must be idempotent".<br>• Playwright config `fullyParallel: false, workers: 1` so even a malicious spec can't fan out.<br>• ✅ DONE on 2026-09-27 (commit `f59f40b`): `tests/e2e/lint-no-mutations.mjs` + `npm run lint:e2e` rejects specs containing `request.post|put|delete|patch`, `.post|.put|.delete|.patch(` on fetch wrappers, mutating endpoint paths under `/api/accounts|journal|trades|admin`, `page.click([data-delete])`, or direct calls to `saveAccount|confirmDelete|deleteTrade|createTrade|userPurge`. Disable directives: `/* lint-e2e-disable:<rule> */` (single line) or `/* lint-e2e-disable-file */` (whole file). New CI step 'Lint e2e specs' in `.github/workflows/e2e.yml` runs before the Playwright suite. |
| **Owner** | Frontend team |
| **Deadline** | Lint policy: 2026-Q4 |
| **Rollback plan** | Manual restore from `Risk #7` backup. |

## Risk #10 — Time-based JS bugs (`setInterval`, `setTimeout`)

| | |
|---|---|
| **Severity** | 🟠 Medium — chat presence ping runs every 60s. If the page is open for 8h, no leak. But if the controller is re-mounted (Turbo navigation), the timer might double-fire (cleanup may not fire if `disconnect()` isn't called reliably by Stimulus). |
| **Likelihood** | Low (Stimulus lifecycle is well-tested). |
| **Mitigation** | • `disconnect()` clears `presenceTimer` and removes the `visibilitychange` listener.<br>• ✅ Defensive guard added (commit `8bcbae4`): both chat controllers now anchor the interval + listener to namespaced globals (`window.__tnsvtPresenceTimer`, `window.__tnsvtPresenceListener` for the widget; `window.__tnsvtChatPagePresenceTimer`, `window.__tnsvtChatPagePresenceListener` for the page). `startPresencePing()` clears any previous timer before installing a new one, catching edge cases where Stimulus `disconnect()` doesn't fire (hot-reload, Turbo race, etc.). |
| **Owner** | Frontend team |
| **Deadline** | 2026-10-01 |
| **Rollback plan** | `git revert` the chat controller changes; presence stops updating within 2 min of last ping (matches `isOnline()` window). |

---

## Mitigation action plan — next 30 / 60 / 90 days

### 30 days (2026-10-25)
- [x] Risk #1: Add PHPUnit test for `accountQueryParam()` / `loadEntriesForOwner()`. ✅ DONE (commit `9afb5c1`)
- [x] Risk #8: Audit `/api/chat/users` and `/api/auth/check` responses, strip sensitive fields. ✅ DONE (commit `88bf052`)
- [x] Risk #10: Add defensive orphan-clear in chat controllers. ✅ DONE (commit `8bcbae4`)
- [x] AGENTS.md: extend doc for Risks #5, #6. ✅ DONE (commit `5204849`)
- [x] Risk #7: Daily `mysqldump` cron + S3 offload. ✅ PARTIAL (commit `14ae10a`): script + AGENTS.md doc. Cron itself needs hPanel setup by user.

### 60 days (2026-11-25)
- [ ] Risk #1: full PHPUnit coverage for journal endpoints.
- [x] Risk #2 tier 1: `MercureHealthCheck` listener. ✅ DONE (commit `c6c5151`)
- [x] Risk #9: lint policy blocking mutation in E2E specs. ✅ DONE (commits `f59f40b`) — `tests/e2e/lint-no-mutations.mjs` + npm `lint:e2e` + CI step.

### 90 days (2026-12-25)
- [ ] Risk #2 tier 2: deploy Mercure externally OR switch to PHP-native SSE.
- [x] Risk #3: PHPStan rule that requires a migration per new entity. ✅ DONE (commit `4dbcf5a`) — `app:lint:entity-migrations` Symfony command + new CI job `lint-entity-migrations`.
- [x] Risk #3: `/api/health/migrations` endpoint + UptimeRobot monitor. ✅ DONE (commit `954e6de`) — `MigrationHealthService` + `MigrationHealthController` (GET public, POST admin), 13 PHPUnit tests.
- [x] Risk #4: Refactor chip strip into `renderFor(active)` single-pass. ✅ DONE (commit `607fe38`) — `account_switcher_controller.js`: extracted `resolveActiveAccountId()` (pure) + `renderFor(activeId)` (single-pass, no flicker).

---

## Roles

- **Backend team** owns Risks #1, #3, #8.
- **Frontend team** owns Risks #4, #9, #10.
- **Platform team** owns Risks #2, #5, #7.
- **Tech writer / docs** owns AGENTS.md updates (Risks #5, #6).

---

## Acceptance — when is this plan "done"?

A quarterly review (next: **2026-12-25**) when:
1. All "30 day" items are checked off (or explicitly deferred with reason).
2. PHPUnit passes with ≥ 1 backend test per Risky API endpoint.
3. E2E suite runs green in CI on every push.
4. `/api/health/migrations` returns 200 in uptime monitoring.
5. `MercureHealthCheck` either logs healthy or has Mercure externally hosted.

Sign off: tech lead + 1 reviewer per PR touching these areas.

---

## 📌 Session Recap — 2026-09-25 → 2026-09-27

> Documento para retomar el trabajo en una nueva sesión/ventana sin perder contexto.

### Commits shipped (todos ya pusheados a `main` y desplegados a `https://www.tnsvt.com`)

| Commit | Bloque | Qué hace |
|---|---|---|
| `96001dd` | E | AGENTS.md: deploy command con `migrations:migrate`, sección SW cache versioning, sección Mercure |
| `68c83d9` | L | Chat presence ping cada 60s (BLOQUE L — fix de "usuarios aparecen off") |
| `c41d9d3` | F + G | Journal: 4 fetches con `account_id` + eliminó fetches duplicados |
| `5c34aca` | H + I + J | Journal: sequence counter (race conditions) + orphan-account + baseline "Todas" = suma |
| `4e894b4` | K3 | Playwright E2E infra + 5 specs + CI workflow + README |
| `0c45395` | Plan | RISK_MITIGATION.md (este archivo) |
| `9afb5c1` | Risk #1 | `JournalAccountFilterTest.php` — 6 PHPUnit tests, 37 asserts |
| `88bf052` | Risk #8 | `SensitiveFieldStripListener` — 7 PHPUnit tests, 51 asserts |
| `8bcbae4` | Risk #10 | Orphan-clear en chat controllers (anchored a globals namespaced) |
| `5204849` | Risks #5+#6 | AGENTS.md: SW bump policy + sección inline-script |
| `14ae10a` | Risk #7 | `scripts/db-backup.sh` + AGENTS.md (cron via hPanel pendiente) |
| `b2ae88d` | docs | RISK_MITIGATION.md: Risk #10 marcado |
| `9a9025f` | docs | RISK_MITIGATION.md: Risks #5+#6 marcados |
| `acee060` | docs | RISK_MITIGATION.md: Risk #7 marcado (PARCIAL) |
| `c6c5151` | Risk #2 | `MercureHealthService` + `MercureHealthController` (GET `/api/health/mercure`, POST admin) + 13 tests |
| `7b90497` | docs | RISK_MITIGATION.md: Risk #2 marcado |
| `e1e75d5` | bug | `mercure.yaml`: `algorithm: HS256` → `hmac.sha256` (descubierto por el health check) |
| `954e6de` | Risk #3 | `MigrationHealthService` + `MigrationHealthController` (GET `/api/health/migrations`, POST admin) + 13 PHPUnit tests + `StubDependencyFactory` |
| `4dbcf5a` | Risk #3 | `app:lint:entity-migrations` Symfony command + nuevo CI job `lint-entity-migrations` + 9 PHPUnit tests |
| `607fe38` | Risk #4 | `account_switcher_controller.js`: `resolveActiveAccountId()` + `renderFor(activeId)` (single-pass, no flicker) |
| `f59f40b` | Risk #9 | `tests/e2e/lint-no-mutations.mjs` + `npm run lint:e2e` + CI step |
| `068350c` | Risk #3 hotfix | MigrationHealthService: fallback a raw SQL cuando MySQL quirks trip Doctrine's metadata-storage check |
| `f95b294` | Risk #3 data-fix | Renombrar 2 outlier migrations a namespace estándar + `UPDATE` en `doctrine_migration_versions` para alinear |

### Estado del repo al cierre de la sesión

- **HEAD en `main`:** `f95b294`
- **Tests pasando:** `230 / 676 asserts / 2 skipped` (PHPUnit). JS syntax checks verdes. PHPStan level 5: 0 errors sobre 277 archivos. Twig lint: 76 files OK. e2e mutation lint: 0 violations. Entity-migrations lint: 61 OK / 0 missing.
- **Risk #2 tier 1 ✅ funcionando en prod:** `GET https://www.tnsvt.com/api/health/mercure` devuelve:
  - `503 degraded` mientras el hub Mercure no exista (Hostinger no lo hostea)
  - Cambiará automáticamente a `200 ok` cuando Mercure esté hosteado externamente (Fly.io, Render, VPS)
- **Risk #3 ✅ blindado en prod:** `GET https://www.tnsvt.com/api/health/migrations` devuelve:
  - `200 ok` con `in_sync: true, available: 12, executed: 12` ✅
  - `500 drift` si se publica una migration sin aplicar (lo agarra UptimeRobot en <60s)
  - Blindaje doble: pre-deploy (`app:lint:entity-migrations`) + post-deploy (endpoint)
- **Cron de backup:** script instalado en `~/bin/db-backup.sh` y testeado (110 KB gzip verificado). Falta que el usuario lo programe via hPanel → Advanced → Cron Jobs

### Pendiente para la próxima sesión

#### 🟢 Bloque 1: Configurar cron via hPanel (lo único que el usuario tiene que hacer)

1. Login en https://hpanel.hostinger.com
2. Hosting → tu dominio → **Advanced → Cron Jobs**
3. Crear Cron Job con:
   - **Tipo:** Personalizado (NO PHP Personalizado)
   - **Command:** `/home/u310596868/bin/db-backup.sh >> /home/u310596868/backups/db-backup.log 2>&1`
   - **Schedule:** `0 3 * * *` (todos los días 3:00 AM server time)
4. Save

#### 🔴 Bloque 2: Errores originales reportados por el usuario (5 bugs)

- ✅ Bloque B (commit `3a5fad8`): `GW_TIER_COLOR` redeclaration — FIXED
- ✅ Bloque L (commit `68c83d9`): chat presence — FIXED
- ✅ Bloque F+G (commit `c41d9d3`): journal account_id — FIXED
- ✅ `/api/me/streak` y `/api/me/heatmap` 500 (commit `ad274d0`): tablas ya existían — fue no-op
- ✅ `DELETE /api/diary/setup` 404 (verificado por curl): la ruta existe y devuelve 401 sin auth — fue artefacto de cache

#### 🟠 Bloque 3: Items 60/90 días restantes (ordenados por prioridad)

| # | Item | Commit previo | Esfuerzo |
|---|---|---|---|
| 1 | ~~**Risk #3 — `/api/health/migrations`** + UptimeRobot~~ | ✅ DONE (`954e6de`) | 1 h |
| 2 | ~~**Risk #3 — PHPStan rule** que requiere migration por entity nueva~~ | ✅ DONE (`4dbcf5a`) | 2-3 h |
| 3 | ~~**Risk #4 — Refactor** chip strip en `renderFor(active)` single-pass~~ | ✅ DONE (`607fe38`) | 1 h |
| 4 | ~~**Risk #9 — Lint policy** que rechaza mutaciones en e2e specs~~ | ✅ DONE (`f59f40b`) | 2-3 h |
| 5 | **Risk #2 tier 2** — deploy Mercure externamente (Fly.io/Render/VPS) | `c6c5151` | cuando usuario quiera |

#### 🟡 Bloque 4: Mejoras pendientes que el usuario mencionó

- **Backend improvement del chat/users** — auditado en Risk #8, está limpio (no había bug real, era percepción del usuario de que los usuarios estaban "hardcodeados"). Si en el futuro se quiere ver online/offline más fino o agregar presencia en admin, se puede extender.
- **Hosting Mercure** — el path está documentado en AGENTS.md § Mercure / SSE. Cuando el usuario decida tener realtime de verdad, hay que hostearlo fuera de Hostinger.

### Cómo retomar

1. **Status actual (2026-09-27 — cierre de esta sesión):** el Bloque 3 está 100% cerrado. Los 4 items marcados ✅ son code shipping + tests + CI wiring. El único pendiente en producción es Risk #2 tier 2 (Mercure externo), decisión del usuario.
2. Si abrís una nueva ventana, podés:
   - Validar todo está bien en prod:
     ```bash
     ssh u310596868@185.173.111.201 "ls -lt ~/backups/db_tnsvt_*.sql.gz | head -3"
     curl -s https://www.tnsvt.com/api/health/mercure
     curl -s https://www.tnsvt.com/api/health/migrations
     ```
     (mercure debería devolver 503 `degraded`; migrations debería devolver 200 `ok` después de deploy)
   - O arrancar un **siguiente Bloque** —ej: configurar UptimeRobot/Better Stack para alertar sobre los 2 endpoints /api/health/*, o hosting de Mercure externo, o staging env.
3. Si todo está verde, el próximo milestone natural es **Risk #1**: full PHPUnit coverage for journal endpoints (sigue pendiente en el plan 60-day).

### Archivos clave creados/modificados en esta sesión

```
src/Service/MercureHealthService.php                            (Risk #2)
src/EventListener/SensitiveFieldStripListener.php               (Risk #8)
src/Controller/Api/MercureHealthController.php                  (Risk #2)
src/Service/MigrationHealthService.php                          (Risk #3 — drift detection + raw-SQL fallback)
src/Controller/Api/MigrationHealthController.php                (Risk #3)
src/Command/LintEntityMigrationsCommand.php                     (Risk #3 — pre-deploy lint)
src/assets/controllers/chat_widget_controller.js                (Bloque L + Risk #10)
src/assets/controllers/chat_controller.js                       (Bloque L + Risk #10)
src/assets/controllers/account_switcher_controller.js           (B + H + I + J + Risk #4)
templates/_partials/api_helper.html.twig                       (Bloque A revert)
templates/sanctum/dashboard.html.twig                           (Bloque B + F + H)
config/packages/mercure.yaml                                   (HS256 → hmac.sha256 fix)
config/services.yaml                                           (DependencyFactory alias)
AGENTS.md                                                     (B + SW cache + inline-script + DB backup)
RISK_MITIGATION.md                                            (este archivo)
scripts/db-backup.sh                                          (Risk #7)
tests/Functional/JournalAccountFilterTest.php                  (Risk #1)
tests/Functional/SensitiveFieldStripTest.php                    (Risk #8)
tests/Functional/MercureHealthTest.php                          (Risk #2)
tests/Functional/MigrationHealthTest.php                        (Risk #3 — 13 tests)
tests/Functional/Stub/StubDependencyFactory.php                 (Risk #3 — test helper)
tests/Functional/Command/LintEntityMigrationsCommandTest.php    (Risk #3 — 9 tests)
tests/e2e/journal-account-switching.spec.ts                     (K3)
tests/e2e/login.ts                                             (K3)
tests/e2e/README.md                                             (K3)
tests/e2e/lint-no-mutations.mjs                                (Risk #9)
package.json + tsconfig.json + playwright.config.ts             (K3 + lint:e2e script)
.github/workflows/ci.yml                                       (+ lint-entity-migrations job)
.github/workflows/e2e.yml                                      (+ lint:e2e step)
```
