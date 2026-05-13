/**
 * Adversarial input payloads. Used by 08-attack-surface.spec.ts (and where
 * relevant elsewhere) to verify that user input is rejected/escaped safely.
 *
 * No destructive payloads here: the SQL category is read-only / detectable
 * (no DROP/TRUNCATE). Defense-in-depth — we don't bait the framework even
 * though prepared statements should make injection impossible.
 */

export const SQL_PAYLOADS: string[] = [
  "' OR '1'='1",
  "' OR 1=1 --",
  "admin'--",
  "1' UNION SELECT NULL--",
  "'; SELECT 1; --",
  "\"; SELECT pg_sleep(1); --",
];

export const XSS_PAYLOADS: string[] = [
  '<script>window.__pwned=true</script>',
  '<img src=x onerror="window.__pwned=true">',
  '"><script>window.__pwned=true</script>',
  '<svg onload="window.__pwned=true">',
  'javascript:void(window.__pwned=true)',
  '<iframe src="data:text/html,<script>parent.__pwned=true</script>">',
];

export const PATH_TRAVERSAL_PAYLOADS: string[] = [
  '../../../etc/passwd',
  '..\\..\\..\\windows\\system32\\config',
  '%2e%2e%2f%2e%2e%2f',
  '....//....//etc/passwd',
];

export const UNICODE_PAYLOADS: string[] = [
  'Råökkönen Åäö',
  'Testi 测试 中文',
  'Сахегруп русский',
  '🚀 emoji test 👨‍👩‍👧‍👦',
  '‮evil‭', // right-to-left override
];

export const OVERFLOW_PAYLOADS = {
  longString: 'A'.repeat(10_000),
  longNumber: '9'.repeat(20),
  intMaxOverflow: '99999999999999999',
};

export const WHITESPACE_PAYLOADS: string[] = [
  '',
  '   ',
  '\t\n\r',
  '​',                  // zero-width space
  ' ',                  // non-breaking space
];

export const MALFORMED_UUIDS: string[] = [
  'not-a-uuid',
  '00000000-0000-0000-0000-000000000000',
  '12345678-1234-1234-1234-12345678',
  'GGGGGGGG-GGGG-7GGG-8GGG-GGGGGGGGGGGG',
  '01958000-0000-4000-8000-000000000099', // UUIDv4, not v7
];

export const MALFORMED_DATES: string[] = [
  'not-a-date',
  '2026-13-01',
  '2026-02-30',
  '31/12/2026',
  '2026-99-99',
  '',
];

export const NUMERIC_EDGE_CASES = {
  negative:  ['-1', '-100', '-99999'],
  zero:      ['0'],
  fractional: ['0.5', '0.001', '1e-10'],
  scientific: ['1e100'],
};

/**
 * Per-field expectations: a single canonical mapping from payload-kind to
 * the acceptable HTTP status range. Used so each test asserts "this kind of
 * attack should be rejected with 4xx, not crash with 5xx".
 *
 * Default contract: anything that's not a valid input must be 400/403/404/409/422
 * — never 5xx, never 200-with-impact.
 */
export const REJECT_RANGE = (status: number): boolean => status >= 400 && status < 500;
