/**
 * Strict i18n key→value coverage for all 0.7 billing UI.
 *
 * What this catches:
 *   - Typo in lang/{locale}.php key name
 *   - Typo in template's I18n::t('foo.bar') call (renders nothing / falls back)
 *   - Key present in fi_FI but missing in en_GB/sw_TZ (parity drift)
 *   - Locale-switching not honored by tenant (?lang= or X-Daems-Locale)
 */
import { test, expect } from '@playwright/test';
import { login } from './fixtures/auth';
import { I18n, Locale, findRawI18nKeysInHtml } from './fixtures/i18n';

const LOCALES: Locale[] = ['fi_FI', 'en_GB', 'sw_TZ'];

/** Keys we expect to find rendered on each billing page (by URL). */
const PAGE_KEYS: Record<string, string[]> = {
  '/backstage/governance/billing': [
    'shell.governance.billing',
    'backstage.governance.billing.year_label',
    'backstage.governance.billing.kpi.pending',
    'backstage.governance.billing.kpi.overdue',
    'backstage.governance.billing.kpi.paid',
    'backstage.governance.billing.kpi.waived',
    'backstage.governance.billing.invoices.link',
    'backstage.governance.billing.overrides.link',
  ],
  '/backstage/governance/billing/invoices': [
    'backstage.title.governance.billing.invoices',
    'backstage.governance.billing.invoices.heading',
    'backstage.governance.billing.invoices.filter.year',
    'backstage.governance.billing.invoices.filter.status',
    'backstage.governance.billing.invoices.filter.fee_type',
    'backstage.governance.billing.invoices.col.user',
    'backstage.governance.billing.invoices.col.fee_type',
    'backstage.governance.billing.invoices.col.amount',
    'backstage.governance.billing.invoices.col.due_date',
    'backstage.governance.billing.invoices.col.status',
    'backstage.governance.billing.invoices.col.actions',
  ],
  '/backstage/governance/billing/overrides': [
    'backstage.title.governance.billing.overrides',
    'backstage.governance.billing.overrides.heading',
    'backstage.governance.billing.overrides.active_only',
    'backstage.governance.billing.overrides.new_button',
    'backstage.governance.billing.overrides.col.user',
    'backstage.governance.billing.overrides.col.fee_type',
    'backstage.governance.billing.overrides.col.amount',
    'backstage.governance.billing.overrides.col.window',
    'backstage.governance.billing.overrides.col.reason',
    'backstage.governance.billing.overrides.col.actions',
  ],
  '/backstage/governance/billing/import': [
    'backstage.title.governance.billing.import',
    'backstage.governance.billing.import.heading',
    'backstage.governance.billing.import.description',
    'backstage.governance.billing.import.file_label',
    'backstage.governance.billing.import.preview_button',
    'backstage.governance.billing.import.col.row',
    'backstage.governance.billing.import.col.payer',
    'backstage.governance.billing.import.col.reference',
    'backstage.governance.billing.import.col.amount',
  ],
};

test.describe('i18n strict coverage — billing 0.7', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  for (const locale of LOCALES) {
    test.describe(`locale ${locale}`, () => {
      for (const [url, keys] of Object.entries(PAGE_KEYS)) {
        test(`${url} renders all expected keys verbatim`, async ({ page }) => {
          const i18n = I18n.load(locale);
          await page.goto(`${url}?lang=${locale}`);
          await page.waitForLoadState('domcontentloaded');
          const html = await page.content();

          const missing: { key: string; expected: string }[] = [];
          for (const key of keys) {
            if (!i18n.has(key)) {
              missing.push({ key, expected: `(MISSING from lang/${locale}.php)` });
              continue;
            }
            const expected = i18n.t(key);
            if (!html.includes(expected)) {
              missing.push({ key, expected });
            }
          }
          expect(missing, `Missing on ${url} [${locale}]: ${JSON.stringify(missing, null, 2)}`).toEqual([]);
        });
      }
    });
  }

  test('lang files have matching key sets across all 3 locales', async () => {
    const fi = I18n.load('fi_FI');
    const en = I18n.load('en_GB');
    const sw = I18n.load('sw_TZ');

    // Limit to billing-specific keys (otherwise drift across unrelated modules
    // would noise this test — that's a separate concern).
    const billingPrefix = 'backstage.governance.billing.';
    const fiBilling = fi.keys().filter((k) => k.startsWith(billingPrefix));
    const enBilling = en.keys().filter((k) => k.startsWith(billingPrefix));
    const swBilling = sw.keys().filter((k) => k.startsWith(billingPrefix));

    const fiMissingInEn = fiBilling.filter((k) => !en.has(k));
    const fiMissingInSw = fiBilling.filter((k) => !sw.has(k));
    const enMissingInFi = enBilling.filter((k) => !fi.has(k));
    const swMissingInFi = swBilling.filter((k) => !fi.has(k));

    expect(fiMissingInEn, `Keys in fi_FI missing from en_GB: ${fiMissingInEn.join(', ')}`).toEqual([]);
    expect(fiMissingInSw, `Keys in fi_FI missing from sw_TZ: ${fiMissingInSw.join(', ')}`).toEqual([]);
    expect(enMissingInFi, `Keys in en_GB missing from fi_FI: ${enMissingInFi.join(', ')}`).toEqual([]);
    expect(swMissingInFi, `Keys in sw_TZ missing from fi_FI: ${swMissingInFi.join(', ')}`).toEqual([]);
  });

  test('no raw i18n keys leak into billing landing HTML', async ({ page }) => {
    await page.goto('/backstage/governance/billing');
    const html = await page.content();
    const raw = findRawI18nKeysInHtml(html).filter((k) =>
      k.startsWith('backstage.') || k.startsWith('shell.') || k.startsWith('billing.')
    );
    expect(raw, `Raw i18n keys leaked into HTML: ${raw.join(', ')}`).toEqual([]);
  });
});
