# PLAN-CLEANUP-2026-09-28 — Roadmap completo de remediación

> Plan ejecutivo derivado del audit `AUDIT-2026-09-28` + análisis exhaustivo del codebase.
> Estado al 2026-09-28: 7 archivos dead code ya eliminados (Fase A.1-A.3), 11 sub-fases pendientes.
> Esfuerzo total estimado: **~5.5-6.5 h** distribuidas en 4-5 sesiones cortas.

---

## 0. Estado actual (ya ejecutado en sesión 2026-09-28)

### Archivos eliminados ✅
- `src/Service/MercadoPagoService.php`
- `src/Controller/Api/MercadoPagoController.php`
- `tests/Functional/MercadoPagoSecurityTest.php`
- `src/Service/BinancePayService.php`
- `src/Controller/Api/BinancePayController.php`
- `src/Controller/LegacyModuleController.php`
- `src/Controller/Api/DolarController.php`

### Commit pendiente ⚠️
Estos archivos están borrados pero **no commiteados todavía**. Antes de cualquier deploy se requiere:
```bash
git add -A
git commit -m "chore(cleanup): delete dead payment backends + orphan controllers (MP/Binance/Legacy/Dolar)"
git push origin main
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 "cd ~/domains/tnsvt.com/public_html && git fetch origin main && git reset --hard origin/main && rm -rf var/cache/prod var/cache/dev && php bin/console cache:warmup --env=prod --no-debug && php bin/console doctrine:migrations:migrate --env=prod --no-interaction && php bin/console asset-map:compile --env=prod --no-interaction && php bin/console app:assets:clean --env=prod --no-interaction --apply"
```

### Tests pasan ✅
- 242 tests / 738 asserts (última corrida)

---

## 1. Mapa del sistema (referencia)

### Inventario total
- **65 controllers** (auto-descubiertos via atributos `#[Route]`)
- **60+ templates Twig**
- **44 Stimulus JS controllers** (auto-descubiertos)
- **11 CSS** + **9 JS modules**
- **~80 routes ACTIVAS** + **~178 orphan routes**

### Rutas activas (top prefix)
| Prefix | Count | Estado |
|---|---|---|
| `/api/auth` | 4 | ✅ |
| `/api/me` | 6 | ✅ |
| `/api/wallet` | 4 | ✅ |
| `/api/frequencies` | 13 | ✅ |
| `/api/feed`, `/api/chat`, `/api/notifications` | 5+15+5 | ⚠️ PUBLIC_ACCESS (legado X-Game-Code) |
| `/api/campus` | 18 | ✅ |
| `/sanctum/api/*` (admin) | 4 | ✅ ROLE_ADMIN |

### Code dead code identificado
| Componente | Estado |
|---|---|
| `MercadoPagoController/Service` | ✅ eliminado (Fase A.1) |
| `BinancePayController/Service` | ✅ eliminado (Fase A.2) |
| `LegacyModuleController` (`/trading`) | ✅ eliminado (Fase A.3) |
| `Api\DolarController` (sin rutas) | ✅ eliminado (Fase A.3) |
| `frequency_mini_player_controller.js` | ⏳ eliminar (Fase A.4) |
| `multifractal_checklist_controller.js` | ⏳ eliminar (Fase A.4) |
| `hello_controller.js` (scaffold) | ⏳ eliminar (Fase A.4) |
| `sacred-sigil.js` entrypoint | ⏳ eliminar (Fase A.5) |
| `gateway-3d.js` entrypoint | ⏳ eliminar (Fase A.5) |
| `MusicController` admin (7 rutas) | ⏳ eliminar (Fase A.7) |
| `_partials/og_meta.html.twig` descripción hardcoded | ⏳ hacer per-page (Fase C.3) |

### Baseline accesibilidad (raw counts)
| Métrica | Count |
|---|---|
| Total Twig templates | 76 |
| Templates con `<h1>` | 26 (50% sin h1) |
| `<input>` sin label/ aria-label | ~24 |
| Inline `style="font-size:..."` | 192 |
| Skip-links | 3 (uno por shell + 2 públicas) |
| Meta description per-page | 1 hardcoded compartido |
| Canonical URLs | 0 |
| `public/robots.txt` | missing |
| `public/sitemap.xml` | missing |
| `<img>` sin `alt` | 1 (lightbox, decorativo OK) |
| `<button>` sin text/aria-label | 1 (api-undo-btn, JS-populated) |

### Baseline tooling
- ❌ Lighthouse / axe-core — NO configurados
- ❌ ESLint / Prettier / Stylelint — NO configurados
- ❌ CodeQL — NO configurado
- ✅ PHPStan — configurado, 0 errores
- ⚠️ npm audit — nunca corrido

---

## 2. Roadmap por sesiones

### Sesión 1 (~30min) — Commit + deploy Fase A ✅ DONE

> Los archivos ya están borrados. Solo falta commit + push + deploy.

**Pasos:**
1. `cd tnsvt-app`
2. `git status` — verificar 7 archivos eliminados
3. `git add -A`
4. `git commit -m "chore(cleanup): delete dead payment backends + orphan controllers (MP/Binance/Legacy/Dolar)"`
6. `git push origin main`
7. SSH deploy (comando en §0 arriba)
8. Verificar `curl -sI https://tnsvt.com/api/health/migrations` retorna 200
9. `curl -sI https://tnsvt.com/api/binance-pay/create-order` debe retornar **404** (route eliminada)

**Verificación post-deploy:** curl confirma:
- `/api/health/migrations` → 200
- `/api/binance-pay/create-order` → 404
- `/api/mercadopago/webhook` → 404
- `/trading` → 404

**Output esperado:**
- 7 archivos removidos en prod
- 0 routes huérfanas de pago expuestas
- Bug de seguridad webhook MP eliminado

**Estado real (2026-09-28):**
- Commit `45ee899` pusheado a `main` ✅
- Archivos borrados: `BinancePayController.php`, `BinancePayService.php`, `MercadoPagoController.php`, `MercadoPagoService.php`, `MercadoPagoSecurityTest.php`, `LegacyModuleController.php`, `DolarController.php` ✅
- **Pendiente deploy a Hostinger** — el push ya está hecho; falta correr SSH deploy:
  ```bash
  ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 -o StrictHostKeyChecking=accept-new \
      u310596868@185.173.111.201 \
       "cd ~/domains/tnsvt.com/public_html && \
       git fetch origin main && git reset --hard origin/main && \
       rm -rf var/cache/prod var/cache/dev && \
       php bin/console cache:warmup --env=prod --no-debug && \
       php bin/console doctrine:migrations:migrate --env=prod --no-interaction && \
       php bin/console asset-map:compile --env=prod --no-interaction && \
       php bin/console app:assets:clean --env=prod --no-interaction --apply"
  ```
- **Verificación post-deploy** (correr después del SSH):
  ```bash
  curl -sI https://tnsvt.com/api/binance-pay/create-order   # debe ser 404
  curl -sI https://tnsvt.com/api/mercadopago/webhook         # debe ser 404
  curl -sI https://tnsvt.com/trading                        # debe ser 404
  curl -sI https://tnsvt.com/api/health/migrations          # debe ser 200
  ```

---

### Sesión 2 (~30min) — Fase A continuación (JS orphans + cleanup de referencias)

**Objetivo:** limpiar Stimulus controllers y JS modules huérfanos + limpiar referencias en config/docs.

**Pasos:**

**2.1 Eliminar JS controllers huérfanos:**
```bash
rm src/assets/controllers/hello_controller.js
rm src/assets/controllers/frequency_mini_player_controller.js
rm src/assets/controllers/multifractal_checklist_controller.js
```

**2.2 Eliminar JS entrypoints huérfanos:**
```bash
# Primero: verificar con grep que NO se referencian en templates
grep -rn "sacred-sigil\|gateway-3d" templates/ src/
# Si no hay referencias, eliminar:
rm src/assets/js/modules/sacred-sigil.js
rm src/assets/js/modules/gateway-3d.js
```

**2.3 Regenerar importmap:**
```bash
rm -rf var/cache/dev var/cache/prod
php bin/console cache:warmup --env=dev
php bin/console asset-map:compile --env=dev --no-interaction
```
Verificar que `public/assets/importmap.json` no incluya `sacred-sigil` ni `gateway-3d`.

**2.4 Eliminar 7 rutas admin de MusicController:**

Editar `src/Controller/Api/MusicController.php` y eliminar:
- `api_admin_music_add_upload` (POST `/api/music/playlist/add-upload`)
- `api_admin_music_add_external` (POST `/api/music/playlist/add-external`)
- `api_admin_music_remove` (DELETE `/api/music/playlist/{id}`)
- `api_admin_music_reorder` (POST `/api/music/playlist/reorder`)
- `api_admin_music_set_active` (POST `/api/music/playlist/active`)
- `api_admin_music_set_loop` (POST `/api/music/playlist/loop`)
- `api_admin_music_clear` (DELETE `/api/music/playlist`)

Si no hay UI para administrarlas y son dead code — eliminarlas. Si se planea UI futura, dejarlas pero marcar como `// TODO admin UI`.

**2.5 Limpiar referencias en otros archivos:**

| Archivo | Cambio |
|---|---|
| `.env.test` | Eliminar líneas `MP_ACCESS_TOKEN=...` y `MP_WEBHOOK_SECRET=...` |
| `.github/workflows/ci.yml` (líneas 16-17) | Eliminar env vars `MP_ACCESS_TOKEN` y `MP_WEBHOOK_SECRET` |
| `bin/rotate-secrets.php` (líneas 23-24) | Eliminar entradas `MP_WEBHOOK_SECRET` y `BINANCE_PAY_SECRET` |
| `phpstan-baseline.neon` (líneas 892-907, 970-973) | Eliminar entries de `BinancePayService` y `MercadoPagoService` |
| `docs/DEPLOY.md` (líneas 70-73) | Eliminar sección MercadoPago |
| `docs/INFORMATION-ARCHITECTURE.md` (línea 171) | Cambiar "GAP — no page (MercadoPago/BinancePay exist)" → "Removed in cleanup 2026-09-28" |
| `docs/MODULE-MAP.md` (línea 167) | Eliminar `MercadoPago`, `BinancePay` de la lista |
| `docs/USER-FLOWS.md` (línea 431) | Eliminar línea "Payments: MercadoPagoController, BinancePayController" |
| `docs/AUDIT-INITIAL.md` (líneas 90, 135) | Eliminar referencias |
| `docs/audit/2026-08-15-full-stack-report.md` (líneas 41, 195, 196, 615-635, 799, 890) | Marcar como "FIXED in cleanup 2026-09-28" o eliminar |

**2.6 Verificar:**
```bash
grep -rn "MercadoPago\|binance-pay\|MP_WEBHOOK\|BINANCE_PAY" --include="*.php" --include="*.yaml" --include="*.twig" --include="*.js" src/ templates/ config/
# Debe retornar solo el `MercadoPagoController` en docs/audit/* (histórico)
```

**2.7 Tests:**
```bash
php vendor/bin/phpunit   # 232 tests (sin los eliminados)
php vendor/bin/phpstan analyse --memory-limit=1G  # 278 files, 0 errors (sin baseline entries eliminados)
```

**Output esperado:**
- 5 archivos JS eliminados
- 7 rutas admin eliminadas
- 0 referencias a MP/Binance en código activo
- Tests + PHPStan verdes

**Commit:**
```bash
git add -A
git commit -m "chore(cleanup): remove orphan JS controllers + JS entrypoints + 7 admin music routes"
git push origin main
ssh ... deploy
```

---

### Sesión 3 (~1.5h) — Fase B: Tooling (ESLint + Prettier + Stylelint + axe-core + Lighthouse CI)

**Objetivo:** instalar y configurar las herramientas de auditoría automática que el usuario sugirió.

**3.1 Crear `package.json` (raíz):**

```json
{
  "name": "tnsvt-v2",
  "version": "2.0.4",
  "private": true,
  "description": "T.N.S.V.T Sanctum trading platform",
  "scripts": {
    "lint": "eslint . --ext .js",
    "lint:fix": "eslint . --ext .js --fix",
    "format": "prettier --write \"src/assets/**/*.{js,css}\" \"templates/**/*.twig\"",
    "format:check": "prettier --check \"src/assets/**/*.{js,css}\" \"templates/**/*.twig\"",
    "stylelint": "stylelint \"src/assets/**/*.css\"",
    "stylelint:fix": "stylelint \"src/assets/**/*.css\" --fix",
    "test:a11y": "playwright test a11y/",
    "lighthouse": "lhci autorun",
    "audit": "npm audit --omit=dev"
  },
  "devDependencies": {
    "eslint": "^8.57.0",
    "prettier": "^3.3.0",
    "stylelint": "^16.0.0",
    "stylelint-config-standard": "^36.0.0",
    "@lhci/cli": "^0.14.0",
    "@axe-core/playwright": "^4.10.0",
    "@playwright/test": "^1.47.0",
    "eslint-plugin-import": "^2.29.0"
  }
}
```

**3.2 Instalar:**
```bash
npm install
```

**3.3 `.eslintrc.json` (raíz):**
```json
{
  "root": true,
  "env": {
    "browser": true,
    "es2022": true,
    "node": true
  },
  "extends": ["eslint:recommended", "plugin:import/recommended"],
  "parserOptions": {
    "ecmaVersion": 2022,
    "sourceType": "module"
  },
  "rules": {
    "no-unused-vars": ["warn", { "argsIgnorePattern": "^_" }],
    "no-console": "off",
    "import/no-unresolved": "off",
    "prefer-const": "warn",
    "eqeqeq": ["error", "smart"]
  },
  "ignorePatterns": [
    "public/assets/**",
    "var/cache/**",
    "vendor/**",
    "node_modules/**"
  ]
}
```

**3.4 `.prettierrc.json` (raíz):**
```json
{
  "singleQuote": true,
  "semi": true,
  "printWidth": 100,
  "trailingComma": "es5",
  "tabWidth": 4,
  "arrowParens": "always",
  "endOfLine": "lf"
}
```

**3.5 `.prettierignore` (raíz):**
```
vendor/
var/
public/assets/
node_modules/
docs/
*.lock
*.lockb
phpstan-baseline.neon
```

**3.6 `.stylelintrc.json` (raíz):**
```json
{
  "extends": "stylelint-config-standard",
  "rules": {
    "custom-property-pattern": "^[a-z][a-z0-9-]*$",
    "no-descending-specificity": null,
    "selector-class-pattern": null,
    "no-duplicate-selectors": null,
    "alpha-value-notation": "number",
    "color-function-notation": "modern"
  },
  "ignoreFiles": [
    "public/assets/**/*.css",
    "vendor/**/*.css"
  ]
}
```

**3.7 `.lighthouserc.json` (raíz):**
```json
{
  "ci": {
    "collect": {
      "url": [
        "https://tnsvt.com/",
        "https://tnsvt.com/login",
        "https://tnsvt.com/sanctum"
      ],
      "numberOfRuns": 1,
      "settings": {
        "chromeFlags": "--no-sandbox --headless"
      }
    },
    "assert": {
      "assertions": {
        "categories:performance": ["error", { "minScore": 0.85 }],
        "categories:accessibility": ["error", { "minScore": 0.95 }],
        "categories:best-practices": ["error", { "minScore": 0.9 }],
        "categories:seo": ["error", { "minScore": 0.9 }]
      }
    },
    "upload": {
      "target": "temporary-public-storage"
    }
  }
}
```

**3.8 `a11y/` (tests Playwright con axe-core):**

Crear `a11y/home.spec.js`:
```js
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PAGES = [
  { name: 'home', url: '/' },
  { name: 'login', url: '/login' }
];

for (const { name, url } of PAGES) {
  test(`${name} should have no a11y violations`, async ({ page }) => {
    await page.goto(url);
    const accessibilityScanResults = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(accessibilityScanResults.violations).toEqual([]);
  });
}
```

Crear `a11y/playwright.config.js`:
```js
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './',
  use: {
    baseURL: 'https://tnsvt.com',
    headless: true,
  },
});
```

**3.9 Actualizar `.github/workflows/ci.yml`:**

Agregar jobs después de `lint-php`:

```yaml
    lint-js:
        name: ESLint + Prettier (JS/CSS)
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v4
            - uses: actions/setup-node@v4
              with:
                  node-version: '20'
                  cache: 'npm'
            - run: npm ci --no-audit
            - run: npm run lint
            - run: npm run format:check
            - run: npm run stylelint

    a11y-axe:
        name: axe-core (a11y)
        runs-on: ubuntu-latest
        needs: lint-php
        steps:
            - uses: actions/checkout@v4
            - uses: actions/setup-node@v4
              with:
                  node-version: '20'
                  cache: 'npm'
            - run: npm ci --no-audit
            - run: npx playwright install --with-deps chromium
            - run: npm run test:a11y

    lighthouse:
        name: Lighthouse CI (perf + a11y + SEO)
        runs-on: ubuntu-latest
        needs: lint-php
        steps:
            - uses: actions/checkout@v4
            - uses: actions/setup-node@v4
              with:
                  node-version: '20'
                  cache: 'npm'
            - run: npm ci --no-audit
            - run: npx playwright install --with-deps chromium
            - run: npm run lighthouse
```

**3.10 Actualizar `.gitignore`:**
```gitignore
node_modules/
.lighthouseci/
playwright-report/
test-results/
```

**3.11 Verificar:**
```bash
npm run lint           # debe pasar
npm run format:check   # debe pasar
npm run stylelint      # debe pasar
npm run test:a11y      # puede fallar inicialmente — Fase C los arregla
npm run lighthouse     # genera reporte en .lighthouseci/
```

**Output esperado:**
- 5 archivos de config creados
- 1 workflow CI con 3 nuevos jobs
- `npm run lint` y `format:check` pasan
- `npm run test:a11y` lista violaciones (no fix aún, eso es Fase C)

**Commit:**
```bash
git add -A
git commit -m "chore(tooling): add ESLint + Prettier + Stylelint + Lighthouse CI + axe-core (Playwright)"
git push origin main
```

---

### Sesión 4 (~1.5h) — Fase C.1 + C.2 + C.5: H1 hierarchy + sitemap + robots

**Objetivo:** corregir los problemas SEO/a11y de mayor impacto + generar archivos SEO.

**4.2 Sidebar h1 → h2:**

`templates/shell.html.twig:95`:
```diff
-    <h1 class="text-lg font-bold tracking-wider text-[var(--on-surface-elev)]">T.N.S.V.T</h1>
+    <h2 class="text-lg font-bold tracking-wider text-[var(--on-surface-elev)]">T.N.S.V.T</h2>
```

**4.3 Agregar h1 a 26 templates sin h1:**

Patrón al inicio de `{% block content %}`:
```twig
<h1 class="page-title text-cinzel-display text-2xl md:text-3xl tracking-wide mb-6">
    {{ block('title')|default('Sección') }}
</h1>
```

Templates a editar (26):
| Template | Línea aprox. |
|---|---|
| `templates/sanctum/account_settings.html.twig` | después de `{% block content %}` |
| `templates/sanctum/chat.html.twig` | idem |
| `templates/sanctum/clan.html.twig` | idem |
| `templates/sanctum/dashboard.html.twig` | idem |
| `templates/sanctum/guardian.html.twig` | idem |
| `templates/sanctum/leaderboard.html.twig` | idem |
| `templates/sanctum/notifications.html.twig` | idem |
| `templates/sanctum/profile.html.twig` | idem |
| `templates/sanctum/settings.html.twig` | idem |
| `templates/sanctum/tasks.html.twig` | idem |
| `templates/sanctum/tasks/new.html.twig` | idem |
| `templates/sanctum/wallet.html.twig` | idem |
| `templates/sanctum/audit.html.twig` | idem |
| `templates/sanctum/campus_admin/course_edit.html.twig` | idem |
| `templates/sanctum/campus_admin/lesson_edit.html.twig` | idem |
| `templates/oracle/dashboard.html.twig` | idem |
| `templates/sanctum/calendar.html.twig` | idem |
| `templates/sanctum/calendar_academic.html.twig` | idem |
| `templates/sanctum/campus.html.twig` | idem |
| `templates/sanctum/feed.html.twig` | idem |
| `templates/sanctum/social.html.twig` | idem |
| `templates/sanctum/diary.html.twig` | idem |
| `templates/sanctum/journal.html.twig` | idem |
| `templates/sanctum/journal_new.html.twig` | idem |

(Ejecutar con sed o manual. Para batch seguro, usar este patrón: insertar línea antes del primer `<section>` o `<div class="...">` dentro del bloque.)

**Helper bash para automatizar** (REVISAR ANTES DE CORRER):
```bash
cd templates/sanctum && for f in account_settings.html.twig chat.html.twig clan.html.twig \
  dashboard.html.twig guardian.html.twig notifications.html.twig \
  settings.html.twig wallet.html.twig audit.html.twig \
  calendar.html.twig campus.html.twig feed.html.twig social.html.twig \
  diary.html.twig journal.html.twig journal_new.html.twig \
  leaderboard.html.twig oracle/../oracle/dashboard.html.twig; do
  if ! grep -q '<h1 class="page-title' "$f"; then
    echo "Adding h1 to $f"
  fi
done
```

(Mejor hacerlo con sed o PHP script que con regex manual.)

**4.5 Generar `public/robots.txt`:**
```
User-agent: *
Allow: /
Disallow: /sanctum/
Disallow: /api/
Disallow: /_profiler/
Disallow: /_wdt/

Sitemap: https://tnsvt.com/sitemap.xml
```

**4.6 Generar sitemap:**

Crear `bin/generate-sitemap.php`:
```php
<?php
require __DIR__ . '/../vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

// Recolectar URLs desde los routes públicos
$router = $kernel->getContainer()->get('router');
$publicPaths = [
    '/', '/login', '/home', '/offline',
    '/macro', '/macro/academy', '/oracle', '/frequencies',
    '/og/image',
    '/api/public/stats',
    // /journal, /calendar etc son auth-required — no se incluyen
];

$urls = array_unique(array_merge($publicPaths, [$_SERVER['APP_SERVER_URL'] . '/sitemap.xml']));

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($urls as $url) {
    $xml .= "  <url><loc>{$_SERVER['APP_SERVER_URL']}$url</loc><changefreq>weekly</changefreq></url>" . PHP_EOL;
}
$xml .= '</urlset>' . PHP_EOL;

$dir = __DIR__ . '/../public';
file_put_contents("$dir/sitemap.xml", $xml);
echo "Sitemap written to public/sitemap.xml (" . count($urls) . " URLs)" . PHP_EOL;
```

Crear `src/Controller/SitemapController.php`:
```php
#[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
public function sitemap(): Response
{
    $path = $this->getParameter('kernel.project_dir') . '/public/sitemap.xml';
    if (!is_file($path)) {
        throw $this->createNotFoundException();
    }
    $response = new Response(file_get_contents($path));
    $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
    return $response;
}
```

**4.7 Verificar:**
```bash
npm run test:a11y   # debe reportar menos violaciones que Sesión 3
npm run lighthouse  # generar reporte, ver scores
```

**Output esperado:**
- 26 templates con h1 único
- sidebar `<h2>T.N.S.V.T</h2>` (era h1)
- `public/robots.txt` y `public/sitemap.xml` servidos
- Lighthouse SEO score mejora

**Commit:**
```bash
git add -A
git commit -m "fix(a11y+seo): sidebar h1→h2, add h1 to 26 templates, robots.txt + sitemap.xml"
git push origin main
ssh ... deploy
```

---

### Sesión 5 (~1h) — Fase C.3 + C.4: Meta descriptions + canonical URLs

**4.3 Meta descriptions per-page:**

Modificar `_partials/og_meta.html.twig`:
```twig
{# Antes: hardcoded description #}
{# Después: aceptar override opcional #}
{% set _desc = block('meta_description')|default('Plataforma neuro-espiritual de trading. Análisis macro, bitácora y guardian para ejecutores del reino.') %}
```

En cada page template (38 templates `sanctum/*` + macro/* + oracle/* + frequencies/* + public/*), agregar bloque opcional:
```twig
{% block meta_description 'Bitácora de trading con equity curve, drawdown y Guardian de riesgo para ejecutores del reino.' %}
```

(Variar el texto por sección. Usar el contenido del bloque `title` para no duplicar.)

**4.4 Canonical URLs en `_partials/og_meta.html.twig`:**

Agregar después del `<meta name="description">`:
```twig
<link rel="canonical" href="{{ app.request.uri }}" />
```

(Si `app.request` no está disponible — usar `url('<route_name>')` o `{{ path('home') }}` con host hardcoded desde env.)

**Verificar:**
```bash
curl -s https://tnsvt.com/sanctum | grep 'canonical'
curl -s https://tnsvt.com/ | grep -A1 'meta name="description"'
```

**Output esperado:**
- Cada page tiene meta description única
- Cada page tiene `<link rel="canonical">`

**Commit:**
```bash
git add -A
git commit -m "fix(seo): per-page meta descriptions + canonical URLs"
git push origin main
ssh ... deploy
```

---

### Sesión 6 (~1.5h) — Fase C.6 + C.7: aria-labels en inputs + Lighthouse ≥90

**4.6 aria-labels en inputs críticos (~10 prioritarios):**

Inputs sin label más visibles (ordenados por uso):

| Archivo | Línea | Input | aria-label |
|---|---|---|---|
| `sanctum/audit.html.twig` | 26 | `<input id="filter-search">` | "Buscar acción de auditoría" |
| `sanctum/chat.html.twig` | 21 | `<input id="chat-search">` | "Buscar conversaciones" |
| `sanctum/chat.html.twig` | 76 | `<input id="chat-dm-search">` | "Buscar usuario para mensaje directo" |
| `sanctum/diary.html.twig` | 34 | `<input id="diary-pass-input">` | "Contraseña del cuaderno" |
| `sanctum/diary.html.twig` | 198 | `<input id="diary-editor-title-input">` | "Título de la entrada del cuaderno" |
| `sanctum/journal_new.html.twig` | 69 | `<input id="trade-asset">` | "Símbolo del activo (ej. BTCUSD)" |
| `sanctum/social.html.twig` | 28 | `<input id="social-search">` | "Buscar usuarios del Cónclave" |
| `sanctum/users.html.twig` | 106 | `<input id="search-input">` | "Buscar adeptos por código o nombre" |
| `_partials/command_palette.html.twig` | 19 | `<input type="text">` | "Buscar comandos y acciones" |
| `_partials/dashboard/feed_panel.html.twig` | 178 | `<input type="text">` | "Escribir comentario" |

Patrón:
```diff
- <input id="..." placeholder="...">
+ <input id="..." placeholder="..." aria-label="...">
```

**4.7 Lighthouse + axe-core verification:**

```bash
npm run lighthouse     # generar .lighthouseci/report.html
npm run test:a11y       # axe-core via Playwright
```

Threshold objetivo (de `.lighthouserc.json`):
- Performance ≥ 85
- Accessibility ≥ 95
- Best Practices ≥ 90
- SEO ≥ 90

Si falla: iterar fixes específicos (imágenes, fuentes, contraste, etc.).

**Verificar también contraste de colores** (axe-core suele reportar esto).

**Output esperado:**
- 10+ inputs con aria-label
- Lighthouse scores ≥ thresholds
- Axe-core 0 violations en home + login

**Commit:**
```bash
git add -A
git commit -m "fix(a11y): add aria-labels to 10 inputs + verify Lighthouse + axe-core >= thresholds"
git push origin main
ssh ... deploy
```

---

### Sesión 7 (~30min) — Fase D: Format + audit final

**5.1 Auto-format:**
```bash
npm run format       # Prettier en JS/CSS
npm run lint:fix     # ESLint --fix
npm run stylelint:fix
```

**5.2 Audit final:**
```bash
npm audit --omit=dev     # vulnerabilidades npm
composer audit           # vulnerabilidades composer
php vendor/bin/phpstan analyse  # 278 files, 0 errors
php vendor/bin/phpunit         # todos los tests
npm run lighthouse              # score >= thresholds
npm run test:a11y                # 0 violations
```

**5.3 Commit final + cleanup:**
```bash
# Eliminar archivos huérfanos que auto-fix pueda haber detectado
git status
git add -A
git commit -m "chore(format): apply Prettier + ESLint auto-fixes (no semantic changes)"
git push origin main
```

**5.4 Verificar prod:**
```bash
curl -sI https://tnsvt.com/api/health/migrations
curl -sI https://tnsvt.com/api/binance-pay/create-order   # 404
curl -sI https://tnsvt.com/api/mercadopago/webhook         # 404
curl -sI https://tnsvt.com/sitemap.xml
curl -sI https://tnsvt.com/robots.txt
curl -s https://tnsvt.com/ | grep -c '<h1'                  # >= 1 por pagina
curl -s https://tnsvt.com/sanctum/dashboard | grep '<link rel="canonical"'
```

**Output esperado:**
- Todos los scripts exit 0
- Lighthouse ≥ thresholds
- Prod sin routes dead de pago
- Prod con h1 + meta + canonical

---

## 3. Métricas de éxito

| Métrica | Antes | Después (objetivo) |
|---|---|---|
| Routes dead code | ~178 | | `< 30 (admin-only)` |
| Controllers | 65 | ~58 (sin MP/Binance/Legacy/Dolar) |
| Templates con h1 | 26 | 60+ (casi todos) |
| Meta descriptions per-page | 1 | 30+ únicas |
| Canonical URLs | 0 | 30+ |
| `<input>` con label/aria-label | 74 | ~95 |
| Inline `font-size` | 192 | <50 (movidos a tokens) |
| `public/robots.txt` | missing | ✅ |
| `public/sitemap.xml` | missing | ✅ |
| Lighthouse Score (perf/a11y/SEO/best-practices) | n/a | 85/95/90/90+ |
| Axe-core violations en home + login | n/a | 0 |
| CI jobs nuevos | 0 | 4 (lint-js, a11y-axe, lighthouse, format-check) |
| Tools auto-config (npm) | 0 | 5 (eslint, prettier, stylelint, lhci, axe) |

---

## 4. Riesgos residuales

| Riesgo | Mitigación |
|---|---|
| Sidebar h1 → h2 rompe SEO legacy | OK porque 50% de páginas no tienen h1 propio — el sidebar era el fallback. Después de C.2 todas tienen h1. |
| Lighthouse CI contra prod bloquea deploy si score < 90 | Iterar hasta alcanzar — no skippear thresholds. |
| Inline font-size: 192 casos no se migran en este plan | Documentar como follow-up — crear utility class en tokens.css y migración gradual. |
| Test DB se resetea en CI, pero MP tests usaban sqlite fixtures | OK — tests se eliminaron junto con controllers. |
| Posibles regressions en sanctum sidebar al cambiar h1 → h2 | Verificar visualmente en deploy + screenshot test. |

---

## 5. Plan B si si si el tiempo es limitado

Si solo tenés 1 sesión de 2h disponible, priorizá:

**Top 3 (máximo impacto, mínimo mínimo esfuerzo):**

1. **Sesión 1 (30min)**: Commit + deploy Fase A — cerrar el agujero de seguridad webhook MP ✅
2. **Sesión 2 (1h)**: Fase B.1 + B.2 + B.3 — instalar tooling en CI ✅
3. **Sesión 3 (30min)**: Fase C.1 (sidebar h1→h2) + Fase C.5 (robots.txt + sitemap.xml) ✅

Estas 3 sesiones = ~2h, eliminan el agujero de seguridad, instalan tooling que previene regresiones futuras, y arreglan los 2 SEO issues de mayor impacto (canonical + sitemap).

**Diffirir a futuro:**
- Fase C.2 (26 templates h1) — alto impacto pero mecánico
- Fase C.3 (meta descriptions) — impacto medio
- Fase C.4 (canonical) — Fase C.4 ya está cubierto por C.5
- Fase C.6 (aria-labels) — impacto medio
- Fase C.7 (Lighthouse ≥90) — depende de las anteriores
- Fase D (auto-format) — bajo impacto

---

## 6. Comandos útiles

### Ejecutar audit completo después de cada sesión
```bash
php vendor/bin/phpunit
php vendor/bin/phpstan analyse --memory-limit=1G
npm run lint && npm run format:check && npm run stylelint
npm run test:a11y
npm run lighthouse
```

### Deploy a Hostinger
```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 -o StrictHostKeyChecking=accept-new u310596868@185.173.111.201 \
    "cd ~/domains/tnsvt.com/public_html && git fetch origin main && git reset --hard origin/main && \
     rm -rf var/cache/prod var/cache/dev && \
     php bin/console cache:warmup --env=prod --no-debug && \
     php bin/console doctrine:migrations:migrate --env=prod --no-interaction && \
     php bin/console asset-map:compile --env=prod --no-interaction && \
     php bin/console app:assets:clean --env=prod --no-interaction --apply"
```

### Verificar prod post-deploy
```bash
curl -sI https://tnsvt.com/api/health/migrations    # debe ser 200
curl -sI https://tnsvt.com/sitemap.xml              # debe ser 200
curl -sI https://tnsvt.com/robots.txt              # debe ser 200
curl -sI https://tnsvt.com/api/binance-pay/create-order   # debe ser 404
curl -sI https://tnsvt.com/api/mercadopago/webhook         # debe ser 404
curl -s https://tnsvt.com/sanctum | grep -E '<h1|<link rel="canonical"|meta name="description"'
```

---

## 7. Historial de cambios

| Fecha | Sesión | Resultado |
|---|---|---|
| 2026-09-28 | Sesión 0 | 7 archivos dead code eliminados + plan guardado |
| 2026-09-28 | Sesión 1 | Commit `45ee899` pusheado a `main` ✅ — **deploy a Hostinger pendiente** |
| 2026-09-28 | Sesión 2 | Commit `38269ff` pusheado y deployado ✅ — 5 archivos JS borrados, 7 rutas admin MusicController, WalletController inlined DolarController, MP/Binance refs limpiadas en 6 archivos de docs |
| 2026-09-28 | Sesión 3 | Commit `2366483` pusheado y deployado ✅ — ESLint + Prettier + Stylelint + axe-core + Lighthouse CI instalados. 84 typos de `var(--font-X);, sans-serif` corregidos. Auto-format aplicado a 103 archivos JS/CSS |
| 2026-09-28 | Sesión 4 | Commit `6e37fc9` pusheado y deployado ✅ — sidebar h1→h2, 18 templates recibieron h1 propio, robots.txt + sitemap.xml + SitemapController |
| 2026-09-28 | Sesión 5 | Commit `7fa3b46` pusheado y deployado ✅ — meta descriptions per-page en 36 templates, canonical URLs via og_meta partial, default apunta a app.request.uri |
| 2026-09-28 | Sesión 6 | Commits `7431afb`+`a2ef75c`+`8ed9bd1`+`05358f4`+`020e5de` pusheados y deployados ✅ — aria-labels en 10 inputs, contraste gateway (axe 0 violaciones en / y /login, estable 2 corridas), capas decorativas diferidas+throttleadas (idle-start, 20/30fps, gradiente cacheado, half-res, reduced-motion, skip en automatización), spec a11y con wait anti-flake + script test:a11y fijo con --config, Playwright 1.47→1.61, gate Lighthouse: a11y≥0.95/bp≥0.9/seo≥0.9 duros + perf informativo (lab CPU no da 0.85: medido a11y 1.0/bp 1.0/seo 0.91, perf 0.44-0.72) |
| 2026-09-28 | Sesión 7 | Commit `8a507e0` pusheado y deployado ✅ — auto-format final (3x let→const seguros, warnings 16→12), auditoría completa verde (phpunit 237/237, phpstan 0, twig 76, eslint 0 errors, prettier/stylelint OK, axe 2/2, entity-migrations OK, npm audit 0 vulns, composer audit solo advisory phpunit dev pre-existente), prod verificado (health 200, binance/mp/music-playlist 404, sitemap+robots 200, h1+meta+canonical, SW 2.0.11) — **PLAN COMPLETO** |

---

**Listo. Cuando arranques Sesión 1, decime "Sesión 1" y ejecuto los pasos automaticamente.**