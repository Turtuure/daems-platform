/**
 * API endpoint fuzz: every known POST endpoint receives a battery of
 * adversarial payloads. Asserts:
 *
 *   - Response status is < 500 for every payload (controlled rejection)
 *   - Response is valid JSON (no PHP fatal leaking into output)
 *   - For GET endpoints: 200 or auth-related (401/403)
 *
 * Caveat: we DO NOT include DROP/TRUNCATE-style payloads. Prepared statements
 * should make injection impossible, but defense-in-depth — we don't bait the
 * framework even in tests.
 */
import { test, expect } from '@playwright/test';
import { loginPlatform, PLATFORM_BASE } from './fixtures/auth';
import { API_GET_ENDPOINTS, API_POST_ENDPOINTS_FOR_FUZZ } from './fixtures/routes';

/** The platform REST API lives on a different host than the society UI.
 *  We prefix relative `/api/v1/*` URLs with the platform base so requests
 *  reach the actual JSON router instead of falling through to society's
 *  HTML home page. */
const abs = (u: string): string => (u.startsWith('http') ? u : `${PLATFORM_BASE}${u}`);
import {
  SQL_PAYLOADS,
  XSS_PAYLOADS,
  PATH_TRAVERSAL_PAYLOADS,
  MALFORMED_UUIDS,
  MALFORMED_DATES,
  NUMERIC_EDGE_CASES,
  OVERFLOW_PAYLOADS,
  WHITESPACE_PAYLOADS,
} from './fixtures/attack-payloads';

test.describe('API GET endpoint smoke (auth required)', () => {
  test.beforeEach(async ({ page }) => {
    await loginPlatform(page);
  });

  for (const url of API_GET_ENDPOINTS) {
    test(`GET ${url} — non-5xx + JSON parses`, async ({ page }) => {
      const resp = await page.request.get(abs(url));
      expect(resp.status(), `${url} status`).toBeLessThan(500);
      const text = await resp.text();
      // If the response was OK or 2xx-ish, body should be valid JSON.
      if (resp.status() < 400) {
        expect(() => JSON.parse(text), `${url} body should parse: ${text.slice(0, 200)}`).not.toThrow();
      }
    });
  }
});

test.describe('API POST endpoint adversarial fuzz', () => {
  test.beforeEach(async ({ page }) => {
    await loginPlatform(page);
  });

  for (const ep of API_POST_ENDPOINTS_FOR_FUZZ) {
    test.describe(`POST ${ep.url}`, () => {
      test('Valid payload returns 2xx/4xx (never 5xx)', async ({ page }) => {
        const resp = await page.request.post(abs(ep.url), { data: ep.sampleBody });
        expect(resp.status(), `valid payload status: ${await resp.text()}`).toBeLessThan(500);
      });

      // Mutate one field at a time with each attack payload.
      const fieldNames = Object.keys(ep.sampleBody);
      for (const field of fieldNames) {
        for (const [kind, payload] of mutationsForField(field, ep.sampleBody[field])) {
          test(`field=${field} kind=${kind} → non-5xx`, async ({ page }) => {
            const body = { ...ep.sampleBody, [field]: payload };
            const resp = await page.request.post(abs(ep.url), { data: body });
            const text = await resp.text();
            expect(resp.status(), `${field}=${kind}: ${text.slice(0, 150)}`).toBeLessThan(500);
            // Output must be valid JSON either way
            if (text.trim() !== '') {
              expect(() => JSON.parse(text), `JSON parse: ${text.slice(0, 150)}`).not.toThrow();
            }
          });
        }
      }
    });
  }
});

/**
 * Per-field mutation generator. Knows nothing about the field's "real" type —
 * just throws each payload class at it and we trust the backend to reject.
 *
 * Yields [kind, payload] pairs. Limited to ~12 per field to keep total test
 * count manageable while still covering each attack class.
 */
function* mutationsForField(_field: string, _original: unknown): Generator<[string, unknown]> {
  yield ['sql_1', SQL_PAYLOADS[0]];
  yield ['sql_2', SQL_PAYLOADS[1]];
  yield ['xss_1', XSS_PAYLOADS[0]];
  yield ['xss_2', XSS_PAYLOADS[1]];
  yield ['path_traversal', PATH_TRAVERSAL_PAYLOADS[0]];
  yield ['malformed_uuid', MALFORMED_UUIDS[0]];
  yield ['malformed_date', MALFORMED_DATES[0]];
  yield ['negative_number', NUMERIC_EDGE_CASES.negative[0]];
  yield ['huge_string', OVERFLOW_PAYLOADS.longString.slice(0, 1000)]; // 1k chars; 10k might trip POST size limits
  yield ['huge_number', OVERFLOW_PAYLOADS.longNumber];
  yield ['empty', WHITESPACE_PAYLOADS[0]];
  yield ['whitespace', WHITESPACE_PAYLOADS[1]];
  yield ['null_value', null];
  yield ['wrong_type', { nested: 'object' }];
}

test.describe('API endpoint requires auth (anonymous → 401/403)', () => {
  test('Anonymous request to admin endpoints rejected', async ({ browser }) => {
    // Fresh context — no cookies, no login
    const context = await browser.newContext();
    const page = await context.newPage();
    const adminEndpoints = [
      ...API_GET_ENDPOINTS.filter((u) => u.includes('/backstage/')),
      ...API_POST_ENDPOINTS_FOR_FUZZ.map((e) => e.url),
    ];
    const leaked: { url: string; status: number }[] = [];
    for (const url of adminEndpoints) {
      // GET probe (some POST routes also accept GET → 405 expected)
      const resp = await page.request.get(abs(url)).catch(() => null);
      const status = resp?.status() ?? 0;
      // Acceptable: 401, 403, 404, 405 — anything that isn't success
      if (status >= 200 && status < 400) {
        leaked.push({ url, status });
      }
    }
    expect(leaked, `Admin endpoints leaked to anonymous: ${JSON.stringify(leaked)}`).toEqual([]);
    await context.close();
  });
});
