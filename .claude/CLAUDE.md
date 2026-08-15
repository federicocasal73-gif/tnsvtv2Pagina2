# TNSVT — Agent Operating Contract

You are working on the TNSVT V2 application.

Your mission is not to blindly add features. Your mission is to understand, organize,
integrate, improve, test and finalize the existing product without breaking working behavior.

## Non-negotiable rules

1. Inspect before modifying.
2. Preserve working functionality unless a replacement is explicitly justified.
3. Never invent routes, entities, APIs, database fields or integrations when an existing implementation can be reused.
4. Prefer consolidation over duplication.
5. Prefer reusable components over page-specific UI.
6. Stitch is the visual reference, not the source of product architecture.
7. Backend contracts are authoritative for data behavior.
8. Never expose secrets, credentials or `.env` values.
9. Never make destructive database/code changes without identifying dependencies and rollback impact.
10. Do not declare a feature finished because it renders. It must be functional, integrated and tested.
11. Keep public website, authenticated Sanctum, mentor/admin areas conceptually separate.
12. When uncertain, document the uncertainty and inspect more code rather than guessing.

## Current product intent

TNSVT is a trading education and operating platform combining:
- methodology and education
- trading journal and execution review
- macro/market intelligence
- behavioral/risk assistance
- AI assistance
- community
- mentor supervision
- administrative operations

The current repository contains substantially more functionality than should appear
in primary navigation. Reorganize the experience before adding unnecessary modules.

## Primary information architecture target

PUBLIC
- Home
- Methodology
- Academy
- Mentorship
- Tools
- About
- FAQ
- Contact

SANCTUM
- Home
- Trading
  - Dashboard
  - Journal
  - Calendar
  - Trading Plan
  - Statistics
- Formation
  - Academy
  - Campus
  - Courses
  - Tasks
  - Progress
- Mind & Macro
  - Macroeconomics
  - Psychology / Diary
  - Oracle
- Community
  - Feed
  - Social
  - Chat
  - Leaderboard
  - Clans
- Account
  - Profile
  - Notifications
  - Security
  - Settings

ADMIN / MENTOR
- isolated from normal student navigation
- visible only to authorized roles

This is a target architecture, not permission to move or delete files immediately.

## Required workflow

AUDIT -> MAP -> IA -> UX FLOWS -> DESIGN SYSTEM -> COMPONENTS -> CONSOLIDATE ->
INTEGRATE -> RESPONSIVE -> QA -> SECURITY -> PERFORMANCE -> PRODUCTION READINESS

Do not skip directly to implementation when the repository has not been audited.

## Completion standard

A module is DONE only when:
- route works
- authorization is correct
- data source is real
- empty/loading/error states exist
- responsive behavior is acceptable
- visual language follows TNSVT design system
- no duplicated implementation was introduced
- relevant tests/checks pass
- documentation is updated when architecture changed

See `.claude/skills/tnsvt-product-architect/SKILL.md` for the detailed operating procedure.
