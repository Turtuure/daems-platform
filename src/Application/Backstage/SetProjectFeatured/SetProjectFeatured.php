<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\SetProjectFeatured;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class SetProjectFeatured
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(SetProjectFeaturedInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if ($this->projects->findByIdForTenant($input->projectId, $tenantId) === null) {
            throw new NotFoundException('project_not_found');
        }
        $this->projects->setFeaturedForTenant($input->projectId, $tenantId, $input->featured);
    }
}
