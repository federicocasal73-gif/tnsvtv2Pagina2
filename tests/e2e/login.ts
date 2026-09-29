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
    /** Required for non-admin logins (server matches code+name). */
    name?: string;
    /** If true, user has ROLE_ADMIN and requires the password field. */
    admin?: boolean;
}

/**
 * Env override with fallback. E2E runs against PRODUCTION, so the
 * credentials must match real prod accounts — never hardcode real
 * secrets here. Set TNSVT_ADMIN_CODE / TNSVT_ADMIN_PASSWORD (and the
 * optional TNSVT_USER*_CODE/NAME) as GitHub Actions secrets; locally
 * the dev-seed fallbacks below apply.
 */
function env(name: string, fallback: string): string {
    const v = process.env[name];
    return v && v.length > 0 ? v : fallback;
}

/** Real test accounts seeded by the V1 import (src/Command/V1ImportCommand.php). */
export const TEST_USERS = {
    admin: {
        code: env('TNSVT_ADMIN_CODE', 'ADMIN01'),
        password: env('TNSVT_ADMIN_PASSWORD', 'admin'),
        admin: true,
    } as TestCredentials,
    regularA: {
        code: env('TNSVT_USERA_CODE', 'AXEL9927'),
        name: env('TNSVT_USERA_NAME', 'axelvaldez'),
        password: '',
        admin: false,
    } as TestCredentials,
    regularB: {
        code: env('TNSVT_USERB_CODE', 'ELENTEOSCURO979'),
        name: env('TNSVT_USERB_NAME', ''),
        password: '',
        admin: false,
    } as TestCredentials,
} as const;

/**
 * Logs in via the /login form and waits for the redirect to /sanctum
 * (the authenticated landing). On success, the context's cookies
 * contain PHPSESSID and the JWT Bearer cookie.
 */
export async function login(page: Page, user: TestCredentials): Promise<void> {
    // El tour de onboarding (onboarding.js, flag `tnsvt_onboarding_v1` en
    // localStorage) tapa la página con un modal en la primera visita y
    // rompe clicks/selectores del suite. Lo marcamos completado antes de
    // navegar para que ningún test lo vea.
    await page.addInitScript(() => {
        try {
            window.localStorage.setItem('tnsvt_onboarding_v1', 'completed');
        } catch {
            /* localStorage no disponible — el tour decidirá solo */
        }
    });
    await page.goto('/login');
    await page.waitForSelector('input[name="code"], input[name="name"]', { timeout: 10_000 });

    // TNSVT login form has fields "code" (or "name" in some templates)
    // and "password" only for admin. The fill tries both naming conventions.
    const codeInput = page
        .locator('input[name="code"]')
        .or(page.locator('input[name="name"]'))
        .first();
    await codeInput.fill(user.code);

    // Non-admin logins require the name to match (CodeAuthenticator).
    // Admins ignore the name field server-side.
    if (user.name) {
        const nameInput = page.locator('input[name="name"]').first();
        if ((await nameInput.count()) > 0) {
            await nameInput.fill(user.name);
        }
    }

    if (user.admin || user.password) {
        const passInput = page
            .locator('input[name="password"]')
            .or(page.locator('input[type="password"]'))
            .first();
        await passInput.fill(user.password || '');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    // Admins land on /sanctum, regular users on /journal (see login form JS).
    await page.waitForURL(/\/(sanctum|journal|$)/, { timeout: 15_000 });
}

/**
 * Clears all cookies + storage so a test starts authenticated as a
 * fresh anon user.
 */
export async function logout(context: BrowserContext): Promise<void> {
    await context.clearCookies();
}
