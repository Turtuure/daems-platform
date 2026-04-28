# Deferred items — Members extraction Wave D (Task 19)

## Pre-existing failures in MemberStatusAuditStatsTest

Discovered while running Members integration suite during Task 19. Both failures exist in the original `tests/Integration/MemberStatusAuditStatsTest.php` on dev BEFORE the Wave D move and are merely reproduced (1:1) in the module copy. Out of scope for Wave D — flagging for follow-up.

- `MemberStatusAuditStatsTest::test_inactive_transitions_today_lands_on_last_sparkline_entry` — `Failed asserting that 0 is identical to 1` (line 64 / module 65)
- `MemberStatusAuditStatsTest::test_filters_by_new_status` — `Failed asserting that 0 is identical to 1` (line 91 / module 92)

Likely root cause: clock/date assumption (test inserts a row "today" but the stats query filters by a window that doesn't include today). Needs separate plan to fix.

The module copy passes byte-for-byte identical assertions to the original — move is correct.
