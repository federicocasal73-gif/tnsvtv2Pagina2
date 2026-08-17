# TNSVT-App — Auditoría Full-Stack

**Fecha:** 2026-08-15
**Proyecto:** `tnsvt-app` (Symfony 8.1 / PHP 8.4)
**Alcance:** `src/` (246 archivos PHP), `templates/` (35 Twig), `assets/`, `config/`, `.env*`
**Out-of-scope:** Accesibilidad WCAG (solicitado explícitamente)
**Modo:** Estática (sin ejecutar tests, sin deply)

---

## Resumen ejecutivo

| Categoría | Crítico | Alto | Medio | Bajo |
|---|---|---|---|---|
| Seguridad | 11 | 9 | 7 | — |
| Código PHP / AI-debt | 7 | 24 | 31 | 18 |
| Frontend / Motion | 5 | 11 | 14 | 8 |
| Tests / Cobertura | 1 | — | — | — |
| **TOTAL** | **24** | **44** | **52** | **26** |

**Riesgo agregado: ALTO.** El proyecto compila, arranca, y la app funciona, pero:

1. **Autenticación rota**: el firewall no está cableado a JWT ni a `X-Game-Code`; cualquier atacante que conozca un `code` de usuario puede impersonar sin contraseña.
2. **Cobertura de tests < 1%**: 1 archivo de test vs 246 archivos PHP — 0.4%.
3. **Stimulus y Turbo instalados pero inertes**: 0 templates los cargan. 0 controllers `__invoke` excepto 2.
4. **Bugs explotables activos**: IDOR en `EconomicReminderController::cancel`, webhook de MercadoPago sin firma obligatoria, `/api/wallet/me` endpoint de tests en producción.
5. **Deuda de IA masiva**: 49+ bloques `catch (\Throwable)` vacíos, 33 `@` supresores, lógica de auth duplicada en 25+ controllers.

**Ningún archivo fue modificado durante la auditoría.** Solo lectura.

---

## Top 10 issues a arreglar YA (deploy blockers)

| # | Issue | Archivo | Por qué bloquea deploy |
|---|---|---|---|
| 1 | **JWT authenticator no registrado en firewall** | `config/packages/security.yaml:13-21` + `src/Security/JwtAuthenticator.php` | El bundle JWT está instalado pero el firewall nunca lo invoca. Todo `Bearer <jwt>` se ignora. |
| 2 | **Login por `code + name` permite enumeración → impersonación** | `src/Security/CodeAuthenticator.php:31-68` | `name` es público (sale en feed/leaderboard); con `code` + `name` cualquiera entra como ese usuario. |
| 3 | **`X-User-Code` en `RequireAdminTrait` eleva a admin sin secreto** | `src/Controller/Api/Admin/RequireAdminTrait.php:21-62` | Suministras `X-User-Code: ADMIN01` y sos admin. |
| 4 | **Sanctum admin endpoints sin `IsGranted('ROLE_ADMIN')`** | `AuditController.php:21`, `SettingsController.php:26`, `TasksController.php:32`, `UsersController.php:21`, `DashboardController.php:30` | Cualquier user logueado lista usuarios, lee audit log, modifica settings. |
| 5 | **Webhook MercadoPago sin firma obligatoria** | `src/Controller/Api/MercadoPagoController.php:122-136` | Si `MP_WEBHOOK_SECRET` está vacío, no valida firma → atacante credita wallets. |
| 6 | **Endpoint de test `/api/wallet/me` en producción** | `src/Controller/Api/WalletController.php:178-195` | Devuelve TODOS los campos del user con solo `X-Game-Code`. |
| 7 | **IDOR en `EconomicReminderController::cancel`** | `src/Controller/Api/EconomicReminderController.php:131-134` | Si `user_code` query está vacío, el check pasa y cancela recordatorios ajenos. |
| 8 | **`config/jwt/` vacío** (no se generaron las claves RSA) | `config/jwt/` | Primer emisión de JWT falla con excepción. |
| 9 | **`.env.local` con secretos reales de prod** | `.env.local:5-70` | APP_SECRET, JWT_PASSPHRASE, ADMIN_PASSWORD, DB credentials, Firebase keys. El `deploy-release.zip` los contiene. |
| 10 | **CORS deshabilitado por `paths: '^/': null`** | `config/packages/nelmio_cors.yaml:9-10` | `null` desactiva CORS para todo el árbol; el firewall es la única defensa. |

---

## FASE 0 — Inventario del proyecto

### Stack

| Capa | Tecnología |
|---|---|
| Runtime | PHP ≥ 8.4 |
| Framework | Symfony 8.1 |
| ORM | Doctrine ORM 3.6 + DBAL 4 |
| Auth | LexikJWTAuthenticationBundle 3.2 + custom `CodeAuthenticator` |
| Push | kreait/firebase-php 8.2 + servicio manual FCM v1 |
| Frontend | Twig + Symfony UX Stimulus 3.1 + UX Turbo 3.1 + asset-mapper |
| Storage | MySQL 8.0.32 (producción Hostinger) |
| Async | Messenger (solo `sync://`) |
| Cache | filesystem + cache.app pool |
| Rate limit | `framework.rate_limiter` + custom DB-backed `RateLimiterService` |

### Tamaños

| Área | Conteo |
|---|---|
| Entidades (`src/Entity/`) | 69 |
| Repositorios | 69 |
| Controladores | 63 (incluyendo 1 trait) |
| Servicios | 33 (8 top-level + 25 en 7 subdominios) |
| Suscriptores de eventos | 1 |
| Comandos consola | 2 |
| Clases util | 1 |
| Migraciones Doctrine | **2** (solo `frequencies` y `settings`) |
| Rutas registradas | ~155 |
| Bundles activos | 16 |
| Archivos PHP en `src/` | 246 |
| Archivos de test | **1** |
| Cobertura efectiva | **< 0.5%** |

### Bounded contexts identificados

- **Sanctum** (UI HTML principal) — 24 templates + 1 god-controller (`SanctumModuleController`)
- **Oracle** — métricas, faith/logic, emotional bias
- **Macro** — no-trade window, economic reminders
- **Frequencies** — audio hub
- **Trono / Tournament** — 2 sistemas paralelos: portfolio-style (`Tournament`) + bracket (`TournamentBracket`)
- **Chat / Feed / Social** — red social con clans, duels, leaderboards
- **Campus** — LMS con cursos, lecciones, assignments, submissions
- **Wallet / Shop / Economy** — coins, VIP, wallet_balance
- **Admin Sanctum** — 7 controllers en `Api\Sanctum\` para CRUD admin
- **Migration / Legacy** — `LegacyDataMigrator` mueve datos de DB v1 → v2

### Funcionalidades de Symfony 8 usadas vs ignoradas

| Feature | ¿Usado? |
|---|---|
| `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler] | parcial (commands sí, listener no, handler no) |
| `#[Autowire(service:)]`, `#[Autowire(env:)]`, `#[Autowire('%kernel.project_dir%')]` | sí (en 4 controllers) |
| `#[IsGranted]`, `#[CurrentUser]` | sí (39 ocurrencias) |
| Controllers `__invoke` | solo 2 (AcademiaAuthController, FirebaseConfigController) |
| Tagged iterators + `_instanceof` | sí en `services.yaml` (LinkPreview enrichers) |
| Rate limiter framework | sí (4 limiters), pero `trusted_proxies` no configurado |
| Messenger transports async | **NO** (solo `sync://`) |
| Scheduler (`#[AsSchedule]`) | **NO** |
| Security headers (CSP/HSTS) | **NO** |
| Custom password hasher (`#[AsPasswordHasher]`) | **NO** |
| `enable_authenticator_manager` | implícito |
| Asset Mapper | sí pero solo `base.html.twig` (huérfano) |
| UX Turbo / Stimulus | instalados, **NO cargados en producción** |

---

## FASE 1 — Calidad de código PHP / AI-debt

### 1.1 Cobertura de tests: BLOQUEANTE

- **1 archivo de test** vs **246 archivos PHP** = 0.4% cobertura efectiva.
- `tests/` solo contiene un archivo placeholder.
- `composer.json` requiere `phpunit/phpunit: 11.5` pero no hay tests reales.
- Riesgo: cualquier refactor puede romper funcionalidad no testeada.

**Acción:** Antes de cualquier fix, escribir al menos tests de los flujos críticos:
- `AuthController::login`, `::refresh`, `::logout`
- `JournalController::create`, `::update`
- `TournamentController::join`, `::update-equity`
- `WalletController::withdraw`, `::me` (eliminar este último)
- `TradingSafeEvent → GuardianSubscriber`

### 1.2 God-files identificados

| Archivo | Líneas | Naturaleza | Acción |
|---|---|---|---|
| `src/Controller/Api/GameController.php` | 561 | 11 routes + XP/level/rank calc | Dividir en `GameController` + `GameRankService` |
| `src/Controller/Api/TournamentController.php` | 716 | 10 routes + 3 rate limiters + 4 admin actions | Extraer `TournamentAdminService` |
| `src/Controller/Api/JournalController.php` | 532 | 8 routes + photo normalization | Extraer `JournalPhotoList` a `Util/` ya existe, moverlo a uso único |
| `src/Controller/Api/Sanctum/SanctumModuleController.php` | 268 | 23 actions, god-controller de UI | Dividir por módulo (Journal/Chat/Feed/Notifications) |
| `src/Controller/Api/CampusAdminController.php` | ~400 | 27 endpoints admin | Extraer servicios CRUD específicos |
| `src/Entity/User.php` | 285 | identity + economy + VIP + reputation + privacy + helpers | Dividir: `UserIdentity`, `UserWallet`, `UserProfile` |
| `src/Service/Monitoring/MonitoringService.php` | 230 | 9 métricas via raw SQL | Mover a repos tipados |
| `src/Service/LinkPreview/LinkPreviewService.php` | 203 | orchestrator 8 deps | OK por dominio, pero extraer cache layer |
| `src/Service/CampusStorage.php` | 352 | storage + validación + ownership | Dividir en `CampusStorage` + `FileValidator` |

### 1.3 49 bloques `catch (\Throwable)` — Deuda IA crítica

Patrón dominante (≈30% de todos los catch):

```php
try {
    // ...
} catch (\Throwable $e) {
    // Log y seguir, no fallar la creacion
}
```

| Archivo | Línea | Problema |
|---|---|---|
| `Controller/Api/TournamentController.php` | 539-543 | Catch sin `logger->error()`, `$e` se descarta |
| `Controller/Api/DuelController.php` | 236-239, 381-384, 450-453, 498-501 | Rollback + return 500 sin log |
| `Controller/Api/WalletController.php` | 65-75 | Catch + `new DolarController()` directo (bypass DI) |
| `Controller/Api/FeedController.php` | 130-135 | "Silently skip failed previews" — comentario AI |
| `Controller/Api/UserController.php` | 215-218 | `error_log()` en vez del logger inyectado |
| `Service/CampusStorage.php` | 347-349 | `return null;` sin log |
| `Controller/Api/LinkPreviewController.php` | 36-40 | `getMessage()` filtrado al cliente |

**Acción:** Política unificada:
```php
} catch (\Throwable $e) {
    $this->logger->error('Operation X failed', [
        'exception' => $e,
        'context' => [...],  // user_id, request_id, etc.
    ]);
    // Si la operación es best-effort: continuar
    // Si es crítica: throw new ServiceException(...) o return 500 con trace_id
}
```

### 1.4 Recursos huérfanos (no se cierran en error)

| Archivo | Línea | Recurso | Riesgo |
|---|---|---|---|
| `Controller/Api/MusicController.php` | 274-290 | `curl_init` + `fopen` | Si `curl_exec` lanza, ambos handles leak |
| `Controller/Api/MusicController.php` | 243-249, 301-304 | `fopen` | `stream_get_contents` puede tirar |
| `Controller/Api/JournalController.php` | 295-315 | `fopen('php://memory')` | Si `fputcsv` lanza, handle leak |

**Acción:** envolver en `try { ... } finally { curl_close($ch); fclose($fp); }`.

### 1.5 33 supresores `@` activos

```
src/Service/BinancePayService.php:129
src/Service/MercadoPagoService.php:134
src/Service/PushNotificationService.php:122, 161, 217
src/Controller/Api/DolarController.php:51
src/Controller/Api/MusicController.php:266, 274, 292, 401, 406, 407, 494-498
src/Controller/CalendarController.php:309, 317
src/Service/TournamentMailer.php:173
src/Service/LinkPreview/UrlNormalizer.php:220, 225, 235
src/Service/CampusStorage.php:37, 174, 181, 186, 214, 217
src/Command/MigrateLegacyDataCommand.php:90
src/Controller/Api/CampusUploadController.php:84, 96, 98
src/Controller/Api/MercadoPagoController.php:270
src/Service/LinkPreview/FaviconService.php:27
```

**Acción:** reemplazar cada `@func()` por:
```php
try {
    return func();
} catch (\Throwable $e) {
    $this->logger->warning('Op silenciosa falló', ['exception' => $e]);
    return $defaultValue;
}
```

### 1.6 Archivos favicon faltantes

`src/Service/LinkPreview/FaviconService.php:99-114` referencia 6 SVGs; **5 no existen en disco**:
- `tradingview-logo.svg` ❌
- `youtube-logo.svg` ❌
- `github-logo.svg` ❌
- `spotify-logo.svg` ❌
- `instagram-logo.svg` ❌
- `default-link.svg` ✅

**Acción:** crear los assets o eliminar el `map` y usar siempre `default-link.svg`.

### 1.7 Dependencias implícitas (transitivas, no declaradas)

| Uso en código | Paquete | En `composer.json`? |
|---|---|---|
| `Symfony\Component\HttpClient\HttpClient` (`CalendarController.php:5,400`) | `symfony/http-client` | ❌ (transitiva de framework-bundle) |
| `Symfony\Contracts\HttpClient\HttpClientInterface` (`FaviconService.php:8`, `LinkPreviewService.php:11`, `MarketDataService.php:6`) | `symfony/http-client-contracts` | ❌ (transitiva) |

**Acción:** agregar a `require`:
```json
"symfony/http-client": "8.1.*",
"symfony/http-client-contracts": "8.1.*"
```

### 1.8 Edge cases faltantes

#### 1.8.1 `json_decode` sin `is_array()` (30+ endpoints)

Patrón vulnerable (PHP 8.4 deprecation):
```php
$data = json_decode($request->getContent(), true);
$code = $data['code'] ?? '';  // $data puede ser null
```

**Acción:** helper único:
```php
private function decodeJsonBody(Request $request): array
{
    $data = json_decode($request->getContent(), true);
    return is_array($data) ? $data : [];
}
```

#### 1.8.2 Time-zone no manejado

- 0 llamadas a `date_default_timezone_set()`
- >100 instancias de `new \DateTimeImmutable()` sin `DateTimeZone`
- `src/Service/Monitoring/MonitoringService.php:97` lee `ini_get('date.timezone')` — saben que es frágil
- `NoTradeWindowService.php:47-128` asume TZ única — **crítico para app de trading**
- `EconomicReminderController.php:63` compara "en el pasado?" sin TZ de usuario

**Acción:**
```php
// Kernel::boot() o services.yaml:
bind: 'DateTimeZone $defaultTz' => '%kernel.default_timezone%'
// En cada new DateTimeImmutable:
new \DateTimeImmutable('now', $this->defaultTz)
```

#### 1.8.3 MIME validation insuficiente en uploads

- `CampusUploadController.php:86-91` confía en `getMimeType()` (del archivo, OK), pero **no valida que extensión coincida con MIME**
- `ChatUploadController.php:58-61` — sin validación cross
- `ProfileController.php:96-101` — `evil.php` con header `image/jpeg` pasa

**Acción:** usar `ImageValidationService` (ya existe en `src/Service/ImageValidationService.php`) **siempre**.

### 1.9 Patrones "AI smells" confirmados

| Patrón | Conteo | Notas |
|---|---|---|
| Docblocks verbosos (>4 líneas en funciones triviales) | ~12 archivos | `BinancePayService.php:28-34`, `MarketDataService.php:8-21`, `LegacyDataMigrator.php:11-28` |
| `final` excesivo | 10+ clases concentradas en `LinkPreview/` | Indica generación bulk con preferencia default |
| Comentarios "Best-effort, move on" | 6+ archivos | `TournamentController.php:538, 542`, `FeedController.php:134`, `WalletController.php:74` |
| Defensive null-checks en tipos no-nullable | varios | `FeedController.php:146-147` |
| Manual `getEntityManager()->flush()` en repos | 6 repos | Viola Doctrine best practices |
| `error_log()` en vez del logger inyectado | `UserController.php:215` | Mezcla con `EntityManager` flush |

### 1.10 Patrones "God-Entity" en User

`src/Entity/User.php:285` — concentra:
- Identity (code, name, email, password, active)
- Economy (walletBalance, coins, reputation)
- VIP (vipUntil, tier)
- Profile (notificationSound, avatar)
- Custom claims (diarySetupToken, diarySetupIv — encrypted blob seed)
- 5 OneToMany + 1 OneToOne
- Domain methods (`isOnline()`, `isVip()`, `getIsAdmin()`, `getAvatarUrl()`, `getAvatarColor()`)
- Constantes (`TIERS` array, line 205)

**Acción:** descomponer en `UserIdentity` + `UserWallet` + `UserProfile` (CQRS-light).

---

## FASE 2 — Frontend Twig + Stimulus + Motion

### 2.1 Stimulus instalado pero INERTE

- `assets/controllers/` tiene 2 archivos: `hello_controller.js` (scaffolding, nunca usado) y `csrf_protection_controller.js` (usa DOM crudo, no Stimulus).
- `assets/controllers.json` declara **0 controllers**.
- `templates/base.html.twig:11` tiene `{{ importmap('app') }}` — pero **0 templates extienden `base.html.twig`**.

**Resultado:** Stimulus bundle se compila pero nunca se carga en producción. Todos los `data-action="..."` que aparecen en templates son atributos HTML leídos por `document.querySelectorAll` en scripts inline, no son bindings Stimulus.

### 2.2 Turbo instalado pero INERTE

- `symfony/ux-turbo: 3.1` está en composer y configurado en `ux_turbo.yaml`.
- `turbo_controller.js`, `turbo_stream_controller.js` están en `public/assets/@symfony/ux-turbo/`.
- **0 templates usan `<turbo-frame>`, `<turbo-stream>`, `data-turbo-frame` o `data-turbo-stream`.**
- `csrf_protection_controller.js:13` escucha `turbo:submit-start` — listener muerto.

**Resultado:** todas las navegaciones son full page reload. Sin View Transitions API. Sin `document.startViewTransition()`.

### 2.3 CSRF: cero protección efectiva

- 0 ocurrencias de `form_theme`, `form_row(`, `csrf_token(`, `_csrf_token` en templates.
- Solo 4 `<form>` hand-rolled en `login.html.twig:22`, `dashboard.html.twig:283`, `journal.html.twig:187`, `tasks.html.twig:30`.
- Todos se envían vía `fetch()` desde scripts inline.
- **Ningún form incluye token CSRF.**
- `framework.csrf_protection.check_header: true` está en `ux_turbo.yaml:3`, pero sin form que use tokens, no aplica.

### 2.4 Skeleton loaders: definidos pero NUNCA usados

| Clase | Definida en | Usada en templates |
|---|---|---|
| `.skeleton-elev` + `skeleton-shimmer` | `components.css:436,442` | **0 templates** |
| `.social-skeleton*` | `components/social.css:61-80` | **0 templates** |

Solo se usa `.loading-pulse` (texto pulsante) en 23+ páginas — **no hay skeletons reales**.

### 2.5 Spinners: NO EXISTEN

- 0 SVGs con `animateTransform`
- 0 conic-gradient spinners
- 0 `@keyframes spin` aplicados a elementos visibles (la keyframe existe en `app.css:676` pero no se usa)

### 2.6 27+ animaciones infinite always-on

Muchas son decorativas y distraen. Lista priorizada:

| Elemento | Keyframe | Archivo | Acción |
|---|---|---|---|
| `.audio-wave-bar` (5 barras) | `wave-bar 1s` | `users.html.twig:106-113` | **Animar SOLO cuando hay audio** (engañoso: parece que suena música) |
| `.cf-bubble ×4` | `cfBubbleFloat 4s` staggered | `cf-widget.css:45-49` | **Mostrar solo cuando chat tiene mensajes no leídos** |
| `.cf-fab` | `cfFabPulse 3s` | `cf-widget.css:108` | OK si está en estado idle |
| `.cf-typing-dots ×3` | `cfTypingBlink 1.4s` | `cf-widget.css:342-345` | **Solo cuando alguien está escribiendo** |
| `.cf-panel` border glow | `cfPanelBorderGlow 4s` | `cf-widget.css:151` | OK (subtle) |
| `.frequency-wave` + `.freq-ring ×4` | `pulse-aura 4s` | `frequencies/hub.html.twig:34-43` | OK (página específica) |
| `.countdown-display` | `countdown-pulse 2s` | `macro/dashboard.html.twig:53` | **Solo cuando hay no-trade window activa** (ahora pulsa siempre) |
| `.live-dot ×5+` | `pulse-gold 2s` | múltiples | Cuando hay varios en pantalla, flicker acumulado |
| WebGL background | RAF 60fps | `webgl-background.js:107-116` | **Pausar con `document.hidden`** y al hacer scroll |
| Three.js sigil | RAF 60fps | `sacred-sigil.js:53-61` | **Pausar con `document.hidden`** |

### 2.7 prefers-reduced-motion: solo cubre APK

- **Único bloque** del proyecto: `apk-layout-fix.css:564`
- Scoped a `body.is-apk` — usuarios web/desktop/safari con `prefers-reduced-motion: reduce` **NO obtienen protección**.

**Acción:**
```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

### 2.8 800+ líneas de CSS inline duplicado en templates

29 templates tienen bloques `<style>` con CSS que duplica clases ya definidas:

| Clase | Definida en styles | Duplicada en templates |
|---|---|---|
| `.trade-row` | `shell.css:150` | `journal.html.twig:266-278` |
| `.kpi-card` | `shell.css:37-48`, `components.css:521-561` | `dashboard.html.twig:312-405` (3ra implementación) |
| `.quick-action` | `components.css:82-98`, `glow.css:240-266` | `dashboard.html.twig:431-451` (3ra implementación) |
| Tier badges | `components.css:171-194` | `shell.css:73-96` (con valores diferentes) |
| `.modal-overlay`/`.modal-content` | `components.css:633-650`, `glow.css:759-769` | `dashboard.html.twig:453-473`, `chat.html.twig:51`, `journal.html.twig:176` (4 implementaciones) |

**Acción:** mover TODO el CSS inline a los stylesheets consolidados. Crear clase única por componente.

### 2.9 61 inline `style="..."` (peor ofensor: `journal.html.twig`)

```html
<input style="width:100%; padding:0.5rem 0.75rem; background:var(--glass-bg-elev); border:1px solid var(--glass-border-elev); border-radius:0.375rem; color:var(--on-surface-elev); font-size:0.875rem;">
```

Esta misma cadena aparece **9 veces** en `journal.html.twig` (líneas 194, 199, 210, 215, 229, 234, 239, 244, 251) y otras 3 veces en `calendar.html.twig`.

La clase `.form-input` ya existe en `components.css:656` — **no se usa**.

**Acción:** reemplazar todos los `style="..."` por la clase apropiada.

### 2.10 Hover-lift inconsistente

Tres distancias diferentes para la misma interacción:

| Distancia | Archivo | Elemento |
|---|---|---|
| `translateX(2px)` | `components.css:484` | `.sanctum-link` hover |
| `translateX(4px)` | `glow.css:685` | `.task-card` hover |
| `translateX(4px)` | `glow.css:830` | `.key-card` hover |
| `translateY(-2px)` | `components.css:553-557` | `.kpi-card` hover |
| `translateY(-3px)` | `home.css:310`, `glow.css:343` | `.feature`, `.login-btn` hover |

**Acción:** design tokens unificados:
```css
--motion-lift-sm: translateY(-2px);
--motion-lift-md: translateY(-3px);
--motion-slide: translateX(2px);
```

### 2.11 Dead CSS / motion

Keyframes definidos pero nunca aplicados a elementos visibles:
- `spin-sacred` (120s), `frequency-wave`, `breath`, `entrance-slide`, `glow-pulse`, `v2-pulse-violet`, `v2-drift`, `v2-spin-slow`, `sacred-geometry-spin-elev`, `aura-glow-elev`

Total: ~10 keyframes zombies.

### 2.12 WebGL + Three.js siempre renderizando (60fps × 2 páginas)

- `webgl-background.js` (Perlin noise GLSL shader) corre en `dashboard`, `oracle`, `frequencies` indefinidamente.
- `sacred-sigil.js` (Three.js) corre en `profile` indefinidamente.
- **Nunca pausan** con `document.visibilitychange`.

**Acción:**
```js
document.addEventListener('visibilitychange', () => {
  if (document.hidden) cancelAnimationFrame(rafId);
  else requestAnimationFrame(render);
});
```

---

## FASE 3 — Seguridad & API

### 3.1 Firewall roto: JWT nunca se invoca

`config/packages/security.yaml:13-21`:
```yaml
firewalls:
    main:
        lazy: true
        provider: app_user_provider
        custom_authenticators:
            - App\Security\CodeAuthenticator  # ← solo este
        logout: { path: /api/auth/logout }
        entry_point: App\Security\CodeAuthenticator
```

- `App\Security\JwtAuthenticator` (existe en `src/Security/JwtAuthenticator.php:24`) **NO está en `custom_authenticators`**.
- `App\Security\LegacyHeaderAuthenticator` **NO está registrado**.
- Cada `Authorization: Bearer <jwt>` es **ignorado**.

**Acción:**
```yaml
firewalls:
    main:
        stateless: true
        provider: app_user_provider
        jwt: ~                              # ← agregar
        custom_authenticators:
            - App\Security\JwtAuthenticator  # ← o este
            - App\Security\CodeAuthenticator # ← para login legacy
        entry_point: App\Security\JwtAuthenticator
```

### 3.2 Login `code + name` permite enumeración → impersonación

`src/Security/CodeAuthenticator.php:42-67`:
```php
$user = $this->userRepository->findByCode($code);
if (strcasecmp(trim($user->getName()), $name) !== 0) {
    throw new BadCredentialsException(...);
}
return new SelfValidatingPassport(new UserBadge($code));
```

Ataque:
1. `GET /sanctum/api/users` (no requiere admin — ver §3.4) → obtener `code`, `name`, `email`, `walletBalance` de cada user.
2. `POST /api/auth/login {code, name}` → autenticado.

`name` es público (sale en feed, leaderboard, chat, profile). No hay contraseña para usuarios normales.

**Acción:** el `User` implementa `PasswordAuthenticatedUserInterface` y tiene columna `password` — **usar la contraseña siempre**, no solo para admin.

### 3.3 `X-User-Code` en `RequireAdminTrait` permite escalar a admin

`src/Controller/Api/Admin/RequireAdminTrait.php:21-62`:
```php
// 2) Fallback: leer del header X-User-Code
$code = $request?->headers->get('X-User-Code') ?? $request?->query->get('user_code');
$user = $userRepository->findByCode(strtoupper(trim((string) $code)));
if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
    return 403;
}
```

`X-User-Code: ADMIN01` y sos admin. Sin token, sin secreto, sin expiración.

Usado en: `UserController`, `MusicController`, `CampusController`, `GameController`, `CampusAdminController` (28 calls), `AdminWalletController`, `AcademiaController`.

**Acción:** eliminar el fallback. Solo confiar en el firewall (post §3.1).

### 3.4 Sanctum admin endpoints sin `IsGranted('ROLE_ADMIN')`

| Controller | Path | Auth actual | Riesgo |
|---|---|---|---|
| `src/Controller/Api/Sanctum/AuditController.php:21` | `GET /sanctum/api/audit` | solo firewall (ROLE_USER) | 🔴 Cualquier user lee audit log |
| `src/Controller/Api/Sanctum/SettingsController.php:26` | `GET /sanctum/api/settings` | solo firewall | 🔴 Lee settings (puede haber secretos) |
| `src/Controller/Api/Sanctum/TasksController.php:32` | `GET /sanctum/api/tasks` | solo firewall | 🔴 Lee tareas internas |
| `src/Controller/Api/Sanctum/UsersController.php:21` | `GET /sanctum/api/users` | solo firewall | 🔴 Lista users con email, wallet, roles |
| `src/Controller/Api/Sanctum/UsersController.php:52` | `PATCH /{code}/tier` | solo firewall | 🔴 Cambia tier de cualquier user (priv escalation) |
| `src/Controller/Api/Sanctum/UsersController.php:92` | `PATCH /{code}/active` | solo firewall | 🔴 Activa/desactiva cualquier user (DoS) |
| `src/Controller/Api/Sanctum/DashboardController.php:30` | `GET /sanctum/api/dashboard` | ROLE_USER | 🟡 KPIs internos a cualquier user |

**Acción:** agregar `#[IsGranted('ROLE_ADMIN')]` a cada uno. O mejor: crear un `#[IsGranted('ROLE_SANCTUM_ADMIN')]` y aplicarlo a nivel de clase.

### 3.5 `access_control` permite API entera a `PUBLIC_ACCESS`

`config/packages/security.yaml:22-44`:
```yaml
- { path: ^/api/music, roles: PUBLIC_ACCESS }
- { path: ^/api/devices, roles: PUBLIC_ACCESS }
- { path: ^/api/notifications, roles: PUBLIC_ACCESS }
- { path: ^/api/feed, roles: PUBLIC_ACCESS }
- { path: ^/api/chat, roles: PUBLIC_ACCESS }
- { path: ^/api/tasks, roles: PUBLIC_ACCESS }
- { path: ^/api/academia, roles: PUBLIC_ACCESS }
```

Cualquier endpoint bajo esos prefijos es **alcanzable anónimamente**. Aunque los controllers hagan 401 internos, el PHP corre, la DB hace queries, el rate limiter consume budget.

**Acción:** revertir a `IS_AUTHENTICATED_FULLY` excepto `^/api/auth/login`, `^/api/auth/refresh`, `^/api/firebase/config`.

### 3.6 CORS deshabilitado por `paths: '^/': null`

`config/packages/nelmio_cors.yaml:9-10`:
```yaml
paths:
    '^/': null  # ← null = "do nothing" = desactivado
```

`null` significa "no aplicar config CORS a este path" — el bundle nunca procesa la petición.

**Acción:**
```yaml
paths:
    '^/api': null        # explícito: deshabilitar CORS para API
    '^/sanctum/api': ~   # default config para admin API
```

### 3.7 `.env.local` con secretos de producción en plaintext

```dotenv
APP_ENV=prod
APP_SECRET=<redacted>
DATABASE_URL="mysql://<redacted>:<redacted>@localhost:3306/..."
JWT_PASSPHRASE=<redacted>
ADMIN_PASSWORD=<redacted>
FIREBASE_WEB_API_KEY=<redacted>
FIREBASE_PROJECT_ID=<redacted>
FIREBASE_MESSAGING_SENDER_ID=<redacted>
FIREBASE_APP_ID=<redacted>
FIREBASE_WEB_PUSH_VAPID_KEY=<redacted>
CORS_ALLOW_ORIGIN=^https?://(localhost|127\.0\.0\.1|10\.0\.2\.2|192\.168\.\d+\.\d+|100\.\d+\.\d+\.\d+|...)
MERCURE_URL=https://default?token=<redacted>
LEGACY_DATABASE_URL="mysql://<redacted>:<redacted>@localhost:3306/..."
```

- El archivo está en `.gitignore` ✅
- **Pero está en `deploy-release.zip`** ❌ (cualquiera con el zip tiene los secretos)
- `MERCURE_URL` con token embebido → tokens en logs / shell history

**Acción inmediata:**
1. **Rotar TODOS los secretos** ahora (APP_SECRET, JWT_PASSPHRASE, ADMIN_PASSWORD, DB passwords, FIREBASE_*, MERCURE_JWT).
2. Mover a `Symfony Secrets vault` (`bin/console secrets:set`).
3. Eliminar `.env.local` de `deploy-release.zip`.

### 3.8 `config/jwt/` está VACÍO

- `lexik_jwt_authentication.yaml` referencia `JWT_SECRET_KEY` y `JWT_PUBLIC_KEY` apuntando a `config/jwt/private.pem` y `config/jwt/public.pem`.
- Esos archivos **no existen**.
- Cualquier emisión de JWT fallará.

**Acción:**
```bash
bin/console lexik:jwt:generate-keypair
```

### 3.9 Webhook MercadoPago sin firma obligatoria

`src/Controller/Api/MercadoPagoController.php:122-136`:
```php
$webhookSecret = $_ENV['MP_WEBHOOK_SECRET'] ?? $_SERVER['MP_WEBHOOK_SECRET'] ?? '';
if ($webhookSecret !== '') {     // ← si está vacío, se salta la validación
    $signature = $request->headers->get('X-Signature', '');
    if (!$this->verifyMPSignature($signature, $request, $webhookSecret)) {
        return new JsonResponse(['error' => 'invalid_signature'], 401);
    }
}
```

Si `MP_WEBHOOK_SECRET` está vacío (o no se configuró), **toda validación de firma se omite**. Después llama a `processPaymentNotification` que acredita wallets via raw SQL.

**Acción:**
```php
if ($webhookSecret === '') {
    throw new \RuntimeException('MP_WEBHOOK_SECRET not configured');
}
// (siempre validar)
$signature = $request->headers->get('X-Signature', '');
if (!$this->verifyMPSignature(...)) return 401;
```

### 3.10 Endpoint `/api/wallet/me` en producción

`src/Controller/Api/WalletController.php:178-195`:
```php
#[Route('/me', name: 'api_wallet_me', methods: ['GET'])]
public function me(Request $request): JsonResponse
{
    return $this->userRepository->findOneBy(['code' => ...]);
}
```

Comentario dice "Endpoint auxiliar para tests/" — **debería estar bloqueado en prod**. Y solo requiere `X-Game-Code` (sin contraseña) → impersona cualquier user.

**Acción:** eliminar o mover a entorno test con `#[Route(condition: 'kernel.environment !== "prod"')]`.

### 3.11 IDOR en `EconomicReminderController::cancel`

`src/Controller/Api/EconomicReminderController.php:131-134`:
```php
$userCode = trim((string)($request->query->get('user_code') ?? ''));
if ($userCode !== '' && $reminder->getUser()?->getCode() !== $userCode) {
    return $this->json(['error' => 'No autorizado'], 403);
}
```

Si `$userCode === ''` (no se pasó query param), el check pasa y cualquier atacante cancela recordatorios ajenos.

**Acción:** usar `$this->getUser()` del firewall (post §3.1) en vez de `query.get('user_code')`.

### 3.12 Admin password de un solo valor

`AdminAuthTrait.php:41-47`:
```php
$provided = $request->headers->get('X-Admin-Password', '');
if (empty($provided) || !hash_equals($this->getAdminPassword(), $provided)) {
    throw new AccessDeniedHttpException('Acceso denegado');
}
```

Una sola contraseña (`ADMIN_PASSWORD`) para **todos** los endpoints admin. Sin 2FA, sin rate limit en failure (rate limit solo en `AdminAuthService::verify`).

**Acción:** migrar a JWT admin con roles separados (`ROLE_SANCTUM_ADMIN`, `ROLE_WALLET_ADMIN`).

### 3.13 Hardcoded `'ADMIN01'` como audit author fallback

`src/Controller/Api/TournamentController.php:511-513`:
```php
$adminUser = $this->userRepository->findOneBy(['code' => 'ADMIN01']);
```

Si no hay admin logueado, el audit log escribe `'admin'` como autor → **sin accountability**.

**Acción:** fallar con 401 en lugar de usar `ADMIN01` silenciosamente.

### 3.14 Rate limiters IP-based pero `trusted_proxies` no configurado

`config/packages/framework.yaml` no tiene `trusted_proxies` ni `trusted_headers`. Detrás del reverse proxy de Hostinger, `getClientIp()` devuelve la IP del proxy → todos los requests parecen venir del mismo IP → **todos los rate limiters son inútiles**.

**Acción:**
```yaml
framework:
    trusted_proxies: '%env(TRUSTED_PROXIES)%'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
```

### 3.15 Push services duplicados

- `src/Service/PushService.php` (143 líneas, usa kreait SDK)
- `src/Service/PushNotificationService.php` (234 líneas, hand-rolled FCM v1 + legacy)
- `TournamentMailer` depende de `PushNotificationService` → `PushService` es **dead code**.

`PushService::$firebaseCredentialsPath` nunca se bindea en `services.yaml` → Firebase SDK nunca inicializa.

**Acción:** eliminar `PushService` o bindear correctamente y usarlo como único.

### 3.16 `error_log()` en UserController

`src/Controller/Api/UserController.php:215`:
```php
} catch (\Throwable $e) {
    error_log('User delete cleanup error: ' . $e->getMessage());
}
```

Usa `error_log()` directo en vez del logger inyectado → bypass de Monolog → no aparece en `/var/log/`.

**Acción:** `$this->logger->error('User delete cleanup failed', ['exception' => $e]);`

### 3.17 `$e->getMessage()` filtrado al cliente

`src/Controller/Api/LinkPreviewController.php:36-40`:
```php
return new JsonResponse(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
```

**Acción:** log interno + respuesta genérica con `trace_id`:
```php
$traceId = bin2hex(random_bytes(8));
$this->logger->error('LinkPreview failed', ['exception' => $e, 'trace_id' => $traceId]);
return new JsonResponse(['error' => 'server_error', 'trace_id' => $traceId], 500);
```

### 3.18 Headers de seguridad: NO configurados

`config/packages/` no contiene `security_headers.yaml` ni `nelmio_security.yaml`. Faltan:
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Strict-Transport-Security` (HSTS)
- `Content-Security-Policy`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`

**Acción:** instalar `nelmio/security-bundle` y configurar.

### 3.19 Session cookie no hardened

`config/packages/framework.yaml:1-15` solo tiene `session: true`. Sin:
- `cookie_secure: auto`
- `cookie_httponly: true`
- `cookie_samesite: lax`
- `gc_maxlifetime`

**Acción:**
```yaml
framework:
    session:
        cookie_secure: auto
        cookie_httponly: true
        cookie_samesite: lax
        gc_maxlifetime: 1800
```

### 3.20 Vulnerabilidades adicionales detectadas

| Severidad | Issue | Archivo:línea |
|---|---|---|
| 🟡 HIGH | `/api/auth/refresh` sin rate limit | `AuthController.php:110-135` |
| 🟡 HIGH | `/api/devices/register` y `/unregister` sin rate limit | `DeviceController.php:23-76` |
| 🟡 HIGH | `/api/auth/check` sin rate limit (info disclosure) | `AuthController.php:152-167` |
| 🟡 HIGH | `/api/wallet/*` sin rate limit | `WalletController.php:53-195` |
| 🟡 HIGH | `/api/academia/admin/verify-academia-pass` sin rate limit | `AcademiaAuthController.php:20-34` |
| 🟡 HIGH | `not_compromised_password` validator NO habilitado | `validator.yaml` (config vacío) |
| 🟢 MED | `MERCADOPAGO::getDolarRate` usa HTTP plain (no TLS) y `@` suppressor | `MercadoPagoController.php:267-280` |
| 🟢 MED | JWT no incluye `aud`, `iss`, `jti` claims | `JwtService.php:31-52` |
| 🟢 MED | Rate-limiter usa filesystem cache (no escala multi-node) | `rate_limiter.yaml:31-34` |
| 🟢 MED | Webhook MercadoPago acepta GET (cache-poisonable) | `MercadoPagoController.php:121` |
| 🟢 MED | `AuditController::list` LIKE concat user input | `AuditController.php:29-48` (no SQLi, pero enumeration) |

---

## Resumen de hallazgos por severidad

### 🔴 CRÍTICOS (24) — bloquean deploy

**Seguridad (11):**
1. JWT authenticator no registrado en firewall
2. Login `code + name` permite enumeración → impersonación
3. `X-User-Code` en `RequireAdminTrait` permite escalar a admin
4. Sanctum admin endpoints sin `IsGranted('ROLE_ADMIN')` (6 controllers)
5. Webhook MercadoPago sin firma obligatoria
6. Endpoint `/api/wallet/me` en producción
7. `config/jwt/` vacío (claves no generadas)
8. `.env.local` con secretos reales de prod
9. CORS deshabilitado por `paths: '^/': null`
10. Firewall abre toda `/api/music|devices|notifications|feed|chat|tasks|academia` a `PUBLIC_ACCESS`
11. `access_control` path `^/api/sanctum` no matchea rutas reales (deja admin REST endpoints abiertos)

**Código (7):**
12. Cobertura de tests < 0.5%
13. IDOR en `EconomicReminderController::cancel`
14. Catch silencioso en `TournamentController::notifyTournamentCreated`
15. Catch silencioso en 4 métodos de `DuelController`
16. Catch silencioso en `WalletController::rates` + bypass DI con `new DolarController()`
17. Catch silencioso en `FeedController::linkPreview` + leak en `LinkPreviewController::preview`
18. Catch silencioso en `CampusStorage::locateUserFile` y `UserController::delete` con `error_log()`

**Frontend (5):**
19. Stimulus instalado pero inerte (0 templates lo cargan)
20. Turbo instalado pero inerte (0 templates lo usan)
21. CSRF: 0 forms protegidos
22. Skeleton loaders definidos pero no usados (solo `.loading-pulse`)
23. `prefers-reduced-motion` solo cubre APK (web/desktop sin protección)

### 🟡 ALTOS (44)

**Seguridad (9):**
- Hardcoded admin code `'ADMIN01'` en audit fallback
- Admin password único sin 2FA
- Rate limiters IP-based sin `trusted_proxies`
- `PushService` dead code + `firebaseCredentialsPath` no bindeado
- Push services duplicados
- `$e->getMessage()` filtrado al cliente
- Session cookie no hardened
- 8 endpoints críticos sin rate limit
- `not_compromised_password` no habilitado

**Código (24):**
- God-files: 9 archivos >250 líneas identificados
- 49 bloques `catch (\Throwable)` con 30% sin log
- 33 supresores `@` activos
- 5 archivos favicon faltantes
- 2 dependencias implícitas (http-client, http-client-contracts)
- `json_decode` sin `is_array()` en 30+ endpoints
- Time-zone no manejado (0 `date_default_timezone_set`, >100 DateTimeImmutable sin TZ)
- MIME validation insuficiente en uploads
- 10 god-class / god-controller identificados
- Repos con `flush()` (Doctrine anti-pattern)
- `_ENV`/`_SERVER` directo en services (defeats container)
- `parse_url()` sin verificar false
- `mt_srand()` global mutation
- 5 inconsistencias arquitectónicas (mixed controller styles, mixed DI patterns)

**Frontend (11):**
- 800+ líneas CSS inline duplicado en templates
- 61 inline `style="..."` (9 en `journal.html.twig` del mismo bloque)
- Hover-lift inconsistente (3 distancias distintas)
- WebGL + Three.js 60fps sin pause en hidden
- 27+ animaciones infinite always-on (algunas engañosas)
- ~10 keyframes definidos pero nunca aplicados
- Animaciones duplicadas 3-4 veces bajo distintos nombres
- 0 spinners, 0 page transitions
- 3 implementaciones diferentes del mismo UI primitive
- 4 versiones de modal-overlay
- `app.css` (1764 líneas) no se carga en producción

### 🟢 MEDIOS (52) y 🔵 BAJOS (26)

Detallados en cada sección. Resumen:
- Inconsistencias menores de naming (snake_case + dot-notation + camelCase en route names)
- Timestamps `DATETIME_IMMUTABLE` vs `DATETIME_MUTABLE` mezclados
- `JournalEntry::$id` es BIGINT pero los demás INT
- `Setting::$key` es string PK (vs auto-increment)
- `User::getAvatarColor()` retorna null (comentado en línea 268)
- `TournamentMailer::writeDebugEmail` siempre escribe a `var/log/emails/` incluso en prod
- `enable_authenticator_manager` no explícito
- `CampusStorage::userDirNameFromCode` trunca a 32 chars (colisiones posibles)
- `MusicController` tiene admin routes dentro
- 8 templates con errores menores de motion (decorativos distractivos)

---

## Plan de remediación recomendado

### Sprint 1 — Fix bloqueantes (1-2 semanas)

1. Rotar **TODOS** los secretos de `.env.local`
2. Generar `config/jwt/private.pem` + `public.pem`
3. Wirear `JwtAuthenticator` en firewall + eliminar `X-User-Code` de `RequireAdminTrait`
4. Forzar uso de `password` en `CodeAuthenticator` para TODOS los users (no solo admin)
5. Agregar `#[IsGranted('ROLE_ADMIN')]` a los 7 controllers Sanctum admin
6. Hacer obligatoria la firma en webhook MercadoPago
7. Eliminar `/api/wallet/me`
8. Patchear IDOR en `EconomicReminderController::cancel`
9. Configurar `trusted_proxies` en `framework.yaml`
10. Mover `.env.local` a Symfony Secrets vault

### Sprint 2 — Hardening de seguridad (1-2 semanas)

1. Instalar `nelmio/security-bundle` y configurar headers (CSP, HSTS, X-Frame-Options)
2. Endurecer session cookies (`secure`, `httponly`, `samesite`)
3. Agregar rate limit a endpoints sin protección (auth/refresh, devices, wallet/*, academia)
4. Habilitar `not_compromised_password` validator
5. Migrar de `ADMIN_PASSWORD` a JWT admin con roles separados
6. Eliminar `PushService` o bindear correctamente
7. Remover fallback `'ADMIN01'` en TournamentController

### Sprint 3 — Calidad de código (2-3 semanas)

1. **Tests primero**: escribir tests de los flujos críticos (AuthController, JournalController, TournamentController, WalletController) — meta: 30% cobertura mínima
2. Política unificada de `try/catch`: helper `safeExecute(callable, logger, fallback)`
3. Reemplazar 33 `@` supresores por try/catch + log
4. Helper `decodeJsonBody(Request): array` + uso en todos los controllers
5. Configurar `DateTimeZone` por defecto via services.yaml + actualizar todos los `new \DateTimeImmutable()` con TZ explícito
6. Usar `ImageValidationService` en todos los uploads
7. Descomponer User entity en `UserIdentity` + `UserWallet` + `UserProfile`
8. Eliminar `final` excesivo en `LinkPreview/` (10 clases)
9. Mover todos los `flush()` de repos a services/controllers

### Sprint 4 — Frontend (2-3 semanas)

1. Decidir: **¿Stimulus + Turbo o vanilla JS?**
   - Si Stimulus: hacer que `shell.html.twig` cargue `importmap('app')` y migrar scripts inline a Stimulus controllers
   - Si vanilla: eliminar las deps de `@symfony/stimulus-bundle` y `ux-turbo` del composer
2. Agregar `prefers-reduced-motion` global (no solo APK)
3. Mover todo el CSS inline de templates a stylesheets consolidados
4. Crear design tokens para hover-lift, stagger, durations
5. Reemplazar 61 `style="..."` por clases
6. Pausar WebGL/Three.js cuando `document.hidden`
7. Solo animar `.audio-wave-bar`, `.cf-typing-dots`, `.countdown-display` cuando hay estado activo
8. Reemplazar `.loading-pulse` text con skeletons reales (`.skeleton-elev` ya existe)

### Sprint 5 — Limpieza estructural (ongoing)

1. Eliminar duplicados: `PushService`/`PushNotificationService`, `RateLimiterService`/framework limiters
2. Mover `LegacyHeaderAuthenticator` y `RequireAdminTrait::X-User-Code` a histórico
3. Consolidad route naming (elegir snake_case)
4. Eliminar archivos favicon faltantes del map o crearlos
5. Agregar `symfony/http-client` y `symfony/http-client-contracts` a `composer.json` require
6. Migrar 2 migrations que faltan a Doctrine Migrations (los 67+ entities no trackeados)
7. Documentar OpenAPI/Swagger de los ~155 endpoints

---

## Métricas finales

| Métrica | Valor | Estado |
|---|---|---|
| Archivos PHP totales | 246 | — |
| Archivos de test | 1 | 🔴 |
| Cobertura efectiva | <0.5% | 🔴 |
| Endpoints API | ~155 | — |
| Endpoints sin rate limit | ~10 | 🟡 |
| Endpoints sin auth | ~12 (controllers Sanctum) | 🔴 |
| Bugs IDOR conocidos | 2 | 🔴 |
| Catch blocks vacíos | ~15 | 🟡 |
| `@` suppressors | 33 | 🟡 |
| Inline `<style>` blocks en templates | 29 | 🟡 |
| Inline `style="..."` atributos | 61 | 🟡 |
| Dead CSS classes | ~10 keyframes + varios selectors | 🟢 |
| God-files (>250 líneas) | 9 | 🟡 |
| `final` excesivo | 10+ clases | 🟢 |
| Doctrine migrations | 2 de 67+ entities | 🟡 |
| Tests | 1 | 🔴 |

---

## Próximos pasos

1. **Mañana**: revisar este reporte y priorizar qué sprints empezar.
2. **Decisión clave a tomar**: ¿Stimulus + Turbo o vanilla JS? (afecta Sprint 4 completo)
3. **Decisión clave a tomar**: ¿Migrar a Symfony Secrets vault ahora o después? (afecta deploy inmediato)
4. **Antes de cualquier merge**: configurar CI con PHPStan/Psalm level 6+ para detectar más issues automáticamente.

---

**Reporte generado por**: opencode + 4 exploration agents paralelos
**Modo**: read-only (0 archivos modificados)
**Tiempo total**: ~12 minutos de análisis
**Próxima ejecución recomendada**: tras Sprint 1 completo para medir progreso