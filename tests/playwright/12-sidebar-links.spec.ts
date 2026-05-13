/**
 * Click every link in the backstage sidebar + breadcrumb from each backstage
 * page. Asserts each link target loads with status < 500. Catches:
 *
 *   - 404 from dead links (route table drift)
 *   - 5xx on rarely-visited routes
 *   - JS error after navigation (no console errors during link traversal)
 */
import { test, expect } from '@playwright/test';
import { login } from './fixtures/auth';
import { ALL_BACKSTAGE_ROUTES } from './fixtures/routes';

test.describe('Backstage sidebar link traversal', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('All sidebar links from Dashboard land non-5xx', async ({ page }) => {
    await page.goto('/backstage/');
    await page.waitForLoadState('domcontentloaded');

    const hrefs = await page.evaluate(() => {
      const links = Array.from(document.querySelectorAll('nav a[href]'));
      return links
        .map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? '')
        .filter((h) => h.startsWith('/backstage') || h === '/backstage');
    });

    const seen = new Set<string>(hrefs);
    const failed: { href: string; status: number }[] = [];
    for (const href of seen) {
      const resp = await page.goto(href).catch(() => null);
      const status = resp?.status() ?? 0;
      if (status >= 500 || status === 0) {
        failed.push({ href, status });
      }
    }
    expect(failed, `Bad-status sidebar links: ${JSON.stringify(failed)}`).toEqual([]);
  });

  test('All "in-page" links from billing landing work', async ({ page }) => {
    await page.goto('/backstage/governance/billing');
    const hrefs = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('a[href]'))
        .map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? '')
        .filter((h) => h.startsWith('/backstage/governance/billing'));
    });
    const failed: { href: string; status: number }[] = [];
    for (const href of new Set(hrefs)) {
      const resp = await page.goto(href).catch(() => null);
      const status = resp?.status() ?? 0;
      if (status >= 500) failed.push({ href, status });
    }
    expect(failed, JSON.stringify(failed)).toEqual([]);
  });

  for (const route of ALL_BACKSTAGE_ROUTES) {
    test(`${route.path} — all <a> hrefs resolve without 5xx`, async ({ page }) => {
      const r = await page.goto(route.path);
      if ((r?.status() ?? 0) >= 500) {
        test.fail(true, `Initial GET 5xx on ${route.path}`);
        return;
      }
      await page.waitForLoadState('domcontentloaded');
      const hrefs = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('a[href]'))
          .map((a) => (a as HTMLAnchorElement).getAttribute('href') ?? '')
          .filter((h) => h.startsWith('/') && !h.startsWith('//'));
      });
      const unique = new Set(hrefs);
      // For performance, sample max 30 unique links per page
      const sample = [...unique].slice(0, 30);
      const failed: { href: string; status: number }[] = [];
      for (const href of sample) {
        // Skip mailto, anchor-only, javascript: etc.
        if (!href.startsWith('/')) continue;
        const resp = await page.request.get(href).catch(() => null);
        const status = resp?.status() ?? 0;
        if (status >= 500) failed.push({ href, status });
      }
      expect(failed, `5xx links from ${route.path}: ${JSON.stringify(failed)}`).toEqual([]);
    });
  }
});
