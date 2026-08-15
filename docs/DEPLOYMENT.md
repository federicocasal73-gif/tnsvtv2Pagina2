# TNSVT — Deployment Guide

How to deploy TNSVT v2 to a Hostinger-like Linux target safely.

> **Read [`docs/SECURITY.md`](SECURITY.md) FIRST.** The credentials that came
> in your original `.env.local` are considered compromised. Rotate them before
> any external commit or deploy.

---

## 0. Tooling created in this phase

| Script | Platform | Purpose |
|---|---|---|
| `bin/pre-deploy-check.sh` | Linux/macOS/WSL | Read-only pre-deploy verification |
| `bin/pre-deploy-check.ps1` | Windows PowerShell | Same, for Windows dev |
| `bin/deploy.sh` | Linux/macOS | Full deploy via SSH + git |
| `bin/post-deploy-smoke.sh` | Linux/macOS | HTTP smoke tests against the live URL |
| `bin/rollback.sh` | Linux/macOS | Roll back to a previous release |

---

## 1. Pre-requisites (Hostinger-style target)

| Component | Required version |
|---|---|
| PHP | ≥ 8.4 (matches `composer.json`) |
| Extensions | `ctype`, `iconv`, `pdo_mysql` (or `pdo_pgsql`), `openssl`, `mbstring`, `intl` |
| Composer | ≥ 2.x |
| Node.js | not required (asset-mapper / importmap is PHP-native) |
| Web server | Apache/Nginx/OpenLiteSpeed. Caddy also works. |
| DB | MySQL 8.x or PostgreSQL 16.x — see `composer.json` doctrine defaults |
| Git | ≥ 2.x on the server |

Verify with `php -v`, `composer --version`, `git --version` on the server.

---

## 2. First-time deploy

### 2.1 Provision the server

1. Create a Hostinger account / server with PHP 8.4 enabled.
2. SSH in and create the deploy target directory (e.g. `~/public_html`).
3. Clone the repo into a sibling directory (e.g. `~/tnsvt`):
   ```bash
   cd ~
   git clone <your-git-remote> tnsvt
   ```
4. Symlink/copy `~/tnsvt/public` to `~/public_html` (or whatever the web root is).
   With Apache/.htaccess, you point DocumentRoot at the `public/` subdirectory.

### 2.2 Configure environment

SSH into the server, then:

```bash
cd ~/tnsvt
cp .env .env.local
```

Edit `.env.local` (NEVER `.env`) with **real production values**:

```dotenv
APP_ENV=prod
APP_SECRET=<32-byte random hex>

DATABASE_URL="mysql://prod_user:STRONG_PASSWORD@localhost:3306/prod_db?serverVersion=8.0.32&charset=utf8mb4"

JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=<strong passphrase>

MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

CORS_ALLOW_ORIGIN='^https?://tnsvt\.com$'

MERCURE_URL=https://tnsvt.com/.well-known/mercure
MERCURE_PUBLIC_URL=https://tnsvt.com/.well-known/mercure
MERCURE_JWT_SECRET=<32-byte random hex>
```

Generate keys:

```bash
php bin/console lexik:jwt:generate-keypair --overwrite
# Prompts for passphrase — use the same value as JWT_PASSPHRASE
```

Generate APP_SECRET and MERCURE_JWT_SECRET:

```bash
php -r 'echo bin2hex(random_bytes(32));'
```

### 2.3 First-time database setup

```bash
APP_ENV=prod php bin/console doctrine:database:create --if-not-exists
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php bin/console doctrine:fixtures:load --no-interaction --append  # only if you have fixtures
```

### 2.4 Cache + permissions

```bash
APP_ENV=prod php bin/console cache:clear --no-warmup
APP_ENV=prod php bin/console cache:warmup

# Ensure var/ is writable by the web server user
chmod -R 775 var/
chown -R www-data:www-data var/    # adjust user to match your web server
```

### 2.5 Smoke test the manual deploy

```bash
curl -I https://tnsvt.com/
curl -I https://tnsvt.com/login
curl -I https://tnsvt.com/api/auth/check
```

Expect:
- `/` → 200 (landing)
- `/login` → 200 (login form)
- `/api/auth/check` → 200 with JSON body

---

## 3. Subsequent deploys (CI-friendly)

### 3.1 Configure deploy env on the local dev machine

In your shell (NOT in `.env.local`):

```bash
export TNSVT_DEPLOY_SSH="u123456@u123456.hostinger.com"
export TNSVT_DEPLOY_PATH="/home/u123456/public_html/tnsvt"
export TNSVT_DEPLOY_BRANCH="main"
export TNSVT_DEPLOY_HEALTHCHECK="https://tnsvt.com"
```

### 3.2 Run the deploy

From the project root:

```bash
./bin/pre-deploy-check.sh        # local verification
./bin/deploy.sh                  # SSH + git push + remote deploy steps + smoke
```

`deploy.sh` will:
1. Run `pre-deploy-check.sh` (aborts if anything fails)
2. SSH-test connectivity
3. Tag a release (`tnsvt-release-<timestamp>`)
4. `git push origin main --tags`
5. On the remote: `git pull`, `composer install`, cache clear/warmup,
   `doctrine:migrations:migrate`, asset install
6. Run `post-deploy-smoke.sh` against the live URL
7. Print the release tag (for rollback)

### 3.3 Rollback

```bash
./bin/rollback.sh tnsvt-release-20260814-181530
# or, to roll back to the most recent successful deploy:
./bin/rollback.sh   # uses .last-release
```

The script prompts for confirmation. It checks out the target commit on the
server and re-runs cache + composer install.

⚠️ **DB migrations are NOT reversed automatically.** If a migration ran in the
release you're rolling back, manually run on the server:

```bash
APP_ENV=prod php bin/console doctrine:migrations:migrate prev
```

---

## 4. Pre-deploy checks (what `./bin/pre-deploy-check.sh` verifies)

1. **PHP syntax** — `php -l` on every `.php` file under `src/`, `templates/`, `bin/`.
2. **Templates present** — verifies the critical templates from Phase 1–5
   exist (`public/home.html.twig`, `public/login.html.twig`,
   `public/shell.html.twig`, `_public_nav.html.twig`, `sanctum/guardian.html.twig`,
   `shell.html.twig`).
3. **Legacy removed** — `templates/home.html.twig` must NOT exist.
4. **Guardian services present** — the Phase 1+2+3 services all on disk.
5. **Security** — `.env` present, `.env.local` gitignored, no committed real
   JWT passphrase, Mercure default secret replaced.
6. **Git state** — clean working tree (warns if not).
7. **Composer sanity** — `composer.json` + `composer.lock` present, `vendor/`
   installed locally.

If any check fails, the script exits non-zero and `deploy.sh` refuses to run.

---

## 5. Smoke tests (what `./bin/post-deploy-smoke.sh` verifies)

Hits the deployed URL and asserts status codes:

| Path | Expected | Why |
|---|---|---|
| `GET /` | 200 | Landing renders |
| `GET /login` | 200 | Login form renders |
| `GET /home` | 200 | Alt landing route |
| `GET /api/auth/check` | 200 | API responds (no auth required) |
| `GET /sanctum` | 302 | Anonymous → redirect to login |
| `GET /styles/home.css` | 200 | CSS asset reachable |
| `GET /styles/tokens.css` | 200 | CSS asset reachable |
| `GET /sanctum/users` | 302 | Admin path → redirect for anonymous |
| `GET /sanctum/monitoring` | 302 | Admin path → redirect for anonymous |

A failure here is a smoke FAIL (red). A warning (yellow) means non-critical
deviation — review but doesn't block.

---

## 6. Manual verification checklist (post-deploy)

Walk through these on the deployed URL with an admin account (`ADMIN01`):

- [ ] `/` shows clean landing, no login form
- [ ] Top-right "Entrar" link works → goes to `/login`
- [ ] `/login` shows only the form
- [ ] Admin login (`ADMIN01` + password) redirects to `/sanctum`
- [ ] Sidebar shows 5 macros + Inicio + Admin (no duplicate Notificaciones)
- [ ] `/sanctum/guardian` loads with score + signals
- [ ] Save a trade → Guardian signals refresh
- [ ] Logout → back to `/`
- [ ] Cron / Mercure hub running (if applicable)

---

## 7. Rollback procedure

1. Identify the release tag: `git tag -l 'tnsvt-release-*' | sort | tail -5`
2. `./bin/rollback.sh <tag>` (or just `./bin/rollback.sh` for last known good)
3. Confirm at the prompt
4. If DB migrations involved: SSH in and run `doctrine:migrations:migrate prev`
5. Re-run `./bin/post-deploy-smoke.sh` to verify
6. Notify users if the rollback affects them (typically just "we're back to
   the previous version")

---

## 8. Operational rules

- **Never commit secrets.** `.env.local` is gitignored; double-check with
  `git ls-files .env.local` — should error.
- **Never skip `pre-deploy-check.sh`.** It catches PHP syntax errors and
  template regressions that would otherwise 500 the live site.
- **Always smoke-test after deploy.** Even a successful `deploy.sh` exit code
  doesn't guarantee the app works — run `post-deploy-smoke.sh`.
- **Tag every release.** The rollback script depends on tags. Don't `git push
  --force`.
- **Rotate secrets on suspicion.** See `SECURITY.md` §3 for the rotation
  protocol.
- **Backup DB before any migration.** `mysqldump -u root -p db > backup.sql`.

---

## 9. Open items (not blockers, but worth tracking)

- **CI pipeline**: no automated CI yet. Consider adding a GitHub Action that
  runs `./bin/pre-deploy-check.sh` on every push to `main`.
- **Health endpoint**: there's no dedicated `/health` route. `post-deploy-smoke.sh`
  uses public paths as a proxy. Consider adding `App\Controller\HealthController`
  that returns JSON for monitoring.
- **Log shipping**: Monolog is configured but no centralized logging. Add
  Monolog handlers for Sentry/Papertrail/etc.
- **Backup automation**: `mysqldump` is manual today. Cron it.
- **Caching strategy**: PHP OPcache + Symfony cache are on. Consider adding a
  reverse proxy (Varnish, Cloudflare) for `GET /` and other marketing pages.
