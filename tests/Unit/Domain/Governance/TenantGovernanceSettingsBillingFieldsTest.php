<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class TenantGovernanceSettingsBillingFieldsTest extends TestCase
{
    public function test_defaults_match_migration_094(): void
    {
        $settings = new TenantGovernanceSettings(
            tenantId:                          TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     false,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
        );

        $this->assertFalse($settings->requiresFormalDecisionForFees());
        $this->assertSame(60, $settings->defaultDueDaysFromAnniversary());
        $this->assertSame(30, $settings->overdueGraceDays());
        $this->assertTrue($settings->lapseCheckEnabled());
    }
}
