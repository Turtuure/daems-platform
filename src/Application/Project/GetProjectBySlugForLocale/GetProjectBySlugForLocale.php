<?php

declare(strict_types=1);

namespace Daems\Application\Project\GetProjectBySlugForLocale;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class GetProjectBySlugForLocale
{
    public function __construct(private readonly ProjectRepositoryInterface $projects)
    {
    }

    public function execute(GetProjectBySlugForLocaleInput $input): GetProjectBySlugForLocaleOutput
    {
        $project = $this->projects->findBySlugForTenant($input->slug, $input->tenantId);
        if ($project === null) {
            return new GetProjectBySlugForLocaleOutput(null);
        }
        $view = $project->view($input->locale, SupportedLocale::contentFallback());
        $payload = array_merge(
            [
                'id'         => $project->id()->value(),
                'slug'       => $project->slug(),
                'category'   => $project->category(),
                'icon'       => $project->icon(),
                'status'     => $project->status(),
                'sort_order' => $project->sortOrder(),
                'featured'   => $project->featured(),
                'created_at' => $project->createdAt(),
            ],
            $view->toApiPayload(),
        );
        return new GetProjectBySlugForLocaleOutput($payload);
    }
}
