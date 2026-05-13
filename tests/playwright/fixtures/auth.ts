/**
 * Authentication helpers shared across all billing smoke specs.
 *
 * Programmatic login via /api/auth/login bypasses the JS-driven dropdown
 * signin form (which is unreliable to drive in tests). Cookies set via
 * page.request are shared with page navigation in the same BrowserContext.
 */
import { Page, expect } from '@playwright/test';

export const ADMIN_EMAIL = process.env.SMOKE_USER ?? 'samppa.turunen@gmail.com';
export const ADMIN_PASS  = process.env.SMOKE_PASS ?? '';

if (!ADMIN_PASS) {
  // We let the throw happen at top-level on first import. Each spec calls
  // login() and would fail anyway; this gives a clearer error.
  console.warn('[smoke] SMOKE_PASS env-var not set — login() will fail.');
}

export async function login(page: Page, email = ADMIN_EMAIL, password = ADMIN_PASS): Promise<void> {
  await page.goto('/');
  const resp = await page.request.post('/api/auth/login', {
    form: { email, password },
  });
  expect(resp.ok(), `Login failed for ${email}: HTTP ${resp.status()} — ${await resp.text()}`).toBeTruthy();
}

/**
 * Logs in directly against the platform REST host (cookies are per-host).
 * Use when tests hit `http://daems-platform.local/api/v1/*` URLs — the
 * society-host session does not carry to platform-host requests.
 */
export const PLATFORM_BASE = process.env.SMOKE_PLATFORM_BASE ?? 'http://daems-platform.local';

export async function loginPlatform(page: Page, email = ADMIN_EMAIL, password = ADMIN_PASS): Promise<void> {
  const resp = await page.request.post(`${PLATFORM_BASE}/api/v1/auth/login`, {
    data: { email, password },
    headers: { 'Content-Type': 'application/json' },
  });
  expect(resp.ok(), `Platform login failed for ${email}: HTTP ${resp.status()} — ${await resp.text()}`).toBeTruthy();
}

export async function logout(page: Page): Promise<void> {
  await page.request.post('/api/auth/logout');
}
