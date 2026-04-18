<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectUpdate;

use Daems\Domain\Auth\ForbiddenException;
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
        $project = $this->projects->findBySlug($input->slug);
        if ($project === null) {
            return new AddProjectUpdateOutput(false, 'Project not found.');
        }

        $ownerId = $project->ownerId();
        if ($ownerId === null) {
            if (!$input->acting->isAdmin()) {
                throw new ForbiddenException();
            }
        } elseif (!$input->acting->owns($ownerId) && !$input->acting->isAdmin()) {
            throw new ForbiddenException();
        }

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
