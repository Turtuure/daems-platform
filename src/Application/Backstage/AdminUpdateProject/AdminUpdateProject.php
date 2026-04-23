<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\AdminUpdateProject;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class AdminUpdateProject
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(AdminUpdateProjectInput $input): AdminUpdateProjectOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $project = $this->projects->findByIdForTenant($input->projectId, $tenantId)
            ?? throw new NotFoundException('project_not_found');

        $errors = [];
        $chromeFields = [];
        $translationFields = [];

        if ($input->title !== null) {
            if (strlen($input->title) < 3 || strlen($input->title) > 200) {
                $errors['title'] = 'length_3_to_200';
            } else {
                $translationFields['title'] = $input->title;
            }
        }
        if ($input->category !== null) {
            if (trim($input->category) === '') {
                $errors['category'] = 'required';
            } else {
                $chromeFields['category'] = $input->category;
            }
        }
        if ($input->icon !== null) {
            $chromeFields['icon'] = $input->icon;
        }
        if ($input->summary !== null) {
            if (strlen(trim($input->summary)) < 10) {
                $errors['summary'] = 'min_10_chars';
            } else {
                $translationFields['summary'] = $input->summary;
            }
        }
        if ($input->description !== null) {
            if (strlen(trim($input->description)) < 20) {
                $errors['description'] = 'min_20_chars';
            } else {
                $translationFields['description'] = $input->description;
            }
        }
        if ($input->sortOrder !== null) {
            $chromeFields['sort_order'] = $input->sortOrder;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($chromeFields !== []) {
            $this->projects->updateForTenant($project->id()->value(), $tenantId, $chromeFields);
        }
        if ($translationFields !== []) {
            $existing = $project->translations()->rowFor(SupportedLocale::uiDefault()) ?? [];
            $merged = [
                'title'       => array_key_exists('title', $translationFields)
                    ? (string) $translationFields['title']
                    : (string) ($existing['title'] ?? ''),
                'summary'     => array_key_exists('summary', $translationFields)
                    ? (string) $translationFields['summary']
                    : (string) ($existing['summary'] ?? ''),
                'description' => array_key_exists('description', $translationFields)
                    ? (string) $translationFields['description']
                    : (string) ($existing['description'] ?? ''),
            ];
            $this->projects->saveTranslation(
                $tenantId,
                $project->id()->value(),
                SupportedLocale::uiDefault(),
                $merged,
            );
        }

        return new AdminUpdateProjectOutput($project->id()->value());
    }
}
