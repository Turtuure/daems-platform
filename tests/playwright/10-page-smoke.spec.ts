/**
 * Generic page-smoke: every known backstage + public page loads cleanly across
 * all 3 supported locales. What this asserts per (page, locale):
 *
 *   1. HTTP response < 500 (renders without server error)
 *   2. No 5xx for any sub-resource (XHR, asset) during initial render
 *   3. No raw i18n keys leak (e.g. "backstage.foo.bar" appearing literally)
 *   4. Page has a visible heading (no completely blank render)
 *   5. For pages with curated i18n keys, the values render verbatim
 */
import { test, expect } from '@playwright/test';
import { login } from './fixtures/auth';
import { I18n, Locale, findRawI18nKeysInHtml } from './fixtures/i18n';
import { ALL_BACKSTAGE_ROUTES, PUBLIC_ROUTES, BackstageRoute } from './fixtures/routes';

const LOCALES: Locale[] = ['fi_FI', 'en_GB', 'sw_TZ'];

function smokeRoute(route: BackstageRoute, locale: Locale): void {
  test(`${route.path} [${locale}]`, async ({ page }) => {
    if (route.adminOnly) {
      await login(page);
    }
    const serverErrors: { url: string; status: number }[] = [];
    page.on('response', (r) => {
      if (r.status() >= 500) serverErrors.push({ url: r.url(), status: r.status() });
    });

    const url = `${route.path}${route.path.includes('?') ? '&' : '?'}lang=${locale}`;
    const resp = await page.goto(url);
    expect(resp?.status() ?? 0, `goto ${url} status`).toBeLessThan(500);
    await page.waitForLoadState('domcontentloaded');

    // 5xx sub-resource check
    expect(
      serverErrors,
      `5xx during ${route.path} render [${locale}]: ${JSON.stringify(serverErrors)}`,
    ).toEqual([]);

    // No raw i18n keys leaked (heuristic — filters known false positives)
    const html = await page.content();
    const raw = findRawI18nKeysInHtml(html)
      .filter((k) => k.startsWith('backstage.') || k.startsWith('shell.') || k.startsWith('billing.'));
    expect(raw, `Raw i18n key(s) leaked on ${route.path} [${locale}]: ${raw.join(', ')}`).toEqual([]);

    // At minimum, there should be a visible <h1> or page-header__title.
    // Some search/empty pages may have h1 only after JS load; allow either.
    const heading = page.locator('h1, .page-header__title').first();
    await expect(heading, `${route.path} [${locale}] heading visible`).toBeVisible({ timeout: 5000 });

    // Optional: assert curated i18n keys render verbatim.
    if (route.i18nKeys && route.i18nKeys.length > 0) {
      const i18n = I18n.load(locale);
      for (const key of route.i18nKeys) {
        if (!i18n.has(key)) continue; // gracefully skip if key not present in this locale
        const expected = i18n.t(key);
        expect(html, `${route.path} [${locale}] should include "${key}" value`).toContain(expected);
      }
    }
  });
}

test.describe('Backstage admin pages × locale × smoke', () => {
  for (const route of ALL_BACKSTAGE_ROUTES) {
    test.describe(route.label, () => {
      for (const locale of LOCALES) smokeRoute(route, locale);
    });
  }
});

test.describe('Public pages × locale × smoke', () => {
  for (const route of PUBLIC_ROUTES) {
    test.describe(route.label, () => {
      for (const locale of LOCALES) smokeRoute(route, locale);
    });
  }
});
