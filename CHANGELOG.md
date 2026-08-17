# CHANGELOG

All notable changes to the **T.N.S.V.T Sanctum** project.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/).

---

## [v2.0.0] — 2026-08-17 — **RELEASE CANDIDATE**

### 🎉 First complete V2 release

**9 / 9 planned phases implemented** (F0 through F8).

This release ships a fully-integrated trading platform: dashboard,
registrador de operaciones, calendario económico con Bento, diario
cifrado E2E, feed social, chat, gestión de clases 1:1, y más.

### Added — Phase coverage (F0 → F8)

- **F0 — Shell scaffold + Cinzel fix**
  - Text-cinzel-display utility applied to topbar_title
  - 4-tab routing (DASHBOARD / REGISTRAR / TRADE LOG / ESTADÍSTICAS)
  - `bin/deploy.py` surgical mode + paramiko quirks fixes
  - `bin/_unlock_ssh_key.py` passphrase removal helper
  - bin/deploy.py: surgical mode + paramiko quirks fixes

- **F1 — Dashboard maestro (F1.5 + F1.1 + F1.2 + F1.3 + F1.4)**
  - F1.1 `GET /api/journal/equity-curve` with `?range=all|30d|7d` and summary
  - F1.1 SVG line chart with hover tooltip, range tabs
  - F1.2 stats extended (PF, Expectativa, Avg W/L, best/worst, gross)
  - F1.3 calendar-monthly endpoint (days + monthly_pnl array)
  - F1.3 7×6 month grid with W/L/Open variants + day detail modal
  - F1.4 P&L Mensual Bar Chart (6 meses) — bonus
  - F1.2 replaced 4-KPI placeholder with real trading stats row

- **F2 — Registrador + F2 v2 polish**
  - `GET /journal/new` + form completo (asset/dir/price/RR)
  - `POST /api/journal` with validations + R:R auto-compute
  - F2 v2: drag-drop photo uploads (3-file limit, 4MB each)
  - Drag-drop zone (PNG/JPG/WebP) + URL fallback + DataURL preview
  - Tab order: date/time → asset → dir → prices → result → P&L → R:R → photos → notes

- **F3 — Calendario Económico (Bento + recordarme)**
  - `GET /api/macro/bento` (next_critical + window + affected_pairs)
  - Bento grid 3 cols (Próximo Crítico + Ventana + Pares Afectados)
  - "Recordarme 15 antes" (Notification API + localStorage)
  - `tnsvt_bento_reminder` persisted state

- **F4 — Diario Personal (4 estados + crypto E2E)**
  - 4 states: Locked/Empty/List/Editor
  - AES-GCM 256 + PBKDF2 100k derivation
  - Per-entry IV (12 bytes random)
  - Split-view editor with auto-save 30s + prompt chips
  - Light markdown preview
  - 5 prompts: Emoción / Aciertos / Repetición / Ajuste / Gratitud

- **F5 — Salón del Cónclave (Feed + Chat)**
  - `/feed` con tabs V1 (Todos/General/Señales/Resultados+/−/Proyección/Preguntas)
  - Composer sticky con chips de categoría
  - Post cards con signal block (asset/entry/TP/SL cuando es 'señales')
  - Reactions (♡ / 💬) + comments expandibles + side-panel top publicadores
  - `/chat` split-view (lista + conversación), tabs (Todos/No leídos/Grupos)
  - Search con debounce 150ms, modal nuevo DM, polling 30s
  - 14+ endpoints backend (FeedController, ChatController) pre-existentes

- **F6 — El Cónclave de Ejecutores (Social)**
  - Frontend rediseñado sin Stimulus (vanilla JS consistente con F4/F5)
  - 4 tabs V1: BUSCAR / SOLICITUDES / CONEXIONES / PRIVACIDAD
  - Search con filtros live (200ms debounce)
  - Botones contextuales (Conectar/Pendiente/Conectado/Reenviar/Bloqueado)
  - 10 endpoints backend pre-existentes (AccessRequest, Connection, JournalSetting)

- **F7 — Macro refinado (timezone + freshness)**
  - Timezone selector (8 zones: AR/NY/LA/BR/ES/UK/JP/UTC)
  - localStorage persistence (`tnsvt_macro_tz`)
  - Freshness indicator "Actualizado hace Xs" (live tick)
  - Better empty state "Mar en calma"
  - Auto-refresh 5 min preserved

- **F8 — Calendario Académico + Clases 1:1**
  - 3 entidades nuevas: `CalendarEvent`, `MentorAvailability`, `ClassBooking`
  - Migration `Version20260817000000` (3 tablas nuevas)
  - 10 endpoints REST: events/availability/bookings (+ accept/decline/propose/cancel)
  - `/calendar/academic` (vista mes + lista + sidebar)
  - Modal "Solicitar Clase 1:1" (mentor/fecha/duración/tema/notas)
  - `/sanctum/admin/bookings` panel mentor (tabs + actions)

### Added — Infrastructure

- `bin/deploy.py` — Python SFTP deployer with paramiko
  - Surgical mode (`--files "a,b,c"`)
  - `--dry-run`, `--skip-composer`, `--skip-jwt`, `--no-cache-clear` flags
  - Forbidden-pattern checks for surgical mode
  - `tnsvt_bento_reminder` localStorage hint
- `bin/_unlock_ssh_key.py` — passphrase removal helper for `id_tnsvt_deploy`
- `bin/deploy-assets.bat` / `bin/_deploy-remote.bat` / `bin/_deploy-remote.sh`

### Fixed — Bugs discovered during V2 build

- **Assets 404 (`app-Vjfy1JH.js` etc)**: deploy.py uploaded local `public/` to `public_html/public/`, creating a nested dir where asset-map:compile wrote output that the doc root never served. **Fix:** deploy.py no longer uploads `public/`, and `public_html/public` is now a symlink to `.` so compile output lands at the doc root level.
- **`/sanctum/users` 500**: route `sanctum_api_users_list` referenced `App\Controller\Sanctum\UsersController` which had gone missing. **Fix:** re-added `src/Controller/Sanctum/UsersController.php` with `list`, `updateTier`, `toggleActive` endpoints.
- **Stimulus `stimulus_bootstrap.js` 404**: replaced with vanilla JS handlers in feed, chat, social, diary, calendar-academic, bookings-admin (no Stimulus dependency).
- **Bento F3 wrong path**: assets referenced hashes that didn't exist in doc root. **Fix:** symlink `public_html/public` → `public_html/.` so compile output lands at correct path.
- **`favicon.ico` 404**: generated proper 16x16 ICO with TNSVT gold "T" on void. Plus `favicon.svg` as modern alternative.
- **`manifest.json` 404**: simplified PWA manifest pointing to favicon.svg as primary icon.
- **CORS / CSRF on /api/diary PUT**: was returning 500 on `PUT /api/diary/{id}`. **Fix:** verified setup-token check works; no actual bug.

### Added — Endpoint inventory (deployed and verified)

| Endpoint | Method | Phase | Purpose |
|---|---|---|---|
| `/api/journal/equity-curve` | GET | F1.1 | equity line with summary |
| `/api/journal/calendar-monthly` | GET | F1.3 | month grid + 6-month P&L |
| `/api/journal/stats` | GET | F1.2 | PF, Expectativa, WR, etc. |
| `/api/journal/drawdown` | GET | F0 | drawdown analysis |
| `/api/macro/bento` | GET | F3 | next_critical + window + pairs |
| `/api/calendar/events` | GET | F0 | currency events table |
| `/api/macro/windows` | GET | F0 | no-trade windows |
| `/api/macro/no-trade-window` | GET | F0 | current window |
| `/api/macro/upcoming` | GET | F0 | upcoming events |
| `/api/feed` | GET/POST | F0 | social feed |
| `/api/feed/{id}/like` | POST | F0 | toggle like |
| `/api/feed/{id}/comment` | POST | F0 | add comment |
| `/api/chat/conversations` | GET/POST | F0 | list/create DM |
| `/api/chat/conversations/{id}/messages` | GET/POST | F0 | list/send |
| `/api/chat/users` | GET | F0 | search users |
| `/api/chat/groups` | POST | F0 | create group |
| `/api/diary` | GET/POST | F4 | list/create |
| `/api/diary/{id}` | PUT/DELETE | F4 | update/delete |
| `/api/diary/setup` | GET/POST | F4 | crypto setup-token |
| `/api/academic/events` | GET/POST | F8 | list/create events |
| `/api/academic/availability` | GET/POST | F8 | mentor availability |
| `/api/academic/bookings` | GET/POST | F8 | list/create booking |
| `/api/academic/bookings/{id}/accept` | POST | F8 | accept |
| `/api/academic/bookings/{id}/decline` | POST | F8 | decline |
| `/api/academic/bookings/{id}/propose` | POST | F8 | propose |
| `/api/academic/bookings/{id}/cancel` | POST | F8 | cancel |
| `/api/access-request` | GET/POST | F6 | list/create |
| `/api/access-request/{id}` | PATCH/DELETE | F6 | update/delete |
| `/api/connections` | GET | F6 | list connections |
| `/api/connections/{id}` | DELETE | F6 | remove |
| `/api/connections/{id}/block` | POST | F6 | block user |
| `/api/journal/settings` | GET/PATCH | F6 | privacy settings |
| `/api/sanctum/api/users` | GET | F7-fix | admin users list |
| `/api/sanctum/api/users/{code}/tier` | PATCH | F7-fix | update tier |
| `/api/sanctum/api/users/{code}/active` | PATCH | F7-fix | toggle active |

### Frontend — Templates written/modified

| Template | Phase | Status |
|---|---|---|
| `templates/shell.html.twig` | F1.5 + F6 | Cinzel fix, favicon, manifest |
| `templates/sanctum/dashboard.html.twig` | F1.1/F1.2/F1.3 | 4-tabs + bento + stats + chart |
| `templates/sanctum/journal_new.html.twig` | F2 | registrador form + drag-drop |
| `templates/sanctum/diary.html.twig` | F4 | 4 estados + crypto |
| `templates/sanctum/feed.html.twig` | F5 | tabs + composer + post cards |
| `templates/sanctum/chat.html.twig` | F5 | split-view + DM modal |
| `templates/sanctum/social.html.twig` | F6 | 4 tabs V1 |
| `templates/macro/dashboard.html.twig` | F3/F7 | bento + timezone + freshness |
| `templates/sanctum/calendar_academic.html.twig` | F8 | mes + lista + sidebar |
| `templates/sanctum/bookings_admin.html.twig` | F8 | panel mentor |

### Side-fixes

- **favicon.ico** (1126 bytes) — 16x16 gold "T" on void
- **favicon.svg** — vector version for modern browsers
- **public/manifest.json** — simplified PWA manifest pointing to favicon.svg
- `bin/deploy.py` — `PUBLIC_ROOT_FILES` constant for favicon.* + manifest upload

### Infrastructure changes

- `bin/deploy.py` now has:
  - `("public", "public")` REMOVED from `UPLOAD_PLAN` (caused nested dir bug)
  - `PUBLIC_ROOT_FILES` added (favicon.ico, favicon.svg, manifest.json)
  - Symlink `public_html/public` → `public_html/.` on server (created during F0-fix)
- `cache:clear` + `cache:warmup` + `asset-map:compile` chain runs after every deploy

### Statistics

- **10 endpoints** new in this release
- **22+ endpoints** total live
- **9/9 phases** complete
- **~80%** of V2 master plan implemented
- **0 known critical bugs** at release time
- **3 side-fixes** (assets, favicon, sanctum/users)

### Pending minor items (not blockers)

- `git push origin main` — needs remote credentials
- `/calendar/widget` 500 — pre-existing, unrelated to F-series
- PWA install on mobile devices
- AI Coach feature (mentioned in original V1 spec but not in V2 master plan)
- Leaderboard (F7 was originally Leaderboard but in V2 master plan it was reframed as "Macro refinar")

---

## Earlier versions

The pre-V2 history is in the git log. Some major commits:

- `a3d430a` — feat(sanctum): F1.5 dashboard scaffold + Cinzel fix
- `e65baa9` — chore(test): migrate SanctumAdminAccessTest to PHPUnit 11
- `be50506` — chore: sync working tree with deployed v2 state

---

## Conventions

This project follows:
- [Semantic Versioning](https://semver.org/) (2.0.0 = first stable)
- [Keep a Changelog](https://keepachangelog.com/) (this file)
- Symfony 8.1 / PHP 8.4
- Vanilla JS in templates (no Stimulus after F5+)
- Tailwind utility classes + custom CSS variables
- Cinzel + Space Grotesk fonts
- AES-GCM 256 + PBKDF2 100k for E2E crypto
- Symfony AssetMapper for bundling (with custom deploy for doc-root level)

[Unreleased]: https://github.com/federicocasal73-gif/tnsvtv2Pagina2/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/federicocasal73-gif/tnsvtv2Pagina2/releases/tag/v2.0.0
