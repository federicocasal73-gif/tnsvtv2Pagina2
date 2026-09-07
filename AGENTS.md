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

## CI pipeline (`.github/workflows/ci.yml`)

6 jobs, all must pass:

| Job | What it does |
|---|---|
| `lint-php` | PHPStan level 5 against `phpstan-baseline.neon` |
| `tests` | PHPUnit 92 tests in sqlite `var/test.db` |
| `lint-twig` | `php bin/console lint:twig templates` |
| `lint-js` | `node --check` on every JS in `src/assets/controllers` |
| `security-audit` | `composer audit` (no vulnerabilities) |
| `messenger-consumer` | Smoke-test `messenger:consume async` for 5s |

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

This repo is deployed to Hostinger via **hPanel Auto Deployment**
(Option A). The hPanel UI polls the GitHub repo and runs `git pull`
on every push to `main`. There is no GitHub Actions SSH workflow
involved — `.github/workflows/deploy.yml` only fires when the
`SSH_HOST` secret is set, which is currently unused.

To verify a push has deployed: open Hostinger hPanel → Files → File
Manager → navigate to `public_html/` → check `git log -1` output via
Hostinger's terminal (if available) or just hit a known endpoint
(e.g. `/api/health`) and confirm the response.

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

**Net effect:** hPanel's git auto-deploy runs `composer install` after
each `git pull`. That step always fails. The "Falló la compilación"
status in hPanel is **cosmetic** — the runtime app is fine because
`vendor/` was populated by a previous deploy when Composer could still
run (or was pre-shipped manually).

**Workaround options** (in order of preference):

1. **Disable the build step** in hPanel → Advanced → Git → your
   repository → there is (depending on hostinger version) either a
   checkbox / toggle / drop-down that selects the project type. Pick
   "PHP/HTML" (which per Hostinger docs does not run a build step)
   instead of any "Node.js" / "with build" preset. If you cannot find
   this, ask Hostinger support to remove the `composer install`
   post-deploy hook on your account.
2. **Keep the existing `vendor/` working.** Since `public_html/vendor/`
   is already populated, the app boots fine as long as you don't add
   new composer dependencies that aren't already installed.
3. **Upgrade to Hostinger VPS or Business shared plan** if you need
   to add new composer packages later. VPS gives you full PHP control
   (incl. enabling `proc_open`); Business shared loosens the
   `disable_functions` list.

**Do NOT** commit `vendor/` to the repo as a workaround unless all
other options fail — it bloats the repo by ~100 MB and forces every
git operation to scan 12 000+ files.

**If you must add a new composer dep**:

- Either do it locally and ship a tarball via Hostinger File Manager
  → upload to `public_html/vendor/your-package/` → regenerate the
  autoloader locally and upload `vendor/composer/autoload_*.php` too.
- Or upgrade the host.

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
