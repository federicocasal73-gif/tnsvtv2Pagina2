# Deployment Guide — T.N.S.V.T Sanctum on Hostinger

## Hosting-specific layout (hPanel)

On hPanel/Hostinger shared hosting, the web server's document root is
the project root (where `composer install` runs), not the standard
`public/` subdir. A standard Symfony project, however, expects the
doc root to be `public/` so that vendor/, src/ and templates/ stay
out of the public web space.

There are two ways to bridge this:

1. **Recommended** — keep the standard layout, drop a wrapper at the
   doc root. Copy `deploy/hpanel-doc-root.htaccess` to the doc root
   (alongside `composer.json`, `vendor/`, `src/`, `public/`). It
   routes every request to `public/index.php` (the Symfony front
   controller) while still letting the web server serve static files
   from `public/assets/`, `public/uploads/`, etc.

   ```bash
   cp deploy/hpanel-doc-root.htaccess .htaccess
   ```

   Do NOT replace `public/.htaccess` — that is the Symfony-standard
   file inside the public/ subdir and is still required.

2. **Alternative** — symlink `public` to the project root so that
   `public/index.php` and `./index.php` resolve to the same file.
   This works but is fragile: PHP resolves symlinks in `__DIR__`,
   which makes `require dirname(__DIR__).'/vendor/autoload_runtime.php'`
   look one level above the project root. Use only if you fully
   understand the implications; option 1 is safer.

## Pre-requisitos

- PHP 8.4 con extensiones: intl, mbstring, sqlite3, opcache
- Composer 2.x
- Mercure hub corriendo (separado o managed)
- Node.js 20+ para build de assets
- MySQL 8.0+ (recomendado para prod) o SQLite 3.x

## Pasos de deploy

### 1. Subir código

```bash
# En local
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
zip -r deploy.zip . -x 'var/cache/*' 'vendor/*' '.env.local'
```

Subir `deploy.zip` a Hostinger via FTP/File Manager.

### 2. Configurar `.env.local`

```env
APP_ENV=prod
APP_SECRET=<openssl rand -hex 32>
DATABASE_URL="mysql://user:pass@localhost:3306/db?serverVersion=8.0.32&charset=utf8mb4"

# JWT
JWT_PASSPHRASE=<openssl rand -hex 32>
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem

# Admin
ADMIN_PASSWORD=<openssl rand -hex 32>
ACADEMIA_ADMIN_PASS=<openssl rand -hex 32>

# MercadoPago
MP_ACCESS_TOKEN=<from MP dashboard>
MP_WEBHOOK_SECRET=<from MP dashboard>

# Mercure (SSE real-time)
MERCURE_URL=https://mercure.tnsvt.com/.well-known/mercure
MERCURE_JWT_SECRET=<openssl rand -hex 32>

# Sentry (error tracking) — vacío desactiva Sentry
SENTRY_DSN=<from sentry.io project>
SENTRY_ENVIRONMENT=prod

# CORS
CORS_ALLOW_ORIGIN='^https://tnsvt\.com$'

# Messenger
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=default&auto_setup=0

# Trusted proxies (Hostinger IP)
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR

# Firebase
FIREBASE_WEB_API_KEY=<from Firebase>
FIREBASE_PROJECT_ID=tnsvt-web
FIREBASE_MESSAGING_SENDER_ID=<from Firebase>
```

### 3. Generar JWT keys (solo primera vez)

```bash
php bin/generate-jwt-keys.php --force
chmod 600 config/jwt/private.pem
```

### 4. Migrar DB

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/setup-messenger-db.php
```

### 5. Build assets

```bash
# Si asset-mapper falló en local:
php bin/console asset-map:compile
```

### 5b. Generar spec OpenAPI (cada release)

```bash
php bin/console openapi:generate
php bin/console lint:yaml config/packages/sentry.yaml
```

Spec servida en `/api/docs/openapi.json`; Swagger UI en `/api/docs`.

### 6. Configurar cron jobs

Agregar a crontab de Hostinger:

```cron
# Messenger consumer (async jobs)
* * * * * cd /home/u310596868/public_html && php bin/console messenger:consume async failed --time-limit=300 -q

# Scheduler (cron tasks)
* * * * * cd /home/u310596868/public_html && php bin/console messenger:consume scheduler_default --time-limit=300 -q

# Cache clear (semanal)
0 3 * * 0 cd /home/u310596868/public_html && php bin/console cache:clear --env=prod
```

### 7. Verificar

```bash
php bin/console about
php bin/console debug:router | grep -c "/api"
php bin/console messenger:stats
```

## Verificación post-deploy

- [ ] `/sanctum/api/users` retorna 401 sin JWT
- [ ] `/api/auth/login` con code+name retorna JWT
- [ ] Headers de seguridad presentes (CSP, HSTS, X-Frame-Options)
- [ ] `/manifest.json` retorna 200 con JSON válido
- [ ] `/sw.js` retorna 200 con JS válido
- [ ] `/api/docs/openapi.json` retorna 200 con spec válida
- [ ] Mercure publishing funciona (`php bin/console debug:container mercure`)
- [ ] Scheduler ejecuta tareas (`php bin/console debug:container scheduler`)
- [ ] Messenger queue async consume funciona

## Rollback

```bash
# Restore código
git checkout HEAD~1

# Restore DB
php bin/console doctrine:migrations:migrate prev --no-interaction
```

## Monitoring

- Logs: `var/log/prod.log`
- Messenger queue: `SELECT COUNT(*) FROM messenger_messages WHERE queue_name='default'`
- Scheduled tasks: revisar logs de cron jobs

## Soporte

- Reportar issues en GitHub con tag `deployment`
- Logs detallados con `APP_ENV=prod --verbose`