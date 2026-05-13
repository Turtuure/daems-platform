<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice;
use Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange\WaiveOpenInvoicesOnHonoraryChange;
use Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange\WaiveOpenInvoicesOnHonoraryChangeInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryFeeInvoiceAuditRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WaiveOpenInvoicesOnHonoraryChangeTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_waives_all_open_invoices(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        // 3 open (Pending, Overdue, Reduced) + 1 already Paid (must NOT be waived).
        $invoices->save($this->invoice(2024, MemberFeeInvoiceStatus::Pending));
        $invoices->save($this->invoice(2025, MemberFeeInvoiceStatus::Overdue));
        $invoices->save($this->invoice(2026, MemberFeeInvoiceStatus::Reduced));
        $paid = $this->invoice(2023, MemberFeeInvoiceStatus::Paid);
        $invoices->save($paid);

        $waiveUseCase = new WaiveMemberFeeInvoice($invoices, $audit, new FrozenClock(new DateTimeImmutable('2027-01-15')));
        $useCase = new WaiveOpenInvoicesOnHonoraryChange($invoices, $waiveUseCase);

        $count = $useCase->handle(new WaiveOpenInvoicesOnHonoraryChangeInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        ));

        $this->assertSame(3, $count);
        $this->assertCount(3, $audit->rows);
        // Verify the paid one stayed Paid.
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $invoices->findById($paid->id())?->status());
    }

    public function test_non_admin_rejected(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $waiveUseCase = new WaiveMemberFeeInvoice($invoices, $audit, new FrozenClock(new DateTimeImmutable()));
        $useCase = new WaiveOpenInvoicesOnHonoraryChange($invoices, $waiveUseCase);

        $this->expectException(ForbiddenException::class);
        $useCase->handle(new WaiveOpenInvoicesOnHonoraryChangeInput(
            actor:    $this->actor(UserTenantRole::Member),
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        ));
    }

    private function invoice(int $year, MemberFeeInvoiceStatus $status): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            year:                $year,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable("{$year}-07-15"),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable("{$year}-09-13"),
            status:              $status,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable("{$year}-07-15"),
        );
    }

    private function actor(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
