<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ApproveProjectProposal;

final class ApproveProjectProposalOutput
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $slug,
    ) {}

    /** @return array{project_id: string, slug: string} */
    public function toArray(): array
    {
        return ['project_id' => $this->projectId, 'slug' => $this->slug];
    }
}
