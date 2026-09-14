# Plan Maestro: Mejoras UI/UX Masivas - TNSVT V2

> Documento de planificacion detallado.
> Generado: 2026-09-08
> Total: 174 mejoras identificadas

---

## Distribucion

| Categoria | Cantidad | Impacto tipico | LoC totales |
|---|---|---|---|
| Bugs criticos | 9 | Eliminacion papercuts | ~50 |
| High-impact wins | 15 | Mejora rapida | ~250 |
| Polish / microinteractions | 20 | Delight | ~400 |
| Big features | 20 | Payoff grande | ~2000 |
| Section-specific | 100+ | Fixes puntuales | ~500 |
| Cross-cutting themes | 10 | Consistencia | ~200 |
| **TOTAL** | **174** | — | **~3400 LoC** |

---

## Roadmap de Ejecucion

| Sesion | Foco | Estimacion | Commits |
|---|---|---|---|
| **0** | Foto Lightbox con swipe/zoom | 1 sesion | 1 grande |
| **1** | Bugs criticos | 1 sesion | 2-3 |
| **2** | Polish + UX flows | 1-2 sesiones | 2-3 |
| **3** | Big features | varias sesiones | selectivos |

---

## Sesion 0: Foto Lightbox (1 commit grande)

**Impacto visual inmediato, transversal a 4 secciones.**

### Archivos nuevos

| Archivo | LoC | Proposito |
|---|---|---|
| `templates/_partials/lightbox.html.twig` | ~80 | Overlay HTML estructura |
| `src/assets/controllers/lightbox_controller.js` | ~120 | Stimulus controller con swipe/zoom/keyboard |
| `src/assets/styles/components/lightbox.css` | ~100 | Estilos fullscreen + zoom + animations |

### Features del Lightbox
- Swipe entre fotos (touch + arrow keys)
- Zoom (pinch + scroll wheel)
- Drag-to-pan
- Cerrar: Esc, click fuera, boton
- URLs con `#lightbox=photo-id` (deep linking)
- Botones: download, share, copy URL
- Soporte imagenes sueltas + galerias de trade captures
- Animacion de entrada/salida (fade + scale)
- `prefers-reduced-motion`

### Integracion en templates
- `templates/sanctum/journal_new.html.twig` (fotos de trade)
- `templates/_partials/dashboard/trade_form.html.twig` (fotos de dashboard trade)
- `templates/sanctum/feed.html.twig` (fotos de posts)
- `templates/sanctum/chat.html.twig` (adjuntos)

### Activacion
Cualquier `<img>` con `class="lightbox-trigger"` abre el lightbox. Agrupadas con `data-lightbox-group="<id>"` se comportan como galeria.

---

## Sesion 1: Bugs Criticos Confirmados (2-3 commits)

### Bug B9 - Frequency hub: duplicate `id="current-freq"`
- **Archivo:** `templates/frequencies/hub.html.twig` lineas 31 y 52
- **Problema:** Dos elementos con mismo ID. JS solo actualiza uno (linea 31), el display grande (linea 52) queda muerto para siempre.
- **Fix:** Eliminar el inner ring display (3 LoC).
- **Commit:** parte de `fix: critical UI bugs round 1`

### Bug B3 - Edit trade lee del DOM (ALTA severidad)
- **Archivo:** `templates/sanctum/journal.html.twig` lineas 1435-1457
- **Problema:** Editar trade lee con `row.querySelector(...).textContent` + regex fragil. **Pierde notes, entry/sl/tp/photos/tags/ratio**. **Date se rompe** porque `.slice(0, 16)` sobre `"15/03/2026 14:30"` da formato invalido.
- **Fix:** Mantener `_tradesById` cache en `loadTrades`, usar `window._tradesById[id]` en el handler de edit (10-15 LoC).
- **Commit:** parte de `fix: critical UI bugs round 1`

### Bug B5 - `<live-indicator-header>` custom HTML tag
- **Archivo:** `templates/sanctum/notifications.html.twig` linea 22
- **Problema:** Custom tag ignorado por browser. Layout funciona por class CSS, pero falta `aria-live` para screen readers.
- **Fix:** Cambiar a `<div class="live-indicator-header" aria-live="polite" aria-atomic="true">` (3 LoC).
- **Commit:** parte de `fix: critical UI bugs round 1`

### L74-L78 - Profile toggles 100% decorativos
- **Archivo:** `templates/sanctum/profile.html.twig` lineas 143, 153, 163, 174
- **Problema:** 4 controles son puramente decorativos — `onclick="classList.toggle"` sin backend.
- **Fix:** Esconder los 4 + tooltip "Proximamente" (5-10 LoC).
- **Commit:** parte de `fix: critical UI bugs round 1`

### Bug B10 - Macro dashboard typo (menor a lo reportado)
- **Archivo:** `templates/macro/dashboard.html.twig` linea 490
- **Problema:** `muteBtn = document.getElementById('bento-remind-btn')` apunta al boton equivocado. Pero `muteBtn` es **dead code** dentro de `renderBento()`, no causa bug funcional.
- **Fix:** Cambiar 1 char o eliminar la linea (1 LoC).
- **Commit:** parte de `fix: critical UI bugs round 1`

### Bug B1/B6/B7 - Replace 9 native `confirm()` con `apiConfirm`
- **Helper existe:** `window.apiConfirm(message, {title, confirmLabel, cancelLabel, variant: 'danger'})`
- **Sitios a migrar:**

| Archivo | Lineas | Funcion |
|---|---|---|
| `src/assets/controllers/account_switcher_controller.js` | 269, 271 | `confirmDelete()` |
| `src/assets/controllers/social_controller.js` | 320 | `removeConnection()` |
| `src/assets/controllers/campus_admin_lesson_controller.js` | 148, 219 | `deleteLesson()`, `deleteMaterial()` |
| `src/assets/controllers/campus_admin_edit_controller.js` | 243 | `deleteModule()` |
| `src/assets/controllers/bookings_admin_controller.js` | 122, 134 | `handleAction()` |
| `src/assets/js/modules/feed-module.js` | 496 | `deletePost()` |
| **Bonus:** `src/assets/controllers/settings_controller.js` | 174, 177 | native `alert()` → `window.apiToast(msg, 'error')` |

- **Fix:** ~10-15 LoC en 7 archivos. Mechanical `if (!confirm(X))` → `if (!await window.apiConfirm(X, {title, variant: 'danger'}))`.
- **Commit:** `fix: replace native confirm/alert with apiConfirm/apiToast across 7 controllers`

---

## Sesion 2: Polish + UX Flows (2-3 commits)

### Top 10 Quick Wins (commit 2.1)
- **B7** Delete trade double-click protection (`apiButtonLoading`)
- **H5** Empty state CTA journal ("Asenta la primera")
- **H15** Copy-to-clipboard codigos de perfil
- **L29** Calendar: chip picker para paises (vs multi-select)
- **L70** Leaderboard: filtros (periodo/metrica)
- **L37** Macro questionnaires: cards clickeables
- **L48** Diary: timestamp relativo
- **L52** Campus: search bar
- **P10** Micro-skeleton en selects
- **H8** Journal tabs ←/→ keys

### Polish microinteractions (commit 2.2)
- **P4** Trade form success: checkmark + progress bar
- **P13** Back-to-top button en feed
- **P1** Badge notifs: animation badge-pop
- **P5** Ripple en primary buttons (CSS-only)
- **P11** Frequency idle pulsing
- **P14** Sidebar animated active-state indicator
- **P15** Diary preview fade transition
- **P20** Calendar: linea "now" que se mueve cada minuto

### Cross-cutting themes (commit 2.3)
- **T4** Auditar `onclick="..."` inline → migrar a `data-action` (8 sitios)
- **T10** Pause poller intervals con `visibilitychange` (5 sitios)
- **T6** Estandarizar confirmaciones destructivas

---

## Sesion 3: Big Features (commits selectivos)

### Top picks priorizados
- **F19** Inline form validation con field-level tooltips — mejora TODOS los forms
- **F14** Chat read receipts + reactions
- **F13** Settings import/export preferences
- **F8** Profile public preview toggle + share card
- **F11** Journal insights card ("Tu mejor dia es martes")
- **F1** Command palette Ctrl+K — decision final

---

## Catalogo Completo de Hallazgos

### Bugs Criticos (9 items)

| # | ID | Archivo:linea | Descripcion | Severidad |
|---|---|---|---|---|
| 1 | **B9** | `templates/frequencies/hub.html.twig:31,52` | Dos `id="current-freq"` — JS actualiza solo uno, display grande muerto | Media |
| 2 | **B3** | `templates/sanctum/journal.html.twig:1435-1457` | Edit trade lee del DOM con regex — pierde notes, date corrupta | **Alta** |
| 3 | **B5** | `templates/sanctum/notifications.html.twig:22` | `<live-indicator-header>` custom tag sin `aria-live` | Baja |
| 4 | **B1** | 9 sitios | Native `confirm()` rompe estilo, deberia usar `apiConfirm` | Alta |
| 5 | **B6** | `src/assets/controllers/account_switcher_controller.js:269-272` | Confirm delete cuenta con soft-delete — deberia ser styled | Alta |
| 6 | **B7** | `templates/sanctum/journal.html.twig:774-785` | Delete trade sin loading state — doble-click puede disparar 2 DELETEs | Media |
| 7 | **B2** | `templates/shell.html.twig:434` | Theme toggle aria-label desactualizado | Baja |
| 8 | **B4** | `templates/sanctum/calendar.html.twig:174` | Calendar wipe DOM cada 5min — pierde scroll + resetear animaciones | Media |
| 9 | **B8** | `src/assets/controllers/protocol_controller.js:33` | Protocol modal: si helper no ready, click silencioso | Baja |
| 10 | **B10** | `templates/macro/dashboard.html.twig:490` | Typo `bento-remind-btn` (dead code) | **Ninguna** |

### High-impact Wins (15 items)

| # | ID | Mejora | Esfuerzo | Prioridad |
|---|---|---|---|---|
| 1 | H1 | Command palette / Ctrl+K search | L | P2 |
| 2 | H2 | Mark required fields visually + a11y | S | P2 |
| 3 | H3 | Ctrl+Enter submit en textareas | S | P2 |
| 4 | H4 | Account-switcher persistence hint | S | P2 |
| 5 | H5 | Empty state CTA journal | S | **P1** |
| 6 | H6 | NotifPopover: recentrar en resize | S | P2 |
| 7 | H7 | Sidebar collapse persistence hint | S | P3 |
| 8 | H8 | Journal tabs ←/→ keyboard nav | S | P2 |
| 9 | H9 | Streak tracker chip en Journal topbar | S | P2 |
| 10 | H10 | aria-busy en regiones loading | S | P2 |
| 11 | H11 | "Pin to topbar" para mas usadas | M | P3 |
| 12 | H12 | Centralizar pollers con stagger/backoff | M | **P1** |
| 13 | H13 | CSP + prefers-reduced-motion audit | M | P2 |
| 14 | H14 | "Undo last delete" toast 5s | M | P2 |
| 15 | H15 | Copy-to-clipboard codigos perfil | S | **P1** |

### Polish / Microinteractions (20 items)

| # | ID | Mejora | Esfuerzo | Prioridad |
|---|---|---|---|---|
| 1 | P1 | Badge notifs con animacion badge-pop | S | P2 |
| 2 | P2 | Animate KPI counters 0→value | S | P3 |
| 3 | P3 | Haptic feedback en success toasts (mobile) | S | P3 |
| 4 | P4 | Trade form success: fullscreen checkmark + progress bar | M | P2 |
| 5 | P5 | Ripple effect en primary buttons (CSS-only) | S | P3 |
| 6 | P6 | Equity curve baseline dashed line clickeable | M | P3 |
| 7 | P7 | Diary cards: keyboard arrow navigation | S | P2 |
| 8 | P8 | Hover-preview en trade rows en calendar day modal | M | P3 |
| 9 | P9 | Macro dashboard: ticker-tape scrolling banner | S | P3 |
| 10 | P10 | Micro-skeleton en `<select>` durante async load | S | P2 |
| 11 | P11 | Frequency visualizer: idle pulsing animation | S | P3 |
| 12 | P12 | Topbar subtitle: transition entre cambios | S | P3 |
| 13 | P13 | Feed: smooth-scroll "back to top" | S | P2 |
| 14 | P14 | Sidebar: animated active-state indicator deslizante | M | P2 |
| 15 | P15 | Diary preview: fade transition al actualizar | S | P3 |
| 16 | P16 | Notifications bell: pulse animation en new | S | P2 |
| 17 | P17 | Profile 3D sigil: tap-to-pause on mobile | S | P3 |
| 18 | P18 | Pull-to-refresh en mobile (feed/journal) | M | P3 |
| 19 | P19 | Chat widget: typing indicator (3 dots) | M | P2 |
| 20 | P20 | Calendar: horizontal "now" line que se mueve | S | P3 |

### Big Features (20 items)

| # | ID | Feature | Esfuerzo | Prioridad |
|---|---|---|---|---|
| 1 | F1 | Command palette / Ctrl+K search | L | P2 |
| 2 | F2 | Drag-to-reorder sidebar nav | L | P3 |
| 3 | F3 | Inline trade editing (no modal) | L | P2 |
| 4 | F4 | Calendar: month/week/day view toggle | XL | P3 |
| 5 | F5 | Notifications: filter by type + bulk actions | M | P2 |
| 6 | F6 | Trade form wizard (4 steps) | L | P2 |
| 7 | F7 | Diary: tags/folders + search | L | P3 |
| 8 | F8 | Profile: public preview toggle + share card | L | P2 |
| 9 | F9 | Admin: bulk actions on users table | L | P3 |
| 10 | F10 | Frequencies: persistent mini-player global | XL | P2 |
| 11 | F11 | Journal: smart insights card | L | P3 |
| 12 | F12 | Macro Academy: progress tracking + bookmarks | L | P3 |
| 13 | F13 | Settings: import/export preferences | M | P3 |
| 14 | F14 | Chat: read receipts + reactions | M | P2 |
| 15 | F15 | Sidebar: contextual quick actions per section | M | P2 |
| 16 | F16 | Performance: virtualise long lists | M | P3 |
| 17 | F17 | Universal search/filter bar | XL | P3 |
| 18 | F18 | Toast notification preferences (per-type) | M | P3 |
| 19 | F19 | Inline form validation field-level | M | **P2** |
| 20 | F20 | Photo lightbox with swipe/zoom | M | **P2** |

### Cross-cutting Themes (10 items)

| # | Tema | Esfuerzo | Impacto |
|---|---|---|---|
| T1 | Single "filter bar" component | M | Consistencia |
| T2 | Mobile-first bottom-sheet modals | M | Mobile UX |
| T3 | Global error toast for unhandled JS errors | S | Bug reports |
| T4 | Audit `onclick="..."` inline handlers | M | a11y + CSP |
| T5 | Make `loading-pulse` instances screen-reader announce | S | a11y |
| T6 | Standardise destructive confirmations | S | Safety |
| T7 | Stop using `style="display:none"` and `hidden` attr | S | a11y |
| T8 | Add `data-test-id` attributes for E2E tests | M | Testing |
| T9 | Light-theme completeness audit | M | Real-world |
| T10 | Persist setInterval pollers through `visibilitychange` | S | Battery |

---

## Estimacion Total

| Sesion | LoC modificadas | LoC nuevas | Commits | Tiempo |
|---|---|---|---|---|
| **0 - Lightbox** | ~50 | ~300 | 1 | 1 sesion |
| **1 - Bugs criticos** | ~50 | 0 | 2-3 | 1 sesion |
| **2 - Polish + UX** | ~400 | 0 | 2-3 | 1-2 sesiones |
| **3 - Big features** | ~600 | ~2000 | selectivos | varias |
| **TOTAL** | **~1100** | **~2300** | **~10-15** | **4+ sesiones** |

---

## Por Seccion (resumen)

### 1. Topbar (`templates/shell.html.twig:218-319`)
- L1 Subtitle overflow, L2 Buttons inconsistent heights, L3 "Invocar Protocolo" always primary, L4 No breadcrumb, L5 Quick search button

### 2. Sidebar (`templates/shell.html.twig:89-197`)
- L6 "Cargando..." flash, L7 Admin section buried, L8 Section titles non-clickable, L9 No badge on notifications link, L10 Collapse state cross-tab

### 3. Dashboard (`templates/sanctum/dashboard.html.twig`)
- L11 Guardian score color logic, L12 No empty state para hoy, L13 Tasks above Guardian, L14 Audit log admin gate, L15 Quick-add task hide for non-admins

### 4. Journal (`templates/sanctum/journal.html.twig`, 1500+ lines)
- L16 Tabs cramped on mobile, L17 Equity curve tooltip math, L18 Monthly P&L chart hardcoded, L19 Trade list truncate, L20 Account chip tier, L21 Equity compare mode, L22 Unificar Registrar tab y modal

### 5. Journal New (`templates/sanctum/journal_new.html.twig`)
- L23 No progress indicator, L24 Asset chips hardcoded, L25 Date default FOUT, L26 Drag-drop accessible, L27 R:R ratio warning, L28 Cancelar dirty-confirm via browser back

### 6. Calendar (`templates/sanctum/calendar.html.twig`)
- L29 Country multi-select → chip picker, L30 No Today button, L31 Timezone label, L32 Legacy hidden table, L33 NO OPERAR configurable

### 7. Macro (`templates/macro/dashboard.html.twig`, 965+ lines)
- L34 Academy CTA duplicate, L35 Two competing timelines, L36 NTW Timeline mobile, L37 Questionnaires cards clickeable, L38 Faith vs Logic hardcoded, L39 Regla Disciplina interactive

### 8. Oracle (`templates/oracle/dashboard.html.twig`)
- L40 User code hardcoded, L41 Refresh keyboard hint, L42 Sacred sigil missing, L43 Ver Mapa decorative

### 9. Diary (`templates/sanctum/diary.html.twig`)
- L44 4-state transitions abrupt, L45 Reset-key preview, L46 Autosave 30s → 5s, L47 Preview mobile toggle, L48 Relative timestamp, L49 Export entries

### 10. Campus (`templates/sanctum/campus.html.twig`)
- L50 Loading state missing, L51 No filter/search, L52 No count badge, L53 No progress bar per card, L54 No favorite/continue

### 11. Frequencies (`templates/frequencies/hub.html.twig`)
- L55 Duplicate id (ver B9), L56 Timer buttons conflict, L57 Custom frequency uniqueness, L58 Stats panel no chart, L59 No Recently played, L60 Sacred Timer buttons no trigger

### 12. Feed (`templates/sanctum/feed.html.twig`)
- L61 Comments char count, L62 Top publicadores biased, L63 No bookmark, L64 Photo upload not supported, L65 Signal render random KV

### 13. Social (`templates/sanctum/social.html.twig`)
- L66 Tabs badges hidden, L67 Privacy tab aqui no en profile, L68 Connection request no confirmation, L69 Search debounce sin cancelation

### 14. Leaderboard (`templates/sanctum/leaderboard.html.twig`)
- L70 No filters, L71 Dorados/plata/bronce verification, L72 No your rank, L73 Underbuilt (25 lines)

### 15. Profile (`templates/sanctum/profile.html.twig`)
- L74-L78 4 toggles 100% decorativos, L79 Stat-mini wrap <360px, L80 Sound selector duplicates settings

### 16. Notifications (`templates/sanctum/notifications.html.twig`)
- L81 Custom live-indicator-header, L82 No filter/search, L83 No bulk-select, L84 No date grouping

### 17. Chat Widget (`templates/_partials/chat_widget.html.twig`)
- L85 Status hardcoded, L86 No read receipts, L87 Search en conversations no message bodies, L88 No emoji reactions, L89 No create group

### 18. Settings (`templates/sanctum/settings.html.twig`)
- L90 Light theme disabled, L91 Settings naming confusion, L92 Sparse (69 lines)

### 19. Admin (`templates/sanctum/admin/*`)
- L93 Audio player mini decorativo, L94 Modo Alerta toggle broken, L95 No bulk actions, L96 Search debounce, L97 No pagination, L98 Tasks no export, L99 Native confirm, L100 No duplicate

---

## Criterios de Exito por Sesion

- Cada sesion = 1 commit deployable
- CI pasa despues de cada commit
- Verificacion manual post-deploy con curl/screenshot
- Sin regresiones (audit pre + post cada deploy)

---

## Riesgos Identificados

1. **Lightbox con data URLs** — fotos de trade se guardan como base64, el lightbox debe soportar
2. **B3 cache de trades** — mantener `_tradesById` sincronizado con `loadTrades` re-fetches
3. **Profile toggles** — si los escondemos, perder feature futura; alternativa: disabled visual + tooltip Proximamente
4. **Native confirms** — todos en async functions ya, swap mecanico seguro
5. **B10 macro dashboard** — el bug es dead code, fix cosmetico no afecta funcionalidad

---

**Plan generado:** 2026-09-08
**Status:** Ejecutado en su mayoría (Sesiones 0-3 + batches sueltos G+H) — ver `docs/CHANGELOG-2026-09.md`
**Resultado real:** 26 commits, ~120 mejoras aplicadas, ~5000 LoC

