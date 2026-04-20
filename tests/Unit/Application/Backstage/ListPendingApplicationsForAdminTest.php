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
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryMemberApplicationRepository;
use Daems\Tests\Support\Fake\InMemoryProjectProposalRepository;
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

    private function makeMemberApp(string $id, string $name = 'Member', string $createdAt = '2026-04-01 10:00:00'): MemberApplication
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
            $createdAt,
        );
    }

    private function makeSupporterApp(string $id, string $contactPerson = 'Org Contact', string $createdAt = '2026-04-02 10:00:00'): SupporterApplication
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
            $createdAt,
        );
    }

    private function makeProposal(string $id, string $title = 'Community Garden', string $createdAt = '2026-04-20 10:00:00'): ProjectProposal
    {
        return new ProjectProposal(
            ProjectProposalId::fromString($id),
            $this->tenant,
            '01958000-0000-7000-8000-0000000000aa',
            'Proposer Name',
            'proposer@x.test',
            $title,
            'environment',
            'A summary for the proposal',
            'A description for the proposal',
            'pending',
            $createdAt,
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
        $proposalRepo  = new InMemoryProjectProposalRepository();

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

        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(4, $out->total);
        self::assertCount(4, $out->items);

        $ids = array_column($out->items, 'id');
        self::assertContains('01958000-0000-7000-8000-000000000021', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000022', $ids);
        self::assertNotContains('01958000-0000-7000-8000-000000000023', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000024', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000025', $ids);

        // created_at is propagated from entities
        foreach ($out->items as $item) {
            self::assertNotSame('', $item['created_at'], "created_at should be non-empty for item {$item['id']}");
        }
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);

        $sut = new ListPendingApplicationsForAdmin(
            new InMemoryMemberApplicationRepository(),
            new InMemorySupporterApplicationRepository(),
            new InMemoryAdminApplicationDismissalRepository(),
            new InMemoryProjectProposalRepository(),
        );
        $sut->execute(new ListPendingApplicationsForAdminInput($this->nonAdminActingUser()));
    }

    public function test_output_includes_pending_project_proposals(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();

        $proposal = $this->makeProposal('01959900-0000-7000-8000-0000000000aa', 'Community Garden');
        $proposalRepo->save($proposal);

        $acting = $this->adminActingUser();
        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(1, $out->total);
        self::assertCount(1, $out->items);
        $item = $out->items[0];
        self::assertSame('01959900-0000-7000-8000-0000000000aa', $item['id']);
        self::assertSame('project_proposal', $item['type']);
        self::assertSame('Community Garden', $item['name']);
        self::assertSame('2026-04-20 10:00:00', $item['created_at']);
    }

    public function test_dismissed_proposal_is_excluded(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();

        $proposalId = '01959900-0000-7000-8000-0000000000bb';
        $proposalRepo->save($this->makeProposal($proposalId, 'Dismissed Proposal'));

        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $dismisser = new DismissApplication($dismissals, $this->makeClock(), $this->makeIds('dis-proposal'));
        $dismisser->execute(new DismissApplicationInput($acting, $proposalId, 'project_proposal'));

        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(0, $out->total);
        self::assertSame([], $out->items);
    }
}
