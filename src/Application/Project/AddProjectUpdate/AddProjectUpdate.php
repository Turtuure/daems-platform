<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectUpdate;

use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Project\ProjectUpdate;
use Daems\Domain\Project\ProjectUpdateId;
use Daems\Domain\User\UserRepositoryInterface;

final class AddProjectUpdate
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(AddProjectUpdateInput $input): AddProjectUpdateOutput
    {
        $project = $this->projects->findBySlugForTenant($input->slug, $input->acting->activeTenant);
        if ($project === null) {
            return new AddProjectUpdateOutput(false, 'Project not found.');
        }

        $project->assertMutableBy($input->acting);

        $actingUser = $this->users->findById($input->acting->id->value());
        $authorName = $actingUser !== null ? $actingUser->name() : 'Unknown';

        $update = new ProjectUpdate(
            ProjectUpdateId::generate(),
            $project->id()->value(),
            $input->title,
            $input->content,
            $authorName,
            date('Y-m-d H:i:s'),
        );

        $this->projects->saveUpdate($update);
        return new AddProjectUpdateOutput(true);
    }
}
