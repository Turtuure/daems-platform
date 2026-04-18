<?php

declare(strict_types=1);

namespace Daems\Application\Project\CreateProject;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class CreateProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(CreateProjectInput $input): CreateProjectOutput
    {
        $slug = $this->makeSlug($input->title);

        $project = new Project(
            ProjectId::generate(),
            $slug,
            $input->title,
            $input->category,
            $input->icon,
            $input->summary,
            $input->description,
            $input->status,
            0,
        );

        $this->projects->save($project);

        return new CreateProjectOutput([
            'id'   => $project->id()->value(),
            'slug' => $project->slug(),
        ]);
    }

    private function makeSlug(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        return substr($slug, 0, 60) . '-' . substr(uniqid(), -6);
    }
}
