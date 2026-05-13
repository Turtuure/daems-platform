<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PaymentRecordTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $rec = new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-09-13T15:30:00'),
            amountCents: 5000,
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
            paidBy:      UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
        );
        $this->assertSame(5000, $rec->amountCents());
        $this->assertSame('bank_transfer', $rec->method());
        $this->assertSame('Nordea 12345/2026', $rec->reference());
    }

    public function test_rejects_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentRecord(
            paidAt: new DateTimeImmutable(),
            amountCents: 0,
            method: 'bank_transfer',
            reference: 'ref',
            paidBy: UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
        );
    }

    public function test_rejects_empty_method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentRecord(
            paidAt: new DateTimeImmutable(),
            amountCents: 5000,
            method: '',
            reference: 'ref',
            paidBy: UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
        );
    }
}
