<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeProjectStatus;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class ChangeProjectStatus
{
    private const ALLOWED = ['draft', 'active', 'archived'];

    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(ChangeProjectStatusInput $input): void
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }
        if (!in_array($input->newStatus, self::ALLOWED, true)) {
            throw new ValidationException(['status' => 'invalid_value']);
        }
        if ($this->projects->findByIdForTenant($input->projectId, $tenantId) === null) {
            throw new NotFoundException('project_not_found');
        }
        $this->projects->setStatusForTenant($input->projectId, $tenantId, $input->newStatus);
    }
}
