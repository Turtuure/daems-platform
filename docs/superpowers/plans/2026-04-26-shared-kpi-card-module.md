# Shared KPI-card Module — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the existing `.kpi-card` UI from `daem-society` into a reusable shared module at `C:\laragon\www\modules\shared\components\cards\kpi-card\`, mirroring the date-picker / time-picker pattern, with no visual or behavioral change.

**Architecture:** PHP partial + CSS + README live in `modules/shared/components/cards/kpi-card/`. A new `daems_shared_partial()` helper (bootstrapped once from `daem-society/public/index.php` inside the backstage routing block) lets pages `require` partials from `modules/shared/` with path-traversal guarding. The new CSS auto-loads from `pages/backstage/layout.php` (same way date-picker / time-picker stylesheets already do). Old CSS rules and the old PHP partial are deleted in the same migration commit — single source of truth, no parallel copies.

**Tech Stack:** PHP 8.x (no framework), vanilla CSS using existing design tokens. No JS module in this plan (sparkline init stays in `daems-backstage-system.js`).

**Spec:** `docs/superpowers/specs/2026-04-26-shared-kpi-card-module-design.md` (commit `2430f08`)

**Repos affected:**
- `C:\laragon\www\modules\shared\` (new files)
- `C:\laragon\www\sites\daem-society\` (helper, layout link, 7 consumer migrations, CSS deletion, old partial deletion)
- `C:\laragon\www\daems-platform\` — none (plan + spec docs only)

**Verification:** No automated tests for CSS extraction. Manual browser smoke-test all 7 KPI pages after migration. Visual diff before/after must be zero.

**Commit identity:** `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."` for every commit. No `Co-Authored-By` trailer. Never push without explicit "pushaa" instruction.

---

## File map

**Created:**
- `C:\laragon\www\modules\shared\components\cards\kpi-card\kpi-card.php` — PHP partial (exact copy of current daem-society version)
- `C:\laragon\www\modules\shared\components\cards\kpi-card\kpi-card.css` — CSS rules extracted from `daems-backstage-system.css`
- `C:\laragon\www\modules\shared\components\cards\kpi-card\README.md` — API + tokens + asset-routing + "adding a new card type" playbook
- `C:\laragon\www\sites\daem-society\public\pages\backstage\_shared.php` — `daems_shared_partial()` helper

**Modified:**
- `C:\laragon\www\sites\daem-society\public\index.php:268` — `require_once` the helper inside the backstage routing block
- `C:\laragon\www\sites\daem-society\public\pages\backstage\layout.php:115` — add `<link>` for the new CSS
- `C:\laragon\www\sites\daem-society\public\assets\css\daems-backstage-system.css` — delete `.kpi-card*` rules (lines 287–324 + 441–448)
- `C:\laragon\www\sites\daem-society\public\pages\backstage\applications\index.php:97` — replace `include`
- `C:\laragon\www\sites\daem-society\public\pages\backstage\events\index.php:113` — replace `include`
- `C:\laragon\www\sites\daem-society\public\pages\backstage\forum\forum-kpi-strip.php:42` — replace `include` (preserves `ob_start/ob_get_clean` wrapping)
- `C:\laragon\www\sites\daem-society\public\pages\backstage\insights\index.php:40` — replace `include`
- `C:\laragon\www\sites\daem-society\public\pages\backstage\members\index.php:205` — replace `include`
- `C:\laragon\www\sites\daem-society\public\pages\backstage\notifications\index.php:134` — replace `include`
- `C:\laragon\www\sites\daem-society\public\pages\backstage\projects\index.php:104` — replace `include`

**Deleted:**
- `C:\laragon\www\sites\daem-society\public\pages\backstage\shared\kpi-card.php`

---

## Task 1 — Create the shared module files

**Files:**
- Create: `C:\laragon\www\modules\shared\components\cards\kpi-card\kpi-card.php`
- Create: `C:\laragon\www\modules\shared\components\cards\kpi-card\kpi-card.css`
- Create: `C:\laragon\www\modules\shared\components\cards\kpi-card\README.md`

- [ ] **Step 1.1 — Create the PHP partial**

The contents are an **exact copy** of `C:\laragon\www\sites\daem-society\public\pages\backstage\shared\kpi-card.php`. Verify with `cmp` after writing.

```php
<?php
/**
 * Shared KPI Card partial.
 *
 * Variables (set by including page before include):
 * @var string  $kpi_id          Required. Used for the sparkline mount-point id.
 * @var string  $label           Uppercase label, e.g. 'Published'.
 * @var string  $value           Number or string value, e.g. '12'.
 * @var string  $icon_html       Inline SVG or emoji for the icon-pill (top right).
 * @var string  $icon_variant    Color modifier: blue|gray|green|amber|purple.
 * @var string  $trend_label     Optional caption beneath value.
 * @var string  $trend_direction up|down|warn|muted (default: up).
 * @var string  $href            Optional. If set, the card becomes an <a>.
 *
 * Sparkline data is rendered into <div id="spark-{kpi_id}"> by per-page JS via
 * window.Sparkline.init(el, sparklineArray, hexColor).
 */
declare(strict_types=1);

$kpi_id          = (string) ($kpi_id          ?? '');
$label           = (string) ($label           ?? '');
$value           = (string) ($value           ?? '0');
$icon_html       = (string) ($icon_html       ?? '');
$icon_variant    = (string) ($icon_variant    ?? 'blue');
$trend_label     = (string) ($trend_label     ?? '');
$trend_direction = (string) ($trend_direction ?? 'up');
$href            = isset($href) ? (string) $href : null;

$variantClass = 'kpi-card__icon--' . $icon_variant;
$trendClass   = $trend_direction === 'up'     ? '' : ('kpi-card__trend--' . $trend_direction);

$tag        = $href !== null ? 'a' : 'div';
$cardClass  = 'kpi-card' . ($href !== null ? ' kpi-card--clickable' : '');
$cardAttr   = $href !== null ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '';
?>
<<?= $tag ?> class="<?= $cardClass ?>"<?= $cardAttr ?> data-kpi="<?= htmlspecialchars($kpi_id, ENT_QUOTES, 'UTF-8') ?>">
  <div class="kpi-card__head">
    <div>
      <div class="kpi-card__label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="kpi-card__value"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></div>
      <?php if ($trend_label !== ''): ?>
        <div class="kpi-card__trend <?= $trendClass ?>"><?= htmlspecialchars($trend_label, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
    <?php if ($icon_html !== ''): ?>
      <span class="kpi-card__icon <?= $variantClass ?>"><?= $icon_html /* trusted inline SVG/emoji */ ?></span>
    <?php elseif ($href !== null): ?>
      <span class="kpi-card__arrow">&rarr;</span>
    <?php endif; ?>
  </div>
  <div class="kpi-card__spark" id="spark-<?= htmlspecialchars($kpi_id, ENT_QUOTES, 'UTF-8') ?>"></div>
</<?= $tag ?>>
```

- [ ] **Step 1.2 — Verify the copy is byte-identical**

Run: `cmp C:/laragon/www/modules/shared/components/cards/kpi-card/kpi-card.php C:/laragon/www/sites/daem-society/public/pages/backstage/shared/kpi-card.php`

Expected: no output (files identical).

- [ ] **Step 1.3 — Create the CSS file**

Contents: the `.kpis-grid` rule + all `.kpi-card*` rules from `daems-backstage-system.css`. Includes:
- `.kpis-grid` (line 288–289 of source)
- Base + variants from lines 287–324
- Loading-state shimmer rules from lines 441–448

```css
/* Shared KPI card module — extracted from daems-backstage-system.css.
 * Tokens consumed: --surface-dark, --surface-border, --surface-light,
 * --surface-lighter, --brand-primary, --brand-primary-muted, --status-success,
 * --status-success-subtle, --status-warning, --status-warning-subtle,
 * --status-error, --status-error-subtle, --accent, --accent-subtle,
 * --text-primary, --text-secondary, --text-muted, --text-xs,
 * --weight-semibold, --weight-bold, --radius-md, --radius-lg, --radius-full,
 * --space-3, --space-4, --transition-fast.
 * The host stylesheet must define these before this file loads.
 */

/* ----- Grid container ---------------------------------------------------- */
.kpis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
             gap: var(--space-3); margin-bottom: var(--space-4); }

/* ----- KPI card base ----------------------------------------------------- */
.kpi-card { background: var(--surface-dark); border: 1px solid var(--surface-border);
            border-radius: var(--radius-lg); padding: 14px 16px 0;
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; min-height: 120px;
            transition: border-color var(--transition-fast); }
.kpi-card--clickable { cursor: pointer; }
.kpi-card--clickable:hover { border-color: var(--brand-primary); }
.kpi-card--clickable:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: -2px; }
.kpi-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.kpi-card__label { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.04em;
                   color: var(--text-secondary); font-weight: var(--weight-semibold); }
.kpi-card__value { font-size: 24px; font-weight: var(--weight-bold); margin-top: 4px;
                   letter-spacing: -0.01em; line-height: 1; color: var(--text-primary); }
.kpi-card__trend { font-size: var(--text-xs); margin-top: 2px;
                   color: var(--status-success); display: inline-flex; align-items: center; gap: 3px; }
.kpi-card__trend--down  { color: var(--status-error); }
.kpi-card__trend--warn  { color: var(--status-warning); }
.kpi-card__trend--muted { color: var(--text-muted); }
.kpi-card__icon { width: 28px; height: 28px; border-radius: var(--radius-md);
                  display: inline-flex; align-items: center; justify-content: center;
                  font-size: 14px; flex-shrink: 0; }
.kpi-card__icon--blue   { background: var(--brand-primary-muted); color: var(--brand-primary); }
.kpi-card__icon--green  { background: var(--status-success-subtle); color: var(--status-success); }
.kpi-card__icon--amber  { background: var(--status-warning-subtle); color: var(--status-warning); }
.kpi-card__icon--red    { background: var(--status-error-subtle);   color: var(--status-error); }
.kpi-card__icon--purple { background: var(--accent-subtle); color: var(--accent); }
.kpi-card__icon--gray   { background: rgba(148,163,184,.15); color: var(--text-secondary); }
.kpi-card__arrow { font-size: 14px; color: var(--text-muted); }
.kpi-card__spark { margin: 8px -16px 0; height: 36px; }
.kpi-card__spark > * { display: block; }

/* Compact variant — sub-page nav use, no sparkline */
.kpi-card--compact { min-height: 0; padding-bottom: 14px; }
.kpi-card--compact .kpi-card__spark { display: none; }

/* ----- Loading state (shimmer) ------------------------------------------- */
.kpi-card.is-loading .kpi-card__value { width: 60px; }
.kpi-card.is-loading .kpi-card__label { opacity: 0.6; }
.kpi-card.is-loading .kpi-card__spark { background: linear-gradient(90deg,
                                                       var(--surface-light) 25%,
                                                       var(--surface-lighter) 50%,
                                                       var(--surface-light) 75%);
                                        background-size: 200% 100%;
                                        animation: skeleton-shimmer 1.5s infinite; }
```

Note: `.kpi-card.is-loading .kpi-card__value { width: 60px; }` and `.kpi-card.is-loading .kpi-card__label { opacity: 0.6; }` reference `.skeleton--text`-style shimmer behavior; verify those are the only `is-loading` rules by grepping `daems-backstage-system.css` for `kpi-card.is-loading`.

- [ ] **Step 1.4 — Verify CSS extraction is complete**

Run: `grep -n "kpi-card" C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css`

Expected: matches at lines 5 (header comment — leave), 288–324 (grid + base + variants — to be deleted in Task 5), 441–448 (loading state — to be deleted in Task 5). All matches must be present in the new `kpi-card.css`. Cross-check by running `grep -n "kpi-card" C:/laragon/www/modules/shared/components/cards/kpi-card/kpi-card.css` and confirming the same selectors appear.

- [ ] **Step 1.5 — Create the README**

```markdown
# Shared KPI card module

Reusable themed metric tile for backstage dashboards. Renders a labeled
value + optional trend caption + optional icon-pill + sparkline mount-point.

## Usage (PHP partial)

```php
<div class="kpis-grid">
  <?php
    $kpi_id          = 'published';
    $label           = 'Published';
    $value           = '42';
    $icon_html       = '<svg ...></svg>';
    $icon_variant    = 'green';        // blue|green|amber|red|purple|gray
    $trend_label     = 'last 30 days';
    $trend_direction = 'muted';        // up|down|warn|muted
    daems_shared_partial('components/cards/kpi-card/kpi-card', [
      'kpi_id' => $kpi_id, 'label' => $label, 'value' => $value,
      'icon_html' => $icon_html, 'icon_variant' => $icon_variant,
      'trend_label' => $trend_label, 'trend_direction' => $trend_direction,
    ]);
  ?>
</div>
```

If `$href` is set, the card renders as `<a>` and gains the
`kpi-card--clickable` modifier (hover + focus-visible styling).

## CSS asset

```html
<link rel="stylesheet" href="/shared/components/cards/kpi-card/kpi-card.css">
```

The host site must expose `/shared/<path>` via its asset router (see
daem-society `public/index.php` for an example). The host's design-system
stylesheet must define the tokens listed in `kpi-card.css`'s header comment
before this file loads.

## Sparkline (separate concern)

The card outputs a `<div class="kpi-card__spark" id="spark-{kpi_id}">` mount-
point but does NOT initialize ApexCharts itself. Per-page JS calls
`window.Sparkline.init(el, dataPoints, color)` to render the sparkline.
This module deliberately stays presentational so each page controls its own
data fetching + chart wiring.

## Variants

- `.kpi-card` — base.
- `.kpi-card--clickable` — added automatically when `$href` is set; cursor
  pointer + hover/focus border-color brand-primary.
- `.kpi-card--compact` — `min-height: 0`, hides the sparkline area. Useful
  for sub-page nav cards where the metric strip is decorative.
- `.kpi-card.is-loading` — used while data is in-flight; shimmer animation
  on the value width + label opacity + sparkline mount.

Icon color: pick one of `blue|green|amber|red|purple|gray` and pass via
`$icon_variant` — applied as `kpi-card__icon--{variant}`.

Trend color: pick one of `up|down|warn|muted` via `$trend_direction`.
`up` is the default and gets no modifier class (uses `--status-success`).

## Adding a new card type

The `modules/shared/components/cards/` directory hosts one folder per card
type. To extract another card pattern:

1. Create `modules/shared/components/cards/<name>/{<name>.php, <name>.css, README.md}`.
   Copy this folder as a template; replace markup; document the API.
2. Add a `<link rel="stylesheet" href="/shared/components/cards/<name>/<name>.css">`
   tag to the relevant shell layout that should auto-load it.
   - Backstage shell: `daem-society/public/pages/backstage/layout.php`.
   - Public shell: `daem-society/public/pages/_layout/header.php` (or wherever the public header lives).
3. Replace any existing inline includes with
   `daems_shared_partial('components/cards/<name>/<name>', [...vars...])`.
4. Remove the old CSS from the host stylesheet (single source of truth).

No new architectural decisions are required for the next card extraction.
The helper, the asset router, the shell auto-include, and this folder shape
all stay the same.
```

- [ ] **Step 1.6 — Commit**

```bash
cd C:/laragon/www/modules
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add shared/components/cards/kpi-card/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(shared): KPI-card module — PHP partial + CSS + README

First entry under modules/shared/components/cards/. PHP partial is a
byte-level copy of the daem-society backstage version; CSS is extracted
verbatim from daems-backstage-system.css (base + variants + loading
state). README includes the playbook for adding subsequent card types."
```

If `C:/laragon/www/modules/` is not yet a git repository, ask the user how they want to track the module (e.g. as a separate repo or as an untracked shared dir). Do not initialize a repo without explicit instruction.

---

## Task 2 — Add the helper + bootstrap from index.php

**Files:**
- Create: `C:\laragon\www\sites\daem-society\public\pages\backstage\_shared.php`
- Modify: `C:\laragon\www\sites\daem-society\public\index.php` (around line 268)

- [ ] **Step 2.1 — Create the helper file**

```php
<?php
declare(strict_types=1);

/**
 * Include a PHP partial from C:\laragon\www\modules\shared\.
 *
 * @param string               $relPath Relative path WITHOUT '.php' suffix,
 *                                      e.g. 'components/cards/kpi-card/kpi-card'.
 * @param array<string, mixed> $vars    Variables made available to the partial
 *                                      (extracted with EXTR_SKIP so collisions
 *                                      with existing scope are silently ignored).
 *
 * @throws \RuntimeException If the resolved path is outside the modules/shared
 *                           sandbox or the file does not exist.
 */
function daems_shared_partial(string $relPath, array $vars = []): void {
    // _shared.php sits at daem-society/public/pages/backstage/, so five ..
    // segments hop up to C:\laragon\www\ and into modules/shared from there.
    $base = realpath(__DIR__ . '/../../../../../modules/shared');
    $file = $base !== false
          ? realpath($base . '/' . $relPath . '.php')
          : false;
    if ($base === false || $file === false || !str_starts_with($file, $base)) {
        throw new \RuntimeException("Shared partial not found or outside sandbox: $relPath");
    }
    extract($vars, EXTR_SKIP);
    require $file;
}
```

- [ ] **Step 2.2 — Wire helper into the backstage routing block**

Edit `C:\laragon\www\sites\daem-society\public\index.php`. Find the block that starts at line 268:

```php
// Backstage routes — role guard enforced in backstage layout
if (preg_match('#^/backstage(/.*)?$#', $uri)) {
    $__adminSub = rtrim($uri === '/backstage' ? '' : substr($uri, 10), '/');
```

Insert one line immediately after the `if` line and before `$__adminSub`:

```php
// Backstage routes — role guard enforced in backstage layout
if (preg_match('#^/backstage(/.*)?$#', $uri)) {
    require_once __DIR__ . '/pages/backstage/_shared.php';
    $__adminSub = rtrim($uri === '/backstage' ? '' : substr($uri, 10), '/');
```

This loads the helper exactly once per backstage request, before any page file is required. Public-site pages do not get the helper (intentional — current scope is backstage only).

- [ ] **Step 2.3 — Smoke-check the helper resolves correctly**

Open any backstage URL in a browser (e.g. `http://daem-society.local/backstage/insights`). Page must still render — no fatal error from the new `require_once`. The helper isn't called yet so no behavior change.

- [ ] **Step 2.4 — Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add public/pages/backstage/_shared.php public/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): daems_shared_partial helper for cross-repo PHP includes

Resolves a relative path under C:\laragon\www\modules\shared with
realpath-based sandboxing (request paths that escape the modules/shared
root throw RuntimeException). Bootstrapped once per backstage request
from public/index.php so every backstage page can call the helper
without per-page require_once."
```

---

## Task 3 — Add CSS link to backstage layout

**Files:**
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\layout.php` (around line 115)

- [ ] **Step 3.1 — Insert the new `<link>` tag**

Find this block in `layout.php` (lines 113–115):

```html
    <link rel="stylesheet" href="/shared/date-picker/date-picker.css">

    <link rel="stylesheet" href="/shared/time-picker/time-picker.css">
```

Add a new line immediately after the time-picker link:

```html
    <link rel="stylesheet" href="/shared/date-picker/date-picker.css">

    <link rel="stylesheet" href="/shared/time-picker/time-picker.css">
    <link rel="stylesheet" href="/shared/components/cards/kpi-card/kpi-card.css">
```

- [ ] **Step 3.2 — Verify the asset is served**

Open `http://daem-society.local/shared/components/cards/kpi-card/kpi-card.css` directly in a browser. Expected: HTTP 200, raw CSS payload (the file contents from Task 1.3). If 404, recheck the asset-router path in `public/index.php:70-85` and the file path on disk.

- [ ] **Step 3.3 — Smoke-check no visual regression yet**

Open any backstage KPI page (e.g. `/backstage/insights`). The KPI strip is now styled by **both** the system stylesheet AND the new module stylesheet. Since the rules are byte-identical, the cascade resolves to the same computed style — there should be no visible change. If KPI cards now look broken, check for selector specificity differences between the two copies.

- [ ] **Step 3.4 — Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add public/pages/backstage/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(backstage): auto-load shared KPI-card CSS from layout shell

Adds <link> tag immediately after the date/time-picker stylesheet links.
The module CSS is byte-identical to the rules currently in
daems-backstage-system.css, so this commit is visually a no-op — it
prepares the ground for the system-CSS deletion in a follow-up commit."
```

---

## Task 4 — Migrate the 7 consumer pages to the helper

**Files (one step per file):**
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\insights\index.php:40`
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\applications\index.php:97`
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\events\index.php:113`
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\members\index.php:205`
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\notifications\index.php:134`
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\projects\index.php:104`
- Modify: `C:\laragon\www\sites\daem-society\public\pages\backstage\forum\forum-kpi-strip.php:42` (special — preserves `ob_start/ob_get_clean` wrapping)

The standard pattern (used by the first 6 files) is a foreach loop that does `extract($kpi); include`. The replacement removes the `extract` (the helper does it) and swaps `include` for `daems_shared_partial`:

**Before:**
```php
<?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
```

**After:**
```php
<?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
```

- [ ] **Step 4.1 — Migrate `insights/index.php`**

The insights file uses a slightly expanded form. Find lines 38–41:

```php
    ] as $kpi) {
      extract($kpi);
      include __DIR__ . '/../shared/kpi-card.php';
    }
```

Replace with:

```php
    ] as $kpi) {
      daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi);
    }
```

Verify `/backstage/insights` still renders the KPI strip identically.

- [ ] **Step 4.2 — Migrate `applications/index.php`**

Find line 97:

```php
  <?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
```

Replace with:

```php
  <?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
```

Verify `/backstage/applications` renders identically.

- [ ] **Step 4.3 — Migrate `events/index.php`**

Find line 113:

```php
  <?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
```

Replace with:

```php
  <?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
```

Verify `/backstage/events` renders identically.

- [ ] **Step 4.4 — Migrate `members/index.php`**

Find line 205:

```php
  <?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
```

Replace with:

```php
  <?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
```

Verify `/backstage/members` renders identically.

- [ ] **Step 4.5 — Migrate `notifications/index.php`**

Find line 134:

```php
  <?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
```

Replace with:

```php
  <?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
```

Verify `/backstage/notifications` renders identically.

- [ ] **Step 4.6 — Migrate `projects/index.php`**

Find line 104:

```php
  <?php foreach ($kpis as $kpi): extract($kpi); include __DIR__ . '/../shared/kpi-card.php'; endforeach; ?>
```

Replace with:

```php
  <?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
```

Verify `/backstage/projects` renders identically.

- [ ] **Step 4.7 — Migrate `forum/forum-kpi-strip.php` (special case)**

This file uses `ob_start()` + `include` + `ob_get_clean()` to capture the partial output and post-process it (adds `--compact` modifier and active-state inline style via regex). Find lines 39–43:

```php
    extract($kpi);
    // Capture the partial output, then post-process to add active-state inline style.
    ob_start();
    include __DIR__ . '/../shared/kpi-card.php';
    $cardHtml = ob_get_clean();
```

Replace with (drops `extract`, swaps `include` for the helper — `ob_start/ob_get_clean` still capture the output because `require` writes to the same output buffer):

```php
    // Capture the partial output, then post-process to add active-state inline style.
    ob_start();
    daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi);
    $cardHtml = ob_get_clean();
```

Verify `/backstage/forum` renders the KPI strip identically — including the active-state highlight when navigating into a sub-page (e.g. `/backstage/forum/reports` should highlight the "Open reports" card with a brand-primary border).

- [ ] **Step 4.8 — Confirm no consumer references the old partial path**

Run: `grep -rn "shared/kpi-card.php" C:/laragon/www/sites/daem-society/`

Expected: only matches inside the deleted block of git history, no live source matches. (The next task deletes the old partial; this grep guards against missed migrations.)

- [ ] **Step 4.9 — Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  public/pages/backstage/insights/index.php \
  public/pages/backstage/applications/index.php \
  public/pages/backstage/events/index.php \
  public/pages/backstage/members/index.php \
  public/pages/backstage/notifications/index.php \
  public/pages/backstage/projects/index.php \
  public/pages/backstage/forum/forum-kpi-strip.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Migrate(backstage): 7 KPI-card consumers to daems_shared_partial helper

Replaces extract+include with the helper across all KPI pages
(applications, events, forum strip, insights, members, notifications,
projects). Forum strip preserves its ob_start/ob_get_clean wrapping
because require writes to the same output buffer as include — the
post-processing regex for the compact + active-state modifiers stays
unchanged."
```

---

## Task 5 — Delete old CSS rules + old PHP partial

**Files:**
- Modify: `C:\laragon\www\sites\daem-society\public\assets\css\daems-backstage-system.css` (delete lines 287–324 + 441–448 + the line-5 header reference)
- Delete: `C:\laragon\www\sites\daem-society\public\pages\backstage\shared\kpi-card.php`

- [ ] **Step 5.1 — Delete the `.kpis-grid` + `.kpi-card*` block (lines 287–324)**

Open `daems-backstage-system.css`. Locate the block from `/* ----- KPI cards ----- */` down to and including `.kpi-card--compact .kpi-card__spark { display: none; }`. Delete the entire block including the section comment header.

After deletion, the file should jump directly from the previous section (pills, ending around line 285) to the next section (`/* ----- Data explorer ----------------------------------------------------- */`).

- [ ] **Step 5.2 — Delete the loading-state block (around lines 441–448)**

Locate the three rules:

```css
.kpi-card.is-loading .kpi-card__value { width: 60px; }
.kpi-card.is-loading .kpi-card__label { opacity: 0.6; }
.kpi-card.is-loading .kpi-card__spark { background: linear-gradient(90deg,
                                                       var(--surface-light) 25%,
                                                       var(--surface-lighter) 50%,
                                                       var(--surface-light) 75%);
                                        background-size: 200% 100%;
                                        animation: skeleton-shimmer 1.5s infinite; }
```

Delete all three. The surrounding `.skeleton--*` rules are unrelated and stay.

- [ ] **Step 5.3 — Update the file's top-of-file table of contents (line 5)**

The file header at line 5 references `.kpi-card / .kpis-grid` as a section. Remove that entry from the index comment so the TOC matches the file's actual contents.

- [ ] **Step 5.4 — Verify no `.kpi-card` rules remain in the system stylesheet**

Run: `grep -n "kpi-card\|kpis-grid" C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css`

Expected: zero matches.

- [ ] **Step 5.5 — Delete the old PHP partial**

```bash
rm C:/laragon/www/sites/daem-society/public/pages/backstage/shared/kpi-card.php
```

Then verify nothing else still references it:

Run: `grep -rn "shared/kpi-card.php" C:/laragon/www/sites/daem-society/`

Expected: zero matches in live source. If any match remains, revisit Task 4.

- [ ] **Step 5.6 — Smoke-check all 7 KPI pages still render**

Open each in a browser and visually confirm the KPI strip looks identical to before:
- `/backstage` (dashboard, if it uses kpi-card — confirm via grep)
- `/backstage/applications`
- `/backstage/events`
- `/backstage/forum` (and `/backstage/forum/reports` for active-state)
- `/backstage/insights`
- `/backstage/members`
- `/backstage/notifications`
- `/backstage/projects`

Hard-refresh (Ctrl+F5) to bypass cached system stylesheet. If any card looks unstyled or broken, the most likely cause is a missed CSS rule in Task 1.3 — diff `kpi-card.css` against the deleted blocks to find what's missing.

- [ ] **Step 5.7 — Commit**

```bash
cd C:/laragon/www/sites/daem-society
git -c user.name="Dev Team" -c user.email="dev@daems.fi" add \
  public/assets/css/daems-backstage-system.css \
  public/pages/backstage/shared/kpi-card.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Remove(backstage): legacy KPI-card CSS + PHP partial — moved to shared module

Single source of truth: .kpi-card* rules and the partial now live only
under modules/shared/components/cards/kpi-card/. The backstage shell
loads the new CSS via the existing /shared/ asset router. Visual output
is unchanged — verified by manually browsing all 7 KPI pages."
```

---

## Task 6 — Final verification + report

- [ ] **Step 6.1 — Run a final cross-repo grep for legacy references**

```bash
grep -rn "shared/kpi-card\.php\|pages/backstage/shared/kpi-card" \
  C:/laragon/www/sites/daem-society/ \
  C:/laragon/www/daems-platform/
```

Expected: zero matches (excluding `.git/` history).

- [ ] **Step 6.2 — Diff the system-CSS one more time**

```bash
grep -n "kpi-card\|kpis-grid" C:/laragon/www/sites/daem-society/public/assets/css/daems-backstage-system.css
```

Expected: zero matches.

- [ ] **Step 6.3 — Confirm the new module loads via the asset router**

Open `http://daem-society.local/shared/components/cards/kpi-card/kpi-card.css` in a browser. Expected: HTTP 200, full CSS payload.

- [ ] **Step 6.4 — Visual smoke-test summary**

Open each of the 7 backstage pages from Step 5.6 and confirm:
- KPI strip visible at top of page.
- Cards have the same border, padding, hover behavior, sparkline area, and icon/trend coloring as before.
- Forum strip's compact variant + active-state highlight still work when navigating into sub-pages.

If anything looks off, compare the network response of the new `kpi-card.css` against the deleted block to find diffs. Do not leave broken styling in `dev` — revert and fix.

- [ ] **Step 6.5 — Report commit SHAs**

Report all commit SHAs produced by Tasks 1–5 to the user. Do **not** push without an explicit "pushaa" instruction.

---

## Self-review notes

- **Spec coverage:** Sections 1–7 of the spec are covered:
  - §1 Motivation: context only, no code.
  - §2 Goals + §3 Non-goals: scoped via "Goal" + Tasks 1, 4–5 (no JS, no visual change, no other cards).
  - §4 Architecture: Task 1 (folder + PHP + CSS), Task 2 (helper + bootstrap), Task 3 (auto-load).
  - §5 Migration steps: Tasks 1–5 implement the spec's 7 numbered steps in order.
  - §6 Risks + mitigations: covered by Steps 4.8 (grep before delete), 5.4 + 5.5 + 6.1 (verify zero legacy refs), 5.6 + 6.4 (visual smoke-test), 3.3 (parallel-load no-op verification).
  - §7 Future-extensibility: README "Adding a new card type" section in Task 1.5.

- **Placeholder scan:** No "TBD" / "TODO" / "fill in" markers. All commit messages, file contents, and grep commands are concrete.

- **Path consistency:** `daems_shared_partial` defined in Task 2.1 with signature `(string $relPath, array $vars = []): void`. All call sites (Task 4.1–4.7) use the same name and the same path argument `'components/cards/kpi-card/kpi-card'`. Helper file path `daem-society/public/pages/backstage/_shared.php` consistent across Task 2 (creation), Task 2.2 (require), and the spec.
