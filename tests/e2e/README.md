# E2E Tests (Playwright)

End-to-end tests for TNSVT Sanctum run against **production** (no staging
env, Hostinger shared hosting has no `proc_open` so we can't seed a DB
in CI). Tests are **read-only** — no trade creation, no account deletion.

## What we cover

See [tests/e2e/](tests/e2e/) — current suite runs against prod with
one chromium worker, retries=1, ~30s/page timeout.

- **journal-account-switching.spec.ts**
  - All 4 widgets (log, stats, calendar, day modal) refresh to the new
    account on chip click.
  - No duplicate fetches (Bug #G — every URL ≤ 1× per account switch).
  - Equity curve baseline in "Todas" = sum of all account_size (Bug #J).
  - Rapid account toggling does not leave stale DOM painted (Bug #H).
  - Chat presence ping keeps User::isOnline() up to date (Bug #L).
  - Plus 6 documented TODO tests (orphan account, monthly_pnl filter,
    event dispatch, page-refresh persistence).

## Local run

```bash
npm ci
npx playwright install --with-deps chromium   # one-time, ~150MB download

# Set test credentials (use real test users from prod)
export TNSVT_BASE_URL=https://www.tnsvt.com
export TNSVT_ADMIN_CODE=ADMIN01
export TNSVT_ADMIN_PASSWORD=admin

npm test                  # headless
npm run test:headed       # opens a browser
npm run test:report       # opens the HTML report
```

## CI

`.github/workflows/e2e.yml` runs the suite on every push to main and
on every PR. Requires two GitHub Secrets:

- `TNSVT_ADMIN_CODE` — admin user code (e.g. `ADMIN01`)
- `TNSVT_ADMIN_PASSWORD` — admin password

Reports (HTML, videos on failure, traces on retry) are uploaded as
artifacts on failure and retained 14 days.

## Adding a new test

1. Add a `test()` block to an existing `.spec.ts` file (or create a new
   one). It must call `login(page, TEST_USERS.X)` from `./login.ts`.
2. Don't create or delete state — tests must be idempotent so they
   can run repeatedly against the same prod data.
3. Use `test.skip(...)` for environment-specific assertions (e.g. "need
   ≥2 trading accounts") rather than `test.fail()`.

## Why against prod?

See [AGENTS.md § Hostinger proc_open limitation](../../AGENTS.md). There
is no separate staging server. Adding a staging env would require a
Hostinger VPS (~$5/mo) plus DB seeding — out of scope until
TODO:replace-with-staging-env is done.
