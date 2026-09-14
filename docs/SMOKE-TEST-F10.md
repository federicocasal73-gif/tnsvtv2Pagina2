# Smoke test post-deploy — F10 slices 1–3 + hotfixes C1-C3

Run these steps in order after `git push + ssh deploy` to verify the
Turbo Drive + mini-player + chat-admin fixes are live and
non-regressing.

## 0. Pre-flight (curl)

```bash
curl -sI https://tnsvt.com/api/auth/check | head -1
# expected: HTTP/2 200

curl -s https://tnsvt.com/api/frequencies/presets | jq '.success'
# expected: true

curl -s "https://tnsvt.com/api/chat/conversations?user_code=ADMIN01" | jq 'type'
# expected: "array"   (was: 500 InvalidOperation)
```

If any of those fail, **stop** and rollback to the previous HEAD —
do not continue.

## 1. Turbo Drive: sidebar persists across navigations

1. Open `https://tnsvt.com/sanctum/dashboard` (auth required).
2. Open DevTools → console. Expect zero errors on load.
3. Click sidebar link **Calendario** → URL becomes `/sanctum/calendar`.
   - **PASS** if the sidebar does **not** flash/reload.
   - **FAIL** if the whole page reloads (no Turbo).
4. Click sidebar link **Leaderboard** → `/sanctum/leaderboard`.
5. Click sidebar link **Calendario** again → `/sanctum/calendar`.
   - **Look for in console:** `Identifier 'threadEl' has already been declared`.
   - **PASS** if absent. **FAIL** if present — it is the regression we fixed in C1.

## 2. Calendar filters actually work

(Turbo navigation can leave Stimulus event listeners stale if duplicated.)

1. Open `https://tnsvt.com/sanctum/calendar`.
2. Type a date into the **Fecha** input → event list refreshes.
3. Pick a different **País** chip → URL `?countries=XX` updates,
   list re-renders.
4. Pick **Ventana = ∞** → URL `?window=0`.
5. Refresh page manually → URL params restored from `?countries=…&date=…`.
   **PASS** if every step fires a request and the list updates.
   **FAIL** if the controls do nothing — the broken Turbo `<script>`
   was wiring those listeners.

## 3. Mini-player is in the shell

1. Open `https://tnsvt.com/frequencies`, log in.
2. Pick a preset (e.g. **Universal 432Hz**), pick 1-minute duration,
   click **Iniciar**.
3. Visually verify:
   - the floating card appears at the **bottom-left of every page**,
     not just `/frequencies`.
4. Open a new page (e.g. sidebar link **Diario**) — the mini-player
   card should still be visible.
5. While on **Diario**, click the card's stop button — the audio
   stops, the card disappears.
6. Reload `/frequencies`. Click Iniciar again. Wait 30s. Hit refresh.
   - **PASS**: on reload, the `GET /api/frequencies/session/active`
     endpoint either returns `{session: null, …}` (the previous
     `stopSession` properly closed it) and the page is idle, OR if you
     did not stop, it returns the active session and the resume modal
     pops up asking to continue.
   - **FAIL** if either the audio double-stacks (two oscillators
     audible) or the modal silently throws.

## 4. Chat shell for admin

1. Open any Sanctum page (e.g. `/sanctum/dashboard`).
2. Wait 5 seconds. The chat shell pings `/api/chat/conversations?user_code=ADMIN01`.
   - **PASS**: no console errors, and the badge with unread count
     updates normally.
   - **FAIL**: `Failed to load resource: 500` in the Network tab —
     rollback immediately and investigate
     `ConversationRepository::findByParticipant`.

## 5. Form submissions still work under Turbo

1. Create a journal trade at `/sanctum/journal/new`.
2. Save → form posts via `apiFetch` (JSON), success toast.
3. Reload `/sanctum/journal` → trade appears in the list.
   - **PASS**: no errors, trade persisted.
   - **FAIL** if the form submits and the page silently fails (Turbo
     is intercepting something it shouldn't).

## 6. Final checks

```bash
composer audit --no-interaction | grep -c "vulnerab"
# expected: 0 (or only the pre-existing ignored PKSA-*)

php bin/console lint:twig templates | tail -1
# expected: All X Twig files contain valid syntax.
```

## Rollback procedure

If step 0, 1, 3, or 4 fails, SSH into the server and revert to the
previous commit:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
  "cd ~/domains/tnsvt.com/public_html && \
   git fetch origin main && git reset --hard b5a660d && \
   rm -rf var/cache/prod var/cache/dev && \
   php bin/console cache:warmup --env=prod --no-debug && \
   php bin/console asset-map:compile --env=prod --no-debug --no-interaction"
```

That reverts only the F10 + hotfixes — keeps everything else.
