<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Audit;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Governance\Exception\GsaOverrideRequiresReason;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class GsaOverrideTest extends TestCase
{
    public function test_rejects_short_reason(): void
    {
        $this->expectException(GsaOverrideRequiresReason::class);
        new GsaOverride(
            id:          GsaOverrideId::generate(),
            gsaUserId:   UserId::generate(),
            tenantId:    TenantId::generate(),
            action:      GsaOverrideAction::ForceApproveBasic,
            targetId:    '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason:      'too short',
            performedAt: new \DateTimeImmutable('2026-05-12'),
        );
    }

    public function test_accepts_reason_at_least_10_chars(): void
    {
        $o = new GsaOverride(
            id:          GsaOverrideId::generate(),
            gsaUserId:   UserId::generate(),
            tenantId:    TenantId::generate(),
            action:      GsaOverrideAction::ForceApproveBasic,
            targetId:    '01958000-0000-7000-8000-aaaaaaaaaaaa',
            reason:      'long enough reason here',
            performedAt: new \DateTimeImmutable('2026-05-12'),
        );
        $this->assertSame('long enough reason here', $o->reason);
    }
}
