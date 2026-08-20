# Glow-up global — Fase 0 (Shell/Chrome) + roadmap por secciones

Fecha: 2026-08-20
Alcance aprobado por el usuario:
- Fase 0 + Núcleo trading primero.
- Incluye arreglar botón muerto ("Invocar Protocolo") y sistema de notificaciones.
- Dirección visual: **consistencia sobre el sistema actual** (tokens gold/violet/void existentes).

## Hallazgos de auditoría

1. **"Invocar Protocolo"** (`templates/shell.html.twig:236-239`) es un `<button>` sin `id`, sin
   `data-action` ni handler JS en ningún archivo. Clic → nada.
2. **Campana topbar** (`shell.html.twig:240-243`) tampoco tiene handler; su badge
   `#header-notif-badge` nunca se actualiza (el JS solo contamina `#bell-badge` de la sidebar).
3. **Notificaciones**: backend completo y funcional (`/api/notifications` list/count/read/read-all/delete,
   `NotificationController`). Página `/sanctum/notifications` funciona (Stimulus
   `notifications_controller.js`). El **popover de campana no existe**: `src/assets/js/modules/notifications-module.js`
   (757 líneas) apunta a `#notifPanel`/`#notifBellWrap`/`#notifBellBtn`/`#notifBadge`/`#notifList`
   que **no existen en ninguna plantilla**. Código huérfano, no importado por ningún entrypoint.
4. **No hay Tailwind** en el stack (`composer.json` no tiene tailwindcss-bundle; no hay
   `tailwind.config.*`). Las clases `grid`, `flex`, `mb-4`, `bg-[var(--gold-elev)]`, `text-[var(...)]`,
   `md:grid-cols-3`, etc. que abundan en las plantillas son **inertes** en el navegador. El layout real
   vive en clases custom (`.glass-card-elev`, `.sanctum-link`, `.dashboard-grid`, `.sanctum-*`, `.ec-*`).
   → Deuda de diseño a resolver progresivamente (ver Fase 1+).
5. El shell carga `app.css` legacy vía `importmap('app')` (que a su vez importa `apk-layout-fix.css`,
   `cf-widget.css`, `diary.css`, `music-bar.css`, `topbar.css`, `offline-banner.css`, `social.css`,
   `campus.css`, `mf-module.css`, `notifications.css`) además de los estilos nuevos del shell.
   Peso muerto y riesgo de conflictos (ej. `canvas { position: fixed; }` pisa el webgl-background).

## Fase 0 — Shell / Chrome (ESTA FASE)

### T1 — Modal "Invocar Protocolo" (Guardian)
- Nuevo controller Stimulus `src/assets/controllers/protocol_controller.js` (`protocol`).
- Al abrir: `Promise.all([apiFetch('/api/guardian/signals'), apiFetch('/api/guardian/score')])`.
- Renderiza: score 0-100 + tier (elite/strong/steady/caution/risk), breakdown, señales ordenadas
  por severidad (danger > warning > info) con icono + pill, y CTA si `action_route`.
- Markup del modal dentro del `<header class="sanctum-topbar">` (target del controller), overlay
  fixed con focus-trap vía `apiSetupModal` del helper.
- Cierre: botón ✕, click fuera, Escape (focus-trap del helper ya maneja Escape).
- Estilos en `src/assets/styles/shell.css` (`.protocol-modal`, `.protocol-overlay`, listado de señales).

### T2 — Popover de notificaciones en la campana
- Nuevo controller Stimulus `src/assets/controllers/notification_bell_controller.js` (`notification-bell`).
- Toggle popover; al abrir: fetch `/api/notifications?user_code=<code>` (usa `window.TNSVT_USER`),
  render lista compacta (ícono por tipo, texto, tiempo, marcador NUEVO), "Ver todas →" a
  `/sanctum/notifications`, CTA marcar todas (reutiliza `/api/notifications/read-all`).
- Badge: sync `#header-notif-badge` y `#bell-badge` desde el poll existente (60s) en `shell.html.twig`.
- Estilos en `src/assets/styles/shell.css` (`.notif-popover`, `.notif-popover-item`).

### T3 — Retirar código muerto
- Eliminar `src/assets/js/modules/notifications-module.js` (huérfano, no importado).
- Eliminar referencias legacy a popover de notifs: `#notifPanel`/`#notifBellWrap`/`#notifBellBtn`/
  `#notifBadge`/`.notif-*` en `apk-layout-fix.css` (solo las del popover, no romper layout APK).
- Revisar `app.css` para no cargar `notifications.css` legacy duplicado (se importa por components.css
  con el CSS nuevo de la página).

### T4 — Auditar/depurar CSS legacy en páginas shell (parcial, seguro)
- Verificar qué clases legacy referencian realmente las plantillas actuales (`.app-header`, `.hub-view`,
  `.mc-*`, `.tj-*`, `.signal-*`, `.feed-*`, `#login-screen`, etc.).
- Solo remover lo comprobadamente no usado. El paso fuerte (entrypoint `shell.js` sin `app.css`) se
  deja como follow-up tras la auditoría por sección (Fase 1+) para no romper secciones no migradas.

### T5 — Primitivas compartidas para el nuevo chrome
- Añadir a `src/assets/styles/shell.css` primitivas usadas por modal + popover (overlay, focus states,
  close button, pill severity). No inventar sistema nuevo; solo lo necesario y consistente con tokens.

### Verificación Fase 0
- `php bin/console asset-map:compile`
- Smoke local (login + /sanctum + abrir modal/popover con sesión).
- Commit local (sin push). Deploy + `bin/post-deploy-smoke.sh`.

## Fase 1 — Núcleo trading (siguiente)
- **Dashboard** (`sanctum/dashboard.html.twig`, 1176 líneas): dividir en partials, polish
  equity-curve/tooltip/tabs, empty states, responsive.
- **Journal** (`journal.html.twig` + `journal_new.html.twig`): patrón de sección, empty state, form.
- **Calendario** (`calendar.html.twig` + `calendar_academic.html.twig`): unificar cards/legend/empty/responsive.
- Durante cada sección: reemplazar utilidades inertes de Tailwind por clases reales donde el layout
  lo requiera (spacing, grid, flex) — resolver la deuda del hallazgo #4 de forma incremental.

## Fase 2 — Mente & Macro
Macro · Oráculo · Guardian · Diario · Frecuencias.

## Fase 3 — Comunidad
Feed · Conexiones · Chat · Clanes.

## Fase 4 — Compete
Torneos · Duelos 1v1 · Game · Honor.

## Fase 5 — Wallet & Cuenta
Wallet · Tienda · Perfil · Configuración · Notificaciones.

## Fase 6 — Admin
Usuarios · Tareas · Audit · Monitoring.

## Criterios de calidad por sección (todas las fases)
- Empty states consistentes (`apiEmpty` / `_partials/empty_state.html.twig`).
- Estados de loading y error visibles.
- Responsive: móvil <640, tablet 640-1024, desktop.
- A11y: focus-visible, contraste, aria-labels, focus-trap en modales.
- Commits locales, sin push. Verificación: asset-map:compile + smoke + deploy.py + post-deploy-smoke.sh.
