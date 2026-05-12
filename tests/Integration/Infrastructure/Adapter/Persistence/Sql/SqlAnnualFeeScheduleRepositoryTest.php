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

    private function schedule(MembershipType $type, int $cents, AnnualFeeScheduleStatus $status = AnnualFeeScheduleStatus::Draft): AnnualFeeSchedule
    {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $this->tenantId,
            year:         2027,
            feeType:      $type,
            amountCents:  $cents,
            currency:     'EUR',
            status:       $status,
            decisionId:   null,
            activatedAt:  $status === AnnualFeeScheduleStatus::Active ? new DateTimeImmutable() : null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable(),
            createdBy:    null,
        );
    }
}
