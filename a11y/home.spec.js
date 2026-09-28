import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PAGES = [
  { name: 'home', url: '/' },
  { name: 'login', url: '/login' }
];

for (const { name, url } of PAGES) {
  test(`${name} should have no a11y violations`, async ({ page }) => {
    await page.goto(url);
    const accessibilityScanResults = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(accessibilityScanResults.violations).toEqual([]);
  });
}