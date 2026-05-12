<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlMemberFeeInvoiceRepositoryTest extends MigrationTestCase
{
    private SqlMemberFeeInvoiceRepository $repo;
    private TenantId $tenantId;
    private UserId $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->repo = new SqlMemberFeeInvoiceRepository($this->pdo());

        $tenantRow = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->tenantId = TenantId::fromString((string) $tenantRow);

        $this->userId = UserId::fromString('01958000-0000-7000-8000-000000000099');
        $this->pdo()->prepare(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $this->userId->value(), 'Test User', 'test@daems.fi', 'x', '1990-01-01', '2026-01-01 00:00:00',
        ]);
    }

    public function test_save_and_find_by_id_round_trip(): void
    {
        $inv = $this->newInvoice(MemberFeeInvoiceStatus::Pending);
        $this->repo->save($inv);

        $loaded = $this->repo->findById($inv->id());
        $this->assertNotNull($loaded);
        $this->assertSame(5000, $loaded->amountCents());
        $this->assertSame(MemberFeeInvoiceStatus::Pending, $loaded->status());
    }

    public function test_unique_violation_on_duplicate_year(): void
    {
        $inv = $this->newInvoice();
        $this->repo->save($inv);

        $dup = $this->newInvoice();
        $this->expectException(\PDOException::class);
        $this->repo->save($dup);
    }

    public function test_round_trip_with_payment(): void
    {
        $inv = $this->newInvoice();
        $inv->recordPayment(new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-08-01T10:00:00'),
            amountCents: 5000,
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
            paidBy:      $this->userId,
        ));
        $this->repo->save($inv);

        $loaded = $this->repo->findById($inv->id());
        $this->assertNotNull($loaded);
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $loaded->status());
        $this->assertSame(5000, $loaded->paidAmountCents());
        $this->assertSame('bank_transfer', $loaded->paidMethod());
    }

    public function test_findOverdueCandidates_filters_by_grace(): void
    {
        $past = $this->newInvoice(MemberFeeInvoiceStatus::Pending, dueDate: new DateTimeImmutable('2026-01-01'));
        $this->repo->save($past);

        $userTwo = UserId::fromString('01958000-0000-7000-8000-000000000098');
        $this->pdo()->prepare(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$userTwo->value(), 'User Two', 'two@daems.fi', 'x', '1990-01-01', '2026-01-01 00:00:00']);

        $invTwo = new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $userTwo,
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-10-01'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable('2026-10-01'),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable(),
        );
        $this->repo->save($invTwo);

        $candidates = $this->repo->findOverdueCandidates($this->tenantId, new DateTimeImmutable('2026-10-15'), 30);
        $this->assertCount(1, $candidates);
        $this->assertEquals($past->id(), $candidates[0]->id());
    }

    private function newInvoice(
        MemberFeeInvoiceStatus $status = MemberFeeInvoiceStatus::Pending,
        ?DateTimeImmutable $dueDate = null,
    ): MemberFeeInvoice {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $this->userId,
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             $dueDate ?? new DateTimeImmutable('2026-09-13'),
            status:              $status,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable('2026-07-15T02:00:00'),
        );
    }
}
