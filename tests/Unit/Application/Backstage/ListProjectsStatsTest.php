<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\Projects\ListProjectsStats\ListProjectsStats;
use Daems\Application\Backstage\Projects\ListProjectsStats\ListProjectsStatsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryProjectProposalRepository;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class ListProjectsStatsTest extends TestCase
{
    private const TENANT_ID = '019d0000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '019d0000-0000-7000-8000-000000000a01';
    private const USER_ID   = '019d0000-0000-7000-8000-000000000a02';

    // Project ids
    private const PROJECT_1 = '019d0000-0000-7000-8000-000000003001';
    private const PROJECT_2 = '019d0000-0000-7000-8000-000000003002';
    private const PROJECT_3 = '019d0000-0000-7000-8000-000000003003';

    // Proposal ids
    private const PROP_1 = '019d0000-0000-7000-8000-000000005001';

    public function test_orchestrates_2_repos_into_4_kpis(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $projectRepo  = new InMemoryProjectRepository();
        $proposalRepo = new InMemoryProjectProposalRepository();

        // Seed 2 active projects (1 of which is featured).
        $projectRepo->save($this->makeProject(self::PROJECT_1, $tenantId, 'active', featured: true));
        $projectRepo->save($this->makeProject(self::PROJECT_2, $tenantId, 'active', featured: false));

        // Seed 1 draft project.
        $projectRepo->save($this->makeProject(self::PROJECT_3, $tenantId, 'draft', featured: false));

        // Seed 1 pending proposal.
        $proposalRepo->save($this->makeProposal(self::PROP_1, $tenantId, 'pending'));

        $usecase = new ListProjectsStats($projectRepo, $proposalRepo);
        $out     = $usecase->execute(new ListProjectsStatsInput(acting: $admin, tenantId: $tenantId));

        // KPI values.
        self::assertSame(2, $out->stats['active']['value']);
        self::assertSame(1, $out->stats['drafts']['value']);
        self::assertSame(1, $out->stats['featured']['value']);
        self::assertSame(1, $out->stats['pending_proposals']['value']);

        // Featured sparkline is intentionally empty (curation toggle).
        self::assertSame([], $out->stats['featured']['sparkline']);

        // All 4 KPIs exist; active/drafts/pending have 30-entry sparklines, featured has 0.
        foreach (['active', 'drafts', 'featured', 'pending_proposals'] as $key) {
            self::assertArrayHasKey($key, $out->stats);
            self::assertArrayHasKey('value', $out->stats[$key]);
            self::assertArrayHasKey('sparkline', $out->stats[$key]);
            self::assertIsArray($out->stats[$key]['sparkline']);
        }
        self::assertCount(30, $out->stats['active']['sparkline']);
        self::assertCount(30, $out->stats['drafts']['sparkline']);
        self::assertCount(0, $out->stats['featured']['sparkline']);
        self::assertCount(30, $out->stats['pending_proposals']['sparkline']);
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $member   = ActingUserFactory::registeredInTenant(self::USER_ID, $tenantId);

        $this->expectException(ForbiddenException::class);

        $usecase = new ListProjectsStats(
            new InMemoryProjectRepository(),
            new InMemoryProjectProposalRepository(),
        );
        $usecase->execute(new ListProjectsStatsInput(acting: $member, tenantId: $tenantId));
    }

    public function test_returns_zero_state_with_no_data(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $usecase = new ListProjectsStats(
            new InMemoryProjectRepository(),
            new InMemoryProjectProposalRepository(),
        );
        $out = $usecase->execute(new ListProjectsStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(0, $out->stats['active']['value']);
        self::assertSame(0, $out->stats['drafts']['value']);
        self::assertSame(0, $out->stats['featured']['value']);
        self::assertSame(0, $out->stats['pending_proposals']['value']);

        // active/drafts/pending fakes return 30-entry windows; featured is intentionally empty.
        self::assertCount(30, $out->stats['active']['sparkline']);
        self::assertCount(30, $out->stats['drafts']['sparkline']);
        self::assertSame([], $out->stats['featured']['sparkline']);
        self::assertCount(30, $out->stats['pending_proposals']['sparkline']);
    }

    private function makeProject(string $id, TenantId $tenantId, string $status, bool $featured): Project
    {
        return new Project(
            ProjectId::fromString($id),
            $tenantId,
            'test-project-' . substr($id, -4),
            'Test Project ' . substr($id, -4),
            'social',
            'bi-folder',
            'Summary text long enough',
            'Description with enough characters here',
            $status,
            0,
            null,
            $featured,
            '2026-04-01 10:00:00',
        );
    }

    private function makeProposal(string $id, TenantId $tenantId, string $status): ProjectProposal
    {
        return new ProjectProposal(
            id:           ProjectProposalId::fromString($id),
            tenantId:     $tenantId,
            userId:       self::USER_ID,
            authorName:   'Author',
            authorEmail:  'author@example.com',
            title:        'Proposed Project',
            category:     'social',
            summary:      'Summary',
            description:  'Description',
            status:       $status,
            createdAt:    (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s'),
            sourceLocale: 'fi_FI',
        );
    }
}
