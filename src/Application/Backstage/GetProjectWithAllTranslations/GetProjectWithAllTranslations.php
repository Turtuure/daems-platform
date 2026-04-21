<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\GetProjectWithAllTranslations;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class GetProjectWithAllTranslations
{
    public function __construct(private readonly ProjectRepositoryInterface $projects)
    {
    }

    public function execute(GetProjectWithAllTranslationsInput $input): GetProjectWithAllTranslationsOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $project = $this->projects->findByIdForTenant($input->projectId, $input->tenantId);
        if ($project === null) {
            throw new NotFoundException('project');
        }

        $translations = [];
        foreach ($project->translations()->raw() as $loc => $row) {
            $translations[$loc] = $row;
        }
        $coverage = $project->translations()->coverage(Project::TRANSLATABLE_FIELDS);

        return new GetProjectWithAllTranslationsOutput([
            'id'           => $project->id()->value(),
            'slug'         => $project->slug(),
            'category'     => $project->category(),
            'icon'         => $project->icon(),
            'status'       => $project->status(),
            'sort_order'   => $project->sortOrder(),
            'featured'     => $project->featured(),
            'created_at'   => $project->createdAt(),
            'translations' => $translations,
            'coverage'     => $coverage,
        ]);
    }
}
