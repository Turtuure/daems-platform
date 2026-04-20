<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposalInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Shared\IdGeneratorInterface;
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

final class ApproveProjectProposalTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';
    private const PROPOSAL_ID = '01959900-0000-7000-8000-00000000000a';
    private const PROPOSAL_USER_ID = '01958000-0000-7000-8000-000000000aaa';
    private const ACTING_ADMIN_ID = '01958000-0000-7000-8000-000000000bbb';

    private function acting(
        bool $platformAdmin = true,
        ?UserTenantRole $role = UserTenantRole::Admin,
        ?UserId $id = null,
    ): ActingUser {
        return new ActingUser(
            id: $id ?? UserId::fromString(self::ACTING_ADMIN_ID),
            email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function ids(string $fixed = '01959900-0000-7000-8000-000000000111'): IdGeneratorInterface
    {
        return new class($fixed) implements IdGeneratorInterface {
            private int $counter = 0;
            public function __construct(private readonly string $base) {}

            public function generate(): string
            {
                $this->counter++;
                if ($this->counter === 1) {
                    return $this->base;
                }
                $suffix = str_pad((string) $this->counter, 2, '0', STR_PAD_LEFT);
                return substr($this->base, 0, -2) . $suffix;
            }
        };
    }

    private function seedProposal(
        InMemoryProjectProposalRepository $repo,
        string $tenantId = self::TENANT,
        string $status = 'pending',
        string $title = 'Community Garden',
        string $summary = 'A summary for the community garden',
        string $description = 'Full description of the community garden idea',
        string $category = 'environment',
    ): ProjectProposal {
        $proposal = new ProjectProposal(
            ProjectProposalId::fromString(self::PROPOSAL_ID),
            TenantId::fromString($tenantId),
            self::PROPOSAL_USER_ID,
            'Anna',
            'anna@x',
            $title,
            $category,
            $summary,
            $description,
            $status,
            '2026-04-18 09:00:00',
        );
        $repo->save($proposal);
        return $proposal;
    }

    private function uc(
        InMemoryProjectProposalRepository $proposals,
        InMemoryProjectRepository $projects,
        InMemoryAdminApplicationDismissalRepository $dismissals,
        ?FrozenClock $clock = null,
        ?IdGeneratorInterface $ids = null,
    ): ApproveProjectProposal {
        return new ApproveProjectProposal(
            $proposals,
            $projects,
            $dismissals,
            new ImmediateTransactionManager(),
            $clock ?? FrozenClock::at('2026-04-20 12:00:00'),
            $ids ?? $this->ids(),
        );
    }

    public function test_admin_approves_creates_project_row(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $out = $this->uc($proposals, $projects, $dismissals)->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );

        $project = $projects->findBySlugForTenant($out->slug, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('Community Garden', $project->title());
        self::assertSame('environment', $project->category());
        self::assertSame('A summary for the community garden', $project->summary());
        self::assertSame('Full description of the community garden idea', $project->description());
        self::assertSame('bi-folder', $project->icon());
        self::assertSame('draft', $project->status());
        self::assertFalse($project->featured());
        $owner = $project->ownerId();
        self::assertNotNull($owner);
        self::assertSame(self::PROPOSAL_USER_ID, $owner->value());
    }

    public function test_approve_records_decision(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $clock = FrozenClock::at('2026-04-20 12:00:00');
        $this->uc($proposals, $projects, $dismissals, $clock)->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, 'looks great'),
        );

        $updated = $proposals->findByIdForTenant(self::PROPOSAL_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($updated);
        self::assertSame('approved', $updated->status());
        self::assertSame('2026-04-20 12:00:00', $updated->decidedAt());
        self::assertSame(self::ACTING_ADMIN_ID, $updated->decidedBy());
        self::assertSame('looks great', $updated->decisionNote());
    }

    public function test_approve_clears_dismissals(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
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

        $this->uc($proposals, $projects, $dismissals)->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );

        self::assertNotContains(self::PROPOSAL_ID, $dismissals->listAppIdsDismissedByAdmin($adminId));
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $this->uc($proposals, $projects, $dismissals)->execute(
            new ApproveProjectProposalInput($this->acting(platformAdmin: false, role: null), self::PROPOSAL_ID, null),
        );
    }

    public function test_proposal_not_in_tenant_throws_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        // Proposal exists in OTHER tenant only
        $this->seedProposal($proposals, tenantId: self::OTHER_TENANT);

        $this->uc($proposals, $projects, $dismissals)->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );
    }

    public function test_already_decided_throws_validation(): void
    {
        $this->expectException(ValidationException::class);
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals, status: 'approved');

        $this->uc($proposals, $projects, $dismissals)->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );
    }

    public function test_output_carries_project_id_and_slug(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        $fixedId = '01959900-0000-7000-8000-000000000111';
        $out = $this->uc($proposals, $projects, $dismissals, ids: $this->ids($fixedId))->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );

        self::assertSame($fixedId, $out->projectId);
        self::assertSame('community-garden', $out->slug);
        self::assertSame(['project_id' => $fixedId, 'slug' => 'community-garden'], $out->toArray());
    }

    public function test_slug_collision_fallback(): void
    {
        $proposals = new InMemoryProjectProposalRepository();
        $projects = new InMemoryProjectRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $this->seedProposal($proposals);

        // Seed existing project with same base slug
        $projects->save(new Project(
            ProjectId::fromString('01959900-0000-7000-8000-0000000000aa'),
            TenantId::fromString(self::TENANT),
            'community-garden',
            'Community Garden',
            'environment',
            'bi-folder',
            'Existing summary is long enough',
            'Existing description is long enough to pass',
            'active',
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        ));

        $out = $this->uc($proposals, $projects, $dismissals)->execute(
            new ApproveProjectProposalInput($this->acting(), self::PROPOSAL_ID, null),
        );

        self::assertNotSame('community-garden', $out->slug);
        self::assertStringStartsWith('community-garden-', $out->slug);
    }
}
