# Deferred items — Members extraction Wave D (Task 19)

## Pre-existing failures in MemberStatusAuditStatsTest

Discovered while running Members integration suite during Task 19. Both failures exist in the original `tests/Integration/MemberStatusAuditStatsTest.php` on dev BEFORE the Wave D move and are merely reproduced (1:1) in the module copy. Out of scope for Wave D — flagging for follow-up.

- `MemberStatusAuditStatsTest::test_inactive_transitions_today_lands_on_last_sparkline_entry` — `Failed asserting that 0 is identical to 1` (line 64 / module 65)
- `MemberStatusAuditStatsTest::test_filters_by_new_status` — `Failed asserting that 0 is identical to 1` (line 91 / module 92)

Likely root cause: clock/date assumption (test inserts a row "today" but the stats query filters by a window that doesn't include today). Needs separate plan to fix.

The module copy passes byte-for-byte identical assertions to the original — move is correct.

## Pre-existing Insights isolation failures (discovered during Members Wave G verification)

Five Insights isolation tests fail with `PDOException: SQLSTATE[HY000]: General error: 1364 Field 'title' doesn't have a default value`. These predate the Members extraction (the dp-insights module hasn't been touched in this session) and reproduce on a fresh test DB:

- `DaemsModule\Insights\Tests\Isolation\InsightStatsTenantIsolationTest::test_stats_isolate_published_count_by_tenant` (line 36)
- `DaemsModule\Insights\Tests\Isolation\InsightStatsTenantIsolationTest::test_stats_isolate_featured_count_by_tenant`
- `DaemsModule\Insights\Tests\Isolation\InsightStatsTenantIsolationTest::test_stats_isolate_scheduled_count_by_tenant`
- `DaemsModule\Insights\Tests\Isolation\InsightTenantIsolationTest::test_list_isolates_by_tenant`
- `DaemsModule\Insights\Tests\Isolation\InsightTenantIsolationTest::test_find_by_slug_requires_matching_tenant`

Likely root cause: Insights moved to i18n schema (legacy `insights.title` was dropped, `insights_i18n.title` is the source of truth), but these tests insert into `insights` directly and don't satisfy any constraint that still requires `title` either as a column on `insights` or via a trigger. Needs a separate fix on the dp-insights module side.

The test DB pollution from a prior killed phpunit run inflated this number to 49 errors at one point. With a fresh DB (`DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test ...`), the failures are exactly 5 and confined to dp-insights.

## Full Integration suite at scale (Wave G post-extraction)

Running `vendor/bin/phpunit --testsuite=Integration` from `daems-platform` (which discovers all 5 modules' integration tests via phpunit.xml `<directory>../modules/*/backend/tests/Integration</directory>`) reports **183 tests, 140 errors** on a freshly recreated `daems_db_test`. The errors cluster around:

- `PDOException: Failed to open the referenced table 'events'` (FK violations)
- `PDOException: Duplicate column name 'role'` (users.role added twice)
- `PDOException: Deadlock found when trying to get lock`

**This pre-dates the Members extraction** — the same `Duplicate column name 'role'` error reproduces on the parent commit `165f75c` (before any Members work) when running the equivalent test suite. The Wave E `MigrationTestCase` slot-gating fix improved per-test stability (each individual test passes when run alone with a fresh DB), but did not solve the suite-level interference.

**Reproducer of the underlying mystery (untriaged):** with a fresh `daems_db_test`, run only `006_create_users_table.sql` followed by `forum_001_create_forum_tables.sql` via PHP PDO. After the second migration runs, `users` mysteriously gains a `role VARCHAR(30)` column even though `forum_001` only contains 3 `CREATE TABLE IF NOT EXISTS` statements for forum_categories/forum_topics/forum_posts (none of which ALTER users). Reproduced via the standalone PHP script that uses `MigrationTestCase`-equivalent SQL parsing (`preg_split('/;[\r\n]+/', $sql)`). This appears to be either a PDO connection-state leak across unrelated `exec()` calls or a cross-test cache contamination — investigation warrants a separate Phase 1 follow-up plan.

**What IS green and verified for the Members extraction:**
- PHPStan level 9: 0 errors (with the regenerated baseline)
- Unit suite: 649/649 passing
- E2E suite: 99/99 passing
- Isolation suite (full): 44/49 passing (5 pre-existing Insights failures, unrelated)
- Members module's own tests, run in isolation: 17 unit + 8 integration + 5 isolation + 5 E2E all green when invoked solo
- Single-test reproduction of every "failing" Integration test passes when run with `--filter <name>` against a freshly recreated test DB

The Members extraction itself is clean. The full-scale Integration suite's pre-existing fragility is a separate infrastructure debt to address.
