# T.N.S.V.T — Reino del Cristo Íntegro

Plataforma operativa para traders: metodología, academia, journaling,
inteligencia macro, Guardian (risk + discipline), wallet y comunidad.

**Stack:** Symfony 8.1 · PHP 8.4 · Doctrine ORM · JWT auth · Firebase · Mercure ·
Messenger · Stimulus · Turbo · MySQL/PostgreSQL · Hostinger

---

## Quick start

### Requisitos

- PHP ≥ 8.4 con extensiones: `ctype`, `iconv`, `mbstring`, `intl`, `pdo_mysql` (o `pdo_pgsql`)
- Composer ≥ 2
- Node.js no requerido (asset-mapper es PHP-nativo)

### Local

```bash
git clone <repo-url> tnsvt-app
cd tnsvt-app
composer install
cp .env.example .env.local       # configurar DB + secretos reales
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console lexik:jwt:generate-keypair  # solo si querés JWT
php -S 127.0.0.1:8000 -t public/   # servidor dev
```

### Deploy a Hostinger

Ver [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — incluye provisioning, secrets,
migrations, smoke tests y rollback.

---

## Estructura del repo

```
tnsvt-app/
├── .claude/                  ← contract + skill + comandos del agente
│   ├── CLAUDE.md              ← reglas operativas
│   ├── commands/              ← /audit, /map, /ia, /ui, etc.
│   └── skills/tnsvt-product-architect/
├── .github/workflows/        ← CI (GitHub Actions)
├── assets/
│   ├── styles/                ← tokens, components, animations, glow
│   ├── js/                    ← módulos JS (vanilla)
│   ├── controllers/           ← Stimulus controllers
│   └── app.js
├── bin/                       ← scripts (pre-deploy-check, deploy, smoke, rollback)
├── config/                    ← Symfony config (packages, routes, services)
├── docs/                      ← documentación completa (10 archivos)
│   ├── README.md              ← índice de docs/
│   ├── AUDIT-INITIAL.md       ← auditoría inicial
│   ├── MODULE-MAP.md          ← mapa de módulos
│   ├── INFORMATION-ARCHITECTURE.md
│   ├── USER-FLOWS.md
│   ├── DESIGN-SYSTEM.md
│   ├── ROADMAP.md
│   ├── INTEGRATION-NOTES.md   ← log de cambios
│   ├── SECURITY.md            ← protocolo de secretos
│   ├── DEPLOYMENT.md          ← guía de deploy
│   └── FINAL-PRODUCTION-AUDIT.md
├── migrations/                ← Doctrine migrations
├── public/                    ← web root (symlink o docroot)
├── src/
│   ├── Controller/            ← controllers web (8) + API (53)
│   ├── Entity/                ← 68 entities
│   ├── Repository/            ← 68 repositories
│   └── Service/               ← 35 services (incl. Guardian/)
├── templates/
│   ├── public/                ← shell público + home + login + _public_nav
│   ├── sanctum/               ← 25 templates del app autenticado
│   ├── oracle/                ← /oracle
│   ├── macro/                 ← /macro
│   ├── frequencies/           ← /frequencies
│   ├── legacy/                ← legacy bridge
│   ├── _partials/             ← api_helper, tabbar, fonts
│   └── shell.html.twig        ← shell autenticado (sidebar 5 macros)
├── tests/                     ← PHPUnit tests
├── var/                       ← cache, log, data (gitignored)
└── vendor/                    ← composer deps (gitignored)
```

---

## Información arquitectónica clave

### 5 macros del Sanctum (sidebar)

| Macro | Links |
|---|---|
| ⌂ Inicio | dashboard |
| 👤 Mi cuenta | perfil, configuración, notificaciones |
| ⚔ Trading | journal, calendario, leaderboard |
| 🎓 Formación | campus |
| 🧠 Mente & Macro | macro, oráculo, Guardian, diario, frecuencias |
| 👥 Comunidad | social, chat, clanes, torneos, duelos, game, honor, wallet, tienda |
| � Admin | usuarios, tareas, audit, settings, monitoring |

### Guardian

Servicio cross-cutting que orquesta:
- `PropFirmRuleChecker` (reglas PropFirm)
- `NoTradeWindowService` (ventanas macro)
- `OracleMetricsService` (métricas)
- `MonitoringService` (eventos)

**Endpoints:**
- `GET /api/guardian/signals` — señales operativas
- `GET /api/guardian/score` — score 0-100 + tier

**Superficies:**
- `/sanctum/guardian` — página completa
- `/sanctum/dashboard` — widget compacto
- `/journal` — banner pre-trade

### Rutas públicas

- `GET /` → landing (marketing)
- `GET /login` → form de login
- `GET /home` → alias de `/`

---

## Documentación

Toda la documentación vive en [`docs/`](docs/README.md):

- **Auditoría inicial** — [`docs/AUDIT-INITIAL.md`](docs/AUDIT-INITIAL.md)
- **Mapa de módulos** — [`docs/MODULE-MAP.md`](docs/MODULE-MAP.md)
- **Arquitectura de información** — [`docs/INFORMATION-ARCHITECTURE.md`](docs/INFORMATION-ARCHITECTURE.md)
- **Flujos de usuario** — [`docs/USER-FLOWS.md`](docs/USER-FLOWS.md)
- **Design system** — [`docs/DESIGN-SYSTEM.md`](docs/DESIGN-SYSTEM.md)
- **Roadmap** — [`docs/ROADMAP.md`](docs/ROADMAP.md)
- **Build log (cambios)** — [`docs/INTEGRATION-NOTES.md`](docs/INTEGRATION-NOTES.md)
- **Seguridad y secretos** — [`docs/SECURITY.md`](docs/SECURITY.md)
- **Deploy a Hostinger** — [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
- **Auditoría final de producción** — [`docs/FINAL-PRODUCTION-AUDIT.md`](docs/FINAL-PRODUCTION-AUDIT.md)

---

## Scripts útiles

```bash
./bin/pre-deploy-check.sh        # verifica antes de deploy (PHP lint, templates, secrets)
./bin/pre-deploy-check.ps1       # versión Windows
./bin/deploy.sh                  # deploy completo via SSH + git
./bin/post-deploy-smoke.sh URL   # HTTP smoke tests contra el sitio en vivo
./bin/rollback.sh                # rollback a un release anterior
```

---

## Convenciones de commit

```
feat(guardian): add daily loss signal
fix(journal): correct entry form validation
docs(security): document rotation protocol
chore(deps): update doctrine to 3.7
```

Prefix: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `style`, `perf`

---

## Licencia

Proprietary. Todos los derechos reservados.
