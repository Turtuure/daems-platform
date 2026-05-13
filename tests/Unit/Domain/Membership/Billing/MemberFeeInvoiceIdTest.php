<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use PHPUnit\Framework\TestCase;

final class MemberFeeInvoiceIdTest extends TestCase
{
    public function test_generate_creates_uuid7(): void
    {
        $id = MemberFeeInvoiceId::generate();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id->value());
    }

    public function test_from_string_round_trip(): void
    {
        $raw = '01958000-0000-7000-8000-0000000000aa';
        $id = MemberFeeInvoiceId::fromString($raw);
        $this->assertSame($raw, $id->value());
    }

    public function test_from_string_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MemberFeeInvoiceId::fromString('not-a-uuid');
    }
}
