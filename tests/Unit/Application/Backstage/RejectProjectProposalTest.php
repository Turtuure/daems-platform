<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposal;
use Daems\Application\Backstage\RejectProjectProposal\RejectProjectProposalInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\ImmediateTransactionManager;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryProjectProposalRepository;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RejectProjectProposalTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';
    private const PROPOSAL_ID = '01959900-0000-7000-8000-00000000000a';
    private const ACTING_ADMIN_ID = '01958000-0000-7000-8000-000000000bbb';

    private function acting(
        bool $platformAdmin = true,
        ?UserTenantRole $role = UserTenantRole::Admin,
    ): ActingUser {
        return new ActingUser(
            id: UserId::fromString(self::ACTING_ADMIN_ID),
            email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function seedProposal(
        InMemoryProjectProposalRepository $repo,
        string $tenantId = self::TENANT,
        string $status = 'pending',
        string $title = 'Community Garden',
    ): void {
        $repo->save(new ProjectProposal(
            ProjectProposalId::fromString(self::PROPOSAL_ID),
            TenantId::fromString($tenantId),
            '01958000-0000-7000-8000-000000000aaa',
            'Anna',
            'anna@x',
            $title,
            'environment',
            'A summary for the community garden',
            'Full description of the community garden idea',
            $status,
            '2026-04-18 09:00:00',
        ));
    }

    private function uc(
        InMemoryProjectProposalRepository $proposals,
        InMemoryAdminApplicationDismissalRepository $dismissals,
        ?FrozenClock $clock = null,
    ): RejectProjectProposal {
        return new RejectProjectProposal(
            $proposals,
            $dismissals,
            new ImmediateTransactionManager(),
            $clock ?? FrozenClock::at('2026-04-20 12:00:00'),
        );
    }

    public function test_admin_rejects_records_decision(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $this->uc($proposals, $dismissals)->execute(
            new RejectProjectProposalInput($this->acting(), self::PROPOSAL_ID, 'not aligned'),
        );

        $updated = $proposals->findByIdForTenant(self::PROPOSAL_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($updated);
        self::assertSame('rejected', $updated->status());
        self::assertSame('2026-04-20 12:00:00', $updated->decidedAt());
        self::assertSame(self::ACTING_ADMIN_ID, $updated->decidedBy());
        self::assertSame('not aligned', $updated->decisionNote());
    }

    public function test_reject_does_not_create_project(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $projects = new InMemoryProjectRepository();
        $this->seedProposal($proposals);

        $this->uc($proposals, $dismissals)->execute(
            new RejectProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );

        // A rejected proposal must not produce a project row under the title's slug
        self::assertNull($projects->findBySlugForTenant('community-garden', TenantId::fromString(self::TENANT)));
    }

    public function test_reject_clears_dismissals(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $adminId = self::ACTING_ADMIN_ID;
        $dismissals->save(new AdminApplicationDismissal(
            id: '01959900-0000-7000-8000-000000000999',
            adminId: $adminId,
            appId: self::PROPOSAL_ID,
            appType: 'project_proposal',
            dismissedAt: new \DateTimeImmutable('2026-04-19 10:00:00'),
        ));
        self::assertContains(self::PROPOSAL_ID, $dismissals->listAppIdsDismissedByAdmin($adminId));

        $this->uc($proposals, $dismissals)->execute(
            new RejectProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );

        self::assertNotContains(self::PROPOSAL_ID, $dismissals->listAppIdsDismissedByAdmin($adminId));
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $proposals = new InMemoryProjectProposalRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $this->uc($proposals, $dismissals)->execute(
            new RejectProjectProposalInput($this->acting(platformAdmin: false, role: null), self::PROPOSAL_ID, null),
        );
    }

    public function test_not_found_when_proposal_not_in_tenant(): void
    {
        $this->expectException(NotFoundException::class);
        $proposals = new InMemoryProjectProposalRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals, tenantId: self::OTHER_TENANT);

        $this->uc($proposals, $dismissals)->execute(
            new RejectProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );
    }

    public function test_already_decided_throws_validation(): void
    {
        $this->expectException(ValidationException::class);
        $proposals = new InMemoryProjectProposalRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals, status: 'rejected');

        $this->uc($proposals, $dismissals)->execute(
            new RejectProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );
    }
}
