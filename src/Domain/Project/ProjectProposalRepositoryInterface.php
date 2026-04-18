<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

interface ProjectProposalRepositoryInterface
{
    public function save(ProjectProposal $proposal): void;
}
