<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use PHPUnit\Framework\TestCase;

final class AnnualFeeScheduleStatusTest extends TestCase
{
    public function test_has_4_states(): void
    {
        $this->assertSame('draft',      AnnualFeeScheduleStatus::Draft->value);
        $this->assertSame('proposed',   AnnualFeeScheduleStatus::Proposed->value);
        $this->assertSame('active',     AnnualFeeScheduleStatus::Active->value);
        $this->assertSame('superseded', AnnualFeeScheduleStatus::Superseded->value);
    }

    public function test_from_string_round_trip(): void
    {
        foreach (['draft', 'proposed', 'active', 'superseded'] as $value) {
            $this->assertSame($value, AnnualFeeScheduleStatus::from($value)->value);
        }
    }
}
