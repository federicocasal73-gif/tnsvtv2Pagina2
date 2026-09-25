// tests/e2e/login.ts — shared login helpers.
//
// TNSVT uses a code-based login at /login (Admin requires password
// too). The login form posts to /api/auth/login, which sets a PHPSESSID
// cookie + JWT Bearer cookie. We use the same page form to log in via
// Playwright's UI (closer to user reality than hitting /api/auth/login
// directly — catches stale-cache / broken-form regressions too).

import type { Page, BrowserContext } from '@playwright/test';

export interface TestCredentials {
    code: string;
    password?: string;
    /** If true, user has ROLE_ADMIN and requires the password field. */
    admin?: boolean;
}

/** Real test accounts seeded by the V1 import (src/Command/V1ImportCommand.php). */
export const TEST_USERS = {
    admin:      { code: 'ADMIN01',               password: 'admin',          admin: true } as TestCredentials,
    regularA:   { code: 'AXEL9927',              password: '',                admin: false } as TestCredentials,
    regularB:   { code: 'ELENTEOSCURO979',       password: '',                admin: false } as TestCredentials,
} as const;

/**
 * Logs in via the /login form and waits for the redirect to /sanctum
 * (the authenticated landing). On success, the context's cookies
 * contain PHPSESSID and the JWT Bearer cookie.
 */
export async function login(page: Page, user: TestCredentials): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[name="code"], input[name="name"]', { timeout: 10_000 });

    // TNSVT login form has fields "code" (or "name" in some templates)
    // and "password" only for admin. The fill tries both naming conventions.
    const codeInput = page.locator('input[name="code"]').or(page.locator('input[name="name"]')).first();
    await codeInput.fill(user.code);

    if (user.admin || user.password) {
        const passInput = page.locator('input[name="password"]').or(page.locator('input[type="password"]')).first();
        await passInput.fill(user.password || '');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForURL(/\/(sanctum|$)/, { timeout: 15_000 });
}

/**
 * Clears all cookies + storage so a test starts authenticated as a
 * fresh anon user.
 */
export async function logout(context: BrowserContext): Promise<void> {
    await context.clearCookies();
}
