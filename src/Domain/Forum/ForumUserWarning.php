<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumUserWarning
{
    public function __construct(
        private readonly ForumUserWarningId $id,
        private readonly TenantId $tenantId,
        private readonly string $userId,
        private readonly string $reason,
        private readonly ?string $relatedReportId,
        private readonly string $issuedBy,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumUserWarningId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function relatedReportId(): ?string
    {
        return $this->relatedReportId;
    }

    public function issuedBy(): string
    {
        return $this->issuedBy;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}
