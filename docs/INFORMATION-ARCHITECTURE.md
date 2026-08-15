# TNSVT Information Architecture

Status: TARGET — must be validated against the actual repository during `/audit` and
`/map`. **Updated after initial audit.**

The information architecture is the contract between the product and its navigation.
A macro is a top-level destination that the user can reach in 1 click from anywhere
in the Sanctum.

---

## 1. The split

TNSVT has two completely different audiences:

1. **Visitors** who land on the public website (anonymous).
2. **Sanctum members** (authenticated: student / mentor / admin).

✅ **Phase 5 done:** split is implemented. `templates/public/shell.html.twig`
serves landing + login. `templates/shell.html.twig` serves the Sanctum app.
Different CSS, different layout. Clean boundary.

---

## 2. Public surface (target)

| Route | Purpose | Template |
|---|---|---|
| `/` | Landing (marketing + entry to Sanctum) | `public/home.html.twig` ✅ |
| `/methodology` | Methodology 2-Steps explanation | `public/methodology.html.twig` (gap) |
| `/academy` | Academy overview (public preview) | `public/academy.html.twig` (gap) |
| `/mentorship` | Mentorship overview | `public/mentorship.html.twig` (gap) |
| `/tools` | Public tools / journal showcase | `public/tools.html.twig` (gap) |
| `/about` | About TNSVT | `public/about.html.twig` (gap) |
| `/faq` | FAQ | `public/faq.html.twig` (gap) |
| `/contact` | Contact / lead capture | `public/contact.html.twig` (gap) |
| `/login` | Login form | `public/login.html.twig` ✅ |
| `/register` (TBD) | Registration | `public/register.html.twig` (gap) |

> `/` and `/home` render `public/home.html.twig` (landing only). `/login` renders
> `public/login.html.twig` (form only). Authentication on `/` or `/login`
> redirects to `/sanctum`. Implemented in Phase 5 (`INTEGRATION-NOTES.md` §10).

---

## 3. Sanctum surface (target)

### ⌂ Sanctum Home

The personal command center. Aggregates the most important signals for the user:
- Guardian status (next action, soft warnings)
- Today's macro events
- Active courses / tasks
- Recent journal entries
- Recent community activity (feed preview)

> **Today's reality:** `sanctum/dashboard.html.twig` exists but the exact wiring
> needs verification. The route is not visible in the audit's controller map —
> either `/sanctum` resolves to a controller we haven't enumerated, or it currently
> 404s and `HomeController::index()` is the de-facto entry. **Verify in F3.**

### ⚔ Trading

| Sub-feature | Purpose | Today's route |
|---|---|---|
| Dashboard (Trading) | Trading-specific KPIs and signals | `/journal` (currently) — proposed split |
| Journal | Trade log + reviews | `/journal` |
| Calendar | Macro calendar + custom reminders | `/calendar` |
| Trading Plan | User-defined plan (goals, risk rules) | **GAP** |
| Statistics | Aggregate performance analytics | **GAP** (data exists, no page) |

> **Pattern:** Journal should not be 5 peer pages (`Dashboard / Registrar / Trade
> Log / Estadísticas / Calendario`). It should be **one page with tabs**:
> `Resumen / Operaciones / Estadísticas / Equity / Drawdown / Calendario`.
> Same pattern for the Trading macro when consolidated.

### 🎓 Formation

| Sub-feature | Purpose | Today's route |
|---|---|---|
| Academy | Browse / enroll in courses | `/campus` |
| Campus | Course player | `/campus` |
| My Courses | Active enrollments | `/sanctum/tasks` (currently) — **rename/move** |
| Tasks | Assigned tasks + personal tasks | `/sanctum/tasks` |
| Progress | Aggregated progress | **GAP** |

> **Pattern:** Academy/Campus same problem as Journal — should be one experience
> with tabs: `Inicio / Cursos / Curso (Módulos / Lecciones / Material / Progreso) /
> Mis tareas`.

### 🧠 Mind & Macro

| Sub-feature | Purpose | Today's route |
|---|---|---|
| Macroeconomics | Calendar + market intelligence | `/macro`, `/calendar` |
| Psychology / Diary | Encrypted psych journal | `/diario` |
| Oracle | Metrics / "second brain" | `/oracle` |
| **Guardian** (proposed) | Risk + discipline feedback | **GAP — concept only** |
| Frequencies (audio) | Optional — may move to Account > Preferences | `/frequencies` |

> **Guardian (proposed):** the atoms are already in the repo (`PropFirmAlert`,
> `PropFirmRuleChecker`, `NoTradeWindowService`, `DiaryEntry`, `JournalEntry`,
> `MonitoringService`). What is missing is the **unified surface** that:
> - surfaces soft warnings before a rule violation,
> - correlates psych state (diary) with trade performance (journal) and macro
>   conditions,
> - feeds a single **discipline score**,
> - emits push notifications via existing `PushService`.
>
> Guardian does NOT need its own macro if implemented as a **cross-cutting
> service + Sanctum Home widget + dedicated `/guardian` page under Mind & Macro**.
> See `AUDIT-INITIAL.md` §10 and `ROADMAP.md` F4 for the integration plan.

### 👥 Community

| Sub-feature | Purpose | Today's route |
|---|---|---|
| Feed | Public community stream | `/feed` |
| Social | Connection graph + access requests | `/social` |
| Chat | 1:1 and group chat | `/chat` |
| Leaderboard | Competitive ranking | `/leaderboard` |
| Honor | Honor board | `/honor` |
| Clans | Group identity | `/clan` |
| Tournaments | Time-bound competitions | `/tournaments` |
| Duels 1v1 | Head-to-head | `/duels` |
| Game | Mini-games | `/game` |
| Wallet | Internal currency | `/wallet` |
| Shop | Spend currency | `/shop` |
| Notifications | All notification types | `/notifications` |

> **Pattern:** 12 peer features today. The target groups them:
> - **Social:** Feed, Social, Chat, Clans
> - **Compete:** Leaderboard, Honor, Tournaments, Duels, Game
> - **Wallet:** Wallet, Shop, Notifications (notifications = transactional events
>   on wallet/social)
>
> **Notifications duplication:** Currently appears in **both** Community and Account
> in the sidebar. Decision: keep it in Account only — it is a personal inbox, not a
> community surface.

### 👤 Account

| Sub-feature | Purpose | Today's route |
|---|---|---|
| Profile | Own profile editor | `/profile` |
| Public profile | View others | `/u/{code}` |
| Security | Password, 2FA, devices, API keys | **GAP — no page** |
| Settings | Personal preferences | `/sanctum/settings` (shared with admin) |
| Notifications | Inbox | `/notifications` |

> **Settings separation:** `/sanctum/settings` is currently used for both personal
> settings and admin settings. Split:
> - Account: `/account/settings` (user preferences, notifications config, theme)
> - Admin: `/admin/settings` (system config)
>
> **Security gap:** `Device`, `ApiKey` entities exist. No Sanctum page exposes
> "where am I logged in" or "manage API keys". Add `/account/security`.

### ⚙ Admin (gated, isolated)

Visible only to `ROLE_ADMIN`. Should **not appear in the primary sidebar** of
normal users. Surface in a **separate Admin app shell** OR a clearly-labelled
"Admin" entry at the bottom of the sidebar that gates the entire `/admin/*` tree.

| Sub-feature | Purpose | Today's route |
|---|---|---|
| Users | User management | `/sanctum/users` |
| Content | CMS-style content | **GAP — no page** (entities exist) |
| Courses | Course admin | **GAP — no page** (CampusAdmin API exists) |
| Tasks | Global tasks | `/sanctum/tasks` |
| Subscriptions | Payments admin | **GAP — no page** (MercadoPago/BinancePay exist) |
| Audit log | System audit | `/sanctum/audit` |
| Monitoring | System monitoring | `/sanctum/monitoring` |
| Settings | System settings | `/sanctum/settings` |
| Admin Wallet | Wallet operations | **API-only** (`/api/admin/wallet/*`) |

### Mentor (target — not implemented)

The IA target lists a separate Mentor experience. **No `MentorController` exists**,
no `ROLE_MENTOR` on `User`, no mentor-only entities. Decision needed before
implementing:

- Option A: Mentor is a role inside Sanctum (mentor sees a "Students" tab and a
  different Home dashboard).
- Option B: Mentor is a separate URL space (`/mentor/*`) with its own shell.
- Option C: Defer until Phase 4 (Integration).

---

## 4. Navigation hierarchy (target)

The primary sidebar should be **5 macros + Admin**:

```
⌂ Inicio                  → /sanctum (or /home sanctum)

⚔ Trading                 → /journal (default tab)
   ├ Resumen
   ├ Operaciones
   ├ Estadísticas
   ├ Equity
   ├ Drawdown
   └ Calendario

🎓 Formación              → /campus
   ├ Inicio
   ├ Cursos
   ├ Curso
   │  ├ Módulos
   │  ├ Lecciones
   │  ├ Material
   │  └ Progreso
   └ Mis tareas

🧠 Mente & Macro          → /macro
   ├ Macroeconomía
   ├ Psicología (Diario)
   ├ Oráculo
   └ Guardian

👥 Comunidad              → /feed
   ├ Social              ├ Feed
                          ├ Conexiones
                          ├ Chat
                          └ Clanes
   └ Compite             ├ Leaderboard
                          ├ Honor
                          ├ Torneos
                          ├ Duelos
                          └ Game

👤 Mi cuenta              → /profile
   ├ Perfil
   ├ Configuración
   ├ Seguridad
   └ Notificaciones

⚙ Admin                   → /sanctum/users   (ROLE_ADMIN only)
```

> **Two-level depth max** for primary sidebar. Third level lives inside the page
> (tabs, sub-headers).

---

## 5. Mobile tab bar

Today's bottom tab bar has 4 destinations: Gateway, Cónclave, Journal, Macro. After
IA reorganization:

```
⌂ Inicio
⚔ Trading
🎓 Formación
� Mente
👥 Comunidad
👤 Cuenta
```

That's 6 tabs. Slightly tight on small screens. Decision: hide labels on very small
screens, show icons + active label only.

---

## 6. Public vs Sanctum — concept boundary

Per `CLAUDE.md` §11: "Keep public website, authenticated Sanctum, mentor/admin areas
**conceptually separate**."

This means:

- **Separate shell HTMLs.** Today everything extends `shell.html.twig`. Public
  landing should extend `public/shell.html.twig` (or just `base.html.twig`).
- **Separate CSS layers.** Public can be lighter (no Sanctum sidebar styles).
- **No public → Sanctum asset bleed.** Public should not load the 8 Sanctum CSS
  files.

Today: `templates/public/home.html.twig` loads only `tokens.css` + `home.css`
(its own extracted styles). `templates/shell.html.twig` (Sanctum) loads the
Sanctum CSS bundle. ✅ **Phase 5 done** — clean boundary.

---

## 7. Cross-cutting services and where they plug in

The IA target above depends on these services (already implemented, but not exposed
in the IA):

| Service | Where it should plug in |
|---|---|
| `PropFirmRuleChecker` | Guardian (Mind & Macro), Trading/Plan, Journal warnings |
| `NoTradeWindowService` | Macro, Guardian, Trading/Home |
| `OracleMetricsService` | Oracle, Journal/Statistics, Trading/Stats |
| `MonitoringService` | Admin/Monitoring, system observability |
| `PushService` | All macros (notification output) |
| `RateLimiterService` | `/api/auth/login`, `/api/chat/*`, `/api/upload/*` |
| `AdminAuditLogger` | All admin actions |

---

## 8. Migration order (proposed, for next phase)

This is the proposed move sequence. **Phase 5 (sidebar visual restructure) done
in 2026-08-14 — see `INTEGRATION-NOTES.md` §9.** Template / route moves deferred.

Phase 5 was executed as: reorder `<nav>` section only, keep all routes.

```
1. Create new public shell (public/shell.html.twig) — minimal.       ✅ DONE Phase 5
2. Split home.html.twig → public/home.html.twig + public/login.html.twig. ✅ DONE Phase 5
3. Create templates/sanctum/{trading,formation,mind,community,account}/ sub-folders. [pending]
4. Move existing sanctum/*.twig into the right sub-folder.           [pending]
5. Update shell.html.twig sidebar to use 5 macros + Admin.            ✅ DONE Phase 4
6. Update _partials/tabbar.html.twig to 6 tabs.                        [pending]
7. Verify every route still resolves.
8. Verify every controller still renders the right template.
9. Update routes.yaml / #[Route] attributes to point to new paths OR add a
   compatibility layer that 301-redirects old paths to new ones.
```

---

## 9. Important constraints

This document is a **target**. It is **not permission** to delete or move files
during `/audit` or `/map`. Before any file move:

- Confirm dependency map (`/map` deliverable).
- Add a compatibility redirect for any public route.
- Verify all `#[Route]` attributes and `path()` Twig calls still resolve.
- Run `bin/console debug:router` to confirm no orphans.

---

**Status:** IA target updated post-audit. Guardian placed in Mind & Macro as
proposed surface. Admin/Account settings split documented. Notification
duplication resolved (Account only). Public/Sanctum separation documented.

No files moved in this phase. Hand off to `/consolidate` for the duplication
candidates and `/integrate` for the Guardian surface.
