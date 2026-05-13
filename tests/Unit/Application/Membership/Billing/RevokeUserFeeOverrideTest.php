<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride;
use Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverrideInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RevokeUserFeeOverrideTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_admin_revokes_override(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $existing = $this->seedOverride($repo);

        $useCase = new RevokeUserFeeOverride($repo, new FrozenClock(new DateTimeImmutable('2026-08-01T00:00:00')));
        $useCase->handle(new RevokeUserFeeOverrideInput(
            actor:      $this->actor(UserTenantRole::Admin),
            overrideId: $existing->id(),
        ));

        $loaded = $repo->findById($existing->id());
        $this->assertNotNull($loaded);
        $this->assertEquals(new DateTimeImmutable('2026-08-01T00:00:00'), $loaded->revokedAt());
    }

    public function test_throws_when_override_not_found(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $useCase = new RevokeUserFeeOverride($repo, new FrozenClock(new DateTimeImmutable()));

        $this->expectException(\DomainException::class);
        $useCase->handle(new RevokeUserFeeOverrideInput(
            actor:      $this->actor(UserTenantRole::Admin),
            overrideId: UserFeeOverrideId::generate(),
        ));
    }

    public function test_member_rejected(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $existing = $this->seedOverride($repo);

        $useCase = new RevokeUserFeeOverride($repo, new FrozenClock(new DateTimeImmutable()));
        $this->expectException(ForbiddenException::class);
        $useCase->handle(new RevokeUserFeeOverrideInput(
            actor:      $this->actor(UserTenantRole::Member),
            overrideId: $existing->id(),
        ));
    }

    private function seedOverride(InMemoryUserFeeOverrideRepository $repo): UserFeeOverride
    {
        $o = new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
            decisionId:          null,
            createdBy:           UserId::fromString(self::ADMIN_ID),
            createdAt:           new DateTimeImmutable('2025-12-15'),
            revokedAt:           null,
            revokedBy:           null,
        );
        $repo->save($o);
        return $o;
    }

    private function actor(UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
