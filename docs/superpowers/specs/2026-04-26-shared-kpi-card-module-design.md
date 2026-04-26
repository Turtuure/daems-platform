# Shared KPI-card module — design spec

**Date:** 2026-04-26
**Status:** Approved (brainstorming complete, awaiting plan)
**Scope:** Extract the existing `.kpi-card` UI from `daem-society` into a reusable shared module under `C:\laragon\www\modules\shared\components\cards\kpi-card\`, following the pattern already established by `modules/shared/date-picker/` and `modules/shared/time-picker/`.

This is the first card-type extraction. The module's folder structure and helper conventions are designed to make subsequent card extractions (member-card, insight-card, event-card, …) follow an identical, no-decision pattern.

---

## 1. Motivation

The `.kpi-card` component is rendered on **7 backstage pages** (`applications`, `events`, `forum`, `insights`, `members`, `notifications`, `projects`) plus the dashboard via two paths:

- A PHP partial at `daem-society/public/pages/backstage/shared/kpi-card.php` (clean API: `$kpi_id`, `$label`, `$value`, `$icon_html`, `$icon_variant`, `$trend_label`, `$trend_direction`, `$href`).
- ~33 lines of CSS in `daem-society/public/assets/css/daems-backstage-system.css` (lines 291–324).

Other tenants on the platform (e.g. `sahegroup`) cannot consume the partial today — it lives inside `daem-society/`. Extracting to `modules/shared/` makes the same UI available to any tenant frontend that mounts the asset router, the same way `date-picker` and `time-picker` are already shared.

This is also a **template extraction** — the conventions chosen here (folder layout, PHP include helper, asset routing, shell auto-include) become the playbook for all future card extractions. We extract one card now, but the design must scale to N cards.

## 2. Goals

- Move the KPI card's PHP, CSS, and docs into `modules/shared/components/cards/kpi-card/`.
- Provide a small `daems_shared_partial(string $relPath, array $vars = []): void` helper so daem-society pages can `require` partials from `modules/shared/` with path-traversal guarding.
- Auto-load the new CSS via the existing backstage shell (`pages/backstage/layout.php`), so no per-page `<link>` tags are needed.
- Remove the old CSS rules and the old PHP partial after migration — single source of truth, no parallel copies that can drift.
- Document the pattern in a README that doubles as the playbook for future card extractions.

## 3. Non-goals

- **No JS module.** The KPI card has no card-specific JS lifecycle. Sparkline rendering is a separate concern handled by `Sparkline.init()` in `daems-backstage-system.js` and is out of scope.
- **No new variants or visual changes.** This is a byte-level migration of existing CSS rules and HTML structure. Visual output before and after must be identical.
- **No other card types.** `member-card`, `event-card`, `insight-card`, `project-card`, `locale-card`, `members-person-card`, `card--metric` etc. stay where they are. Each will be a separate, future extraction following the same pattern.
- **No automated visual regression testing.** Smoke-test by browsing all 7 KPI pages manually after migration.

## 4. Architecture

### 4.1 Folder layout

```
C:\laragon\www\modules\shared\components\cards\kpi-card\
├── kpi-card.php       # PHP partial — exact copy of current daem-society version
├── kpi-card.css       # CSS rules — extracted verbatim from daems-backstage-system.css
└── README.md          # API + tokens consumed + asset-routing + "adding a new card" playbook
```

The `components/cards/` parent directory is created with this PR. Future card types live as siblings: `components/cards/member-card/`, `components/cards/insight-card/`, etc.

### 4.2 PHP partial

`modules/shared/components/cards/kpi-card/kpi-card.php` is the **identical contents** of the current `daem-society/public/pages/backstage/shared/kpi-card.php` — same `declare(strict_types=1)`, same variable defaults, same output. No API change.

### 4.3 PHP include helper

A new helper file is added at `daem-society/public/pages/backstage/_shared.php`:

```php
<?php
declare(strict_types=1);

/**
 * Include a PHP partial from C:\laragon\www\modules\shared\.
 *
 * @param string               $relPath  Relative path WITHOUT '.php' suffix,
 *                                       e.g. 'components/cards/kpi-card/kpi-card'.
 * @param array<string, mixed> $vars     Variables made available to the partial
 *                                       (extracted with EXTR_SKIP so collisions
 *                                       with existing scope are silently ignored).
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

`layout.php` requires this helper once at the top of the backstage shell, making it available to every backstage page.

**Path-traversal protection:** `realpath()` resolves symlinks and `..` segments, then `str_starts_with($file, $base)` ensures the result remains under `modules/shared/`. A request for `'../../../../etc/passwd'` resolves to a path outside `$base` and throws.

### 4.4 CSS extraction + auto-include

CSS rules from `daems-backstage-system.css` lines 291–324 are extracted verbatim into `modules/shared/components/cards/kpi-card/kpi-card.css`. This includes:

- Base `.kpi-card`, `.kpi-card__head`, `.kpi-card__label`, `.kpi-card__value`, `.kpi-card__trend`, `.kpi-card__icon`, `.kpi-card__spark`, `.kpi-card__arrow` rules
- Modifiers: `.kpi-card--clickable`, `.kpi-card--compact`, `.kpi-card.is-loading` (shimmer animation)
- Icon color variants: `.kpi-card__icon--blue|green|amber|red|purple|gray`
- Trend variants: `.kpi-card__trend--down|warn|muted`

The new file is loaded from the backstage shell `pages/backstage/layout.php`, immediately after the existing date-picker / time-picker `<link>` tags (around line 116):

```html
<link rel="stylesheet" href="/shared/components/cards/kpi-card/kpi-card.css">
```

The asset router in `public/index.php` (lines 70–85) already serves `/shared/<path>` requests — no router changes needed.

The old CSS rules in `daems-backstage-system.css` are **deleted** in the same migration commit, eliminating drift risk.

### 4.5 Tokens consumed

The extracted CSS depends on tokens defined in `daems-backstage.css` and `daems-backstage-system.css`:

- Surface: `--surface-dark`, `--surface-border`
- Text: `--text-primary`, `--text-secondary`, `--text-muted`
- Brand: `--brand-primary`
- Status: `--status-success`, `--status-error`, `--status-warning`
- Misc: `--radius-md`, `--shadow-sm`, `--space-*`

These remain in the host stylesheets — the module assumes the host loads its design system before loading any card module. The README documents this dependency, same as date-picker README does.

## 5. Migration steps (executable in one PR)

1. Create `modules/shared/components/cards/kpi-card/{kpi-card.php, kpi-card.css, README.md}` — PHP and CSS are exact copies of current versions.
2. Add `daem-society/public/pages/backstage/_shared.php` with the `daems_shared_partial()` helper.
3. In `daem-society/public/pages/backstage/layout.php`:
   - Add `require_once __DIR__ . '/_shared.php';` near the top of the file (before any HTML output).
   - Add `<link rel="stylesheet" href="/shared/components/cards/kpi-card/kpi-card.css">` immediately after the time-picker stylesheet link.
4. In each of the 7 consumer files, replace `include __DIR__ . '/../shared/kpi-card.php';` with:
   ```php
   daems_shared_partial('components/cards/kpi-card/kpi-card', [
       'kpi_id' => '...',
       'label'  => '...',
       // … other vars currently set inline before the include
   ]);
   ```
   Each existing include site sets its variables in the surrounding scope before `include`. The migration moves those into the `$vars` array.
5. Delete `.kpi-card*` rules from `daems-backstage-system.css` (verify no other selectors depend on the removed lines — the rules are self-contained per the original extraction).
6. Delete `daem-society/public/pages/backstage/shared/kpi-card.php` (confirm no other includers via grep before deleting).
7. Manual smoke-test: open all 7 backstage pages in a browser and verify the KPI strip renders identically to before.

## 6. Risks and mitigations

| Risk | Mitigation |
|------|------------|
| A page misses the migration and `include` of the deleted file 500s. | Step 6 includes a grep across the entire repo for `kpi-card.php` to confirm zero remaining references before deleting. |
| Asset router doesn't expose the new path. | Existing router already serves `/shared/**` — no change needed; the `components/cards/kpi-card/kpi-card.css` path resolves under the existing `realpath` guard. |
| Variables expected by the partial are not set in the `$vars` array, leading to silent fallback to defaults. | The partial defaults all variables to safe empty strings (existing behavior). Each migrated call site is verified by visual inspection in step 7. |
| `daems_shared_partial()` path resolution fails on Windows due to forward/backslash mixing. | `realpath()` normalizes separators on both OSes; the `str_starts_with` check operates on the normalized result. Verified by running on the dev Laragon stack. |

## 7. Future-extensibility — adding a new card type

The README in `modules/shared/components/cards/kpi-card/` includes a "Adding a new card type" section that codifies:

```
1. Create modules/shared/components/cards/<name>/{<name>.php, <name>.css, README.md}
   — copy the existing PHP partial as a template; replace markup; document API.
2. Add a <link> tag to the relevant shell layout that should auto-load it.
   For backstage: pages/backstage/layout.php.
   For public site: pages/_layout/header.php (or wherever the public shell lives).
3. Replace any existing inline includes with daems_shared_partial(
       'components/cards/<name>/<name>', [...vars...]
   ).
4. Remove the old CSS from the host stylesheet.
```

No new architectural decisions are required for the next card extraction — same helper, same router, same shell, same folder shape.

## 8. Out-of-scope follow-ups

- Extracting `member-card`, `event-card`, `project-card`, `insight-card`, `locale-card`, `members-person-card`, `card--metric` — each one a separate spec/plan/PR. The inventory exists (see brainstorming session 2026-04-26).
- A future shared "card primitives" CSS file containing the truly generic `.card`/`.card__body` base rules from `daems-backstage.css`. Not needed for this PR — KPI card is self-contained.
- Visual regression testing setup (Playwright + screenshot diffing) for the backstage shell. Out of scope; current verification is manual browser smoke-test.
