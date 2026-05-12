<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Governance;

use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDelegation;
use Daems\Domain\Governance\BoardDelegationId;
use Daems\Domain\Governance\Exception\DelegationNotPermittedForType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use PHPUnit\Framework\TestCase;

final class BoardDelegationTest extends TestCase
{
    public function test_construct_rejects_non_whitelisted_type(): void
    {
        $this->expectException(DelegationNotPermittedForType::class);
        new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::Expel,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-05-12'),
            revokedAt:         null,
        );
    }

    public function test_is_active_within_window(): void
    {
        $d = new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::ApproveBasic,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-05-01'),
            revokedAt:         null,
        );
        $this->assertTrue($d->isActive(new \DateTimeImmutable('2026-05-12')));
    }

    public function test_is_inactive_after_revoke(): void
    {
        $d = new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::AwardSubTier,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-05-01'),
            revokedAt:         new \DateTimeImmutable('2026-05-10'),
        );
        $this->assertFalse($d->isActive(new \DateTimeImmutable('2026-05-12')));
    }

    public function test_is_inactive_before_valid_from(): void
    {
        $d = new BoardDelegation(
            id:                BoardDelegationId::generate(),
            tenantId:          TenantId::generate(),
            decisionType:      BoardDecisionType::InviteFull,
            delegatedToRole:   UserTenantRole::Admin,
            sourceDecisionId:  BoardDecisionId::generate(),
            validFrom:         new \DateTimeImmutable('2026-06-01'),
            revokedAt:         null,
        );
        $this->assertFalse($d->isActive(new \DateTimeImmutable('2026-05-12')));
    }
}
