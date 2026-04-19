<?php

declare(strict_types=1);

namespace Daems\Application\Project\ListProjects;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ListProjects
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
    ) {}

    public function execute(ListProjectsInput $input): ListProjectsOutput
    {
        $projects = $this->projects->listForTenant($input->tenantId, $input->category, $input->status, $input->search);

        return new ListProjectsOutput(
            array_map(fn(Project $p) => $this->toArray($p), $projects),
        );
    }

    private function toArray(Project $p): array
    {
        return [
            'id'         => $p->id()->value(),
            'slug'       => $p->slug(),
            'title'      => $p->title(),
            'category'   => $p->category(),
            'icon'       => $p->icon(),
            'summary'    => $p->summary(),
            'status'     => $p->status(),
            'sort_order' => $p->sortOrder(),
        ];
    }
}
