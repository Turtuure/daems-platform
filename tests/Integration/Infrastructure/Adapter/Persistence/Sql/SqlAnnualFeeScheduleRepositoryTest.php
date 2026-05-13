<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlAnnualFeeScheduleRepositoryTest extends MigrationTestCase
{
    private SqlAnnualFeeScheduleRepository $repo;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->repo = new SqlAnnualFeeScheduleRepository($this->pdo());
        // tenants seeded by mig 019
        $tenantRow = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->tenantId = TenantId::fromString((string) $tenantRow);
    }

    public function test_save_and_find_by_id(): void
    {
        $schedule = $this->schedule(MembershipType::Basic, 5000);
        $this->repo->save($schedule);
        $loaded = $this->repo->findById($schedule->id());

        $this->assertNotNull($loaded);
        $this->assertSame(5000, $loaded->amountCents());
        $this->assertSame(MembershipType::Basic, $loaded->feeType());
    }

    public function test_find_active_for_returns_only_active(): void
    {
        $superseded = $this->schedule(MembershipType::Basic, 4000, AnnualFeeScheduleStatus::Superseded);
        $active     = $this->schedule(MembershipType::Basic, 5000, AnnualFeeScheduleStatus::Active);
        $this->repo->save($superseded);
        $this->repo->save($active);

        $found = $this->repo->findActiveFor($this->tenantId, 2027, MembershipType::Basic);
        $this->assertNotNull($found);
        $this->assertSame(5000, $found->amountCents());
    }

    public function test_supersede_round_trip(): void
    {
        $schedule = $this->schedule(MembershipType::Basic, 5000, AnnualFeeScheduleStatus::Active);
        $this->repo->save($schedule);

        $schedule->supersede(new DateTimeImmutable('2027-01-15T10:00:00'));
        $this->repo->save($schedule);

        $loaded = $this->repo->findById($schedule->id());
        $this->assertNotNull($loaded);
        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $loaded->status());
        $this->assertEquals(new DateTimeImmutable('2027-01-15T10:00:00'), $loaded->supersededAt());
    }

    public function test_find_proposed_by_decision_returns_only_matching_proposed_rows(): void
    {
        $decId  = '01958000-0000-7000-8000-0000000000cc';
        $decId2 = '01958000-0000-7000-8000-0000000000dd';
        $this->seedBoardDecision($decId);
        $this->seedBoardDecision($decId2);

        // Two proposed rows tied to our decision
        $p1 = $this->schedule(MembershipType::Basic,     5000, AnnualFeeScheduleStatus::Proposed, $decId);
        $p2 = $this->schedule(MembershipType::Supporting, 1500, AnnualFeeScheduleStatus::Proposed, $decId);
        // Proposed row tied to a different decision (must not be returned)
        $p3 = $this->schedule(MembershipType::Full,         0, AnnualFeeScheduleStatus::Proposed, $decId2);
        // Active row tied to our decision (must not be returned — wrong status)
        $a1 = $this->schedule(MembershipType::Basic,     4000, AnnualFeeScheduleStatus::Active, $decId);
        $this->repo->save($p1);
        $this->repo->save($p2);
        $this->repo->save($p3);
        $this->repo->save($a1);

        $found = $this->repo->findProposedByDecision($decId);
        $this->assertCount(2, $found);
        $ids = array_map(fn($s) => $s->id()->value(), $found);
        $this->assertContains($p1->id()->value(), $ids);
        $this->assertContains($p2->id()->value(), $ids);
    }

    /**
     * Seeds the minimal parent rows required for a board_decisions FK:
     * users → boards → board_decisions.
     * Uses fixed, stable IDs so multiple calls across test methods don't conflict.
     */
    private function seedBoardDecision(string $decisionId): void
    {
        $userId  = '01958000-0000-7000-8000-0000000000bb';
        $boardId = '01958000-0000-7000-8000-0000000000b0';
        $pdo     = $this->pdo();

        // Seed user (idempotent — INSERT IGNORE)
        $pdo->exec(
            "INSERT IGNORE INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES ('{$userId}', 'Test User', 'test@test.invalid', 'x', '1990-01-01', '2026-01-01 00:00:00')"
        );
        // Seed board (idempotent)
        $pdo->exec(
            "INSERT IGNORE INTO boards (id, tenant_id, bootstrapped_by_user_id, bootstrapped_at, created_at)
             VALUES ('{$boardId}', '{$this->tenantId->value()}', '{$userId}', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
        // Seed board_decision
        $pdo->exec(
            "INSERT INTO board_decisions
                (id, board_id, decision_type, threshold, mode, vote_visibility, status,
                 proposed_by_user_id, proposed_at, expires_at, via_delegation)
             VALUES ('{$decisionId}', '{$boardId}', 'annual_fee_schedule', 'majority', 'sync', 'visible', 'passed',
                     '{$userId}', '2026-11-01 00:00:00', '2026-12-31 00:00:00', 0)"
        );
    }

    private function schedule(MembershipType $type, int $cents, AnnualFeeScheduleStatus $status = AnnualFeeScheduleStatus::Draft, ?string $decisionId = null): AnnualFeeSchedule
    {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $this->tenantId,
            year:         2027,
            feeType:      $type,
            amountCents:  $cents,
            currency:     'EUR',
            status:       $status,
            decisionId:   $decisionId,
            activatedAt:  $status === AnnualFeeScheduleStatus::Active ? new DateTimeImmutable() : null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable(),
            createdBy:    null,
        );
    }
}
