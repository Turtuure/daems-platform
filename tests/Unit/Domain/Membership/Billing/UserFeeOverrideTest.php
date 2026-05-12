<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UserFeeOverrideTest extends TestCase
{
    public function test_constructs_with_required_fields(): void
    {
        $o = $this->override();
        $this->assertSame(2500, $o->overrideAmountCents());
        $this->assertSame(MembershipType::Basic, $o->feeType());
        $this->assertSame('Opiskelija-alennus', $o->reason());
    }

    public function test_rejects_empty_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              '',
            decisionId:          null,
            createdBy:           UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            createdAt:           new DateTimeImmutable(),
            revokedAt:           null,
            revokedBy:           null,
        );
    }

    public function test_rejects_until_before_from(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2027-12-31'),
            validUntil:          new DateTimeImmutable('2026-01-01'),
            reason:              'invalid range',
            decisionId:          null,
            createdBy:           UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            createdAt:           new DateTimeImmutable(),
            revokedAt:           null,
            revokedBy:           null,
        );
    }

    public function test_isActive_at(): void
    {
        $o = $this->override();
        $this->assertTrue($o->isActiveAt(new DateTimeImmutable('2026-06-15')));
        $this->assertFalse($o->isActiveAt(new DateTimeImmutable('2025-06-15')));
        $this->assertFalse($o->isActiveAt(new DateTimeImmutable('2028-06-15')));
    }

    public function test_isActive_with_null_valid_until(): void
    {
        $o = $this->override(openEnded: true);
        $this->assertTrue($o->isActiveAt(new DateTimeImmutable('2099-06-15')));
    }

    public function test_revoke_sets_fields(): void
    {
        $o = $this->override();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $now = new DateTimeImmutable('2026-08-01T00:00:00');
        $o->revoke($actor, $now);

        $this->assertEquals($now, $o->revokedAt());
        $this->assertEquals($actor, $o->revokedBy());
        $this->assertFalse($o->isActiveAt(new DateTimeImmutable('2026-09-15')));
    }

    public function test_revoke_twice_is_idempotent(): void
    {
        $o = $this->override();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $first = new DateTimeImmutable('2026-08-01T00:00:00');
        $second = new DateTimeImmutable('2027-01-01T00:00:00');
        $o->revoke($actor, $first);
        $o->revoke($actor, $second);
        $this->assertEquals($first, $o->revokedAt());
    }

    private function override(bool $openEnded = false): UserFeeOverride
    {
        return new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          $openEnded ? null : new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
            decisionId:          null,
            createdBy:           UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            createdAt:           new DateTimeImmutable('2025-12-15'),
            revokedAt:           null,
            revokedBy:           null,
        );
    }
}
