import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PAGES = [
  { name: 'home', url: '/' },
  { name: 'login', url: '/login' }
];

for (const { name, url } of PAGES) {
  test(`${name} should have no a11y violations`, async ({ page }) => {
    await page.goto(url);
    // Home/login bento tiles usan animación gw-fade-up (delay máx .7s +
    // duración .8s). Analizar a mitad del fade mide texto semi-transparente
    // y el contraste falla de forma flaky. Esperar a que asiente + fonts.
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(2000);
    const accessibilityScanResults = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(accessibilityScanResults.violations).toEqual([]);
  });
}