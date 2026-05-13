/**
 * Route inventory — single source of truth for what URLs Playwright knows about.
 *
 * Parsed semi-statically (not at runtime from router.php) because we want
 * deterministic test names and the ability to annotate per-route behaviour
 * (admin-only? GET only? expected i18n keys?).
 */

export interface BackstageRoute {
  path: string;
  label: string;
  /** True if non-admin should get 403/redirect. Most are admin-only. */
  adminOnly: boolean;
  /** Optional: expected i18n keys this page renders. Empty = no strict assertion. */
  i18nKeys?: string[];
}

/**
 * Backstage pages — sourced from public/backstage/router.php (2026-05-13).
 * Keep in sync manually when router.php gains/loses routes.
 */
export const BACKSTAGE_ROUTES: BackstageRoute[] = [
  { path: '/backstage/',                                    label: 'Dashboard',          adminOnly: true },
  { path: '/backstage/notifications',                       label: 'Notifications',      adminOnly: true },
  { path: '/backstage/search',                              label: 'Search',             adminOnly: true },
  { path: '/backstage/settings',                            label: 'Settings',           adminOnly: true },
  { path: '/backstage/settings/modules',                    label: 'Modules',            adminOnly: true },
  { path: '/backstage/project-proposals',                   label: 'Project proposals',  adminOnly: true },
  { path: '/backstage/governance/board',                    label: 'Board',              adminOnly: true },
  { path: '/backstage/governance/billing',                  label: 'Billing landing',    adminOnly: true,
    i18nKeys: ['shell.governance.billing', 'backstage.governance.billing.kpi.pending'] },
  { path: '/backstage/governance/billing/overrides',        label: 'Billing overrides',  adminOnly: true,
    i18nKeys: ['backstage.governance.billing.overrides.heading'] },
  { path: '/backstage/governance/billing/invoices',         label: 'Billing invoices',   adminOnly: true,
    i18nKeys: ['backstage.governance.billing.invoices.heading'] },
  { path: '/backstage/governance/billing/import',           label: 'CSV import',         adminOnly: true,
    i18nKeys: ['backstage.governance.billing.import.heading'] },
  { path: '/backstage/governance/decisions',                label: 'Decisions list',     adminOnly: true },
  { path: '/backstage/governance/decisions/new',            label: 'Decisions new',      adminOnly: true },
  { path: '/backstage/governance/expulsions',               label: 'Expulsions list',   adminOnly: true },
  { path: '/backstage/governance/expulsions/new',           label: 'Expulsions new',    adminOnly: true },
  { path: '/backstage/governance/delegations',              label: 'Delegations',        adminOnly: true },
  { path: '/backstage/governance/settings',                 label: 'Governance settings',adminOnly: true },
];

/**
 * Module-added backstage pages — convention is /backstage/<module>.
 */
export const MODULE_BACKSTAGE_ROUTES: BackstageRoute[] = [
  { path: '/backstage/members',          label: 'Members admin',  adminOnly: true },
  { path: '/backstage/events',           label: 'Events admin',   adminOnly: true },
  { path: '/backstage/forum',            label: 'Forum admin',    adminOnly: true },
  { path: '/backstage/projects',         label: 'Projects admin', adminOnly: true },
  { path: '/backstage/insights',         label: 'Insights admin', adminOnly: true },
];

/**
 * Public pages on daems.local (not behind /backstage/).
 */
export const PUBLIC_ROUTES: BackstageRoute[] = [
  { path: '/',          label: 'Home',     adminOnly: false },
  { path: '/about',     label: 'About',    adminOnly: false },
  { path: '/projects',  label: 'Projects', adminOnly: false },
  { path: '/events',    label: 'Events',   adminOnly: false },
  { path: '/forums',    label: 'Forums',   adminOnly: false },
  { path: '/insights',  label: 'Insights', adminOnly: false },
  { path: '/join',      label: 'Join',     adminOnly: false },
  { path: '/login',     label: 'Login',    adminOnly: false },
];

export const ALL_BACKSTAGE_ROUTES = [...BACKSTAGE_ROUTES, ...MODULE_BACKSTAGE_ROUTES];

/**
 * API endpoints that admin GET should succeed against (response body is
 * irrelevant — we just verify they don't 5xx and require auth).
 *
 * NOTE: These paths are PLATFORM-host paths — `/api/v1/*` is only routed by
 * `daems-platform.local`. The society host (`daems.local`) only proxies
 * `/api/backstage/*` and falls through to the public HTML renderer for
 * anything else. Tests in 13-api-fuzz.spec.ts use `loginPlatform` and
 * absolute platform URLs accordingly.
 */
export const API_GET_ENDPOINTS: string[] = [
  '/api/v1/status',
  '/api/v1/admin/stats',
  '/api/v1/backstage/stats',
  '/api/v1/backstage/governance/billing/fee-schedules?year=2027',
  '/api/v1/backstage/governance/billing/overrides',
  '/api/v1/backstage/governance/billing/invoices',
  '/api/v1/backstage/governance/billing/kpi',
  '/api/v1/backstage/members?per_page=10',
  '/api/v1/backstage/members/stats',
];

/**
 * API endpoints that take POST input — used by attack-surface fuzz.
 * Each entry: {url, sample body, expected reject status range}.
 */
export interface ApiPostEndpoint {
  url: string;
  sampleBody: Record<string, unknown>;
  expectRejectFor: 'invalid_uuid' | 'missing_fields' | 'sql_inj' | 'xss';
}

export const API_POST_ENDPOINTS_FOR_FUZZ: ApiPostEndpoint[] = [
  {
    url: '/api/v1/backstage/governance/billing/fee-schedules',
    sampleBody: { year: 2099, fees: { SUPPORTING: 1000, BASIC: 5000, FULL: 0 } },
    expectRejectFor: 'missing_fields',
  },
  {
    url: '/api/v1/backstage/governance/billing/overrides',
    sampleBody: {
      user_id: '01958000-0000-7000-8000-000000000099',
      fee_type: 'BASIC',
      override_amount_cents: 2500,
      valid_from: '2026-01-01',
      reason: 'smoke',
    },
    expectRejectFor: 'invalid_uuid',
  },
  // Path-param routes (need to be tested with a fake id):
  // /api/v1/backstage/governance/billing/invoices/{id}/{action} -- covered separately
];
