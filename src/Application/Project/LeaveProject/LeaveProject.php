<?php

declare(strict_types=1);

namespace Daems\Application\Project\LeaveProject;

use Daems\Domain\Project\ProjectRepositoryInterface;

final class LeaveProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(LeaveProjectInput $input): LeaveProjectOutput
    {
        $project = $this->projects->findBySlug($input->slug);
        if ($project === null) {
            return new LeaveProjectOutput(false, 'Project not found.');
        }

        $this->projects->removeParticipant($project->id()->value(), $input->acting->id->value());
        return new LeaveProjectOutput(true);
    }
}
