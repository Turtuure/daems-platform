<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UpdateProjectTranslation;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class UpdateProjectTranslation
{
    public function __construct(private readonly ProjectRepositoryInterface $projects)
    {
    }

    public function execute(UpdateProjectTranslationInput $input): UpdateProjectTranslationOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $locale = SupportedLocale::fromString($input->localeRaw);

        $rawTitle = $input->fields['title'] ?? null;
        $rawSummary = $input->fields['summary'] ?? null;
        $rawDescription = $input->fields['description'] ?? null;
        $safeFields = [
            'title'       => is_scalar($rawTitle) ? (string) $rawTitle : '',
            'summary'     => is_scalar($rawSummary) ? (string) $rawSummary : '',
            'description' => is_scalar($rawDescription) ? (string) $rawDescription : '',
        ];
        if (trim($safeFields['title']) === '') {
            throw new \DomainException('title_required');
        }
        if (trim($safeFields['summary']) === '') {
            throw new \DomainException('summary_required');
        }
        if (trim($safeFields['description']) === '') {
            throw new \DomainException('description_required');
        }

        $this->projects->saveTranslation($input->tenantId, $input->projectId, $locale, $safeFields);

        $project = $this->projects->findByIdForTenant($input->projectId, $input->tenantId);
        if ($project === null) {
            throw new \RuntimeException('project_vanished');
        }
        $coverage = $project->translations()->coverage(Project::TRANSLATABLE_FIELDS);
        return new UpdateProjectTranslationOutput($coverage);
    }
}
