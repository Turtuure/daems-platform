<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\CreateProjectAsAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class CreateProjectAsAdmin
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(CreateProjectAsAdminInput $input): CreateProjectAsAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $errors = [];
        if (strlen($input->title) < 3 || strlen($input->title) > 200) {
            $errors['title'] = 'length_3_to_200';
        }
        if (trim($input->category) === '') {
            $errors['category'] = 'required';
        }
        if (strlen(trim($input->summary)) < 10) {
            $errors['summary'] = 'min_10_chars';
        }
        if (strlen(trim($input->description)) < 20) {
            $errors['description'] = 'min_20_chars';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $slug = $this->uniqueSlug($input->title, $tenantId);
        $projectId = ProjectId::fromString($this->ids->generate());

        $ownerId = ($input->ownerId !== null && $input->ownerId !== '')
            ? UserId::fromString($input->ownerId)
            : $input->acting->id;

        $icon = ($input->icon !== null && $input->icon !== '') ? $input->icon : 'bi-folder';

        $project = new Project(
            $projectId,
            $tenantId,
            $slug,
            $input->title,
            $input->category,
            $icon,
            $input->summary,
            $input->description,
            'draft',
            0,
            $ownerId,
            false,
            '',
        );
        $this->projects->save($project);

        return new CreateProjectAsAdminOutput($projectId->value(), $slug);
    }

    private function uniqueSlug(string $title, TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'project';
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = 'project';
        }
        if ($this->projects->findBySlugForTenant($base, $tenantId) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $suffix = substr($this->ids->generate(), 0, 8);
            $candidate = $base . '-' . $suffix;
            if ($this->projects->findBySlugForTenant($candidate, $tenantId) === null) {
                return $candidate;
            }
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
