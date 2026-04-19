<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;

final class InMemoryProjectProposalRepository implements ProjectProposalRepositoryInterface
{
    /** @var list<ProjectProposal> */
    public array $proposals = [];

    public function save(ProjectProposal $proposal): void
    {
        $this->proposals[] = $proposal;
    }

    public function lastProposal(): ?ProjectProposal
    {
        return $this->proposals === [] ? null : $this->proposals[array_key_last($this->proposals)];
    }
}
