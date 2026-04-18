<?php

declare(strict_types=1);

namespace Daems\Application\Project\UpdateProject;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class UpdateProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(UpdateProjectInput $input): UpdateProjectOutput
    {
        $existing = $this->projects->findBySlug($input->slug);
        if ($existing === null) {
            return new UpdateProjectOutput(false, 'Project not found.');
        }

        $ownerId = $existing->ownerId();
        if ($ownerId === null) {
            if (!$input->acting->isAdmin()) {
                throw new ForbiddenException();
            }
        } elseif (!$input->acting->owns($ownerId) && !$input->acting->isAdmin()) {
            throw new ForbiddenException();
        }

        $updated = new Project(
            $existing->id(),
            $existing->slug(),
            $input->title,
            $input->category,
            $input->icon,
            $input->summary,
            $input->description,
            $input->status,
            $existing->sortOrder(),
            $existing->ownerId(),
        );

        $this->projects->save($updated);
        return new UpdateProjectOutput(true);
    }
}
