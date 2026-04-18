<?php

declare(strict_types=1);

namespace Daems\Application\Project\JoinProject;

use Daems\Domain\Project\ProjectParticipant;
use Daems\Domain\Project\ProjectParticipantId;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class JoinProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(JoinProjectInput $input): JoinProjectOutput
    {
        $project = $this->projects->findBySlug($input->slug);
        if ($project === null) {
            return new JoinProjectOutput(false, 'Project not found.');
        }

        if ($this->projects->isParticipant($project->id()->value(), $input->userId)) {
            return new JoinProjectOutput(false, 'Already a participant.');
        }

        $participant = new ProjectParticipant(
            ProjectParticipantId::generate(),
            $project->id()->value(),
            $input->userId,
            date('Y-m-d H:i:s'),
        );

        $this->projects->addParticipant($participant);
        return new JoinProjectOutput(true);
    }
}
