# Changelog — 2026-09-XX (plan maestro UI/UX ejecutado)

## Highlights

26 commits, ~120 mejoras del catálogo aplicadas, CI verde, deploy
continuo sin downtime. Tocado el front (templates + JS + CSS), un
controller del backend (`ChatController` para reacciones/lectura) y un
controller muerto eliminado.

## Sesiones

### Sesión 0 — Lightbox (`5e29660`)
Photo lightbox con swipe/zoom/pan/teclado, hash routing,
`#lightbox-trigger` + `data-lightbox-group` opcional, integrado en
journal/trade_form/feed/chat.

### Sesión 1 — Bugs críticos (`9d5c41b`, `51acb5d`, `1b2cb01`)
- L9 (duplicate id), L5 (custom tag sin a11y), L10 (dead code)
- B3 fix: edición de trade ya no scrappea DOM — usa `_tradesById`
  cacheado de la API. Setea `date` correctamente sin corrupción.
- Native confirm/alert → apiConfirm/apiToast (7 controllers +
  feed-module).

### Sesión 2 — Polish + UX
- **2.1 quick wins** (`697f5e9`): required fields visual, Ctrl+Enter submit,
  popover resize, sidebar hint, streak chip, L6 sidebar prefill,
  L16 tabs mobile, L25 date default, L62 engagement ranking,
  L66 social badge, L72 your-rank.
- **2.2 forms** (`739e385`): inline form validation con tooltips,
  stepper L23, RR warning, KPI counters animados.
- **2.3 cross-cutting** (`896c44c`): global error handler, SR loading,
  hidden attr, data-test-id E2E, haptic feedback, subtitle fade,
  bell pulse.
- **Microinteractions** (`b7cd04a`): P1, P4, P5, P11, P13, P14, P15, P20.
- **High-impact wins** (`ae2ab25`): H2, H3, H5, H8, H15.
- **Settings import/export** (`b1ce957`): F13 + L49.
- **Auto-refresh transparency** (`b5917ca`): L68, L46, L44, L63.

### Sesión 3 — Big features
- **F1 Command palette** (`be9e4a0`): Ctrl+K, 21 destinos, fuzzy match,
  acciones contextuales detectadas por DOM.
- **F14 chat receipts + reactions** (`dc7618a`): sin migración (metadata JSON
  + lastReadAt existente), allowlist 7 emojis, undo via apiUndoToast.
- **F3 inline trade edit** (`8c459c1`): cache `_tradesById`, edición
  in-place de row, Enter save / Esc cancel, botón "Más" → modal completo.
- **F6 wizard 4 steps** (`e9ea66a`): gates por paso, stepper clickeable.
- **big features batch** (`1496857`): undo toast, F5 notifs filter+bulk, F11 insights.

### Sesión 3 — Loose ends (suites sueltas)
A → F (6 batches: `c554680`, `8fc55af`, `cdba77b`, `a90cbdb`, `4e95012`, `253f511`)
L22: wizard stepper visible + chip + insight card.
L28: back-guard en journal_new. L47: diary mobile write/read toggle.
L69: search abort. L84: notificaciones agrupadas por día.
L87: chat search en bodies. L40: oracle sin hardcode.
L17: equity tooltip sin NaN. P6: baseline clickeable.
L1: topbar subtitle fade. P16: bell pulse. H4: account hint.
H9: streak chip en journal. H10: aria-busy.
L23: stepper. L25: date default. L26: drag-drop accessible.
L36: timeline mobile. P9: macro ticker.
L28: journal_new wizard. L31: tz. L33: NO OPERAR cfg.
L80: stat-mini <360px. L2: topbar heights. L65: signal sort.
P17: sigil tap-pause. L41/42/43: oracle identity + Ver Mapa scroll.
P8: hover-preview day modal. L36 timeline.
L7/8/10: sidebar Admin + sections collapsables + cross-tab sync.
L13/14/15: dashboard order/gates/score.

### Sesión final
- **Batch G** (`2501f00`, `caa1bb9`): L32 calendario legacy muerto
  eliminado (tabla + controller), P19 typing indicator limpia al
  enviar.
- **Batch H** (`c9cce6b`): F18 toast prefs per-type con toggles en
  /account_settings persistidos en localStorage.

## Stats

- Commits del bloque: 26 (incluye hotfixes)
- Tests: 96 → 96 assertions
- PHPStan: 2 errors pre-existentes en `V1ImportCommand` (no toqué)
- Archivos tocados: ~40 templates, ~15 controllers JS, ~25 styles

## Lo que quedó fuera de scope (deliberado)

- **F2, F7, F11, F12**: features M/L de rediseño propio
- **F4, F10, F17**: XL cada uno (semanas de trabajo)
- **F9, L93-L100 admin**: dominio de otro agente
- **T1, T2, T9, H13**: cross-cutting M con regresión visual amplia
- **P18**: pull-to-refresh, payoff bajo

Ver `.opencode/plans/2026-09-08-ui-ux-improvements-master-plan.md`
para el catálogo completo y el mapeo de qué quedó pendiente.
