<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FeeInvoiceAuditTest extends TestCase
{
    public function test_record_with_payload(): void
    {
        $audit = FeeInvoiceAudit::record(
            tenantId:  TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            invoiceId: MemberFeeInvoiceId::fromString('01958000-0000-7000-8000-0000000000aa'),
            action:    FeeInvoiceAuditAction::Waived,
            actor:     UserId::fromString('01958000-0000-7000-8000-0000000000bb'),
            now:       new DateTimeImmutable('2026-09-20T10:00:00'),
            payload:   ['reason' => 'Pitkäaikaissairaus'],
        );
        $this->assertSame(FeeInvoiceAuditAction::Waived, $audit->action);
        $this->assertSame('{"reason":"Pitkäaikaissairaus"}', $audit->payloadJson);
    }

    public function test_record_without_actor_for_cron(): void
    {
        $audit = FeeInvoiceAudit::record(
            tenantId:  TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            invoiceId: MemberFeeInvoiceId::fromString('01958000-0000-7000-8000-0000000000aa'),
            action:    FeeInvoiceAuditAction::OverdueFlagged,
            actor:     null,
            now:       new DateTimeImmutable('2026-10-15T03:00:00'),
            payload:   null,
        );
        $this->assertNull($audit->performedBy);
        $this->assertNull($audit->payloadJson);
    }

    public function test_id_is_uuid7(): void
    {
        $audit = FeeInvoiceAudit::record(
            tenantId:  TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            invoiceId: MemberFeeInvoiceId::fromString('01958000-0000-7000-8000-0000000000aa'),
            action:    FeeInvoiceAuditAction::Created,
            actor:     null,
            now:       new DateTimeImmutable(),
            payload:   null,
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $audit->id,
        );
    }
}
