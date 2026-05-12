<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use PHPUnit\Framework\TestCase;

final class MemberFeeInvoiceStatusTest extends TestCase
{
    public function test_5_cases(): void
    {
        $this->assertSame('PENDING', MemberFeeInvoiceStatus::Pending->value);
        $this->assertSame('PAID',    MemberFeeInvoiceStatus::Paid->value);
        $this->assertSame('OVERDUE', MemberFeeInvoiceStatus::Overdue->value);
        $this->assertSame('WAIVED',  MemberFeeInvoiceStatus::Waived->value);
        $this->assertSame('REDUCED', MemberFeeInvoiceStatus::Reduced->value);
    }

    public function test_isOpen(): void
    {
        $this->assertTrue(MemberFeeInvoiceStatus::Pending->isOpen());
        $this->assertTrue(MemberFeeInvoiceStatus::Overdue->isOpen());
        $this->assertTrue(MemberFeeInvoiceStatus::Reduced->isOpen());
        $this->assertFalse(MemberFeeInvoiceStatus::Paid->isOpen());
        $this->assertFalse(MemberFeeInvoiceStatus::Waived->isOpen());
    }

    public function test_isFinal(): void
    {
        $this->assertTrue(MemberFeeInvoiceStatus::Paid->isFinal());
        $this->assertTrue(MemberFeeInvoiceStatus::Waived->isFinal());
        $this->assertFalse(MemberFeeInvoiceStatus::Pending->isFinal());
    }
}
