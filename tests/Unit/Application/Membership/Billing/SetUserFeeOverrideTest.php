<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride;
use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverrideInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SetUserFeeOverrideTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_admin_creates_override(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $useCase = new SetUserFeeOverride($repo, new FrozenClock(new DateTimeImmutable('2026-01-01')));

        $id = $useCase->handle(new SetUserFeeOverrideInput(
            actor:               $this->actor(UserTenantRole::Admin),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            feeType:             'BASIC',
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
        ));

        $this->assertNotEmpty($id);
        $this->assertCount(1, $repo->listForTenant(TenantId::fromString(self::TENANT_ID)));
    }

    public function test_platform_admin_can_create_in_any_tenant(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $useCase = new SetUserFeeOverride($repo, new FrozenClock(new DateTimeImmutable('2026-01-01')));

        $platformAdmin = new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'gsa@test',
            isPlatformAdmin:    true,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: null,
        );

        $id = $useCase->handle(new SetUserFeeOverrideInput(
            actor:               $platformAdmin,
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            feeType:             'BASIC',
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          null,
            reason:              'GSA-set',
        ));
        $this->assertNotEmpty($id);
    }

    public function test_member_rejected(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $useCase = new SetUserFeeOverride($repo, new FrozenClock(new DateTimeImmutable()));

        $this->expectException(ForbiddenException::class);
        $useCase->handle(new SetUserFeeOverrideInput(
            actor:               $this->actor(UserTenantRole::Member),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            feeType:             'BASIC',
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          null,
            reason:              'r',
        ));
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
