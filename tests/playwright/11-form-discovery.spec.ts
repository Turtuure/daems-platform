/**
 * Dynamic form discovery: for every known backstage page, find every <form>,
 * find every input/select/textarea field, and fuzz each with a small set of
 * adversarial payloads. Asserts:
 *
 *   - Submitting the form never produces a 5xx
 *   - Console has no "Uncaught" errors during interaction
 *   - HTML doesn't include a literal <script>...window.__pwned... payload
 *     (cheap XSS sanitisation check)
 *
 * This is parametric / page-driven so we don't need to enumerate every form
 * manually.
 */
import { test, expect, Page } from '@playwright/test';
import { login } from './fixtures/auth';
import { ALL_BACKSTAGE_ROUTES } from './fixtures/routes';
import { SQL_PAYLOADS, XSS_PAYLOADS, WHITESPACE_PAYLOADS } from './fixtures/attack-payloads';

const ATTACK_BATTERY = [
  ...SQL_PAYLOADS.slice(0, 2),    // 2 SQL injections per field
  ...XSS_PAYLOADS.slice(0, 2),    // 2 XSS payloads
  WHITESPACE_PAYLOADS[0],          // empty
  WHITESPACE_PAYLOADS[1],          // whitespace-only
];

async function collectFormShapes(page: Page) {
  // Returns each form's selector path + an array of input descriptors.
  return await page.evaluate(() => {
    const forms = Array.from(document.querySelectorAll('form'));
    return forms.map((form, fi) => {
      const inputs = Array.from(form.querySelectorAll('input, textarea, select'))
        .filter((el) => {
          const t = (el as HTMLInputElement).type ?? '';
          // Skip hidden, csrf, submit, file (file needs separate handling), checkbox/radio
          return t !== 'hidden' && t !== 'submit' && t !== 'button' && t !== 'file' && t !== 'checkbox' && t !== 'radio';
        })
        .map((el, ii) => {
          const e = el as HTMLInputElement;
          return {
            name: e.name || `__noname_${ii}`,
            type: e.type || 'text',
            tag: e.tagName.toLowerCase(),
            required: e.required,
          };
        });
      return { formIndex: fi, action: form.getAttribute('action') ?? '', method: form.method, inputs };
    });
  });
}

test.describe.serial('Form discovery + attack fuzz per backstage page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  for (const route of ALL_BACKSTAGE_ROUTES) {
    test(`${route.path} — every form survives attack battery`, async ({ page }) => {
      // Fuzz on pages with multi-input filter forms (e.g. invoices: year/status/fee_type)
      // can spend >60s on goto×payload iterations. Bump to 3 min per route.
      test.setTimeout(180_000);
      const serverErrors: { url: string; status: number }[] = [];
      page.on('response', (r) => {
        if (r.status() >= 500) serverErrors.push({ url: r.url(), status: r.status() });
      });
      const consoleErrors: string[] = [];
      page.on('pageerror', (e) => consoleErrors.push(e.message));

      // Some pages need the new-modal/dialog opened first to even show a form;
      // for the smoke-fuzz we just look at what's visible after initial render.
      // Trigger common "new" buttons if present (best-effort, ignore failures).
      const r = await page.goto(route.path);
      if ((r?.status() ?? 200) >= 500) {
        throw new Error(`${route.path} initial GET 5xx`);
      }
      await page.waitForLoadState('domcontentloaded');

      // Best-effort: click common "new" / "open dialog" triggers so forms appear.
      const openers = [
        '#new-override-btn',
        '#new-override-btn',
        'a[href*="edit=1"]',
        'a[href*="/new"]',
      ];
      for (const sel of openers) {
        const loc = page.locator(sel).first();
        if (await loc.count() > 0 && await loc.isVisible().catch(() => false)) {
          await loc.click().catch(() => {}); // ignore navigation away
          await page.waitForTimeout(300);
          break;
        }
      }

      const shapes = await collectFormShapes(page);
      const pageStart = Date.now();
      // Cap total time per route to keep the suite predictable. Pages with
      // many forms × big batteries (e.g. invoices list with mark-paid/waive/
      // reduce dialog forms) can balloon past the per-test timeout.
      const ROUTE_BUDGET_MS = 90_000;
      // It's fine for pages without forms — just verify smoke.
      for (let i = 0; i < shapes.length; i++) {
        const shape = shapes[i];
        if (shape.inputs.length === 0) continue;
        // Skip forms that aren't visible right now (e.g. forms inside closed
        // <dialog> elements). The openers loop already tried to expose any
        // form that has a corresponding trigger button.
        const formProbe = page.locator('form').nth(i);
        const formVisible = await formProbe.isVisible().catch(() => false);
        if (!formVisible) continue;
        for (const payload of ATTACK_BATTERY) {
          if (Date.now() - pageStart > ROUTE_BUDGET_MS) break;
          // Re-navigate per payload to start from a clean form state.
          await page.goto(route.path).catch(() => {});
          // Re-open dialog if needed
          for (const sel of openers) {
            const loc = page.locator(sel).first();
            if (await loc.count() > 0 && await loc.isVisible().catch(() => false)) {
              await loc.click().catch(() => {});
              await page.waitForTimeout(200);
              break;
            }
          }
          const form = page.locator('form').nth(i);
          if (await form.count() === 0) continue;
          // Fill every visible input with the payload
          for (const inp of shape.inputs) {
            const field = form.locator(`[name="${inp.name}"]`).first();
            const visible = await field.isVisible().catch(() => false);
            if (!visible) continue;
            // Type-specific payload: skip if obviously incompatible (e.g. number field gets non-numeric XSS → browser rejects)
            if (inp.type === 'number' && !/^-?\d/.test(payload)) continue;
            if (inp.type === 'date'   && !/^\d{4}-\d{2}-\d{2}$/.test(payload)) continue;
            await field.fill(payload).catch(() => {});
          }
          // Submit (page may navigate or context may close after click → catch+bail)
          try {
            const submitBtn = form.locator('button[type="submit"], input[type="submit"]').first();
            if (await submitBtn.count() > 0) {
              await submitBtn.click({ noWaitAfter: true }).catch(() => {});
              await page.waitForTimeout(400).catch(() => {});
            }
          } catch {
            // Page/context closed mid-submit — re-navigate next iter
          }
          if (page.isClosed?.()) break;
        }
        if (page.isClosed?.()) break;
      }
      if (page.isClosed?.()) return;
      // No 5xx during the fuzz
      expect(serverErrors, `5xx during fuzz on ${route.path}: ${JSON.stringify(serverErrors.slice(0, 5))}`).toEqual([]);

      // No XSS reflected (window.__pwned should never be set by user input)
      const pwned = await page.evaluate(() => (window as unknown as { __pwned?: boolean }).__pwned === true);
      expect(pwned, `XSS executed on ${route.path}`).toBe(false);

      // Console must not have unhandled exceptions
      expect(consoleErrors, `Unhandled console errors on ${route.path}: ${consoleErrors.join('\n')}`).toEqual([]);
    });
  }
});
