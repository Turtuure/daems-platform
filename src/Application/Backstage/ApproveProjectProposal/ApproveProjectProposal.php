<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ApproveProjectProposal;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ApproveProjectProposal
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
        private readonly ProjectRepositoryInterface $projects,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(ApproveProjectProposalInput $input): ApproveProjectProposalOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $proposal = $this->proposals->findByIdForTenant($input->proposalId, $tenantId)
            ?? throw new NotFoundException('proposal_not_found');

        if ($proposal->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        $now = $this->clock->now();

        return $this->tx->run(function () use ($proposal, $tenantId, $now, $input): ApproveProjectProposalOutput {
            $projectId = ProjectId::fromString($this->ids->generate());
            $slug = $this->uniqueSlug($proposal->title(), $tenantId);
            $ownerUserId = UserId::fromString($proposal->userId());

            $project = new Project(
                $projectId,
                $tenantId,
                $slug,
                $proposal->title(),
                $proposal->category(),
                'bi-folder',
                $proposal->summary(),
                $proposal->description(),
                'draft',
                0,
                $ownerUserId,
                false,
                '',
            );
            $this->projects->save($project);

            $this->proposals->recordDecision(
                $proposal->id()->value(),
                $tenantId,
                'approved',
                $input->acting->id->value(),
                $input->note,
                $now,
            );

            $this->dismissals->deleteByAppId($proposal->id()->value());

            return new ApproveProjectProposalOutput($projectId->value(), $slug);
        });
    }

    private function uniqueSlug(string $title, TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'project';
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = 'project';
        }
        if ($this->projects->findBySlugForTenant($base, $tenantId) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $candidate = $base . '-' . substr($this->ids->generate(), 0, 8);
            if ($this->projects->findBySlugForTenant($candidate, $tenantId) === null) {
                return $candidate;
            }
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
