<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserFeeOverrideRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlUserFeeOverrideRepositoryTest extends MigrationTestCase
{
    private SqlUserFeeOverrideRepository $repo;
    private TenantId $tenantId;
    private UserId $userId;
    private UserId $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->repo = new SqlUserFeeOverrideRepository($this->pdo());

        $this->tenantId = TenantId::fromString((string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn());
        $this->userId   = UserId::fromString('01958000-0000-7000-8000-000000000099');
        $this->adminId  = UserId::fromString('01958000-0000-7000-8000-0000000000aa');

        foreach ([$this->userId, $this->adminId] as $uid) {
            $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?)'
            )->execute([
                $uid->value(),
                'User-' . substr($uid->value(), -4),
                $uid->value() . '@daems.fi',
                '1990-01-01',
                '2026-01-01 00:00:00',
            ]);
        }
    }

    public function test_save_round_trip(): void
    {
        $o = $this->override();
        $this->repo->save($o);
        $loaded = $this->repo->findById($o->id());
        $this->assertNotNull($loaded);
        $this->assertSame(2500, $loaded->overrideAmountCents());
        $this->assertSame('Opiskelija-alennus', $loaded->reason());
    }

    public function test_findActiveFor_respects_time_window(): void
    {
        $o = $this->override();
        $this->repo->save($o);

        $hit = $this->repo->findActiveFor($this->tenantId, $this->userId, 'BASIC', new DateTimeImmutable('2026-06-15'));
        $this->assertNotNull($hit);

        $miss = $this->repo->findActiveFor($this->tenantId, $this->userId, 'BASIC', new DateTimeImmutable('2028-06-15'));
        $this->assertNull($miss);
    }

    public function test_revoke_round_trip(): void
    {
        $o = $this->override();
        $this->repo->save($o);
        $o->revoke($this->adminId, new DateTimeImmutable('2026-08-01T00:00:00'));
        $this->repo->save($o);

        $loaded = $this->repo->findById($o->id());
        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->revokedAt());
        $this->assertFalse($loaded->isActiveAt(new DateTimeImmutable('2026-09-15')));
    }

    public function test_listForTenant_with_activeOnly(): void
    {
        $active = $this->override();
        $oldPromo = new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            $this->tenantId,
            userId:              $this->userId,
            feeType:             MembershipType::Supporting,
            overrideAmountCents: 500,
            validFrom:           new DateTimeImmutable('2025-01-01'),
            validUntil:          new DateTimeImmutable('2025-12-31'),
            reason:              'Old promo',
            decisionId:          null,
            createdBy:           $this->adminId,
            createdAt:           new DateTimeImmutable('2025-01-01'),
            revokedAt:           null,
            revokedBy:           null,
        );
        $this->repo->save($active);
        $this->repo->save($oldPromo);

        $all = $this->repo->listForTenant($this->tenantId, activeOnly: false);
        $this->assertCount(2, $all);

        $activeOnly = $this->repo->listForTenant($this->tenantId, activeOnly: true);
        $this->assertCount(1, $activeOnly);
    }

    private function override(): UserFeeOverride
    {
        return new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            $this->tenantId,
            userId:              $this->userId,
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
            decisionId:          null,
            createdBy:           $this->adminId,
            createdAt:           new DateTimeImmutable('2025-12-15'),
            revokedAt:           null,
            revokedBy:           null,
        );
    }
}
