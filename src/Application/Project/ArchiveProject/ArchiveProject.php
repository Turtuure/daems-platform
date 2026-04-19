<?php

declare(strict_types=1);

namespace Daems\Application\Project\ArchiveProject;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ArchiveProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(ArchiveProjectInput $input): ArchiveProjectOutput
    {
        $existing = $this->projects->findBySlug($input->slug);
        if ($existing === null) {
            return new ArchiveProjectOutput(false, 'Project not found.');
        }

        $existing->assertMutableBy($input->acting);

        $archived = new Project(
            $existing->id(),
            $existing->slug(),
            $existing->title(),
            $existing->category(),
            $existing->icon(),
            $existing->summary(),
            $existing->description(),
            'archived',
            $existing->sortOrder(),
            $existing->ownerId(),
        );

        $this->projects->save($archived);
        return new ArchiveProjectOutput(true);
    }
}
