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
| **Mitigation** | • Bloque F (P0) ships `accountQueryParam()` helper centralised in `templates/sanctum/journal.html.twig`. Any future widget MUST use it.<br>• Bloque K3 (e2e) tests assert every journal URL contains `account_id=` after a non-default chip click.<br>• PHPUnit regression test should be added (currently no backend test for the helper — TODO). |
| **Owner** | Backend team |
| **Deadline** | Add PHPUnit test by **2026-10-15** (next sprint) |
| **Rollback plan** | `git revert c41d9d3` (commit of Fix #F+G). Verifies widgets revert to 'all accounts'. Then investigate. |

## Risk #2 — Mercure hub will keep failing silently on Hostinger

| | |
|---|---|
| **Severity** | 🟠 Medium — chat realtime (typing indicators, message arrival without reload) is permanently degraded. Users learn to live with it. The failure is silent: no log, no metric. |
| **Likelihood** | Certain (already failing) |
| **Mitigation (tier 1)** | Add a `MercureHealthCheck` listener: every 5 min, try `cache_pool->getItem('mercure_alive')->isHit()`. If the publisher fails ≥ 3 times, log a single `CRITICAL` line with `<env=production>` tag so `monolog` alerts catch it. Minimal overhead.<br>**Status: TODO — NOT yet shipped.** |
| **Mitigation (tier 2)** | Document a runbook in `AGENTS.md § Mercure / SSE` explaining how to host Mercure externally (Fly.io free tier, Render, or a $5/mo VPS). Hostinger shared has `proc_open` disabled — same constraint that prevents `composer install` — so the hub MUST be elsewhere. |
| **Owner** | Platform team |
| **Deadline** | Tier 1: 2026-10-01 (next sprint). Tier 2: when chat realtime becomes a customer-requested feature. |
| **Rollback plan** | Tier 1 is read-only — no rollback needed. Tier 2 is a deployment; old domain stays live until new hub smoke-tested. |

## Risk #3 — Future `campus_*`-style entities never get a migration

| | |
|---|---|
| **Severity if it breaks** | 🔴 High (entire feature invisible in prod, works in tests because `doctrine:schema:create` mirrors all entities). Lessons learned from the 12-table bug we just fixed. |
| **Likelihood** | Medium — easy to ship a new entity without a migration; CI never catches it (sqlite via `schema:create`). |
| **Mitigation (automated)** | Custom PHPStan rule (config in `phpstan-baseline.neon`) that detects a new `#[ORM\Entity]` without a corresponding `Version*.php` migration in the same PR. Reject the PR via the linter step.<br>**Status: NOT shipped (would take ~2-3h to write + test a custom rule).** |
| **Mitigation (process)** | `AGENTS.md § Deploy to Hostinger` now includes `php bin/console doctrine:migrations:migrate --env=prod --no-interaction` as part of the deploy command. From this commit onward, every push applies pending migrations on prod. |
| **Mitigation (observability)** | Add a `/api/health/migrations` endpoint that returns the highest-applied migration version vs the highest-defined. If out of sync, returns 500 + logs `critical`. Expose in uptime monitoring (UptimeRobot / Betterstack).<br>**Status: TODO.** |
| **Owner** | Backend team |
| **Deadline** | Process (✅ shipped). Automation: 2026-11-01 (next quarter). |
| **Rollback plan** | If a migration breaks prod, SSH `doctrine:migrations:migrate prev` to roll back one version. The m:ss option lets us reach a specific version non-sequentially. |

## Risk #4 — Account-switcher chip strip flicker on cold cache

| | |
|---|---|
| **Severity** | 🟡 Low (cosmetic) |
| **Likelihood** | Medium — `renderChips()` runs twice on initial load (once before `setActiveAccount` resolves the persisted/orphan id, once after). Bloque I partially fixed by reordering, but the flicker window is ~10–50ms on slow networks. |
| **Mitigation** | • Bloque I reorders `loadAccounts()` to resolve active before rendering.<br>• Future: collapse `setActiveAccount` and `renderChips` into a single `renderFor(active)` method that takes the active id as a parameter. Avoids the dual-pass entirely.<br>**Status: Bloque I shipped. Refactor: TODO.** |
| **Owner** | Frontend team |
| **Deadline** | Refactor: 2026-Q4 |
| **Rollback plan** | If flicker becomes user-visible: temporarily inline the persisted id into the Twig template with `data-active-account-id="{{ app.user.activeAccountId }}"` so the first paint is correct server-side. Removes the need for the JS resolution. |

## Risk #5 — Service Worker `cache_version` stale after asset changes

| | |
|---|---|
| **Severity** | 🟠 Medium (silently keeps clients pinned to old bundles; the "Failed to fetch" at sw.js:158 returns; users see broken UI until manual SW unregister) |
| **Likelihood** | Low now (bug fix in `ServiceWorkerController` reads `$_ENV` correctly). Recurs only if a future regression breaks the env var reading. |
| **Mitigation** | • Bloque "fix(sw): read APP_VERSION from $_ENV..." shipped (`eec49b5`).<br>• AGENTS.md documents the convention: bump APP_VERSION in BOTH `.env` AND `.env.local` on every asset change.<br>• Bloque E added `migrations:migrate` to deploy command — same doc now needs to mention bumping APP_VERSION for the `asset-map:compile` steps too. **TODO: extend AGENTS.md to make the bump mandatory when asset changes are committed.** |
| **Owner** | Platform team |
| **Deadline** | Doc update: this PR (combine with Bloque E). |
| **Rollback plan** | If SW is broken on prod: SSH in and manually edit `var/cache/prod/App_KernelProdContainer.xml` to set the `cache_version` to the next incremented value. Or have users hit DevTools → Application → Service Workers → Unregister. |

## Risk #6 — `assets/api-helper.js` orphan import can re-occur

| | |
|---|---|
| **Severity** | 🟡 Low (asset-map:compile fails locally; deploy script `set -e` halts) |
| **Likelihood** | Medium — a future agent (or dev) might re-introduce the ES module pattern that Bloque A (and its revert) shows is broken for this codebase. |
| **Mitigation** | • AGENTS.md should document the constraint: "Inline `<script>` (not ES module) when the script defines globals read by other inline `<script>` blocks downstream. See commit messages `a010e62` and `df090e6` for the full history."<br>**Status: TODO.** |
| **Owner** | Tech writer / docs |
| **Deadline** | Doc update: this PR (combine with Bloque E) |
| **Rollback plan** | `git checkout HEAD -- templates/_partials/api_helper.html.twig src/assets/app.js` |

## Risk #7 — Production database backup integrity

| | |
|---|---|
| **Severity** | 🔴 Critical if needed (no backup = permanent data loss on migration failure) |
| **Likelihood** | Real — every `doctrine:migrations:migrate` on prod runs without an automatic snapshot. |
| **Mitigation** | • Deploy script should call `mysqldump` BEFORE the migration in the same SSH session.<br>• Cron job (daily) on the Hostinger box: `mysqldump | gzip > ~/backups/db_daily_$(date).sql.gz`, retain 30 days, offload to S3 nightly.<br>**Status: Bloque D's deploy command DOES take a backup. The daily cron is TODO.** |
| **Owner** | Platform team |
| **Deadline** | Daily cron: 2026-10-15 |
| **Rollback plan** | `gunzip < ~/backups/db_*.sql.gz | mysql -u $DB_USER -p $DB_PASS $DB_NAME`. Tested with `BACKUP_PATH` from the Bloque D script. |

## Risk #8 — Secrets in chat / logs leak via API responses

| | |
|---|---|
| **Severity** | 🟠 Medium (PII exposure — emails, X-Game-Code reuse risks) |
| **Likelihood** | Medium — `/api/chat/users` currently returns the full user list. **TODO confirm shape.** Same for `/api/auth/check`. |
| **Mitigation** | Audit every API response that touches `/api/chat/` for leaking `email`, `lastLogin`, `password_hash`, or any field not strictly needed by the client.<br>Add a Symfony `kernel.response` listener that strips `password`/`apiKey`/`lastLoginIp` on the way out.<br>**Status: TODO.** |
| **Owner** | Backend team |
| **Deadline** | 2026-Q4 (next security sprint) |
| **Rollback plan** | If over-aggressive stripping breaks a legitimate use case: refine the per-endpoint rule via a `#[StripFields('password')]` attribute + targeted listener. |

## Risk #9 — E2E tests against prod mutate real data

| | |
|---|---|
| **Severity** | 🔴 High (a runaway test could delete a user's trade or accounts) |
| **Likelihood** | Low now (all current tests are read-only). Future contributors might add a write test. |
| **Mitigation** | • `tests/e2e/README.md` mandates "Don't create or delete state — tests must be idempotent".<br>• Playwright config `fullyParallel: false, workers: 1` so even a malicious spec can't fan out.<br>• Optional: add a CI policy that rejects PRs introducing `request.post\|delete\|put` to non-test URLs in spec files.<br>**Status: Convention documented. Tooling: TODO.** |
| **Owner** | Frontend team |
| **Deadline** | Lint policy: 2026-Q4 |
| **Rollback plan** | Manual restore from `Risk #7` backup. |

## Risk #10 — Time-based JS bugs (`setInterval`, `setTimeout`)

| | |
|---|---|
| **Severity** | 🟠 Medium — chat presence ping runs every 60s. If the page is open for 8h, no leak. But if the controller is re-mounted (Turbo navigation), the timer might double-fire (cleanup may not fire if `disconnect()` isn't called reliably by Stimulus). |
| **Likelihood** | Low (Stimulus lifecycle is well-tested). |
| **Mitigation** | • `disconnect()` clears `presenceTimer` and removes the `visibilitychange` listener.<br>• Add a defensive guard: at module load, `clearInterval(window.__lastPingTimer)` to prevent any orphaned interval.<br>**Status: Bloque L shipped. Defensive guard: TODO.** |
| **Owner** | Frontend team |
| **Deadline** | 2026-10-01 |
| **Rollback plan** | `git revert` the chat controller changes; presence stops updating within 2 min of last ping (matches `isOnline()` window). |

---

## Mitigation action plan — next 30 / 60 / 90 days

### 30 days (2026-10-25)
- [ ] Risk #1: Add PHPUnit test for `accountQueryParam()` / `loadEntriesForOwner()`.
- [ ] Risk #8: Audit `/api/chat/users` and `/api/auth/check` responses, strip sensitive fields.
- [ ] Risk #10: Add defensive orphan-clear in chat controllers.
- [ ] Risk #7: Daily `mysqldump` cron + S3 offload.
- [ ] AGENTS.md: extend doc for Risks #5, #6.

### 60 days (2026-11-25)
- [ ] Risk #1: full PHPUnit coverage for journal endpoints.
- [ ] Risk #2 tier 1: `MercureHealthCheck` listener.
- [ ] Risk #9: lint policy blocking mutation in E2E specs.

### 90 days (2026-12-25)
- [ ] Risk #2 tier 2: deploy Mercure externally OR switch to PHP-native SSE.
- [ ] Risk #3: PHPStan rule that requires a migration per new entity.
- [ ] Risk #3: `/api/health/migrations` endpoint + UptimeRobot monitor.
- [ ] Risk #4: Refactor chip strip into `renderFor(active)` single-pass.

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
