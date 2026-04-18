<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectUpdate;

use Daems\Domain\Project\ProjectUpdate;
use Daems\Domain\Project\ProjectUpdateId;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class AddProjectUpdate
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(AddProjectUpdateInput $input): AddProjectUpdateOutput
    {
        $project = $this->projects->findBySlug($input->slug);
        if ($project === null) {
            return new AddProjectUpdateOutput(false, 'Project not found.');
        }

        $update = new ProjectUpdate(
            ProjectUpdateId::generate(),
            $project->id()->value(),
            $input->title,
            $input->content,
            $input->authorName,
            date('Y-m-d H:i:s'),
        );

        $this->projects->saveUpdate($update);
        return new AddProjectUpdateOutput(true);
    }
}
