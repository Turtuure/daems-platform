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
        $project = $this->projects->findBySlugForTenant($input->slug, $input->acting->activeTenant);
        if ($project === null) {
            return new JoinProjectOutput(false, 'Project not found.');
        }

        $userId = $input->acting->id->value();

        if ($this->projects->isParticipant($project->id()->value(), $userId)) {
            return new JoinProjectOutput(false, 'Already a participant.');
        }

        $participant = new ProjectParticipant(
            ProjectParticipantId::generate(),
            $project->id()->value(),
            $userId,
            date('Y-m-d H:i:s'),
        );

        $this->projects->addParticipant($participant);
        return new JoinProjectOutput(true);
    }
}
