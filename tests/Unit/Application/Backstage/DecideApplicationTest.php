<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DecideApplication\DecideApplication;
use Daems\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DecideApplicationTest extends TestCase
{
    private TenantId $tenant;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-04-20 12:00:00'); }
        };
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: false, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testNonAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Member), 'member', 'id', 'approved', null),
        );
    }

    public function testInvalidDecisionThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);

        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', 'id', 'maybe', null),
        );
    }

    public function testApplicationNotFoundThrows404(): void
    {
        $this->expectException(NotFoundException::class);

        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $m->method('findByIdForTenant')->willReturn(null);
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', 'id', 'approved', null),
        );
    }

    public function testRecordsDecisionForMember(): void
    {
        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->tenant, 'A', 'a@x', '1990-01-01', null, 'm', null, 'pending',
        );
        $m = $this->createMock(MemberApplicationRepositoryInterface::class);
        $m->method('findByIdForTenant')->willReturn($app);
        $m->expects($this->once())->method('recordDecision');
        $s = $this->createMock(SupporterApplicationRepositoryInterface::class);

        $out = (new DecideApplication($m, $s, $this->clock))->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', $app->id()->value(), 'approved', 'welcome'),
        );

        self::assertTrue($out->success);
    }
}
