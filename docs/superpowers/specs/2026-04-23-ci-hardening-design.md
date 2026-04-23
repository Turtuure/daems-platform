# CI/CD Hardening — Design

**Date:** 2026-04-23
**Branches:** `ci-hardening` in both `daems-platform` and `daem-society` repos
**Scope:** Both repos. GitHub Actions workflows + composer lock fix + branch protection.

## Problem

The repos have GitHub Actions CI workflows checked in (`.github/workflows/ci.yml`) but only one half is healthy:

- ✅ **daem-society CI** is green on every push to `dev`.
- ❌ **daems-platform CI** has been failing on every push for at least four weeks. Root cause: `composer.lock` is locked to `symfony/string v8.0.8` (which requires PHP ≥ 8.4) while the matrix runs PHP 8.1 + 8.3. `composer install` aborts before any test runs.

Even when the platform CI is fixed, two more gaps remain:

1. **No MySQL service** in the platform workflow → the Integration suite (most of the test value) cannot run there.
2. **No branch protection** on `main` in either repo → green CI is decorative; nothing prevents a direct push.
3. **No Playwright run** in the daem-society workflow → the E2E specs only ever run locally.

Two minor cleanups also fall out: `actions/checkout@v4` triggers a Node 20 deprecation warning on every run.

## Goal

Get every relevant signal — type-checking, unit tests, integration tests, end-to-end tests — running on every push, and prevent merging to `main` without it. After this work:

- Both repos' CI runs and stays green on `dev` pushes.
- The platform CI runs the full PHPUnit suite (Unit + Integration + E2E) against a MySQL service.
- The society CI gains an `e2e` job that boots the PHP built-in server and runs Playwright on chromium.
- `main` in both repos requires the relevant CI checks to pass before merge, with no direct pushes.

## Non-goals

- Mutation testing (Infection) — separate concern, expensive.
- Code coverage reporting — separate concern.
- PHP 8.4 in the matrix — staying on 8.1 + 8.3 to match `composer.json` `require.php: ">=8.1"`.
- Lighthouse / a11y / mobile audits in CI.
- Deploy automation — out of scope here.

## Architecture

The work splits cleanly into five sub-packages. Three live in `daems-platform`, one in `daem-society`, one is repo-config done via `gh api`:

| # | Sub-package | Repo | Files touched |
|---|---|---|---|
| 1 | Composer lock fix | daems-platform | `composer.json`, `composer.lock` |
| 2 | MySQL service in platform CI | daems-platform | `.github/workflows/ci.yml` |
| 3 | Branch protection on `main` | both (via `gh api`) | none in the repos |
| 4 | Playwright job in society CI | daem-society | `.github/workflows/ci.yml` |
| 5 | `actions/checkout` v4 → v5 | both | `.github/workflows/ci.yml` |

### Sub-package 1: Composer lock fix (daems-platform)

Add a platform constraint to `composer.json` so future `composer update` runs resolve dependencies against the lowest supported PHP (8.1):

```json
"config": {
    ...existing keys...,
    "platform": { "php": "8.1.99" }
}
```

The `.99` suffix matches Composer's recommended pattern — the lock then accepts any package compatible with PHP ^8.1, but never picks a package that requires 8.2+.

After adding the platform key, run `composer update --with-all-dependencies` once locally to regenerate `composer.lock`. This will roll `symfony/string` from v8.0.8 back to a 7.x release, which satisfies both the transitive constraint (`symfony/console v7.4.8` requires `^7.2|^8.0`) and the PHP 8.1 baseline.

Verify locally before pushing: `composer analyse` (PHPStan still 0 errors) and `vendor/bin/phpunit` (all 779 tests pass). Risk: a transitive downgrade could trip a static-analysis edge case. If it does, address that before continuing.

### Sub-package 2: MySQL service in platform CI

Extend `.github/workflows/ci.yml` to spin up a MySQL 8.4 service container alongside each matrix job, mirroring the local dev DB (`C:/laragon/bin/mysql/mysql-8.4.3-winx64`). Required additions:

- `services.mysql` block with image `mysql:8.4`, env vars seeding root password + `daems_db_test` database, healthcheck.
- A `Wait for MySQL` step that polls `mysqladmin ping` until ready (typically ~10 s).
- A `Run migrations` step: `php scripts/apply_pending_migrations.php` against the test DB so the Integration suite has a fully-migrated schema before PHPUnit runs.
- Pass `TEST_DB_HOST=127.0.0.1`, `TEST_DB_PORT=3306`, `TEST_DB_USER=root`, `TEST_DB_PASS=salasana`, `TEST_DB_NAME=daems_db_test` as `env:` on the PHPUnit step.

The existing PHPUnit invocation does not change — it picks up these env vars via the same fallback logic the integration tests already use (`getenv('TEST_DB_HOST') ?: '127.0.0.1'`).

Expected CI duration after this change: ~3-4 minutes per matrix job (was ~13 s). Acceptable.

### Sub-package 3: Branch protection (`main`)

Configure via `gh api` rather than the GitHub UI — reproducible, auditable in shell history. One command per repo:

```bash
gh api -X PUT repos/Turtuure/daems-platform/branches/main/protection \
  --input - <<'EOF'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["PHP 8.1", "PHP 8.3"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": null,
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
EOF
```

The same call against `Turtuure/daem-society` with society-specific check names. Done last (after both repos' CI is green), otherwise the first PR after enabling protection cannot merge.

### Sub-package 4: Playwright job in society CI

Add a second job `e2e` to `daem-society/.github/workflows/ci.yml`. Triggered only on `pull_request: branches: [main]` (not on every dev push — too costly). Steps:

1. Checkout, setup PHP 8.3, composer install.
2. `npx playwright install --with-deps chromium`.
3. Boot the PHP dev server in the background: `php -S 127.0.0.1:8080 -t public public/router.php &`.
4. Wait briefly for the server to be reachable (`curl --retry 10 ...`).
5. Run `npx playwright test --project=chromium --reporter=line`.

Specs that need admin login (`loginAs(page, 'admin')`) skip themselves when `TEST_ADMIN_EMAIL` is unset — they pass green in CI without secrets. When the user later sets repo secrets + makes the platform reachable from the runner, those tests start exercising the real flow without any code change.

### Sub-package 5: `actions/checkout` v4 → v5

One-line bump in both workflow files: `uses: actions/checkout@v4` → `@v5`. Removes the Node 20 deprecation banner from every CI log.

## Component boundaries

- Each sub-package is independent. They could be merged in any order (with the constraint that #3 must be last).
- Sub-package 1 is the only one that risks breaking other code (lock changes affect runtime). Sub-packages 2, 4, 5 only affect CI behaviour.
- Sub-package 3 is the only one that is not a code change at all — it's repo configuration applied via API.

## Data flow

Nothing dynamic at runtime — every change is at build/test time:

```
push to dev (or PR to main)
  └─ GitHub Actions trigger
      ├─ daems-platform: matrix [8.1, 8.3]
      │   ├─ checkout v5
      │   ├─ setup-php with extensions pdo, pdo_mysql
      │   ├─ start mysql:8.4 service container
      │   ├─ composer install (now succeeds — lock fix)
      │   ├─ wait for MySQL
      │   ├─ run migrations against daems_db_test
      │   ├─ phpstan
      │   └─ phpunit (Unit + Integration + E2E)
      └─ daem-society: matrix [8.1, 8.3]
          ├─ checkout v5
          ├─ setup-php with extensions pdo, gd
          ├─ composer install
          ├─ phpstan
          └─ phpunit
              and (only on PR to main) e2e job:
                  ├─ npx playwright install
                  ├─ start php -S
                  └─ npx playwright test --project=chromium
```

## Error handling

| Failure | Behaviour |
|---|---|
| `composer install` finds incompatible lock again | CI fails fast; lock fix needs revisiting. The platform constraint should make this unrecoverable. |
| MySQL service slow to start | `Wait for MySQL` step polls up to 60 s; if it never comes up, the step fails with a clear message. |
| Migrations fail (e.g. SQL syntax error introduced) | `Run migrations` step fails before PHPUnit runs. The error log includes the offending migration filename. |
| PHPUnit Integration suite fails | Standard PHPUnit failure output. The MySQL service stays running for the duration of the job, so `--filter` can be added locally to reproduce. |
| Playwright server fails to boot | `curl` retry exhausts → step fails with clear message. Specs skip themselves on `loginAs` returning false; this never produces a misleading PASS. |
| Playwright login secrets missing | Specs that need them skip cleanly. Other specs run normally. CI is green. |
| Branch protection blocks a needed hotfix | Protection includes `enforce_admins: true` — even GSAs cannot bypass. If a true emergency arises, the user can flip protection off temporarily via UI or `gh api -X DELETE`. |

## Testing

The CI workflow itself is what we are testing. Verification is done by:

1. **Sub-package 1:** locally re-run `composer analyse` + `vendor/bin/phpunit` after the lock change to confirm 779/779 pass and PHPStan stays at 0 errors. Push the branch — first CI run must succeed.
2. **Sub-package 2:** push the branch — observe the platform CI now runs Integration tests (matrix takes ~3-4 min) and is green.
3. **Sub-package 4:** open a PR from `ci-hardening` → `main` (a throwaway PR if needed) so the `e2e` job triggers. Confirm it runs and is green.
4. **Sub-package 5:** verify the Node 20 warning no longer appears in CI logs.
5. **Sub-package 3:** after both repos' CI is green, run the two `gh api` commands. Verify with `gh api repos/Turtuure/<repo>/branches/main/protection` returning the new ruleset. Try to push to main directly — should be rejected with a protection-rule error.

## Implementation order

Implement in two PRs (one per repo) plus one branch-protection finalization:

1. **PR `ci-hardening` in daems-platform** → lock fix + MySQL service + checkout bump. Verify CI green. Merge.
2. **PR `ci-hardening` in daem-society** → Playwright job + checkout bump. Verify CI green. Merge.
3. **`gh api`** branch protection on both `main`s.

Sub-package 3 is intentionally last: enabling protection before the CI checks they require become green-able would block the very PRs that fix CI.

## File inventory

**daems-platform repo:**
- `composer.json` — add `config.platform.php: "8.1.99"` (~3 lines added)
- `composer.lock` — regenerated by `composer update --with-all-dependencies` (auto, ~hundreds of lines)
- `.github/workflows/ci.yml` — MySQL service + migration step + env vars + checkout bump (~30 lines added, 1 line modified)

**daem-society repo:**
- `.github/workflows/ci.yml` — new `e2e:` job + checkout bump (~25 lines added, 1 line modified)

**Repo configuration (no files):**
- Branch protection rules on `Turtuure/daems-platform` and `Turtuure/daem-society`, applied via `gh api`.

Estimated diff: daems-platform ~30 lines hand-written + auto lock changes. daem-society ~25 lines.
