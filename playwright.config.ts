// playwright.config.ts — TNSVT E2E test runner configuration.
//
// Targets the production site (https://www.tnsvt.com) because there is
// no staging environment — the CHANGELOG audit explicitly states the
// Hostinger shared hosting has no proc_open (so no Symfony CLI, no DB
// seeds in CI). Tests are READ-ONLY smoke tests of the front-end
// behavior; they do NOT mutate production data (no trade creation,
// no account creation). Where a test would need state, it is seeded
// by a prerequisite script run manually before the suite.
//
// To run locally:
//   npm ci
//   npx playwright install --with-deps chromium   (one-time)
//   npm test
//
// Auth credentials below are real test accounts on prod. They are not
// real users in the financial sense — they are V1-imported demo users
// identified in src/Command/V1ImportCommand.php.
//
// CI: runs on every push to main + every PR (see .github/workflows/e2e.yml).

import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 1,
    timeout: 30_000,
    expect: {
        timeout: 5_000,
    },
    reporter: process.env.CI
        ? [['list'], ['github']]
        : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL: process.env.TNSVT_BASE_URL || 'https://www.tnsvt.com',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 10_000,
        navigationTimeout: 30_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
