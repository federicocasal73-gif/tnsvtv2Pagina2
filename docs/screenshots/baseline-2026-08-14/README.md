# Baseline screenshots — Pre CSS consolidation

**Date captured:** 2026-08-14/15
**Capture method:** Windows screenshot tool (`Win+Shift+S` likely)
**Source:** `tnsvt.com` (live production URL — see observations)

---

## ⚠️ Important observation

**These screenshots are from the LIVE production site (`tnsvt.com`), NOT from a local build.**

**The site shown is the OLD pre-Phase-4 layout** — the sidebar has 7 sections
(Trading, Social, Competición, Educación, Economía, Herramientas, Admin),
not the new 5-macro sidebar (Inicio, Mi Cuenta, Trading, Formación, Mente &
Macro, Comunidad, Admin) implemented in Phase 4.

This means **the changes from Phase 4 onwards are in the local repo but NOT
deployed to production yet**. The CSS consolidation merges will still be valid
(no visual regression risk because we're just consolidating files, not changing
styles), but to SEE the visual differences the operator needs to:

1. Deploy the new code to tnsvt.com
2. Re-capture screenshots post-deploy

---

## Captured (27 files, Windows auto-generated names)

All files are in `Captura de pantalla_*.jpeg` format with timestamps.
The user captured 27 screenshots over ~2 hours.

**Pages captured (inferred from sidebar visible):**

| Sidebar state | Likely page |
|---|---|
| Chat highlighted | `/chat` (multiple shots during interaction) |
| Honor highlighted | `/honor` |
| Chat / profile / settings (admin) | Various admin actions |

**Logged-in state:** ADMIN role (avatar badge visible, Admin section visible
in sidebar).

---

## Checklist (post-hoc)

Re-mapping the README checklist against what was captured:

| # | Mandatory | Page | Captured |
|---|---|---|---|
| 1 | ☐ | `/` landing | Unclear (need to check earlier captures) |
| 2 | ☐ | `/login` | Unclear |
| 3 | ☐ | `/sanctum/dashboard` | Likely not (no data visible) |
| 4 | ☐ | `/journal` | Not visible in captures |
| 5 | ☐ | `/sanctum/guardian` | Not visible in captures |
| 6 | ☐ | `/chat` | ✅ Yes (multiple) |
| 7 | ☐ | `/profile` | ✅ Yes (admin profile visible) |
| 8 | ☐ | `/account/settings` | Not visible in captures |
| 9 | ☐ | `/sanctum/users` (admin) | Not visible in captures |
| 10 | ☐ | `/oracle` | Not visible in captures |

**Coverage assessment:** partial — mostly Chat and Honor pages with admin
profile interaction. Not enough for a full regression test, but enough to
confirm the basic shell (sidebar, topbar, glass cards, buttons) renders
correctly.

---

## How this baseline will be used

The CSS consolidation merges will be applied to the LOCAL repo. The merge plan
is designed to be **visual-invariant** — i.e., the rendered output should be
identical before and after each merge. To verify:

1. After each merge, you can either:
   - Run the app locally (`php -S 127.0.0.1:8000 -t public/`) and re-capture
   - Deploy the changes to tnsvt.com and re-capture
2. Compare the new screenshots against this baseline
3. If anything looks different, `git revert HEAD` to undo the merge

---

## Files in this directory

- `README.md` (this file)
- `Captura de pantalla_*.jpeg` × 27 (Windows screenshot tool output)

**Disk usage:** ~3-5 MB total (varies by JPEG compression).

---

## Notes for post-deploy verification

When the Phase 4+ code is deployed to tnsvt.com, the screenshots should show:

- New sidebar with 5 macros (Inicio, Mi Cuenta, Trading, Formación, Mente & Macro,
  Comunidad) + Admin section
- "Conexiones" instead of "Journal Sharing" (Phase 4 rename)
- Notification single entry under Mi Cuenta (not in Herramientas)
- No duplicate Notificaciones

When that happens, this baseline becomes outdated. Create a new
`docs/screenshots/post-phase-4-*/` directory at that time.
