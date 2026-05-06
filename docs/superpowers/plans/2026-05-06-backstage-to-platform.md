# Backstage → Platform Migration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lift the entire `/backstage` admin UI out of `sites/daem-society/public/*` into `daems-platform/`, so one codebase serves the same admin UI to every tenant. After this plan, navigating to `daems-platform.local/backstage` (or any per-tenant platform host) lands on the same UI, scoped to that tenant's data; `daem-society/public/backstage` and `daem-society/public/api/backstage` are gone.

**Architecture:** Backstage becomes a second front controller in the platform repo (`public/backstage.php`), reusing the existing platform DI kernel for direct use-case calls and falling back to the curl-based `ApiClient` only where it already exists. Per-tenant module page dirs at `C:\laragon\www\modules\<name>\frontend\backstage\` stay where they are — both repos already mount them from filesystem; platform takes over the routing role from society. Tenant resolution stays via `Host` header (Phase 1); session-based tenant switcher is out of scope (parked for Phase 2).

**Tech Stack:** PHP 8.1, Apache rewrite (`.htaccess`), platform's existing `Daems\Infrastructure\Framework\Http\Kernel`, ApexCharts/Bootstrap (assets unchanged), platform `TenantContextMiddleware` + `AuthMiddleware`, MySQL (no schema changes).

---

## Decisions (locked, do not relitigate during execution)

1. **Backstage URL:** `daems-platform.local/backstage/*`. Per-tenant access in dev: add `<tenant>-platform.local` rows to `config/tenant-fallback.php` (Wave G) so e.g. `sahegroup-platform.local/backstage` resolves to the sahegroup tenant. Each host serves identical code; tenant comes from the `Host` header via existing `TenantContextMiddleware`.
2. **Two front controllers in platform:** keep `public/index.php` for `/api/v1/*` JSON; add `public/backstage.php` for HTML at `/backstage/*` and HTML form/JSON proxies at `/api/backstage/*`. `.htaccess` dispatches by URL prefix.
3. **No reverse proxy.** Society's `/backstage` URL ceases to work — society redirects `/backstage*` → `https://<tenant>-platform.local/backstage*`. Frontend nav links update.
4. **ApiClient moves with backstage** (verbatim) to `daems-platform/src/Frontend/ApiClient.php`. We do NOT refactor backstage pages to call kernel use cases directly in Phase 1 — that is a follow-up. The curl-self-call from `daems-platform.local/backstage*` to `daems-platform.local/api/v1/*` adds one localhost hop per page; acceptable for the migration.
5. **Modules stay at `C:\laragon\www\modules\*`.** Both repos still mount them from filesystem. Society keeps the modules dir reference for the public site; platform adds an identical mount path.
6. **i18n:** copy `lang/{fi_FI,en_GB,sw_TZ}.php` and `src/I18n.php` from society to platform (in `frontend/`). Society keeps its copies for the public site. Lang catalogues drift independently from this point on; that is acceptable because backstage and public site already use disjoint key prefixes (`backstage.*` vs `nav.*`/`home.*`/etc).
7. **Session cookie scope:** session is per-host, so logging in at `daem-society.local` does NOT carry to `daems-platform.local`. Backstage requires its own login. Login form lives at `daems-platform.local/backstage/login` (new). Society keeps its own login for the public site.
8. **No DB schema changes.** No new use cases. No new platform API endpoints. This is a code-move plan, not a feature plan.
9. **Branch:** new branch `backstage-to-platform` off current `dev`. Each wave commits to that branch; wave-G PR collapses on merge.

---

## File structure (target end state)

```
daems-platform/
├── public/
│   ├── index.php                          # API kernel — unchanged
│   ├── backstage.php                      # NEW — backstage front controller
│   ├── .htaccess                          # UPDATED — dispatches /backstage* and /api/backstage* to backstage.php
│   ├── uploads/                           # keeps existing avatars uploaded by API
│   └── backstage/                         # NEW — backstage assets + pages root
│       ├── pages/
│       │   ├── _shared.php                # MOVED from sites/daem-society/public/pages/backstage/
│       │   ├── index.php                  # MOVED — dashboard
│       │   ├── layout.php                 # MOVED — sidebar shell
│       │   ├── partials/
│       │   ├── shared/
│       │   ├── notifications/
│       │   ├── search/
│       │   ├── settings/
│       │   ├── project-proposals/
│       │   ├── toasts.css                 # MOVED
│       │   └── toasts.js                  # MOVED
│       ├── api/                           # MOVED — backstage proxy endpoints
│       │   ├── applications.php
│       │   ├── dismiss.php
│       │   ├── event-upload.php
│       │   ├── events.php
│       │   ├── forum.php
│       │   ├── insights.php
│       │   ├── members.php
│       │   ├── notifications.php
│       │   ├── projects.php
│       │   ├── proposals.php
│       │   ├── search.php
│       │   └── tenant-settings.php
│       ├── auth/
│       │   ├── login.php                  # NEW — login form HTML
│       │   ├── login-handler.php          # NEW — POST handler (calls platform /auth/login)
│       │   └── logout.php                 # NEW
│       └── assets/
│           ├── css/
│           │   ├── daems-backstage.css         # MOVED
│           │   ├── daems-backstage-system.css  # MOVED
│           │   ├── daems-search.css            # MOVED
│           │   ├── bootstrap.min.css           # MOVED
│           │   └── bootstrap-icons.min.css     # MOVED
│           ├── js/
│           │   ├── daems-backstage.js              # MOVED
│           │   ├── daems-backstage-dashboard.js    # MOVED
│           │   ├── daems-backstage-system.js       # MOVED
│           │   ├── daems-search.js                 # MOVED
│           │   ├── bootstrap.bundle.min.js         # MOVED
│           │   └── qrcode-generator.js             # MOVED (used by /backstage/members card)
│           ├── img/                                # MOVED — brand favicon, logo
│           └── fonts/                              # MOVED
├── src/
│   └── Frontend/                          # NEW namespace for the curl-based proxy layer
│       ├── ApiClient.php                  # MOVED from sites/daem-society/src/ApiClient.php
│       ├── I18n.php                       # MOVED from sites/daem-society/src/I18n.php
│       └── MemberNumberFormatter.php      # MOVED from sites/daem-society/src/
├── lang/                                  # NEW — i18n catalogues for backstage
│   ├── fi_FI.php                          # COPIED from sites/daem-society/lang/
│   ├── en_GB.php                          # COPIED
│   └── sw_TZ.php                          # COPIED
└── config/
    └── tenant-fallback.php                # UPDATED — adds <tenant>-platform.local rows

sites/daem-society/                        # public site only after migration
├── public/
│   ├── pages/
│   │   └── backstage/                     # DELETED
│   ├── api/
│   │   └── backstage/                     # DELETED
│   ├── assets/
│   │   ├── css/daems-backstage*.css       # DELETED
│   │   └── js/daems-backstage*.js         # DELETED
│   └── index.php                          # UPDATED — drops backstage routing block + /api/backstage proxies; adds redirect /backstage* → platform
└── src/
    ├── ApiClient.php                      # KEEP — public site still uses it
    └── I18n.php                           # KEEP

modules/<name>/frontend/backstage/         # UNCHANGED — files stay; consumed by platform now
```

---

## Wave overview

| Wave | Tasks | Outcome |
|------|-------|---------|
| A. Platform scaffolding | 1–4 | New `public/backstage.php` front controller boots; serves a "hello backstage" page at `daems-platform.local/backstage`. No society changes yet. |
| B. Move shared chrome | 5–8 | Layout, dashboard, partials, shared CSS/JS work at `daems-platform.local/backstage`. Society's copy still works in parallel. |
| C. Move platform-specific pages | 9–12 | Notifications, search, settings, project-proposals served from platform. |
| D. Move proxy endpoints + login | 13–17 | All `/api/backstage/*` JSON proxies served from platform. Login + logout work. |
| E. Mount modules from platform | 18–20 | Module backstage pages (`members`, `events`, `forum`, `insights`, `projects`) routed through platform's backstage front controller. |
| F. Society cleanup + redirect | 21–23 | Society drops backstage routes/files; redirects `/backstage*` to platform host. |
| G. Multi-tenant smoke + tests + docs | 24–28 | `sahegroup-platform.local/backstage` works. CLAUDE.md + memory + roadmap updated. PR ready. |

Waves are commit boundaries — each wave ends in a working build (PHPStan 0 errors, `composer test:all` green, browser smoke for the new surface). Halt on any red.

---

## Wave A — Platform scaffolding

### Task 1: Create branch and inventory

**Files:**
- Modify: `docs/superpowers/plans/2026-05-06-backstage-to-platform.md` (this file — add execution log section at bottom)

- [ ] **Step 1: Create branch off `dev`**

```bash
git -C C:/laragon/www/daems-platform checkout dev
git -C C:/laragon/www/daems-platform pull --ff-only origin dev
git -C C:/laragon/www/daems-platform checkout -b backstage-to-platform
git -C C:/laragon/www/sites/daem-society checkout dev
git -C C:/laragon/www/sites/daem-society pull --ff-only origin dev
git -C C:/laragon/www/sites/daem-society checkout -b backstage-to-platform
```

Expected: both repos on `backstage-to-platform`, working tree clean.

- [ ] **Step 2: Verify baseline is green in platform**

```bash
cd C:/laragon/www/daems-platform
vendor/bin/phpstan analyse --memory-limit=1G
composer test
composer test:e2e
```

Expected: PHPStan 0 errors. Unit + Integration green. E2E green. (Full `composer test:all` runs the Isolation suite which has 5 pre-existing Insights failures — see `docs/superpowers/plans/deferred-items.md` — so we run the two passing buckets separately.)

- [ ] **Step 3: Append execution log section to this plan**

At the bottom of this plan file, append:

```markdown
---

## Execution log

| Date | Wave | Task | Commit | Notes |
|------|------|------|--------|-------|
| | | | | |
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add docs/superpowers/plans/2026-05-06-backstage-to-platform.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Plan(backstage): start backstage→platform migration on backstage-to-platform branch"
```

---

### Task 2: Add `public/backstage.php` front controller skeleton

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage.php`
- Modify: `C:/laragon/www/daems-platform/public/.htaccess`

- [ ] **Step 1: Write `public/backstage.php` skeleton**

Path: `C:\laragon\www\daems-platform\public\backstage.php`

```php
<?php

declare(strict_types=1);

/**
 * Backstage front controller — serves /backstage/* HTML and /api/backstage/* JSON.
 *
 * The API kernel at public/index.php handles /api/v1/*. This controller is a
 * separate process; it does NOT instantiate the platform Kernel/Router. It
 * uses curl-via-ApiClient to talk to the API kernel on the same host (single
 * extra localhost hop per page). Sessions and CSRF live here.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Frontend/ApiClient.php';
require_once dirname(__DIR__) . '/src/Frontend/I18n.php';

define('DAEMS_BACKSTAGE_PUBLIC', __DIR__ . '/backstage');
// Modules expect this constant; point it at the backstage public dir so
// module backstage files like layout.php require paths resolve correctly.
define('DAEMS_SITE_PUBLIC', __DIR__ . '/backstage');

session_start();
\Daems\Frontend\I18n::locale();

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');

// Static asset passthrough — Apache also serves these directly via -f rule
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        return false;
    }
}

// Logout
if ($uri === '/backstage/logout') {
    require __DIR__ . '/backstage/auth/logout.php';
    exit;
}

// Login (form + handler)
if ($uri === '/backstage/login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require __DIR__ . '/backstage/auth/login-handler.php';
    } else {
        require __DIR__ . '/backstage/auth/login.php';
    }
    exit;
}

// Backstage HTML routes
if (str_starts_with($uri, '/backstage')) {
    require __DIR__ . '/backstage/router.php';
    exit;
}

// Backstage JSON proxy routes
if (str_starts_with($uri, '/api/backstage/')) {
    require __DIR__ . '/backstage/api-router.php';
    exit;
}

http_response_code(404);
echo 'Not found';
```

- [ ] **Step 2: Update `.htaccess` to route `/backstage*` and `/api/backstage*` to `backstage.php`**

Path: `C:\laragon\www\daems-platform\public\.htaccess`

```apache
RewriteEngine On

# Static files served directly
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Backstage UI + admin proxies → backstage front controller
RewriteRule ^backstage(/.*)?$ backstage.php [L,QSA]
RewriteRule ^api/backstage(/.*)?$ backstage.php [L,QSA]

# Everything else → API kernel
RewriteRule ^ index.php [L,QSA]
```

- [ ] **Step 3: Create stub routers (filled in later waves)**

Path: `C:\laragon\www\daems-platform\public\backstage\router.php`

```php
<?php
declare(strict_types=1);

http_response_code(200);
echo '<!doctype html><meta charset=utf-8><title>Backstage (WIP)</title>';
echo '<h1>Backstage migration in progress</h1>';
echo '<p>Wave A scaffold reached. URI: ' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES) . '</p>';
```

Path: `C:\laragon\www\daems-platform\public\backstage\api-router.php`

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json');
http_response_code(404);
echo json_encode(['error' => 'api_router_not_implemented']);
```

- [ ] **Step 4: Verify in browser**

```bash
# Apache reloads .htaccess on every request — no restart needed
```

Open `http://daems-platform.local/backstage` — should render the WIP page.
Open `http://daems-platform.local/api/v1/health` (or any existing API route) — should still work (API kernel untouched).

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage.php public/backstage/router.php public/backstage/api-router.php public/.htaccess
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): scaffold platform backstage front controller + .htaccess split for /backstage* and /api/backstage*"
```

---

### Task 3: Move ApiClient + I18n + MemberNumberFormatter into platform

**Files:**
- Create: `C:/laragon/www/daems-platform/src/Frontend/ApiClient.php`
- Create: `C:/laragon/www/daems-platform/src/Frontend/I18n.php`
- Create: `C:/laragon/www/daems-platform/src/Frontend/MemberNumberFormatter.php`
- Create: `C:/laragon/www/daems-platform/lang/fi_FI.php`
- Create: `C:/laragon/www/daems-platform/lang/en_GB.php`
- Create: `C:/laragon/www/daems-platform/lang/sw_TZ.php`

- [ ] **Step 1: Copy ApiClient.php verbatim to platform**

```bash
cp C:/laragon/www/sites/daem-society/src/ApiClient.php C:/laragon/www/daems-platform/src/Frontend/ApiClient.php
```

Then namespace it. Edit `C:/laragon/www/daems-platform/src/Frontend/ApiClient.php`:

After the opening `<?php\ndeclare(strict_types=1);` line, insert:

```php

namespace Daems\Frontend;

```

And inside the class, change the `class_exists('I18n', false)` check (around line 28) to:

```php
        if (class_exists(I18n::class, false)) {
            /** @var string $loc */
            $loc = I18n::locale();
            return $loc;
        }
```

Add `use` statement isn't needed because both classes share the namespace.

- [ ] **Step 2: Copy I18n.php verbatim to platform**

```bash
cp C:/laragon/www/sites/daem-society/src/I18n.php C:/laragon/www/daems-platform/src/Frontend/I18n.php
```

Edit the namespace into the file (insert `namespace Daems\Frontend;` after the strict_types line). Update the lang directory path inside I18n.php — change references from `__DIR__ . '/../lang/'` to `dirname(__DIR__, 2) . '/lang/'` so it points at `daems-platform/lang/` (not `src/lang/`). Verify by reading the file's current `__DIR__` usage and adjust accordingly.

- [ ] **Step 3: Copy MemberNumberFormatter.php**

```bash
cp C:/laragon/www/sites/daem-society/src/MemberNumberFormatter.php C:/laragon/www/daems-platform/src/Frontend/MemberNumberFormatter.php
```

Add `namespace Daems\Frontend;` after the strict_types line.

- [ ] **Step 4: Copy lang catalogues**

```bash
mkdir -p C:/laragon/www/daems-platform/lang
cp C:/laragon/www/sites/daem-society/lang/fi_FI.php C:/laragon/www/daems-platform/lang/fi_FI.php
cp C:/laragon/www/sites/daem-society/lang/en_GB.php C:/laragon/www/daems-platform/lang/en_GB.php
cp C:/laragon/www/sites/daem-society/lang/sw_TZ.php C:/laragon/www/daems-platform/lang/sw_TZ.php
```

- [ ] **Step 5: Register `Daems\Frontend\` in composer autoload**

Read `C:/laragon/www/daems-platform/composer.json`. In the `autoload.psr-4` map, add:

```json
"Daems\\Frontend\\": "src/Frontend/"
```

Then:

```bash
cd C:/laragon/www/daems-platform
composer dump-autoload
```

- [ ] **Step 6: Update `public/backstage.php` requires to use namespaced classes**

In `public/backstage.php`, replace the top-of-file `require_once` block with:

```php
require_once dirname(__DIR__) . '/vendor/autoload.php';
// Composer autoload covers Daems\Frontend\* — no manual requires needed.

define('DAEMS_BACKSTAGE_PUBLIC', __DIR__ . '/backstage');
define('DAEMS_SITE_PUBLIC', __DIR__ . '/backstage');

session_start();
\Daems\Frontend\I18n::locale();
```

- [ ] **Step 7: Verify**

Open `http://daems-platform.local/backstage` — same WIP page renders, no class-not-found errors. Inspect server logs (Laragon → Apache error log) for warnings.

```bash
cd C:/laragon/www/daems-platform
composer analyse
```

Expected: PHPStan still 0 errors (`Daems\Frontend\*` are not referenced anywhere yet, so they cannot break analysis).

- [ ] **Step 8: Commit**

```bash
cd C:/laragon/www/daems-platform
git add src/Frontend/ lang/ composer.json composer.lock public/backstage.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): port ApiClient + I18n + lang catalogues to Daems\\Frontend namespace"
```

---

### Task 4: Wire backstage role guard + session

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/_guard.php`
- Modify: `C:/laragon/www/daems-platform/public/backstage/router.php`

- [ ] **Step 1: Write the role guard**

Path: `C:\laragon\www\daems-platform\public\backstage\_guard.php`

```php
<?php
declare(strict_types=1);

/**
 * Backstage role guard — only platform admins (is_platform_admin=true) and
 * tenant admins (user_tenants.role='admin' on the active tenant) reach the UI.
 *
 * Session shape (set by /backstage/login handler):
 *   $_SESSION['user']               = associative user array from /auth/me
 *   $_SESSION['token']              = bearer token
 *   $_SESSION['user']['role']       = legacy alias (synthesized: 'admin' or 'global_system_administrator')
 *   $_SESSION['user']['is_platform_admin'] = bool
 */

$__user = $_SESSION['user'] ?? null;
$__role = is_array($__user) ? ($__user['role'] ?? '') : '';
$__isPlatformAdmin = is_array($__user) ? ($__user['is_platform_admin'] ?? false) : false;

$__isAdmin = $__isPlatformAdmin === true
          || $__role === 'admin'
          || $__role === 'global_system_administrator';

if (!$__isAdmin) {
    header('Location: /backstage/login?redirect=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/backstage')));
    exit;
}
```

- [ ] **Step 2: Update `public/backstage/router.php` to require the guard**

Path: `C:\laragon\www\daems-platform\public\backstage\router.php`

```php
<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';

http_response_code(200);
echo '<!doctype html><meta charset=utf-8><title>Backstage (WIP)</title>';
echo '<h1>Authenticated backstage scaffold</h1>';
echo '<p>User: ' . htmlspecialchars((string) ($_SESSION['user']['name'] ?? '?'), ENT_QUOTES) . '</p>';
echo '<p>URI: ' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES) . '</p>';
```

- [ ] **Step 3: Verify**

Open `http://daems-platform.local/backstage` in a private window — should redirect to `/backstage/login?redirect=/backstage` (which 404s for now; login form added in Task 13). The redirect itself confirms the guard runs.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/_guard.php public/backstage/router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): role guard redirects unauthenticated visitors to /backstage/login"
```

---

## Wave B — Move shared chrome (layout, dashboard, partials)

### Task 5: Move shared partials + layout from society to platform

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/_shared.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/layout.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/index.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/partials/metric-card.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/partials/sub-page-card.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/shared/empty-state.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/shared/locale-cards.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/shared/sub-page-card.css`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/shared/locale-cards.css`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/shared/locale-cards.js`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/toasts.css`
- Create: `C:/laragon/www/daems-platform/public/backstage/pages/toasts.js`

- [ ] **Step 1: Copy the entire backstage pages tree from society**

```bash
mkdir -p C:/laragon/www/daems-platform/public/backstage/pages
cp -r C:/laragon/www/sites/daem-society/public/pages/backstage/* C:/laragon/www/daems-platform/public/backstage/pages/
```

- [ ] **Step 2: Update `_shared.php` to point ApiClient require at the new location**

Edit `C:\laragon\www\daems-platform\public\backstage\pages\_shared.php`. The original references `DAEMS_SITE_PUBLIC . '/../src/ApiClient.php'`. After migration, `DAEMS_SITE_PUBLIC` resolves to `daems-platform/public/backstage`, so `../src/ApiClient.php` would resolve to `daems-platform/public/src/ApiClient.php` — wrong.

Replace the require block:

```php
if (!class_exists(\Daems\Frontend\ApiClient::class)) {
    // Composer autoload should already cover this — keep the check defensive.
    require_once dirname(__DIR__, 3) . '/src/Frontend/ApiClient.php';
}
```

Also replace the `daems_shared_partial()` body's `realpath(__DIR__ . '/../../../../../modules/shared')` with the new hop count from `daems-platform/public/backstage/pages/`:

```php
// _shared.php sits at daems-platform/public/backstage/pages/, so four ..
// segments hop up to C:\laragon\www\ and into modules/shared from there.
$base = realpath(__DIR__ . '/../../../../modules/shared');
```

- [ ] **Step 3: Update `layout.php` asset URLs**

Edit `C:\laragon\www\daems-platform\public\backstage\pages\layout.php`. Society served assets at `/assets/css/*` and `/pages/backstage/toasts.css`. In platform, backstage assets live at `/backstage/assets/*` and `/backstage/pages/toasts.css`.

Use a single `replace_all` per filename pattern. Replace these substrings throughout `layout.php`:

| Old | New |
|-----|-----|
| `href="/assets/css/daems-backstage` | `href="/backstage/assets/css/daems-backstage` |
| `href="/assets/css/bootstrap` | `href="/backstage/assets/css/bootstrap` |
| `href="/assets/css/daems-search` | `href="/backstage/assets/css/daems-search` |
| `href="/assets/img/brand/daems-favicon.svg"` | `href="/backstage/assets/img/brand/daems-favicon.svg"` |
| `href="/pages/backstage/toasts.css"` | `href="/backstage/pages/toasts.css"` |
| `href="/shared/` | `href="/modules-shared/` (placeholder — final wiring in Task 7) |
| `src="/shared/` | `src="/modules-shared/` |
| `src="/assets/js/daems-backstage` | `src="/backstage/assets/js/daems-backstage` |
| `src="/assets/js/daems-search` | `src="/backstage/assets/js/daems-search` |
| `src="/assets/js/bootstrap` | `src="/backstage/assets/js/bootstrap` |
| `src="/assets/js/qrcode-generator` | `src="/backstage/assets/js/qrcode-generator` |
| `src="/pages/backstage/toasts.js"` | `src="/backstage/pages/toasts.js"` |

Also update the `__avatarDisk = __DIR__ . '/../../uploads/avatars/'` line. New path from `daems-platform/public/backstage/pages/` to platform's `public/uploads/avatars/` is `__DIR__ . '/../../uploads/avatars/'` — same relative depth, so the line stays as-is. Verify by computing: `daems-platform/public/backstage/pages/../../uploads/avatars` = `daems-platform/public/uploads/avatars`. Correct.

- [ ] **Step 4: Run a grep sweep for any other `__DIR__ . '/../...'` paths that crossed dir-depth boundaries**

```bash
grep -rn "__DIR__" C:/laragon/www/daems-platform/public/backstage/pages/ 2>&1
```

For each hit, verify the resolved path makes sense from the new location. Adjust hop counts as needed. Common offenders: `index.php` (dashboard) likely has `__DIR__ . '/../../../src/ApiClient.php'`. Update all such references to use composer autoload (drop the require) or to `dirname(__DIR__, 3) . '/src/Frontend/ApiClient.php'`.

- [ ] **Step 5: Wire `pages/index.php` (dashboard) into the router**

Replace `public/backstage/router.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');
$sub = rtrim($uri === '/backstage' ? '' : substr($uri, 10), '/');

$map = [
    '' => __DIR__ . '/pages/index.php',
];

if (isset($map[$sub]) && is_file($map[$sub])) {
    require $map[$sub];
    exit;
}

http_response_code(404);
echo 'Not found';
```

- [ ] **Step 6: Verify in browser (use a session you know is admin)**

You'll need a logged-in admin session at `daems-platform.local`. Quick path: paste the session cookie from a working society login, OR add Task 13's login form first and circle back.

For Wave B verification, **temporarily** stub the guard to a hardcoded admin session by editing `_guard.php` to skip the redirect when `$_GET['_dev_skip_guard'] === '1'`. Open `http://daems-platform.local/backstage?_dev_skip_guard=1` and confirm the dashboard renders. Revert the dev skip after verification (or remove it before committing).

- [ ] **Step 7: PHPStan + commit**

```bash
cd C:/laragon/www/daems-platform
composer analyse
```

Expected: 0 errors (the new files use ApiClient via the legacy `ApiClient::get(...)` global-class style. PHPStan won't see this as it's a string-name reference. Add a `phpstan-baseline.neon` ignore only if a real type-resolution error appears.)

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): move dashboard + layout + shared partials from society to platform"
```

---

### Task 6: Move backstage CSS/JS assets

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/assets/css/daems-backstage.css` (and 4 siblings)
- Create: `C:/laragon/www/daems-platform/public/backstage/assets/js/daems-backstage.js` (and 4 siblings)
- Create: `C:/laragon/www/daems-platform/public/backstage/assets/img/` (subset)
- Create: `C:/laragon/www/daems-platform/public/backstage/assets/fonts/` (whole dir)

- [ ] **Step 1: Copy backstage-only CSS**

```bash
mkdir -p C:/laragon/www/daems-platform/public/backstage/assets/css
cp C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage.css C:/laragon/www/daems-platform/public/backstage/assets/css/
cp C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css C:/laragon/www/daems-platform/public/backstage/assets/css/
cp C:/laragon/www/sites/daem-society/public/assets/css/daems-search.css C:/laragon/www/daems-platform/public/backstage/assets/css/
cp C:/laragon/www/sites/daem-society/public/assets/css/bootstrap.min.css C:/laragon/www/daems-platform/public/backstage/assets/css/
cp C:/laragon/www/sites/daem-society/public/assets/css/bootstrap-icons.min.css C:/laragon/www/daems-platform/public/backstage/assets/css/
```

- [ ] **Step 2: Copy backstage-only JS**

```bash
mkdir -p C:/laragon/www/daems-platform/public/backstage/assets/js
cp C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage.js C:/laragon/www/daems-platform/public/backstage/assets/js/
cp C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-dashboard.js C:/laragon/www/daems-platform/public/backstage/assets/js/
cp C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-system.js C:/laragon/www/daems-platform/public/backstage/assets/js/
cp C:/laragon/www/sites/daem-society/public/assets/js/daems-search.js C:/laragon/www/daems-platform/public/backstage/assets/js/
cp C:/laragon/www/sites/daem-society/public/assets/js/bootstrap.bundle.min.js C:/laragon/www/daems-platform/public/backstage/assets/js/
cp C:/laragon/www/sites/daem-society/public/assets/js/qrcode-generator.js C:/laragon/www/daems-platform/public/backstage/assets/js/
```

- [ ] **Step 3: Copy fonts + brand images used by backstage**

```bash
mkdir -p C:/laragon/www/daems-platform/public/backstage/assets/img/brand
cp -r C:/laragon/www/sites/daem-society/public/assets/fonts C:/laragon/www/daems-platform/public/backstage/assets/
cp C:/laragon/www/sites/daem-society/public/assets/img/brand/daems-favicon.svg C:/laragon/www/daems-platform/public/backstage/assets/img/brand/
```

If layout.php or any backstage page references additional `/assets/img/*` files, copy those too. Sweep:

```bash
grep -rn '/assets/img/' C:/laragon/www/daems-platform/public/backstage/ 2>&1 | head -30
grep -rn '/assets/img/' C:/laragon/www/modules/*/frontend/backstage/ 2>&1 | head -30
```

Copy each referenced file into `daems-platform/public/backstage/assets/img/` preserving subdir structure. Update those references from `/assets/img/...` to `/backstage/assets/img/...`. (Module backstage files are touched in Wave E — defer their `/assets/*` rewrites to Task 18.)

- [ ] **Step 4: Verify the dashboard renders with styles**

Open `http://daems-platform.local/backstage?_dev_skip_guard=1` (or with a real session) — sidebar, KPI strip, fonts, icons should all render. Check DevTools Network tab for any 404'd asset requests.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/assets/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): copy CSS, JS, fonts, brand images into platform/public/backstage/assets"
```

---

### Task 7: Mount `modules/shared/*` at `/modules-shared/*`

**Files:**
- Modify: `C:/laragon/www/daems-platform/public/backstage.php`

- [ ] **Step 1: Add a shared-modules passthrough in `backstage.php`**

In `public/backstage.php`, before the `if (str_starts_with($uri, '/backstage'))` block, insert:

```php
// modules/shared/* → /modules-shared/* (DatePicker, TimePicker, KpiCard, etc.)
if (str_starts_with($uri, '/modules-shared/')) {
    $rel = substr($uri, strlen('/modules-shared/'));
    $base = realpath(dirname(__DIR__, 2) . '/modules/shared');
    $file = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($base !== false && $file !== false && str_starts_with($file, $base) && is_file($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
                 'png' => 'image/png', 'json' => 'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// modules/<name>/frontend/assets/* → /modules/<name>/assets/*
if (preg_match('#^/modules/([a-z][a-z0-9-]*)/assets/(.+)$#', $uri, $m)) {
    $module = $m[1];
    $rel = $m[2];
    $base = realpath(dirname(__DIR__, 2) . '/modules/' . $module . '/frontend/assets');
    $file = $base !== false ? realpath($base . '/' . $rel) : false;
    if ($base !== false && $file !== false && str_starts_with($file, $base) && is_file($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
                 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                 'json' => 'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}
```

- [ ] **Step 2: Update `.htaccess` to also pass these paths to backstage.php**

In `public/.htaccess`, after the existing `RewriteRule ^api/backstage(/.*)?$` line, add:

```apache
RewriteRule ^modules-shared(/.*)?$ backstage.php [L,QSA]
RewriteRule ^modules/[^/]+/assets(/.*)?$ backstage.php [L,QSA]
```

- [ ] **Step 3: Verify a known shared asset loads**

Open `http://daems-platform.local/modules-shared/date-picker/date-picker.css` directly — should serve the CSS file (HTTP 200).
Open `http://daems-platform.local/modules-shared/components/cards/kpi-card/kpi-card.css` — should serve.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage.php public/.htaccess
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): serve modules/shared/* at /modules-shared/* and modules/<n>/assets/* at /modules/<n>/assets/*"
```

---

### Task 8: Browser smoke wave-B (dashboard only)

**Files:** none

- [ ] **Step 1: Walk the dashboard with real auth**

If Task 13's login isn't built yet, ALSO add a temporary admin-session bootstrap at the top of `_guard.php`:

```php
// REMOVE before Task 13 — temporary dev auth.
if (empty($_SESSION['user']) && ($_GET['_dev_admin'] ?? null) === '1') {
    $_SESSION['user'] = ['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'Dev Admin', 'role' => 'admin', 'is_platform_admin' => true];
    $_SESSION['token'] = 'dev-fake-token';
}
```

Note: this won't authenticate against the API. Skip steps that require live API data; verify only chrome rendering.

Open `http://daems-platform.local/backstage?_dev_admin=1`. Verify:
- Sidebar renders with all nav items
- KPI strip placeholder draws (numbers will be 0 since fake token can't fetch)
- No 404s in Network tab
- No console JS errors that aren't already present in society's version

- [ ] **Step 2: Remove the dev shims**

Delete the `_dev_skip_guard` and `_dev_admin` blocks from `_guard.php`.

- [ ] **Step 3: Commit shim removal**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/_guard.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(backstage): dev-mode guard shims; real auth lands in Task 13"
```

---

## Wave C — Move platform-specific pages

### Task 9: Wire notifications page

**Files:**
- Modify: `C:/laragon/www/daems-platform/public/backstage/router.php`

The directory `public/backstage/pages/notifications/` already exists from Task 5's recursive copy. Just route it.

- [ ] **Step 1: Update router map**

Edit `public/backstage/router.php`. Replace the `$map` array with:

```php
$map = [
    ''                    => __DIR__ . '/pages/index.php',
    '/notifications'      => __DIR__ . '/pages/notifications/index.php',
];
```

- [ ] **Step 2: Sweep notifications/index.php for path issues**

```bash
grep -rn "__DIR__\|/assets/\|/shared/\|/pages/backstage/" C:/laragon/www/daems-platform/public/backstage/pages/notifications/index.php
```

Adjust hop counts and rewrite asset URLs the same way as Task 5/Step 4. Pay attention to:
- `notifications.css` and `notifications.js` references — they live at `/backstage/pages/notifications/*.css|js` now (relative to web root).
- `notifications-stats.js` likewise.

- [ ] **Step 3: Verify in browser**

Open `http://daems-platform.local/backstage/notifications` — page renders, stats list, no 404s.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): wire /backstage/notifications in platform router"
```

---

### Task 10: Wire search page

**Files:**
- Modify: `C:/laragon/www/daems-platform/public/backstage/router.php`

- [ ] **Step 1: Add `/search` to router map**

```php
$map = [
    ''                    => __DIR__ . '/pages/index.php',
    '/notifications'      => __DIR__ . '/pages/notifications/index.php',
    '/search'             => __DIR__ . '/pages/search/index.php',
];
```

- [ ] **Step 2: Sweep `pages/search/index.php` for path issues**

Same as Task 9/Step 2. Likely uses `daems-search.js` and `daems-search.css` — those are already at `/backstage/assets/{js,css}/daems-search.*`.

- [ ] **Step 3: Verify**

Open `http://daems-platform.local/backstage/search?q=test` — search results load (or empty state if no data; the proxy isn't built yet so expect zero hits).

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): wire /backstage/search in platform router"
```

---

### Task 11: Wire settings page

**Files:**
- Modify: `C:/laragon/www/daems-platform/public/backstage/router.php`

- [ ] **Step 1: Add `/settings` to router map**

```php
$map = [
    ''                    => __DIR__ . '/pages/index.php',
    '/notifications'      => __DIR__ . '/pages/notifications/index.php',
    '/search'             => __DIR__ . '/pages/search/index.php',
    '/settings'           => __DIR__ . '/pages/settings/index.php',
];
```

- [ ] **Step 2: Sweep `pages/settings/index.php` for paths**

Same routine. The settings page also renders `settings.css` from the same dir (`/backstage/pages/settings/settings.css`).

- [ ] **Step 3: Verify**

Open `http://daems-platform.local/backstage/settings` — tenant settings panel renders with current values. Saving the form will fail until Task 17 (tenant-settings proxy migrated).

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): wire /backstage/settings in platform router"
```

---

### Task 12: Wire project-proposals page

**Files:**
- Modify: `C:/laragon/www/daems-platform/public/backstage/router.php`

- [ ] **Step 1: Add `/project-proposals` to map**

```php
$map = [
    ''                    => __DIR__ . '/pages/index.php',
    '/notifications'      => __DIR__ . '/pages/notifications/index.php',
    '/search'             => __DIR__ . '/pages/search/index.php',
    '/settings'           => __DIR__ . '/pages/settings/index.php',
    '/project-proposals'  => __DIR__ . '/pages/project-proposals/index.php',
];
```

- [ ] **Step 2: Sweep `pages/project-proposals/index.php`**

Look for `proposal-modal.css|js` references — they live next to the page (`/backstage/pages/project-proposals/proposal-modal.*`).

- [ ] **Step 3: Verify**

Open `http://daems-platform.local/backstage/project-proposals` — proposals list (empty or populated) renders without 404s.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): wire /backstage/project-proposals in platform router"
```

---

## Wave D — Move proxy endpoints + login

### Task 13: Add `/backstage/login` form + handler

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/auth/login.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/auth/login-handler.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/auth/logout.php`

- [ ] **Step 1: Write login HTML form**

Path: `C:\laragon\www\daems-platform\public\backstage\auth\login.php`

```php
<?php
declare(strict_types=1);

$error = $_GET['error'] ?? null;
$redirect = (string) ($_GET['redirect'] ?? '/backstage');
?>
<!doctype html>
<html lang="fi" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backstage login — Daems</title>
<link rel="stylesheet" href="/backstage/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/backstage/assets/css/daems-backstage.css">
<style>body{display:grid;place-items:center;min-height:100vh}form{max-width:360px;width:100%}</style>
</head>
<body>
<form method="post" action="/backstage/login" class="card p-4 shadow-sm">
  <h1 class="h4 mb-3">Backstage</h1>
  <?php if ($error): ?>
    <div class="alert alert-danger small"><?= htmlspecialchars((string) $error, ENT_QUOTES) ?></div>
  <?php endif; ?>
  <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES) ?>">
  <label class="form-label">Email</label>
  <input class="form-control mb-3" type="email" name="email" required autofocus>
  <label class="form-label">Password</label>
  <input class="form-control mb-3" type="password" name="password" required>
  <button class="btn btn-primary w-100" type="submit">Sign in</button>
</form>
</body>
</html>
```

- [ ] **Step 2: Write login POST handler**

Path: `C:\laragon\www\daems-platform\public\backstage\auth\login-handler.php`

```php
<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;

$email    = (string) ($_POST['email']    ?? '');
$password = (string) ($_POST['password'] ?? '');
$redirect = (string) ($_POST['redirect'] ?? '/backstage');

$result = ApiClient::post('/auth/login', ['email' => $email, 'password' => $password]);
$payload = is_array($result) ? ($result['body']['data'] ?? null) : null;
$user = null;
if (is_array($payload)) {
    $user = $payload['user'] ?? ($payload['id'] ?? null ? $payload : null);
}

if (!is_array($result) || $result['status'] !== 200 || !is_array($user)) {
    header('Location: /backstage/login?error=' . rawurlencode('Invalid email or password') . '&redirect=' . rawurlencode($redirect));
    exit;
}

$token = is_array($payload) ? ($payload['token'] ?? null) : null;

// Enrich session with /auth/me — synthesize legacy 'role' field for layout guard
$tenantRole = null;
$tenantData = null;
$meUser     = null;
if (is_string($token)) {
    $_SESSION['token'] = $token;
    $me = ApiClient::get('/auth/me');
    if (is_array($me)) {
        if (is_string($me['role_in_tenant'] ?? null)) $tenantRole = $me['role_in_tenant'];
        if (is_array($me['tenant'] ?? null))           $tenantData = $me['tenant'];
        if (is_array($me['user']   ?? null))           $meUser     = $me['user'];
    }
}

$user = is_array($meUser) ? $meUser : $user;
$user['is_platform_admin'] = $user['is_platform_admin'] ?? false;
$user['role'] = $user['is_platform_admin'] ? 'global_system_administrator' : ($tenantRole ?? 'registered');
if ($tenantData !== null) $user['tenant'] = $tenantData;

$_SESSION['user'] = $user;

// Only allow internal redirect targets
$path = parse_url($redirect, PHP_URL_PATH);
$safe = is_string($path) && str_starts_with($path, '/backstage') ? $redirect : '/backstage';
header('Location: ' . $safe);
exit;
```

- [ ] **Step 3: Write logout**

Path: `C:\laragon\www\daems-platform\public\backstage\auth\logout.php`

```php
<?php
declare(strict_types=1);

unset($_SESSION['user'], $_SESSION['token'], $_SESSION['expires_at'], $_SESSION['view_as_role']);
session_regenerate_id(true);
header('Location: /backstage/login');
exit;
```

- [ ] **Step 4: Verify the round-trip**

Open `http://daems-platform.local/backstage` (private window). Should land on `/backstage/login`. Submit known admin creds. Should land on dashboard. Click logout → back to login.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/auth/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): /backstage/login form, POST handler enriching session with /auth/me, and /backstage/logout"
```

---

### Task 14: Move JSON proxies — applications, members, notifications, search, dismiss

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/api/applications.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/members.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/notifications.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/search.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/dismiss.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api-router.php` (real version)

- [ ] **Step 1: Copy proxy files verbatim**

```bash
mkdir -p C:/laragon/www/daems-platform/public/backstage/api
cp C:/laragon/www/sites/daem-society/public/api/backstage/applications.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/members.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/notifications.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/search.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/dismiss.php C:/laragon/www/daems-platform/public/backstage/api/
```

- [ ] **Step 2: Replace `ApiClient` requires in each copied file**

Each file has a top block like:

```php
if (!class_exists('ApiClient')) {
    require_once __DIR__ . '/../../../src/ApiClient.php';
}
```

Replace with:

```php
if (!class_exists(\Daems\Frontend\ApiClient::class)) {
    require_once dirname(__DIR__, 3) . '/src/Frontend/ApiClient.php';
}
use Daems\Frontend\ApiClient;
```

(The `use` import lets the existing `ApiClient::get(...)` calls keep working unchanged.)

- [ ] **Step 3: Write the real api-router.php**

Path: `C:\laragon\www\daems-platform\public\backstage\api-router.php`

```php
<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$map = [
    '/api/backstage/applications'    => __DIR__ . '/api/applications.php',
    '/api/backstage/members'         => __DIR__ . '/api/members.php',
    '/api/backstage/notifications'   => __DIR__ . '/api/notifications.php',
    '/api/backstage/search'          => __DIR__ . '/api/search.php',
    '/api/backstage/dismiss'         => __DIR__ . '/api/dismiss.php',
    // Wave-D additions append below as proxies migrate.
];

if (isset($map[$uri]) && is_file($map[$uri])) {
    require $map[$uri];
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'unknown_proxy', 'uri' => $uri]);
```

- [ ] **Step 4: Verify each proxy with curl**

```bash
# Applications stats (must be authenticated — easiest is in-browser)
curl -i -b "PHPSESSID=<known-good-session>" "http://daems-platform.local/api/backstage/applications?op=stats"
```

Expected: `200 OK` with `{"data":{...}}`. Repeat for members (`?op=stats`), notifications, search (`?q=foo`), dismiss (POST).

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/api/ public/backstage/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): port applications/members/notifications/search/dismiss proxies + api-router"
```

---

### Task 15: Move JSON proxies — events, projects, proposals, event-upload

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/api/events.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/projects.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/proposals.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/event-upload.php`
- Modify: `C:/laragon/www/daems-platform/public/backstage/api-router.php`

- [ ] **Step 1: Copy + namespace-fix four files**

```bash
cp C:/laragon/www/sites/daem-society/public/api/backstage/events.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/projects.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/proposals.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/event-upload.php C:/laragon/www/daems-platform/public/backstage/api/
```

In each file, apply the same ApiClient namespace-fix from Task 14 Step 2.

`event-upload.php` proxies a multipart upload. Verify it forwards `$_FILES` correctly — society's version uses `CURLFile`. No code change needed beyond the require/use rewrite.

- [ ] **Step 2: Add to api-router map**

Append to the `$map` array in `api-router.php`:

```php
    '/api/backstage/events'          => __DIR__ . '/api/events.php',
    '/api/backstage/projects'        => __DIR__ . '/api/projects.php',
    '/api/backstage/proposals'       => __DIR__ . '/api/proposals.php',
    '/api/backstage/event-upload'    => __DIR__ . '/api/event-upload.php',
```

- [ ] **Step 3: Verify with curl + browser**

Open `/backstage/events` (will route via Wave E module mount in Task 18 — defer full test, but the JSON proxy itself must respond):

```bash
curl -i -b "PHPSESSID=..." "http://daems-platform.local/api/backstage/events?op=list"
curl -i -b "PHPSESSID=..." "http://daems-platform.local/api/backstage/projects?op=list"
curl -i -b "PHPSESSID=..." "http://daems-platform.local/api/backstage/proposals?op=list"
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/api/ public/backstage/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): port events/projects/proposals/event-upload proxies"
```

---

### Task 16: Move JSON proxies — forum, insights

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/api/forum.php`
- Create: `C:/laragon/www/daems-platform/public/backstage/api/insights.php`
- Modify: `C:/laragon/www/daems-platform/public/backstage/api-router.php`

- [ ] **Step 1: Copy + namespace-fix**

```bash
cp C:/laragon/www/sites/daem-society/public/api/backstage/forum.php C:/laragon/www/daems-platform/public/backstage/api/
cp C:/laragon/www/sites/daem-society/public/api/backstage/insights.php C:/laragon/www/daems-platform/public/backstage/api/
```

Apply Task 14/Step 2 namespace fix.

- [ ] **Step 2: Add to api-router map**

```php
    '/api/backstage/forum'           => __DIR__ . '/api/forum.php',
    '/api/backstage/insights'        => __DIR__ . '/api/insights.php',
```

- [ ] **Step 3: Verify**

```bash
curl -i -b "PHPSESSID=..." "http://daems-platform.local/api/backstage/forum?op=stats"
curl -i -b "PHPSESSID=..." "http://daems-platform.local/api/backstage/insights?op=list"
```

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/api/ public/backstage/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): port forum + insights proxies"
```

---

### Task 17: Move tenant-settings proxy + miscellaneous proxies

**Files:**
- Create: `C:/laragon/www/daems-platform/public/backstage/api/tenant-settings.php`
- Modify: `C:/laragon/www/daems-platform/public/backstage/api-router.php`

- [ ] **Step 1: Copy + namespace-fix**

```bash
cp C:/laragon/www/sites/daem-society/public/api/backstage/tenant-settings.php C:/laragon/www/daems-platform/public/backstage/api/
```

Apply namespace fix.

- [ ] **Step 2: Add to api-router map (POST-only entry)**

```php
    '/api/backstage/tenant-settings' => __DIR__ . '/api/tenant-settings.php',
```

The original society router gated this on POST. Either replicate the gate inside `tenant-settings.php` (preferred — keeps the proxy self-contained) or check `$method` in `api-router.php` before requiring. Use the in-file gate:

At the top of `tenant-settings.php`, after the require/use block, add:

```php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}
```

- [ ] **Step 3: End-to-end verify settings page**

Open `http://daems-platform.local/backstage/settings`. Edit a setting. Submit. Reload — value should persist. (This requires Wave-D auth + tenant context to be working end-to-end. If the save fails, dig with browser DevTools → Network → /api/backstage/tenant-settings response body.)

- [ ] **Step 4: Smoke-walk every backstage page that has data**

For each of: dashboard, notifications, search, settings, project-proposals — open the page and confirm KPI cards / lists / tables all populate. (Module pages still 404; covered in Wave E.)

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage/api/tenant-settings.php public/backstage/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): port tenant-settings proxy with explicit POST gate"
```

---

## Wave E — Mount module backstage pages

### Task 18: Add module discovery + page router to backstage front controller

**Files:**
- Modify: `C:/laragon/www/daems-platform/public/backstage.php`
- Modify: `C:/laragon/www/daems-platform/public/backstage/router.php`

- [ ] **Step 1: Add module discovery to `backstage.php`**

In `public/backstage.php`, after the `define('DAEMS_SITE_PUBLIC', ...)` line and before `session_start()`, insert the same module-scan block society uses:

```php
// Discover modules (filesystem scan of ../../modules/*/module.json)
$daemsKnownModules = [];
$modulesBase = realpath(dirname(__DIR__, 2) . '/modules');
if ($modulesBase !== false && is_dir($modulesBase)) {
    foreach ((array) glob($modulesBase . '/*/module.json') as $manifestPath) {
        if (!is_string($manifestPath)) continue;
        $data = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) continue;
        $name = $data['name'];
        $modDir = dirname($manifestPath);
        $backstageDir = isset($data['frontend']['backstage_pages'])
                      ? $modDir . '/' . $data['frontend']['backstage_pages'] : null;
        $daemsKnownModules[$name] = [
            'backstage' => $backstageDir !== null ? rtrim($backstageDir, '/\\') : null,
        ];
    }
}
$GLOBALS['daemsKnownModules'] = $daemsKnownModules;
```

- [ ] **Step 2: Update `router.php` to dispatch to module backstage dirs**

Edit `public/backstage/router.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';

$uri = strtok(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '/')), '?');
$daemsKnownModules = $GLOBALS['daemsKnownModules'] ?? [];

// Module page router — /backstage/<module>/<sub-path>?
if (preg_match('#^/backstage/([a-z][a-z0-9-]*)(/.*)?$#', $uri, $m)
    && isset($daemsKnownModules[$m[1]])
    && $daemsKnownModules[$m[1]]['backstage'] !== null
) {
    $module = $m[1];
    $sub = ltrim($m[2] ?? '', '/');
    $base = realpath($daemsKnownModules[$module]['backstage']);
    if ($base !== false) {
        $candidates = [];
        if ($sub === '') {
            $candidates[] = $base . '/index.php';
        } else {
            $candidates[] = $base . '/' . $sub . '/index.php';
            $candidates[] = $base . '/' . $sub . '.php';
        }
        foreach ($candidates as $cand) {
            $real = realpath($cand);
            if ($real !== false && str_starts_with($real, $base) && is_file($real)) {
                require $real;
                exit;
            }
        }
    }
}

// Legacy /backstage/applications redirect — kept for compat (members module owns the file)
$sub = rtrim($uri === '/backstage' ? '' : substr($uri, 10), '/');

if ($sub === '/applications' && isset($daemsKnownModules['members']['backstage'])) {
    $appsFile = $daemsKnownModules['members']['backstage'] . '/applications/index.php';
    if (is_file($appsFile)) { require $appsFile; exit; }
}

$map = [
    ''                    => __DIR__ . '/pages/index.php',
    '/notifications'      => __DIR__ . '/pages/notifications/index.php',
    '/search'             => __DIR__ . '/pages/search/index.php',
    '/settings'           => __DIR__ . '/pages/settings/index.php',
    '/project-proposals'  => __DIR__ . '/pages/project-proposals/index.php',
];

if (isset($map[$sub]) && is_file($map[$sub])) {
    require $map[$sub];
    exit;
}

http_response_code(404);
echo 'Not found';
```

- [ ] **Step 3: Verify module pages render**

Open each:
- `http://daems-platform.local/backstage/members`
- `http://daems-platform.local/backstage/applications` (legacy redirect → members)
- `http://daems-platform.local/backstage/events`
- `http://daems-platform.local/backstage/forum`
- `http://daems-platform.local/backstage/insights`
- `http://daems-platform.local/backstage/projects`

For each, check Network tab for 404'd assets. Expected: most assets resolve via `/modules-shared/*` and `/modules/<name>/assets/*` mounts from Task 7.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/daems-platform
git add public/backstage.php public/backstage/router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): discover + dispatch module backstage pages from platform router"
```

---

### Task 19: Fix module backstage references that hardcode society paths

**Files:**
- Modify: any module backstage file that references `/assets/*` or hardcodes `daem-society` paths

- [ ] **Step 1: Inventory hardcoded references**

```bash
grep -rn "daem-society\|/assets/css/\|/assets/js/\|/assets/img/" C:/laragon/www/modules/*/frontend/backstage/ 2>&1 | head -50
```

For each hit, decide:
- `/assets/css/*` references inside module backstage layout-includes → likely already pulled via `layout.php` in platform's backstage chrome (which uses `/backstage/assets/*`). If a module includes its own `<link>` tags, rewrite to `/backstage/assets/*`.
- `daem-society` literal strings → likely error message text or comment. Update to `daems-platform`.
- `DAEMS_SITE_PUBLIC . '/pages/backstage/layout.php'` → in platform, `DAEMS_SITE_PUBLIC` resolves to `daems-platform/public/backstage`, so `pages/backstage/layout.php` becomes `pages/layout.php` (note the path collapse). Update those requires:

  Old:
  ```php
  require DAEMS_SITE_PUBLIC . '/pages/backstage/layout.php';
  ```

  New:
  ```php
  require DAEMS_SITE_PUBLIC . '/pages/layout.php';
  ```

  Apply this rewrite in each module backstage file. List from earlier grep included references at line 1054 of `modules/members/frontend/backstage/index.php` plus the corresponding lines in events/forum/insights/projects.

- [ ] **Step 2: Apply edits**

For each file in the list, run `Edit` (or sed) to replace `/pages/backstage/layout.php` with `/pages/layout.php` (only in module backstage files, NOT in society's copy).

Modules repos to commit changes in: `modules/events`, `modules/forum`, `modules/insights`, `modules/members`, `modules/projects`. Each is a separate git repo — commit per-module.

```bash
for mod in events forum insights members projects; do
  cd C:/laragon/www/modules/$mod
  git checkout -b backstage-to-platform
  git add .
  git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update($mod): rewrite DAEMS_SITE_PUBLIC layout require for platform-hosted backstage" || true
done
```

- [ ] **Step 3: Re-verify all module pages**

Smoke-walk all six module backstage entry points again. They should render with sidebar, KPI strips, and live data.

- [ ] **Step 4: Verify in platform (no commit if module-only changes)**

```bash
cd C:/laragon/www/daems-platform
composer analyse
composer test:all
```

Both green. No commit needed in platform unless a module-discovery glob path changed.

---

### Task 20: Wave-E browser smoke (full backstage walkthrough)

**Files:** none (manual QA)

- [ ] **Step 1: Walk every page**

For tenant `daems` at `http://daems-platform.local/backstage`:

| URL | Expected |
|-----|----------|
| `/backstage` | Dashboard with KPI cards, charts, recent activity |
| `/backstage/members` | Register table; KPI strip at top |
| `/backstage/members?view=pending` | Pending applications list with Approve/Reject |
| `/backstage/applications` | Same as members?view=pending (legacy redirect) |
| `/backstage/events` | Events list with Create button |
| `/backstage/events/new` | New-event form |
| `/backstage/event-proposals` | Event proposals list |
| `/backstage/projects` | Projects list |
| `/backstage/projects/new` | New-project form |
| `/backstage/project-proposals` | Project proposals list |
| `/backstage/forum` | Forum admin index (categories/topics/reports/audit subs) |
| `/backstage/insights` | Insights list with Create |
| `/backstage/insights/new` | New-insight form |
| `/backstage/notifications` | Notifications stats |
| `/backstage/search?q=test` | Search results |
| `/backstage/settings` | Tenant settings form (saves persist) |

- [ ] **Step 2: Smoke key write paths**

- Create + delete a test event
- Approve a test member application (or undo via decided list)
- Create + archive a test project
- Edit + save a test forum category
- Save tenant time-format setting; reload; verify persists

- [ ] **Step 3: If any page is broken, halt and fix before proceeding to Wave F**

Wave F deletes the society-side copy. Do not delete until platform-side is fully green.

---

## Wave F — Society cleanup + redirect

### Task 21: Strip backstage routing from society's `index.php`

**Files:**
- Modify: `C:/laragon/www/sites/daem-society/public/index.php`

- [ ] **Step 1: Delete the backstage routing block**

In `public/index.php`, delete:
- Lines 252–321 (all `if ($uri === '/api/backstage/...')` proxy blocks AND the inline `/api/backstage/member-growth` block)
- Lines 378–409 (`if (preg_match('#^/backstage(/.*)?$#', $uri))` block)
- The `daems_shared_partial` `_shared.php` require near line 20 (`require_once __DIR__ . '/pages/backstage/_shared.php';`)

- [ ] **Step 2: Add a 301 redirect for any `/backstage*` hit**

Insert before the route table (around line 411):

```php
// Backstage migrated to platform — redirect to the per-host platform UI.
// Convention: <tenant>-platform.local hosts the backstage. The society host
// itself doesn't host backstage anymore; we redirect to the daems platform
// host since this is the daems tenant's frontend.
if (str_starts_with($uri, '/backstage')) {
    $platformHost = 'daems-platform.local';  // adjust per-tenant if society serves multiple tenants
    header('Location: http://' . $platformHost . $uri, true, 301);
    exit;
}
if (str_starts_with($uri, '/api/backstage/')) {
    header('Location: http://daems-platform.local' . $uri, true, 301);
    exit;
}
```

- [ ] **Step 3: Verify**

Open `http://daem-society.local/backstage` — should 301 to `http://daems-platform.local/backstage`. Check `curl -i` confirms `Location:` header.

Open `http://daem-society.local/` — public site still loads.

Open `http://daem-society.local/projects/<slug>` — public project detail still loads.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git add public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(society): backstage routes + proxies; 301 /backstage* and /api/backstage/* to daems-platform.local"
```

---

### Task 22: Delete backstage files from society

**Files:**
- Delete: `C:/laragon/www/sites/daem-society/public/pages/backstage/` (whole tree)
- Delete: `C:/laragon/www/sites/daem-society/public/api/backstage/` (whole tree)
- Delete: `C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage.css`
- Delete: `C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css`
- Delete: `C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage.js`
- Delete: `C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-dashboard.js`
- Delete: `C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-system.js`

`daems-search.css` and `daems-search.js` are used by the **public** search page (`/search?q=...`), so they STAY in society. Verify before deleting:

```bash
grep -rn "daems-search" C:/laragon/www/sites/daem-society/public/ 2>&1
```

If hits include `pages/search/` or `partials/top-nav.php`, keep both files in society.

- [ ] **Step 1: Delete backstage trees**

```bash
rm -rf C:/laragon/www/sites/daem-society/public/pages/backstage
rm -rf C:/laragon/www/sites/daem-society/public/api/backstage
```

- [ ] **Step 2: Delete backstage-only CSS/JS**

```bash
rm -f C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage.css
rm -f C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css
rm -f C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage.js
rm -f C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-dashboard.js
rm -f C:/laragon/www/sites/daem-society/public/assets/js/daems-backstage-system.js
```

- [ ] **Step 3: Verify society public site still works**

Open the homepage, `/about`, `/projects`, `/events`, `/forum/<category>`, `/insights/<slug>`, `/profile`. None should 500. None should reference deleted assets.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git add -A public/pages/backstage public/api/backstage public/assets
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(society): backstage page trees, proxy endpoints, and backstage-only CSS/JS — moved to platform"
```

---

### Task 23: Drop ApiClient/I18n/lang from society if no public-site references remain

**Files:**
- Verify (do NOT delete yet): `C:/laragon/www/sites/daem-society/src/ApiClient.php`
- Verify: `C:/laragon/www/sites/daem-society/src/I18n.php`
- Verify: `C:/laragon/www/sites/daem-society/lang/`

The public site uses ApiClient extensively (every `/projects/...`, `/events/...`, `/forum/...` page calls it). KEEP these in society. This task is just an audit gate.

- [ ] **Step 1: Confirm public-site usage**

```bash
grep -rln "ApiClient::\|use ApiClient\b" C:/laragon/www/sites/daem-society/public/ 2>&1 | head -20
grep -rln "I18n::" C:/laragon/www/sites/daem-society/public/ 2>&1 | head -10
```

If hits remain (they will), do nothing — files stay.

- [ ] **Step 2: No commit needed**

Verification only.

---

## Wave G — Multi-tenant smoke + tests + docs

### Task 24: Add per-tenant platform hosts to fallback map

**Files:**
- Modify: `C:/laragon/www/daems-platform/config/tenant-fallback.php`

- [ ] **Step 1: Add `<tenant>-platform.local` rows**

Edit `config/tenant-fallback.php`:

```php
return [
    'daem-society.local'      => 'daems',
    'daems-platform.local'    => 'daems',
    'sahegroup.local'         => 'sahegroup',
    'sahegroup-platform.local' => 'sahegroup',  // NEW
    'localhost'               => 'daems',
];
```

- [ ] **Step 2: Add Apache vhost for `sahegroup-platform.local`**

In Laragon, add a new vhost pointing `sahegroup-platform.local` at `C:\laragon\www\daems-platform\public`. Add `127.0.0.1 sahegroup-platform.local` to `C:\Windows\System32\drivers\etc\hosts`. Restart Apache.

- [ ] **Step 3: Smoke**

Open `http://sahegroup-platform.local/backstage`. Should show login. Log in with a sahegroup-tenant admin. Should land on dashboard scoped to sahegroup data (different members, events, projects).

- [ ] **Step 4: Commit (platform only)**

```bash
cd C:/laragon/www/daems-platform
git add config/tenant-fallback.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(tenant): add sahegroup-platform.local → sahegroup fallback for backstage host"
```

---

### Task 25: Run full test suite + PHPStan in both repos

**Files:** none

- [ ] **Step 1: Platform**

```bash
cd C:/laragon/www/daems-platform
composer analyse
composer test:all
```

Expected: PHPStan 0 errors. All test suites green. (Backstage code under `public/` isn't analysed by PHPStan since `phpstan.neon` doesn't include it; that's fine.)

- [ ] **Step 2: Society**

```bash
cd C:/laragon/www/sites/daem-society
# Run whatever the society test command is — check composer.json scripts
composer test 2>&1 || true
```

If society has Playwright e2e tests that hit `/backstage`, expect failures — those tests must move to platform OR be retargeted. Capture the failure list; defer migration of tests to a follow-up plan.

- [ ] **Step 3: No commit**

Verification only. Halt if PHPStan or platform tests are red.

---

### Task 26: Browser smoke wave-G (final)

**Files:** none

- [ ] **Step 1: Two-tenant walkthrough**

For tenant `daems` at `http://daems-platform.local/backstage`:
- Log in
- Walk every page (use Task 20's table)
- Create-edit-delete a test event AND a test member status change

For tenant `sahegroup` at `http://sahegroup-platform.local/backstage`:
- Log in with sahegroup admin
- Walk dashboard + members + events
- Create + delete a test event scoped to sahegroup

For society public site:
- `http://daem-society.local/` → loads
- `http://daem-society.local/backstage` → 301 to `http://daems-platform.local/backstage`
- `http://daem-society.local/projects/<known-slug>` → loads

- [ ] **Step 2: Capture findings**

Log any issues in the execution-log table at the bottom of this plan. If any are blockers, fix before opening PR. Non-blocker follow-ups → file in `docs/superpowers/plans/deferred-items.md`.

---

### Task 27: Update CLAUDE.md + memory + roadmap

**Files:**
- Modify: `C:/laragon/www/daems-platform/CLAUDE.md`
- Create: `C:/Users/Sam/.claude/projects/C--laragon-www-daems-platform/memory/project_backstage_in_platform.md`
- Modify: `C:/Users/Sam/.claude/projects/C--laragon-www-daems-platform/memory/MEMORY.md`
- Modify: `C:/laragon/www/daems-platform/docs/planning/roadmap.md` (if it exists)

- [ ] **Step 1: Update CLAUDE.md two-repo table**

In `daems-platform/CLAUDE.md`, edit the "Two-repo architecture" table to reflect:

| Repo | Path | Role |
|------|------|------|
| `daems-platform` | `C:\laragon\www\daems-platform` | **Backend API + Backstage UI**. PHP 8.1+, REST at `/api/v1/*`, admin UI at `/backstage/*` (per-tenant via Host header). |
| `daem-society` | `C:\laragon\www\sites\daem-society` | **Public site only**. Calls platform API via `ApiClient`. `/backstage*` redirects to `daems-platform.local/backstage*`. |

Add a new "Backstage" section after "Multi-tenant" describing:
- URL: `<tenant-host>/backstage` (currently `daems-platform.local`, `sahegroup-platform.local`)
- Tenant resolved via `Host` header (Phase 1)
- Pages live at `daems-platform/public/backstage/pages/*` + `modules/<name>/frontend/backstage/*`
- Proxies live at `daems-platform/public/backstage/api/*`
- Login at `/backstage/login` with own session (NOT shared with public site)

- [ ] **Step 2: Write memory file**

Path: `C:\Users\Sam\.claude\projects\C--laragon-www-daems-platform\memory\project_backstage_in_platform.md`

```markdown
---
name: Backstage now in platform
description: As of 2026-05-XX, /backstage UI lives in daems-platform/public/backstage/, not in sites/daem-society. Per-tenant via Host header.
type: project
---

Backstage moved out of sites/daem-society into daems-platform.

**Why:** Same admin UI for every tenant (daems, sahegroup, future); single source of truth for admin code; fewer places to ship a fix.

**How to apply:**
- Backstage URL: `<tenant>-platform.local/backstage` (e.g. `daems-platform.local/backstage`, `sahegroup-platform.local/backstage`).
- Pages: `daems-platform/public/backstage/pages/*` + `modules/<name>/frontend/backstage/*` (modules dir unchanged at `C:\laragon\www\modules\*`).
- Proxies: `daems-platform/public/backstage/api/*` (curl-self-call to `daems-platform/public/index.php` API kernel; one extra localhost hop per page — accepted).
- Front controller split: `public/index.php` for `/api/v1/*`, `public/backstage.php` for `/backstage/*` and `/api/backstage/*`. `.htaccess` dispatches by URL prefix.
- Auth: separate session at the platform host. Login at `/backstage/login`. Public-site login at `daem-society.local/login` is unchanged but does NOT carry to backstage.
- Tenant resolution: `Host` header → `config/tenant-fallback.php` → `daems` or `sahegroup`. Tenant switcher (single host, multiple tenants) is Phase 2.
- Adding a new tenant for backstage: add `<tenant>-platform.local` row to fallback map + Apache vhost + hosts file row.
- ApiClient + I18n are in `Daems\Frontend\` namespace (`src/Frontend/`). Society keeps its own copies for the public site.
```

- [ ] **Step 3: Add to MEMORY.md index**

Append to `C:/Users/Sam/.claude/projects/C--laragon-www-daems-platform/memory/MEMORY.md`:

```markdown
- [Backstage in platform](project_backstage_in_platform.md) — 2026-05-XX: backstage moved from sites/daem-society to daems-platform; URL `<tenant>-platform.local/backstage`; per-tenant via Host header
```

(Replace `2026-05-XX` with actual completion date.)

- [ ] **Step 4: Commit (platform repo + memory)**

```bash
cd C:/laragon/www/daems-platform
git add CLAUDE.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(docs): CLAUDE.md reflects backstage living in platform"
```

(Memory directory is not in git — Write tool persists it directly.)

---

### Task 28: Open PRs

**Files:** none (PR creation)

- [ ] **Step 1: Push platform branch**

```bash
cd C:/laragon/www/daems-platform
git push -u origin backstage-to-platform
```

- [ ] **Step 2: Push society branch**

```bash
cd C:/laragon/www/sites/daem-society
git push -u origin backstage-to-platform
```

- [ ] **Step 3: Push module branches (5 repos)**

```bash
for mod in events forum insights members projects; do
  cd C:/laragon/www/modules/$mod
  git push -u origin backstage-to-platform
done
```

- [ ] **Step 4: Open PRs (one per repo, linked)**

Title each: `Move backstage into platform`

PR body for the platform PR (lead PR):

```markdown
## Summary
- Lifts backstage UI + admin proxies out of sites/daem-society into daems-platform/public/backstage/
- Splits front controllers: API at /api/v1/*, backstage at /backstage/* and /api/backstage/*
- Same UI for every tenant — tenant resolved via Host header (`daems-platform.local`, `sahegroup-platform.local`)
- Society redirects /backstage* to platform host

## Linked PRs
- daem-society#XXX — strip backstage routes + assets
- modules/members#XXX — DAEMS_SITE_PUBLIC layout require fix
- modules/events#XXX — same
- modules/forum#XXX — same
- modules/insights#XXX — same
- modules/projects#XXX — same

## Test plan
- [ ] PHPStan level 9 clean (`composer analyse`)
- [ ] All test suites green (`composer test:all`)
- [ ] Manual two-tenant walkthrough on daems + sahegroup (Wave G/Task 26)
- [ ] daem-society.local/backstage 301-redirects to daems-platform.local/backstage
- [ ] Public site (daem-society.local/, /events/, /projects/, /forum/) unaffected

## Out of scope (Phase 2)
- Single-host tenant switcher (one URL serves all tenants; user picks active tenant in session)
- Migrate Playwright e2e tests from society to platform
- Refactor backstage proxies to call platform kernel use cases directly (kill the curl-self-call hop)
```

- [ ] **Step 5: Wait for explicit "pushaa" before merging**

Per CLAUDE.md commit conventions: never merge without explicit user approval.

---

## Out of scope / Phase 2 follow-ups

These are intentionally NOT in this plan. Park them in `docs/superpowers/plans/deferred-items.md` for later:

1. **Single-host tenant switcher.** Today, sahegroup admins use `sahegroup-platform.local`. Future: one host (`backstage.daems.fi`) where the user picks the active tenant after login. Requires session-based active-tenant resolution and a tenant-picker UI for users who belong to multiple tenants.
2. **Kill the curl-self-call hop.** Backstage proxies in `public/backstage/api/*.php` currently curl-call `daems-platform.local/api/v1/*` from the same machine. Replace with direct kernel `make()->execute()` calls. Saves ~30ms per page; needs tenant-and-locale to be passed through without HTTP-header indirection.
3. **Move Playwright e2e tests from society to platform.** Society's `playwright.config.ts` likely has admin-flow specs. Move them; retarget URLs.
4. **Module backstage assets that still reference `/assets/img/*` (society).** Audit + rewrite to `/backstage/assets/img/*` or `/modules/<name>/assets/*`.
5. **Login UI polish.** The Wave-D login form is functional but spartan. Replace with the design-system version after Phase 2 ships.
6. **Public-site i18n + ApiClient drift.** Society and platform now have separate copies of `lang/*` and `ApiClient.php`. Set up a shared module (`modules/shared/php-frontend/`) and have both repos load from there to prevent drift.

---

## Self-review checklist (executed when plan was written)

- [x] **Spec coverage:** every wave maps to user's request — backstage moved, lives in platform, same UI for all tenants. ✓
- [x] **No placeholders:** every step has concrete code, paths, or commands. Wave-G/Task 27 has `2026-05-XX` placeholders for completion date — those are filled at execution time, not now. ✓
- [x] **Type consistency:** `Daems\Frontend\ApiClient`, `Daems\Frontend\I18n` are referenced consistently. ✓
- [x] **Reversibility:** waves A–E are additive; society-side delete only happens in Wave F after platform side is fully green. ✓
- [x] **Decisions documented:** all 9 locked-in decisions listed at top. Disagreements re-argued in code review, not mid-execution. ✓

---

## Execution log

| Date | Wave | Task | Commit | Notes |
|------|------|------|--------|-------|
| 2026-05-06 | A | 1 | (this commit) | Branched off dev (8 ahead of origin) in both repos. PHPStan baseline: 0 errors with `--memory-limit=1G` (default 128 MB OOMs on parallel workers; not fixed in this plan). `composer test:all` skipped per documented pre-existing Integration-suite fragility (`docs/superpowers/plans/deferred-items.md`); we re-verify per-suite at wave boundaries instead. |
