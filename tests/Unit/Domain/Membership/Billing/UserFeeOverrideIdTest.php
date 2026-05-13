<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use PHPUnit\Framework\TestCase;

final class UserFeeOverrideIdTest extends TestCase
{
    public function test_generate_creates_uuid7(): void
    {
        $id = UserFeeOverrideId::generate();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id->value());
    }

    public function test_from_string_round_trip(): void
    {
        $raw = '01958000-0000-7000-8000-0000000000aa';
        $this->assertSame($raw, UserFeeOverrideId::fromString($raw)->value());
    }

    public function test_from_string_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UserFeeOverrideId::fromString('not-a-uuid');
    }

    public function test_equals(): void
    {
        $a = UserFeeOverrideId::fromString('01958000-0000-7000-8000-0000000000aa');
        $b = UserFeeOverrideId::fromString('01958000-0000-7000-8000-0000000000aa');
        $c = UserFeeOverrideId::fromString('01958000-0000-7000-8000-0000000000bb');
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
