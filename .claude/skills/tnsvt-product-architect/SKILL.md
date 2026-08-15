---
name: tnsvt-product-architect
description: Senior product architect and full-stack delivery skill for auditing, reorganizing, improving, integrating and finalizing the TNSVT V2 Symfony trading platform. Use before major changes and whenever the task involves architecture, navigation, UX/UI, module consolidation, integration, QA, security, responsive design or production readiness.
---

# TNSVT Product Architect

Act as a combined:
- Product Architect
- Information Architect
- UX/UI Engineer
- Senior Symfony/PHP Engineer
- Frontend Engineer
- QA Engineer
- Security Reviewer
- Performance Engineer
- Release Engineer

## Prime directive

Turn the existing TNSVT V2 codebase into one coherent product.

Do not optimize for number of files changed.
Optimize for:
1. coherence
2. reuse
3. functional integration
4. visual consistency
5. maintainability
6. safety
7. production readiness

## Phase 0 — Repository reconnaissance

Before changing code, inspect:
- composer.json
- package.json
- Symfony configuration
- routes
- controllers
- entities
- repositories
- services
- templates
- assets
- security configuration
- Messenger/Mercure/API configuration
- database/migrations
- tests
- environment files without exposing values

Produce:
`docs/AUDIT-INITIAL.md`

Include:
- stack
- modules
- routes
- entities
- integrations
- duplicated functionality
- incomplete functionality
- risky areas
- technical debt
- visual debt
- recommended priorities

## Phase 1 — Product map

Produce:
`docs/MODULE-MAP.md`

Map every significant existing feature to:
PUBLIC / SANCTUM / MENTOR / ADMIN / INFRASTRUCTURE.

Do not delete anything merely because it is not in primary navigation.

## Phase 2 — Information architecture

Produce:
`docs/INFORMATION-ARCHITECTURE.md`

For every module define:
- purpose
- target user
- entry point
- child views
- actions
- data source
- permissions
- dependencies
- exit/navigation paths

Use the target IA from CLAUDE.md as the starting hypothesis, then adjust based on actual code.

## Phase 3 — User flows

Produce:
`docs/USER-FLOWS.md`

At minimum:
- visitor -> registration/login
- student -> Sanctum
- student -> study
- student -> task
- student -> journal
- student -> trading review
- student -> Guardian/risk feedback
- student -> community
- mentor -> student supervision
- admin -> platform management

## Phase 4 — Design system

Treat the existing Stitch direction and repository design documentation as visual references.

Consolidate duplicated tokens/styles before inventing new ones.

Preferred semantic roles:
- Void: application background
- Surface: panels/cards
- Gold: primary/high-value action
- Violet: intelligence/navigation/secondary emphasis
- White/light: primary content
- Muted: secondary content
- Green: positive trading/result state
- Red: risk/error/destructive state

Do not use decorative glow everywhere.
Use hierarchy, spacing and typography first.

## Phase 5 — Component architecture

Identify repeated UI patterns:
- shell
- top bar
- sidebar
- cards
- buttons
- badges
- tabs
- tables
- charts
- metric blocks
- forms
- alerts
- empty states
- loading states
- error states
- modal/drawer
- notifications

Create reusable components instead of copying HTML/CSS between templates.

## Phase 6 — Consolidation

Search for:
- duplicate routes
- duplicate controllers
- duplicate templates
- duplicate CSS
- duplicate JS
- duplicate business logic
- overlapping concepts

For each duplication:
KEEP / MERGE / DEPRECATE / REPLACE

Never delete blindly.

## Phase 7 — Integration

For every UI screen verify:
- route
- controller
- service
- repository/entity
- API/integration if applicable
- authorization
- validation
- persistence
- feedback

A beautiful screen with fake/static data is NOT complete.

## Phase 8 — Responsive

Check:
- 320px
- 375px
- 768px
- 1024px
- 1440px+

Check:
- navigation
- tables
- charts
- forms
- modals
- cards
- touch targets
- horizontal overflow

## Phase 9 — QA

For each modified module:
- syntax
- route
- authorization
- happy path
- empty state
- error state
- invalid input
- responsive behavior
- console/runtime errors
- regressions

## Phase 10 — Security

Inspect:
- authentication
- authorization
- CSRF
- XSS
- SQL/Doctrine safety
- rate limits
- secrets
- file uploads
- access control
- IDOR
- API exposure
- debug mode
- production config

Never print or commit secret values.

## Phase 11 — Performance

Inspect:
- N+1 queries
- unnecessary eager loading
- asset duplication
- oversized images
- blocking JS/CSS
- caching
- repeated API calls
- expensive dashboard queries

## Phase 12 — Finalization

Create:
`docs/FINAL-PRODUCTION-AUDIT.md`

A release is READY only if:
- critical defects = 0
- high severity defects = 0
- all core navigation paths work
- authorization is verified
- important integrations work
- responsive behavior is acceptable
- no known secret leakage exists
- build/test checks pass
- rollback/deployment notes exist

If not ready, state exactly why.

## Output discipline

At the end of each task report:
1. What was inspected
2. What was changed
3. Why it was changed
4. Files affected
5. Tests/checks performed
6. Remaining risks
7. Recommended next action

Never claim success without evidence.
