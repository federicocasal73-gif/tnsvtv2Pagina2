# AGENTS.md — T.N.S.V.T V2

Operational guide for AI agents and humans working on this Symfony 8.1
trading platform. Read this **before** making non-trivial changes.

## Stack at a glance

- **Symfony 8.1** on PHP 8.4, Doctrine ORM, JWT auth (Lexik), Mercure
  (SSE), Messenger (async + sync transports), Stimulus + Turbo
  (asset-mapper, no Node build step), MySQL/PostgreSQL/SQLite.
- **Auth firewall**: `main` (JWT + Code authenticators), single firewall.
- **Roles**: `ROLE_USER` (everyone), `ROLE_ADMIN` (admins).
- **Entity layout**: `App\Entity\User` is split into three traits —
  `UserAuthTrait` (id, code, email, password, roles, active, lastLogin,
  lastActivityAt, getIsAdmin, …), `UserEconomyTrait` (wallet, coins,
  reputation), `UserTierTrait` (TIER_* constants, TIERS array,
  getTier/setTier with INITIATE fallback).

## Routes / controllers map (Sanctum & admin)

| URL prefix | Controller | Notes |
|---|---|---|
| `/api/*` | `App\Controller\Api\*` | JSON; mostly JWT-protected |
| `/sanctum/api/users` | `App\Controller\Sanctum\UsersController` | **This is the live one** for `/sanctum/api/users/*` (route name `sanctum_api_users_list`). Has `requireAdmin()` helper. |
| `/sanctum/api/users` | `App\Controller\Api\Sanctum\UsersController` | Also exists; route is shadowed by the one above. Kept for reference / phase-1a tests. |
| `/sanctum` (HTML) | `App\Controller\Sanctum\HomeController` | Dashboard redirect, requires auth |

## Recent audit & fixes (2026-09-29 session)

A full backend reconnaissance + targeted fixes session was performed.
Summary below — keep this in mind before opening new PRs that touch
the same areas.

### Bugs fixed in prod

| ID | What | Fix | Files |
|---|---|---|---|
| **B1** | `Setting::$key` mapped to column `setting_key` (entity mismatch with migration's `key`). All `SettingRepository` calls would 500 in prod MySQL. | Entity column name fixed to `key`. | `src/Entity/Setting.php`, `tests/Functional/SettingRepositoryTest.php` |
| **B2** | `AdminWalletController` ran `UPDATE "user"` (singular) against the real `users` (plural) table → 0 rows affected → `user_not_found`. | SQL literals fixed to `users`. | `src/Controller/Api/AdminWalletController.php` |
| **B3** | `DashboardController` queried `tournament_trades` (table dropped by `Version20260822000000`) → 500 on every `/sanctum/api/dashboard`. | Removed query, return `globalPnl=0` + warning. Bonus: rewrote remaining queries from MySQL-specific (`DATE_SUB(NOW(), INTERVAL ...)`) to Doctrine DQL for portability. | `src/Controller/Api/Sanctum/DashboardController.php`, `tests/Functional/DashboardControllerTest.php` |
| **B5** | `OracleController::resolveUserCode()` accepted `?code=` without ownership check → any ROLE_USER could read any other user's trading psychology metrics. | Returns 403 when non-admin asks for someone else's code. | `src/Controller/Api/Sanctum/OracleController.php`, `tests/Functional/OracleIdorTest.php` |
| **B4** | `LegacyHeaderAuthenticator` was implemented but never registered in firewall; X-Game-Code fallback was re-implemented in 6+ controllers. | Registered the authenticator. New `App\Security\GameCodeResolver` service consolidates the lookup. Migrated ChatController, ChatUploadController, CampusUploadController; remaining controllers can be migrated one by one (firewall now authenticates via header → `$this->getUser()` works). | `config/packages/security.yaml`, `src/Security/GameCodeResolver.php` (NEW), 3 controllers, `tests/Functional/GameCodeAuthTest.php` |
| **Schedule legacy** | `src/Schedule.php` and `src/Scheduler/MainSchedule.php` coexisted as two providers (separate transports). | Deleted `src/Schedule.php`. Moved `MarkTasksOverdueMessage` to `MainSchedule`. Single `scheduler_main` transport. | `src/Schedule.php` (DELETED), `src/Scheduler/MainSchedule.php` |

### Pre-existing reality vs docs

- **`config/jwt/*.pem` is ALREADY gitignored** (`.gitignore:12-14`). The
  earlier AGENTS.md warning about committed keys was outdated; the
  files were removed from tracking previously but stayed on disk.
  See `config/jwt/README.md` for the rotation procedure and
  `bin/deploy.sh` for the first-time generation step.
- **`RateLimiterTrait` IS used** by CampusController, CampusUploadController,
  FeedController, SocialController (4 callers). The earlier reconnaissance
  report that flagged it as dead was wrong. Trait stays.
- **`App\Controller\Api\Sanctum\UsersController`** does NOT exist; the
  route `/sanctum/api/users` is served by `App\Controller\Sanctum\UsersController`.
  The AGENTS.md table line about a shadowed controller is a leftover.
- **Push notifications (backend)**: `PushNotificationService` reads
  `FCM_SERVICE_ACCOUNT` or `FCM_SERVER_KEY` (NOT the `FIREBASE_*`
  env vars documented in `.env.example`). Mismatch kept for now.
- **Mercure hub is NOT running** in Hostinger shared; chat SSE/typing
  silently fall back to polling. `.env.local` has a placeholder URL
  (`https://default?token=...`).
- **Scheduler cron is NOT installed** on Hostinger shared. Jobs
  defined but never run unless operator configures hPanel Cron.

### Deferred (decided NOT to change in this session)

- **Frontend orphans**: `feed-module.js`, `redirect.css`, `cf-widget.css`,
  `mf-module.css`, `music-bar.css`, `topbar.css` — never loaded.
  Cosmetic; safe to delete in a separate PR.
- **Tailwind classes without Tailwind**: `shell.html.twig` and others
  use `bg-[var(--void-elev)]` etc. without a Tailwind pipeline. No
  CDN declared. The pages render but those utility classes don't
  apply. Out of scope for backend review.
- **`kreait/firebase-php` declared but unused**: composer.json line 13.
  The hand-rolled `PushNotificationService` covers everything.
- **`~45 entities without `CREATE TABLE` migration**: works because
  prod MySQL was populated via `doctrine:schema:create` originally.
  Generating a comprehensive initial migration is a multi-hour task
  with rollback complexity; defer.

## CI pipeline (`.github/workflows/ci.yml`)

9 jobs, all must pass:

| Job | What it does |
|---|---|
| `lint-php` | PHPStan level 5 against `phpstan-baseline.neon` + `openapi:generate` + `lint:yaml sentry.yaml` |
| `tests` | PHPUnit 237 tests in sqlite `var/test.db` |
| `lint-twig` | `php bin/console lint:twig templates` |
| `lint-js` | ESLint + Prettier check + Stylelint + `node --check` on standalone JS + inline `<script>` lint via `bin/lint-inline-js.py` |
| `security-audit` | `composer audit` (only the ignored phpunit dev advisory) |
| `messenger-consumer` | Smoke-test `messenger:consume async` for 5s |
| `lint-entity-migrations` | `app:lint:entity-migrations` (every entity needs a migration) |
| `a11y-axe` | axe-core via Playwright against prod `/` + `/login` (needs `lint-php`) |
| `lighthouse` | LHCI against prod `/`, `/login`, `/sanctum` — gates a11y≥0.95, best-practices≥0.9, seo≥0.9; perf is measured but NOT gated (SwiftShader lab, see below) |

Separate workflow `.github/workflows/e2e.yml` runs the TS Playwright
suite (`tests/e2e/`, root `playwright.config.ts`) against prod. It needs
repo secrets `TNSVT_ADMIN_CODE` + `TNSVT_ADMIN_PASSWORD` (real prod admin
creds — the specs log in as a real user; `tests/e2e/login.ts` reads them
with dev fallbacks). Optional `TNSVT_USERA_CODE/NAME`,
`TNSVT_USERB_CODE/NAME` for regular-user tests. Without the secrets the
login step times out and the suite fails — set them in GitHub Settings →
Secrets before trusting a red e2e run.

### Critical CI requirements (every job that boots the kernel)

1. **Generate JWT keys** (Lexik reads `config/jwt/private.pem`):
   ```yaml
   - name: Generate JWT keys
     env:
         JWT_PASSPHRASE: ''
     run: |
         mkdir -p config/jwt var
         if [ ! -f config/jwt/private.pem ]; then
             openssl genrsa -out config/jwt/private.pem 2048 2>/dev/null
             openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem 2>/dev/null
         fi
   ```
   Keys are generated **without passphrase** and `JWT_PASSPHRASE` is
   explicitly overridden to `''` (the repo's `.env` ships with a
   placeholder `!placeholder-rotate-in-env-local!` that would explode
   Lexik with `bad decrypt`). Only add the step to jobs that call
   `bin/console` (lint-php, tests, messenger-consumer).

2. **Warm up `dev` cache with debug=true** before PHPStan:
   `phpstan.neon` points to `var/cache/dev/App_KernelDevDebugContainer.xml`
   which is only produced when debug=true. Add
   `php bin/console cache:warmup --env=dev` (no `--no-debug`) to the
   Setup database step in lint-php.

3. **Test environment**: sqlite via `var/test.db`, `APP_ENV=test`,
   `MESSENGER_TRANSPORT_DSN=in-memory://`, `JWT_PASSPHRASE=''`. These
   are already set in the workflow's top-level `env:` block.

### When CI fails

1. Pull the failed run log:
   ```bash
   gh run view <run-id> --log > ci-fail.log
   ```
2. Find the first `[error]` or `PHP Fatal` line in the failing job's
   log section. Look at the stack trace.

## Local development

```bash
composer install
cp .env.example .env.local       # real DB + secrets
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
php -S 127.0.0.1:8000 -t public/
```

Run tests locally:

```bash
php bin/console cache:warmup --env=test --no-debug
php bin/console doctrine:schema:create
php vendor/bin/phpunit
php vendor/bin/phpstan analyse
```

## Deploy to Hostinger

This repo is deployed to Hostinger via **direct SSH** from this agent's
machine. The hPanel Auto Deployment was disabled because it always
ran `composer install` post-pull, which fails on shared hosting (see
"Hostinger proc_open limitation" below).

### Connection

| Field    | Value                       |
|----------|-----------------------------|
| Host     | `185.173.111.201`           |
| Port     | `65002` (not default 22)    |
| User     | `u310596868`                |
| Docroot  | `~/domains/tnsvt.com/public_html` |
| Key      | `~/.ssh/id_tnsvt_deploy_oc` (ed25519, no passphrase) |
| Comment  | `tnsvt-v2-deploy-2026`      |

Fingerprint: `SHA256:tLE73RqSVDp7BEtGom7Ge6zjY0B8WIp2WFqFsjVynEs`

### Deploy command

After every push to `main`, the agent runs this SSH command:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 -o StrictHostKeyChecking=accept-new \
    u310596868@185.173.111.201 \
     "cd ~/domains/tnsvt.com/public_html && \
     git fetch origin main && \
     git reset --hard origin/main && \
     rm -rf var/cache/prod var/cache/dev && \
     php bin/console cache:warmup --env=prod --no-debug && \
     php bin/console doctrine:migrations:migrate --env=prod --no-interaction && \
     php bin/console asset-map:compile --env=prod --no-interaction && \
     php bin/console app:assets:clean --env=prod --no-interaction --apply"
```

`doctrine:migrations:migrate` is included so that any new migration
shipped in the deploy is applied automatically. The local SQLite CI
env uses `doctrine:schema:create`, but prod MySQL on Hostinger has no
equivalent — every migration must be applied via this step.

The final `app:assets:clean --apply` prunes stale compiled assets
(not referenced by the just-regenerated `public/assets/manifest.json`).
Every `asset-map:compile` produces a new content-hashed filename and
leaves the previous one on disk; without clean, `public/assets/`
accumulates dozens of orphan `controllers-*.js` / `styles/*-*.css` /
`js/modules/*-*.js` files after a few deploys. The clean command parses
`manifest.json` and deletes anything on disk that's not in the live set.

`public/assets/` is gitignored, so `asset-map:compile` **must** run on every
deploy — otherwise new Stimulus controllers never reach the browser (see
campus admin users panel incident: twig deployed but JS never hydrated).

`composer install` is **intentionally omitted** because of the
`proc_open` limitation below.

To verify a deploy succeeded from your machine:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    "cd ~/domains/tnsvt.com/public_html && git rev-parse --short HEAD"
```

Then curl `https://tnsvt.com/api/auth/check` and confirm 200.

### GitHub Actions CI workflow

`.github/workflows/deploy.yml` was deleted (commit `0cc357d`). It used
to use `appleboy/ssh-action@v1.0.3` to deploy, but the current SSH
deploy is run by the agent directly (not via GitHub Actions) so we
have full control over what happens.

`.github/workflows/ci.yml` runs the 9 CI jobs (PHPStan, PHPUnit,
twig lint, JS lint, composer audit, messenger smoke test,
entity-migrations lint, axe-core, Lighthouse) on every
push to `main` or PR. **Deploy does NOT happen from CI** — only from
the agent. CI is green when all 9 jobs pass.

### Lighthouse 403 from CI runners ⚠️

The `lighthouse` job measures **production** (`https://tnsvt.com`).
GitHub runner egress IPs intermittently get HTTP 403 from Hostinger's
edge (CDN `hcdn` bot mitigation) while residential IPs and Playwright
runs from the same runners get 200 — the app itself never 403s `/`
(check `security.yaml`: `^/` is `PUBLIC_ACCESS`, and no rate limiter
covers page loads). If the job fails with `ERRORED_DOCUMENT_REQUEST
(Status code: 403)`, re-run the failed job first (`gh run rerun <id>
--failed` — fresh VM, fresh IP) before investigating code. Do NOT
weaken app rate limits to accommodate CI.

### Hostinger proc_open limitation ⚠️

Hostinger **shared hosting** disables `proc_open` in `disable_functions`
for security. This breaks `composer install` because Composer's runtime
requires `proc_open` (Symfony Process class), regardless of any flags.
Even `composer install --no-scripts` fails immediately:

```
ERROR: The Process class relies on proc_open, which is not available
on your PHP installation.
```

This `disable_functions` is INI_SYSTEM and **cannot** be overridden from
`.user.ini`, `.htaccess`, `php.ini`, or `ini_set()`. Only Hostinger staff
can change it, and they typically refuse on shared plans.

**Why we don't care:** the SSH deploy workflow above does NOT run
`composer install`. `vendor/` is already populated from a previous
successful Composer run (or pre-shipped manually). As long as no new
composer dependencies are added, the existing `vendor/` works fine.
The deploy just pulls new source code, clears cache, and rebuilds.

**If you need to add a new composer dep:**

1. `composer install` locally to populate your local `vendor/`
2. Tar `vendor/your-new-package/` + updated `vendor/composer/` (autoload changes)
3. Upload via Hostinger File Manager or `scp` to `public_html/vendor/`
4. Or upgrade to Hostinger VPS or Business shared plan where `proc_open`
  is enabled.

**Do NOT** commit `vendor/` to the repo as a workaround unless all
other options fail — it bloats the repo by ~100 MB and forces every
git operation to scan 12 000+ files.

## Conventions

- Commits use Conventional Commits (`feat:`, `fix:`, `ci:`, `refactor:`).
- Phase-prefixes (`P0`, `P1`, ...) for major milestones, free-form
  otherwise.
- **Do not** add `--no-verify` to skip hooks.
- **Do not** commit secrets, `var/`, `vendor/`, `public/assets/` (already
  in `.gitignore`).

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `JWTEncodeFailureException: ... private key/passphrase` | Lexik passphrase mismatch or missing key | Regenerate keys (see CI step 1 above), set `JWT_PASSPHRASE=''` |
| `Container var/cache/dev/App_KernelDevDebugContainer.xml does not exist` | PHPStan step didn't warm up dev cache with debug | Add `php bin/console cache:warmup --env=dev` (no `--no-debug`) |
| `Call to undefined method App\Entity\User::isAdmin()` | User is a sum of three traits; the actual method is `getIsAdmin()` | Use `getIsAdmin()` (or check `UserAuthTrait`) |
| `AccessDenied` returning 500 instead of 403 | Controller throws instead of returning JSON | Use `requireAdmin()` helper from `App\Controller\Sanctum\UsersController` or add `kernel.exception` listener that translates `AccessDeniedException` → 403 JSON for `/api/` and `/sanctum/api/` paths |
| `Uncaught ReferenceError: apiFetch is not defined` (or `apiSetupModal`, `apiToast`, `apiConfirm`) at line ~XXX in a child template's inline script | Someone re-introduced the "convert api_helper.html.twig to ES module" mistake (commit `df090e6`). The ES module loads asynchronously but the inline script runs synchronously during HTML parse. | Read the "Inline `<script>` vs ES modules" section below. The partial **must stay inline**. If you need code-splitting, refactor the consumer to import or use DOMContentLoaded. |
| `sw.js:158 Uncaught (in promise) TypeError: Failed to fetch` after a deploy | Service Worker is trying to refetch old bundle URLs after asset hashes changed but `APP_VERSION` wasn't bumped in `.env` AND `.env.local`. The SW activate handler invalidates only caches whose key starts with the current `CACHE_VERSION`. | Bump `APP_VERSION` in both `.env` and `.env.local`, deploy. See "Service Worker cache versioning" below. |
| `Cannot find imported JavaScript asset "js/api-helper.js" in asset mapper` during `asset-map:compile` | Stale entry in `var/cache` referencing the deleted module. | `rm -rf var/cache/dev var/cache/prod` then re-compile. (See commit `a010e62` history.) |
| `Uncaught PHP Exception Error: "Unknown named parameter $X"` (or `ClassNotFoundError` for a vendor class) **only on prod**, local/CI green | Prod `vendor/` drifted from `composer.lock` (stale or Frankenstein package: old files, mixed generations). `composer install` can never run on Hostinger (proc_open). | Tar the locked package from local `vendor/` + `scp` to prod + whole-dir replace + `cache:warmup`. No `vendor/composer/*` metadata changes needed if versions match the lock (PSR-4 fallback resolves). Verify with `grep -m1 "function <name>" vendor/...` on prod. (Incident 2026-09-29: `symfony/mercure` + `mercure-bundle`.) |

### Service Worker cache versioning

`CACHE_VERSION` in `templates/sw.js.twig` is derived from `APP_VERSION`
via `src/Controller\ServiceWorkerController.php`. When the SW activates,
it deletes every cache whose key doesn't start with the current
`CACHE_VERSION` — so a bumped `APP_VERSION` invalidates all client-side
caches at once.

**Always bump `APP_VERSION` in BOTH `.env` (committed) AND `.env.local`
(gitignored) when you change static assets** (`asset-map:compile` output
hashes, CSS/JS modules, etc.). If you only bump `.env`, prod will keep
serving the old `cache_version` because `ServiceWorkerController` reads
via `$_ENV` / `$_SERVER` (Dotenv), not `getenv()`.

**Mandatory check on every asset-changing PR:** when you commit
`public/assets/*` (after compile), `src/assets/styles/*`, or any
JS/CSS module that `asset-map:compile` will hash, the PR description
must include "Bump APP_VERSION to 2.0.X+1". CI cannot enforce this
because `public/assets/` is gitignored — this is a manual
convention enforced by reviewers.

**Bug history:** the original implementation used `getenv('APP_VERSION')`,
which **always returned false** on Hostinger shared hosting because
LiteSpeed + PHP-FPM don't export OS env vars. The fallback hardcoded
`'2.0.0'` was therefore unconditional, and the cache never invalidated
between deploys. Fix (commit `eec49b5`): read from `$_ENV` then
`$_SERVER` then `getenv()` in that order.

**Symptom if you forget to bump:** users see `sw.js:158 Uncaught (in
promise) TypeError: Failed to fetch` in DevTools console after a
deploy that changed bundle hashes. The SW still has the old cache
key (`tnsvt-2.0.0-*`) and tries to refetch the old URLs that no
longer exist on disk. Fix: either bump `APP_VERSION` and redeploy,
or have the user DevTools → Application → Service Workers →
"Unregister".

### Mercure / SSE

`templates/sw.js.twig` and `MercurePublisher` assume a Mercure hub is
reachable at `MERCURE_PUBLIC_URL`. On Hostinger shared, the hub is **not
running** (`.well-known/mercure` returns 404), so all SSE-driven
features (typing indicator, realtime message arrival, presence
notifications) silently fall back to polling. Chat itself still works
via 15-30s polling, but the UX feels laggy. To enable real realtime, host
Mercure externally (Fly.io free tier, Render, or a small VPS) and set
`MERCURE_URL` + `MERCURE_PUBLIC_URL` in `.env.local` to the public URLs.

### Inline `<script>` vs ES modules — DO NOT convert `api_helper.html.twig`

`templates/_partials/api_helper.html.twig` defines **global** functions
(`window.apiFetch`, `window.apiToast`, `window.apiSetupModal`,
`window.apiConfirm`, etc.) on the `window` object. These globals are
consumed by **inline `<script>` blocks** in many child templates
(e.g. `templates/sanctum/journal.html.twig`, `templates/sanctum/calendar.html.twig`,
`templates/sanctum/journal_new.html.twig`) that call `apiSetupModal(...)`,
`apiFetch(...)`, etc. **synchronously at parse time**.

**If you convert this partial to an ES module** (loaded via `importmap`
in `app.js`, served as a deferred `<script type="module">`):
- The module loads **asynchronously**, AFTER the HTML is parsed.
- Inline scripts in child templates (e.g. `dashboard.html.twig`) try
  to call `apiSetupModal(...)` while parsing — but `window.apiSetupModal`
  is still `undefined` at that point.
- You get `Uncaught ReferenceError: apiSetupModal is not defined` in
  every page that uses an inline script + the API.

**This is exactly what happened in commit `df090e6`** and was reverted
in commit `a010e62`. The inline `<script>` in `api_helper.html.twig`
**must remain inline** until you also refactor **every consumer** to
either:
- Import the helper as an ES module from the module itself (i.e.
  `import { apiFetch } from '...api-helper'`), OR
- Wait for `window.apiReady` event (would require a ready-pattern).

**Rule of thumb:** if a helper assigns to `window.*`, keep it as an
inline `<script>` that runs synchronously. If you really want code-splitting,
wrap the consumers in `DOMContentLoaded` so they run after the module
loads. Never assume a deferred module is loaded at inline-script-parse time.

**Allowed for ES modules:** new helper modules that **export** functions
and are consumed by **other ES modules** (e.g. Stimulus controllers
loaded by `@symfony/stimulus-bundle`). Those work fine because Stimulus
runs controllers after DOMContentLoaded.

### Database backups

**Status (Risk #7 in RISK_MITIGATION.md):** script `scripts/db-backup.sh`
exists and is tested (creates a verified gzipped dump). **Cron is
NOT installed on the Hostinger shared box** — `crontab`, `at`,
`systemd-run` are all unavailable. The deploy step in this repo
does **NOT** install a cron automatically.

**Hostinger shared does not allow `proc_open`, `crontab`, or
`systemd-timer`** — same root cause as the `composer install`
limitation. You must use **Hostinger hPanel → Advanced → Cron Jobs**
(web UI) to schedule the backup. Steps:

1. Log in to https://hpanel.hostinger.com
2. Hosting → your domain → **Advanced → Cron Jobs**
3. Add a cron entry:
   - **Command:** `/home/u310596868/bin/db-backup.sh >> /home/u310596868/backups/db-backup.log 2>&1`
   - **Schedule:** `0 3 * * *` (every day at 03:00 server time)
4. Save. The script will run daily, gzip to `~/backups/`, rotate
   files older than 30 days.

If you want to run it manually (e.g., before a deploy that touches
schema): `ssh u310596868@185.173.111.201 '~/bin/db-backup.sh'`.

**Restore from a backup:**
```bash
# Find the latest backup file
ls -lt ~/backups/db_tnsvt_*.sql.gz | head -1
# Decompress + pipe into mysql (use socket auth, not TCP)
gunzip -c ~/backups/db_tnsvt_20260927_030000.sql.gz \
  | mysql --socket=/var/lib/mysql/mysql.sock \
           -u u310596868_tnsvt_v2 \
           u310596868_tnsvt_v2
```

**Off-server backup (future):** the script is structured so adding
an upload step before the rotation is one-line. When the user has
S3 / Backblaze B2 / a personal NAS / etc., add the upload before
the `find … -delete` line. Until then, the backup lives on the same
disk as the server — better than nothing, not disaster-proof.
