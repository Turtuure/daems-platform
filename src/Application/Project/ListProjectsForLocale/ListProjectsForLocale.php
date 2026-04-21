<?php

declare(strict_types=1);

namespace Daems\Application\Project\ListProjectsForLocale;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ListProjectsForLocale
{
    public function __construct(private readonly ProjectRepositoryInterface $projects)
    {
    }

    public function execute(ListProjectsForLocaleInput $input): ListProjectsForLocaleOutput
    {
        $projects = $this->projects->listForTenant(
            $input->tenantId,
            $input->category,
            $input->status,
            $input->search,
        );
        $payload = [];
        foreach ($projects as $project) {
            $view = $project->view($input->locale, SupportedLocale::contentFallback());
            $payload[] = array_merge(
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
        }
        return new ListProjectsForLocaleOutput($payload);
    }
}
