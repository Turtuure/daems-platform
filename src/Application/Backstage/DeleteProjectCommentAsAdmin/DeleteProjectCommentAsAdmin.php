<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DeleteProjectCommentAsAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectCommentModerationAudit;
use Daems\Domain\Project\ProjectCommentModerationAuditRepositoryInterface;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;

final class DeleteProjectCommentAsAdmin
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly ProjectCommentModerationAuditRepositoryInterface $audit,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(DeleteProjectCommentAsAdminInput $input): void
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        // Idempotent: always attempt delete, always write audit row.
        $this->projects->deleteCommentForTenant($input->commentId, $tenantId);

        $this->audit->save(new ProjectCommentModerationAudit(
            $this->ids->generate(),
            $tenantId,
            $input->projectId,
            $input->commentId,
            'deleted',
            $input->reason,
            $input->acting->id->value(),
            $this->clock->now(),
        ));
    }
}
