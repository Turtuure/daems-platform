<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DismissApplication\DismissApplication;
use Daems\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryMemberApplicationRepository;
use Daems\Tests\Support\Fake\InMemorySupporterApplicationRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListPendingApplicationsForAdminTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function adminActingUser(string $userId = '01958000-0000-7000-8000-000000000010'): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString($userId),
            email: 'admin@x.com',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function nonAdminActingUser(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-000000000011'),
            email: 'member@x.com',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Member,
        );
    }

    private function makeMemberApp(string $id, string $name = 'Member'): MemberApplication
    {
        return new MemberApplication(
            MemberApplicationId::fromString($id),
            $this->tenant,
            $name,
            strtolower($name) . '@x.test',
            '1990-01-01',
            null,
            'motivation',
            null,
            'pending',
        );
    }

    private function makeSupporterApp(string $id, string $contactPerson = 'Org Contact'): SupporterApplication
    {
        return new SupporterApplication(
            SupporterApplicationId::fromString($id),
            $this->tenant,
            'Org Name',
            $contactPerson,
            null,
            strtolower(str_replace(' ', '', $contactPerson)) . '@org.test',
            null,
            'motivation',
            null,
            'pending',
        );
    }

    private function makeClock(): \Daems\Domain\Shared\Clock
    {
        return new class implements \Daems\Domain\Shared\Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-04-20 12:00:00');
            }
        };
    }

    private function makeIds(string $id = 'd-1'): \Daems\Domain\Shared\IdGeneratorInterface
    {
        return new class($id) implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function __construct(private readonly string $id) {}
            public function generate(): string { return $this->id; }
        };
    }

    public function test_merges_member_and_supporter_excludes_dismissed(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();

        $member1 = $this->makeMemberApp('01958000-0000-7000-8000-000000000021', 'Alice');
        $member2 = $this->makeMemberApp('01958000-0000-7000-8000-000000000022', 'Bob');
        $member3 = $this->makeMemberApp('01958000-0000-7000-8000-000000000023', 'Carol');
        $supporter1 = $this->makeSupporterApp('01958000-0000-7000-8000-000000000024', 'Dana');
        $supporter2 = $this->makeSupporterApp('01958000-0000-7000-8000-000000000025', 'Eve');

        $memberApps->save($member1);
        $memberApps->save($member2);
        $memberApps->save($member3);
        $supporterApps->save($supporter1);
        $supporterApps->save($supporter2);

        // Admin dismisses member3
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');
        $dismisser = new DismissApplication($dismissals, $this->makeClock(), $this->makeIds('dis-1'));
        $dismisser->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000023', 'member'));

        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(4, $out->total);
        self::assertCount(4, $out->items);

        $ids = array_column($out->items, 'id');
        self::assertContains('01958000-0000-7000-8000-000000000021', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000022', $ids);
        self::assertNotContains('01958000-0000-7000-8000-000000000023', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000024', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000025', $ids);
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);

        $sut = new ListPendingApplicationsForAdmin(
            new InMemoryMemberApplicationRepository(),
            new InMemorySupporterApplicationRepository(),
            new InMemoryAdminApplicationDismissalRepository(),
        );
        $sut->execute(new ListPendingApplicationsForAdminInput($this->nonAdminActingUser()));
    }
}
