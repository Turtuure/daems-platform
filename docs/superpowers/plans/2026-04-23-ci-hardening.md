# CI/CD Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make platform CI green, run the full PHPUnit suite (incl. Integration) against MySQL in CI, add a Playwright job to society CI, modernise both workflows, and protect both `main` branches.

**Architecture:** Two repos, two PRs, plus one final `gh api` call per repo for branch protection. All work lives on a `ci-hardening` branch in each repo.

**Tech Stack:** GitHub Actions, Composer 2.x, MySQL 8.4, Playwright (chromium), `gh` CLI.

**Spec:** `docs/superpowers/specs/2026-04-23-ci-hardening-design.md`

**Branch:** `ci-hardening` in both `daems-platform` and `daem-society` repos.

---

## File Structure

| Path | Repo | Action | Responsibility |
|---|---|---|---|
| `composer.json` | daems-platform | Modify | Add `config.platform.php: "8.1.99"` so future locks resolve against PHP 8.1 baseline. |
| `composer.lock` | daems-platform | Regenerate | Result of `composer update --with-all-dependencies`. Auto-managed; do not hand-edit. |
| `.github/workflows/ci.yml` | daems-platform | Modify | Add MySQL service + migration step + test DB env vars. Bump `actions/checkout@v4` → `@v5`. |
| `.github/workflows/ci.yml` | daem-society | Modify | Add `e2e:` job (PHP server + Playwright). Bump `actions/checkout@v4` → `@v5`. |
| _(no file)_ | both | `gh api` | Branch protection rules on `main`. Run after both PRs merged. |

---

## Conventions for this plan

- **Commit identity:** every commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`. NO `Co-Authored-By:` trailer. Never `git push` without explicit user instruction.
- **Working directory:** Tasks 1-4 in `C:\laragon\www\daems-platform`. Tasks 5-6 in `C:\laragon\www\sites\daem-society`. Task 7 hits both repos via `gh api`. Bash `cd` is reset between tool calls — prefix every command with `cd /c/laragon/www/<repo>/ && <cmd>`.
- **No `.claude/` staging:** if it appears, run `git reset HEAD .claude/` before committing.
- **Forbidden tool:** never invoke `mcp__code-review-graph__*` (hangs indefinitely).
- **Pre-commit hook failure → fix + create NEW commit, never `--amend`.**

---

## Task 1: Composer lock fix (daems-platform)

**Files:**
- Modify: `composer.json` (add `config.platform.php`)
- Regenerate: `composer.lock` (via `composer update --with-all-dependencies`)

Root cause: `composer.lock` currently locks `symfony/string v8.0.8` which requires PHP ≥ 8.4. The CI matrix runs PHP 8.1 + 8.3, so `composer install` aborts. Fix by declaring the lowest supported PHP as the platform baseline; Composer's resolver then never picks a 8.4-only release.

- [ ] **Step 1: Add `config.platform.php` to composer.json**

The current `config` block is:

```json
  "config": {
    "allow-plugins": {
      "infection/extension-installer": true
    }
  }
```

Replace with:

```json
  "config": {
    "allow-plugins": {
      "infection/extension-installer": true
    },
    "platform": {
      "php": "8.1.99"
    }
  }
```

The `.99` suffix is Composer's idiomatic way to express "any 8.1.x patch" without admitting 8.2.

- [ ] **Step 2: Regenerate the lock file**

```bash
cd /c/laragon/www/daems-platform && composer update --with-all-dependencies 2>&1 | tail -30
```

Expected: Composer prints a list of `- Downgrading symfony/string (v8.0.8 => v7.x.y)` plus possibly other transitive moves. No `Your lock file does not contain a compatible set of packages` error.

If a different package now blocks resolution, capture the error verbatim and stop — that's a new constraint that needs handling outside this task.

- [ ] **Step 3: Confirm symfony/string is no longer 8.x in the lock**

```bash
cd /c/laragon/www/daems-platform && grep -A 2 '"name": "symfony/string"' composer.lock | head -3
```

Expected: `"version": "v7.<minor>.<patch>"` (NOT `v8.x`).

- [ ] **Step 4: Run PHPStan locally to verify no static-analysis regressions from the dependency move**

```bash
cd /c/laragon/www/daems-platform && composer analyse 2>&1 | grep -E "OK|errors?" | head -3
```

Expected: `[OK] No errors`. If PHPStan now reports errors, the downgrade exposed an API change — investigate and fix before continuing.

- [ ] **Step 5: Run the full PHPUnit suite locally**

```bash
cd /c/laragon/www/daems-platform && vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -5
```

Expected: `OK (NNN tests, MMM assertions)`. If any Unit test fails, the lock change is unsafe — investigate.

(Skipping Integration locally because it requires the dev DB and takes ~12 min — CI will run it.)

- [ ] **Step 6: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add composer.json composer.lock && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "Chore(deps): pin composer platform to PHP 8.1.99; downgrade symfony/string from 8.0.x

Lock file was selecting symfony/string v8.0.8 (requires PHP 8.4) which
crashed CI on the 8.1 + 8.3 matrix. The platform constraint forces the
resolver to pick 8.1-compatible releases for all transitive deps."
```

---

## Task 2: MySQL service + checkout v5 in platform CI

**Files:**
- Modify: `.github/workflows/ci.yml` (replace entire file with the version below)

Add a `mysql:8.4` service container, a `Wait for MySQL` step, a `Run migrations` step, and the test DB env vars on the PHPUnit step. Bump `actions/checkout@v4` → `@v5`.

- [ ] **Step 1: Replace the workflow file**

Overwrite `.github/workflows/ci.yml` with this content:

```yaml
name: CI

on:
  push:
    branches: [dev]
  pull_request:
    branches: [main]

jobs:
  test:
    name: PHP ${{ matrix.php }}
    runs-on: ubuntu-latest

    strategy:
      matrix:
        php: ["8.1", "8.3"]

    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ROOT_PASSWORD: salasana
          MYSQL_DATABASE: daems_db_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -psalasana"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10

    env:
      TEST_DB_HOST: 127.0.0.1
      TEST_DB_PORT: 3306
      TEST_DB_USER: root
      TEST_DB_PASS: salasana
      TEST_DB_NAME: daems_db_test

    steps:
      - uses: actions/checkout@v5

      - name: Set up PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo, pdo_mysql
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Wait for MySQL
        run: |
          for i in $(seq 1 30); do
            if mysqladmin ping -h 127.0.0.1 -P 3306 -uroot -psalasana --silent; then
              echo "MySQL is up."
              exit 0
            fi
            echo "Waiting for MySQL ($i/30)…"
            sleep 2
          done
          echo "MySQL did not become ready in 60 s." >&2
          exit 1

      - name: Apply migrations to test DB
        env:
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_USER: root
          DB_PASS: salasana
          DB_NAME: daems_db_test
        run: php scripts/apply_pending_migrations.php

      - name: PHPStan
        run: vendor/bin/phpstan analyse --no-progress

      - name: PHPUnit (full suite)
        run: vendor/bin/phpunit
```

Notes:
- `apply_pending_migrations.php` reads connection details from hardcoded variables in the script (`$host = '127.0.0.1'`, etc.). The `env:` block on that step is informational — the script's defaults already match the service container. No script edits required for this plan.
- The PHPUnit step uses the matrix-level `env:` block for `TEST_DB_*` so the integration tests' `getenv('TEST_DB_*')` calls resolve to the service container.

- [ ] **Step 2: Validate workflow YAML**

```bash
cd /c/laragon/www/daems-platform && \
  python -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" 2>&1 && \
  echo "YAML OK"
```

Expected: `YAML OK`. If python is not on PATH, an alternative: `actionlint` if installed, or simply rely on GitHub's parser when the branch is pushed.

- [ ] **Step 3: Commit**

```bash
cd /c/laragon/www/daems-platform && \
  git add .github/workflows/ci.yml && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "CI(platform): MySQL 8.4 service + migrations + checkout v5

Adds a mysql:8.4 service container so the Integration suite (which
requires a real DB per the MigrationTestCase pattern) runs in CI.
Migrations applied via apply_pending_migrations.php before PHPUnit.
checkout@v4 -> v5 silences the Node 20 deprecation banner."
```

---

## Task 3: Push platform branch and verify CI

- [ ] **Step 1: Push the branch**

```bash
cd /c/laragon/www/daems-platform && git push -u origin ci-hardening 2>&1
```

Expected output includes `* [new branch]      ci-hardening -> ci-hardening` and the GitHub PR-create URL.

- [ ] **Step 2: Wait for the CI run, then inspect status**

The push triggers CI on the branch (because the workflow's `on.push` includes `dev` only — but the PR will trigger on `pull_request: branches: [main]`. To get a CI run for verification before opening the PR, also temporarily push to a tagged branch, OR open a draft PR right away.)

Open a draft PR to trigger CI on the new branch:

```bash
cd /c/laragon/www/daems-platform && \
  gh pr create --draft --base dev --head ci-hardening \
    --title "WIP: CI hardening — composer lock + MySQL service + checkout v5" \
    --body "Draft to trigger CI. Will switch to ready when both jobs are green."
```

Expected: a PR URL is printed.

- [ ] **Step 3: Wait for CI to complete and report status**

```bash
cd /c/laragon/www/daems-platform && \
  gh run list --branch ci-hardening --limit 1 --json status,conclusion,databaseId 2>&1
```

If the latest run is still `in_progress`, poll until completion:

```bash
cd /c/laragon/www/daems-platform && \
  RUN_ID=$(gh run list --branch ci-hardening --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

Expected: both matrix legs (`PHP 8.1` and `PHP 8.3`) finish with conclusion `success`. CI duration ~3-5 minutes per leg.

If a leg fails:
- `composer install` failure → revisit Task 1 (lock not actually fixed).
- `Wait for MySQL` timeout → service container slow; bump retries to 30 → 60.
- Migration failure → check the migration filename in the log; the issue is in the migration SQL, not the workflow.
- PHPUnit failure → triage normally.

Do NOT proceed past this step until both legs are green.

---

## Task 4: Mark PR ready, request merge

- [ ] **Step 1: Update PR title + body for review**

```bash
cd /c/laragon/www/daems-platform && \
  PR_NUM=$(gh pr list --head ci-hardening --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "CI hardening (platform): composer lock + MySQL service + checkout v5" \
    --body "$(cat <<'EOF'
## Summary
- Pin composer platform to PHP 8.1.99 → resolves transitive symfony/string 8.0.8 (requires PHP 8.4) downgrade. Lock regenerated; CI matrix (8.1 + 8.3) now installs cleanly.
- Add mysql:8.4 service container to the workflow + Wait-for-MySQL + Apply-migrations steps so the Integration suite runs against a real DB in CI.
- Bump actions/checkout v4 → v5 (removes Node 20 deprecation banner).

## Test plan
- [x] composer analyse green locally after lock update
- [x] vendor/bin/phpunit --testsuite=Unit green locally after lock update
- [x] Both CI matrix legs (PHP 8.1, PHP 8.3) green
- [ ] Manual: confirm CI duration is in the 3-5 min range per leg

## Spec
docs/superpowers/specs/2026-04-23-ci-hardening-design.md

## Plan
docs/superpowers/plans/2026-04-23-ci-hardening.md
EOF
)" && \
  gh pr ready "$PR_NUM"
```

Expected: PR moves out of draft state.

- [ ] **Step 2: Report the PR URL to the user and wait for explicit "mergaa platform PR" before merging**

Per the established workflow: do NOT auto-merge. Print the PR URL, wait.

When user gives the go-ahead, merge with merge-commit:

```bash
cd /c/laragon/www/daems-platform && \
  PR_NUM=$(gh pr list --head ci-hardening --json number --jq '.[0].number') && \
  gh pr merge "$PR_NUM" --merge --delete-branch
```

Then sync local dev:

```bash
cd /c/laragon/www/daems-platform && \
  git checkout dev && \
  git pull --ff-only origin dev
```

---

## Task 5: Add Playwright job + checkout v5 in society CI

**Files:**
- Modify: `.github/workflows/ci.yml` in `daem-society` repo (replace entire file)

Add a second job `e2e` that runs only on PRs targeting main. Boots `php -S` and runs Playwright on chromium.

- [ ] **Step 1: Switch to society branch (already created earlier in this plan execution)**

```bash
cd /c/laragon/www/sites/daem-society && git checkout ci-hardening && git branch --show-current
```

Expected: `ci-hardening`.

- [ ] **Step 2: Replace `.github/workflows/ci.yml`**

Overwrite with:

```yaml
name: CI

on:
  push:
    branches: [dev]
  pull_request:
    branches: [main]

jobs:
  test:
    name: PHP ${{ matrix.php }}
    runs-on: ubuntu-latest

    strategy:
      matrix:
        php: ["8.1", "8.3"]

    steps:
      - uses: actions/checkout@v5

      - name: Set up PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo, gd
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: PHPStan
        run: vendor/bin/phpstan analyse --no-progress

      - name: PHPUnit
        run: vendor/bin/phpunit

  e2e:
    name: Playwright (chromium)
    runs-on: ubuntu-latest
    if: github.event_name == 'pull_request'
    needs: test

    steps:
      - uses: actions/checkout@v5

      - name: Set up PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: pdo, gd
          coverage: none

      - name: Install PHP dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Set up Node 20
        uses: actions/setup-node@v4
        with:
          node-version: "20"

      - name: Install Node dependencies
        run: npm ci

      - name: Install Playwright browsers (chromium only)
        run: npx playwright install --with-deps chromium

      - name: Start PHP dev server
        run: |
          php -S 127.0.0.1:8080 -t public public/router.php > /tmp/php-server.log 2>&1 &
          for i in $(seq 1 20); do
            if curl --silent --fail http://127.0.0.1:8080/ >/dev/null; then
              echo "PHP server is up."
              exit 0
            fi
            echo "Waiting for PHP server ($i/20)…"
            sleep 1
          done
          echo "PHP server did not become ready in 20 s." >&2
          cat /tmp/php-server.log >&2
          exit 1

      - name: Run Playwright (chromium)
        env:
          BASE_URL: http://127.0.0.1:8080
        run: npx playwright test --project=chromium --reporter=line
```

Note: specs that require `loginAs(page, 'admin')` skip themselves cleanly when `TEST_ADMIN_EMAIL` / `TEST_ADMIN_PASSWORD` are unset. The job stays green without secrets — exercise of those flows is unlocked later by adding repo secrets.

- [ ] **Step 3: YAML validation**

```bash
cd /c/laragon/www/sites/daem-society && \
  python -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" 2>&1 && \
  echo "YAML OK"
```

Expected: `YAML OK`.

- [ ] **Step 4: Commit**

```bash
cd /c/laragon/www/sites/daem-society && \
  git add .github/workflows/ci.yml && \
  git -c user.name="Dev Team" -c user.email="dev@daems.org" commit \
    -m "CI(society): add Playwright e2e job (chromium); checkout v5

New e2e job runs only on PR-to-main. Boots php -S on 127.0.0.1:8080
and executes the Playwright suite (chromium project). Login-gated
specs skip themselves via the existing loginAs() pattern when
TEST_ADMIN_* env vars are unset, so the job is green out-of-the-box."
```

---

## Task 6: Push society branch, PR, merge

- [ ] **Step 1: Push and open draft PR**

```bash
cd /c/laragon/www/sites/daem-society && \
  git push -u origin ci-hardening && \
  gh pr create --draft --base dev --head ci-hardening \
    --title "WIP: CI hardening — Playwright e2e job + checkout v5" \
    --body "Draft to trigger CI."
```

- [ ] **Step 2: Wait for CI to complete**

```bash
cd /c/laragon/www/sites/daem-society && \
  RUN_ID=$(gh run list --branch ci-hardening --limit 1 --json databaseId --jq '.[0].databaseId') && \
  gh run watch "$RUN_ID" 2>&1 | tail -10
```

Expected: `test` matrix legs green. The `e2e` job is `if: github.event_name == 'pull_request'` — confirm it ran (not skipped) since this IS a PR. It should be green (login-gated specs skip cleanly).

Do NOT proceed past this step until all jobs are green.

- [ ] **Step 3: Move PR to ready, edit title + body**

```bash
cd /c/laragon/www/sites/daem-society && \
  PR_NUM=$(gh pr list --head ci-hardening --json number --jq '.[0].number') && \
  gh pr edit "$PR_NUM" \
    --title "CI hardening (society): Playwright e2e job + checkout v5" \
    --body "$(cat <<'EOF'
## Summary
- Add Playwright e2e job to .github/workflows/ci.yml — runs on PR-to-main only.
- Boots php -S on 127.0.0.1:8080, runs npx playwright test --project=chromium.
- Login-gated specs skip cleanly when TEST_ADMIN_EMAIL/PASSWORD secrets are unset; job is green out-of-the-box.
- Bumps actions/checkout v4 → v5 (Node 20 deprecation removed).

## Test plan
- [x] YAML validates
- [x] Both test matrix legs (PHP 8.1, PHP 8.3) green
- [x] e2e job runs and is green
- [ ] Manual: when TEST_ADMIN_* secrets get added later, login-gated specs should automatically start exercising

## Spec / Plan
- Spec: daems-platform/docs/superpowers/specs/2026-04-23-ci-hardening-design.md
EOF
)" && \
  gh pr ready "$PR_NUM"
```

- [ ] **Step 4: Report PR URL and wait for "mergaa society PR" before merging**

When the user authorizes:

```bash
cd /c/laragon/www/sites/daem-society && \
  PR_NUM=$(gh pr list --head ci-hardening --json number --jq '.[0].number') && \
  gh pr merge "$PR_NUM" --merge --delete-branch && \
  git checkout dev && git pull --ff-only origin dev
```

---

## Task 7: Branch protection on `main` in both repos

Run AFTER both PRs are merged. Otherwise the very PR that fixes CI cannot be merged because the protection requires checks that don't exist yet.

This is a single shell command per repo. Each modifies repo configuration on github.com (sensitive shared state) — the agent MUST report what is about to happen, then run only after explicit user authorisation.

- [ ] **Step 1: Show the planned commands to the user, wait for go-ahead**

Print this to the user verbatim:

> About to apply branch protection to `main` in both repos. After this:
> - No direct pushes to `main` (PR required).
> - PR cannot merge unless `PHP 8.1` and `PHP 8.3` checks are green.
> - `enforce_admins: true` — even GSAs cannot bypass.
>
> Run? (yes / no)

Wait for explicit "yes" or equivalent.

- [ ] **Step 2: Apply protection on daems-platform**

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

Expected: a JSON response describing the new protection rules. No error.

- [ ] **Step 3: Apply protection on daem-society**

```bash
gh api -X PUT repos/Turtuure/daem-society/branches/main/protection \
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

Expected: same as Step 2.

- [ ] **Step 4: Verify both protections are active**

```bash
gh api repos/Turtuure/daems-platform/branches/main/protection --jq '.required_status_checks.contexts' 2>&1
gh api repos/Turtuure/daem-society/branches/main/protection --jq '.required_status_checks.contexts' 2>&1
```

Expected: both print `["PHP 8.1","PHP 8.3"]`.

- [ ] **Step 5: Final report**

Report to the user:
- Both PRs merged (with merge-commit SHAs).
- Branch protection active on both `main`s.
- CI matrix sizes (~3-5 min platform, ~30 s society test job + ~3-4 min e2e on PR-to-main).
- Note that the next PR-to-main on either repo will exercise the new protection rules.
