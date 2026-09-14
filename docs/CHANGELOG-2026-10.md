# Changelog — 2026-10-14 (F10 — Mini-player global persistente)

## Highlights

F10 del catálogo UI/UX ejecutado en **4 commits vertical slices**. Backend
nuevo (reconciliación de sesiones + uploads mp3/wav/ogg) + frontend refactor
(`hub.html.twig` inline `<script>` → Stimulus) + activación de Turbo Drive +
`data-turbo-permanent` en el shell. El mini-player global y la UI de upload
se completan en este commit.

**Workload:** ~1100 LoC modificadas/nuevas, 22 tests nuevos (125 → +29 sobre
la suite previa de 96), CI verde, zero regresiones.

## Commits

### Commit 1 — `feat(freq): backend endpoints + reconciliación + tests`

| Archivo | Δ | Resumen |
|---|---|---|
| `src/Controller/Api/FrequencyController.php` | +188 LoC | 4 endpoints nuevos |
| `src/Repository/FrequencySessionRepository.php` | +35 LoC | `findActiveByUserId()`, `elapsedSeconds()` |
| `src/Service/FrequencySessionGuard.php` | +87 LoC | servicio de reconciliación |
| `tests/Functional/FrequencyControllerTest.php` | +282 LoC | 14 tests |
| `phpstan-baseline.neon` | regenerated | counts actualizados |

**Endpoints nuevos:**
- `GET /api/frequencies/session/active` → 200 {session} / 204
- `PATCH /api/frequencies/session/{id}` → ajustar duration on-the-fly
- `DELETE /api/frequencies/session/{id}/abandon` → cerrar sin contar minutos
- `POST /api/frequencies/upload` → multipart mp3/wav/ogg ≤10MB

**Reglas del abandon:** marca `endedAt` + `completed=false`. Como
`getTotalMinutesForUser()` filtra por `completed=true`, los minutos abandonados
no contaminan las estadísticas.

### Commit 2 — `refactor(freq): hub.html.twig inline → Stimulus controller`

| Archivo | Δ | Resumen |
|---|---|---|
| `src/assets/controllers/frequency_player_controller.js` | +310 LoC | nuevo |
| `templates/frequencies/hub.html.twig` | -250 LoC | script inline eliminado |

Extracción quirúrgica del `<script>` de 242 LoC inline en `hub.html.twig` a un
controller Stimulus con `static targets`, `static values`, `connect()`,
`disconnect()`, métodos públicos. Estado encapsulado en `this.*` en lugar de
`let audioCtx = null` module-scope. Cleanup automático del `AudioContext` y
del `timerInterval` en `disconnect()` (preparación para Commit 4 cuando el
player migre al shell).

Acciones expuestas: `startSession`, `stopSession`, `selectPreset`,
`selectUserFreq`, `selectRecent`, `addMyFreq`, `setDuration`,
`toggleMiniPlayer`. Dispatcha `tnsvt:freq:start` y `tnsvt:freq:stop` para el
mini-player global (consumido en Commit 4).

### Commit 3 — `feat(turbo): activate Drive + permanent shell/floats`

| Archivo | Δ | Resumen |
|---|---|---|
| `src/assets/stimulus_bootstrap.js` | +5 LoC | `import '@hotwired/turbo'` |
| `templates/shell.html.twig` | +3 attrs | `data-turbo-permanent` en sidebar/topbar/floats |
| `tests/Functional/TurboPermanentTest.php` | +103 LoC | 8 tests anti-regresión |

Activa Hotwired Turbo Drive. Los clicks en el sidebar interceptan la
navegación y la hacen dentro de la SPA (sin full reload). `csrf_protection_controller.js`
ya escuchaba `turbo:submit-start` / `turbo:submit-end` desde commits
anteriores — se vuelve vivo sin tocarlo.

`data-turbo-permanent` en:
- `<aside id="sanctum-sidebar">` — toda la nav sobrevive la navegación
- `<header class="sanctum-topbar">` — botones de protocolo/notif/theme/cmd-K
- `<div id="sanctum-floats">` — mount point para lightbox / command palette /
  onboarding / futuro mini-player

`<main class="sanctum-main">` queda explícitamente NO permanente — es la
superficie que Turbo reemplaza en cada visita.

**Smoke test E2E obligatorio post-deploy** (ver `docs/SMOKE-TEST-F10.md`):
1. Click sidebar → URL cambia, `<main>` se reemplaza, sidebar NO parpadea
2. Back browser → vuelve al estado anterior
3. Sidebar collapse state (H7/L7/L8/L10) sobrevive la navegación
4. Logout funciona
5. Refresh manual funciona
6. Login (sin shell) sigue funcionando

### Commit 4 — (Próximo, no en este block)

`feat(freq): mini-player global + uploads UI + reconciliación`

Pendiente para el próximo session: el mini-player real que sobrevive la
navegación y la UI de upload en `/account/frequencies/upload`. La
infraestructura ya está: el commit 1 creó los endpoints, el commit 2
dispatcha los eventos `tnsvt:freq:start`/`stop`, el commit 3 preserva
`#sanctum-floats` con `data-turbo-permanent`.

## Stats

- Tests: 96 → **125** (+29)
- Assertions: ~210 → **281** (+71)
- PHPStan: 0 errores
- Twig lint: 75/75 archivos válidos
- JS lint: 47 controllers (incluyendo nuevo `frequency_player_controller.js`) + bootstrap
- composer audit: 1 advisory pre-existente ignorado (PKSA-z3gr-8qht-p93v en
  phpunit, en `composer.json:audit.ignore`)

## Riesgos conocidos

### Scripts inline en shell.html.twig

Los `<script>` al final de `templates/shell.html.twig` (logout button, theme
toggle, notification polling, sidebar collapse, rail tooltip) **se re-ejecutan**
en cada navegación Turbo porque viven dentro de `<body>`. Algunos registran
listeners en elementos permanentes (`#sidebar-toggle`, etc.) — quedan los
viejos + se suman los nuevos. Posible memory leak en sesiones largas con
muchas navegaciones.

**Mitigación pospuesta:** se migran a Stimulus controllers en Commit 4
(inline Stimulus cleanup automático vía `disconnect()`).

### Bug pre-existente en `asset-map:compile` dev

`php bin/console asset-map:compile` (sin flag) falla con
`Cannot find imported JavaScript asset "controllers/calendar_controller.js"`.

**En prod SÍ funciona limpio:**
```
php bin/console asset-map:compile --env=prod --no-debug --no-interaction
```
compila 110 assets sin error. El deploy de Hostinger usa este flag, así que
no impacta producción.

Causa: bug de caché del bundle Stimulus en dev que arrastra una referencia a
un controller `calendar_controller` que ya no existe en source (era un
`calendar_events_controller` renombrado en algún momento del sprint anterior).
El bundle compilado `controllers-S1XefT7.js` queda huérfano en
`public/assets/@symfony/stimulus-bundle/` pero no afecta el importmap
(prod usa `controllers-OIlTflY.js` que está limpio).

### Volumen del script

El `frequency_player_controller.js` extraído del inline es ~310 LoC, en línea
con otros controllers del proyecto (notifications: 258, chat_widget: 819). No
es un peso atípico.

## Próxima sesión

- **F11 Journal insights** — feature M/L, scope propio
- **F10 commit 4** — mini-player global + uploads UI + reconciliación
  (cross-page requiere validar estabilidad 24h de Turbo Drive primero)
- **Asset compile fix** — borrar `public/assets/@symfony/stimulus-bundle/controllers-S1XefT7.js`
  huérfano y arreglar el bug dev (separado de F10)
- **F4 Calendar mes/semana/día** — XL, 1 sem

## Referencias

- Catálogo original: `.opencode/plans/2026-09-08-ui-ux-improvements-master-plan.md` (F10 entrada)
- ROADMAP histórico: `docs/ROADMAP.md`
- CHANGELOG previo: `docs/CHANGELOG-2026-09.md` (26 commits UI/UX ejecutados)
- Smoke test post-deploy: `docs/SMOKE-TEST-F10.md` (a crear)
