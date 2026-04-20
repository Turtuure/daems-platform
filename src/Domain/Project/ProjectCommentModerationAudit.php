<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

final class ProjectCommentModerationAudit
{
    public function __construct(
        public readonly string $id,
        public readonly TenantId $tenantId,
        public readonly string $projectId,
        public readonly string $commentId,
        public readonly string $action,
        public readonly ?string $reason,
        public readonly string $performedBy,
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}
